#!/usr/bin/env node
/**
 * RTC Sync Endpoint Benchmark
 *
 * Measures response times for the /wp-sync/v1/updates endpoint
 * under various scenarios: empty polls, with updates, multiple
 * clients, and compaction.
 *
 * Usage:
 *   node tools/rtc-bench/bench.mjs [options]
 *
 * Options:
 *   --url <url>           WordPress base URL (default: http://localhost:8888)
 *   --user <user:pass>    Auth via Basic auth (default: admin:password)
 *   --cookie <string>     Auth via cookie header (use with --nonce)
 *   --nonce <string>      WP nonce for cookie auth
 *   --iterations <n>      Requests per scenario (default: 50)
 *   --post-id <id>        Post ID to use for the room (default: 1)
 *   --warmup <n>          Warmup requests before measuring (default: 5)
 *   --scenarios <list>    Comma-separated scenarios to run (default: all)
 *   --json                Output raw JSON results
 *   --help                Show this help
 *
 * Available scenarios:
 *   empty-poll            Poll with no updates (baseline)
 *   with-update           Send a small Yjs update each request
 *   large-update          Send a ~10KB update each request
 *   multi-room            Poll 3 rooms in a single request
 *   accumulated           Poll after accumulating many stored updates
 *   compaction            Trigger and measure compaction
 */

const SCENARIOS = [
	'empty-poll',
	'with-update',
	'large-update',
	'multi-room',
	'accumulated',
	'compaction',
];

function parseArgs() {
	const args = process.argv.slice( 2 );
	const opts = {
		url: 'http://localhost:8888',
		user: 'admin:password',
		cookie: null,
		nonce: null,
		endpoint: 'rest-api', // 'rest-api' or 'lightweight'
		iterations: 50,
		postId: 1,
		warmup: 5,
		scenarios: SCENARIOS,
		json: false,
	};

	for ( let i = 0; i < args.length; i++ ) {
		switch ( args[ i ] ) {
			case '--url':
				opts.url = args[ ++i ];
				break;
			case '--user':
				opts.user = args[ ++i ];
				break;
			case '--cookie':
				opts.cookie = args[ ++i ];
				break;
			case '--nonce':
				opts.nonce = args[ ++i ];
				break;
			case '--endpoint':
				opts.endpoint = args[ ++i ];
				break;
			case '--iterations':
				opts.iterations = parseInt( args[ ++i ], 10 );
				break;
			case '--post-id':
				opts.postId = parseInt( args[ ++i ], 10 );
				break;
			case '--warmup':
				opts.warmup = parseInt( args[ ++i ], 10 );
				break;
			case '--scenarios':
				opts.scenarios = args[ ++i ].split( ',' );
				break;
			case '--json':
				opts.json = true;
				break;
			case '--help':
				console.log(
					process.argv[ 1 ]
						.split( '/' )
						.pop() + ' - RTC Sync Endpoint Benchmark\n'
				);
				console.log( 'Options:' );
				console.log(
					'  --url <url>           WordPress URL (default: http://localhost:8888)'
				);
				console.log(
					'  --user <user:pass>    Basic auth credentials (default: admin:password)'
				);
				console.log(
					'  --cookie <string>     Cookie header for auth (use with --nonce)'
				);
				console.log(
					'  --nonce <string>      WP nonce for cookie auth'
				);
				console.log(
					'  --endpoint <type>     rest-api (default) or lightweight'
				);
				console.log(
					'  --iterations <n>      Requests per scenario (default: 50)'
				);
				console.log(
					'  --post-id <id>        Post ID for room (default: 1)'
				);
				console.log(
					'  --warmup <n>          Warmup requests (default: 5)'
				);
				console.log(
					'  --scenarios <list>    Comma-separated list (default: all)'
				);
				console.log( '  --json                Output raw JSON' );
				console.log(
					'\nScenarios: ' + SCENARIOS.join( ', ' )
				);
				process.exit( 0 );
		}
	}

	return opts;
}

function buildRequestHeaders( opts ) {
	const headers = {
		'Content-Type': 'application/json',
	};

	if ( opts.cookie ) {
		headers.Cookie = opts.cookie;
		if ( opts.nonce ) {
			headers[ 'X-WP-Nonce' ] = opts.nonce;
		}
	} else {
		headers.Authorization =
			'Basic ' + Buffer.from( opts.user ).toString( 'base64' );
	}

	return headers;
}

