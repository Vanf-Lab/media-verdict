<?php
/**
 * Snapshot engine: ZIP backups of attachments (original + all generated
 * sizes) with a JSON manifest, plus one-click restore.
 *
 * Snapshots live in wp-content/media-verdict/snapshots/, protected by an
 * index.php silencer and an .htaccess deny rule. Nothing is ever deleted
 * without a snapshot existing first — that is the fail-safe contract.
 *
 * @package Media_Verdict
 */

defined( 'ABSPATH' ) || exit;

/**
 * Creates, lists and restores snapshots.
 */
class Media_Verdict_Snapshot {

	/**
	 * Ensures the snapshots directory exists and is protected.
	 *
	 * @return bool
	 */
	public static function ensure_snapshot_dir() {
		$dir = MEDIA_VERDICT_SNAPSHOT_DIR;

		if ( ! file_exists( $dir ) ) {
			wp_mkdir_p( $dir );
		}

		$index = trailingslashit( $dir ) . 'index.php';
		if ( ! file_exists( $index ) ) {
			file_put_contents( $index, "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}

		$htaccess = trailingslashit( $dir ) . '.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			file_put_contents( $htaccess, "Require all denied\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}

		return file_exists( $dir ) && is_writable( $dir );
	}

	/**
	 * Returns the absolute path of every file belonging to an attachment:
	 * the original upload plus every generated size.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array List of absolute file paths that exist.
	 */
	public static function attachment_files( $attachment_id ) {
		$files  = array();
		$main   = get_attached_file( (int) $attachment_id );
		if ( $main && file_exists( $main ) ) {
			$files[] = $main;
		}

		$meta = wp_get_attachment_metadata( (int) $attachment_id );
		if ( is_array( $meta ) && ! empty( $meta['sizes'] ) && is_array( $meta['sizes'] ) && $main ) {
			$dir = dirname( $main );
			foreach ( $meta['sizes'] as $size ) {
				if ( empty( $size['file'] ) ) {
					continue;
				}
				$path = trailingslashit( $dir ) . wp_basename( $size['file'] );
				if ( file_exists( $path ) && ! in_array( $path, $files, true ) ) {
					$files[] = $path;
				}
			}
		}

		return $files;
	}

	/**
	 * Creates a snapshot ZIP for a list of attachments.
	 *
	 * @param array  $ids   Attachment IDs.
	 * @param string $label Optional label for the file name.
	 * @return array|WP_Error Array with 'file', 'path', 'count', 'bytes', 'manifest'.
	 */
	public static function create( array $ids, $label = '' ) {
		if ( ! self::ensure_snapshot_dir() ) {
			return new WP_Error( 'media_verdict_snapshot_dir', __( 'No se pudo crear el directorio de snapshots.', 'media-verdict' ) );
		}

		if ( ! class_exists( 'ZipArchive' ) ) {
			return new WP_Error( 'media_verdict_no_zip', __( 'ZipArchive no está disponible en este servidor.', 'media-verdict' ) );
		}

		$ids = array_values( array_unique( array_map( 'intval', $ids ) ) );
		$ids = array_filter( $ids );

		if ( empty( $ids ) ) {
			return new WP_Error( 'media_verdict_no_ids', __( 'No hay adjuntos para respaldar.', 'media-verdict' ) );
		}

		$upload_dir = wp_upload_dir();
		$base       = trailingslashit( $upload_dir['basedir'] );

		$stamp = gmdate( 'Ymd-His' );
		$safe  = $label ? '-' . sanitize_file_name( $label ) : '';
		$file  = "media-verdict-{$stamp}{$safe}.zip";
		$path  = trailingslashit( MEDIA_VERDICT_SNAPSHOT_DIR ) . $file;

		$zip = new ZipArchive();
		if ( true !== $zip->open( $path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
			return new WP_Error( 'media_verdict_zip_open', __( 'No se pudo crear el archivo ZIP.', 'media-verdict' ) );
		}

		$manifest = array(
			'plugin'  => 'media-verdict',
			'version' => MEDIA_VERDICT_VERSION,
			'created' => gmdate( 'c' ),
			'label'   => $label,
			'items'   => array(),
		);

		$count = 0;
		foreach ( $ids as $id ) {
			$files = self::attachment_files( $id );
			if ( empty( $files ) ) {
				continue;
			}

			$item = array(
				'attachment_id' => $id,
				'title'         => get_the_title( $id ),
				'mime'          => get_post_mime_type( $id ),
				'attached_file' => get_post_meta( $id, '_wp_attached_file', true ),
				'metadata'      => wp_get_attachment_metadata( $id ),
				'files'         => array(),
			);

			foreach ( $files as $abs ) {
				$rel = ltrim( str_replace( $base, '', wp_normalize_path( $abs ) ), '/' );
				if ( '' === $rel || strpos( $rel, '..' ) !== false ) {
					continue; // Zip-slip guard.
				}
				$zip->addFile( $abs, $rel );
				$item['files'][] = array(
					'path' => $rel,
					'md5'  => md5_file( $abs ),
					'size' => filesize( $abs ),
				);
			}

			$manifest['items'][ $id ] = $item;
			$count++;
		}

		$zip->addFromString( 'manifest.json', wp_json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
		$zip->close();

		if ( 0 === $count ) {
			@unlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions
			return new WP_Error( 'media_verdict_no_files', __( 'Ningún adjunto tenía archivos en disco.', 'media-verdict' ) );
		}

		Media_Verdict_DB::audit(
			'snapshot',
			array_keys( $manifest['items'] ),
			$file,
			sprintf(
				/* translators: %d: number of attachments */
				__( 'Snapshot creado con %d adjuntos.', 'media-verdict' ),
				$count
			)
		);

		return array(
			'file'     => $file,
			'path'     => $path,
			'count'    => $count,
			'bytes'    => filesize( $path ),
			'manifest' => $manifest,
		);
	}

	/**
	 * Whether trashed media really goes to the WordPress trash on this
	 * site. WordPress only trashes attachments when MEDIA_TRASH is true
	 * (it is NOT the default); otherwise wp_delete_attachment() silently
	 * force-deletes. The trash flow must never downgrade silently.
	 *
	 * @return bool
	 */
	public static function media_trash_available() {
		return (bool) ( defined( 'MEDIA_TRASH' ) && MEDIA_TRASH && defined( 'EMPTY_TRASH_DAYS' ) && EMPTY_TRASH_DAYS );
	}

	/**
	 * Message shown when the media trash is unavailable.
	 *
	 * @return string
	 */
	public static function media_trash_unavailable_message() {
		return __( 'La papelera de medios no está activa en este sitio (MEDIA_TRASH desactivado). Para usarla, añadí define( \'MEDIA_TRASH\', true ); a tu wp-config.php. Por seguridad, Media Verdict no mueve archivos a la papelera hasta entonces.', 'media-verdict' );
	}

	/**
	 * Lists snapshots in the directory, newest first.
	 *
	 * @return array
	 */
	public static function list_all() {
		$dir   = MEDIA_VERDICT_SNAPSHOT_DIR;
		$items = array();

		if ( ! file_exists( $dir ) ) {
			return $items;
		}

		foreach ( glob( trailingslashit( $dir ) . '*.zip' ) as $path ) {
			$items[] = array(
				'file'     => wp_basename( $path ),
				'path'     => $path,
				'bytes'    => filesize( $path ),
				'created'  => filemtime( $path ),
				'manifest' => self::read_manifest( $path ),
			);
		}

		usort(
			$items,
			function ( $a, $b ) {
				return $b['created'] - $a['created'];
			}
		);

		return $items;
	}

	/**
	 * Reads the manifest.json embedded in a snapshot ZIP.
	 *
	 * @param string $path Absolute ZIP path.
	 * @return array|null
	 */
	public static function read_manifest( $path ) {
		$zip = new ZipArchive();
		if ( true !== $zip->open( $path ) ) {
			return null;
		}
		$json = $zip->getFromName( 'manifest.json' );
		$zip->close();

		if ( ! $json ) {
			return null;
		}

		$data = json_decode( $json, true );
		return is_array( $data ) ? $data : null;
	}

	/**
	 * Restores files (and missing attachment posts) from a snapshot.
	 * Existing files are overwritten with the snapshotted bytes — the
	 * snapshot is the source of truth.
	 *
	 * @param string $file Snapshot file name (basename).
	 * @return array|WP_Error Array with 'restored_files', 'restored_posts'.
	 */
	public static function restore( $file ) {
		$file = sanitize_file_name( wp_basename( (string) $file ) );
		$path = trailingslashit( MEDIA_VERDICT_SNAPSHOT_DIR ) . $file;

		if ( ! file_exists( $path ) ) {
			return new WP_Error( 'media_verdict_snapshot_missing', __( 'El snapshot no existe.', 'media-verdict' ) );
		}

		$manifest = self::read_manifest( $path );
		if ( ! is_array( $manifest ) || empty( $manifest['items'] ) ) {
			return new WP_Error( 'media_verdict_snapshot_manifest', __( 'El manifiesto del snapshot es inválido.', 'media-verdict' ) );
		}

		$zip = new ZipArchive();
		if ( true !== $zip->open( $path ) ) {
			return new WP_Error( 'media_verdict_zip_open', __( 'No se pudo abrir el snapshot.', 'media-verdict' ) );
		}

		$upload_dir = wp_upload_dir();
		$base       = trailingslashit( wp_normalize_path( $upload_dir['basedir'] ) );

		$restored_files = 0;
		$restored_posts = 0;
		$ids            = array();

		foreach ( $manifest['items'] as $id => $item ) {
			$id     = (int) $id;
			$ids[]  = $id;

			foreach ( $item['files'] as $f ) {
				$rel = ltrim( (string) $f['path'], '/' );
				if ( '' === $rel || strpos( $rel, '..' ) !== false ) {
					continue; // Zip-slip guard.
				}
				$dest = $base . $rel;
				wp_mkdir_p( dirname( $dest ) );

				$stream = $zip->getStream( $rel );
				if ( ! $stream ) {
					continue;
				}
				$contents = stream_get_contents( $stream );
				fclose( $stream );
				if ( false === $contents ) {
					continue;
				}
				file_put_contents( $dest, $contents ); // phpcs:ignore WordPress.WP.AlternativeFunctions
				$restored_files++;

				// Verify integrity when the manifest carries an md5.
				if ( ! empty( $f['md5'] ) && md5_file( $dest ) !== $f['md5'] ) {
					$zip->close();
					return new WP_Error(
						'media_verdict_restore_md5',
						sprintf(
							/* translators: %s: file path */
							__( 'Fallo de integridad al restaurar %s.', 'media-verdict' ),
							$rel
						)
					);
				}
			}

			// Re-create the attachment post if it no longer exists, preserving
			// the original ID (import_id) so existing ID references
			// (_thumbnail_id, galleries, Elementor data) keep working.
			$post = get_post( $id );
			if ( ! $post && ! empty( $item['attached_file'] ) ) {
				$new_id = wp_insert_attachment(
					array(
						'import_id'      => $id,
						'post_title'     => $item['title'],
						'post_mime_type' => $item['mime'],
						'post_status'    => 'inherit',
					),
					$base . $item['attached_file']
				);
				if ( $new_id && ! is_wp_error( $new_id ) ) {
					update_post_meta( $new_id, '_wp_attached_file', $item['attached_file'] );
					if ( ! empty( $item['metadata'] ) ) {
						wp_update_attachment_metadata( $new_id, $item['metadata'] );
					}
					$restored_posts++;
				}
			} elseif ( $post && 'trash' === $post->post_status ) {
				wp_untrash_post( $id );
				$restored_posts++;
			}
		}

		$zip->close();

		Media_Verdict_DB::audit(
			'restore',
			$ids,
			$file,
			sprintf(
				/* translators: 1: files, 2: posts */
				__( 'Restaurados %1$d archivos y %2$d adjuntos desde el snapshot.', 'media-verdict' ),
				$restored_files,
				$restored_posts
			)
		);

		return array(
			'restored_files' => $restored_files,
			'restored_posts' => $restored_posts,
		);
	}

	/**
	 * Deletes a snapshot file.
	 *
	 * @param string $file Snapshot file name (basename).
	 * @return bool
	 */
	public static function delete( $file ) {
		$file = sanitize_file_name( wp_basename( (string) $file ) );
		$path = trailingslashit( MEDIA_VERDICT_SNAPSHOT_DIR ) . $file;

		if ( ! file_exists( $path ) || 'zip' !== strtolower( pathinfo( $path, PATHINFO_EXTENSION ) ) ) {
			return false;
		}

		Media_Verdict_DB::audit( 'snapshot_delete', array(), $file, __( 'Snapshot eliminado manualmente.', 'media-verdict' ) );

		return (bool) @unlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions
	}

	/**
	 * Removes snapshots older than the retention setting.
	 *
	 * @return int Number of snapshots deleted.
	 */
	public static function prune_old() {
		$days = (int) get_option( 'media_verdict_retention_days', 30 );
		if ( $days <= 0 ) {
			return 0;
		}

		$cutoff  = time() - ( $days * DAY_IN_SECONDS );
		$deleted = 0;

		foreach ( self::list_all() as $snap ) {
			if ( $snap['created'] < $cutoff && self::delete( $snap['file'] ) ) {
				$deleted++;
			}
		}

		return $deleted;
	}
}
