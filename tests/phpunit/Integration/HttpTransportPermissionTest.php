<?php
/**
 * HTTP transport permission boundary tests.
 *
 * @package WP\MCP\Tests
 */

declare( strict_types=1 );

namespace WP\MCP\Tests\Integration;

use WP\MCP\Infrastructure\ErrorHandling\Contracts\McpErrorHandlerInterface;
use WP\MCP\Tests\Fixtures\DummyErrorHandler;
use WP\MCP\Tests\TestCase;
use WP\MCP\Transport\HttpTransport;
use WP\MCP\Transport\Infrastructure\McpTransportContext;
use WP_Error;
use WP_REST_Request;

/** Proves that transport-level authentication remains fail-closed and extensible. */
final class HttpTransportPermissionTest extends TestCase {

	/** Custom callbacks control access and receive the original REST request. */
	public function test_custom_permission_callback_controls_access_and_receives_request(): void {
		$request  = new WP_REST_Request( 'POST', '/mcp' );
		$captured = null;
		$denied   = $this->make_transport(
			static function ( WP_REST_Request $received ) use ( &$captured ): bool {
				$captured = $received;

				return false;
			}
		);

		$this->assertFalse( $denied->check_permission( $request ) );
		$this->assertSame( $request, $captured );

		$allowed = $this->make_transport( '__return_true' );
		$this->assertTrue( $allowed->check_permission( $request ) );
	}

	/** A WP_Error from a custom callback is logged and denied. */
	public function test_custom_permission_callback_wp_error_fails_closed_and_logs(): void {
		$transport = $this->make_transport(
			static fn(): WP_Error => new WP_Error( 'blocked', 'Custom permission error' )
		);

		$this->assertFalse( $transport->check_permission( new WP_REST_Request( 'POST', '/mcp' ) ) );
		$this->assertCount( 1, DummyErrorHandler::$logs );
		$this->assertStringContainsString( 'Permission callback returned WP_Error: Custom permission error', DummyErrorHandler::$logs[0]['message'] );
		$this->assertSame( array( 'HttpTransport::check_permission' ), DummyErrorHandler::$logs[0]['context'] );
	}

	/** Exceptions from custom callbacks are logged and denied. */
	public function test_custom_permission_callback_exception_fails_closed_and_logs(): void {
		$transport = $this->make_transport(
			static function (): bool {
				throw new \RuntimeException( 'Permission callback failed' );
			}
		);

		$this->assertFalse( $transport->check_permission( new WP_REST_Request( 'POST', '/mcp' ) ) );
		$this->assertCount( 1, DummyErrorHandler::$logs );
		$this->assertStringContainsString( 'Error in transport permission callback: Permission callback failed', DummyErrorHandler::$logs[0]['message'] );
		$this->assertSame( array( 'HttpTransport::check_permission' ), DummyErrorHandler::$logs[0]['context'] );
	}

	/** The default permission requires an authenticated user and logs denial. */
	public function test_default_permission_requires_authenticated_user_and_logs_denial(): void {
		$transport = $this->make_transport();
		$request   = new WP_REST_Request( 'POST', '/mcp' );

		wp_set_current_user( 1 );
		$this->assertTrue( $transport->check_permission( $request ) );

		wp_set_current_user( 0 );
		$this->assertFalse( $transport->check_permission( $request ) );
		$this->assertCount( 1, DummyErrorHandler::$logs );
		$this->assertStringContainsString( 'does not have capability "read"', DummyErrorHandler::$logs[0]['message'] );
	}

	/** The capability filter is honored and invalid values fall back to read. */
	public function test_capability_filter_controls_access_and_invalid_values_fall_back(): void {
		$transport = $this->make_transport();
		$request   = new WP_REST_Request( 'POST', '/mcp' );

		add_filter( 'mcp_adapter_default_transport_permission_user_capability', static fn(): string => 'manage_options' );
		wp_set_current_user( 1 );
		$this->assertTrue( $transport->check_permission( $request ) );

		remove_all_filters( 'mcp_adapter_default_transport_permission_user_capability' );
		add_filter( 'mcp_adapter_default_transport_permission_user_capability', static fn() => null );
		$this->assertTrue( $transport->check_permission( $request ) );
		remove_all_filters( 'mcp_adapter_default_transport_permission_user_capability' );
	}

