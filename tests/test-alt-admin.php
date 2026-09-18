<?php
// Exercises the parsing the admin screen depends on: context extraction and
// attachment resolution, against markup shaped like this site's content.
define( 'ABSPATH', true );
function add_action(...$a) {}
function wp_parse_url( $u, $c = -1 ) { return $c === -1 ? parse_url($u) : parse_url($u,$c); }
function get_post_meta( $id, $k, $s = false ) { return $GLOBALS['M'][$id] ?? ''; }
function attachment_url_to_postid( $url ) { return $GLOBALS['BYURL'][$url] ?? 0; }
require '/Users/macspert/Documents/wp/mavo-img-srcset/includes/class-mavo-alt-admin.php';

$T=0;$F=0;
function is_same($e,$a,$l){ global $T,$F; $T++;
  if($e===$a){echo "  ok   $l\n";return;} $F++;
  echo "  FAIL $l\n       expected ".var_export($e,true)."\n       actual   ".var_export($a,true)."\n"; }

$GLOBALS['M'] = [ 12 => 'stored description' ];
$GLOBALS['BYURL'] = [ 'https://x/u/2020/01/beach-rotated.jpeg' => 55 ];

$html = <<<'HTML'
<h2>Où dormir à Lisbonne</h2>
<p>Intro paragraph.</p>
<h3>Le quartier d'Alfama</h3>
<p>The tram climbs the hill every few minutes.</p>
<figure class="wp-picture-figure">
  <img src="https://x/u/2020/01/tram-960x720.jpeg" class="wp-image-12" alt="">
  <figcaption>Tram 28, Alfama</figcaption>
</figure>
<h2>Se déplacer</h2>
<p>Getting around is easy.</p>
<img src="https://x/u/2020/01/beach-480x360.jpeg" alt="the beach"> <em>Praia de Carcavelos</em>
HTML;

// usage_count hits the database; not needed to test parsing.
$imgs = @Mavo_Alt_Admin::images_in( $html );

is_same( 2, count($imgs), 'both images found' );

is_same( "Où dormir à Lisbonne", $imgs[0]['h2'],  'first image takes the h2 above it' );
is_same( "Le quartier d'Alfama", $imgs[0]['h3'],  'and the nearer h3' );
is_same( 'The tram climbs the hill every few minutes.', $imgs[0]['paragraph'], 'and the paragraph that introduces it' );
is_same( 'Tram 28, Alfama', $imgs[0]['caption'], 'figcaption is picked up' );
is_same( 12, $imgs[0]['attachment_id'], 'attachment resolved from wp-image-NNN' );
is_same( 'stored description', $imgs[0]['media_alt'], 'media alt is read' );
is_same( '', $imgs[0]['content_alt'], 'empty content alt reported as empty' );
is_same( 'tram-960x720.jpeg', $imgs[0]['filename'], 'filename from the src' );

is_same( 'Se déplacer', $imgs[1]['h2'], 'second image takes the LATER h2, not the first' );
is_same( "Le quartier d'Alfama", $imgs[1]['h3'], 'h3 is the nearest preceding one' );
is_same( 'Getting around is easy.', $imgs[1]['paragraph'], 'nearest preceding paragraph' );
is_same( 'Praia de Carcavelos', $imgs[1]['caption'], 'trailing <em> counts as a caption' );
is_same( 'the beach', $imgs[1]['content_alt'], 'content alt is reported' );
is_same( 55, $imgs[1]['attachment_id'], 'no class -> resolved by URL, via the -rotated original' );

$c = Mavo_Alt_Admin::url_candidates( 'https://x/u/a/photo-640x480.jpeg' );
is_same( 'https://x/u/a/photo-640x480.jpeg', $c[0], 'URL tried as given' );
is_same( true, in_array('https://x/u/a/photo.jpeg', $c, true), 'size suffix stripped' );
is_same( true, in_array('https://x/u/a/photo-rotated.jpeg', $c, true), '-rotated original tried' );
is_same( true, in_array('https://x/u/a/photo-scaled.jpeg', $c, true), '-scaled original tried' );
is_same( true, in_array('https://x/u/a/photo.jpeg', Mavo_Alt_Admin::url_candidates('https://x/u/a/photo.jpeg.webp'), true),
	'a .webp sidecar URL resolves back to its JPEG' );

is_same( [], Mavo_Alt_Admin::images_in( '<p>No pictures here.</p>' ), 'content without images returns nothing' );

echo "\n$T assertions, $F failed\n";
exit($F ? 1 : 0);
