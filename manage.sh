#!/bin/bash
# FreeRADIUS Per-Device VLAN Management CLI
set -e

COMPOSE="docker compose"
PSQL="$COMPOSE exec -T postgres psql -U radius -d radius -t -A"

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
CYAN='\033[0;36m'
NC='\033[0m'

usage() {
    cat << 'EOF'
FreeRADIUS Per-Device VLAN Management

USERS (Enterprise Auth):
  ./manage.sh add-user <username> <password> [--device-name "..."] [--mac <mac>]
  ./manage.sh remove-user <username>
  ./manage.sh list-users
  ./manage.sh change-password <username> <new-password>

DEVICES (MAC Auth):
  ./manage.sh add-device <mac> [--name "..."] [--pool offline|online]
  ./manage.sh remove-device <mac>
  ./manage.sh list-devices
  ./manage.sh move-device <mac> offline|online

POOL / LOGS:
  ./manage.sh pool-status
  ./manage.sh auth-log [--last N]

TESTING:
  ./manage.sh test <username|mac> [password]
EOF
}

run_sql() {
    $PSQL -c "$1" 2>/dev/null | head -1
}

normalize_mac() {
    echo "$1" | tr -d ':-.' | tr 'A-F' 'a-f'
}

# ============================================================
# USER MANAGEMENT
# ============================================================

cmd_add_user() {
    local username="" password="" device_name="" mac=""
    username="$1"; shift
    password="$1"; shift

    while [[ $# -gt 0 ]]; do
        case "$1" in
            --device-name) device_name="$2"; shift 2 ;;
            --name) device_name="$2"; shift 2 ;;
            --mac) mac=$(normalize_mac "$2"); shift 2 ;;
            *) echo -e "${RED}Unknown option: $1${NC}"; exit 1 ;;
        esac
    done

    if [ -z "$username" ] || [ -z "$password" ]; then
        echo -e "${RED}Usage: ./manage.sh add-user <username> <password> [--device-name \"...\"] [--mac <mac>]${NC}"
        exit 1
    fi

    # Check if username exists
    local existing
    existing=$(run_sql "SELECT count(*) FROM devices WHERE username='$username';")
    if [ "$existing" -gt 0 ]; then
        echo -e "${RED}User '$username' already exists.${NC}"
        exit 1
    fi

    if [ -z "$mac" ]; then
        echo -e "${RED}MAC address required for enterprise users (--mac <mac>).${NC}"
        exit 1
    fi

    # Assign VLAN from online pool
    local vlan
    vlan=$(run_sql "UPDATE vlan_pool SET in_use = true, assigned_mac = '$mac', assigned_at = NOW()
        WHERE vlan_id = (SELECT vlan_id FROM vlan_pool WHERE pool = 'online' AND in_use = false ORDER BY vlan_id LIMIT 1 FOR UPDATE SKIP LOCKED)
        RETURNING vlan_id;")

    if [ -z "$vlan" ]; then
        echo -e "${RED}Online pool exhausted! Cannot assign VLAN.${NC}"
        exit 1
    fi

    run_sql "INSERT INTO devices (mac, username, password, vlan_id, device_name, ssid_category, created_at, updated_at)
        VALUES ('$mac', '$username', '$password', $vlan, '${device_name:-$username}', 'secure', NOW(), NOW());"

    echo -e "${GREEN}User '$username' added → MAC: $mac, VLAN: $vlan${NC}"
}

cmd_remove_user() {
    local username="$1"
    if [ -z "$username" ]; then
        echo -e "${RED}Usage: ./manage.sh remove-user <username>${NC}"
        exit 1
    fi

    local vlan mac
    vlan=$(run_sql "SELECT vlan_id FROM devices WHERE username='$username';")
    mac=$(run_sql "SELECT mac FROM devices WHERE username='$username';")

    if [ -z "$vlan" ]; then
        echo -e "${RED}User '$username' not found.${NC}"
        exit 1
    fi

    run_sql "DELETE FROM devices WHERE username='$username';"
    run_sql "UPDATE vlan_pool SET in_use = false, assigned_mac = NULL, assigned_at = NULL WHERE vlan_id = $vlan;"

    echo -e "${GREEN}User '$username' removed. VLAN $vlan released.${NC}"
}

