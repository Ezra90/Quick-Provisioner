<?php
/**
 * Quick Provisioner - Installation Script
 *
 * This file contains FreePBX module lifecycle hooks for installation.
 * Refactored for procedural execution as required by FreePBX.
 */

if (!defined('FREEPBX_IS_AUTH')) { die('No direct script access allowed'); }

global $db;
global $amp_conf;

// --- 1. Database Installation ---
try {
    // Create the main devices table if it doesn't exist
    $sql = "CREATE TABLE IF NOT EXISTS `quickprovisioner_devices` (
        `id` INT(11) NOT NULL AUTO_INCREMENT,
        `mac` VARCHAR(17) NOT NULL COMMENT 'Device MAC address',
        `model` VARCHAR(50) NOT NULL COMMENT 'Device model',
        `extension` VARCHAR(20) NOT NULL COMMENT 'Associated extension',
        `wallpaper` VARCHAR(255) DEFAULT NULL COMMENT 'Wallpaper filename',
        `wallpaper_mode` VARCHAR(20) DEFAULT 'crop' COMMENT 'Wallpaper display mode',
        `security_pin` VARCHAR(20) DEFAULT NULL COMMENT 'Device security PIN',
        `keys_json` TEXT DEFAULT NULL COMMENT 'JSON data for programmable keys',
        `contacts_json` TEXT DEFAULT NULL COMMENT 'JSON data for contacts',
        `custom_options_json` TEXT DEFAULT NULL COMMENT 'JSON data for custom options',
        `custom_template_override` TEXT DEFAULT NULL COMMENT 'Custom template override',
        `prov_username` VARCHAR(50) DEFAULT NULL COMMENT 'Provisioning auth username',
        `prov_password` VARCHAR(100) DEFAULT NULL COMMENT 'Provisioning auth password',
        `custom_sip_secret` VARCHAR(100) DEFAULT NULL COMMENT 'Custom SIP secret (overrides FreePBX)',
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `mac` (`mac`),
        KEY `extension` (`extension`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Quick Provisioner device configurations'";

    $db->query($sql);

    // Add columns if missing (upgrade compatibility)
    try {
        $db->query("ALTER TABLE quickprovisioner_devices ADD COLUMN IF NOT EXISTS prov_username VARCHAR(50) DEFAULT NULL");
        $db->query("ALTER TABLE quickprovisioner_devices ADD COLUMN IF NOT EXISTS prov_password VARCHAR(100) DEFAULT NULL");
        $db->query("ALTER TABLE quickprovisioner_devices ADD COLUMN IF NOT EXISTS custom_sip_secret VARCHAR(100) DEFAULT NULL COMMENT 'Custom SIP secret (overrides FreePBX)'");
    } catch (Exception $e) {
        // Columns may already exist
    }

    if (class_exists('FreePBX')) {
        FreePBX::create()->Logger->log(FPBX_LOG_INFO, "Quick Provisioner: Database table checked/created successfully");
    }

} catch (Exception $e) {
    if (class_exists('FreePBX')) {
        FreePBX::create()->Logger->log(FPBX_LOG_ERROR, "Quick Provisioner Install Error: " . $e->getMessage());
    }
    error_log("Quick Provisioner Install Error: " . $e->getMessage());
}

// --- 2. Directory & Permission Setup ---
$module_path = __DIR__;
$directories = [
    $module_path . '/assets',
    $module_path . '/assets/uploads',
    $module_path . '/assets/ringtones',
    $module_path . '/assets/firmware',
    $module_path . '/assets/phonebook',
    $module_path . '/templates',
];

// Temporarily set umask to 0 to ensure proper permissions
$old_umask = umask(0);

foreach ($directories as $dir) {
    if (!is_dir($dir)) {
        if (!mkdir($dir, 0775, true)) {
            error_log("Quick Provisioner: Failed to create directory: $dir");
            if (class_exists('FreePBX')) {
                FreePBX::create()->Logger->log(FPBX_LOG_ERROR, "Quick Provisioner: Failed to create directory: $dir");
            }
            continue;
        }
        if (class_exists('FreePBX')) {
            FreePBX::create()->Logger->log(FPBX_LOG_INFO, "Quick Provisioner: Created directory $dir");
        }
    } else {
        // Set permissions on existing directories to ensure they're correct
        if (!chmod($dir, 0775)) {
            error_log("Quick Provisioner: Failed to set permissions on $dir");
            if (class_exists('FreePBX')) {
                FreePBX::create()->Logger->log(FPBX_LOG_WARNING, "Quick Provisioner: Failed to set permissions on $dir");
            }
        }
    }
}

umask($old_umask);

// --- 3. Create .htaccess files to prevent direct execution ---
$protected_dirs = [
    $module_path . '/assets/uploads'   => "# Quick Provisioner - Protect uploads directory\nphp_flag engine off\nOptions -ExecCGI -Indexes\nAddType text/plain .php .php3 .phtml .pht\n",
    $module_path . '/assets/ringtones' => "# Quick Provisioner - Protect ringtones directory\nphp_flag engine off\nOptions -ExecCGI -Indexes\n",
    $module_path . '/assets/firmware'  => "# Quick Provisioner - Protect firmware directory\nphp_flag engine off\nOptions -ExecCGI -Indexes\n",
    $module_path . '/assets/phonebook' => "# Quick Provisioner - Protect phonebook directory\nphp_flag engine off\nOptions -ExecCGI -Indexes\n",
    $module_path . '/templates'        => "# Quick Provisioner - Protect templates directory\nphp_flag engine off\nOptions -ExecCGI -Indexes\nAddType text/plain .php .php3 .phtml .pht .mustache\n",
];

foreach ($protected_dirs as $dir => $htaccess_content) {
    if (is_dir($dir)) {
        $htaccess_path = $dir . '/.htaccess';
        if (!file_exists($htaccess_path)) {
            if (file_put_contents($htaccess_path, $htaccess_content) === false) {
                error_log("Quick Provisioner: Failed to create .htaccess in $dir");
            } else {
                chmod($htaccess_path, 0664);
            }
        }
    }
}

if (class_exists('FreePBX')) {
    FreePBX::create()->Logger->log(FPBX_LOG_INFO, "Quick Provisioner: Module installation/check completed");
}
?>