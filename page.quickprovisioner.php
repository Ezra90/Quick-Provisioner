<?php
function qp_is_local_network() {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    if ($ip === '::1') return true;
    if (preg_match('/^(127\.|10\.|192\.168\.|172\.(1[6-9]|2[0-9]|3[01])\.)/', $ip)) {
        return true;
    }
    return false;
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
            if (isset($user['extension'])) {
                $extensions[] = $user['extension'];
            }
        }
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
?>
<div class="container-fluid">
    <h1><i class="fa fa-phone"></i> Quick-Provisioner <small class="text-muted">0.1-dev</small></h1>

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
                <thead><tr><th>MAC</th><th>Extension</th><th>Secret</th><th>Model</th><th>Actions</th></tr></thead>
                <tbody id="deviceListBody"></tbody>
            </table>
        </div>

        <!-- ==================== TAB 2: DEVICE EDITOR ==================== -->
        <div id="tab-editor" class="tab-pane fade">
            <form id="deviceForm">
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
                                                <?php foreach ($extensions as $ext): ?>
                                                <option value="<?= htmlspecialchars($ext) ?>"><?= htmlspecialchars($ext) ?></option>
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
                                    <input type="text" id="prov_username" name="prov_username" class="form-control" placeholder="For remote provisioning">
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
                                            <h4 class="panel-title"><i class="fa fa-caret-right"></i> Custom Template Override</h4>
                                        </div>
                                        <div id="advTemplateOverride" class="panel-collapse collapse">
                                            <div class="panel-body">
                                                <textarea id="custom_template_override" name="custom_template_override" class="form-control" rows="6" placeholder="Paste custom template here..."></textarea>
                                                <p class="text-warning"><small>Overrides the model template entirely.</small></p>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <hr>

                                <button type="submit" class="btn btn-success btn-block btn-lg"><i class="fa fa-save"></i> Save Device</button>
                                <button type="button" class="btn btn-info btn-block" onclick="previewConfig()"><i class="fa fa-eye"></i> Preview Config</button>
                            </div>
                        </div>
                    </div>

                    <!-- RIGHT COLUMN: Template Content -->
                    <div class="col-md-8">
                        <div class="panel panel-default">
                            <div class="panel-heading"><strong id="rightColHeader">Select a Model to Load Template</strong></div>
                            <div class="panel-body">
                                <ul class="nav nav-tabs" role="tablist">
                                    <li class="active"><a data-toggle="tab" href="#sub-settings">Settings</a></li>
                                    <li><a data-toggle="tab" href="#sub-wallpaper">Wallpaper</a></li>
                                    <li><a data-toggle="tab" href="#sub-buttons">Button Layout</a></li>
                                    <li><a data-toggle="tab" href="#sub-contacts" onclick="loadContacts()">Contacts</a></li>
                                </ul>
                                <div class="tab-content" style="padding-top:15px;">

                                    <!-- Settings Sub-Tab -->
                                    <div id="sub-settings" class="tab-pane fade in active">
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
                                                    <select id="wp_mode_sel" class="form-control" onchange="$('#wallpaper_mode').val(this.value); renderPreview();">
                                                        <option value="crop">Crop to Fill</option>
                                                        <option value="fit">Fit (Letterbox)</option>
                                                    </select>
                                                </div>
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
                                        <p class="text-muted">Click buttons on the phone to configure their function.</p>
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
                                    </div>

                                    <!-- Contacts Sub-Tab -->
                                    <div id="sub-contacts" class="tab-pane fade">
                                        <button type="button" class="btn btn-success btn-sm" onclick="addContact()"><i class="fa fa-plus"></i> Add Contact</button>
                                        <div id="contactsList" style="margin-top:10px;"></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </form>
        </div>

        <!-- ==================== TAB 3: FILE MANAGER ==================== -->
        <div id="tab-files" class="tab-pane fade">
            <div class="row">
                <div class="col-md-4">
                    <div class="panel panel-default">
                        <div class="panel-heading"><strong><i class="fa fa-image"></i> Wallpapers</strong></div>
                        <div class="panel-body">
                            <input type="file" id="assetUpload" class="form-control" accept="image/*">
                            <br><button class="btn btn-primary btn-sm" onclick="uploadAsset()"><i class="fa fa-upload"></i> Upload</button>
                        </div>
                    </div>
                    <div id="assetGrid" class="row"></div>
                </div>
                <div class="col-md-4">
                    <div class="panel panel-default">
                        <div class="panel-heading"><strong><i class="fa fa-music"></i> Ringtones</strong></div>
                        <div class="panel-body">
                            <input type="file" id="ringtoneUpload" class="form-control" accept=".wav">
                            <br><button class="btn btn-primary btn-sm" onclick="uploadRingtone()"><i class="fa fa-upload"></i> Upload</button>
                        </div>
                    </div>
                    <div id="ringtoneList"></div>
                </div>
                <div class="col-md-4">
                    <div class="panel panel-default">
                        <div class="panel-heading"><strong><i class="fa fa-microchip"></i> Firmware</strong></div>
                        <div class="panel-body">
                            <input type="file" id="firmwareUpload" class="form-control">
                            <br><button class="btn btn-primary btn-sm" onclick="uploadFirmware()"><i class="fa fa-upload"></i> Upload</button>
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
                        <div class="panel-heading"><strong>Import Template</strong></div>
                        <div class="panel-body">
                            <textarea id="driverInput" class="form-control" rows="10" placeholder="Paste Mustache template with META block..."></textarea>
                            <br>
                            <button class="btn btn-primary" onclick="importDriver()"><i class="fa fa-download"></i> Import</button>
                            <button class="btn btn-default" onclick="showExample()">Show Example</button>
                            <hr>
                            <input type="file" id="templateFileUpload" accept=".mustache,.cfg,.xml">
                            <button class="btn btn-default btn-sm" onclick="uploadTemplateFile()"><i class="fa fa-upload"></i> Upload File</button>
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div id="importFeedback"></div>
                    <table class="table table-striped">
                        <thead><tr><th>Template</th><th>Manufacturer</th><th>Models</th><th>Actions</th></tr></thead>
                        <tbody id="templatesList"></tbody>
                    </table>
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
                            <button class="btn btn-success" onclick="reloadPBX()"><i class="fa fa-refresh"></i> Reload Config</button>
                            <span class="text-muted">Apply changes without interrupting calls</span>
                            <br><br>
                            <button class="btn btn-warning" onclick="restartPBX()"><i class="fa fa-power-off"></i> Restart PBX</button>
                            <span class="text-danger"><i class="fa fa-exclamation-triangle"></i> Interrupts active calls</span>
                            <div id="pbxStatus" style="margin-top:15px;"></div>
                        </div>
                    </div>

                    <!-- Module Updates -->
                    <div class="panel panel-info">
                        <div class="panel-heading"><h3 class="panel-title"><i class="fa fa-cloud-download"></i> Module Updates</h3></div>
                        <div class="panel-body">
                            <p><strong>Version:</strong> <span id="currentVersion">0.1-dev</span> &nbsp; <strong>Commit:</strong> <span id="currentCommit">...</span></p>
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
        <div class="form-group"><label>Type</label><select id="keyType" class="form-control"><option value="line">Line</option><option value="speed_dial">Speed Dial</option><option value="blf">BLF</option></select></div>
        <div class="form-group"><label>Value</label><input type="text" id="keyValue" class="form-control"></div>
        <div class="form-group"><label>Label</label><input type="text" id="keyLabel" class="form-control"></div>
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
        <div class="form-group"><label>Name</label><input type="text" id="contactName" class="form-control"></div>
        <div class="form-group"><label>Number</label><input type="text" id="contactNumber" class="form-control"></div>
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
var csrf = '<?= $csrf_token ?>';

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
        if (!r.status) { $('#deviceListBody').html('<tr><td colspan="5" class="text-danger">Error: ' + esc(r.message||'') + '</td></tr>'); return; }
        var html = '';
        r.devices.forEach(function(d) {
            var sec = d.secret ? esc(d.secret) : '<span class="text-muted">N/A</span>';
            if (d.secret_source === 'Custom') sec += ' <span class="label label-info">Custom</span>';
            else if (d.secret_source === 'FreePBX') sec += ' <span class="label label-success">FreePBX</span>';
            html += '<tr><td>' + esc(d.mac) + '</td><td>' + esc(d.extension) + '</td><td>' + sec + '</td><td>' + esc(d.model) + '</td>';
            html += '<td><button class="btn btn-xs btn-default" onclick="editDevice(' + d.id + ')"><i class="fa fa-pencil"></i> Edit</button> ';
            html += '<button class="btn btn-xs btn-danger" onclick="deleteDevice(' + d.id + ')"><i class="fa fa-trash"></i></button></td></tr>';
        });
        $('#deviceListBody').html(html || '<tr><td colspan="5" class="text-muted">No devices yet. Click Add New to get started.</td></tr>');
    });
}

