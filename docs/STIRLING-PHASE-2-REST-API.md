# Stirling Phase 2 REST API

Phase 2 adds a read-only Roku manifest API without starting the screenshot worker, Roku app, pairing, or device management work.

## Endpoints

List published displays:

```text
GET /wp-json/stirling-foyer/v1/displays
```

Example response:

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

Get a display manifest:

```text
GET /wp-json/stirling-foyer/v1/displays/office-upstairs
```

Example response:

```json
{
  "schemaVersion": 1,
  "generatedAt": "2026-07-13T19:00:00Z",
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
      "fit": "cover",
      "revision": "stable-slide-revision",
      "durationSeconds": 8
    }
  ],
  "warnings": [],
  "revision": "stable-manifest-revision"
}
```

## Behaviour

- Display lists include only published `foyer_display` posts.
- Display manifests are resolved through `Foyer_Display::get_active_channel()`.
- Scheduled/default channel behavior therefore matches the existing Foyer browser player.
- Slide order comes from `Foyer_Channel::get_slides()`.
- Unpublished slides are excluded by the existing Foyer channel model.
- Image-background slides return public attachment URLs from the `foyer` image size.
- External webpage iframe slides return an unsupported slide record and warning. Screenshot generation is intentionally deferred.
- Invalid or unpublished display slugs return a REST 404.
- Responses do not expose arbitrary post meta, WordPress users, filesystem paths, or admin-only settings.

## Manual Testing

List displays:

```sh
curl -i http://of-k9/display/wp-json/stirling-foyer/v1/displays
```

Fetch the example display manifest:

```sh
curl -i http://of-k9/display/wp-json/stirling-foyer/v1/displays/office-upstairs
```

Check a missing display returns 404:

```sh
curl -i http://of-k9/display/wp-json/stirling-foyer/v1/displays/not-a-display
```

Confirm the existing Samsung/browser player still loads:

```text
http://of-k9/display/foyer/office-upstairs/
```

## Test Command

```sh
phpunit tests/test-foyer-includes-roku-rest-api.php
```
