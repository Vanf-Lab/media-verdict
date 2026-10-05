<?php
/**
 * Uninstall: drops the plugin's tables and options.
 *
 * Snapshots are NEVER deleted silently: the wp-content/media-verdict/
 * directory is left in place with a LEEME.txt note so no backup is ever
 * lost by uninstalling.
 *
 * @package Media_Verdict
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

$index = $wpdb->prefix . 'media_verdict_index';
$audit = $wpdb->prefix . 'media_verdict_audit';

$wpdb->query( "DROP TABLE IF EXISTS {$index}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
$wpdb->query( "DROP TABLE IF EXISTS {$audit}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

delete_option( 'media_verdict_retention_days' );
delete_option( 'media_verdict_protected' );
delete_option( 'media_verdict_last_scan' );
delete_option( 'media_verdict_scan_state' );

// Leave snapshots behind on purpose, with an explanatory note.
$dir = WP_CONTENT_DIR . '/media-verdict/snapshots';
if ( file_exists( $dir ) ) {
	file_put_contents( // phpcs:ignore WordPress.WP.AlternativeFunctions
		trailingslashit( $dir ) . 'LEEME.txt',
		"Media Verdict fue desinstalado.\n" .
		"Estos snapshots se conservaron a propósito: contienen respaldos de archivos multimedia.\n" .
		"Podés borrar este directorio manualmente cuando ya no los necesites.\n"
	);
}