cmd_list_users() {
    echo -e "${YELLOW}=== Enterprise Users ===${NC}"
    echo ""
    printf "%-15s %-14s %-6s %-25s\n" "USERNAME" "MAC" "VLAN" "DEVICE"
    printf "%-15s %-14s %-6s %-25s\n" "--------" "---" "----" "------"
    run_sql "SELECT username, mac, vlan_id, COALESCE(device_name, '') FROM devices WHERE username IS NOT NULL ORDER BY username;" | while IFS='|' read -r user mac vlan name; do
        printf "%-15s %-14s %-6s %-25s\n" "$user" "$mac" "$vlan" "$name"
    done
}

cmd_change_password() {
    local username="$1" password="$2"
    if [ -z "$username" ] || [ -z "$password" ]; then
        echo -e "${RED}Usage: ./manage.sh change-password <username> <new-password>${NC}"
        exit 1
    fi
    run_sql "UPDATE devices SET password='$password', updated_at=NOW() WHERE username='$username';"
    echo -e "${GREEN}Password updated for '$username'.${NC}"
}

# ============================================================
# DEVICE MANAGEMENT
# ============================================================

cmd_add_device() {
    local mac="" device_name="" pool="online"
    mac=$(normalize_mac "$1"); shift

    while [[ $# -gt 0 ]]; do
        case "$1" in
            --name) device_name="$2"; shift 2 ;;
            --pool) pool="$2"; shift 2 ;;
            *) echo -e "${RED}Unknown option: $1${NC}"; exit 1 ;;
        esac
    done

    if [ -z "$mac" ]; then
        echo -e "${RED}Usage: ./manage.sh add-device <mac> [--name \"...\"] [--pool offline|online]${NC}"
        exit 1
    fi

    local existing
    existing=$(run_sql "SELECT count(*) FROM devices WHERE mac='$mac';")
    if [ "$existing" -gt 0 ]; then
        echo -e "${RED}Device '$mac' already exists.${NC}"
        exit 1
    fi

    local category
    if [ "$pool" = "offline" ]; then category="iot-offline"; else category="iot"; fi

    local vlan
    vlan=$(run_sql "UPDATE vlan_pool SET in_use = true, assigned_mac = '$mac', assigned_at = NOW()
        WHERE vlan_id = (SELECT vlan_id FROM vlan_pool WHERE pool = '$pool' AND in_use = false ORDER BY vlan_id LIMIT 1 FOR UPDATE SKIP LOCKED)
        RETURNING vlan_id;")

    if [ -z "$vlan" ]; then
        echo -e "${RED}Pool '$pool' exhausted! Cannot assign VLAN.${NC}"
        exit 1
    fi

    run_sql "INSERT INTO devices (mac, vlan_id, device_name, ssid_category, created_at, updated_at)
        VALUES ('$mac', $vlan, '${device_name:-Unknown}', '$category', NOW(), NOW());"

    echo -e "${GREEN}Device '$mac' added → VLAN: $vlan (pool: $pool)${NC}"
}

cmd_remove_device() {
    local mac
    mac=$(normalize_mac "$1")
    if [ -z "$mac" ]; then
        echo -e "${RED}Usage: ./manage.sh remove-device <mac>${NC}"
        exit 1
    fi

    local vlan
    vlan=$(run_sql "SELECT vlan_id FROM devices WHERE mac='$mac';")

    if [ -z "$vlan" ]; then
        echo -e "${RED}Device '$mac' not found.${NC}"
        exit 1
    fi

    run_sql "DELETE FROM devices WHERE mac='$mac';"
    run_sql "UPDATE vlan_pool SET in_use = false, assigned_mac = NULL, assigned_at = NULL WHERE vlan_id = $vlan;"

    echo -e "${GREEN}Device '$mac' removed. VLAN $vlan released.${NC}"
}

