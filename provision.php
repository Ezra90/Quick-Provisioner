<?php
// provision.php - Quick-Provisioner - Mustache Provisioning Engine
include '/etc/freepbx.conf';
require_once __DIR__ . '/MustacheEngine.php';

if (!function_exists('qp_is_local_network')) {
    function qp_is_local_network() {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        if ($ip === '::1') return true;
        if (preg_match('/^(127\.|10\.|192\.168\.|172\.(1[6-9]|2[0-9]|3[01])\.)/', $ip)) {
            return true;
        }
        return false;
    }
}

function qp_log_access($status_code, $path, $mac, $extension, $resource_type) {
    try {
        // FreePBX 17 Database::query() no longer accepts bound params as arg #2
        $stmt = \FreePBX::Database()->prepare(
            "INSERT INTO quickprovisioner_access_log (status_code, method, path, client_ip, mac, extension, resource_type, user_agent) VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->execute([
            $status_code,
            $_SERVER['REQUEST_METHOD'] ?? 'GET',
            substr($path, 0, 255),
            $_SERVER['REMOTE_ADDR'] ?? '',
            $mac,
            $extension,
            $resource_type,
            substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255)
        ]);
    } catch (Exception $e) {
        // Don't let log failures break provisioning
    }
}

/**
 * Validate remote Basic Auth against a device's prov_username/prov_password.
 */
function qp_check_device_basic_auth(array $device) {
    if (qp_is_local_network()) {
        return true;
    }
    $prov_user = $device['prov_username'] ?? '';
    $prov_pass = $device['prov_password'] ?? '';
    if ($prov_user === '' || $prov_pass === '') {
        header('WWW-Authenticate: Basic realm="Phone Provisioning"');
        header('HTTP/1.0 401 Unauthorized');
        die('Authentication required');
    }
    $user = $_SERVER['PHP_AUTH_USER'] ?? '';
    $pass = $_SERVER['PHP_AUTH_PW'] ?? '';
    if ($user !== $prov_user || $pass !== $prov_pass) {
        header('WWW-Authenticate: Basic realm="Phone Provisioning"');
        header('HTTP/1.0 401 Unauthorized');
        die('Authentication required');
    }
    return true;
}

/**
 * For shared assets (ringtones/firmware): require local net OR Basic Auth that
 * matches any Quick-Provisioner device with provisioning credentials set.
 * Returns the matched device row or true on local network.
 */
function qp_check_asset_basic_auth($preferred_mac = null) {
    if (qp_is_local_network()) {
        return true;
    }
    $user = $_SERVER['PHP_AUTH_USER'] ?? '';
    $pass = $_SERVER['PHP_AUTH_PW'] ?? '';
    if ($user === '' || $pass === '') {
        header('WWW-Authenticate: Basic realm="Phone Provisioning"');
        header('HTTP/1.0 401 Unauthorized');
        die('Authentication required');
    }

    // Prefer binding auth to a specific MAC when known (phonebook files).
    if ($preferred_mac) {
        $mac = strtoupper(preg_replace('/[^A-Fa-f0-9]/', '', $preferred_mac));
        $stmt = \FreePBX::Database()->prepare("SELECT * FROM quickprovisioner_devices WHERE mac=?");
        $stmt->execute([$mac]);
        $device = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($device) {
            qp_check_device_basic_auth($device);
            return $device;
        }
        http_response_code(404);
        die('Device not found');
    }

    $stmt = \FreePBX::Database()->query(
        "SELECT * FROM quickprovisioner_devices WHERE prov_username IS NOT NULL AND prov_username != '' AND prov_password IS NOT NULL AND prov_password != ''"
    );
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        if (hash_equals((string)$row['prov_username'], $user) && hash_equals((string)$row['prov_password'], $pass)) {
            return $row;
        }
    }
    header('WWW-Authenticate: Basic realm="Phone Provisioning"');
    header('HTTP/1.0 401 Unauthorized');
    die('Authentication required');
}

/**
 * Extract MAC from phonebook filenames: pb_AABBCCDDEEFF.xml or aabbccddeeff-directory.xml
 */
