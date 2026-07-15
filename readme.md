# WordPress Foyer

WordPress Foyer is a fork of the Foyer digital signage plugin for WordPress. It keeps the original Foyer browser player, post types, metadata, and text domain, then adds:

- read-only Roku display manifest endpoints;
- Roku-compatible image output for image slides;
- external webpage snapshots for Roku via a command-line worker;
- optional per-slide Show from / Until scheduling;
- a native Roku SceneGraph app in `roku-app/`.

The upstream project remains Foyer for WordPress by Menno Luitjes. See `UPSTREAM.md` for the upstream baseline and sync notes.

## Requirements

### WordPress Plugin

- WordPress with pretty permalinks enabled.
- PHP version supported by the target WordPress installation.
- Writable WordPress uploads directory.
- WP-CLI for snapshot refresh commands.

### Webpage Snapshots

External webpage slides continue to render as live iframes in the normal Foyer browser player. Roku devices cannot render those iframes directly, so this fork uses a separate snapshot worker.

Required server software:

- Node.js
- npm
- Playwright
- Chromium installed through Playwright
- WP-CLI
- systemd, for production scheduling on Ubuntu/Debian-style servers

The snapshot worker must run outside web requests. Do not run Chromium from a REST request, page render, admin save action, or WP-Cron.

Recommended execution model:

```text
systemd timer
  -> WP-CLI command
    -> Node.js Playwright worker
      -> Chromium
      -> PNG snapshot in wp-content/uploads/foyer-roku/
```

### Roku App

The Roku app can be built from this repository. See `roku-app/README.md` for the private API URL config, build commands, sideloading, and BrightScript debugging.

## Ubuntu Installation

Install system dependencies:

```bash
sudo apt update
sudo apt install -y php-cli unzip curl ca-certificates nodejs npm
```

Install WP-CLI if it is not already installed:

```bash
curl -O https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar
php wp-cli.phar --info
chmod +x wp-cli.phar
sudo mv wp-cli.phar /usr/local/bin/wp
```

Install Node dependencies from the plugin directory:

```bash
cd /var/www/wordpress/wp-content/plugins/foyer
npm install
```

Install Playwright Chromium into a shared browser directory:

```bash
sudo mkdir -p /opt/ms-playwright
sudo chown www-data:www-data /opt/ms-playwright
sudo -u www-data env PLAYWRIGHT_BROWSERS_PATH=/opt/ms-playwright npm run install-browsers
```

Create the snapshot uploads directory:

```bash
sudo mkdir -p /var/www/wordpress/wp-content/uploads/foyer-roku
sudo chown www-data:www-data /var/www/wordpress/wp-content/uploads/foyer-roku
sudo chmod 755 /var/www/wordpress/wp-content/uploads/foyer-roku
```

Adjust paths and the web user for your server. Some Ubuntu packages install Node.js and npm separately; using NodeSource, nvm, or distro packages is fine as long as the systemd service points to the correct `node` binary.

## WordPress Configuration

Activate the plugin in WordPress, then review the Foyer settings page.

Important settings:

- Roku manifest refresh interval
- Webpage snapshot refresh interval
- Webpage snapshot width
- Webpage snapshot height
- Webpage snapshot timeout
- Webpage snapshot settle delay
- Webpage snapshot allowed hosts

Default snapshot viewport:

```text
1920 x 1080
```

Allowed hosts are intentionally explicit. Iframe URLs are treated as untrusted input. Only `http` and `https` URLs without embedded credentials are accepted for snapshots. Saving a valid iframe slide automatically adds its normalized host to the allowed-host list, but hosts are not removed automatically.

## REST API

The Roku app uses these read-only endpoints:

```text
GET /wp-json/foyer/v1/displays
GET /wp-json/foyer/v1/displays/{slug}
```

Example:

```bash
curl http://example.internal/wp-json/foyer/v1/displays
curl http://example.internal/wp-json/foyer/v1/displays/lobby
```

The manifest returns only published displays, the active published channel, and currently eligible published slides. Slide timing comes from the active channel's Duration setting (8 seconds by default), and the channel's Transition setting controls the transition effect. Image slides return Roku-compatible image records. Iframe slides return a snapshot image when one exists; otherwise the manifest includes a controlled warning and continues.

## Snapshot Worker

Run a one-off refresh:

```bash
sudo -u www-data env PLAYWRIGHT_BROWSERS_PATH=/opt/ms-playwright \
  wp --path=/var/www/wordpress \
  foyer roku-snapshots refresh \
  --node=/usr/bin/node \
  --worker=/var/www/wordpress/wp-content/plugins/foyer/bin/foyer-roku-snapshot-worker.js
```

Successful output looks like:

```text
display=16 channel=7 slide=15 host=dashboard.example.internal status=updated duration=5.349s message=
Roku snapshots complete processed=1 updated=1 unchanged=0 failed=0 skipped=0 duration=5.356s
```

Snapshots are stored under:

```text
wp-content/uploads/foyer-roku/
```

The worker writes to a temporary PNG first, then atomically replaces the public snapshot only after a successful render. Previous successful snapshots remain in place if a refresh fails.

When the same iframe slide is used by multiple displays or active channels, the worker renders that slide once per refresh run and reuses the same snapshot file.

## systemd Timer

Example files are provided:

```text
docs/systemd/foyer-roku-snapshots.service
docs/systemd/foyer-roku-snapshots.timer
```

Copy and edit them for your paths:

```bash
sudo cp docs/systemd/foyer-roku-snapshots.service /etc/systemd/system/foyer-roku-snapshots.service
sudo cp docs/systemd/foyer-roku-snapshots.timer /etc/systemd/system/foyer-roku-snapshots.timer
sudo systemctl daemon-reload
```

Before enabling, edit:

- `User`
- `Group`
- `WorkingDirectory`
- `Environment=PLAYWRIGHT_BROWSERS_PATH=/opt/ms-playwright`
- `ExecStart`
- `OnUnitActiveSec`

Enable and start the timer:

```bash
sudo systemctl enable --now foyer-roku-snapshots.timer
```

Run once manually:

```bash
sudo systemctl start foyer-roku-snapshots.service
```

Inspect logs:

```bash
journalctl -u foyer-roku-snapshots.service -n 100 --no-pager
```

The systemd timer is the actual production scheduler. The WordPress refresh setting documents expected snapshot staleness and manifest behavior; it does not dynamically reconfigure systemd.

## Slide Scheduling

Slides support optional Show from and Until fields.

- Blank Show from means visible immediately.
- Blank Until means no expiry.
- Show from is inclusive.
- Until is exclusive.
- Dates are entered as `YYYY-MM-DD HH:MM` in the WordPress site timezone.

The normal browser player, Roku manifest, and snapshot worker all use shared server-side scheduling logic. The Roku app does not implement separate scheduling.

See `docs/PHASE-5-SLIDE-SCHEDULING.md` for manual validation steps.

## Roku App Build

Create local private config:

```bash
cp roku-app/config.example.json roku-app/config.local.json
```

Edit `roku-app/config.local.json`:

```json
{
  "apiBaseUrl": "http://example.internal/wp-json/foyer/v1"
}
```

Build on Linux/macOS:

```bash
./roku-app/scripts/build.sh --config ./roku-app/config.local.json
```

Build on Windows PowerShell:

```powershell
.\roku-app\scripts\build.ps1 -ConfigPath .\roku-app\config.local.json
```

Output:

```text
roku-app/dist/wordpress-foyer-roku.zip
```

`config.local.json`, `.build/`, and `dist/` are ignored by Git.

## Security Notes

- Do not commit `roku-app/config.local.json`.
- Do not commit generated Roku ZIP files.
- Do not commit Playwright browser downloads or `node_modules/`.
- Do not put usernames, passwords, cookies, or bearer tokens in iframe URLs.
- Do not allow broad host patterns unless the deployment explicitly accepts that SSRF risk.
- The REST API exposes display manifests only; it must not expose arbitrary post metadata, users, filesystem paths, or admin settings.

Run a quick local scan before publishing:

```bash
git grep -n "your-internal-hostname"
git status --ignored --short
```

## Rollback

Disable snapshot automation:

```bash
sudo systemctl disable --now foyer-roku-snapshots.timer
```

Stop a running snapshot service:

```bash
sudo systemctl stop foyer-roku-snapshots.service
```

Roll back plugin code using your normal deployment method, then clear opcode caches if your server uses them.

The browser player continues to use the original Foyer rendering path. Removing the Roku app or snapshot timer does not remove existing Foyer displays, channels, or slides.
