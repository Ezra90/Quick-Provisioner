<?php
// ajax.quickprovisioner.php - Quick Provisioner v3.0.0 - Backend API

// Configuration constants (only define if not already defined by Quickprovisioner.class.php)
if (!defined('QP_FREEPBX_BASE_PATH')) {
    define('QP_FREEPBX_BASE_PATH', '/var/www/html');
}
if (!defined('QP_GIT_COMMAND')) {
    define('QP_GIT_COMMAND', '/usr/bin/git');
}
if (!defined('QP_FWCONSOLE_RELOAD')) {
    define('QP_FWCONSOLE_RELOAD', '/usr/sbin/fwconsole reload');
}
if (!defined('QP_FWCONSOLE_RESTART')) {
    define('QP_FWCONSOLE_RESTART', '/usr/sbin/fwconsole restart');
}

require_once __DIR__ . '/MustacheEngine.php';

function qp_is_local_network() {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    if ($ip === '::1') return true;
    if (preg_match('/^(127\.|10\.|192\.168\.|172\.(1[6-9]|2[0-9]|3[01])\.)/', $ip)) {
        return true;
    }
    return false;
}

if (!qp_is_local_network()) {
    die(json_encode(['status' => false, 'message' => 'Remote access denied. Admin UI is local network only.']));
}

if (!defined('FREEPBX_IS_AUTH') || !FREEPBX_IS_AUTH) {
    die(json_encode(['status' => false, 'message' => 'Unauthorized']));
}

global $db;
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
// Ensure CSRF token exists in session
if (!isset($_SESSION['qp_csrf'])) {
    $_SESSION['qp_csrf'] = bin2hex(random_bytes(32));
}
// Support both 'command' (FreePBX routing via ajax.php) and 'action' (backward compatibility for direct calls)
$action = $_REQUEST['command'] ?? $_REQUEST['action'] ?? '';
$response = ['status' => false, 'message' => 'Invalid action'];

// Helper functions for safe file operations
function qp_safe_write($filepath, $content) {
    $dir = dirname($filepath);
    if (!is_dir($dir)) {
        $old_umask = umask(0);
        if (!mkdir($dir, 0775, true)) {
            umask($old_umask);
            return ['status' => false, 'message' => 'Failed to create directory: ' . $dir];
        }
        umask($old_umask);
    }
    
    if (!is_writable($dir)) {
        return ['status' => false, 'message' => 'Directory not writable: ' . $dir];
    }
    
    if (file_put_contents($filepath, $content) === false) {
        return ['status' => false, 'message' => 'Failed to write file: ' . basename($filepath)];
    }
    if (!chmod($filepath, 0664)) {
        error_log("Quick Provisioner: Failed to set permissions on $filepath");
    }
    return ['status' => true];
}

function qp_safe_delete($filepath) {
    if (!file_exists($filepath)) {
        return ['status' => false, 'message' => 'File not found: ' . basename($filepath)];
    }
    if (!unlink($filepath)) {
        return ['status' => false, 'message' => 'Failed to delete file: ' . basename($filepath)];
    }
    return ['status' => true];
}

function qp_safe_move_upload($tmp_file, $target) {
    $dir = dirname($target);
    if (!is_dir($dir)) {
        $old_umask = umask(0);
        if (!mkdir($dir, 0775, true)) {
            umask($old_umask);
            return ['status' => false, 'message' => 'Failed to create directory: ' . $dir];
        }
        umask($old_umask);
    }
    
    if (!is_writable($dir)) {
        return ['status' => false, 'message' => 'Directory not writable: ' . $dir];
    }
    
    if (!move_uploaded_file($tmp_file, $target)) {
        return ['status' => false, 'message' => 'Failed to move uploaded file'];
    }
    if (!chmod($target, 0664)) {
        error_log("Quick Provisioner: Failed to set permissions on $target");
    }
    return ['status' => true];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (!isset($_REQUEST['csrf_token']) || $_REQUEST['csrf_token'] !== $_SESSION['qp_csrf'])) {
    $response['message'] = 'CSRF validation failed';
    echo json_encode($response);
    exit;
}

$templates_dir = __DIR__ . '/templates';  // New: Folder for model templates

