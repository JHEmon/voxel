<?php
/**
 * Plugin Name: NEFESCH Expired Events Cleanup
 * Description: Reads the event expiration date and flips expired events to the "Expired" post status (or trash/draft/delete), in safe batches via WP-Cron.
 * Version:     1.1.0
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

	const CRON_HOOK = 'nefesch_purge_expired_events';
	const LOCK_KEY  = 'nefesch_purge_expired_events_lock';
	const LOCK_TTL  = 5 * MINUTE_IN_SECONDS;
	const KEY_CACHE = 'nefesch_expired_events_detected_key';

	/**
	 * Meta keys commonly used by expiration-date metaboxes. Only used when
	 * `meta_key` is left empty; the first key that actually exists on the post
	 * type wins and is cached for a day.
	 *
	 * @var string[]
	 */
	private $candidate_keys = [
		'voxel:expiry_date',
		'voxel:expiration_date',
		'_expiration-date',
		'_expiration_date',
		'expiration_date',
		'_expiry_date',
		'expiry_date',
		'_expires_on',
		'_listing_expiry_date',
		'_job_expires',
		'event_end_date',
		'_EventEndDate',
	];

	/** @var NEFESCH_Expired_Events|null */
	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'init', [ $this, 'maybe_schedule' ], 20 );
		add_action( self::CRON_HOOK, [ $this, 'run' ] );
		add_action( 'template_redirect', [ $this, 'maybe_expire_on_view' ] );
	}

	/**
	 * Runtime configuration. Override any key with the `nefesch_expired_events_config` filter.
	 *
	 * post_type     string|string[] Post type(s) holding events.
	 * meta_key      string          Meta key holding the expiration date. Empty = auto-detect
	 *                               from $candidate_keys.
	 * grace         int             Seconds an event stays live past its expiration date.
	 * action        string          'status' | 'trash' | 'draft' | 'delete'.
	 * target_status string          Post status used when action is 'status'.
	 * batch         int             Posts handled per run.
	 * strategy      string          'auto' | 'sql' | 'scan'. See find_candidates().
	 * dry_run       bool            Report only, change nothing.
	 * skip_meta     string          Meta key that, when truthy, protects a post.
	 * on_view       bool            Also expire a single event when it is viewed.
	 *
	 * @return array
	 */
	public function config() {
		$defaults = [
			'post_type'     => 'event',
			'meta_key'      => '',
			'grace'         => 0,
			'action'        => 'status',
			'target_status' => 'expired',
			'batch'         => 100,
			'strategy'      => 'auto',
			'dry_run'       => false,
			'skip_meta'     => '_nefesch_never_expire',
			'on_view'       => false,
		];

		$config = apply_filters( 'nefesch_expired_events_config', $defaults );

		$config['batch'] = max( 1, (int) $config['batch'] );
		$config['grace'] = max( 0, (int) $config['grace'] );

		if ( ! in_array( $config['action'], [ 'status', 'trash', 'draft', 'delete' ], true ) ) {
			$config['action'] = 'status';
		}

		if ( ! in_array( $config['strategy'], [ 'auto', 'sql', 'scan' ], true ) ) {
			$config['strategy'] = 'auto';
		}

		if ( '' === $config['meta_key'] ) {
			$config['meta_key'] = $this->detect_meta_key( $config['post_type'] );
		}

		return $config;
	}

	/* ---------------------------------------------------------------------
	 * Reading the expiration date
	 * ------------------------------------------------------------------ */

	/**
	 * Find which of the candidate keys this post type actually uses.
	 * Cached for a day; delete the transient after changing plugins.
	 *
	 * @param string|string[] $post_type
	 * @return string Meta key, or '' when nothing matched.
	 */
	public function detect_meta_key( $post_type ) {
		$types  = (array) $post_type;
		$cached = get_transient( self::KEY_CACHE . '_' . md5( implode( ',', $types ) ) );

		if ( is_string( $cached ) ) {
			return $cached;
		}

		global $wpdb;

		$keys         = apply_filters( 'nefesch_expired_events_candidate_keys', $this->candidate_keys );
		$key_holders  = implode( ',', array_fill( 0, count( $keys ), '%s' ) );
		$type_holders = implode( ',', array_fill( 0, count( $types ), '%s' ) );

		$found = (string) $wpdb->get_var( $wpdb->prepare(
			"SELECT pm.meta_key
			 FROM {$wpdb->postmeta} pm
			 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
			 WHERE p.post_type IN ({$type_holders})
			   AND pm.meta_key IN ({$key_holders})
			   AND pm.meta_value <> ''
			 GROUP BY pm.meta_key
			 ORDER BY COUNT(*) DESC
			 LIMIT 1",
			array_merge( $types, $keys )
		) );

		// Nothing from the known list: look for any key that names itself an
		// expiry/expiration date on this post type, newest theme conventions included.
		if ( '' === $found ) {
			$found = (string) $wpdb->get_var( $wpdb->prepare(
				"SELECT pm.meta_key
				 FROM {$wpdb->postmeta} pm
				 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
				 WHERE p.post_type IN ({$type_holders})
				   AND ( pm.meta_key LIKE %s OR pm.meta_key LIKE %s )
				   AND pm.meta_value <> ''
				 GROUP BY pm.meta_key
				 ORDER BY COUNT(*) DESC
				 LIMIT 1",
				array_merge( $types, [ '%expiry%', '%expiration%' ] )
			) );
		}

		set_transient( self::KEY_CACHE . '_' . md5( implode( ',', $types ) ), $found, DAY_IN_SECONDS );

		return $found;
	}

	/**
	 * Turn a stored expiration value into a UTC timestamp.
	 *
	 * Handles unix timestamps, 'Y-m-d H:i:s', 'Y-m-d', 'd.m.Y H:i' (the format the
	 * metabox displays) and arrays that carry a date under a common key. Plain
	 * date strings are read in the site timezone, which is how metaboxes store them.
	 *
	 * @param mixed $raw
	 * @return int|null Timestamp, or null when the value carries no usable date.
	 */
	public function parse_expiry( $raw ) {
		if ( is_array( $raw ) ) {
			foreach ( [ 'timestamp', 'date', 'end', 'expires', 'expiration' ] as $key ) {
				if ( isset( $raw[ $key ] ) && '' !== $raw[ $key ] ) {
					return $this->parse_expiry( $raw[ $key ] );
				}
			}
			return null;
		}

		if ( is_numeric( $raw ) ) {
			$timestamp = (int) $raw;
			return $timestamp > 0 ? $timestamp : null;
		}

		if ( ! is_string( $raw ) ) {
			return null;
		}

		$raw = trim( $raw );

		if ( '' === $raw || '0000-00-00 00:00:00' === $raw ) {
			return null;
		}

		// 27.06.2026 17:00 / 27.06.2026 -> ISO, so DateTime does not read it as m/d.
		if ( preg_match( '/^(\d{1,2})\.(\d{1,2})\.(\d{4})(?:[ T]+(\d{1,2}):(\d{2}))?/', $raw, $m ) ) {
			$raw = sprintf(
				'%04d-%02d-%02d %02d:%02d:00',
				$m[3], $m[2], $m[1],
				isset( $m[4] ) ? $m[4] : 0,
				isset( $m[5] ) ? $m[5] : 0
			);
		}

		try {
			$date = new DateTimeImmutable( $raw, wp_timezone() );
		} catch ( Exception $e ) {
			return null;
		}

		return $date->getTimestamp();
	}

	/**
	 * The expiration timestamp for one post, or null when it never expires.
	 *
	 * Filter `nefesch_expired_events_expiry` to plug in a plugin's own API when the
	 * date is rule-based and not stored as a plain meta value.
	 *
	 * @param int   $post_id
	 * @param array $config
	 * @return int|null
	 */
	public function get_expiry( $post_id, array $config ) {
		$raw       = $config['meta_key'] ? get_post_meta( $post_id, $config['meta_key'], true ) : '';
		$timestamp = $this->parse_expiry( $raw );

		return apply_filters( 'nefesch_expired_events_expiry', $timestamp, $post_id, $raw, $config );
	}

	/* ---------------------------------------------------------------------
	 * Finding expired posts
	 * ------------------------------------------------------------------ */

	private function source_statuses( array $config ) {
		$statuses = [ 'publish', 'pending', 'private', 'future', 'draft' ];

		if ( 'status' === $config['action'] ) {
			$statuses = array_diff( $statuses, [ $config['target_status'], 'draft' ] );
		} elseif ( 'draft' === $config['action'] ) {
			$statuses = array_diff( $statuses, [ 'draft' ] );
		}

		return array_values( $statuses );
	}

	/**
	 * Candidate IDs to inspect.
	 *
	 * 'sql'  narrows in the database (fast, but needs a sortable stored format).
	 * 'scan' walks every post that has the key and decides in PHP (format-agnostic).
	 * 'auto' tries SQL and falls back to scan when SQL matches nothing — that is
	 * the signature of a stored format MySQL cannot compare, e.g. '27.06.2026 17:00'.
	 *
	 * @param array $config
	 * @param int   $cutoff
	 * @return int[]
	 */
	public function find_candidates( array $config, $cutoff ) {
		if ( ! $config['meta_key'] ) {
			return [];
		}

		$base = [
			'post_type'              => $config['post_type'],
			'post_status'            => 'delete' === $config['action']
				? array_merge( $this->source_statuses( $config ), [ 'trash' ] )
				: $this->source_statuses( $config ),
			'posts_per_page'         => $config['batch'],
			'fields'                 => 'ids',
			'orderby'                => 'ID',
			'order'                  => 'ASC',
			'no_found_rows'          => true,
			'ignore_sticky_posts'    => true,
			'update_post_term_cache' => false,
		];

		$protect = [];
		if ( ! empty( $config['skip_meta'] ) ) {
			$protect = [
				[
					'key'     => $config['skip_meta'],
					'compare' => 'NOT EXISTS',
				],
			];
		}

		if ( 'scan' !== $config['strategy'] ) {
			$sql = new WP_Query( $base + [
				'meta_query' => array_merge( [
					'relation' => 'AND',
					[
						'key'     => $config['meta_key'],
						'value'   => wp_date( 'Y-m-d H:i:s', $cutoff ),
						'compare' => '<',
						'type'    => 'DATETIME',
					],
					[
						'key'     => $config['meta_key'],
						'value'   => '',
						'compare' => '!=',
					],
				], $protect ),
			] );

			if ( $sql->posts || 'sql' === $config['strategy'] ) {
				return array_map( 'intval', $sql->posts );
			}
		}

		// Format-agnostic fallback: every post carrying the key, filtered in PHP.
		$scan = new WP_Query( $base + [
			'posts_per_page' => $config['batch'] * 10,
			'meta_query'     => array_merge( [
				'relation' => 'AND',
				[
					'key'     => $config['meta_key'],
					'compare' => 'EXISTS',
				],
				[
					'key'     => $config['meta_key'],
					'value'   => '',
					'compare' => '!=',
				],
			], $protect ),
		] );

		return array_map( 'intval', $scan->posts );
	}

	/* ---------------------------------------------------------------------
	 * Acting on them
	 * ------------------------------------------------------------------ */

	public function maybe_schedule() {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + MINUTE_IN_SECONDS, 'hourly', self::CRON_HOOK );
		}
	}

	public static function unschedule() {
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	/**
	 * Process one batch.
	 *
	 * @return array{processed:int,skipped:int,ids:int[],error:string}
	 */
	public function run() {
		$result = [ 'processed' => 0, 'skipped' => 0, 'ids' => [], 'error' => '' ];

		if ( get_transient( self::LOCK_KEY ) ) {
			$result['error'] = 'A run is already in progress.';
			return $result;
		}
		set_transient( self::LOCK_KEY, 1, self::LOCK_TTL );

		try {
			$config = $this->config();

			if ( ! $config['meta_key'] ) {
				$result['error'] = 'No expiration meta key found or configured. Run: wp nefesch-events probe';
				return $result;
			}

			if ( 'status' === $config['action'] && ! get_post_status_object( $config['target_status'] ) ) {
				$result['error'] = sprintf(
					'Post status "%s" is not registered. Check the plugin that adds it, or set target_status.',
					$config['target_status']
				);
				return $result;
			}

			$cutoff = time() - $config['grace'];
			$hits   = 0;

			foreach ( $this->find_candidates( $config, $cutoff ) as $post_id ) {
				if ( $hits >= $config['batch'] ) {
					break;
				}

				$expiry = $this->get_expiry( $post_id, $config );

				// null = never expires (rule off, empty value, unparseable).
				if ( null === $expiry || $expiry >= $cutoff ) {
					continue;
				}

				if ( false === apply_filters( 'nefesch_expired_events_should_expire', true, $post_id, $config ) ) {
					$result['skipped']++;
					continue;
				}

				$hits++;

				if ( $config['dry_run'] ) {
					$result['processed']++;
					$result['ids'][] = $post_id;
					continue;
				}

				if ( $this->apply_action( $post_id, $config ) ) {
					$result['processed']++;
					$result['ids'][] = $post_id;
				} else {
					$result['skipped']++;
				}
			}

			$this->log( $result, $config );

			if ( $hits >= $config['batch'] && ! $config['dry_run'] ) {
				wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::CRON_HOOK );
			}
		} finally {
			delete_transient( self::LOCK_KEY );
		}

		return $result;
	}

	/**
	 * @param int   $post_id
	 * @param array $config
	 * @return bool
	 */
	private function apply_action( $post_id, array $config ) {
		do_action( 'nefesch_expired_event_before', $post_id, $config );

		switch ( $config['action'] ) {
			case 'delete':
				$done = (bool) wp_delete_post( $post_id, true );
				break;

			case 'trash':
				$done = (bool) wp_trash_post( $post_id );
				break;

			case 'draft':
			case 'status':
				$status = 'draft' === $config['action'] ? 'draft' : $config['target_status'];
				$done   = ! is_wp_error( wp_update_post( [
					'ID'          => $post_id,
					'post_status' => $status,
				], true ) );
				break;

			default:
				$done = false;
		}

		if ( $done ) {
			do_action( 'nefesch_expired_event_after', $post_id, $config );
		}

		return $done;
	}

	/**
	 * Expire a single event the moment it is viewed, so a visitor never sees a
	 * stale one in the window between cron runs. Opt in with `on_view`.
	 */
	public function maybe_expire_on_view() {
		if ( ! is_singular() || is_admin() ) {
			return;
		}

		$config = $this->config();

		if ( empty( $config['on_view'] ) || $config['dry_run'] || ! $config['meta_key'] ) {
			return;
		}

		$post = get_queried_object();

		if ( ! $post instanceof WP_Post || ! in_array( $post->post_type, (array) $config['post_type'], true ) ) {
			return;
		}

		if ( ! in_array( $post->post_status, $this->source_statuses( $config ), true ) ) {
			return;
		}

		if ( ! empty( $config['skip_meta'] ) && get_post_meta( $post->ID, $config['skip_meta'], true ) ) {
			return;
		}

		$expiry = $this->get_expiry( $post->ID, $config );

		if ( null === $expiry || $expiry >= time() - $config['grace'] ) {
			return;
		}

		if ( false === apply_filters( 'nefesch_expired_events_should_expire', true, $post->ID, $config ) ) {
			return;
		}

		$this->apply_action( $post->ID, $config );
	}

	private function log( array $result, array $config ) {
		if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
			return;
		}

		error_log( sprintf(
			'[nefesch-expired-events]%s action=%s key=%s processed=%d skipped=%d ids=%s',
			$config['dry_run'] ? ' DRY-RUN' : '',
			'status' === $config['action'] ? 'status:' . $config['target_status'] : $config['action'],
			$config['meta_key'],
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
 * Run the cleanup now, from anywhere.
 *
 * @param array $overrides Same keys as NEFESCH_Expired_Events::config().
 * @return array{processed:int,skipped:int,ids:int[],error:string}
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
