<?php
/**
 * pfSense VLAN API — Per-device VLAN management with Floating Rules
 *
 * SAFETY: This script ONLY modifies resources it created (tracked in SQLite).
 * It will NEVER touch interfaces, rules, or config it doesn't own.
 * Every mutation checks ownership against the SQLite DB first.
 *
 * State: SQLite DB at /usr/local/www/api/daathnet.db
 *
 * Endpoints:
 *   GET    /health          — Health check
 *   POST   /vlan/create     — Create VLAN + interface + DHCP + add to floating rules
 *   POST   /vlan/rename     — Rename VLAN
 *   POST   /vlan/move       — Move VLAN between online/offline pools
 *   GET    /vlan/list       — List managed VLANs
 *   DELETE /vlan/{id}       — Remove VLAN completely
 *   POST   /floating/init   — (Re)create floating rules for all managed VLANs
 *   GET    /floating/status — Show floating rule state
 */

// pfSense bootstrap
require_once("config.inc");
require_once("interfaces.inc");
require_once("services.inc");
require_once("filter.inc");
require_once("util.inc");

// ─── Configuration ──────────────────────────────────────────

define('API_KEY', getenv('PFSENSE_API_KEY') ?: 'CHANGE_ME');
define('PARENT_IF', getenv('PFSENSE_PARENT_IF') ?: 'ix2');
define('VLAN_MIN', 100);
define('VLAN_MAX', 199);
define('INTERNAL_ALIAS', 'internal_networks');
define('DB_PATH', __DIR__ . '/daathnet.db');
define('TRACKER_BASE', 1700000000);

// ─── Request Handling ───────────────────────────────────────

header('Content-Type: application/json');

if (($_SERVER['HTTP_X_API_KEY'] ?? '') !== API_KEY) {
    respond(401, ['error' => 'Unauthorized']);
}

$method = $_SERVER['REQUEST_METHOD'];
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

if ($method === 'GET'    && $uri === '/health')          { respond(200, ['status' => 'ok', 'version' => '3.0']); }
if ($method === 'POST'   && $uri === '/vlan/create')     { handle_vlan_create(); }
if ($method === 'POST'   && $uri === '/vlan/rename')     { handle_vlan_rename(); }
if ($method === 'POST'   && $uri === '/vlan/move')       { handle_vlan_move(); }
if ($method === 'GET'    && $uri === '/vlan/list')        { handle_vlan_list(); }
if ($method === 'DELETE' && preg_match('#^/vlan/(\d+)$#', $uri, $m)) { handle_vlan_delete((int)$m[1]); }
if ($method === 'POST'   && $uri === '/floating/init')   { handle_floating_init(); }
if ($method === 'GET'    && $uri === '/floating/status')  { handle_floating_status(); }

respond(404, ['error' => 'Not found']);

// ─── Response Helper ────────────────────────────────────────

function respond($code, $data) {
    http_response_code($code);
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    exit;
}

function get_json_body() {
    $body = json_decode(file_get_contents('php://input'), true);
    if (!$body) respond(400, ['error' => 'Invalid JSON body']);
    return $body;
}

function validate_vlan_id($id) {
    if (!is_numeric($id) || $id < VLAN_MIN || $id > VLAN_MAX) {
        respond(400, ['error' => 'VLAN ID must be ' . VLAN_MIN . '-' . VLAN_MAX]);
    }
    return (int)$id;
}

// ─── SQLite Database ────────────────────────────────────────

