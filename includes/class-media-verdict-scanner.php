<?php
/**
 * The detection engine.
 *
 * Fail-safe philosophy: when in doubt, mark as USED. An attachment is
 * only reported as "sin uso detectado" when zero references are found in
 * every scanned source. The UI copy must never promise certainty — no
 * scanner can see dynamic references (hardcoded CSS, JS-built URLs,
 * email templates).
 *
 * Sources (batched, resumable):
 *  1. post_content of every post type (public + drafts + revisions).
 *  2. postmeta with special parsers (_thumbnail_id, _product_image_gallery,
 *     _elementor_data) plus a generic noisy safety sweep.
 *  3. termmeta, usermeta (known keys), options (special + safety sweep).
 *
 * @package Media_Verdict
 */

defined( 'ABSPATH' ) || exit;

/**
 * Runs the scan in phases, either one batch at a time (AJAX) or fully (CLI).
 */
class Media_Verdict_Scanner {

	/**
	 * Batch sizes per phase.
	 *
	 * @var array
	 */
	private $batch_sizes = array(
		'attachments' => 200,
		'content'     => 100,
		'postmeta'    => 500,
		'termmeta'    => 500,
		'usermeta'    => 200,
		'options'     => 200,
		'parsers'     => 50,
	);

	/**
	 * Token index.
	 *
	 * @var Media_Verdict_Tokens
	 */
	private $tokens;

	/**
	 * Evidence accumulator: attachment_id => [ [label, confidence], ... ].
	 *
	 * @var array
	 */
	private $evidence = array();

	/**
	 * User-protected attachment IDs.
	 *
	 * @var array
	 */
	private $protected = array();

	/**
	 * Phase order.
	 *
	 * @var array
	 */
	private $phases = array( 'attachments', 'content', 'postmeta', 'termmeta', 'usermeta', 'options', 'parsers', 'finalize' );

