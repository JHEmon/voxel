# NEFESCH Voxel Expiry Control

Voxel already expires posts. This plugin does not reimplement that — it drives it, closes the gaps, and makes the behaviour inspectable.

## What Voxel does out of the box

Read `app/controllers/cron-controller.php::check_for_expired_posts()`:

| Fact | Value |
|---|---|
| Cron hook | `voxel/schedule:check_for_expired_posts` |
| Frequency | `twicedaily`, filterable via `voxel/check_for_expired_posts/frequency` |
| Statuses it acts on | `publish` and `unpublished` only |
| Result | `wp_update_post( [ 'post_status' => 'expired' ] )` |
| Custom date meta | `voxel:expiry_date`, `Y-m-d H:i:s`, site local time |
| "Never expire" | the same meta set to `9999-01-01 00:00:00` |
| "Follow expiration rules" | the meta is **deleted**; the date is computed per post type |
| Rules | `N days after publishing`, or the end date of a `date` / `recurring-date` field |
| Effective date for one post | `\Voxel\Post::get( $id )->get_expiry_date()` — null means never |

So if your events are not expiring, the code is not missing. Something above is not firing.

## The three gaps this plugin fills

**1. Frequency.** Twice a day means an event can sit expired for up to twelve hours. The filter alone does not help: Voxel only schedules the job `if ( ! wp_next_scheduled( ... ) )`, so an already-scheduled job keeps its old frequency forever. This plugin clears the schedule once when the setting changes, at `init` priority 5, so Voxel re-creates it at priority 10 with the new frequency.

**2. Statuses Voxel skips.** Only `publish` and `unpublished` are ever expired. A draft, pending or private event stays at its old status forever, however long past its date. `extra_statuses` extends the same check to them, using Voxel's own date resolution.

**3. The window between runs.** `on_view` expires a post the moment a visitor opens it, so a stale event cannot be seen just because cron is an hour away.

## Configure

```php
add_filter( 'nefesch_voxel_expiry_config', function ( $config ) {
	$config['frequency']      = 'hourly';   // '' leaves Voxel's twicedaily alone
	$config['on_view']        = true;
	$config['extra_statuses'] = [];         // e.g. [ 'draft', 'pending' ]
	$config['post_types']     = [];         // empty = all Voxel post types
	$config['batch']          = 200;
	return $config;
} );
```

Skip individual posts:

```php
add_filter( 'nefesch_voxel_expiry_should_expire', function ( $expire, $post_id ) {
	return has_term( 'evergreen', 'event_type', $post_id ) ? false : $expire;
}, 10, 2 );
```

`nefesch_voxel_expired_post` fires after each status change.

## Commands

```bash
wp nefesch-voxel doctor                  # cron state, rules per post type, backlog per status
wp nefesch-voxel preview --post_type=event   # resolved expiry date per post, and its source
wp nefesch-voxel run                     # fire the expiration check now
wp nefesch-voxel reschedule --frequency=hourly
```

`doctor` is the first thing to run. An **OVERDUE** next-run means WP-Cron is not firing at all — the usual cause on a low-traffic site — and no amount of extra code fixes that:

```php
// wp-config.php
define( 'DISABLE_WP_CRON', true );
```

```cron
*/15 * * * * cd /path/to/wp && wp cron event run --due-now >/dev/null 2>&1
```

## Two things worth knowing about Voxel's own logic

**Recurring-date events expire on the LAST occurrence, not the first.** The cron query uses `MAX( IFNULL( r.until, r.end ) )` from `voxel_recurring_dates`, while the metabox display (`resolve_expiration_rules()` in `app/utils/post-utils.php`) picks the **earliest** occurrence. For a recurring event the "Expires on" date in the sidebar can therefore be months earlier than the date the post actually expires. The sidebar is the optimistic one; the cron is the truth.

**A custom date suppresses the rules entirely.** Every rule query joins `voxel:expiry_date` and requires it to be `NULL`. Setting "Custom expiration date" — or "Never expire", which writes the `9999` sentinel — takes that post out of rule-based expiry for good, until someone switches it back to "Follow expiration rules".

## Index consistency

`expire()` re-runs `should_index()` / `index()` / `unindex()` after the status change, so Voxel's search index does not keep serving a post that just expired.
