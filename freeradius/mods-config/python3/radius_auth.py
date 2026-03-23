"""
FreeRADIUS Python3 Module — Per-Device VLAN Assignment v2

Key = (identity, ssid) — same device on different SSIDs gets different VLANs.
Enterprise users identified by username, MAC-auth by MAC address.
SSID determines VLAN type via SSID_MAP env var.
"""

import os
import re
import radiusd
import psycopg2
from psycopg2.extras import RealDictCursor

# FreeRADIUS return codes
RLM_MODULE_REJECT  = 0
RLM_MODULE_FAIL    = 1
RLM_MODULE_OK      = 2
RLM_MODULE_HANDLED = 3
RLM_MODULE_INVALID = 4
RLM_MODULE_NOOP    = 7
RLM_MODULE_UPDATED = 8

# Database config
DB_HOST = os.environ.get('POSTGRES_HOST', 'postgres')
DB_PORT = os.environ.get('POSTGRES_PORT', '5432')
DB_NAME = os.environ.get('POSTGRES_DB', 'radius')
DB_USER = os.environ.get('POSTGRES_USER', 'radius')
DB_PASS = os.environ.get('POSTGRES_PASSWORD', '')

# VLAN limit (max VLANs that can be created)
VLAN_LIMIT = int(os.environ.get('VLAN_LIMIT', '100'))

# SSID -> VLAN type mapping from env
# Format: "o:SSID1,g:SSID2,e:SSID3,e:SSID4"
# Unmatched SSIDs default to 'i' (internet)
SSID_TYPE_MAP = {}
_ssid_map_raw = os.environ.get('SSID_MAP', '')
if _ssid_map_raw:
    for entry in _ssid_map_raw.split(','):
        entry = entry.strip()
        if ':' in entry:
            typ, ssid_name = entry.split(':', 1)
            SSID_TYPE_MAP[ssid_name.strip().lower()] = typ.strip().lower()

_db_conn = None


def get_db():
    """Get or create a database connection."""
    global _db_conn
    try:
        if _db_conn and not _db_conn.closed:
            _db_conn.isolation_level
            return _db_conn
    except Exception:
        _db_conn = None

    try:
        _db_conn = psycopg2.connect(
            host=DB_HOST, port=DB_PORT, dbname=DB_NAME,
            user=DB_USER, password=DB_PASS,
            connect_timeout=5
        )
        _db_conn.autocommit = True
        return _db_conn
    except Exception as e:
        radiusd.radlog(radiusd.L_ERR, f"PYTHON: DB connect failed: {e}")
        return None


def normalize_mac(raw):
    if not raw:
        return ''
    return re.sub(r'[:\-\.]', '', raw).lower()


def is_mac_address(s):
    n = normalize_mac(s)
    return bool(re.match(r'^[0-9a-f]{12}$', n))


def extract_ssid(called_station_id):
    if not called_station_id:
        return ''
    m = re.search(r':(.+)$', called_station_id)
    return m.group(1) if m else ''


def ssid_to_type(ssid):
    """Map SSID to VLAN type using SSID_MAP. Default: 'i' (internet)."""
    if not ssid:
        return 'i'
    return SSID_TYPE_MAP.get(ssid.lower(), 'i')


def request_to_dict(p):
    d = {}
    if p:
        for pair in p:
            if isinstance(pair, tuple) and len(pair) == 2:
                d[pair[0]] = pair[1]
    return d


def check_simultaneous_use(db, calling_station):
    """Check if there's an active session. Returns True if login allowed."""
    if not calling_station:
        return True

    cur = db.cursor()
    cur.execute(
        "SELECT COUNT(*) FROM radacct WHERE callingstationid = %s AND acctstoptime IS NULL",
        (calling_station,)
    )
    active_count = cur.fetchone()[0]
    cur.close()

    if active_count == 0:
        return True

    cur = db.cursor()
    cur.execute(
        """SELECT COALESCE(acctupdatetime, acctstarttime) < NOW() - INTERVAL '30 seconds'
           FROM radacct
           WHERE callingstationid = %s AND acctstoptime IS NULL
           ORDER BY acctstarttime DESC LIMIT 1""",
        (calling_station,)
    )
    row = cur.fetchone()
    cur.close()

    if row and row[0]:
        cur = db.cursor()
        cur.execute(
            """UPDATE radacct SET acctstoptime = NOW(), acctterminatecause = 'Stale-Session'
               WHERE callingstationid = %s AND acctstoptime IS NULL""",
            (calling_station,)
        )
        cur.close()
        radiusd.radlog(radiusd.L_INFO, f"PYTHON: Closed stale session(s) for {calling_station}")
        return True

    return False


