<?php
/**
 * Plugin Name: mavo-img-srcset
 * Plugin URI:  https://mamanvoyage.com
 * Description: Converts img tags to responsive WebP srcset on the fly, without touching the database.
 * Version:     1.0.0
 * Author:      mavo
 * License:     GPL-2.0+
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Mavo_Img_Srcset {

	/**
	 * The sizes hint for content images, matching the 960 px content column.
	 *
	 * Written out twice before — here and in the srcset built by process_img() —
	 * with nothing keeping the two in step. A sizes value that disagrees with the
	 * srcset it belongs to makes the browser pick the wrong candidate.
	 */
	private const SIZES = '(max-width: 960px) 100vw, 960px';

	public function __construct() {
		add_filter( 'the_content',                    [ $this, 'transform' ], 9 );
		add_filter( 'post_thumbnail_html',            [ $this, 'transform' ], 9 );
		add_filter( 'wp_get_attachment_image',        [ $this, 'transform' ], 9 );
		add_filter( 'wp_get_attachment_image_attributes', [ $this, 'add_fetchpriority' ], 10, 2 );
		add_action( 'wp_enqueue_scripts',             [ $this, 'enqueue_styles' ] );
		add_filter( 'wp_generate_attachment_metadata', [ $this, 'generate_webp_for_attachment' ], 10, 2 );
	}

	public function generate_webp_for_attachment( array $metadata, int $attachment_id ): array {
		if ( get_post_mime_type( $attachment_id ) !== 'image/jpeg' ) {
			return $metadata;
		}

		$file = get_attached_file( $attachment_id );
		if ( ! $file || ! file_exists( $file ) ) {
			return $metadata;
		}

		$files = [ $file ];

		if ( ! empty( $metadata['sizes'] ) ) {
			$dir = dirname( $file );
			foreach ( $metadata['sizes'] as $size ) {
				$size_path = $dir . '/' . $size['file'];
				if ( file_exists( $size_path ) ) {
					$files[] = $size_path;
				}
			}
		}

		foreach ( $files as $src ) {
			exec( sprintf(
				'cwebp -q 82 -metadata icc %s -o %s 2>/dev/null',
				escapeshellarg( $src ),
				escapeshellarg( $src . '.webp' )
			) );
		}

		return $metadata;
	}

	public function add_fetchpriority( array $attr, $attachment ): array {
		if ( ! $attachment instanceof WP_Post ) {
			return $attr;
		}

		// Ensure sizes is always present when srcset is set.
		if ( ! empty( $attr['srcset'] ) && empty( $attr['sizes'] ) ) {
			$attr['sizes'] = self::SIZES;
		}

		static $count = 0;
		if ( ! ( is_home() || is_front_page() ) ) {
			return $attr;
		}
		if ( isset( $attr['class'] ) && strpos( $attr['class'], 'wp-post-image' ) !== false ) {
			$count++;
			if ( $count === 1 ) {
				$attr['fetchpriority'] = 'high';
				$attr['loading']       = 'eager';
			}
		}
		return $attr;
	}

	public function enqueue_styles(): void {
		wp_enqueue_style(
			'mavo-img-srcset',
			plugin_dir_url( __FILE__ ) . 'mavo-img-srcset.css',
			[],
			file_exists( plugin_dir_path( __FILE__ ) . 'mavo-img-srcset.css' )
				? filemtime( plugin_dir_path( __FILE__ ) . 'mavo-img-srcset.css' )
				: '1.0.0'
		);
	}

	public function transform( string $content ): string {
		if ( is_admin() || strpos( $content, '<img' ) === false ) {
			return $content;
		}
		try {
			return $this->do_transform( $content );
		} catch ( \Throwable $e ) {
			return $content;
		}
	}

	private function do_transform( string $content ): string {
		$doc = new DOMDocument();

		// Restored straight after the parse: this is a process-global, and leaving
		// it on silenced libxml warnings for every plugin that ran later in the
		// request, not just for our own loadHTML().
		$libxml_prev = libxml_use_internal_errors( true );
		$doc->loadHTML(
			'<!DOCTYPE html><html><head><meta charset="utf-8"></head><body><div id="mavo-root">' .
			$content .
			'</div></body></html>'
		);
		libxml_clear_errors();
		libxml_use_internal_errors( $libxml_prev );

		$xpath = new DOMXPath( $doc );
		$imgs  = $xpath->query( '//div[@id="mavo-root"]//img' );

		if ( ! $imgs || $imgs->length === 0 ) {
			return $content;
		}

		// Snapshot into array and reverse so replacements don't invalidate later nodes.
		$img_list = [];
		foreach ( $imgs as $img ) {
			$img_list[] = $img;
		}
		$img_list = array_reverse( $img_list );

		$post_id = (int) get_the_ID();

		foreach ( $img_list as $img ) {
			$this->process_img( $img, $doc, $post_id );
		}

		// Serialize only mavo-root's children to avoid the wrapper div.
		$root = $xpath->query( '//div[@id="mavo-root"]' )->item( 0 );
		if ( $root === null ) {
			return $content;
		}
		$html = '';
		foreach ( $root->childNodes as $child ) {
			$html .= $doc->saveHTML( $child );
		}

		return $html;
	}

	private function get_fallback_alt( int $post_id ): string {
		static $cache = [];
		if ( array_key_exists( $post_id, $cache ) ) {
			return $cache[ $post_id ];
		}
		global $wpdb;
		$keyword = $wpdb->get_var( $wpdb->prepare(
			"SELECT primary_focus_keyword FROM {$wpdb->prefix}yoast_indexable WHERE object_id = %d LIMIT 1",
			$post_id
		) );
		$cache[ $post_id ] = $keyword !== null ? (string) $keyword : '';
		return $cache[ $post_id ];
	}

	/**
	 * URL of an intermediate size's WebP, or null when no such file exists.
	 *
	 * Every URL here is guessed by arithmetic rather than read from attachment
	 * metadata, and a guess that misses produces a srcset entry the browser
	 * cannot load — an image that simply does not paint, with nothing in any
	 * error log to say so. A sweep of the live site found 18 such URLs across 42
	 * posts, from two causes:
	 *
	 *   -rotated  WordPress writes an EXIF-rotated upload as "IMG_6585-rotated.jpeg"
	 *             but names the intermediate sizes after the un-rotated base, so
	 *             the file on disk is "IMG_6585-640x853.jpeg", never
	 *             "IMG_6585-rotated-640x853.jpeg". Every portrait phone photo hits
	 *             this. The un-rotated base is therefore tried first.
	 *
	 *   rounding  The heights below come from the width/height attributes the
	 *             editor stored, which are themselves rounded, so they can land a
	 *             pixel away from the dimensions WordPress used for the filename
	 *             (…-640x452 guessed, …-640x453 on disk).
	 *
	 * Rather than guess harder, an entry that does not resolve to a file is left
	 * out of the srcset: the browser then falls back to the 960w candidate, which
	 * has already been checked. Only unresolvable URLs are affected — an image
	 * whose three files all exist comes out byte-identical to before.
	 *
	 * The proper repair is to read $meta['sizes'] via the wp-image-NNN class, the
	 * way Mavo Picture Tag does; that changes the URL of every image on the site
	 * and is left for a separate, verifiable change.
	 */
	private function sized_webp( string $dir, string $filename, string $ext, int $width, int $height ): ?string {
		if ( $height <= 0 ) {
			return null;
		}

		$bases = [ $filename ];

		if ( preg_match( '/^(.+)-rotated$/', $filename, $m ) ) {
			array_unshift( $bases, $m[1] );
		}

		foreach ( $bases as $base ) {
			$url = $dir . '/' . $base . '-' . $width . 'x' . $height . '.' . $ext . '.webp';

			if ( $this->webp_exists( $url ) ) {
				return $url;
			}
		}

		return null;
	}

	/**
	 * Whether a derived URL resolves to a file in the uploads directory.
	 *
	 * A URL that cannot be mapped to a local path at all — a CDN, offloaded
	 * media, a rewritten domain — is reported as present, so those installs keep
	 * the behaviour they have today rather than losing every srcset entry.
	 */
	private function webp_exists( string $url ): bool {
		static $cache = [];

		if ( isset( $cache[ $url ] ) ) {
			return $cache[ $url ];
		}

		$path = $this->local_path( $url );

		return $cache[ $url ] = ( $path === null ) ? true : file_exists( $path );
	}

	/** Maps an uploads URL to its path on disk, or null if it is not one. */
	private function local_path( string $url ): ?string {
		static $uploads = null;

		if ( $uploads === null ) {
			$uploads = wp_upload_dir();
		}

		$baseurl = $uploads['baseurl'] ?? '';
		$basedir = $uploads['basedir'] ?? '';

		if ( $baseurl === '' || $basedir === '' || ! empty( $uploads['error'] ) ) {
			return null;
		}

		// http/https and protocol-relative all name the same directory.
		foreach ( [ $baseurl, set_url_scheme( $baseurl, 'http' ), set_url_scheme( $baseurl, 'https' ), preg_replace( '#^https?:#', '', $baseurl ) ] as $prefix ) {
			if ( $prefix !== '' && str_starts_with( $url, $prefix ) ) {
				return $basedir . substr( $url, strlen( $prefix ) );
			}
		}

		return null;
	}

	private function process_img( DOMElement $img, DOMDocument $doc, int $post_id ): void {
		// --- Skip conditions ---

		$width_attr = $img->getAttribute( 'width' );
		if ( $width_attr === '' || (int) $width_attr < 960 ) {
			return;
		}

		$src = $img->getAttribute( 'src' );
		if ( $src === '' ) {
			return;
		}

		if ( strpos( $src, 'i0.wp.com' ) !== false || strpos( $src, '?' ) !== false ) {
			return;
		}

		$ext = strtolower( pathinfo( $src, PATHINFO_EXTENSION ) );
		if ( ! in_array( $ext, [ 'jpg', 'jpeg' ], true ) ) {
			return;
		}

		// --- URL derivation ---

		$orig_width  = (int) $width_attr;
		$orig_height = (int) $img->getAttribute( 'height' );
		$dir         = pathinfo( $src, PATHINFO_DIRNAME );
		$filename    = pathinfo( $src, PATHINFO_FILENAME );

		$h960 = ( $orig_width > 0 && $orig_height > 0 )
			? (int) round( 960 * $orig_height / $orig_width )
			: $orig_height;
		$h640 = ( $orig_width > 0 && $orig_height > 0 )
			? (int) round( 640 * $orig_height / $orig_width )
			: 0;
		$h480 = ( $orig_width > 0 && $orig_height > 0 )
			? (int) round( 480 * $orig_height / $orig_width )
			: 0;

		// The full-size WebP carries the whole thing: it is both the src and the
		// 960w candidate, so if it is missing there is no safe output to build and
		// the original <img> is left exactly as the editor wrote it.
		$webp_960 = $src . '.webp';
		if ( ! $this->webp_exists( $webp_960 ) ) {
			return;
		}

		$srcset = [ $webp_960 . ' 960w' ];

		foreach ( [ 640 => $h640, 480 => $h480 ] as $width => $height ) {
			$candidate = $this->sized_webp( $dir, $filename, $ext, $width, $height );
			if ( $candidate !== null ) {
				$srcset[] = $candidate . ' ' . $width . 'w';
			}
		}

		// --- Build new <img> ---

		$new_img = $doc->createElement( 'img' );
		$new_img->setAttribute( 'src', $webp_960 );
		$new_img->setAttribute( 'srcset', implode( ', ', $srcset ) );
		$new_img->setAttribute( 'sizes', self::SIZES );
		$alt = $img->getAttribute( 'alt' );
		if ( $alt === '' && $post_id > 0 ) {
			$alt = $this->get_fallback_alt( $post_id );
		}
		$new_img->setAttribute( 'alt', $alt );

		// Strip alignment classes, then add aligncenter.
		$class = preg_replace( '/\balign(?:center|left|right|none)\b\s*/', '', $img->getAttribute( 'class' ) );
		$class = trim( preg_replace( '/\s+/', ' ', $class ) );
		$new_img->setAttribute( 'class', trim( $class . ' aligncenter mavo-img-tag' ) );

		$new_img->setAttribute( 'width', '960' );
		if ( $h960 > 0 ) {
			$new_img->setAttribute( 'height', (string) $h960 );
		}
		$fetchpriority = $img->getAttribute( 'fetchpriority' );
		if ( $fetchpriority !== '' ) {
			$new_img->setAttribute( 'fetchpriority', $fetchpriority );
			$new_img->setAttribute( 'loading', 'eager' );
		} else {
			$new_img->setAttribute( 'loading', 'lazy' );
		}
		$new_img->setAttribute( 'decoding', 'async' );

		// --- Determine anchor node ---

		$parent        = $img->parentNode;
		$is_centered_p = (
			$parent instanceof DOMElement &&
			$parent->nodeName === 'p' &&
			strpos( $parent->getAttribute( 'style' ), 'text-align' ) !== false &&
			strpos( $parent->getAttribute( 'style' ), 'center' ) !== false
		);
		$anchor = $is_centered_p ? $parent : $img;

		// --- Detect <em> caption immediately following the anchor ---
		// Check img's next sibling first (covers em inside a centered <p> alongside the img),
		// then fall back to the anchor's next sibling (covers em outside the <p>).

		$em_node          = null;
		$whitespace_nodes = [];

		foreach ( [ $img->nextSibling, $anchor->nextSibling ] as $start ) {
			if ( $start === null ) {
				continue;
			}
			$sibling           = $start;
			$candidate_ws      = [];
			$candidate_em      = null;
			while ( $sibling !== null ) {
				if ( $sibling instanceof DOMText && trim( $sibling->nodeValue ) === '' ) {
					$candidate_ws[] = $sibling;
					$sibling        = $sibling->nextSibling;
					continue;
				}
				if ( $sibling instanceof DOMElement && $sibling->nodeName === 'em' ) {
					$candidate_em = $sibling;
				}
				break;
			}
			if ( $candidate_em !== null ) {
				$em_node          = $candidate_em;
				$whitespace_nodes = $candidate_ws;
				break;
			}
		}

		// --- Build output node ---

		if ( $em_node !== null ) {
			$figure = $doc->createElement( 'figure' );
			$figure->setAttribute( 'class', 'wp-picture-figure' );
			$figure->appendChild( $new_img );
			$figcaption              = $doc->createElement( 'figcaption' );
			$figcaption->textContent = $em_node->textContent;
			$figure->appendChild( $figcaption );
			$output = $figure;
		} else {
			$output = $new_img;
		}

		// --- Replace anchor ---

		if ( $anchor->parentNode === null ) {
			return;
		}
		$anchor->parentNode->insertBefore( $output, $anchor );
		$anchor->parentNode->removeChild( $anchor );

		foreach ( $whitespace_nodes as $node ) {
			if ( $node->parentNode ) {
				$node->parentNode->removeChild( $node );
			}
		}
		if ( $em_node !== null && $em_node->parentNode ) {
			$em_node->parentNode->removeChild( $em_node );
		}
	}
}

new Mavo_Img_Srcset();
