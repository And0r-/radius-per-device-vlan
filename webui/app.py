"""
FreeRADIUS Per-Device VLAN — Web Management UI
"""

import os
import re
from functools import wraps
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

    pools = {}
    for pool in ('offline', 'online'):
        cur.execute("SELECT COUNT(*) as total FROM vlan_pool WHERE pool = %s", (pool,))
        total = cur.fetchone()['total']
        cur.execute("SELECT COUNT(*) as used FROM vlan_pool WHERE pool = %s AND in_use = true", (pool,))
        used = cur.fetchone()['used']
        pools[pool] = {'total': total, 'used': used, 'free': total - used,
                       'pct': int(used / total * 100) if total else 0}

    cur.execute("SELECT COUNT(*) as c FROM devices WHERE username IS NOT NULL")
    user_count = cur.fetchone()['c']
    cur.execute("SELECT COUNT(*) as c FROM devices WHERE username IS NULL")
    device_count = cur.fetchone()['c']

    cur.execute("""SELECT * FROM auth_log ORDER BY ts DESC LIMIT 10""")
    recent_logs = cur.fetchall()

    cur.close()
    db.close()
    return render_template('dashboard.html', pools=pools, user_count=user_count,
                           device_count=device_count, recent_logs=recent_logs)


# ─── Devices ─────────────────────────────────────────────────

@app.route('/devices')
def devices():
    db = get_db()
    cur = db.cursor(cursor_factory=RealDictCursor)
    cur.execute("""
        SELECT d.*, v.pool FROM devices d
        LEFT JOIN vlan_pool v ON d.vlan_id = v.vlan_id
        WHERE d.username IS NULL
        ORDER BY v.pool, d.vlan_id
    """)
    device_list = cur.fetchall()
    cur.close()
    db.close()
    return render_template('devices.html', devices=device_list)


@app.route('/devices/add', methods=['POST'])
def device_add():
    mac = normalize_mac(request.form.get('mac', ''))
    name = request.form.get('name', '').strip() or 'Unknown'
    pool = request.form.get('pool', 'online')

    if not is_valid_mac(mac):
        flash('Invalid MAC address. Use format: aabbccddeeff', 'error')
        return redirect(url_for('devices'))

    if pool not in ('online', 'offline'):
        flash('Pool must be online or offline.', 'error')
        return redirect(url_for('devices'))

    category = 'iot-offline' if pool == 'offline' else 'iot'

    db = get_db()
    cur = db.cursor()

    cur.execute("SELECT COUNT(*) FROM devices WHERE mac = %s", (mac,))
    if cur.fetchone()[0] > 0:
        flash(f'Device {mac} already exists.', 'error')
        cur.close()
        db.close()
        return redirect(url_for('devices'))

    cur.execute("""
        UPDATE vlan_pool SET in_use = true, assigned_mac = %s, assigned_at = NOW()
        WHERE vlan_id = (
            SELECT vlan_id FROM vlan_pool WHERE pool = %s AND in_use = false
            ORDER BY vlan_id LIMIT 1 FOR UPDATE SKIP LOCKED
        ) RETURNING vlan_id
    """, (mac, pool))
    row = cur.fetchone()

    if not row:
        flash(f'Pool "{pool}" exhausted!', 'error')
        cur.close()
        db.close()
        return redirect(url_for('devices'))

    vlan_id = row[0]
    cur.execute("""
        INSERT INTO devices (mac, vlan_id, device_name, ssid_category, created_at, updated_at)
        VALUES (%s, %s, %s, %s, NOW(), NOW())
    """, (mac, vlan_id, name, category))

    cur.close()
    db.close()
    flash(f'Device {mac} added → VLAN {vlan_id} ({pool})', 'success')
    return redirect(url_for('devices'))


@app.route('/devices/<mac>/delete', methods=['POST'])
def device_delete(mac):
    db = get_db()
    cur = db.cursor()
    cur.execute("SELECT vlan_id FROM devices WHERE mac = %s", (mac,))
    row = cur.fetchone()
    if row:
        cur.execute("DELETE FROM devices WHERE mac = %s", (mac,))
        cur.execute("UPDATE vlan_pool SET in_use = false, assigned_mac = NULL, assigned_at = NULL WHERE vlan_id = %s", (row[0],))
        flash(f'Device {mac} removed. VLAN {row[0]} released.', 'success')
    else:
        flash(f'Device {mac} not found.', 'error')
    cur.close()
    db.close()
    return redirect(url_for('devices'))


