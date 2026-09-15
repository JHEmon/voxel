<?php
/**
 * Find out what is logging you out — temporary diagnostic, remove when done.
 *
 * Logs every auth-cookie event with a backtrace, so you see which plugin file
 * cleared the cookie, or whether it simply expired early.
 *
 * Paste into the child theme's functions.php, reproduce the logout, then read
 * wp-content/nefesch-logout-debug.log.
 */

if ( ! defined( 'NEFESCH_LOGOUT_DEBUG_FILE' ) ) {
	define( 'NEFESCH_LOGOUT_DEBUG_FILE', WP_CONTENT_DIR . '/nefesch-logout-debug.log' );
}

/**
 * @param string $event
 * @param array  $data
 * @param bool   $with_backtrace
 */
function nefesch_logout_log( $event, array $data = [], $with_backtrace = false ) {
	$lines = [
		str_repeat( '-', 70 ),
		sprintf( '[%s] %s', current_time( 'Y-m-d H:i:s' ), $event ),
		sprintf( 'URL:      %s %s', $_SERVER['REQUEST_METHOD'] ?? '?', $_SERVER['REQUEST_URI'] ?? '?' ),
		sprintf( 'Context:  %s', wp_doing_ajax() ? 'ajax' : ( is_admin() ? 'admin' : 'frontend' ) ),
		sprintf( 'User:     %d', get_current_user_id() ),
	];

	foreach ( $data as $key => $value ) {
		$lines[] = sprintf( '%-9s %s', $key . ':', is_scalar( $value ) ? $value : wp_json_encode( $value ) );
	}

	if ( $with_backtrace ) {
		$lines[] = 'Called by:';

		foreach ( debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 25 ) as $frame ) {
			if ( empty( $frame['file'] ) ) {
				continue;
			}

			// Only the interesting frames: plugins and themes, not WP internals.
			if ( false === strpos( $frame['file'], '/wp-content/' ) ) {
				continue;
			}

			$lines[] = sprintf(
				'  %s:%d  %s%s%s()',
				str_replace( WP_CONTENT_DIR, '', $frame['file'] ),
				$frame['line'] ?? 0,
				$frame['class'] ?? '',
				$frame['type'] ?? '',
				$frame['function'] ?? ''
			);
		}
	}

	error_log( implode( PHP_EOL, $lines ) . PHP_EOL, 3, NEFESCH_LOGOUT_DEBUG_FILE );
}

// Something actively cleared the cookie — the backtrace names the culprit.
add_action( 'clear_auth_cookie', function () {
	nefesch_logout_log( 'clear_auth_cookie', [], true );
} );

add_action( 'wp_logout', function ( $user_id = 0 ) {
	nefesch_logout_log( 'wp_logout', [ 'target' => $user_id ], true );
} );

// The cookie was not cleared, it aged out. 'expired' shows how long it was meant to last.
add_action( 'auth_cookie_expired', function ( $elements ) {
	nefesch_logout_log( 'auth_cookie_expired', [
		'account' => $elements['username'] ?? '?',
		'expired' => date_i18n( 'Y-m-d H:i:s', $elements['expiration'] ?? 0 ),
		'scheme'  => $elements['scheme'] ?? '?',
	] );
} );

// A salt/secret-key change invalidates every cookie at once and lands here.
add_action( 'auth_cookie_bad_hash', function ( $elements ) {
	nefesch_logout_log( 'auth_cookie_bad_hash (salts changed?)', [
		'account' => $elements['username'] ?? '?',
	] );
} );

add_action( 'auth_cookie_bad_session_token', function ( $elements ) {
	nefesch_logout_log( 'auth_cookie_bad_session_token (session destroyed server-side)', [
		'account' => $elements['username'] ?? '?',
	] );
} );

add_action( 'auth_cookie_bad_username', function ( $elements ) {
	nefesch_logout_log( 'auth_cookie_bad_username', [
		'account' => $elements['username'] ?? '?',
	] );
} );

// Who is shortening the session? Logged once per login.
add_filter( 'auth_cookie_expiration', function ( $length, $user_id, $remember ) {
	nefesch_logout_log( 'auth_cookie_expiration', [
		'length'   => sprintf( '%d seconds (%.1f hours)', $length, $length / 3600 ),
		'remember' => $remember ? 'yes' : 'no',
	], true );

	return $length;
}, PHP_INT_MAX, 3 );