def assign_vlan(db, identity, ssid, vlan_type, auth_type, password=None, name=None):
    """Assign next free VLAN. Returns vlan_id or None."""
    cur = db.cursor()

    # Check VLAN limit
    cur.execute("SELECT COUNT(*) FROM vlan_pool WHERE in_use = true")
    used = cur.fetchone()[0]
    cur.execute("SELECT COUNT(*) FROM vlan_pool")
    total = cur.fetchone()[0]

    if used >= VLAN_LIMIT:
        radiusd.radlog(radiusd.L_ERR, f"PYTHON: VLAN limit ({VLAN_LIMIT}) reached! Rejecting {identity}")
        cur.close()
        return None

    free = total - used
    if free == 0:
        radiusd.radlog(radiusd.L_ERR, f"PYTHON: All VLANs exhausted! Rejecting {identity}")
        cur.close()
        return None

    # Warning at 80%
    if total > 0:
        usage_pct = (used / total) * 100
        if usage_pct >= 80:
            radiusd.radlog(radiusd.L_WARN,
                f"PYTHON WARNING: VLAN pool {int(usage_pct)}% full ({free} free)")

    # Atomically assign next free VLAN
    cur.execute(
        """UPDATE vlan_pool SET in_use = true, vlan_type = %s,
               assigned_to = %s, assigned_ssid = %s, assigned_at = NOW()
           WHERE vlan_id = (
               SELECT vlan_id FROM vlan_pool
               WHERE in_use = false
               ORDER BY vlan_id LIMIT 1
               FOR UPDATE SKIP LOCKED
           ) RETURNING vlan_id""",
        (vlan_type, identity, ssid)
    )
    row = cur.fetchone()

    if not row:
        radiusd.radlog(radiusd.L_ERR, f"PYTHON: VLAN assignment failed for {identity}")
        cur.close()
        return None

    vlan_id = row[0]

    # Create assignment entry
    cur.execute(
        """INSERT INTO vlan_assignments (identity, ssid, auth_type, password, vlan_id, vlan_type, name)
           VALUES (%s, %s, %s, %s, %s, %s, %s)
           ON CONFLICT (identity, ssid) DO UPDATE
           SET vlan_id = %s, vlan_type = %s, updated_at = NOW()""",
        (identity, ssid, auth_type, password, vlan_id, vlan_type, name or 'Auto-assigned',
         vlan_id, vlan_type)
    )
    cur.close()

    radiusd.radlog(radiusd.L_INFO,
        f"PYTHON: Assigned VLAN {vlan_id} (type:{vlan_type}) to {identity} on {ssid}")
    return vlan_id


def _log_auth(db, mac, username, ssid, result, vlan, vlan_type):
    if not db:
        return
    try:
        cur = db.cursor()
        cur.execute(
            "INSERT INTO auth_log (mac, username, ssid, result, vlan_id, vlan_type) VALUES (%s, %s, %s, %s, %s, %s)",
            (mac or None, username or None, ssid or None, result, vlan, vlan_type)
        )
        cur.close()
    except Exception as e:
        radiusd.radlog(radiusd.L_ERR, f"PYTHON: auth log error: {e}")


def _reject(db, mac, username, ssid, message):
    _log_auth(db, mac, username, ssid, 'reject', None, None)
    return (RLM_MODULE_REJECT,
            (('Reply-Message', message),),
            ())


