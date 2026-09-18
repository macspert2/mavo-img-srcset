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

if ( is_admin() ) {
	require_once __DIR__ . '/includes/class-mavo-alt-admin.php';
	Mavo_Alt_Admin::register();
}

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
		// Nothing here means anything in a feed: aligncenter, mavo-img-tag and the
		// <figure> wrapper are all styled by the site's own CSS, which a feed
		// reader never loads. Skipping also avoids parsing every item's body.
		if ( is_admin() || is_feed() || strpos( $content, '<img' ) === false ) {
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

		foreach ( $img_list as $img ) {
			$this->process_img( $img, $doc );
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

	/**
	 * The attachment ID WordPress wrote into the image's class, or 0.
	 *
	 * The editor puts wp-image-NNN on every image it inserts; roughly 95% of the
	 * content images on this site carry one.
	 */
	private function attachment_id_from_class( string $class ): int {
		return preg_match( '/\bwp-image-(\d+)\b/', $class, $m ) ? (int) $m[1] : 0;
	}

	/**
	 * The alt text stored against the attachment itself, or '' if there is none.
	 *
	 * This replaces a fallback that used the post's Yoast focus keyword. That
	 * keyword is one string per post, so every un-alted image in an article was
	 * given the same text: measured across 62 pages, 1620 of 2295 content images
	 * — 71% — shared their alt with another image on the same page. For someone
	 * using a screen reader that is a dozen images all announcing "week-end à
	 * Paris en famille"; to a search engine it is the textbook description of
	 * keyword-stuffed alt text. It also meant querying a third-party plugin's
	 * private table on the front end.
	 *
	 * Where the media library has no alt either, the attribute is left empty on
	 * purpose. alt="" is the correct markup for an image with nothing useful to
	 * say about it: assistive technology skips it, which is better than reading
	 * out a keyword.
	 */
	private function media_alt( int $attachment_id ): string {
		static $cache = [];

		if ( $attachment_id < 1 ) {
			return '';
		}

		if ( ! array_key_exists( $attachment_id, $cache ) ) {
			$cache[ $attachment_id ] = trim( (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ) );
		}

		return $cache[ $attachment_id ];
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
	private function process_img( DOMElement $img, DOMDocument $doc ): void {
		// --- Alt: the media library's own, never an invented one ---
		//
		// Before the guards below, because alt is about what an image shows, not
		// what format it is in: a PNG needs a description as much as a JPEG does.
		//
		// The media library wins over whatever the editor typed into the content,
		// which is the opposite of the previous order. Tools → Image alt text is
		// where alt is curated now, with the surrounding headings visible; text
		// written there has to be able to reach the page, and it could not while
		// a stale alt baked into post_content took precedence. Content alt is
		// still the fallback when the library has nothing.

		$attachment_id = $this->attachment_id_from_class( $img->getAttribute( 'class' ) );
		$media_alt     = $this->media_alt( $attachment_id );

		if ( $media_alt !== '' ) {
			$img->setAttribute( 'alt', $media_alt );
		}

		// --- Skip conditions ---
		//
		// The width test used to sit here and gate everything, which is why an
		// image narrower than the content column got no caption and no alignment
		// class. It was never meant to: it existed because the responsive layer
		// below it needed dimensions to derive filenames from. That layer is gone,
		// so the test now guards only the part that actually depends on width.
		// Measured cost of the old placement: 14 <em> captions across 62 pages
		// silently left as italic text. Nothing was protected in exchange — the
		// site uses aligncenter throughout and has no alignleft or alignright for
		// the normalisation to trample.

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

		// --- Alignment classes: strip whatever was chosen, centre everything ---

		$class = preg_replace( '/\balign(?:center|left|right|none)\b\s*/', '', $img->getAttribute( 'class' ) );
		$class = trim( preg_replace( '/\s+/', ' ', (string) $class ) );
		$img->setAttribute( 'class', trim( $class . ' aligncenter mavo-img-tag' ) );

		// --- Display dimensions ---
		//
		// Only for images at least as wide as the content column. Forcing 960 on a
		// narrower one would ask the browser to upscale it, so a small image keeps
		// the dimensions the editor gave it and simply sits centred.
		//
		// These are layout hints rather than filenames: a pixel out costs nothing,
		// which is why this arithmetic is safe where the arithmetic that named
		// files was not.

		$width_attr  = $img->getAttribute( 'width' );
		$orig_width  = (int) $width_attr;
		$orig_height = (int) $img->getAttribute( 'height' );

		if ( $width_attr !== '' && $orig_width >= 960 ) {
			if ( $orig_height > 0 ) {
				$img->setAttribute( 'height', (string) (int) round( 960 * $orig_height / $orig_width ) );
			}

			$img->setAttribute( 'width', '960' );
		}

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
