<?php
/**
 * Tools → Image alt text: curating alt text with the context it was written in.
 *
 * The media library's own screen shows an image and a box. What it cannot show
 * is the thing that decides what the alt should say — the heading it sits under
 * and the sentence it illustrates. The same photograph of a street is "the view
 * from our balcony" in one article and "a typical Lisbon tram" in another.
 *
 * So this walks a post's content in document order, and for each image offers
 * the preceding h2, h3 and paragraph alongside the field.
 *
 * Alt is stored on the attachment, which is global: an image used in three posts
 * has one alt for all three. The usage count shown per image is there to make
 * that visible when it matters.
 */

defined( 'ABSPATH' ) || exit;

final class Mavo_Alt_Admin {

	/** Set on a post once its images have been through this screen. */
	public const META_REVIEWED = '_mavo_alt_reviewed';

	private const PAGE_SLUG = 'mavo-alt-text';
	private const ACTION    = 'mavo_alt_save';

	/** Post meta holding the rolling view count, written by recent-post-popularity. */
	private const META_VIEWS = 'views';

	public static function register(): void {
		add_action( 'admin_menu', [ __CLASS__, 'add_page' ] );
		add_action( 'admin_post_' . self::ACTION, [ __CLASS__, 'handle_save' ] );
	}

	public static function add_page(): void {
		add_management_page(
			__( 'Image alt text', 'mavo-img-srcset' ),
			__( 'Image alt text', 'mavo-img-srcset' ),
			'edit_posts',
			self::PAGE_SLUG,
			[ __CLASS__, 'render_page' ]
		);
	}

	private static function page_url( int $post_id = 0 ): string {
		$args = [ 'page' => self::PAGE_SLUG ];

		if ( $post_id ) {
			$args['post'] = $post_id;
		}

		return add_query_arg( $args, admin_url( 'tools.php' ) );
	}

	/* ------------------------------------------------------------ the posts */

