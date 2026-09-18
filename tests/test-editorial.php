<?php
/**
 * The editorial DOM pass, after the responsive layer moved to core.
 *
 * Half these assertions are about what the plugin must no longer do: srcset,
 * sizes, loading and decoding are core's now, and an <img> that still carried a
 * plugin-built srcset would make core skip the image entirely.
 */
define( 'ABSPATH', true );

function add_filter( ...$a ) {}
function add_action( ...$a ) {}
function apply_filters( $n, $v ) { return $v; }
function plugin_dir_url( $f )  { return '/p/'; }
function plugin_dir_path( $f ) { return __DIR__ . '/'; }
function is_admin() { return false; }
function is_feed()  { return $GLOBALS['IS_FEED'] ?? false; }
function get_post_meta( $id, $key, $single = false ) { return $GLOBALS['MEDIA_ALT'][ $id ][ $key ] ?? ''; }
function pll_current_language( $f = 'slug' ) { return $GLOBALS['LANG'] ?? 'fr'; }
function pll_default_language( $f = 'slug' ) { return 'fr'; }
function pll_languages_list( $a = [] ) { return [ 'fr', 'en', 'de' ]; }
function add_management_page( ...$a ) {}
function __( $t, $d = '' ) { return $t; }
function set_url_scheme( $u, $s ) { return preg_replace( '#^https?:#', $s . ':', $u ); }
function wp_upload_dir() { return [ 'baseurl' => 'https://example.com/wp-content/uploads', 'basedir' => '/nonexistent', 'error' => false ]; }

$GLOBALS['MEDIA_ALT'] = [];
unset( $GLOBALS['LANG'] );   // attachment id => alt stored in the media library
$GLOBALS['IS_FEED']   = false;

require_once __DIR__ . '/../mavo-img-srcset.php';

$T = 0; $F = 0;
function ok( bool $c, string $l ) { global $T,$F; $T++; echo $c ? "  ok   $l\n" : "  FAIL $l\n"; if(!$c){$F++;} }
function is_same( $e, $a, string $l ) {
	global $T,$F; $T++;
	if ( $e === $a ) { echo "  ok   $l\n"; return; }
	$F++; echo "  FAIL $l\n       expected " . var_export($e,true) . "\n       actual   " . var_export($a,true) . "\n";
}

$U = 'https://example.com/wp-content/uploads/2026/09';
function t( string $html ): string {
	return trim( preg_replace( '/\s+/', ' ', ( new Mavo_Img_Srcset() )->transform( $html ) ) );
}

/* ---- the responsive layer must be gone ---------------------------------- */
$out = t( '<img src="' . $U . '/a.jpg" width="960" height="720" alt="x">' );
ok( ! str_contains( $out, 'srcset' ), 'no srcset is built — core does that from metadata now' );
ok( ! str_contains( $out, 'sizes=' ), 'no sizes attribute — core derives it' );
ok( ! str_contains( $out, 'loading=' ), 'no loading attribute — core decides eager vs lazy' );
ok( ! str_contains( $out, 'decoding=' ), 'no decoding attribute' );
ok( str_contains( $out, 'src="' . $U . '/a.jpg"' ), 'src is left as the editor wrote it, for core to match' );
ok( ! str_contains( $out, '.webp' ), 'no .webp URL is constructed here' );

/* ---- editorial behaviour is unchanged ----------------------------------- */
$out = t( '<img src="' . $U . '/a.jpg" width="960" height="720" class="alignleft size-full wp-image-7" alt="x">' );
ok( str_contains( $out, 'aligncenter' ), 'alignment is normalised to centre' );
ok( ! str_contains( $out, 'alignleft' ), 'the original alignment class is stripped' );
ok( str_contains( $out, 'mavo-img-tag' ), 'the marker class is added' );
ok( str_contains( $out, 'wp-image-7' ), 'wp-image-NNN survives — core needs it to find the attachment' );
ok( str_contains( $out, 'size-full' ), 'unrelated classes survive' );
ok( str_contains( $out, 'width="960"' ), 'width is normalised to the content column' );
ok( str_contains( $out, 'height="720"' ), 'height is scaled to match' );

