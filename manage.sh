#!/bin/bash
# FreeRADIUS Per-Device VLAN Management CLI v2
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
FreeRADIUS Per-Device VLAN Management v2

ENTERPRISE USERS:
  ./manage.sh add-user <username> <password> <ssid> [--name "..."]
  ./manage.sh remove-user <username> <ssid>
  ./manage.sh list-users
  ./manage.sh change-password <username> <ssid> <new-password>

DEVICES (MAC Auth):
  ./manage.sh add-device <mac> <ssid> [--name "..."]
  ./manage.sh remove-device <mac> <ssid>
  ./manage.sh list-devices
  ./manage.sh remove-all-for-mac <mac>

POOL / LOGS:
  ./manage.sh pool-status
  ./manage.sh auth-log [--last N]
  ./manage.sh list-all

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
# ENTERPRISE USER MANAGEMENT
# ============================================================

cmd_add_user() {
    local username="" password="" ssid="" name=""
    username="$1"; shift
    password="$1"; shift
    ssid="$1"; shift

    while [[ $# -gt 0 ]]; do
        case "$1" in
            --name) name="$2"; shift 2 ;;
            *) echo -e "${RED}Unknown option: $1${NC}"; exit 1 ;;
        esac
    done

    if [ -z "$username" ] || [ -z "$password" ] || [ -z "$ssid" ]; then
        echo -e "${RED}Usage: ./manage.sh add-user <username> <password> <ssid> [--name \"...\"]${NC}"
        exit 1
    fi

    local existing
    existing=$(run_sql "SELECT count(*) FROM vlan_assignments WHERE identity='$username' AND ssid='$ssid';")
    if [ "$existing" -gt 0 ]; then
        echo -e "${RED}User '$username' on SSID '$ssid' already exists.${NC}"
        exit 1
    fi

    local vlan
    vlan=$(run_sql "UPDATE vlan_pool SET in_use = true, vlan_type = 'e', assigned_to = '$username', assigned_ssid = '$ssid', assigned_at = NOW()
        WHERE vlan_id = (SELECT vlan_id FROM vlan_pool WHERE in_use = false ORDER BY vlan_id LIMIT 1 FOR UPDATE SKIP LOCKED)
        RETURNING vlan_id;")

    if [ -z "$vlan" ]; then
        echo -e "${RED}VLAN pool exhausted!${NC}"
        exit 1
    fi

    run_sql "INSERT INTO vlan_assignments (identity, ssid, auth_type, password, vlan_id, vlan_type, name)
        VALUES ('$username', '$ssid', 'enterprise', '$password', $vlan, 'e', '${name:-$username}');"

    echo -e "${GREEN}User '$username' on '$ssid' → VLAN $vlan${NC}"
}

cmd_remove_user() {
    local username="$1" ssid="$2"
    if [ -z "$username" ] || [ -z "$ssid" ]; then
        echo -e "${RED}Usage: ./manage.sh remove-user <username> <ssid>${NC}"
        exit 1
    fi

    local vlan
    vlan=$(run_sql "SELECT vlan_id FROM vlan_assignments WHERE identity='$username' AND ssid='$ssid';")
    if [ -z "$vlan" ]; then
        echo -e "${RED}User '$username' on '$ssid' not found.${NC}"
        exit 1
    fi

    run_sql "DELETE FROM vlan_assignments WHERE identity='$username' AND ssid='$ssid';"
    run_sql "UPDATE vlan_pool SET in_use = false, vlan_type = NULL, assigned_to = NULL, assigned_ssid = NULL, assigned_at = NULL WHERE vlan_id = $vlan;"

    echo -e "${GREEN}User '$username' on '$ssid' removed. VLAN $vlan released.${NC}"
}

cmd_list_users() {
    echo -e "${YELLOW}=== Enterprise Users ===${NC}"
    echo ""
    printf "%-15s %-25s %-6s %-4s %-20s\n" "USERNAME" "SSID" "VLAN" "TYPE" "NAME"
    printf "%-15s %-25s %-6s %-4s %-20s\n" "--------" "----" "----" "----" "----"
    run_sql "SELECT identity, ssid, vlan_id, vlan_type, COALESCE(name, '') FROM vlan_assignments WHERE auth_type = 'enterprise' ORDER BY identity, ssid;" | while IFS='|' read -r user ssid vlan vtype name; do
        [ -z "$user" ] && continue
        printf "%-15s %-25s %-6s %-4s %-20s\n" "$user" "$ssid" "$vlan" "$vtype" "$name"
    done
}

cmd_change_password() {
    local username="$1" ssid="$2" password="$3"
    if [ -z "$username" ] || [ -z "$ssid" ] || [ -z "$password" ]; then
        echo -e "${RED}Usage: ./manage.sh change-password <username> <ssid> <new-password>${NC}"
        exit 1
    fi
    run_sql "UPDATE vlan_assignments SET password='$password', updated_at=NOW() WHERE identity='$username' AND ssid='$ssid';"
    echo -e "${GREEN}Password updated for '$username' on '$ssid'.${NC}"
}

# ============================================================
# DEVICE MANAGEMENT (MAC Auth)
# ============================================================

cmd_add_device() {
    local mac="" ssid="" name=""
    mac=$(normalize_mac "$1"); shift
    ssid="$1"; shift

    while [[ $# -gt 0 ]]; do
        case "$1" in
            --name) name="$2"; shift 2 ;;
            *) echo -e "${RED}Unknown option: $1${NC}"; exit 1 ;;
        esac
    done

    if [ -z "$mac" ] || [ -z "$ssid" ]; then
        echo -e "${RED}Usage: ./manage.sh add-device <mac> <ssid> [--name \"...\"]${NC}"
        exit 1
    fi

    local existing
    existing=$(run_sql "SELECT count(*) FROM vlan_assignments WHERE identity='$mac' AND ssid='$ssid';")
    if [ "$existing" -gt 0 ]; then
        echo -e "${RED}Device '$mac' on SSID '$ssid' already exists.${NC}"
        exit 1
    fi

    # Determine type from SSID (simple: default 'i', check common patterns)
    local vtype="i"

    local vlan
    vlan=$(run_sql "UPDATE vlan_pool SET in_use = true, vlan_type = '$vtype', assigned_to = '$mac', assigned_ssid = '$ssid', assigned_at = NOW()
        WHERE vlan_id = (SELECT vlan_id FROM vlan_pool WHERE in_use = false ORDER BY vlan_id LIMIT 1 FOR UPDATE SKIP LOCKED)
        RETURNING vlan_id;")

    if [ -z "$vlan" ]; then
        echo -e "${RED}VLAN pool exhausted!${NC}"
        exit 1
    fi

    run_sql "INSERT INTO vlan_assignments (identity, ssid, auth_type, vlan_id, vlan_type, name)
        VALUES ('$mac', '$ssid', 'mac', $vlan, '$vtype', '${name:-Unknown}');"

    echo -e "${GREEN}Device '$mac' on '$ssid' → VLAN $vlan (type: $vtype)${NC}"
}

cmd_remove_device() {
    local mac ssid
    mac=$(normalize_mac "$1")
    ssid="$2"
    if [ -z "$mac" ] || [ -z "$ssid" ]; then
        echo -e "${RED}Usage: ./manage.sh remove-device <mac> <ssid>${NC}"
        exit 1
    fi

    local vlan
    vlan=$(run_sql "SELECT vlan_id FROM vlan_assignments WHERE identity='$mac' AND ssid='$ssid';")
    if [ -z "$vlan" ]; then
        echo -e "${RED}Device '$mac' on '$ssid' not found.${NC}"
        exit 1
    fi

    run_sql "DELETE FROM vlan_assignments WHERE identity='$mac' AND ssid='$ssid';"
    run_sql "UPDATE vlan_pool SET in_use = false, vlan_type = NULL, assigned_to = NULL, assigned_ssid = NULL, assigned_at = NULL WHERE vlan_id = $vlan;"

    echo -e "${GREEN}Device '$mac' on '$ssid' removed. VLAN $vlan released.${NC}"
}

cmd_remove_all_for_mac() {
    local mac
    mac=$(normalize_mac "$1")
    if [ -z "$mac" ]; then
        echo -e "${RED}Usage: ./manage.sh remove-all-for-mac <mac>${NC}"
        exit 1
    fi

    local count
    count=$(run_sql "SELECT count(*) FROM vlan_assignments WHERE identity='$mac';")
    if [ "$count" -eq 0 ]; then
        echo -e "${YELLOW}No assignments found for '$mac'.${NC}"
        exit 0
    fi

    # Release VLANs
    run_sql "UPDATE vlan_pool SET in_use = false, vlan_type = NULL, assigned_to = NULL, assigned_ssid = NULL, assigned_at = NULL
        WHERE vlan_id IN (SELECT vlan_id FROM vlan_assignments WHERE identity='$mac');"
    run_sql "DELETE FROM vlan_assignments WHERE identity='$mac';"

    echo -e "${GREEN}Removed $count assignment(s) for MAC '$mac'. VLANs released.${NC}"
}

cmd_list_devices() {
    echo -e "${YELLOW}=== MAC Devices ===${NC}"
    echo ""
    printf "%-14s %-25s %-6s %-4s %-20s\n" "MAC" "SSID" "VLAN" "TYPE" "NAME"
    printf "%-14s %-25s %-6s %-4s %-20s\n" "---" "----" "----" "----" "----"
    run_sql "SELECT identity, ssid, vlan_id, vlan_type, COALESCE(name, '') FROM vlan_assignments WHERE auth_type = 'mac' ORDER BY identity, ssid;" | while IFS='|' read -r mac ssid vlan vtype name; do
        [ -z "$mac" ] && continue
        printf "%-14s %-25s %-6s %-4s %-20s\n" "$mac" "$ssid" "$vlan" "$vtype" "$name"
    done
}

# ============================================================
# POOL / LOGS / ALL
# ============================================================

cmd_list_all() {
    echo -e "${YELLOW}=== All VLAN Assignments ===${NC}"
    echo ""
    printf "%-15s %-8s %-25s %-6s %-4s %-20s\n" "IDENTITY" "AUTH" "SSID" "VLAN" "TYPE" "NAME"
    printf "%-15s %-8s %-25s %-6s %-4s %-20s\n" "--------" "----" "----" "----" "----" "----"
    run_sql "SELECT identity, auth_type, ssid, vlan_id, vlan_type, COALESCE(name, '') FROM vlan_assignments ORDER BY vlan_id;" | while IFS='|' read -r id auth ssid vlan vtype name; do
        [ -z "$id" ] && continue
        printf "%-15s %-8s %-25s %-6s %-4s %-20s\n" "$id" "$auth" "$ssid" "$vlan" "$vtype" "$name"
    done
}

cmd_pool_status() {
    echo -e "${YELLOW}=== VLAN Pool Status ===${NC}"
    echo ""
    local total used free
    total=$(run_sql "SELECT count(*) FROM vlan_pool;")
    used=$(run_sql "SELECT count(*) FROM vlan_pool WHERE in_use=true;")
    free=$((total - used))
    local pct=0
    if [ "$total" -gt 0 ]; then pct=$((used * 100 / total)); fi

    local color=$GREEN
    if [ "$pct" -ge 80 ]; then color=$RED; elif [ "$pct" -ge 50 ]; then color=$YELLOW; fi

    printf "  Total: ${color}%d/%d used (%d%%)${NC} — %d free\n" "$used" "$total" "$pct" "$free"

    echo ""
    echo -e "${CYAN}By type:${NC}"
    for t in e i o g; do
        local tcount
        tcount=$(run_sql "SELECT count(*) FROM vlan_pool WHERE in_use=true AND vlan_type='$t';")
        [ "$tcount" -gt 0 ] && printf "  %s: %d VLANs\n" "$t" "$tcount"
    done

    echo ""
    echo -n "  Next free: "
    run_sql "SELECT vlan_id FROM vlan_pool WHERE in_use=false ORDER BY vlan_id LIMIT 3;" | tr '\n' ' '
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
    printf "%-20s %-14s %-15s %-25s %-8s %-6s %-4s\n" "TIME" "MAC" "USER" "SSID" "RESULT" "VLAN" "TYPE"
    printf "%-20s %-14s %-15s %-25s %-8s %-6s %-4s\n" "----" "---" "----" "----" "------" "----" "----"
    run_sql "SELECT to_char(ts, 'YYYY-MM-DD HH24:MI:SS'), COALESCE(mac,''), COALESCE(username,''), COALESCE(ssid,''), result, COALESCE(vlan_id::text,''), COALESCE(vlan_type,'')
        FROM auth_log ORDER BY ts DESC LIMIT $count;" | while IFS='|' read -r ts mac user ssid result vlan vtype; do
        [ -z "$ts" ] && continue
        local color=$GREEN
        if [ "$result" = "reject" ]; then color=$RED; fi
        printf "%-20s %-14s %-15s %-25s ${color}%-8s${NC} %-6s %-4s\n" "$ts" "$mac" "$user" "$ssid" "$result" "$vlan" "$vtype"
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
    add-user)           shift; cmd_add_user "$@" ;;
    remove-user)        cmd_remove_user "$2" "$3" ;;
    list-users)         cmd_list_users ;;
    change-password)    cmd_change_password "$2" "$3" "$4" ;;
    add-device)         shift; cmd_add_device "$@" ;;
    remove-device)      cmd_remove_device "$2" "$3" ;;
    remove-all-for-mac) cmd_remove_all_for_mac "$2" ;;
    list-devices)       cmd_list_devices ;;
    list-all)           cmd_list_all ;;
    pool-status)        cmd_pool_status ;;
    auth-log)           shift; cmd_auth_log "$@" ;;
    test)               cmd_test "$2" "$3" ;;
    *)                  usage ;;
esac
