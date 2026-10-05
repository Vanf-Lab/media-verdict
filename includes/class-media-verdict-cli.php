<?php
/**
 * WP-CLI commands: wp media-verdict <scan|list|snapshot|trash|restore|report>.
 *
 * @package Media_Verdict
 */

defined( 'ABSPATH' ) || exit;

/**
 * Media Verdict commands.
 */
class Media_Verdict_CLI {

	/**
	 * Runs the full usage scan.
	 *
	 * ## EXAMPLES
	 *
	 *     wp media-verdict scan
	 *
	 * @return void
	 */
	public function scan() {
		$scanner = new Media_Verdict_Scanner();
		$summary = $scanner->run_full(
			function ( $phase, $done, $total ) {
				WP_CLI::log( sprintf( '%s: %d/%d', $phase, $done, $total ) );
			}
		);

		WP_CLI::success(
			sprintf(
				'Scan complete: %d total, %d used, %d unused (%.2f MB recoverable).',
				$summary['total'],
				$summary['used'],
				$summary['unused'],
				$summary['unused_mb']
			)
		);
	}

	/**
	 * Lists verdicts.
	 *
	 * ## OPTIONS
	 *
	 * [--status=<status>]
	 * : Filter by status: used, unused, all. Default: all.
	 *
	 * [--format=<format>]
	 * : Output format: table, json, csv. Default: table.
	 *
	 * [--limit=<n>]
	 * : Max rows. Default: 50.
	 *
	 * ## EXAMPLES
	 *
	 *     wp media-verdict list --status=unused --format=json
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Associative args.
	 * @return void
	 */
	public function list( $args, $assoc_args ) {
		$status = isset( $assoc_args['status'] ) ? $assoc_args['status'] : 'all';
		$format = isset( $assoc_args['format'] ) ? $assoc_args['format'] : 'table';
		$limit  = isset( $assoc_args['limit'] ) ? (int) $assoc_args['limit'] : 50;

		$rows = Media_Verdict_DB::get_verdicts( $status, '', $limit, 0 );

		$out = array();
		foreach ( $rows as $row ) {
			$out[] = array(
				'id'     => (int) $row->attachment_id,
				'title'  => $row->post_title,
				'file'   => $row->attached_file,
				'status' => $row->status,
				'size'   => (int) $row->file_size,
			);
		}

		WP_CLI\Utils\format_items( $format, $out, array( 'id', 'title', 'file', 'status', 'size' ) );
	}

	/**
	 * Creates a snapshot ZIP for attachments.
	 *
	 * ## OPTIONS
	 *
	 * --ids=<ids>
	 * : Comma-separated attachment IDs.
	 *
	 * [--label=<label>]
	 * : Label for the file name.
	 *
	 * ## EXAMPLES
	 *
	 *     wp media-verdict snapshot --ids=12,34 --label=manual
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Associative args.
	 * @return void
	 */
	public function snapshot( $args, $assoc_args ) {
		$ids   = isset( $assoc_args['ids'] ) ? array_map( 'intval', explode( ',', $assoc_args['ids'] ) ) : array();
		$label = isset( $assoc_args['label'] ) ? sanitize_file_name( $assoc_args['label'] ) : '';

		$result = Media_Verdict_Snapshot::create( $ids, $label );
		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		WP_CLI::success( sprintf( 'Snapshot %s created (%d attachments).', $result['file'], $result['count'] ) );
	}

	/**
	 * Moves attachments to trash, creating a snapshot first.
	 * The snapshot is mandatory: if it fails, nothing is trashed.
	 *
	 * ## OPTIONS
	 *
	 * --ids=<ids>
	 * : Comma-separated attachment IDs.
	 *
	 * [--yes]
	 * : Skip the confirmation.
	 *
	 * ## EXAMPLES
	 *
	 *     wp media-verdict trash --ids=12,34 --yes
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Associative args.
	 * @return void
	 */
	public function trash( $args, $assoc_args ) {
		$ids = isset( $assoc_args['ids'] ) ? array_map( 'intval', explode( ',', $assoc_args['ids'] ) ) : array();
		$ids = array_values( array_filter( array_unique( $ids ) ) );

		if ( empty( $ids ) ) {
			WP_CLI::error( 'No attachment IDs given.' );
		}

		if ( ! isset( $assoc_args['yes'] ) ) {
			WP_CLI::confirm( sprintf( 'Create a snapshot and move %d attachment(s) to trash?', count( $ids ) ) );
		}

		if ( ! Media_Verdict_Snapshot::media_trash_available() ) {
			WP_CLI::error( 'Media trash is not enabled on this site (MEDIA_TRASH). Refusing to trash: without it, wp_delete_attachment() would force-delete. Add define( \'MEDIA_TRASH\', true ); to wp-config.php first.' );
		}

		$snap = Media_Verdict_Snapshot::create( $ids, 'pre-trash' );
		if ( is_wp_error( $snap ) ) {
			WP_CLI::error( 'Snapshot failed, nothing trashed: ' . $snap->get_error_message() );
		}
		WP_CLI::log( 'Snapshot: ' . $snap['file'] );

		foreach ( $ids as $id ) {
			$result = wp_delete_attachment( $id, false );
			if ( $result ) {
				WP_CLI::log( "Trashed #{$id}." );
			} else {
				WP_CLI::warning( "Could not trash #{$id}." );
			}
		}

		Media_Verdict_DB::audit( 'trash', $ids, $snap['file'], 'CLI trash with snapshot.' );
		WP_CLI::success( 'Done.' );
	}

	/**
	 * Restores files (and missing attachment posts) from a snapshot.
	 *
	 * ## OPTIONS
	 *
	 * --snapshot=<file>
	 * : Snapshot file name (basename).
	 *
	 * ## EXAMPLES
	 *
	 *     wp media-verdict restore --snapshot=media-verdict-20260101-120000.zip
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Associative args.
	 * @return void
	 */
	public function restore( $args, $assoc_args ) {
		$file = isset( $assoc_args['snapshot'] ) ? $assoc_args['snapshot'] : '';

		$result = Media_Verdict_Snapshot::restore( $file );
		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		WP_CLI::success(
			sprintf(
				'Restored %d files and %d attachment posts.',
				$result['restored_files'],
				$result['restored_posts']
			)
		);
	}

	/**
	 * Prints a summary report of the last scan.
	 *
	 * ## EXAMPLES
	 *
	 *     wp media-verdict report
	 *
	 * @return void
	 */
	public function report() {
		$summary = ( new Media_Verdict_Scanner() )->summary();

		WP_CLI::log( sprintf( 'Total attachments : %d', $summary['total'] ) );
		WP_CLI::log( sprintf( 'Used            : %d', $summary['used'] ) );
		WP_CLI::log( sprintf( 'Unused detected : %d', $summary['unused'] ) );
		WP_CLI::log( sprintf( 'Recoverable     : %.2f MB', $summary['unused_mb'] ) );
		WP_CLI::log( sprintf( 'Last scan       : %s', $summary['last_scan'] ? $summary['last_scan'] : 'never' ) );
	}
}

WP_CLI::add_command( 'media-verdict', 'Media_Verdict_CLI' );
