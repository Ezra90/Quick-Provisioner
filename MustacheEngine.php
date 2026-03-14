<?php
// MustacheEngine.php - Lightweight Mustache renderer & META parser for Quick Provisioner
// Compatible with Pocket-Provisioner Android app template format (.mustache files with META blocks)

/**
 * Extract the META JSON block from a Mustache template source string.
 *
 * Looks for {{! META: { ... } }} comment blocks and parses the embedded JSON.
 *
 * @param string $source Raw template source
 * @return array|null Parsed META associative array, or null if not found / invalid
 */
function qp_parse_template_meta($source) {
    if (!is_string($source) || $source === '') {
        return null;
    }

    if (!preg_match('/\{\{!\s*META:\s*(\{[\s\S]*\})\s*\}\}/', $source, $matches)) {
        return null;
    }

    $json = $matches[1];
    $meta = json_decode($json, true);
    if (!is_array($meta)) {
        return null;
    }

    // Normalise expected keys with sensible defaults
    return [
        'manufacturer'     => $meta['manufacturer'] ?? '',
        'model_family'     => $meta['model_family'] ?? '',
        'display_name'     => $meta['display_name'] ?? '',
        'config_format'    => $meta['config_format'] ?? 'cfg',
        'content_type'     => $meta['content_type'] ?? 'text/plain',
        'filename_pattern' => $meta['filename_pattern'] ?? '{mac}.cfg',
        'supported_models' => $meta['supported_models'] ?? [],
        'max_line_keys'    => (int)($meta['max_line_keys'] ?? 0),
        'wallpaper_specs'  => $meta['wallpaper_specs'] ?? [],
        'type_mapping'     => $meta['type_mapping'] ?? [],
        'categories'       => $meta['categories'] ?? [],
        'variables'        => $meta['variables'] ?? [],
    ];
}

/**
 * Render a Mustache template string with a variable context.
 *
 * Supported tags:
 *   {{variable}}              - variable substitution (no HTML escaping)
 *   {{#section}}...{{/section}} - truthy / iterable section
 *   {{^section}}...{{/section}} - inverted (falsy) section
 *   {{! comment }}             - stripped from output
 *
 * Nested sections and iteration with inner-scope variable precedence are supported.
 *
 * @param string $template Mustache template string
 * @param array  $context  Associative array of variables
 * @return string Rendered output
 */
function qp_render_mustache($template, $context) {
    if (!is_string($template)) {
        return '';
    }
    return _qp_mustache_render_section($template, $context);
}

/**
 * Internal recursive renderer for a template fragment within a given context.
 *
 * @param string $template
 * @param array  $context
 * @return string
 */
function _qp_mustache_render_section($template, $context) {
    // 1. Strip comments: {{! ... }}
    $template = preg_replace('/\{\{![\s\S]*?\}\}/', '', $template);

    // 2. Process sections (truthy and inverted) from outermost inward.
    //    We loop until no more section tags remain so that nested sections
    //    produced by earlier passes are also resolved.
    // Cap recursive section passes to prevent runaway loops on malformed templates
    $maxPasses = 64;
    for ($pass = 0; $pass < $maxPasses; $pass++) {
        $changed = false;

        // Truthy sections: {{#name}}...{{/name}}
        // Match innermost sections first (no nested same-name tags inside)
        $template = preg_replace_callback(
            '/\{\{#([a-zA-Z0-9_.\-]+)\}\}((?:(?!\{\{#\1\}\})(?!\{\{\/\1\}\})[\s\S])*?)\{\{\/\1\}\}/',
            function ($m) use ($context) {
                return _qp_mustache_eval_section($m[1], $m[2], $context, false);
            },
            $template,
            -1,
            $count
        );
        if ($count > 0) {
            $changed = true;
        }

        // Inverted sections: {{^name}}...{{/name}}
        $template = preg_replace_callback(
            '/\{\{\^([a-zA-Z0-9_.\-]+)\}\}((?:(?!\{\{\^?\1\}\})(?!\{\{\/\1\}\})[\s\S])*?)\{\{\/\1\}\}/',
            function ($m) use ($context) {
                return _qp_mustache_eval_section($m[1], $m[2], $context, true);
            },
            $template,
            -1,
            $count
        );
        if ($count > 0) {
            $changed = true;
        }

        if (!$changed) {
            break;
        }
    }

    // 3. Variable interpolation: {{name}} (no HTML escaping for config output)
    $template = preg_replace_callback(
        '/\{\{([a-zA-Z0-9_.\-]+)\}\}/',
        function ($m) use ($context) {
            $key = $m[1];
            if (array_key_exists($key, $context)) {
                $val = $context[$key];
                if (is_bool($val)) {
                    return $val ? '1' : '0';
                }
                if (is_scalar($val)) {
                    return (string)$val;
                }
            }
            return '';
        },
        $template
    );

    return $template;
}

