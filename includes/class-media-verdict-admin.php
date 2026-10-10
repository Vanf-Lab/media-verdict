<?php
/**
 * Admin page: Media → Media Verdict.
 *
 * Dashboard with counters, batched scanner with progress, filterable
 * verdict table with bulk actions, protected list, snapshots, audit
 * history and settings.
 *
 * Safety contract, enforced here:
 *  - Deletion ALWAYS creates a snapshot first.
 *  - Deletion ALWAYS goes to the WordPress trash (never forced).
 *  - UI copy says "sin uso detectado", never "guaranteed unused".
 *
 * @package Media_Verdict
 */

defined( 'ABSPATH' ) || exit;

/**
 * Renders the admin UI and handles its AJAX.
 */
class Media_Verdict_Admin {

	/**
	 * Registers hooks.
	 *
	 * @return void
	 */
	public static function hooks() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );

		add_action( 'wp_ajax_media_verdict_scan_start', array( __CLASS__, 'ajax_scan_start' ) );
		add_action( 'wp_ajax_media_verdict_scan_batch', array( __CLASS__, 'ajax_scan_batch' ) );
		add_action( 'wp_ajax_media_verdict_trash_dryrun', array( __CLASS__, 'ajax_trash_dryrun' ) );
		add_action( 'wp_ajax_media_verdict_bulk_trash', array( __CLASS__, 'ajax_bulk_trash' ) );
		add_action( 'wp_ajax_media_verdict_bulk_snapshot', array( __CLASS__, 'ajax_bulk_snapshot' ) );
		add_action( 'wp_ajax_media_verdict_protect', array( __CLASS__, 'ajax_protect' ) );
		add_action( 'wp_ajax_media_verdict_snapshot_restore', array( __CLASS__, 'ajax_snapshot_restore' ) );
		add_action( 'wp_ajax_media_verdict_snapshot_delete', array( __CLASS__, 'ajax_snapshot_delete' ) );
		add_action( 'wp_ajax_media_verdict_prune_snapshots', array( __CLASS__, 'ajax_prune_snapshots' ) );
	}

	/**
	 * Adds the submenu under Media.
	 *
	 * @return void
	 */
	public static function menu() {
		add_submenu_page(
			'upload.php',
			__( 'Media Verdict', 'media-verdict' ),
			__( 'Media Verdict', 'media-verdict' ),
			'manage_options',
			'media-verdict',
			array( __CLASS__, 'render' )
		);
	}

	/**
	 * Enqueues admin assets on our page.
	 *
	 * @param string $hook Hook suffix.
	 * @return void
	 */
	public static function enqueue( $hook ) {
		if ( 'media_page_media-verdict' !== $hook ) {
			return;
		}

		wp_enqueue_style(
			'media-verdict-admin',
			MEDIA_VERDICT_PLUGIN_URL . 'assets/css/media-verdict-admin.css',
			array(),
			MEDIA_VERDICT_VERSION
		);

		wp_enqueue_script(
			'media-verdict-admin',
			MEDIA_VERDICT_PLUGIN_URL . 'assets/js/media-verdict-admin.js',
			array( 'jquery' ),
			MEDIA_VERDICT_VERSION,
			true
		);

		wp_localize_script(
			'media-verdict-admin',
			'MediaVerdictAdmin',
			array(
				'ajax'  => admin_url( 'admin-ajax.php' ),
				'nonce' => wp_create_nonce( 'media_verdict_admin' ),
				'i18n'  => array(
					'scanning'  => __( 'Scanning…', 'media-verdict' ),
					'done'      => __( 'Scan complete.', 'media-verdict' ),
					'confirmTrash' => __( 'A snapshot will be created first and the files will go to the WordPress trash (not permanently deleted). Continue?', 'media-verdict' ),
					'selectFirst'  => __( 'Select at least one attachment.', 'media-verdict' ),
				),
			)
		);
	}

	/**
	 * Renders the page.
	 *
	 * @return void
	 */
	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'media-verdict' ) );
		}

		$scanner = new Media_Verdict_Scanner();
		$summary = $scanner->summary();

		$tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'dashboard'; // phpcs:ignore WordPress.Security.NonceVerification

		echo '<div class="wrap mv-wrap">';
		echo '<h1>' . esc_html__( 'Media Verdict', 'media-verdict' ) . '</h1>';
		echo '<p class="description">' . esc_html__( 'Fail-safe media library usage detector: when in doubt, a file is marked as in use. "No detected usage" means the scan found no references — it is not a guarantee: no scanner can see dynamic references (hardcoded CSS, JS-built URLs, email templates). Deletion always creates a snapshot first and uses the WordPress trash.', 'media-verdict' ) . '</p>';

		$tabs = array(
			'dashboard'  => __( 'Dashboard', 'media-verdict' ),
			'snapshots'  => __( 'Snapshots', 'media-verdict' ),
			'protected'  => __( 'Protected', 'media-verdict' ),
			'history'    => __( 'History', 'media-verdict' ),
			'settings'   => __( 'Settings', 'media-verdict' ),
		);

		echo '<h2 class="nav-tab-wrapper">';
		foreach ( $tabs as $slug => $label ) {
			$active = $tab === $slug ? ' nav-tab-active' : '';
			echo '<a class="nav-tab' . esc_attr( $active ) . '" href="' . esc_url( admin_url( 'upload.php?page=media-verdict&tab=' . $slug ) ) . '">' . esc_html( $label ) . '</a>';
		}
		echo '</h2>';

		switch ( $tab ) {
			case 'snapshots':
				self::render_snapshots();
				break;
			case 'protected':
				self::render_protected();
				break;
			case 'history':
				self::render_history();
				break;
			case 'settings':
				self::render_settings();
				break;
			default:
				self::render_dashboard( $summary );
				break;
		}

		echo '</div>';
	}

	/**
	 * Dashboard tab: counters, scan controls, verdict table.
	 *
	 * @param array $summary Summary.
	 * @return void
	 */
	private static function render_dashboard( $summary ) {
		$focus = isset( $_GET['media_verdict_focus'] ) ? (int) $_GET['media_verdict_focus'] : 0; // phpcs:ignore WordPress.Security.NonceVerification

		echo '<div class="mv-cards">';
		self::card( __( 'Total', 'media-verdict' ), number_format_i18n( $summary['total'] ), '' );
		self::card( __( 'In use', 'media-verdict' ), number_format_i18n( $summary['used'] ), 'mv-green' );
		self::card( __( 'No detected usage', 'media-verdict' ), number_format_i18n( $summary['unused'] ), 'mv-red' );
		self::card( __( 'Recoverable (est.)', 'media-verdict' ), $summary['unused_mb'] . ' MB', 'mv-red' );
		echo '</div>';

		echo '<p>';
		if ( $summary['last_scan'] ) {
			printf(
				/* translators: %s: date */
				esc_html__( 'Last scan: %s', 'media-verdict' ),
				esc_html( $summary['last_scan'] )
			);
		} else {
			esc_html_e( 'No scan has been run yet.', 'media-verdict' );
		}
		echo '</p>';

		echo '<p><button id="mv-scan-btn" class="button button-primary">' . esc_html__( 'Scan', 'media-verdict' ) . '</button> ';
		echo '<span id="mv-scan-progress" style="display:none"><span class="spinner is-active" style="float:none;margin:0 4px 0 0"></span><span id="mv-scan-label"></span></span></p>';
		echo '<div id="mv-scan-bar" style="display:none;max-width:600px;height:10px;background:#e5e5e5;border-radius:5px"><div id="mv-scan-fill" style="height:10px;width:0;background:#2271b1;border-radius:5px"></div></div>';

		self::render_table( $focus );
	}

	/**
	 * Renders a stat card.
	 *
	 * @param string $label Label.
	 * @param string $value Value.
	 * @param string $class Extra class.
	 * @return void
	 */
	private static function card( $label, $value, $class = '' ) {
		echo '<div class="mv-card ' . esc_attr( $class ) . '"><div class="mv-card-value">' . esc_html( $value ) . '</div><div class="mv-card-label">' . esc_html( $label ) . '</div></div>';
	}

	/**
	 * Verdict table with filters, search, pagination and bulk actions.
	 *
	 * @param int $focus Attachment ID to highlight.
	 * @return void
	 */
	private static function render_table( $focus = 0 ) {
		$status = isset( $_GET['media_verdict_status'] ) ? sanitize_key( $_GET['media_verdict_status'] ) : 'all'; // phpcs:ignore WordPress.Security.NonceVerification
		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$paged  = isset( $_GET['media_verdict_paged'] ) ? max( 1, (int) $_GET['media_verdict_paged'] ) : 1; // phpcs:ignore WordPress.Security.NonceVerification
		$per    = 20;
		$offset = ( $paged - 1 ) * $per;

		if ( ! in_array( $status, array( 'all', 'used', 'unused' ), true ) ) {
			$status = 'all';
		}

		$rows  = Media_Verdict_DB::get_verdicts( $status, $search, $per, $offset );
		$total = Media_Verdict_DB::count_verdicts( $status );
		$pages = max( 1, (int) ceil( $total / $per ) );

		$base = admin_url( 'upload.php?page=media-verdict' );

		echo '<h2>' . esc_html__( 'Verdicts', 'media-verdict' ) . '</h2>';

		// Filters.
		echo '<form method="get" class="mv-filters">';
		echo '<input type="hidden" name="page" value="media-verdict" />';
		foreach ( array( 'all' => __( 'All', 'media-verdict' ), 'used' => __( 'In use', 'media-verdict' ), 'unused' => __( 'No detected usage', 'media-verdict' ) ) as $slug => $label ) {
			$active = $status === $slug ? ' button-primary' : '';
			echo '<a class="button' . esc_attr( $active ) . '" href="' . esc_url( add_query_arg( array( 'media_verdict_status' => $slug, 'media_verdict_paged' => 1, 's' => $search ), $base ) ) . '">' . esc_html( $label ) . '</a> ';
		}
		echo '<input type="search" name="s" value="' . esc_attr( $search ) . '" placeholder="' . esc_attr__( 'Search…', 'media-verdict' ) . '" /> ';
		echo '<input type="hidden" name="media_verdict_status" value="' . esc_attr( $status ) . '" />';
		echo '<button class="button">' . esc_html__( 'Filter', 'media-verdict' ) . '</button>';
		echo '</form>';

		// Bulk actions.
		echo '<div class="mv-bulk">';
		echo '<button class="button" id="mv-bulk-snapshot">' . esc_html__( 'Create snapshot', 'media-verdict' ) . '</button> ';
		echo '<button class="button" id="mv-bulk-dryrun">' . esc_html__( 'Trash dry-run', 'media-verdict' ) . '</button> ';
		echo '<button class="button button-link-delete" id="mv-bulk-trash">' . esc_html__( 'Move to trash (with snapshot)', 'media-verdict' ) . '</button> ';
		echo '<button class="button" id="mv-bulk-protect">' . esc_html__( 'Protect', 'media-verdict' ) . '</button>';
		echo '<div id="mv-bulk-result" style="margin-top:8px"></div>';
		echo '</div>';

		echo '<table class="wp-list-table widefat fixed striped mv-table">';
		echo '<thead><tr>';
		echo '<td class="check-column"><input type="checkbox" id="mv-check-all" /></td>';
		echo '<th>' . esc_html__( 'File', 'media-verdict' ) . '</th>';
		echo '<th>' . esc_html__( 'Verdict', 'media-verdict' ) . '</th>';
		echo '<th>' . esc_html__( 'Size', 'media-verdict' ) . '</th>';
		echo '<th>' . esc_html__( 'Evidence', 'media-verdict' ) . '</th>';
		echo '</tr></thead><tbody>';

		if ( empty( $rows ) ) {
			echo '<tr><td colspan="5">' . esc_html__( 'No results. Run a scan to populate the table.', 'media-verdict' ) . '</td></tr>';
		}

		foreach ( $rows as $row ) {
			$id        = (int) $row->attachment_id;
			$thumb     = wp_get_attachment_image( $id, array( 60, 60 ) );
			$file      = $row->attached_file ? $row->attached_file : '#' . $id;
			$highlight = $focus === $id ? ' style="background:#fff8e1"' : '';

			echo '<tr' . $highlight . '>';
			echo '<th class="check-column"><input type="checkbox" class="mv-check" value="' . esc_attr( $id ) . '" /></th>';
			echo '<td>' . $thumb . '<br><code>' . esc_html( wp_basename( (string) $file ) ) . '</code><br><span class="description">#' . esc_html( $id ) . '</span></td>';

			if ( 'used' === $row->status ) {
				echo '<td><span class="mv-pill mv-used">' . esc_html__( 'In use', 'media-verdict' ) . '</span></td>';
			} else {
				echo '<td><span class="mv-pill mv-unused">' . esc_html__( 'No detected usage', 'media-verdict' ) . '</span></td>';
			}

			echo '<td>' . esc_html( size_format( (int) $row->file_size ) ) . '</td>';

			$summary = Media_Verdict_Library::evidence_summary( $row->evidence );
			echo '<td class="mv-evidence">' . esc_html( $summary ? $summary : __( '—', 'media-verdict' ) );
			echo ' <a href="#" class="mv-evidence-toggle">' . esc_html__( 'details', 'media-verdict' ) . '</a>';
			echo '<div class="mv-evidence-full" style="display:none">' . self::evidence_list_html( $row->evidence ) . '</div>';
			echo '</td>';

			echo '</tr>';
		}

		echo '</tbody></table>';

		// Pagination.
		if ( $pages > 1 ) {
			echo '<div class="tablenav"><div class="tablenav-pages">';
			for ( $i = 1; $i <= $pages; $i++ ) {
				$cls = $i === $paged ? ' button-primary' : '';
				echo '<a class="button' . esc_attr( $cls ) . '" href="' . esc_url( add_query_arg( array( 'media_verdict_status' => $status, 'media_verdict_paged' => $i, 's' => $search ), $base ) ) . '">' . esc_html( $i ) . '</a> ';
			}
			echo '</div></div>';
		}
	}

	/**
	 * Renders the evidence list as HTML.
	 *
	 * @param string $evidence_json Evidence JSON.
	 * @return string
	 */
	private static function evidence_list_html( $evidence_json ) {
		$evidence = json_decode( (string) $evidence_json, true );
		if ( ! is_array( $evidence ) || empty( $evidence ) ) {
			return '<em>' . esc_html__( 'No recorded evidence.', 'media-verdict' ) . '</em>';
		}

		$out = '<ul>';
		foreach ( $evidence as $entry ) {
			$out .= '<li>' . esc_html( $entry['label'] ) . ' <span class="description">(' . esc_html( $entry['confidence'] ) . ')</span></li>';
		}
		return $out . '</ul>';
	}

	/**
	 * Snapshots tab.
	 *
	 * @return void
	 */
	private static function render_snapshots() {
		echo '<h2>' . esc_html__( 'Snapshots', 'media-verdict' ) . '</h2>';
		echo '<p><button class="button" id="mv-prune-snapshots">' . esc_html__( 'Delete expired snapshots', 'media-verdict' ) . '</button> <span id="mv-prune-result"></span></p>';

		$snaps = Media_Verdict_Snapshot::list_all();
		if ( empty( $snaps ) ) {
			echo '<p>' . esc_html__( 'No snapshots yet.', 'media-verdict' ) . '</p>';
			return;
		}

		echo '<table class="wp-list-table widefat fixed striped"><thead><tr>';
		echo '<th>' . esc_html__( 'File', 'media-verdict' ) . '</th><th>' . esc_html__( 'Created', 'media-verdict' ) . '</th><th>' . esc_html__( 'Size', 'media-verdict' ) . '</th><th>' . esc_html__( 'Attachments', 'media-verdict' ) . '</th><th></th>';
		echo '</tr></thead><tbody>';

		foreach ( $snaps as $snap ) {
			$count = $snap['manifest'] && isset( $snap['manifest']['items'] ) ? count( $snap['manifest']['items'] ) : '?';
			echo '<tr>';
			echo '<td><code>' . esc_html( $snap['file'] ) . '</code></td>';
			echo '<td>' . esc_html( gmdate( 'Y-m-d H:i:s', $snap['created'] ) ) . '</td>';
			echo '<td>' . esc_html( size_format( $snap['bytes'] ) ) . '</td>';
			echo '<td>' . esc_html( $count ) . '</td>';
			echo '<td><button class="button mv-snap-restore" data-file="' . esc_attr( $snap['file'] ) . '">' . esc_html__( 'Restore', 'media-verdict' ) . '</button> ';
			echo '<button class="button button-link-delete mv-snap-delete" data-file="' . esc_attr( $snap['file'] ) . '">' . esc_html__( 'Delete', 'media-verdict' ) . '</button></td>';
			echo '</tr>';
		}

		echo '</tbody></table><div id="mv-snap-result" style="margin-top:8px"></div>';
	}

	/**
	 * Protected tab.
	 *
	 * @return void
	 */
	private static function render_protected() {
		$protected = array_map( 'intval', (array) get_option( 'media_verdict_protected', array() ) );

		echo '<h2>' . esc_html__( 'Protected', 'media-verdict' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Protected attachments are always marked as in use and never appear as trash candidates.', 'media-verdict' ) . '</p>';

		if ( empty( $protected ) ) {
			echo '<p>' . esc_html__( 'No protected attachments.', 'media-verdict' ) . '</p>';
			return;
		}

		echo '<table class="wp-list-table widefat fixed striped"><thead><tr><th>' . esc_html__( 'Attachment', 'media-verdict' ) . '</th><th></th></tr></thead><tbody>';
		foreach ( $protected as $id ) {
			$title = get_the_title( $id );
			echo '<tr><td>#' . esc_html( $id ) . ' ' . esc_html( $title ? '«' . $title . '»' : '' ) . '</td>';
			echo '<td><button class="button mv-unprotect" data-id="' . esc_attr( $id ) . '">' . esc_html__( 'Unprotect', 'media-verdict' ) . '</button></td></tr>';
		}
		echo '</tbody></table><div id="mv-protect-result" style="margin-top:8px"></div>';
	}

	/**
	 * History tab.
	 *
	 * @return void
	 */
	private static function render_history() {
		echo '<h2>' . esc_html__( 'Action history', 'media-verdict' ) . '</h2>';

		$entries = Media_Verdict_DB::get_audit( 100 );
		if ( empty( $entries ) ) {
			echo '<p>' . esc_html__( 'No recorded actions.', 'media-verdict' ) . '</p>';
			return;
		}

		echo '<table class="wp-list-table widefat fixed striped"><thead><tr>';
		echo '<th>' . esc_html__( 'Date', 'media-verdict' ) . '</th><th>' . esc_html__( 'Action', 'media-verdict' ) . '</th><th>' . esc_html__( 'Attachments', 'media-verdict' ) . '</th><th>' . esc_html__( 'Snapshot', 'media-verdict' ) . '</th><th>' . esc_html__( 'Details', 'media-verdict' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $entries as $e ) {
			echo '<tr>';
			echo '<td>' . esc_html( $e->created_at ) . '</td>';
			echo '<td><code>' . esc_html( $e->action ) . '</code></td>';
			echo '<td>' . esc_html( $e->attachment_ids ) . '</td>';
			echo '<td>' . esc_html( $e->snapshot_file ? $e->snapshot_file : '—' ) . '</td>';
			echo '<td>' . esc_html( $e->details ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * Settings tab.
	 *
	 * @return void
	 */
	private static function render_settings() {
		if ( isset( $_POST['media_verdict_settings_nonce'] ) && wp_verify_nonce( sanitize_key( $_POST['media_verdict_settings_nonce'] ), 'media_verdict_settings' ) ) {
			update_option( 'media_verdict_retention_days', max( 0, (int) $_POST['media_verdict_retention_days'] ) );
			echo '<div class="notice notice-success"><p>' . esc_html__( 'Settings saved.', 'media-verdict' ) . '</p></div>';
		}

		$retention = (int) get_option( 'media_verdict_retention_days', 30 );

		echo '<h2>' . esc_html__( 'Settings', 'media-verdict' ) . '</h2>';
		echo '<form method="post">';
		wp_nonce_field( 'media_verdict_settings', 'media_verdict_settings_nonce' );
		echo '<table class="form-table"><tr>';
		echo '<th><label for="mv-retention">' . esc_html__( 'Snapshot retention (days)', 'media-verdict' ) . '</label></th>';
		echo '<td><input id="mv-retention" type="number" min="0" name="media_verdict_retention_days" value="' . esc_attr( $retention ) . '" /> ';
		echo '<p class="description">' . esc_html__( '0 = keep forever. Expired snapshots can be deleted manually.', 'media-verdict' ) . '</p></td>';
		echo '</tr></table>';
		echo '<p><button class="button button-primary">' . esc_html__( 'Save', 'media-verdict' ) . '</button></p>';
		echo '</form>';

		// Active source parsers (v2): which builders/sliders the engine can read.
		echo '<h2>' . esc_html__( 'Active parsers', 'media-verdict' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Builder/slider sources detected on this site. Each parser can only mark images as in use, never condemn them.', 'media-verdict' ) . '</p>';
		echo '<ul class="mv-parsers">';
		foreach ( Media_Verdict_Parsers::all() as $parser ) {
			$active = false;
			try {
				$active = $parser->is_available();
			} catch ( Exception $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement
			}
			$badge = $active
				? '<span class="mv-badge mv-badge-used">' . esc_html__( 'Active', 'media-verdict' ) . '</span>'
				: '<span class="mv-badge mv-badge-unused">' . esc_html__( 'Not detected', 'media-verdict' ) . '</span>';
			echo '<li>' . esc_html( $parser->label() ) . ' ' . $badge . '</li>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $badge is built from escaped strings above.
		}
		echo '</ul>';
	}

	/**
	 * AJAX guard: nonce + capability.
	 *
	 * @return void
	 */
	private static function guard() {
		check_ajax_referer( 'media_verdict_admin', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'No permission.', 'media-verdict' ) ), 403 );
		}
	}

	/**
	 * Returns selected IDs from the request.
	 *
	 * @return array
	 */
	private static function request_ids() {
		$ids = isset( $_POST['ids'] ) ? array_map( 'intval', (array) $_POST['ids'] ) : array();
		return array_values( array_filter( array_unique( $ids ) ) );
	}

	/**
	 * AJAX: reset scan state.
	 *
	 * @return void
	 */
	public static function ajax_scan_start() {
		self::guard();
		update_option( 'media_verdict_scan_state', array( 'phase' => 0, 'offset' => 0 ) );
		// Fresh scan: drop any evidence left by an aborted previous scan.
		delete_option( Media_Verdict_Scanner::EVIDENCE_OPTION );
		wp_send_json_success( array( 'phases' => ( new Media_Verdict_Scanner() )->get_phases() ) );
	}

	/**
	 * AJAX: run one batch. State is passed by the client (stateless server).
	 *
	 * @return void
	 */
	public static function ajax_scan_batch() {
		self::guard();

		$scanner = new Media_Verdict_Scanner();
		$phases  = $scanner->get_phases();
		$phase_i = isset( $_POST['phase'] ) ? max( 0, (int) $_POST['phase'] ) : 0;
		$offset  = isset( $_POST['offset'] ) ? max( 0, (int) $_POST['offset'] ) : 0;

		if ( ! isset( $phases[ $phase_i ] ) ) {
			wp_send_json_success( array( 'finished' => true, 'summary' => $scanner->summary() ) );
		}

		$phase  = $phases[ $phase_i ];
		$result = $scanner->run_batch( $phase, $offset );
		// The scanner is per-request (stateless driver): persist this
		// batch's evidence so finalize() can see it later.
		$scanner->flush_evidence();
		$total  = $scanner->phase_total( $phase );

		if ( $result['done'] ) {
			$phase_i++;
			$offset = 0;
		} else {
			$offset = $result['next_offset'];
		}

		wp_send_json_success(
			array(
				'finished' => ! isset( $phases[ $phase_i ] ),
				'phase'    => $phase_i,
				'offset'   => $offset,
				'label'    => sprintf(
					/* translators: 1: phase, 2: processed, 3: total */
					__( '%1$s: %2$d / %3$d', 'media-verdict' ),
					$phase,
					min( $offset, $total ),
					$total
				),
				'summary'  => ! isset( $phases[ $phase_i ] ) ? $scanner->summary() : null,
			)
		);
	}

	/**
	 * AJAX: dry-run of the trash flow — shows exactly what would happen.
	 *
	 * @return void
	 */
	public static function ajax_trash_dryrun() {
		self::guard();

		$ids = self::request_ids();
		if ( empty( $ids ) ) {
			wp_send_json_error( array( 'message' => __( 'No attachments selected.', 'media-verdict' ) ) );
		}

		$items = array();
		foreach ( $ids as $id ) {
			$files = Media_Verdict_Snapshot::attachment_files( $id );
			$bytes = 0;
			foreach ( $files as $f ) {
				$bytes += filesize( $f );
			}
			$items[] = array(
				'id'    => $id,
				'title' => get_the_title( $id ),
				'files' => count( $files ),
				'bytes' => $bytes,
			);
		}

		$trash_ok = Media_Verdict_Snapshot::media_trash_available();

		wp_send_json_success(
			array(
				'items'   => $items,
				'trash_available' => $trash_ok,
				'message' => $trash_ok
					? sprintf(
						/* translators: %d: count */
						__( 'A snapshot would be created and %d attachments would go to the WordPress trash (recoverable).', 'media-verdict' ),
						count( $items )
					)
					: __( 'WARNING: the media trash is not enabled on this site. The trash flow is blocked until MEDIA_TRASH is enabled.', 'media-verdict' ),
			)
		);
	}

	/**
	 * AJAX: snapshot-first trash. Creates the snapshot, THEN trashes.
	 * If the snapshot fails, nothing is trashed — fail-safe.
	 *
	 * @return void
	 */
	public static function ajax_bulk_trash() {
		self::guard();

		$ids = self::request_ids();
		if ( empty( $ids ) ) {
			wp_send_json_error( array( 'message' => __( 'No attachments selected.', 'media-verdict' ) ) );
		}

		// Never trash protected attachments.
		$protected = array_map( 'intval', (array) get_option( 'media_verdict_protected', array() ) );
		$ids       = array_values( array_diff( $ids, $protected ) );
		if ( empty( $ids ) ) {
			wp_send_json_error( array( 'message' => __( 'All selected items are protected.', 'media-verdict' ) ) );
		}

		// Fail-safe: never silently downgrade trash to force-delete.
		if ( ! Media_Verdict_Snapshot::media_trash_available() ) {
			wp_send_json_error( array( 'message' => Media_Verdict_Snapshot::media_trash_unavailable_message() ) );
		}

		// 1. Snapshot first. On failure, abort before touching anything.
		$snap = Media_Verdict_Snapshot::create( $ids, 'pre-trash' );
		if ( is_wp_error( $snap ) ) {
			wp_send_json_error( array( 'message' => $snap->get_error_message() ) );
		}

		// 2. Trash (never force-delete).
		$trashed = array();
		$failed  = array();
		foreach ( $ids as $id ) {
			$result = wp_delete_attachment( $id, false );
			if ( $result ) {
				$trashed[] = $id;
			} else {
				$failed[] = $id;
			}
		}

		Media_Verdict_DB::audit(
			'trash',
			$trashed,
			$snap['file'],
			sprintf(
				/* translators: 1: trashed, 2: failed */
				__( '%1$d moved to trash, %2$d failed.', 'media-verdict' ),
				count( $trashed ),
				count( $failed )
			)
		);

		wp_send_json_success(
			array(
				'trashed'   => $trashed,
				'failed'    => $failed,
				'snapshot'  => $snap['file'],
				'message'   => sprintf(
					/* translators: %d: count */
					__( '%d attachments moved to trash. Snapshot: %s', 'media-verdict' ),
					count( $trashed ),
					$snap['file']
				),
			)
		);
	}

	/**
	 * AJAX: snapshot only.
	 *
	 * @return void
	 */
	public static function ajax_bulk_snapshot() {
		self::guard();

		$ids = self::request_ids();
		if ( empty( $ids ) ) {
			wp_send_json_error( array( 'message' => __( 'No attachments selected.', 'media-verdict' ) ) );
		}

		$snap = Media_Verdict_Snapshot::create( $ids, 'manual' );
		if ( is_wp_error( $snap ) ) {
			wp_send_json_error( array( 'message' => $snap->get_error_message() ) );
		}

		wp_send_json_success(
			array(
				'file'    => $snap['file'],
				'message' => sprintf(
					/* translators: 1: count, 2: file */
					__( 'Snapshot created: %1$d attachments in %2$s', 'media-verdict' ),
					$snap['count'],
					$snap['file']
				),
			)
		);
	}

	/**
	 * AJAX: protect / unprotect.
	 *
	 * @return void
	 */
	public static function ajax_protect() {
		self::guard();

		$ids       = self::request_ids();
		$protect   = ! isset( $_POST['unprotect'] );
		$protected = array_map( 'intval', (array) get_option( 'media_verdict_protected', array() ) );

		if ( $protect ) {
			$protected = array_values( array_unique( array_merge( $protected, $ids ) ) );
			Media_Verdict_DB::audit( 'protect', $ids, '', __( 'Attachments protected.', 'media-verdict' ) );
		} else {
			$protected = array_values( array_diff( $protected, $ids ) );
			Media_Verdict_DB::audit( 'unprotect', $ids, '', __( 'Attachments unprotected.', 'media-verdict' ) );
		}

		update_option( 'media_verdict_protected', $protected );
		wp_send_json_success( array( 'protected' => $protected ) );
	}

	/**
	 * AJAX: restore from snapshot.
	 *
	 * @return void
	 */
	public static function ajax_snapshot_restore() {
		self::guard();

		$file = isset( $_POST['file'] ) ? sanitize_file_name( wp_basename( (string) $_POST['file'] ) ) : '';
		$result = Media_Verdict_Snapshot::restore( $file );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success(
			array(
				'message' => sprintf(
					/* translators: 1: files, 2: posts */
					__( 'Restored %1$d files and %2$d attachments.', 'media-verdict' ),
					$result['restored_files'],
					$result['restored_posts']
				),
			)
		);
	}

	/**
	 * AJAX: delete a snapshot.
	 *
	 * @return void
	 */
	public static function ajax_snapshot_delete() {
		self::guard();

		$file = isset( $_POST['file'] ) ? sanitize_file_name( wp_basename( (string) $_POST['file'] ) ) : '';
		if ( Media_Verdict_Snapshot::delete( $file ) ) {
			wp_send_json_success( array( 'message' => __( 'Snapshot deleted.', 'media-verdict' ) ) );
		}

		wp_send_json_error( array( 'message' => __( 'Could not delete the snapshot.', 'media-verdict' ) ) );
	}

	/**
	 * AJAX: prune expired snapshots.
	 *
	 * @return void
	 */
	public static function ajax_prune_snapshots() {
		self::guard();

		$deleted = Media_Verdict_Snapshot::prune_old();
		wp_send_json_success(
			array(
				'message' => sprintf(
					/* translators: %d: count */
					__( '%d expired snapshots deleted.', 'media-verdict' ),
					$deleted
				),
			)
		);
	}
}
