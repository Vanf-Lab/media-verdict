<?php
/**
 * Builder/slider source parsers for the detection engine (v2/v3).
 *
 * Page builders and slider plugins keep image references in their own
 * ecosystems: custom tables, base64 blobs, escaped JSON, template
 * placeholders. The core scanner cannot see those formats, so each
 * supported builder gets a dedicated parser here.
 *
 * A parser is a small class with:
 *  - id() / label()           — slug and human name.
 *  - is_available()           — data-driven guard (tables / markers must
 *                               exist; never assume the plugin is active).
 *  - total()                  — work units for batching/progress.
 *  - scan_batch( $scanner, $offset, $limit ) — process up to $limit rows
 *                               starting at $offset; returns rows processed.
 *
 * Parsers resolve references through the scanner's public helpers
 * (resolve_id / resolve_url / resolve_token) and record evidence with
 * $scanner->add_evidence( $id, $label, $confidence ).
 *
 * Third parties can register their own via the `media_verdict_parsers`
 * filter (append a Media_Verdict_Parser instance).
 *
 * Fail-safe philosophy applies: a parser may only ever mark attachments
 * as USED, never condemn them.
 *
 * @package Media_Verdict
 */

defined( 'ABSPATH' ) || exit;

/**
 * Base class with shared helpers for source parsers.
 */
abstract class Media_Verdict_Parser {

	/**
	 * Parser slug.
	 *
	 * @return string
	 */
	abstract public function id();

	/**
	 * Human-readable name.
	 *
	 * @return string
	 */
	abstract public function label();

	/**
	 * Whether this parser has data to scan on this site.
	 *
	 * @return bool
	 */
	abstract public function is_available();

	/**
	 * Total work units (rows) for batching.
	 *
	 * @return int
	 */
	abstract public function total();

	/**
	 * Process up to $limit rows starting at $offset.
	 *
	 * @param Media_Verdict_Scanner $scanner Scanner (evidence + resolvers).
	 * @param int                   $offset  Row offset.
	 * @param int                   $limit   Max rows.
	 * @return int Rows processed.
	 */
	abstract public function scan_batch( $scanner, $offset, $limit );

	/**
	 * Checks that a table exists (guards against inactive/uninstalled plugins).
	 *
	 * @param string $table Full table name.
	 * @return bool
	 */
	protected function table_exists( $table ) {
		global $wpdb;
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return $found === $table;
	}

	/**
	 * Reads a column from a row trying several candidate names.
	 *
	 * @param object $row        DB row.
	 * @param array  $candidates Column names to try, in order.
	 * @param mixed  $default    Default when none exists.
	 * @return mixed
	 */
	protected function col( $row, array $candidates, $default = '' ) {
		foreach ( $candidates as $c ) {
			if ( isset( $row->$c ) && '' !== $row->$c && null !== $row->$c ) {
				return $row->$c;
			}
		}
		return $default;
	}

	/**
	 * Unescapes JSON-escaped forward slashes (\/) so URLs become matchable.
	 * Revolution Slider and LayerSlider store URLs this way.
	 *
	 * @param string $text Raw text.
	 * @return string
	 */
	protected function unescape_slashes( $text ) {
		return str_replace( '\\/', '/', (string) $text );
	}
}

/**
 * Registry: all parsers, filterable; only available ones run.
 */
class Media_Verdict_Parsers {

	/**
	 * Cached availability list per request.
	 *
	 * @var array|null
	 */
	private static $available_cache = null;

