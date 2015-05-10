<?php

namespace CustomizeWidgetsPlus;

class Test_HTTPS_Resource_Proxy extends Base_Test_Case {

	/**
	 * @var $wpdb
	 */
	public $wpdb;

	function setUp() {
		parent::setUp();
	}

	/**
	 * @see HTTPS_Resource_Proxy::__construct()
	 */
	function test_construct() {
		$instance = new HTTPS_Resource_Proxy( $this->plugin );

		$this->assertEquals( 10, has_action( 'init', array( $instance, 'add_rewrite_rule' ) ) );
		$this->assertEquals( 10, has_filter( 'query_vars', array( $instance, 'filter_query_vars' ) ) );
		$this->assertEquals( 10, has_filter( 'redirect_canonical', array( $instance, 'enforce_trailingslashing' ) ) );
		$this->assertEquals( 10, has_action( 'template_redirect', array( $instance, 'handle_proxy_request' ) ) );
		$this->assertEquals( 10, has_action( 'init', array( $instance, 'add_proxy_filtering' ) ) );
	}

	/**
	 * @see HTTPS_Resource_Proxy::default_config()
	 */
	function test_default_config() {
		$default_config = HTTPS_Resource_Proxy::default_config();
		$this->assertArrayHasKey( 'min_cache_ttl', $default_config );
		$this->assertArrayHasKey( 'customize_preview_only', $default_config );
		$this->assertArrayHasKey( 'logged_in_users_only', $default_config );
		$this->assertArrayHasKey( 'max_content_length', $default_config );
	}

	/**
	 * @see HTTPS_Resource_Proxy::config()
	 */
	function test_config() {
		$instance = new HTTPS_Resource_Proxy( $this->plugin );
		$this->assertInternalType( 'int', $instance->config( 'min_cache_ttl' ) );
		$this->assertInternalType( 'bool', $instance->config( 'customize_preview_only' ) );
		$this->assertInternalType( 'bool', $instance->config( 'logged_in_users_only' ) );
		$this->assertInternalType( 'int', $instance->config( 'max_content_length' ) );
	}

	/**
	 * @see HTTPS_Resource_Proxy::is_proxy_enabled()
	 */
	function test_is_proxy_enabled() {
		$instance = new HTTPS_Resource_Proxy( $this->plugin );
		$this->assertInternalType( 'bool', $instance->is_proxy_enabled() );
		add_filter( 'https_resource_proxy_filtering_enabled', '__return_false', 5 );
		$this->assertFalse( $instance->is_proxy_enabled() );
		add_filter( 'https_resource_proxy_filtering_enabled', '__return_true', 10 );
		$this->assertTrue( $instance->is_proxy_enabled() );
	}

	/**
	 * @see HTTPS_Resource_Proxy::add_proxy_filtering()
	 */
	function test_add_proxy_filtering() {
		$instance = new HTTPS_Resource_Proxy( $this->plugin );

		add_filter( 'https_resource_proxy_filtering_enabled', '__return_true' );
		$instance->add_proxy_filtering();
		$this->assertEquals( 10, has_filter( 'script_loader_src', array( $instance, 'filter_script_loader_src' ) ) );
		$this->assertEquals( 10, has_filter( 'style_loader_src', array( $instance, 'filter_style_loader_src' ) ) );
	}

	/**
	 * @see HTTPS_Resource_Proxy::filter_query_vars()
	 */
	function test_filter_query_vars() {
		$instance = new HTTPS_Resource_Proxy( $this->plugin );
		$query_vars = $instance->filter_query_vars( array() );
		$this->assertContains( HTTPS_Resource_Proxy::NONCE_QUERY_VAR, $query_vars );
		$this->assertContains( HTTPS_Resource_Proxy::HOST_QUERY_VAR, $query_vars );
		$this->assertContains( HTTPS_Resource_Proxy::PATH_QUERY_VAR, $query_vars );
	}

	/**
	 * @see HTTPS_Resource_Proxy::add_rewrite_rule()
	 */
	function test_add_rewrite_rule() {
		/** @var \WP_Rewrite $wp_rewrite */
		global $wp_rewrite;
		$instance = new HTTPS_Resource_Proxy( $this->plugin );
		$this->assertEmpty( $instance->rewrite_regex );
		$instance->add_rewrite_rule();
		$this->assertNotEmpty( $instance->rewrite_regex );
		$this->assertArrayHasKey( $instance->rewrite_regex, $wp_rewrite->extra_rules_top );
	}

	/**
	 * @see HTTPS_Resource_Proxy::reserve_api_endpoint()
	 */
	function test_reserve_api_endpoint() {
		$instance = new HTTPS_Resource_Proxy( $this->plugin );
		$instance->add_rewrite_rule();
		$this->assertTrue( $instance->reserve_api_endpoint( false, $instance->config( 'endpoint' ) ) );
		$this->assertFalse( $instance->reserve_api_endpoint( false, 'prefix-' . $instance->config( 'endpoint' ) . '-suffix' ) );
	}