function newDevice() {
    $('#deviceForm')[0].reset();
    $('#deviceId').val(''); $('#extension').val(''); $('#extension_custom').val('');
    $('#ext_sel_wrap').show(); $('#ext_cust_wrap').hide();
    $('#sip_secret_preview').val(''); $('#sip_secret_custom').val(''); $('#custom_sip_secret').val('');
    $('#secret_prev_wrap').show(); $('#secret_cust_wrap').hide();
    currentKeys = []; currentContacts = []; currentDeviceId = null; smartDialShortcuts = {};
    clearWallpaper(); renderPreview();
    $('a[href="#tab-editor"]').tab('show');
}

function editDevice(id) {
    currentDeviceId = id;
    ajax('get_device', {id: id}, function(r) {
        if (!r.status || !r.data) return;
        var d = r.data;
        $('#deviceId').val(d.id);
        $('#mac').val(d.mac);

        var found = false;
        $('#extension_select option').each(function() { if ($(this).val() === d.extension) { found = true; return false; } });
        if (found) {
            $('#extension_select').val(d.extension); $('#extension').val(d.extension);
            $('#ext_sel_wrap').show(); $('#ext_cust_wrap').hide();
        } else {
            $('#extension_custom').val(d.extension); $('#extension').val(d.extension);
            $('#ext_sel_wrap').hide(); $('#ext_cust_wrap').show();
        }

        $('#custom_sip_secret').val(d.custom_sip_secret || '');
        loadSipSecret();
        $('#model').val(d.model);
        loadProfile(function() {
            // After profile loads, populate custom options
            var co = {};
            try { co = JSON.parse(d.custom_options_json) || {}; } catch(e) {}
            for (var k in co) { $('[name="custom_options[' + k + ']"]').val(co[k]); }
        });
        $('#wallpaper').val(d.wallpaper);
        updateWpPreview(d.wallpaper);
        $('#wallpaper_mode').val(d.wallpaper_mode); $('#wp_mode_sel').val(d.wallpaper_mode);
        $('#prov_username').val(d.prov_username || ''); $('#prov_password').val(d.prov_password || '');
        $('#custom_template_override').val(d.custom_template_override || '');
        try { currentKeys = JSON.parse(d.keys_json) || []; } catch(e) { currentKeys = []; }
        try { currentContacts = JSON.parse(d.contacts_json) || []; } catch(e) { currentContacts = []; }
        try {
            var opts = JSON.parse(d.custom_options_json) || {};
            if (opts.smart_dial_shortcuts) smartDialShortcuts = JSON.parse(opts.smart_dial_shortcuts);
        } catch(e) { smartDialShortcuts = {}; }
        renderPreview();
    });
    $('a[href="#tab-editor"]').tab('show');
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
            html += '<tr><td>' + esc(t.display_name) + '</td><td>' + esc(t.manufacturer) + '</td>';
            html += '<td>' + esc((t.supported_models||[]).join(', ')) + '</td>';
            html += '<td><button class="btn btn-xs btn-danger" onclick="deleteTemplate(\'' + esc(t.model).replace(/'/g,"\\'") + '\')"><i class="fa fa-trash"></i></button></td></tr>';
        });
        $('#templatesList').html(html);
    });
}