function buildEndpoint( opts ) {
	if ( opts.endpoint === 'lightweight' ) {
		return `${ opts.url }/wp-sync-endpoint`;
	}
	if ( opts.cookie ) {
		// Cookie auth sites typically use pretty permalinks.
		return `${ opts.url }/wp-json/wp-sync/v1/updates`;
	}
	return `${ opts.url }/index.php?rest_route=/wp-sync/v1/updates`;
}

// Generate a fake base64-encoded Yjs-like update of a given byte size.
function fakeUpdate( sizeBytes ) {
	const buf = Buffer.alloc( sizeBytes );
	for ( let i = 0; i < sizeBytes; i++ ) {
		buf[ i ] = ( i * 7 + 13 ) & 0xff;
	}
	return buf.toString( 'base64' );
}

async function syncRequest( endpoint, headers, payload ) {
	const start = performance.now();
	const res = await fetch( endpoint, {
		method: 'POST',
		headers,
		body: JSON.stringify( payload ),
	} );
	const elapsed = performance.now() - start;
	const body = await res.json();
	return { elapsed, status: res.status, body };
}

function computeStats( times ) {
	const sorted = [ ...times ].sort( ( a, b ) => a - b );
	const n = sorted.length;
	const sum = sorted.reduce( ( s, v ) => s + v, 0 );
	const mean = sum / n;
	const median =
		n % 2 === 0
			? ( sorted[ n / 2 - 1 ] + sorted[ n / 2 ] ) / 2
			: sorted[ Math.floor( n / 2 ) ];
	const min = sorted[ 0 ];
	const max = sorted[ n - 1 ];
	const p95 = sorted[ Math.floor( n * 0.95 ) ];
	const p99 = sorted[ Math.floor( n * 0.99 ) ];
	const variance =
		sorted.reduce( ( s, v ) => s + ( v - mean ) ** 2, 0 ) / n;
	const stddev = Math.sqrt( variance );

	return { n, mean, median, min, max, p95, p99, stddev };
}

function formatMs( ms ) {
	return ms.toFixed( 1 ) + 'ms';
}

function printStats( name, stats ) {
	console.log( `\n  ${ name }  (${ stats.n } samples)` );
	console.log( `  ${ '-'.repeat( 50 ) }` );
	console.log(
		`  Mean:   ${ formatMs( stats.mean ) }    Median: ${ formatMs( stats.median ) }`
	);
	console.log(
		`  Min:    ${ formatMs( stats.min ) }    Max:    ${ formatMs( stats.max ) }`
	);
	console.log(
		`  P95:    ${ formatMs( stats.p95 ) }    P99:    ${ formatMs( stats.p99 ) }`
	);
	console.log( `  StdDev: ${ formatMs( stats.stddev ) }` );
}

// Clean up sync storage for a room so scenarios start fresh.
async function resetRoom( endpoint, headers, room, clientId ) {
	await syncRequest( endpoint, headers, {
		rooms: [
			{
				room,
				client_id: clientId,
				after: 0,
				awareness: null,
				updates: [],
			},
		],
	} );
}