	/**
	 * All registered parsers (built-ins + filtered).
	 *
	 * @return Media_Verdict_Parser[]
	 */
	public static function all() {
		$parsers = array(
			new Media_Verdict_Parser_SmartSlider3(),
			new Media_Verdict_Parser_RevSlider(),
			new Media_Verdict_Parser_LayerSlider(),
			new Media_Verdict_Parser_MasterSlider(),
			new Media_Verdict_Parser_VisualComposer(),
			new Media_Verdict_Parser_WPBakery(),
			new Media_Verdict_Parser_Cornerstone(),
			new Media_Verdict_Parser_Oxygen(),
		);

		$parsers = apply_filters( 'media_verdict_parsers', $parsers );

		$out = array();
		foreach ( (array) $parsers as $p ) {
			if ( $p instanceof Media_Verdict_Parser ) {
				$out[] = $p;
			}
		}
		return $out;
	}

	/**
	 * Parsers with data available on this site.
	 *
	 * @return Media_Verdict_Parser[]
	 */
	public static function available() {
		if ( null === self::$available_cache ) {
			self::$available_cache = array();
			foreach ( self::all() as $p ) {
				try {
					if ( $p->is_available() ) {
						self::$available_cache[] = $p;
					}
				} catch ( Exception $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement
					// A broken availability probe must never break the scan.
				}
			}
		}
		return self::$available_cache;
	}

	/**
	 * Resets the availability cache (tests).
	 *
	 * @return void
	 */
	public static function reset_cache() {
		self::$available_cache = null;
	}
}

/**
 * Smart Slider 3 (free + pro share the same tables).
 *
 * Storage: {prefix}nextend2_smartslider3_slides — params JSON holds
 * "backgroundImage", the `thumbnail` column holds a plain URL, and the
 * `slide` JSON holds per-layer "bgimage" keys. URLs are NOT escaped.
 */
class Media_Verdict_Parser_SmartSlider3 extends Media_Verdict_Parser {

	public function id() {
		return 'smartslider3';
	}

	public function label() {
		return 'Smart Slider 3';
	}

	private function table() {
		global $wpdb;
		return $wpdb->prefix . 'nextend2_smartslider3_slides';
	}

	private function sliders_table() {
		global $wpdb;
		return $wpdb->prefix . 'nextend2_smartslider3_sliders';
	}

	public function is_available() {
		return $this->table_exists( $this->table() );
	}

	public function total() {
		global $wpdb;
		$t = $this->table();
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Slider id => title map (small table, fetched whole).
	 *
	 * @return array
	 */
	private function slider_names() {
		global $wpdb;
		$t = $this->sliders_table();
		if ( ! $this->table_exists( $t ) ) {
			return array();
		}
		$rows = $wpdb->get_results( "SELECT * FROM {$t}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$map  = array();
		foreach ( (array) $rows as $row ) {
			$id           = (int) $this->col( $row, array( 'id' ) );
			$map[ $id ]   = (string) $this->col( $row, array( 'title', 'name' ), '#' . $id );
		}
		return $map;
	}

	public function scan_batch( $scanner, $offset, $limit ) {
		global $wpdb;
		$t    = $this->table();
		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( "SELECT * FROM {$t} ORDER BY id ASC LIMIT %d OFFSET %d", $limit, $offset )
		);

		$names = $this->slider_names();

		foreach ( (array) $rows as $row ) {
			$slide_id  = (int) $this->col( $row, array( 'id', 'ID' ) );
			$slider_id = (int) $this->col( $row, array( 'slider', 'slider_id', 'sliderID' ) );
			$name      = isset( $names[ $slider_id ] ) ? $names[ $slider_id ] : '#' . $slider_id;

			$blob = $this->unescape_slashes(
				$this->col( $row, array( 'thumbnail' ) ) . "\n" .
				$this->col( $row, array( 'params' ) ) . "\n" .
				$this->col( $row, array( 'slide' ) )
			);

			$label = sprintf(
				/* translators: 1: slide ID, 2: slider name */
				__( 'Smart Slider 3: slide #%1$d background "%2$s"', 'media-verdict' ),
				$slide_id,
				$name
			);

			foreach ( $scanner->find_in_text( $blob ) as $id ) {
				$scanner->add_evidence( $id, $label, 'medium' );
			}
		}

		return count( (array) $rows );
	}
}

/**
 * Revolution Slider.
 *
 * Storage: {prefix}revslider_slides.params — JSON with escaped slashes
 * (\/). Backgrounds and layer images live there. References that appear
 * ONLY as customAdminThumbSrc are admin preview thumbnails, not frontend
 * usage: still marked used (fail-safe) but with an explicit label so the
 * user can judge.
 */
class Media_Verdict_Parser_RevSlider extends Media_Verdict_Parser {

