# Upstream

This repository is the Stirling Marathon Limited internal fork of Foyer.

## Upstream Source

- Repository: https://github.com/mennolui/wp-foyer
- Reviewed baseline commit: `910cc5109def610b4f8efc7ce33beba464e25c2e`
- Reviewed upstream version: `1.7.6`
- Local upstream mirror branch: `master`
- Long-lived Stirling branch: `stirling-main`

## Sync Procedure

1. Fetch upstream changes into the upstream mirror.
2. Keep `master` as an untouched mirror of upstream.
3. Create integration branches from `stirling-main`.
4. Merge or cherry-pick upstream changes into the integration branch.
5. Resolve conflicts without changing Foyer post types, meta keys, text domain, or class names unless a migration is deliberately planned.
6. Run the WordPress PHPUnit suite and manually verify an existing Foyer display.
7. Merge reviewed changes back into `stirling-main`.

Do not commit directly on the production server.
