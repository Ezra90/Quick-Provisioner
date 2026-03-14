# Quick-Provisioner for FreePBX

<p align="center">
  <strong>Always-on DMS / Endpoint Manager for FreePBX</strong>
</p>

<p align="center">
  <img src="https://img.shields.io/badge/Platform-FreePBX-orange" alt="Platform: FreePBX">
  <img src="https://img.shields.io/badge/PHP-7.4+-blue" alt="PHP 7.4+">
  <img src="https://img.shields.io/badge/License-MIT-yellow" alt="License: MIT">
</p>

> ⚠️ **Alpha Release** — This module is under active development. Expect frequent updates and breaking changes between versions.

**Quick-Provisioner** turns your FreePBX server into a permanent **DMS / Endpoint Manager** for VoIP handsets. It serves device configurations, wallpapers, ringtones, firmware, and phonebooks — all managed through the FreePBX web interface with live SIP secret integration.

> 📱 **Companion App:** [Pocket Provisioner](https://github.com/Ezra90/Pocket-Provisioner) — Android standalone provisioning utility using the same Mustache template format. Quick-Provisioner operates as an always-on server-hosted DMS, while Pocket Provisioner is a standalone temp/one-off provisioning tool.

---

## ✨ Key Features

| Feature | Description |
|---------|-------------|
| 🖥️ **Always-On DMS** | Permanent provisioning server hosted on your FreePBX |
| 🔑 **FreePBX Integration** | Auto-pulls SIP secrets and tracks extension changes from FreePBX |
| 📋 **Template-Driven Settings** | Loads descriptions, examples, and categories from Mustache templates |
| 🖼️ **Wallpaper Hosting** | Upload and auto-resize images to exact phone model specs |
| 🔔 **Ringtone Hosting** | Import WAV ringtone files for phone download |
| ⚡ **Firmware Hosting** | Serve firmware files for phone auto-upgrade |
| 🎛️ **Button Layout Editor** | Configure BLF, Speed Dial, and Line keys with SVG visual preview |
| 📊 **Access Logging** | Track all provisioning requests from handsets |
| 🔄 **Git-Based Updates** | Check for and apply updates directly from the web UI |
| 🔒 **Per-Device Auth** | HTTP Basic Auth for remote provisioning security |

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

## 🚀 Installation

### Via FreePBX CLI (Recommended)

```bash
cd /var/www/html/admin/modules
git clone https://github.com/Ezra90/Quick-Provisioner.git quickprovisioner
chown -R asterisk:asterisk quickprovisioner
fwconsole ma install quickprovisioner
fwconsole reload
```

Then navigate to **Admin → Quick-Provisioner** in the FreePBX web interface.

### Unofficial / Manual Install

If `fwconsole ma install` is unavailable or you prefer a manual setup:

```bash
cd /var/www/html/admin/modules
git clone https://github.com/Ezra90/Quick-Provisioner.git quickprovisioner
chown -R asterisk:asterisk quickprovisioner
chmod -R 0775 quickprovisioner
fwconsole chown
fwconsole reload
```

> **Note:** The module will auto-create its database tables and asset directories on first load.

---

## 🔄 Updating

### From the Web UI (Easiest)

1. Open **Admin → Quick-Provisioner → Admin tab**
2. Click **Check for Updates**
3. Review the changelog
4. Click **Yes, Update Now**

The module will automatically:
- Pull latest changes from GitHub
- Fix file permissions (`fwconsole chown`)
- Reload FreePBX configuration
- Run database migrations

### From CLI

```bash
cd /var/www/html/admin/modules/quickprovisioner
git pull origin main
fwconsole chown
fwconsole reload
```

### Using the Update Script

```bash
# Install the helper script (one-time)
sudo cp /var/www/html/admin/modules/quickprovisioner/scripts/qp-update /usr/local/bin/
sudo chmod +x /usr/local/bin/qp-update

# Run updates
sudo qp-update
```

The update script handles git pull, permission fixes, ownership, and FreePBX reload automatically.

---

## 🔧 Quick Start

### 1. Add a Device

1. Open **Admin → Quick-Provisioner**
2. Click **Add New** on the Devices tab
3. Select an **Extension** from the FreePBX dropdown (SIP secret auto-loads)
4. Select a **Model** (grouped by manufacturer)
5. Enter the device **MAC Address**
6. Configure settings using the category panels (pulled from the template)
7. Click **Save Device**

### 2. Configure DHCP

Point **DHCP Option 66** to your FreePBX server:

```
http://<your-freepbx-ip>/admin/modules/quickprovisioner/provision.php?mac={mac}
```

### 3. Boot Phones

Reboot or factory-reset your handsets. They will auto-provision from Quick-Provisioner.

---

## 📁 File Hosting

The provisioning server hosts files at these endpoints:

| Endpoint | Content | Directory |
|----------|---------|-----------|
| `provision.php?mac={MAC}` | Device configuration | Dynamic (from database + template) |
| `/ringtones/{file}` | Ringtone WAV files | `assets/ringtones/` |
| `/firmware/{file}` | Firmware binaries | `assets/firmware/` |
| `/phonebook/{file}` | XML phonebook | `assets/phonebook/` |
| `media.php?mac={MAC}` | Auto-resized wallpaper | `assets/uploads/` |

### File Storage Location

Asset files are stored within the module directory:
```
quickprovisioner/
├── assets/
│   ├── uploads/      → Wallpaper images (JPG/PNG/GIF)
│   ├── ringtones/    → WAV ringtone files
│   ├── firmware/     → Firmware binaries (.rom, .ld, .bin)
│   └── phonebook/    → Per-device XML phonebooks
└── templates/        → Mustache template files
```

---

## 📊 Access Logging

Monitor all handset provisioning requests in the **Admin → Access Log** section:

- **Real-time tracking** of all provisioning requests
- **MAC address** and **extension** resolution
- **Resource type** tracking — config, wallpaper, ringtone, firmware, phonebook
- **Client IP** and **User-Agent** logging

---

## 🔧 Template System

### Bundled Templates

| Template | File | Handsets |
|----------|------|----------|
| Yealink T4x/T5x | `yealink_t4x.cfg.mustache` | T54W, T46U, T48G, T57W, T58W |
| Polycom VVX | `polycom_vvx.xml.mustache` | VVX series, Edge E series |
| Cisco 8800 MPP | `cisco_88xx.xml.mustache` | 8851, 8865 (3PCC) |

### Template Compatibility

Templates are **fully interchangeable** between Quick-Provisioner and [Pocket Provisioner](https://github.com/Ezra90/Pocket-Provisioner). Both apps use the same Mustache format with embedded META blocks for categories, variables, and visual editor data.

### Custom Templates

1. Navigate to the **Templates** tab
2. Paste or upload a `.mustache` template file
3. The template must include a `{{! META: { ... } }}` comment block

Templates use **Mustache** syntax with variables like:
```mustache
{{sip_server}}          <!-- SIP registrar address -->
{{extension}}           <!-- User extension -->
{{password}}            <!-- SIP password (auto-fetched from FreePBX) -->
{{wallpaper_url}}       <!-- Full URL to wallpaper -->
{{ringtone_url}}        <!-- Full URL to ringtone -->
{{firmware_url}}        <!-- Full URL to firmware -->
{{phonebook_url}}       <!-- Full URL to phonebook -->
```

### Template Categories

Settings are organised into expandable category panels (defined in the template META):

| Category | Icon | Settings |
|----------|------|----------|
| SIP & Registration | 📞 | Server, port, transport, proxy, backup |
| Display & Audio | 📱 | Ringtone, screensaver timeout |
| Security | 🔑 | Admin password, web UI toggle |
| Network | 🌐 | Voice VLAN, data VLAN, CDP/LLDP |
| Call Features | 📲 | Auto-answer, DND, call waiting, forwarding |
| Provisioning & Time | 🔧 | NTP, timezone, firmware URL |
| Diagnostics & Logs | 🔍 | Syslog server, debug level |

---

## 🖼️ Wallpaper Specifications

The module auto-resizes images to match each phone model:

| Phone Model | Resolution | Format |
|-------------|------------|--------|
| Yealink T54W / T46U | 480 × 272 | PNG |
| Yealink T48G / T57W | 800 × 480 | PNG |
| Yealink T58W | 1024 × 600 | PNG |
| Poly VVX 150–350 / Edge E350 | 320 × 240 | PNG |
| Poly VVX 450 / Edge E450 | 480 × 272 | PNG |
| Poly VVX 1500 | 800 × 480 | PNG |
| Cisco 8851 / 8865 | 800 × 480 | PNG |

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

## 🏗️ Project Structure

```
quickprovisioner/
├── Quickprovisioner.class.php    → FreePBX BMO module class
├── MustacheEngine.php            → Mustache template renderer + META parser
├── provision.php                 → Provisioning endpoint (phones fetch configs here)
├── media.php                     → Wallpaper image resizer
├── ajax.quickprovisioner.php     → Admin AJAX backend API
├── page.quickprovisioner.php     → Admin web interface
├── install.php                   → Module install / DB migration hooks
├── uninstall.php                 → Module uninstall hooks
├── module.xml                    → FreePBX module descriptor
├── scripts/qp-update             → CLI update helper script
├── assets/                       → Runtime asset storage (uploads, ringtones, firmware)
└── templates/                    → Mustache template files (.mustache)
```

---

## 📋 Requirements

| Requirement | Version |
|-------------|---------|
| FreePBX | 13.0+ (16.0+ recommended) |
| PHP | 7.4+ with GD extension |
| MySQL/MariaDB | 5.7+ |
| Git | Required for updates |

---

## 🔒 Security

- **Local network only** — Admin UI is restricted to local network access
- **CSRF protection** — All POST requests validate CSRF tokens
- **Per-device auth** — Remote provisioning requires HTTP Basic Auth credentials
- **Path traversal prevention** — All file operations use `basename()` and `realpath()` validation
- **Asset directory protection** — `.htaccess` files disable PHP execution in upload directories

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

This project is licensed under the MIT License — see the [LICENSE](LICENSE) file for details.

---

<p align="center">
  Made with ❤️ for Telecommunications Technicians
</p>
