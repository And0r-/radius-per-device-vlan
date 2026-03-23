"""
FreeRADIUS Per-Device VLAN — Web Management UI v2
"""

import os
import re
from flask import Flask, render_template, request, redirect, url_for, flash

import psycopg2
from psycopg2.extras import RealDictCursor

app = Flask(__name__)
app.secret_key = os.environ.get('SECRET_KEY', 'change-me-in-production')

DB_CONFIG = {
    'host': os.environ.get('POSTGRES_HOST', 'postgres'),
    'port': os.environ.get('POSTGRES_PORT', '5432'),
    'dbname': os.environ.get('POSTGRES_DB', 'radius'),
    'user': os.environ.get('POSTGRES_USER', 'radius'),
    'password': os.environ.get('POSTGRES_PASSWORD', ''),
}


def get_db():
    conn = psycopg2.connect(**DB_CONFIG)
    conn.autocommit = True
    return conn


def normalize_mac(raw):
    if not raw:
        return ''
    return re.sub(r'[:\-\.\s]', '', raw).lower()


def is_valid_mac(mac):
    return bool(re.match(r'^[0-9a-f]{12}$', mac))


# ─── Dashboard ───────────────────────────────────────────────

@app.route('/')
def dashboard():
    db = get_db()
    cur = db.cursor(cursor_factory=RealDictCursor)

    cur.execute("SELECT COUNT(*) as total FROM vlan_pool")
    total = cur.fetchone()['total']
    cur.execute("SELECT COUNT(*) as used FROM vlan_pool WHERE in_use = true")
    used = cur.fetchone()['used']

    cur.execute("SELECT COUNT(*) as c FROM vlan_assignments WHERE auth_type = 'enterprise'")
    user_count = cur.fetchone()['c']
    cur.execute("SELECT COUNT(*) as c FROM vlan_assignments WHERE auth_type = 'mac'")
    device_count = cur.fetchone()['c']

    # By type
    cur.execute("SELECT vlan_type, COUNT(*) as c FROM vlan_pool WHERE in_use = true GROUP BY vlan_type ORDER BY vlan_type")
    type_counts = {row['vlan_type']: row['c'] for row in cur.fetchall()}

    cur.execute("SELECT * FROM auth_log ORDER BY ts DESC LIMIT 10")
    recent_logs = cur.fetchall()

    cur.close()
    db.close()
    return render_template('dashboard.html',
                           total=total, used=used, free=total - used,
                           pct=int(used / total * 100) if total else 0,
                           user_count=user_count, device_count=device_count,
                           type_counts=type_counts, recent_logs=recent_logs)


# ─── Devices ─────────────────────────────────────────────────

@app.route('/devices')
def devices():
    db = get_db()
    cur = db.cursor(cursor_factory=RealDictCursor)
    cur.execute("SELECT * FROM vlan_assignments WHERE auth_type = 'mac' ORDER BY vlan_id")
    device_list = cur.fetchall()
    cur.close()
    db.close()
    return render_template('devices.html', devices=device_list)


@app.route('/devices/add', methods=['POST'])
def device_add():
    mac = normalize_mac(request.form.get('mac', ''))
    ssid = request.form.get('ssid', '').strip()
    name = request.form.get('name', '').strip() or 'Unknown'
    vtype = request.form.get('vlan_type', 'i')

    if not is_valid_mac(mac):
        flash('Invalid MAC address.', 'error')
        return redirect(url_for('devices'))
    if not ssid:
        flash('SSID is required.', 'error')
        return redirect(url_for('devices'))

    db = get_db()
    cur = db.cursor()

    cur.execute("SELECT COUNT(*) FROM vlan_assignments WHERE identity = %s AND ssid = %s", (mac, ssid))
    if cur.fetchone()[0] > 0:
        flash(f'Device {mac} on {ssid} already exists.', 'error')
        cur.close()
        db.close()
        return redirect(url_for('devices'))

    cur.execute("""
        UPDATE vlan_pool SET in_use = true, vlan_type = %s, assigned_to = %s, assigned_ssid = %s, assigned_at = NOW()
        WHERE vlan_id = (SELECT vlan_id FROM vlan_pool WHERE in_use = false ORDER BY vlan_id LIMIT 1 FOR UPDATE SKIP LOCKED)
        RETURNING vlan_id
    """, (vtype, mac, ssid))
    row = cur.fetchone()
    if not row:
        flash('VLAN pool exhausted!', 'error')
        cur.close()
        db.close()
        return redirect(url_for('devices'))

    vlan_id = row[0]
    cur.execute("""
        INSERT INTO vlan_assignments (identity, ssid, auth_type, vlan_id, vlan_type, name)
        VALUES (%s, %s, 'mac', %s, %s, %s)
    """, (mac, ssid, vlan_id, vtype, name))

    cur.close()
    db.close()
    flash(f'Device {mac} on {ssid} → VLAN {vlan_id} (type: {vtype})', 'success')
    return redirect(url_for('devices'))


