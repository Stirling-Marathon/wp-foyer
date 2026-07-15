# WordPress Foyer Roku App

Native Roku SceneGraph signage player for the WordPress/Foyer REST manifest.

## Private Configuration

The real API URL is intentionally excluded from this public repository. Create a local config file before building:

```sh
cp roku-app/config.example.json roku-app/config.local.json
```

Edit `roku-app/config.local.json`:

```json
{
  "apiBaseUrl": "http://your-internal-server.example/wp-json/foyer/v1"
}
```

Confirm the local file is ignored by Git:

```sh
git status --ignored --short roku-app/config.local.json
```

Configuration precedence is:

1. Explicit build argument.
2. `WORDPRESS_FOYER_API_BASE_URL`.
3. `roku-app/config.local.json`.

The private URL is not tracked in Git and is not copied into the repository source tree. It is still embedded in the generated Roku ZIP because the Roku app needs it at runtime. Endpoint security must come from server-side authentication, firewalling, or network controls, not source-code hiding.

## Build

Windows:

```powershell
.\roku-app\scripts\build.ps1
```

Windows with explicit config:

```powershell
.\roku-app\scripts\build.ps1 -ConfigPath .\roku-app\config.local.json
```

Linux/macOS:

```sh
./roku-app/scripts/build.sh
```

Linux/macOS with explicit config:

```sh
./roku-app/scripts/build.sh --config ./roku-app/config.local.json
```

The build creates:

```text
roku-app/dist/wordpress-foyer-roku.zip
```

The build stages files under `roku-app/.build/`, generates `source/AppConfig.generated.brs` inside the staged package only, and then removes the staging directory.

## Verify Public Source

The internal hostname should not appear in tracked files:

```sh
git grep -n "<your-internal-hostname>"
```

Expected result: no matches.

Inspect generic API configuration references:

```sh
git grep -n "apiBaseUrl"
```

Expected result: generic configuration code and examples only.

Inspect the runtime ZIP:

```sh
tar -tf roku-app/dist/wordpress-foyer-roku.zip
```

Expected:

- `manifest` at ZIP root.
- `source/AppConfig.generated.brs` present.
- `config.local.json` absent.
- `config.example.json` absent.

## Sideloading

1. Enable Roku Developer Mode on the Roku device.
2. Note the Roku IP address from the developer-mode screen or network settings.
3. Open the Roku Development Application Installer in a browser:

   ```text
   http://<roku-ip>
   ```

4. Sign in with username `rokudev` and the developer password configured on that Roku.
5. Upload `dist/wordpress-foyer-roku.zip`.
6. Launch the application.
7. On first launch, select the Foyer display for that TV.
8. During playback, press `*` to open settings and choose `Change display`.
9. Press the Roku Home button to leave the application.

Only one development app can be sideloaded on a Roku at a time. Installing another sideloaded app replaces this one. Keeping the same application identity is important when testing whether registry values survive app updates. If registry data is reset, reselecting the display is harmless.

## Debug Output

Use the BrightScript console:

```text
telnet <roku-ip> 8085
```

Useful log lines include display-list requests, manifest refreshes, image cache hits/misses, image download results, offline cache activation, and skipped slides. The app does not log full image bodies or credentials.

## Runtime Behaviour

- First launch fetches `/displays`, shows a native list, and saves the selected slug.
- Later launches immediately request `/displays/{slug}`.
- Supported slide types are image slides from `foyer-image` and `foyer-iframe-snapshot`.
- The manifest `revision` controls adoption of changed content.
- Image cache keys use slide ID plus slide revision, so unchanged URLs can still refresh when the server revision changes.
- Temporary network failures keep the last valid content playing.
- If no live manifest is available, the app attempts to play the cached manifest and cached images.
- For unattended signage, disable the Roku screensaver and auto power saving in the device settings. Press the Roku Home button to leave the app.

## Artwork

The PNG files in `images/` are temporary placeholders. Replace them before production branding:

```text
icon_focus_hd.png
icon_side_hd.png
splash_hd.png
splash_fhd.png
```

Do not hotlink icons from WordPress.