	/**
	 * Option holding per-batch evidence between stateless requests.
	 *
	 * The AJAX driver creates a NEW scanner per HTTP request, so the
	 * in-memory $evidence accumulator dies with each batch. Evidence is
	 * therefore persisted here (autoload=no) after every batch and merged
	 * back before finalizing. CLI scans (single object) don't strictly
	 * need it, but flush/load are harmless there too.
	 */
	const EVIDENCE_OPTION = 'media_verdict_scan_evidence';

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->tokens    = new Media_Verdict_Tokens();
		$this->protected = array_map( 'intval', (array) get_option( 'media_verdict_protected', array() ) );
	}

	/**
	 * Returns the ordered phase list.
	 *
	 * @return array
	 */
	public function get_phases() {
		return $this->phases;
	}

	/**
	 * Returns total work units for a phase (for progress bars).
	 *
	 * @param string $phase Phase slug.
	 * @return int
	 */
	public function phase_total( $phase ) {
		global $wpdb;

		switch ( $phase ) {
			case 'attachments':
				return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'attachment'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			case 'content':
				return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
					$wpdb->prepare(
						"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type NOT IN ('attachment','nav_menu_item','custom_css','customize_changeset','oembed_cache') AND post_status NOT IN ('trash','auto-draft')",
					)
				);
			case 'postmeta':
				return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->postmeta}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			case 'termmeta':
				return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->termmeta}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			case 'usermeta':
				return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->usermeta}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			case 'options':
				return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name NOT LIKE '\\_transient\\_%' AND option_name NOT LIKE '\\_site\\_transient\\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			case 'parsers':
				$total = 0;
				foreach ( Media_Verdict_Parsers::available() as $parser ) {
					$total += $parser->total();
				}
				return $total;
			case 'finalize':
				return 1;
		}

		return 0;
	}

	/**
	 * Runs a single batch of a phase.
	 *
	 * @param string $phase  Phase slug.
	 * @param int    $offset Offset within the phase.
	 * @return array {done, next_offset, processed}
	 */
	public function run_batch( $phase, $offset ) {
		$offset = max( 0, (int) $offset );
		$limit  = isset( $this->batch_sizes[ $phase ] ) ? $this->batch_sizes[ $phase ] : 100;

		// The token index must exist before any matching phase. Rebuild it
		// incrementally-safe: build() skips already-indexed attachments.
		if ( 'attachments' !== $phase ) {
			$this->ensure_tokens();
		}

		$method = 'scan_' . $phase;
		if ( ! method_exists( $this, $method ) ) {
			return array( 'done' => true, 'next_offset' => 0, 'processed' => 0 );
		}

		$processed = $this->$method( $offset, $limit );
		$done      = $processed < $limit;

		return array(
			'done'        => $done,
			'next_offset' => $done ? 0 : $offset + $limit,
			'processed'   => $processed,
		);
	}

	/**
	 * Runs the whole scan (used by WP-CLI).
	 *
	 * @param callable|null $progress Optional callback( $phase, $done, $total ).
	 * @return array Summary: used, unused, scanned.
	 */
	public function run_full( $progress = null ) {
		// Fresh scan: no stale evidence from a previous (possibly aborted) scan.
		delete_option( self::EVIDENCE_OPTION );

		foreach ( $this->phases as $phase ) {
			$total  = $this->phase_total( $phase );
			$offset = 0;
			do {
				$result = $this->run_batch( $phase, $offset );
				$this->flush_evidence();
				$offset = $result['next_offset'];
				if ( is_callable( $progress ) ) {
					call_user_func( $progress, $phase, min( $offset, $total ), $total );
				}
			} while ( ! $result['done'] );
		}

		return $this->summary();
	}

	/**
	 * Returns the scan summary from the index table.
	 *
	 * @return array
	 */
	public function summary() {
		return array(
			'total'      => Media_Verdict_DB::count_verdicts( 'all' ),
			'used'       => Media_Verdict_DB::count_verdicts( 'used' ),
			'unused'     => Media_Verdict_DB::count_verdicts( 'unused' ),
			'unused_mb'  => round( Media_Verdict_DB::sum_sizes( 'unused' ) / 1048576, 2 ),
			'last_scan'  => get_option( 'media_verdict_last_scan', '' ),
		);
	}

	/**
	 * Makes sure the token index covers every attachment.
	 *
	 * @return void
	 */
	private function ensure_tokens() {
		global $wpdb;

		$ids = $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$this->tokens->build( array_map( 'intval', $ids ) );
	}

	/**
	 * Phase: index every attachment's tokens.
	 *
	 * @param int $offset Offset.
	 * @param int $limit  Limit.
	 * @return int Processed.
	 */
	private function scan_attachments( $offset, $limit ) {
		global $wpdb;

		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment' ORDER BY ID ASC LIMIT %d OFFSET %d", $limit, $offset ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$this->tokens->build( array_map( 'intval', (array) $ids ) );

		return count( (array) $ids );
	}

	/**
	 * Phase: scan post_content of every post (all types except attachments,
	 * including drafts and revisions — fail-safe direction).
	 *
	 * @param int $offset Offset.
	 * @param int $limit  Limit.
	 * @return int Processed.
	 */
	private function scan_content( $offset, $limit ) {
		global $wpdb;

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT ID, post_title, post_type FROM {$wpdb->posts}
				WHERE post_type NOT IN ('attachment','nav_menu_item','custom_css','customize_changeset','oembed_cache')
				AND post_status NOT IN ('trash','auto-draft')
				ORDER BY ID ASC LIMIT %d OFFSET %d",
				$limit,
				$offset
			)
		);

		foreach ( $rows as $row ) {
			$content = get_post_field( 'post_content', (int) $row->ID );
			if ( '' === trim( (string) $content ) ) {
				continue;
			}
			$label = $this->post_label( $row );
			foreach ( $this->find_in_text( (string) $content ) as $id ) {
				$this->add_evidence( $id, $label, 'high' );
			}
		}

		return count( (array) $rows );
	}

	/**
	 * Phase: scan postmeta with special parsers + generic safety sweep.
	 *
	 * @param int $offset Offset.
	 * @param int $limit  Limit.
	 * @return int Processed.
	 */
	private function scan_postmeta( $offset, $limit ) {
		global $wpdb;

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT meta_id, post_id, meta_key, meta_value FROM {$wpdb->postmeta} ORDER BY meta_id ASC LIMIT %d OFFSET %d",
				$limit,
				$offset
			)
		);

		foreach ( $rows as $row ) {
			$post_id = (int) $row->post_id;
			// An attachment's own meta (_wp_attached_file, _wp_attachment_metadata,
			// _wp_attachment_image_alt...) is not "usage" — skip it.
			if ( $this->tokens->has( $post_id ) ) {
				continue;
			}

			$label = sprintf(
				/* translators: 1: meta key, 2: post label */
				__( 'Meta %1$s of %2$s', 'media-verdict' ),
				$row->meta_key,
				$this->post_label( $post_id )
			);

			switch ( $row->meta_key ) {
				case '_thumbnail_id':
					$id = $this->tokens->resolve_id( (int) $row->meta_value );
					if ( $id ) {
						$this->add_evidence(
							$id,
							sprintf(
								/* translators: %s: post label */
								__( 'Featured image of %s', 'media-verdict' ),
								$this->post_label( $post_id )
							),
							'high'
						);
					}
					break;

				case '_product_image_gallery':
					foreach ( preg_split( '/[\s,]+/', (string) $row->meta_value ) as $piece ) {
						$id = $this->tokens->resolve_id( (int) $piece );
						if ( $id ) {
							$this->add_evidence(
								$id,
								sprintf(
									/* translators: %s: post label */
									__( '%s gallery', 'media-verdict' ),
									$this->post_label( $post_id )
								),
								'high'
							);
						}
					}
					break;

				case '_elementor_data':
					$this->scan_elementor_data( (string) $row->meta_value, $post_id );
					break;

				default:
					$value = $row->meta_value;
					if ( is_numeric( $value ) ) {
						$id = $this->tokens->resolve_id( (int) $value );
						if ( $id ) {
							$this->add_evidence( $id, $label, 'medium' );
						}
						break;
					}
					foreach ( $this->find_in_text( (string) $value ) as $id ) {
						$this->add_evidence( $id, $label, 'medium' );
					}
					break;
			}
		}

		return count( (array) $rows );
	}

	/**
	 * Extracts attachment references from Elementor's _elementor_data JSON.
	 *
	 * @param string $json    Raw JSON.
	 * @param int    $post_id Owning post ID.
	 * @return void
	 */
	private function scan_elementor_data( $json, $post_id ) {
		$data = json_decode( $json, true );
		if ( ! is_array( $data ) ) {
			return;
		}

		$label = sprintf(
			/* translators: %s: post label */
			__( 'Elementor: %s', 'media-verdict' ),
			$this->post_label( $post_id )
		);

		$self = $this;
		$walk = function ( $node ) use ( &$walk, $self, $label ) {
			if ( is_array( $node ) ) {
				foreach ( $node as $key => $value ) {
					if ( 'id' === $key && is_numeric( $value ) ) {
						$id = $self->tokens->resolve_id( (int) $value );
						if ( $id ) {
							$self->add_evidence( $id, $label, 'medium' );
						}
					} elseif ( is_string( $value ) ) {
						foreach ( $self->find_in_text( $value ) as $id ) {
							$self->add_evidence( $id, $label, 'medium' );
						}
					} else {
						$walk( $value );
					}
				}
			}
		};
		$walk( $data );
	}

	/**
	 * Phase: scan termmeta (generic safety sweep).
	 *
	 * @param int $offset Offset.
	 * @param int $limit  Limit.
	 * @return int Processed.
	 */
	private function scan_termmeta( $offset, $limit ) {
		global $wpdb;

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT meta_id, term_id, meta_key, meta_value FROM {$wpdb->termmeta} ORDER BY meta_id ASC LIMIT %d OFFSET %d",
				$limit,
				$offset
			)
		);

		foreach ( $rows as $row ) {
			$term = get_term( (int) $row->term_id );
			$name = ( $term && ! is_wp_error( $term ) ) ? $term->name : '#' . (int) $row->term_id;
			$label = sprintf(
				/* translators: 1: meta key, 2: term name */
				__( 'Meta %1$s of term "%2$s"', 'media-verdict' ),
				$row->meta_key,
				$name
			);

			if ( is_numeric( $row->meta_value ) ) {
				$id = $this->tokens->resolve_id( (int) $row->meta_value );
				if ( $id ) {
					$this->add_evidence( $id, $label, 'medium' );
				}
				continue;
			}
			foreach ( $this->find_in_text( (string) $row->meta_value ) as $id ) {
				$this->add_evidence( $id, $label, 'medium' );
			}
		}

		return count( (array) $rows );
	}

	/**
	 * Phase: scan usermeta, limited to keys that plausibly hold images.
	 *
	 * @param int $offset Offset.
	 * @param int $limit  Limit.
	 * @return int Processed.
	 */
	private function scan_usermeta( $offset, $limit ) {
		global $wpdb;

		$like_avatar  = '%' . $wpdb->esc_like( 'avatar' ) . '%';
		$like_photo   = '%' . $wpdb->esc_like( 'photo' ) . '%';
		$like_image   = '%' . $wpdb->esc_like( 'image' ) . '%';
		$like_picture = '%' . $wpdb->esc_like( 'picture' ) . '%';

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT umeta_id, user_id, meta_key, meta_value FROM {$wpdb->usermeta}
				WHERE meta_key LIKE %s OR meta_key LIKE %s OR meta_key LIKE %s OR meta_key LIKE %s
				ORDER BY umeta_id ASC LIMIT %d OFFSET %d",
				$like_avatar,
				$like_photo,
				$like_image,
				$like_picture,
				$limit,
				$offset
			)
		);

		foreach ( (array) $rows as $row ) {
			$user  = get_userdata( (int) $row->user_id );
			$label = sprintf(
				/* translators: 1: meta key, 2: user login */
				__( 'Meta %1$s of user "%2$s"', 'media-verdict' ),
				$row->meta_key,
				$user ? $user->user_login : '#' . (int) $row->user_id
			);

			if ( is_numeric( $row->meta_value ) ) {
				$id = $this->tokens->resolve_id( (int) $row->meta_value );
				if ( $id ) {
					$this->add_evidence( $id, $label, 'medium' );
				}
				continue;
			}
			foreach ( $this->find_in_text( (string) $row->meta_value ) as $id ) {
				$this->add_evidence( $id, $label, 'medium' );
			}
		}

		return count( (array) $rows );
	}

	/**
	 * Phase: scan options — special keys first, then a noisy safety sweep
	 * over the rest (transients excluded).
	 *
	 * @param int $offset Offset.
	 * @param int $limit  Limit.
	 * @return int Processed.
	 */
	private function scan_options( $offset, $limit ) {
		global $wpdb;

		$not_transient      = $wpdb->esc_like( '_transient_' ) . '%';
		$not_site_transient = $wpdb->esc_like( '_site_transient_' ) . '%';

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT option_id, option_name, option_value FROM {$wpdb->options}
				WHERE option_name NOT LIKE %s AND option_name NOT LIKE %s
				ORDER BY option_id ASC LIMIT %d OFFSET %d",
				$not_transient,
				$not_site_transient,
				$limit,
				$offset
			)
		);

		foreach ( $rows as $row ) {
			$name  = $row->option_name;
			$value = $row->option_value;

			if ( strlen( (string) $value ) > 2000000 ) {
				continue; // Skip absurdly large values.
			}

			if ( 'custom_logo' === $name ) {
				$id = $this->tokens->resolve_id( (int) $value );
				if ( $id ) {
					$this->add_evidence( $id, __( 'Site logo', 'media-verdict' ), 'high' );
				}
				continue;
			}

			if ( 'site_icon' === $name ) {
				$id = $this->tokens->resolve_id( (int) $value );
				if ( $id ) {
					$this->add_evidence( $id, __( 'Site icon', 'media-verdict' ), 'high' );
				}
				continue;
			}

			if ( 0 === strpos( $name, 'theme_mods_' ) ) {
				$label = sprintf(
					/* translators: %s: theme slug */
					__( 'Theme customizer (%s)', 'media-verdict' ),
					substr( $name, 11 )
				);
				foreach ( $this->find_in_mixed( maybe_unserialize( $value ) ) as $id ) {
					$this->add_evidence( $id, $label, 'medium' );
				}
				continue;
			}

			if ( 0 === strpos( $name, 'widget_' ) ) {
				$label = sprintf(
					/* translators: %s: option name */
					__( 'Widget (%s)', 'media-verdict' ),
					$name
				);
				foreach ( $this->find_in_mixed( maybe_unserialize( $value ) ) as $id ) {
					$this->add_evidence( $id, $label, 'medium' );
				}
				continue;
			}

			// Noisy safety sweep: any option value referencing a real file
			// protects the attachment. It can never condemn. Bare integers
			// are NOT matched here (remote catalogs' internal IDs would
			// collide with local attachment IDs); URL/basename references
			// and markup-embedded IDs still count.
			if ( in_array( $name, $this->skipped_options(), true ) ) {
				continue;
			}
			foreach ( $this->find_in_mixed( maybe_unserialize( $value ), 0, false ) as $id ) {
				$this->add_evidence(
					$id,
					sprintf(
						/* translators: %s: option name */
						__( 'Option %s', 'media-verdict' ),
						$name
					),
					'low'
				);
			}
		}

		return count( (array) $rows );
	}

	/**
	 * Phase: builder/slider source parsers (v2).
	 *
	 * Offsets are global row indexes across the concatenated available
	 * parsers, so the standard `$offset + $limit` driver contract keeps
	 * working without per-parser state.
	 *
	 * @param int $offset Offset.
	 * @param int $limit  Limit.
	 * @return int Processed.
	 */
	private function scan_parsers( $offset, $limit ) {
		$processed = 0;
		$start     = 0;

		foreach ( Media_Verdict_Parsers::available() as $parser ) {
			if ( $processed >= $limit ) {
				break;
			}
			$total = max( 0, (int) $parser->total() );
			$end   = $start + $total;
			if ( $offset < $end ) {
				$row_offset = max( 0, $offset - $start );
				$want       = min( $limit - $processed, $total - $row_offset );
				if ( $want > 0 ) {
					$processed += (int) $parser->scan_batch( $this, $row_offset, $want );
				}
			}
			$start = $end;
		}

		return $processed;
	}

	/**
	 * Phase: persist verdicts for every attachment.
	 *
	 * @param int $offset Unused.
	 * @param int $limit  Unused.
	 * @return int Always 1.
	 */
	private function scan_finalize( $offset, $limit ) {
		global $wpdb;

		// Stateless drivers (admin AJAX) accumulate evidence across
		// requests in the EVIDENCE_OPTION; merge it back before verdicting.
		$this->load_persisted_evidence();

		$ids = $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		$dynamic = $this->dynamic_basenames();

		foreach ( array_map( 'intval', $ids ) as $id ) {
			$evidence = isset( $this->evidence[ $id ] ) ? $this->evidence[ $id ] : array();

			// System-known dynamic assets (e.g. WooCommerce placeholder):
			// referenced by plugin code, invisible to any DB scan.
			$attached = get_post_meta( $id, '_wp_attached_file', true );
			$base     = $attached ? strtolower( pathinfo( wp_basename( $attached ), PATHINFO_FILENAME ) ) : '';
			if ( '' !== $base && in_array( $base, $dynamic, true ) ) {
				$evidence[] = array(
					'label'      => __( 'Dynamic system asset (possible reference from code)', 'media-verdict' ),
					'confidence' => 'high',
				);
			}

			// User-protected attachments are always "used".
			if ( in_array( $id, $this->protected, true ) ) {
				$evidence[] = array(
					'label'      => __( 'Manually protected', 'media-verdict' ),
					'confidence' => 'high',
				);
			}

			$status    = ! empty( $evidence ) ? 'used' : 'unused';
			$file_size = $this->attachment_bytes( $id );

			Media_Verdict_DB::upsert_verdict( $id, $status, $this->dedup_evidence( $evidence ), $file_size );
		}

		Media_Verdict_DB::prune_missing();
		update_option( 'media_verdict_last_scan', current_time( 'mysql' ) );
		Media_Verdict_DB::audit( 'scan', array(), '', __( 'Scan complete.', 'media-verdict' ) );

		// Scan complete: persisted evidence must not leak into the next scan.
		// Clear the in-memory buffer too, so a driver-level flush_evidence()
		// after this batch cannot resurrect the option.
		delete_option( self::EVIDENCE_OPTION );
		$this->evidence = array();

		return 1;
	}

	/**
	 * Basenames (without extension) known to be referenced dynamically by
	 * plugins/themes, invisible to DB scans. Filterable.
	 *
	 * @return array
	 */
	private function dynamic_basenames() {
		$list = array( 'woocommerce-placeholder' );
		$list = apply_filters( 'media_verdict_dynamic_basenames', $list );
		return array_map( 'strtolower', array_map( 'strval', (array) $list ) );
	}

	/**
	 * Total bytes of an attachment: original + generated sizes.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return int
	 */
	private function attachment_bytes( $attachment_id ) {
		$total = 0;
		foreach ( Media_Verdict_Snapshot::attachment_files( $attachment_id ) as $file ) {
			$total += filesize( $file );
		}
		return $total;
	}

	/**
	 * Resolves a numeric attachment ID (parser helper).
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return int The ID if indexed, 0 otherwise.
	 */
	public function resolve_id( $attachment_id ) {
		return $this->tokens->resolve_id( (int) $attachment_id );
	}

	/**
	 * Resolves a raw URL or path (parser helper). Unescapes JSON-escaped
	 * slashes (\/) before matching — Revolution/LayerSlider style.
	 *
	 * @param string $url Raw URL or path.
	 * @return int Attachment ID, or 0.
	 */
	public function resolve_url( $url ) {
		$url = str_replace( '\\/', '/', (string) $url );
		return $this->tokens->resolve_url( $url );
	}

	/**
	 * Resolves a bare basename token, with size-suffix fallback
	 * (parser helper, e.g. Master Slider's "/slider8-150x150.jpg").
	 *
	 * @param string $token Basename token.
	 * @return int Attachment ID, or 0 when unknown/ambiguous.
	 */
	public function resolve_token( $token ) {
		$token = strtolower( trim( (string) $token ) );
		$id    = $this->tokens->resolve( $token );
		if ( $id > 0 ) {
			return $id;
		}
		return $this->tokens->resolve( Media_Verdict_Tokens::strip_size_suffix( $token ) );
	}

	/**
	 * Records one evidence entry for an attachment (deduped later).
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $label         Human-readable label.
	 * @param string $confidence    high|medium|low.
	 * @return void
	 */
	public function add_evidence( $attachment_id, $label, $confidence = 'medium' ) {
		$attachment_id = (int) $attachment_id;
		if ( $attachment_id <= 0 ) {
			return;
		}
		if ( ! isset( $this->evidence[ $attachment_id ] ) ) {
			$this->evidence[ $attachment_id ] = array();
		}
		$this->evidence[ $attachment_id ][] = array(
			'label'      => (string) $label,
			'confidence' => (string) $confidence,
		);
	}

	/**
	 * Dedups evidence entries by label.
	 *
	 * @param array $evidence Evidence list.
	 * @return array
	 */
	private function dedup_evidence( array $evidence ) {
		$seen = array();
		$out  = array();
		foreach ( $evidence as $entry ) {
			$key = $entry['label'];
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			$out[]        = $entry;
		}
		return $out;
	}

	/**
	 * Persists the in-memory evidence buffer to EVIDENCE_OPTION, merging
	 * with (and deduplicating against) what earlier batches already stored.
	 * The buffer is emptied afterwards.
	 *
	 * @return void
	 */
	public function flush_evidence() {
		if ( empty( $this->evidence ) ) {
			return;
		}

		$stored = get_option( self::EVIDENCE_OPTION, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		foreach ( $this->evidence as $id => $entries ) {
			$id = (int) $id;
			if ( ! isset( $stored[ $id ] ) || ! is_array( $stored[ $id ] ) ) {
				$stored[ $id ] = array();
			}
			$stored[ $id ] = $this->dedup_evidence( array_merge( $stored[ $id ], $entries ) );
		}

		update_option( self::EVIDENCE_OPTION, $stored, false ); // autoload=no.
		$this->evidence = array();
	}

	/**
	 * Merges evidence persisted by earlier batches (EVIDENCE_OPTION) back
	 * into the in-memory accumulator, deduplicating by label.
	 *
	 * @return void
	 */
	public function load_persisted_evidence() {
		$stored = get_option( self::EVIDENCE_OPTION, array() );
		if ( ! is_array( $stored ) || empty( $stored ) ) {
			return;
		}

		foreach ( $stored as $id => $entries ) {
			$id = (int) $id;
			if ( $id <= 0 || ! is_array( $entries ) ) {
				continue;
			}
			if ( ! isset( $this->evidence[ $id ] ) ) {
				$this->evidence[ $id ] = array();
			}
			$this->evidence[ $id ] = $this->dedup_evidence( array_merge( $this->evidence[ $id ], $entries ) );
		}
	}

	/**
	 * Finds referenced attachment IDs inside a text blob.
	 *
	 * @param string $text Text to scan.
	 * @return array Attachment IDs (unique).
	 */
	public function find_in_text( $text ) {
		$found = array();
		$text  = (string) $text;
		if ( '' === trim( $text ) ) {
			return $found;
		}

		// Classic editor / HTML: wp-image-<id> class.
		if ( preg_match_all( '/wp-image-(\d+)/', $text, $m ) ) {
			foreach ( $m[1] as $raw ) {
				$id = $this->tokens->resolve_id( (int) $raw );
				if ( $id ) {
					$found[ $id ] = true;
				}
			}
		}

		// Gutenberg block comments: <!-- wp:image {"id":123 ...} -->.
		if ( preg_match_all( '/<!--\s*wp:(?:image|gallery|media-text|cover|post-featured-image)[^>]*?"id":(\d+)/', $text, $m ) ) {
			foreach ( $m[1] as $raw ) {
				$id = $this->tokens->resolve_id( (int) $raw );
				if ( $id ) {
					$found[ $id ] = true;
				}
			}
		}

		// data-id="123" attributes (galleries, sliders).
		if ( preg_match_all( '/data-id=["\'](\d+)["\']/', $text, $m ) ) {
			foreach ( $m[1] as $raw ) {
				$id = $this->tokens->resolve_id( (int) $raw );
				if ( $id ) {
					$found[ $id ] = true;
				}
			}
		}

		// URLs: absolute, protocol-relative and uploads-relative.
		if ( preg_match_all( '#(?:https?:)?//[^\s"\'<>()]+|/wp-content/uploads/[^\s"\'<>()]+#i', $text, $m ) ) {
			foreach ( $m[0] as $url ) {
				$id = $this->tokens->resolve_url( $url );
				if ( $id ) {
					$found[ $id ] = true;
				}
			}
		}

		return array_keys( $found );
	}

	/**
	 * Finds referenced attachment IDs inside mixed data (arrays, objects,
	 * serialized strings, JSON strings). Recurses with a depth guard.
	 *
	 * @param mixed $data        Data to scan.
	 * @param int   $depth       Current depth.
	 * @param bool  $numeric_ids Whether bare integers may resolve to
	 *                           attachment IDs. Disable for noisy remote
	 *                           catalogs (their internal IDs collide with
	 *                           local attachment IDs).
	 * @return array Attachment IDs (unique).
	 */
	public function find_in_mixed( $data, $depth = 0, $numeric_ids = true ) {
		$found = array();
		if ( $depth > 8 ) {
			return $found;
		}

		if ( is_array( $data ) || is_object( $data ) ) {
			foreach ( (array) $data as $value ) {
				foreach ( $this->find_in_mixed( $value, $depth + 1, $numeric_ids ) as $id ) {
					$found[ $id ] = true;
				}
			}
			return array_keys( $found );
		}

		if ( is_numeric( $data ) ) {
			if ( ! $numeric_ids ) {
				return $found;
			}
			$id = $this->tokens->resolve_id( (int) $data );
			if ( $id ) {
				$found[ $id ] = true;
			}
			return array_keys( $found );
		}

		if ( is_string( $data ) ) {
			// Serialized or JSON strings: decode and recurse.
			$maybe = maybe_unserialize( $data );
			if ( $maybe !== $data ) {
				return $this->find_in_mixed( $maybe, $depth + 1, $numeric_ids );
			}
			$trim = ltrim( $data );
			if ( ( str_starts_with( $trim, '{' ) || str_starts_with( $trim, '[' ) ) ) {
				$json = json_decode( $data, true );
				if ( is_array( $json ) ) {
					return $this->find_in_mixed( $json, $depth + 1, $numeric_ids );
				}
			}
			return $this->find_in_text( $data );
		}

		return $found;
	}

	/**
	 * Options that are remote catalogs/caches: they can never constitute
	 * local usage, and their internal numeric IDs collide with local
	 * attachment IDs. Skipped by the safety sweep. Filterable.
	 *
	 * @return array
	 */
	private function skipped_options() {
		$skip = array(
			'elementor_remote_info_library',
			'ptk_patterns',
		);
		return (array) apply_filters( 'media_verdict_skipped_options', $skip );
	}

	/**
	 * Human-readable label for a post: «Tipo #ID "Título"».
	 *
	 * @param int|object $post Post ID or row object with ID/post_title/post_type.
	 * @return string
	 */
	private function post_label( $post ) {
		if ( is_object( $post ) ) {
			$id         = (int) $post->ID;
			$title      = (string) $post->post_title;
			$post_type  = (string) $post->post_type;
		} else {
			$id    = (int) $post;
			$title = get_the_title( $id );
			$type  = get_post_type( $id );
			$post_type = $type ? $type : 'post';
		}

		$type_labels = array(
			'post'            => __( 'Post', 'media-verdict' ),
			'page'            => __( 'Page', 'media-verdict' ),
			'product'         => __( 'Product', 'media-verdict' ),
			'attachment'      => __( 'Attachment', 'media-verdict' ),
			'elementor_library' => __( 'Elementor template', 'media-verdict' ),
		);

		$type_name = isset( $type_labels[ $post_type ] ) ? $type_labels[ $post_type ] : $post_type;
		$title     = '' !== $title ? ' «' . $title . '»' : '';

		if ( is_object( $post ) && 'revision' === $post_type ) {
			return sprintf(
				/* translators: 1: type, 2: ID */
				__( '%1$s #%2$d (revision)', 'media-verdict' ),
				__( 'Revision', 'media-verdict' ),
				$id
			);
		}

		return sprintf(
			/* translators: 1: type, 2: ID, 3: title */
			__( '%1$s #%2$d%3$s', 'media-verdict' ),
			$type_name,
			$id,
			$title
		);
	}
}
