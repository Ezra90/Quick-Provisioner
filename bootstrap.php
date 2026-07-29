<?php
/**
 * bootstrap.php — Telstra/TIPT-style QSetup bootstrap for handsets.
 *
 * DHCP option 160 should point here (no credentials in the URL).
 * Returns a tiny Poly/Yealink/Cisco cfg that:
 *   - Sets the provisioning server to Quick-Provisioner
 *   - Enables the QSetup softkey (Poly: enter Prov user + Prov password)
 *   - Does NOT embed SIP secrets or Prov passwords
 *
 * After QSetup, the handset pulls {mac}-prov.cfg with Basic Auth.
 * provision.php requires BOTH Prov credentials AND matching MAC.
 *
 * Examples:
 *   GET bootstrap.php
 *   GET bootstrap.php?vendor=polycom
 *   GET bootstrap.php?mac=AABBCCDDEEFF   (optional hint; still no secrets)
 */
include '/etc/freepbx.conf';
require_once __DIR__ . '/MustacheEngine.php';

// Poly often treats the DHCP option URL as the config root and requests
// bootstrap.php/{mac}-prov.cfg before (or instead of) honouring device.prov.serverName.
// Hand those off to provision.php so LAN bring-up actually gets SIP secrets.
$pathInfo = (string)($_SERVER['PATH_INFO'] ?? '');
if ($pathInfo === '' && !empty($_SERVER['REQUEST_URI'])) {
    $uriPath = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '';
    $marker = '/bootstrap.php';
    $pos = stripos($uriPath, $marker);
    if ($pos !== false) {
        $pathInfo = substr($uriPath, $pos + strlen($marker));
    }
}
$pathInfo = '/' . ltrim(str_replace('\\', '/', $pathInfo), '/');
$bootLeaf = basename(urldecode($pathInfo));
if (($q = strpos($bootLeaf, '?')) !== false) {
    $bootLeaf = substr($bootLeaf, 0, $q);
}
if ($bootLeaf !== '' && $bootLeaf !== 'bootstrap.php') {
    if (preg_match('/^([A-Fa-f0-9]{12})(-prov)?\.cfg$/i', $bootLeaf)
        || preg_match('/^000000000000\.cfg$/i', $bootLeaf)
        || preg_match('/^([A-Fa-f0-9]{12})-directory\.xml$/i', $bootLeaf)
        || preg_match('/^pb_([A-Fa-f0-9]{12})\.xml$/i', $bootLeaf)) {
        $_SERVER['PATH_INFO'] = '/' . $bootLeaf;
        require __DIR__ . '/provision.php';
        exit;
    }
    // Wallpaper probes relative to DHCP bootstrap URL → real media handler
    if (preg_match('/\.(jpe?g|png|bmp|gif)$/i', $bootLeaf)) {
        $_GET['file'] = $bootLeaf;
        require __DIR__ . '/media.php';
        exit;
    }
    // Firmware: phones request {part}.sip.ld under the DHCP/bootstrap base
    if (preg_match('/\.(sip\.)?ld$/i', $bootLeaf) || preg_match('/\.rom$/i', $bootLeaf)) {
        $fwPath = __DIR__ . '/assets/firmware/' . basename($bootLeaf);
        $real = realpath($fwPath);
        $fwDir = realpath(__DIR__ . '/assets/firmware');
        if ($real && $fwDir && is_file($real)
            && (strpos($real, $fwDir . DIRECTORY_SEPARATOR) === 0 || strpos($real, $fwDir . '/') === 0)) {
            header('Content-Type: application/octet-stream');
            header('Content-Length: ' . filesize($real));
            header('Content-Disposition: attachment; filename="' . basename($real) . '"');
            header('Cache-Control: no-store');
            readfile($real);
            exit;
        }
        http_response_code(404);
        exit;
    }
    // Log / misc probes — acknowledge without secrets
    if (preg_match('/\.(log|xml|cfg)$/i', $bootLeaf)) {
        http_response_code(204);
        exit;
    }
}

if (!function_exists('qp_is_local_network')) {
    function qp_is_local_network() {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        if ($ip === '::1') {
            return true;
        }
        if (preg_match('/^(127\.|10\.|192\.168\.|172\.(1[6-9]|2[0-9]|3[01])\.)/', $ip)) {
            return true;
        }
        return false;
    }
}

