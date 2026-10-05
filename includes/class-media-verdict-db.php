<?php
/**
 * Database layer: index table (verdicts) and audit log table.
 *
 * @package Media_Verdict
 */

defined( 'ABSPATH' ) || exit;

/**
 * Handles creation and CRUD for the plugin's own tables.
 */
class Media_Verdict_DB {

	/**
	 * Index table name (with prefix).
	 *
	 * @return string
	 */
	public static function index_table() {
		global $wpdb;
		return $wpdb->prefix . 'media_verdict_index';
	}

	/**
	 * Audit log table name (with prefix).
	 *
	 * @return string
	 */
	public static function audit_table() {
		global $wpdb;
		return $wpdb->prefix . 'media_verdict_audit';
	}

	/**
	 * Creates both tables with dbDelta.
	 *
	 * @return void
	 */
	public static function create_tables() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();

		$index = self::index_table();
		$sql   = "CREATE TABLE {$index} (
			attachment_id BIGINT(20) UNSIGNED NOT NULL,
			status VARCHAR(16) NOT NULL DEFAULT 'unused',
			evidence LONGTEXT NULL,
			file_size BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			scanned_at DATETIME NULL,
			PRIMARY KEY (attachment_id),
			KEY status (status)
		) {$charset};";
		dbDelta( $sql );

		$audit = self::audit_table();
		$sql   = "CREATE TABLE {$audit} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			action VARCHAR(32) NOT NULL,
			attachment_ids LONGTEXT NULL,
			snapshot_file VARCHAR(255) NULL,
			details LONGTEXT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			KEY action (action)
		) {$charset};";
		dbDelta( $sql );
	}

	/**
	 * Upserts a verdict row for an attachment.
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $status        'used' or 'unused'.
	 * @param array  $evidence      List of evidence entries.
	 * @param int    $file_size     Total bytes (original + sizes).
	 * @return void
	 */
	public static function upsert_verdict( $attachment_id, $status, array $evidence, $file_size ) {
		global $wpdb;

		$wpdb->replace( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			self::index_table(),
			array(
				'attachment_id' => (int) $attachment_id,
				'status'        => 'used' === $status ? 'used' : 'unused',
				'evidence'      => wp_json_encode( array_values( $evidence ) ),
				'file_size'     => (int) $file_size,
				'scanned_at'    => current_time( 'mysql' ),
			),
			array( '%d', '%s', '%s', '%d', '%s' )
		);
	}

	/**
	 * Returns the verdict row for an attachment, or null.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return object|null
	 */
	public static function get_verdict( $attachment_id ) {
		global $wpdb;

		return $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				'SELECT * FROM ' . self::index_table() . ' WHERE attachment_id = %d', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$attachment_id
			)
		);
	}

	/**
	 * Returns verdict rows, optionally filtered by status.
	 *
	 * @param string $status  'used', 'unused' or 'all'.
	 * @param string $search  Optional search in attachment title/filename.
	 * @param int    $limit   Limit.
	 * @param int    $offset  Offset.
	 * @return array
	 */
	public static function get_verdicts( $status = 'all', $search = '', $limit = 50, $offset = 0 ) {
		global $wpdb;

		$table = self::index_table();
		$where = '1=1';
		$args  = array();

		if ( 'used' === $status || 'unused' === $status ) {
			$where .= ' AND i.status = %s';
			$args[] = $status;
		}

		if ( '' !== $search ) {
			$where .= ' AND (p.post_title LIKE %s OR pm.meta_value LIKE %s)';
			$args[] = '%' . $wpdb->esc_like( $search ) . '%';
			$args[] = '%' . $wpdb->esc_like( $search ) . '%';
		}

		$args[] = (int) $limit;
		$args[] = (int) $offset;

		$sql = "SELECT i.*, p.post_title, p.post_mime_type, pm.meta_value AS attached_file
			FROM {$table} i
			LEFT JOIN {$wpdb->posts} p ON p.ID = i.attachment_id
			LEFT JOIN {$wpdb->postmeta} pm ON pm.post_id = i.attachment_id AND pm.meta_key = '_wp_attached_file'
			WHERE {$where}
			ORDER BY i.file_size DESC
			LIMIT %d OFFSET %d";

		return $wpdb->get_results( $wpdb->prepare( $sql, $args ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Counts verdict rows by status.
	 *
	 * @param string $status 'used', 'unused' or 'all'.
	 * @return int
	 */
	public static function count_verdicts( $status = 'all' ) {
		global $wpdb;

		$table = self::index_table();
		if ( 'used' === $status || 'unused' === $status ) {
			return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . $table . ' WHERE status = %s', $status ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
		}

		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $table ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Sums file sizes for a status (recoverable estimate).
	 *
	 * @param string $status 'used' or 'unused'.
	 * @return int
	 */
	public static function sum_sizes( $status ) {
		global $wpdb;

		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT SUM(file_size) FROM ' . self::index_table() . ' WHERE status = %s', $status ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Deletes verdict rows for attachments that no longer exist.
	 *
	 * @return int Number of rows deleted.
	 */
	public static function prune_missing() {
		global $wpdb;

		$table = self::index_table();
		return (int) $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			"DELETE i FROM {$table} i LEFT JOIN {$wpdb->posts} p ON p.ID = i.attachment_id WHERE p.ID IS NULL"
		);
	}

	/**
	 * Writes an audit log entry.
	 *
	 * @param string $action         Action slug (scan, snapshot, trash, restore, protect, ...).
	 * @param array  $attachment_ids Attachment IDs involved.
	 * @param string $snapshot_file Snapshot file name, if any.
	 * @param string $details       Human-readable details.
	 * @return void
	 */
	public static function audit( $action, array $attachment_ids = array(), $snapshot_file = '', $details = '' ) {
		global $wpdb;

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			self::audit_table(),
			array(
				'action'         => substr( (string) $action, 0, 32 ),
				'attachment_ids' => wp_json_encode( array_values( array_map( 'intval', $attachment_ids ) ) ),
				'snapshot_file'  => substr( (string) $snapshot_file, 0, 255 ),
				'details'        => (string) $details,
				'created_at'     => current_time( 'mysql' ),
			),
			array( '%s', '%s', '%s', '%s', '%s' )
		);
	}

	/**
	 * Returns recent audit entries.
	 *
	 * @param int $limit Limit.
	 * @return array
	 */
	public static function get_audit( $limit = 50 ) {
		global $wpdb;

		return $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( 'SELECT * FROM ' . self::audit_table() . ' ORDER BY id DESC LIMIT %d', (int) $limit ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		);
	}
}
