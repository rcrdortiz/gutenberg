<?php
/**
 * Plugin Name: RTC Fast Sync
 * Description: Skips unnecessary REST route registration for sync polling requests.
 *
 * Drop into wp-content/mu-plugins/
 *
 * On every REST API request, WordPress fires all ~140 rest_api_init callbacks
 * to register ~544 routes, even when only /wp-sync/v1/updates is needed.
 * This mu-plugin detects sync requests early and removes all non-essential
 * rest_api_init callbacks before the REST server initializes.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Returns true if this request targets the sync endpoint.
 */
function _rtc_fast_sync_is_sync_request() {
	// Check REST API via pretty permalinks: /wp-json/wp-sync/v1/updates
	if ( isset( $_SERVER['REQUEST_URI'] ) ) {
		$uri = $_SERVER['REQUEST_URI'];
		if ( false !== strpos( $uri, '/wp-sync/v1/updates' ) ) {
			return true;
		}
	}

	// Check REST API via query param: ?rest_route=/wp-sync/v1/updates
	if ( isset( $_GET['rest_route'] ) && false !== strpos( $_GET['rest_route'], '/wp-sync/v1/updates' ) ) {
		return true;
	}

	return false;
}

/**
 * Before rest_api_init fires, remove all callbacks that don't register
 * the sync route or core infrastructure. This runs at wp_loaded (which
 * fires before REST server init) so we can modify the hook list in time.
 */
function _rtc_fast_sync_strip_rest_init() {
	if ( ! _rtc_fast_sync_is_sync_request() ) {
		return;
	}

	if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
		return;
	}

	global $wp_filter;

	if ( ! isset( $wp_filter['rest_api_init'] ) ) {
		return;
	}

	// Allowlist: callbacks we must keep for sync to work.
	$allowed = array(
		// Core REST infrastructure.
		'rest_api_default_filters',
		'create_initial_rest_routes',

		// The sync endpoint registration.
		'gutenberg_register_collaboration_rest_routes',

		// Core WP routes that permission checks may depend on.
		'register_initial_settings',
	);

	foreach ( $wp_filter['rest_api_init']->callbacks as $priority => &$callbacks ) {
		foreach ( $callbacks as $id => $cb ) {
			$func = $cb['function'];
			$name = _rtc_fast_sync_get_callback_name( $func );

			$keep = false;
			foreach ( $allowed as $pattern ) {
				if ( false !== strpos( $name, $pattern ) ) {
					$keep = true;
					break;
				}
			}

			if ( ! $keep ) {
				unset( $callbacks[ $id ] );
			}
		}
	}
}
add_action( 'wp_loaded', '_rtc_fast_sync_strip_rest_init', 0 );

/**
 * Also skip heavy plugins_loaded / init work for sync requests.
 * This removes Jetpack module initialization that isn't needed.
 */
function _rtc_fast_sync_skip_heavy_init() {
	if ( ! _rtc_fast_sync_is_sync_request() ) {
		return;
	}

	if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
		return;
	}

	// Prevent Jetpack Sync from running listeners on this request.
	add_filter( 'jetpack_sync_modules', '__return_empty_array', 999 );

	// Skip Jetpack Sync sending (it queues for later anyway).
	add_filter( 'jetpack_sync_sender_should_load', '__return_false', 999 );
}
add_action( 'plugins_loaded', '_rtc_fast_sync_skip_heavy_init', 0 );

/**
 * Extracts a readable name from a callback for matching.
 *
 * @param mixed $func Callback (string, array, or Closure).
 * @return string Human-readable callback identifier.
 */
function _rtc_fast_sync_get_callback_name( $func ) {
	if ( is_string( $func ) ) {
		return $func;
	}

	if ( is_array( $func ) && isset( $func[0], $func[1] ) ) {
		$class = is_object( $func[0] ) ? get_class( $func[0] ) : (string) $func[0];
		return $class . '::' . $func[1];
	}

	if ( $func instanceof Closure ) {
		try {
			$r = new ReflectionFunction( $func );
			return 'Closure@' . basename( $r->getFileName() ) . ':' . $r->getStartLine();
		} catch ( ReflectionException $e ) {
			return 'Closure@unknown';
		}
	}

	return 'unknown';
}
