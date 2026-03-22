"""
FreeRADIUS Python3 Module — Per-Device VLAN Assignment

Handles: Device lookup, auto-assignment from VLAN pools,
         simultaneous-use enforcement, SSID-based pool selection
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

# SSID -> category mapping
SSID_MAP = {
    'daathnet-secure':       'secure',
    'daathnet-guest-secure': 'guest-secure',
    'daathnet-guest':        'guest',
    'daathnet-iot-secure':   'iot-secure',
    'daathnet-iot-premium':  'iot-premium',
    'daathnet-iot-offline':  'iot-offline',
    'daathnet-iot':          'iot',
}

_db_conn = None


def get_db():
    """Get or create a database connection."""
    global _db_conn
    try:
        if _db_conn and not _db_conn.closed:
            # Test if connection is alive
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
    """Normalize MAC to lowercase 12-char hex."""
    if not raw:
        return ''
    return re.sub(r'[:\-\.]', '', raw).lower()


def is_mac_address(s):
    """Check if string is a MAC address."""
    n = normalize_mac(s)
    return bool(re.match(r'^[0-9a-f]{12}$', n))


def extract_ssid(called_station_id):
    """Extract SSID from Called-Station-Id (format: AA-BB-CC-DD-EE-FF:SSID)."""
    if not called_station_id:
        return ''
    m = re.search(r':(.+)$', called_station_id)
    return m.group(1) if m else ''


def ssid_to_category(ssid):
    if not ssid:
        return 'unknown'
    return SSID_MAP.get(ssid.lower(), 'unknown')


def category_to_pool(category):
    return 'offline' if category == 'iot-offline' else 'online'


def request_to_dict(p):
    """Convert FreeRADIUS request tuple to dict."""
    d = {}
    if p:
        for pair in p:
            if isinstance(pair, tuple) and len(pair) == 2:
                d[pair[0]] = pair[1]
    return d


def check_simultaneous_use(db, calling_station):
    """Check if there's an active session. Returns True if login is allowed."""
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

    # Check if session is stale (no update > 30 seconds)
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
        # Stale session — close it
        cur = db.cursor()
        cur.execute(
            """UPDATE radacct SET acctstoptime = NOW(), acctterminatecause = 'Stale-Session'
               WHERE callingstationid = %s AND acctstoptime IS NULL""",
            (calling_station,)
        )
        cur.close()
        radiusd.radlog(radiusd.L_INFO, f"PYTHON: Closed stale session(s) for {calling_station}")
        return True

    return False  # Active session exists — reject


def assign_vlan_from_pool(db, mac, pool, category, ssid, username=None, device_name=None):
    """Assign next free VLAN from pool. Returns vlan_id or None."""
    cur = db.cursor()

    # Check pool availability
    cur.execute("SELECT COUNT(*) FROM vlan_pool WHERE pool = %s AND in_use = false", (pool,))
    free_count = cur.fetchone()[0]

    cur.execute("SELECT COUNT(*) FROM vlan_pool WHERE pool = %s", (pool,))
    total_count = cur.fetchone()[0]

    if free_count == 0:
        radiusd.radlog(radiusd.L_ERR, f"PYTHON: Pool '{pool}' EXHAUSTED! Rejecting {mac}")
        cur.close()
        return None

    # Warning at 80%
    if total_count > 0:
        usage_pct = ((total_count - free_count) / total_count) * 100
        if usage_pct >= 80:
            radiusd.radlog(radiusd.L_WARN,
                f"PYTHON WARNING: Pool '{pool}' is {int(usage_pct)}% full ({free_count} free)")

    # Atomically assign next free VLAN
    cur.execute(
        """UPDATE vlan_pool SET in_use = true, assigned_mac = %s, assigned_at = NOW()
           WHERE vlan_id = (
               SELECT vlan_id FROM vlan_pool
               WHERE pool = %s AND in_use = false
               ORDER BY vlan_id LIMIT 1
               FOR UPDATE SKIP LOCKED
           ) RETURNING vlan_id""",
        (mac, pool)
    )
    row = cur.fetchone()

    if not row:
        radiusd.radlog(radiusd.L_ERR, f"PYTHON: VLAN assignment race condition for {mac}")
        cur.close()
        return None

    vlan_id = row[0]

    # Create device entry
    cur.execute(
        """INSERT INTO devices (mac, username, vlan_id, device_name, ssid_category, created_at, updated_at)
           VALUES (%s, %s, %s, %s, %s, NOW(), NOW())
           ON CONFLICT (mac) DO UPDATE SET vlan_id = %s, ssid_category = %s, updated_at = NOW()""",
        (mac, username, vlan_id, device_name or 'Auto-assigned', category,
         vlan_id, category)
    )
    cur.close()

    radiusd.radlog(radiusd.L_INFO,
        f"PYTHON: Auto-assigned VLAN {vlan_id} (pool: {pool}) to {mac} (SSID: {ssid})")
    return vlan_id