	/**
	 * Posts that contain an image, treated ones first, then untreated.
	 *
	 * One query rather than WP_Query: this needs the view count and the reviewed
	 * flag for ordering, and only the title for display, so pulling whole post
	 * objects would be waste. The LIKE is a scan, but it runs once per screen for
	 * a couple of administrators.
	 *
	 * @return array<int,object{ID:int,post_title:string,views:int,reviewed:string}>
	 */
	private static function posts(): array {
		global $wpdb;

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT p.ID,
			        p.post_title,
			        CAST( COALESCE( v.meta_value, 0 ) AS UNSIGNED ) AS views,
			        COALESCE( r.meta_value, '' )                    AS reviewed
			   FROM {$wpdb->posts} p
			   LEFT JOIN {$wpdb->postmeta} v ON v.post_id = p.ID AND v.meta_key = %s
			   LEFT JOIN {$wpdb->postmeta} r ON r.post_id = p.ID AND r.meta_key = %s
			  WHERE p.post_type IN ( 'post', 'page' )
			    AND p.post_status = 'publish'
			    AND p.post_content LIKE %s
			  ORDER BY ( r.meta_value IS NULL ) ASC, views DESC, p.ID DESC",
			self::META_VIEWS,
			self::META_REVIEWED,
			'%<img%'
		) );

		return is_array( $rows ) ? $rows : [];
	}

	/* ----------------------------------------------------------- the images */

	/**
	 * Every image in a post, with the context that surrounds it.
	 *
	 * Pure parsing: no database. The usage count the screen also shows is added
	 * by the caller, so this can be tested against a string of markup alone.
	 *
	 * Context comes from the XPath reverse axis, so "preceding" means nearest
	 * earlier in document order — and an ancestor does not count, which is what
	 * we want: a paragraph wrapping the image is not the paragraph that
	 * introduces it.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function images_in( string $content ): array {
		if ( strpos( $content, '<img' ) === false ) {
			return [];
		}

		$doc         = new DOMDocument();
		$libxml_prev = libxml_use_internal_errors( true );

		$doc->loadHTML(
			'<!DOCTYPE html><html><head><meta charset="utf-8"></head><body><div id="mavo-root">'
			. $content . '</div></body></html>'
		);

		libxml_clear_errors();
		libxml_use_internal_errors( $libxml_prev );

		$xpath = new DOMXPath( $doc );
		$nodes = $xpath->query( '//div[@id="mavo-root"]//img' );
		$out   = [];

		if ( ! $nodes ) {
			return $out;
		}

		foreach ( $nodes as $i => $img ) {
			$src = (string) $img->getAttribute( 'src' );

			if ( $src === '' ) {
				continue;
			}

			$attachment_id = self::attachment_id( $img, $src );

			$out[] = [
				'index'         => $i,
				'src'           => $src,
				'filename'      => basename( (string) wp_parse_url( $src, PHP_URL_PATH ) ),
				'attachment_id' => $attachment_id,
				'content_alt'   => trim( (string) $img->getAttribute( 'alt' ) ),
				'media_alt'     => $attachment_id ? self::media_alt( $attachment_id ) : '',
				'alts'          => self::alts_for( $attachment_id ),
				'h2'            => self::nearest( $xpath, $img, 'h2' ),
				'h3'            => self::nearest( $xpath, $img, 'h3' ),
				'paragraph'     => self::nearest( $xpath, $img, 'p' ),
				'caption'       => self::caption( $img ),
			];
		}

		return $out;
	}

	/**
	 * Stored alt per language, keyed by slug.
	 *
	 * @return array<string,string>
	 */
	private static function alts_for( int $attachment_id ): array {
		$out = [];

		foreach ( self::languages() as $lang ) {
			$out[ $lang ] = self::stored_alt( $attachment_id, $lang );
		}

		return $out;
	}

	/** Nearest preceding element of a kind, as plain text. */
	private static function nearest( DOMXPath $xpath, DOMElement $img, string $tag ): string {
		$found = $xpath->query( 'preceding::' . $tag . '[1]', $img );

		if ( ! $found || $found->length === 0 ) {
			return '';
		}

		$text = trim( preg_replace( '/\s+/', ' ', (string) $found->item( 0 )->textContent ) );

		return $text;
	}

	/**
	 * The caption, if the image has one.
	 *
	 * Both shapes the site uses: a <figcaption> alongside it, and the trailing
	 * <em> that the editorial pass turns into one. Worth showing, because a
	 * caption and an alt saying the same thing is a caption read out twice.
	 */
	private static function caption( DOMElement $img ): string {
		$parent = $img->parentNode;

		if ( $parent instanceof DOMElement && $parent->nodeName === 'figure' ) {
			foreach ( $parent->childNodes as $child ) {
				if ( $child instanceof DOMElement && $child->nodeName === 'figcaption' ) {
					return trim( (string) $child->textContent );
				}
			}
		}

		$sibling = $img->nextSibling;

		while ( $sibling instanceof DOMText && trim( (string) $sibling->nodeValue ) === '' ) {
			$sibling = $sibling->nextSibling;
		}

		if ( $sibling instanceof DOMElement && $sibling->nodeName === 'em' ) {
			return trim( (string) $sibling->textContent );
		}

		return '';
	}

	/**
	 * The attachment behind an image: by class first, then by URL.
	 *
	 * 16% of this site's content images carry no wp-image-NNN class — older
	 * imported posts, which are also the likeliest to want alt text. For those
	 * the URL is all there is, and it needs the same -rotated / -scaled handling
	 * as everywhere else on this site: WordPress registers the full-size file
	 * under a suffixed name while intermediates use the plain one.
	 */
	public static function attachment_id( ?DOMElement $img, string $src ): int {
		if ( $img instanceof DOMElement
			&& preg_match( '/\bwp-image-(\d+)\b/', (string) $img->getAttribute( 'class' ), $m )
		) {
			return (int) $m[1];
		}

		foreach ( self::url_candidates( $src ) as $url ) {
			$id = (int) attachment_url_to_postid( $url );

			if ( $id > 0 ) {
				return $id;
			}
		}

		return 0;
	}

	/**
	 * URLs worth trying for an image src, most likely first.
	 *
	 * Mirrors mavo-auto-feature's mavo_attachment_url_candidates(); kept separate
	 * rather than called across plugins, per sharing-between-plugins.md — the
	 * two solve different problems and neither should white-screen if the other
	 * is deactivated.
	 *
	 * @return string[]
	 */
	public static function url_candidates( string $src ): array {
		$src  = (string) preg_replace( '/\.webp$/i', '', $src );
		$list = [ $src ];
		$base = (string) preg_replace( '/-\d+x\d+(\.[A-Za-z0-9]+)$/', '$1', $src );

		if ( $base !== $src ) {
			$list[] = $base;
		}

		foreach ( [ '-scaled', '-rotated', '-rotated-scaled' ] as $suffix ) {
			$list[] = (string) preg_replace( '/(\.[A-Za-z0-9]+)$/', $suffix . '$1', $base );
		}

		return array_values( array_unique( array_filter( $list ) ) );
	}

