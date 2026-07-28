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

    foreach ($keys as $k) {
        $rawType    = $k['type'] ?? 'line';
        $typeCode   = $typeMapping[$rawType] ?? $rawType;
        $position   = $k['index'] ?? 1; // index is already 1-based; do not add 1
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
        'contacts'          => $contactEntries,
        'has_contacts'      => !empty($contactEntries),
        'has_phonebook'     => !empty($remotePhonebooks),
        'phonebook_url'     => $phonebookUrl,
        'remote_phonebooks' => $remotePhonebooks,
        'polycom_contacts_directory' => $server_info['polycom_contacts_directory'] ?? '',
        'has_polycom_contacts_directory' => !empty($server_info['polycom_contacts_directory']) && !empty($contactEntries),
        'attendant_keys'    => $attendantKeys,
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
function qp_save_phonebook_for_device(array $device, array $contacts = null) {
    $mac = preg_replace('/[^A-Fa-f0-9]/', '', strtoupper($device['mac'] ?? ''));
    if (strlen($mac) !== 12) {
        return null;
    }
    if ($contacts === null) {
        $contacts = qp_normalize_contacts($device['contacts_json'] ?? '[]');
    }
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
 */
function qp_get_global_settings() {
    $defaults = [
        'sip_server_host' => '',
        'sip_server_port' => '',
    ];
    try {
        $mod = \FreePBX::Quickprovisioner();
        foreach ($defaults as $k => $v) {
            $val = $mod->getConfig($k);
            if ($val !== false && $val !== null && $val !== '') {
                $defaults[$k] = (string)$val;
            }
        }
    } catch (Exception $e) {
        // Module object unavailable during early bootstrap
    }
    return $defaults;
}

/**
 * Resolve SIP registrar host: per-device custom_options.sip_server >
 * global sip_server_host > HTTP_HOST hostname > SERVER_ADDR.
 */
function qp_resolve_sip_server(array $custom_options = []) {
    if (!empty($custom_options['sip_server'])) {
        return trim((string)$custom_options['sip_server']);
    }
    $globals = qp_get_global_settings();
    if (!empty($globals['sip_server_host'])) {
        return trim($globals['sip_server_host']);
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
 * Build the HTTP URL phones use to fetch a device phonebook.
 */
function qp_build_phonebook_url($mac) {
    $mac = preg_replace('/[^A-Fa-f0-9]/', '', strtoupper((string)$mac));
    if (strlen($mac) !== 12) {
        return '';
    }
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_ADDR'] ?? '127.0.0.1');
    return "$protocol://$host/admin/modules/quickprovisioner/provision.php?mac=$mac&type=phonebook";
}

/**
 * Base URL directory for Polycom CONTACTS_DIRECTORY-style fetches of {mac}-directory.xml
 */
function qp_build_polycom_contacts_directory_url() {
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_ADDR'] ?? '127.0.0.1');
    // Trailing slash: phone appends {mac}-directory.xml
    return "$protocol://$host/admin/modules/quickprovisioner/provision.php/";
}