	/** The transport retains its public REST route and request-delegation surface. */
	public function test_transport_registers_route_and_delegates_requests(): void {
		$transport = $this->make_transport();
		do_action( 'rest_api_init' );

		$this->assertArrayHasKey( '/mcp/v1/mcp', rest_get_server()->get_routes() );

		$response = $transport->handle_request( new WP_REST_Request( 'PATCH', '/mcp' ) );
		$this->assertSame( 405, $response->get_status() );
		$this->assertNull( $response->get_data() );
	}


	/** Cross-site browser requests are denied before a custom callback executes. */
	public function test_cross_origin_is_rejected_before_custom_permission_callback(): void {
		$called    = false;
		$transport = $this->make_transport(
			static function () use ( &$called ): bool {
				$called = true;
				return true;
			}
		);
		$request   = new WP_REST_Request( 'POST', '/mcp' );
		$request->set_header( 'Origin', 'https://malicious.invalid' );

		$result = $transport->check_permission( $request );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'mcp_adapter_origin_forbidden', $result->get_error_code() );
		$this->assertSame( 403, $result->get_error_data()['status'] );
		$this->assertFalse( $called );
	}

	/** Same-origin requests and clients without Origin remain permitted. */
	public function test_same_origin_and_absent_origin_are_allowed(): void {
		$transport = $this->make_transport( '__return_true' );
		$request   = new WP_REST_Request( 'POST', '/mcp' );
		$this->assertTrue( $transport->check_permission( $request ) );

		$site_url = wp_parse_url( rest_url() );
		$origin   = $site_url['scheme'] . '://' . $site_url['host'];
		if ( isset( $site_url['port'] ) ) {
			$origin .= ':' . $site_url['port'];
		}
		$request->set_header( 'Origin', $origin );
		$this->assertTrue( $transport->check_permission( $request ) );
	}

	/** Proxies may explicitly allow their public origin; malformed Origins fail closed. */
	public function test_origin_allowlist_and_malformed_origins(): void {
		$transport = $this->make_transport( '__return_true' );
		$request   = new WP_REST_Request( 'POST', '/mcp' );
		$filter    = static fn(): array => array( 'https://approved.example:443' );
		add_filter( 'mcp_adapter_allowed_http_origins', $filter );

		try {
			$request->set_header( 'Origin', 'https://approved.example' );
			$this->assertTrue( $transport->check_permission( $request ) );

			foreach ( array( 'null', 'https://approved.example/path', 'https://evil.invalid', 'https://approved.example?x=1', 'https://user@approved.example' ) as $origin ) {
				$request->set_header( 'Origin', $origin );
				$this->assertInstanceOf( WP_Error::class, $transport->check_permission( $request ), $origin );
			}
		} finally {
			remove_filter( 'mcp_adapter_allowed_http_origins', $filter );
		}
	}

	/**
	 * Build a transport with an optional custom permission callback.
	 *
	 * @param callable|callable-string|null $permission_callback Permission callback.
	 */
	private function make_transport( $permission_callback = null, ?McpErrorHandlerInterface $error_handler = null ): HttpTransport {
		$server  = $this->makeServer();
		$context = $server->create_transport_context();

		return new HttpTransport(
			new McpTransportContext(
				array(
					'mcp_server'                    => $context->mcp_server,
					'initialize_handler'            => $context->initialize_handler,
					'tools_handler'                 => $context->tools_handler,
					'resources_handler'             => $context->resources_handler,
					'prompts_handler'               => $context->prompts_handler,
					'system_handler'                => $context->system_handler,
					'observability_handler'         => $context->observability_handler,
					'error_handler'                 => $error_handler ?? new DummyErrorHandler(),
					'transport_permission_callback' => $permission_callback,
				)
			)
		);
	}
}