function loadModelDropdown() {
    ajax('list_drivers', {}, function(r) {
        if (!r.status) return;
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
    });
}

function loadProfile(afterCb) {
    var model = $('#model').val();
    if (!model) return;
    ajax('get_driver', {model: model}, function(r) {
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
        var dn = profiles[model].display_name || model;
        $('#rightColHeader').html('<i class="fa fa-check-circle text-success"></i> ' + esc(dn) + ' Template Loaded');
        loadDeviceOptions();
        updatePageSelect();
        updateScreenDims();
        renderPreview();
        loadWpGallery();
        if (typeof afterCb === 'function') afterCb();
    });
}

function loadDeviceOptions() {
    var model = $('#model').val();
    var p = profiles[model];
    var html = '';
    if (!p || !p.variables || p.variables.length === 0) {
        $('#deviceOptions').html('<p class="text-muted">No configurable settings in this template.</p>');
        return;
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
            var ph = v.example ? v.example : (v.default ? 'Default: ' + v.default : '');
            html += '<div class="form-group">';
            html += '<label>' + esc(v.name) + '</label>';
            html += '<input type="text" name="custom_options[' + esc(v.name) + ']" class="form-control" placeholder="' + esc(ph) + '" value="' + esc(v.default || '') + '">';
            if (v.description) html += '<small class="help-block text-muted">' + esc(v.description) + '</small>';
            html += '</div>';
        });

        html += '</div></div></div>';
    });
    $('#deviceOptions').html(html);
}

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
    var b1 = currentKeys.find(function(k) { return k.index === 1; });
    if (!b1) { currentKeys.push({index:1, type:'line', label:ext, value:ext}); }
    else if (!b1.type) { b1.type='line'; b1.label=ext; b1.value=ext; }
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

