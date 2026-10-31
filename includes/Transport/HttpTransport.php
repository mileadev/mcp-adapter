<?php
/**
 * MCP HTTP Transport for WordPress (MCP 2025-11-25 baseline)
 *
 * This transport implements the MCP HTTP transport surface used by this plugin.
 * It can work both with and without the mcp-wordpress-remote proxy.
 *
 * Note: SSE (GET streaming) is not yet implemented; GET currently returns 405.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Transport;

use WP\MCP\Transport\Contracts\McpRestTransportInterface;
use WP\MCP\Transport\Infrastructure\HttpRequestContext;
use WP\MCP\Transport\Infrastructure\HttpRequestHandler;
use WP\MCP\Transport\Infrastructure\McpTransportContext;
use WP\MCP\Transport\Infrastructure\McpTransportHelperTrait;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * MCP HTTP Transport - Unified transport for both proxy and direct clients
 *
 * Implements the MCP 2025-11-25 HTTP transport shape used by this adapter (POST + sessions).
 *
 * Note: SSE (GET streaming) is not yet implemented; GET currently returns 405.
 */
class HttpTransport implements McpRestTransportInterface {
	use McpTransportHelperTrait;

	/**
	 * The HTTP request handler.
	 *
	 * @var \WP\MCP\Transport\Infrastructure\HttpRequestHandler
	 */
	protected HttpRequestHandler $request_handler;

	/**
	 * Initialize the class and register routes
	 *
	 * @param \WP\MCP\Transport\Infrastructure\McpTransportContext $transport_context The transport context.
	 */
	public function __construct( McpTransportContext $transport_context ) {
		$this->request_handler = new HttpRequestHandler( $transport_context );
		add_action( 'rest_api_init', array( $this, 'register_routes' ), 16 );
	}

