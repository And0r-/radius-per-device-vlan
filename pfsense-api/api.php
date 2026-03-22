<?php
/**
 * pfSense VLAN API — Per-device VLAN management with Floating Rules
 *
 * State: SQLite DB at /usr/local/www/api/daathnet.db
 * Rules: Floating rules (no quick) for all VLANs, interface block for offline
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
define('TRACKER_BASE', 1700000000);  // Base for our tracker IDs

// ─── Request Handling ───────────────────────────────────────

header('Content-Type: application/json');

if (($_SERVER['HTTP_X_API_KEY'] ?? '') !== API_KEY) {
    respond(401, ['error' => 'Unauthorized']);
}

$method = $_SERVER['REQUEST_METHOD'];
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

if ($method === 'GET'    && $uri === '/health')          { respond(200, ['status' => 'ok', 'version' => '2.0']); }
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

    // Auto-create schema
    $db->exec("
        CREATE TABLE IF NOT EXISTS vlans (
            vlan_id       INTEGER PRIMARY KEY,
            pfsense_if    TEXT NOT NULL,          -- e.g. 'opt1'
            pool          TEXT NOT NULL DEFAULT 'online',
            name          TEXT NOT NULL DEFAULT 'unnamed',
            block_tracker INTEGER,                -- tracker ID for offline block rule (NULL if online)
            created_at    TEXT NOT NULL DEFAULT (datetime('now')),
            updated_at    TEXT NOT NULL DEFAULT (datetime('now'))
        );
        CREATE TABLE IF NOT EXISTS floating_rules (
            rule_name     TEXT PRIMARY KEY,        -- dns, dhcp, internet, block
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

// ─── pfSense Config Helpers ─────────────────────────────────

function pf_find_interface_by_if($if_name) {
    global $config;
    if (!isset($config['interfaces'])) return null;
    foreach ($config['interfaces'] as $key => $iface) {
        if (($iface['if'] ?? '') === $if_name) return $key;
    }
    return null;
}

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

/**
 * Find a firewall rule by tracker ID. Returns [index, rule] or null.
 */
function pf_find_rule_by_tracker($tracker) {
    global $config;
    if (!is_array($config['filter']['rule'] ?? null)) return null;
    foreach ($config['filter']['rule'] as $idx => $rule) {
        if ((int)($rule['tracker'] ?? 0) === (int)$tracker) {
            return [$idx, $rule];
        }
    }
    return null;
}

/**
 * Remove a firewall rule by tracker ID.
 */
