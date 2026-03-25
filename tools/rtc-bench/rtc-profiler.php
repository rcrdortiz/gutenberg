<?php
/**
 * RTC Sync Profiler - measures time spent at each stage of a sync request.
 * Injects timing data into the sync response JSON.
 */

$GLOBALS['_rtc_profile'] = array(
	'mu_plugin_load' => microtime( true ),
	'php_start'      => $_SERVER['REQUEST_TIME_FLOAT'] ?? microtime( true ),
);

$_rtc_hooks = array(
	'muplugins_loaded',
	'plugins_loaded',
	'after_setup_theme',
	'init',
	'wp_loaded',
	'parse_request',
	'rest_api_init',
);

foreach ( $_rtc_hooks as $hook ) {
	add_action(
		$hook,
		function () use ( $hook ) {
			$GLOBALS['_rtc_profile'][ $hook ] = microtime( true );
		},
		-9999
	);
}

// Use rest_request_after_callbacks which fires after the endpoint callback.
add_filter(
	'rest_request_after_callbacks',
	function ( $response, $handler, $request ) {
		if ( ! ( $response instanceof WP_REST_Response ) ) {
			return $response;
		}

		$route = $request->get_route();
		if ( strpos( $route, '/wp-sync/' ) !== 0 ) {
			return $response;
		}

		$GLOBALS['_rtc_profile']['after_callbacks'] = microtime( true );
		$start = $GLOBALS['_rtc_profile']['php_start'];

		$timings   = array();
		$prev_time = $start;

		$order = array(
			'mu_plugin_load',
			'muplugins_loaded',
			'plugins_loaded',
			'after_setup_theme',
			'init',
			'wp_loaded',
			'parse_request',
			'rest_api_init',
			'rest_pre_dispatch',
			'after_callbacks',
		);

		foreach ( $order as $label ) {
			if ( isset( $GLOBALS['_rtc_profile'][ $label ] ) ) {
				$delta = ( $GLOBALS['_rtc_profile'][ $label ] - $prev_time ) * 1000;
				$abs   = ( $GLOBALS['_rtc_profile'][ $label ] - $start ) * 1000;

				$timings[ $label ] = array(
					'abs_ms'   => round( $abs, 2 ),
					'delta_ms' => round( $delta, 2 ),
				);

				$prev_time = $GLOBALS['_rtc_profile'][ $label ];
			}
		}

		$total               = ( microtime( true ) - $start ) * 1000;
		$timings['total_ms'] = round( $total, 2 );

		$data              = $response->get_data();
		$data['_profiler'] = $timings;
		$response->set_data( $data );

		return $response;
	},
	9999,
	3
);

// Also capture rest_pre_dispatch.
add_filter(
	'rest_pre_dispatch',
	function ( $result, $server, $request ) {
		if ( strpos( $request->get_route(), '/wp-sync/' ) === 0 ) {
			$GLOBALS['_rtc_profile']['rest_pre_dispatch'] = microtime( true );
		}
		return $result;
	},
	10,
	3
);