function qp_mac_from_asset_filename($filename) {
    $filename = basename((string)$filename);
    if (preg_match('/^pb_([A-Fa-f0-9]{12})\.xml$/i', $filename, $m)) {
        return strtoupper($m[1]);
    }
    if (preg_match('/^([A-Fa-f0-9]{12})-directory\.xml$/i', $filename, $m)) {
        return strtoupper($m[1]);
    }
    return null;
}

// ---------------------------------------------------------------------------
// Dynamic phonebook XML (?mac=...&type=phonebook|directory)
// ---------------------------------------------------------------------------
if (isset($_GET['type']) && in_array($_GET['type'], ['phonebook', 'directory'], true)) {
    $mac = isset($_GET['mac']) ? strtoupper(preg_replace('/[^A-Fa-f0-9]/', '', $_GET['mac'])) : '';
    if (strlen($mac) !== 12 || !ctype_xdigit($mac)) {
        http_response_code(400);
        die('Invalid MAC');
    }
    $stmt = \FreePBX::Database()->prepare("SELECT * FROM quickprovisioner_devices WHERE mac=?");
    $stmt->execute([$mac]);
    $device = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$device) {
        http_response_code(404);
        die('Device not found');
    }
    qp_check_device_basic_auth($device);
    $contacts = qp_normalize_contacts($device['contacts_json'] ?? '[]');
    if (empty($contacts)) {
        http_response_code(404);
        die('No contacts');
    }
    // Polycom local directory uses Polycom XML; Yealink/Cisco remote book uses vendor XML
    $model_for_xml = ($_GET['type'] === 'directory') ? 'VVX250' : ($device['model'] ?? '');
    $xml = qp_generate_phonebook_xml($contacts, $model_for_xml, $device['extension'] ?? '');
    qp_save_phonebook_for_device($device, $contacts);
    $out_name = ($_GET['type'] === 'directory') ? (strtolower($mac) . '-directory.xml') : ('pb_' . $mac . '.xml');
    header('Content-Type: application/xml; charset=utf-8');
    header('Content-Disposition: inline; filename="' . $out_name . '"');
    qp_log_access(200, $_SERVER['REQUEST_URI'] ?? '', $mac, $device['extension'] ?? '', 'phonebook');
    echo $xml;
    exit;
}

// PATH_INFO / URI style: .../provision.php/aabbccddeeff-directory.xml or .../pb_MAC.xml
$path_info = $_SERVER['PATH_INFO'] ?? '';
$request_uri_for_dir = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '';
$dir_basename = basename($path_info !== '' ? $path_info : $request_uri_for_dir);
if ($dir_basename && preg_match('/^([A-Fa-f0-9]{12})-directory\.xml$/i', $dir_basename, $dm)) {
    $_GET['mac'] = strtoupper($dm[1]);
    $_GET['type'] = 'directory';
    // Re-enter directory handler via include logic — simplest: redirect internal
    $mac = strtoupper($dm[1]);
    $stmt = \FreePBX::Database()->prepare("SELECT * FROM quickprovisioner_devices WHERE mac=?");
    $stmt->execute([$mac]);
    $device = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$device) {
        http_response_code(404);
        die('Device not found');
    }
    qp_check_device_basic_auth($device);
    $contacts = qp_normalize_contacts($device['contacts_json'] ?? '[]');
    if (empty($contacts)) {
        http_response_code(404);
        die('No contacts');
    }
    $xml = qp_generate_phonebook_xml($contacts, 'VVX250', $device['extension'] ?? '');
    header('Content-Type: application/xml; charset=utf-8');
    header('Content-Disposition: inline; filename="' . strtolower($mac) . '-directory.xml"');
    qp_log_access(200, $request_uri_for_dir, $mac, $device['extension'] ?? '', 'phonebook');
    echo $xml;
    exit;
}

// ---------------------------------------------------------------------------
// Asset file serving (ringtones, firmware, phonebook)
// ---------------------------------------------------------------------------
$request_uri = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);

$asset_routes = [
    '/ringtones/' => ['dir' => __DIR__ . '/assets/ringtones', 'type' => 'audio/wav'],
    '/firmware/'  => ['dir' => __DIR__ . '/assets/firmware',  'type' => 'application/octet-stream'],
    '/phonebook/' => ['dir' => __DIR__ . '/assets/phonebook', 'type' => 'application/xml'],
];

