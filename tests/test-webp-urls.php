<?php
/**
 * The URL swap layer, against a real temporary uploads directory.
 *
 * The point of this layer is that it constructs no filenames, so the tests are
 * mostly about what it declines to touch.
 */
define( 'ABSPATH', true );

$GLOBALS['DIR'] = sys_get_temp_dir() . '/mavo-urls-test-' . getmypid();
@mkdir( $GLOBALS['DIR'] . '/2026/09', 0777, true );

function add_filter( ...$a ) {}
function apply_filters( $n, $v ) { return $v; }
function set_url_scheme( $u, $s ) { return preg_replace( '#^https?:#', $s . ':', $u ); }
function wp_upload_dir() {
	return [ 'baseurl' => 'https://example.com/wp-content/uploads',
	         'basedir' => $GLOBALS['DIR'], 'error' => false ];
}

require_once __DIR__ . '/../includes/class-mavo-webp-files.php';
require_once __DIR__ . '/../includes/class-mavo-webp-urls.php';

$T = 0; $F = 0;
function is_same( $e, $a, string $l ) {
	global $T, $F; $T++;
	if ( $e === $a ) { echo "  ok   $l\n"; return; }
	$F++; echo "  FAIL $l\n       expected " . var_export( $e, true ) . "\n       actual   " . var_export( $a, true ) . "\n";
}

$U = 'https://example.com/wp-content/uploads/2026/09';

touch( $GLOBALS['DIR'] . '/2026/09/has-webp.jpg.webp' );
touch( $GLOBALS['DIR'] . '/2026/09/rotated-640x853.jpeg.webp' );

/* ---- the swap ----------------------------------------------------------- */
is_same( "$U/has-webp.jpg.webp", Mavo_Webp_Urls::swap( "$U/has-webp.jpg" ),
	'a JPEG with a sidecar is swapped' );
is_same( "$U/no-webp.jpg", Mavo_Webp_Urls::swap( "$U/no-webp.jpg" ),
	'a JPEG without a sidecar is left alone — the JPEG is the smaller file' );
is_same( "$U/thing.png", Mavo_Webp_Urls::swap( "$U/thing.png" ), 'PNG is never touched' );
is_same( "$U/already.webp", Mavo_Webp_Urls::swap( "$U/already.webp" ), 'an existing WebP is not double-swapped' );
is_same( "$U/has-webp.jpg?v=2", Mavo_Webp_Urls::swap( "$U/has-webp.jpg?v=2" ),
	'a URL carrying a query string is left alone' );
is_same( 'https://cdn.example.net/x/has-webp.jpg', Mavo_Webp_Urls::swap( 'https://cdn.example.net/x/has-webp.jpg' ),
	'a URL outside the uploads directory is left alone' );
is_same( "$U/rotated-640x853.jpeg.webp", Mavo_Webp_Urls::swap( "$U/rotated-640x853.jpeg" ),
	'the -rotated intermediate core hands us resolves — no name is derived' );

/* ---- srcset ------------------------------------------------------------- */
$sources = [
	960 => [ 'url' => "$U/has-webp.jpg",  'descriptor' => 'w', 'value' => 960 ],
	640 => [ 'url' => "$U/no-webp.jpg",   'descriptor' => 'w', 'value' => 640 ],
];
$out = Mavo_Webp_Urls::filter_srcset( $sources );
is_same( "$U/has-webp.jpg.webp", $out[960]['url'], 'srcset candidate with a sidecar is swapped' );
is_same( "$U/no-webp.jpg", $out[640]['url'], 'candidate without a sidecar keeps its JPEG, not dropped' );
is_same( 2, count( $out ), 'no candidate is lost' );
is_same( 'w', $out[960]['descriptor'], 'the rest of the source entry is untouched' );
is_same( [], Mavo_Webp_Urls::filter_srcset( [] ), 'an empty srcset stays empty' );
is_same( false, Mavo_Webp_Urls::filter_srcset( false ), 'core returning false is passed through' );

/* ---- attachment src ----------------------------------------------------- */
$img = [ "$U/has-webp.jpg", 960, 720, false ];
is_same( "$U/has-webp.jpg.webp", Mavo_Webp_Urls::filter_src( $img )[0], 'attachment src is swapped' );
is_same( 960, Mavo_Webp_Urls::filter_src( $img )[1], 'width is left alone' );
is_same( false, Mavo_Webp_Urls::filter_src( false ), 'a failed lookup is passed through' );

/* ---- content tag -------------------------------------------------------- */
$tag = '<img src="' . $U . '/has-webp.jpg" srcset="' . $U . '/has-webp.jpg.webp 960w" alt="x" class="wp-image-1">';
$got = Mavo_Webp_Urls::filter_content_tag( $tag );
is_same( 1, substr_count( $got, 'has-webp.jpg.webp 960w' ), 'the srcset is not re-swapped' );
is_same( true, str_contains( $got, 'src="' . $U . '/has-webp.jpg.webp"' ), 'the content src is swapped' );
is_same( true, str_contains( $got, 'class="wp-image-1"' ), 'other attributes survive' );

$plain = '<img src="' . $U . '/no-webp.jpg" alt="y">';
is_same( $plain, Mavo_Webp_Urls::filter_content_tag( $plain ), 'a tag with nothing to swap is returned byte-identical' );

echo "\n$T assertions, $F failed\n";
exec( 'rm -rf ' . escapeshellarg( $GLOBALS['DIR'] ) );
exit( $F ? 1 : 0 );