async function runScenario( name, opts, endpoint, headers ) {
	const room = `postType/post:${ opts.postId }`;
	const clientId = 10000 + Math.floor( Math.random() * 80000 );
	const times = [];
	let errors = 0;

	// Reset room state.
	await resetRoom( endpoint, headers, room, clientId );

	switch ( name ) {
		case 'empty-poll': {
			for ( let i = 0; i < opts.warmup; i++ ) {
				await syncRequest( endpoint, headers, {
					rooms: [
						{
							room,
							client_id: clientId,
							after: 0,
							awareness: {},
							updates: [],
						},
					],
				} );
			}
			for ( let i = 0; i < opts.iterations; i++ ) {
				const { elapsed, status } = await syncRequest(
					endpoint,
					headers,
					{
						rooms: [
							{
								room,
								client_id: clientId,
								after: 0,
								awareness: {},
								updates: [],
							},
						],
					}
				);
				if ( status === 200 ) {
					times.push( elapsed );
				} else {
					errors++;
				}
			}
			break;
		}

		case 'with-update': {
			const smallUpdate = fakeUpdate( 64 );
			for ( let i = 0; i < opts.warmup; i++ ) {
				await syncRequest( endpoint, headers, {
					rooms: [
						{
							room,
							client_id: clientId,
							after: 0,
							awareness: {},
							updates: [
								{ data: smallUpdate, type: 'update' },
							],
						},
					],
				} );
			}
			let cursor = 0;
			for ( let i = 0; i < opts.iterations; i++ ) {
				const { elapsed, status, body } = await syncRequest(
					endpoint,
					headers,
					{
						rooms: [
							{
								room,
								client_id: clientId,
								after: cursor,
								awareness: {},
								updates: [
									{
										data: smallUpdate,
										type: 'update',
									},
								],
							},
						],
					}
				);
				if ( status === 200 ) {
					times.push( elapsed );
					cursor = body.rooms?.[ 0 ]?.end_cursor ?? cursor;
				} else {
					errors++;
				}
			}
			break;
		}

		case 'large-update': {
			const largeUpdate = fakeUpdate( 10240 );
			for ( let i = 0; i < opts.warmup; i++ ) {
				await syncRequest( endpoint, headers, {
					rooms: [
						{
							room,
							client_id: clientId,
							after: 0,
							awareness: {},
							updates: [
								{ data: largeUpdate, type: 'update' },
							],
						},
					],
				} );
			}
			let cursor = 0;
			for ( let i = 0; i < opts.iterations; i++ ) {
				const { elapsed, status, body } = await syncRequest(
					endpoint,
					headers,
					{
						rooms: [
							{
								room,
								client_id: clientId,
								after: cursor,
								awareness: {},
								updates: [
									{
										data: largeUpdate,
										type: 'update',
									},
								],
							},
						],
					}
				);
				if ( status === 200 ) {
					times.push( elapsed );
					cursor = body.rooms?.[ 0 ]?.end_cursor ?? cursor;
				} else {
					errors++;
				}
			}
			break;
		}

		case 'multi-room': {
			const rooms = [
				`postType/post:${ opts.postId }`,
				'postType/post',
				'taxonomy/category',
			];
			for ( let i = 0; i < opts.warmup; i++ ) {
				await syncRequest( endpoint, headers, {
					rooms: rooms.map( ( r ) => ( {
						room: r,
						client_id: clientId,
						after: 0,
						awareness: {},
						updates: [],
					} ) ),
				} );
			}
			for ( let i = 0; i < opts.iterations; i++ ) {
				const { elapsed, status } = await syncRequest(
					endpoint,
					headers,
					{
						rooms: rooms.map( ( r ) => ( {
							room: r,
							client_id: clientId,
							after: 0,
							awareness: {},
							updates: [],
						} ) ),
					}
				);
				if ( status === 200 ) {
					times.push( elapsed );
				} else {
					errors++;
				}
			}
			break;
		}

		case 'accumulated': {
			const otherClientId = clientId + 1;
			const smallUpdate = fakeUpdate( 128 );
			for ( let i = 0; i < 40; i++ ) {
				await syncRequest( endpoint, headers, {
					rooms: [
						{
							room,
							client_id: otherClientId,
							after: 0,
							awareness: {},
							updates: [
								{ data: smallUpdate, type: 'update' },
							],
						},
					],
				} );
			}
			for ( let i = 0; i < opts.warmup; i++ ) {
				await syncRequest( endpoint, headers, {
					rooms: [
						{
							room,
							client_id: clientId,
							after: 0,
							awareness: {},
							updates: [],
						},
					],
				} );
			}
			for ( let i = 0; i < opts.iterations; i++ ) {
				const { elapsed, status } = await syncRequest(
					endpoint,
					headers,
					{
						rooms: [
							{
								room,
								client_id: clientId,
								after: 0,
								awareness: {},
								updates: [],
							},
						],
					}
				);
				if ( status === 200 ) {
					times.push( elapsed );
				} else {
					errors++;
				}
			}
			break;
		}

		case 'compaction': {
			const otherClientId = clientId + 1;
			const smallUpdate = fakeUpdate( 128 );
			for ( let i = 0; i < 55; i++ ) {
				await syncRequest( endpoint, headers, {
					rooms: [
						{
							room,
							client_id: otherClientId,
							after: 0,
							awareness: {},
							updates: [
								{ data: smallUpdate, type: 'update' },
							],
						},
					],
				} );
			}
			const compactionData = fakeUpdate( 2048 );
			for ( let i = 0; i < opts.iterations; i++ ) {
				const poll = await syncRequest( endpoint, headers, {
					rooms: [
						{
							room,
							client_id: clientId,
							after: 0,
							awareness: {},
							updates: [],
						},
					],
				} );
				const cursor =
					poll.body.rooms?.[ 0 ]?.end_cursor ?? 0;

				const { elapsed, status } = await syncRequest(
					endpoint,
					headers,
					{
						rooms: [
							{
								room,
								client_id: clientId,
								after: cursor,
								awareness: {},
								updates: [
									{
										data: compactionData,
										type: 'compaction',
									},
								],
							},
						],
					}
				);
				if ( status === 200 ) {
					times.push( elapsed );
				} else {
					errors++;
				}

				for ( let j = 0; j < 55; j++ ) {
					await syncRequest( endpoint, headers, {
						rooms: [
							{
								room,
								client_id: otherClientId,
								after: 0,
								awareness: {},
								updates: [
									{
										data: smallUpdate,
										type: 'update',
									},
								],
							},
						],
					} );
				}
			}
			break;
		}
	}

	return { times, errors };
}

