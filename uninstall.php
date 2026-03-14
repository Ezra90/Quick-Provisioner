<?php
if (!defined('FREEPBX_IS_AUTH')) { die('No direct script access allowed'); }

global $db;
global $amp_conf;

$logger = FreePBX::create()->Logger;
$logger->log('Starting Quick Provisioner uninstall', 'INFO');

// --- 1. Drop Database Table ---
try {
    $db->query("DROP TABLE IF EXISTS `quickprovisioner_devices`");
    $logger->log('Dropped quickprovisioner_devices table', 'INFO');
} catch(Exception $e) {
    $logger->log('Error dropping quickprovisioner_devices table: ' . $e->getMessage(), 'ERROR');
}

// --- 2. Remove asset directories and contents ---
$asset_dirs = [
    __DIR__ . '/assets/uploads',
    __DIR__ . '/assets/ringtones',
    __DIR__ . '/assets/firmware',
    __DIR__ . '/assets/phonebook',
];

foreach ($asset_dirs as $dir) {
    if (is_dir($dir)) {
        try {
            $files = glob($dir . '/*');
            if ($files) {
                foreach ($files as $file) {
                    if (is_file($file)) {
                        unlink($file);
                    }
                }
            }
            $htaccess = $dir . '/.htaccess';
            if (file_exists($htaccess)) {
                unlink($htaccess);
            }
            rmdir($dir);
            $logger->log('Removed directory: ' . basename($dir), 'INFO');
        } catch(Exception $e) {
            $logger->log('Error removing ' . basename($dir) . ': ' . $e->getMessage(), 'ERROR');
        }
    }
}

// --- 3. Remove template files (.mustache and .json) ---
$templates_dir = __DIR__ . '/templates';
if (is_dir($templates_dir)) {
    try {
        foreach (['*.mustache', '*.json'] as $pattern) {
            $templates = glob($templates_dir . '/' . $pattern);
            if ($templates) {
                foreach ($templates as $template) {
                    if (is_file($template)) {
                        unlink($template);
                        $logger->log('Removed template: ' . basename($template), 'INFO');
                    }
                }
            }
        }
        $htaccess = $templates_dir . '/.htaccess';
        if (file_exists($htaccess)) {
            unlink($htaccess);
        }
        rmdir($templates_dir);
        $logger->log('Removed templates directory', 'INFO');
    } catch(Exception $e) {
        $logger->log('Error removing templates directory: ' . $e->getMessage(), 'ERROR');
    }
}

// --- 4. Remove assets directory if empty ---
$assets_dir = __DIR__ . '/assets';
if (is_dir($assets_dir)) {
    try {
        $files = glob($assets_dir . '/*');
        if ($files) {
            foreach ($files as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
        }
        $remaining = glob($assets_dir . '/*');
        if (empty($remaining)) {
            rmdir($assets_dir);
            $logger->log('Removed assets directory', 'INFO');
        }
    } catch(Exception $e) {
        $logger->log('Error removing assets directory: ' . $e->getMessage(), 'ERROR');
    }
}

$logger->log('Quick Provisioner uninstall completed', 'INFO');
?>
