<?php
/**
 * WP-CLI commands for Voxel post expiration.
 *
 *   wp nefesch-voxel doctor
 *   wp nefesch-voxel preview --post_type=event
 *   wp nefesch-voxel run
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

class NEFESCH_Voxel_Expiry_CLI {

	/**
	 * Report the state of Voxel's expiration system: cron schedule, how overdue it
	 * is, the configured expiration rules per post type, and how many posts are
	 * past their date but still live.
	 *
	 * @when after_wp_load
	 */
	public function doctor( $args, $assoc_args ) {
		$plugin = NEFESCH_Voxel_Expiry::instance();
		$config = $plugin->config();

		if ( ! class_exists( '\Voxel\Post_Type' ) ) {
			WP_CLI::error( 'Voxel is not active — this plugin drives Voxel\'s expiration system.' );
		}

		$event = wp_get_scheduled_event( NEFESCH_Voxel_Expiry::VOXEL_HOOK );

		WP_CLI::log( '== Cron ==' );

		if ( $event ) {
			WP_CLI::log( sprintf( 'Schedule:        %s', $event->schedule ) );
			WP_CLI::log( sprintf(
				'Next run:        %s%s',
				wp_date( 'Y-m-d H:i', $event->timestamp ),
				$event->timestamp < time()
					? sprintf( '  << OVERDUE by %d h', floor( ( time() - $event->timestamp ) / HOUR_IN_SECONDS ) )
					: ''
			) );
		} else {
			WP_CLI::warning( 'voxel/schedule:check_for_expired_posts is NOT scheduled.' );
		}

		WP_CLI::log( sprintf(
			'DISABLE_WP_CRON: %s',
			defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ? 'true (a system cron must run wp cron event run)' : 'false'
		) );

		WP_CLI::log( '' );
		WP_CLI::log( '== Expiration rules ==' );

		$rows = [];

		foreach ( \Voxel\Post_Type::get_voxel_types() as $post_type ) {
			$rules = $post_type->repository->get_expiration_rules();

			if ( empty( $rules ) ) {
				$rows[] = [ 'post_type' => $post_type->get_key(), 'rule' => '(none configured)' ];
				continue;
			}

			foreach ( $rules as $rule ) {
				$rows[] = [
					'post_type' => $post_type->get_key(),
					'rule'      => 'fixed' === $rule['type']
						? sprintf( '%d days after publishing', $rule['amount'] )
						: sprintf( 'end date of field "%s"', $rule['field'] ),
				];
			}
		}

		WP_CLI\Utils\format_items( 'table', $rows, [ 'post_type', 'rule' ] );

		WP_CLI::log( '' );
		WP_CLI::log( '== Backlog ==' );

		$types = $plugin->post_types( $config );
		$now   = current_time( 'mysql' );

		foreach ( [ 'publish', 'unpublished', 'draft', 'pending', 'private' ] as $status ) {
			$ids = get_posts( [
				'post_type'      => $types,
				'post_status'    => $status,
				'posts_per_page' => 500,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			] );

			$expired = 0;

			foreach ( $ids as $post_id ) {
				if ( $plugin->is_expired( $post_id, $now ) ) {
					$expired++;
				}
			}

			$handled = in_array( $status, NEFESCH_Voxel_Expiry::VOXEL_STATUSES, true )
				? 'Voxel cron'
				: ( in_array( $status, $config['extra_statuses'], true ) ? 'extra_statuses' : 'NOT handled' );

			WP_CLI::log( sprintf(
				'%-12s %3d of %3d checked are past their expiry date  [%s]',
				$status . ':',
				$expired,
				count( $ids ),
				$handled
			) );
		}
	}

	/**
	 * Show the resolved expiry date per post, exactly as Voxel computes it.
	 *
	 * ## OPTIONS
	 *
	 * [--post_type=<type>]
	 * [--status=<status>]
	 * : Default: publish
	 * [--limit=<n>]
	 * : Default: 20
	 *
	 * @when after_wp_load
	 */
	public function preview( $args, $assoc_args ) {
		$plugin = NEFESCH_Voxel_Expiry::instance();
		$config = $plugin->config();

		$ids = get_posts( [
			'post_type'      => $assoc_args['post_type'] ?? $plugin->post_types( $config ),
			'post_status'    => $assoc_args['status'] ?? 'publish',
			'posts_per_page' => (int) ( $assoc_args['limit'] ?? 20 ),
			'fields'         => 'ids',
			'orderby'        => 'ID',
			'order'          => 'DESC',
			'no_found_rows'  => true,
		] );

		if ( ! $ids ) {
			WP_CLI::warning( 'No posts matched.' );
			return;
		}

		$now  = current_time( 'mysql' );
		$rows = [];

		foreach ( $ids as $post_id ) {
			$custom = (string) get_post_meta( $post_id, NEFESCH_Voxel_Expiry::META_KEY, true );
			$date   = $plugin->get_expiry_date( $post_id );

			$rows[] = [
				'ID'      => $post_id,
				'status'  => get_post_status( $post_id ),
				'source'  => '' === $custom ? 'rules' : ( NEFESCH_Voxel_Expiry::NEVER === $custom ? 'never' : 'custom' ),
				'expires' => $date ?: 'never',
				'expired' => ( $date && $date < $now ) ? 'YES' : 'no',
				'title'   => mb_substr( get_the_title( $post_id ), 0, 34 ),
			];
		}

		WP_CLI\Utils\format_items( 'table', $rows, [ 'ID', 'status', 'source', 'expires', 'expired', 'title' ] );
	}

	/**
	 * Fire Voxel's expiration check now, plus the extra-status pass.
	 *
	 * @when after_wp_load
	 */
	public function run( $args, $assoc_args ) {
		if ( ! has_action( NEFESCH_Voxel_Expiry::VOXEL_HOOK ) ) {
			WP_CLI::error( 'Nothing is hooked to voxel/schedule:check_for_expired_posts — is Voxel active?' );
		}

		nefesch_run_voxel_expiry_check();

		WP_CLI::success( 'Expiration check finished. Verify with: wp nefesch-voxel doctor' );
	}

	/**
	 * Force Voxel's expiry cron onto the configured frequency.
	 *
	 * ## OPTIONS
	 *
	 * [--frequency=<schedule>]
	 * : hourly | twicedaily | daily. Defaults to the plugin config.
	 *
	 * @when after_wp_load
	 */
	public function reschedule( $args, $assoc_args ) {
		$frequency = $assoc_args['frequency'] ?? NEFESCH_Voxel_Expiry::instance()->config()['frequency'];

		if ( ! array_key_exists( $frequency, wp_get_schedules() ) ) {
			WP_CLI::error( sprintf( 'Unknown schedule "%s".', $frequency ) );
		}

		wp_clear_scheduled_hook( NEFESCH_Voxel_Expiry::VOXEL_HOOK );
		wp_schedule_event( time(), $frequency, NEFESCH_Voxel_Expiry::VOXEL_HOOK );
		update_option( 'nefesch_voxel_expiry_frequency', $frequency, false );

		WP_CLI::success( sprintf( 'voxel/schedule:check_for_expired_posts now runs %s.', $frequency ) );
	}
}

WP_CLI::add_command( 'nefesch-voxel', 'NEFESCH_Voxel_Expiry_CLI' );
