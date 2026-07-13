# Stirling Phase 0 and Phase 1 Notes

## Repository Findings

- WordPress minimum in plugin metadata: `Requires at least: 4.1`.
- Historical CI used PHP `5.3` and `5.6`; no current Composer, npm, Grunt, or package build file is present.
- Readable public JavaScript sources exist under `raw/js/public/`.
- Readable public LESS sources exist under `raw/less/public/`.
- Generated public assets are committed under `public/js/foyer-public-min.js` and `public/css/foyer-public.css`.
- The generated JavaScript appears to have been assembled from CodeKit `@codekit-prepend` comments in `raw/js/public/foyer-public.js`.
- PHPUnit tests are WordPress PHPUnit tests and require `WP_TESTS_DIR`; `bin/install-wp-tests.sh` is present for setting up the test library.

## Phase 0 Changes

- Main plugin metadata identifies the fork as Stirling Foyer.
- `FOYER_PLUGIN_VERSION` matches the fork header version.
- `Update URI` points at the Stirling repository to prevent WordPress.org replacement of the fork.
- `UPSTREAM.md` documents the upstream baseline and sync procedure.
- `CHANGELOG-STIRLING.md` tracks internal fork changes.
- `readme.md` now opens with a Stirling fork note.

## Phase 1 Changes

- Added `Foyer_Settings` as the central source for defaults, sanitization, and option reads.
- Added `Foyer -> Settings` with Settings API registration and `manage_options` access.
- Added configurable browser timing values:
  - transition duration
  - content refresh interval
  - forced reload interval
- Added reserved Roku and snapshot settings for later phases without implementing the REST API, worker, or Roku application.
- Public transition CSS is overridden with narrowly scoped inline CSS from the sanitized transition duration.
- Public JavaScript receives sanitized timing through `wp_localize_script()`.
- Raw source JavaScript and the committed generated script were both updated. No build tool is present in this repository.

## Deployment Commands

From the plugin directory on the target server:

```sh
git fetch origin
git checkout stirling-main
git pull --ff-only origin stirling-main
```

If deploying this feature branch for review:

```sh
git fetch origin
git checkout stirling-phase-0-1
git pull --ff-only origin stirling-phase-0-1
```

After deployment, open WordPress admin and verify:

```text
http://of-k9.stirling/display/wp-admin
```

Manual display smoke test:

```text
http://of-k9/display/foyer/office-upstairs/
```

## Testing Commands

Set up WordPress tests if needed:

```sh
bash bin/install-wp-tests.sh wordpress_test root '' localhost latest
```

Run all tests:

```sh
phpunit
```

Run only the new settings tests:

```sh
phpunit tests/test-foyer-includes-settings.php
```

## Manual Checks

- Confirm the plugin row shows `Stirling Foyer`.
- Confirm WordPress does not offer the upstream WordPress.org Foyer update over the fork.
- Confirm `Foyer -> Settings` is visible only to administrators.
- Save default settings and reload an existing display.
- Change transition duration, content refresh, and forced reload values, then confirm the display page source includes matching localized timing and inline transition CSS.

## Deferred Items

- Exact plugin filesystem path on `of-k9`.
- WordPress `home` and `siteurl`.
- Hostname resolution from Roku TV network.
- Current target server PHP version.

These require target-server access and were not changed in Phase 0 or Phase 1.