/**
 * Evaluate a single section block.
 *
 * @param string $name     Section variable name
 * @param string $inner    Content between opening and closing tags
 * @param array  $context  Current variable context
 * @param bool   $inverted True for {{^name}} sections
 * @return string
 */
function _qp_mustache_eval_section($name, $inner, $context, $inverted) {
    $value = array_key_exists($name, $context) ? $context[$name] : null;

    $isFalsy = ($value === null || $value === false || $value === ''
                || $value === 0 || $value === '0'
                || (is_array($value) && count($value) === 0));

    if ($inverted) {
        return $isFalsy ? _qp_mustache_render_section($inner, $context) : '';
    }

    // Falsy → hide
    if ($isFalsy) {
        return '';
    }

    // Non-empty array → iterate
    if (is_array($value) && !_qp_is_assoc($value)) {
        $out = '';
        foreach ($value as $item) {
            if (is_array($item)) {
                // Merge item into context; item keys take precedence
                $merged = array_merge($context, $item);
                $out .= _qp_mustache_render_section($inner, $merged);
            } else {
                // Scalar item: expose as {{.}}
                $merged = $context;
                $merged['.'] = $item;
                $out .= _qp_mustache_render_section($inner, $merged);
            }
        }
        return $out;
    }

    // Truthy scalar / assoc array → render once
    if (is_array($value) && _qp_is_assoc($value)) {
        $merged = array_merge($context, $value);
        return _qp_mustache_render_section($inner, $merged);
    }

    return _qp_mustache_render_section($inner, $context);
}

/**
 * Check whether an array is associative (has string keys).
 */
function _qp_is_assoc($arr) {
    if (!is_array($arr) || $arr === []) {
        return false;
    }
    return array_keys($arr) !== range(0, count($arr) - 1);
}

/**
 * Resolve which .mustache template file to use for a given phone model.
 *
 * Resolution order:
 *   1. Exact match: {model}.mustache (dots replaced with underscores)
 *   2. Any .mustache file whose META supported_models contains the model
 *   3. Brand-based fallback (Cisco/Polycom/Yealink)
 *   4. null if nothing found
 *
 * @param string $model         Phone model name (e.g. "T48G", "Cisco 8851")
 * @param string $templates_dir Absolute path to the templates directory
 * @return string|null Full path to the template file, or null
 */
function qp_resolve_template_file($model, $templates_dir) {
    $templates_dir = rtrim($templates_dir, '/');
    if (!is_dir($templates_dir)) {
        return null;
    }

    // 1. Exact match (dots → underscores)
    $safe = str_replace('.', '_', $model);
    $safe = str_replace(' ', '_', $safe);
    $candidate = $templates_dir . '/' . $safe . '.mustache';
    if (file_exists($candidate)) {
        return $candidate;
    }

    // Also try with mixed extensions (e.g. model.cfg.mustache patterns from directory)
    $glob = glob($templates_dir . '/*.mustache');
    if ($glob === false) {
        error_log("Quick Provisioner: Failed to scan templates directory: $templates_dir");
        $glob = [];
    }

    // 2. Scan META supported_models in every .mustache file
    $modelUpper = strtoupper($model);
    foreach ($glob as $file) {
        $source = file_get_contents($file);
        if ($source === false) {
            continue;
        }
        $meta = qp_parse_template_meta($source);
        if ($meta === null) {
            continue;
        }
        foreach ($meta['supported_models'] as $supported) {
            if (strtoupper($supported) === $modelUpper) {
                return $file;
            }
        }
    }

    // 3. Brand-based fallback
    $fallback = _qp_brand_fallback_template($model);
    if ($fallback !== null) {
        $path = $templates_dir . '/' . $fallback;
        if (file_exists($path)) {
            return $path;
        }
    }

    return null;
}

/**
 * Determine the fallback template filename based on brand heuristics.
 *
 * @param string $model
 * @return string|null
 */
