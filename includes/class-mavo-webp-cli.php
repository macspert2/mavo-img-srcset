<?php
/**
 * wp mavo-webp status | backfill
 *
 * The sidecars for everything uploaded before this plugin existed were produced
 * by a manual shell sweep that is in no repository, and nothing has re-created
 * one since. Regenerating thumbnails, adding an image size or restoring from a
 * backup therefore leaves images stranded on JPEG with no way to notice.
 *
 * Registered only under WP-CLI, so the class never loads on a web request.
 */

defined( 'ABSPATH' ) || exit;

final class Mavo_Webp_CLI {

	/** Attachments per query. Keeps memory flat on a large library. */
	private const BATCH = 200;

	/**
	 * Reports how many JPEGs have a current .webp sidecar.
	 *
	 * Reads only — run it before a backfill to see the size of the job, and after
	 * a thumbnail regeneration to see what went stale.
	 *
	 * ## OPTIONS
	 *
	 * [--limit=<n>]
	 * : Stop after examining this many attachments.
	 *
	 * ## EXAMPLES
	 *
	 *     wp mavo-webp status
	 */
	public function status( $args, $assoc ): void {
		$limit = isset( $assoc['limit'] ) ? max( 1, (int) $assoc['limit'] ) : PHP_INT_MAX;

		$this->require_binary( true );

		$counts = [ 'attachments' => 0, 'files' => 0, 'current' => 0, 'stale' => 0, 'missing' => 0 ];

		$this->each_attachment( $limit, static function ( int $id ) use ( &$counts ): void {
			$counts['attachments']++;

			foreach ( Mavo_Webp_Files::source_files( $id ) as $path ) {
				$counts['files']++;

				if ( ! file_exists( Mavo_Webp_Files::sidecar( $path ) ) ) {
					$counts['missing']++;
				} elseif ( Mavo_Webp_Files::needs_conversion( $path ) ) {
					$counts['stale']++;
				} else {
					$counts['current']++;
				}
			}
		} );

		WP_CLI::log( '' );
		WP_CLI::log( sprintf( 'JPEG attachments examined : %d', $counts['attachments'] ) );
		WP_CLI::log( sprintf( 'Files on disk             : %d', $counts['files'] ) );
		WP_CLI::log( sprintf( '  sidecar current         : %d', $counts['current'] ) );
		WP_CLI::log( sprintf( '  sidecar stale           : %d', $counts['stale'] ) );
		WP_CLI::log( sprintf( '  sidecar missing         : %d', $counts['missing'] ) );

		$todo = $counts['stale'] + $counts['missing'];

		if ( $todo < 1 ) {
			WP_CLI::success( 'Every file has a current sidecar.' );

			return;
		}

		WP_CLI::warning( sprintf( '%d file(s) would be converted by: wp mavo-webp backfill', $todo ) );
	}

