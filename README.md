# Quick Provisioner for FreePBX

<p align="center">
  <strong>Always-on VoIP provisioning server for FreePBX</strong>
</p>

<p align="center">
  <img src="https://img.shields.io/badge/Platform-FreePBX-orange" alt="Platform: FreePBX">
  <img src="https://img.shields.io/badge/PHP-7.4+-blue" alt="PHP 7.4+">
  <img src="https://img.shields.io/badge/License-GPLv3-yellow" alt="License: GPLv3">
</p>

> ⚠️ **Work In Progress** — This module is under active development. Expect frequent updates and breaking changes between versions.

**Quick Provisioner** is a standalone FreePBX module that turns your PBX server into a permanent **HTTP Provisioning Server (DMS)** for VoIP handsets. It serves device configurations, wallpapers, ringtones, firmware, and phonebooks — all managed through the FreePBX web interface.

> 📱 **Companion App:** [Pocket Provisioner](https://github.com/Ezra90/Pocket-Provisioner) — Mobile field utility for Android that uses the same Mustache template format. Templates are fully interchangeable between both apps.

---

## ✨ Key Features

| Feature | Description |
|---------|-------------|
| 🖥️ **Always-On DMS** | Permanent provisioning server hosted on your FreePBX — no laptop or phone needed |
| 📝 **Mustache Templates** | Same template format as Pocket Provisioner — fully interchangeable |
| 🖼️ **Wallpaper Hosting** | Upload and auto-resize images to exact phone model specs |
| 🔔 **Ringtone Hosting** | Upload WAV ringtones served via HTTP |
| ⚡ **Firmware Hosting** | Serve firmware files for phone auto-upgrade |
| 📚 **Phonebook Hosting** | Serve XML phonebooks per device |
| 🎛️ **Button Layout Editor** | Configure BLF, Speed Dial, and Line keys visually |
| 🔒 **Per-Device Auth** | Basic Auth credentials per device for remote provisioning |
| 📊 **FreePBX Integration** | Pulls extensions, secrets, and SIP settings directly from FreePBX |
| 🔄 **Git-Based Updates** | Update the module from the web UI via git pull |

---

## 📦 Supported Handsets

### Yealink (T-Series)
- **T54W** / **T46U** — Color screen desk phones
- **T48G** / **T57W** — Touchscreen models
- **T58W** / **T58G** — Video flagship phones
- Generic T3x/T4x/T5x template support

### Poly (Polycom)
- **VVX Series** — VVX150, VVX250, VVX350, VVX450, VVX1500
- **Edge E Series** — E350, E450

### Cisco MPP (Multiplatform)
- **8800 Series** — 8851, 8865 (3PCC/MPP firmware)
- Uses official Cisco `<flat-profile>` XML format

---

## 🚀 Installation (Unofficial Module)

Quick Provisioner is **not** in the official FreePBX module repository. It must be installed manually via CLI using git.

### Prerequisites

- FreePBX 13+ (tested on 16)
- PHP 7.4+ with GD extension
- Git installed on the server
- SSH or console access to the FreePBX server

### Step 1: Clone the Repository

```bash
# Navigate to the FreePBX modules directory
cd /var/www/html/admin/modules

# Clone the repository
git clone https://github.com/Ezra90/freepbx-quickprovisioner.git quickprovisioner
```

### Step 2: Set Permissions

```bash
# Set ownership to the FreePBX web user
chown -R asterisk:asterisk /var/www/html/admin/modules/quickprovisioner

# Set directory permissions
find /var/www/html/admin/modules/quickprovisioner -type d -exec chmod 0775 {} \;

# Set file permissions
find /var/www/html/admin/modules/quickprovisioner -type f -exec chmod 0664 {} \;
```

### Step 3: Install via fwconsole

```bash
# Install the module
fwconsole ma install quickprovisioner

# Reload FreePBX
fwconsole reload
```

### Step 4: Access the Module

Navigate to **Admin → Quick Provisioner** in your FreePBX web interface.

---

## 🔄 Updating

### Option A: Update from the Web GUI

The module includes a built-in update checker in the **Admin** tab:

1. Open **Admin → Quick Provisioner → Admin**
2. Click **Check for Updates**
3. Review the changelog
4. Click **Apply Update** — this runs `git pull` on the module directory
5. Click **Reload PBX** to apply changes

> **Note:** The web GUI update requires the `asterisk` user to have git access. If updates fail, use the CLI method below.

### Option B: Update from CLI

```bash
cd /var/www/html/admin/modules/quickprovisioner

# Pull latest changes
git pull origin main

# Fix permissions
chown -R asterisk:asterisk /var/www/html/admin/modules/quickprovisioner
find /var/www/html/admin/modules/quickprovisioner -type d -exec chmod 0775 {} \;
find /var/www/html/admin/modules/quickprovisioner -type f -exec chmod 0664 {} \;

# Reload FreePBX
fwconsole reload
```

---

## ⚙️ Quick Start

### 1. Add a Device

1. Open **Admin → Quick Provisioner**
2. Enter the device **MAC address** (12 hex characters)
3. Select the **Model** from the dropdown (templates are auto-loaded)
4. Select or type the **Extension** number
5. Configure line keys, wallpaper, and options as needed
6. Click **Save**

### 2. Configure DHCP Option 66

Point your DHCP server's **Option 66** (or Option 160 for some vendors) to:

```
http://<your-freepbx-ip>/admin/modules/quickprovisioner/provision.php?mac=$MAC
```

For Yealink phones, use:
```
http://<your-freepbx-ip>/admin/modules/quickprovisioner/provision.php?mac={mac}
```

### 3. Boot the Phone

Power on or reboot the phone. It will:
1. Request its configuration from the provisioning URL
2. Download wallpaper, ringtone, and firmware if configured
3. Register with your FreePBX SIP server automatically

---

## 📁 File Hosting

The provisioning server hosts files at these endpoints:

| Endpoint | Content | Directory |
|----------|---------|-----------|
| `provision.php?mac={MAC}` | Device configuration | Dynamic (Mustache rendered) |
| `media.php?mac={MAC}` | Wallpaper images | `assets/uploads/` |
| `provision.php` + `/ringtones/{file}` | Ringtone WAV files | `assets/ringtones/` |
| `provision.php` + `/firmware/{file}` | Firmware binaries | `assets/firmware/` |
| `provision.php` + `/phonebook/{file}` | XML phonebooks | `assets/phonebook/` |

### File Storage Layout

```
quickprovisioner/
├── assets/
│   ├── uploads/      → Wallpaper images (auto-resized PNGs/JPGs)
│   ├── ringtones/    → WAV ringtones
│   ├── firmware/     → Firmware binaries (.rom, .ld, .loads, .bin)
│   └── phonebook/    → Per-device XML phonebooks
└── templates/        → Mustache templates (.mustache)
```

---

## 🔧 Template System

Quick Provisioner uses **Mustache templates** — the same format as [Pocket Provisioner](https://github.com/Ezra90/Pocket-Provisioner). Templates are fully interchangeable between both apps.

### Bundled Templates

| Template | File | Handsets |
|----------|------|----------|
| Yealink T4x/T5x | `yealink_t4x.cfg.mustache` | T54W, T46U, T48G, T57W, T58W, T58G |
| Polycom VVX | `polycom_vvx.xml.mustache` | VVX series, Edge E series |
| Cisco 8800 MPP | `cisco_88xx.xml.mustache` | 8851, 8865 (3PCC) |

### Custom Templates

1. Navigate to **Templates** tab in the module
2. Paste a `.mustache` template or upload a template file
3. Templates must include a `{{! META: {...} }}` block with variable definitions
4. Export templates to share with Pocket Provisioner or your team

### Template Variables

Templates use **Mustache** syntax with variables like:

```mustache
{{sip_server}}          <!-- SIP registrar address -->
{{extension}}           <!-- User extension -->
{{password}}            <!-- SIP password (auto-fetched from FreePBX) -->
{{wallpaper_url}}       <!-- Full URL to wallpaper -->
{{ringtone_url}}        <!-- Full URL to ringtone -->
{{firmware_url}}        <!-- Full URL to firmware -->
{{provisioning_url}}    <!-- Auto-provisioning server URL -->
```

### META Block

Each template embeds a JSON metadata block that defines available variables, categories, supported models, and wallpaper specifications:

```mustache
{{! META: {
  "manufacturer": "Yealink",
  "display_name": "Yealink T4x/T5x",
  "supported_models": ["T54W", "T46U", "T48G"],
  "wallpaper_specs": {
    "T54W": {"width": 480, "height": 272},
    "T48G": {"width": 800, "height": 480}
  },
  "variables": [
    {"name": "sip_server", "category": "sip", "description": "SIP registrar", "default": ""}
  ]
} }}
```

---

## 🖼️ Wallpaper Specifications

Wallpaper images are auto-resized based on the phone model (from template META):

| Phone Model | Resolution | Format |
|-------------|------------|--------|
| Yealink T54W / T46U | 480 × 272 | PNG/JPG |
| Yealink T48G / T57W | 800 × 480 | PNG/JPG |
| Yealink T58W | 1024 × 600 | PNG/JPG |
| Poly Edge E350 | 320 × 240 | PNG/JPG |
| Poly Edge E450 | 480 × 272 | PNG/JPG |
| Cisco 8851 / 8865 | 800 × 480 | PNG/JPG |

---

## 🔔 Ringtone Specifications

| Requirement | Value |
|-------------|-------|
| Format | WAV (PCM) |
| Sample Rate | 8000 Hz or 16000 Hz |
| Bit Depth | 16-bit |
| Channels | Mono |
| Max Size | 1 MB |

---

## 🔒 Security

### Local Network Protection

The admin web interface is restricted to local network access only (RFC 1918 addresses). Remote access to the admin UI is blocked.

### Per-Device Authentication

Each device can have a unique provisioning username and password. Remote provisioning requests require HTTP Basic Auth credentials that match the device record.

### CSRF Protection

All admin AJAX requests include CSRF tokens to prevent cross-site request forgery.

---

## 📋 Troubleshooting

### Phone Not Provisioning

1. Verify DHCP Option 66 points to the correct URL
2. Check the MAC address is entered correctly (12 hex characters, no separators)
3. Ensure the phone model matches a loaded template
4. Check FreePBX logs: **Reports → System Log**

### Template Not Loading

1. Ensure the `.mustache` file has a valid `{{! META: {...} }}` block
2. Check the `supported_models` array includes your phone model
3. Try re-importing the template via the Templates tab

### Permission Issues After Update

```bash
chown -R asterisk:asterisk /var/www/html/admin/modules/quickprovisioner
find /var/www/html/admin/modules/quickprovisioner -type d -exec chmod 0775 {} \;
find /var/www/html/admin/modules/quickprovisioner -type f -exec chmod 0664 {} \;
fwconsole reload
```

---

## 🏗️ Project Structure

```
quickprovisioner/
├── Quickprovisioner.class.php    → FreePBX BMO module class
├── MustacheEngine.php            → Mustache template renderer + META parser
├── provision.php                 → Provisioning endpoint (serves configs + assets)
├── media.php                     → Wallpaper image resizer
├── ajax.quickprovisioner.php     → Admin AJAX backend API
├── page.quickprovisioner.php     → Admin web interface
├── install.php                   → Module installation hooks
├── uninstall.php                 → Module uninstall hooks
├── module.xml                    → FreePBX module descriptor
├── templates/                    → Mustache template files
│   ├── yealink_t4x.cfg.mustache
│   ├── polycom_vvx.xml.mustache
│   └── cisco_88xx.xml.mustache
└── assets/
    ├── uploads/                  → Wallpaper images
    ├── ringtones/                → WAV ringtone files
    ├── firmware/                 → Firmware binaries
    └── phonebook/                → XML phonebook files
```

---

## 🤝 Contributing

Contributions are welcome! Please:

1. Fork the repository
2. Create a feature branch (`git checkout -b feature/amazing-feature`)
3. Commit your changes (`git commit -m 'Add amazing feature'`)
4. Push to the branch (`git push origin feature/amazing-feature`)
5. Open a Pull Request

---

## 📄 License

This project is licensed under the GPLv3 License — see the [LICENSE](LICENSE) file for details.

---

<p align="center">
  Made with ❤️ for Telecommunications Technicians
</p>