def _log_auth(db, mac, username, result, vlan, ssid):
    """Log authentication attempt to auth_log table."""
    if not db:
        return
    try:
        cur = db.cursor()
        cur.execute(
            "INSERT INTO auth_log (mac, username, result, vlan_id, ssid) VALUES (%s, %s, %s, %s, %s)",
            (mac or None, username or None, result, vlan, ssid or None)
        )
        cur.close()
    except Exception as e:
        radiusd.radlog(radiusd.L_ERR, f"PYTHON: auth log error: {e}")


def _reject(db, mac, username, ssid, message):
    """Return REJECT and log it."""
    _log_auth(db, mac, username, 'reject', None, ssid)
    return (RLM_MODULE_REJECT,
            (('Reply-Message', message),),
            ())


def authorize(p):
    """Main authorize handler. Called for both MAC auth (outer) and Enterprise auth (inner tunnel)."""
    req = request_to_dict(p)

    username = req.get('User-Name', '')
    calling_station = req.get('Calling-Station-Id', '')
    called_station = req.get('Called-Station-Id', '')

    ssid = extract_ssid(called_station)
    category = ssid_to_category(ssid)
    pool = category_to_pool(category)
    mac_auth = is_mac_address(username)

    # Only normalize actual MACs, not usernames
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
            cur.execute("SELECT * FROM devices WHERE mac = %s", (mac,))
            device = cur.fetchone()
            cur.close()

            if device:
                # Known device — check simultaneous use
                if not check_simultaneous_use(db, calling_station):
                    radiusd.radlog(radiusd.L_INFO, f"PYTHON: Simul-use REJECT for MAC {mac}")
                    return _reject(db, mac, username, ssid, 'Simultaneous login not allowed')

                return (RLM_MODULE_UPDATED,
                        (('Tunnel-Type', '13'),
                         ('Tunnel-Medium-Type', '6'),
                         ('Tunnel-Private-Group-Id', str(device['vlan_id']))),
                        (('Cleartext-Password', mac),))

            # Unknown MAC — auto-assign
            cur.close()
            if not check_simultaneous_use(db, calling_station):
                return _reject(db, mac, username, ssid, 'Simultaneous login not allowed')

            vlan = assign_vlan_from_pool(db, mac, pool, category, ssid)
            if not vlan:
                return _reject(db, mac, username, ssid, 'VLAN pool exhausted - access denied')

            return (RLM_MODULE_UPDATED,
                    (('Tunnel-Type', '13'),
                     ('Tunnel-Medium-Type', '6'),
                     ('Tunnel-Private-Group-Id', str(vlan))),
                    (('Cleartext-Password', mac),))

        else:
            # --- Enterprise Authentication (EAP-PEAP/MSCHAPv2) ---
            cur.execute("SELECT * FROM devices WHERE username = %s", (username,))
            device = cur.fetchone()
            cur.close()

            if not device:
                radiusd.radlog(radiusd.L_INFO, f"PYTHON: Unknown enterprise user '{username}' REJECTED")
                return _reject(db, mac, username, ssid, 'Unknown user - access denied')

            if not device.get('password'):
                radiusd.radlog(radiusd.L_ERR, f"PYTHON: User '{username}' has no password")
                return _reject(db, mac, username, ssid, 'Account configuration error')

            # Simultaneous use check
            if calling_station and not check_simultaneous_use(db, calling_station):
                radiusd.radlog(radiusd.L_INFO, f"PYTHON: Simul-use REJECT for user '{username}'")
                return _reject(db, mac, username, ssid, 'Simultaneous login not allowed')

            vlan = device.get('vlan_id')
            if not vlan:
                vlan = assign_vlan_from_pool(db, mac or f'ent-{username}', 'online',
                                             category, ssid, username)
                if not vlan:
                    return _reject(db, mac, username, ssid, 'VLAN pool exhausted - access denied')

            return (RLM_MODULE_UPDATED,
                    (('Tunnel-Type', '13'),
                     ('Tunnel-Medium-Type', '6'),
                     ('Tunnel-Private-Group-Id', str(vlan))),
                    (('Cleartext-Password', device['password']),))

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
    elif calling_station:
        mac = normalize_mac(calling_station)
    else:
        mac = ''

    db = get_db()
    if db:
        try:
            cur = db.cursor()
            cur.execute("SELECT vlan_id FROM devices WHERE mac = %s", (mac,))
            row = cur.fetchone()
            vlan = row[0] if row else None
            cur.close()
            _log_auth(db, mac, username, 'accept', vlan, ssid)
        except Exception as e:
            radiusd.radlog(radiusd.L_ERR, f"PYTHON: post_auth log error: {e}")

    return RLM_MODULE_UPDATED


def detach():
    """Cleanup on module unload."""
    global _db_conn
    if _db_conn:
        try:
            _db_conn.close()
        except Exception:
            pass
    return RLM_MODULE_OK