	/**
	 * Creates the missing .webp sidecars.
	 *
	 * Safe to interrupt and safe to re-run: each file is skipped when its sidecar
	 * is already newer than the source, so a second run costs stat calls and
	 * nothing else.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : Report what would be converted without writing anything.
	 *
	 * [--force]
	 * : Reconvert even where a current sidecar exists.
	 *
	 * [--attachment=<id>]
	 * : Restrict the run to a single attachment.
	 *
	 * [--limit=<n>]
	 * : Stop after this many attachments.
	 *
	 * ## EXAMPLES
	 *
	 *     wp mavo-webp backfill --dry-run
	 *     wp mavo-webp backfill
	 *     wp mavo-webp backfill --attachment=69731 --force
	 */
	public function backfill( $args, $assoc ): void {
		$dry   = (bool) ( $assoc['dry-run'] ?? false );
		$force = (bool) ( $assoc['force'] ?? false );
		$limit = isset( $assoc['limit'] ) ? max( 1, (int) $assoc['limit'] ) : PHP_INT_MAX;

		$this->require_binary( $dry );

		$totals = [ 'converted' => 0, 'skipped' => 0, 'failed' => 0, 'bytes_in' => 0, 'bytes_out' => 0 ];
		$seen   = 0;

		$work = static function ( int $id ) use ( &$totals, &$seen, $dry, $force ): void {
			$seen++;

			if ( $dry ) {
				foreach ( Mavo_Webp_Files::source_files( $id ) as $path ) {
					$key = Mavo_Webp_Files::needs_conversion( $path, $force ) ? 'converted' : 'skipped';
					$totals[ $key ]++;
				}

				return;
			}

			foreach ( Mavo_Webp_Files::for_attachment( $id, $force ) as $key => $value ) {
				$totals[ $key ] += $value;
			}
		};

		if ( isset( $assoc['attachment'] ) ) {
			$work( (int) $assoc['attachment'] );
		} else {
			$this->each_attachment( $limit, $work );
		}

		WP_CLI::log( '' );
		WP_CLI::log( sprintf( 'Attachments examined : %d', $seen ) );
		WP_CLI::log( sprintf( '%s      : %d', $dry ? 'Would convert ' : 'Converted     ', $totals['converted'] ) );
		WP_CLI::log( sprintf( 'Already current      : %d', $totals['skipped'] ) );

		if ( $totals['failed'] > 0 ) {
			WP_CLI::warning( sprintf(
				'%d file(s) produced no sidecar. cwebp failed, or the WebP was not smaller than the JPEG — '
				. 'in which case skipping it is correct and the renderer will serve the JPEG.',
				$totals['failed']
			) );
		}

		if ( ! $dry && $totals['bytes_in'] > 0 ) {
			WP_CLI::log( sprintf(
				'Bytes                : %s -> %s (%.1f%% saved)',
				size_format( $totals['bytes_in'] ),
				size_format( $totals['bytes_out'] ),
				100 - ( $totals['bytes_out'] / $totals['bytes_in'] * 100 )
			) );
		}

		WP_CLI::success( $dry ? 'Dry run complete; nothing was written.' : 'Backfill complete.' );
	}