// ===================== FORM SUBMIT =====================
$('#deviceForm').submit(function(e) {
    e.preventDefault();
    var ext = $('#extension').val();
    if (!ext) { alert('Select or enter an extension'); return; }

    var fd = $(this).serializeArray();
    if (Object.keys(smartDialShortcuts).length > 0)
        fd.push({name:'custom_options[smart_dial_shortcuts]', value:JSON.stringify(smartDialShortcuts)});

    ajax('save_device', {
        data: $.param(fd),
        keys_json: JSON.stringify(currentKeys),
        contacts_json: JSON.stringify(currentContacts)
    }, function(r) {
        if (r.status) { alert('Saved!'); loadDevices(); newDevice(); }
        else alert('Error: ' + r.message);
    });
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
    if (ve.expandable_layout) { $('#pageSelectorGrp').hide(); isExpandedView = false; }
    else {
        $('#pageSelectorGrp').show();
        var pp = ve.keys_per_page || 10, mk = p.max_line_keys || 29, mp = Math.ceil(mk/pp);
        var h = '';
        for (var i=1;i<=mp;i++) h += '<option value="'+i+'">Page '+i+'</option>';
        $('#pageSelect').html(h);
    }
}

function renderPreview() {
    var model = $('#model').val(), p = profiles[model];
    if (!p || !p.visual_editor) return;
    var ve = p.visual_editor, page = parseInt($('#pageSelect').val()) || 1;
    var total = ve.total_pages || Math.ceil((p.max_line_keys||29) / (ve.keys_per_page||10));
    var c = $('#previewContainer');
    c.empty().css({width:ve.schematic.chassis_width+'px', height:ve.schematic.chassis_height+'px', position:'relative'});
    var dn = p.display_name || model;
    var svg = generatePhoneSVG(ve.schematic, dn, page, total);
    c.css({backgroundImage:'url(data:image/svg+xml;base64,'+btoa(svg)+')', backgroundSize:'contain', backgroundRepeat:'no-repeat'});

    var wp = $('#wallpaper').val();
    if (wp) {
        var wpu = wp.startsWith('http') ? wp : 'media.php?file='+encodeURIComponent(wp)+'&preview=1';
        var mode = $('#wallpaper_mode').val();
        $('<div>').css({position:'absolute', left:ve.schematic.screen_x+'px', top:ve.schematic.screen_y+'px', width:ve.schematic.screen_width+'px', height:ve.schematic.screen_height+'px', backgroundImage:'url('+wpu+')', backgroundSize:mode==='crop'?'cover':'contain', backgroundRepeat:'no-repeat', backgroundPosition:'center', borderRadius:'2px'}).appendTo(c);
    }

    var kl = $('<div>').css({position:'absolute', top:0, left:0, width:'100%', height:'100%'}).appendTo(c);
    ve.keys.forEach(function(key) {
        var show = ve.expandable_layout ? (isExpandedView || key.column===1 || key.column===5) : (key.page===undefined || key.page===page);
        if (!show) return;
        var kd = currentKeys.find(function(k){return k.index===key.index;});
        var has = kd && kd.type;
        var lbl = (kd && kd.label) ? kd.label : 'Key '+key.index;
        var bg='rgba(80,80,80,0.8)', bc='rgba(150,150,150,0.5)';
        if (has) {
            switch(kd.type) {
                case 'line': bg='rgba(46,204,64,0.3)'; bc='rgba(46,204,64,0.6)'; break;
                case 'blf': bg='rgba(0,116,217,0.3)'; bc='rgba(0,116,217,0.6)'; break;
                case 'speed_dial': bg='rgba(255,133,27,0.3)'; bc='rgba(255,133,27,0.6)'; break;
                default: bg='rgba(177,13,201,0.3)'; bc='rgba(177,13,201,0.6)';
            }
        }
        $('<button>').css({position:'absolute', left:key.x+'px', top:key.y+'px', width:(key.width||44)+'px', height:(key.height||24)+'px', textAlign:'center', fontSize:'9px', padding:'2px', overflow:'hidden', textOverflow:'ellipsis', whiteSpace:'nowrap', borderRadius:'3px', border:'1px solid '+bc, backgroundColor:bg, color:has?'#fff':'#aaa', cursor:'pointer', fontWeight:has?'bold':'normal', lineHeight:((key.height||24)-4)+'px'}).text(lbl).click(function(){editKey(key.index);}).appendTo(kl);
    });

    // Page nav buttons
    if (!ve.expandable_layout && total > 1) {
        var ny = ve.schematic.screen_y + ve.schematic.screen_height + 10, nx = ve.schematic.chassis_width/2;
        if (page > 1) $('<button>').css({position:'absolute', left:(nx-85)+'px', top:ny+'px', width:'70px', height:'24px', fontSize:'11px', borderRadius:'4px', border:'1px solid rgba(150,150,150,0.4)', backgroundColor:'rgba(60,60,60,0.9)', color:'#ccc', cursor:'pointer', zIndex:1000}).html('&#9664; Prev').click(function(){$('#pageSelect').val(Math.max(1,page-1)); renderPreview();}).appendTo(c);
        if (page < total) $('<button>').css({position:'absolute', left:(nx+15)+'px', top:ny+'px', width:'70px', height:'24px', fontSize:'11px', borderRadius:'4px', border:'1px solid rgba(150,150,150,0.4)', backgroundColor:'rgba(60,60,60,0.9)', color:'#ccc', cursor:'pointer', zIndex:1000}).html('More &#9654;').click(function(){$('#pageSelect').val(Math.min(total,page+1)); renderPreview();}).appendTo(c);
    }
}

function editKey(idx) {
    $('#keyIndex').text(idx);
    var k = currentKeys.find(function(x){return x.index===idx;}) || {};
    $('#keyType').val(k.type||'line'); $('#keyValue').val(k.value||''); $('#keyLabel').val(k.label||'');
    $('#keyModal').modal('show');
}
function saveKey() {
    var idx = parseInt($('#keyIndex').text()), t=$('#keyType').val(), v=$('#keyValue').val(), l=$('#keyLabel').val();
    var ex = currentKeys.find(function(k){return k.index===idx;});
    if (ex) { ex.type=t; ex.value=v; ex.label=l; } else currentKeys.push({index:idx, type:t, value:v, label:l});
    renderPreview(); $('#keyModal').modal('hide');
}
function clearKey() {
    var idx = parseInt($('#keyIndex').text());
    currentKeys = currentKeys.filter(function(k){return k.index!==idx;});
    renderPreview(); $('#keyModal').modal('hide');
}

// ===================== WALLPAPER =====================
function updateScreenDims() {
    var model = $('#model').val(), p = profiles[model];
    var w=800, h=480;
    if (p && p.wallpaper_specs) {
        var sp = p.wallpaper_specs[model] || p.wallpaper_specs;
        if (sp && sp.width) w = sp.width;
        if (sp && sp.height) h = sp.height;
    }
    $('#screenW').text(w); $('#screenH').text(h);
}

function uploadWallpaper() {
    var f = $('#wpUpload')[0].files[0];
    if (!f) { alert('Select a file'); return; }
    var fd = new FormData(); fd.append('file', f); fd.append('csrf_token', csrf);
    var model = $('#model').val(), p = profiles[model];
    if (p && p.wallpaper_specs) {
        var sp = p.wallpaper_specs[model];
        if (sp) { fd.append('resize_width', sp.width); fd.append('resize_height', sp.height); }
    }
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
        var src = fn.startsWith('http') ? fn : 'media.php?file='+encodeURIComponent(fn)+'&preview=1';
        $('#wpPreviewImg').attr('src', src); $('#wpPreview').show(); $('#wpEmpty').hide();
    } else { $('#wpPreview').hide(); $('#wpEmpty').show(); }
}

