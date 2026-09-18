<?php
/**
 * Serving the .webp sidecars, by swapping URLs WordPress has already worked out.
 *
 * This replaces the filename arithmetic that used to build the responsive layer.
 * That arithmetic existed for a good reason — 93% of attachments had no sizes in
 * their metadata, so wp_calculate_image_srcset() could return nothing and there
 * was nothing to delegate to. `wp mavo-webp repair-sizes` closed that gap, and
 * with real filenames in metadata the guessing can go: core knows exactly which
 * files exist and at what dimensions, so the only job left is to point at the
 * sidecar beside each one.
 *
 * Two live bugs died with the arithmetic. It could not know that an EXIF-rotated
 * upload stores its intermediates under the un-rotated base, and it derived
 * heights from the editor's rounded width/height attributes, so it produced
 * filenames a pixel off the real ones. Both put 404s into srcsets. Neither is
 * expressible here, because no filename is ever constructed.
 *
 * Nothing is swapped without checking the sidecar is on disk, so an image whose
 * conversion was refused — a WebP larger than its JPEG — keeps the JPEG, which
 * is the smaller file and the right answer.
 */

defined( 'ABSPATH' ) || exit;

final class Mavo_Webp_Urls {

	public static function register(): void {
		// Covers content images and wp_get_attachment_image() alike: both build
		// their srcset through wp_calculate_image_srcset().
		add_filter( 'wp_calculate_image_srcset', [ __CLASS__, 'filter_srcset' ] );

		// The src of an attachment image (featured images, theme templates).
		add_filter( 'wp_get_attachment_image_src', [ __CLASS__, 'filter_src' ] );

		// The src of an image in post content, which core leaves as the editor
		// wrote it — core adds a srcset but never touches src.
		add_filter( 'wp_content_img_tag', [ __CLASS__, 'filter_content_tag' ] );
	}

	/**
	 * The sidecar URL for a JPEG URL, or null when there is not one to serve.
	 *
	 * Resolution is by filesystem, not by guesswork: a URL that cannot be mapped
	 * into the uploads directory — a CDN, offloaded media, another domain — is
	 * left exactly as it came in.
	 */
	public static function sidecar_url( string $url ): ?string {
		static $cache = [];

		if ( array_key_exists( $url, $cache ) ) {
			return $cache[ $url ];
		}

		if ( $url === '' || strpos( $url, '?' ) !== false ) {
			return $cache[ $url ] = null;
		}

		if ( ! in_array( strtolower( (string) pathinfo( $url, PATHINFO_EXTENSION ) ), [ 'jpg', 'jpeg' ], true ) ) {
			return $cache[ $url ] = null;
		}

		$path = self::local_path( $url );

		if ( $path === null || ! file_exists( Mavo_Webp_Files::sidecar( $path ) ) ) {
			return $cache[ $url ] = null;
		}

		return $cache[ $url ] = Mavo_Webp_Files::sidecar( $url );
	}

	/** Swaps a URL when a sidecar exists, otherwise hands it back unchanged. */
	public static function swap( string $url ): string {
		return self::sidecar_url( $url ) ?? $url;
	}

	/**
	 * Swaps each srcset candidate independently.
	 *
	 * Per candidate rather than all-or-nothing, so one missing sidecar costs that
	 * one entry its WebP instead of dropping the whole responsive set.
	 */
	public static function filter_srcset( $sources ) {
		if ( ! is_array( $sources ) ) {
			return $sources;
		}

		foreach ( $sources as $width => $source ) {
			if ( ! empty( $source['url'] ) ) {
				$sources[ $width ]['url'] = self::swap( (string) $source['url'] );
			}
		}

		return $sources;
	}

	/** Swaps the src of an attachment image. */
	public static function filter_src( $image ) {
		if ( is_array( $image ) && ! empty( $image[0] ) ) {
			$image[0] = self::swap( (string) $image[0] );
		}

		return $image;
	}

	/**
	 * Swaps the src of a content image.
	 *
	 * The srcset in the same tag was already swapped by filter_srcset(), which
	 * core applies while building it; only src is left to do here.
	 */
	public static function filter_content_tag( $tag ) {
		return (string) preg_replace_callback(
			'/\bsrc=(["\'])([^"\']+)\1/i',
			static fn( array $m ): string => 'src=' . $m[1] . self::swap( $m[2] ) . $m[1],
			(string) $tag,
			1
		);
	}

	/** Maps an uploads URL to its path on disk, or null if it is not one. */
	private static function local_path( string $url ): ?string {
		static $uploads = null;

		if ( $uploads === null ) {
			$uploads = wp_upload_dir();
		}

		$baseurl = (string) ( $uploads['baseurl'] ?? '' );
		$basedir = (string) ( $uploads['basedir'] ?? '' );

		if ( $baseurl === '' || $basedir === '' || ! empty( $uploads['error'] ) ) {
			return null;
		}

		// http, https and protocol-relative all name the same directory.
		$prefixes = [
			$baseurl,
			set_url_scheme( $baseurl, 'http' ),
			set_url_scheme( $baseurl, 'https' ),
			(string) preg_replace( '#^https?:#', '', $baseurl ),
		];

		foreach ( $prefixes as $prefix ) {
			if ( $prefix !== '' && str_starts_with( $url, $prefix ) ) {
				return $basedir . substr( $url, strlen( $prefix ) );
			}
		}

		return null;
	}
}