foreach ($asset_routes as $prefix => $route) {
    if ($request_uri && strpos($request_uri, $prefix) !== false) {
        $pos = strpos($request_uri, $prefix);
        $raw_filename = substr($request_uri, $pos + strlen($prefix));
        $filename = basename($raw_filename);

        if ($filename === '' || $filename === '.' || $filename === '..') {
            http_response_code(400);
            die('Invalid filename');
        }

        $mac_hint = qp_mac_from_asset_filename($filename);
        // Phonebook files must map to a device; shared assets accept any valid device creds
        if (strpos($prefix, 'phonebook') !== false) {
            if (!$mac_hint) {
                http_response_code(400);
                die('Phonebook filename must include device MAC');
            }
            qp_check_asset_basic_auth($mac_hint);
        } else {
            qp_check_asset_basic_auth(null);
        }

        $file_path = $route['dir'] . '/' . $filename;
        $real_path = realpath($file_path);
        $real_dir  = realpath($route['dir']);

        if ($real_path === false || $real_dir === false || !is_file($real_path)) {
            http_response_code(404);
            die('File not found');
        }
        $under_dir = (strpos($real_path, $real_dir . DIRECTORY_SEPARATOR) === 0)
            || (strpos($real_path, $real_dir . '/') === 0);
        if (!$under_dir) {
            http_response_code(404);
            die('File not found');
        }

        $safe_filename = str_replace('"', '\\"', $filename);
        header('Content-Type: ' . $route['type']);
        header('Content-Disposition: attachment; filename="' . $safe_filename . '"');
        $rt = 'asset';
        if (strpos($prefix, 'ringtone') !== false) $rt = 'ringtone';
        elseif (strpos($prefix, 'firmware') !== false) $rt = 'firmware';
        elseif (strpos($prefix, 'phonebook') !== false) $rt = 'phonebook';
        qp_log_access(200, $request_uri, $mac_hint, null, $rt);
        readfile($real_path);
        exit;
    }
}

// ---------------------------------------------------------------------------
// MAC-based device provisioning
// ---------------------------------------------------------------------------
$mac = isset($_GET['mac']) ? strtoupper(preg_replace('/[^A-Fa-f0-9]/', '', $_GET['mac'])) : null;
if (!$mac || strlen($mac) !== 12 || !ctype_xdigit($mac)) {
    \FreePBX::create()->Logger->log(FPBX_LOG_WARNING, "Invalid MAC attempt: " . ($mac ?? 'none'));
    http_response_code(400);
    die("Invalid or no MAC provided");
}

$stmt = \FreePBX::Database()->prepare("SELECT * FROM quickprovisioner_devices WHERE mac=?");
$stmt->execute([$mac]);
$device = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$device) {
    \FreePBX::create()->Logger->log(FPBX_LOG_WARNING, "Device not found for MAC: $mac");
    http_response_code(404);
    die("Device not found");
}

// Check authentication for remote access
if (!qp_is_local_network()) {
    // Log warning if remote provisioning over HTTP
    if (!isset($_SERVER['HTTPS']) || $_SERVER['HTTPS'] !== 'on') {
        \FreePBX::create()->Logger->log(FPBX_LOG_WARNING, "WARNING: Remote provisioning over HTTP (non-HTTPS) for MAC: $mac");
    }

    $prov_user = $device['prov_username'] ?? '';
    $prov_pass = $device['prov_password'] ?? '';

    // Always require credentials for remote access
    if (empty($prov_user) || empty($prov_pass)) {
        header('WWW-Authenticate: Basic realm="Phone Provisioning"');
        header('HTTP/1.0 401 Unauthorized');
        \FreePBX::create()->Logger->log(FPBX_LOG_WARNING, "Remote provisioning denied - no credentials configured for MAC: $mac");
        die('Authentication required');
    }

    $auth_user = $_SERVER['PHP_AUTH_USER'] ?? '';
    $auth_pass = $_SERVER['PHP_AUTH_PW'] ?? '';

    if ($auth_user !== $prov_user || $auth_pass !== $prov_pass) {
        header('WWW-Authenticate: Basic realm="Phone Provisioning"');
        header('HTTP/1.0 401 Unauthorized');
        \FreePBX::create()->Logger->log(FPBX_LOG_WARNING, "Unauthorized provisioning attempt for MAC: $mac");
        die('Authentication required');
    }
}