$out = t( '<img src="' . $U . '/a.jpg" width="1280" height="960" alt="x">' );
ok( str_contains( $out, 'height="720"' ), 'a 1280x960 image is described as 960x720' );

/* ---- attributes the plugin does not name must survive ------------------- */
$out = t( '<img src="' . $U . '/a.jpg" width="960" height="720" alt="x" data-mavo-post-id="42" title="T">' );
ok( str_contains( $out, 'data-mavo-post-id="42"' ), 'unknown attributes survive the in-place edit' );
ok( str_contains( $out, 'title="T"' ), 'title survives' );

/* ---- captions ------------------------------------------------------------ */
$out = t( '<img src="' . $U . '/a.jpg" width="960" height="720" alt="x"> <em>A caption</em>' );
ok( str_contains( $out, '<figure class="wp-picture-figure">' ), 'a trailing <em> produces a figure' );
ok( str_contains( $out, '<figcaption>A caption</figcaption>' ), 'the em text becomes the caption' );
ok( ! str_contains( $out, '<em>' ), 'the original em is removed' );

$out = t( '<p style="text-align: center;"><img src="' . $U . '/a.jpg" width="960" height="720" alt="x"></p>' );
ok( ! str_contains( $out, '<p style' ), 'a centred <p> wrapper is dropped' );
ok( str_contains( $out, 'aligncenter' ), 'centring moves to the class' );

$out = t( '<p style="text-align: center;"><img src="' . $U . '/a.jpg" width="960" height="720" alt="x"> <em>Cap</em></p>' );
ok( str_contains( $out, '<figcaption>Cap</figcaption>' ), 'an em inside the centred p is still found' );
ok( ! str_contains( $out, '<p style' ), 'and the wrapper still goes' );

$out = t( '<img src="' . $U . '/a.jpg" width="960" height="720" alt="x"> <strong>not a caption</strong>' );
ok( ! str_contains( $out, 'figure' ), 'a following element that is not <em> does not make a figure' );

/* ---- alt comes from the media library, never invented -------------------- */
// The library now WINS over content alt: Tools → Image alt text writes there,
// and text written there has to be able to reach the page.
// Id 70, not 7: an earlier assertion above already looked 7 up while the
// stub was empty, and media_alt() caches per request.
$GLOBALS['MEDIA_ALT'] = [ 70 => [ '_wp_attachment_image_alt' => 'Le Lac Noir, Durmitor' ] ];

ok( str_contains( t( '<img src="' . $U . '/a.jpg" width="960" height="720" alt="" class="wp-image-70">' ), 'alt="Le Lac Noir, Durmitor"' ),
	'an empty alt is filled from the attachment\'s own alt text' );
ok( str_contains( t( '<img src="' . $U . '/a.jpg" width="960" height="720" alt="stale" class="wp-image-70">' ), 'alt="Le Lac Noir, Durmitor"' ),
	'the media library overrides an alt baked into the content' );
ok( str_contains( t( '<img src="' . $U . '/a.jpg" width="960" height="720" alt="kept" class="wp-image-99">' ), 'alt="kept"' ),
	'content alt survives when the library has nothing' );
ok( str_contains( t( '<img src="' . $U . '/a.jpg" width="960" height="720" alt="   " class="wp-image-70">' ), 'alt="Le Lac Noir, Durmitor"' ),
	'a whitespace-only alt counts as empty' );

$out = t( '<img src="' . $U . '/a.jpg" width="960" height="720" alt="" class="wp-image-99">' );
ok( str_contains( $out, 'alt=""' ), 'no media alt -> alt stays empty, which is correct for a decorative image' );

// A PNG gets no responsive treatment, but it still gets its description.
ok( str_contains( t( '<img src="' . $U . '/a.png" width="960" height="720" alt="" class="wp-image-70">' ), 'alt="Le Lac Noir, Durmitor"' ),
	'alt reaches a PNG too — it runs before the format guards' );
ok( ! str_contains( t( '<img src="' . $U . '/a.png" width="960" height="720" class="wp-image-70">' ), 'mavo-img-tag' ),
	'...without the PNG gaining any of the other editorial treatment' );
