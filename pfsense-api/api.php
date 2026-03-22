<?php
/**
 * pfSense VLAN API — Minimal REST API for per-device VLAN management
 *
 * Endpoints:
 *   POST   /vlan/create   — Create VLAN + interface + DHCP + firewall rules
 *   POST   /vlan/rename   — Rename VLAN interface description
 *   GET    /vlan/list     — List all DaathNet VLANs (100-199)
 *   DELETE /vlan/{id}     — Remove VLAN + interface + DHCP + rules
 *
 * Run: php -S 192.168.4.x:9443 api.php (on pfSense)
 *
 * Security: API-Key via X-API-Key header, only VLAN IDs 100-199
 */

// pfSense bootstrap
require_once("config.inc");
require_once("interfaces.inc");
require_once("services.inc");
require_once("filter.inc");
require_once("util.inc");

// Configuration
define('API_KEY', getenv('PFSENSE_API_KEY') ?: 'CHANGE_ME');
define('PARENT_IF', getenv('PFSENSE_PARENT_IF') ?: 'ix2');     // Production: ix2, Dev: vtnet0
define('VLAN_MIN', 100);
define('VLAN_MAX', 199);
define('INTERNAL_ALIAS', 'internal_networks');

// ─── Request Handling ───────────────────────────────────────

header('Content-Type: application/json');

// Auth check
if (($_SERVER['HTTP_X_API_KEY'] ?? '') !== API_KEY) {
    respond(401, ['error' => 'Unauthorized']);
}

$method = $_SERVER['REQUEST_METHOD'];
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// Route
if ($method === 'POST' && $uri === '/vlan/create') {
    handle_create();
} elseif ($method === 'POST' && $uri === '/vlan/rename') {
    handle_rename();
} elseif ($method === 'GET' && $uri === '/vlan/list') {
    handle_list();
} elseif ($method === 'DELETE' && preg_match('#^/vlan/(\d+)$#', $uri, $m)) {
    handle_delete((int)$m[1]);
} elseif ($method === 'GET' && $uri === '/health') {
    respond(200, ['status' => 'ok', 'version' => '1.0']);
} else {
    respond(404, ['error' => 'Not found', 'endpoints' => [
        'POST /vlan/create', 'POST /vlan/rename', 'GET /vlan/list', 'DELETE /vlan/{id}', 'GET /health'
    ]]);
}

// ─── Helpers ────────────────────────────────────────────────

function respond($code, $data) {
    http_response_code($code);
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    exit;
}

function get_json_body() {
    $body = json_decode(file_get_contents('php://input'), true);
    if (!$body) {
        respond(400, ['error' => 'Invalid JSON body']);
    }
    return $body;
}

function validate_vlan_id($id) {
    if (!is_numeric($id) || $id < VLAN_MIN || $id > VLAN_MAX) {
        respond(400, ['error' => "VLAN ID must be between " . VLAN_MIN . "-" . VLAN_MAX]);
    }
    return (int)$id;
}

function pool_prefix($pool) {
    return $pool === 'offline' ? 'x' : 'o';
}

function make_description($vlan_id, $pool, $name) {
    $p = pool_prefix($pool);
    $safe_name = preg_replace('/[^a-zA-Z0-9_-]/', '_', $name);
    return "vlan_{$vlan_id}_{$p}_{$safe_name}";
}

/**
 * Find the pfSense interface key (opt1, opt2, ...) for a given VLAN tag
 */
function find_interface_by_vlan($vlan_id) {
    global $config;
    $vlan_if = PARENT_IF . ".{$vlan_id}";
    if (!isset($config['interfaces'])) return null;
    foreach ($config['interfaces'] as $key => $iface) {
        if (($iface['if'] ?? '') === $vlan_if) {
            return $key;
        }
    }
    return null;
}

/**
 * Find the VLAN entry index in config[vlans][vlan]
 */
function find_vlan_entry($vlan_id) {
    global $config;
    if (!is_array($config['vlans']['vlan'] ?? null)) return null;
    foreach ($config['vlans']['vlan'] as $idx => $v) {
        if (($v['tag'] ?? '') == $vlan_id && ($v['if'] ?? '') === PARENT_IF) {
            return $idx;
        }
    }
    return null;
}

/**
 * Get next available OPT interface number
 */
function next_opt_key() {
    global $config;
    $i = 1;
    while (isset($config['interfaces']["opt{$i}"])) {
        $i++;
    }
    return "opt{$i}";
}

/**
 * Ensure the internal_networks alias exists
 */