def authorize(p):
    """Main authorize handler."""
    req = request_to_dict(p)

    username = req.get('User-Name', '')
    calling_station = req.get('Calling-Station-Id', '')
    called_station = req.get('Called-Station-Id', '')

    ssid = extract_ssid(called_station)
    vlan_type = ssid_to_type(ssid)
    mac_auth = is_mac_address(username)

    if mac_auth:
        mac = normalize_mac(username)
    elif calling_station:
        mac = normalize_mac(calling_station)
    else:
        mac = ''

    db = get_db()
    if not db:
        return RLM_MODULE_FAIL

    try:
        cur = db.cursor(cursor_factory=RealDictCursor)

        if mac_auth:
            # --- MAC Authentication ---
            identity = mac

            # Lookup by (mac, ssid)
            cur.execute("SELECT * FROM vlan_assignments WHERE identity = %s AND ssid = %s",
                        (identity, ssid))
            assignment = cur.fetchone()
            cur.close()

            if assignment:
                # Known device on this SSID
                if not check_simultaneous_use(db, calling_station):
                    return _reject(db, mac, username, ssid, 'Simultaneous login not allowed')

                return (RLM_MODULE_UPDATED,
                        (('Tunnel-Type', '13'),
                         ('Tunnel-Medium-Type', '6'),
                         ('Tunnel-Private-Group-Id', str(assignment['vlan_id']))),
                        (('Cleartext-Password', mac),))

            # Unknown MAC on this SSID — auto-assign
            if not check_simultaneous_use(db, calling_station):
                return _reject(db, mac, username, ssid, 'Simultaneous login not allowed')

            vlan = assign_vlan(db, identity, ssid, vlan_type, 'mac')
            if not vlan:
                return _reject(db, mac, username, ssid, 'VLAN pool exhausted - access denied')

            return (RLM_MODULE_UPDATED,
                    (('Tunnel-Type', '13'),
                     ('Tunnel-Medium-Type', '6'),
                     ('Tunnel-Private-Group-Id', str(vlan))),
                    (('Cleartext-Password', mac),))

        else:
            # --- Enterprise Authentication ---
            identity = username

            # Lookup by (username, ssid)
            cur.execute("SELECT * FROM vlan_assignments WHERE identity = %s AND ssid = %s AND auth_type = 'enterprise'",
                        (identity, ssid))
            assignment = cur.fetchone()
            cur.close()

            if not assignment:
                radiusd.radlog(radiusd.L_INFO, f"PYTHON: Unknown enterprise user '{username}' on SSID '{ssid}'")
                return _reject(db, mac, username, ssid, 'Unknown user - access denied')

            if not assignment.get('password'):
                return _reject(db, mac, username, ssid, 'Account configuration error')

            # Simultaneous use check by MAC
            if calling_station and not check_simultaneous_use(db, calling_station):
                return _reject(db, mac, username, ssid, 'Simultaneous login not allowed')

            vlan = assignment.get('vlan_id')
            if not vlan:
                # No VLAN yet — assign one
                vlan = assign_vlan(db, identity, ssid, vlan_type, 'enterprise',
                                   assignment['password'], assignment.get('name'))
                if not vlan:
                    return _reject(db, mac, username, ssid, 'VLAN pool exhausted - access denied')

            return (RLM_MODULE_UPDATED,
                    (('Tunnel-Type', '13'),
                     ('Tunnel-Medium-Type', '6'),
                     ('Tunnel-Private-Group-Id', str(vlan))),
                    (('Cleartext-Password', assignment['password']),))

    except Exception as e:
        radiusd.radlog(radiusd.L_ERR, f"PYTHON: authorize exception: {e}")
        return RLM_MODULE_FAIL


def post_auth(p):
    """Log successful authentication."""
    req = request_to_dict(p)

    username = req.get('User-Name', '')
    calling_station = req.get('Calling-Station-Id', '')
    called_station = req.get('Called-Station-Id', '')
    ssid = extract_ssid(called_station)

    mac_auth = is_mac_address(username)
    if mac_auth:
        mac = normalize_mac(username)
        identity = mac
    elif calling_station:
        mac = normalize_mac(calling_station)
        identity = username
    else:
        mac = ''
        identity = username

    db = get_db()
    if db:
        try:
            cur = db.cursor()
            cur.execute("SELECT vlan_id, vlan_type FROM vlan_assignments WHERE identity = %s AND ssid = %s",
                        (identity, ssid))
            row = cur.fetchone()
            vlan = row[0] if row else None
            vtype = row[1] if row else None
            cur.close()
            _log_auth(db, mac, username, ssid, 'accept', vlan, vtype)
        except Exception as e:
            radiusd.radlog(radiusd.L_ERR, f"PYTHON: post_auth log error: {e}")

    return RLM_MODULE_UPDATED


def detach():
    global _db_conn
    if _db_conn:
        try:
            _db_conn.close()
        except Exception:
            pass
    return RLM_MODULE_OK
