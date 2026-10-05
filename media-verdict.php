<?php
/**
 * Plugin Name: Media Verdict
 * Description: Fail-safe media library usage detector. Shows which images are in use (green) and which have no detected usage (red) right inside the Media Library, with snapshot-first safe deletion, trash and one-click restore.
 * Version: 0.3.1
 * Requires at least: 6.4
 * Requires PHP: 8.0
 * Author: Vanf Lab
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: media-verdict
 * Domain Path: /languages
 */

defined( 'ABSPATH' ) || exit;

define( 'MEDIA_VERDICT_VERSION', '0.3.1' );
define( 'MEDIA_VERDICT_PLUGIN_FILE', __FILE__ );
define( 'MEDIA_VERDICT_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'MEDIA_VERDICT_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'MEDIA_VERDICT_SNAPSHOT_DIR', WP_CONTENT_DIR . '/media-verdict/snapshots' );

require_once MEDIA_VERDICT_PLUGIN_DIR . 'includes/class-media-verdict-db.php';
require_once MEDIA_VERDICT_PLUGIN_DIR . 'includes/class-media-verdict-tokens.php';
require_once MEDIA_VERDICT_PLUGIN_DIR . 'includes/class-media-verdict-parsers.php';
require_once MEDIA_VERDICT_PLUGIN_DIR . 'includes/class-media-verdict-snapshot.php';
require_once MEDIA_VERDICT_PLUGIN_DIR . 'includes/class-media-verdict-scanner.php';
require_once MEDIA_VERDICT_PLUGIN_DIR . 'includes/class-media-verdict-library.php';
require_once MEDIA_VERDICT_PLUGIN_DIR . 'includes/class-media-verdict-admin.php';
require_once MEDIA_VERDICT_PLUGIN_DIR . 'includes/class-media-verdict.php';

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once MEDIA_VERDICT_PLUGIN_DIR . 'includes/class-media-verdict-cli.php';
}

register_activation_hook( __FILE__, 'media_verdict_activate' );

/**
 * Runs on plugin activation: creates the index + audit tables, the snapshots
 * directory (protected with index.php and .htaccess deny) and default options.
 *
 * @return void
 */
function media_verdict_activate() {
	Media_Verdict_DB::create_tables();
	Media_Verdict_Snapshot::ensure_snapshot_dir();

	add_option( 'media_verdict_retention_days', 30 );
	add_option( 'media_verdict_protected', array() );
	add_option( 'media_verdict_last_scan', '' );
	add_option( 'media_verdict_scan_state', array() );
}

add_action( 'plugins_loaded', array( 'Media_Verdict', 'instance' ) );
