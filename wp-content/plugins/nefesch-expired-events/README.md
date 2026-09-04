# NEFESCH Expired Events Cleanup

Reads the event expiration date and flips expired events to the **Expired** post status automatically (or trash / draft / permanent delete). Runs hourly via WP-Cron, in batches.

## Install

Drop the folder in `wp-content/plugins/` and activate, or in `wp-content/mu-plugins/` — the cron job self-schedules on `init`, so both work. From a theme, `require_once` the main file in `functions.php`.

## Read this first if you are on the Voxel theme

Voxel already ships this: the *Expiration date* metabox and the `expired` post status are its own post workflow, and its cron flips posts over on schedule. Before adding automation, find out why the built-in one is not running:

```bash
wp nefesch-events doctor --post_type=event
```

It prints the detected expiration meta key, whether the `expired` status is registered, whether `DISABLE_WP_CRON` is set, every expiry-related cron job with its next run, and a count of events that are past their date but still live. An **overdue** cron job is the answer in most cases — WP-Cron only fires on traffic, so a quiet site never runs it. Fix that and you need none of this plugin:

```bash
wp cron event run --due-now
```

Then set a real system cron (`*/15 * * * * cd /path/to/wp && wp cron event run --due-now`) and put `define( 'DISABLE_WP_CRON', true );` in `wp-config.php`.

Use this plugin when the built-in job genuinely does not cover your case: events whose expiry rule does not fire, a backlog that needs clearing once, or a stricter guarantee than "whenever cron next runs" — that is what `on_view` is for.

## Setup in three commands

The plugin needs two facts about your site: the meta key holding the expiration date, and the slug behind "Expired" in the Publish dropdown. Both are discoverable:

```bash
wp nefesch-events statuses                                  # slug behind "Expired" (Voxel: expired)
wp nefesch-events probe --post_type=event --search=expir     # the expiration meta key
wp nefesch-events preview --post_type=event                  # what it reads per post
```

`probe` shows each key with a sample value **and how the plugin parses it** (`reads_as`). Pick the key whose `reads_as` matches the date the metabox displays. `preview` then lists your latest events with stored value, parsed date and an `expired` verdict — the honest dry-run before anything moves.

## Configure

```php
add_filter( 'nefesch_expired_events_config', function ( $config ) {
	$config['post_type']     = 'event';       // your CPT slug
	$config['meta_key']      = '';            // '' = auto-detect, or set it explicitly
	$config['action']        = 'status';      // 'status' | 'trash' | 'draft' | 'delete'
	$config['target_status'] = 'expired';     // the custom status slug
	$config['grace']         = 0;             // seconds of tolerance past the date
	$config['batch']         = 100;
	$config['on_view']       = false;         // also expire on single-post view
	return $config;
} );
```

Then verify and run:

```bash
wp nefesch-events purge --dry-run
wp nefesch-events purge
```

## When the date is rule-based

If the metabox is set to *Follow expiration rules*, the displayed date may be computed at render time rather than stored in postmeta. `probe` tells you which case you are in: if no key reads back the right date, feed the plugin your own resolver instead —

```php
add_filter( 'nefesch_expired_events_expiry', function ( $timestamp, $post_id, $raw, $config ) {
	// Return a unix timestamp, or null for "never expires".
	return your_plugin_get_expiration_timestamp( $post_id );
}, 10, 4 );
```

Everything else — batching, the lock, the status change — keeps working unchanged.

## Date formats it understands

`Y-m-d H:i:s`, `Y-m-d`, `d.m.Y H:i` (the format the metabox displays), unix timestamps, and arrays carrying the date under `timestamp` / `date` / `end` / `expires` / `expiration`. Plain date strings are read **in the site timezone**, which is how metaboxes store them. Anything unparseable or empty means *never expires* — the plugin does not touch it.

## Protecting individual events

Set the meta `_nefesch_never_expire` to any truthy value, or filter:

```php
add_filter( 'nefesch_expired_events_should_expire', function ( $expire, $post_id ) {
	return has_term( 'evergreen', 'event_type', $post_id ) ? false : $expire;
}, 10, 2 );
```

`nefesch_expired_event_before` / `nefesch_expired_event_after` fire per post for logging or notifications.

## How it finds expired posts

`strategy` controls the lookup:

- `sql` — narrows in the database with a `meta_query`. Fast, but only correct when the stored format sorts lexically (`Y-m-d H:i:s`, timestamps).
- `scan` — pulls every post carrying the key and decides in PHP. Format-agnostic, heavier.
- `auto` *(default)* — tries SQL, falls back to scan when SQL matches nothing, which is the signature of a format MySQL cannot compare (e.g. `27.06.2026 17:00`).

Every candidate is re-checked in PHP before anything changes, so a format mismatch can never cause a false expiry.

## Notes

- `action=delete` is permanent. `status` and `trash` are reversible; `status` is the default because it keeps the post, its URL and its history intact.
- A full batch re-queues itself a minute later, so large archives drain across runs instead of timing out.
- WP-Cron only fires on traffic. On a quiet site set `DISABLE_WP_CRON` and run `wp cron event run --due-now` from system cron.
- `on_view` closes the gap between cron runs: a visitor opening a just-expired event triggers the status change immediately. It costs one meta read per single view.
- The detected meta key is cached for a day in a transient. After switching event plugins, run `wp transient delete --all` or set `meta_key` explicitly.
- Recurring events: if the expiration meta holds only the first occurrence, use the `nefesch_expired_events_expiry` filter to return the last one.
