<?php
// Server-side profiling script for RTC sync operations.
// Run via: wp eval-file wp-content/mu-plugins/profile-server.php

$runs = 10;
$results = array();

for ( $i = 0; $i < $runs; $i++ ) {
	$t = array();

	$t['start'] = microtime( true );

	// 1. Storage init
	$storage = new WP_Sync_Post_Meta_Storage();
	$t['storage_init'] = microtime( true );

	// 2. Permission check (the expensive one)
	$t['perm_start'] = microtime( true );
	$can_edit = current_user_can( 'edit_post', 3 );
	$t['perm_done'] = microtime( true );

	// 3. Awareness read (called in check_permissions AND handle_request)
	$awareness = $storage->get_awareness_state( 'postType/post:3' );
	$t['awareness_read'] = microtime( true );

	// 4. Awareness write
	$storage->set_awareness_state( 'postType/post:3', $awareness );
	$t['awareness_write'] = microtime( true );

	// 5. Updates read
	$updates = $storage->get_updates_after_cursor( 'postType/post:3', 0 );
	$t['updates_read'] = microtime( true );

	// 6. Cursor + count (cached from updates_read)
	$cursor = $storage->get_cursor( 'postType/post:3' );
	$count = $storage->get_update_count( 'postType/post:3' );
	$t['done'] = microtime( true );

	$results[] = $t;
}

// Output results.
echo "=== RTC Sync Server-Side Profile ===" . PHP_EOL;
echo "Runs: $runs" . PHP_EOL . PHP_EOL;

$stages = array(
	'storage_init'    => 'Storage init',
	'perm_done'       => 'Permission check',
	'awareness_read'  => 'Awareness read',
	'awareness_write' => 'Awareness write',
	'updates_read'    => 'Updates read',
	'done'            => 'Cursor + count',
);

$prev_key = 'start';
foreach ( $stages as $key => $label ) {
	$deltas = array();
	foreach ( $results as $r ) {
		$deltas[] = ( $r[ $key ] - $r[ $prev_key ] ) * 1000;
	}
	sort( $deltas );
	$median = $deltas[ (int) ( count( $deltas ) / 2 ) ];
	$mean   = array_sum( $deltas ) / count( $deltas );
	$min    = $deltas[0];
	$max    = end( $deltas );
	$bar    = str_repeat( '#', max( 1, (int) ( $median / 0.5 ) ) );

	printf( "%-20s  median=%6.2fms  mean=%6.2fms  min=%6.2fms  max=%6.2fms  %s\n", $label, $median, $mean, $min, $max, $bar );
	$prev_key = $key;
}

// Total
$totals = array();
foreach ( $results as $r ) {
	$totals[] = ( $r['done'] - $r['start'] ) * 1000;
}
sort( $totals );
$median = $totals[ (int) ( count( $totals ) / 2 ) ];
$mean   = array_sum( $totals ) / count( $totals );
echo PHP_EOL;
printf( "Total sync logic:   median=%6.2fms  mean=%6.2fms\n", $median, $mean );
echo PHP_EOL;

// Now measure what the HTTP overhead looks like
echo "=== HTTP Request Overhead Estimate ===" . PHP_EOL;
echo "Client sees ~420ms. Sync logic is ~" . round( $median, 0 ) . "ms." . PHP_EOL;
echo "Overhead = ~" . round( 420 - $median, 0 ) . "ms (WP bootstrap + REST API + auth + network)" . PHP_EOL;

// Measure individual WP operations that contribute to overhead
$t2 = array();
$t2['start'] = microtime( true );

// Simulate what happens during a REST request
// 1. Cookie validation
wp_validate_auth_cookie();
$t2['cookie_validate'] = microtime( true );

// 2. Nonce verification
wp_verify_nonce( 'fake_nonce', 'wp_rest' );
$t2['nonce_verify'] = microtime( true );

// 3. REST schema validation (simulated with JSON decode)
json_decode( '{"rooms":[{"room":"postType/post:3","client_id":12345,"after":0,"awareness":{},"updates":[]}]}', true );
$t2['json_decode'] = microtime( true );

// 4. get_post (used by current_user_can)
get_post( 3 );
$t2['get_post'] = microtime( true );

echo PHP_EOL;
$prev2 = 'start';
$labels2 = array(
	'cookie_validate' => 'Cookie validation',
	'nonce_verify'    => 'Nonce verification',
	'json_decode'     => 'JSON decode',
	'get_post'        => 'get_post(3)',
);
foreach ( $labels2 as $k => $l ) {
	$d = ( $t2[ $k ] - $t2[ $prev2 ] ) * 1000;
	printf( "%-20s  %6.2fms\n", $l, $d );
	$prev2 = $k;
}