@app.route('/devices/<mac>/move', methods=['POST'])
def device_move(mac):
    new_pool = request.form.get('pool', '')
    if new_pool not in ('online', 'offline'):
        flash('Pool must be online or offline.', 'error')
        return redirect(url_for('devices'))

    db = get_db()
    cur = db.cursor(cursor_factory=RealDictCursor)

    cur.execute("SELECT d.vlan_id, v.pool FROM devices d JOIN vlan_pool v ON d.vlan_id = v.vlan_id WHERE d.mac = %s", (mac,))
    device = cur.fetchone()
    if not device:
        flash(f'Device {mac} not found.', 'error')
        cur.close()
        db.close()
        return redirect(url_for('devices'))

    if device['pool'] == new_pool:
        flash(f'Device already in pool {new_pool}.', 'error')
        cur.close()
        db.close()
        return redirect(url_for('devices'))

    cur.execute("""
        UPDATE vlan_pool SET in_use = true, assigned_mac = %s, assigned_at = NOW()
        WHERE vlan_id = (
            SELECT vlan_id FROM vlan_pool WHERE pool = %s AND in_use = false
            ORDER BY vlan_id LIMIT 1 FOR UPDATE SKIP LOCKED
        ) RETURNING vlan_id
    """, (mac, new_pool))
    row = cur.fetchone()
    if not row:
        flash(f'Pool "{new_pool}" exhausted!', 'error')
        cur.close()
        db.close()
        return redirect(url_for('devices'))

    new_vlan = row['vlan_id']
    old_vlan = device['vlan_id']
    new_cat = 'iot-offline' if new_pool == 'offline' else 'iot'

    cur.execute("UPDATE vlan_pool SET in_use = false, assigned_mac = NULL, assigned_at = NULL WHERE vlan_id = %s", (old_vlan,))
    cur.execute("UPDATE devices SET vlan_id = %s, ssid_category = %s, updated_at = NOW() WHERE mac = %s", (new_vlan, new_cat, mac))

    cur.close()
    db.close()
    flash(f'Device {mac} moved: VLAN {old_vlan} → {new_vlan} ({new_pool})', 'success')
    return redirect(url_for('devices'))


# ─── Users ───────────────────────────────────────────────────

@app.route('/users')
def users():
    db = get_db()
    cur = db.cursor(cursor_factory=RealDictCursor)
    cur.execute("""
        SELECT d.*, v.pool FROM devices d
        LEFT JOIN vlan_pool v ON d.vlan_id = v.vlan_id
        WHERE d.username IS NOT NULL
        ORDER BY d.username
    """)
    user_list = cur.fetchall()
    cur.close()
    db.close()
    return render_template('users.html', users=user_list)


@app.route('/users/add', methods=['POST'])
def user_add():
    username = request.form.get('username', '').strip()
    password = request.form.get('password', '').strip()
    mac = normalize_mac(request.form.get('mac', ''))
    name = request.form.get('name', '').strip() or username

    if not username or not password:
        flash('Username and password required.', 'error')
        return redirect(url_for('users'))
    if not is_valid_mac(mac):
        flash('Valid MAC address required (format: aabbccddeeff).', 'error')
        return redirect(url_for('users'))

    db = get_db()
    cur = db.cursor()

    cur.execute("SELECT COUNT(*) FROM devices WHERE username = %s", (username,))
    if cur.fetchone()[0] > 0:
        flash(f'User "{username}" already exists.', 'error')
        cur.close()
        db.close()
        return redirect(url_for('users'))

    cur.execute("SELECT COUNT(*) FROM devices WHERE mac = %s", (mac,))
    if cur.fetchone()[0] > 0:
        flash(f'MAC {mac} already registered.', 'error')
        cur.close()
        db.close()
        return redirect(url_for('users'))

    cur.execute("""
        UPDATE vlan_pool SET in_use = true, assigned_mac = %s, assigned_at = NOW()
        WHERE vlan_id = (
            SELECT vlan_id FROM vlan_pool WHERE pool = 'online' AND in_use = false
            ORDER BY vlan_id LIMIT 1 FOR UPDATE SKIP LOCKED
        ) RETURNING vlan_id
    """, (mac,))
    row = cur.fetchone()
    if not row:
        flash('Online pool exhausted!', 'error')
        cur.close()
        db.close()
        return redirect(url_for('users'))

    vlan_id = row[0]
    cur.execute("""
        INSERT INTO devices (mac, username, password, vlan_id, device_name, ssid_category, created_at, updated_at)
        VALUES (%s, %s, %s, %s, %s, 'secure', NOW(), NOW())
    """, (mac, username, password, vlan_id, name))

    cur.close()
    db.close()
    flash(f'User "{username}" added → VLAN {vlan_id}', 'success')
    return redirect(url_for('users'))


@app.route('/users/<username>/delete', methods=['POST'])
def user_delete(username):
    db = get_db()
    cur = db.cursor()
    cur.execute("SELECT vlan_id FROM devices WHERE username = %s", (username,))
    row = cur.fetchone()
    if row:
        cur.execute("DELETE FROM devices WHERE username = %s", (username,))
        cur.execute("UPDATE vlan_pool SET in_use = false, assigned_mac = NULL, assigned_at = NULL WHERE vlan_id = %s", (row[0],))
        flash(f'User "{username}" removed. VLAN {row[0]} released.', 'success')
    else:
        flash(f'User "{username}" not found.', 'error')
    cur.close()
    db.close()
    return redirect(url_for('users'))


@app.route('/users/<username>/password', methods=['POST'])
def user_password(username):
    new_pass = request.form.get('password', '').strip()
    if not new_pass:
        flash('Password cannot be empty.', 'error')
        return redirect(url_for('users'))
    db = get_db()
    cur = db.cursor()
    cur.execute("UPDATE devices SET password = %s, updated_at = NOW() WHERE username = %s", (new_pass, username))
    cur.close()
    db.close()
    flash(f'Password updated for "{username}".', 'success')
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