@app.route('/devices/<mac>/<path:ssid>/delete', methods=['POST'])
def device_delete(mac, ssid):
    db = get_db()
    cur = db.cursor()
    cur.execute("SELECT vlan_id FROM vlan_assignments WHERE identity = %s AND ssid = %s", (mac, ssid))
    row = cur.fetchone()
    if row:
        cur.execute("DELETE FROM vlan_assignments WHERE identity = %s AND ssid = %s", (mac, ssid))
        cur.execute("UPDATE vlan_pool SET in_use = false, vlan_type = NULL, assigned_to = NULL, assigned_ssid = NULL, assigned_at = NULL WHERE vlan_id = %s", (row[0],))
        flash(f'Device {mac} on {ssid} removed. VLAN {row[0]} released.', 'success')
    else:
        flash(f'Device not found.', 'error')
    cur.close()
    db.close()
    return redirect(url_for('devices'))


# ─── Users ───────────────────────────────────────────────────

@app.route('/users')
def users():
    db = get_db()
    cur = db.cursor(cursor_factory=RealDictCursor)
    cur.execute("SELECT * FROM vlan_assignments WHERE auth_type = 'enterprise' ORDER BY identity, ssid")
    user_list = cur.fetchall()
    cur.close()
    db.close()
    return render_template('users.html', users=user_list)


@app.route('/users/add', methods=['POST'])
def user_add():
    username = request.form.get('username', '').strip()
    password = request.form.get('password', '').strip()
    ssid = request.form.get('ssid', '').strip()
    name = request.form.get('name', '').strip() or username

    if not username or not password or not ssid:
        flash('Username, password, and SSID required.', 'error')
        return redirect(url_for('users'))

    db = get_db()
    cur = db.cursor()

    cur.execute("SELECT COUNT(*) FROM vlan_assignments WHERE identity = %s AND ssid = %s", (username, ssid))
    if cur.fetchone()[0] > 0:
        flash(f'User "{username}" on {ssid} already exists.', 'error')
        cur.close()
        db.close()
        return redirect(url_for('users'))

    cur.execute("""
        UPDATE vlan_pool SET in_use = true, vlan_type = 'e', assigned_to = %s, assigned_ssid = %s, assigned_at = NOW()
        WHERE vlan_id = (SELECT vlan_id FROM vlan_pool WHERE in_use = false ORDER BY vlan_id LIMIT 1 FOR UPDATE SKIP LOCKED)
        RETURNING vlan_id
    """, (username, ssid))
    row = cur.fetchone()
    if not row:
        flash('VLAN pool exhausted!', 'error')
        cur.close()
        db.close()
        return redirect(url_for('users'))

    vlan_id = row[0]
    cur.execute("""
        INSERT INTO vlan_assignments (identity, ssid, auth_type, password, vlan_id, vlan_type, name)
        VALUES (%s, %s, 'enterprise', %s, %s, 'e', %s)
    """, (username, ssid, password, vlan_id, name))

    cur.close()
    db.close()
    flash(f'User "{username}" on {ssid} → VLAN {vlan_id}', 'success')
    return redirect(url_for('users'))


@app.route('/users/<username>/<path:ssid>/delete', methods=['POST'])
def user_delete(username, ssid):
    db = get_db()
    cur = db.cursor()
    cur.execute("SELECT vlan_id FROM vlan_assignments WHERE identity = %s AND ssid = %s", (username, ssid))
    row = cur.fetchone()
    if row:
        cur.execute("DELETE FROM vlan_assignments WHERE identity = %s AND ssid = %s", (username, ssid))
        cur.execute("UPDATE vlan_pool SET in_use = false, vlan_type = NULL, assigned_to = NULL, assigned_ssid = NULL, assigned_at = NULL WHERE vlan_id = %s", (row[0],))
        flash(f'User "{username}" on {ssid} removed. VLAN {row[0]} released.', 'success')
    else:
        flash(f'User not found.', 'error')
    cur.close()
    db.close()
    return redirect(url_for('users'))


@app.route('/users/<username>/<path:ssid>/password', methods=['POST'])
def user_password(username, ssid):
    new_pass = request.form.get('password', '').strip()
    if not new_pass:
        flash('Password cannot be empty.', 'error')
        return redirect(url_for('users'))
    db = get_db()
    cur = db.cursor()
    cur.execute("UPDATE vlan_assignments SET password = %s, updated_at = NOW() WHERE identity = %s AND ssid = %s",
                (new_pass, username, ssid))
    cur.close()
    db.close()
    flash(f'Password updated for "{username}" on {ssid}.', 'success')
    return redirect(url_for('users'))


# ─── Logs ────────────────────────────────────────────────────

@app.route('/logs')
def logs():
    limit = request.args.get('limit', 50, type=int)
    db = get_db()
    cur = db.cursor(cursor_factory=RealDictCursor)
    cur.execute("SELECT * FROM auth_log ORDER BY ts DESC LIMIT %s", (limit,))
    log_list = cur.fetchall()
    cur.close()
    db.close()
    return render_template('logs.html', logs=log_list, limit=limit)


if __name__ == '__main__':
    app.run(host='0.0.0.0', port=8443, ssl_context='adhoc', debug=True)
