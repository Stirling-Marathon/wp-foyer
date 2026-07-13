# Foyer + Roku Digital Signage — Codex Handoff

## Project owner

Stirling Marathon Limited

## Source repository

Upstream:

- Repository: `mennolui/wp-foyer`
- Upstream default branch: `master`
- Reviewed baseline commit: `910cc5109def610b4f8efc7ce33beba464e25c2e`
- Upstream plugin version: `1.7.6`
- License: GPLv3 or later

Target fork:

- GitHub owner: `StirlingMarathonLimited`
- Recommended repository name: `wp-foyer`
- Long-lived customization branch: `stirling-main`
- Keep upstream `master` clean for comparison and future merges.

## Environment

Internal-only Ubuntu LAMP server with full administrative control.

WordPress administration:

- `http://of-k9.stirling/display/wp-admin`

Example Foyer display:

- `http://of-k9/display/foyer/office-upstairs/`

Important: the two URLs use different hostnames (`of-k9.stirling` and `of-k9`). Do not normalize or change these without first checking WordPress `home`/`siteurl`, Apache virtual hosts, DNS, and existing TV access. The Roku feed should return URLs resolvable by the Roku TVs.

Displays:

- 5 Samsung TVs using their integrated browsers and normal Foyer display URLs.
- 4 Roku TVs using one sideloaded Roku application.
- The Roku TVs are not expected to need another sideloaded development application.

## Existing Foyer data model

Keep Foyer as the only content-management system.

```text
Foyer Display
    -> active/default/scheduled Channel
        -> ordered published Slides
```

Relevant classes:

- `Foyer_Display`
  - post type: `foyer_display`
  - `get_active_channel()` resolves scheduled/default channels.
- `Foyer_Channel`
  - post type: `foyer_channel`
  - `get_slides()` returns ordered published slides.
  - `get_slides_duration()`
  - `get_slides_transition()`
- `Foyer_Slide`
  - post type: `foyer_slide`
  - `get_format()`
  - `get_background()`

Relevant metadata:

- Display default channel: `foyer_channel`
- Display schedule: `foyer_display_schedule`
- Channel slide list: `foyer_slide`
- Channel slide duration: `foyer_channel_slides_duration`
- Channel transition: `foyer_channel_slides_transition`
- Slide format: `slide_format`
- Slide background: `slide_background`
- Image attachment: `slide_bg_image_image`
- External webpage URL: `slide_iframe_website_url`

## Primary goals

1. Maintain all displays, channels, schedules, slide order, and content in Foyer.
2. Preserve the current browser player for the 5 Samsung TVs.
3. Add a native Roku image player for the 4 Roku TVs.
4. Convert Foyer external-webpage slides to periodically refreshed screenshots for Roku.
5. Add a WordPress admin settings page for global values currently hardcoded in Foyer.
6. Keep the project maintainable as a fork and prevent WordPress.org from overwriting it.

## Non-goals

- Do not attempt to embed or wrap arbitrary HTML in Roku. Roku has no general-purpose browser/WebView for this use case.
- Do not create a second independent playlist database.
- Do not modify production data structures unnecessarily.
- Do not add cloud services or external SaaS dependencies.
- Do not require HTTPS during the first internal proof of concept, but isolate URL handling so HTTPS can be added later.
- Do not build a public Roku Channel Store release during the initial project.

# Phase 0 — Fork hygiene and baseline

## Plugin identity

Update the main plugin header in `foyer.php`:

- Plugin Name: `Stirling Foyer`
- Description: note that it is the internal Stirling fork.
- Version: start with a version greater than `1.7.6`, for example `1.8.0-stirling.1`.
- Author: preserve upstream attribution and add Stirling Marathon Limited.
- Plugin URI: the fork repository URL.
- Add:
  - `Update URI: https://github.com/StirlingMarathonLimited/wp-foyer`

Keep:

- Text domain: `foyer`
- Main plugin file: `foyer.php`
- Internal class names and post-type slugs.

Reason: changing text domains, post types, or class prefixes would create needless migration and compatibility risk.

## Upstream documentation

Add:

- `UPSTREAM.md`
  - upstream repository URL
  - reviewed baseline commit
  - current upstream version
  - sync procedure
- `CHANGELOG-STIRLING.md`
  - internal changes only
