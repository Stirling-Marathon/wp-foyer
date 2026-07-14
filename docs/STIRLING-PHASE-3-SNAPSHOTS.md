# Stirling Phase 3 Webpage Snapshots

Phase 3 adds screenshot generation for active Foyer external webpage slides so Roku manifests can receive PNG images. It does not change the normal Foyer browser player; Samsung TVs continue to render the live iframe.

## Dependencies

Install these on the Ubuntu server:

- WP-CLI
- Node.js LTS
- npm
- Playwright npm dependency
- Playwright Chromium and Ubuntu browser libraries

Example Ubuntu commands:

```sh
cd /var/www/html/display/wp-content/plugins/wp-foyer
sudo apt-get update
sudo apt-get install -y ca-certificates curl
curl -fsSL https://deb.nodesource.com/setup_lts.x | sudo -E bash -
sudo apt-get install -y nodejs
npm install
npx playwright install --with-deps chromium
```

Install WP-CLI if it is not already present:

```sh
curl -O https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar
php wp-cli.phar --info
chmod +x wp-cli.phar
sudo mv wp-cli.phar /usr/local/bin/wp
```

Confirm the command can bootstrap WordPress:

```sh
wp --path=/var/www/html/display plugin list
```

## Settings

Configure `Foyer -> Settings`:

- Webpage snapshot refresh interval
- Webpage snapshot width
- Webpage snapshot height
- Webpage snapshot timeout
- Webpage snapshot settle delay
- Webpage snapshot allowed hosts

Initial allowed hosts:

```text
of-k9
of-k9.stirling
```

The systemd timer is the actual scheduler. The WordPress refresh setting documents/controls expected snapshot freshness and can be reused by future operational checks; it does not dynamically change the systemd timer interval.

## Worker Command

Run manually from the server:

```sh
cd /var/www/html/display/wp-content/plugins/wp-foyer
wp --path=/var/www/html/display foyer roku-snapshots refresh --node=/usr/bin/node --worker=/var/www/html/display/wp-content/plugins/wp-foyer/bin/foyer-roku-snapshot-worker.js
```

The command returns non-zero if any active iframe snapshot fails. It prints:

```text
processed
updated
unchanged
failed
skipped
```

## systemd

Example files are provided only; install them manually after adjusting paths and user:

```text
docs/systemd/foyer-roku-snapshots.service
docs/systemd/foyer-roku-snapshots.timer
```

Installation example:

```sh
sudo cp docs/systemd/foyer-roku-snapshots.service /etc/systemd/system/
sudo cp docs/systemd/foyer-roku-snapshots.timer /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now foyer-roku-snapshots.timer
systemctl list-timers foyer-roku-snapshots.timer
```

Manual run:

```sh
sudo systemctl start foyer-roku-snapshots.service
journalctl -u foyer-roku-snapshots.service -n 100 --no-pager
```

Do not run the service as root. Use the web/application user that owns the WordPress files and can write to uploads.

## Endpoint Output

Before a successful snapshot, an external webpage slide remains controlled unsupported:

```json
{
  "slides": [
    {
      "id": 15,
      "type": "unsupported",
      "sourceType": "foyer-iframe"
    }
  ],
  "warnings": [
    {
      "slideId": 15,
      "code": "snapshot_not_available"
    }
  ]
}
```

After a successful snapshot:

```json
{
  "id": 15,
  "type": "image",
  "sourceType": "foyer-iframe-snapshot",
  "url": "http://of-k9/display/wp-content/uploads/foyer-roku/slide-15.png",
  "fit": "cover",
  "revision": "sha256-content-hash",
  "durationSeconds": 8
}
```

Manual validation target:

```sh
curl -i http://of-k9.stirling/display/wp-json/stirling-foyer/v1/displays/factory-office
```

The snapshot URL for slide `15` should load directly in a browser.

Confirm Samsung/browser playback still works:

```text
http://of-k9/display/foyer/factory-office/
```

## Tests

Unit tests do not require Chromium:

```sh
phpunit tests/test-foyer-includes-roku-snapshots.php
phpunit tests/test-foyer-includes-roku-rest-api.php
phpunit tests/test-foyer-includes-settings.php
```

Manual browser integration test:

```sh
wp --path=/var/www/html/display foyer roku-snapshots refresh --node=/usr/bin/node --worker=/var/www/html/display/wp-content/plugins/wp-foyer/bin/foyer-roku-snapshot-worker.js
```

## Rollback

Disable the timer:

```sh
sudo systemctl disable --now foyer-roku-snapshots.timer
sudo rm /etc/systemd/system/foyer-roku-snapshots.service /etc/systemd/system/foyer-roku-snapshots.timer
sudo systemctl daemon-reload
```

Roll back the plugin branch or checkout the prior release. Existing snapshot files in `wp-content/uploads/foyer-roku/` are cache artifacts and can be removed after rollback if desired.

## Limitations

- No authentication-cookie handling is implemented.
- No video, animated capture, or full-page capture is implemented.
- IP literal hosts are rejected by default.
- Playwright/Chromium is required only for the CLI worker, never for REST requests.