	/**
	 * Register MCP HTTP routes
	 */
	public function register_routes(): void {
		// Get server info from request handler's transport context
		$server = $this->request_handler->get_transport_context()->mcp_server;

		// Single endpoint for MCP communication (POST, GET reserved for SSE, DELETE for session termination).
		// Do not remove GET: it is part of the MCP HTTP transport shape and will be implemented (SSE) in a future iteration.
		register_rest_route(
			$server->get_server_route_namespace(),
			$server->get_server_route(),
			array(
				'methods'             => array( 'POST', 'GET', 'DELETE' ),
				'callback'            => array( $this, 'handle_request' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);
	}

	/**
	 * Check if the user has permission to access the MCP API
	 *
	 * @param \WP_REST_Request<array<string, mixed>> $request The request object.
	 *
	 * @return bool|\WP_Error True when permitted, false when denied, or a 403 error for a disallowed Origin.
	 */
	public function check_permission( \WP_REST_Request $request ) {
		$context = new HttpRequestContext( $request );

		// Enforce the MCP Origin boundary before invoking any custom permission callback.
		if ( ! $this->is_allowed_origin( $request ) ) {
			return new \WP_Error( 'mcp_adapter_origin_forbidden', 'MCP requests from this Origin are not allowed.', array( 'status' => 403 ) );
		}

		// Check permission using callback or default
		$transport_context = $this->request_handler->get_transport_context();

		if ( null !== $transport_context->transport_permission_callback ) {
			try {
				$result = call_user_func( $transport_context->transport_permission_callback, $context->request );

				// Handle WP_Error returns
				if ( ! is_wp_error( $result ) ) {
					// Cast to bool to match return type while preserving truthy/falsy semantics.
					return (bool) $result;
				}

				// Log the error and deny access (fail-closed)
				$this->request_handler->get_transport_context()->error_handler->log(
					'Permission callback returned WP_Error: ' . $result->get_error_message(),
					array( 'HttpTransport::check_permission' )
				);

				return false;
			} catch ( \Throwable $e ) {
				// Log the error and deny access (fail-closed)
				$this->request_handler->get_transport_context()->error_handler->log( 'Error in transport permission callback: ' . $e->getMessage(), array( 'HttpTransport::check_permission' ) );

				return false;
			}
		}

		/**
		 * Filters the default user capability required for MCP transport access.
		 *
		 * This filter is only applied when no custom transport permission callback
		 * is provided. The capability is checked using current_user_can().
		 *
		 * @since 0.3.0
		 *
		 * @param string                                        $capability The required capability. Default 'read'.
		 * @param \WP\MCP\Transport\Infrastructure\HttpRequestContext $context    The HTTP request context.
		 */
		$user_capability = apply_filters( 'mcp_adapter_default_transport_permission_user_capability', 'read', $context );

		// Validate that the filtered capability is a non-empty string
		if ( ! is_string( $user_capability ) || empty( $user_capability ) ) {
			$user_capability = 'read';
		}

		$user_has_capability = current_user_can( $user_capability ); // phpcs:ignore WordPress.WP.Capabilities.Undetermined -- Capability is filtered and defaults to 'read'

		if ( ! $user_has_capability ) {
			$user_id = get_current_user_id();
			$this->request_handler->get_transport_context()->error_handler->log(
				sprintf( 'Permission denied for MCP API access. User ID %d does not have capability "%s"', $user_id, $user_capability ),
				array( 'HttpTransport::check_permission' )
			);
		}

		return $user_has_capability;
	}


	/**
	 * Reject cross-origin HTTP MCP requests before authorization callbacks run.
	 *
	 * Absent Origin headers remain valid for non-browser MCP clients. Sites
	 * behind a reverse proxy can add their public origins with the filter.
	 *
	 * @param \WP_REST_Request $request The HTTP request.
	 * @return bool Whether the request Origin matches an allowed site origin.
	 */
	private function is_allowed_origin( \WP_REST_Request $request ): bool {
		$origin = $request->get_header( 'Origin' );
		if ( null === $origin ) {
			return true;
		}

		$normalized = is_string( $origin ) ? $this->normalize_origin( $origin, true ) : null;
		if ( null === $normalized ) {
			return false;
		}

		/**
		 * Filters the HTTP origins permitted to call the MCP endpoint.
		 *
		 * An origin must be an absolute HTTP(S) URL, not a wildcard.
		 * Only explicit origins are allowed; the site's REST URL is the default.
		 *
		 * @since 0.7.2
		 *
		 * @param string[]         $allowed_origins Permitted site origins.
		 * @param \WP_REST_Request $request         Incoming request.
		 */
		$allowed_origins = apply_filters( 'mcp_adapter_allowed_http_origins', array( rest_url() ), $request );
		if ( ! is_array( $allowed_origins ) ) {
			return false;
		}

		foreach ( $allowed_origins as $allowed_origin ) {
			if ( is_string( $allowed_origin ) && $normalized === $this->normalize_origin( $allowed_origin, false ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Compare scheme, hostname and effective port, not URL paths.
	 *
	 * Incoming Origin headers must not contain a path, userinfo or query.
	 * An allowed URL may contain a path (for example, rest_url()).
	 *
	 * @param string $url    HTTP(S) URL.
	 * @param bool   $strict Whether to enforce browser Origin syntax.
	 * @return string|null Canonical origin for comparison.
	 */
	private function normalize_origin( string $url, bool $strict ): ?string {
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || ! isset( $parts['scheme'], $parts['host'] ) ) {
			return null;
		}

		$scheme = strtolower( $parts['scheme'] );
		if (
			! in_array( $scheme, array( 'http', 'https' ), true )
			|| '' === $parts['host']
			|| isset( $parts['user'] )
			|| isset( $parts['pass'] )
			|| isset( $parts['query'] )
			|| isset( $parts['fragment'] )
			|| ( $strict && ! empty( $parts['path'] ) && '/' !== $parts['path'] )
		) {
			return null;
		}

		$port = isset( $parts['port'] ) ? (int) $parts['port'] : ( 'https' === $scheme ? 443 : 80 );

		return sprintf( '%s://%s:%d', $scheme, strtolower( $parts['host'] ), $port );
	}

	/**
	 * Handle HTTP requests according to MCP 2025-11-25 specification
	 *
	 * @param \WP_REST_Request<array<string, mixed>> $request The request object.
	 *
	 * @return \WP_REST_Response
	 */
	public function handle_request( \WP_REST_Request $request ): \WP_REST_Response {
		$context = new HttpRequestContext( $request );

		return $this->request_handler->handle_request( $context );
	}
}
