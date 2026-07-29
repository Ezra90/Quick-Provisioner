<?php
// media.php - Quick-Provisioner - Secure Resizer
include '/etc/freepbx.conf';

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
if (!function_exists('qp_media_log_access')) {
    function qp_media_log_access($status_code, $path, $mac, $resource_type) {
        try {
            $stmt = \FreePBX::Database()->prepare(
                "INSERT INTO quickprovisioner_access_log (status_code, method, path, client_ip, mac, extension, resource_type, user_agent) VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
            );
            $stmt->execute([
                $status_code,
                $_SERVER['REQUEST_METHOD'] ?? 'GET',
                substr((string)$path, 0, 255),
                $_SERVER['REMOTE_ADDR'] ?? '',
                $mac,
                null,
                $resource_type,
                substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
            ]);
        } catch (Exception $e) {
            // Never break media serving on log errors.
        }
    }
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$authorized = false;
$mac = isset($_GET['mac']) ? strtoupper(preg_replace('/[^A-Fa-f0-9]/', '', $_GET['mac'])) : null;

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
    $stmt = \FreePBX::Database()->prepare("SELECT prov_username, prov_password FROM quickprovisioner_devices WHERE mac=?");
    $stmt->execute([$mac]);
    $device = $stmt->fetch(PDO::FETCH_ASSOC);
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
$pathInfo = $_SERVER['PATH_INFO'] ?? '';
if ($file === '' && $pathInfo !== '') {
    $file = basename((string)$pathInfo);
}
$req_w = (int)($_GET['w'] ?? 0);
$req_h = (int)($_GET['h'] ?? 0);
$mode = $_GET['mode'] ?? 'crop';

if (!function_exists('qp_media_placeholder')) {
    function qp_media_placeholder() {
        header('Content-Type: image/png');
        echo base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=');
        exit;
    }
}

// Validate mode parameter
if (!in_array($mode, ['crop', 'fit'], true)) {
    $mode = 'crop';
}

if (empty($file) && !empty($mac)) {
    $stmt = \FreePBX::Database()->prepare("SELECT wallpaper FROM quickprovisioner_devices WHERE mac=?");
    $stmt->execute([$mac]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!empty($row['wallpaper'])) {
        $file = basename((string)$row['wallpaper']);
    }
}

// If MAC omitted, infer device from a handset that uses this wallpaper file
$inferred_custom_options = null;
if (empty($mac) && !empty($file)) {
    try {
        $stmt = \FreePBX::Database()->prepare(
            "SELECT mac, model, custom_options_json FROM quickprovisioner_devices WHERE wallpaper = ? OR wallpaper LIKE ? ORDER BY id DESC LIMIT 1"
        );
        $base = basename((string)$file);
        $stmt->execute([$base, '%/' . $base]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            if (empty($mac) && !empty($row['mac'])) {
                $mac = strtoupper(preg_replace('/[^A-Fa-f0-9]/', '', (string)$row['mac']));
            }
            if (!empty($row['custom_options_json'])) {
                $inferred_custom_options = $row['custom_options_json'];
            }
        }
    } catch (Exception $e) {
        // ignore
    }
}

$path = __DIR__ . '/assets/uploads/' . basename($file);
if (!file_exists($path) || empty($file)) {
    qp_media_placeholder();
}

$device_custom_options = [];
if ($mac && ($req_w == 0 || $req_h == 0 || !isset($_GET['inset_right']))) {
    require_once __DIR__ . '/MustacheEngine.php';
    $stmt = \FreePBX::Database()->prepare("SELECT model, custom_options_json FROM quickprovisioner_devices WHERE mac=?");
    $stmt->execute([$mac]);
    $device = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($device) {
        $model = basename($device['model']);
        if (!empty($device['custom_options_json'])) {
            $decoded = json_decode($device['custom_options_json'], true);
            if (is_array($decoded)) {
                $device_custom_options = $decoded;
            }
        } elseif (!empty($inferred_custom_options)) {
            $decoded = json_decode($inferred_custom_options, true);
            if (is_array($decoded)) {
                $device_custom_options = $decoded;
            }
        }
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
                    $inset_left = (int)($spec['inset_left'] ?? 0);
                    $inset_top = (int)($spec['inset_top'] ?? 0);
                    $inset_right = (int)($spec['inset_right'] ?? 0);
                    $inset_bottom = (int)($spec['inset_bottom'] ?? 0);
                }
            }
        }

        // Device Wallpaper tab: around_keys (META defaults) | full | custom margins
        $layout = $device_custom_options['wallpaper_layout'] ?? 'around_keys';
        if ($layout === 'full') {
            $inset_left = 0;
            $inset_top = 0;
            $inset_right = 0;
            $inset_bottom = 0;
        } elseif ($layout === 'custom') {
            $inset_left = max(0, (int)($device_custom_options['wallpaper_inset_left'] ?? $inset_left ?? 0));
            $inset_top = max(0, (int)($device_custom_options['wallpaper_inset_top'] ?? $inset_top ?? 0));
            $inset_right = max(0, (int)($device_custom_options['wallpaper_inset_right'] ?? $inset_right ?? 0));
            $inset_bottom = max(0, (int)($device_custom_options['wallpaper_inset_bottom'] ?? $inset_bottom ?? 0));
        }
        // around_keys: keep META insets (or zeros if model has none)
    }
}

