# Linux deployment — HCC WhatsApp Bridge

This complements `docs/WINDOWS_DEPLOYMENT.md` and the macOS LaunchAgent installer.
It covers running `automation/whatsapp-bridge.js` under **systemd**, using the same
`automation/start-bridge.sh` supervisor that macOS uses.

The admission / AI / MySQL stack on Linux is the same PHP app. Point Apache (or
nginx + php-fpm) at the repo, set `config/env.php` with `DB_SOCKET` empty (or a
real Linux MySQL socket), and use TCP `127.0.0.1` when in doubt.

## Prerequisites

- Node.js 18+ on `PATH` (or reachable from the service environment)
- Google Chrome or Chromium (`CHROME_PATH` if not in a standard location)
- The bridge dependencies: `cd automation && npm install`
- A writable session dir at `automation/.whatsapp-session` (created on first run)

## Install the bridge service (user)

From the repo root:

```bash
chmod +x automation/start-bridge.sh automation/linux/install-systemd.sh
./automation/linux/install-systemd.sh
```

That renders `automation/linux/hcc-whatsapp-bridge.service.template` with the
absolute paths of **this** checkout (no hardcoded `/Applications` or
`C:\xampp` paths), installs it as a **systemd --user** unit, and starts it.

```bash
systemctl --user status hcc-whatsapp-bridge
journalctl --user -u hcc-whatsapp-bridge -f
```

To keep the user service running after logout:

```bash
loginctl enable-linger "$USER"
```

Unload:

```bash
./automation/linux/install-systemd.sh --unload
```

## System-wide service (optional)

```bash
sudo ./automation/linux/install-systemd.sh --system
sudo journalctl -u hcc-whatsapp-bridge -f
```

Prefer the user unit unless you deliberately want a machine-wide service.

## Environment

`start-bridge.sh` already looks for Node under common prefixes. For the service
you may also set (in the unit or in `automation/.env`):

| Variable | Purpose |
|---|---|
| `CHROME_PATH` | Absolute Chrome/Chromium binary |
| `WHATSAPP_IPC_DIR` | Shared IPC dir with PHP (default `/tmp` on Linux) |
| `API_BASE_URL` | PHP admin base, default `http://localhost/hcc/admin` |
| `WHATSAPP_BRIDGE_SECRET` | Must match `config/env.php` (16+ chars) |
| `WHATSAPP_REVOKE_WINDOW_SEC` | Optional override for delete-for-everyone window |

PHP pairing / logout flags use the same IPC directory rules as the bridge
(`WHATSAPP_IPC_DIR`, else `/tmp`, else `sys_get_temp_dir()`).

## Health checks

```bash
curl -sS 'http://127.0.0.1/hcc/admin/api_whatsapp_session.php?action=status&qa_test=1'
php tests/test_whatsapp_capabilities.php
php tests/test_whatsapp_revoke.php
```

Do not call the deployment production-ready until the admission e2e test passes
on **this** machine: `php tests/admission_workflow_e2e.php`.

## macOS / Windows equivalents

| OS | Install |
|---|---|
| macOS | `./automation/install-launchd.sh` (template plist) |
| Windows | `automation\start-bridge.bat` or `automation\windows\start-bridge.ps1` (+ optional NSSM via `register-services.ps1 -IncludeBridge`) |
| Linux | `./automation/linux/install-systemd.sh` |
