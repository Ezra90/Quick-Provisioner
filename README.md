# Quick Provisioner for FreePBX

> ⚠️ **Early Access** — This module is under active development. Things will change frequently.

**Quick Provisioner** turns your FreePBX server into a permanent **HTTP Provisioning Server (DMS)** for VoIP handsets. It serves device configurations, wallpapers, ringtones, firmware, and phonebooks — all managed through the FreePBX web interface.

> 📱 **Companion App:** [Pocket Provisioner](https://github.com/Ezra90/Pocket-Provisioner) — Android field utility using the same Mustache template format.

---

## Features

- Always-on provisioning server hosted on FreePBX
- Mustache templates (interchangeable with Pocket Provisioner)
- Visual button layout editor with drawn handset SVG preview
- Wallpaper upload and auto-resize
- Ringtone and firmware hosting
- Per-device HTTP Basic Auth
- FreePBX extension/secret integration
- Git-based updates from web UI

## Supported Handsets

- **Yealink** — T54W, T46U, T48G, T57W, T58W, T58G
- **Poly (Polycom)** — VVX150, VVX250, VVX350, VVX450, VVX1500, Edge E350, E450
- **Cisco MPP** — 8851, 8865 (3PCC/MPP firmware)

---

## Installation

```bash
cd /var/www/html/admin/modules
git clone https://github.com/Ezra90/freepbx-quickprovisioner.git quickprovisioner
chown -R asterisk:asterisk quickprovisioner
fwconsole ma install quickprovisioner
fwconsole reload
```

Then navigate to **Admin → Quick Provisioner** in FreePBX.

## Updating

From the web UI: **Admin tab → Check for Updates → Apply Update**

Or from CLI:

```bash
cd /var/www/html/admin/modules/quickprovisioner
git pull origin main
chown -R asterisk:asterisk /var/www/html/admin/modules/quickprovisioner
fwconsole reload
```

---

## Quick Start

1. Open **Admin → Quick Provisioner**
2. Enter MAC address, select model, select extension
3. Configure line keys using the visual button editor
4. Click **Save**
5. Point DHCP Option 66 to: `http://<your-freepbx-ip>/admin/modules/quickprovisioner/provision.php?mac={mac}`
6. Boot/reboot the phone

---

## Project Structure

```
quickprovisioner/
├── Quickprovisioner.class.php    → FreePBX BMO module class
├── MustacheEngine.php            → Mustache template renderer
├── provision.php                 → Provisioning endpoint
├── media.php                     → Wallpaper image resizer
├── ajax.quickprovisioner.php     → Admin AJAX backend
├── page.quickprovisioner.php     → Admin web interface
├── install.php                   → Module install hooks
├── uninstall.php                 → Module uninstall hooks
├── module.xml                    → FreePBX module descriptor
├── scripts/qp-update             → CLI update helper
└── templates/                    → Mustache template files
```