	public function id() {
		return 'revslider';
	}

	public function label() {
		return 'Revolution Slider';
	}

	private function table() {
		global $wpdb;
		return $wpdb->prefix . 'revslider_slides';
	}

	private function sliders_table() {
		global $wpdb;
		return $wpdb->prefix . 'revslider_sliders';
	}

	public function is_available() {
		return $this->table_exists( $this->table() );
	}

	public function total() {
		global $wpdb;
		$t = $this->table();
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	private function slider_aliases() {
		global $wpdb;
		$t = $this->sliders_table();
		if ( ! $this->table_exists( $t ) ) {
			return array();
		}
		$rows = $wpdb->get_results( "SELECT * FROM {$t}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$map  = array();
		foreach ( (array) $rows as $row ) {
			$id         = (int) $this->col( $row, array( 'id' ) );
			$map[ $id ] = (string) $this->col( $row, array( 'alias', 'title', 'name' ), '#' . $id );
		}
		return $map;
	}

	public function scan_batch( $scanner, $offset, $limit ) {
		global $wpdb;
		$t    = $this->table();
		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( "SELECT * FROM {$t} ORDER BY id ASC LIMIT %d OFFSET %d", $limit, $offset )
		);

		$aliases = $this->slider_aliases();

		foreach ( (array) $rows as $row ) {
			$slider_id = (int) $this->col( $row, array( 'slider_id', 'slider' ) );
			$alias     = isset( $aliases[ $slider_id ] ) ? $aliases[ $slider_id ] : '#' . $slider_id;
			$clean     = $this->unescape_slashes( $this->col( $row, array( 'params' ) ) );

			if ( '' === trim( $clean ) ) {
				continue;
			}

			$label = sprintf(
				/* translators: %s: slider alias */
				__( 'Revolution Slider: slide of "%s"', 'media-verdict' ),
				$alias
			);
			$label_admin = sprintf(
				/* translators: %s: slider alias */
				__( 'Admin preview (Revolution Slider "%s")', 'media-verdict' ),
				$alias
			);

			// Admin-only thumbnails: customAdminThumbSrc values.
			$thumb_ids = array();
			if ( preg_match_all( '/"customAdminThumbSrc"\s*:\s*"([^"]+)"/', $clean, $m ) ) {
				foreach ( $m[1] as $url ) {
					$id = $scanner->resolve_url( $url );
					if ( $id ) {
						$thumb_ids[ $id ] = true;
					}
				}
			}

			// Everything else in params.
			$rest      = preg_replace( '/"customAdminThumbSrc"\s*:\s*"[^"]*",?/', '', $clean );
			$other_ids = $scanner->find_in_text( (string) $rest );
			$other_map = array_fill_keys( $other_ids, true );

			foreach ( $scanner->find_in_text( $clean ) as $id ) {
				if ( isset( $thumb_ids[ $id ] ) && ! isset( $other_map[ $id ] ) ) {
					$scanner->add_evidence( $id, $label_admin, 'medium' );
				} else {
					$scanner->add_evidence( $id, $label, 'medium' );
				}
			}
		}

		return count( (array) $rows );
	}
}

/**
 * LayerSlider.
 *
 * Storage: {prefix}layerslider.data — JSON with escaped slashes (\/).
 * Backgrounds and layer images live there.
 */
class Media_Verdict_Parser_LayerSlider extends Media_Verdict_Parser {