	/**
	 * Reports what WordPress recorded as intermediate sizes.
	 *
	 * wp_calculate_image_srcset() builds a srcset purely from
	 * _wp_attachment_metadata['sizes']; an attachment with fewer than two usable
	 * entries there can never get a responsive srcset from core, however many
	 * resized files happen to sit on disk. This counts how much of the library is
	 * in that position, because it decides whether core can be relied on for the
	 * responsive layer at all.
	 *
	 * Reads only.
	 *
	 * ## OPTIONS
	 *
	 * [--limit=<n>]
	 * : Stop after examining this many attachments.
	 *
	 * ## EXAMPLES
	 *
	 *     wp mavo-webp sizes
	 */
	public function sizes( $args, $assoc ): void {
		$limit = isset( $assoc['limit'] ) ? max( 1, (int) $assoc['limit'] ) : PHP_INT_MAX;

		$c      = [ 'total' => 0, 'no_file' => 0, 'no_meta' => 0, 'recorded' => 0, 'on_disk' => 0, 'usable' => 0 ];
		$spread = [];
		$widths = [];

		$this->each_attachment( $limit, static function ( int $id ) use ( &$c, &$spread, &$widths ): void {
			$c['total']++;

			$file = get_attached_file( $id );

			if ( ! $file || ! file_exists( $file ) ) {
				$c['no_file']++;

				return;
			}

			$meta = wp_get_attachment_metadata( $id );

			if ( ! is_array( $meta ) || empty( $meta['sizes'] ) || ! is_array( $meta['sizes'] ) ) {
				$c['no_meta']++;
				$spread[0] = ( $spread[0] ?? 0 ) + 1;

				return;
			}

			$dir     = dirname( $file );
			$present = 0;

			foreach ( $meta['sizes'] as $size ) {
				$c['recorded']++;

				if ( empty( $size['file'] ) || ! file_exists( $dir . '/' . $size['file'] ) ) {
					continue;
				}

				$c['on_disk']++;
				$present++;

				$w = (int) ( $size['width'] ?? 0 );

				if ( $w > 0 ) {
					$widths[ $w ] = ( $widths[ $w ] ?? 0 ) + 1;
				}
			}

			$spread[ $present ] = ( $spread[ $present ] ?? 0 ) + 1;

			// Core needs the full size plus at least one intermediate to be useful.
			if ( $present >= 1 ) {
				$c['usable']++;
			}
		} );

		$pct = static fn( int $n ) => $c['total'] > 0 ? sprintf( '%5.1f%%', $n / $c['total'] * 100 ) : '    —';

		WP_CLI::log( '' );
		WP_CLI::log( sprintf( 'Attachments examined            : %d', $c['total'] ) );
		WP_CLI::log( sprintf( '  full-size file missing        : %6d  %s', $c['no_file'], $pct( $c['no_file'] ) ) );
		WP_CLI::log( sprintf( '  no sizes in metadata          : %6d  %s', $c['no_meta'], $pct( $c['no_meta'] ) ) );
		WP_CLI::log( sprintf( '  core CAN build a srcset       : %6d  %s', $c['usable'], $pct( $c['usable'] ) ) );
		WP_CLI::log( '' );
		WP_CLI::log( sprintf( 'Sizes recorded in metadata      : %d', $c['recorded'] ) );
		WP_CLI::log( sprintf( '  of those, present on disk     : %d', $c['on_disk'] ) );

		ksort( $spread );
		WP_CLI::log( '' );
		WP_CLI::log( 'Intermediates per attachment (present on disk):' );

		foreach ( $spread as $n => $count ) {
			WP_CLI::log( sprintf( '  %2d intermediate(s) : %6d  %s', $n, $count, $pct( $count ) ) );
		}

		arsort( $widths );
		WP_CLI::log( '' );
		WP_CLI::log( 'Most common recorded widths:' );

		foreach ( array_slice( $widths, 0, 12, true ) as $w => $count ) {
			WP_CLI::log( sprintf( '  %5dw : %6d', $w, $count ) );
		}

		WP_CLI::success( 'Done.' );
	}

	/** Stops early when cwebp cannot be run, unless the caller only reads. */
	private function require_binary( bool $tolerate ): void {
		if ( Mavo_Webp_Files::available() ) {
			return;
		}

		$message = sprintf(
			'%s cannot be run (missing from PATH, or exec() disabled). '
			. 'Set the path with the mavo_webp_cwebp_binary filter.',
			Mavo_Webp_Files::binary()
		);

		if ( $tolerate ) {
			WP_CLI::warning( $message . ' Reporting only.' );

			return;
		}

		WP_CLI::error( $message );
	}

	/**
	 * Walks the JPEG attachments in ID order, with a progress bar.
	 *
	 * The object cache is flushed between batches: without it, WordPress retains
	 * every post and meta row it has touched and a library of any size exhausts
	 * memory long before the run finishes.
	 */
	private function each_attachment( int $limit, callable $callback ): void {
		$total  = min( $limit, Mavo_Webp_Files::count_attachments() );
		$bar    = WP_CLI\Utils\make_progress_bar( 'Attachments', $total );
		$offset = 0;
		$done   = 0;

		while ( $done < $total ) {
			$ids = Mavo_Webp_Files::attachment_ids( min( self::BATCH, $total - $done ), $offset );

			if ( ! $ids ) {
				break;
			}

			// One query for the batch instead of one per attachment.
			update_meta_cache( 'post', $ids );

			foreach ( $ids as $id ) {
				$callback( $id );
				$bar->tick();
				$done++;
			}

			$offset += count( $ids );

			if ( ! wp_using_ext_object_cache() ) {
				wp_cache_flush();
			}
		}

		$bar->finish();
	}
}

WP_CLI::add_command( 'mavo-webp', 'Mavo_Webp_CLI' );
