<?php
// MustacheEngine.php - Lightweight Mustache renderer & META parser for Quick-Provisioner
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
        'visual_editor'    => $meta['visual_editor'] ?? null,
        'sample_preset'    => $meta['sample_preset'] ?? null,
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
    // 1. Strip comments: META first (JSON may contain `} }` spaced closes), then others
    $template = preg_replace('/\{\{!\s*META:\s*\{[\s\S]*\}\s*\}\}/', '', $template);
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
        error_log("Quick-Provisioner: Failed to scan templates directory: $templates_dir");
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
 * Apply Pocket-compatible short-dial shortening to a dialled number.
 *
 * @param string $full
 * @param string $mode  full|3digit|4digit|5digit|custom
 * @param int    $customDigits
 * @return string
 */
function qp_apply_short_dial($full, $mode = 'full', $customDigits = 0) {
    $full = (string)$full;
    if ($full === '') {
        return '';
    }
    $digits = 0;
    switch ($mode) {
        case '3digit': $digits = 3; break;
        case '4digit': $digits = 4; break;
        case '5digit': $digits = 5; break;
        case 'custom': $digits = max(0, (int)$customDigits); break;
        default: return $full;
    }
    if ($digits <= 0) {
        return $full;
    }
    $len = strlen($full);
    return $len > $digits ? substr($full, -$digits) : $full;
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

    // Merge button speed-dial/BLF into contacts so VVX1500 directory hotkeys
    // work even when contacts_json was left empty (home-screen uses <sd>/<bw>).
    $contacts = qp_normalize_contacts($device['contacts_json'] ?? []);
    $contacts = qp_apply_poly_directory_speed_dials($device, $contacts);

    $mac        = $device['mac'] ?? '';
    $model      = $device['model'] ?? '';
    $extension  = $device['extension'] ?? '';
    // Prefer explicit server_info, else resolve from globals / host
    $sipServer  = $server_info['server_ip'] ?? '';
    if ($sipServer === '') {
        $sipServer = qp_resolve_sip_server($custom_options);
    }
    $sipPort    = $custom_options['sip_port']  ?? $server_info['sip_port'] ?? qp_resolve_sip_port($custom_options);
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
    $provUrls        = $server_info['provisioning_base'] ?? null
        ? ['provisioning_base' => $server_info['provisioning_base'], 'provisioning_url' => $server_info['provisioning_url'] ?? '']
        : qp_build_provisioning_urls($mac);
    $provisioningUrl = $provUrls['provisioning_url'];
    $provisioningBase = $provUrls['provisioning_base'];
    $provUser        = $device['prov_username'] ?? '';
    $provPass        = $device['prov_password'] ?? '';
    $securityPin     = $device['security_pin'] ?? '';
    // Poly refuses to keep factory admin "456" and nags until a non-default password
    // is applied via device.auth.localAdminPassword.set=1. Default hotel lock: 789.
    $adminPassword   = trim((string)($custom_options['admin_password'] ?? ''));
    if ($adminPassword === '' || $adminPassword === '456') {
        $adminPassword = '789';
    }

    // Feature flags from custom options
    $autoAnswer       = $custom_options['auto_answer'] ?? '0';
    $dndEnabled       = $custom_options['dnd_enabled'] ?? '0';
    $callWaiting      = $custom_options['call_waiting'] ?? '1';
    $webUiEnabled     = $custom_options['web_ui_enabled'] ?? '1';
    $cdpLldpEnabled   = $custom_options['cdp_lldp_enabled'] ?? '1';
    $kidFriendly      = _qp_is_truthy($custom_options['kid_friendly_mode'] ?? '0');
    if ($kidFriendly) {
        // Restricted handsets never expose the phone web UI.
        $webUiEnabled = '0';
    }
    $screensaverTimeout = $custom_options['screensaver_timeout'] ?? '0';
    $voiceVlanId      = $custom_options['voice_vlan_id'] ?? '';
    $dataVlanId       = $custom_options['data_vlan_id'] ?? '';
    $firmwareUrl      = trim((string)($custom_options['firmware_url'] ?? ''));
    $syslogServer     = $custom_options['syslog_server'] ?? '';
    $ringtoneUrl      = $custom_options['ringtone_url'] ?? '';
    $cfwAlways        = $custom_options['cfw_always'] ?? '';
    $cfwBusy          = $custom_options['cfw_busy'] ?? '';
    $cfwNoAnswer      = $custom_options['cfw_no_answer'] ?? '';
    $dialPlan         = trim((string)($custom_options['dial_plan'] ?? ''));
    if ($dialPlan === '') {
        // FreePBX 3-digit extensions (100–999). Factory Poly maps often include 2-digit
        // room patterns (xx) which fire before the user finishes dialling 101/104.
        $dialPlan = qp_default_dial_plan();
    }

    // For toggle/boolean settings, "has_*" means the user explicitly configured it
    // (the key exists in custom_options), even if the value is "0".
    $hasAutoAnswer  = array_key_exists('auto_answer', $custom_options);
    $hasDnd         = array_key_exists('dnd_enabled', $custom_options);
    $hasCallWaiting = array_key_exists('call_waiting', $custom_options);
    // Always emit web-admin keys so field diagnostics work without per-device toggles.
    $hasWebUi       = true;
    $hasCdpLldp     = array_key_exists('cdp_lldp_enabled', $custom_options);

    // VVX1500: video over UDP fragments SIP INVITEs — prefer TCP signalling.
    $isVvx1500 = in_array($model, ['VVX1500', 'VVX 1500'], true);
    // Firmware URL is opt-in via custom_options only (do not auto-push on every boot).
    $videoEnable = $isVvx1500 ? '1' : (string)($custom_options['video_enable'] ?? '0');
    if (array_key_exists('video_enable', $custom_options)) {
        $videoEnable = _qp_is_truthy($custom_options['video_enable']) ? '1' : '0';
    }
    $sipTransportMode = 'UDPOnly';
    if ($videoEnable === '1') {
        $sipTransportMode = 'TCPPreferred';
    }
    if (!empty($custom_options['sip_transport'])) {
        $sipTransportMode = (string)$custom_options['sip_transport'];
    }

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
            'sip_transport_mode'  => $sipTransportMode,
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
            // has_sip_server controls whether a full SIP registration is emitted.
            // When the server IP is empty the template falls back to DMS/cloud mode.
            'has_sip_server'      => ($sipServer !== ''),
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
    $speedDialKeys = [];
    $expansionKeys = [];
    $polyLineKeyAssignments = [];
    $hasPrimaryLineAssignment = false;

    foreach ($keys as $k) {
        $rawType    = $k['type'] ?? 'line';
        // Normalize Pocket-style type aliases
        if ($rawType === 'speeddial') {
            $rawType = 'speed_dial';
        }
        $typeCode   = $typeMapping[$rawType] ?? $rawType;
        if (!isset($typeMapping[$rawType]) && $rawType === 'speed_dial' && isset($typeMapping['speeddial'])) {
            $typeCode = $typeMapping['speeddial'];
        }
        $position   = $k['index'] ?? 1; // index is already 1-based; do not add 1
        $fullValue  = (string)($k['full_value'] ?? $k['fullValue'] ?? $k['value'] ?? '');
        $shortMode  = (string)($k['short_dial_mode'] ?? $k['shortDialMode'] ?? 'full');
        $customDig  = (int)($k['custom_digits'] ?? $k['customDigits'] ?? 0);
        $keyValue   = qp_apply_short_dial($fullValue, $shortMode, $customDig);
        $keyLabel   = $k['label'] ?? '';
        $keyLine    = $k['line'] ?? 1;
        $pickupCode = $k['pickup_code'] ?? '';
        $role       = $k['role'] ?? 'line';
        $module     = (int)($k['module'] ?? 0);
        $isSelfExtension = ($extension !== '' && trim((string)$keyValue) === trim((string)$extension));

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
            'full_value'  => $fullValue,
            'short_dial_mode' => $shortMode,
            'role'        => $role,
            'module'      => $module,
        ];

        if ($role === 'expansion' || $module > 0) {
            $expansionKeys[] = [
                'module'    => max(1, $module),
                'position'  => $position,
                'type_code' => $typeCode,
                'key_value' => $keyValue,
                'key_label' => $keyLabel,
            ];
            continue;
        }

        if ($position <= $maxLineKeys || $maxLineKeys === 0) {
            $lineKeys[] = $entry;
        }

        if ($rawType === 'line' && $module === 0 && ($position <= $maxLineKeys || $maxLineKeys === 0)) {
            $hasPrimaryLineAssignment = true;
            $polyLineKeyAssignments[] = [
                'position' => $position,
                'category' => 'Line',
                'index' => max(1, (int)$keyLine),
                'has_index' => true,
            ];
        }

        // Attendant keys for Polycom: BLF entries only
        if ($rawType === 'blf' && !$isSelfExtension) {
            $entry['button_position'] = $position;
            $entry['position'] = count($attendantKeys) + 1; // Poly requires sequential resourceList indexes
            $attendantKeys[] = $entry;
        }

        // Speed dial entries use speedDial.N index (separate from BLF resourceList)
        if ($rawType === 'speed_dial' && $module === 0 && $keyValue !== '') {
            $entry['button_position'] = $position;
            $entry['position'] = count($speedDialKeys) + 1;
            $speedDialKeys[] = $entry;
        }
    }

    if (!$hasPrimaryLineAssignment && ($maxLineKeys === 0 || $maxLineKeys >= 1)) {
        $polyLineKeyAssignments[] = [
            'position' => 1,
            'category' => 'Line',
            'index' => 1,
            'has_index' => true,
        ];
    }

    $attendantIndex = 0;
    foreach ($attendantKeys as $entry) {
        $attendantIndex++;
        $renderPosition = (int)($entry['button_position'] ?? $entry['position']);
        if (!$hasPrimaryLineAssignment && $renderPosition >= 1) {
            $renderPosition++;
        }
        if ($maxLineKeys > 0 && $renderPosition > $maxLineKeys) {
            continue;
        }
        $polyLineKeyAssignments[] = [
            'position' => $renderPosition,
            'category' => 'BLF',
            'index' => 0,
            'has_index' => true,
            'resource_position' => $attendantIndex,
        ];
    }

    $speedDialIndex = 0;
    foreach ($speedDialKeys as $entry) {
        $speedDialIndex++;
        $renderPosition = (int)($entry['button_position'] ?? $entry['position']);
        if (!$hasPrimaryLineAssignment && $renderPosition >= 1) {
            $renderPosition++;
        }
        if ($maxLineKeys > 0 && $renderPosition > $maxLineKeys) {
            continue;
        }
        $polyLineKeyAssignments[] = [
            'position' => $renderPosition,
            'category' => 'SpeedDial',
            'index' => $speedDialIndex,
            'has_index' => true,
        ];
    }

    // VVX1500 does NOT support Flexible Line Key reassignment (Poly UCS notes).
    // Home-screen hotkeys come from directory <sd> indexes instead.
    $polyTwoColumnDisplay = $isVvx1500;
    if ($isVvx1500) {
        $polyLineKeyAssignments = [];
    }

    if (!empty($polyLineKeyAssignments)) {
        usort($polyLineKeyAssignments, function ($a, $b) {
            return ((int)$a['position']) <=> ((int)$b['position']);
        });
    }

    // --- Build remote_phonebooks array ---
    // Contacts are name/number entries. Phones expect a remote phonebook *URL*
    // that returns vendor XML (same model as Pocket-Provisioner), not the number itself.
    $remotePhonebooks = [];
    $phonebookUrl = $server_info['phonebook_url'] ?? '';
    if ($phonebookUrl !== '' && !empty($contacts)) {
        $remotePhonebooks[] = [
            'index' => 1,
            'name'  => $server_info['phonebook_name'] ?? 'Directory',
            'url'   => $phonebookUrl,
        ];
    }

    // Normalize contacts for template use (and keep Pocket-compatible fields)
    $contactEntries = [];
    foreach ($contacts as $idx => $c) {
        if (!is_array($c)) {
            continue;
        }
        $name = trim((string)($c['name'] ?? ''));
        $number = trim((string)($c['number'] ?? $c['phone'] ?? ''));
        if ($name === '' && $number === '') {
            continue;
        }
        $contactEntries[] = [
            'index'  => $idx + 1,
            'name'   => $name !== '' ? $name : $number,
            'number' => $number,
            'phone'  => $number,
            'source' => $c['source'] ?? 'custom',
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
        'sip_transport_mode'=> $sipTransportMode,
        'video_enable'      => $videoEnable,
        'video_auto_start'  => $videoEnable === '1' ? '1' : '0',
        'extension'         => $extension,
        'display_name'      => $displayName,
        'password'          => $secret,
        'reg_expiry'        => $regExpiry,
        'security_pin'      => $securityPin,
        'admin_password'    => $adminPassword,
        'has_admin_password'=> ($adminPassword !== ''),
        'wallpaper_url'     => $wallpaperUrl,
        'provisioning_url'  => $provisioningUrl,
        'provisioning_base' => $provisioningBase,
        'has_provisioning_base' => ($provisioningBase !== ''),
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
        'ntp_server'           => $custom_options['ntp_server'] ?? '0.au.pool.ntp.org',
        'timezone'             => $custom_options['timezone'] ?? 'Australia/Brisbane',
        'dst_enable'           => $custom_options['dst_enable'] ?? '0',
        'gmt_offset'           => $custom_options['gmt_offset'] ?? '36000',
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
        'has_dial_plan'           => true,
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
        'has_sip_server'       => ($sipServer !== ''),
        'is_web_ui_enabled'    => _qp_is_truthy($webUiEnabled),
        'is_cdp_lldp_enabled'  => _qp_is_truthy($cdpLldpEnabled),
        'is_auto_answer'       => _qp_is_truthy($autoAnswer),
        'is_dnd_enabled'       => _qp_is_truthy($dndEnabled),
        'is_call_waiting'      => _qp_is_truthy($callWaiting),
        'is_kid_friendly'      => $kidFriendly,
        'kid_friendly_mode'    => $kidFriendly ? '1' : '0',

        // Structured arrays
        'lines'             => $lines,
        'line_keys'         => $lineKeys,
        'contacts'          => $contactEntries,
        'has_contacts'      => !empty($contactEntries),
        'has_phonebook'     => !empty($remotePhonebooks),
        'phonebook_url'     => $phonebookUrl,
        'remote_phonebooks' => $remotePhonebooks,
        'polycom_contacts_directory' => $server_info['polycom_contacts_directory'] ?? '',
        'has_polycom_contacts_directory' => !empty($server_info['polycom_contacts_directory']) && !empty($contactEntries),
        'attendant_keys'    => $attendantKeys,
        'has_attendant_keys' => !empty($attendantKeys),
        'speed_dial_keys'   => $speedDialKeys,
        'has_speed_dial_keys' => !empty($speedDialKeys),
        'poly_two_column_display' => $polyTwoColumnDisplay,
        'poly_line_key_assignments' => $polyLineKeyAssignments,
        'has_poly_line_key_assignments' => !empty($polyLineKeyAssignments),
        'expansion_keys'    => $expansionKeys,
        'has_expansion_keys'=> !empty($expansionKeys),
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

    // For XML-format configs (Polycom, Cisco) escape all string values so that
    // user-supplied data containing '<', '>', '&', '"' or "'" cannot produce
    // malformed XML that the phone's parser will reject.
    if (($meta['config_format'] ?? '') === 'xml') {
        $ctx = _qp_xml_escape_context($ctx);
    }

    return $ctx;
}

/**
 * XML-escape a scalar value for safe embedding in XML-format config files.
 * No-op for non-string/non-scalar values.
 *
 * @param mixed $val
 * @return mixed
 */
function _qp_xml_escape($val) {
    if (is_string($val)) {
        return htmlspecialchars($val, ENT_XML1, 'UTF-8');
    }
    return $val;
}

/**
 * Apply XML escaping to every string value in a flat or nested context array.
 * Called only when the template's config_format is 'xml'.
 *
 * @param array $ctx
 * @return array
 */
function _qp_xml_escape_context(array $ctx) {
    foreach ($ctx as $k => $v) {
        if (is_string($v)) {
            $ctx[$k] = _qp_xml_escape($v);
        } elseif (is_array($v)) {
            $ctx[$k] = _qp_xml_escape_context($v);
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

/**
 * XML-escape a string for phonebook/directory documents.
 */
function qp_phonebook_xml_escape($s) {
    return htmlspecialchars((string)$s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

/**
 * Normalize contacts_json into [{name, number, source}, ...].
 */
function qp_normalize_contacts($contacts_json_or_array) {
    if (is_string($contacts_json_or_array)) {
        $contacts = json_decode($contacts_json_or_array, true);
    } else {
        $contacts = $contacts_json_or_array;
    }
    if (!is_array($contacts)) {
        return [];
    }
    $out = [];
    foreach ($contacts as $c) {
        if (!is_array($c)) {
            continue;
        }
        $name = trim((string)($c['name'] ?? ''));
        $number = trim((string)($c['number'] ?? $c['phone'] ?? ''));
        if ($name === '' && $number === '') {
            continue;
        }
        $source = $c['source'] ?? 'custom';
        if (!in_array($source, ['freepbx', 'custom'], true)) {
            $source = 'custom';
        }
        $out[] = [
            'name'   => $name !== '' ? $name : $number,
            'number' => $number,
            'source' => $source,
        ];
    }
    return $out;
}

/**
 * Generate vendor phonebook XML (Yealink / Polycom / Cisco) from contact entries.
 * Mirrors Pocket-Provisioner PhonebookService formats.
 */
function qp_generate_phonebook_xml(array $contacts, $model = '', $displayName = '') {
    $m = strtoupper((string)$model);
    if (strpos($m, 'CISCO') !== false || strpos($m, 'CP') === 0 || preg_match('/(?:^|[^0-9])(?:78|88)\d{2}(?:[^0-9]|$)/', $m)) {
        return qp_generate_cisco_phonebook_xml($contacts, $displayName);
    }
    if (strpos($m, 'POLY') !== false || strpos($m, 'VVX') !== false || strpos($m, 'EDGE') !== false || strpos($m, 'OBI') === 0) {
        return qp_generate_polycom_phonebook_xml($contacts, $displayName);
    }
    return qp_generate_yealink_phonebook_xml($contacts, $displayName);
}

function qp_generate_yealink_phonebook_xml(array $contacts, $displayName = '') {
    $buf = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<YealinkIPPhoneDirectory>\n";
    if ($displayName !== '') {
        $buf .= '  <!-- ' . qp_phonebook_xml_escape($displayName) . " -->\n";
    }
    foreach ($contacts as $c) {
        $buf .= "  <DirectoryEntry>\n";
        $buf .= '    <Name>' . qp_phonebook_xml_escape($c['name'] ?? '') . "</Name>\n";
        $buf .= '    <Telephone>' . qp_phonebook_xml_escape($c['number'] ?? '') . "</Telephone>\n";
        $buf .= "  </DirectoryEntry>\n";
    }
    $buf .= "</YealinkIPPhoneDirectory>\n";
    return $buf;
}

function qp_generate_polycom_phonebook_xml(array $contacts, $displayName = '') {
    $buf = "<?xml version=\"1.0\" standalone=\"yes\"?>\n<directory>\n";
    if ($displayName !== '') {
        $buf .= '  <!-- ' . qp_phonebook_xml_escape($displayName) . " -->\n";
    }
    $buf .= "  <item_list>\n";
    foreach ($contacts as $c) {
        $buf .= "    <item>\n";
        $buf .= '      <fn>' . qp_phonebook_xml_escape($c['name'] ?? '') . "</fn>\n";
        $buf .= '      <ct>' . qp_phonebook_xml_escape($c['number'] ?? '') . "</ct>\n";
        // VVX1500 home-screen hotkeys come from directory speed-dial indexes
        // (lineKey.reassignment is NOT supported on VVX1500).
        if (!empty($c['sd']) && (int)$c['sd'] > 0) {
            $buf .= '      <sd>' . (int)$c['sd'] . "</sd>\n";
        }
        if (!empty($c['bw'])) {
            $buf .= "      <bw>1</bw>\n";
        }
        $buf .= "    </item>\n";
    }
    $buf .= "  </item_list>\n</directory>\n";
    return $buf;
}

function qp_generate_cisco_phonebook_xml(array $contacts, $displayName = '') {
    $buf = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<CiscoIPPhoneDirectory>\n";
    if ($displayName !== '') {
        $buf .= '  <Title>' . qp_phonebook_xml_escape($displayName) . "</Title>\n";
        $buf .= "  <Prompt>Select a contact</Prompt>\n";
    }
    foreach ($contacts as $c) {
        $buf .= "  <DirectoryEntry>\n";
        $buf .= '    <Name>' . qp_phonebook_xml_escape($c['name'] ?? '') . "</Name>\n";
        $buf .= '    <Telephone>' . qp_phonebook_xml_escape($c['number'] ?? '') . "</Telephone>\n";
        $buf .= "  </DirectoryEntry>\n";
    }
    $buf .= "</CiscoIPPhoneDirectory>\n";
    return $buf;
}

/**
 * Persist phonebook XML for a device MAC. Returns filename or null if empty.
 */
/**
 * Apply Poly directory speed-dial / buddy-watch fields from device keys_json.
 * VVX1500 home-screen hotkeys require <sd>N</sd> in the local directory XML.
 *
 * @param array $device
 * @param array $contacts
 * @return array
 */
function qp_apply_poly_directory_speed_dials(array $device, array $contacts) {
    $keys = [];
    if (!empty($device['keys_json'])) {
        $keys = json_decode($device['keys_json'], true) ?? [];
    }
    if (!is_array($keys)) {
        $keys = [];
    }
    $extension = trim((string)($device['extension'] ?? ''));
    $sdByNumber = [];
    $bwNumbers = [];
    $sdIndex = 1;
    usort($keys, function ($a, $b) {
        return ((int)($a['index'] ?? 0)) <=> ((int)($b['index'] ?? 0));
    });
    foreach ($keys as $k) {
        if (!is_array($k)) {
            continue;
        }
        $rawType = (string)($k['type'] ?? '');
        if ($rawType === 'speeddial') {
            $rawType = 'speed_dial';
        }
        $num = trim((string)($k['value'] ?? $k['full_value'] ?? ''));
        if ($num === '' || ($extension !== '' && $num === $extension)) {
            continue;
        }
        if ($rawType === 'blf') {
            $bwNumbers[$num] = true;
        }
        if (in_array($rawType, ['speed_dial', 'blf'], true) && !isset($sdByNumber[$num])) {
            $sdByNumber[$num] = $sdIndex++;
        }
    }

    $byNumber = [];
    foreach ($contacts as $c) {
        if (!is_array($c)) {
            continue;
        }
        $n = trim((string)($c['number'] ?? $c['phone'] ?? ''));
        if ($n !== '') {
            $byNumber[$n] = true;
        }
    }
    foreach ($keys as $k) {
        if (!is_array($k)) {
            continue;
        }
        $rawType = (string)($k['type'] ?? '');
        if ($rawType === 'speeddial') {
            $rawType = 'speed_dial';
        }
        if (!in_array($rawType, ['speed_dial', 'blf'], true)) {
            continue;
        }
        $num = trim((string)($k['value'] ?? $k['full_value'] ?? ''));
        if ($num === '' || isset($byNumber[$num]) || ($extension !== '' && $num === $extension)) {
            continue;
        }
        $label = trim((string)($k['label'] ?? ''));
        $contacts[] = [
            'name' => $label !== '' ? $label : $num,
            'number' => $num,
            'source' => 'button',
        ];
        $byNumber[$num] = true;
    }

    foreach ($contacts as &$c) {
        if (!is_array($c)) {
            continue;
        }
        $n = trim((string)($c['number'] ?? $c['phone'] ?? ''));
        if ($n !== '' && isset($sdByNumber[$n])) {
            $c['sd'] = $sdByNumber[$n];
        }
        if ($n !== '' && isset($bwNumbers[$n])) {
            $c['bw'] = 1;
        }
    }
    unset($c);

    // Stable order: speed-dial entries first (by sd), then remaining contacts.
    usort($contacts, function ($a, $b) {
        $sa = (int)($a['sd'] ?? 0);
        $sb = (int)($b['sd'] ?? 0);
        if ($sa > 0 && $sb > 0) {
            return $sa <=> $sb;
        }
        if ($sa > 0) {
            return -1;
        }
        if ($sb > 0) {
            return 1;
        }
        return strcmp((string)($a['name'] ?? ''), (string)($b['name'] ?? ''));
    });

    return $contacts;
}

function qp_save_phonebook_for_device(array $device, array $contacts = null) {
    $mac = preg_replace('/[^A-Fa-f0-9]/', '', strtoupper($device['mac'] ?? ''));
    if (strlen($mac) !== 12) {
        return null;
    }
    if ($contacts === null) {
        $contacts = qp_normalize_contacts($device['contacts_json'] ?? '[]');
    }
    $contacts = qp_apply_poly_directory_speed_dials($device, $contacts);

    $dir = __DIR__ . '/assets/phonebook';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    $yealink_name = 'pb_' . $mac . '.xml';
    $poly_name = strtolower($mac) . '-directory.xml';
    $yealink_path = $dir . '/' . $yealink_name;
    $poly_path = $dir . '/' . $poly_name;

    if (empty($contacts)) {
        if (is_file($yealink_path)) {
            @unlink($yealink_path);
        }
        if (is_file($poly_path)) {
            @unlink($poly_path);
        }
        return null;
    }

    // Yealink / Cisco remote phonebook XML
    $vendor_xml = qp_generate_phonebook_xml($contacts, $device['model'] ?? '', $device['extension'] ?? '');
    if (file_put_contents($yealink_path, $vendor_xml) === false) {
        error_log("Quick-Provisioner: Failed to write phonebook $yealink_path");
        return null;
    }
    @chmod($yealink_path, 0664);

    // Polycom local contact directory companion file ({mac}-directory.xml)
    $poly_xml = qp_generate_polycom_phonebook_xml($contacts, $device['extension'] ?? '');
    if (file_put_contents($poly_path, $poly_xml) !== false) {
        @chmod($poly_path, 0664);
    }

    return $yealink_name;
}

/**
 * Module-level settings (kvstore via BMO helpers).
 *
 * Note: FreePBX::Quickprovisioner() often fails in unauthenticated scripts
 * (provision.php / media.php) with "Unable to locate BMO Class". Always fall
 * back to a direct kvstore read so public handset fetches still see Admin settings.
 */
function qp_get_global_settings() {
    $defaults = [
        'sip_server_host' => '',
        'sip_server_port' => '',
        // WAN HTTP port for provision/media/directory URLs (UniFi forwards 9080→80).
        // Empty = omit port (standard 80/443). Default 9080 for remote-safe cfgs.
        'public_http_port' => '9080',
        // lan_open = LAN MAC-only (bring-up); qsetup = Prov user/pass + MAC (default locked).
        'auth_mode' => 'qsetup',
    ];
    try {
        $mod = \FreePBX::Quickprovisioner();
        foreach ($defaults as $k => $v) {
            $val = $mod->getConfig($k);
            if ($val !== false && $val !== null && $val !== '') {
                $defaults[$k] = (string)$val;
            }
        }
    } catch (Throwable $e) {
        // BMO unavailable in public provision context — use SQL fallback below.
    }

    try {
        $db = \FreePBX::Database();
        $stmt = $db->query(
            "SELECT `key`, `val` FROM kvstore_FreePBX_modules_Quickprovisioner
             WHERE `id` = 'noid' AND `key` IN ('sip_server_host','sip_server_port','public_http_port','auth_mode')"
        );
        if ($stmt) {
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $k = (string)($row['key'] ?? '');
                if ($k === '' || !array_key_exists($k, $defaults)) {
                    continue;
                }
                $v = trim((string)($row['val'] ?? ''));
                if ($k === 'public_http_port') {
                    // Allow clearing to standard ports by saving empty
                    $defaults[$k] = $v;
                    continue;
                }
                if ($k === 'auth_mode') {
                    if ($v === 'qsetup' || $v === 'lan_open') {
                        $defaults[$k] = $v;
                    }
                    continue;
                }
                if ($v !== '' && $defaults[$k] === '') {
                    $defaults[$k] = $v;
                }
            }
        }
    } catch (Throwable $e) {
        // ignore
    }

    // Last resort for host: FreePBX SIP Settings → External Address (public DNS)
    if ($defaults['sip_server_host'] === '') {
        try {
            $ext = \FreePBX::Sipsettings()->get('externip');
            if (is_string($ext) && trim($ext) !== '') {
                $defaults['sip_server_host'] = trim($ext);
            }
        } catch (Throwable $e) {
            // ignore
        }
    }

    return $defaults;
}

/**
 * Provision auth mode: lan_open (bring-up) or qsetup (locked).
 */
function qp_auth_mode() {
    $mode = qp_get_global_settings()['auth_mode'] ?? 'qsetup';
    return ($mode === 'qsetup') ? 'qsetup' : 'lan_open';
}

/** True when LAN clients may pull by MAC without Prov Basic Auth / QSetup. */
function qp_lan_open_auth() {
    return qp_auth_mode() === 'lan_open'
        && function_exists('qp_is_local_network')
        && qp_is_local_network();
}

/**
 * Persist a module setting to kvstore (works even when BMO class load fails).
 */
function qp_set_global_setting($key, $value) {
    $key = (string)$key;
    $value = (string)$value;
    if (!in_array($key, ['sip_server_host', 'sip_server_port', 'public_http_port', 'auth_mode'], true)) {
        return false;
    }
    try {
        \FreePBX::Quickprovisioner()->setConfig($key, $value);
    } catch (Throwable $e) {
        // continue with SQL write
    }
    try {
        $db = \FreePBX::Database();
        $db->prepare(
            "DELETE FROM kvstore_FreePBX_modules_Quickprovisioner WHERE `id` = 'noid' AND `key` = ?"
        )->execute([$key]);
        $db->prepare(
            "INSERT INTO kvstore_FreePBX_modules_Quickprovisioner (`key`, `val`, `type`, `id`) VALUES (?, ?, NULL, 'noid')"
        )->execute([$key, $value]);
        return true;
    } catch (Throwable $e) {
        error_log('Quick-Provisioner: failed to persist setting ' . $key . ' - ' . $e->getMessage());
        return false;
    }
}

/**
 * Default Poly/Yealink digitmap for typical FreePBX 3-digit extensions.
 */
function qp_default_dial_plan() {
    return '[1-9]xx|*xx.|*x.T|911|0T';
}

/**
 * Normalize a pasted host / URL into a bare hostname or IP for SIP/provisioning.
 * Accepts: pbx.example.com | http://pbx.example.com | https://pbx.example.com/path
 */
function qp_normalize_server_host($raw) {
    $host = trim((string)$raw);
    if ($host === '') {
        return '';
    }
    // Allow pasting a full URL — phones need host only for SIP, and we rebuild URLs ourselves.
    if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $host)) {
        $parts = parse_url($host);
        if (is_array($parts) && !empty($parts['host'])) {
            $host = $parts['host'];
            if (!empty($parts['port'])) {
                // IPv6 literals already use []; for host:port keep as host only (port is separate setting)
            }
        }
    }
    // Strip accidental path/query if someone pasted host/path without scheme
    $host = preg_replace('#[/\\\\].*$#', '', $host);
    // Strip trailing :port (leave IPv6 [addr] alone)
    if ($host !== '' && $host[0] !== '[') {
        $host = preg_replace('/:\d+$/', '', $host);
    }
    return trim($host);
}

/**
 * Append a remote provisioning auth failure for fail2ban.
 * LAN clients are skipped (also covered by fail2ban ignoreip).
 *
 * Log line format (stable for filters):
 *   2026-07-29 12:00:00 QP_AUTH_FAIL ip=203.0.113.10 mac=AABBCCDDEEFF reason=unknown_mac
 */
function qp_auth_fail_log($reason, $mac = '') {
    if (function_exists('qp_is_local_network') && qp_is_local_network()) {
        return;
    }
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    if ($ip === '' || $ip === 'unknown') {
        return;
    }
    $mac = strtoupper(preg_replace('/[^A-Fa-f0-9]/', '', (string)$mac));
    $reason = preg_replace('/[^a-z0-9_]/', '', strtolower((string)$reason));
    if ($reason === '') {
        $reason = 'auth_fail';
    }
    $line = date('Y-m-d H:i:s') . " QP_AUTH_FAIL ip={$ip} mac={$mac} reason={$reason}\n";
    $path = '/var/log/asterisk/quickprovisioner-auth.log';
    @file_put_contents($path, $line, FILE_APPEND | LOCK_EX);
    if (function_exists('qp_log_access')) {
        $code = ($reason === 'unknown_mac' || $reason === 'invalid_mac') ? 404 : 401;
        try {
            qp_log_access($code, $_SERVER['REQUEST_URI'] ?? '', $mac !== '' ? $mac : null, null, 'auth_fail');
        } catch (Throwable $e) {
            // ignore
        }
    }
}

/**
 * Hostname used in phone-facing HTTP URLs (provision / media / directory).
 * Prefer global SIP host when set so remote handsets get a public DNS name.
 */
function qp_public_http_host() {
    $globals = qp_get_global_settings();
    $configured = qp_normalize_server_host($globals['sip_server_host'] ?? '');
    if ($configured !== '') {
        return $configured;
    }
    $host = $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_ADDR'] ?? '127.0.0.1');
    $host = preg_replace('/:\d+$/', '', (string)$host);
    return $host !== '' ? $host : '127.0.0.1';
}

/**
 * Host[:port] for phone-facing HTTP URLs.
 * Uses Admin → public_http_port (default 9080) so remotes hit the UniFi
 * non-standard provisioning forward instead of WAN :80.
 */
function qp_public_http_authority() {
    $host = qp_public_http_host();
    $globals = qp_get_global_settings();
    $port = trim((string)($globals['public_http_port'] ?? ''));
    if ($port === '' || $port === '80' || $port === '443') {
        return $host;
    }
    if (!preg_match('/^\d{1,5}$/', $port) || (int)$port < 1 || (int)$port > 65535) {
        return $host;
    }
    // IPv6 literals need brackets before :port
    if (strpos($host, ':') !== false && $host[0] !== '[') {
        $host = '[' . $host . ']';
    }
    return $host . ':' . $port;
}

/**
 * Resolve SIP registrar host: per-device custom_options.sip_server >
 * global sip_server_host > HTTP_HOST hostname > SERVER_ADDR.
 */
function qp_resolve_sip_server(array $custom_options = []) {
    if (!empty($custom_options['sip_server'])) {
        return qp_normalize_server_host($custom_options['sip_server']);
    }
    $globals = qp_get_global_settings();
    if (!empty($globals['sip_server_host'])) {
        return qp_normalize_server_host($globals['sip_server_host']);
    }
    $host = $_SERVER['HTTP_HOST'] ?? '';
    if ($host !== '') {
        // Strip port if present
        $host = preg_replace('/:\d+$/', '', $host);
        if ($host !== '' && $host !== 'localhost' && $host !== '127.0.0.1') {
            return $host;
        }
    }
    return $_SERVER['SERVER_ADDR'] ?? '';
}

function qp_resolve_sip_port(array $custom_options = []) {
    if (!empty($custom_options['sip_port'])) {
        return (string)$custom_options['sip_port'];
    }
    $globals = qp_get_global_settings();
    if (!empty($globals['sip_server_port'])) {
        return (string)$globals['sip_server_port'];
    }
    try {
        return (string)(\FreePBX::Sipsettings()->get('bindport') ?? '5060');
    } catch (Exception $e) {
        return '5060';
    }
}

/**
 * Build provisioning URLs used in handset templates.
 *
 * - provisioning_base: RPS-style directory (trailing slash). Phones fetch {MAC}.cfg / {MAC}.xml.
 * - provisioning_url: explicit resync URL with ?mac= (Yealink check-sync, bootstrap handoff).
 */
function qp_build_provisioning_urls($mac) {
    $mac = strtoupper(preg_replace('/[^A-Fa-f0-9]/', '', (string)$mac));
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = function_exists('qp_public_http_authority') ? qp_public_http_authority() : qp_public_http_host();
    $script = "$protocol://$host/admin/modules/quickprovisioner/provision.php";
    return [
        'provisioning_base' => $script . '/',
        'provisioning_url'  => $script . '?mac=' . rawurlencode($mac),
    ];
}

/**
 * Build the HTTP URL phones use to fetch a device phonebook.
 */
function qp_build_phonebook_url($mac) {
    $mac = preg_replace('/[^A-Fa-f0-9]/', '', strtoupper((string)$mac));
    if (strlen($mac) !== 12) {
        return '';
    }
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = function_exists('qp_public_http_authority') ? qp_public_http_authority() : qp_public_http_host();
    return "$protocol://$host/admin/modules/quickprovisioner/provision.php?mac=$mac&type=phonebook";
}

/**
 * Base URL directory for Polycom CONTACTS_DIRECTORY-style fetches of {mac}-directory.xml
 */
function qp_build_polycom_contacts_directory_url() {
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = function_exists('qp_public_http_authority') ? qp_public_http_authority() : qp_public_http_host();
    // Trailing slash: phone appends {mac}-directory.xml
    return "$protocol://$host/admin/modules/quickprovisioner/provision.php/";
}

/**
 * Public URL for a firmware binary under provision.php/firmware/.
 */
function qp_build_firmware_asset_url($filename) {
    $filename = basename((string)$filename);
    if ($filename === '' || $filename === '.' || $filename === '..') {
        return '';
    }
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = function_exists('qp_public_http_authority') ? qp_public_http_authority() : qp_public_http_host();
    return "$protocol://$host/admin/modules/quickprovisioner/provision.php/firmware/" . rawurlencode($filename);
}

/** Default VVX1500 UC image shipped in assets/firmware/. */
function qp_default_vvx1500_firmware_url() {
    return qp_build_firmware_asset_url('2345-17960-001.sip.ld');
}

/**
 * Look up device by Prov user/pass. Returns null if missing/invalid.
 */
function qp_find_device_by_prov_auth($user, $pass) {
    if ($user === '' || $pass === '') {
        return null;
    }
    try {
        $stmt = \FreePBX::Database()->query(
            "SELECT * FROM quickprovisioner_devices WHERE prov_username IS NOT NULL AND prov_username != '' AND prov_password IS NOT NULL AND prov_password != ''"
        );
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if (hash_equals((string)$row['prov_username'], (string)$user)
                && hash_equals((string)$row['prov_password'], (string)$pass)) {
                return $row;
            }
        }
    } catch (Exception $e) {
        return null;
    }
    return null;
}

/**
 * FreePBX Intrusion Detection / fail2ban whitelist is IP-based.
 * Quick-Provisioner keeps a managed block keyed by handset MAC so add/delete
 * in QP stays in sync. IPs are taken from PJSIP contacts + recent provision hits.
 */
function qp_firewall_whitelist_markers() {
    return [
        'begin' => '# BEGIN QUICKPROVISIONER-MAC-WHITELIST',
        'end'   => '# END QUICKPROVISIONER-MAC-WHITELIST',
    ];
}

function qp_normalize_whitelist_ip($ip) {
    $ip = trim((string)$ip);
    if ($ip === '' || $ip === '::1' || $ip === '127.0.0.1') {
        return '';
    }
    // Strip port / zone id
    if (strpos($ip, '%') !== false) {
        $ip = explode('%', $ip, 2)[0];
    }
    if (preg_match('/^\[([^\]]+)\]:\d+$/', $ip, $m)) {
        $ip = $m[1];
    } elseif (preg_match('/^(\d+\.\d+\.\d+\.\d+):\d+$/', $ip, $m)) {
        $ip = $m[1];
    }
    if (filter_var($ip, FILTER_VALIDATE_IP)) {
        return $ip;
    }
    return '';
}

/**
 * Collect current IPs associated with a QP device (SIP contact + access log).
 */
function qp_collect_ips_for_device($mac, $extension = '') {
    $mac = strtoupper(preg_replace('/[^A-Fa-f0-9]/', '', (string)$mac));
    $ips = [];
    $extension = preg_replace('/[^0-9A-Za-z_-]/', '', (string)$extension);

    try {
        $db = \FreePBX::Database();
        if ($mac !== '') {
            $stmt = $db->prepare(
                "SELECT DISTINCT client_ip FROM quickprovisioner_access_log
                 WHERE mac = ? AND status_code = 200 AND client_ip IS NOT NULL AND client_ip != ''
                   AND (
                     user_agent LIKE '%Polycom%'
                     OR user_agent LIKE '%FileTransport%'
                     OR user_agent LIKE '%Yealink%'
                     OR user_agent LIKE '%Cisco%'
                     OR user_agent LIKE '%VTech%'
                   )
                 ORDER BY id DESC LIMIT 20"
            );
            $stmt->execute([$mac]);
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $ip = qp_normalize_whitelist_ip($row['client_ip'] ?? '');
                if ($ip !== '' && !qp_is_self_ip($ip)) {
                    $ips[$ip] = true;
                }
            }
        }
    } catch (Throwable $e) {
        // ignore
    }

    if ($extension !== '') {
        $out = [];
        @exec('asterisk -rx ' . escapeshellarg("pjsip show contacts") . ' 2>/dev/null', $out);
        $text = implode("\n", $out);
        // Match lines mentioning this extension's AOR/contact
        if (preg_match_all('/\b' . preg_quote($extension, '/') . '\b[^\n]*?@([0-9a-fA-F\.:]+)/', $text, $mm)) {
            foreach ($mm[1] as $raw) {
                $ip = qp_normalize_whitelist_ip($raw);
                if ($ip !== '' && !qp_is_self_ip($ip)) {
                    $ips[$ip] = true;
                }
            }
        }
        // Also try endpoint-specific
        $out2 = [];
        @exec('asterisk -rx ' . escapeshellarg("pjsip show contacts {$extension}") . ' 2>/dev/null', $out2);
        foreach ($out2 as $line) {
            if (preg_match('/@([0-9a-fA-F\.:]+)/', $line, $m)) {
                $ip = qp_normalize_whitelist_ip($m[1]);
                if ($ip !== '' && !qp_is_self_ip($ip)) {
                    $ips[$ip] = true;
                }
            }
        }
    }

    return array_keys($ips);
}

/** True if IP is this PBX (should not be fail2ban-whitelisted as a "handset"). */
function qp_is_self_ip($ip) {
    static $self = null;
    if ($self === null) {
        $self = ['127.0.0.1' => true, '::1' => true];
        // Prefer request/server addr + all host IPs — never hardcode a site LAN IP.
        foreach ([$_SERVER['SERVER_ADDR'] ?? ''] as $cand) {
            $n = qp_normalize_whitelist_ip($cand);
            if ($n !== '') {
                $self[$n] = true;
            }
        }
        $out = [];
        @exec('hostname -I 2>/dev/null', $out);
        foreach (preg_split('/\s+/', implode(' ', $out)) as $cand) {
            $n = qp_normalize_whitelist_ip($cand);
            if ($n !== '') {
                $self[$n] = true;
            }
        }
    }
    return isset($self[$ip]);
}

/**
 * Rebuild FreePBX Firewall Intrusion Detection custom_whitelist managed block
 * from all Quick-Provisioner devices (MAC + known IPs).
 */
function qp_sync_firewall_mac_whitelist() {
    $markers = qp_firewall_whitelist_markers();
    $lines = [$markers['begin'], '# Managed by Quick-Provisioner — do not edit by hand'];

    try {
        $rows = \FreePBX::Database()->query(
            "SELECT mac, extension FROM quickprovisioner_devices ORDER BY extension, mac"
        )->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('Quick-Provisioner: firewall whitelist sync failed to list devices - ' . $e->getMessage());
        return false;
    }

    $allIps = [];
    foreach ($rows as $row) {
        $mac = strtoupper(preg_replace('/[^A-Fa-f0-9]/', '', (string)($row['mac'] ?? '')));
        if (strlen($mac) !== 12) {
            continue;
        }
        $ext = (string)($row['extension'] ?? '');
        $lines[] = '# MAC=' . $mac . ($ext !== '' ? (' EXT=' . $ext) : '');
        foreach (qp_collect_ips_for_device($mac, $ext) as $ip) {
            $lines[] = $ip;
            $allIps[$ip] = true;
        }
    }
    $lines[] = $markers['end'];
    $managedBlock = implode("\n", $lines);

    // Persist MAC inventory for UI / debugging
    try {
        if (function_exists('qp_set_global_setting')) {
            // qp_set_global_setting only allows known keys — write SQL directly
            $db = \FreePBX::Database();
            $db->prepare(
                "DELETE FROM kvstore_FreePBX_modules_Quickprovisioner WHERE `id` = 'noid' AND `key` = ?"
            )->execute(['firewall_mac_whitelist']);
            $payload = json_encode([
                'updated_at' => date('c'),
                'macs' => array_values(array_filter(array_map(function ($r) {
                    return strtoupper(preg_replace('/[^A-Fa-f0-9]/', '', (string)($r['mac'] ?? '')));
                }, $rows))),
                'ips' => array_keys($allIps),
            ]);
            $db->prepare(
                "INSERT INTO kvstore_FreePBX_modules_Quickprovisioner (`key`, `val`, `type`, `id`) VALUES (?, ?, NULL, 'noid')"
            )->execute(['firewall_mac_whitelist', $payload]);
        }
    } catch (Throwable $e) {
        // non-fatal
    }

    try {
        $fw = \FreePBX::Firewall();
    } catch (Throwable $e) {
        error_log('Quick-Provisioner: Firewall module unavailable for MAC whitelist sync');
        return false;
    }

    $current = (string)$fw->getConfig('custom_whitelist');
    $current = str_replace(["\r\n", "\r"], "\n", $current);
    // Strip previous managed block
    $pattern = '/' . preg_quote($markers['begin'], '/') . '.*?' . preg_quote($markers['end'], '/') . '\s*/s';
    $current = preg_replace($pattern, '', $current);
    $current = trim($current);
    $new = $current === '' ? $managedBlock : ($current . "\n" . $managedBlock);
    $fw->setConfig('custom_whitelist', $new);

    // Ask Firewall/Sysadmin to push whitelist into fail2ban when available
    try {
        if (method_exists($fw, 'sync')) {
            // no-op if not
        }
        if (method_exists($fw, 'runHook')) {
            try {
                $fw->runHook('get-dynamic-ignoreip');
            } catch (Throwable $e) {
                // ignore
            }
        }
        if (class_exists('FreePBX') && method_exists('\FreePBX', 'Sysadmin')) {
            try {
                \FreePBX::Sysadmin()->runHook('fail2ban-generate');
            } catch (Throwable $e) {
                // Sysadmin may be incomplete on some boxes
            }
        }
    } catch (Throwable $e) {
        // non-fatal — whitelist is stored for next ID apply
    }

    // Keep host fail2ban ignore list in sync when we can write jail.d (needs root via sudo)
    qp_write_fail2ban_qp_ignore(array_keys($allIps));

    return true;
}

/**
 * Optional host fail2ban ignoreip drop-in for QP handset IPs.
 */
function qp_write_fail2ban_qp_ignore(array $ips) {
    $ips = array_values(array_filter(array_map('qp_normalize_whitelist_ip', $ips)));
    $path = '/etc/fail2ban/jail.d/quickprovisioner-whitelist.local';
    $body = "# Managed by Quick-Provisioner — do not edit\n[DEFAULT]\n";
    if ($ips) {
        $body .= 'ignoreip = 127.0.0.1/8 ::1 ' . implode(' ', $ips) . "\n";
    } else {
        $body .= "ignoreip = 127.0.0.1/8 ::1\n";
    }
    $tmp = '/tmp/qp-f2b-whitelist.local';
    if (@file_put_contents($tmp, $body) === false) {
        return false;
    }
    // Best-effort; may fail without passwordless sudo
    $cmd = 'sudo cp ' . escapeshellarg($tmp) . ' ' . escapeshellarg($path)
        . ' && sudo fail2ban-client reload 2>/dev/null';
    @exec($cmd, $out, $code);
    @unlink($tmp);
    return $code === 0;
}
