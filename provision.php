<?php
// provision.php - Quick-Provisioner - Mustache Provisioning Engine
include '/etc/freepbx.conf';
require_once __DIR__ . '/MustacheEngine.php';

if (!function_exists('qp_lookup_secret_from_asterisk')) {
    function qp_lookup_secret_from_asterisk(string $ext): string {
        $ext = preg_replace('/[^0-9A-Za-z_-]/', '', $ext);
        if ($ext === '') {
            return '';
        }
        $auth = $ext . '-auth';
        $cmd = 'asterisk -rx ' . escapeshellarg("pjsip show auth {$auth}");
        $out = [];
        $code = 0;
        @exec($cmd . ' 2>/dev/null', $out, $code);
        if ($code !== 0 || empty($out)) {
            return '';
        }
        $text = implode("\n", $out);
        if (preg_match('/^\s*password\s*:\s*(\S+)/mi', $text, $m)) {
            return trim((string)$m[1]);
        }
        return '';
    }
}

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
 * Require QSetup-style Basic Auth (prov_username/prov_password) for a device.
 * Always enforced (LAN + WAN). Pair with MAC checks for dual-factor binding.
 */
function qp_check_device_basic_auth(array $device) {
    $prov_user = $device['prov_username'] ?? '';
    $prov_pass = $device['prov_password'] ?? '';
    $mac = (string)($device['mac'] ?? '');
    if ($prov_user === '' || $prov_pass === '') {
        if (function_exists('qp_auth_fail_log')) {
            qp_auth_fail_log('missing_creds', $mac);
        }
        header('WWW-Authenticate: Basic realm="Phone Provisioning"');
        header('HTTP/1.0 401 Unauthorized');
        die('Authentication required');
    }
    $user = $_SERVER['PHP_AUTH_USER'] ?? '';
    $pass = $_SERVER['PHP_AUTH_PW'] ?? '';
    if ($user === '' || $pass === ''
        || !hash_equals((string)$prov_user, (string)$user)
        || !hash_equals((string)$prov_pass, (string)$pass)) {
        if (function_exists('qp_auth_fail_log')) {
            qp_auth_fail_log($user === '' ? 'no_auth' : 'bad_auth', $mac);
        }
        header('WWW-Authenticate: Basic realm="Phone Provisioning"');
        header('HTTP/1.0 401 Unauthorized');
        die('Authentication required');
    }
    return true;
}

/**
 * Dual-factor: Prov credentials must match the device for this MAC.
 * In lan_open mode, local-network clients skip auth (bring-up / no QSetup).
 */
function qp_require_mac_and_prov_auth(array $device) {
    if (function_exists('qp_lan_open_auth') && qp_lan_open_auth()) {
        return true;
    }
    qp_check_device_basic_auth($device);
    $user = $_SERVER['PHP_AUTH_USER'] ?? '';
    $pass = $_SERVER['PHP_AUTH_PW'] ?? '';
    $authed = qp_find_device_by_prov_auth($user, $pass);
    $wantMac = strtoupper(preg_replace('/[^A-Fa-f0-9]/', '', (string)($device['mac'] ?? '')));
    $gotMac = strtoupper(preg_replace('/[^A-Fa-f0-9]/', '', (string)($authed['mac'] ?? '')));
    if (!$authed || $wantMac === '' || $gotMac === '' || !hash_equals($wantMac, $gotMac)) {
        if (function_exists('qp_auth_fail_log')) {
            qp_auth_fail_log('mac_mismatch', $wantMac);
        }
        header('WWW-Authenticate: Basic realm="Phone Provisioning"');
        header('HTTP/1.0 401 Unauthorized');
        die('Authentication required');
    }
    return true;
}

/**
 * Shared assets (ringtones/firmware/phonebook): require Prov auth.
 * When preferred_mac is set, credentials must belong to that MAC.
 */
