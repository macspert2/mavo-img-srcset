<?php
/**
 * Creating and locating the .webp sidecars that sit beside each JPEG.
 *
 * The site's convention is an appended extension — photo-640x480.jpg.webp — not
 * WordPress's own photo-640x480.webp, and tens of thousands of files already use
 * it, so it stays. Nothing in core writes these; this class is the only thing on
 * the site that creates them.
 *
 * Until now they were created in one place only, inline on upload, with the exit
 * code discarded. That left three gaps this class closes: a missing or unusable
 * cwebp failed silently, an interrupted conversion left a truncated file at the
 * live path, and images that predate the plugin — or whose sizes were regenerated
 * afterwards — had no way to be converted at all except a manual shell sweep that
 * lives in nobody's repository.
 */

defined( 'ABSPATH' ) || exit;

final class Mavo_Webp_Files {

	/** Matches the quality the existing sidecars were produced at. */
	public const QUALITY = 82;

	/** The mime types worth converting. PNG is excluded: it often grows. */
	public const MIME_TYPES = [ 'image/jpeg' ];

	/**
	 * Whether cwebp can actually be run.
	 *
	 * Probed once per request. exec() is frequently disabled on shared hosting,
	 * and the binary may simply not be installed — both of which used to look
	 * exactly like "conversion happened" from the caller's point of view.
	 */
	public static function available(): bool {
		static $available = null;

		if ( $available !== null ) {
			return $available;
		}

		if ( ! function_exists( 'exec' ) ) {
			return $available = false;
		}

		$disabled = array_map( 'trim', explode( ',', (string) ini_get( 'disable_functions' ) ) );

		if ( in_array( 'exec', $disabled, true ) ) {
			return $available = false;
		}

		exec( escapeshellcmd( self::binary() ) . ' -version 2>/dev/null', $out, $code );

		return $available = ( $code === 0 );
	}

	/** The cwebp binary, overridable when it is not on PATH. */
	public static function binary(): string {
		return (string) apply_filters( 'mavo_webp_cwebp_binary', 'cwebp' );
	}

	/** The sidecar that belongs to a source file. */
	public static function sidecar( string $path ): string {
		return $path . '.webp';
	}

	/**
	 * Every local file belonging to an attachment: the registered full size plus
	 * each intermediate.
	 *
	 * The pre-scaled original of a big upload (wp_get_original_image_path) is
	 * deliberately left out — no markup ever points at it, so a sidecar for it
	 * would be bytes nobody requests.
	 *
	 * @return string[] Absolute paths that exist on disk, de-duplicated.
	 */
	public static function source_files( int $attachment_id, ?array $meta = null ): array {
		$file = get_attached_file( $attachment_id );

		if ( ! $file || ! file_exists( $file ) ) {
			return [];
		}

		$meta  = $meta ?? wp_get_attachment_metadata( $attachment_id );
		$files = [ $file ];
		$dir   = dirname( $file );

		foreach ( ( is_array( $meta ) ? ( $meta['sizes'] ?? [] ) : [] ) as $size ) {
			if ( empty( $size['file'] ) ) {
				continue;
			}

			// Sizes sharing dimensions share a file; array_unique settles it below.
			$path = $dir . '/' . $size['file'];

			if ( file_exists( $path ) ) {
				$files[] = $path;
			}
		}

		return array_values( array_unique( $files ) );
	}

	/**
	 * Whether a source still needs converting.
	 *
	 * A sidecar older than its source is stale — that is what happens when sizes
	 * are regenerated after an edit — so mtime decides, not mere existence.
	 */
	public static function needs_conversion( string $path, bool $force = false ): bool {
		if ( $force ) {
			return true;
		}

		$sidecar = self::sidecar( $path );

		if ( ! file_exists( $sidecar ) ) {
			return true;
		}

		return filemtime( $sidecar ) < filemtime( $path );
	}

