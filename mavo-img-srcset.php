<?php
/**
 * Plugin Name: mavo-img-srcset
 * Plugin URI:  https://mamanvoyage.com
 * Description: Converts img tags to responsive WebP srcset on the fly, without touching the database.
 * Version:     2.0.0
 * Author:      mavo
 * License:     GPL-2.0+
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/includes/class-mavo-webp-files.php';
require_once __DIR__ . '/includes/class-mavo-webp-urls.php';

// Deleting an attachment now removes its recorded sizes, so the sidecars have to
// go with them or every deletion leaves orphans behind.
add_filter( 'wp_delete_file', [ 'Mavo_Webp_Files', 'delete_sidecar' ] );

// CLI only, so the command class never loads on a web request.
if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once __DIR__ . '/includes/class-mavo-webp-cli.php';
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

		// Core builds the srcset from real filenames; this points each candidate
		// at its .webp sidecar. Replaces the filename arithmetic that used to
		// live in process_img().
		Mavo_Webp_Urls::register();
	}

	/**
	 * Creates the sidecars for a freshly uploaded image.
	 *
	 * The work moved to Mavo_Webp_Files so that `wp mavo-webp backfill` converts
	 * files exactly the way an upload does — one implementation, not two that
	 * have to be kept in step. $metadata is passed through because it is the
	 * filter's return value, and forwarded because at this point it is fresher
	 * than anything stored for the attachment.
	 */
	public function generate_webp_for_attachment( array $metadata, int $attachment_id ): array {
		Mavo_Webp_Files::for_attachment( $attachment_id, false, $metadata );

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
	 * Editorial clean-up of one content image. The responsive layer is not here.
	 *
	 * Until the attachment metadata was repaired, this method also built the
	 * whole responsive image: it derived 640w and 480w filenames by arithmetic,
	 * appended .webp and replaced the <img> wholesale. It had to, because 93% of
	 * attachments had no sizes recorded and wp_calculate_image_srcset() could
	 * therefore return nothing. Now that the real filenames are in metadata, core
	 * builds the srcset from them and Mavo_Webp_Urls points each candidate at its
	 * sidecar — so the guessing, and the two classes of 404 it produced, are gone.
	 *
	 * What remains is the part core has no opinion about: the alt fallback, the
	 * alignment classes, unwrapping a centred <p>, and turning a trailing <em>
	 * into a <figcaption>. The guards above are unchanged on purpose, so exactly
	 * the same images are touched as before and the only difference in the output
	 * is the attributes that moved to core.
	 *
	 * The element is now modified in place rather than rebuilt, so every
	 * attribute this method does not name survives untouched.
	 */
	private function process_img( DOMElement $img, DOMDocument $doc, int $post_id ): void {
		// --- Skip conditions (deliberately identical to the previous version) ---

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

		// --- Alt fallback ---

		if ( $img->getAttribute( 'alt' ) === '' && $post_id > 0 ) {
			$img->setAttribute( 'alt', $this->get_fallback_alt( $post_id ) );
		}

		// --- Alignment classes: strip whatever was chosen, centre everything ---

		$class = preg_replace( '/\balign(?:center|left|right|none)\b\s*/', '', $img->getAttribute( 'class' ) );
		$class = trim( preg_replace( '/\s+/', ' ', (string) $class ) );
		$img->setAttribute( 'class', trim( $class . ' aligncenter mavo-img-tag' ) );

		// --- Display dimensions ---
		// Still normalised to the 960px content column. These are layout hints,
		// not filenames: being a pixel out costs nothing, which is why this
		// arithmetic is safe to keep while the arithmetic that named files was not.

		$orig_width  = (int) $width_attr;
		$orig_height = (int) $img->getAttribute( 'height' );

		if ( $orig_width > 0 && $orig_height > 0 ) {
			$img->setAttribute( 'height', (string) (int) round( 960 * $orig_height / $orig_width ) );
		}

		$img->setAttribute( 'width', '960' );

		// --- Anchor: a centred <p> wrapper is dropped, the image replaces it ---

		$parent        = $img->parentNode;
		$is_centered_p = (
			$parent instanceof DOMElement &&
			$parent->nodeName === 'p' &&
			strpos( $parent->getAttribute( 'style' ), 'text-align' ) !== false &&
			strpos( $parent->getAttribute( 'style' ), 'center' ) !== false
		);
		$anchor = $is_centered_p ? $parent : $img;

		// --- A trailing <em> becomes the caption ---
		// The image's own next sibling is checked first, which covers an <em>
		// sitting inside the centred <p> beside the image; the anchor's sibling
		// covers an <em> outside it.

		$em_node          = null;
		$whitespace_nodes = [];

		foreach ( [ $img->nextSibling, $anchor->nextSibling ] as $start ) {
			if ( $start === null ) {
				continue;
			}

			$sibling      = $start;
			$candidate_ws = [];
			$candidate_em = null;

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

		if ( $anchor->parentNode === null ) {
			return;
		}

		// --- Restructure ---

		if ( $em_node !== null ) {
			$figure = $doc->createElement( 'figure' );
			$figure->setAttribute( 'class', 'wp-picture-figure' );

			$anchor->parentNode->insertBefore( $figure, $anchor );
			$figure->appendChild( $img );   // moves the element out of the anchor

			$figcaption              = $doc->createElement( 'figcaption' );
			$figcaption->textContent = $em_node->textContent;
			$figure->appendChild( $figcaption );

			if ( $anchor !== $img ) {
				$anchor->parentNode->removeChild( $anchor );
			}
		} elseif ( $anchor !== $img ) {
			$anchor->parentNode->insertBefore( $img, $anchor );
			$anchor->parentNode->removeChild( $anchor );
		}

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