	public function id() {
		return 'layerslider';
	}

	public function label() {
		return 'LayerSlider';
	}

	private function table() {
		global $wpdb;
		return $wpdb->prefix . 'layerslider';
	}

	public function is_available() {
		return $this->table_exists( $this->table() );
	}

	public function total() {
		global $wpdb;
		$t = $this->table();
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	public function scan_batch( $scanner, $offset, $limit ) {
		global $wpdb;
		$t    = $this->table();
		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( "SELECT * FROM {$t} ORDER BY id ASC LIMIT %d OFFSET %d", $limit, $offset )
		);

		foreach ( (array) $rows as $row ) {
			$name  = (string) $this->col( $row, array( 'name', 'slug', 'title' ), '#' . $this->col( $row, array( 'id', 'ID' ) ) );
			$clean = $this->unescape_slashes( $this->col( $row, array( 'data' ) ) );

			if ( '' === trim( $clean ) ) {
				continue;
			}

			$label = sprintf(
				/* translators: %s: project name */
				__( 'LayerSlider: slide background (project "%s")', 'media-verdict' ),
				$name
			);

			foreach ( $scanner->find_in_text( $clean ) as $id ) {
				$scanner->add_evidence( $id, $label, 'medium' );
			}
		}

		return count( (array) $rows );
	}
}

/**
 * Master Slider.
 *
 * Storage: {prefix}masterslider_sliders.params — JSON that is
 * BASE64-encoded. Decoded, it holds bare basenames ("/bg.jpg",
 * "/slider8-150x150.jpg") with no path or URL, so we match them
 * against the basename tokens already in the index.
 */
class Media_Verdict_Parser_MasterSlider extends Media_Verdict_Parser {

	public function id() {
		return 'masterslider';
	}

	public function label() {
		return 'Master Slider';
	}

	private function table() {
		global $wpdb;
		return $wpdb->prefix . 'masterslider_sliders';
	}

	public function is_available() {
		return $this->table_exists( $this->table() );
	}

	public function total() {
		global $wpdb;
		$t = $this->table();
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	public function scan_batch( $scanner, $offset, $limit ) {
		global $wpdb;
		$t    = $this->table();
		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( "SELECT * FROM {$t} ORDER BY id ASC LIMIT %d OFFSET %d", $limit, $offset )
		);

		foreach ( (array) $rows as $row ) {
			$raw = $this->col( $row, array( 'params' ) );
			if ( '' === trim( (string) $raw ) ) {
				continue;
			}

			$decoded = base64_decode( (string) $raw, true );
			if ( false === $decoded || '' === trim( $decoded ) ) {
				continue;
			}

			$slider_name = (string) $this->col( $row, array( 'title', 'name', 'alias' ) );
			$slider_id   = $this->col( $row, array( 'ID', 'id' ) );
			$label       = '' !== $slider_name
				? sprintf(
					/* translators: 1: title, 2: ID */
					__( 'Master Slider: slide "%1$s" (#%2$s)', 'media-verdict' ),
					$slider_name,
					$slider_id
				)
				: sprintf(
					/* translators: %s: slider ID */
					__( 'Master Slider: slide (slider #%s)', 'media-verdict' ),
					$slider_id
				);

			if ( ! preg_match_all( '/[A-Za-z0-9_][A-Za-z0-9_.\-]*\.(?:jpe?g|png|gif|webp|svg)/i', $decoded, $m ) ) {
				continue;
			}

			foreach ( array_unique( $m[0] ) as $base ) {
				$id = $scanner->resolve_token( strtolower( $base ) );
				if ( $id ) {
					$scanner->add_evidence( $id, $label, 'medium' );
				}
			}
		}

		return count( (array) $rows );
	}
}

/**
 * The NEW Visual Composer (visualcomposer.com) — not WPBakery.
 *
 * Two hiding spots, both verified on this site:
 *  1. post_content uses a template placeholder instead of the real URL:
 *     <img ... src="|!|vcvUploadUrl|!|/2026/10/img.jpg" ...>
 *     We swap the placeholder for the uploads base URL before matching.
 *  2. postmeta `vcv-pageContent` holds URL-ENCODED JSON, e.g.
 *     %22full%22%3A%22https%3A%2F%2F...%2Fimg.jpg%22%2C%22id%22%3A440
 *     We rawurldecode() first, then match URLs and "id":NNN references.
 */
class Media_Verdict_Parser_VisualComposer extends Media_Verdict_Parser {