- Update `README.txt` or add `README.md` explaining this is an internal fork.

## Branch discipline

- `master`: untouched upstream mirror.
- `stirling-main`: deployable internal branch.
- Feature branches from `stirling-main`.
- Pull requests merge into `stirling-main`.
- Never commit directly on the production server.

# Phase 1 — Global settings page

Foyer currently states that it has no settings page. Add a `Foyer -> Settings` submenu using the WordPress Settings API.

Suggested option name:

```php
foyer_settings
```

Suggested initial fields:

```text
transition_duration_seconds
content_refresh_seconds
forced_reload_seconds
roku_manifest_refresh_seconds
webpage_snapshot_refresh_seconds
webpage_snapshot_width
webpage_snapshot_height
```

Defaults:

```text
transition_duration_seconds = 1.5
content_refresh_seconds = 300
forced_reload_seconds = 28800
roku_manifest_refresh_seconds = 60
webpage_snapshot_refresh_seconds = 120
webpage_snapshot_width = 1920
webpage_snapshot_height = 1080
```

Validation:

- Transition duration: float, minimum `0`, maximum `10`.
- Refresh intervals: integer with safe minimums.
- Width/height: positive integers with reasonable limits.
- Require `manage_options`.
- Use nonces and Settings API sanitization.
- Escape all output.

Suggested new class:

```text
admin/class-foyer-admin-settings.php
```

Suggested settings helper:

```text
includes/class-foyer-settings.php
```

The helper should centralize defaults and sanitized option access.

## Remove hardcoded browser timing

Current hardcoded public player values include:

```text
CSS transition duration = 1.5 seconds
JavaScript transition duration = 1.5 seconds
Content refresh interval = 300 seconds
Forced page reload = 28800 seconds
```

Do not generate an entire dynamic CSS file.

Preferred implementation:

1. Enqueue the existing stylesheet.
2. Add narrowly scoped inline CSS with `wp_add_inline_style()` for the configured transition duration.
3. Pass browser timing settings to JavaScript with `wp_localize_script()` or `wp_add_inline_script()` before the player script.
4. Refactor the readable source JavaScript if available; regenerate/minify only through a documented build step.
5. Do not hand-edit only the minified file without also adding a maintainable source file/build process.

The CSS and JavaScript transition values must always derive from the same sanitized setting.

# Phase 2 — Read-only Roku REST API

Add a public read-only REST namespace:

```text
stirling-foyer/v1
```

Initial endpoints:

```text
GET /wp-json/stirling-foyer/v1/displays
GET /wp-json/stirling-foyer/v1/displays/{slug}
```

Because the site and displays are internal-only, unauthenticated read access is acceptable for the initial implementation. Do not expose WordPress users, settings, filesystem paths, or unrelated post metadata.

## Display list response

Return only published displays:

```json
{
  "displays": [
    {
      "id": 123,
      "slug": "office-upstairs",
      "name": "Office Upstairs"
    }
  ]
}
```

## Display manifest response

Resolve the active channel by calling Foyer's existing classes. Do not duplicate scheduling logic.

Example:

```json
{
  "schemaVersion": 1,
  "generatedAt": "2026-07-13T14:00:00Z",
  "revision": "sha256-or-stable-revision-value",
  "refreshSeconds": 60,
  "display": {
    "id": 123,
    "slug": "office-upstairs",
    "name": "Office Upstairs"
  },
  "channel": {
    "id": 456,
    "name": "Office",
    "durationSeconds": 8,
    "transition": "fade",
    "transitionDurationSeconds": 1.5
  },
  "slides": [
    {
      "id": 789,
      "type": "image",
      "sourceType": "foyer-image",
      "url": "http://of-k9/display/wp-content/uploads/example.jpg",
      "durationSeconds": 8,
      "fit": "cover",
      "revision": "stable-cache-buster"
    }
  ]
}
```

Rules:

- Preserve Foyer slide order.
- Return only published slides.
- Use channel defaults where Foyer already does.
- Generate absolute URLs.
- Return a stable revision value that changes when the effective manifest changes.
- Set REST cache headers deliberately.
- Do not return raw serialized post metadata.

## Initial supported slide handling

### Image-background slides

Return the WordPress attachment URL at an appropriate Full HD size.