function loadWpGallery() {
    ajax('list_assets', {}, function(r) {
        if (!r.status) return;
        var html = '';
        r.files.forEach(function(f) {
            html += '<div class="col-xs-6 col-sm-4 col-md-3" style="margin-bottom:10px;"><div class="thumbnail">';
            html += '<img src="media.php?file='+encodeURIComponent(f.filename)+'&preview=1" style="width:100%; height:100px; object-fit:cover;">';
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

// ===================== CONTACTS =====================
function loadContacts() {
    var html = '<table class="table table-striped table-condensed"><thead><tr><th>Name</th><th>Number</th><th>Actions</th></tr></thead><tbody>';
    currentContacts.forEach(function(c, i) {
        html += '<tr><td>'+esc(c.name||'')+'</td><td>'+esc(c.number||'')+'</td>';
        html += '<td><button class="btn btn-xs btn-default" onclick="editContact('+i+')">Edit</button> <button class="btn btn-xs btn-danger" onclick="removeContact('+i+')">Del</button></td></tr>';
    });
    html += '</tbody></table>';
    if (!currentContacts.length) html = '<p class="text-muted">No contacts. Click Add Contact.</p>';
    $('#contactsList').html(html);
}
function addContact() { $('#contactIdx').text(currentContacts.length); $('#contactName').val(''); $('#contactNumber').val(''); $('#contactModal').modal('show'); }
function editContact(i) { $('#contactIdx').text(i); var c=currentContacts[i]||{}; $('#contactName').val(c.name||''); $('#contactNumber').val(c.number||''); $('#contactModal').modal('show'); }
function saveContact() {
    var i=parseInt($('#contactIdx').text()), c={name:$('#contactName').val(), number:$('#contactNumber').val()};
    if (i < currentContacts.length) currentContacts[i]=c; else currentContacts.push(c);
    loadContacts(); $('#contactModal').modal('hide');
}
function removeContact(i) { if(confirm('Remove?')) { currentContacts.splice(i,1); loadContacts(); } }
function clearContact() { $('#contactName').val(''); $('#contactNumber').val(''); }

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
            html += '<img src="media.php?file='+encodeURIComponent(f.filename)+'&preview=1" style="width:100%; height:80px; object-fit:cover;">';
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
function importDriver() {
    var t = $('#driverInput').val().trim();
    if (!t) { $('#importFeedback').html('<div class="alert alert-warning">Paste a template first.</div>'); return; }
    ajax('import_driver', {template: t}, function(r) {
        if (r.status) { $('#importFeedback').html('<div class="alert alert-success">Imported!</div>'); loadTemplateList(); loadModelDropdown(); $('#driverInput').val(''); }
        else $('#importFeedback').html('<div class="alert alert-danger">'+esc(r.message)+'</div>');
    });
}
function uploadTemplateFile() {
    var f = document.getElementById('templateFileUpload');
    if (!f.files[0]) { $('#importFeedback').html('<div class="alert alert-warning">Select a file.</div>'); return; }
    var reader = new FileReader();
    reader.onload = function(e) { $('#driverInput').val(e.target.result); importDriver(); };
    reader.readAsText(f.files[0]);
}
function deleteTemplate(model) { if(!confirm('Delete template?')) return; ajax('delete_driver', {model:model}, function(r) { if(r.status) { loadTemplateList(); loadModelDropdown(); } else alert(r.message); }); }
function showExample() {
    $('#driverInput').val('{{! META: {\n  "manufacturer": "Yealink",\n  "model_family": "T4x",\n  "display_name": "Yealink T4x Custom",\n  "config_format": "cfg",\n  "content_type": "text/plain",\n  "filename_pattern": "{mac}.cfg",\n  "supported_models": ["T48G"],\n  "max_line_keys": 29,\n  "type_mapping": {"line": 15, "blf": 16, "speed_dial": 13},\n  "categories": [\n    {"id": "sip", "label": "SIP & Registration", "icon": "📞", "order": 1}\n  ],\n  "variables": [\n    {"name": "sip_server", "category": "sip", "description": "SIP server address", "example": "pbx.example.com", "default": ""}\n  ]\n} }}\n#!version:1.0.0.1\n{{#lines}}\naccount.{{line_index}}.enable = 1\naccount.{{line_index}}.user_name = {{user_name}}\naccount.{{line_index}}.password = {{password}}\naccount.{{line_index}}.sip_server.1.address = {{sip_server}}\n{{/lines}}');
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

// ===================== INIT =====================
loadDevices();
loadModelDropdown();

$(document).ready(function() {
    ajax('check_updates', {}, function(r) {
        if (r.status && r.current_commit) $('#currentCommit').text(r.current_commit.substring(0,7));
        if (r.current_version) $('#currentVersion').text(r.current_version);
    });
});
</script>
<?php
?>