	public function id() {
		return 'visualcomposer';
	}

	public function label() {
		return 'Visual Composer';
	}

	public function is_available() {
		global $wpdb;
		$has_meta = (bool) $wpdb->get_var( "SELECT 1 FROM {$wpdb->postmeta} WHERE meta_key = 'vcv-pageContent' LIMIT 1" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( $has_meta ) {
			return true;
		}
		$like = '%' . $wpdb->esc_like( '|!|vcvUploadUrl|!|' ) . '%';
		return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$wpdb->posts} WHERE post_content LIKE %s LIMIT 1", $like ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	private function content_total() {
		global $wpdb;
		$like = '%' . $wpdb->esc_like( '|!|vcvUploadUrl|!|' ) . '%';
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_content LIKE %s", $like ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	private function meta_total() {
		global $wpdb;
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = 'vcv-pageContent'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	public function total() {
		return $this->content_total() + $this->meta_total();
	}

	public function scan_batch( $scanner, $offset, $limit ) {
		$done = 0;
		$cc   = $this->content_total();

		if ( $offset < $cc ) {
			$n      = $this->scan_content_batch( $scanner, $offset, min( $limit, $cc - $offset ) );
			$done  += $n;
			$offset += $n;
		}
		if ( $done < $limit && $offset >= $cc ) {
			$done += $this->scan_meta_batch( $scanner, $offset - $cc, $limit - $done );
		}
		return $done;
	}

	/**
	 * Batch over post_content with the vcv placeholder.
	 *
	 * @param Media_Verdict_Scanner $scanner Scanner.
	 * @param int                   $offset  Offset.
	 * @param int                   $limit   Limit.
	 * @return int Processed.
	 */
	private function scan_content_batch( $scanner, $offset, $limit ) {
		global $wpdb;
		$like = '%' . $wpdb->esc_like( '|!|vcvUploadUrl|!|' ) . '%';
		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT ID, post_title, post_content FROM {$wpdb->posts} WHERE post_content LIKE %s ORDER BY ID ASC LIMIT %d OFFSET %d",
				$like,
				$limit,
				$offset
			)
		);

		$uploads = wp_upload_dir();
		$base    = isset( $uploads['baseurl'] ) ? $uploads['baseurl'] : '';

		foreach ( (array) $rows as $row ) {
			$fixed = str_replace( '|!|vcvUploadUrl|!|', $base, (string) $row->post_content );
			$label = sprintf(
				/* translators: 1: ID, 2: title */
				__( 'Visual Composer: page #%1$d%2$s', 'media-verdict' ),
				(int) $row->ID,
				'' !== $row->post_title ? ' «' . $row->post_title . '»' : ''
			);
			foreach ( $scanner->find_in_text( $fixed ) as $id ) {
				$scanner->add_evidence( $id, $label, 'medium' );
			}
		}

		return count( (array) $rows );
	}

	/**
	 * Batch over the vcv-pageContent meta (URL-encoded JSON).
	 *
	 * @param Media_Verdict_Scanner $scanner Scanner.
	 * @param int                   $offset  Offset.
	 * @param int                   $limit   Limit.
	 * @return int Processed.
	 */
	private function scan_meta_batch( $scanner, $offset, $limit ) {
		global $wpdb;
		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = 'vcv-pageContent' ORDER BY meta_id ASC LIMIT %d OFFSET %d",
				$limit,
				$offset
			)
		);

