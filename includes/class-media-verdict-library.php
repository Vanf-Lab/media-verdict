<?php
/**
 * Media Library integration (upload.php only — never the editor modal).
 *
 * - Grid mode: green frame on used items, red frame + badge on items with
 *   no detected usage. Tooltip/click shows the evidence.
 * - List mode: "Uso" column with a green/red pill and an evidence link.
 *
 * @package Media_Verdict
 */

defined( 'ABSPATH' ) || exit;

/**
 * Hooks the library screens.
 */
class Media_Verdict_Library {

	/**
	 * Registers hooks.
	 *
	 * @return void
	 */
	public static function hooks() {
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_filter( 'wp_prepare_attachment_for_js', array( __CLASS__, 'attachment_js' ), 10, 3 );
		add_filter( 'manage_media_columns', array( __CLASS__, 'columns' ) );
		add_action( 'manage_media_custom_column', array( __CLASS__, 'column_content' ), 10, 2 );
		add_action( 'wp_ajax_media_verdict_verdicts', array( __CLASS__, 'ajax_verdicts' ) );
	}

	/**
	 * Whether the current screen is the media library (upload.php).
	 *
	 * @return bool
	 */
	private static function is_library_screen() {
		if ( ! function_exists( 'get_current_screen' ) ) {
			return false;
		}
		$screen = get_current_screen();
		return $screen && 'upload' === $screen->id;
	}

	/**
	 * Enqueues CSS/JS on upload.php only.
	 *
	 * @param string $hook Current admin hook.
	 * @return void
	 */
	public static function enqueue( $hook ) {
		if ( 'upload.php' !== $hook || ! self::is_library_screen() ) {
			return;
		}

		wp_enqueue_style(
			'media-verdict-library',
			MEDIA_VERDICT_PLUGIN_URL . 'assets/css/media-verdict-library.css',
			array(),
			MEDIA_VERDICT_VERSION
		);

		wp_enqueue_script(
			'media-verdict-library',
			MEDIA_VERDICT_PLUGIN_URL . 'assets/js/media-verdict-library.js',
			array( 'jquery' ),
			MEDIA_VERDICT_VERSION,
			true
		);

		wp_localize_script(
			'media-verdict-library',
			'MediaVerdictLibrary',
			array(
				'ajax'  => admin_url( 'admin-ajax.php' ),
				'nonce' => wp_create_nonce( 'media_verdict_verdicts' ),
				'admin' => admin_url( 'upload.php?page=media-verdict' ),
				'i18n'  => array(
					'used'       => __( 'En uso', 'media-verdict' ),
					'unused'     => __( 'Sin uso detectado', 'media-verdict' ),
					'evidence'   => __( 'Evidencia', 'media-verdict' ),
					'view'       => __( 'Ver detalle', 'media-verdict' ),
					'notScanned' => __( 'Sin escanear', 'media-verdict' ),
				),
			)
		);
	}

	/**
	 * Adds verdict data to the attachment JS model (grid mode reads it).
	 *
	 * @param array   $response   Attachment data for JS.
	 * @param WP_Post $attachment Attachment object.
	 * @param array   $meta       Meta.
	 * @return array
	 */
	public static function attachment_js( $response, $attachment, $meta ) {
		if ( ! self::is_library_screen() ) {
			return $response;
		}

		$verdict = Media_Verdict_DB::get_verdict( (int) $attachment->ID );
		if ( $verdict ) {
			$response['mvStatus']   = $verdict->status;
			$response['mvEvidence'] = self::evidence_summary( $verdict->evidence );
		} else {
			$response['mvStatus']   = 'unknown';
			$response['mvEvidence'] = '';
		}

		return $response;
	}

	/**
	 * AJAX: returns verdicts for a batch of attachment IDs (grid fallback).
	 *
	 * @return void
	 */
	public static function ajax_verdicts() {
		check_ajax_referer( 'media_verdict_verdicts', 'nonce' );

		if ( ! current_user_can( 'upload_files' ) ) {
			wp_send_json_error( array( 'message' => __( 'Sin permisos.', 'media-verdict' ) ), 403 );
		}

		$ids = isset( $_POST['ids'] ) ? array_map( 'intval', (array) $_POST['ids'] ) : array();
		$ids = array_slice( array_filter( array_unique( $ids ) ), 0, 100 );

		$out = array();
		foreach ( $ids as $id ) {
			$verdict = Media_Verdict_DB::get_verdict( $id );
			$out[ $id ] = $verdict
				? array(
					'status'   => $verdict->status,
					'evidence' => self::evidence_summary( $verdict->evidence ),
				)
				: array(
					'status'   => 'unknown',
					'evidence' => '',
				);
		}

		wp_send_json_success( $out );
	}

	/**
	 * Short human summary of the evidence JSON.
	 *
	 * @param string $evidence_json Evidence JSON.
	 * @return string
	 */
	public static function evidence_summary( $evidence_json ) {
		$evidence = json_decode( (string) $evidence_json, true );
		if ( ! is_array( $evidence ) || empty( $evidence ) ) {
			return '';
		}

		$labels = array();
		foreach ( array_slice( $evidence, 0, 5 ) as $entry ) {
			if ( ! empty( $entry['label'] ) ) {
				$labels[] = $entry['label'];
			}
		}
		$summary = implode( '; ', $labels );
		if ( count( $evidence ) > 5 ) {
			$summary .= sprintf(
				/* translators: %d: more evidence count */
				__( ' (+%d más)', 'media-verdict' ),
				count( $evidence ) - 5
			);
		}

		return $summary;
	}

	/**
	 * Adds the "Uso" column to list mode.
	 *
	 * @param array $columns Columns.
	 * @return array
	 */
	public static function columns( $columns ) {
		$columns['media_verdict_usage'] = __( 'Uso', 'media-verdict' );
		return $columns;
	}

	/**
	 * Renders the "Uso" column cell.
	 *
	 * @param string $column_name Column name.
	 * @param int    $post_id     Attachment ID.
	 * @return void
	 */
	public static function column_content( $column_name, $post_id ) {
		if ( 'media_verdict_usage' !== $column_name ) {
			return;
		}

		$verdict = Media_Verdict_DB::get_verdict( (int) $post_id );
		$url     = admin_url( 'upload.php?page=media-verdict&media_verdict_focus=' . (int) $post_id );

		if ( ! $verdict ) {
			echo '<span class="mv-pill mv-unknown">' . esc_html__( 'Sin escanear', 'media-verdict' ) . '</span>';
			return;
		}

		if ( 'used' === $verdict->status ) {
			$summary = self::evidence_summary( $verdict->evidence );
			echo '<a class="mv-pill mv-used" title="' . esc_attr( $summary ) . '" href="' . esc_url( $url ) . '">'
				. esc_html__( 'En uso', 'media-verdict' ) . '</a>';
		} else {
			echo '<a class="mv-pill mv-unused" href="' . esc_url( $url ) . '" title="' . esc_attr__( 'Sin uso detectado: ninguna referencia encontrada en el escaneo. Esto no es una garantía.', 'media-verdict' ) . '">'
				. esc_html__( 'Sin uso detectado', 'media-verdict' ) . '</a>';
		}
	}
}