	/**
	 * @see HTTPS_Resource_Proxy::filter_loader_src()
	 * @see HTTPS_Resource_Proxy::filter_script_loader_src()
	 * @see HTTPS_Resource_Proxy::filter_style_loader_src()
	 */
	function test_filter_loader_src() {
		$instance = new HTTPS_Resource_Proxy( $this->plugin );
		add_filter( 'https_resource_proxy_filtering_enabled', '__return_true' );
		$instance->add_proxy_filtering();

		$src = 'https://example.org/main.css?ver=1';
		$this->assertEquals( $src, $instance->filter_loader_src( $src ) );

		$src = 'http://example.org/main.css?ver=2';
		$parsed_src = parse_url( $src );

		$filtered_src = $instance->filter_loader_src( $src );
		$this->assertNotEquals( $src, $filtered_src );

		$this->assertNotEmpty( preg_match( '#' . $instance->rewrite_regex . '#', $filtered_src, $matches ) );

		$this->assertNotEmpty( $matches['nonce'] );
		$this->assertNotEmpty( $matches['host'] );
		$this->assertNotEmpty( $matches['path'] );

		$this->assertEquals( wp_create_nonce( HTTPS_Resource_Proxy::MODULE_SLUG ), $matches['nonce'] );
		$this->assertEquals( $parsed_src['host'], $matches['host'] );
		$this->assertEquals( '/main.css', $parsed_src['path'] );
		$this->assertStringEndsWith( '?ver=2', $filtered_src );

		$src = 'http://example.org/main.css?ver=2';
		$this->assertEquals( $instance->filter_loader_src( $src ), $instance->filter_style_loader_src( $src ) );
		$src = 'http://example.org/main.js?ver=2';
		$this->assertEquals( $instance->filter_loader_src( $src ), $instance->filter_script_loader_src( $src ) );
	}

	/**
	 * @see HTTPS_Resource_Proxy::enqueue_scripts()
	 */
	function test_enqueue_scripts() {
		$instance = new HTTPS_Resource_Proxy( $this->plugin );
		add_filter( 'https_resource_proxy_filtering_enabled', '__return_true' );
		$instance->add_proxy_filtering();

		unset( $instance );
		$wp_scripts = wp_scripts(); // and fire wp_default_scripts
		wp_styles(); // fire wp_default_styles
		do_action( 'wp_enqueue_scripts' );
		$this->assertContains( $this->plugin->script_handles['https-resource-proxy'], $wp_scripts->queue );
		$this->assertContains( 'var _httpsResourceProxyExports', $wp_scripts->get_data( $this->plugin->script_handles['https-resource-proxy'], 'data' ) );
	}

	/**
	 * @see HTTPS_Resource_Proxy::enforce_trailingslashing()
	 */
	function test_enforce_trailingslashing() {
		$instance = new HTTPS_Resource_Proxy( $this->plugin );
		set_query_var( HTTPS_Resource_Proxy::PATH_QUERY_VAR, '/main.css' );
		$instance->plugin->config[ HTTPS_Resource_Proxy::MODULE_SLUG ]['trailingslash_srcs'] = true;
		$this->assertStringEndsWith( '.js/', $instance->enforce_trailingslashing( 'http://example.com/main.js' ) );
		$this->assertStringEndsWith( '.js/?ver=1', $instance->enforce_trailingslashing( 'http://example.com/main.js?ver=1' ) );
		$instance->plugin->config[ HTTPS_Resource_Proxy::MODULE_SLUG ]['trailingslash_srcs'] = false;
		$this->assertStringEndsWith( '.js', $instance->enforce_trailingslashing( 'http://example.com/main.js/' ) );
		$this->assertStringEndsWith( '.js?ver=1', $instance->enforce_trailingslashing( 'http://example.com/main.js/?ver=1' ) );
		set_query_var( HTTPS_Resource_Proxy::PATH_QUERY_VAR, null );
	}

	/**
	 * @see HTTPS_Resource_Proxy::send_proxy_response()
	 * @see HTTPS_Resource_Proxy::handle_proxy_request()
	 */
	function test_send_proxy_response_not_enabled() {
		$instance = new HTTPS_Resource_Proxy( $this->plugin );
		add_filter( 'https_resource_proxy_filtering_enabled', '__return_false' );

		$exception = null;
		try {
			$instance->send_proxy_response( array() );
		} catch ( Exception $e ) {
			$exception = $e;
		}
		$this->assertInstanceOf( __NAMESPACE__ . '\\Exception', $exception );
		$this->assertEquals( 'proxy_not_enabled', $exception->getMessage() );
	}