function qp_check_asset_basic_auth($preferred_mac = null) {
    if (function_exists('qp_lan_open_auth') && qp_lan_open_auth()) {
        return true;
    }
    $user = $_SERVER['PHP_AUTH_USER'] ?? '';
    $pass = $_SERVER['PHP_AUTH_PW'] ?? '';
    if ($user === '' || $pass === '') {
        if (function_exists('qp_auth_fail_log')) {
            qp_auth_fail_log('no_auth', (string)$preferred_mac);
        }
        header('WWW-Authenticate: Basic realm="Phone Provisioning"');
        header('HTTP/1.0 401 Unauthorized');
        die('Authentication required');
    }

    if ($preferred_mac) {
        $mac = strtoupper(preg_replace('/[^A-Fa-f0-9]/', '', $preferred_mac));
        $stmt = \FreePBX::Database()->prepare("SELECT * FROM quickprovisioner_devices WHERE mac=?");
        $stmt->execute([$mac]);
        $device = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($device) {
            qp_require_mac_and_prov_auth($device);
            return $device;
        }
        if (function_exists('qp_auth_fail_log')) {
            qp_auth_fail_log('unknown_mac', $mac);
        }
        http_response_code(404);
        die('Device not found');
    }

    $device = qp_find_device_by_prov_auth($user, $pass);
    if ($device) {
        return $device;
    }
    if (function_exists('qp_auth_fail_log')) {
        qp_auth_fail_log('bad_auth', '');
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

/**
 * Extract MAC from RPS-style config filenames: AABBCCDDEEFF.cfg / .xml
 */
function qp_mac_from_config_filename($filename) {
    $filename = basename((string)$filename);
    if (preg_match('/^([A-Fa-f0-9]{12})\.(cfg|xml)$/i', $filename, $m)) {
        return strtoupper($m[1]);
    }
    return null;
}

function qp_is_poly_model($model): bool {
    $model = strtoupper((string)$model);
    return strpos($model, 'VVX') !== false || strpos($model, 'POLY') !== false || strpos($model, 'EDGE') !== false;
}

function qp_poly_secondary_filename(string $mac): string {
    return strtolower($mac) . '-prov.cfg';
}

function qp_poly_primary_config(string $mac): string {
    $secondary = qp_poly_secondary_filename($mac);
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
        . '<APPLICATION APP_FILE_PATH="sip.ld" CONFIG_FILES="' . $secondary . "\" />\n";
}

/**
 * Render and output provisioning config for a registered device MAC.
 */
function qp_serve_device_config($mac, $requested_filename = null) {
    $mac = strtoupper(preg_replace('/[^A-Fa-f0-9]/', '', (string)$mac));
    if (strlen($mac) !== 12 || !ctype_xdigit($mac)) {
        if (function_exists('qp_auth_fail_log')) {
            qp_auth_fail_log('invalid_mac', $mac);
        }
        http_response_code(400);
        die('Invalid or no MAC provided');
    }

    $stmt = \FreePBX::Database()->prepare("SELECT * FROM quickprovisioner_devices WHERE mac=?");
    $stmt->execute([$mac]);
    $device = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$device) {
        \FreePBX::create()->Logger->log(FPBX_LOG_WARNING, "Device not found for MAC: $mac");
        if (function_exists('qp_auth_fail_log')) {
            qp_auth_fail_log('unknown_mac', $mac);
        }
        http_response_code(404);
        die('Device not found');
    }

    if (empty($_SERVER['HTTPS']) || $_SERVER['HTTPS'] !== 'on') {
        if (!function_exists('qp_is_local_network') || !qp_is_local_network()) {
            \FreePBX::create()->Logger->log(FPBX_LOG_WARNING, "WARNING: Remote provisioning over HTTP (non-HTTPS) for MAC: $mac");
        }
    }
    // QSetup + MAC: Prov user/pass must match this device's MAC.
    qp_require_mac_and_prov_auth($device);

    $model = basename($device['model']);
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

    $meta = qp_parse_template_meta($source);
    if ($meta === null) {
        http_response_code(500);
        die("Invalid or missing META block in template for model $model");
    }

    $content_type = $meta['content_type'] ?? 'text/plain';
    $filename_pattern = $meta['filename_pattern'] ?? '{mac}.cfg';
    $filename = str_replace('{mac}', $mac, $filename_pattern);
    $requested_filename = basename((string)$requested_filename);
    $is_poly = qp_is_poly_model($device['model'] ?? $model);
    $requested_is_poly_primary = $is_poly && $requested_filename !== '' && preg_match('/^[A-Fa-f0-9]{12}\.cfg$/', $requested_filename);
    $requested_is_poly_secondary = $is_poly && $requested_filename !== '' && preg_match('/^[A-Fa-f0-9]{12}-prov\.cfg$/', $requested_filename);

    $ext = $device['extension'];
    $display_name = $ext;
    $secret = '';

    try {
        $userInfo = \FreePBX::Core()->getUser($ext);
        if (is_array($userInfo) && !empty($userInfo['name'])) {
            $display_name = (string)$userInfo['name'];
        }
    } catch (\Throwable $e) {
        error_log("Quick-Provisioner: Error fetching user info for extension $ext - " . $e->getMessage());
    }
    // Fallback: FreePBX users table (some web contexts return empty getUser name)
    if ($display_name === '' || $display_name === $ext) {
        try {
            $ust = \FreePBX::Database()->prepare('SELECT name FROM users WHERE extension = ? LIMIT 1');
            $ust->execute([(string)$ext]);
            $uname = $ust->fetchColumn();
            if (is_string($uname) && trim($uname) !== '') {
                $display_name = trim($uname);
            }
        } catch (\Throwable $e) {
            // keep extension fallback
        }
    }
    // Prefer an explicit primary line-key label only when the user set a real
    // name. autoFillBtn1 defaults label to the extension number, which must NOT
    // override FreePBX display names when the label is only the extension digits.
    if (!empty($device['keys_json'])) {
        $kj = json_decode($device['keys_json'], true);
        if (is_array($kj)) {
            foreach ($kj as $krow) {
                if (!is_array($krow)) continue;
                $kt = strtolower((string)($krow['type'] ?? ''));
                $ki = (int)($krow['index'] ?? 0);
                $kl = trim((string)($krow['label'] ?? ''));
                if ($kt === 'line' && $ki === 1 && $kl !== '' && $kl !== (string)$ext) {
                    $display_name = $kl;
                    break;
                }
            }
        }
    }

    if (!empty($device['custom_sip_secret'])) {
        $secret = $device['custom_sip_secret'];
    } else {
        try {
            $deviceInfo = \FreePBX::Core()->getDevice($ext);
            $secret = $deviceInfo['secret'] ?? '';
        } catch (Exception $e) {
            error_log("Quick-Provisioner: Error fetching secret for extension $ext - " . $e->getMessage());
        }
        // Some FreePBX builds do not return pjsip auth password via getDevice().
        // Fall back to Asterisk auth introspection to avoid blank reg passwords.
        if ($secret === '') {
            $secret = qp_lookup_secret_from_asterisk((string)$ext);
        }
    }

    $server_ip = qp_resolve_sip_server([]);
    $sip_port = qp_resolve_sip_port([]);
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

    $wallpaper_url = '';
    if (!empty($device['wallpaper'])) {
        $protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
        $host = function_exists('qp_public_http_authority')
            ? qp_public_http_authority()
            : (function_exists('qp_public_http_host') ? qp_public_http_host() : ($_SERVER['HTTP_HOST'] ?? '127.0.0.1'));
        $wallpaper_file = rawurlencode((string)$device['wallpaper']);
        // Path-style URL only — Poly VVX often rejects / mishandles ?query on bm.1.name.
        // media.php infers MAC from wallpaper ownership when mac= is omitted.
        $wallpaper_url = "$protocol://$host/admin/modules/quickprovisioner/media.php/$wallpaper_file";
    }

    $prov = qp_build_provisioning_urls($mac);
    $server_info = [
        'server_ip'        => $server_ip,
        'server_port'      => $sip_port,
        'sip_port'         => $sip_port,
        'display_name'     => $display_name,
        'secret'           => $secret,
        'wallpaper_url'    => $wallpaper_url,
        'provisioning_url' => $prov['provisioning_url'],
        'provisioning_base'=> $prov['provisioning_base'],
        'phonebook_url'    => qp_build_phonebook_url($mac),
        'phonebook_name'   => 'Directory',
        'polycom_contacts_directory' => qp_build_polycom_contacts_directory_url(),
    ];

    qp_save_phonebook_for_device($device);

    $context = qp_build_provisioning_context($device, $meta, $server_info);
    $template_source = preg_replace('/\{\{!\s*META:\s*\{[\s\S]*\}\s*\}\}\s*/', '', $source);
    $output = qp_render_mustache($template_source, $context);

    if ($requested_is_poly_primary) {
        $content_type = 'application/xml';
        $filename = strtolower($mac) . '.cfg';
        $output = qp_poly_primary_config($mac);
    } elseif ($requested_is_poly_secondary) {
        $content_type = 'application/xml';
        $filename = strtolower($mac) . '-prov.cfg';
    } elseif ($requested_filename !== '') {
        // Respect the template-declared content type for non-Poly handsets.
        // Only the filename should mirror what the handset requested.
        $filename = $requested_filename;
    }

    $request_uri = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: ('?mac=' . $mac);
    $safe_filename = str_replace('"', '\\"', $filename);
    header("Content-Type: $content_type");
    header("Content-Disposition: inline; filename=\"$safe_filename\"");
    qp_log_access(200, $request_uri, $mac, $ext, 'config');
    // Refresh Intrusion Detection whitelist with this handset's client IP
    if (function_exists('qp_sync_firewall_mac_whitelist')) {
        try {
            qp_sync_firewall_mac_whitelist();
        } catch (Throwable $e) {
            // never break provisioning
        }
    }
    echo $output;
    exit;
}

// ---------------------------------------------------------------------------
// Dynamic phonebook XML (?mac=...&type=phonebook|directory)
// ---------------------------------------------------------------------------
if (isset($_GET['type']) && in_array($_GET['type'], ['phonebook', 'directory'], true)) {
    $mac = isset($_GET['mac']) ? strtoupper(preg_replace('/[^A-Fa-f0-9]/', '', $_GET['mac'])) : '';
    if (strlen($mac) !== 12 || !ctype_xdigit($mac)) {
        if (function_exists('qp_auth_fail_log')) {
            qp_auth_fail_log('invalid_mac', $mac);
        }
        http_response_code(400);
        die('Invalid MAC');
    }
    $stmt = \FreePBX::Database()->prepare("SELECT * FROM quickprovisioner_devices WHERE mac=?");
    $stmt->execute([$mac]);
    $device = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$device) {
        if (function_exists('qp_auth_fail_log')) {
            qp_auth_fail_log('unknown_mac', $mac);
        }
        http_response_code(404);
        die('Device not found');
    }
    qp_require_mac_and_prov_auth($device);
    $contacts = qp_normalize_contacts($device['contacts_json'] ?? '[]');
    $contacts = qp_apply_poly_directory_speed_dials($device, $contacts);
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

// PATH_INFO / URI style: .../provision.php/AABBCCDDEEFF.cfg|.xml (RPS / Poly / Cisco)
$path_info = $_SERVER['PATH_INFO'] ?? '';
$request_uri_for_dir = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '';
$dir_basename = basename($path_info !== '' ? $path_info : $request_uri_for_dir);
if (strcasecmp($dir_basename, '000000000000.cfg') === 0) {
    // Discovery stub (no secrets). QSetup off on LAN when auth_mode=lan_open.
    $base = function_exists('qp_build_provisioning_urls')
        ? (qp_build_provisioning_urls('000000000000')['provisioning_base'] ?? '')
        : '';
    $qsetupOn = (function_exists('qp_lan_open_auth') && qp_lan_open_auth()) ? '0' : '1';
    $lanOpenLocal = (function_exists('qp_lan_open_auth') && qp_lan_open_auth());
    $bootOpt = $lanOpenLocal ? '1' : '2';
    header('Content-Type: application/xml');
    header('Content-Disposition: inline; filename="000000000000.cfg"');
    echo '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
    echo "<PHONE_CONFIG>\n";
    echo "  <APPLICATION APP_FILE_PATH=\"sip.ld\" CONFIG_FILES=\"[PHONE_MAC_ADDRESS]-prov.cfg\" />\n";
    echo "  <prov prov.quickSetup.enabled=\"{$qsetupOn}\" prov.quickSetup.limitServerDetails=\"1\" />\n";
    if ($base !== '') {
        echo "  <DEVICE device.set=\"1\" device.prov.serverName=\"" . htmlspecialchars($base, ENT_QUOTES) . "\" device.prov.serverType=\"HTTP\""
            . " device.dhcp.bootSrvUseOpt.set=\"1\" device.dhcp.bootSrvUseOpt=\"{$bootOpt}\""
            . " device.prov.ztpEnabled.set=\"1\" device.prov.ztpEnabled=\"0\" />\n";
    }
    if (!$lanOpenLocal) {
        echo "  <UPDATER updater.autoPowerUp.set=\"1\" updater.autoPowerUp=\"0\" updater.application.url.set=\"1\" updater.application.url=\"\" />\n";
    }
    echo "</PHONE_CONFIG>\n";
    qp_log_access(200, $request_uri_for_dir, null, null, 'config');
    exit;
}
$config_mac = qp_mac_from_config_filename($dir_basename);
if ($config_mac) {
    qp_serve_device_config($config_mac, $dir_basename);
}

if ($dir_basename && preg_match('/^([A-Fa-f0-9]{12})-prov\.cfg$/i', $dir_basename, $pm)) {
    qp_serve_device_config(strtoupper($pm[1]), $dir_basename);
}

// PATH_INFO / URI style: .../provision.php/aabbccddeeff-directory.xml or .../pb_MAC.xml
if ($dir_basename && preg_match('/^([A-Fa-f0-9]{12})-directory\.xml$/i', $dir_basename, $dm)) {
    $_GET['mac'] = strtoupper($dm[1]);
    $_GET['type'] = 'directory';
    // Re-enter directory handler via include logic — simplest: redirect internal
    $mac = strtoupper($dm[1]);
    $stmt = \FreePBX::Database()->prepare("SELECT * FROM quickprovisioner_devices WHERE mac=?");
    $stmt->execute([$mac]);
    $device = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$device) {
        if (function_exists('qp_auth_fail_log')) {
            qp_auth_fail_log('unknown_mac', $mac);
        }
        http_response_code(404);
        die('Device not found');
    }
    qp_require_mac_and_prov_auth($device);
    $contacts = qp_normalize_contacts($device['contacts_json'] ?? '[]');
    $contacts = qp_apply_poly_directory_speed_dials($device, $contacts);
    if (empty($contacts)) {
        http_response_code(404);
        die('No contacts');
    }
    $xml = qp_generate_phonebook_xml($contacts, 'VVX250', $device['extension'] ?? '');
    qp_save_phonebook_for_device($device, $contacts);
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
// MAC-based device provisioning (?mac=AABBCCDDEEFF)
// ---------------------------------------------------------------------------
$mac = isset($_GET['mac']) ? strtoupper(preg_replace('/[^A-Fa-f0-9]/', '', $_GET['mac'])) : null;
if (!$mac || strlen($mac) !== 12 || !ctype_xdigit($mac)) {
    \FreePBX::create()->Logger->log(FPBX_LOG_WARNING, "Invalid MAC attempt: " . ($mac ?? 'none'));
    http_response_code(400);
    die("Invalid or no MAC provided");
}

qp_serve_device_config($mac);
?>