		foreach ( (array) $rows as $row ) {
			$decoded = rawurldecode( (string) $row->meta_value );
			if ( '' === trim( $decoded ) ) {
				continue;
			}
			$label = sprintf(
				/* translators: %d: post ID */
				__( 'Visual Composer: page #%d', 'media-verdict' ),
				(int) $row->post_id
			);

			foreach ( $scanner->find_in_text( $decoded ) as $id ) {
				$scanner->add_evidence( $id, $label, 'medium' );
			}

			// "id":NNN references inside the VC JSON (scoped to this
			// parser only — never applied to generic content).
			if ( preg_match_all( '/"id"\s*:\s*(\d+)/', $decoded, $m ) ) {
				foreach ( $m[1] as $raw ) {
					$id = $scanner->resolve_id( (int) $raw );
					if ( $id ) {
						$scanner->add_evidence( $id, $label, 'medium' );
					}
				}
			}
		}

		return count( (array) $rows );
	}
}

/**
 * Classic WPBakery Page Builder shortcodes.
 *
 * Storage: shortcodes in post_content, e.g.
 * [vc_single_image image="123" ...] or [vc_gallery images="1,2,3"].
 * Explicit attachment IDs — high confidence.
 */
class Media_Verdict_Parser_WPBakery extends Media_Verdict_Parser {

	public function id() {
		return 'wpbakery';
	}

	public function label() {
		return 'WPBakery Page Builder';
	}

	public function is_available() {
		global $wpdb;
		$like = '%' . $wpdb->esc_like( '[vc_row' ) . '%';
		if ( (bool) $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$wpdb->posts} WHERE post_content LIKE %s LIMIT 1", $like ) ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			return true;
		}
		return (bool) $wpdb->get_var( "SELECT 1 FROM {$wpdb->postmeta} WHERE meta_key = '_wpb_vc_js_status' LIMIT 1" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	public function total() {
		global $wpdb;
		$like = '%' . $wpdb->esc_like( '[vc_row' ) . '%';
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_content LIKE %s", $like ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	public function scan_batch( $scanner, $offset, $limit ) {
		global $wpdb;
		$like = '%' . $wpdb->esc_like( '[vc_row' ) . '%';
		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT ID, post_title, post_content FROM {$wpdb->posts} WHERE post_content LIKE %s ORDER BY ID ASC LIMIT %d OFFSET %d",
				$like,
				$limit,
				$offset
			)
		);

		foreach ( (array) $rows as $row ) {
			if ( ! preg_match_all( '/\bimages?="(\d[\d\s,]*)"/', (string) $row->post_content, $m ) ) {
				continue;
			}
			$label = sprintf(
				/* translators: 1: ID, 2: title */
				__( 'WPBakery: page #%1$d%2$s', 'media-verdict' ),
				(int) $row->ID,
				'' !== $row->post_title ? ' «' . $row->post_title . '»' : ''
			);
			foreach ( $m[1] as $list ) {
				foreach ( preg_split( '/[\s,]+/', $list ) as $raw ) {
					$id = $scanner->resolve_id( (int) $raw );
					if ( $id ) {
						$scanner->add_evidence( $id, $label, 'high' );
					}
				}
			}
		}

		return count( (array) $rows );
	}
}

/**
 * Cornerstone (X/Pro theme builder).
 *
 * Storage: postmeta `_cornerstone_data` = JSON `{"data":[...]}` (elements
 * nested via `_modules`). Image elements (v7) store the source as
 * `{"_type":"image","image_src":"235:full"}` — the "ID:size" attachment
 * reference format (see Cornerstone's own `cs_resolve_attachment_source()`,
 * whose docblock reads: 'Accepts an attachment reference (e.g. "123:full")
 * or a URL'). A plain URL in image_src is already covered by the generic
 * URL matcher; this parser handles the ID reference, which carries no URL.
 */