async function main() {
	const opts = parseArgs();
	const endpoint = buildEndpoint( opts );
	const headers = buildRequestHeaders( opts );
	const authMode = opts.cookie ? 'cookie+nonce' : 'basic';
	const endpointType = opts.endpoint;

	// Verify connectivity.
	try {
		const check = await syncRequest( endpoint, headers, {
			rooms: [
				{
					room: `postType/post:${ opts.postId }`,
					client_id: 99999,
					after: 0,
					awareness: {},
					updates: [],
				},
			],
		} );
		if ( check.status !== 200 ) {
			console.error(
				`Endpoint returned status ${ check.status }. Check credentials.`
			);
			console.error( JSON.stringify( check.body, null, 2 ) );
			process.exit( 1 );
		}
	} catch ( err ) {
		console.error( `Cannot reach ${ endpoint }: ${ err.message }` );
		process.exit( 1 );
	}

	const allResults = {};

	if ( ! opts.json ) {
		console.log( '\nRTC Sync Endpoint Benchmark' );
		console.log( '='.repeat( 55 ) );
		console.log( `URL:        ${ opts.url }` );
		console.log( `Endpoint:   ${ endpointType }` );
		console.log( `Auth:       ${ authMode }` );
		console.log( `Iterations: ${ opts.iterations }` );
		console.log( `Warmup:     ${ opts.warmup }` );
		console.log( `Post ID:    ${ opts.postId }` );
	}

	for ( const scenario of opts.scenarios ) {
		if ( ! SCENARIOS.includes( scenario ) ) {
			console.error( `Unknown scenario: ${ scenario }` );
			continue;
		}

		if ( ! opts.json ) {
			process.stdout.write( `\n  Running: ${ scenario }...` );
		}

		const { times, errors } = await runScenario(
			scenario,
			opts,
			endpoint,
			headers
		);

		if ( times.length === 0 ) {
			if ( ! opts.json ) {
				console.log( ` FAILED (${ errors } errors)` );
			}
			allResults[ scenario ] = { error: `${ errors } errors` };
			continue;
		}

		const stats = computeStats( times );
		allResults[ scenario ] = { ...stats, errors };

		if ( ! opts.json ) {
			process.stdout.write( '\r' + ' '.repeat( 60 ) + '\r' );
			printStats( scenario, stats );
			if ( errors > 0 ) {
				console.log( `  Errors: ${ errors }` );
			}
		}
	}

	if ( opts.json ) {
		console.log( JSON.stringify( allResults, null, 2 ) );
	} else {
		console.log( '\n' + '='.repeat( 55 ) );
		console.log( 'Done.\n' );
	}
}

main().catch( ( err ) => {
	console.error( err );
	process.exit( 1 );
} );
