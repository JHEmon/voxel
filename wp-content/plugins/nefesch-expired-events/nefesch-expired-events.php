<?php
/**
 * Plugin Name: NEFESCH Expired Events Cleanup
 * Description: Moves (or deletes) event posts whose end date has passed, in safe batches, via WP-Cron.
 * Version:     1.0.0
 * Author:      NEFESCH
 * License:     GPL-2.0-or-later
 * Text Domain: nefesch-expired-events
 *
 * Works as a normal plugin, an mu-plugin, or as an include from functions.php.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( class_exists( 'NEFESCH_Expired_Events' ) ) {
	return;
}

final class NEFESCH_Expired_Events {

	const CRON_HOOK  = 'nefesch_purge_expired_events';
	const LOCK_KEY   = 'nefesch_purge_expired_events_lock';
	const LOCK_TTL   = 5 * MINUTE_IN_SECONDS;

	/** @var NEFESCH_Expired_Events|null */
	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'init', [ $this, 'maybe_schedule' ] );
		add_action( self::CRON_HOOK, [ $this, 'run' ] );
	}

	/**
	 * Runtime configuration. Override any key with the `nefesch_expired_events_config` filter.
	 *
	 * post_type   string|string[] Post type(s) holding events.
	 * meta_key    string          Meta key holding the event END date. If your events only
	 *                             have a start date, point this at the start date instead.
	 * meta_type   string          'DATETIME' for 'Y-m-d H:i:s' / 'Y-m-d' strings,
	 *                             'NUMERIC'  for unix timestamps.
	 * grace       int             Seconds an event stays live after its end date.
	 * action      string          'trash' | 'draft' | 'delete' (delete = permanent).
	 * batch       int             Posts handled per cron run.
	 * dry_run     bool            Log what would happen, change nothing.
	 * skip_meta   string          Optional meta key; a truthy value on a post protects it.
	 *
	 * @return array
	 */
	public function config() {
		$defaults = [
			'post_type' => 'event',
			'meta_key'  => 'event_end_date',
			'meta_type' => 'DATETIME',
			'grace'     => DAY_IN_SECONDS,
			'action'    => 'trash',
			'batch'     => 100,
			'dry_run'   => false,
			'skip_meta' => '_nefesch_never_expire',
		];

		$config = apply_filters( 'nefesch_expired_events_config', $defaults );

		$config['batch']     = max( 1, (int) $config['batch'] );
		$config['grace']     = max( 0, (int) $config['grace'] );
		$config['meta_type'] = 'NUMERIC' === strtoupper( $config['meta_type'] ) ? 'NUMERIC' : 'DATETIME';

		if ( ! in_array( $config['action'], [ 'trash', 'draft', 'delete' ], true ) ) {
			$config['action'] = 'trash';
		}

		return $config;
	}

	/**
	 * Schedule the daily job. Runs on `init` so it also works as an mu-plugin,
	 * where activation hooks never fire.
	 */
	public function maybe_schedule() {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + MINUTE_IN_SECONDS, 'daily', self::CRON_HOOK );
		}
	}

	public static function unschedule() {
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	/**
	 * The cutoff value the meta is compared against, in the same shape as the stored meta.
	 *
	 * @param array $config
	 * @return string|int
	 */
	private function cutoff( array $config ) {
		$timestamp = time() - $config['grace'];

		if ( 'NUMERIC' === $config['meta_type'] ) {
			return $timestamp;
		}

		// Meta stored as a local-time string -> compare against local time.
		return wp_date( 'Y-m-d H:i:s', $timestamp );
	}

	/**
	 * Find expired event IDs.
	 *
	 * @param array $config
	 * @return int[]
	 */
	public function find_expired( array $config ) {
		$meta_query = [
			'relation' => 'AND',
			[
				'key'     => $config['meta_key'],
				'value'   => $this->cutoff( $config ),
				'compare' => '<',
				'type'    => $config['meta_type'],
			],
			// Empty meta casts to 0000-00-00 / 0 and would otherwise always look expired.
			[
				'key'     => $config['meta_key'],
				'value'   => '',
				'compare' => '!=',
			],
		];

		if ( ! empty( $config['skip_meta'] ) ) {
			$meta_query[] = [
				'key'     => $config['skip_meta'],
				'compare' => 'NOT EXISTS',
			];
		}

		$query = new WP_Query( [
			'post_type'              => $config['post_type'],
			'post_status'            => 'delete' === $config['action']
				? [ 'publish', 'draft', 'pending', 'private', 'future', 'trash' ]
				: [ 'publish', 'pending', 'private', 'future' ],
			'posts_per_page'         => $config['batch'],
			'fields'                 => 'ids',
			'orderby'                => 'ID',
			'order'                  => 'ASC',
			'no_found_rows'          => true,
			'ignore_sticky_posts'    => true,
			'suppress_filters'       => false,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
			'meta_query'             => $meta_query,
		] );

		return array_map( 'intval', $query->posts );
	}

	/**
	 * Process one batch. Re-queues itself while a full batch keeps coming back,
	 * so a 20k-event site drains without one long-running request.
	 *
	 * @return array{processed:int,skipped:int,ids:int[]}
	 */
	public function run() {
		$result = [ 'processed' => 0, 'skipped' => 0, 'ids' => [] ];

		// Cheap cross-request lock: overlapping cron runs would fight over the same batch.
		if ( get_transient( self::LOCK_KEY ) ) {
			return $result;
		}
		set_transient( self::LOCK_KEY, 1, self::LOCK_TTL );

		try {
			$config = $this->config();
			$ids    = $this->find_expired( $config );

			foreach ( $ids as $post_id ) {
				if ( false === apply_filters( 'nefesch_expired_events_should_expire', true, $post_id, $config ) ) {
					$result['skipped']++;
					continue;
				}

				if ( $config['dry_run'] ) {
					$result['processed']++;
					$result['ids'][] = $post_id;
					continue;
				}

				do_action( 'nefesch_expired_event_before', $post_id, $config );

				switch ( $config['action'] ) {
					case 'delete':
						$done = (bool) wp_delete_post( $post_id, true );
						break;
					case 'draft':
						$done = ! is_wp_error( wp_update_post( [
							'ID'          => $post_id,
							'post_status' => 'draft',
						], true ) );
						break;
					default:
						$done = (bool) wp_trash_post( $post_id );
				}

				if ( $done ) {
					$result['processed']++;
					$result['ids'][] = $post_id;
					do_action( 'nefesch_expired_event_after', $post_id, $config );
				} else {
					$result['skipped']++;
				}
			}

			$this->log( $result, $config );

			// Full batch -> there is probably more. Come back in a minute.
			if ( count( $ids ) >= $config['batch'] && ! $config['dry_run'] ) {
				wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::CRON_HOOK );
			}
		} finally {
			delete_transient( self::LOCK_KEY );
		}

		return $result;
	}

	private function log( array $result, array $config ) {
		if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
			return;
		}

		error_log( sprintf(
			'[nefesch-expired-events]%s action=%s processed=%d skipped=%d ids=%s',
			$config['dry_run'] ? ' DRY-RUN' : '',
			$config['action'],
			$result['processed'],
			$result['skipped'],
			implode( ',', $result['ids'] )
		) );
	}
}

NEFESCH_Expired_Events::instance();

if ( function_exists( 'register_deactivation_hook' ) ) {
	register_deactivation_hook( __FILE__, [ 'NEFESCH_Expired_Events', 'unschedule' ] );
}

/**
 * Convenience wrapper: run the cleanup now, from anywhere.
 *
 * @param array $overrides Same keys as NEFESCH_Expired_Events::config().
 * @return array{processed:int,skipped:int,ids:int[]}
 */
function nefesch_remove_expired_events( array $overrides = [] ) {
	if ( empty( $overrides ) ) {
		return NEFESCH_Expired_Events::instance()->run();
	}

	$filter = static function ( $config ) use ( $overrides ) {
		return array_merge( $config, $overrides );
	};

	add_filter( 'nefesch_expired_events_config', $filter, 99 );
	$result = NEFESCH_Expired_Events::instance()->run();
	remove_filter( 'nefesch_expired_events_config', $filter, 99 );

	return $result;
}

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once __DIR__ . '/cli.php';
}