function qp_bootstrap_base_url() {
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    $scheme = $https ? 'https' : 'http';
    $host = function_exists('qp_public_http_authority')
        ? qp_public_http_authority()
        : ($_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_ADDR'] ?? '127.0.0.1'));
    // Prefer Host header so LAN bootstrap keeps LAN host:port when called that way.
    if (!empty($_SERVER['HTTP_HOST']) && function_exists('qp_is_local_network') && qp_is_local_network()) {
        $host = $_SERVER['HTTP_HOST'];
        if (strpos($host, ':') === false && function_exists('qp_public_http_authority')) {
            // Keep configured public port only when Host has no port — remotes need :9080.
            // On LAN, Host is usually 192.168.x.x without port → stay on :80.
        }
    }
    $script = $_SERVER['SCRIPT_NAME'] ?? '/admin/modules/quickprovisioner/bootstrap.php';
    $dir = rtrim(str_replace('\\', '/', dirname($script)), '/');
    return $scheme . '://' . $host . $dir;
}

function qp_normalize_mac_bootstrap($mac) {
    $mac = strtoupper(preg_replace('/[^0-9A-Fa-f]/', '', (string)$mac));
    return strlen($mac) === 12 ? $mac : '';
}

function qp_guess_vendor_bootstrap($model) {
    $m = strtoupper((string)$model);
    if (strpos($m, 'CISCO') !== false || preg_match('/\b[78]8\d{2}\b/', $m)) {
        return 'cisco';
    }
    if (strpos($m, 'POLY') !== false || strpos($m, 'VVX') !== false || strpos($m, 'EDGE') !== false) {
        return 'polycom';
    }
    return 'yealink';
}

$mac = qp_normalize_mac_bootstrap($_REQUEST['mac'] ?? ($_REQUEST['MAC'] ?? ''));
$vendor = strtolower((string)($_REQUEST['vendor'] ?? ''));

if ($vendor === '' && $mac !== '') {
    try {
        $stmt = \FreePBX::Database()->prepare(
            "SELECT model FROM quickprovisioner_devices WHERE UPPER(REPLACE(REPLACE(mac,':',''),'-','')) = ?"
        );
        $stmt->execute([$mac]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $vendor = qp_guess_vendor_bootstrap($row['model'] ?? '');
        }
    } catch (Exception $e) {
        // ignore — default polycom for hotel fleet
    }
}
if ($vendor === '') {
    $vendor = 'polycom';
}

$base = qp_bootstrap_base_url();
$provisionBase = $base . '/provision.php/';

// lan_open + LAN: skip QSetup (MAC-only pull). WAN / qsetup mode: enable QSetup softkey.
$lanOpenLocal = function_exists('qp_lan_open_auth') && qp_lan_open_auth();
$qsetupOn = $lanOpenLocal ? '0' : '1';
$modeLabel = $lanOpenLocal ? 'lan_open' : 'qsetup';

header('Cache-Control: no-store');
header('X-QP-Bootstrap: ' . $modeLabel);

if ($vendor === 'polycom') {
    header('Content-Type: application/xml; charset=utf-8');
    echo '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
    echo "<PHONE_CONFIG>\n";
    if ($lanOpenLocal) {
        echo "  <!-- Quick-Provisioner LAN bring-up: no QSetup; full cfg by MAC. Flip Admin → auth_mode=qsetup to lock. -->\n";
    } else {
        echo "  <!-- Quick-Provisioner QSetup bootstrap: enter Prov user + Prov password on the phone. -->\n";
    }
    // Absolute CONFIG_FILES so Poly does not append -prov.cfg under bootstrap.php.
    // APP_FILE_PATH left as sip.ld (no forced firmware from bootstrap — full cfg freezes updater).
    $macProvUrl = $provisionBase . '[PHONE_MAC_ADDRESS]-prov.cfg';
    echo "  <APPLICATION APP_FILE_PATH=\"sip.ld\" CONFIG_FILES=\"{$macProvUrl}\" />\n";
    echo "  <prov\n";
    echo "    prov.quickSetup.enabled=\"{$qsetupOn}\"\n";
    echo "    prov.quickSetup.limitServerDetails=\"1\"\n";
    echo "  />\n";
    echo "  <DEVICE\n";
    echo "    device.set=\"1\"\n";
    echo "    device.prov.serverName=\"{$provisionBase}\"\n";
    echo "    device.prov.serverType=\"HTTP\"\n";
    echo "    device.dhcp.bootSrvUseOpt.set=\"1\"\n";
    echo "    device.dhcp.bootSrvUseOpt=\"1\"\n";
    echo "    device.prov.ztpEnabled.set=\"1\"\n";
    echo "    device.prov.ztpEnabled=\"0\"\n";
    echo "  />\n";
    echo "</PHONE_CONFIG>\n";
    exit;
}

if ($vendor === 'cisco') {
    header('Content-Type: application/xml; charset=utf-8');
    echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    echo "<flat-profile>\n";
    echo "  <Profile_Rule>{$provisionBase}</Profile_Rule>\n";
    echo "  <Resync_On_Reset>Yes</Resync_On_Reset>\n";
    echo "</flat-profile>\n";
    exit;
}

// Default: Yealink CFG — phone prompts for user/password when server requires auth
header('Content-Type: text/plain; charset=utf-8');
echo "#!version:1.0.0.1\n";
if ($lanOpenLocal) {
    echo "## Quick-Provisioner LAN bring-up → full config by MAC (no Prov prompt)\n";
} else {
    echo "## Quick-Provisioner QSetup bootstrap → full config after Prov auth\n";
}
echo "static.auto_provision.server.url = {$provisionBase}\n";
echo "static.auto_provision.power_on = 1\n";
echo "static.auto_provision.repeat.enable = 1\n";
exit;
