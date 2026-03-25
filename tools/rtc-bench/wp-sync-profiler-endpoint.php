<?php
/**
 * RTC Sync Profiler Endpoint
 *
 * Drop-in REST API endpoint that wraps the sync endpoint and
 * measures time at each WordPress lifecycle stage.
 *
 * Register via mu-plugin or directly in functions.php.
 *
 * Test with: POST /wp-json/wp-sync-profiler/v1/profile
 * Same payload as /wp-sync/v1/updates
 */

add_action(
	'rest_api_init',
	function () {
		register_rest_route(
			'wp-sync-profiler/v1',
			'/profile',
			array(
				'methods'             => 'POST',
				'callback'            => 'rtc_profiler_handle_request',
				'permission_callback' => function () {
					return current_user_can( 'edit_posts' );
				},
			)
		);
	}
);

// Record timestamps at WordPress lifecycle hooks.
$GLOBALS['_rtc_profiler_times'] = array(
	'php_start' => $_SERVER['REQUEST_TIME_FLOAT'] ?? microtime( true ),
	'file_load' => microtime( true ),
);

$_hooks_to_track = array(
	'muplugins_loaded',
	'plugins_loaded',
	'after_setup_theme',
	'init',
	'wp_loaded',
	'parse_request',
	'rest_api_init',
);

foreach ( $_hooks_to_track as $_h ) {
	add_action(
		$_h,
		function () use ( $_h ) {
			$GLOBALS['_rtc_profiler_times'][ $_h ] = microtime( true );
		},
		-9999
	);
}

function rtc_profiler_handle_request( WP_REST_Request $request ) {
	$times = $GLOBALS['_rtc_profiler_times'];

	$times['handler_start'] = microtime( true );

	// Forward to the real sync endpoint.
	$sync_request = new WP_REST_Request( 'POST', '/wp-sync/v1/updates' );
	$sync_request->set_body_params( $request->get_body_params() );

	// Get body from raw input if params are empty.
	$params = $request->get_body_params();
	if ( empty( $params ) ) {
		$body   = $request->get_body();
		$params = json_decode( $body, true );
		if ( $params ) {
			$sync_request->set_body_params( $params );
		}
	}

	$server   = rest_get_server();
	$response = $server->dispatch( $sync_request );

	$times['handler_done'] = microtime( true );

	// Build profiling output.
	$start   = $times['php_start'];
	$prev    = $start;
	$profile = array();

	$order = array(
		'file_load',
		'muplugins_loaded',
		'plugins_loaded',
		'after_setup_theme',
		'init',
		'wp_loaded',
		'parse_request',
		'rest_api_init',
		'handler_start',
		'handler_done',
	);

	foreach ( $order as $label ) {
		if ( isset( $times[ $label ] ) ) {
			$t                = $times[ $label ];
			$profile[ $label ] = array(
				'abs_ms'   => round( ( $t - $start ) * 1000, 2 ),
				'delta_ms' => round( ( $t - $prev ) * 1000, 2 ),
			);
			$prev = $t;
		}
	}

	$profile['total_ms'] = round( ( microtime( true ) - $start ) * 1000, 2 );

	// Merge profiling into the response.
	$data              = $response->get_data();
	$data['_profiler'] = $profile;

	return new WP_REST_Response( $data, $response->get_status() );
}