### External webpage slides

Return a snapshot URL after Phase 3 is implemented.

### Unsupported formats

Do not fail the whole manifest. Return a controlled unsupported record or omit the slide and include a warning array.

Example:

```json
{
  "warnings": [
    {
      "slideId": 999,
      "code": "unsupported_slide_type",
      "message": "Roku output is not available for this slide."
    }
  ]
}
```

# Phase 3 — Webpage snapshot worker

Foyer's external webpage slide is an HTML iframe using `slide_iframe_website_url`. Roku cannot render that. Produce a PNG snapshot for Roku while Samsung TVs continue using the live iframe.

## Architecture

Use a separate local worker process:

```text
WordPress/Foyer slide metadata
    -> worker discovers active webpage slides
    -> Playwright/Chromium loads each URL
    -> PNG written to a controlled cache directory
    -> REST manifest returns snapshot URL
```

Do not launch Chromium synchronously from a WordPress REST or page request.

## Preferred implementation

Add a WordPress CLI command or a small standalone script that bootstraps WordPress:

```text
wp foyer roku-snapshots refresh
```

The command should:

1. Load published Foyer displays.
2. Resolve active channels using `Foyer_Display::get_active_channel()`.
3. Find iframe slides.
4. Validate target URLs against an allowlist.
5. Request screenshot generation.
6. Record success/failure metadata.
7. Preserve the last successful snapshot on failure.

A systemd timer is preferred over WP-Cron because the server is fully controlled and the task launches a browser process.

## Snapshot storage

Use a dedicated directory under uploads, for example:

```text
wp-content/uploads/foyer-roku/
```

Example file:

```text
slide-789-<content-hash>.png
```

Requirements:

- Temporary file followed by atomic rename.
- Never serve partially written images.
- Old snapshot cleanup with conservative retention.
- WordPress-readable and web-server-readable permissions.
- No world-writable directories.
- Last successful snapshot remains available after a failed refresh.

## Browser settings

Defaults from Foyer settings:

```text
viewport = 1920x1080
device scale factor = 1
full page = false
```

Wait strategy should be configurable or conservative. Do not use an indefinite `networkidle` wait on dashboards that poll continuously. Prefer:

- DOM content loaded
- optional fixed settle delay
- per-slide timeout

## URL security

Even on an internal network, treat stored webpage URLs as untrusted input.

- Permit only `http` and `https`.
- Add an admin-configured hostname allowlist.
- Reject credentials embedded in URLs.
- Block file URLs and unsupported schemes.
- Do not expose Playwright debugging endpoints.
- Log URL, slide ID, status, and error summary without logging secrets.

# Phase 4 — Roku application

Create the Roku app in a separate top-level directory or separate repository. Preferred initial location if kept together:

```text
roku/
```

Suggested structure:

```text
roku/
  manifest
  source/
    main.brs
  components/
    MainScene.xml
    MainScene.brs
    ManifestTask.xml
    ManifestTask.brs
    SettingsScene.xml
    SettingsScene.brs
  images/
```

## First-run configuration

For four devices, do not build a pairing server initially.

On first launch:

1. Fetch `/displays`.
2. Show a native list of display names.
3. User selects the display with the Roku remote.
4. Store the selected display slug in `roRegistry`.
5. Provide a settings action to change the assignment.

Do not require typing a full URL with the Roku remote.

The base API URL may be:

- compiled into the sideloaded build initially; or
- editable in an advanced settings screen.

Initial expected base:

```text
http://of-k9/display/wp-json/stirling-foyer/v1
```

Verify hostname resolution from the Roku VLAN/network before relying on `of-k9`. If necessary, use the resolvable internal FQDN consistently.

## Player requirements

- Fetch display manifest asynchronously.
- Validate JSON before replacing the active playlist.
- Keep last-known-good manifest in memory and optionally registry/cache.
- Preload the next image.
- Display full-screen using native SceneGraph nodes.
- Support `cover` and `contain`.
- Advance according to `durationSeconds`.
- Implement `none`, `fade`, and basic `slide` transitions where practical.
- Refresh manifest according to `refreshSeconds`.
- Apply new manifest at a safe boundary.
- Recover from failed image loads.
- Show a controlled local error screen only when no valid playlist has ever loaded.
- Do not loop rapidly on errors.
- Avoid logging full URLs if they may later contain secrets.