	/**
	 * @see HTTPS_Resource_Proxy::send_proxy_response()
	 * @see HTTPS_Resource_Proxy::handle_proxy_request()
	 */
	function test_send_proxy_response_bad_nonce() {
		$instance = new HTTPS_Resource_Proxy( $this->plugin );
		add_filter( 'https_resource_proxy_filtering_enabled', '__return_true' );

		$exception = null;
		try {
			$instance->send_proxy_response( array( 'nonce' => 'food' ) );
		} catch ( Exception $e ) {
			$exception = $e;
		}
		$this->assertInstanceOf( __NAMESPACE__ . '\\Exception', $exception );
		$this->assertEquals( 'bad_nonce', $exception->getMessage() );
	}

	/**
	 * @see HTTPS_Resource_Proxy::send_proxy_response()
	 * @see HTTPS_Resource_Proxy::handle_proxy_request()
	 */
	function test_send_proxy_response_remote_get_error() {
		$instance = new HTTPS_Resource_Proxy( $this->plugin );
		add_filter( 'https_resource_proxy_filtering_enabled', '__return_true' );

		$params = array(
			'nonce' => wp_create_nonce( HTTPS_Resource_Proxy::MODULE_SLUG ),
			'host' => 'bad.tldnotexisting',
			'path' => '/main.js',
		);
		$r = $instance->send_proxy_response( $params );

		$this->assertEquals( 400, wp_remote_retrieve_response_code( $r ) );
		$this->assertEquals( 'http_request_failed', wp_remote_retrieve_response_message( $r ) );
	}

	function filter_pre_http_request( $r ) {
		$r = array_merge(
			array(
				'response' => array(
					'code' => 200,
					'message' => 'OK',
				),
				'headers' => array(),
				'body' => '',
			),
			$r
		);
		$r['headers']['content-length'] = strlen( $r['body'] );
		add_filter( 'pre_http_request', function () use ( $r ) {
			return $r;
		} );
	}

	/**
	 * @see HTTPS_Resource_Proxy::send_proxy_response()
	 * @see HTTPS_Resource_Proxy::handle_proxy_request()
	 */
	function test_send_proxy_response_too_large() {
		$instance = new HTTPS_Resource_Proxy( $this->plugin );
		add_filter( 'https_resource_proxy_filtering_enabled', '__return_true' );

		$this->filter_pre_http_request( array(
			'body' => str_repeat( '*', $instance->config( 'max_content_length' ) + 1 ),
		) );

		$params = array(
			'nonce' => wp_create_nonce( HTTPS_Resource_Proxy::MODULE_SLUG ),
			'host' => 'example.org',
			'path' => '/main.js',
		);
		$r = $instance->send_proxy_response( $params );

		$this->assertEquals( 502, wp_remote_retrieve_response_code( $r ) );
		$this->assertEquals( 'Response Too Large', wp_remote_retrieve_response_message( $r ) );
	}

	/**
	 * @see HTTPS_Resource_Proxy::send_proxy_response()
	 * @see HTTPS_Resource_Proxy::handle_proxy_request()
	 */
	function test_send_proxy_response_successes() {
		$instance = new HTTPS_Resource_Proxy( $this->plugin );
		add_filter( 'https_resource_proxy_filtering_enabled', '__return_true' );

		$params = array(
			'nonce' => wp_create_nonce( HTTPS_Resource_Proxy::MODULE_SLUG ),
			'host' => 'example.org',
			'path' => '/main.js',
		);
		$this->filter_pre_http_request( array(
			'headers' => array(
				'content-type' => 'text/javascript',
				'etag' => '"abc123"',
				'last-modified' => gmdate( 'r', time() - 10 ),
				'expires' => gmdate( 'r', time() + 10 ),
				'x-foo' => 'bar',
			),
			'body' => 'alert( 1 );',
		) );
		$r1 = $instance->send_proxy_response( $params );
		$this->assertArrayHasKey( 'response', $r1 );
		$this->assertArrayHasKey( 'headers', $r1 );
		$this->assertArrayHasKey( 'body', $r1 );
		$this->assertEquals( 200, wp_remote_retrieve_response_code( $r1 ) );
		$this->assertNotEmpty( wp_remote_retrieve_body( $r1 ) );
		$this->assertArrayHasKey( 'etag', $r1['headers'] );
		$this->assertArrayNotHasKey( 'x-foo', $r1['headers'] );

		remove_all_filters( 'pre_http_request' );
		add_filter( 'pre_http_request', function () {
			throw new Exception( 'pre_http_request should not have been called due to transient' );
		} );
		$r2 = $instance->send_proxy_response( $params );
		$this->assertEquals( $r1, $r2 );

		$params['if_modified_since'] = $r1['headers']['last-modified'];
		$r3 = $instance->send_proxy_response( $params );
		$this->assertEquals( 304, wp_remote_retrieve_response_code( $r3 ) );
		$this->assertEmpty( wp_remote_retrieve_body( $r3 ) );

		unset( $params['if_modified_since'] );
		$params['if_none_match'] = '"abc123"';
		$r4 = $instance->send_proxy_response( $params );
		$this->assertEquals( 304, wp_remote_retrieve_response_code( $r4 ) );
	}

}
