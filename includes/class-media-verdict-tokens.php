<?php
/**
 * Token builder: matchable tokens per attachment + inverted index.
 *
 * An attachment can be referenced in content/meta in many forms:
 * - full URL (https://site.com/wp-content/uploads/2026/10/foto.jpg)
 * - sized variant URL (foto-300x300.jpg)
 * - relative path (2026/10/foto.jpg)
 * - bare basename (foto.jpg)
 * - attachment ID (wp-image-123, "id":123, _thumbnail_id, gallery lists)
 * - guid
 *
 * This class builds every plausible token for an attachment and an
 * inverted map token => attachment_id so the scanner can resolve a
 * reference found in text back to its owning attachment. The sized
 * variant `foto-300x300.jpg` resolves to the attachment that owns
 * `foto.jpg` (size suffix stripped).
 *
 * @package Media_Verdict
 */

defined( 'ABSPATH' ) || exit;

/**
 * Builds and queries the token index.
 */
class Media_Verdict_Tokens {

	/**
	 * Inverted index: normalized token => attachment_id.
	 *
	 * @var array
	 */
	private $map = array();

	/**
	 * Tokens grouped per attachment (for debugging).
	 *
	 * @var array
	 */
	private $per_attachment = array();

	/**
	 * Normalizes a URL or path for matching: strips scheme, host, query,
	 * fragment, url-decodes and lowercases.
	 *
	 * @param string $url URL or path.
	 * @return string
	 */
	public static function normalize( $url ) {
		$url = trim( (string) $url );
		if ( '' === $url ) {
			return '';
		}
		// Strip scheme + host.
		$url = preg_replace( '#^https?://[^/]+#i', '', $url );
		// Strip query and fragment.
		$url = preg_replace( '/[?#].*$/', '', $url );
		$url = rawurldecode( $url );
		$url = strtolower( $url );
		// Collapse accidental double slashes.
		$url = preg_replace( '#/{2,}#', '/', $url );
		return ltrim( $url, '/' );
	}

	/**
	 * Strips a WordPress size suffix (-300x300) from a basename, keeping
	 * the extension. `foto-300x300.jpg` => `foto.jpg`. Leaves names like
	 * `foto-2024.jpg` untouched.
	 *
	 * @param string $basename File basename.
	 * @return string
	 */
	public static function strip_size_suffix( $basename ) {
		return (string) preg_replace( '/-\d+x\d+(\.[a-z0-9]+)$/i', '$1', (string) $basename );
	}

	/**
	 * Builds tokens for one attachment and registers them in the index.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array List of tokens registered.
	 */
	public function add_attachment( $attachment_id ) {
		$attachment_id = (int) $attachment_id;
		if ( $attachment_id <= 0 || isset( $this->per_attachment[ $attachment_id ] ) ) {
			return isset( $this->per_attachment[ $attachment_id ] ) ? $this->per_attachment[ $attachment_id ] : array();
		}

		$tokens = array();

		// Numeric ID token.
		$tokens[ 'id:' . $attachment_id ] = true;

		$attached_file = get_post_meta( $attachment_id, '_wp_attached_file', true );
		if ( $attached_file ) {
			$norm = self::normalize( $attached_file );
			if ( '' !== $norm ) {
				$tokens[ $norm ] = true;
			}
			// Also register the uploads-relative path without year/month prefix
			// is not safe (collisions), so we keep the full relative path only.
		}

		$guid = get_post_field( 'guid', $attachment_id );
		if ( $guid ) {
			$norm = self::normalize( $guid );
			if ( '' !== $norm ) {
				$tokens[ $norm ] = true;
			}
		}

		// Basenames: original + every generated size, each also in
		// size-suffix-stripped form so `foto-300x300.jpg` => owner of `foto.jpg`.
		$basenames = array();
		if ( $attached_file ) {
			$basenames[] = wp_basename( $attached_file );
		}
		$meta = wp_get_attachment_metadata( $attachment_id );
		if ( is_array( $meta ) && ! empty( $meta['sizes'] ) && is_array( $meta['sizes'] ) ) {
			foreach ( $meta['sizes'] as $size ) {
				if ( ! empty( $size['file'] ) ) {
					$basenames[] = wp_basename( $size['file'] );
				}
			}
		}

		foreach ( array_unique( $basenames ) as $base ) {
			$base = strtolower( $base );
			$tokens[ $base ] = true;
			$stripped        = strtolower( self::strip_size_suffix( $base ) );
			if ( $stripped !== $base ) {
				$tokens[ $stripped ] = true;
			}
		}

		$this->per_attachment[ $attachment_id ] = array_keys( $tokens );
		foreach ( $tokens as $token => $_ ) {
			// First attachment to claim a token wins; collisions between two
			// attachments' basenames are ambiguous and must NOT condemn either.
			// We record the collision so the scanner can treat it as weak.
			if ( ! isset( $this->map[ $token ] ) ) {
				$this->map[ $token ] = $attachment_id;
			} elseif ( $this->map[ $token ] !== $attachment_id && 0 !== strpos( $token, 'id:' ) ) {
				$this->map[ $token ] = 0; // 0 = ambiguous, matches nothing.
			}
		}

		return $this->per_attachment[ $attachment_id ];
	}

	/**
	 * Builds the index for a list of attachment IDs.
	 *
	 * @param array $attachment_ids Attachment IDs.
	 * @return void
	 */
	public function build( array $attachment_ids ) {
		foreach ( $attachment_ids as $id ) {
			$this->add_attachment( (int) $id );
		}
	}

	/**
	 * Resolves a normalized token to an attachment ID.
	 *
	 * @param string $token Normalized token.
	 * @return int Attachment ID, or 0 when unknown/ambiguous.
	 */
	public function resolve( $token ) {
		$token = strtolower( trim( (string) $token ) );
		if ( '' === $token ) {
			return 0;
		}
		return isset( $this->map[ $token ] ) ? (int) $this->map[ $token ] : 0;
	}

	/**
	 * Resolves a raw URL or path to an attachment ID, trying the full
	 * normalized path first and the basename as fallback.
	 *
	 * @param string $url Raw URL or path.
	 * @return int Attachment ID, or 0.
	 */
	public function resolve_url( $url ) {
		$norm = self::normalize( $url );
		if ( '' === $norm ) {
			return 0;
		}
		$id = $this->resolve( $norm );
		if ( $id > 0 ) {
			return $id;
		}
		// Fallback: basename (also size-stripped).
		$base = strtolower( wp_basename( $norm ) );
		$id   = $this->resolve( $base );
		if ( $id > 0 ) {
			return $id;
		}
		return $this->resolve( self::strip_size_suffix( $base ) );
	}

	/**
	 * Resolves a numeric attachment ID token.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return int The ID if indexed, 0 otherwise.
	 */
	public function resolve_id( $attachment_id ) {
		$attachment_id = (int) $attachment_id;
		if ( $attachment_id <= 0 ) {
			return 0;
		}
		return isset( $this->per_attachment[ $attachment_id ] ) ? $attachment_id : 0;
	}

	/**
	 * Checks whether an attachment ID is in the index.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return bool
	 */
	public function has( $attachment_id ) {
		return isset( $this->per_attachment[ (int) $attachment_id ] );
	}
}
