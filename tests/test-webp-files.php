<?php
/**
 * Runs Mavo_Webp_Files against real JPEGs in a real temporary directory, with a
 * real cwebp. Nothing about the conversion is mocked, because the failures worth
 * catching here — a truncated sidecar, a silent non-zero exit, a WebP larger than
 * its JPEG — only exist at the point where the binary actually runs.
 *
 * Skips itself if cwebp is not installed.
 */
define( 'ABSPATH', true );

$GLOBALS['DIR']     = sys_get_temp_dir() . '/mavo-webp-test-' . getmypid();
$GLOBALS['FILTERS'] = [];   // filter name => forced value
$GLOBALS['SEEN']    = [];   // filter names consulted
$GLOBALS['ATT']     = [];

@mkdir( $GLOBALS['DIR'], 0777, true );

function apply_filters( $name, $value ) {
	$GLOBALS['SEEN'][ $name ] = true;

	return array_key_exists( $name, $GLOBALS['FILTERS'] ) ? $GLOBALS['FILTERS'][ $name ] : $value;
}
function get_attached_file( $id )          { return $GLOBALS['ATT'][ $id ]['file'] ?? false; }
function wp_get_attachment_metadata( $id ) { return $GLOBALS['ATT'][ $id ]['meta'] ?? false; }
function get_post_mime_type( $id )         { return $GLOBALS['ATT'][ $id ]['mime'] ?? false; }

require_once __DIR__ . '/../includes/class-mavo-webp-files.php';

$T = 0; $F = 0;
function ok( bool $c, string $l ) {
	global $T, $F; $T++;
	echo $c ? "  ok   $l\n" : "  FAIL $l\n";
	if ( ! $c ) { $F++; }
}
function is_same( $e, $a, string $l ) {
	global $T, $F; $T++;
	if ( $e === $a ) { echo "  ok   $l\n"; return; }
	$F++; echo "  FAIL $l\n       expected " . var_export( $e, true ) . "\n       actual   " . var_export( $a, true ) . "\n";
}

if ( ! Mavo_Webp_Files::available() ) {
	echo "  cwebp not installed — skipping.\n";
	exit( 0 );
}

/** A photographic-ish JPEG, so WebP has something to win on. */
function make_jpeg( string $name, int $w = 400, int $h = 300, int $quality = 92 ): string {
	$im = imagecreatetruecolor( $w, $h );
	for ( $x = 0; $x < $w; $x += 2 ) {
		for ( $y = 0; $y < $h; $y += 2 ) {
			$c = imagecolorallocate( $im, ( $x * 7 ) % 256, ( $y * 5 ) % 256, ( $x * $y ) % 256 );
			imagefilledrectangle( $im, $x, $y, $x + 1, $y + 1, $c );
		}
	}
	$path = $GLOBALS['DIR'] . '/' . $name;
	imagejpeg( $im, $path, $quality );

	return $path;
}

/* ---- naming ------------------------------------------------------------- */
is_same( '/a/b/photo-640x480.jpg.webp', Mavo_Webp_Files::sidecar( '/a/b/photo-640x480.jpg' ),
	'sidecar keeps the site convention of an appended extension' );

/* ---- conversion --------------------------------------------------------- */
$src = make_jpeg( 'photo.jpg' );
$out = Mavo_Webp_Files::convert( $src );
ok( $out > 0, 'a real JPEG converts' );
ok( file_exists( $src . '.webp' ), 'the sidecar lands beside the source' );
ok( $out < filesize( $src ), 'the sidecar is smaller than the JPEG' );
is_same( [], glob( $GLOBALS['DIR'] . '/*.tmp' ), 'no temporary file is left behind' );
ok( str_starts_with( (string) file_get_contents( $src . '.webp', false, null, 0, 4 ), 'RIFF' ),
	'the sidecar really is a WebP' );

/* ---- staleness ---------------------------------------------------------- */
is_same( false, Mavo_Webp_Files::needs_conversion( $src ), 'a current sidecar is left alone' );
is_same( true,  Mavo_Webp_Files::needs_conversion( $src, true ), '--force overrides a current sidecar' );

touch( $src, time() + 10 );   // source edited after its sidecar
is_same( true, Mavo_Webp_Files::needs_conversion( $src ),
	'a sidecar older than its source counts as stale, not as done' );

$missing = $GLOBALS['DIR'] . '/never-converted.jpg';
touch( $missing );
is_same( true, Mavo_Webp_Files::needs_conversion( $missing ), 'a missing sidecar needs conversion' );

/* ---- a WebP that is not smaller is discarded ---------------------------- */
// A source already crushed to q=20, re-encoded at q=100: WebP reliably grows.
$tiny = make_jpeg( 'tiny.jpg', 200, 200, 15 );
$GLOBALS['FILTERS']['mavo_webp_quality'] = 100;
is_same( 0, Mavo_Webp_Files::convert( $tiny ), 'a WebP no smaller than its JPEG is refused' );
ok( ! file_exists( $tiny . '.webp' ), '...and no sidecar is left for the renderer to prefer' );
unset( $GLOBALS['FILTERS']['mavo_webp_quality'] );
is_same( [], glob( $GLOBALS['DIR'] . '/*.tmp' ), 'still no temporary file left behind' );

/* ---- filters are actually consulted ------------------------------------- */
ok( isset( $GLOBALS['SEEN']['mavo_webp_quality'] ),         'quality is filterable' );
ok( isset( $GLOBALS['SEEN']['mavo_webp_require_smaller'] ), 'the smaller-only rule is filterable' );