	/**
	 * Converts one file. Returns the sidecar's size in bytes, or 0.
	 *
	 * Written to a temporary file and renamed into place, so an interrupted run
	 * cannot leave a half-written sidecar at a path the site is actively serving
	 * — rename(2) within a directory is atomic.
	 *
	 * A sidecar that is not smaller than its JPEG is discarded rather than kept:
	 * serving it would cost the visitor bytes for nothing, and its absence makes
	 * the renderer fall back to the JPEG, which is the correct outcome.
	 */
	public static function convert( string $path ): int {
		if ( ! self::available() || ! is_readable( $path ) || filesize( $path ) < 1 ) {
			return 0;
		}

		$final = self::sidecar( $path );
		$tmp   = $final . '.' . getmypid() . '.tmp';

		exec( sprintf(
			'%s -quiet -q %d -metadata icc %s -o %s 2>/dev/null',
			escapeshellcmd( self::binary() ),
			(int) apply_filters( 'mavo_webp_quality', self::QUALITY ),
			escapeshellarg( $path ),
			escapeshellarg( $tmp )
		), $out, $code );

		if ( $code !== 0 || ! file_exists( $tmp ) || filesize( $tmp ) < 1 ) {
			@unlink( $tmp );

			return 0;
		}

		$size = filesize( $tmp );

		if ( apply_filters( 'mavo_webp_require_smaller', true ) && $size >= filesize( $path ) ) {
			@unlink( $tmp );

			return 0;
		}

		if ( ! @rename( $tmp, $final ) ) {
			@unlink( $tmp );

			return 0;
		}

		return $size;
	}

	/**
	 * Converts every file of one attachment.
	 *
	 * @return array{converted:int,skipped:int,failed:int,bytes_in:int,bytes_out:int}
	 */
	public static function for_attachment( int $attachment_id, bool $force = false, ?array $meta = null ): array {
		$result = [ 'converted' => 0, 'skipped' => 0, 'failed' => 0, 'bytes_in' => 0, 'bytes_out' => 0 ];

		if ( ! in_array( (string) get_post_mime_type( $attachment_id ), self::MIME_TYPES, true ) ) {
			return $result;
		}

		foreach ( self::source_files( $attachment_id, $meta ) as $path ) {
			if ( ! self::needs_conversion( $path, $force ) ) {
				$result['skipped']++;

				continue;
			}

			$size = self::convert( $path );

			if ( $size < 1 ) {
				$result['failed']++;

				continue;
			}

			$result['converted']++;
			$result['bytes_in']  += filesize( $path );
			$result['bytes_out'] += $size;
		}

		return $result;
	}

/**
	 * Resized files sitting beside an attachment that its metadata never recorded.
	 *
	 * Most of this library's intermediates were produced out of band rather than
	 * by WordPress, so they exist on disk while _wp_attachment_metadata['sizes']
	 * is empty. Core's wp_calculate_image_srcset() reads only that array, so for
	 * those attachments core can build nothing at all — which is why this plugin
	 * derives filenames by arithmetic in the first place.
	 *
	 * Matching is deliberately strict:
	 *   - the pattern anchors digits-x-digits directly after the base, so
	 *     "photo-2-640x480.jpg" can never be claimed by "photo.jpg";
	 *   - a candidate that is itself some attachment's registered full-size file
	 *     is skipped, so an upload named "map-960x720.jpg" is not swallowed as an
	 *     intermediate of "map.jpg";
	 *   - -rotated and -scaled are stripped from the base first, because the full
	 *     size carries those suffixes while its intermediates do not.
	 *
	 * @param string[] $taken   Absolute paths that are some attachment's own file.
	 * @param array    $listing Directory listing, reused across one directory.
	 * @return array<string,array{file:string,width:int,height:int}> Keyed by size name.
	 */
	public static function orphan_sizes( int $attachment_id, array $taken = [], ?array $listing = null ): array {
		$file = get_attached_file( $attachment_id );

		if ( ! $file || ! file_exists( $file ) ) {
			return [];
		}

		$dir  = dirname( $file );
		$name = pathinfo( $file, PATHINFO_FILENAME );
		$ext  = pathinfo( $file, PATHINFO_EXTENSION );

		// IMG_1-rotated.jpeg and IMG_1-scaled.jpg both have plain intermediates.
		$base = preg_replace( '/-scaled$/', '', $name );
		$base = preg_replace( '/-rotated$/', '', $base );

		$meta  = wp_get_attachment_metadata( $attachment_id );
		$known = [];

		foreach ( ( is_array( $meta ) ? ( $meta['sizes'] ?? [] ) : [] ) as $size ) {
			if ( ! empty( $size['file'] ) ) {
				$known[ $size['file'] ] = true;
			}
		}

		$listing = $listing ?? @scandir( $dir ) ?: [];
		$pattern = '/^' . preg_quote( $base, '/' ) . '-(\d+)x(\d+)\.' . preg_quote( $ext, '/' ) . '$/i';
		$found   = [];

		foreach ( $listing as $entry ) {
			if ( isset( $known[ $entry ] ) || $entry === basename( $file ) ) {
				continue;
			}

			if ( ! preg_match( $pattern, $entry, $m ) ) {
				continue;
			}

			if ( isset( $taken[ $dir . '/' . $entry ] ) ) {
				continue;   // someone else's original
			}

			$found[ 'mavo-' . $m[1] . 'x' . $m[2] ] = [
				'file'   => $entry,
				'width'  => (int) $m[1],
				'height' => (int) $m[2],
			];
		}

		return $found;
	}

