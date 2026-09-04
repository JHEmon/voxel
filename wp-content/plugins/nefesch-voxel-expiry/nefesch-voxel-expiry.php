<?php
/**
 * Plugin Name: NEFESCH Voxel Expiry Control
 * Description: Makes Voxel's built-in post expiration run on your schedule, enforces it on view, and extends it to statuses Voxel skips.
 * Version:     1.0.0
 * Author:      NEFESCH
 * License:     GPL-2.0-or-later
 *
 * Voxel already expires posts: `Voxel\Controllers\Cron_Controller::check_for_expired_posts()`
 * runs on `voxel/schedule:check_for_expired_posts` (twicedaily) and sets `post_status = expired`
 * for posts in `publish` / `unpublished` whose expiry date has passed. This plugin does not
 * duplicate that logic — it drives it.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( class_exists( 'NEFESCH_Voxel_Expiry' ) ) {
	return;
}

final class NEFESCH_Voxel_Expiry {

	/** Voxel's cron hook. */
	const VOXEL_HOOK = 'voxel/schedule:check_for_expired_posts';

	/** Voxel's expiry meta key. */
	const META_KEY = 'voxel:expiry_date';

	/** Voxel writes this sentinel for "Never expire". */
	const NEVER = '9999-01-01 00:00:00';

	/** Statuses Voxel's own cron looks at. */
	const VOXEL_STATUSES = [ 'publish', 'unpublished' ];

	const FREQ_OPTION   = 'nefesch_voxel_expiry_frequency';
	const CURSOR_OPTION = 'nefesch_voxel_expiry_cursor';

	/** @var NEFESCH_Voxel_Expiry|null */
	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_filter( 'voxel/check_for_expired_posts/frequency', [ $this, 'filter_frequency' ] );

		// Before Voxel's own Cron_Controller::schedule_cron_jobs() on init:10.
		add_action( 'init', [ $this, 'maybe_reschedule' ], 5 );

		// After Voxel has processed publish/unpublished.
		add_action( self::VOXEL_HOOK, [ $this, 'expire_extra_statuses' ], 20 );

		add_action( 'template_redirect', [ $this, 'maybe_expire_on_view' ] );
	}

	/**
	 * frequency      string   WP-Cron schedule for Voxel's expiry job: 'hourly',
	 *                         'twicedaily', 'daily', or '' to leave Voxel's default alone.
	 * on_view        bool     Expire a post the moment someone opens it.
	 * extra_statuses string[] Statuses beyond publish/unpublished to expire. Voxel
	 *                         ignores drafts, pending and private posts entirely.
	 * post_types     string[] Limit extra_statuses handling. Empty = all Voxel post types.
	 * batch          int      Posts inspected per run for extra_statuses.
	 *
	 * @return array
	 */
	public function config() {
		$config = apply_filters( 'nefesch_voxel_expiry_config', [
			'frequency'      => 'hourly',
			'on_view'        => true,
			'extra_statuses' => [],
			'post_types'     => [],
			'batch'          => 200,
		] );

		$config['batch']          = max( 1, (int) $config['batch'] );
		$config['extra_statuses'] = array_diff( (array) $config['extra_statuses'], self::VOXEL_STATUSES );

		return $config;
	}

	/* ------------------------------------------------------------------ *
	 * Scheduling
	 * ------------------------------------------------------------------ */

	/**
	 * Voxel reads this filter when it schedules the job.
	 *
	 * @param string $frequency
	 * @return string
	 */
	public function filter_frequency( $frequency ) {
		$wanted = $this->config()['frequency'];

		if ( ! $wanted || ! array_key_exists( $wanted, wp_get_schedules() ) ) {
			return $frequency;
		}

		return $wanted;
	}

	/**
	 * Voxel only schedules the job when it is not already scheduled, so changing the
	 * frequency filter alone does nothing to an existing schedule. Clear it once when
	 * the setting changes; Voxel re-schedules it on the same request at init:10.
	 */
	public function maybe_reschedule() {
		$wanted = $this->config()['frequency'];

		if ( ! $wanted || ! array_key_exists( $wanted, wp_get_schedules() ) ) {
			return;
		}

		$current  = wp_get_scheduled_event( self::VOXEL_HOOK );
		$recorded = get_option( self::FREQ_OPTION );

		if ( $current && $current->schedule === $wanted && $recorded === $wanted ) {
			return;
		}

		wp_clear_scheduled_hook( self::VOXEL_HOOK );
		update_option( self::FREQ_OPTION, $wanted, false );
	}

	public static function on_deactivate() {
		delete_option( self::FREQ_OPTION );
		delete_option( self::CURSOR_OPTION );
		wp_clear_scheduled_hook( self::VOXEL_HOOK );
	}

	/* ------------------------------------------------------------------ *
	 * Reading Voxel's expiry date
	 * ------------------------------------------------------------------ */

	/**
	 * Voxel post types, or the configured subset.
	 *
	 * @param array $config
	 * @return string[]
	 */
	public function post_types( array $config ) {
		if ( ! empty( $config['post_types'] ) ) {
			return (array) $config['post_types'];
		}

		if ( ! class_exists( '\Voxel\Post_Type' ) ) {
			return [];
		}

		return array_keys( \Voxel\Post_Type::get_voxel_types() );
	}

	/**
	 * The effective expiry date for a post as Voxel itself resolves it: the custom
	 * date when one is set, otherwise the earliest date the post type's expiration
	 * rules produce. Returns null when the post never expires.
	 *
	 * @param int $post_id
	 * @return string|null 'Y-m-d H:i:s' in site local time.
	 */
	public function get_expiry_date( $post_id ) {
		if ( ! class_exists( '\Voxel\Post' ) ) {
			return null;
		}

		$post = \Voxel\Post::get( $post_id );

		if ( ! $post ) {
			return null;
		}

		$date = $post->get_expiry_date();

		// get_expiry_date() already maps the 9999 sentinel to null; belt and braces.
		return ( $date && self::NEVER !== $date ) ? $date : null;
	}

	/**
	 * @param int         $post_id
	 * @param string|null $now 'Y-m-d H:i:s', site local. Defaults to now.
	 * @return bool
	 */
	public function is_expired( $post_id, $now = null ) {
		$date = $this->get_expiry_date( $post_id );

		if ( null === $date ) {
			return false;
		}

		return $date < ( $now ?: current_time( 'mysql' ) );
	}

	/* ------------------------------------------------------------------ *
	 * Acting
	 * ------------------------------------------------------------------ */

	/**
	 * @param int $post_id
	 * @return bool
	 */
	public function expire( $post_id ) {
		if ( ! apply_filters( 'nefesch_voxel_expiry_should_expire', true, $post_id ) ) {
			return false;
		}

		$updated = wp_update_post( [
			'ID'          => $post_id,
			'post_status' => 'expired',
		], true );

		if ( is_wp_error( $updated ) ) {
			return false;
		}

		// Keep Voxel's search index in step with the new status.
		if ( class_exists( '\Voxel\Post' ) ) {
			$post = \Voxel\Post::force_get( $post_id );
			if ( $post ) {
				$post->should_index() ? $post->index() : $post->unindex();
			}
		}

		do_action( 'nefesch_voxel_expired_post', $post_id );

		return true;
	}

	/**
	 * Voxel's cron only touches publish/unpublished. Anything listed in
	 * `extra_statuses` is handled here, on the same schedule, using Voxel's own
	 * expiry resolution so custom dates and expiration rules both count.
	 *
	 * Walks the post list with a rolling cursor so a large backlog of drafts that
	 * never expire cannot starve the ones that do.
	 *
	 * @return int[] Expired post IDs.
	 */
	public function expire_extra_statuses() {
		$config = $this->config();
		$types  = $this->post_types( $config );

		if ( empty( $config['extra_statuses'] ) || empty( $types ) ) {
			return [];
		}

		$cursor = (int) get_option( self::CURSOR_OPTION, 0 );

		$ids = get_posts( [
			'post_type'              => $types,
			'post_status'            => $config['extra_statuses'],
			'posts_per_page'         => $config['batch'],
			'fields'                 => 'ids',
			'orderby'                => 'ID',
			'order'                  => 'ASC',
			'no_found_rows'          => true,
			'suppress_filters'       => false,
			'update_post_term_cache' => false,
			'ignore_sticky_posts'    => true,
			'nefesch_min_id'         => $cursor,
		] );

		if ( empty( $ids ) && $cursor ) {
			update_option( self::CURSOR_OPTION, 0, false );
			return [];
		}

		update_option( self::CURSOR_OPTION, $ids ? max( $ids ) : 0, false );

		$now     = current_time( 'mysql' );
		$expired = [];

		foreach ( $ids as $post_id ) {
			if ( $this->is_expired( $post_id, $now ) && $this->expire( $post_id ) ) {
				$expired[] = $post_id;
			}
		}

		return $expired;
	}

	/**
	 * Close the window between cron runs: a visitor opening a post whose date has
	 * just passed flips it immediately. The current request still renders; the next
	 * one sees the expired status.
	 */
	public function maybe_expire_on_view() {
		if ( is_admin() || ! is_singular() || empty( $this->config()['on_view'] ) ) {
			return;
		}

		$post = get_queried_object();

		if ( ! $post instanceof WP_Post ) {
			return;
		}

		if ( ! in_array( $post->post_status, self::VOXEL_STATUSES, true ) ) {
			return;
		}

		if ( ! in_array( $post->post_type, $this->post_types( $this->config() ), true ) ) {
			return;
		}

		if ( $this->is_expired( $post->ID ) ) {
			$this->expire( $post->ID );
		}
	}
}

/**
 * Support a minimum-ID cursor in get_posts() without a second query.
 */
add_filter( 'posts_where', function ( $where, $query ) {
	$min_id = $query->get( 'nefesch_min_id' );

	if ( $min_id ) {
		global $wpdb;
		$where .= $wpdb->prepare( " AND {$wpdb->posts}.ID > %d", (int) $min_id );
	}

	return $where;
}, 10, 2 );

NEFESCH_Voxel_Expiry::instance();

if ( function_exists( 'register_deactivation_hook' ) ) {
	register_deactivation_hook( __FILE__, [ 'NEFESCH_Voxel_Expiry', 'on_deactivate' ] );
}

/**
 * Run Voxel's own expiration check right now.
 *
 * @return void
 */
function nefesch_run_voxel_expiry_check() {
	do_action( NEFESCH_Voxel_Expiry::VOXEL_HOOK );
}

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once __DIR__ . '/cli.php';
}