/* ---- attachment traversal ----------------------------------------------- */
$full  = make_jpeg( 'IMG_1.jpg' );
$small = make_jpeg( 'IMG_1-640x480.jpg' );
$GLOBALS['ATT'][7] = [
	'file' => $full,
	'mime' => 'image/jpeg',
	'meta' => [ 'sizes' => [
		'a'       => [ 'file' => 'IMG_1-640x480.jpg' ],
		'same'    => [ 'file' => 'IMG_1-640x480.jpg' ],   // two sizes, one file
		'missing' => [ 'file' => 'IMG_1-999x999.jpg' ],   // recorded but absent
	] ],
];
is_same( [ $full, $small ], Mavo_Webp_Files::source_files( 7 ),
	'source files are de-duplicated and absent ones dropped' );

$r = Mavo_Webp_Files::for_attachment( 7 );
is_same( 2, $r['converted'], 'both files of the attachment convert' );
is_same( 0, $r['failed'], 'none fail' );
is_same( 0, Mavo_Webp_Files::for_attachment( 7 )['converted'], 're-running converts nothing' );
is_same( 2, Mavo_Webp_Files::for_attachment( 7 )['skipped'], 're-running reports both as already current' );

/* ---- a non-JPEG attachment is left alone -------------------------------- */
$GLOBALS['ATT'][8] = [ 'file' => $full, 'mime' => 'image/png', 'meta' => [] ];
is_same( 0, Mavo_Webp_Files::for_attachment( 8 )['converted'], 'PNG attachments are skipped' );

/* ---- discovering resized files that metadata never recorded ------------- */

function attach( int $id, string $file, array $sizes = [] ): void {
	$GLOBALS['ATT'][ $id ] = [
		'file' => $file,
		'mime' => 'image/jpeg',
		'meta' => [ 'width' => 960, 'height' => 720, 'sizes' => $sizes ],
	];
}

$d = $GLOBALS['DIR'] . '/orphans';
@mkdir( $d, 0777, true );

foreach ( [
	'holiday.jpg',            // the attachment
	'holiday-640x480.jpg',    // its intermediates, unknown to WordPress
	'holiday-480x360.jpg',
	'holiday-2.jpg',          // a DIFFERENT attachment
	'holiday-2-640x480.jpg',  // belonging to that one
	'map-960x720.jpg',        // an upload whose own name looks like a size
	'note.txt',
] as $f ) {
	touch( "$d/$f" );
}

attach( 10, "$d/holiday.jpg" );
attach( 11, "$d/holiday-2.jpg" );
attach( 12, "$d/map-960x720.jpg" );

// map-960x720.jpg is itself an attachment, so it must never be claimed.
$taken = [ "$d/holiday.jpg" => true, "$d/holiday-2.jpg" => true, "$d/map-960x720.jpg" => true ];

$found = Mavo_Webp_Files::orphan_sizes( 10, $taken );
is_same( [ 'holiday-480x360.jpg', 'holiday-640x480.jpg' ],
	( static function ( $f ) { $n = array_column( $f, 'file' ); sort( $n ); return $n; } )( $found ),
	'finds the attachment\'s own unrecorded intermediates' );
ok( ! in_array( 'holiday-2-640x480.jpg', array_column( $found, 'file' ), true ),
	'never claims a neighbour\'s intermediate (holiday-2-640x480)' );
ok( ! in_array( 'map-960x720.jpg', array_column( $found, 'file' ), true ),
	'never claims a real upload that merely looks like a size' );
is_same( 640, $found['mavo-640x480']['width'], 'width is read from the filename' );
is_same( 480, $found['mavo-640x480']['height'], 'height is read from the filename' );

$found11 = Mavo_Webp_Files::orphan_sizes( 11, $taken );
is_same( [ 'holiday-2-640x480.jpg' ], array_column( $found11, 'file' ),
	'the neighbour finds its own intermediate, and only that' );

/* already-recorded sizes are left out */
attach( 13, "$d/holiday.jpg", [ 'x' => [ 'file' => 'holiday-640x480.jpg', 'width' => 640, 'height' => 480 ] ] );
is_same( [ 'holiday-480x360.jpg' ], array_column( Mavo_Webp_Files::orphan_sizes( 13, $taken ), 'file' ),
	'a size WordPress already recorded is not offered again' );

/* -rotated and -scaled full sizes have plainly-named intermediates */
foreach ( [ 'IMG_9-rotated.jpeg', 'IMG_9-640x853.jpeg', 'IMG_9-480x640.jpeg' ] as $f ) { touch( "$d/$f" ); }
attach( 14, "$d/IMG_9-rotated.jpeg" );
$rot = array_column( Mavo_Webp_Files::orphan_sizes( 14, $taken ), 'file' );
sort( $rot );
is_same( [ 'IMG_9-480x640.jpeg', 'IMG_9-640x853.jpeg' ], $rot,
	'a -rotated original finds its un-rotated intermediates' );

foreach ( [ 'IMG_8-scaled.jpg', 'IMG_8-640x480.jpg' ] as $f ) { touch( "$d/$f" ); }
attach( 15, "$d/IMG_8-scaled.jpg" );
is_same( [ 'IMG_8-640x480.jpg' ], array_column( Mavo_Webp_Files::orphan_sizes( 15, $taken ), 'file' ),
	'a -scaled original finds its unsuffixed intermediates' );

/* non-image neighbours are ignored */
ok( ! in_array( 'note.txt', array_column( $found, 'file' ), true ), 'unrelated files are ignored' );

echo "\n$T assertions, $F failed\n";
exec( 'rm -rf ' . escapeshellarg( $GLOBALS['DIR'] ) );
exit( $F ? 1 : 0 );