ok( ! str_contains( $out, 'wp-image-99"' ) || ! preg_match( '/alt="[^"]+"/', $out ),
	'nothing is invented to fill the gap' );

$out = t( '<img src="' . $U . '/a.jpg" width="960" height="720" alt="">' );
ok( str_contains( $out, 'alt=""' ), 'an image with no wp-image class keeps an empty alt' );
$GLOBALS['MEDIA_ALT'] = [];
unset( $GLOBALS['LANG'] );

/* ---- translations ------------------------------------------------------- */
// Stored on the same attachment; Polylang media translation is not enabled here.
$GLOBALS['MEDIA_ALT'] = [ 71 => [
	'_wp_attachment_image_alt' => 'Le Lac Noir',
	'_mavo_alt_de'             => 'Der Schwarze See',
] ];

$img = '<img src="' . $U . '/a.jpg" width="960" height="720" alt="" class="wp-image-71">';

$GLOBALS['LANG'] = 'de';
ok( str_contains( t( $img ), 'alt="Der Schwarze See"' ), 'a German page gets the German alt' );

$GLOBALS['LANG'] = 'en';
ok( str_contains( t( $img ), 'alt="Le Lac Noir"' ),
	'an untranslated language falls back to the default — alt="" would tell a screen reader to skip the photo' );

$GLOBALS['LANG'] = 'fr';
ok( str_contains( t( $img ), 'alt="Le Lac Noir"' ), 'the default language reads the media library key' );
unset( $GLOBALS['LANG'] );
$GLOBALS['MEDIA_ALT'] = [];

/* ---- skip conditions, unchanged ------------------------------------------ */
foreach ( [
	[ '<img src="' . $U . '/a.png" width="960" height="720">', 'not a JPEG' ],
	[ '<img src="' . $U . '/a.jpg?x=1" width="960" height="720">', 'carries a query string' ],
	[ '<img src="https://i0.wp.com/a.jpg" width="960" height="720">', 'a Photon URL' ],
	[ '<img width="960" height="720">', 'no src' ],
] as [ $html, $why ] ) {
	ok( ! str_contains( t( $html ), 'mavo-img-tag' ), "skipped: $why" );
}

/* ---- narrow images: styled, but never upscaled -------------------------- */
$out = t( '<img src="' . $U . '/small.jpg" width="600" height="400" class="alignleft"> <em>Caption</em>' );
ok( str_contains( $out, 'mavo-img-tag' ), 'an image narrower than the column is now styled' );
ok( str_contains( $out, '<figcaption>Caption</figcaption>' ), '...and gets its caption — this is the case the old gate silently dropped' );
ok( str_contains( $out, 'width="600"' ), '...but keeps its own width, so the browser is not asked to upscale it' );
ok( str_contains( $out, 'height="400"' ), '...and its own height' );

$out = t( '<img src="' . $U . '/nodims.jpg"> <em>Caption</em>' );
ok( str_contains( $out, '<figcaption>Caption</figcaption>' ), 'an image with no dimensions at all still gets its caption' );
ok( ! str_contains( $out, 'width="960"' ), '...and gains no invented width' );

$out = t( '<img src="' . $U . '/big.jpg" width="1280" height="960">' );
ok( str_contains( $out, 'width="960"' ), 'an image at least as wide as the column is still normalised' );
ok( str_contains( $out, 'height="720"' ), '...with a scaled height' );

/* ---- feeds --------------------------------------------------------------- */
$GLOBALS['IS_FEED'] = true;
$feed = '<img src="' . $U . '/a.jpg" width="960" height="720" alt="x"> <em>Cap</em>';
is_same( $feed, ( new Mavo_Img_Srcset() )->transform( $feed ), 'feeds are left completely alone' );
$GLOBALS['IS_FEED'] = false;

/* ---- content without images is returned untouched ------------------------ */
is_same( '<p>Hello</p>', t( '<p>Hello</p>' ), 'content with no <img> is passed straight through' );

echo "\n$T assertions, $F failed\n";
exit( $F ? 1 : 0 );
