<?php
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

if (!qp_is_local_network()) {
    die('Remote access denied. Admin UI is local network only.');
}

if (!defined('FREEPBX_IS_AUTH')) { die('No direct script access allowed'); }

if (!class_exists('FreePBX') || !\FreePBX::Core()) {
    die('FreePBX Core not available. Please ensure FreePBX is properly installed.');
}

$extensions = [];
try {
    $users = \FreePBX::Core()->getAllUsers();
    if ($users && is_array($users)) {
        foreach ($users as $user) {
            if (!isset($user['extension']) || $user['extension'] === '') {
                continue;
            }
            $extensions[] = [
                'extension' => (string)$user['extension'],
                'name' => (string)($user['name'] ?? ''),
            ];
        }
        usort($extensions, function ($a, $b) {
            return strnatcmp($a['extension'], $b['extension']);
        });
    }
} catch (Exception $e) {
    error_log("Quick-Provisioner: Failed to fetch extensions - " . $e->getMessage());
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['qp_csrf'])) {
    $_SESSION['qp_csrf'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['qp_csrf'];
$module_version = 'unknown';
$module_xml_path = __DIR__ . '/module.xml';
if (is_readable($module_xml_path)) {
    $xml = @file_get_contents($module_xml_path);
    if ($xml && preg_match('/<version>([^<]+)<\/version>/', $xml, $m)) {
        $module_version = trim($m[1]);
    }
}
?>
<div class="container-fluid">
    <h1><i class="fa fa-phone"></i> Quick-Provisioner <small class="text-muted"><?= htmlspecialchars($module_version, ENT_QUOTES, 'UTF-8') ?></small></h1>

    <ul class="nav nav-tabs" role="tablist">
        <li class="active"><a data-toggle="tab" href="#tab-devices" onclick="loadDevices()">Devices</a></li>
        <li><a data-toggle="tab" href="#tab-editor">Device Editor</a></li>
        <li><a data-toggle="tab" href="#tab-files" onclick="loadAllFiles()">File Manager</a></li>
        <li><a data-toggle="tab" href="#tab-templates" onclick="loadTemplateList()">Templates</a></li>
        <li><a data-toggle="tab" href="#tab-admin">Admin</a></li>
    </ul>

    <div class="tab-content" style="padding-top:20px;">

        <!-- ==================== TAB 1: DEVICES ==================== -->
        <div id="tab-devices" class="tab-pane fade in active">
            <button class="btn btn-success" onclick="newDevice()"><i class="fa fa-plus"></i> Add New</button>
            <button class="btn btn-default" onclick="loadDevices()"><i class="fa fa-refresh"></i> Refresh</button>
            <table class="table table-striped" style="margin-top:15px;">
                <thead><tr><th>MAC</th><th>Extension</th><th>Name</th><th>Secret</th><th>Model</th><th>Actions</th></tr></thead>
                <tbody id="deviceListBody"></tbody>
            </table>
        </div>

        <!-- ==================== TAB 2: DEVICE EDITOR ==================== -->
        <div id="tab-editor" class="tab-pane fade">
            <div id="deviceForm">
                <input type="hidden" id="deviceId" name="deviceId">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                <input type="hidden" id="extension" name="extension">
                <input type="hidden" id="wallpaper" name="wallpaper">
                <input type="hidden" id="wallpaper_mode" name="wallpaper_mode" value="crop">
                <input type="hidden" id="custom_sip_secret" name="custom_sip_secret">

                <div class="row">
                    <!-- LEFT COLUMN: Core Device Settings -->
                    <div class="col-md-4">
                        <div class="panel panel-primary">
                            <div class="panel-heading"><strong><i class="fa fa-cog"></i> Core Device Settings</strong></div>
                            <div class="panel-body">

                                <!-- Extension -->
                                <div class="form-group">
                                    <label>Extension Number</label>
                                    <div id="ext_sel_wrap">
                                        <div class="input-group">
                                            <select id="extension_select" class="form-control" onchange="extSelChanged()">
                                                <option value="">-- Select Extension --</option>
                                                <?php foreach ($extensions as $row):
                                                    $ext = $row['extension'];
                                                    $name = $row['name'];
                                                    $label = $name !== '' ? ($ext . ' — ' . $name) : $ext;
                                                ?>
                                                <option value="<?= htmlspecialchars($ext) ?>" data-name="<?= htmlspecialchars($name) ?>"><?= htmlspecialchars($label) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                            <span class="input-group-btn"><button type="button" class="btn btn-default" onclick="toggleCustomExt()" title="Custom"><i class="fa fa-edit"></i></button></span>
                                        </div>
                                    </div>
                                    <div id="ext_cust_wrap" style="display:none;">
                                        <div class="input-group">
                                            <input type="text" id="extension_custom" class="form-control" placeholder="Custom extension" onchange="custExtChanged()">
                                            <span class="input-group-btn"><button type="button" class="btn btn-default" onclick="toggleCustomExt()" title="Back to list"><i class="fa fa-list"></i></button></span>
                                        </div>
                                    </div>
                                    <small class="text-muted">Select from FreePBX or enter custom</small>
                                </div>

                                <!-- SIP Secret -->
                                <div class="form-group">
                                    <label>SIP Secret</label>
                                    <div id="secret_prev_wrap">
                                        <div class="input-group">
                                            <input type="text" id="sip_secret_preview" class="form-control" readonly placeholder="Auto-fetched from FreePBX">
                                            <span class="input-group-btn">
                                                <button type="button" class="btn btn-default" onclick="copyText('sip_secret_preview')" title="Copy"><i class="fa fa-copy"></i></button>
                                                <button type="button" class="btn btn-default" onclick="toggleCustomSecret()" title="Custom"><i class="fa fa-edit"></i></button>
                                            </span>
                                        </div>
                                    </div>
                                    <div id="secret_cust_wrap" style="display:none;">
                                        <div class="input-group">
                                            <input type="text" id="sip_secret_custom" class="form-control" placeholder="Enter custom SIP secret">
                                            <span class="input-group-btn">
                                                <button type="button" class="btn btn-success" onclick="saveCustomSecret()" title="Save"><i class="fa fa-save"></i></button>
                                                <button type="button" class="btn btn-default" onclick="toggleCustomSecret()" title="Back"><i class="fa fa-refresh"></i></button>
                                            </span>
                                        </div>
                                    </div>
                                    <small class="text-muted">Auto-fetched from FreePBX or enter custom override</small>
                                </div>

                                <hr>

                                <!-- Model -->
                                <div class="form-group">
                                    <label>Model</label>
                                    <select id="model" name="model" class="form-control" onchange="loadProfile()"></select>
                                </div>

                                <!-- MAC -->
                                <div class="form-group">
                                    <label>MAC Address</label>
                                    <input type="text" id="mac" name="mac" class="form-control" required placeholder="AABBCCDDEEFF">
                                </div>

                                <hr>

                                <!-- Provisioning Auth -->
                                <div class="form-group">
                                    <label>Provisioning Username</label>
                                    <div class="input-group">
                                        <input type="text" id="prov_username" name="prov_username" class="form-control" placeholder="For remote provisioning">
                                        <span class="input-group-btn"><button type="button" class="btn btn-default" onclick="genProvUser()" title="Generate from extension + name">Generate</button></span>
                                    </div>
                                    <small class="text-muted">Generate builds <code>ext_name</code> from the selected FreePBX extension (qsetup-friendly).</small>
                                </div>
                                <div class="form-group">
                                    <label>Provisioning Password</label>
                                    <div class="input-group">
                                        <input type="text" id="prov_password" name="prov_password" class="form-control" placeholder="For remote provisioning">
                                        <span class="input-group-btn"><button type="button" class="btn btn-default" onclick="genProvPass()">Generate</button></span>
                                    </div>
                                </div>

                                <hr>

                                <!-- Custom Template Override -->
                                <div class="panel-group">
                                    <div class="panel panel-default">
                                        <div class="panel-heading" style="cursor:pointer;" onclick="$('#advTemplateOverride').collapse('toggle');">
                                            <h4 class="panel-title"><i class="fa fa-caret-right"></i> Per-device template override</h4>
                                        </div>
                                        <div id="advTemplateOverride" class="panel-collapse collapse">
                                            <div class="panel-body">
                                                <textarea id="custom_template_override" name="custom_template_override" class="form-control" rows="6" placeholder="Leave blank to use the model template from the Templates tab..."></textarea>
                                                <p class="text-warning"><small>Advanced: replaces the shared model template for <em>this device only</em>. To edit Poly/Yealink/Cisco templates for everyone, use the <strong>Templates</strong> tab.</small></p>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <hr>

                                <button type="button" class="btn btn-success btn-block btn-lg" onclick="saveDevice()"><i class="fa fa-save"></i> Save Device</button>
                                <button type="button" class="btn btn-info btn-block" onclick="previewConfig()"><i class="fa fa-eye"></i> Preview Config</button>
                            </div>
                        </div>
                    </div>

                    <!-- RIGHT COLUMN: Template Content -->
                    <div class="col-md-8">
                        <div class="panel panel-default">
                            <div class="panel-heading"><strong id="rightColHeader">Select a Model to Load Template</strong></div>
                            <div class="panel-body">
                                <ul class="nav nav-tabs" role="tablist" id="editorSubTabs">
                                    <li class="active"><a href="#sub-settings" data-toggle="tab" data-qp-subtab="1">Settings</a></li>
                                    <li><a href="#sub-wallpaper" data-toggle="tab" data-qp-subtab="1">Wallpaper</a></li>
                                    <li><a href="#sub-buttons" data-toggle="tab" data-qp-subtab="1">Button Layout</a></li>
                                    <li><a href="#sub-contacts" data-toggle="tab" data-qp-subtab="1" onclick="loadContacts()">Contacts</a></li>
                                </ul>
                                <div class="tab-content" style="padding-top:15px;">

                                    <!-- Settings Sub-Tab -->
                                    <div id="sub-settings" class="tab-pane fade in active">
                                        <div class="btn-toolbar" style="margin-bottom:12px;" id="settingsToolbar" hidden>
                                            <div class="btn-group">
                                                <button type="button" class="btn btn-default btn-sm" onclick="loadTemplateExamples('empty')" title="Fill empty fields from template examples"><i class="fa fa-magic"></i> Load Examples</button>
                                                <button type="button" class="btn btn-default btn-sm" onclick="loadTemplateExamples('all')" title="Overwrite all fields with template examples"><i class="fa fa-refresh"></i> Reload Examples</button>
                                                <button type="button" class="btn btn-default btn-sm" onclick="loadTemplateDefaults()" title="Reset fields to template defaults"><i class="fa fa-undo"></i> Apply Defaults</button>
                                            </div>
                                            <div class="btn-group">
                                                <button type="button" class="btn btn-info btn-sm" onclick="loadSamplePreset()" title="Load demo button layout from template sample_preset"><i class="fa fa-th"></i> Load Sample Buttons</button>
                                            </div>
                                        </div>
                                        <p class="text-muted" id="samplePresetNote" style="display:none; margin-top:-4px;"></p>
                                        <div id="deviceOptions"><p class="text-muted">Select a model to view settings.</p></div>
                                    </div>

                                    <!-- Wallpaper Sub-Tab -->
                                    <div id="sub-wallpaper" class="tab-pane fade">
                                        <div class="row">
                                            <div class="col-sm-6">
                                                <div class="panel panel-default">
                                                    <div class="panel-heading"><strong>Screen Dimensions</strong></div>
                                                    <div class="panel-body"><strong>Width:</strong> <span id="screenW">--</span>px &nbsp; <strong>Height:</strong> <span id="screenH">--</span>px</div>
                                                </div>
                                            </div>
                                            <div class="col-sm-6">
                                                <div class="form-group">
                                                    <label>Display Mode</label>
                                                    <select id="wp_mode_sel" class="form-control" onchange="$('#wallpaper_mode').val(this.value); refreshWallpaperPreview();">
                                                        <option value="crop">Crop to Fill</option>
                                                        <option value="fit">Fit (Letterbox)</option>
                                                    </select>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="panel panel-default">
                                            <div class="panel-heading"><strong>Background vs Keys</strong></div>
                                            <div class="panel-body">
                                                <p class="text-muted" style="margin-top:0;">When the handset shows on-screen hotkeys (e.g. VVX1500 right column), keep the logo in the clear area instead of under the keys.</p>
                                                <div class="form-group">
                                                    <label>Wallpaper layout</label>
                                                    <select id="wp_layout_sel" name="custom_options[wallpaper_layout]" class="form-control" onchange="onWallpaperLayoutChange()">
                                                        <option value="around_keys">Clear of keys (recommended)</option>
                                                        <option value="full">Full bleed (edge to edge)</option>
                                                        <option value="custom">Custom margins…</option>
                                                    </select>
                                                </div>
                                                <div id="wpInsetCustom" style="display:none;">
                                                    <div class="row">
                                                        <div class="col-xs-6 col-sm-3"><label>Left</label><input type="number" min="0" max="400" class="form-control" id="wp_inset_left" name="custom_options[wallpaper_inset_left]" value="16" onchange="refreshWallpaperPreview()"></div>
                                                        <div class="col-xs-6 col-sm-3"><label>Top</label><input type="number" min="0" max="400" class="form-control" id="wp_inset_top" name="custom_options[wallpaper_inset_top]" value="40" onchange="refreshWallpaperPreview()"></div>
                                                        <div class="col-xs-6 col-sm-3"><label>Right</label><input type="number" min="0" max="400" class="form-control" id="wp_inset_right" name="custom_options[wallpaper_inset_right]" value="168" onchange="refreshWallpaperPreview()"></div>
                                                        <div class="col-xs-6 col-sm-3"><label>Bottom</label><input type="number" min="0" max="400" class="form-control" id="wp_inset_bottom" name="custom_options[wallpaper_inset_bottom]" value="56" onchange="refreshWallpaperPreview()"></div>
                                                    </div>
                                                    <p class="help-block">Pixels of screen reserved for UI chrome. Image is fitted into the remaining rectangle.</p>
                                                </div>
                                                <p class="help-block" id="wpInsetHint"></p>
                                            </div>
                                        </div>
                                        <div class="panel panel-primary">
                                            <div class="panel-heading"><strong>Upload Wallpaper</strong></div>
                                            <div class="panel-body">
                                                <input type="file" id="wpUpload" class="form-control" accept="image/*">
                                                <br><button type="button" class="btn btn-primary" onclick="uploadWallpaper()"><i class="fa fa-upload"></i> Upload</button>
                                                <small class="text-muted" style="margin-left:10px;">JPG, PNG, GIF. Max 5MB.</small>
                                            </div>
                                        </div>
                                        <div class="panel panel-default">
                                            <div class="panel-heading"><strong>Or Custom URL</strong></div>
                                            <div class="panel-body">
                                                <div class="input-group">
                                                    <input type="text" id="customWpUrl" class="form-control" placeholder="https://example.com/wallpaper.jpg">
                                                    <span class="input-group-btn"><button type="button" class="btn btn-default" onclick="setCustomWpUrl()"><i class="fa fa-link"></i> Use</button></span>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="panel panel-info">
                                            <div class="panel-heading"><strong>Current Wallpaper</strong></div>
                                            <div class="panel-body text-center">
                                                <div id="wpPreview" style="display:none;">
                                                    <img id="wpPreviewImg" style="max-width:100%; max-height:300px; border:1px solid #ccc; border-radius:4px;">
                                                    <br><br><button type="button" class="btn btn-danger btn-sm" onclick="clearWallpaper()"><i class="fa fa-times"></i> Clear</button>
                                                </div>
                                                <div id="wpEmpty"><p class="text-muted">No wallpaper selected</p></div>
                                            </div>
                                        </div>
                                        <h4>Gallery</h4>
                                        <div id="wpGallery" class="row"></div>
                                    </div>

                                    <!-- Button Layout Sub-Tab -->
                                    <div id="sub-buttons" class="tab-pane fade">
                                        <p class="text-muted">Click buttons on the handset preview to program them. Yealink T46U/T54W: with &gt;10 DSS keys the phone uses <strong>3×9 keys</strong> (L1–5 / R6–9) and the <strong>bottom-right is the page switcher</strong>, not a linekey. Poly VVX450: L1–6 / R7–12. Poly VVX1500: landscape touchscreen with up to <strong>6 line keys on the right</strong> (Speed Dial is the reliable home-screen dial button; BLF is the status lamp). Cisco 8851: 5 programmable keys on the left.</p>
                                        <div class="btn-toolbar" style="margin-bottom:10px;">
                                            <div class="btn-group">
                                                <button type="button" class="btn btn-primary btn-sm" onclick="autofillButtonsFromFreepbx('empty')"><i class="fa fa-magic"></i> Auto-fill empty from FreePBX</button>
                                                <button type="button" class="btn btn-default btn-sm" onclick="autofillButtonsFromFreepbx('all')"><i class="fa fa-list"></i> Replace all with FreePBX BLFs</button>
                                            </div>
                                            <div class="btn-group">
                                                <button type="button" class="btn btn-info btn-sm" onclick="loadSamplePreset()"><i class="fa fa-th"></i> Sample Buttons</button>
                                                <button type="button" class="btn btn-warning btn-sm" onclick="clearAllButtons()"><i class="fa fa-trash"></i> Clear Buttons</button>
                                            </div>
                                        </div>
                                        <div class="form-group" id="layoutTargetGrp" style="display:none;">
                                            <label>Layout target</label>
                                            <select id="layoutTarget" class="form-control" onchange="onLayoutTargetChange()"></select>
                                        </div>
                                        <div class="form-group" id="pageSelectorGrp">
                                            <label>Page</label>
                                            <select id="pageSelect" class="form-control" onchange="renderPreview()"></select>
                                        </div>
                                        <div class="panel panel-default">
                                            <div class="panel-heading"><strong>Visual Preview</strong></div>
                                            <div class="panel-body">
                                                <div id="previewContainer" style="position:relative; margin:0 auto; border:1px solid #ccc;"></div>
                                            </div>
                                        </div>
                                        <p class="text-muted" id="bootstrapHint" style="margin-top:10px;"></p>
                                    </div>

                                    <!-- Contacts Sub-Tab -->
                                    <div id="sub-contacts" class="tab-pane fade">
                                        <p class="text-muted" style="margin-bottom:10px;">
                                            Build a remote phonebook for this handset. Import FreePBX extensions (name + number),
                                            then add or rename custom contacts. Names are editable; the phone downloads vendor XML on provision.
                                        </p>
                                        <button type="button" class="btn btn-primary btn-sm" onclick="importFreepbxExtensions()"><i class="fa fa-download"></i> Import FreePBX Extensions</button>
                                        <button type="button" class="btn btn-default btn-sm" onclick="refreshFreepbxNames()" title="Update names for imported extensions from FreePBX"><i class="fa fa-refresh"></i> Refresh FreePBX Names</button>
                                        <button type="button" class="btn btn-success btn-sm" onclick="addContact()"><i class="fa fa-plus"></i> Add Custom Contact</button>
                                        <button type="button" class="btn btn-warning btn-sm" onclick="clearAllContacts()"><i class="fa fa-trash"></i> Clear All</button>
                                        <div id="contactsList" style="margin-top:10px;"></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ==================== TAB 3: FILE MANAGER ==================== -->
        <div id="tab-files" class="tab-pane fade">
            <div class="row">
                <div class="col-md-4">
                    <div class="panel panel-default">
                        <div class="panel-heading"><strong><i class="fa fa-image"></i> Wallpapers</strong></div>
                        <div class="panel-body">
                            <input type="file" id="assetUpload" class="form-control" accept="image/*">
                            <br><button type="button" class="btn btn-primary btn-sm" onclick="uploadAsset()"><i class="fa fa-upload"></i> Upload</button>
                        </div>
                    </div>
                    <div id="assetGrid" class="row"></div>
                </div>
                <div class="col-md-4">
                    <div class="panel panel-default">
                        <div class="panel-heading"><strong><i class="fa fa-music"></i> Ringtones</strong></div>
                        <div class="panel-body">
                            <input type="file" id="ringtoneUpload" class="form-control" accept=".wav">
                            <br><button type="button" class="btn btn-primary btn-sm" onclick="uploadRingtone()"><i class="fa fa-upload"></i> Upload</button>
                        </div>
                    </div>
                    <div id="ringtoneList"></div>
                </div>
                <div class="col-md-4">
                    <div class="panel panel-default">
                        <div class="panel-heading"><strong><i class="fa fa-microchip"></i> Firmware</strong></div>
                        <div class="panel-body">
                            <input type="file" id="firmwareUpload" class="form-control">
                            <br><button type="button" class="btn btn-primary btn-sm" onclick="uploadFirmware()"><i class="fa fa-upload"></i> Upload</button>
                        </div>
                    </div>
                    <div id="firmwareList"></div>
                </div>
            </div>
        </div>

        <!-- ==================== TAB 4: TEMPLATES ==================== -->
        <div id="tab-templates" class="tab-pane fade">
            <div class="row">
                <div class="col-md-6">
                    <div class="panel panel-default">
                        <div class="panel-heading">
                            <strong id="templateEditorTitle"><i class="fa fa-file-code-o"></i> Template Editor</strong>
                            <span id="templateEditorBadge" class="label label-default" style="margin-left:8px; display:none;">new</span>
                        </div>
                        <div class="panel-body">
                            <p class="text-muted" style="margin-top:0;">
                                Edit Mustache templates used by Device Editor. Click <strong>Edit</strong> on a template at right to load it here, or start a new one.
                            </p>
                            <div class="form-group">
                                <label>Filename <small class="text-muted">(saved under templates/)</small></label>
                                <input type="text" id="templateFilename" class="form-control" placeholder="e.g. polycom_vvx.xml.mustache" autocomplete="off">
                            </div>
                            <textarea id="driverInput" class="form-control" rows="16" placeholder="Mustache template with {{! META: {...} }} block..." style="font-family:monospace; font-size:12px;"></textarea>
                            <br>
                            <div class="btn-toolbar">
                                <div class="btn-group">
                                    <button type="button" class="btn btn-success" onclick="saveTemplate()"><i class="fa fa-save"></i> Save Template</button>
                                    <button type="button" class="btn btn-primary" onclick="saveTemplateAsNew()"><i class="fa fa-plus"></i> Save as New</button>
                                </div>
                                <div class="btn-group">
                                    <button type="button" class="btn btn-default" onclick="newTemplateEditor()"><i class="fa fa-file-o"></i> New</button>
                                    <button type="button" class="btn btn-default" onclick="showExample()"><i class="fa fa-lightbulb-o"></i> Mini Example</button>
                                </div>
                            </div>
                            <hr>
                            <label>Or upload a file</label>
                            <div class="input-group">
                                <input type="file" id="templateFileUpload" class="form-control" accept=".mustache,.cfg,.xml,.txt">
                                <span class="input-group-btn">
                                    <button type="button" class="btn btn-default" onclick="uploadTemplateFile()"><i class="fa fa-upload"></i> Load File</button>
                                </span>
                            </div>
                            <div id="importFeedback" style="margin-top:12px;"></div>
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="panel panel-default">
                        <div class="panel-heading">
                            <strong><i class="fa fa-list"></i> Installed Templates</strong>
                            <button type="button" class="btn btn-xs btn-default pull-right" onclick="loadTemplateList()"><i class="fa fa-refresh"></i></button>
                        </div>
                        <div class="panel-body" style="padding:0;">
                            <table class="table table-striped" style="margin:0;">
                                <thead><tr><th>Template</th><th>Manufacturer</th><th>Models</th><th style="width:120px;">Actions</th></tr></thead>
                                <tbody id="templatesList"></tbody>
                            </table>
                        </div>
                    </div>
                    <p class="help-block">
                        These drive the Model dropdown in Device Editor. After saving, hard-refresh if a device is already open so it picks up META/settings changes.
                    </p>
                </div>
            </div>
        </div>

        <!-- ==================== TAB 5: ADMIN ==================== -->
        <div id="tab-admin" class="tab-pane fade">
            <div class="row">
                <div class="col-md-6">
                    <!-- PBX Controls -->
                    <div class="panel panel-primary">
                        <div class="panel-heading"><h3 class="panel-title"><i class="fa fa-server"></i> PBX Controls</h3></div>
                        <div class="panel-body">
                            <button type="button" class="btn btn-success" onclick="reloadPBX()"><i class="fa fa-refresh"></i> Reload Config</button>
                            <span class="text-muted">Apply changes without interrupting calls</span>
                            <br><br>
                            <button type="button" class="btn btn-warning" onclick="restartPBX()"><i class="fa fa-power-off"></i> Restart PBX</button>
                            <span class="text-danger"><i class="fa fa-exclamation-triangle"></i> Interrupts active calls</span>
                            <div id="pbxStatus" style="margin-top:15px;"></div>
                        </div>
                    </div>

                    <!-- Handset resync -->
                    <div class="panel panel-warning">
                        <div class="panel-heading"><h3 class="panel-title"><i class="fa fa-bolt"></i> Handset Provisioning</h3></div>
                        <div class="panel-body">
                            <p class="text-muted" style="margin-top:0;">
                                Push SIP <code>check-sync</code> to every Quick-Provisioner handset so they re-fetch config. Phones must be registered. Use <strong>force reboot</strong> if a phone ignores a normal resync.
                            </p>
                            <div class="checkbox">
                                <label>
                                    <input type="checkbox" id="resyncForceReboot"> Force reboot (<code>check-sync;reboot=true</code>)
                                </label>
                            </div>
                            <button type="button" class="btn btn-warning" id="resyncAllBtn" onclick="resyncAllHandsets()">
                                <i class="fa fa-bolt"></i> Resync All Handsets
                            </button>
                            <div id="resyncAllStatus" style="margin-top:12px;"></div>
                        </div>
                    </div>

                    <!-- Global SIP / Provisioning -->
                    <div class="panel panel-default">
                        <div class="panel-heading"><h3 class="panel-title"><i class="fa fa-globe"></i> Global SIP Server</h3></div>
                        <div class="panel-body">
                            <p class="text-muted">Hostname or IP written into handset configs as the SIP registrar <em>and</em> as the public host in provisioning / wallpaper / directory URLs (so remote phones can call home via public DNS). You can paste a full URL — <code>http://</code> is stripped automatically. Per-device Settings still win if set.</p>
                            <div class="form-group">
                                <label>SIP / Public Host</label>
                                <input type="text" id="globalSipHost" class="form-control" placeholder="e.g. pbx.example.com or http://pbx.example.com">
                            </div>
                            <div class="form-group">
                                <label>SIP Port (optional)</label>
                                <input type="text" id="globalSipPort" class="form-control" placeholder="Leave blank to use FreePBX bindport">
                            </div>
                            <button type="button" class="btn btn-primary" onclick="saveGlobalSettings()"><i class="fa fa-save"></i> Save Global Settings</button>
                            <span id="globalSettingsStatus" style="margin-left:10px;"></span>
                        </div>
                    </div>

                    <!-- Module Updates -->
                    <div class="panel panel-info">
                        <div class="panel-heading"><h3 class="panel-title"><i class="fa fa-cloud-download"></i> Module Updates</h3></div>
                        <div class="panel-body">
                            <p><strong>Version:</strong> <span id="currentVersion"><?= htmlspecialchars($module_version, ENT_QUOTES, 'UTF-8') ?></span> &nbsp; <strong>Commit:</strong> <span id="currentCommit">...</span></p>
                            <button class="btn btn-primary" onclick="checkForUpdates()" id="checkUpdatesBtn"><i class="fa fa-search"></i> Check for Updates</button>
                            <div id="updateStatus" style="margin-top:15px; display:none;">
                                <div id="updateMsg"></div>
                                <div id="changelogSection" style="margin-top:15px; display:none;">
                                    <h4>Changelog:</h4>
                                    <div class="list-group" id="changelogList" style="max-height:200px; overflow-y:auto;"></div>
                                    <button class="btn btn-success" onclick="performUpdate()" id="confirmUpdateBtn">Yes, Update Now</button>
                                    <button class="btn btn-default" onclick="$('#changelogSection,#updateStatus').hide()">Cancel</button>
                                </div>
                            </div>
                            <div id="updateResult" style="margin-top:15px; display:none;"></div>
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="panel panel-default">
                        <div class="panel-heading"><h3 class="panel-title"><i class="fa fa-heartbeat"></i> Module Health</h3></div>
                        <div class="panel-body">
                            <p class="text-muted">Checks writable asset paths, provisioning credential coverage, and SIP secret resolution.</p>
                            <button class="btn btn-default" onclick="loadModuleHealth()"><i class="fa fa-stethoscope"></i> Run Health Check</button>
                            <button class="btn btn-warning" onclick="repairModulePermissions()" style="margin-left:5px;"><i class="fa fa-wrench"></i> Repair Permissions</button>
                        <button class="btn btn-info" onclick="runTemplateSelfTest()" style="margin-left:5px;"><i class="fa fa-flask"></i> Template Self-Test</button>
                            <div id="moduleHealthResult" style="margin-top:15px; display:none;"></div>
                        </div>
                    </div>
                    <!-- Access Log -->
                    <div class="panel panel-default">
                        <div class="panel-heading">
                            <h3 class="panel-title" style="display:inline;"><i class="fa fa-list-alt"></i> Access Log</h3>
                            <button class="btn btn-xs btn-default pull-right" onclick="loadAccessLog()"><i class="fa fa-refresh"></i></button>
                            <button class="btn btn-xs btn-danger pull-right" style="margin-right:5px;" onclick="clearAccessLog()"><i class="fa fa-trash"></i> Clear</button>
                        </div>
                        <div class="panel-body" style="max-height:500px; overflow-y:auto;">
                            <table class="table table-condensed table-striped" style="font-size:11px;">
                                <thead><tr><th>Time</th><th>Status</th><th>Path</th><th>MAC</th><th>IP</th><th>Type</th></tr></thead>
                                <tbody id="accessLogBody"><tr><td colspan="6" class="text-muted">Click refresh to load</td></tr></tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>

    </div>
</div>

<!-- Key Editor Modal -->
<div class="modal fade" id="keyModal">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header"><h4>Edit Key <span id="keyIndex"></span></h4></div>
      <div class="modal-body">
        <div class="form-group">
          <label>Type</label>
          <select id="keyType" class="form-control">
            <option value="line">Line</option>
            <option value="speed_dial">Speed Dial</option>
            <option value="blf">BLF</option>
            <option value="voicemail">Voicemail</option>
            <option value="transfer">Transfer</option>
            <option value="pickup">Pickup</option>
            <option value="park">Park / BLF Park</option>
            <option value="dtmf">DTMF</option>
          </select>
        </div>
        <div class="form-group" id="keyExtPickGroup">
          <label>FreePBX Extension</label>
          <div class="input-group">
            <select id="keyExtSelect" class="form-control">
              <option value="">— Select extension —</option>
              <option value="__custom__">Custom / manual…</option>
            </select>
            <span class="input-group-btn">
              <button type="button" class="btn btn-default" id="keyExtRefresh" title="Reload FreePBX extensions"><i class="fa fa-refresh"></i></button>
            </span>
          </div>
          <small class="text-muted">Pick an extension to fill Value. Label defaults to the FreePBX name — you can override it.</small>
        </div>
        <div class="form-group">
          <label>Value <small class="text-muted">(extension / number dialled)</small></label>
          <input type="text" id="keyValue" class="form-control" placeholder="e.g. 101">
        </div>
        <div class="form-group" id="keyShortDialGroup">
          <label>Short Dial</label>
          <select id="keyShortDial" class="form-control">
            <option value="full">Full — use complete number</option>
            <option value="3digit">3-digit — last 3 digits</option>
            <option value="4digit">4-digit — last 4 digits</option>
            <option value="5digit">5-digit — last 5 digits</option>
            <option value="custom">Custom — specify digits</option>
          </select>
          <input type="number" id="keyCustomDigits" class="form-control" min="1" max="20" value="4" style="margin-top:6px; display:none;" placeholder="Trailing digits to keep">
          <small class="text-muted" id="keyShortDialPreview"></small>
        </div>
        <div class="form-group">
          <label>Label <small class="text-muted">(shown on the phone button)</small></label>
          <div class="input-group">
            <input type="text" id="keyLabel" class="form-control" placeholder="Custom name or leave FreePBX name">
            <span class="input-group-btn">
              <button type="button" class="btn btn-default" id="keyLabelUseName" title="Use FreePBX name for this extension"><i class="fa fa-user"></i></button>
            </span>
          </div>
          <small class="text-muted" id="keyLabelHint"></small>
        </div>
        <input type="hidden" id="keyRole" value="line">
        <input type="hidden" id="keyModule" value="0">
        <p class="help-block" id="keyRoleHint" style="display:none;"></p>
        <button class="btn btn-primary" onclick="saveKey()">Save</button>
        <button class="btn btn-warning" onclick="clearKey()">Clear</button>
      </div>
    </div>
  </div>
</div>

<!-- Contact Editor Modal -->
<div class="modal fade" id="contactModal">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header"><h4>Edit Contact <span id="contactIdx"></span></h4></div>
      <div class="modal-body">
        <input type="hidden" id="contactId">
        <input type="hidden" id="contactSource" value="custom">
        <div class="form-group"><label>Name</label><input type="text" id="contactName" class="form-control" placeholder="Display name"></div>
        <div class="form-group"><label>Number</label><input type="text" id="contactNumber" class="form-control" placeholder="Extension or phone number"></div>
        <p class="help-block">Imported FreePBX rows keep the extension number; you can rename the display name. Custom contacts can use any number.</p>
        <button class="btn btn-primary" onclick="saveContact()">Save</button>
        <button class="btn btn-warning" onclick="clearContact()">Clear</button>
      </div>
    </div>
  </div>
</div>

<!-- Config Preview Modal -->
<div class="modal fade" id="configModal">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header"><h4>Provisioning Config Preview</h4></div>
      <div class="modal-body"><textarea id="configPreview" class="form-control" rows="20" readonly style="font-family:monospace; font-size:11px;"></textarea></div>
      <div class="modal-footer"><button class="btn btn-default" data-dismiss="modal">Close</button></div>
    </div>
  </div>
</div>

<script>
var currentKeys = [];
var currentContacts = [];
var currentDeviceId = null;
var profiles = {};
var templateSources = {};
var smartDialShortcuts = {};
var isExpandedView = false;
var layoutTarget = 'phone'; // 'phone' | 'exp:1' | 'exp:2' ...
var mediaEndpoint = '/admin/modules/quickprovisioner/media.php';
var csrf = '<?= $csrf_token ?>';
var freepbxExtensions = <?= json_encode($extensions, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS) ?>;
var keyLabelCustomised = false;
var profileLoadSeq = 0; // ignore stale loadProfile responses
var modelDropdownReady = false;
var editingTemplateFile = ''; // basename currently open in Templates tab

function parseLayoutTarget() {
    if (layoutTarget === 'phone') return { role: 'line', module: 0 };
    var m = /^exp:(\d+)$/.exec(layoutTarget);
    return { role: 'expansion', module: m ? parseInt(m[1], 10) : 1 };
}

function findKey(idx, role, module) {
    role = role || 'line';
    module = module || 0;
    return currentKeys.find(function(k) {
        var kr = k.role || 'line';
        var km = parseInt(k.module, 10) || 0;
        return k.index === idx && kr === role && km === module;
    });
}

function buildExpansionSlots(em, page) {
    // Yealink EXP40/EXP50: page N has keys ((N-1)*20+1)..(N*20)
    // Physical: left column 10 keys (top→bottom), then right column 10 keys
    var kpp = (em && em.keys_per_page) || 20;
    var rows = Math.floor(kpp / 2) || 10;
    var start = (page - 1) * kpp + 1;
    var slots = [];
    var leftX = 40, rightX = 250, y0 = 48, rowH = 28;
    for (var r = 0; r < rows; r++) {
        slots.push({
            index: start + r,
            x: leftX,
            y: y0 + r * rowH,
            width: 48,
            height: 24,
            page: page,
            side: 'left',
            role: 'expansion'
        });
        slots.push({
            index: start + rows + r,
            x: rightX,
            y: y0 + r * rowH,
            width: 48,
            height: 24,
            page: page,
            side: 'right',
            role: 'expansion'
        });
    }
    return slots;
}

function ajax(cmd, data, cb) {
    data = data || {};
    data.csrf_token = csrf;
    $.post('ajax.php?module=quickprovisioner&command=' + cmd, data, cb, 'json').fail(function() {
        console.error('AJAX failed: ' + cmd);
    });
}

function esc(t) { return $('<div>').text(t).html(); }
function fmtSize(b) { if (b < 1024) return b + ' B'; if (b < 1048576) return (b/1024).toFixed(1) + ' KB'; return (b/1048576).toFixed(1) + ' MB'; }

// ===================== DEVICES =====================
function loadDevices() {
    ajax('list_devices_with_secrets', {}, function(r) {
        if (!r.status) { $('#deviceListBody').html('<tr><td colspan="6" class="text-danger">Error: ' + esc(r.message||'') + '</td></tr>'); return; }
        var html = '';
        r.devices.forEach(function(d) {
            var sec = d.secret ? esc(d.secret) : '<span class="text-muted">N/A</span>';
            if (d.secret_source === 'Custom') sec += ' <span class="label label-info">Custom</span>';
            else if (d.secret_source === 'FreePBX') sec += ' <span class="label label-success">FreePBX</span>';
            var name = d.display_name || freepbxExtName(d.extension) || '';
            html += '<tr><td>' + esc(d.mac) + '</td><td>' + esc(d.extension) + '</td>';
            html += '<td>' + (name ? esc(name) : '<span class="text-muted">—</span>') + '</td>';
            html += '<td>' + sec + '</td><td>' + esc(d.model) + '</td>';
            html += '<td style="white-space:nowrap;">';
            html += '<button class="btn btn-xs btn-default" onclick="editDevice(' + d.id + ')" title="Edit"><i class="fa fa-pencil"></i></button> ';
            html += '<button class="btn btn-xs btn-info" onclick="rebuildDevice(' + d.id + ', false)" title="Rebuild config"><i class="fa fa-refresh"></i></button> ';
            html += '<button class="btn btn-xs btn-warning" onclick="rebuildDevice(' + d.id + ', true)" title="Rebuild + check-sync notify"><i class="fa fa-bolt"></i></button> ';
            html += '<button class="btn btn-xs btn-danger" onclick="deleteDevice(' + d.id + ')" title="Delete"><i class="fa fa-trash"></i></button>';
            html += '</td></tr>';
        });
        $('#deviceListBody').html(html || '<tr><td colspan="6" class="text-muted">No devices yet. Click Add New to get started.</td></tr>');
    });
}

function rebuildDevice(id, notify) {
    var msg = notify
        ? 'Rebuild config and send SIP check-sync to the phone?'
        : 'Mark config rebuilt (phone will pick up on next provision)?';
    if (!confirm(msg)) return;
    ajax('rebuild_device', {id: id, notify: notify ? 1 : 0}, function(r) {
        if (!r.status) { alert(r.message || 'Rebuild failed'); return; }
        var extra = '';
        if (r.notify) extra = '\nNotify: exit=' + r.notify.exit + (r.notify.output ? (' ' + r.notify.output) : '');
        alert((r.message || 'OK') + extra);
    });
}

function newDevice() {
    clearDeviceEditor();
    $('a[href="#tab-editor"]').tab('show');
}

/** Wipe editor fields without relying on form.reset() (deviceForm is a div). */
function clearDeviceEditor() {
    currentKeys = [];
    currentContacts = [];
    currentDeviceId = null;
    smartDialShortcuts = {};
    keyLabelCustomised = false;
    $('#deviceId').val('');
    $('#extension').val('');
    $('#extension_select').val('');
    $('#extension_custom').val('');
    $('#ext_sel_wrap').show();
    $('#ext_cust_wrap').hide();
    $('#sip_secret_preview').val('');
    $('#sip_secret_custom').val('');
    $('#custom_sip_secret').val('');
    $('#secret_prev_wrap').show();
    $('#secret_cust_wrap').hide();
    $('#model').val('');
    $('#mac').val('');
    $('#prov_username').val('');
    $('#prov_password').val('');
    $('#custom_template_override').val('');
    $('#wallpaper_mode').val('crop');
    $('#wp_mode_sel').val('crop');
    $('#wp_layout_sel').val('around_keys');
    $('#deviceOptions').html('<p class="text-muted">Select a model to view settings.</p>');
    $('#rightColHeader').text('Select a Model to Load Template');
    clearWallpaper();
    renderPreview();
}

function editDevice(id) {
    currentDeviceId = id;
    ajax('get_device', {id: id}, function(r) {
        if (!r.status || !r.data) return;
        var d = r.data;
        applyDeviceToEditor(d);
    });
    $('a[href="#tab-editor"]').tab('show');
}

/** Populate the editor from a device row (edit or post-save refresh). */
function applyDeviceToEditor(d) {
    if (!d) return;
    currentDeviceId = d.id || currentDeviceId;
    $('#deviceId').val(d.id || '');
    $('#mac').val(d.mac || '');

    var found = false;
    $('#extension_select option').each(function() {
        if ($(this).val() === d.extension) { found = true; return false; }
    });
    if (found) {
        $('#extension_select').val(d.extension);
        $('#extension').val(d.extension);
        $('#ext_sel_wrap').show();
        $('#ext_cust_wrap').hide();
    } else {
        $('#extension_custom').val(d.extension || '');
        $('#extension').val(d.extension || '');
        $('#ext_sel_wrap').hide();
        $('#ext_cust_wrap').show();
    }

    $('#custom_sip_secret').val(d.custom_sip_secret || '');
    loadSipSecret();

    var model = d.model || '';
    $('#model').val(model);
    try { currentKeys = JSON.parse(d.keys_json) || []; } catch (e) { currentKeys = []; }
    try { currentContacts = JSON.parse(d.contacts_json) || []; } catch (e) { currentContacts = []; }
    try {
        var opts = JSON.parse(d.custom_options_json) || {};
        if (opts.smart_dial_shortcuts) smartDialShortcuts = JSON.parse(opts.smart_dial_shortcuts);
        else smartDialShortcuts = {};
    } catch (e) { smartDialShortcuts = {}; }

    $('#wallpaper').val(d.wallpaper || '');
    updateWpPreview(d.wallpaper);
    $('#wallpaper_mode').val(d.wallpaper_mode || 'crop');
    $('#wp_mode_sel').val(d.wallpaper_mode || 'crop');
    $('#prov_username').val(d.prov_username || '');
    $('#prov_password').val(d.prov_password || '');
    $('#custom_template_override').val(d.custom_template_override || '');

    loadProfile(function() {
        // Re-assert identity fields after async profile rebuild (prevents wipe races)
        $('#deviceId').val(d.id || '');
        $('#mac').val(d.mac || '');
        $('#extension').val(d.extension || '');
        if (found) $('#extension_select').val(d.extension);
        else $('#extension_custom').val(d.extension || '');
        $('#model').val(model);

        var co = {};
        try { co = JSON.parse(d.custom_options_json) || {}; } catch (e) {}
        for (var k in co) {
            $('[name="custom_options[' + k + ']"]').val(co[k]);
        }
        if (co.wallpaper_layout) $('#wp_layout_sel').val(co.wallpaper_layout);
        else $('#wp_layout_sel').val('around_keys');
        onWallpaperLayoutChange();
        if (d.wallpaper) updateWpPreview(d.wallpaper);
        renderPreview();
    });
}

function deleteDevice(id) {
    if (!confirm('Delete this device?')) return;
    ajax('delete_device', {id: id}, function(r) {
        if (r.status) loadDevices(); else alert('Error: ' + r.message);
    });
}

// ===================== TEMPLATES & PROFILE =====================
function loadTemplateList() {
    ajax('list_drivers', {}, function(r) {
        if (!r.status) return;
        var html = '';
        r.list.forEach(function(t) {
            var key = t.filename || (t.model + '.mustache');
            var keyJs = String(key).replace(/\\/g, '\\\\').replace(/'/g, "\\'");
            var active = (editingTemplateFile === key) ? ' style="background:#eef8ee;"' : '';
            html += '<tr' + active + '>';
            html += '<td><strong>' + esc(t.display_name) + '</strong><br><small class="text-muted">' + esc(key) + '</small></td>';
            html += '<td>' + esc(t.manufacturer) + '</td>';
            html += '<td><small>' + esc((t.supported_models||[]).join(', ')) + '</small></td>';
            html += '<td style="white-space:nowrap;">';
            html += '<button type="button" class="btn btn-xs btn-primary" onclick="editTemplateFile(\'' + keyJs + '\')" title="Load into editor"><i class="fa fa-pencil"></i> Edit</button> ';
            html += '<button type="button" class="btn btn-xs btn-danger" onclick="deleteTemplate(\'' + keyJs + '\')" title="Delete"><i class="fa fa-trash"></i></button>';
            html += '</td></tr>';
        });
        $('#templatesList').html(html || '<tr><td colspan="4" class="text-muted">No templates installed.</td></tr>');
    });
}

function loadModelDropdown() {
    ajax('list_drivers', {}, function(r) {
        if (!r.status) return;
        var prev = $('#model').val();
        // Group by manufacturer for optgroups
        var groups = {};
        r.list.forEach(function(t) {
            var mfr = t.manufacturer || 'Other';
            if (!groups[mfr]) groups[mfr] = [];
            groups[mfr].push(t);
        });
        var html = '<option value="">-- Select Model --</option>';
        var mfrOrder = ['Yealink', 'Polycom', 'Poly', 'Cisco'];
        var seen = {};
        // Ordered manufacturers first
        mfrOrder.forEach(function(mfr) {
            if (!groups[mfr]) return;
            seen[mfr] = true;
            html += '<optgroup label="' + esc(mfr) + '">';
            groups[mfr].forEach(function(t) {
                // Add each supported model as a separate option
                if (t.supported_models && t.supported_models.length > 0) {
                    t.supported_models.forEach(function(m) {
                        html += '<option value="' + esc(m) + '">' + esc(m) + '</option>';
                    });
                } else {
                    html += '<option value="' + esc(t.model) + '">' + esc(t.display_name) + '</option>';
                }
            });
            html += '</optgroup>';
        });
        // Remaining manufacturers
        Object.keys(groups).forEach(function(mfr) {
            if (seen[mfr]) return;
            html += '<optgroup label="' + esc(mfr) + '">';
            groups[mfr].forEach(function(t) {
                if (t.supported_models && t.supported_models.length > 0) {
                    t.supported_models.forEach(function(m) {
                        html += '<option value="' + esc(m) + '">' + esc(m) + '</option>';
                    });
                } else {
                    html += '<option value="' + esc(t.model) + '">' + esc(t.display_name) + '</option>';
                }
            });
            html += '</optgroup>';
        });
        $('#model').html(html);
        // Preserve selection — rebuilding options used to wipe model mid-edit
        if (prev) $('#model').val(prev);
        modelDropdownReady = true;
    });
}

function loadProfile(afterCb) {
    var model = $('#model').val();
    if (!model) return;
    var seq = ++profileLoadSeq;
    // Snapshot identity so a late callback cannot leave the editor blank
    var snap = {
        deviceId: $('#deviceId').val(),
        extension: $('#extension').val(),
        mac: $('#mac').val(),
        model: model
    };
    ajax('get_driver', {model: model}, function(r) {
        if (seq !== profileLoadSeq) return; // stale response
        if (!r.status) { alert('Error: ' + r.message); return; }
        profiles[model] = r.meta;
        templateSources[model] = r.source || '';
        if (!profiles[model].visual_editor) {
            profiles[model].visual_editor = generateVisualEditor(model, profiles[model]);
        } else if (!profiles[model].visual_editor.total_pages) {
            var mp = 1;
            (profiles[model].visual_editor.keys || []).forEach(function(k) { if (k.page && k.page > mp) mp = k.page; });
            profiles[model].visual_editor.total_pages = mp;
        }
        // Per-model key count (e.g. VVX1500=6) — trim shared-family key maps
        // Clone visual_editor before mutating so cached META is not permanently trimmed
        if (profiles[model].visual_editor && !profiles[model]._ve_base) {
            profiles[model]._ve_base = JSON.parse(JSON.stringify(profiles[model].visual_editor));
        }
        if (profiles[model]._ve_base) {
            profiles[model].visual_editor = JSON.parse(JSON.stringify(profiles[model]._ve_base));
        }
        applyModelKeyLimit(model, profiles[model]);
        var dn = profiles[model].display_name || model;
        $('#rightColHeader').html('<i class="fa fa-check-circle text-success"></i> ' + esc(dn) + ' Template Loaded');
        loadDeviceOptions();
        updatePageSelect();
        updateScreenDims();
        // Restore identity if something else cleared it during the ajax round-trip
        if (snap.deviceId && !$('#deviceId').val()) $('#deviceId').val(snap.deviceId);
        if (snap.extension && !$('#extension').val()) {
            $('#extension').val(snap.extension);
            if ($('#extension_select option[value="' + String(snap.extension).replace(/"/g, '\\"') + '"]').length) {
                $('#extension_select').val(snap.extension);
            }
        }
        if (snap.mac && !$('#mac').val()) $('#mac').val(snap.mac);
        if (snap.model && $('#model').val() !== snap.model) $('#model').val(snap.model);
        onWallpaperLayoutChange();
        renderPreview();
        loadWpGallery();
        if (typeof afterCb === 'function') afterCb();
    });
}

/** Limit visual-editor keys to model_key_counts[model] and adapt VVX1500 layout. */
function applyModelKeyLimit(model, profile) {
    if (!profile || !profile.visual_editor) return;
    var ve = profile.visual_editor;
    var counts = ve.model_key_counts || {};
    var limit = parseInt(counts[model], 10) || 0;
    if (limit > 0 && Array.isArray(ve.keys)) {
        ve.keys = ve.keys.filter(function(k) { return (k.index || 0) <= limit; });
        ve.keys_per_page = Math.min(ve.keys_per_page || limit, limit);
        ve.total_pages = 1;
    }
    // VVX1500 is landscape touchscreen — remap to a usable right-column layout
    if (model === 'VVX1500' || model === 'VVX 1500') {
        ve.schematic = {
            chassis_width: 520, chassis_height: 320,
            screen_x: 24, screen_y: 28, screen_width: 360, screen_height: 240
        };
        var n = limit > 0 ? limit : 6;
        var keys = [];
        var top = 36, gap = 34, kh = 28, kw = 92;
        var left = ve.schematic.screen_x + ve.schematic.screen_width + 12;
        for (var i = 1; i <= n; i++) {
            keys.push({
                index: i, x: left, y: top + (i - 1) * gap,
                width: kw, height: kh, page: 1, side: 'right'
            });
        }
        ve.keys = keys;
        ve.keys_per_page = n;
        ve.total_pages = 1;
        ve.soft_keys = [
            {label: 'New Call', x: 40, y: 278, width: 70, height: 20},
            {label: 'Forward', x: 120, y: 278, width: 70, height: 20},
            {label: 'MyStat', x: 200, y: 278, width: 70, height: 20},
            {label: 'Buddies', x: 280, y: 278, width: 70, height: 20}
        ];
        // Drop portrait chassis SVG so landscape schematic is used
        ve.chassis_svg_b64 = '';
        ve.chassis_svg = '';
        ve.svg_fallback = true;
    }
}

function loadDeviceOptions() {
    var model = $('#model').val();
    var p = profiles[model];
    var html = '';
    if (!p || !p.variables || p.variables.length === 0) {
        $('#settingsToolbar').attr('hidden', true);
        $('#samplePresetNote').hide();
        $('#deviceOptions').html('<p class="text-muted">No configurable settings in this template.</p>');
        return;
    }
    $('#settingsToolbar').removeAttr('hidden');
    if (p.sample_preset && p.sample_preset.label) {
        $('#samplePresetNote').text('Sample preset available: ' + p.sample_preset.label + (p.sample_preset.notes ? ' — ' + p.sample_preset.notes : '') + ' Use “Load Sample Buttons” above.').show();
    } else {
        $('#samplePresetNote').hide();
    }

    // Build category lookup
    var catDefs = {}, catOrder = [];
    if (p.categories && p.categories.length) {
        p.categories.sort(function(a,b) { return (a.order||0) - (b.order||0); });
        p.categories.forEach(function(c) { catDefs[c.id] = c; catOrder.push(c.id); });
    }
    var cats = {};
    p.variables.forEach(function(v) {
        var c = v.category || 'other';
        if (!cats[c]) cats[c] = [];
        cats[c].push(v);
    });
    Object.keys(cats).forEach(function(c) { if (catOrder.indexOf(c) === -1) catOrder.push(c); });

    var boolVars = {
        auto_answer:1, dnd_enabled:1, call_waiting:1, web_ui_enabled:1,
        cdp_lldp_enabled:1, dst_enable:1, kid_friendly_mode:1
    };
    var selectVars = {
        transport: ['UDP','TCP','TLS','DNS-SRV'],
        debug_level_cisco: ['EMERGENCY','ALERT','CRITICAL','ERROR','WARNING','NOTICE','INFO','DEBUG']
    };

    catOrder.forEach(function(cat) {
        if (!cats[cat]) return;
        var cd = catDefs[cat];
        var label = cd ? cd.label : (cat.charAt(0).toUpperCase() + cat.slice(1));
        var icon = cd && cd.icon ? cd.icon + ' ' : '';
        var cid = 'cat_' + cat;

        html += '<div class="panel panel-default">';
        html += '<div class="panel-heading" style="cursor:pointer;" onclick="$(\'#' + cid + '\').collapse(\'toggle\')">';
        html += '<h4 class="panel-title">' + icon + '<i class="fa fa-chevron-down"></i> ' + esc(label) + '</h4>';
        html += '</div>';
        html += '<div id="' + cid + '" class="panel-collapse collapse in"><div class="panel-body">';

        cats[cat].forEach(function(v) {
            var name = v.name;
            var ph = v.example ? v.example : (v.default ? 'Default: ' + v.default : '');
            var defVal = v.default || '';
            html += '<div class="form-group" data-var="' + esc(name) + '">';
            html += '<label>' + esc(name);
            if (v.example) {
                html += ' <button type="button" class="btn btn-link btn-xs qp-load-one-example" data-var="' + esc(name) + '" title="Load example: ' + esc(v.example) + '">example</button>';
            }
            html += '</label>';

            // Cisco Yes/No style bools
            var isCiscoBool = (defVal === 'Yes' || defVal === 'No' || v.example === 'Yes' || v.example === 'No');
            if (boolVars[name] && !isCiscoBool) {
                html += '<select name="custom_options[' + esc(name) + ']" class="form-control">';
                html += '<option value="1"' + (defVal === '1' ? ' selected' : '') + '>Enabled (1)</option>';
                html += '<option value="0"' + (defVal !== '1' ? ' selected' : '') + '>Disabled (0)</option>';
                html += '</select>';
            } else if (boolVars[name] && isCiscoBool) {
                html += '<select name="custom_options[' + esc(name) + ']" class="form-control">';
                html += '<option value="Yes"' + (defVal === 'Yes' ? ' selected' : '') + '>Yes</option>';
                html += '<option value="No"' + (defVal !== 'Yes' ? ' selected' : '') + '>No</option>';
                html += '</select>';
            } else if (name === 'transport') {
                html += '<select name="custom_options[' + esc(name) + ']" class="form-control">';
                selectVars.transport.forEach(function(opt) {
                    html += '<option value="' + opt + '"' + (defVal === opt ? ' selected' : '') + '>' + opt + '</option>';
                });
                html += '</select>';
            } else if (name === 'ringtone_url' || name === 'firmware_url') {
                html += '<div class="input-group">';
                html += '<input type="text" name="custom_options[' + esc(name) + ']" class="form-control" placeholder="' + esc(ph) + '" value="' + esc(defVal) + '">';
                html += '<span class="input-group-btn"><button type="button" class="btn btn-default qp-pick-asset" data-var="' + esc(name) + '" data-kind="' + (name === 'ringtone_url' ? 'ringtone' : 'firmware') + '"><i class="fa fa-folder-open"></i></button></span>';
                html += '</div>';
                if (name === 'firmware_url') {
                    html += '<div style="margin-top:6px;">';
                    html += '<button type="button" class="btn btn-xs btn-default qp-stage-fw" data-model="T54W" data-file="T5XW-96.86.0.81.rom" title="Set T54W stage 1 firmware URL">T54W Stage 1</button> ';
                    html += '<button type="button" class="btn btn-xs btn-default qp-stage-fw" data-model="T54W" data-file="T5XW-96.87.0.16.rom" title="Set T54W stage 2 firmware URL">T54W Stage 2</button>';
                    html += '</div>';
                }
            } else {
                html += '<input type="text" name="custom_options[' + esc(name) + ']" class="form-control" placeholder="' + esc(ph) + '" value="' + esc(defVal) + '">';
            }

            if (v.description) {
                html += '<small class="help-block text-muted">' + esc(v.description);
                if (v.example) html += ' <em>(e.g. ' + esc(v.example) + ')</em>';
                html += '</small>';
            } else if (v.example) {
                html += '<small class="help-block text-muted">Example: ' + esc(v.example) + '</small>';
            }
            html += '</div>';
        });

        html += '</div></div></div>';
    });
    $('#deviceOptions').html(html);
}

function _setCustomOption(name, value) {
    var $el = $('[name="custom_options[' + name + ']"]');
    if ($el.length) $el.val(value);
}

function buildProvisionAssetUrl(kind, filename) {
    var p = '';
    if (kind === 'firmware') p = 'firmware';
    else if (kind === 'ringtone') p = 'ringtones';
    else p = kind;
    return window.location.origin + '/admin/modules/quickprovisioner/provision.php/' + p + '/' + encodeURIComponent(filename);
}

function loadTemplateExamples(mode, silent) {
    var model = $('#model').val(), p = profiles[model];
    if (!p || !p.variables) return 0;
    var filled = 0;
    p.variables.forEach(function(v) {
        var val = v.example || '';
        if (!val) return;
        var $el = $('[name="custom_options[' + v.name + ']"]');
        if (!$el.length) return;
        if (mode === 'empty' && $.trim($el.val())) return;
        $el.val(val);
        filled++;
    });
    if (!silent) {
        alert(filled ? ('Loaded ' + filled + ' example value(s).') : 'No example values available (or all fields already filled).');
    }
    return filled;
}

function loadTemplateDefaults() {
    var model = $('#model').val(), p = profiles[model];
    if (!p || !p.variables) return;
    p.variables.forEach(function(v) {
        _setCustomOption(v.name, v.default || '');
    });
}

function loadSamplePreset() {
    var model = $('#model').val(), p = profiles[model];
    if (!p || !p.sample_preset || !p.sample_preset.buttons) {
        alert('This template has no sample_preset.buttons yet.');
        return;
    }
    if (currentKeys.length && !confirm('Replace current button layout with the sample preset?')) return;
    currentKeys = p.sample_preset.buttons.map(function(b) {
        return {
            index: b.index,
            type: b.type || 'blf',
            value: b.value || '',
            full_value: b.value || '',
            label: b.label || '',
            short_dial_mode: b.short_dial_mode || 'full',
            custom_digits: b.custom_digits || 4
        };
    });
    // Also load setting examples into empty fields
    loadTemplateExamples('empty', true);
    renderPreview();
    alert('Loaded sample buttons' + (p.sample_preset.label ? (': ' + p.sample_preset.label) : '') + '. Review Settings and Save Device when ready.');
}

$(document).on('click', '.qp-load-one-example', function() {
    var name = $(this).data('var');
    var model = $('#model').val(), p = profiles[model];
    if (!p || !p.variables) return;
    var v = p.variables.find(function(x){ return x.name === name; });
    if (v && v.example) _setCustomOption(name, v.example);
});

$(document).on('click', '.qp-pick-asset', function() {
    var kind = $(this).data('kind');
    var varName = $(this).data('var');
    var cmd = kind === 'ringtone' ? 'list_ringtones' : 'list_firmware';
    ajax(cmd, {}, function(r) {
        if (!r.status) { alert(r.message || 'Failed to list files'); return; }
        var items = r.files || r.list || [];
        if (!items.length) { alert('No ' + kind + ' files uploaded yet. Use the File Manager tab first.'); return; }
        var names = items.map(function(it){ return (typeof it === 'string') ? it : (it.filename || it.name); });
        var pick = prompt('Enter filename to use:\n\n' + names.join('\n'), names[0]);
        if (!pick) return;
        _setCustomOption(varName, buildProvisionAssetUrl(kind, pick));
    });
});

$(document).on('click', '.qp-stage-fw', function() {
    var model = $('#model').val() || '';
    if (String(model).toUpperCase() !== String($(this).data('model')).toUpperCase()) {
        alert('This staged preset is for ' + $(this).data('model') + ' only. Current model: ' + model);
        return;
    }
    var file = $(this).data('file');
    ajax('list_firmware', {}, function(r) {
        if (!r.status) { alert(r.message || 'Failed to list firmware files'); return; }
        var items = r.files || [];
        var names = items.map(function(it){ return it.filename || it.name || it; });
        if (names.indexOf(file) === -1) {
            alert('Firmware file not uploaded yet: ' + file + '\nUpload it in File Manager first.');
            return;
        }
        _setCustomOption('firmware_url', buildProvisionAssetUrl('firmware', file));
    });
});

// ===================== EXTENSION & SECRET =====================
function extSelChanged() {
    var ext = $('#extension_select').val();
    $('#extension').val(ext);
    loadSipSecret();
    autoFillBtn1(ext);
}

function custExtChanged() {
    var ext = $('#extension_custom').val();
    $('#extension').val(ext);
    $('#sip_secret_preview').val('');
    autoFillBtn1(ext);
}

function toggleCustomExt() {
    if ($('#ext_cust_wrap').is(':visible')) {
        $('#ext_cust_wrap').hide(); $('#ext_sel_wrap').show();
        $('#extension').val($('#extension_select').val());
        loadSipSecret();
    } else {
        $('#ext_sel_wrap').hide(); $('#ext_cust_wrap').show();
        $('#extension').val($('#extension_custom').val());
    }
}

function loadSipSecret() {
    var ext = $('#extension').val();
    if (!ext) { $('#sip_secret_preview').val(''); return; }
    var cs = $('#custom_sip_secret').val();
    if (cs) { $('#sip_secret_preview').val(cs + ' (Custom)'); return; }
    ajax('get_sip_secret', {extension: ext}, function(r) {
        $('#sip_secret_preview').val(r.status ? r.secret : 'Error: ' + r.message);
    });
}

function toggleCustomSecret() {
    if ($('#secret_cust_wrap').is(':visible')) {
        $('#secret_cust_wrap').hide(); $('#secret_prev_wrap').show();
        loadSipSecret();
    } else {
        $('#secret_prev_wrap').hide(); $('#secret_cust_wrap').show();
        var cs = $('#custom_sip_secret').val();
        if (cs) $('#sip_secret_custom').val(cs);
        else {
            var pv = $('#sip_secret_preview').val();
            if (pv && pv.indexOf('Error:') === -1) $('#sip_secret_custom').val(pv.replace(' (Custom)', ''));
        }
        $('#sip_secret_custom').focus();
    }
}

function saveCustomSecret() {
    var s = $('#sip_secret_custom').val().trim();
    if (!s) { alert('Enter a secret'); return; }
    $('#custom_sip_secret').val(s);
    $('#sip_secret_preview').val(s + ' (Custom)');
    $('#secret_cust_wrap').hide(); $('#secret_prev_wrap').show();
}

function autoFillBtn1(ext) {
    if (!ext) return;
    var name = '';
    var $opt = $('#extension_select option[value="' + String(ext).replace(/"/g, '\\"') + '"]');
    if ($opt.length) name = $.trim($opt.attr('data-name') || '');
    var lineLabel = name || ext;
    var b1 = currentKeys.find(function(k) { return k.index === 1; });
    if (!b1) { currentKeys.push({index:1, type:'line', label:lineLabel, value:ext}); }
    else if (!b1.type) { b1.type='line'; b1.label=lineLabel; b1.value=ext; }
    else if (b1.type === 'line' && (!b1.label || b1.label === ext) && name) {
        // Refresh placeholder "102" labels with FreePBX name when available
        b1.label = name;
        b1.value = ext;
    }
    renderPreview();
}

function copyText(id) {
    var t = document.getElementById(id).value;
    if (!t) return;
    if (navigator.clipboard) { navigator.clipboard.writeText(t).catch(function(){}); }
    else { var ta = document.createElement('textarea'); ta.value = t; ta.style.position='fixed'; ta.style.opacity='0'; document.body.appendChild(ta); ta.select(); try { document.execCommand('copy'); } catch(e){} document.body.removeChild(ta); }
}

function genProvPass() {
    var c = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789', p = '';
    if (window.crypto) { var r = new Uint8Array(16); crypto.getRandomValues(r); for (var i=0;i<16;i++) p += c.charAt(r[i] % c.length); }
    else { for (var i=0;i<16;i++) p += c.charAt(Math.floor(Math.random()*c.length)); }
    $('#prov_password').val(p);
}

/** Slugify a display name for use in provisioning usernames. */
function qpSlugName(name) {
    return String(name || '')
        .toLowerCase()
        .normalize('NFKD')
        .replace(/[\u0300-\u036f]/g, '')
        .replace(/[^a-z0-9]+/g, '')
        .replace(/^-+|-+$/g, '')
        .substring(0, 24);
}

/**
 * Generate qsetup-style provisioning username: {ext}_{nameSlug}
 * Falls back to ext{ext} when no FreePBX name is available.
 */
function genProvUser() {
    var ext = ($('#extension').val() || '').trim();
    if (!ext) {
        // Prefer select, then custom field
        ext = ($('#extension_select').val() || $('#extension_custom').val() || '').trim();
    }
    if (!ext) {
        alert('Select or enter an extension first');
        return;
    }
    var name = '';
    var $opt = $('#extension_select option:selected');
    if ($opt.length && $opt.val() === ext) {
        name = $opt.attr('data-name') || '';
    }
    var slug = qpSlugName(name);
    var user = slug ? (ext + '_' + slug) : ('ext' + ext);
    // Keep usernames conservative for phone Basic Auth / URL embedding
    user = user.replace(/[^A-Za-z0-9._-]/g, '').substring(0, 48);
    $('#prov_username').val(user);
}

// ===================== FORM SUBMIT =====================
function saveDevice() {
    // Keep extension hidden in sync with visible picker
    if (!$('#extension').val()) {
        var ext = ($('#extension_select').val() || $('#extension_custom').val() || '').trim();
        if (ext) $('#extension').val(ext);
    }
    var ext = $('#extension').val();
    if (!ext) { alert('Select or enter an extension'); return; }
    if (!$('#mac').val()) { alert('Enter a MAC address'); return; }
    if (!$('#model').val()) { alert('Select a model'); return; }

    var fd = $('#deviceForm').find('input,select,textarea').serializeArray();
    if (Object.keys(smartDialShortcuts).length > 0)
        fd.push({name:'custom_options[smart_dial_shortcuts]', value:JSON.stringify(smartDialShortcuts)});

    ajax('save_device', {
        data: $.param(fd),
        keys_json: JSON.stringify(currentKeys),
        contacts_json: JSON.stringify(currentContacts)
    }, function(r) {
        if (!r.status) { alert('Error: ' + r.message); return; }
        // Stay on the same handset — clearing after save was wiping in-progress edits
        if (r.id) {
            $('#deviceId').val(r.id);
            currentDeviceId = r.id;
        }
        loadDevices();
        alert('Saved!');
    });
}

// Block accidental Enter-key submits if FreePBX wraps this page in a parent <form>
$(document).on('keydown', '#deviceForm input, #deviceForm select, #deviceForm textarea', function(e) {
    if (e.key === 'Enter' || e.keyCode === 13) {
        if (this.tagName === 'TEXTAREA') return;
        e.preventDefault();
        return false;
    }
});

// Keep nested editor tabs from fighting FreePBX / parent Bootstrap tabs
$(document).on('click', '#editorSubTabs a[data-qp-subtab]', function(e) {
    e.preventDefault();
    e.stopPropagation();
    $(this).tab('show');
});

// ===================== CONFIG PREVIEW =====================
function previewConfig() {
    if (!currentDeviceId) return alert('Save device first');
    ajax('preview_config', {id: currentDeviceId}, function(r) {
        if (r.status) { $('#configPreview').val(r.config); $('#configModal').modal('show'); }
        else alert('Error: ' + r.message);
    });
}

// ===================== VISUAL EDITOR =====================
function generateVisualEditor(model, profile) {
    var maxK = profile.max_line_keys || 29;
    var perPage = 10;
    var pages = Math.ceil(maxK / perPage);
    var sch = {chassis_width:340, chassis_height:540, screen_x:65, screen_y:58, screen_width:210, screen_height:150};
    var keys = [], idx = 1;
    for (var pg=1; pg<=pages; pg++) {
        for (var i=0; i<5 && idx<=maxK; i++) { keys.push({index:idx, x:12, y:66+i*28, width:44, height:24, page:pg, side:'left'}); idx++; }
        for (var i=0; i<5 && idx<=maxK; i++) { keys.push({index:idx, x:286, y:66+i*28, width:44, height:24, page:pg, side:'right'}); idx++; }
    }
    return {svg_fallback:true, expandable_layout:false, schematic:sch, keys_per_page:perPage, total_pages:pages, keys:keys};
}

function generatePhoneSVG(sch, name, page, total) {
    var w=sch.chassis_width, h=sch.chassis_height, sx=sch.screen_x, sy=sch.screen_y, sw=sch.screen_width, sh=sch.screen_height;
    var ncx=w/2, ncy=sy+sh+70, nr=40;
    var svg = '<svg width="'+w+'" height="'+h+'" xmlns="http://www.w3.org/2000/svg">';
    svg += '<defs><linearGradient id="cbg" x1="0%" y1="0%" x2="0%" y2="100%"><stop offset="0%" style="stop-color:#555"/><stop offset="50%" style="stop-color:#3a3a3a"/><stop offset="100%" style="stop-color:#2a2a2a"/></linearGradient>';
    svg += '<linearGradient id="sbg" x1="0%" y1="0%" x2="0%" y2="100%"><stop offset="0%" style="stop-color:#1a2a3a"/><stop offset="100%" style="stop-color:#0a1520"/></linearGradient></defs>';
    svg += '<rect width="'+w+'" height="'+h+'" fill="url(#cbg)" rx="18"/>';
    svg += '<rect x="'+(sx-4)+'" y="'+(sy-4)+'" width="'+(sw+8)+'" height="'+(sh+8)+'" fill="#111" rx="4"/>';
    svg += '<rect x="'+sx+'" y="'+sy+'" width="'+sw+'" height="'+sh+'" fill="url(#sbg)" rx="2"/>';
    for (var i=0;i<5;i++) { var ky=66+i*28; svg += '<rect x="10" y="'+ky+'" width="48" height="24" fill="#2a2a2a" stroke="#444" stroke-width="0.5" rx="3"/>'; svg += '<rect x="'+(w-58)+'" y="'+ky+'" width="48" height="24" fill="#2a2a2a" stroke="#444" stroke-width="0.5" rx="3"/>'; }
    if (total > 1) svg += '<text x="'+(sx+sw/2)+'" y="'+(sy+sh-8)+'" fill="#6a9ab5" font-size="11" text-anchor="middle">Page '+page+'/'+total+'</text>';
    svg += '<text x="'+(sx+sw/2)+'" y="'+(sy+18)+'" fill="#4a6a7a" font-size="12" text-anchor="middle">'+(name||'Phone')+'</text>';
    svg += '<circle cx="'+ncx+'" cy="'+ncy+'" r="'+nr+'" fill="#3a3a3a" stroke="#555" stroke-width="1.5"/>';
    svg += '<circle cx="'+ncx+'" cy="'+ncy+'" r="15" fill="#444" stroke="#666"/>';
    svg += '<text x="'+ncx+'" y="'+(ncy+4)+'" fill="#aaa" font-size="10" font-weight="bold" text-anchor="middle">OK</text>';
    svg += '<text x="'+(w/2)+'" y="'+(h-14)+'" fill="#555" font-size="11" font-weight="bold" text-anchor="middle" letter-spacing="2">'+(name||'PHONE').toUpperCase()+'</text>';
    svg += '</svg>';
    return svg;
}

function updatePageSelect() {
    var model = $('#model').val(), p = profiles[model];
    if (!p || !p.visual_editor) return;
    var ve = p.visual_editor;
    var em = ve.expansion_modules;
    var ltHtml = '<option value="phone">Phone DSS keys</option>';
    if (em && em.supported) {
        var maxM = em.max_modules || 3;
        for (var mi = 1; mi <= maxM; mi++) {
            ltHtml += '<option value="exp:' + mi + '">Expansion module ' + mi + '</option>';
        }
        $('#layoutTargetGrp').show();
    } else {
        $('#layoutTargetGrp').hide();
        layoutTarget = 'phone';
    }
    $('#layoutTarget').html(ltHtml);
    if ($('#layoutTarget option[value="' + layoutTarget + '"]').length) {
        $('#layoutTarget').val(layoutTarget);
    } else {
        layoutTarget = 'phone';
        $('#layoutTarget').val('phone');
    }

    var tgt = parseLayoutTarget();
    if (ve.expandable_layout && tgt.role === 'line') {
        $('#pageSelectorGrp').hide();
        isExpandedView = false;
    } else {
        $('#pageSelectorGrp').show();
        var mp = 1;
        if (tgt.role === 'expansion' && em) {
            mp = em.pages_per_module || Math.ceil((em.keys_per_module || 60) / (em.keys_per_page || 20)) || 3;
        } else {
            var pp = ve.keys_per_page || 10;
            if (ve.model_info && ve.model_info[model] && ve.model_info[model].keys_per_page) {
                pp = ve.model_info[model].keys_per_page;
            }
            var mk = p.max_line_keys || 29;
            if (ve.model_info && ve.model_info[model] && ve.model_info[model].max_keys) {
                mk = ve.model_info[model].max_keys;
            }
            mp = ve.total_pages || Math.ceil(mk / pp);
            (ve.keys || []).forEach(function(k) { if (k.page && k.page > mp) mp = k.page; });
            ve.total_pages = mp;
        }
        var h = '';
        for (var i = 1; i <= mp; i++) h += '<option value="' + i + '">Page ' + i + '</option>';
        $('#pageSelect').html(h);
    }
    // Bootstrap URL hint (TIPT-style)
    var mac = ($('#mac').val() || '').replace(/[^0-9A-Fa-f]/g, '');
    if (mac.length === 12) {
        $('#bootstrapHint').html('Bootstrap URL: <code>…/bootstrap.php?mac=' + esc(mac.toUpperCase()) + '</code> — handset pulls a tiny redirect cfg then full provision (qsetup / DHCP option 66 friendly).').show();
    } else {
        $('#bootstrapHint').text('Save a MAC to see the bootstrap.php URL for zero-touch / qsetup onboarding.').show();
    }
}

function onLayoutTargetChange() {
    layoutTarget = $('#layoutTarget').val() || 'phone';
    updatePageSelect();
    renderPreview();
}

function clearAllButtons() {
    var tgt = parseLayoutTarget();
    var msg = tgt.role === 'expansion'
        ? ('Clear all expansion module ' + tgt.module + ' keys?')
        : 'Clear all programmed phone buttons?';
    if (currentKeys.length && !confirm(msg)) return;
    if (tgt.role === 'expansion') {
        currentKeys = currentKeys.filter(function(k) {
            return !((k.role || 'line') === 'expansion' && (parseInt(k.module, 10) || 0) === tgt.module);
        });
    } else {
        currentKeys = currentKeys.filter(function(k) {
            return (k.role || 'line') === 'expansion';
        });
    }
    renderPreview();
}

function autofillButtonsFromFreepbx(mode) {
    var model = $('#model').val(), p = profiles[model];
    if (!p || !p.visual_editor) {
        alert('Load a model with visual_editor keys first.');
        return;
    }
    var ve = p.visual_editor;
    var tgt = parseLayoutTarget();
    var slots;
    if (tgt.role === 'expansion') {
        var em = ve.expansion_modules || {};
        var pages = em.pages_per_module || 3;
        slots = [];
        for (var pg = 1; pg <= pages; pg++) {
            slots = slots.concat(buildExpansionSlots(em, pg));
        }
    } else {
        if (!ve.keys || !ve.keys.length) {
            alert('This model has no phone DSS key slots.');
            return;
        }
        slots = (ve.keys || []).slice().sort(function(a, b) { return (a.index || 0) - (b.index || 0); });
    }
    var selfExt = String($('#extension').val() || '');
    ajax('list_freepbx_extensions', {}, function(r) {
        if (!r.status) { alert(r.message || 'Failed to load FreePBX extensions'); return; }
        var exts = (r.extensions || []).filter(function(e) {
            return String(e.extension) !== selfExt;
        });
        if (!exts.length) { alert('No other FreePBX extensions to map.'); return; }

        if (mode === 'all') {
            if (!confirm(tgt.role === 'expansion'
                ? ('Replace expansion module ' + tgt.module + ' keys with FreePBX BLFs?')
                : 'Replace all phone buttons with FreePBX BLFs?')) return;
            if (tgt.role === 'expansion') {
                currentKeys = currentKeys.filter(function(k) {
                    return !((k.role || 'line') === 'expansion' && (parseInt(k.module, 10) || 0) === tgt.module);
                });
            } else {
                currentKeys = currentKeys.filter(function(k) {
                    return (k.role || 'line') === 'expansion';
                });
            }
        }

        var used = {};
        currentKeys.forEach(function(k) {
            var kr = k.role || 'line';
            var km = parseInt(k.module, 10) || 0;
            if (kr === tgt.role && km === tgt.module) used[k.index] = true;
        });
        var reservedLine = false;
        if (tgt.role !== 'expansion' && slots.length && selfExt) {
            var lineExists = currentKeys.some(function(k) {
                var kr = k.role || 'line';
                var km = parseInt(k.module, 10) || 0;
                return kr === tgt.role && km === tgt.module && (k.type || '') === 'line';
            });
            if (!lineExists) {
                var firstSlot = slots[0].index;
                currentKeys = currentKeys.filter(function(k) {
                    var kr = k.role || 'line';
                    var km = parseInt(k.module, 10) || 0;
                    return !(k.index === firstSlot && kr === tgt.role && km === tgt.module);
                });
                currentKeys.push({
                    index: firstSlot,
                    type: 'line',
                    value: selfExt,
                    full_value: selfExt,
                    label: ($('#extension option:selected').text() || selfExt),
                    short_dial_mode: 'full',
                    custom_digits: 4,
                    role: tgt.role,
                    module: tgt.module
                });
                used[firstSlot] = true;
                reservedLine = true;
            }
        }
        var ei = 0, added = 0;
        slots.forEach(function(slot) {
            if (ei >= exts.length) return;
            if (tgt.role !== 'expansion' && slots.length && slot.index === slots[0].index) return;
            if (mode === 'empty' && used[slot.index]) return;
            var ext = exts[ei++];
            var name = ext.name || ext.extension;
            var row = {
                index: slot.index,
                type: 'blf',
                value: String(ext.extension),
                full_value: String(ext.extension),
                label: name,
                short_dial_mode: 'full',
                custom_digits: 4,
                role: tgt.role,
                module: tgt.module
            };
            currentKeys = currentKeys.filter(function(k) {
                var kr = k.role || 'line';
                var km = parseInt(k.module, 10) || 0;
                return !(k.index === slot.index && kr === tgt.role && km === tgt.module);
            });
            currentKeys.push(row);
            added++;
        });
        renderPreview();
        var extra = reservedLine ? ' Preserved key 1 as this extension line appearance.' : '';
        alert('Mapped ' + added + ' BLF button(s) from FreePBX (skipped this device\'s own extension).' + extra);
    });
}

function decodeChassisSvg(ve) {
    if (!ve) return null;
    if (ve.chassis_svg && String(ve.chassis_svg).trim()) return String(ve.chassis_svg);
    if (ve.chassis_svg_b64 && String(ve.chassis_svg_b64).trim()) {
        try {
            return atob(String(ve.chassis_svg_b64).replace(/\s+/g, ''));
        } catch (e) { return null; }
    }
    return null;
}

function renderPreview() {
    var model = $('#model').val(), p = profiles[model];
    if (!p || !p.visual_editor) return;
    var ve = p.visual_editor, page = parseInt($('#pageSelect').val()) || 1;
    var tgt = parseLayoutTarget();
    var em = ve.expansion_modules;
    var total = 1;
    if (tgt.role === 'expansion' && em) {
        total = em.pages_per_module || Math.ceil((em.keys_per_module || 60) / (em.keys_per_page || 20)) || 3;
    } else {
        total = ve.total_pages || Math.ceil((p.max_line_keys || 29) / (ve.keys_per_page || 10));
        if (ve.model_info && ve.model_info[model] && ve.model_info[model].max_keys && ve.model_info[model].keys_per_page) {
            total = Math.ceil(ve.model_info[model].max_keys / ve.model_info[model].keys_per_page);
        }
        (ve.keys || []).forEach(function(k) { if (k.page && k.page > total) total = k.page; });
    }
    var c = $('#previewContainer');
    c.empty().css({width: ve.schematic.chassis_width + 'px', height: ve.schematic.chassis_height + 'px', position: 'relative'});
    var dn = p.display_name || model;
    if (tgt.role === 'expansion') {
        dn = 'EXP' + tgt.module;
        c.css({backgroundImage: 'none', backgroundColor: '#1a1a1a'});
        $('<div>').css({
            position: 'absolute', left: 0, top: 8, width: '100%', textAlign: 'center',
            color: '#9cf', fontSize: '12px', fontWeight: 'bold', letterSpacing: '1px'
        }).text('Expansion Module ' + tgt.module + ' — Page ' + page + '/' + total).appendTo(c);
    } else {
        var chassis = decodeChassisSvg(ve);
        var svg = chassis || generatePhoneSVG(ve.schematic, dn, page, total);
        try {
            c.css({backgroundImage: 'url(data:image/svg+xml;base64,' + btoa(unescape(encodeURIComponent(svg))) + ')', backgroundSize: 'contain', backgroundRepeat: 'no-repeat', backgroundPosition: 'center top', backgroundColor: 'transparent'});
        } catch (e) {
            c.css({backgroundImage: 'url(data:image/svg+xml;base64,' + btoa(svg) + ')', backgroundSize: 'contain', backgroundRepeat: 'no-repeat', backgroundPosition: 'center top'});
        }
        var wp = $('#wallpaper').val();
        if (wp) {
            var mode = $('#wallpaper_mode').val() || 'crop';
            var wpu = buildWallpaperPreviewUrl(wp, mode);
            $('<div>').css({position: 'absolute', left: ve.schematic.screen_x + 'px', top: ve.schematic.screen_y + 'px', width: ve.schematic.screen_width + 'px', height: ve.schematic.screen_height + 'px', backgroundImage: 'url(' + wpu + ')', backgroundSize: mode === 'crop' ? 'cover' : 'contain', backgroundRepeat: 'no-repeat', backgroundPosition: 'center', borderRadius: '2px'}).appendTo(c);
        }
    }

    var kl = $('<div>').css({position: 'absolute', top: 0, left: 0, width: '100%', height: '100%'}).appendTo(c);
    var slots = tgt.role === 'expansion'
        ? buildExpansionSlots(em || {}, page)
        : (ve.keys || []);

    slots.forEach(function(key) {
        var show = true;
        if (tgt.role === 'line') {
            show = ve.expandable_layout ? (isExpandedView || key.column === 1 || key.column === 5) : (key.page === undefined || key.page === page);
        }
        if (!show) return;
        var kd = findKey(key.index, tgt.role, tgt.module);
        var has = kd && kd.type;
        var lbl = (kd && kd.label) ? kd.label : 'Key ' + key.index;
        var bg = 'rgba(80,80,80,0.8)', bc = 'rgba(150,150,150,0.5)';
        if (has) {
            switch (kd.type) {
                case 'line': bg = 'rgba(46,204,64,0.3)'; bc = 'rgba(46,204,64,0.6)'; break;
                case 'blf': bg = 'rgba(0,116,217,0.3)'; bc = 'rgba(0,116,217,0.6)'; break;
                case 'speed_dial': bg = 'rgba(255,133,27,0.3)'; bc = 'rgba(255,133,27,0.6)'; break;
                default: bg = 'rgba(177,13,201,0.3)'; bc = 'rgba(177,13,201,0.6)';
            }
        }
        $('<button>').css({position: 'absolute', left: key.x + 'px', top: key.y + 'px', width: (key.width || 44) + 'px', height: (key.height || 24) + 'px', textAlign: 'center', fontSize: '9px', padding: '2px', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap', borderRadius: '3px', border: '1px solid ' + bc, backgroundColor: bg, color: has ? '#fff' : '#aaa', cursor: 'pointer', fontWeight: has ? 'bold' : 'normal', lineHeight: ((key.height || 24) - 4) + 'px'}).text(lbl).click(function() { editKey(key.index, tgt.role, tgt.module); }).appendTo(kl);
    });

    if (tgt.role === 'line') {
        // Soft-key chrome from template (hardware under-screen keys)
        (ve.soft_keys || []).forEach(function(sk) {
            var $btn = $('<div>').css({
                position: 'absolute', left: sk.x + 'px', top: sk.y + 'px',
                width: (sk.width || 48) + 'px', height: (sk.height || 20) + 'px',
                textAlign: 'center', fontSize: '9px', lineHeight: ((sk.height || 20) - 2) + 'px',
                borderRadius: '3px', border: '1px solid rgba(150,150,150,0.45)',
                backgroundColor: 'rgba(40,40,40,0.75)', color: '#ccc', overflow: 'hidden'
            }).text(sk.label || '');
            if (sk.programmable && sk.index) {
                $btn.css({cursor: 'pointer', backgroundColor: 'rgba(60,80,100,0.85)'}).click(function() { editKey(sk.index, 'line', 0); });
            }
            $btn.appendTo(kl);
        });

        // Yealink-style page switcher (bottom-right physical key, not programmable)
        if (ve.page_switcher && total > 1) {
            var ps = ve.page_switcher;
            $('<div>').css({
                position: 'absolute', left: ps.x + 'px', top: ps.y + 'px',
                width: (ps.width || 42) + 'px', height: (ps.height || 22) + 'px',
                textAlign: 'center', fontSize: '9px', lineHeight: ((ps.height || 22) - 2) + 'px',
                borderRadius: '3px', border: '1px dashed rgba(100,180,255,0.7)',
                backgroundColor: 'rgba(30,60,90,0.85)', color: '#9cf', cursor: 'pointer'
            }).text((ps.label || 'Page') + ' ' + page + '/' + total).click(function() {
                var next = page >= total ? 1 : page + 1;
                $('#pageSelect').val(next); renderPreview();
            }).appendTo(kl);
        }

        // Page nav buttons
        if (!ve.expandable_layout && total > 1) {
            var ny = ve.schematic.screen_y + ve.schematic.screen_height + 10, nx = ve.schematic.chassis_width / 2;
            if (page > 1) $('<button>').css({position: 'absolute', left: (nx - 85) + 'px', top: ny + 'px', width: '70px', height: '24px', fontSize: '11px', borderRadius: '4px', border: '1px solid rgba(150,150,150,0.4)', backgroundColor: 'rgba(60,60,60,0.9)', color: '#ccc', cursor: 'pointer', zIndex: 1000}).html('&#9664; Prev').click(function() { $('#pageSelect').val(Math.max(1, page - 1)); renderPreview(); }).appendTo(c);
            if (page < total) $('<button>').css({position: 'absolute', left: (nx + 15) + 'px', top: ny + 'px', width: '70px', height: '24px', fontSize: '11px', borderRadius: '4px', border: '1px solid rgba(150,150,150,0.4)', backgroundColor: 'rgba(60,60,60,0.9)', color: '#ccc', cursor: 'pointer', zIndex: 1000}).html('More &#9654;').click(function() { $('#pageSelect').val(Math.min(total, page + 1)); renderPreview(); }).appendTo(c);
        }
    } else if (total > 1) {
        var eny = ve.schematic.chassis_height - 36, enx = ve.schematic.chassis_width / 2;
        if (page > 1) $('<button>').css({position: 'absolute', left: (enx - 85) + 'px', top: eny + 'px', width: '70px', height: '24px', fontSize: '11px', borderRadius: '4px', border: '1px solid rgba(150,150,150,0.4)', backgroundColor: 'rgba(60,60,60,0.9)', color: '#ccc', cursor: 'pointer', zIndex: 1000}).html('&#9664; Prev').click(function() { $('#pageSelect').val(Math.max(1, page - 1)); renderPreview(); }).appendTo(c);
        if (page < total) $('<button>').css({position: 'absolute', left: (enx + 15) + 'px', top: eny + 'px', width: '70px', height: '24px', fontSize: '11px', borderRadius: '4px', border: '1px solid rgba(150,150,150,0.4)', backgroundColor: 'rgba(60,60,60,0.9)', color: '#ccc', cursor: 'pointer', zIndex: 1000}).html('More &#9654;').click(function() { $('#pageSelect').val(Math.min(total, page + 1)); renderPreview(); }).appendTo(c);
    }
}

function qpComputeShortDial(full, mode, customDigits) {
    full = String(full || '');
    if (!full) return '';
    var digits = 0;
    if (mode === '3digit') digits = 3;
    else if (mode === '4digit') digits = 4;
    else if (mode === '5digit') digits = 5;
    else if (mode === 'custom') digits = parseInt(customDigits, 10) || 0;
    else return full;
    if (digits <= 0) return full;
    return full.length > digits ? full.slice(-digits) : full;
}

function updateKeyShortDialUi() {
    var mode = $('#keyShortDial').val() || 'full';
    $('#keyCustomDigits').toggle(mode === 'custom');
    var full = $.trim($('#keyValue').val());
    var dig = parseInt($('#keyCustomDigits').val(), 10) || 4;
    var eff = qpComputeShortDial(full, mode, dig);
    $('#keyShortDialPreview').text(mode === 'full' || !full ? '' : ('Config will dial: ' + eff));
}

function editKey(idx, role, module) {
    var tgt = parseLayoutTarget();
    role = role || tgt.role;
    module = (module !== undefined && module !== null) ? module : tgt.module;
    $('#keyIndex').text(idx);
    $('#keyRole').val(role);
    $('#keyModule').val(module);
    if (role === 'expansion') {
        $('#keyRoleHint').text('Programming expansion_module.' + module + '.key.' + idx).show();
    } else {
        $('#keyRoleHint').hide().text('');
    }
    var k = findKey(idx, role, module) || {};
    keyLabelCustomised = !!(k.label && String(k.label).length);
    var t = k.type || 'line';
    if (t === 'speeddial') t = 'speed_dial';
    $('#keyType').val(t);
    var full = k.full_value || k.fullValue || k.value || '';
    $('#keyValue').val(full);
    $('#keyLabel').val(k.label || '');
    $('#keyShortDial').val(k.short_dial_mode || k.shortDialMode || 'full');
    $('#keyCustomDigits').val(k.custom_digits || k.customDigits || 4);
    populateKeyExtSelect();
    syncKeyExtSelectFromValue();
    updateKeyExtUi();
    updateKeyLabelHint();
    updateKeyShortDialUi();
    $('#keyModal').modal('show');
}
function saveKey() {
    var idx = parseInt($('#keyIndex').text());
    var role = $('#keyRole').val() || 'line';
    var module = parseInt($('#keyModule').val(), 10) || 0;
    var t = $('#keyType').val();
    var full = $.trim($('#keyValue').val());
    var l = $.trim($('#keyLabel').val());
    var mode = $('#keyShortDial').val() || 'full';
    var dig = parseInt($('#keyCustomDigits').val(), 10) || 4;
    var v = qpComputeShortDial(full, mode, dig);
    var payload = {
        index: idx,
        type: t,
        value: v,
        full_value: full,
        label: l,
        short_dial_mode: mode,
        custom_digits: dig,
        role: role,
        module: module
    };
    var ex = findKey(idx, role, module);
    if (ex) {
        ex.type = payload.type;
        ex.value = payload.value;
        ex.full_value = payload.full_value;
        ex.label = payload.label;
        ex.short_dial_mode = payload.short_dial_mode;
        ex.custom_digits = payload.custom_digits;
        ex.role = payload.role;
        ex.module = payload.module;
    } else {
        currentKeys.push(payload);
    }
    renderPreview(); $('#keyModal').modal('hide');
}
function clearKey() {
    var idx = parseInt($('#keyIndex').text());
    var role = $('#keyRole').val() || 'line';
    var module = parseInt($('#keyModule').val(), 10) || 0;
    currentKeys = currentKeys.filter(function(k) {
        var kr = k.role || 'line';
        var km = parseInt(k.module, 10) || 0;
        return !(k.index === idx && kr === role && km === module);
    });
    renderPreview(); $('#keyModal').modal('hide');
}

function freepbxExtName(ext) {
    ext = String(ext || '');
    for (var i = 0; i < (freepbxExtensions || []).length; i++) {
        if (String(freepbxExtensions[i].extension) === ext) {
            return freepbxExtensions[i].name || '';
        }
    }
    return '';
}

function populateKeyExtSelect() {
    var $sel = $('#keyExtSelect');
    var cur = $sel.val();
    $sel.find('option:not([value=""]):not([value="__custom__"])').remove();
    (freepbxExtensions || []).forEach(function(row) {
        var ext = String(row.extension || '');
        if (!ext) return;
        var name = row.name || '';
        var label = name ? (ext + ' — ' + name) : ext;
        $sel.append($('<option>').attr('value', ext).text(label));
    });
    if (cur) $sel.val(cur);
}

function syncKeyExtSelectFromValue() {
    var v = $.trim($('#keyValue').val());
    var $sel = $('#keyExtSelect');
    if (!v) { $sel.val(''); return; }
    var found = false;
    $sel.find('option').each(function() {
        if (String($(this).attr('value')) === v) found = true;
    });
    $sel.val(found ? v : '__custom__');
}

function updateKeyExtUi() {
    var t = $('#keyType').val();
    var show = (t === 'blf' || t === 'speed_dial' || t === 'line' || t === 'transfer' || t === 'pickup' || t === 'park' || t === 'voicemail');
    $('#keyExtPickGroup').toggle(!!show);
    $('#keyShortDialGroup').toggle(t !== 'line');
}

function updateKeyLabelHint() {
    var v = $.trim($('#keyValue').val());
    var known = freepbxExtName(v);
    var $hint = $('#keyLabelHint');
    if (known) {
        $hint.text(keyLabelCustomised
            ? ('FreePBX name: ' + known + ' (label overridden)')
            : ('FreePBX name: ' + known));
        $('#keyLabelUseName').prop('disabled', false);
    } else {
        $hint.text(v ? 'No FreePBX match — enter a custom label if needed.' : 'Select an extension or type a value.');
        $('#keyLabelUseName').prop('disabled', !known);
    }
}

function applyKeyExtSelection() {
    var sel = $('#keyExtSelect').val();
    if (!sel || sel === '__custom__') {
        if (sel === '__custom__') {
            $('#keyValue').focus();
        }
        updateKeyLabelHint();
        return;
    }
    $('#keyValue').val(sel);
    var known = freepbxExtName(sel);
    if (!keyLabelCustomised) {
        $('#keyLabel').val(known || sel);
    }
    updateKeyLabelHint();
}

function refreshKeyFreepbxExtensions(cb) {
    ajax('list_freepbx_extensions', {}, function(r) {
        if (!r.status) {
            alert(r.message || 'Failed to load FreePBX extensions');
            return;
        }
        freepbxExtensions = r.extensions || [];
        populateKeyExtSelect();
        syncKeyExtSelectFromValue();
        updateKeyLabelHint();
        if (typeof cb === 'function') cb();
    });
}

$(document).on('change', '#keyType', function() {
    updateKeyExtUi();
    updateKeyShortDialUi();
});
$(document).on('change', '#keyExtSelect', function() {
    applyKeyExtSelection();
});
$(document).on('input', '#keyValue', function() {
    syncKeyExtSelectFromValue();
    updateKeyLabelHint();
    updateKeyShortDialUi();
});
$(document).on('change input', '#keyShortDial, #keyCustomDigits', function() {
    updateKeyShortDialUi();
});
$(document).on('input', '#keyLabel', function() {
    keyLabelCustomised = true;
    updateKeyLabelHint();
});
$(document).on('click', '#keyLabelUseName', function() {
    var known = freepbxExtName($.trim($('#keyValue').val()));
    if (!known) return;
    $('#keyLabel').val(known);
    keyLabelCustomised = false;
    updateKeyLabelHint();
});
$(document).on('click', '#keyExtRefresh', function() {
    refreshKeyFreepbxExtensions();
});

// Prefill extension dropdown options once DOM is ready
$(function() { populateKeyExtSelect(); });

// ===================== WALLPAPER =====================
function getModelWallpaperSpec() {
    var model = $('#model').val(), p = profiles[model];
    if (p && p.wallpaper_specs) {
        return p.wallpaper_specs[model] || p.wallpaper_specs[String(model).toUpperCase()] || null;
    }
    return null;
}

function getWallpaperInsets() {
    var layout = $('#wp_layout_sel').val() || 'around_keys';
    if (layout === 'full') {
        return { left: 0, top: 0, right: 0, bottom: 0, layout: layout };
    }
    var sp = getModelWallpaperSpec() || {};
    var defL = parseInt(sp.inset_left, 10) || 0;
    var defT = parseInt(sp.inset_top, 10) || 0;
    var defR = parseInt(sp.inset_right, 10) || 0;
    var defB = parseInt(sp.inset_bottom, 10) || 0;
    // Models with on-screen keys but no META insets: sensible VVX1500-like defaults for landscape
    if (layout === 'around_keys' && (defL + defT + defR + defB) === 0) {
        var model = ($('#model').val() || '').toUpperCase();
        if (model.indexOf('VVX1500') !== -1 || model.indexOf('VVX 1500') !== -1) {
            defL = 16; defT = 40; defR = 168; defB = 56;
        }
    }
    if (layout === 'custom') {
        return {
            left: Math.max(0, parseInt($('#wp_inset_left').val(), 10) || 0),
            top: Math.max(0, parseInt($('#wp_inset_top').val(), 10) || 0),
            right: Math.max(0, parseInt($('#wp_inset_right').val(), 10) || 0),
            bottom: Math.max(0, parseInt($('#wp_inset_bottom').val(), 10) || 0),
            layout: layout
        };
    }
    return { left: defL, top: defT, right: defR, bottom: defB, layout: layout };
}

function onWallpaperLayoutChange() {
    var layout = $('#wp_layout_sel').val() || 'around_keys';
    if (layout === 'custom') {
        var sp = getModelWallpaperSpec() || {};
        if (!$('#wp_inset_left').val()) $('#wp_inset_left').val(sp.inset_left || 16);
        if (!$('#wp_inset_top').val()) $('#wp_inset_top').val(sp.inset_top || 40);
        if (!$('#wp_inset_right').val()) $('#wp_inset_right').val(sp.inset_right || 168);
        if (!$('#wp_inset_bottom').val()) $('#wp_inset_bottom').val(sp.inset_bottom || 56);
        $('#wpInsetCustom').show();
    } else {
        $('#wpInsetCustom').hide();
    }
    updateWallpaperInsetHint();
    refreshWallpaperPreview();
}

function updateWallpaperInsetHint() {
    var ins = getWallpaperInsets();
    var dims = getWallpaperDimensions();
    var cw = Math.max(1, dims.width - ins.left - ins.right);
    var ch = Math.max(1, dims.height - ins.top - ins.bottom);
    if (ins.layout === 'full' || (ins.left + ins.top + ins.right + ins.bottom) === 0) {
        $('#wpInsetHint').text('Image fills the full ' + dims.width + '×' + dims.height + ' screen.');
    } else {
        $('#wpInsetHint').text('Image fits in a ' + cw + '×' + ch + ' content area (margins L' + ins.left + ' T' + ins.top + ' R' + ins.right + ' B' + ins.bottom + ').');
    }
}

function refreshWallpaperPreview() {
    updateWallpaperInsetHint();
    var fn = $('#wallpaper').val();
    if (fn) updateWpPreview(fn);
    renderPreview();
}

function updateScreenDims() {
    var model = $('#model').val(), p = profiles[model];
    var w=800, h=480;
    if (p && p.wallpaper_specs) {
        var sp = p.wallpaper_specs[model] || null;
        if (sp && sp.width) w = sp.width;
        if (sp && sp.height) h = sp.height;
    }
    $('#screenW').text(w); $('#screenH').text(h);
    updateWallpaperInsetHint();
}

function getWallpaperDimensions() {
    var model = $('#model').val(), p = profiles[model];
    var w = parseInt($('#screenW').text(), 10) || 800;
    var h = parseInt($('#screenH').text(), 10) || 480;
    if (p && p.wallpaper_specs) {
        var sp = p.wallpaper_specs[model] || null;
        if (sp && sp.width) w = parseInt(sp.width, 10) || w;
        if (sp && sp.height) h = parseInt(sp.height, 10) || h;
    }
    return { width: w, height: h };
}

function buildWallpaperPreviewUrl(fn, mode) {
    if (!fn) return '';
    if (fn.startsWith('http')) return fn;
    var dims = getWallpaperDimensions();
    var wpMode = mode || $('#wallpaper_mode').val() || 'crop';
    var ins = getWallpaperInsets();
    // When margins are set, force fit-into-content so logos stay clear of keys
    if ((ins.left + ins.top + ins.right + ins.bottom) > 0) wpMode = 'fit';
    return mediaEndpoint + '?file=' + encodeURIComponent(fn)
        + '&w=' + encodeURIComponent(dims.width)
        + '&h=' + encodeURIComponent(dims.height)
        + '&mode=' + encodeURIComponent(wpMode)
        + '&inset_left=' + encodeURIComponent(ins.left)
        + '&inset_top=' + encodeURIComponent(ins.top)
        + '&inset_right=' + encodeURIComponent(ins.right)
        + '&inset_bottom=' + encodeURIComponent(ins.bottom)
        + '&preview=1&t=' + Date.now();
}

function uploadWallpaper() {
    var f = $('#wpUpload')[0].files[0];
    if (!f) { alert('Select a file'); return; }
    var fd = new FormData(); fd.append('file', f); fd.append('csrf_token', csrf);
    fd.append('device_model', $('#model').val() || '');
    var model = $('#model').val(), p = profiles[model];
    if (p && p.wallpaper_specs) {
        var sp = p.wallpaper_specs[model];
        if (sp) { fd.append('resize_width', sp.width); fd.append('resize_height', sp.height); }
    }
    var ins = getWallpaperInsets();
    fd.append('inset_left', ins.left);
    fd.append('inset_top', ins.top);
    fd.append('inset_right', ins.right);
    fd.append('inset_bottom', ins.bottom);
    $.ajax({url:'ajax.php?module=quickprovisioner&command=upload_file', type:'POST', data:fd, contentType:false, processData:false, success:function(r) {
        if (r.status) { loadWpGallery(); selWp(r.url); } else alert('Error: '+r.message);
    }});
}

function setCustomWpUrl() {
    var u = $('#customWpUrl').val().trim();
    if (!u) { alert('Enter URL'); return; }
    $('#wallpaper').val(u); updateWpPreview(u); renderPreview();
}

function selWp(fn) { $('#wallpaper').val(fn); updateWpPreview(fn); renderPreview(); }
function clearWallpaper() { $('#wallpaper').val(''); $('#wpPreview').hide(); $('#wpEmpty').show(); renderPreview(); }

function updateWpPreview(fn) {
    if (fn) {
        var src = buildWallpaperPreviewUrl(fn, $('#wallpaper_mode').val() || 'crop');
        $('#wpPreviewImg')
            .attr('src', src)
            .off('error')
            .on('error', function() {
                $('#wpPreview').hide();
                $('#wpEmpty').html('<p class="text-warning">Wallpaper preview failed to render. Re-upload this image.</p>').show();
            });
        $('#wpPreview').show();
        $('#wpEmpty').hide();
    } else { $('#wpPreview').hide(); $('#wpEmpty').show(); }
}

function loadWpGallery() {
    ajax('list_assets', {}, function(r) {
        if (!r.status) return;
        var html = '';
        r.files.forEach(function(f) {
            html += '<div class="col-xs-6 col-sm-4 col-md-3" style="margin-bottom:10px;"><div class="thumbnail">';
            html += '<img src="'+mediaEndpoint+'?file='+encodeURIComponent(f.filename)+'&preview=1" style="width:100%; height:100px; object-fit:cover;" onerror="this.style.display=\'none\';this.insertAdjacentHTML(\'afterend\',\'<div class=&quot;text-warning&quot; style=&quot;padding:8px;font-size:11px;&quot;>Preview failed</div>\');">';
            html += '<div class="caption" style="font-size:10px;"><p style="word-break:break-all;">'+esc(f.filename)+'</p>';
            html += '<button type="button" class="btn btn-xs btn-primary" onclick="selWp(\''+f.filename+'\')">Select</button> ';
            html += '<button type="button" class="btn btn-xs btn-danger" onclick="delWpAsset(\''+f.filename+'\')">Del</button>';
            html += '</div></div></div>';
        });
        $('#wpGallery').html(html || '<div class="col-xs-12"><p class="text-muted">No wallpapers uploaded yet.</p></div>');
    });
}

function delWpAsset(fn) {
    if (!confirm('Delete '+fn+'?')) return;
    ajax('delete_asset', {filename:fn}, function(r) {
        if (r.status) { loadWpGallery(); if ($('#wallpaper').val()===fn) clearWallpaper(); }
        else alert('Error: '+r.message);
    });
}

// ===================== CONTACTS / PHONEBOOK =====================
function loadContacts() {
    var html = '<table class="table table-striped table-condensed"><thead><tr><th>Name</th><th>Number</th><th>Source</th><th>Actions</th></tr></thead><tbody>';
    currentContacts.forEach(function(c, i) {
        var src = c.source || 'custom';
        var badge = src === 'freepbx' ? '<span class="label label-info">FreePBX</span>' : '<span class="label label-default">Custom</span>';
        html += '<tr><td>'+esc(c.name||'')+'</td><td>'+esc(c.number||'')+'</td><td>'+badge+'</td>';
        html += '<td><button type="button" class="btn btn-xs btn-default" onclick="editContact('+i+')">Edit</button> <button type="button" class="btn btn-xs btn-danger" onclick="removeContact('+i+')">Del</button></td></tr>';
    });
    html += '</tbody></table>';
    if (!currentContacts.length) html = '<p class="text-muted">No contacts yet. Import FreePBX extensions and/or add custom contacts, then Save Device.</p>';
    $('#contactsList').html(html);
}
function addContact() {
    $('#contactIdx').text(currentContacts.length);
    $('#contactName').val('');
    $('#contactNumber').val('');
    $('#contactSource').val('custom');
    $('#contactModal').modal('show');
}
function editContact(i) {
    $('#contactIdx').text(i);
    var c = currentContacts[i] || {};
    $('#contactName').val(c.name || '');
    $('#contactNumber').val(c.number || '');
    $('#contactSource').val(c.source || 'custom');
    $('#contactModal').modal('show');
}
function saveContact() {
    var i = parseInt($('#contactIdx').text(), 10);
    var c = {
        name: ($('#contactName').val() || '').trim(),
        number: ($('#contactNumber').val() || '').trim(),
        source: $('#contactSource').val() || 'custom'
    };
    if (!c.name && !c.number) { alert('Enter a name and/or number'); return; }
    if (!c.name) c.name = c.number;
    if (i < currentContacts.length) currentContacts[i] = c; else currentContacts.push(c);
    loadContacts();
    $('#contactModal').modal('hide');
}
function removeContact(i) { if (confirm('Remove this contact?')) { currentContacts.splice(i, 1); loadContacts(); } }
function clearContact() { $('#contactName').val(''); $('#contactNumber').val(''); }
function clearAllContacts() {
    if (!currentContacts.length) return;
    if (confirm('Remove all contacts from this device?')) { currentContacts = []; loadContacts(); }
}
function _contactNumberKey(n) { return String(n || '').replace(/\s+/g, ''); }
function importFreepbxExtensions() {
    ajax('list_freepbx_extensions', {}, function(r) {
        if (!r.status) { alert(r.message || 'Failed to load extensions'); return; }
        var existing = {};
        currentContacts.forEach(function(c) { existing[_contactNumberKey(c.number)] = true; });
        var added = 0;
        (r.extensions || []).forEach(function(ext) {
            var num = String(ext.extension || '');
            if (!num || existing[_contactNumberKey(num)]) return;
            currentContacts.push({
                name: ext.name || num,
                number: num,
                source: 'freepbx'
            });
            existing[_contactNumberKey(num)] = true;
            added++;
        });
        loadContacts();
        alert(added ? ('Imported ' + added + ' FreePBX extension(s). Save the device to apply.') : 'No new extensions to import (all already present).');
    });
}
function refreshFreepbxNames() {
    ajax('list_freepbx_extensions', {}, function(r) {
        if (!r.status) { alert(r.message || 'Failed to load extensions'); return; }
        var byExt = {};
        (r.extensions || []).forEach(function(ext) { byExt[_contactNumberKey(ext.extension)] = ext.name || ext.extension; });
        var updated = 0;
        currentContacts.forEach(function(c) {
            if ((c.source || '') !== 'freepbx') return;
            var key = _contactNumberKey(c.number);
            if (byExt[key] && c.name !== byExt[key]) { c.name = byExt[key]; updated++; }
        });
        loadContacts();
        alert(updated ? ('Updated ' + updated + ' FreePBX name(s). Save the device to apply.') : 'No FreePBX names needed updating.');
    });
}

// ===================== FILE MANAGER =====================
function loadAllFiles() { loadAssets(); loadRingtones(); loadFirmware(); }

function uploadAsset() {
    var f = $('#assetUpload')[0].files[0];
    if (!f) return alert('Select file');
    var fd = new FormData(); fd.append('file', f); fd.append('csrf_token', csrf);
    $.ajax({url:'ajax.php?module=quickprovisioner&command=upload_file', type:'POST', data:fd, contentType:false, processData:false, success:function(r) { if(r.status) loadAssets(); else alert('Error: '+r.message); }});
}
function loadAssets() {
    ajax('list_assets', {}, function(r) {
        if (!r.status) return;
        var html = '';
        r.files.forEach(function(f) {
            html += '<div class="col-xs-6 col-sm-4" style="margin-bottom:10px;"><div class="thumbnail">';
            html += '<img src="'+mediaEndpoint+'?file='+encodeURIComponent(f.filename)+'&preview=1" style="width:100%; height:80px; object-fit:cover;">';
            html += '<div class="caption" style="font-size:10px;"><p>'+esc(f.filename)+'</p><p>'+fmtSize(f.size)+'</p>';
            html += '<button class="btn btn-xs btn-danger" onclick="deleteAsset(\''+esc(f.filename).replace(/'/g,"\\'")+'\')">Delete</button>';
            html += '</div></div></div>';
        });
        $('#assetGrid').html(html);
    });
}
function deleteAsset(fn) { if(!confirm('Delete '+fn+'?')) return; ajax('delete_asset', {filename:fn}, function(r) { if(r.status) loadAssets(); else alert(r.message); }); }

function uploadRingtone() {
    var f = document.getElementById('ringtoneUpload');
    if (!f.files[0]) return alert('Select file');
    var fd = new FormData(); fd.append('file', f.files[0]); fd.append('csrf_token', csrf);
    $.ajax({url:'ajax.php?module=quickprovisioner&command=upload_ringtone', type:'POST', data:fd, contentType:false, processData:false, dataType:'json', success:function(r) { if(r.status) { f.value=''; loadRingtones(); } else alert(r.message); }});
}
function loadRingtones() {
    ajax('list_ringtones', {}, function(r) {
        if (!r.status) return;
        var html = '';
        r.files.forEach(function(f) {
            html += '<div class="list-group-item"><i class="fa fa-music"></i> '+esc(f.filename)+' <span class="text-muted">('+fmtSize(f.size)+')</span>';
            html += ' <button class="btn btn-xs btn-danger pull-right" onclick="deleteRingtone(\''+esc(f.filename).replace(/'/g,"\\'")+'\')"><i class="fa fa-trash"></i></button></div>';
        });
        $('#ringtoneList').html(html || '<p class="text-muted" style="padding:10px;">No ringtones uploaded.</p>');
    });
}
function deleteRingtone(fn) { if(!confirm('Delete '+fn+'?')) return; ajax('delete_ringtone', {filename:fn}, function(r) { if(r.status) loadRingtones(); else alert(r.message); }); }

function uploadFirmware() {
    var f = document.getElementById('firmwareUpload');
    if (!f.files[0]) return alert('Select file');
    var fd = new FormData(); fd.append('file', f.files[0]); fd.append('csrf_token', csrf);
    $.ajax({url:'ajax.php?module=quickprovisioner&command=upload_firmware', type:'POST', data:fd, contentType:false, processData:false, dataType:'json', success:function(r) { if(r.status) { f.value=''; loadFirmware(); } else alert(r.message); }});
}
function loadFirmware() {
    ajax('list_firmware', {}, function(r) {
        if (!r.status) return;
        var html = '';
        r.files.forEach(function(f) {
            html += '<div class="list-group-item"><i class="fa fa-microchip"></i> '+esc(f.filename)+' <span class="text-muted">('+fmtSize(f.size)+')</span>';
            html += ' <button class="btn btn-xs btn-danger pull-right" onclick="deleteFirmware(\''+esc(f.filename).replace(/'/g,"\\'")+'\')"><i class="fa fa-trash"></i></button></div>';
        });
        $('#firmwareList').html(html || '<p class="text-muted" style="padding:10px;">No firmware uploaded.</p>');
    });
}
function deleteFirmware(fn) { if(!confirm('Delete '+fn+'?')) return; ajax('delete_firmware', {filename:fn}, function(r) { if(r.status) loadFirmware(); else alert(r.message); }); }

// ===================== TEMPLATES =====================
function setTemplateEditorMode(filename) {
    editingTemplateFile = filename || '';
    if (editingTemplateFile) {
        $('#templateFilename').val(editingTemplateFile);
        $('#templateEditorTitle').html('<i class="fa fa-pencil"></i> Editing template');
        $('#templateEditorBadge').text('editing').removeClass('label-default label-success').addClass('label-warning').show();
    } else {
        $('#templateEditorTitle').html('<i class="fa fa-file-code-o"></i> Template Editor');
        $('#templateEditorBadge').text('new').removeClass('label-warning label-success').addClass('label-default').show();
    }
}

function newTemplateEditor(force) {
    if (!force && $('#driverInput').val().trim() && !confirm('Clear the editor and start a new template?')) return;
    $('#driverInput').val('');
    $('#templateFilename').val('');
    setTemplateEditorMode('');
    $('#importFeedback').html('');
    loadTemplateList();
}

function editTemplateFile(filename) {
    filename = String(filename || '').replace(/^.*[\\\/]/, '');
    if (!filename) return;
    ajax('get_template', {filename: filename}, function(r) {
        if (!r.status) {
            $('#importFeedback').html('<div class="alert alert-danger">' + esc(r.message || 'Load failed') + '</div>');
            return;
        }
        $('#driverInput').val(r.source || '');
        setTemplateEditorMode(r.filename || filename);
        $('#importFeedback').html('<div class="alert alert-info" style="margin:0;">Loaded <code>' + esc(r.filename || filename) + '</code> — edit then Save Template.</div>');
        loadTemplateList();
        $('a[href="#tab-templates"]').tab('show');
        try { $('#driverInput')[0].scrollTop = 0; } catch (e) {}
    });
}

function normalizeTemplateFilename(name) {
    name = String(name || '').trim().replace(/^.*[\\\/]/, '');
    if (!name) return '';
    if (!/\.mustache$/i.test(name)) name += '.mustache';
    return name;
}

function saveTemplate() {
    var t = $('#driverInput').val().trim();
    if (!t) {
        $('#importFeedback').html('<div class="alert alert-warning">Nothing to save — paste or load a template first.</div>');
        return;
    }
    var filename = normalizeTemplateFilename($('#templateFilename').val() || editingTemplateFile);
    if (!filename) {
        $('#importFeedback').html('<div class="alert alert-warning">Enter a filename (e.g. polycom_vvx.xml.mustache).</div>');
        $('#templateFilename').focus();
        return;
    }
    if (editingTemplateFile && filename === editingTemplateFile) {
        if (!confirm('Overwrite installed template "' + filename + '"?')) return;
    }
    ajax('import_driver', {template: t, filename: filename}, function(r) {
        if (r.status) {
            setTemplateEditorMode(r.filename || filename);
            $('#importFeedback').html('<div class="alert alert-success">Saved <code>' + esc(r.filename || filename) + '</code></div>');
            loadTemplateList();
            loadModelDropdown();
        } else {
            $('#importFeedback').html('<div class="alert alert-danger">' + esc(r.message) + '</div>');
        }
    });
}

function saveTemplateAsNew() {
    var suggested = normalizeTemplateFilename($('#templateFilename').val() || editingTemplateFile || 'custom_template.mustache');
    if (/\.mustache$/i.test(suggested)) {
        suggested = suggested.replace(/\.mustache$/i, '_copy.mustache');
    }
    var name = prompt('Save as new filename:', suggested);
    if (!name) return;
    $('#templateFilename').val(normalizeTemplateFilename(name));
    setTemplateEditorMode(''); // treat as new until saved
    saveTemplate();
}

/** @deprecated alias — Import button removed; Save Template replaces it */
function importDriver() { saveTemplate(); }

function uploadTemplateFile() {
    var f = document.getElementById('templateFileUpload');
    if (!f.files[0]) { $('#importFeedback').html('<div class="alert alert-warning">Select a file.</div>'); return; }
    var name = f.files[0].name || '';
    var reader = new FileReader();
    reader.onload = function(e) {
        $('#driverInput').val(e.target.result);
        if (name) {
            var fn = normalizeTemplateFilename(name);
            $('#templateFilename').val(fn);
            setTemplateEditorMode('');
        }
        $('#importFeedback').html('<div class="alert alert-info">File loaded into editor — review then Save Template.</div>');
    };
    reader.readAsText(f.files[0]);
}

function deleteTemplate(filenameOrModel) {
    var key = String(filenameOrModel || '');
    if (!confirm('Delete template "' + key + '"? Devices using it will fail to provision until replaced.')) return;
    ajax('delete_driver', {model: key.replace(/\.mustache$/i, ''), filename: key}, function(r) {
        if (r.status) {
            if (editingTemplateFile === key || editingTemplateFile === normalizeTemplateFilename(key)) {
                newTemplateEditor(true);
            }
            loadTemplateList();
            loadModelDropdown();
            $('#importFeedback').html('<div class="alert alert-success">Deleted.</div>');
        } else alert(r.message);
    });
}

function showExample() {
    // Prefer loading a real installed template so "example" matches production format
    ajax('list_drivers', {}, function(r) {
        if (r.status && r.list && r.list.length) {
            var pick = r.list.find(function(t) {
                return /polycom/i.test(t.display_name || '') || /polycom/i.test(t.filename || '');
            }) || r.list[0];
            var names = r.list.map(function(t, i) {
                return (i + 1) + '. ' + (t.display_name || t.model) + '  (' + (t.filename || t.model) + ')';
            }).join('\n');
            var choice = prompt(
                'Load which installed template into the editor?\n\n' + names + '\n\nEnter number (or Cancel for mini stub):',
                '1'
            );
            if (choice === null) return;
            var idx = parseInt(choice, 10) - 1;
            if (!isNaN(idx) && r.list[idx]) {
                editTemplateFile(r.list[idx].filename || (r.list[idx].model + '.mustache'));
                return;
            }
            // fall through to mini stub if invalid number
        }
        $('#driverInput').val('{{! META: {\n  "manufacturer": "Yealink",\n  "model_family": "T4x",\n  "display_name": "Yealink T4x Custom",\n  "config_format": "cfg",\n  "content_type": "text/plain",\n  "filename_pattern": "{mac}.cfg",\n  "supported_models": ["T48G"],\n  "max_line_keys": 29,\n  "type_mapping": {"line": 15, "blf": 16, "speed_dial": 13},\n  "categories": [\n    {"id": "sip", "label": "SIP & Registration", "icon": "📞", "order": 1}\n  ],\n  "variables": [\n    {"name": "sip_server", "category": "sip", "description": "SIP server address", "example": "pbx.example.com", "default": ""}\n  ]\n} }}\n#!version:1.0.0.1\n{{#lines}}\naccount.{{line_index}}.enable = 1\naccount.{{line_index}}.user_name = {{user_name}}\naccount.{{line_index}}.password = {{password}}\naccount.{{line_index}}.sip_server.1.address = {{sip_server}}\n{{/lines}}');
        $('#templateFilename').val('yealink_t4x_custom.mustache');
        setTemplateEditorMode('');
        $('#importFeedback').html('<div class="alert alert-info">Mini stub loaded — edit META/body then Save as New.</div>');
    });
}

// ===================== ADMIN =====================
function reloadPBX() {
    if (!confirm('Reload configuration?')) return;
    $('#pbxStatus').html('<i class="fa fa-spinner fa-spin"></i> Reloading...');
    ajax('restart_pbx', {type:'reload'}, function(r) { $('#pbxStatus').html('<span class="'+(r.status?'text-success':'text-danger')+'">'+esc(r.message)+'</span>'); });
}
function restartPBX() {
    if (!confirm('Restart PBX? This will interrupt active calls!')) return;
    $('#pbxStatus').html('<i class="fa fa-spinner fa-spin"></i> Restarting...');
    ajax('restart_pbx', {type:'restart'}, function(r) { $('#pbxStatus').html('<span class="'+(r.status?'text-success':'text-danger')+'">'+esc(r.message)+'</span>'); });
}

function resyncAllHandsets() {
    var force = $('#resyncForceReboot').is(':checked') ? 1 : 0;
    var msg = force
        ? 'Force-reboot ALL Quick-Provisioner handsets via SIP check-sync?'
        : 'Send SIP check-sync to ALL Quick-Provisioner handsets?\n\nRegistered phones will re-fetch config.';
    if (!confirm(msg)) return;
    var $btn = $('#resyncAllBtn');
    $btn.prop('disabled', true);
    $('#resyncAllStatus').html('<i class="fa fa-spinner fa-spin"></i> Sending check-sync…');
    ajax('resync_all_devices', {force_reboot: force}, function(r) {
        $btn.prop('disabled', false);
        if (!r.status) {
            $('#resyncAllStatus').html('<div class="alert alert-danger">' + esc(r.message || 'Failed') + '</div>');
            return;
        }
        var html = '<div class="alert alert-' + (r.failed ? 'warning' : 'success') + '" style="margin-bottom:8px;">' + esc(r.message || 'Done') + '</div>';
        if (r.results && r.results.length) {
            html += '<table class="table table-condensed table-striped" style="margin:0;"><thead><tr><th>Ext</th><th>Model</th><th>Result</th></tr></thead><tbody>';
            r.results.forEach(function(row) {
                html += '<tr><td>' + esc(row.extension || '—') + '</td><td>' + esc(row.model || '') + '</td>';
                html += '<td class="' + (row.ok ? 'text-success' : 'text-danger') + '">' + esc(row.ok ? (row.output || 'OK') : (row.output || 'Failed')) + '</td></tr>';
            });
            html += '</tbody></table>';
        }
        $('#resyncAllStatus').html(html);
    });
}

function checkForUpdates() {
    $('#checkUpdatesBtn').prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i> Checking...');
    $('#updateStatus').hide(); $('#updateResult').hide();
    ajax('check_updates', {}, function(r) {
        $('#checkUpdatesBtn').prop('disabled', false).html('<i class="fa fa-search"></i> Check for Updates');
        if (!r.status) { $('#updateStatus').show(); $('#updateMsg').html('<div class="alert alert-danger">'+esc(r.message)+'</div>'); return; }
        $('#currentCommit').text(r.current_commit.substring(0,7));
        if (r.current_version) $('#currentVersion').text(r.current_version);
        $('#updateStatus').show();
        if (r.has_updates) {
            $('#updateMsg').html('<div class="alert alert-info"><strong>Updates Available!</strong> Remote: '+r.remote_commit.substring(0,7)+'</div>');
            loadChangelog(r.current_commit, r.remote_commit);
        } else {
            $('#updateMsg').html('<div class="alert alert-success"><strong>Up to Date</strong></div>');
            $('#changelogSection').hide();
        }
    });
}

function loadChangelog(cur, rem) {
    ajax('get_changelog', {current_commit:cur, remote_commit:rem}, function(r) {
        if (r.status && r.commits && r.commits.length) {
            var html = '';
            r.commits.forEach(function(c) { html += '<div class="list-group-item"><strong>'+c.hash.substring(0,7)+'</strong> — '+esc(c.message)+'<br><small class="text-muted">'+esc(c.author)+'</small></div>'; });
            $('#changelogList').html(html);
        } else { $('#changelogList').html('<div class="list-group-item text-muted">No changelog</div>'); }
        $('#changelogSection').show();
    });
}

function performUpdate() {
    if (!confirm('Update now? This will pull latest changes and fix permissions.')) return;
    $('#confirmUpdateBtn').prop('disabled', true).text('Updating...');
    $('#changelogSection').hide();
    $('#updateMsg').html('<div class="alert alert-info"><i class="fa fa-spinner fa-spin"></i> Updating...</div>');
    ajax('perform_update', {}, function(r) {
        $('#confirmUpdateBtn').prop('disabled', false).text('Yes, Update Now');
        if (r.status) {
            var msg = '<div class="alert alert-success"><strong>Updated!</strong> '+r.old_commit.substring(0,7)+' → '+r.new_commit.substring(0,7);
            if (r.new_version) msg += '<br>Version: '+r.new_version;
            if (r.post_update) msg += '<br><small>'+r.post_update.join(', ')+'</small>';
            msg += '<br><br>'+esc(r.message)+'</div>';
            $('#updateResult').html(msg).show(); $('#updateStatus').hide();
            $('#currentCommit').text(r.new_commit.substring(0,7));
            if (r.new_version) $('#currentVersion').text(r.new_version);
        } else {
            $('#updateResult').html('<div class="alert alert-danger">'+esc(r.message)+'</div>').show();
            $('#changelogSection').show();
        }
    });
}

// Access Log
function loadAccessLog() {
    ajax('list_access_log', {limit:100}, function(r) {
        if (!r.status) return;
        var html = '';
        (r.entries || []).forEach(function(e) {
            var ts = e.timestamp ? e.timestamp.substring(11,19) : '';
            var sc = parseInt(e.status_code);
            var cls = sc >= 400 ? 'text-danger' : (sc >= 300 ? 'text-warning' : '');
            html += '<tr><td>'+esc(ts)+'</td><td class="'+cls+'">'+e.status_code+'</td><td>'+esc(e.path||'')+'</td><td>'+esc(e.mac||'')+'</td><td>'+esc(e.client_ip||'')+'</td><td>'+esc(e.resource_type||'')+'</td></tr>';
        });
        $('#accessLogBody').html(html || '<tr><td colspan="6" class="text-muted">No log entries</td></tr>');
    });
}
function clearAccessLog() {
    if (!confirm('Clear all access log entries?')) return;
    ajax('clear_access_log', {}, function(r) { if(r.status) loadAccessLog(); });
}

function loadModuleHealth() {
    $('#moduleHealthResult').html('<div class="text-muted"><i class="fa fa-spinner fa-spin"></i> Checking...</div>').show();
    ajax('module_health', {}, function(r) {
        if (!r.status || !r.health) {
            $('#moduleHealthResult').html('<div class="alert alert-danger">' + esc(r.message || 'Health check failed') + '</div>').show();
            return;
        }
        var h = r.health;
        var html = '<div class="alert alert-info" style="margin-bottom:10px;">'
            + 'Devices: <strong>' + esc(String(h.device_count || 0)) + '</strong>'
            + ' | Missing SIP secrets: <strong>' + esc(String(h.missing_secrets || 0)) + '</strong>'
            + ' | Missing provisioning creds: <strong>' + esc(String(h.missing_provision_creds || 0)) + '</strong>'
            + '</div>';
        html += '<table class="table table-condensed table-striped"><thead><tr><th>Area</th><th>Exists</th><th>Writable</th><th>Perms</th></tr></thead><tbody>';
        (h.directories || []).forEach(function(d) {
            html += '<tr><td>' + esc(d.name || '') + '</td><td>' + (d.exists ? 'Yes' : '<span class="text-danger">No</span>') + '</td><td>' + (d.writable ? 'Yes' : '<span class="text-danger">No</span>') + '</td><td>' + esc(d.perms || '') + '</td></tr>';
        });
        html += '</tbody></table>';
        $('#moduleHealthResult').html(html).show();
    });
}

function repairModulePermissions() {
    if (!confirm('Run module permission repair now?')) return;
    $('#moduleHealthResult').html('<div class="text-muted"><i class="fa fa-spinner fa-spin"></i> Repairing...</div>').show();
    ajax('repair_module_permissions', {}, function(r) {
        if (!r.status) {
            $('#moduleHealthResult').html('<div class="alert alert-danger">' + esc(r.message || 'Permission repair failed') + '</div>').show();
            return;
        }
        loadModuleHealth();
    });
}

function runTemplateSelfTest() {
    $('#moduleHealthResult').html('<div class="text-muted"><i class="fa fa-spinner fa-spin"></i> Running template self-test...</div>').show();
    ajax('template_self_test', {}, function(r) {
        if (!r || !r.results) {
            $('#moduleHealthResult').html('<div class="alert alert-danger">Template self-test failed to return results.</div>').show();
            return;
        }
        var html = '<div class="alert ' + (r.all_ok ? 'alert-success' : 'alert-warning') + '" style="margin-bottom:10px;">'
            + esc(r.message || (r.all_ok ? 'All template self-tests passed' : 'Template self-test found issues'))
            + '</div>';
        html += '<table class="table table-condensed table-striped"><thead><tr><th>Template</th><th>Status</th><th>Notes</th></tr></thead><tbody>';
        (r.results || []).forEach(function(t) {
            var ok = !!t.ok;
            var notes = ok ? 'OK' : esc((t.errors || []).join('; '));
            html += '<tr><td>' + esc(t.template || '') + '</td><td>' + (ok ? '<span class="text-success">PASS</span>' : '<span class="text-danger">FAIL</span>') + '</td><td>' + notes + '</td></tr>';
        });
        html += '</tbody></table>';
        $('#moduleHealthResult').html(html).show();
    });
}

// ===================== GLOBAL SETTINGS =====================
function loadGlobalSettings() {
    ajax('get_global_settings', {}, function(r) {
        if (!r.status || !r.settings) return;
        $('#globalSipHost').val(r.settings.sip_server_host || '');
        $('#globalSipPort').val(r.settings.sip_server_port || '');
    });
}
function saveGlobalSettings() {
    ajax('save_global_settings', {
        sip_server_host: $('#globalSipHost').val(),
        sip_server_port: $('#globalSipPort').val()
    }, function(r) {
        if (r.status) {
            $('#globalSettingsStatus').html('<span class="text-success">Saved</span>');
            if (r.settings) {
                $('#globalSipHost').val(r.settings.sip_server_host || '');
                $('#globalSipPort').val(r.settings.sip_server_port || '');
            }
        } else {
            $('#globalSettingsStatus').html('<span class="text-danger">' + esc(r.message || 'Save failed') + '</span>');
        }
        setTimeout(function() { $('#globalSettingsStatus').empty(); }, 4000);
    });
}

// ===================== INIT =====================
loadDevices();
loadModelDropdown();

$(document).ready(function() {
    loadGlobalSettings();
    ajax('check_updates', {}, function(r) {
        if (r.status && r.current_commit) $('#currentCommit').text(r.current_commit.substring(0,7));
        if (r.current_version) $('#currentVersion').text(r.current_version);
    });
});
</script>
<?php
?>
