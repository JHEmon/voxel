<?php
/**
 * WP-CLI commands for the expired-events cleanup.
 *
 *   wp nefesch-events probe --post_type=veranstaltung
 *   wp nefesch-events statuses
 *   wp nefesch-events preview --post_type=veranstaltung
 *   wp nefesch-events purge --dry-run
 *   wp nefesch-events purge --action=status --target_status=expired
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

class NEFESCH_Expired_Events_CLI {

	/**
	 * List the meta keys used by a post type, with a sample value, so you can
	 * identify the real expiration-date key and its stored format.
	 *
	 * ## OPTIONS
	 *
	 * [--post_type=<type>]
	 * : Post type to inspect. Default: event
	 *
	 * [--search=<text>]
	 * : Only show keys containing this text, e.g. --search=expir
	 *
	 * [--limit=<n>]
	 * : Max keys to list. Default: 80
	 *
	 * @when after_wp_load
	 */
	public function probe( $args, $assoc_args ) {
		global $wpdb;

		$post_type = $assoc_args['post_type'] ?? 'event';
		$limit     = (int) ( $assoc_args['limit'] ?? 80 );
		$search    = $assoc_args['search'] ?? '';

		$sql    = "SELECT pm.meta_key, COUNT(*) AS uses, MIN(pm.meta_value) AS sample
			 FROM {$wpdb->postmeta} pm
			 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
			 WHERE p.post_type = %s AND pm.meta_value <> ''";
		$params = [ $post_type ];

		if ( '' !== $search ) {
			$sql     .= ' AND pm.meta_key LIKE %s';
			$params[] = '%' . $wpdb->esc_like( $search ) . '%';
		}

		$sql     .= ' GROUP BY pm.meta_key ORDER BY uses DESC LIMIT %d';
		$params[] = $limit;

		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );

		if ( ! $rows ) {
			WP_CLI::warning( sprintf( 'No meta found for post type "%s".', $post_type ) );
			return;
		}

		$plugin = NEFESCH_Expired_Events::instance();

		foreach ( $rows as &$row ) {
			$row['sample'] = mb_substr( (string) $row['sample'], 0, 40 );
			$parsed        = $plugin->parse_expiry( $row['sample'] );
			$row['reads_as'] = $parsed ? wp_date( 'Y-m-d H:i', $parsed ) : '-';
		}
		unset( $row );

		WP_CLI\Utils\format_items( 'table', $rows, [ 'meta_key', 'uses', 'sample', 'reads_as' ] );
		WP_CLI::log( 'Pick the key whose "reads_as" shows the expected expiration date.' );
	}

	/**
	 * List registered post statuses, so you can confirm the slug behind the
	 * "Expired" entry in the Publish dropdown.
	 *
	 * @when after_wp_load
	 */
	public function statuses( $args, $assoc_args ) {
		$rows = [];

		foreach ( get_post_stati( [], 'objects' ) as $slug => $status ) {
			$rows[] = [
				'slug'   => $slug,
				'label'  => is_string( $status->label ) ? $status->label : '',
				'public' => $status->public ? 'yes' : 'no',
				'core'   => $status->_builtin ? 'yes' : 'no',
			];
		}

		WP_CLI\Utils\format_items( 'table', $rows, [ 'slug', 'label', 'public', 'core' ] );
	}

	/**
	 * Show what the cleanup reads for each event: stored value, parsed date,
	 * and whether it counts as expired. Changes nothing.
	 *
	 * ## OPTIONS
	 *
	 * [--post_type=<type>]
	 * [--meta_key=<key>]
	 * [--limit=<n>]
	 * : Default: 20
	 *
	 * @when after_wp_load
	 */
	public function preview( $args, $assoc_args ) {
		$overrides = [];

		foreach ( [ 'post_type', 'meta_key' ] as $key ) {
			if ( isset( $assoc_args[ $key ] ) ) {
				$overrides[ $key ] = $assoc_args[ $key ];
			}
		}

		$filter = static function ( $config ) use ( $overrides ) {
			return array_merge( $config, $overrides );
		};

		add_filter( 'nefesch_expired_events_config', $filter, 99 );
		$plugin = NEFESCH_Expired_Events::instance();
		$config = $plugin->config();
		remove_filter( 'nefesch_expired_events_config', $filter, 99 );

		if ( ! $config['meta_key'] ) {
			WP_CLI::error( 'No expiration meta key detected. Run: wp nefesch-events probe --search=expir' );
		}

		WP_CLI::log( sprintf( 'Using meta key: %s', $config['meta_key'] ) );

		$ids = get_posts( [
			'post_type'      => $config['post_type'],
			'post_status'    => 'any',
			'posts_per_page' => (int) ( $assoc_args['limit'] ?? 20 ),
			'fields'         => 'ids',
			'orderby'        => 'ID',
			'order'          => 'DESC',
		] );

		$now  = time() - $config['grace'];
		$rows = [];

		foreach ( $ids as $post_id ) {
			$raw    = get_post_meta( $post_id, $config['meta_key'], true );
			$expiry = $plugin->get_expiry( $post_id, $config );

			$rows[] = [
				'ID'      => $post_id,
				'status'  => get_post_status( $post_id ),
				'stored'  => is_scalar( $raw ) ? mb_substr( (string) $raw, 0, 30 ) : gettype( $raw ),
				'parsed'  => $expiry ? wp_date( 'Y-m-d H:i', $expiry ) : 'never',
				'expired' => ( null !== $expiry && $expiry < $now ) ? 'YES' : 'no',
			];
		}

		WP_CLI\Utils\format_items( 'table', $rows, [ 'ID', 'status', 'stored', 'parsed', 'expired' ] );
	}

	/**
	 * Diagnose why events are not expiring on their own before adding anything.
	 *
	 * Reports the detected expiration key, whether WP-Cron is reachable, every
	 * scheduled job that looks expiry-related, and how many events are already
	 * past their date but still not in the expired status.
	 *
	 * ## OPTIONS
	 *
	 * [--post_type=<type>]
	 *
	 * @when after_wp_load
	 */
	public function doctor( $args, $assoc_args ) {
		$overrides = [];

		if ( isset( $assoc_args['post_type'] ) ) {
			$overrides['post_type'] = $assoc_args['post_type'];
		}

		$filter = static function ( $config ) use ( $overrides ) {
			return array_merge( $config, $overrides );
		};

		add_filter( 'nefesch_expired_events_config', $filter, 99 );
		$plugin = NEFESCH_Expired_Events::instance();
		$config = $plugin->config();
		remove_filter( 'nefesch_expired_events_config', $filter, 99 );

		WP_CLI::log( sprintf( 'Post type:        %s', implode( ', ', (array) $config['post_type'] ) ) );
		WP_CLI::log( sprintf( 'Expiration key:   %s', $config['meta_key'] ?: 'NOT FOUND' ) );
		WP_CLI::log( sprintf(
			'Target status:    %s (%s)',
			$config['target_status'],
			get_post_status_object( $config['target_status'] ) ? 'registered' : 'NOT REGISTERED'
		) );
		WP_CLI::log( sprintf(
			'DISABLE_WP_CRON:  %s',
			defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ? 'true (needs a system cron)' : 'false'
		) );

		$rows = [];

		foreach ( (array) _get_cron_array() as $timestamp => $hooks ) {
			foreach ( (array) $hooks as $hook => $events ) {
				if ( ! preg_match( '/expir|voxel|nefesch/i', $hook ) ) {
					continue;
				}
				$rows[] = [
					'hook'     => $hook,
					'next_run' => wp_date( 'Y-m-d H:i', $timestamp ),
					'overdue'  => $timestamp < time() ? sprintf( '%d h', floor( ( time() - $timestamp ) / HOUR_IN_SECONDS ) ) : '-',
				];
			}
		}

		if ( $rows ) {
			WP_CLI::log( '' );
			WP_CLI\Utils\format_items( 'table', $rows, [ 'hook', 'next_run', 'overdue' ] );
			WP_CLI::log( 'An overdue job means WP-Cron is not firing. Fix that before adding automation.' );
		} else {
			WP_CLI::warning( 'No expiry-related cron job scheduled.' );
		}

		if ( ! $config['meta_key'] ) {
			return;
		}

		$result = nefesch_remove_expired_events( array_merge( $overrides, [
			'dry_run' => true,
			'batch'   => 500,
		] ) );

		WP_CLI::log( '' );
		WP_CLI::log( sprintf(
			'Events past their expiration date but still live: %d%s',
			$result['processed'],
			$result['ids'] ? ' (e.g. ' . implode( ', ', array_slice( $result['ids'], 0, 10 ) ) . ')' : ''
		) );
	}

	/**
	 * Run the cleanup now.
	 *
	 * ## OPTIONS
	 *
	 * [--post_type=<type>]
	 * [--meta_key=<key>]
	 * [--target_status=<slug>]
	 * [--action=<status|trash|draft|delete>]
	 * [--strategy=<auto|sql|scan>]
	 * [--grace=<seconds>]
	 * [--batch=<n>]
	 *
	 * [--dry-run]
	 * : Report what would change without changing it.
	 *
	 * @when after_wp_load
	 */
	public function purge( $args, $assoc_args ) {
		$overrides = [];

		foreach ( [ 'post_type', 'meta_key', 'action', 'target_status', 'strategy' ] as $key ) {
			if ( isset( $assoc_args[ $key ] ) ) {
				$overrides[ $key ] = $assoc_args[ $key ];
			}
		}

		foreach ( [ 'grace', 'batch' ] as $key ) {
			if ( isset( $assoc_args[ $key ] ) ) {
				$overrides[ $key ] = (int) $assoc_args[ $key ];
			}
		}

		$dry_run = ! empty( $assoc_args['dry-run'] );

		if ( $dry_run ) {
			$overrides['dry_run'] = true;
		}

		$result = nefesch_remove_expired_events( $overrides );

		if ( $result['error'] ) {
			WP_CLI::error( $result['error'] );
		}

		WP_CLI::success( sprintf(
			'%s%d processed, %d skipped.%s',
			$dry_run ? '[dry run] ' : '',
			$result['processed'],
			$result['skipped'],
			$result['ids'] ? ' IDs: ' . implode( ', ', $result['ids'] ) : ''
		) );
	}
}

WP_CLI::add_command( 'nefesch-events', 'NEFESCH_Expired_Events_CLI' );
