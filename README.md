# Media Verdict

**Find out which media your WordPress site actually uses — with proof, not guesses.**

Media Verdict scans your entire site and shows, right inside the Media Library, which items are in use and which have *no detected usage*. Every verdict comes with evidence ("Used in: Post #123, Product #45"). And when you clean up, it snapshots everything first — so deleting is never a leap of faith.

![Version](https://img.shields.io/badge/version-0.3.1-blue)
![License](https://img.shields.io/badge/license-GPL--2.0--or--later-green)
![WordPress](https://img.shields.io/badge/wordpress-6.4%2B-blue)
![PHP](https://img.shields.io/badge/php-8.0%2B-purple)

## Why it's different

Most "unused image" plugins give you a list and a delete button. Media Verdict is built around one uncomfortable truth: **no scanner can prove an image is unused** — dynamic references (hardcoded CSS, JS-built URLs, email templates) are invisible to every plugin. So instead of pretending:

- 🟢🔴 **Visual verdicts in the library** — green frame = in use, red frame = no detected usage, in grid and list views.
- 🔍 **Evidence per item** — every "used" verdict tells you exactly where the item appears.
- 🛡️ **Fail-safe by design** — when in doubt, it's marked *used*. The UI says "no detected usage", never "guaranteed unused".
- 📦 **Snapshot-first deletion** — every cleanup creates a ZIP snapshot (original file + all generated sizes + md5 manifest) *before* anything moves.
- 🗑️ **Trash, never force-delete** — items go to the WordPress trash; one-click restore with integrity verification.

## What it scans

Post content (all post types, including drafts and revisions), post meta (featured images, WooCommerce galleries, Elementor data), term meta, user meta, options (widgets, Customizer, logo, site icon) and uploads-directory references — plus dedicated parsers for builders and sliders:

| Source | Detection method |
|---|---|
| Smart Slider 3 | custom tables |
| Revolution Slider | escaped JSON in custom tables |
| LayerSlider | custom tables |
| Master Slider | base64 blobs |
| Visual Composer (new) | placeholder + urlencoded JSON |
| WPBakery (classic) | `image="ID"` shortcode attributes |
| Cornerstone | `_cornerstone_data` (`"image_src":"ID:size"`) |
| Oxygen | `ct_builder_json` (`attachment_id`, unescaped) |
| Divi, Beaver Builder, Depicter | generic content matcher |

The parser registry is filterable (`media_verdict_parsers`) — new builders can be added without touching core.

## Proven at scale

Real-world test on a WooCommerce staging site: **6,070 attachments / 8.1 GB scanned in 33 seconds** → 4,964 used, 1,106 with no detected usage (1.37 GB reclaimable). Five-image trash → snapshot → restore round-trip verified byte-perfect (md5 match, original attachment IDs preserved).

## WP-CLI

```bash
wp media-verdict scan      # full scan
wp media-verdict list      # list verdicts
wp media-verdict snapshot  # snapshot only
wp media-verdict trash     # move unused to trash (snapshot first)
wp media-verdict restore   # restore from snapshot
wp media-verdict report    # summary report
```

## Installation

1. Upload the `media-verdict` folder to `wp-content/plugins/`.
2. Activate the plugin.
3. Go to **Media → Media Verdict** and run a scan.

Recommended: enable the trash flow with `define( 'MEDIA_TRASH', true );` in `wp-config.php`.

## FAQ

**Can it guarantee an image is 100% unused?**
No — and neither can any plugin on the market. Dynamic references (CSS, JS-built URLs, email templates) can't be seen by static analysis. That's why Media Verdict is fail-safe: doubt means "used", deletion always snapshots first, and restore is one click.

**Is it safe to run on a client/production site?**
Scanning is read-only. Cleanup requires an explicit action, always snapshots first, and always uses the trash — nothing is ever force-deleted.

**Does it work with page builders?**
Yes — Elementor, WPBakery, Visual Composer, Cornerstone, Oxygen, Divi, Beaver Builder — plus 8 slider plugins (see table above).

## Changelog

See [readme.txt](readme.txt) for the full changelog.

## Contributing

Issues and pull requests are welcome. The parser registry (`media_verdict_parsers` filter) is the main extension point — if you find a builder whose images aren't detected, open an issue with the storage format and we'll add a parser.

## License

GPL-2.0-or-later. Copyright (c) 2026 Vanf. See [LICENSE](LICENSE).