## Roku HTTP note

The current environment is HTTP. Treat HTTP transport as an early proof-of-concept checkpoint. If the Roku platform/build rejects or restricts an internal HTTP resource, add internal HTTPS or a resolvable certificate strategy rather than weakening validation broadly.

## Sideloading

The same application package is sideloaded to all four TVs. Each TV keeps its own selected display slug in `roRegistry`.

Document:

- Developer Mode enablement.
- Device web installer URL.
- ZIP packaging command.
- Update procedure.
- Registry reset/reassignment procedure.

# Phase 5 — Operations

## Production deployment

Do not use the WordPress.org plugin updater for the fork.

Recommended deployment options:

1. Git checkout in the plugin directory with production tracking `stirling-main`; or
2. Versioned release directories with an atomic symlink.

At minimum:

- Back up the current `foyer` plugin directory.
- Back up the WordPress database.
- Confirm the fork preserves existing CPT names and metadata.
- Test the example display:
  - `http://of-k9/display/foyer/office-upstairs/`
- Confirm Samsung browser playback before deploying Roku features.
- Disable page caching for `/display/foyer/*` and the manifest endpoints if a cache is later introduced.

## Logging

WordPress:

- use `error_log()` only behind `WP_DEBUG_LOG`, or create a small controlled logger;
- do not log secrets or complete settings dumps.

Snapshot worker:

- systemd journal;
- include slide ID, display ID, duration, result, and concise error.

Roku:

- concise developer-console logging;
- no excessive polling logs in steady state.

# Acceptance criteria

## Fork/settings

- The customized plugin is visibly identified as the Stirling fork.
- WordPress.org does not offer to overwrite it.
- Existing displays/channels/slides remain intact.
- Transition duration is configurable in wp-admin.
- Browser CSS and JS use the same configured transition duration.
- Refresh and forced-reload intervals are configurable.
- The existing Samsung display still works.

## REST API

- `/displays` lists the expected published Foyer displays.
- `/displays/office-upstairs` resolves the correct active channel.
- Scheduling behaviour matches the normal Foyer browser output.
- Manifest slide order matches Foyer.
- Image URLs load from a Roku-accessible client.
- Invalid display slugs return a proper REST error/404.

## Snapshot worker

- A Foyer external-webpage slide produces a 1920x1080 snapshot.
- A failed refresh leaves the last successful snapshot in place.
- The worker never blocks a WordPress page or REST request.
- Snapshot URLs change or invalidate cache when content changes.

## Roku

- First launch allows display selection without typing a URL.
- Assignment persists after app exit and TV reboot.
- Images rotate for at least 24 hours without manual intervention.
- Temporary WordPress/network failure does not blank a working display.
- Manifest changes appear within the configured refresh window.
- All four Roku TVs can run the same sideloaded ZIP with different display assignments.

# Testing expectations

Before implementation, inspect the current repository and write down:

- actual WordPress/PHP minimums in the target server;
- whether readable, unminified JS/CSS sources exist;
- how plugin asset builds are currently produced;
- whether PHPUnit tests still execute on the current PHP version;
- exact plugin filesystem path on `of-k9`;
- WordPress `home` and `siteurl`;
- hostname resolution from a Roku TV.

Add tests where practical:

- PHP unit tests for settings sanitization.
- PHP tests for manifest generation and active-channel resolution.
- REST tests for invalid slugs and unsupported slide types.
- Snapshot worker validation tests without requiring a live Chromium run for every test.
- Manual Roku soak test checklist.

# First Codex task

Work only on Phase 0 and Phase 1 initially.

1. Inspect the repository before editing.
2. Create a feature branch from `stirling-main`.
3. Add fork identity/update protection.
4. Add the settings helper and Foyer Settings submenu.
5. Replace hardcoded transition/content-refresh/full-reload values with sanitized settings.
6. Preserve all existing Foyer data and front-end behaviour by default.
7. Add or update tests.
8. Document all changed files and exact deployment/testing commands.
9. Do not begin the Roku API or Roku application until Phase 1 is reviewed.

Return:

- summary of repository findings;
- implementation plan;
- files changed;
- test results;
- known compatibility risks;
- a PR-ready diff.