cmd_list_devices() {
    echo -e "${YELLOW}=== All Devices ===${NC}"
    echo ""
    printf "%-14s %-6s %-8s %-15s %-25s\n" "MAC" "VLAN" "POOL" "CATEGORY" "NAME"
    printf "%-14s %-6s %-8s %-15s %-25s\n" "---" "----" "----" "--------" "----"
    run_sql "SELECT d.mac, d.vlan_id, v.pool, COALESCE(d.ssid_category,''), COALESCE(d.device_name,'')
        FROM devices d LEFT JOIN vlan_pool v ON d.vlan_id = v.vlan_id
        ORDER BY v.pool, d.vlan_id;" | while IFS='|' read -r mac vlan pool cat name; do
        printf "%-14s %-6s %-8s %-15s %-25s\n" "$mac" "$vlan" "$pool" "$cat" "$name"
    done
}

cmd_move_device() {
    local mac new_pool
    mac=$(normalize_mac "$1")
    new_pool="$2"

    if [ -z "$mac" ] || [ -z "$new_pool" ]; then
        echo -e "${RED}Usage: ./manage.sh move-device <mac> offline|online${NC}"
        exit 1
    fi

    if [ "$new_pool" != "offline" ] && [ "$new_pool" != "online" ]; then
        echo -e "${RED}Pool must be 'offline' or 'online'.${NC}"
        exit 1
    fi

    local old_vlan
    old_vlan=$(run_sql "SELECT vlan_id FROM devices WHERE mac='$mac';")

    if [ -z "$old_vlan" ]; then
        echo -e "${RED}Device '$mac' not found.${NC}"
        exit 1
    fi

    # Check current pool
    local current_pool
    current_pool=$(run_sql "SELECT pool FROM vlan_pool WHERE vlan_id=$old_vlan;")
    if [ "$current_pool" = "$new_pool" ]; then
        echo -e "${YELLOW}Device already in pool '$new_pool' (VLAN $old_vlan).${NC}"
        exit 0
    fi

    # Assign new VLAN from new pool
    local new_vlan
    new_vlan=$(run_sql "UPDATE vlan_pool SET in_use = true, assigned_mac = '$mac', assigned_at = NOW()
        WHERE vlan_id = (SELECT vlan_id FROM vlan_pool WHERE pool = '$new_pool' AND in_use = false ORDER BY vlan_id LIMIT 1 FOR UPDATE SKIP LOCKED)
        RETURNING vlan_id;")

    if [ -z "$new_vlan" ]; then
        echo -e "${RED}Pool '$new_pool' exhausted!${NC}"
        exit 1
    fi

    # Release old VLAN
    run_sql "UPDATE vlan_pool SET in_use = false, assigned_mac = NULL, assigned_at = NULL WHERE vlan_id = $old_vlan;"

    # Update device
    local new_cat
    if [ "$new_pool" = "offline" ]; then new_cat="iot-offline"; else new_cat="iot"; fi
    run_sql "UPDATE devices SET vlan_id = $new_vlan, ssid_category = '$new_cat', updated_at = NOW() WHERE mac = '$mac';"

    echo -e "${GREEN}Device '$mac' moved: VLAN $old_vlan ($current_pool) → VLAN $new_vlan ($new_pool)${NC}"
}

# ============================================================
# POOL / LOGS
# ============================================================

cmd_pool_status() {
    echo -e "${YELLOW}=== VLAN Pool Status ===${NC}"
    echo ""
    for pool in offline online; do
        local total free used
        total=$(run_sql "SELECT count(*) FROM vlan_pool WHERE pool='$pool';")
        used=$(run_sql "SELECT count(*) FROM vlan_pool WHERE pool='$pool' AND in_use=true;")
        free=$((total - used))
        local pct=0
        if [ "$total" -gt 0 ]; then pct=$((used * 100 / total)); fi

        local color=$GREEN
        if [ "$pct" -ge 80 ]; then color=$RED; elif [ "$pct" -ge 50 ]; then color=$YELLOW; fi

        local range
        if [ "$pool" = "offline" ]; then range="100-119"; else range="120-199"; fi

        printf "  %-8s (VLAN %s): ${color}%d/%d used (%d%%)${NC} — %d free\n" \
            "$pool" "$range" "$used" "$total" "$pct" "$free"
    done

    echo ""
    echo -e "${CYAN}Next free VLANs:${NC}"
    echo -n "  Offline: "
    run_sql "SELECT vlan_id FROM vlan_pool WHERE pool='offline' AND in_use=false ORDER BY vlan_id LIMIT 3;" | tr '\n' ' '
    echo ""
    echo -n "  Online:  "
    run_sql "SELECT vlan_id FROM vlan_pool WHERE pool='online' AND in_use=false ORDER BY vlan_id LIMIT 3;" | tr '\n' ' '
    echo ""
}

cmd_auth_log() {
    local count=20
    while [[ $# -gt 0 ]]; do
        case "$1" in
            --last) count="$2"; shift 2 ;;
            *) count="$1"; shift ;;
        esac
    done

    echo -e "${YELLOW}=== Auth Log (last $count) ===${NC}"
    echo ""
    printf "%-20s %-14s %-15s %-8s %-6s %-20s\n" "TIME" "MAC" "USER" "RESULT" "VLAN" "SSID"
    printf "%-20s %-14s %-15s %-8s %-6s %-20s\n" "----" "---" "----" "------" "----" "----"
    run_sql "SELECT to_char(ts, 'YYYY-MM-DD HH24:MI:SS'), COALESCE(mac,''), COALESCE(username,''), result, COALESCE(vlan_id::text,''), COALESCE(ssid,'')
        FROM auth_log ORDER BY ts DESC LIMIT $count;" | while IFS='|' read -r ts mac user result vlan ssid; do
        local color=$GREEN
        if [ "$result" = "reject" ]; then color=$RED; fi
        printf "%-20s %-14s %-15s ${color}%-8s${NC} %-6s %-20s\n" "$ts" "$mac" "$user" "$result" "$vlan" "$ssid"
    done
}

# ============================================================
# TEST
# ============================================================

cmd_test() {
    local identity="$1" password="$2"
    if [ -z "$identity" ]; then
        echo -e "${RED}Usage: ./manage.sh test <username|mac> [password]${NC}"
        exit 1
    fi
    # If no password and looks like MAC, use MAC as password
    if [ -z "$password" ]; then
        local normalized
        normalized=$(normalize_mac "$identity")
        if [[ "$normalized" =~ ^[0-9a-f]{12}$ ]]; then
            password="$normalized"
            identity="$normalized"
        else
            echo -e "${RED}Password required for enterprise auth.${NC}"
            exit 1
        fi
    fi
    echo -e "${CYAN}Testing auth for '$identity'...${NC}"
    $COMPOSE exec freeradius radtest "$identity" "$password" 127.0.0.1 0 testing123
}

# ============================================================
# MAIN
# ============================================================

case "${1:-}" in
    add-user)         shift; cmd_add_user "$@" ;;
    remove-user)      cmd_remove_user "$2" ;;
    list-users)       cmd_list_users ;;
    change-password)  cmd_change_password "$2" "$3" ;;
    add-device)       shift; cmd_add_device "$@" ;;
    remove-device)    cmd_remove_device "$2" ;;
    list-devices)     cmd_list_devices ;;
    move-device)      cmd_move_device "$2" "$3" ;;
    pool-status)      cmd_pool_status ;;
    auth-log)         shift; cmd_auth_log "$@" ;;
    test)             cmd_test "$2" "$3" ;;
    *)                usage ;;
esac