	/**
	 * Writes discovered sizes into the attachment's metadata.
	 *
	 * Existing entries are never touched: this only adds what WordPress did not
	 * already know. Returns the number of sizes added.
	 */
	public static function record_sizes( int $attachment_id, array $sizes ): int {
		if ( ! $sizes ) {
			return 0;
		}

		$meta = wp_get_attachment_metadata( $attachment_id );
		$meta = is_array( $meta ) ? $meta : [];

		// srcset needs the full size's own dimensions; fill them if absent.
		if ( empty( $meta['width'] ) || empty( $meta['height'] ) ) {
			$dims = @getimagesize( (string) get_attached_file( $attachment_id ) );

			if ( ! $dims ) {
				return 0;
			}

			$meta['width']  = (int) $dims[0];
			$meta['height'] = (int) $dims[1];
		}

		$meta['sizes'] = array_merge( $sizes, is_array( $meta['sizes'] ?? null ) ? $meta['sizes'] : [] );

		wp_update_attachment_metadata( $attachment_id, $meta );

		return count( $sizes );
	}

	/**
	 * Every path that is some attachment's own full-size file.
	 *
	 * One query, used to stop a real upload being mistaken for an intermediate.
	 *
	 * @return array<string,true>
	 */
	public static function attached_paths(): array {
		global $wpdb;

		$rows = $wpdb->get_col(
			"SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file'"
		);

		$uploads = wp_upload_dir();
		$base    = trailingslashit( $uploads['basedir'] ?? '' );
		$paths   = [];

		foreach ( $rows as $rel ) {
			$paths[ $base . $rel ] = true;
		}

		return $paths;
	}

	/** Total JPEG attachments, for progress reporting. */
	public static function count_attachments(): int {
		global $wpdb;

		return (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(ID) FROM {$wpdb->posts}
			  WHERE post_type = 'attachment' AND post_mime_type = %s",
			self::MIME_TYPES[0]
		) );
	}

	/**
	 * A page of JPEG attachment IDs, oldest first.
	 *
	 * Ordered by ID so that paging stays stable while the run is in progress, and
	 * fetched as bare IDs rather than WP_Post objects so a large library does not
	 * have to fit in memory.
	 *
	 * @return int[]
	 */
	public static function attachment_ids( int $limit, int $offset ): array {
		global $wpdb;

		$ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT ID FROM {$wpdb->posts}
			  WHERE post_type = 'attachment' AND post_mime_type = %s
			  ORDER BY ID ASC
			  LIMIT %d OFFSET %d",
			self::MIME_TYPES[0],
			$limit,
			$offset
		) );

		return array_map( 'intval', $ids );
	}
}
