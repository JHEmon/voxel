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
