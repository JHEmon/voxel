<?php
/**
 * Voxel event expiration control — paste into your CHILD THEME's functions.php.
 *
 * Voxel already expires posts: Cron_Controller::check_for_expired_posts() runs on
 * `voxel/schedule:check_for_expired_posts` (twicedaily) and sets post_status = 'expired'
 * for posts in publish/unpublished whose expiry date has passed.
 *
 * This does not reimplement that. It:
 *   1. runs Voxel's check hourly instead of twice a day
 *   2. extends it to statuses Voxel ignores (draft, pending, private) — optional
 *   3. expires a post the moment it is viewed, closing the gap between cron runs
 */

/* -------------------------------------------------------------------------
 * Settings
 * ---------------------------------------------------------------------- */

define( 'NEFESCH_EXPIRY_FREQUENCY', 'hourly' );   // 'hourly' | 'twicedaily' | 'daily'
define( 'NEFESCH_EXPIRY_ON_VIEW', true );         // expire on single-post view
define( 'NEFESCH_EXPIRY_EXTRA_STATUSES', '' );    // e.g. 'draft,pending' — '' = leave alone
define( 'NEFESCH_EXPIRY_BATCH', 200 );            // posts inspected per run for the above

/* -------------------------------------------------------------------------
 * 1. Frequency
 * ---------------------------------------------------------------------- */

add_filter( 'voxel/check_for_expired_posts/frequency', function ( $frequency ) {
	return array_key_exists( NEFESCH_EXPIRY_FREQUENCY, wp_get_schedules() )
		? NEFESCH_EXPIRY_FREQUENCY
		: $frequency;
} );

/**
 * Voxel only schedules the job when nothing is scheduled, so the filter above has no
 * effect on an existing schedule. Clear it once, at init:5, and Voxel recreates it at
 * init:10 with the new frequency.
 */
add_action( 'init', function () {
	if ( ! array_key_exists( NEFESCH_EXPIRY_FREQUENCY, wp_get_schedules() ) ) {
		return;
	}

	$event = wp_get_scheduled_event( 'voxel/schedule:check_for_expired_posts' );

	if ( $event && $event->schedule === NEFESCH_EXPIRY_FREQUENCY ) {
		return;
	}

	wp_clear_scheduled_hook( 'voxel/schedule:check_for_expired_posts' );
}, 5 );

/* -------------------------------------------------------------------------
 * Shared helpers
 * ---------------------------------------------------------------------- */

/**
 * The effective expiry date for a post, resolved exactly as Voxel resolves it:
 * the custom date when set, otherwise the post type's expiration rules.
 *
 * @param int $post_id
 * @return string|null 'Y-m-d H:i:s' site local, or null when it never expires.
 */
function nefesch_get_expiry_date( $post_id ) {
	if ( ! class_exists( '\Voxel\Post' ) ) {
		return null;
	}

	$post = \Voxel\Post::get( $post_id );
	$date = $post ? $post->get_expiry_date() : null;

	// get_expiry_date() maps Voxel's "never" sentinel (9999-01-01) to null already.
	return $date ?: null;
}

/**
 * Set a post to the expired status and keep Voxel's search index in step.
 *
 * @param int $post_id
 * @return bool
 */
function nefesch_expire_post( $post_id ) {
	if ( ! apply_filters( 'nefesch_should_expire_post', true, $post_id ) ) {
		return false;
	}

	$updated = wp_update_post( [
		'ID'          => $post_id,
		'post_status' => 'expired',
	], true );

	if ( is_wp_error( $updated ) ) {
		return false;
	}

	if ( class_exists( '\Voxel\Post' ) ) {
		$post = \Voxel\Post::force_get( $post_id );
		if ( $post ) {
			$post->should_index() ? $post->index() : $post->unindex();
		}
	}

	return true;
}

/* -------------------------------------------------------------------------
 * 2. Statuses Voxel's cron ignores
 * ---------------------------------------------------------------------- */

add_action( 'voxel/schedule:check_for_expired_posts', function () {
	$statuses = array_filter( array_map( 'trim', explode( ',', NEFESCH_EXPIRY_EXTRA_STATUSES ) ) );
	$statuses = array_diff( $statuses, [ 'publish', 'unpublished' ] );

	if ( empty( $statuses ) || ! class_exists( '\Voxel\Post_Type' ) ) {
		return;
	}

	// Rolling cursor: a large pile of never-expiring drafts must not starve the rest.
	$cursor = (int) get_option( 'nefesch_expiry_cursor', 0 );

	$ids = get_posts( [
		'post_type'              => array_keys( \Voxel\Post_Type::get_voxel_types() ),
		'post_status'            => $statuses,
		'posts_per_page'         => NEFESCH_EXPIRY_BATCH,
		'fields'                 => 'ids',
		'orderby'                => 'ID',
		'order'                  => 'ASC',
		'no_found_rows'          => true,
		'update_post_term_cache' => false,
		'nefesch_min_id'         => $cursor,
	] );

	update_option( 'nefesch_expiry_cursor', $ids ? max( $ids ) : 0, false );

	$now = current_time( 'mysql' );

	foreach ( $ids as $post_id ) {
		$date = nefesch_get_expiry_date( $post_id );

		if ( $date && $date < $now ) {
			nefesch_expire_post( $post_id );
		}
	}
}, 20 );

/**
 * Minimum-ID cursor for the query above.
 */
add_filter( 'posts_where', function ( $where, $query ) {
	$min_id = $query->get( 'nefesch_min_id' );

	if ( $min_id ) {
		global $wpdb;
		$where .= $wpdb->prepare( " AND {$wpdb->posts}.ID > %d", (int) $min_id );
	}

	return $where;
}, 10, 2 );

/* -------------------------------------------------------------------------
 * 3. Expire on view
 * ---------------------------------------------------------------------- */

add_action( 'template_redirect', function () {
	if ( ! NEFESCH_EXPIRY_ON_VIEW || is_admin() || ! is_singular() ) {
		return;
	}

	$post = get_queried_object();

	if ( ! $post instanceof WP_Post ) {
		return;
	}

	// Voxel only ever expires these two.
	if ( ! in_array( $post->post_status, [ 'publish', 'unpublished' ], true ) ) {
		return;
	}

	if ( ! class_exists( '\Voxel\Post_Type' ) ) {
		return;
	}

	if ( ! array_key_exists( $post->post_type, \Voxel\Post_Type::get_voxel_types() ) ) {
		return;
	}

	$date = nefesch_get_expiry_date( $post->ID );

	if ( $date && $date < current_time( 'mysql' ) ) {
		nefesch_expire_post( $post->ID );
	}
} );