function _qp_brand_fallback_template($model) {
    $upper = strtoupper($model);

    // Cisco: brand name or 78xx/88xx pattern
    if (strpos($upper, 'CISCO') !== false || preg_match('/\b[78]8\d{2}\b/', $upper)) {
        return 'cisco_88xx.xml.mustache';
    }

    // Polycom / Poly
    if (strpos($upper, 'POLY') !== false
        || strpos($upper, 'VVX') !== false
        || strpos($upper, 'EDGE') !== false) {
        return 'polycom_vvx.xml.mustache';
    }

    // Default to Yealink
    return 'yealink_t4x.cfg.mustache';
}

/**
 * Build the Mustache variable context for provisioning a device.
 *
 * Mirrors the Pocket-Provisioner MustacheRenderer.buildVariables() format so
 * that the same .mustache templates produce identical output.
 *
 * @param array $device      Device row from the database
 * @param array $meta        Parsed META array (from qp_parse_template_meta)
 * @param array $server_info Associative array with keys:
 *                           server_ip, server_port, sip_port, display_name,
 *                           secret, wallpaper_url, provisioning_url
 * @return array Context suitable for qp_render_mustache()
 */
function qp_build_provisioning_context($device, $meta, $server_info) {
    $custom_options = [];
    if (!empty($device['custom_options_json'])) {
        $custom_options = json_decode($device['custom_options_json'], true) ?? [];
    }

    $keys = [];
    if (!empty($device['keys_json'])) {
        $keys = json_decode($device['keys_json'], true) ?? [];
    }

    $contacts = [];
    if (!empty($device['contacts_json'])) {
        $contacts = json_decode($device['contacts_json'], true) ?? [];
    }

    $mac        = $device['mac'] ?? '';
    $model      = $device['model'] ?? '';
    $extension  = $device['extension'] ?? '';
    $sipServer  = $server_info['server_ip'] ?? '';
    $sipPort    = $custom_options['sip_port']  ?? $server_info['sip_port'] ?? '5060';
    $transport  = $custom_options['transport'] ?? 'UDP';
    $displayName = $server_info['display_name'] ?? $extension;
    $secret     = $server_info['secret'] ?? '';

    // Transport code mapping (Yealink-specific; other vendors use the transport string directly)
    $transportCodes = ['UDP' => 0, 'TCP' => 1, 'TLS' => 2, 'DNS-SRV' => 3];
    $transportCode  = $transportCodes[strtoupper($transport)] ?? 0;

    $regExpiry       = $custom_options['reg_expiry'] ?? '3600';
    $voicemailNumber = $custom_options['voicemail_number'] ?? '';
    $outboundProxy   = $custom_options['outbound_proxy_host'] ?? '';
    $outboundPort    = $custom_options['outbound_proxy_port'] ?? '5060';
    $backupServer    = $custom_options['backup_server'] ?? '';
    $backupPort      = $custom_options['backup_port'] ?? '5060';
    $wallpaperUrl    = $server_info['wallpaper_url'] ?? '';
    $provisioningUrl = $server_info['provisioning_url'] ?? '';
    $provUser        = $device['prov_username'] ?? '';
    $provPass        = $device['prov_password'] ?? '';
    $securityPin     = $device['security_pin'] ?? '';
    $adminPassword   = $custom_options['admin_password'] ?? '';

    // Feature flags from custom options
    $autoAnswer       = $custom_options['auto_answer'] ?? '0';
    $dndEnabled       = $custom_options['dnd_enabled'] ?? '0';
    $callWaiting      = $custom_options['call_waiting'] ?? '1';
    $webUiEnabled     = $custom_options['web_ui_enabled'] ?? '1';
    $cdpLldpEnabled   = $custom_options['cdp_lldp_enabled'] ?? '1';
    $screensaverTimeout = $custom_options['screensaver_timeout'] ?? '0';
    $voiceVlanId      = $custom_options['voice_vlan_id'] ?? '';
    $dataVlanId       = $custom_options['data_vlan_id'] ?? '';
    $firmwareUrl      = $custom_options['firmware_url'] ?? '';
    $syslogServer     = $custom_options['syslog_server'] ?? '';
    $ringtoneUrl      = $custom_options['ringtone_url'] ?? '';
    $cfwAlways        = $custom_options['cfw_always'] ?? '';
    $cfwBusy          = $custom_options['cfw_busy'] ?? '';
    $cfwNoAnswer      = $custom_options['cfw_no_answer'] ?? '';
    $dialPlan         = $custom_options['dial_plan'] ?? '';

    // For toggle/boolean settings, "has_*" means the user explicitly configured it
    // (the key exists in custom_options), even if the value is "0".
    $hasAutoAnswer  = array_key_exists('auto_answer', $custom_options);
    $hasDnd         = array_key_exists('dnd_enabled', $custom_options);
    $hasCallWaiting = array_key_exists('call_waiting', $custom_options);
    $hasWebUi       = array_key_exists('web_ui_enabled', $custom_options);
    $hasCdpLldp     = array_key_exists('cdp_lldp_enabled', $custom_options);

    // --- Build the lines array (single line) ---
    $lines = [
        [
            'line_index'          => 1,
            'label'               => $displayName,
            'display_name'        => $displayName,
            'auth_name'           => $extension,
            'user_name'           => $extension,
            'password'            => $secret,
            'sip_server'          => $sipServer,
            'sip_port'            => $sipPort,
            'transport'           => $transport,
            'transport_code'      => $transportCode,
            'expires'             => $regExpiry,
            'has_outbound_proxy'  => ($outboundProxy !== ''),
            'outbound_proxy_host' => $outboundProxy,
            'outbound_proxy_port' => $outboundPort,
            'has_backup_server'   => ($backupServer !== ''),
            'backup_server'       => $backupServer,
            'backup_port'         => $backupPort,
            'has_voicemail'       => ($voicemailNumber !== ''),
            'voicemail_number'    => $voicemailNumber,
            'has_auto_answer'     => $hasAutoAnswer,
            'auto_answer'         => $autoAnswer,
            'has_cfw_always'      => ($cfwAlways !== ''),
            'cfw_always'          => $cfwAlways,
            'has_cfw_busy'        => ($cfwBusy !== ''),
            'cfw_busy'            => $cfwBusy,
            'has_cfw_no_answer'   => ($cfwNoAnswer !== ''),
            'cfw_no_answer'       => $cfwNoAnswer,
        ],
    ];

    // --- Build line_keys array ---
    $typeMapping = $meta['type_mapping'] ?? [];
    usort($keys, function ($a, $b) {
        return ($a['index'] ?? 0) - ($b['index'] ?? 0);
    });

    $maxLineKeys = $meta['max_line_keys'] ?? 0;
    $lineKeys = [];
    $attendantKeys = [];

    foreach ($keys as $k) {
        $rawType    = $k['type'] ?? 'line';
        $typeCode   = $typeMapping[$rawType] ?? $rawType;
        $position   = ($k['index'] ?? 0) + 1; // 1-based position
        $keyValue   = $k['value'] ?? '';
        $keyLabel   = $k['label'] ?? '';
        $keyLine    = $k['line'] ?? 1;
        $pickupCode = $k['pickup_code'] ?? '';

        $entry = [
            'position'    => $position,
            'type'        => $rawType,
            'type_code'   => $typeCode,
            'key_value'   => $keyValue,
            'key_label'   => $keyLabel,
            'key_line'    => $keyLine,
            'pickup_code' => $pickupCode,
            'sip_server'  => $sipServer,
            'is_blf'      => ($rawType === 'blf'),
        ];

        if ($position <= $maxLineKeys || $maxLineKeys === 0) {
            $lineKeys[] = $entry;
        }

        // Attendant keys for Polycom: BLF entries only
        if ($rawType === 'blf') {
            $attendantKeys[] = $entry;
        }
    }

    // --- Build remote_phonebooks array ---
    $remotePhonebooks = [];
    foreach ($contacts as $idx => $c) {
        $remotePhonebooks[] = [
            'index' => $idx + 1,
            'name'  => $c['name'] ?? '',
            'url'   => $c['number'] ?? '',
        ];
    }

    // --- has_* boolean flags ---
    $ctx = [
        'mac_address'       => $mac,
        'mac'               => $mac,
        'model'             => $model,
        'sip_server'        => $sipServer,
        'sip_port'          => $sipPort,
        'transport'         => $transport,
        'transport_code'    => $transportCode,
        'extension'         => $extension,
        'display_name'      => $displayName,
        'password'          => $secret,
        'reg_expiry'        => $regExpiry,
        'security_pin'      => $securityPin,
        'admin_password'    => $adminPassword,
        'wallpaper_url'     => $wallpaperUrl,
        'provisioning_url'  => $provisioningUrl,
        'provision_user'    => $provUser,
        'provision_pass'    => $provPass,

        // Feature values
        'auto_answer'          => $autoAnswer,
        'dnd_enabled'          => $dndEnabled,
        'call_waiting'         => $callWaiting,
        'web_ui_enabled'       => $webUiEnabled,
        'cdp_lldp_enabled'     => $cdpLldpEnabled,
        'screensaver_timeout'  => $screensaverTimeout,
        'voice_vlan_id'        => $voiceVlanId,
        'data_vlan_id'         => $dataVlanId,
        'firmware_url'         => $firmwareUrl,
        'syslog_server'        => $syslogServer,
        'ringtone_url'         => $ringtoneUrl,
        'ring_type'            => $custom_options['ring_type'] ?? 'Ring1.wav',
        'ntp_server'           => $custom_options['ntp_server'] ?? '0.pool.ntp.org',
        'timezone'             => $custom_options['timezone'] ?? 'UTC',
        'dst_enable'           => $custom_options['dst_enable'] ?? '0',
        'gmt_offset'           => $custom_options['gmt_offset'] ?? '0',
        'debug_level'          => $custom_options['debug_level'] ?? '0',
        'cfw_always'           => $cfwAlways,
        'cfw_busy'             => $cfwBusy,
        'cfw_no_answer'        => $cfwNoAnswer,
        'dial_plan'            => $dialPlan,
        'voicemail_number'     => $voicemailNumber,
        'outbound_proxy_host'  => $outboundProxy,
        'outbound_proxy_port'  => $outboundPort,
        'backup_server'        => $backupServer,
        'backup_port'          => $backupPort,

        // Boolean helper flags for conditional sections
        'has_voicemail'           => ($voicemailNumber !== ''),
        'has_outbound_proxy'      => ($outboundProxy !== ''),
        'has_backup_server'       => ($backupServer !== ''),
        'has_auto_answer'         => $hasAutoAnswer,
        'has_dnd'                 => $hasDnd,
        'has_call_waiting'        => $hasCallWaiting,
        'has_cfw_always'          => ($cfwAlways !== ''),
        'has_cfw_busy'            => ($cfwBusy !== ''),
        'has_cfw_no_answer'       => ($cfwNoAnswer !== ''),
        'has_dial_plan'           => ($dialPlan !== ''),
        'has_screensaver_timeout' => ($screensaverTimeout !== '0' && $screensaverTimeout !== ''),
        'has_web_ui'              => $hasWebUi,
        'has_cdp_lldp'            => $hasCdpLldp,
        'has_firmware'            => ($firmwareUrl !== ''),
        'has_syslog'              => ($syslogServer !== ''),
        'has_custom_ringtone'     => ($ringtoneUrl !== ''),
        'has_data_vlan'           => ($dataVlanId !== ''),
        'vlan_enabled'            => ($voiceVlanId !== ''),
        'lock_enable'             => ($securityPin !== '' ? 1 : 0),

        // Boolean helper flags matching Pocket-Provisioner naming
        'is_web_ui_enabled'    => _qp_is_truthy($webUiEnabled),
        'is_cdp_lldp_enabled'  => _qp_is_truthy($cdpLldpEnabled),
        'is_auto_answer'       => _qp_is_truthy($autoAnswer),
        'is_dnd_enabled'       => _qp_is_truthy($dndEnabled),
        'is_call_waiting'      => _qp_is_truthy($callWaiting),

        // Structured arrays
        'lines'             => $lines,
        'line_keys'         => $lineKeys,
        'attendant_keys'    => $attendantKeys,
        'remote_phonebooks' => $remotePhonebooks,
        'expansion_keys'    => [],
    ];

    // Merge all custom_options directly into context (template can reference any custom var)
    foreach ($custom_options as $key => $value) {
        if (!array_key_exists($key, $ctx)) {
            $ctx[$key] = $value;
        }
    }

    // Fill in gaps from META variable defaults
    if (!empty($meta['variables'])) {
        foreach ($meta['variables'] as $varDef) {
            $varName = $varDef['name'] ?? '';
            if ($varName === '') {
                continue;
            }
            if (!array_key_exists($varName, $ctx) || $ctx[$varName] === '' || $ctx[$varName] === null) {
                $default = $varDef['default'] ?? '';
                if ($default !== '') {
                    $ctx[$varName] = $default;
                }
            }
        }
    }

    return $ctx;
}

/**
 * Evaluate whether a value should be considered "truthy" for boolean helper flags.
 * Treats "1", "yes", "true", "on" (case-insensitive) and boolean true as truthy.
 *
 * @param mixed $val
 * @return bool
 */
function _qp_is_truthy($val) {
    if ($val === true) {
        return true;
    }
    if (is_string($val)) {
        return in_array(strtolower($val), ['1', 'yes', 'true', 'on'], true);
    }
    return !empty($val);
}
