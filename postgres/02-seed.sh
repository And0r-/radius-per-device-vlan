#!/bin/bash
# Seed initial enterprise users.
# Passwords and MACs come from environment variables — nothing hardcoded.
# This script runs automatically on first DB init via /docker-entrypoint-initdb.d/.
# Additional devices can be added later via manage.sh.
set -e

psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname "$POSTGRES_DB" <<-EOSQL

    -- Enterprise users (WPA3-Enterprise / EAP-PEAP)
    UPDATE vlan_pool SET in_use = true, assigned_mac = '${MAC_ANDI_DESKTOP}', assigned_at = NOW() WHERE vlan_id = 120;
    INSERT INTO devices (mac, username, password, vlan_id, device_name, ssid_category)
    VALUES ('${MAC_ANDI_DESKTOP}', 'andi-desktop', '${ANDI_DESKTOP_PASS}', 120, 'Desktop PC', 'secure');

    UPDATE vlan_pool SET in_use = true, assigned_mac = '${MAC_ANDI_MOBILE}', assigned_at = NOW() WHERE vlan_id = 121;
    INSERT INTO devices (mac, username, password, vlan_id, device_name, ssid_category)
    VALUES ('${MAC_ANDI_MOBILE}', 'andi-mobile', '${ANDI_MOBILE_PASS}', 121, 'Pixel 7 Pro', 'secure');

    UPDATE vlan_pool SET in_use = true, assigned_mac = '${MAC_WORK_LAPTOP}', assigned_at = NOW() WHERE vlan_id = 122;
    INSERT INTO devices (mac, username, password, vlan_id, device_name, ssid_category)
    VALUES ('${MAC_WORK_LAPTOP}', 'work-laptop', '${WORK_LAPTOP_PASS}', 122, 'Arbeitslaptop', 'secure');

EOSQL

echo "==> Seed data loaded (3 enterprise users). Add devices via manage.sh."
