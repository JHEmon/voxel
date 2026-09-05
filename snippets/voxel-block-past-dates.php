<?php
/**
 * Block past dates when creating an event — child theme functions.php.
 *
 * Voxel does not validate this: Recurring_Date_Field::validate() only checks that end
 * is not before start, the entry count, and the recurrence settings. Nothing rejects a
 * date in the past, and the create-post datepicker is built without Pikaday's minDate
 * option (see the #recurring-date-picker component in assets/dist/create-post.js).
 *
 * Two layers, both needed:
 *   1. Server-side rejection on submit — the actual guarantee.
 *   2. Past days greyed out in the picker — so nobody hits the error in the first place.
 */

/* -------------------------------------------------------------------------
 * Settings
 * ---------------------------------------------------------------------- */

// Post types to guard. Empty array = every Voxel post type.
define( 'NEFESCH_NO_PAST_DATES_POST_TYPES', 'event' );

// Allow saving an existing post whose date is already in the past, as long as the
// date was not changed. Without this, nobody can ever edit a past event again.
define( 'NEFESCH_NO_PAST_DATES_ALLOW_UNCHANGED', true );

/* -------------------------------------------------------------------------
 * 1. Server side — reject the submission
 * ---------------------------------------------------------------------- */

add_action( 'voxel/frontend/after_post_validation', function ( $args ) {
	$post_types = array_filter( array_map( 'trim', explode( ',', NEFESCH_NO_PAST_DATES_POST_TYPES ) ) );

	if ( ! empty( $post_types ) && ! in_array( $args['post_type']->get_key(), $post_types, true ) ) {
		return;
	}

	// Midnight today, site local — a date earlier than this is in the past.
	$today = strtotime( current_time( 'Y-m-d' ) . ' 00:00:00' );

	foreach ( $args['fields'] as $field ) {
		$type = $field->get_type();

		if ( ! in_array( $type, [ 'date', 'recurring-date' ], true ) ) {
			continue;
		}

		$value = $args['values'][ $field->get_key() ] ?? null;

		if ( empty( $value ) ) {
			continue;
		}

		$submitted = nefesch_collect_start_dates( $type, $value );

		if ( empty( $submitted ) ) {
			continue;
		}

		// Editing an existing post: leave an untouched past date alone.
		if ( NEFESCH_NO_PAST_DATES_ALLOW_UNCHANGED && ! empty( $args['is_editing'] ) ) {
			$stored = nefesch_collect_start_dates( $type, $field->get_value() );

			if ( $submitted === $stored ) {
				continue;
			}
		}

		foreach ( $submitted as $start ) {
			if ( strtotime( $start ) < $today ) {
				throw new \Exception( sprintf(
					/* translators: %1$s field label, %2$s the offending date */
					__( '%1$s: the date %2$s is in the past. Please choose today or a later date.', 'nefesch' ),
					$field->get_label(),
					date_i18n( get_option( 'date_format' ), strtotime( $start ) )
				) );
			}
		}
	}
} );

/**
 * Normalise both field types to a plain list of start dates.
 *
 * A `date` field sanitizes to 'Y-m-d' or 'Y-m-d H:i:s'; a `recurring-date` field to a
 * list of [ 'start' => 'Y-m-d H:i:s', 'end' => ... ] rows.
 *
 * @param string $type
 * @param mixed  $value
 * @return string[]
 */
function nefesch_collect_start_dates( $type, $value ) {
	if ( 'date' === $type ) {
		return is_string( $value ) && '' !== $value ? [ $value ] : [];
	}

	$dates = [];

	foreach ( (array) $value as $row ) {
		if ( ! empty( $row['start'] ) ) {
			$dates[] = $row['start'];
		}
	}

	sort( $dates );

	return $dates;
}

/* -------------------------------------------------------------------------
 * 2. Front end — grey out past days in the picker
 * ---------------------------------------------------------------------- */

add_action( 'wp_enqueue_scripts', function () {
	if ( ! wp_script_is( 'vx:create-post.js', 'registered' ) ) {
		return;
	}

	/**
	 * Voxel defers both `pikaday` and `vx:create-post.js` (Assets_Controller::$deferred_scripts),
	 * so this inline script runs BEFORE Pikaday's own file has executed — window.Pikaday does
	 * not exist yet. Install a setter instead, and wrap whatever Pikaday assigns to it.
	 */
	$js = <<<'JS'
(function () {
	var DEBUG = !!window.NEFESCH_PICKER_DEBUG;

	function startOfToday() {
		var d = new Date();
		d.setHours(0, 0, 0, 0);
		return d;
	}

	function inCreatePostForm(options) {
		var el = options.container || options.field;

		if (el && el.closest && el.closest('.ts-create-post')) {
			return true;
		}

		// The popup may be rendered outside the form; fall back to "this page is a
		// create-post page", which is true for the submission form and not for a
		// booking or search widget on a normal page.
		return !!document.querySelector('.ts-create-post');
	}

	function wrap(Original) {
		if (typeof Original !== 'function' || Original.__nefeschWrapped) {
			return Original;
		}

		function Wrapped(options) {
			options = options || {};

			if (!options.minDate && inCreatePostForm(options)) {
				options.minDate = startOfToday();

				var previous = options.disableDayFn;
				options.disableDayFn = function (date) {
					if (date < startOfToday()) {
						return true;
					}
					return previous ? previous.call(this, date) : undefined;
				};

				if (DEBUG) { console.log('[nefesch] picker limited to today onwards'); }
			} else if (DEBUG) {
				console.log('[nefesch] picker left unrestricted', options.container || options.field);
			}

			return new Original(options);
		}

		Wrapped.prototype = Original.prototype;
		Wrapped.__nefeschWrapped = true;

		return Wrapped;
	}

	if (typeof window.Pikaday === 'function') {
		window.Pikaday = wrap(window.Pikaday);
		if (DEBUG) { console.log('[nefesch] Pikaday wrapped immediately'); }
		return;
	}

	// Pikaday is deferred and has not loaded yet — catch the assignment.
	var stored;

	Object.defineProperty(window, 'Pikaday', {
		configurable: true,
		enumerable: true,
		get: function () {
			return stored;
		},
		set: function (value) {
			stored = wrap(value);
			if (DEBUG) { console.log('[nefesch] Pikaday wrapped on assignment'); }
		}
	});
})();
JS;

	wp_add_inline_script( 'vx:create-post.js', $js, 'before' );
}, 100 );