// ---------------------------------------------------------------------------
// Resolve Mustache template for the device model
// ---------------------------------------------------------------------------
$model = basename($device['model']); // Sanitize to prevent path traversal
$template_path = qp_resolve_template_file($model, __DIR__ . '/templates');

if ($template_path === null) {
    http_response_code(404);
    die("Template not found for model $model");
}

$source = file_get_contents($template_path);
if ($source === false) {
    http_response_code(500);
    die("Failed to read template for model $model");
}

// Parse META block for content_type, filename_pattern, and context defaults
$meta = qp_parse_template_meta($source);
if ($meta === null) {
    http_response_code(500);
    die("Invalid or missing META block in template for model $model");
}

$content_type = $meta['content_type'] ?? 'text/plain';
$filename_pattern = $meta['filename_pattern'] ?? '{mac}.cfg';
$filename = str_replace('{mac}', $mac, $filename_pattern);

// ---------------------------------------------------------------------------
// Gather server-side data for the provisioning context
// ---------------------------------------------------------------------------
$ext = $device['extension'];
$display_name = $ext;
$secret = '';

// Fetch display name from FreePBX
try {
    $userInfo = \FreePBX::Core()->getUser($ext);
    $display_name = $userInfo['name'] ?? $ext;
} catch (Exception $e) {
    error_log("Quick-Provisioner: Error fetching user info for extension $ext - " . $e->getMessage());
}

// Use custom secret if available, otherwise fetch from FreePBX
if (!empty($device['custom_sip_secret'])) {
    $secret = $device['custom_sip_secret'];
} else {
    try {
        $deviceInfo = \FreePBX::Core()->getDevice($ext);
        $secret = $deviceInfo['secret'] ?? '';
    } catch (Exception $e) {
        error_log("Quick-Provisioner: Error fetching secret for extension $ext - " . $e->getMessage());
    }
}

$server_ip = qp_resolve_sip_server([]);
$sip_port = qp_resolve_sip_port([]);

// Per-device custom_options may still override inside qp_build_provisioning_context
$custom_opts = [];
if (!empty($device['custom_options_json'])) {
    $custom_opts = json_decode($device['custom_options_json'], true) ?: [];
}
if (!empty($custom_opts['sip_server'])) {
    $server_ip = $custom_opts['sip_server'];
}
if (!empty($custom_opts['sip_port'])) {
    $sip_port = $custom_opts['sip_port'];
}

// Build wallpaper URL if the device has a wallpaper configured
$wallpaper_url = '';
if (!empty($device['wallpaper'])) {
    $protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'];
    $wallpaper_url = "$protocol://$host/admin/modules/quickprovisioner/media.php?mac=$mac";
}

// Build the provisioning base URL from the current request
$prov_protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
$prov_host = $_SERVER['HTTP_HOST'];
$provisioning_url = "$prov_protocol://$prov_host/admin/modules/quickprovisioner/provision.php?mac=$mac";

$server_info = [
    'server_ip'        => $server_ip,
    'server_port'      => $sip_port,
    'sip_port'         => $sip_port,
    'display_name'     => $display_name,
    'secret'           => $secret,
    'wallpaper_url'    => $wallpaper_url,
    'provisioning_url' => $provisioning_url,
    'phonebook_url'    => qp_build_phonebook_url($mac),
    'phonebook_name'   => 'Directory',
    'polycom_contacts_directory' => qp_build_polycom_contacts_directory_url(),
];

// Keep on-disk phonebook XML in sync for PATH_INFO / static fetches
qp_save_phonebook_for_device($device);

// ---------------------------------------------------------------------------
// Build context and render the Mustache template
// ---------------------------------------------------------------------------
$context = qp_build_provisioning_context($device, $meta, $server_info);

// Strip the META comment block so it doesn't appear in the output
$template_source = preg_replace('/\{\{!\s*META:\s*\{[\s\S]*?\}\s*\}\}\s*/', '', $source);

$output = qp_render_mustache($template_source, $context);

// Send response headers and body
$safe_filename = str_replace('"', '\\"', $filename);
header("Content-Type: $content_type");
header("Content-Disposition: attachment; filename=\"$safe_filename\"");
qp_log_access(200, $request_uri ?? "?mac=$mac", $mac, $ext, 'config');
echo $output;
?>
