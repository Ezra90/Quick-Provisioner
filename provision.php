<?php
// provision.php - Quick Provisioner v3.0.0 - Mustache Provisioning Engine
include '/etc/freepbx.conf';
require_once __DIR__ . '/MustacheEngine.php';

function qp_is_local_network() {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    if ($ip === '::1') return true;
    if (preg_match('/^(127\.|10\.|192\.168\.|172\.(1[6-9]|2[0-9]|3[01])\.)/', $ip)) {
        return true;
    }
    return false;
}

// ---------------------------------------------------------------------------
// Asset file serving (ringtones, firmware, phonebook)
// These endpoints are checked before MAC-based provisioning so that static
// assets can be served efficiently with the same auth rules.
// ---------------------------------------------------------------------------
$request_uri = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);

$asset_routes = [
    '/ringtones/' => ['dir' => __DIR__ . '/assets/ringtones', 'type' => 'audio/wav'],
    '/firmware/'  => ['dir' => __DIR__ . '/assets/firmware',  'type' => 'application/octet-stream'],
    '/phonebook/' => ['dir' => __DIR__ . '/assets/phonebook', 'type' => 'application/xml'],
];

foreach ($asset_routes as $prefix => $route) {
    if ($request_uri && strpos($request_uri, $prefix) !== false) {
        // Extract filename from the URL path after the prefix
        $pos = strpos($request_uri, $prefix);
        $raw_filename = substr($request_uri, $pos + strlen($prefix));
        $filename = basename($raw_filename); // prevent directory traversal

        if ($filename === '' || $filename === '.' || $filename === '..') {
            http_response_code(400);
            die('Invalid filename');
        }

        // Auth check: require local network or valid Basic Auth credentials
        if (!qp_is_local_network()) {
            $auth_user = $_SERVER['PHP_AUTH_USER'] ?? '';
            $auth_pass = $_SERVER['PHP_AUTH_PW'] ?? '';
            if ($auth_user === '' || $auth_pass === '') {
                header('WWW-Authenticate: Basic realm="Phone Provisioning"');
                header('HTTP/1.0 401 Unauthorized');
                die('Authentication required');
            }
        }

        $file_path = $route['dir'] . '/' . $filename;
        $real_path = realpath($file_path);
        $real_dir  = realpath($route['dir']);

        // Verify the resolved path is within the expected asset directory
        if ($real_path === false || $real_dir === false
            || strpos($real_path, $real_dir . '/') !== 0
            || !is_file($real_path)) {
            http_response_code(404);
            die('File not found');
        }

        $safe_filename = str_replace('"', '\\"', $filename);
        header('Content-Type: ' . $route['type']);
        header('Content-Disposition: attachment; filename="' . $safe_filename . '"');
        readfile($file_path);
        exit;
    }
}

// ---------------------------------------------------------------------------
// MAC-based device provisioning
// ---------------------------------------------------------------------------
$mac = isset($_GET['mac']) ? strtoupper(preg_replace('/[^A-F0-9]/', '', $_GET['mac'])) : null;
if (!$mac || strlen($mac) !== 12 || !ctype_xdigit($mac)) {
    \FreePBX::create()->Logger->log(FPBX_LOG_WARNING, "Invalid MAC attempt: " . ($mac ?? 'none'));
    http_response_code(400);
    die("Invalid or no MAC provided");
}

global $db;
$device = $db->getRow("SELECT * FROM quickprovisioner_devices WHERE mac=?", [$mac]);
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
    error_log("Quick Provisioner: Error fetching user info for extension $ext - " . $e->getMessage());
}

// Use custom secret if available, otherwise fetch from FreePBX
if (!empty($device['custom_sip_secret'])) {
    $secret = $device['custom_sip_secret'];
} else {
    try {
        $deviceInfo = \FreePBX::Core()->getDevice($ext);
        $secret = $deviceInfo['secret'] ?? '';
    } catch (Exception $e) {
        error_log("Quick Provisioner: Error fetching secret for extension $ext - " . $e->getMessage());
    }
}

$server_ip = $_SERVER['SERVER_ADDR'];
$sip_port = \FreePBX::Sipsettings()->get('bindport') ?? '5060';

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
];

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
echo $output;
?>
