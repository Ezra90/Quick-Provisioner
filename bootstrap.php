<?php
/**
 * bootstrap.php — TIPT / BroadWorks-style bootstrap redirect for handsets.
 *
 * Phones (or DHCP option 66/160) can point here first. We return a tiny
 * vendor config that sends them to the real Quick-Provisioner provision URL
 * with their MAC (or authenticated username).
 *
 * Examples:
 *   GET bootstrap.php?mac=AABBCCDDEEFF
 *   GET bootstrap.php?mac=aa:bb:cc:dd:ee:ff&vendor=yealink
 *   Basic Auth with prov_username / prov_password (looks up device)
 */
include '/etc/freepbx.conf';
require_once __DIR__ . '/MustacheEngine.php';

function qp_bootstrap_base_url() {
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    $scheme = $https ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_ADDR'] ?? '127.0.0.1');
    $script = $_SERVER['SCRIPT_NAME'] ?? '/admin/modules/quickprovisioner/bootstrap.php';
    $dir = rtrim(str_replace('\\', '/', dirname($script)), '/');
    return $scheme . '://' . $host . $dir;
}

function qp_normalize_mac($mac) {
    $mac = strtoupper(preg_replace('/[^0-9A-Fa-f]/', '', (string)$mac));
    return strlen($mac) === 12 ? $mac : '';
}

function qp_find_device_by_mac($mac) {
    try {
        $stmt = \FreePBX::Database()->prepare(
            "SELECT * FROM quickprovisioner_devices WHERE UPPER(REPLACE(REPLACE(mac,':',''),'-','')) = ?"
        );
        $stmt->execute([$mac]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Exception $e) {
        return null;
    }
}

function qp_find_device_by_prov_auth($user, $pass) {
    if ($user === '' || $pass === '') {
        return null;
    }
    try {
        $stmt = \FreePBX::Database()->prepare(
            "SELECT * FROM quickprovisioner_devices WHERE prov_username = ? AND prov_password = ? LIMIT 1"
        );
        $stmt->execute([$user, $pass]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Exception $e) {
        return null;
    }
}

function qp_guess_vendor($model) {
    $m = strtoupper((string)$model);
    if (strpos($m, 'CISCO') !== false || preg_match('/\b[78]8\d{2}\b/', $m)) {
        return 'cisco';
    }
    if (strpos($m, 'POLY') !== false || strpos($m, 'VVX') !== false || strpos($m, 'EDGE') !== false) {
        return 'polycom';
    }
    return 'yealink';
}

$mac = qp_normalize_mac($_REQUEST['mac'] ?? ($_REQUEST['MAC'] ?? ''));
$vendor = strtolower((string)($_REQUEST['vendor'] ?? ''));
$device = null;

if ($mac !== '') {
    $device = qp_find_device_by_mac($mac);
}

if (!$device) {
    $user = $_SERVER['PHP_AUTH_USER'] ?? '';
    $pass = $_SERVER['PHP_AUTH_PW'] ?? '';
    $device = qp_find_device_by_prov_auth($user, $pass);
    if ($device) {
        $mac = qp_normalize_mac($device['mac'] ?? '');
    }
}

if (!$device || $mac === '') {
    header('HTTP/1.0 404 Not Found');
    header('Content-Type: text/plain; charset=utf-8');
    echo "Unknown device. Register MAC in Quick-Provisioner or authenticate with provisioning credentials.\n";
    exit;
}

if ($vendor === '') {
    $vendor = qp_guess_vendor($device['model'] ?? '');
}

$base = qp_bootstrap_base_url();
$provisionUrl = $base . '/provision.php?mac=' . rawurlencode($mac);
$provUser = $device['prov_username'] ?? '';
$provPass = $device['prov_password'] ?? '';

header('Cache-Control: no-store');

if ($vendor === 'polycom') {
    header('Content-Type: application/xml; charset=utf-8');
    echo '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
    echo "<PHONE_CONFIG>\n";
    echo "  <device device.prov.serverName=\"{$provisionUrl}\" ";
    if ($provUser !== '') {
        echo "device.prov.user=\"{$provUser}\" device.prov.password=\"{$provPass}\" ";
    }
    echo "/>\n</PHONE_CONFIG>\n";
    exit;
}

if ($vendor === 'cisco') {
    header('Content-Type: application/xml; charset=utf-8');
    echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    echo "<flat-profile>\n";
    echo "  <Profile_Rule>{$provisionUrl}</Profile_Rule>\n";
    echo "  <Resync_On_Reset>Yes</Resync_On_Reset>\n";
    if ($provUser !== '') {
        echo "  <Profile_Rule_B>[{$provUser}:{$provPass}]{$provisionUrl}</Profile_Rule_B>\n";
    }
    echo "</flat-profile>\n";
    exit;
}

// Default: Yealink CFG
header('Content-Type: text/plain; charset=utf-8');
echo "#!version:1.0.0.1\n";
echo "## Quick-Provisioner bootstrap → full config\n";
echo "static.auto_provision.server.url = {$provisionUrl}\n";
if ($provUser !== '') {
    echo "static.auto_provision.server.username = {$provUser}\n";
    echo "static.auto_provision.server.password = {$provPass}\n";
}
echo "static.auto_provision.power_on = 1\n";
echo "static.auto_provision.repeat.enable = 1\n";
exit;
