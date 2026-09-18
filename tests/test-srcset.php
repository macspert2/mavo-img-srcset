<?php
/**
 * Runs the real plugin against a real temporary uploads directory, so the
 * file_exists() checks are exercised for real rather than stubbed.
 */
define( 'ABSPATH', true );

$UP = sys_get_temp_dir() . '/mavo-srcset-test-' . getmypid();
@mkdir( $UP . '/2026/09', 0777, true );

function add_filter(...$a) {}
function add_action(...$a) {}
function plugin_dir_url($f) { return '/p/'; }
function plugin_dir_path($f) { return __DIR__ . '/'; }
function is_admin() { return false; }
function get_the_ID() { return 0; }
function set_url_scheme($u,$s){ return preg_replace('#^https?:#', $s.':', $u); }
function wp_upload_dir() {
    return [ 'baseurl' => 'https://example.com/wp-content/uploads',
             'basedir' => $GLOBALS['UP'], 'error' => false ];
}
$GLOBALS['UP'] = $UP;

require_once __DIR__ . '/../mavo-img-srcset.php';

$T = 0; $F = 0;
function is_same($e,$a,$l){ global $T,$F; $T++;
  if($e===$a){ echo "  ok   $l\n"; return; }
  $F++; echo "  FAIL $l\n       expected: $e\n       actual:   $a\n"; }

function touchf( string $rel ): void { touch( $GLOBALS['UP'] . '/' . $rel ); }
function srcset_of( string $html ): string {
    $p = new Mavo_Img_Srcset();
    $out = $p->transform( $html );
    return preg_match('/srcset="([^"]*)"/', $out, $m) ? $m[1] : '(none)';
}
function src_of( string $html ): string {
    $p = new Mavo_Img_Srcset();
    $out = $p->transform( $html );
    return preg_match('/src="([^"]*)"/', $out, $m) ? $m[1] : '(none)';
}

$U = 'https://example.com/wp-content/uploads/2026/09';

/* ---- 1. all three present: output must be exactly as before ------------ */
foreach ( ['IMG_1.jpg', 'IMG_1-640x480.jpg', 'IMG_1-480x360.jpg'] as $f ) {
    touchf( "2026/09/$f.webp" );
}
$html = '<img src="'.$U.'/IMG_1.jpg" width="960" height="720" alt="a">';
is_same(
  "$U/IMG_1.jpg.webp 960w, $U/IMG_1-640x480.jpg.webp 640w, $U/IMG_1-480x360.jpg.webp 480w",
  srcset_of( $html ),
  'all files present -> srcset unchanged from the old three-entry form' );

/* ---- 2. EXIF-rotated: intermediates live under the un-rotated base ----- */
touchf( '2026/09/IMG_2-rotated.jpeg.webp' );
touchf( '2026/09/IMG_2-640x853.jpeg.webp' );   // note: no "-rotated"
touchf( '2026/09/IMG_2-480x640.jpeg.webp' );
$html = '<img src="'.$U.'/IMG_2-rotated.jpeg" width="960" height="1280" alt="b">';
is_same(
  "$U/IMG_2-rotated.jpeg.webp 960w, $U/IMG_2-640x853.jpeg.webp 640w, $U/IMG_2-480x640.jpeg.webp 480w",
  srcset_of( $html ),
  'rotated original -> intermediates resolved under the un-rotated base' );

/* ---- 3. a genuinely "-rotated"-named set still wins ------------------- */
touchf( '2026/09/IMG_3-rotated.jpeg.webp' );
touchf( '2026/09/IMG_3-rotated-640x480.jpeg.webp' );
$html = '<img src="'.$U.'/IMG_3-rotated.jpeg" width="960" height="720" alt="c">';
is_same(
  "$U/IMG_3-rotated.jpeg.webp 960w, $U/IMG_3-rotated-640x480.jpeg.webp 640w",
  srcset_of( $html ),
  'a real -rotated-named intermediate is still used when it exists' );

/* ---- 4. off-by-one height: bad entry dropped, not emitted as a 404 ----- */
touchf( '2026/09/IMG_4.jpeg.webp' );
touchf( '2026/09/IMG_4-640x453.jpeg.webp' );   // real file; plugin guesses 452
$html = '<img src="'.$U.'/IMG_4.jpeg" width="960" height="678" alt="d">';
is_same( "$U/IMG_4.jpeg.webp 960w", srcset_of( $html ),
  'unresolvable intermediates are dropped, leaving the working 960w candidate' );

/* ---- 5. no full-size webp: the <img> must be left completely alone ----- */
$html = '<img src="'.$U.'/IMG_5.jpeg" width="960" height="720" alt="e" class="alignleft">';
is_same( "$U/IMG_5.jpeg", src_of( $html ), 'missing full-size webp -> original src untouched' );
is_same( '(none)', srcset_of( $html ), 'missing full-size webp -> no srcset invented' );

/* ---- 6. a URL outside uploads keeps the old behaviour ---------------- */
$html = '<img src="https://cdn.example.net/x/IMG_6.jpg" width="960" height="720" alt="f">';
is_same(
  'https://cdn.example.net/x/IMG_6.jpg.webp 960w, https://cdn.example.net/x/IMG_6-640x480.jpg.webp 640w, https://cdn.example.net/x/IMG_6-480x360.jpg.webp 480w',
  srcset_of( $html ),
  'unresolvable host -> all three entries kept, behaviour unchanged' );

/* ---- 7. non-jpeg and small images still skipped ---------------------- */
is_same( "$U/x.png", src_of( '<img src="'.$U.'/x.png" width="960" height="720">' ), 'png untouched' );
is_same( "$U/s.jpg", src_of( '<img src="'.$U.'/s.jpg" width="600" height="400">' ), 'under 960 untouched' );

echo "\n$T assertions, $F failed\n";
exec( 'rm -rf ' . escapeshellarg( $UP ) );
exit( $F ? 1 : 0 );