function pf_remove_rule_by_tracker($tracker) {
    global $config;
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
 * Get all managed interface keys (from our SQLite DB).
 */
function get_managed_interface_keys() {
    $vlans = db_get_all_vlans();
    return array_column($vlans, 'pfsense_if');
}

/**
 * Build comma-separated interface list for floating rules.
 */
function build_floating_interfaces($pool_filter = null) {
    $vlans = db_get_all_vlans();
    $ifaces = [];
    foreach ($vlans as $v) {
        if ($pool_filter === null || $v['pool'] === $pool_filter) {
            $ifaces[] = $v['pfsense_if'];
        }
    }
    return implode(',', $ifaces);
}

/**
 * Update floating rules to include current set of managed interfaces.
 */
function update_floating_rules() {
    global $config;
    $rules = db_get_floating_rules();
    if (empty($rules)) return; // not initialized

    $all_ifaces = build_floating_interfaces();
    if (empty($all_ifaces)) return;

    pf_ensure_array($config, 'filter');
    pf_ensure_array($config['filter'], 'rule');

    foreach (['dns', 'dhcp', 'internet', 'block'] as $name) {
        if (!isset($rules[$name])) continue;
        $found = pf_find_rule_by_tracker($rules[$name]);
        if (!$found) continue;

        [$idx, $rule] = $found;
        $config['filter']['rule'][$idx]['interface'] = $all_ifaces;
    }
}

// ─── Floating Rules Init ────────────────────────────────────

function handle_floating_init() {
    global $config;
    $db = get_db();

    // 1. Remove old floating rules if they exist
    $old_rules = db_get_floating_rules();
    foreach ($old_rules as $name => $tracker) {
        pf_remove_rule_by_tracker($tracker);
    }
    $db->exec("DELETE FROM floating_rules");

    // 2. Ensure prerequisites
    pf_ensure_internal_alias();
    pf_ensure_array($config, 'filter');
    pf_ensure_array($config['filter'], 'rule');

    // 3. Collect all managed interfaces
    $all_ifaces = build_floating_interfaces();
    if (empty($all_ifaces)) {
        // No VLANs yet — create rules with placeholder, will be updated on first VLAN create
        $all_ifaces = 'wan'; // placeholder, will be replaced
    }

    // 4. Create floating rules
    $trackers = [
        'dns'      => TRACKER_BASE + 1,
        'dhcp'     => TRACKER_BASE + 2,
        'internet' => TRACKER_BASE + 3,
        'block'    => TRACKER_BASE + 9,
    ];

    // DNS rule
    $config['filter']['rule'][] = [
        'type'        => 'pass',
        'floating'    => 'yes',
        'interface'   => $all_ifaces,
        'ipprotocol'  => 'inet',
        'protocol'    => 'udp',
        'source'      => ['any' => ''],
        'destination' => ['port' => '53'],
        'descr'       => 'DaathNet: Allow DNS',
        'tracker'     => (string)$trackers['dns'],
    ];

    // DHCP rule
    $config['filter']['rule'][] = [
        'type'        => 'pass',
        'floating'    => 'yes',
        'interface'   => $all_ifaces,
        'ipprotocol'  => 'inet',
        'protocol'    => 'udp',
        'source'      => ['any' => ''],
        'destination' => ['any' => '', 'port' => '67-68'],
        'descr'       => 'DaathNet: Allow DHCP',
        'tracker'     => (string)$trackers['dhcp'],
    ];

    // Internet rule (NOT internal)
    $config['filter']['rule'][] = [
        'type'        => 'pass',
        'floating'    => 'yes',
        'interface'   => $all_ifaces,
        'ipprotocol'  => 'inet',
        'source'      => ['any' => ''],
        'destination' => ['address' => INTERNAL_ALIAS, 'not' => ''],
        'descr'       => 'DaathNet: Allow Internet (block internal)',
        'tracker'     => (string)$trackers['internet'],
    ];

    // Block all
    $config['filter']['rule'][] = [
        'type'        => 'block',
        'floating'    => 'yes',
        'interface'   => $all_ifaces,
        'ipprotocol'  => 'inet',
        'source'      => ['any' => ''],
        'destination' => ['any' => ''],
        'descr'       => 'DaathNet: Block all other',
        'tracker'     => (string)$trackers['block'],
    ];

    // 5. Save tracker IDs to SQLite
    $stmt = $db->prepare("INSERT INTO floating_rules (rule_name, tracker_id) VALUES (:name, :tracker)");
    foreach ($trackers as $name => $tracker) {
        $stmt->bindValue(':name', $name);
        $stmt->bindValue(':tracker', $tracker);
        $stmt->execute();
        $stmt->reset();
    }

    // 6. Apply
    write_config("DaathNet API: Floating rules initialized");
    filter_configure();

    respond(200, [
        'status'   => 'initialized',
        'trackers' => $trackers,
        'interfaces' => $all_ifaces,
    ]);
}

function handle_floating_status() {
    $rules = db_get_floating_rules();
    $vlans = db_get_all_vlans();

    $ifaces_all = build_floating_interfaces();

    respond(200, [
        'floating_rules' => $rules,
        'managed_vlans'  => count($vlans),
        'interfaces'     => $ifaces_all ?: '(none)',
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

    // Check if already managed
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
        'if'     => PARENT_IF,
        'tag'    => (string)$vlan_id,
        'descr'  => $description,
        'vlanif' => $vlan_if,
    ];
    $config['vlans']['vlan'][] = $vlan_entry;

    // 2. Assign interface
    $opt_key = pf_next_opt_key();
    $config['interfaces'][$opt_key] = [
        'if'       => $vlan_if,
        'descr'    => $description,
        'enable'   => '',
        'ipaddr'   => $ip,
        'subnet'   => $subnet,
        'spoofmac' => '',
    ];

    // 3. DHCP
    pf_ensure_array($config, 'dhcpd');
    $config['dhcpd'][$opt_key] = [
        'enable'  => '',
        'range'   => ['from' => "10.110.{$vlan_id}.2", 'to' => "10.110.{$vlan_id}.6"],
        'gateway' => $ip,
    ];

    // 4. Offline block rule (interface-level, evaluated before floating)
    $block_tracker = null;
    if ($pool === 'offline') {
        $block_tracker = TRACKER_BASE + $vlan_id * 10;
        pf_ensure_array($config, 'filter');
        pf_ensure_array($config['filter'], 'rule');
        // Insert at beginning so it's evaluated first
        array_unshift($config['filter']['rule'], [
            'type'        => 'block',
            'interface'   => $opt_key,
            'ipprotocol'  => 'inet',
            'source'      => ['any' => ''],
            'destination' => ['any' => '', 'not' => '', 'address' => $ip, 'port' => '53'],
            'descr'       => "DaathNet {$vlan_id}: Offline block (except DNS)",
            'tracker'     => (string)$block_tracker,
        ]);
    }

    // 5. Update floating rules to include new interface
    update_floating_rules();

    // 6. Save to SQLite
    $stmt = $db->prepare("INSERT INTO vlans (vlan_id, pfsense_if, pool, name, block_tracker) VALUES (:id, :if, :pool, :name, :bt)");
    $stmt->bindValue(':id', $vlan_id, SQLITE3_INTEGER);
    $stmt->bindValue(':if', $opt_key);
    $stmt->bindValue(':pool', $pool);
    $stmt->bindValue(':name', $name);
    $stmt->bindValue(':bt', $block_tracker, SQLITE3_INTEGER);
    $stmt->execute();

    // 7. Now update floating rules again (new VLAN is in DB now)
    update_floating_rules();

    // 8. Write config and apply
    write_config("DaathNet API: Created VLAN {$vlan_id} ({$pool}: {$name})");
    interface_vlan_configure($vlan_entry);
    interface_configure($opt_key);
    services_dhcpd_configure();
    filter_configure();

    respond(201, [
        'status'      => 'created',
        'vlan_id'     => $vlan_id,
        'interface'   => $opt_key,
        'description' => $description,
        'ip'          => "{$ip}/{$subnet}",
        'dhcp_range'  => "10.110.{$vlan_id}.2 - 10.110.{$vlan_id}.6",
        'pool'        => $pool,
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
    if (!isset($config['interfaces'][$iface_key])) {
        respond(500, ['error' => "Interface {$iface_key} missing in pfSense config"]);
    }

    $new_descr = make_description($vlan_id, $vlan['pool'], $name);

    // Update pfSense config
    $config['interfaces'][$iface_key]['descr'] = $new_descr;

    // Update VLAN description too
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

// ─── VLAN Move (online <-> offline) ─────────────────────────

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
    pf_ensure_array($config, 'filter');
    pf_ensure_array($config['filter'], 'rule');

    $block_tracker = null;

    if ($new_pool === 'offline') {
        // Add interface block rule
        $block_tracker = TRACKER_BASE + $vlan_id * 10;
        $ip = "10.110.{$vlan_id}.1";
        array_unshift($config['filter']['rule'], [
            'type'        => 'block',
            'interface'   => $iface_key,
            'ipprotocol'  => 'inet',
            'source'      => ['any' => ''],
            'destination' => ['any' => '', 'not' => '', 'address' => $ip, 'port' => '53'],
            'descr'       => "DaathNet {$vlan_id}: Offline block (except DNS)",
            'tracker'     => (string)$block_tracker,
        ]);
    } else {
        // Remove offline block rule
        if ($vlan['block_tracker']) {
            pf_remove_rule_by_tracker($vlan['block_tracker']);
        }
    }

    // Update description
    $new_descr = make_description($vlan_id, $new_pool, $vlan['name']);
    if (isset($config['interfaces'][$iface_key])) {
        $config['interfaces'][$iface_key]['descr'] = $new_descr;
    }

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
            'vlan_id'      => (int)$v['vlan_id'],
            'interface'    => $v['pfsense_if'],
            'pool'         => $v['pool'],
            'name'         => $v['name'],
            'description'  => $iface ? ($iface['descr'] ?? '') : '(missing)',
            'ip'           => $iface ? (($iface['ipaddr'] ?? '') . '/29') : '',
            'enabled'      => $iface ? isset($iface['enable']) : false,
            'pfsense_ok'   => $iface !== null,
            'created_at'   => $v['created_at'],
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
    $vlan_if = PARENT_IF . ".{$vlan_id}";

    // 1. Remove offline block rule if exists
    if ($vlan['block_tracker']) {
        pf_remove_rule_by_tracker($vlan['block_tracker']);
    }

    // 2. Remove DHCP
    unset($config['dhcpd'][$iface_key]);

    // 3. Remove interface
    unset($config['interfaces'][$iface_key]);

    // 4. Remove VLAN entry from config
    if (is_array($config['vlans']['vlan'] ?? null)) {
        $config['vlans']['vlan'] = array_values(array_filter(
            $config['vlans']['vlan'],
            fn($v) => !(($v['if'] ?? '') === PARENT_IF && ($v['tag'] ?? '') == $vlan_id)
        ));
    }

    // 5. Remove from SQLite
    $stmt = $db->prepare("DELETE FROM vlans WHERE vlan_id = :id");
    $stmt->bindValue(':id', $vlan_id, SQLITE3_INTEGER);
    $stmt->execute();

    // 6. Update floating rules (remove this interface)
    update_floating_rules();

    // 7. Destroy OS interface
    if (does_interface_exist($vlan_if)) {
        pfSense_interface_destroy($vlan_if);
    }

    // 8. Write config and apply
    write_config("DaathNet API: Deleted VLAN {$vlan_id}");
    services_dhcpd_configure();
    filter_configure();

    respond(200, ['status' => 'deleted', 'vlan_id' => $vlan_id]);
}