function ensure_internal_alias() {
    global $config;
    if (!is_array($config['aliases'] ?? null)) {
        $config['aliases'] = [];
    }
    if (!is_array($config['aliases']['alias'] ?? null)) {
        $config['aliases']['alias'] = [];
    }
    foreach ($config['aliases']['alias'] as $a) {
        if (($a['name'] ?? '') === INTERNAL_ALIAS) {
            return; // already exists
        }
    }
    $config['aliases']['alias'][] = [
        'name' => INTERNAL_ALIAS,
        'type' => 'network',
        'address' => '10.0.0.0/8 172.16.0.0/12 192.168.0.0/16 127.0.0.0/8',
        'descr' => 'RFC1918 + loopback — used by DaathNet VLAN rules',
        'detail' => 'RFC1918 Class A||RFC1918 Class B||RFC1918 Class C||Loopback',
    ];
}

// ─── Endpoint: Create VLAN ──────────────────────────────────

function handle_create() {
    global $config;

    $body = get_json_body();
    $vlan_id = validate_vlan_id($body['vlan_id'] ?? 0);
    $name = $body['name'] ?? 'unnamed';
    $pool = $body['pool'] ?? 'online';

    if (!in_array($pool, ['online', 'offline'])) {
        respond(400, ['error' => 'Pool must be "online" or "offline"']);
    }

    // Check if VLAN already exists
    if (find_vlan_entry($vlan_id) !== null) {
        respond(409, ['error' => "VLAN {$vlan_id} already exists"]);
    }

    $vlan_if = PARENT_IF . ".{$vlan_id}";
    $description = make_description($vlan_id, $pool, $name);
    $ip = "10.110.{$vlan_id}.1";
    $subnet = "29";

    // 1. Create VLAN (pfSense stores empty string when no VLANs exist)
    if (!is_array($config['vlans'] ?? null)) {
        $config['vlans'] = [];
    }
    if (!is_array($config['vlans']['vlan'] ?? null)) {
        $config['vlans']['vlan'] = [];
    }
    $vlan_entry = [
        'if'    => PARENT_IF,
        'tag'   => (string)$vlan_id,
        'descr' => $description,
        'vlanif' => $vlan_if,
    ];
    $config['vlans']['vlan'][] = $vlan_entry;

    // 2. Create interface assignment
    $opt_key = next_opt_key();
    $config['interfaces'][$opt_key] = [
        'if'       => $vlan_if,
        'descr'    => $description,
        'enable'   => '',
        'ipaddr'   => $ip,
        'subnet'   => $subnet,
        'spoofmac' => '',
    ];

    // 3. Configure DHCP
    if (!is_array($config['dhcpd'] ?? null)) {
        $config['dhcpd'] = [];
    }
    $config['dhcpd'][$opt_key] = [
        'enable'    => '',
        'range' => [
            'from' => "10.110.{$vlan_id}.2",
            'to'   => "10.110.{$vlan_id}.6",
        ],
        'gateway' => $ip,
    ];

    // 4. Ensure internal_networks alias
    ensure_internal_alias();

    // 5. Create firewall rules
    if (!is_array($config['filter'] ?? null)) {
        $config['filter'] = [];
    }
    if (!is_array($config['filter']['rule'] ?? null)) {
        $config['filter']['rule'] = [];
    }

    // Rule: Allow DNS to gateway
    $config['filter']['rule'][] = [
        'type'        => 'pass',
        'interface'   => $opt_key,
        'ipprotocol'  => 'inet',
        'protocol'    => 'udp',
        'source'      => ['network' => $opt_key],
        'destination' => ['address' => $ip, 'port' => '53'],
        'descr'       => "DaathNet {$vlan_id}: Allow DNS",
        'tracker'     => (string)(1700000000 + $vlan_id * 10 + 1),
    ];

    // Rule: Allow DHCP
    $config['filter']['rule'][] = [
        'type'        => 'pass',
        'interface'   => $opt_key,
        'ipprotocol'  => 'inet',
        'protocol'    => 'udp',
        'source'      => ['any' => ''],
        'destination' => ['any' => '', 'port' => '67-68'],
        'descr'       => "DaathNet {$vlan_id}: Allow DHCP",
        'tracker'     => (string)(1700000000 + $vlan_id * 10 + 2),
    ];

    // Rule: Internet access (online pool only)
    if ($pool === 'online') {
        $config['filter']['rule'][] = [
            'type'        => 'pass',
            'interface'   => $opt_key,
            'ipprotocol'  => 'inet',
            'source'      => ['network' => $opt_key],
            'destination' => ['address' => INTERNAL_ALIAS, 'not' => ''],
            'descr'       => "DaathNet {$vlan_id}: Allow Internet (block internal)",
            'tracker'     => (string)(1700000000 + $vlan_id * 10 + 3),
        ];
    }

    // Rule: Block everything else (explicit deny for logging)
    $config['filter']['rule'][] = [
        'type'        => 'block',
        'interface'   => $opt_key,
        'ipprotocol'  => 'inet',
        'source'      => ['any' => ''],
        'destination' => ['any' => ''],
        'descr'       => "DaathNet {$vlan_id}: Block all other",
        'tracker'     => (string)(1700000000 + $vlan_id * 10 + 9),
    ];

    // 6. Write config and apply
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

// ─── Endpoint: Rename VLAN ──────────────────────────────────

function handle_rename() {
    global $config;

    $body = get_json_body();
    $vlan_id = validate_vlan_id($body['vlan_id'] ?? 0);
    $name = $body['name'] ?? '';

    if (empty($name)) {
        respond(400, ['error' => 'Name is required']);
    }

    $iface_key = find_interface_by_vlan($vlan_id);
    if (!$iface_key) {
        respond(404, ['error' => "VLAN {$vlan_id} not found"]);
    }

    // Detect current pool from existing description
    $current_descr = $config['interfaces'][$iface_key]['descr'] ?? '';
    $pool = (strpos($current_descr, '_x_') !== false) ? 'offline' : 'online';

    $new_descr = make_description($vlan_id, $pool, $name);
    $config['interfaces'][$iface_key]['descr'] = $new_descr;

    // Also update VLAN description
    $vlan_idx = find_vlan_entry($vlan_id);
    if ($vlan_idx !== null) {
        $config['vlans']['vlan'][$vlan_idx]['descr'] = $new_descr;
    }

    write_config("DaathNet API: Renamed VLAN {$vlan_id} to {$name}");

    respond(200, [
        'status'      => 'renamed',
        'vlan_id'     => $vlan_id,
        'description' => $new_descr,
    ]);
}

// ─── Endpoint: List VLANs ───────────────────────────────────

function handle_list() {
    global $config;

    $vlans = [];
    if (!is_array($config['vlans']['vlan'] ?? null)) {
        respond(200, ['vlans' => [], 'count' => 0]);
    }

    foreach ($config['vlans']['vlan'] as $v) {
        $tag = (int)($v['tag'] ?? 0);
        if ($tag < VLAN_MIN || $tag > VLAN_MAX) continue;
        if (($v['if'] ?? '') !== PARENT_IF) continue;

        $iface_key = find_interface_by_vlan($tag);
        $descr = $config['interfaces'][$iface_key]['descr'] ?? $v['descr'] ?? '';
        $pool = (strpos($descr, '_x_') !== false) ? 'offline' : 'online';
        $ip = $config['interfaces'][$iface_key]['ipaddr'] ?? '';

        // Extract name from description (vlan_142_o_some-name → some-name)
        $name = '';
        if (preg_match('/^vlan_\d+_[ox]_(.+)$/', $descr, $m)) {
            $name = $m[1];
        }

        $vlans[] = [
            'vlan_id'     => $tag,
            'interface'   => $iface_key,
            'description' => $descr,
            'name'        => $name,
            'pool'        => $pool,
            'ip'          => $ip ? "{$ip}/29" : '',
            'enabled'     => isset($config['interfaces'][$iface_key]['enable']),
        ];
    }

    usort($vlans, fn($a, $b) => $a['vlan_id'] <=> $b['vlan_id']);
    respond(200, ['vlans' => $vlans, 'count' => count($vlans)]);
}

// ─── Endpoint: Delete VLAN ──────────────────────────────────

function handle_delete($vlan_id) {
    global $config;

    $vlan_id = validate_vlan_id($vlan_id);

    $iface_key = find_interface_by_vlan($vlan_id);
    if (!$iface_key) {
        respond(404, ['error' => "VLAN {$vlan_id} not found"]);
    }

    $tracker_base = 1700000000 + $vlan_id * 10;

    // 1. Remove firewall rules (by tracker)
    if (isset($config['filter']['rule'])) {
        $config['filter']['rule'] = array_values(array_filter(
            $config['filter']['rule'],
            function($rule) use ($tracker_base) {
                $t = (int)($rule['tracker'] ?? 0);
                return $t < $tracker_base || $t >= $tracker_base + 10;
            }
        ));
    }

    // 2. Remove DHCP config
    unset($config['dhcpd'][$iface_key]);

    // 3. Remove interface assignment
    unset($config['interfaces'][$iface_key]);

    // 4. Remove VLAN entry
    $vlan_idx = find_vlan_entry($vlan_id);
    if ($vlan_idx !== null) {
        unset($config['vlans']['vlan'][$vlan_idx]);
        $config['vlans']['vlan'] = array_values($config['vlans']['vlan']);
    }

    // 5. Write config and apply
    write_config("DaathNet API: Deleted VLAN {$vlan_id}");
    services_dhcpd_configure();
    filter_configure();

    respond(200, [
        'status'  => 'deleted',
        'vlan_id' => $vlan_id,
    ]);
}