switch ($action) {
    // === DEVICE ACTIONS ===
    case 'save_device':
        parse_str($_POST['data'] ?? '', $form);
        if (empty($form['mac']) || strlen($form['mac']) < 12) {
            $response['message'] = 'Invalid MAC';
            break;
        }
        // Sanitize and validate MAC address format
        $mac_clean = strtoupper(preg_replace('/[^A-F0-9]/', '', $form['mac']));
        if (strlen($mac_clean) !== 12 || !ctype_xdigit($mac_clean)) {
            $response['message'] = 'Invalid MAC address format';
            break;
        }
        $form['mac'] = $mac_clean;
        
        $keys_json = $_POST['keys_json'] ?? '[]';
        $contacts_json = $_POST['contacts_json'] ?? '[]';
        $custom_options_json = json_encode($form['custom_options'] ?? []);
        $custom_template_override = $form['custom_template_override'] ?? '';
        $wallpaper = $form['wallpaper'] ?? '';
        $wallpaper_mode = $form['wallpaper_mode'] ?? 'crop';
        $security_pin = $form['security_pin'] ?? '';
        $prov_username = $form['prov_username'] ?? '';
        $prov_password = $form['prov_password'] ?? '';
        $custom_sip_secret = $form['custom_sip_secret'] ?? null;
        // Allow empty string to clear the custom secret
        if ($custom_sip_secret === '') {
            $custom_sip_secret = null;
        }

        $id = $form['deviceId'] ?? null;
        try {
            if ($id) {
                $sql = "UPDATE quickprovisioner_devices SET mac=?, model=?, extension=?, wallpaper=?, wallpaper_mode=?, security_pin=?, keys_json=?, contacts_json=?, custom_options_json=?, custom_template_override=?, prov_username=?, prov_password=?, custom_sip_secret=? WHERE id=?";
                $params = [$form['mac'], $form['model'], $form['extension'], $wallpaper, $wallpaper_mode, $security_pin, $keys_json, $contacts_json, $custom_options_json, $custom_template_override, $prov_username, $prov_password, $custom_sip_secret, $id];
            } else {
                $sql = "INSERT INTO quickprovisioner_devices (mac, model, extension, wallpaper, wallpaper_mode, security_pin, keys_json, contacts_json, custom_options_json, custom_template_override, prov_username, prov_password, custom_sip_secret) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                $params = [$form['mac'], $form['model'], $form['extension'], $wallpaper, $wallpaper_mode, $security_pin, $keys_json, $contacts_json, $custom_options_json, $custom_template_override, $prov_username, $prov_password, $custom_sip_secret];
            }
            $db->query($sql, $params);
            \FreePBX::create()->Logger->log(FPBX_LOG_INFO, "Device saved: MAC=" . $form['mac']);
            $response = ['status' => true];
        } catch (Exception $e) {
            error_log("Quick Provisioner: Error saving device - " . $e->getMessage());
            $response['message'] = 'Database error: Failed to save device';
        }
        break;

    case 'get_device':
        $id = $_REQUEST['id'] ?? null;
        if (!$id || !is_numeric($id)) { $response['message'] = 'Invalid ID'; break; }
        $row = $db->getRow("SELECT * FROM quickprovisioner_devices WHERE id=?", [(int)$id]);
        $response = ['status' => true, 'data' => $row ?: null];
        break;

    case 'list_devices':
        $rows = $db->query("SELECT * FROM quickprovisioner_devices ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
        $response = ['status' => true, 'devices' => $rows];
        break;

    case 'list_devices_with_secrets':
        $rows = $db->query("SELECT * FROM quickprovisioner_devices ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
        $devices = [];
        foreach ($rows as $row) {
            $ext = $row['extension'];
            $secret = '';
            $secretSource = '';

            // Check if custom secret is set
            if (!empty($row['custom_sip_secret'])) {
                $secret = $row['custom_sip_secret'];
                $secretSource = 'Custom';
            } else {
                // Try to fetch secret from FreePBX
                try {
                    $device = \FreePBX::Core()->getDevice($ext);
                    if ($device && is_array($device) && isset($device['secret'])) {
                        $secret = $device['secret'];
                        $secretSource = 'FreePBX';
                    } else {
                        error_log("Quick Provisioner: Secret not found for extension $ext");
                    }
                } catch (Exception $e) {
                    error_log("Quick Provisioner: Error fetching secret for extension $ext - " . $e->getMessage());
                }
            }

            $devices[] = [
                'id' => $row['id'],
                'mac' => $row['mac'],
                'extension' => $row['extension'],
                'model' => $row['model'],
                'secret' => $secret,
                'secret_source' => $secretSource
            ];
        }
        $response = ['status' => true, 'devices' => $devices];
        break;

    case 'delete_device':
        $id = $_REQUEST['id'] ?? null;
        if (!$id || !is_numeric($id)) { $response['message'] = 'Invalid ID'; break; }
        $stmt = $db->query("DELETE FROM quickprovisioner_devices WHERE id=?", [(int)$id]);
        if ($stmt->rowCount() > 0) {
            \FreePBX::create()->Logger->log(FPBX_LOG_INFO, "Device deleted: ID=$id");
            $response = ['status' => true];
        } else {
            $response['message'] = 'Device not found';
        }
        break;

    case 'preview_config':
        $id = $_REQUEST['id'] ?? null;
        if (!$id || !is_numeric($id)) { $response['message'] = 'Invalid ID'; break; }
        $device = $db->getRow("SELECT * FROM quickprovisioner_devices WHERE id=?", [(int)$id]);
        if (!$device) { $response['message'] = 'Device not found'; break; }
        $model = basename($device['model']); // Sanitize to prevent path traversal

        // Resolve Mustache template file
        $template_file = qp_resolve_template_file($model, $templates_dir);
        if (!$template_file) { $response['message'] = 'Template not found for model: ' . $model; break; }
        $template_source = file_get_contents($template_file);
        if ($template_source === false) { $response['message'] = 'Failed to read template'; break; }

        $meta = qp_parse_template_meta($template_source);
        if ($meta === null) { $response['message'] = 'Template has no valid META block'; break; }

        $ext = $device['extension'];

        // Fetch user info and secret with error handling
        $display_name = $ext;
        $secret = '';

        // Use custom secret if available
        if (!empty($device['custom_sip_secret'])) {
            $secret = $device['custom_sip_secret'];
        } else {
            try {
                $deviceInfo = \FreePBX::Core()->getDevice($ext);
                if ($deviceInfo && is_array($deviceInfo) && isset($deviceInfo['secret'])) {
                    $secret = $deviceInfo['secret'];
                } else {
                    error_log("Quick Provisioner: Secret not found for extension $ext during config preview");
                }
            } catch (Exception $e) {
                error_log("Quick Provisioner: Error fetching FreePBX data for extension $ext - " . $e->getMessage());
            }
        }

        // Fetch display name
        try {
            $userInfo = \FreePBX::Core()->getUser($ext);
            if ($userInfo && is_array($userInfo) && isset($userInfo['name'])) {
                $display_name = $userInfo['name'];
            }
        } catch (Exception $e) {
            error_log("Quick Provisioner: Error fetching user info for extension $ext - " . $e->getMessage());
        }

        $server_ip = $_SERVER['SERVER_ADDR'];
        $server_port = \FreePBX::Sipsettings()->get('bindport') ?? '5060';

        // Build wallpaper URL using META wallpaper_specs for dimensions
        $wpUrl = "";
        if (!empty($device['wallpaper'])) {
            $protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? "https" : "http";
            $host = $_SERVER['HTTP_HOST'];
            $mac_clean = strtoupper(preg_replace('/[^A-F0-9]/', '', $device['mac']));
            $wpUrl = "$protocol://$host/admin/modules/quickprovisioner/media.php?mac=" . $mac_clean;
            // Append screen dimensions from Mustache META wallpaper_specs
            $wallpaper_specs = $meta['wallpaper_specs'] ?? [];
            $modelUpper = strtoupper($model);
            if (isset($wallpaper_specs[$model])) {
                $wpUrl .= "&w=" . (int)$wallpaper_specs[$model]['width'] . "&h=" . (int)$wallpaper_specs[$model]['height'];
            } elseif (isset($wallpaper_specs[$modelUpper])) {
                $wpUrl .= "&w=" . (int)$wallpaper_specs[$modelUpper]['width'] . "&h=" . (int)$wallpaper_specs[$modelUpper]['height'];
            }
        }

        // Build provisioning context using Mustache engine
        $server_info = [
            'server_ip'        => $server_ip,
            'server_port'      => $server_port,
            'sip_port'         => $server_port,
            'display_name'     => $display_name,
            'secret'           => $secret,
            'wallpaper_url'    => $wpUrl,
            'provisioning_url' => '',
        ];
        $context = qp_build_provisioning_context($device, $meta, $server_info);

        // If there is a custom template override, use that as raw Mustache source
        if (!empty($device['custom_template_override'])) {
            $render_source = $device['custom_template_override'];
        } else {
            // Strip META block from template before rendering
            $render_source = preg_replace('/\{\{!\s*META:\s*\{[\s\S]*?\}\s*\}\}\s*/', '', $template_source);
        }

        $config = qp_render_mustache($render_source, $context);
        $response = ['status' => true, 'config' => $config];
        break;

    case 'upload_file':
        if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            $response['message'] = 'Upload error: ' . $_FILES['file']['error'];
            break;
        }
        if ($_FILES['file']['size'] > 5 * 1024 * 1024) {
            $response['message'] = 'File too large (max 5MB)';
            break;
        }
        $allowed_mimes = ['image/jpeg', 'image/png', 'image/gif'];
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($_FILES['file']['tmp_name']);
        if (!in_array($mime, $allowed_mimes)) {
            $response['message'] = 'Invalid file type';
            break;
        }
        $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif'];
        $ext = strtolower(pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, $allowed_extensions)) {
            $response['message'] = 'Invalid file extension';
            break;
        }
        $filename = uniqid('asset_') . '.' . $ext;
        $target = __DIR__ . '/assets/uploads/' . $filename;
        
        // Auto-resize image if dimensions are provided
        $resize_width = isset($_POST['resize_width']) ? intval($_POST['resize_width']) : 0;
        $resize_height = isset($_POST['resize_height']) ? intval($_POST['resize_height']) : 0;
        
        // Auto-detect wallpaper dimensions from Mustache template META if model is specified
        if ($resize_width === 0 && $resize_height === 0 && !empty($_POST['device_model'])) {
            $wp_model = basename($_POST['device_model']);
            $wp_template_file = qp_resolve_template_file($wp_model, $templates_dir);
            if ($wp_template_file) {
                $wp_source = file_get_contents($wp_template_file);
                if ($wp_source !== false) {
                    $wp_meta = qp_parse_template_meta($wp_source);
                    if ($wp_meta !== null && !empty($wp_meta['wallpaper_specs'])) {
                        $wp_specs = $wp_meta['wallpaper_specs'];
                        if (isset($wp_specs[$wp_model])) {
                            $resize_width = (int)$wp_specs[$wp_model]['width'];
                            $resize_height = (int)$wp_specs[$wp_model]['height'];
                        } elseif (isset($wp_specs[strtoupper($wp_model)])) {
                            $resize_width = (int)$wp_specs[strtoupper($wp_model)]['width'];
                            $resize_height = (int)$wp_specs[strtoupper($wp_model)]['height'];
                        }
                    }
                }
            }
        }
        
        if ($resize_width > 0 && $resize_height > 0 && function_exists('imagecreatefromjpeg')) {
            // Load source image
            $source = null;
            switch ($mime) {
                case 'image/jpeg':
                    $source = @imagecreatefromjpeg($_FILES['file']['tmp_name']);
                    break;
                case 'image/png':
                    $source = @imagecreatefrompng($_FILES['file']['tmp_name']);
                    break;
                case 'image/gif':
                    $source = @imagecreatefromgif($_FILES['file']['tmp_name']);
                    break;
            }
            
            if ($source) {
                $src_width = imagesx($source);
                $src_height = imagesy($source);
                
                // Create destination image
                $dest = imagecreatetruecolor($resize_width, $resize_height);
                
                // Preserve transparency for PNG/GIF
                if ($mime === 'image/png' || $mime === 'image/gif') {
                    imagealphablending($dest, false);
                    imagesavealpha($dest, true);
                    $transparent = imagecolorallocatealpha($dest, 0, 0, 0, 127);
                    imagefilledrectangle($dest, 0, 0, $resize_width, $resize_height, $transparent);
                }
                
                // Resize with resampling for better quality
                imagecopyresampled($dest, $source, 0, 0, 0, 0, $resize_width, $resize_height, $src_width, $src_height);
                
                // Save resized image
                $save_result = false;
                switch ($ext) {
                    case 'jpg':
                    case 'jpeg':
                        $save_result = imagejpeg($dest, $target, 90);
                        break;
                    case 'png':
                        $save_result = imagepng($dest, $target, 9);
                        break;
                    case 'gif':
                        $save_result = imagegif($dest, $target);
                        break;
                }
                
                imagedestroy($source);
                imagedestroy($dest);
                
                if ($save_result) {
                    if (!chmod($target, 0664)) {
                        error_log("Quick Provisioner: Failed to set permissions on $target");
                    }
                    \FreePBX::create()->Logger->log(FPBX_LOG_INFO, "Asset uploaded and resized: $filename");
                    $response = ['status' => true, 'url' => $filename];
                } else {
                    $response['message'] = 'Failed to save resized image';
                }
            } else {
                // Fallback to regular upload if image processing fails
                $result = qp_safe_move_upload($_FILES['file']['tmp_name'], $target);
                if ($result['status']) {
                    \FreePBX::create()->Logger->log(FPBX_LOG_INFO, "Asset uploaded (resize failed, using original): $filename");
                    $response = ['status' => true, 'url' => $filename];
                } else {
                    $response['message'] = $result['message'];
                }
            }
        } else {
            // No resize requested or GD not available
            $result = qp_safe_move_upload($_FILES['file']['tmp_name'], $target);
            if ($result['status']) {
                \FreePBX::create()->Logger->log(FPBX_LOG_INFO, "Asset uploaded: $filename");
                $response = ['status' => true, 'url' => $filename];
            } else {
                $response['message'] = $result['message'];
            }
        }
        break;

    case 'list_assets':
        $uploads_dir = __DIR__ . '/assets/uploads';
        $files = [];
        if (is_dir($uploads_dir)) {
            foreach (scandir($uploads_dir) as $item) {
                if ($item === '.' || $item === '..' || $item === '.htaccess') continue;
                $filepath = $uploads_dir . '/' . $item;
                if (is_file($filepath)) {
                    $files[] = ['filename' => $item, 'size' => filesize($filepath)];
                }
            }
        }
        $response = ['status' => true, 'files' => $files];
        break;

    case 'delete_asset':
        $filename = basename($_POST['filename'] ?? '');
        $path = __DIR__ . '/assets/uploads/' . $filename;
        $result = qp_safe_delete($path);
        if ($result['status']) {
            \FreePBX::create()->Logger->log(FPBX_LOG_INFO, "Asset deleted: $filename");
            $response = ['status' => true];
        } else {
            $response['message'] = $result['message'];
        }
        break;

    // === RINGTONE & FIRMWARE ASSET MANAGEMENT ===
    case 'upload_ringtone':
        if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            $response['message'] = 'Upload error';
            break;
        }
        if ($_FILES['file']['size'] > 1 * 1024 * 1024) {
            $response['message'] = 'File too large (max 1MB)';
            break;
        }
        $finfo_rt = new finfo(FILEINFO_MIME_TYPE);
        $mime_rt = $finfo_rt->file($_FILES['file']['tmp_name']);
        if ($mime_rt !== 'audio/wav' && $mime_rt !== 'audio/x-wav' && $mime_rt !== 'audio/wave') {
            $response['message'] = 'Invalid file type. Only WAV audio files are allowed.';
            break;
        }
        $rt_name = basename($_FILES['file']['name']);
        $rt_name = preg_replace('/[^a-zA-Z0-9_.\-]/', '_', $rt_name);
        $rt_target = __DIR__ . '/assets/ringtones/' . $rt_name;
        $result = qp_safe_move_upload($_FILES['file']['tmp_name'], $rt_target);
        if ($result['status']) {
            \FreePBX::create()->Logger->log(FPBX_LOG_INFO, "Ringtone uploaded: $rt_name");
            $response = ['status' => true, 'filename' => $rt_name];
        } else {
            $response['message'] = $result['message'];
        }
        break;

    case 'upload_firmware':
        if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            $response['message'] = 'Upload error';
            break;
        }
        if ($_FILES['file']['size'] > 100 * 1024 * 1024) {
            $response['message'] = 'File too large (max 100MB)';
            break;
        }
        $fw_ext = strtolower(pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION));
        $allowed_fw_ext = ['rom', 'ld', 'loads', 'bin', 'img', 'fw', 'sbn', 'cop', 'pkg'];
        if (!in_array($fw_ext, $allowed_fw_ext)) {
            $response['message'] = 'Invalid firmware extension. Allowed: ' . implode(', ', $allowed_fw_ext);
            break;
        }
        $fw_name = basename($_FILES['file']['name']);
        $fw_name = preg_replace('/[^a-zA-Z0-9_.\-]/', '_', $fw_name);
        $fw_target = __DIR__ . '/assets/firmware/' . $fw_name;
        $result = qp_safe_move_upload($_FILES['file']['tmp_name'], $fw_target);
        if ($result['status']) {
            \FreePBX::create()->Logger->log(FPBX_LOG_INFO, "Firmware uploaded: $fw_name");
            $response = ['status' => true, 'filename' => $fw_name];
        } else {
            $response['message'] = $result['message'];
        }
        break;

    case 'list_ringtones':
        $ringtones_dir = __DIR__ . '/assets/ringtones';
        $files = [];
        if (is_dir($ringtones_dir)) {
            foreach (scandir($ringtones_dir) as $item) {
                if ($item === '.' || $item === '..' || $item === '.htaccess') continue;
                $filepath = $ringtones_dir . '/' . $item;
                if (is_file($filepath)) {
                    $files[] = ['filename' => $item, 'size' => filesize($filepath)];
                }
            }
        }
        $response = ['status' => true, 'files' => $files];
        break;

    case 'list_firmware':
        $firmware_dir = __DIR__ . '/assets/firmware';
        $files = [];
        if (is_dir($firmware_dir)) {
            foreach (scandir($firmware_dir) as $item) {
                if ($item === '.' || $item === '..' || $item === '.htaccess') continue;
                $filepath = $firmware_dir . '/' . $item;
                if (is_file($filepath)) {
                    $files[] = ['filename' => $item, 'size' => filesize($filepath)];
                }
            }
        }
        $response = ['status' => true, 'files' => $files];
        break;

    case 'delete_ringtone':
        $filename = basename($_POST['filename'] ?? '');
        if (!$filename) { $response['message'] = 'No filename'; break; }
        $path = __DIR__ . '/assets/ringtones/' . $filename;
        $result = qp_safe_delete($path);
        if ($result['status']) {
            \FreePBX::create()->Logger->log(FPBX_LOG_INFO, "Ringtone deleted: $filename");
            $response = ['status' => true];
        } else {
            $response['message'] = $result['message'];
        }
        break;

    case 'delete_firmware':
        $filename = basename($_POST['filename'] ?? '');
        if (!$filename) { $response['message'] = 'No filename'; break; }
        $path = __DIR__ . '/assets/firmware/' . $filename;
        $result = qp_safe_delete($path);
        if ($result['status']) {
            \FreePBX::create()->Logger->log(FPBX_LOG_INFO, "Firmware deleted: $filename");
            $response = ['status' => true];
        } else {
            $response['message'] = $result['message'];
        }
        break;

    case 'get_driver':
        $model = basename($_REQUEST['model'] ?? ''); // Sanitize to prevent path traversal
        if (!$model) { $response['message'] = 'No model'; break; }
        $template_file = qp_resolve_template_file($model, $templates_dir);
        if (!$template_file) { $response['message'] = 'Template not found'; break; }
        $source = file_get_contents($template_file);
        if ($source === false) { $response['message'] = 'Failed to read template'; break; }
        $meta = qp_parse_template_meta($source);
        $response = ['status' => true, 'source' => $source, 'meta' => $meta];
        break;

    case 'import_driver':
        // Support file upload
        if (isset($_FILES['template_file']) && $_FILES['template_file']['error'] === UPLOAD_ERR_OK) {
            $template_content = file_get_contents($_FILES['template_file']['tmp_name']);
            if ($template_content === false) {
                $response['message'] = 'Failed to read uploaded file';
                break;
            }
        } else {
            $template_content = $_POST['template'] ?? '';
        }
        if (empty($template_content)) {
            $response['message'] = 'No template content provided';
            break;
        }
        $meta = qp_parse_template_meta($template_content);
        if ($meta === null) {
            $response['message'] = 'Invalid template: missing or malformed META block';
            break;
        }
        // Determine filename from POST field or META display_name
        $filename = $_POST['filename'] ?? '';
        if (empty($filename)) {
            $display = $meta['display_name'] ?? '';
            if (empty($display)) {
                $response['message'] = 'No filename provided and META display_name is empty';
                break;
            }
            // Sanitize display_name into a safe filename
            $filename = preg_replace('/[^a-zA-Z0-9_\-.]/', '_', $display);
            $filename = strtolower($filename);
        }
        $filename = basename($filename); // Sanitize to prevent path traversal
        // Ensure .mustache extension (case-insensitive check)
        if (strcasecmp(substr($filename, -9), '.mustache') !== 0) {
            $filename .= '.mustache';
        }
        $path = $templates_dir . '/' . $filename;
        $result = qp_safe_write($path, $template_content);
        if ($result['status']) {
            $response = ['status' => true, 'filename' => $filename];
        } else {
            $response['message'] = $result['message'];
        }
        break;

    case 'delete_driver':
        $model = basename($_POST['model'] ?? ''); // Sanitize to prevent path traversal
        if (!$model) { $response['message'] = 'No model'; break; }
        // Try resolving via qp_resolve_template_file first
        $template_file = qp_resolve_template_file($model, $templates_dir);
        if ($template_file) {
            $result = qp_safe_delete($template_file);
        } else {
            // Fallback: try direct .mustache filename
            $path = $templates_dir . '/' . $model . '.mustache';
            $result = qp_safe_delete($path);
        }
        if ($result['status']) {
            $response = ['status' => true];
        } else {
            $response['message'] = $result['message'];
        }
        break;

    case 'list_drivers':
        $files = glob($templates_dir . '/*.mustache');
        if ($files === false) { $files = []; }
        $list = [];
        foreach ($files as $file) {
            $model = basename($file, '.mustache');
            $source = file_get_contents($file);
            $meta = ($source !== false) ? qp_parse_template_meta($source) : null;
            $list[] = [
                'model'            => $model,
                'display_name'     => ($meta['display_name'] ?? '') ?: $model,
                'manufacturer'     => $meta['manufacturer'] ?? '',
                'supported_models' => $meta['supported_models'] ?? [],
            ];
        }
        $response = ['status' => true, 'list' => $list];
        break;

    case 'get_sip_secret':
        $ext = $_REQUEST['extension'] ?? null;
        if (!$ext) {
            $response['message'] = 'No extension provided';
            break;
        }
        try {
            $device = \FreePBX::Core()->getDevice($ext);
            $secret = '';
            if ($device && is_array($device) && isset($device['secret'])) {
                $secret = $device['secret'];
            }
            if ($secret) {
                $response = ['status' => true, 'secret' => $secret];
            } else {
                $response['message'] = "Secret not found for extension $ext. Extension may not exist in FreePBX.";
                error_log("Quick Provisioner: Secret not found for extension $ext");
            }
        } catch (Exception $e) {
            $response['message'] = "Error fetching secret: " . $e->getMessage();
            error_log("Quick Provisioner: Error fetching secret for extension $ext - " . $e->getMessage());
        }
        break;

    // === UPDATE MANAGEMENT ACTIONS ===
    case 'check_updates':
        $module_dir = __DIR__;
        
        // Validate module_dir is within expected path for security
        $real_module_dir = realpath($module_dir);
        if ($real_module_dir === false || strpos($real_module_dir, QP_FREEPBX_BASE_PATH) !== 0) {
            $response['message'] = 'Invalid module directory';
            break;
        }

        // Use explicit git commands with -C flag to avoid cd command injection
        $git_cmd = QP_GIT_COMMAND . ' -C ' . escapeshellarg($real_module_dir);
        
        // Get current commit hash
        $current_commit = trim(shell_exec($git_cmd . ' rev-parse HEAD 2>&1'));
        if (empty($current_commit) || strlen($current_commit) !== 40) {
            $response['message'] = 'Failed to get current commit: ' . $current_commit;
            break;
        }

        // Get current version from module.xml
        $module_xml_path = $real_module_dir . '/module.xml';
        $current_version = '3.0.0'; // Default
        if (file_exists($module_xml_path)) {
            $xml_content = @file_get_contents($module_xml_path);
            if ($xml_content && preg_match('/<version>(.*?)<\/version>/', $xml_content, $matches)) {
                $current_version = htmlspecialchars($matches[1], ENT_QUOTES, 'UTF-8');
            }
        }

        // Fetch from origin
        $fetch_output = shell_exec($git_cmd . ' fetch origin main 2>&1');

        // Get remote commit hash
        $remote_commit = trim(shell_exec($git_cmd . ' rev-parse origin/main 2>&1'));
        if (empty($remote_commit) || strlen($remote_commit) !== 40) {
            $response['message'] = 'Failed to get remote commit. Fetch output: ' . htmlspecialchars($fetch_output, ENT_QUOTES, 'UTF-8');
            break;
        }

        // Check if updates are available
        $has_updates = ($current_commit !== $remote_commit);

        $response = [
            'status' => true,
            'current_commit' => $current_commit,
            'current_version' => $current_version,
            'remote_commit' => $remote_commit,
            'has_updates' => $has_updates
        ];
        break;

    case 'get_changelog':
        $module_dir = __DIR__;
        $current_commit = $_POST['current_commit'] ?? '';
        $remote_commit = $_POST['remote_commit'] ?? '';

        if (empty($current_commit) || empty($remote_commit)) {
            $response['message'] = 'Missing commit parameters';
            break;
        }
        
        // Validate commit hashes are valid SHA-1 (40 hex chars)
        if (!preg_match('/^[a-f0-9]{40}$/i', $current_commit) || !preg_match('/^[a-f0-9]{40}$/i', $remote_commit)) {
            $response['message'] = 'Invalid commit hash format';
            break;
        }
        
        // Validate module_dir is within expected path for security
        $real_module_dir = realpath($module_dir);
        if ($real_module_dir === false || strpos($real_module_dir, QP_FREEPBX_BASE_PATH) !== 0) {
            $response['message'] = 'Invalid module directory';
            break;
        }

        // Use git -C instead of cd for security
        $git_cmd = '/usr/bin/git -C ' . escapeshellarg($real_module_dir);
        $log_cmd = sprintf(
            "%s log %s..%s --pretty=format:'%%H||%%s||%%an||%%ai' 2>&1",
            $git_cmd,
            escapeshellarg($current_commit),
            escapeshellarg($remote_commit)
        );
        $log_output = shell_exec($log_cmd);

        $commits = [];
        if (!empty($log_output)) {
            $lines = explode("\n", trim($log_output));
            foreach ($lines as $line) {
                if (empty($line)) continue;
                $parts = explode('||', $line);
                if (count($parts) >= 4) {
                    $commits[] = [
                        'hash' => htmlspecialchars($parts[0], ENT_QUOTES, 'UTF-8'),
                        'message' => htmlspecialchars($parts[1], ENT_QUOTES, 'UTF-8'),
                        'author' => htmlspecialchars($parts[2], ENT_QUOTES, 'UTF-8'),
                        'date' => $parts[3]
                    ];
                }
            }
        }

        $response = [
            'status' => true,
            'commits' => $commits
        ];
        break;

    case 'perform_update':
        $module_dir = __DIR__;
        
        // Validate module_dir is within expected path for security
        $real_module_dir = realpath($module_dir);
        if ($real_module_dir === false || strpos($real_module_dir, QP_FREEPBX_BASE_PATH) !== 0) {
            $response['message'] = 'Invalid module directory';
            break;
        }
        
        // Use git -C instead of cd for security
        $git_cmd = QP_GIT_COMMAND . ' -C ' . escapeshellarg($real_module_dir);

        // Get current commit before update
        $old_commit = trim(shell_exec($git_cmd . ' rev-parse HEAD 2>&1'));

        // Perform git pull
        $pull_output = shell_exec($git_cmd . ' pull origin main 2>&1');

        // Check if pull was successful
        if (strpos($pull_output, 'Already up to date') !== false || strpos($pull_output, 'Fast-forward') !== false || strpos($pull_output, 'Updating') !== false) {
            // Get new commit hash
            $new_commit = trim(shell_exec($git_cmd . ' rev-parse HEAD 2>&1'));

            // Get new version from module.xml
            $module_xml_path = $real_module_dir . '/module.xml';
            $new_version = null;
            if (file_exists($module_xml_path)) {
                $xml_content = @file_get_contents($module_xml_path);
                if ($xml_content && preg_match('/<version>(.*?)<\/version>/', $xml_content, $matches)) {
                    $new_version = htmlspecialchars($matches[1], ENT_QUOTES, 'UTF-8');
                }
            }

            // Note: Permission/ownership changes require elevated privileges.
            // If needed, run 'fwconsole chown' manually or use the scripts/qp-update script with sudo.

            \FreePBX::create()->Logger->log(FPBX_LOG_INFO, "Module updated: $old_commit -> $new_commit");

            $response = [
                'status' => true,
                'old_commit' => $old_commit,
                'new_commit' => $new_commit,
                'new_version' => $new_version,
                'message' => 'Update completed successfully. Please refresh the page to see changes.'
            ];
        } else {
            $response['message'] = 'Git pull failed: ' . $pull_output;
        }
        break;

    case 'restart_pbx':
        $restart_type = isset($_POST['type']) ? $_POST['type'] : 'reload';

        if (!in_array($restart_type, ['reload', 'restart'], true)) {
            $response = ['status' => false, 'message' => 'Invalid restart type'];
            break;
        }

        // Use explicit command mapping with constants for security
        $allowed_commands = [
            'reload' => QP_FWCONSOLE_RELOAD,
            'restart' => QP_FWCONSOLE_RESTART
        ];
        $command = $allowed_commands[$restart_type];

        $output = [];
        $return_var = 0;
        exec($command . ' 2>&1', $output, $return_var);

        if ($return_var === 0) {
            $response = [
                'status' => true,
                'message' => 'PBX ' . $restart_type . ' completed successfully',
                'output' => implode("\n", $output)
            ];
        } else {
            $response = [
                'status' => false,
                'message' => 'PBX ' . $restart_type . ' failed',
                'output' => implode("\n", $output)
            ];
        }
        break;
}

echo json_encode($response);
?>