// Optional explicit insets (preview / UI override META + device layout)
if (isset($_GET['inset_left'])) $inset_left = max(0, (int)$_GET['inset_left']);
if (isset($_GET['inset_top'])) $inset_top = max(0, (int)$_GET['inset_top']);
if (isset($_GET['inset_right'])) $inset_right = max(0, (int)$_GET['inset_right']);
if (isset($_GET['inset_bottom'])) $inset_bottom = max(0, (int)$_GET['inset_bottom']);

if ($req_w == 0) $req_w = 800;
if ($req_h == 0) $req_h = 480;
$req_w = max(1, min(4096, $req_w));
$req_h = max(1, min(4096, $req_h));
$inset_left = $inset_left ?? 0;
$inset_top = $inset_top ?? 0;
$inset_right = $inset_right ?? 0;
$inset_bottom = $inset_bottom ?? 0;

try {
    $info = @getimagesize($path);
    if (!$info) {
        throw new RuntimeException('Invalid image metadata');
    }
    list($orig_w, $orig_h, $type) = $info;
    if ($orig_w < 1 || $orig_h < 1) {
        throw new RuntimeException('Invalid image dimensions');
    }

    switch ($type) {
        case IMAGETYPE_JPEG:
            $src = @imagecreatefromjpeg($path);
            break;
        case IMAGETYPE_PNG:
            $src = @imagecreatefrompng($path);
            break;
        case IMAGETYPE_GIF:
            $src = @imagecreatefromgif($path);
            break;
        default:
            throw new RuntimeException('Unsupported image type');
    }
    if (!$src) {
        throw new RuntimeException('Unable to decode source image');
    }

    $dst = imagecreatetruecolor($req_w, $req_h);

    // Dark fill under insets (matches VVX chrome around hotkeys)
    $hasInsets = ($inset_left + $inset_top + $inset_right + $inset_bottom) > 0;
    if ($type == IMAGETYPE_PNG || $type == IMAGETYPE_GIF) {
        imagealphablending($dst, true);
        imagesavealpha($dst, true);
        if ($hasInsets) {
            $fill = imagecolorallocate($dst, 16, 16, 24);
            imagefill($dst, 0, 0, $fill);
        } else {
            imagealphablending($dst, false);
            $trans = imagecolorallocatealpha($dst, 0, 0, 0, 127);
            imagefill($dst, 0, 0, $trans);
        }
    } else {
        $black = imagecolorallocate($dst, 16, 16, 24);
        imagefill($dst, 0, 0, $black);
    }

    $src_ratio = $orig_w / $orig_h;

    // Content box: keep logos clear of VVX1500 right hotkeys / softkeys / status bar.
    $content_w = max(1, $req_w - $inset_left - $inset_right);
    $content_h = max(1, $req_h - $inset_top - $inset_bottom);
    $content_ratio = $content_w / $content_h;

    // Prefer fit-into-content when insets are set (hotel logos); else legacy full-bleed crop/fit.
    $compose_mode = ($inset_left + $inset_top + $inset_right + $inset_bottom) > 0 ? 'fit' : $mode;

    if ($compose_mode === 'crop') {
        $dst_ratio = $req_w / $req_h;
        if ($src_ratio > $dst_ratio) {
            $nh = $req_h;
            $nw = $req_h * $src_ratio;
        } else {
            $nw = $req_w;
            $nh = $req_w / $src_ratio;
        }
        $x = (int)round(($req_w - $nw) / 2);
        $y = (int)round(($req_h - $nh) / 2);
    } else {
        // Fit entire image inside the content rectangle (letterbox within safe area).
        if ($src_ratio > $content_ratio) {
            $nw = $content_w;
            $nh = $content_w / $src_ratio;
        } else {
            $nh = $content_h;
            $nw = $content_h * $src_ratio;
        }
        $x = (int)round($inset_left + ($content_w - $nw) / 2);
        $y = (int)round($inset_top + ($content_h - $nh) / 2);
    }

    $nw = (int)round($nw);
    $nh = (int)round($nh);

    imagecopyresampled($dst, $src, $x, $y, 0, 0, $nw, $nh, (int)$orig_w, (int)$orig_h);

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
    qp_media_log_access(200, $_SERVER['REQUEST_URI'] ?? '', $mac, 'wallpaper');

    imagedestroy($src);
    imagedestroy($dst);
} catch (Throwable $e) {
    error_log('Quick-Provisioner media.php failed for "' . basename($file) . '": ' . $e->getMessage());
    if (is_readable($path)) {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($path) ?: 'application/octet-stream';
        header('Content-Type: ' . $mime);
        readfile($path);
        exit;
    }
    qp_media_placeholder();
}
?>
