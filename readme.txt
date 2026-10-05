=== Media Verdict ===
Contributors: vanflab
Tags: media, media library, images, unused images, cleanup, optimization, performance, storage, woocommerce, snapshot
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 0.3.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Fail-safe media library usage detector: green/red frames in the library, evidence of where each image is used, snapshot-first safe deletion with trash and one-click restore.

== Description ==

Media Verdict scans your whole site — post content (all post types, drafts and revisions), post meta (featured images, WooCommerce galleries, Elementor data), term meta, user meta, options (widgets, customizer, logo, site icon) and builder/slider sources (Smart Slider 3, Revolution Slider, LayerSlider, Master Slider, Visual Composer, WPBakery, Cornerstone, Oxygen) — and tells you which media library items are in use and which have **no detected usage**.

Fail-safe by design:

* When in doubt, an item is marked as **used**.
* The UI says "no detected usage", never "guaranteed unused" — no scanner can see dynamic references (hardcoded CSS, JS-built URLs, email templates).
* Deletion **always** creates a ZIP snapshot first (original + all generated sizes, with md5 manifest).
* Deletion **always** goes to the WordPress trash, never forced.
* One-click restore from the snapshot, with integrity verification.

In the Media Library (grid and list modes) used items get a green frame and unused-detected items a red frame with a badge; hovering or clicking shows the evidence ("Used in: Post #123, Product #45").

WP-CLI: `wp media-verdict scan|list|snapshot|trash|restore|report`.

== Installation ==

1. Upload the `media-verdict` folder to `wp-content/plugins/`.
2. Activate it.
3. Go to Media → Media Verdict and run a scan.

== Changelog ==

= 0.3.1 =
* Fixed: scans started from wp-admin lost all evidence between AJAX requests (the driver creates a new scanner per request) and marked everything as unused. Evidence is now persisted per batch in the `media_verdict_scan_evidence` option and merged back before finalizing, so admin scans produce the same verdicts as CLI scans.

= 0.3.0 =
* New builder parsers: Cornerstone (`_cornerstone_data` "ID:size" attachment references) and Oxygen (`ct_builder_json` numeric `attachment_id`s, with stripslashes for its escaped JSON). Both detected and verified against real test pages.
* Builder coverage is now: Smart Slider 3, Revolution Slider, LayerSlider, Master Slider, Visual Composer, WPBakery, Cornerstone, Oxygen — plus Divi, Beaver Builder and Depicter via the generic content matcher.

= 0.2.0 =
* Builder/slider parsers: Smart Slider 3, Revolution Slider, LayerSlider, Master Slider, the new Visual Composer and classic WPBakery shortcodes — each with its own storage format (custom tables, escaped JSON, base64 blobs, template placeholders). Filterable registry (`media_verdict_parsers`).
* Fixed: URL normalizer regex that silently broke every URL-based match.
* Settings: new "Parsers activos" list showing which builders were detected on the site.

= 0.1.0 =
* Initial release: detection engine, library overlays, snapshot/trash/restore flow, WP-CLI.
