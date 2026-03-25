<?php
/**
 * WP_Sync_Lightweight_Endpoint class
 *
 * @package gutenberg
 */

if ( ! class_exists( 'WP_Sync_Lightweight_Endpoint' ) ) {

	/**
	 * A minimal sync endpoint that bypasses the REST API stack entirely.
	 *
	 * Hooks into parse_request to intercept sync requests early,
	 * performs its own auth (cookie+nonce or application passwords),
	 * and delegates to WP_HTTP_Polling_Sync_Server for the actual logic.
	 *
	 * @since 7.0.0
	 * @access private
	 */
	class WP_Sync_Lightweight_Endpoint {
		/**
		 * Query var used to identify sync requests.
		 *
		 * @var string
		 */
		const QUERY_VAR = 'wp_sync';

		/**
		 * The sync server instance.
		 *
		 * @var WP_HTTP_Polling_Sync_Server
		 */
		private WP_HTTP_Polling_Sync_Server $sync_server;

		/**
		 * Constructor.
		 *
		 * @param WP_HTTP_Polling_Sync_Server $sync_server The sync server.
		 */
		public function __construct( WP_HTTP_Polling_Sync_Server $sync_server ) {
			$this->sync_server = $sync_server;
		}

		/**
		 * Registers the endpoint hooks.
		 * Hooks at 'init' (late priority) to intercept requests as early
		 * as possible, before the main query runs.
		 */
		public function register(): void {
			add_action( 'init', array( $this, 'handle_at_init' ), 999 );
		}

		/**
		 * Returns the endpoint URL for client-side use.
		 *
		 * @return string The endpoint URL.
		 */
		public static function get_endpoint_url(): string {
			return home_url( '/wp-sync-endpoint' );
		}

		/**
		 * Handler called at init. Checks REQUEST_URI and exits early
		 * for non-sync requests.
		 */
		public function handle_at_init(): void {
			$this->handle_sync_request();
		}

		/**
		 * Handles incoming sync requests.
		 */
		private function handle_sync_request(): void {
			// Match both /?wp_sync=1 and /wp-sync-endpoint.
			$is_sync_request = isset( $_GET[ self::QUERY_VAR ] )
				|| ( isset( $_SERVER['REQUEST_URI'] ) && false !== strpos( $_SERVER['REQUEST_URI'], '/wp-sync-endpoint' ) );

			if ( ! $is_sync_request ) {
				return;
			}

			// Only accept POST.
			if ( 'POST' !== $_SERVER['REQUEST_METHOD'] ) {
				wp_send_json_error(
					array( 'message' => 'Method not allowed.' ),
					405
				);
			}

			// Authenticate.
			$auth_result = $this->authenticate();
			if ( is_wp_error( $auth_result ) ) {
				wp_send_json_error(
					array(
						'code'    => $auth_result->get_error_code(),
						'message' => $auth_result->get_error_message(),
					),
					$auth_result->get_error_data()['status'] ?? 401
				);
			}

			// Parse body.
			$body = file_get_contents( 'php://input' );
			$data = json_decode( $body, true );

			if ( null === $data || ! isset( $data['rooms'] ) ) {
				wp_send_json_error(
					array( 'message' => 'Invalid JSON body.' ),
					400
				);
			}

			// Build a WP_REST_Request so we can reuse the sync server's
			// handle_request() and check_permissions() without changes.
			$request = new WP_REST_Request( 'POST', '/wp-sync/v1/updates' );
			$request->set_body_params( $data );

			// Run permission check.
			$permission = $this->sync_server->check_permissions( $request );
			if ( is_wp_error( $permission ) ) {
				wp_send_json_error(
					array(
						'code'    => $permission->get_error_code(),
						'message' => $permission->get_error_message(),
					),
					$permission->get_error_data()['status'] ?? 403
				);
			}

			if ( true !== $permission ) {
				wp_send_json_error(
					array( 'message' => 'Permission denied.' ),
					403
				);
			}

			// Handle the sync request.
			$response = $this->sync_server->handle_request( $request );

			if ( is_wp_error( $response ) ) {
				wp_send_json_error(
					array(
						'code'    => $response->get_error_code(),
						'message' => $response->get_error_message(),
					),
					$response->get_error_data()['status'] ?? 500
				);
			}

			// Extract data from WP_REST_Response.
			$data   = $response->get_data();
			$status = $response->get_status();

			status_header( $status );
			header( 'Content-Type: application/json; charset=' . get_option( 'blog_charset' ) );

			echo wp_json_encode( $data );
			exit;
		}

		/**
		 * Authenticates the request using cookie+nonce or application passwords.
		 *
		 * Triggers the same 'determine_current_user' and 'rest_authentication_errors'
		 * filters that the REST API uses, so Application Passwords and other auth
		 * plugins work identically.
		 *
		 * @return true|WP_Error True if authenticated, WP_Error otherwise.
		 */
		private function authenticate() {
			// Ensure PHP_AUTH_USER and PHP_AUTH_PW are set from the
			// Authorization header. Apache may pass it via
			// HTTP_AUTHORIZATION instead.
			if ( ! isset( $_SERVER['PHP_AUTH_USER'] ) ) {
				$auth_header = null;
				if ( isset( $_SERVER['HTTP_AUTHORIZATION'] ) ) {
					$auth_header = $_SERVER['HTTP_AUTHORIZATION'];
				} elseif ( isset( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) ) {
					$auth_header = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
				}

				if ( $auth_header && 0 === strpos( $auth_header, 'Basic ' ) ) {
					$decoded = base64_decode( substr( $auth_header, 6 ), true );
					if ( $decoded && false !== strpos( $decoded, ':' ) ) {
						list( $user, $pass )       = explode( ':', $decoded, 2 );
						$_SERVER['PHP_AUTH_USER']   = $user;
						$_SERVER['PHP_AUTH_PW']     = $pass;
					}
				}
			}

			// Enable Application Password auth by triggering the same
			// filter the REST API uses.
			if ( function_exists( 'wp_is_application_passwords_available' )
				&& wp_is_application_passwords_available() ) {
				// Application Passwords require this to be an "API request".
				add_filter( 'application_password_is_api_request', '__return_true' );
				add_filter( 'determine_current_user', 'wp_validate_application_password', 20 );
			}

			// Re-determine the current user with the new filters in place.
			// We pass 0 to force re-evaluation from scratch.
			$user_id = apply_filters( 'determine_current_user', 0 );
			if ( $user_id ) {
				wp_set_current_user( $user_id );
			}

			$user_id = get_current_user_id();

			if ( 0 === $user_id ) {
				return new WP_Error(
					'rest_not_logged_in',
					__( 'You are not currently logged in.' ),
					array( 'status' => 401 )
				);
			}

			// For cookie auth, verify the nonce (same as REST API).
			// Application Password requests don't need a nonce.
			$is_app_password = isset( $GLOBALS['wp_rest_application_password_uuid'] );
			if ( ! $is_app_password ) {
				$nonce = '';
				if ( isset( $_SERVER['HTTP_X_WP_NONCE'] ) ) {
					$nonce = sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_WP_NONCE'] ) );
				} elseif ( isset( $_POST['_wpnonce'] ) ) {
					$nonce = sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) );
				}

				if ( empty( $nonce ) || ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
					return new WP_Error(
						'rest_cookie_invalid_nonce',
						__( 'Cookie check failed.' ),
						array( 'status' => 403 )
					);
				}
			}

			return true;
		}
	}
}