/* ---------------------------------------------------------- languages */

	/**
	 * Where each language's alt text is stored.
	 *
	 * The default language uses WordPress's own key, so the media library screen
	 * keeps showing the right thing and any other tool that reads alt text still
	 * works. The others hang off the same attachment under our own keys.
	 *
	 * Polylang can do this properly — Languages → Settings → Media gives every
	 * media item a translated attachment post per language, each with its own
	 * alt. It is not enabled here, and enabling it for a 16,000-image library
	 * would mean tens of thousands of new attachment posts to gain three text
	 * fields. These keys migrate into translated attachments cleanly if that ever
	 * changes.
	 */
	public static function meta_key( string $lang ): string {
		return $lang === self::default_language()
			? '_wp_attachment_image_alt'
			: '_mavo_alt_' . preg_replace( '/[^a-z0-9_]/', '', strtolower( $lang ) );
	}

	/** Site default language, asked of Polylang rather than assumed. */
	public static function default_language(): string {
		if ( function_exists( 'pll_default_language' ) ) {
			$lang = (string) pll_default_language( 'slug' );

			if ( $lang !== '' ) {
				return $lang;
			}
		}

		return 'fr';
	}

	/**
	 * Languages to offer a field for, default first.
	 *
	 * Asked of Polylang, so adding a fourth language adds a fourth box with no
	 * code change. With Polylang absent there is one language and one box.
	 *
	 * @return string[]
	 */
	public static function languages(): array {
		$default = self::default_language();

		if ( ! function_exists( 'pll_languages_list' ) ) {
			return [ $default ];
		}

		$all = (array) pll_languages_list( [ 'fields' => 'slug' ] );
		$all = array_values( array_filter( array_map( 'strval', $all ) ) );

		if ( ! $all ) {
			return [ $default ];
		}

		// Default first: it is the one that falls back to, and the one the media
		// library itself shows.
		return array_merge( [ $default ], array_values( array_diff( $all, [ $default ] ) ) );
	}

	/** The stored alt for one language, untouched by any fallback. */
	public static function stored_alt( int $attachment_id, string $lang ): string {
		if ( $attachment_id < 1 ) {
			return '';
		}

		return trim( (string) get_post_meta( $attachment_id, self::meta_key( $lang ), true ) );
	}

	private static function media_alt( int $attachment_id ): string {
		return trim( (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ) );
	}

	/**
	 * How many published posts use this attachment.
	 *
	 * Alt text is stored on the attachment, so anything above 1 means the text
	 * written here will also be read out somewhere else. A LIKE per image is the
	 * expensive part of this screen; it is bounded by the images in one post.
	 */
	private static function usage_count( int $attachment_id ): int {
		global $wpdb;

		return (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(ID) FROM {$wpdb->posts}
			  WHERE post_status = 'publish'
			    AND post_type IN ( 'post', 'page' )
			    AND post_content LIKE %s",
			'%wp-image-' . $attachment_id . '%'
		) );
	}

	/* -------------------------------------------------------------- the page */

	public static function render_page(): void {
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'You are not allowed to edit posts.', 'mavo-img-srcset' ) );
		}

		$posts = self::posts();

		if ( ! $posts ) {
			echo '<div class="wrap"><h1>' . esc_html__( 'Image alt text', 'mavo-img-srcset' ) . '</h1>'
				. '<p>' . esc_html__( 'No published posts contain images.', 'mavo-img-srcset' ) . '</p></div>';

			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- navigation only.
		$current = isset( $_GET['post'] ) ? (int) $_GET['post'] : 0;

		if ( ! $current || ! self::in_list( $posts, $current ) ) {
			$current = self::first_untreated( $posts );
		}

		$post   = get_post( $current );
		$images = $post ? self::images_in( (string) $post->post_content ) : [];

		foreach ( $images as $i => $image ) {
			$images[ $i ]['uses'] = $image['attachment_id'] ? self::usage_count( (int) $image['attachment_id'] ) : 0;
		}
		$done   = count( array_filter( $posts, static fn( $p ) => $p->reviewed !== '' ) );

		self::styles();
		?>
		<div class="wrap mavo-alt">
			<h1><?php esc_html_e( 'Image alt text', 'mavo-img-srcset' ); ?></h1>

			<p class="description">
				<?php
				printf(
					/* translators: 1: reviewed count, 2: total */
					esc_html__( '%1$d of %2$d posts reviewed. Alt text is saved to the media library, so an image used in several posts shares one description.', 'mavo-img-srcset' ),
					(int) $done,
					count( $posts )
				);
				?>
			</p>

			<?php self::render_strip( $posts, $current ); ?>

			<?php if ( ! $post ) : ?>
				<p><?php esc_html_e( 'That post could not be loaded.', 'mavo-img-srcset' ); ?></p>
			<?php else : ?>
				<h2 class="mavo-alt__post">
					<?php echo esc_html( get_the_title( $post ) ); ?>
					<a class="mavo-alt__view" href="<?php echo esc_url( (string) get_permalink( $post ) ); ?>" target="_blank" rel="noopener">
						<?php esc_html_e( 'view', 'mavo-img-srcset' ); ?>
					</a>
					<a class="mavo-alt__view" href="<?php echo esc_url( (string) get_edit_post_link( $post->ID ) ); ?>">
						<?php esc_html_e( 'edit', 'mavo-img-srcset' ); ?>
					</a>
				</h2>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
					<input type="hidden" name="post_id" value="<?php echo (int) $post->ID; ?>">
					<?php wp_nonce_field( self::ACTION . '_' . $post->ID ); ?>

					<?php if ( ! $images ) : ?>
						<p><?php esc_html_e( 'No images found in this post.', 'mavo-img-srcset' ); ?></p>
					<?php endif; ?>

					<?php foreach ( $images as $image ) : ?>
						<?php self::render_image( $image ); ?>
					<?php endforeach; ?>

					<div class="mavo-alt__actions">
						<?php submit_button( __( 'Save and mark reviewed', 'mavo-img-srcset' ), 'primary', 'submit', false ); ?>
						<?php $next = self::next_untreated( $posts, $current ); ?>
						<?php if ( $next ) : ?>
							<a class="button" href="<?php echo esc_url( self::page_url( $next ) ); ?>">
								<?php esc_html_e( 'Skip to next unreviewed', 'mavo-img-srcset' ); ?>
							</a>
						<?php endif; ?>
					</div>
				</form>
			<?php endif; ?>
		</div>
		<?php
	}

	/** One image: the picture, its context, and the field. */
	private static function render_image( array $image ): void {
		$id      = (int) $image['attachment_id'];
		$uses    = (int) ( $image['uses'] ?? 0 );
		$differs = $id > 0 && $image['content_alt'] !== '' && $image['media_alt'] !== ''
			&& $image['content_alt'] !== $image['media_alt'];
		?>
		<div class="mavo-alt__row">
			<div class="mavo-alt__thumb">
				<img src="<?php echo esc_url( $image['src'] ); ?>" alt="" loading="lazy">
			</div>

			<div class="mavo-alt__body">
				<p class="mavo-alt__file">
					<code><?php echo esc_html( $image['filename'] ); ?></code>
					<?php if ( $id ) : ?>
						<a href="<?php echo esc_url( (string) get_edit_post_link( $id ) ); ?>" target="_blank" rel="noopener">#<?php echo (int) $id; ?></a>
						<?php if ( $uses > 1 ) : ?>
							<span class="mavo-alt__warn">
								<?php
								printf(
									/* translators: %d: number of posts */
									esc_html__( 'used in %d posts — this text is shared by all of them', 'mavo-img-srcset' ),
									$uses
								);
								?>
							</span>
						<?php endif; ?>
					<?php else : ?>
						<span class="mavo-alt__warn"><?php esc_html_e( 'not in the media library — cannot be saved', 'mavo-img-srcset' ); ?></span>
					<?php endif; ?>
				</p>

				<?php foreach ( [ 'h2' => 'H2', 'h3' => 'H3', 'paragraph' => '¶', 'caption' => '❝' ] as $key => $label ) : ?>
					<?php if ( $image[ $key ] !== '' ) : ?>
						<p class="mavo-alt__ctx"><span><?php echo esc_html( $label ); ?></span> <?php echo esc_html( self::clip( (string) $image[ $key ] ) ); ?></p>
					<?php endif; ?>
				<?php endforeach; ?>

				<?php if ( $id ) : ?>
					<input type="hidden" name="attachment[]" value="<?php echo (int) $id; ?>">

					<?php
					$default = self::default_language();

					foreach ( self::languages() as $lang ) :
						$field = 'alt-' . (int) $image['index'] . '-' . $lang;
						$is_default = $lang === $default;

						// The default language prefills from the content when the
						// library is still empty — that text is usually the best
						// starting point there is. A translation has no such
						// source, so it starts blank rather than showing French.
						$prefill = $image['alts'][ $lang ] ?? '';

						if ( $is_default && $prefill === '' ) {
							$prefill = $image['content_alt'];
						}
						?>
						<label class="mavo-alt__label" for="<?php echo esc_attr( $field ); ?>">
							<?php echo esc_html( strtoupper( $lang ) ); ?>
							<?php if ( $is_default ) : ?>
								<span class="mavo-alt__hint"><?php esc_html_e( 'media library', 'mavo-img-srcset' ); ?></span>
							<?php else : ?>
								<span class="mavo-alt__hint"><?php esc_html_e( 'empty falls back to the default language', 'mavo-img-srcset' ); ?></span>
							<?php endif; ?>
						</label>
						<textarea id="<?php echo esc_attr( $field ); ?>"
						          name="alt[<?php echo esc_attr( $lang ); ?>][]"
						          rows="2"
						          class="large-text"
						          placeholder="<?php echo esc_attr( $is_default
							          ? __( 'Leave empty if the image is decorative', 'mavo-img-srcset' )
							          : __( 'Leave empty to use the default language', 'mavo-img-srcset' ) ); ?>"><?php echo esc_textarea( $prefill ); ?></textarea>
					<?php endforeach; ?>

					<?php if ( $differs ) : ?>
						<p class="mavo-alt__warn">
							<?php
							printf(
								/* translators: %s: the alt text stored in the media library */
								esc_html__( 'Different text is baked into this post: %s', 'mavo-img-srcset' ),
								esc_html( $image['content_alt'] )
							);
							?>
						</p>
					<?php endif; ?>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	/** The post switcher: reviewed on the left, still to do on the right. */
	private static function render_strip( array $posts, int $current ): void {
		echo '<div class="mavo-alt__strip" id="mavo-alt-strip">';

		foreach ( $posts as $post ) {
			$classes = 'mavo-alt__chip';

			if ( $post->reviewed !== '' ) {
				$classes .= ' is-done';
			}

			if ( (int) $post->ID === $current ) {
				$classes .= ' is-current';
			}

			printf(
				'<a class="%s" href="%s" id="%s" title="%s">%s%s</a>',
				esc_attr( $classes ),
				esc_url( self::page_url( (int) $post->ID ) ),
				(int) $post->ID === $current ? 'mavo-alt-current' : '',
				esc_attr( sprintf( '%s — %d views', $post->post_title, (int) $post->views ) ),
				$post->reviewed !== '' ? '✓ ' : '',
				esc_html( self::clip( (string) $post->post_title, 38 ) )
			);
		}

		echo '</div>';

		// Bring the selected chip into view: the untreated posts sit to the
		// right of everything already done, which can be a long way along.
		echo '<script>(function(){var c=document.getElementById("mavo-alt-current");'
			. 'if(c){c.scrollIntoView({block:"nearest",inline:"center"});}})();</script>';
	}

	private static function clip( string $text, int $max = 140 ): string {
		return mb_strlen( $text ) > $max ? mb_substr( $text, 0, $max - 1 ) . '…' : $text;
	}

	private static function in_list( array $posts, int $id ): bool {
		foreach ( $posts as $post ) {
			if ( (int) $post->ID === $id ) {
				return true;
			}
		}

		return false;
	}

	/** Highest-view post nobody has been through yet. */
	private static function first_untreated( array $posts ): int {
		foreach ( $posts as $post ) {
			if ( $post->reviewed === '' ) {
				return (int) $post->ID;
			}
		}

		return (int) $posts[0]->ID;
	}

	private static function next_untreated( array $posts, int $current ): int {
		foreach ( $posts as $post ) {
			if ( $post->reviewed === '' && (int) $post->ID !== $current ) {
				return (int) $post->ID;
			}
		}

		return 0;
	}

	/* -------------------------------------------------------------- the save */

	public static function handle_save(): void {
		$post_id = isset( $_POST['post_id'] ) ? (int) $_POST['post_id'] : 0;

		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_die( esc_html__( 'You are not allowed to edit this post.', 'mavo-img-srcset' ) );
		}

		check_admin_referer( self::ACTION . '_' . $post_id );

		$ids   = isset( $_POST['attachment'] ) ? array_map( 'intval', (array) wp_unslash( $_POST['attachment'] ) ) : [];
		$posted = isset( $_POST['alt'] ) && is_array( $_POST['alt'] ) ? wp_unslash( $_POST['alt'] ) : [];
		$saved  = 0;

		foreach ( self::languages() as $lang ) {
			$values = isset( $posted[ $lang ] ) ? array_map( 'sanitize_text_field', (array) $posted[ $lang ] ) : [];

			foreach ( $ids as $i => $attachment_id ) {
				if ( $attachment_id < 1 || ! isset( $values[ $i ] ) ) {
					continue;
				}

				$alt = trim( $values[ $i ] );
				$key = self::meta_key( $lang );

				if ( self::stored_alt( $attachment_id, $lang ) === $alt ) {
					continue;
				}

				// An empty box is a decision, not an omission: for the default
				// language it means the image is decorative, and for a
				// translation it means "use the default". Both are stored as
				// empty rather than skipped, so clearing a field actually clears
				// it — deleting the row keeps get_post_meta() honest.
				if ( $alt === '' ) {
					delete_post_meta( $attachment_id, $key );
				} else {
					update_post_meta( $attachment_id, $key, $alt );
				}

				$saved++;
			}
		}

		update_post_meta( $post_id, self::META_REVIEWED, (string) time() );

		$next = self::next_untreated( self::posts(), $post_id );

		wp_safe_redirect( add_query_arg(
			[ 'saved' => $saved ],
			self::page_url( $next ?: $post_id )
		) );

		exit;
	}

	private static function styles(): void {
		?>
		<style>
			.mavo-alt__strip { display:flex; gap:4px; overflow-x:auto; padding:8px 0; margin:12px 0 20px; border-bottom:1px solid #dcdcde; }
			.mavo-alt__chip { flex:0 0 auto; padding:4px 10px; border:1px solid #c3c4c7; border-radius:12px; background:#fff; font-size:12px; text-decoration:none; white-space:nowrap; }
			.mavo-alt__chip.is-done { opacity:.5; background:#f6f7f7; }
			.mavo-alt__chip.is-current { border-color:#2271b1; box-shadow:0 0 0 1px #2271b1; font-weight:600; }
			.mavo-alt__post { margin:0 0 4px; }
			.mavo-alt__view { font-size:12px; font-weight:400; margin-left:8px; }
			.mavo-alt__row { display:flex; gap:20px; padding:18px 0; border-top:1px solid #dcdcde; }
			.mavo-alt__thumb { flex:0 0 480px; max-width:480px; }
			.mavo-alt__thumb img { width:100%; height:auto; display:block; border:1px solid #dcdcde; }
			.mavo-alt__body { flex:1 1 auto; min-width:280px; }
			.mavo-alt__file { margin:0 0 10px; }
			.mavo-alt__ctx { margin:2px 0; color:#50575e; font-size:13px; }
			.mavo-alt__ctx span { display:inline-block; min-width:2em; color:#787c82; font-weight:600; }
			.mavo-alt__label { display:block; margin:12px 0 4px; font-weight:600; }
			.mavo-alt__hint { font-weight:400; color:#787c82; font-size:12px; margin-left:6px; }
			.mavo-alt__warn { color:#8a6d00; font-size:12px; }
			.mavo-alt__actions { position:sticky; bottom:0; background:#f0f0f1; padding:12px 0; margin-top:20px; border-top:1px solid #c3c4c7; display:flex; gap:10px; align-items:center; }
			@media (max-width:1100px) { .mavo-alt__row { flex-direction:column; } .mavo-alt__thumb { flex-basis:auto; } }
		</style>
		<?php
	}
}