class Media_Verdict_Parser_Cornerstone extends Media_Verdict_Parser {

	public function id() {
		return 'cornerstone';
	}

	public function label() {
		return 'Cornerstone';
	}

	public function is_available() {
		global $wpdb;
		return (bool) $wpdb->get_var( "SELECT 1 FROM {$wpdb->postmeta} WHERE meta_key = '_cornerstone_data' LIMIT 1" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	public function total() {
		global $wpdb;
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = '_cornerstone_data'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	public function scan_batch( $scanner, $offset, $limit ) {
		global $wpdb;
		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_cornerstone_data' ORDER BY meta_id ASC LIMIT %d OFFSET %d",
				$limit,
				$offset
			)
		);

		foreach ( (array) $rows as $row ) {
			$blob = (string) $row->meta_value;
			if ( '' === trim( $blob ) ) {
				continue;
			}

			$title = get_the_title( (int) $row->post_id );
			$label = sprintf(
				/* translators: 1: ID, 2: title */
				__( 'Cornerstone: page #%1$d%2$s', 'media-verdict' ),
				(int) $row->post_id,
				'' !== $title ? ' «' . $title . '»' : ''
			);

			$ids = array();

			// "image_src":"235:full" — ID:size attachment reference.
			if ( preg_match_all( '/"[a-z_]*src[a-z_]*"\s*:\s*"(\d+)(?::[^"]*)?"/i', $blob, $m ) ) {
				$ids = array_merge( $ids, $m[1] );
			}

			foreach ( array_unique( $ids ) as $raw ) {
				$id = $scanner->resolve_id( (int) $raw );
				if ( $id ) {
					$scanner->add_evidence( $id, $label, 'high' );
				}
			}
		}

		return count( (array) $rows );
	}
}

/**
 * Oxygen builder.
 *
 * Storage: postmeta `ct_builder_json` = addslashes(wp_json_encode($tree))
 * — the JSON comes back with escaped slashes. Image components store
 * `{"name":"ct_image","options":{"attachment_id":"234",
 * "attachment_size":"full"}}` (attachment_id may be string or int).
 * Components may also use a plain `src` URL, which the generic URL
 * matcher already covers; this parser handles the numeric ID reference.
 */
class Media_Verdict_Parser_Oxygen extends Media_Verdict_Parser {

	public function id() {
		return 'oxygen';
	}

	public function label() {
		return 'Oxygen';
	}

	public function is_available() {
		global $wpdb;
		return (bool) $wpdb->get_var( "SELECT 1 FROM {$wpdb->postmeta} WHERE meta_key = 'ct_builder_json' LIMIT 1" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	public function total() {
		global $wpdb;
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = 'ct_builder_json'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	public function scan_batch( $scanner, $offset, $limit ) {
		global $wpdb;
		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = 'ct_builder_json' ORDER BY meta_id ASC LIMIT %d OFFSET %d",
				$limit,
				$offset
			)
		);

		foreach ( (array) $rows as $row ) {
			$blob = stripslashes( (string) $row->meta_value );
			if ( '' === trim( $blob ) ) {
				continue;
			}

			$title = get_the_title( (int) $row->post_id );
			$label = sprintf(
				/* translators: 1: ID, 2: title */
				__( 'Oxygen: page #%1$d%2$s', 'media-verdict' ),
				(int) $row->post_id,
				'' !== $title ? ' «' . $title . '»' : ''
			);

			if ( ! preg_match_all( '/"attachment_id"\s*:\s*"?(\d+)"?/', $blob, $m ) ) {
				continue;
			}

			foreach ( array_unique( $m[1] ) as $raw ) {
				$id = $scanner->resolve_id( (int) $raw );
				if ( $id ) {
					$scanner->add_evidence( $id, $label, 'high' );
				}
			}
		}

		return count( (array) $rows );
	}
}
