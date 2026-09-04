# NEFESCH Expired Events Cleanup

Moves event posts whose end date has passed out of the public site — trash, draft, or permanent delete — in safe batches via WP-Cron.

## Install

Drop the folder in `wp-content/plugins/` and activate it, or in `wp-content/mu-plugins/` (the cron job self-schedules on `init`, so it works either way). From a theme you can also just `require_once` the main file in `functions.php`.

## Step 1 — find your real end-date meta key

Do not guess this. Different event systems store it differently:

| System | Likely key | Storage |
|---|---|---|
| The Events Calendar | `_EventEndDate` | `Y-m-d H:i:s`, site local |
| EventOn | `evcal_erow` | unix timestamp |
| ACF date/time field | your field name | `Y-m-d H:i:s` if the field returns that format |
| Voxel / custom post type | your field key | varies — check it |

With WP-CLI:

```bash
wp nefesch-events probe --post_type=event
```

That lists every meta key on the post type with a sample value, so you can see both the key and its format.

## Step 2 — configure

```php
add_filter( 'nefesch_expired_events_config', function ( $config ) {
	$config['post_type'] = 'event';
	$config['meta_key']  = '_EventEndDate';
	$config['meta_type'] = 'DATETIME'; // or 'NUMERIC' for unix timestamps
	$config['grace']     = DAY_IN_SECONDS; // stay live 24h past the end date
	$config['action']    = 'trash';        // 'trash' | 'draft' | 'delete'
	$config['batch']     = 100;
	return $config;
} );
```

Protect individual events by setting the meta `_nefesch_never_expire` to any truthy value, or with a filter:

```php
add_filter( 'nefesch_expired_events_should_expire', function ( $expire, $post_id ) {
	return has_term( 'evergreen', 'event_type', $post_id ) ? false : $expire;
}, 10, 2 );
```

`nefesch_expired_event_before` / `nefesch_expired_event_after` fire per post for logging or notifications.

## Step 3 — verify before you let it run

```bash
wp nefesch-events purge --dry-run            # lists IDs, changes nothing
wp nefesch-events purge --action=trash       # do it
```

Always dry-run first on production data, and take a DB snapshot before the first real run.

## Notes

- `action=delete` is permanent (`wp_delete_post( $id, true )`). `trash` is reversible and respects `EMPTY_TRASH_DAYS`; it is the default for a reason.
- Batches: a full batch re-queues itself a minute later, so large archives drain over several cron runs instead of one timeout-prone request.
- WP-Cron only fires on traffic. On a low-traffic site, disable it (`DISABLE_WP_CRON`) and run `wp cron event run --due-now` from a real system cron.
- Recurring events: if your end-date meta only holds the *first* occurrence, this will expire series that are still active. Point `meta_key` at the last-occurrence field, or exclude recurring posts with the `should_expire` filter.
- All-day events stored as `Y-m-d` compare fine as `DATETIME`; the day counts as ending at 00:00, so give them `grace = DAY_IN_SECONDS`.
