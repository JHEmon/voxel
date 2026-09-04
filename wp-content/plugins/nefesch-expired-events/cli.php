<?php
/**
 * WP-CLI commands for the expired-events cleanup.
 *
 *   wp nefesch-events probe --post_type=event
 *   wp nefesch-events purge --dry-run
 *   wp nefesch-events purge --action=trash --batch=500
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
	 * identify the real end-date key before configuring the cleanup.
	 *
	 * ## OPTIONS
	 *
	 * [--post_type=<type>]
	 * : Post type to inspect. Default: event
	 *
	 * [--limit=<n>]
	 * : Max meta keys to list. Default: 60
	 *
	 * @when after_wp_load
	 */
	public function probe( $args, $assoc_args ) {
		global $wpdb;

		$post_type = $assoc_args['post_type'] ?? 'event';
		$limit     = (int) ( $assoc_args['limit'] ?? 60 );

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT pm.meta_key, COUNT(*) AS uses, MIN(pm.meta_value) AS sample
			 FROM {$wpdb->postmeta} pm
			 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
			 WHERE p.post_type = %s AND pm.meta_value <> ''
			 GROUP BY pm.meta_key
			 ORDER BY uses DESC
			 LIMIT %d",
			$post_type,
			$limit
		), ARRAY_A );

		if ( ! $rows ) {
			WP_CLI::warning( sprintf( 'No meta found for post type "%s".', $post_type ) );
			return;
		}

		foreach ( $rows as &$row ) {
			$row['sample'] = mb_substr( (string) $row['sample'], 0, 60 );
		}
		unset( $row );

		WP_CLI\Utils\format_items( 'table', $rows, [ 'meta_key', 'uses', 'sample' ] );
	}

	/**
	 * Run the expired-event cleanup now.
	 *
	 * ## OPTIONS
	 *
	 * [--post_type=<type>]
	 * [--meta_key=<key>]
	 * [--meta_type=<DATETIME|NUMERIC>]
	 * [--grace=<seconds>]
	 * [--action=<trash|draft|delete>]
	 * [--batch=<n>]
	 *
	 * [--dry-run]
	 * : Report what would be affected without changing anything.
	 *
	 * @when after_wp_load
	 */
	public function purge( $args, $assoc_args ) {
		$overrides = [];

		foreach ( [ 'post_type', 'meta_key', 'meta_type', 'action' ] as $key ) {
			if ( isset( $assoc_args[ $key ] ) ) {
				$overrides[ $key ] = $assoc_args[ $key ];
			}
		}

		foreach ( [ 'grace', 'batch' ] as $key ) {
			if ( isset( $assoc_args[ $key ] ) ) {
				$overrides[ $key ] = (int) $assoc_args[ $key ];
			}
		}

		if ( ! empty( $assoc_args['dry-run'] ) ) {
			$overrides['dry_run'] = true;
		}

		$result = nefesch_remove_expired_events( $overrides );

		WP_CLI::success( sprintf(
			'%s%d processed, %d skipped.%s',
			! empty( $overrides['dry_run'] ) ? '[dry run] ' : '',
			$result['processed'],
			$result['skipped'],
			$result['ids'] ? ' IDs: ' . implode( ', ', $result['ids'] ) : ''
		) );
	}
}

WP_CLI::add_command( 'nefesch-events', 'NEFESCH_Expired_Events_CLI' );
