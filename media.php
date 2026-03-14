<?php
// media.php - Quick Provisioner v3.0.0 - Secure Resizer
include '/etc/freepbx.conf';

function qp_is_local_network() {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    if ($ip === '::1') return true;
    if (preg_match('/^(127\.|10\.|192\.168\.|172\.(1[6-9]|2[0-9]|3[01])\.)/', $ip)) {
        return true;
    }
    return false;
}

$authorized = false;
$mac = isset($_GET['mac']) ? strtoupper(preg_replace('/[^A-F0-9]/', '', $_GET['mac'])) : null;

// Local network always authorized
if (qp_is_local_network()) {
    $authorized = true;
}

// Check session auth (FreePBX admin logged in)
if (!$authorized && isset($_SESSION['AMP_user']) && is_object($_SESSION['AMP_user'])) {
    $authorized = true;
}

// Check per-device provisioning auth for remote requests
if (!$authorized && $mac && isset($_SERVER['PHP_AUTH_USER'])) {
    global $db;
    $device = $db->getRow("SELECT prov_username, prov_password FROM quickprovisioner_devices WHERE mac=?", [$mac]);
    if ($device && !empty($device['prov_username']) && !empty($device['prov_password'])) {
        if ($_SERVER['PHP_AUTH_USER'] === $device['prov_username'] && ($_SERVER['PHP_AUTH_PW'] ?? '') === $device['prov_password']) {
            $authorized = true;
        }
    }
}

if (!$authorized) {
    header('WWW-Authenticate: Basic realm="Phone Provisioning"');
    header('HTTP/1.0 401 Unauthorized');
    die('Access Denied');
}

$file = $_GET['file'] ?? '';
$req_w = (int)($_GET['w'] ?? 0);
$req_h = (int)($_GET['h'] ?? 0);
$mode = $_GET['mode'] ?? 'crop';

// Validate mode parameter
if (!in_array($mode, ['crop', 'fit'], true)) {
    $mode = 'crop';
}

$path = __DIR__ . '/assets/uploads/' . basename($file);
if (!file_exists($path) || empty($file)) {
    header('Content-Type: image/png');
    echo base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=');
    exit;
}

if ($mac && ($req_w == 0 || $req_h == 0)) {
    global $db;
    require_once __DIR__ . '/MustacheEngine.php';
    $device = $db->getRow("SELECT model FROM quickprovisioner_devices WHERE mac=?", [$mac]);
    if ($device) {
        $model = basename($device['model']);
        $template_file = qp_resolve_template_file($model, __DIR__ . '/templates');
        if ($template_file) {
            $source = file_get_contents($template_file);
            $meta = qp_parse_template_meta($source);
            if ($meta && !empty($meta['wallpaper_specs'])) {
                // Try exact model match first, then first available spec
                $spec = $meta['wallpaper_specs'][$model] ?? null;
                if (!$spec) {
                    $spec = reset($meta['wallpaper_specs']);
                }
                if ($spec) {
                    if ($req_w == 0) $req_w = $spec['width'] ?? 800;
                    if ($req_h == 0) $req_h = $spec['height'] ?? 480;
                }
            }
        }
    }
}

if ($req_w == 0) $req_w = 800;
if ($req_h == 0) $req_h = 480;

$info = getimagesize($path);
if (!$info) die("Invalid image");
list($orig_w, $orig_h, $type) = $info;

switch ($type) {
    case IMAGETYPE_JPEG: $src = imagecreatefromjpeg($path); break;
    case IMAGETYPE_PNG: $src = imagecreatefrompng($path); break;
    case IMAGETYPE_GIF: $src = imagecreatefromgif($path); break;
    default: die("Unsupported format");
}

$dst = imagecreatetruecolor($req_w, $req_h);

if ($type == IMAGETYPE_PNG || $type == IMAGETYPE_GIF) {
    imagealphablending($dst, false);
    imagesavealpha($dst, true);
    $trans = imagecolorallocatealpha($dst, 0, 0, 0, 127);
    imagefill($dst, 0, 0, $trans);
} else {
    $black = imagecolorallocate($dst, 0, 0, 0);
    imagefill($dst, 0, 0, $black);
}

$src_ratio = $orig_w / $orig_h;
$dst_ratio = $req_w / $req_h;

if ($mode === 'crop') {
    if ($src_ratio > $dst_ratio) {
        $nh = $req_h;
        $nw = $req_h * $src_ratio;
    } else {
        $nw = $req_w;
        $nh = $req_w / $src_ratio;
    }
} else {
    if ($src_ratio > $dst_ratio) {
        $nw = $req_w;
        $nh = $req_w / $src_ratio;
    } else {
        $nh = $req_h;
        $nw = $req_h * $src_ratio;
    }
}

$x = ($req_w - $nw) / 2;
$y = ($req_h - $nh) / 2;

imagecopyresampled($dst, $src, $x, $y, 0, 0, $nw, $nh, $orig_w, $orig_h);

if ($type == IMAGETYPE_PNG) {
    header('Content-Type: image/png');
    imagepng($dst);
} elseif ($type == IMAGETYPE_GIF) {
    header('Content-Type: image/gif');
    imagegif($dst);
} else {
    header('Content-Type: image/jpeg');
    imagejpeg($dst, null, 90);
}

imagedestroy($src);
imagedestroy($dst);
?>
