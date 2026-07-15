# Phase 5: Slide Scheduling

Phase 5 adds optional per-slide visibility windows for Foyer slides.

## Slide fields

Each slide can define:

- Show from
- Until

Both fields are optional. Blank fields mean no restriction.

The admin input format is:

```text
YYYY-MM-DD HH:MM
```

Values are parsed in the WordPress site timezone and stored as Unix timestamps in post meta:

```text
foyer_slide_show_from
foyer_slide_show_until
```

Show from is inclusive. Until is exclusive.

## Runtime behavior

The shared `Foyer_Channel::get_slides()` path filters out unpublished, future, and expired slides while preserving configured slide order. This path is used by the normal browser player and the Roku REST manifest.

The Roku snapshot worker uses `Foyer_Channel::get_current_and_future_slides()` so future iframe slides can be rendered before they become visible. Expired iframe slides are skipped.

## Iframe snapshot allowlist

When an iframe slide is saved with a valid `http` or `https` URL without credentials, the normalized hostname is added to the Roku snapshot allowed-host list if it is not already present.

The host list does not store URL paths or query strings, and hosts are not removed automatically when a slide changes or expires.

## Manual Validation

1. Edit a slide and set Show from to a future time.
2. Load a Foyer browser display containing that slide and confirm the slide is omitted.
3. Load the Roku REST manifest for the same display and confirm the slide is omitted.
4. Move Show from into the past and confirm the slide appears after the normal content or manifest refresh.
5. Set Until to the current time or a past time and confirm the slide is omitted.
6. Save an iframe slide with a valid host and confirm the host appears under the Roku snapshot allowed hosts setting.
7. Run the Roku snapshot worker and confirm current and future iframe slides are processed, while expired iframe slides are skipped.