function get_db() {
    static $db = null;
    if ($db) return $db;

    $db = new SQLite3(DB_PATH);
    $db->busyTimeout(5000);
    $db->exec("PRAGMA journal_mode=WAL");
    $db->exec("PRAGMA foreign_keys=ON");

    $db->exec("
        CREATE TABLE IF NOT EXISTS vlans (
            vlan_id       INTEGER PRIMARY KEY,
            pfsense_if    TEXT NOT NULL,
            pool          TEXT NOT NULL DEFAULT 'online',
            name          TEXT NOT NULL DEFAULT 'unnamed',
            block_tracker INTEGER,
            created_at    TEXT NOT NULL DEFAULT (datetime('now')),
            updated_at    TEXT NOT NULL DEFAULT (datetime('now'))
        );
        CREATE TABLE IF NOT EXISTS floating_rules (
            rule_name     TEXT PRIMARY KEY,
            tracker_id    INTEGER NOT NULL UNIQUE
        );
    ");

    return $db;
}

function db_get_vlan($vlan_id) {
    $db = get_db();
    $stmt = $db->prepare("SELECT * FROM vlans WHERE vlan_id = :id");
    $stmt->bindValue(':id', $vlan_id, SQLITE3_INTEGER);
    $result = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
    return $result ?: null;
}

function db_get_all_vlans() {
    $db = get_db();
    $results = [];
    $r = $db->query("SELECT * FROM vlans ORDER BY vlan_id");
    while ($row = $r->fetchArray(SQLITE3_ASSOC)) {
        $results[] = $row;
    }
    return $results;
}

function db_get_floating_rules() {
    $db = get_db();
    $rules = [];
    $r = $db->query("SELECT * FROM floating_rules");
    while ($row = $r->fetchArray(SQLITE3_ASSOC)) {
        $rules[$row['rule_name']] = $row['tracker_id'];
    }
    return $rules;
}

// ─── Ownership Guards ───────────────────────────────────────
// CRITICAL: These functions ensure we ONLY touch our own resources.

/**
 * Assert that an interface key belongs to us. Dies if not.
 */
function assert_owned_interface($iface_key) {
    $db = get_db();
    $stmt = $db->prepare("SELECT COUNT(*) FROM vlans WHERE pfsense_if = :if");
    $stmt->bindValue(':if', $iface_key);
    $count = $stmt->execute()->fetchArray()[0];
    if ($count === 0) {
        respond(403, ['error' => "Interface '{$iface_key}' is not managed by this API. Refusing to modify."]);
    }
}

/**
 * Assert that a firewall rule tracker belongs to us. Dies if not.
 */
function assert_owned_tracker($tracker) {
    $db = get_db();
    // Check floating rules
    $stmt = $db->prepare("SELECT COUNT(*) FROM floating_rules WHERE tracker_id = :t");
    $stmt->bindValue(':t', $tracker, SQLITE3_INTEGER);
    if ($stmt->execute()->fetchArray()[0] > 0) return;
    // Check VLAN block trackers
    $stmt = $db->prepare("SELECT COUNT(*) FROM vlans WHERE block_tracker = :t");
    $stmt->bindValue(':t', $tracker, SQLITE3_INTEGER);
    if ($stmt->execute()->fetchArray()[0] > 0) return;

    respond(403, ['error' => "Firewall rule tracker '{$tracker}' is not managed by this API. Refusing to modify."]);
}

/**
 * Build interface list from ONLY our managed VLANs.
 * Can NEVER return wan, lan, or any non-managed interface.
 */
function build_owned_interface_list($pool_filter = null) {
    $vlans = db_get_all_vlans();
    $ifaces = [];
    foreach ($vlans as $v) {
        if ($pool_filter === null || $v['pool'] === $pool_filter) {
            $ifaces[] = $v['pfsense_if'];
        }
    }
    return $ifaces;
}

/**
 * Safe removal of a firewall rule — only if we own the tracker.
 */
function safe_remove_rule($tracker) {
    global $config;
    assert_owned_tracker($tracker);
    if (!is_array($config['filter']['rule'] ?? null)) return false;
    foreach ($config['filter']['rule'] as $idx => $rule) {
        if ((int)($rule['tracker'] ?? 0) === (int)$tracker) {
            unset($config['filter']['rule'][$idx]);
            $config['filter']['rule'] = array_values($config['filter']['rule']);
            return true;
        }
    }
    return false;
}

/**
 * Update floating rules to reflect current managed interfaces.
 * ONLY sets interfaces from our DB — never touches non-managed interfaces.
 * If no managed VLANs exist, removes the floating rules from pfSense config.
 */
function sync_floating_rules() {
    global $config;
    $rules = db_get_floating_rules();
    if (empty($rules)) return;

    $owned_ifaces = build_owned_interface_list();

    pf_ensure_array($config, 'filter');
    pf_ensure_array($config['filter'], 'rule');

    if (empty($owned_ifaces)) {
        // No managed VLANs — remove floating rules from pfSense
        // (tracker IDs stay in DB for re-creation later)
        foreach ($rules as $name => $tracker) {
            foreach ($config['filter']['rule'] as $idx => $rule) {
                if ((int)($rule['tracker'] ?? 0) === (int)$tracker) {
                    unset($config['filter']['rule'][$idx]);
                    break;
                }
            }
        }
        $config['filter']['rule'] = array_values($config['filter']['rule']);
        return;
    }

    $iface_string = implode(',', $owned_ifaces);

    foreach ($rules as $name => $tracker) {
        foreach ($config['filter']['rule'] as $idx => &$rule) {
            if ((int)($rule['tracker'] ?? 0) === (int)$tracker) {
                $rule['interface'] = $iface_string;
                break;
            }
        }
        unset($rule);
    }
}

// ─── pfSense Config Helpers ─────────────────────────────────

function pf_next_opt_key() {
    global $config;
    $i = 1;
    while (isset($config['interfaces']["opt{$i}"])) $i++;
    return "opt{$i}";
}

function pf_ensure_array(&$parent, $key) {
    if (!is_array($parent[$key] ?? null)) {
        $parent[$key] = [];
    }
}

function pf_ensure_internal_alias() {
    global $config;
    pf_ensure_array($config, 'aliases');
    pf_ensure_array($config['aliases'], 'alias');
    foreach ($config['aliases']['alias'] as $a) {
        if (($a['name'] ?? '') === INTERNAL_ALIAS) return;
    }
    $config['aliases']['alias'][] = [
        'name' => INTERNAL_ALIAS,
        'type' => 'network',
        'address' => '10.0.0.0/8 172.16.0.0/12 192.168.0.0/16 127.0.0.0/8',
        'descr' => 'RFC1918 + loopback — DaathNet VLAN rules',
        'detail' => 'RFC1918 Class A||RFC1918 Class B||RFC1918 Class C||Loopback',
    ];
}

function make_description($vlan_id, $pool, $name) {
    $p = ($pool === 'offline') ? 'x' : 'o';
    $safe = preg_replace('/[^a-zA-Z0-9_-]/', '_', $name);
    return "vlan_{$vlan_id}_{$p}_{$safe}";
}

// ─── Floating Rules Init ────────────────────────────────────

function handle_floating_init() {
    global $config;
    $db = get_db();

    // 1. Remove old floating rules from pfSense (only ours!)
    $old_rules = db_get_floating_rules();
    foreach ($old_rules as $name => $tracker) {
        // These are our own trackers, safe to remove
        if (!is_array($config['filter']['rule'] ?? null)) break;
        foreach ($config['filter']['rule'] as $idx => $rule) {
            if ((int)($rule['tracker'] ?? 0) === (int)$tracker) {
                unset($config['filter']['rule'][$idx]);
                break;
            }
        }
    }
    if (is_array($config['filter']['rule'] ?? null)) {
        $config['filter']['rule'] = array_values($config['filter']['rule']);
    }
    $db->exec("DELETE FROM floating_rules");

    // 2. Define tracker IDs
    $trackers = [
        'dns'      => TRACKER_BASE + 1,
        'dhcp'     => TRACKER_BASE + 2,
        'internet' => TRACKER_BASE + 3,
        'block'    => TRACKER_BASE + 9,
    ];

    // 3. Save tracker IDs to DB first (so ownership guards work)
    $stmt = $db->prepare("INSERT INTO floating_rules (rule_name, tracker_id) VALUES (:name, :tracker)");
    foreach ($trackers as $name => $tracker) {
        $stmt->bindValue(':name', $name);
        $stmt->bindValue(':tracker', $tracker);
        $stmt->execute();
        $stmt->reset();
    }

    // 4. Only create rules in pfSense if we have managed VLANs
    $owned_ifaces = build_owned_interface_list();
    if (empty($owned_ifaces)) {
        respond(200, [
            'status'     => 'initialized',
            'note'       => 'No managed VLANs yet. Rules will be created in pfSense on first VLAN create.',
            'trackers'   => $trackers,
            'interfaces' => [],
        ]);
    }

    $iface_string = implode(',', $owned_ifaces);

    // 5. Create floating rules with ONLY managed interfaces
    pf_ensure_internal_alias();
    pf_ensure_array($config, 'filter');
    pf_ensure_array($config['filter'], 'rule');

    $config['filter']['rule'][] = [
        'type' => 'pass', 'floating' => 'yes', 'interface' => $iface_string,
        'ipprotocol' => 'inet', 'protocol' => 'udp',
        'source' => ['any' => ''], 'destination' => ['port' => '53'],
        'descr' => 'DaathNet: Allow DNS', 'tracker' => (string)$trackers['dns'],
    ];

    $config['filter']['rule'][] = [
        'type' => 'pass', 'floating' => 'yes', 'interface' => $iface_string,
        'ipprotocol' => 'inet', 'protocol' => 'udp',
        'source' => ['any' => ''], 'destination' => ['any' => '', 'port' => '67-68'],
        'descr' => 'DaathNet: Allow DHCP', 'tracker' => (string)$trackers['dhcp'],
    ];

    $config['filter']['rule'][] = [
        'type' => 'pass', 'floating' => 'yes', 'interface' => $iface_string,
        'ipprotocol' => 'inet',
        'source' => ['any' => ''], 'destination' => ['address' => INTERNAL_ALIAS, 'not' => ''],
        'descr' => 'DaathNet: Allow Internet (block internal)', 'tracker' => (string)$trackers['internet'],
    ];

    $config['filter']['rule'][] = [
        'type' => 'block', 'floating' => 'yes', 'interface' => $iface_string,
        'ipprotocol' => 'inet',
        'source' => ['any' => ''], 'destination' => ['any' => ''],
        'descr' => 'DaathNet: Block all other', 'tracker' => (string)$trackers['block'],
    ];

    // 6. Apply
    write_config("DaathNet API: Floating rules initialized");
    filter_configure();

    respond(200, [
        'status'     => 'initialized',
        'trackers'   => $trackers,
        'interfaces' => $owned_ifaces,
    ]);
}

function handle_floating_status() {
    $rules = db_get_floating_rules();
    $owned = build_owned_interface_list();

    respond(200, [
        'floating_rules'    => $rules,
        'managed_vlans'     => count(db_get_all_vlans()),
        'managed_interfaces' => $owned,
    ]);
}

// ─── VLAN Create ────────────────────────────────────────────

function handle_vlan_create() {
    global $config;
    $db = get_db();

    $body = get_json_body();
    $vlan_id = validate_vlan_id($body['vlan_id'] ?? 0);
    $name = $body['name'] ?? 'unnamed';
    $pool = $body['pool'] ?? 'online';

    if (!in_array($pool, ['online', 'offline'])) {
        respond(400, ['error' => 'Pool must be "online" or "offline"']);
    }
    if (db_get_vlan($vlan_id)) {
        respond(409, ['error' => "VLAN {$vlan_id} already managed"]);
    }

    $vlan_if = PARENT_IF . ".{$vlan_id}";
    $description = make_description($vlan_id, $pool, $name);
    $ip = "10.110.{$vlan_id}.1";
    $subnet = "29";

    // 1. Create VLAN in pfSense config
    pf_ensure_array($config, 'vlans');
    pf_ensure_array($config['vlans'], 'vlan');

    $vlan_entry = [
        'if' => PARENT_IF, 'tag' => (string)$vlan_id,
        'descr' => $description, 'vlanif' => $vlan_if,
    ];
    $config['vlans']['vlan'][] = $vlan_entry;

    // 2. Assign interface
    $opt_key = pf_next_opt_key();
    $config['interfaces'][$opt_key] = [
        'if' => $vlan_if, 'descr' => $description, 'enable' => '',
        'ipaddr' => $ip, 'subnet' => $subnet, 'spoofmac' => '',
    ];

    // 3. DHCP
    pf_ensure_array($config, 'dhcpd');
    $config['dhcpd'][$opt_key] = [
        'enable' => '',
        'range' => ['from' => "10.110.{$vlan_id}.2", 'to' => "10.110.{$vlan_id}.6"],
        'gateway' => $ip,
    ];

    // 4. Offline block rule (interface-level)
    $block_tracker = null;
    if ($pool === 'offline') {
        $block_tracker = TRACKER_BASE + $vlan_id * 10;
        pf_ensure_array($config, 'filter');
        pf_ensure_array($config['filter'], 'rule');
        array_unshift($config['filter']['rule'], [
            'type' => 'block', 'interface' => $opt_key, 'ipprotocol' => 'inet',
            'source' => ['any' => ''],
            'destination' => ['any' => '', 'not' => '', 'address' => $ip, 'port' => '53'],
            'descr' => "DaathNet {$vlan_id}: Offline block (except DNS)",
            'tracker' => (string)$block_tracker,
        ]);
    }

    // 5. Save to SQLite FIRST (so sync_floating_rules can find it)
    $stmt = $db->prepare("INSERT INTO vlans (vlan_id, pfsense_if, pool, name, block_tracker) VALUES (:id, :if, :pool, :name, :bt)");
    $stmt->bindValue(':id', $vlan_id, SQLITE3_INTEGER);
    $stmt->bindValue(':if', $opt_key);
    $stmt->bindValue(':pool', $pool);
    $stmt->bindValue(':name', $name);
    $stmt->bindValue(':bt', $block_tracker, SQLITE3_INTEGER);
    $stmt->execute();

    // 6. Update floating rules (now includes new VLAN)
    sync_floating_rules();

    // 7. Write config and apply
    write_config("DaathNet API: Created VLAN {$vlan_id} ({$pool}: {$name})");
    interface_vlan_configure($vlan_entry);
    interface_configure($opt_key);
    services_dhcpd_configure();
    filter_configure();

    respond(201, [
        'status' => 'created', 'vlan_id' => $vlan_id, 'interface' => $opt_key,
        'description' => $description, 'ip' => "{$ip}/{$subnet}",
        'dhcp_range' => "10.110.{$vlan_id}.2 - 10.110.{$vlan_id}.6", 'pool' => $pool,
    ]);
}

// ─── VLAN Rename ────────────────────────────────────────────

function handle_vlan_rename() {
    global $config;
    $db = get_db();

    $body = get_json_body();
    $vlan_id = validate_vlan_id($body['vlan_id'] ?? 0);
    $name = trim($body['name'] ?? '');
    if (empty($name)) respond(400, ['error' => 'Name is required']);

    $vlan = db_get_vlan($vlan_id);
    if (!$vlan) respond(404, ['error' => "VLAN {$vlan_id} not managed by this API"]);

    $iface_key = $vlan['pfsense_if'];
    assert_owned_interface($iface_key);

    if (!isset($config['interfaces'][$iface_key])) {
        respond(500, ['error' => "Interface {$iface_key} missing in pfSense config"]);
    }

    $new_descr = make_description($vlan_id, $vlan['pool'], $name);
    $config['interfaces'][$iface_key]['descr'] = $new_descr;

    // Update VLAN description
    if (is_array($config['vlans']['vlan'] ?? null)) {
        foreach ($config['vlans']['vlan'] as &$v) {
            if (($v['if'] ?? '') === PARENT_IF && ($v['tag'] ?? '') == $vlan_id) {
                $v['descr'] = $new_descr;
                break;
            }
        }
        unset($v);
    }

    // Update SQLite
    $stmt = $db->prepare("UPDATE vlans SET name = :name, updated_at = datetime('now') WHERE vlan_id = :id");
    $stmt->bindValue(':name', $name);
    $stmt->bindValue(':id', $vlan_id, SQLITE3_INTEGER);
    $stmt->execute();

    write_config("DaathNet API: Renamed VLAN {$vlan_id} to {$name}");
    respond(200, ['status' => 'renamed', 'vlan_id' => $vlan_id, 'description' => $new_descr]);
}

// ─── VLAN Move ──────────────────────────────────────────────

function handle_vlan_move() {
    global $config;
    $db = get_db();

    $body = get_json_body();
    $vlan_id = validate_vlan_id($body['vlan_id'] ?? 0);
    $new_pool = $body['pool'] ?? '';
    if (!in_array($new_pool, ['online', 'offline'])) {
        respond(400, ['error' => 'Pool must be "online" or "offline"']);
    }

    $vlan = db_get_vlan($vlan_id);
    if (!$vlan) respond(404, ['error' => "VLAN {$vlan_id} not managed by this API"]);
    if ($vlan['pool'] === $new_pool) {
        respond(200, ['status' => 'unchanged', 'vlan_id' => $vlan_id, 'pool' => $new_pool]);
    }

    $iface_key = $vlan['pfsense_if'];
    assert_owned_interface($iface_key);

    pf_ensure_array($config, 'filter');
    pf_ensure_array($config['filter'], 'rule');

    $block_tracker = null;

    if ($new_pool === 'offline') {
        $block_tracker = TRACKER_BASE + $vlan_id * 10;
        $ip = "10.110.{$vlan_id}.1";
        array_unshift($config['filter']['rule'], [
            'type' => 'block', 'interface' => $iface_key, 'ipprotocol' => 'inet',
            'source' => ['any' => ''],
            'destination' => ['any' => '', 'not' => '', 'address' => $ip, 'port' => '53'],
            'descr' => "DaathNet {$vlan_id}: Offline block (except DNS)",
            'tracker' => (string)$block_tracker,
        ]);
    } else {
        // Remove offline block — only if we own it
        if ($vlan['block_tracker']) {
            safe_remove_rule($vlan['block_tracker']);
        }
    }

    // Update description
    $new_descr = make_description($vlan_id, $new_pool, $vlan['name']);
    $config['interfaces'][$iface_key]['descr'] = $new_descr;

    // Update SQLite
    $stmt = $db->prepare("UPDATE vlans SET pool = :pool, block_tracker = :bt, updated_at = datetime('now') WHERE vlan_id = :id");
    $stmt->bindValue(':pool', $new_pool);
    $stmt->bindValue(':bt', $block_tracker, SQLITE3_INTEGER);
    $stmt->bindValue(':id', $vlan_id, SQLITE3_INTEGER);
    $stmt->execute();

    write_config("DaathNet API: Moved VLAN {$vlan_id} to {$new_pool}");
    filter_configure();

    respond(200, ['status' => 'moved', 'vlan_id' => $vlan_id, 'pool' => $new_pool, 'description' => $new_descr]);
}

// ─── VLAN List ──────────────────────────────────────────────

function handle_vlan_list() {
    global $config;
    $vlans = db_get_all_vlans();
    $result = [];

    foreach ($vlans as $v) {
        $iface = $config['interfaces'][$v['pfsense_if']] ?? null;
        $result[] = [
            'vlan_id'    => (int)$v['vlan_id'],
            'interface'  => $v['pfsense_if'],
            'pool'       => $v['pool'],
            'name'       => $v['name'],
            'description' => $iface ? ($iface['descr'] ?? '') : '(missing in pfSense)',
            'ip'         => $iface ? (($iface['ipaddr'] ?? '') . '/29') : '',
            'enabled'    => $iface ? isset($iface['enable']) : false,
            'pfsense_ok' => $iface !== null,
            'created_at' => $v['created_at'],
        ];
    }

    respond(200, ['vlans' => $result, 'count' => count($result)]);
}

// ─── VLAN Delete ────────────────────────────────────────────

function handle_vlan_delete($vlan_id) {
    global $config;
    $db = get_db();

    $vlan_id = validate_vlan_id($vlan_id);
    $vlan = db_get_vlan($vlan_id);
    if (!$vlan) respond(404, ['error' => "VLAN {$vlan_id} not managed by this API"]);

    $iface_key = $vlan['pfsense_if'];
    assert_owned_interface($iface_key);

    $vlan_if = PARENT_IF . ".{$vlan_id}";

    // 1. Remove offline block rule if exists (ownership checked)
    if ($vlan['block_tracker']) {
        safe_remove_rule($vlan['block_tracker']);
    }

    // 2. Remove DHCP (only for our interface)
    unset($config['dhcpd'][$iface_key]);

    // 3. Remove interface (only our own)
    unset($config['interfaces'][$iface_key]);

    // 4. Remove VLAN entry from config
    if (is_array($config['vlans']['vlan'] ?? null)) {
        $config['vlans']['vlan'] = array_values(array_filter(
            $config['vlans']['vlan'],
            fn($v) => !(($v['if'] ?? '') === PARENT_IF && ($v['tag'] ?? '') == $vlan_id)
        ));
    }

    // 5. Remove from SQLite FIRST (so sync_floating_rules excludes it)
    $stmt = $db->prepare("DELETE FROM vlans WHERE vlan_id = :id");
    $stmt->bindValue(':id', $vlan_id, SQLITE3_INTEGER);
    $stmt->execute();

    // 6. Update floating rules (now excludes deleted VLAN)
    sync_floating_rules();

    // 7. Destroy OS interface
    if (does_interface_exist($vlan_if)) {
        pfSense_interface_destroy($vlan_if);
    }

    // 8. Apply
    write_config("DaathNet API: Deleted VLAN {$vlan_id}");
    services_dhcpd_configure();
    filter_configure();

    respond(200, ['status' => 'deleted', 'vlan_id' => $vlan_id]);
}
