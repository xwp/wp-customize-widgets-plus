<?php

namespace CustomizeWidgetsPlus;

/**
 * When FORCE_SSL_ADMIN is enabled (such as on WordPress.com), the Customizer
 * will load the site into the preview iframe using HTTPS as well. If, however,
 * external resources are being referenced which are not HTTPS, they will fail
 * to load due to the browser's security model raise mixed content warnings.
 * This functionality will attempt to rewrite any HTTP URLs to be HTTPS ones
 * via a WordPress-based proxy.
 *
 * @link https://github.com/xwp/wp-customize-widgets-plus/issues/4
 * @package CustomizeWidgetsPlus
 */


class HTTPS_Resource_Proxy {

	const MODULE_SLUG = 'https_resource_proxy';

	const NONCE_QUERY_VAR = 'https_resource_proxy_nonce';

	const HOST_QUERY_VAR = 'https_resource_proxy_host';

	const PATH_QUERY_VAR = 'https_resource_proxy_path';

	const REGEX_DELIMITER = '#';

	/**
	 * @param Plugin $plugin
	 */
	public $plugin;

	/**
	 * @var string
	 */
	public $rewrite_regex;

	/**
	 * @param Plugin $plugin
	 */
	function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;

		add_action( 'init', array( $this, 'add_rewrite_rule' ) );
		add_filter( 'query_vars', array( $this, 'filter_query_vars' ) );
		add_filter( 'redirect_canonical', array( $this, 'enforce_trailingslashing' ) );
		add_action( 'template_redirect', array( $this, 'handle_proxy_request' ) );
		add_action( 'init', array( $this, 'add_proxy_filtering' ) );
		add_filter( 'wp_unique_post_slug_is_bad_flat_slug', array( $this, 'reserve_api_endpoint' ), 10, 2 );
		add_filter( 'wp_unique_post_slug_is_bad_hierarchical_slug', array( $this, 'reserve_api_endpoint' ), 10, 2 );
	}

	/**
	 * @return array
	 */
	static function default_config() {
		return array(
			'endpoint' => 'wp-https-resource-proxy',
			'min_cache_ttl' => 5 * MINUTE_IN_SECONDS,
			'customize_preview_only' => true,
			'logged_in_users_only' => true,
			'request_timeout' => 3,
			'trailingslash_srcs' => true, // web server configs may be configured to route apparent static file requests to 404 handler
			'max_content_length' => 768 * 1024, // guard against 1MB Memcached Object Cache limit, so body + serialized request metadata
		);
	}

	/**
	 * Return the config entry for the supplied key, or all configs if not supplied.
	 *
	 * @param string $key
	 * @return array|mixed
	 */
	function config( $key = null ) {
		if ( is_null( $key ) ) {
			return $this->plugin->config[ self::MODULE_SLUG ];
		} else if ( isset( $this->plugin->config[ self::MODULE_SLUG ][ $key ] ) ) {
			return $this->plugin->config[ self::MODULE_SLUG ][ $key ];
		} else {
			return null;
		}
	}

	/**
	 * Return whether the proxy is enabled.
	 *
	 * @return bool
	 */
	function is_proxy_enabled() {
		$enabled = (
			is_ssl()
			&&
			! is_admin()
			&&
			( is_customize_preview() || ! $this->config( 'customize_preview_only' ) )
			&&
			( is_user_logged_in() || ! $this->config( 'logged_in_users_only' ) )
		);
		return apply_filters( 'https_resource_proxy_filtering_enabled', $enabled, $this );
	}

	/**
	 * Add the filters for injecting the functionality into the page.
	 *
	 * @action init
	 */
	function add_proxy_filtering() {
		if ( ! $this->is_proxy_enabled() ) {
			return;
		}

		nocache_headers(); // we don't want to cache the resource URLs containing the nonces
		add_filter( 'script_loader_src', array( $this, 'filter_script_loader_src' ) );
		add_filter( 'style_loader_src', array( $this, 'filter_style_loader_src' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_scripts' ) );

		/*
		 * On WordPress.com, prevent hostname in assets from being replaced with
		 * the WPCOM CDN (e.g. w1.wp.com) as then the assets will 404.
		 */
		if ( $this->plugin->is_wpcom_vip_prod() ) {
			remove_filter( 'style_loader_src', 'staticize_subdomain' );
			remove_filter( 'script_loader_src', 'staticize_subdomain' );
		}
	}

	/**
	 * @filter query_vars
	 * @param $query_vars
	 *
	 * @return array
	 */
	function filter_query_vars( $query_vars ) {
		$query_vars[] = self::NONCE_QUERY_VAR;
		$query_vars[] = self::HOST_QUERY_VAR;
		$query_vars[] = self::PATH_QUERY_VAR;
		return $query_vars;
	}

	/**
	 * @action init
	 */
	function add_rewrite_rule() {
		$this->rewrite_regex = preg_quote( $this->config( 'endpoint' ), self::REGEX_DELIMITER ) . '/(?P<nonce>\w+)/(?P<host>[^/]+)(?P<path>/.+)';

		$redirect_vars = array(
			self::NONCE_QUERY_VAR => '$matches[1]',
			self::HOST_QUERY_VAR => '$matches[2]',
			self::PATH_QUERY_VAR => '$matches[3]',
		);
		$redirect_var_pairs = array();
		foreach ( $redirect_vars as $name => $value ) {
			$redirect_var_pairs[] = $name . '=' . $value;
		}
		$redirect = 'index.php?' . join( '&', $redirect_var_pairs );

		add_rewrite_rule( $this->rewrite_regex, $redirect, 'top' );
	}

	/**
	 * Reserve the API endpoint slugs.
	 *
	 * @param bool $is_bad Whether a post slug is available for use or not.
	 * @param string $slug The post's slug.
	 *
	 * @return bool
	 *
	 * @filter wp_unique_post_slug_is_bad_flat_slug
	 * @filter wp_unique_post_slug_is_bad_hierarchical_slug
	 */
	public function reserve_api_endpoint( $is_bad, $slug ) {
		if ( $this->config( 'endpoint' ) === $slug ) {
			$is_bad = true;
		}
		return $is_bad;
	}

	/**
	 * @filter script_loader_src
	 * @param $src
	 * @return string
	 */
	function filter_script_loader_src( $src ) {
		return $this->filter_loader_src( $src );
	}

	/**
	 * @filter style_loader_src
	 * @param $src
	 * @return string
	 */
	function filter_style_loader_src( $src ) {
		return $this->filter_loader_src( $src );
	}

	/**
	 * @return string
	 */
	function get_base_url() {
		$proxied_src = trailingslashit( site_url( $this->config( 'endpoint' ) ) );
		$proxied_src .= trailingslashit( wp_create_nonce( self::MODULE_SLUG ) );
		return $proxied_src;
	}

	/**
	 * Rewrite asset URLs to use the proxy when appropriate.
	 *
	 * @param string $src
	 * @return string
	 */
	function filter_loader_src( $src ) {
		if ( ! isset( $this->rewrite_regex ) ) {
			$this->add_rewrite_rule();
		}

		$parsed_url = parse_url( $src );
		$regex = self::REGEX_DELIMITER . $this->rewrite_regex . self::REGEX_DELIMITER;
		$should_filter = (
			isset( $parsed_url['scheme'] )
			&&
			'http' === $parsed_url['scheme']
			&&
			! preg_match( $regex, parse_url( $src, PHP_URL_PATH ) ) // prevent applying regex more than once
		);
		if ( $should_filter ) {
			$proxied_src = $this->get_base_url();
			$proxied_src .= $parsed_url['host'];
			$proxied_src .= $parsed_url['path'];

			/*
			 * Now we trailingslash to account for web server configs that try
			 * to optimize requests to non-existing static assets by sending
			 * them straight to 404 instead of sending them to the WP router.
			 */
			if ( $this->config( 'trailingslash_srcs' ) ) {
				$proxied_src = trailingslashit( $proxied_src );
			}

			if ( ! empty( $parsed_url['query'] ) ) {
				$proxied_src .= '?' . $parsed_url['query'];
			}
			$src = $proxied_src;
		}
		return $src;
	}

	/**
	 * @action wp_enqueue_scripts
	 */
	function enqueue_scripts() {
		$data = array(
			'baseUrl' => $this->get_base_url(),
			'trailingslashSrcs' => $this->config( 'trailingslash_srcs' ),
		);

		wp_scripts()->add_data(
			$this->plugin->script_handles['https-resource-proxy'],
			'data',
			sprintf( 'var _httpsResourceProxyExports = %s', wp_json_encode( $data ) )
		);
		wp_enqueue_script( $this->plugin->script_handles['https-resource-proxy'] );
	}

	/**
	 * Enforce trailingslashing of proxied resource URLs.
	 *
	 * @filter redirect_canonical
	 * @param string $redirect_url
	 * @return string
	 */
	function enforce_trailingslashing( $redirect_url ) {
		if ( get_query_var( self::PATH_QUERY_VAR ) ) {
			if ( $this->config( 'trailingslash_srcs' ) ) {
				if ( false === strpos( $redirect_url, '?' ) ) {
					$redirect_url = trailingslashit( $redirect_url );
				} else {
					$redirect_url = preg_replace( '#(?<=[^/])(?=\?)#', '/', $redirect_url );
				}
			} else {
				$redirect_url = preg_replace( '#/(?=\?|$)#', '', $redirect_url );
			}
		}
		return $redirect_url;
	}

	/**
	 * Interrupt WP execution and serve response if called for.
	 *
	 * @see HTTPS_Resource_Proxy::send_proxy_response()
	 *
	 * @action template_redirect
	 */
	function handle_proxy_request() {
		$is_request = ( get_query_var( self::NONCE_QUERY_VAR ) && get_query_var( self::HOST_QUERY_VAR ) && get_query_var( self::PATH_QUERY_VAR ) );
		if ( ! $is_request ) {
			return;
		}
		$params = array(
			'nonce' => get_query_var( self::NONCE_QUERY_VAR ),
			'host' => get_query_var( self::HOST_QUERY_VAR ),
			'path' => get_query_var( self::PATH_QUERY_VAR ),
			'query' => null,
			'if_none_match' => null,
			'if_modified_since' => null,
		);
		if ( ! empty( $_SERVER['QUERY_STRING'] ) ) { // input var okay
			$params['query'] = wp_unslash( $_SERVER['QUERY_STRING'] ); // input var okay; sanitization okay
		}
		if ( isset( $_SERVER['HTTP_IF_MODIFIED_SINCE'] ) ) { // input var okay
			$params['if_modified_since'] = wp_unslash( $_SERVER['HTTP_IF_MODIFIED_SINCE'] ); // input var okay; sanitization okay
		}
		if ( isset( $_SERVER['HTTP_IF_NONE_MATCH'] ) ) { // input var okay
			$params['if_none_match'] = wp_unslash( $_SERVER['HTTP_IF_NONE_MATCH'] ); // input var okay; sanitization okay
		}

		try {
			$r = $this->send_proxy_response( $params );

			$code = wp_remote_retrieve_response_code( $r );
			$message = wp_remote_retrieve_response_message( $r );
			if ( empty( $message ) ) {
				$message = get_status_header_desc( $code );
			}
			$protocol = isset( $_SERVER['SERVER_PROTOCOL'] ) ? $_SERVER['SERVER_PROTOCOL'] : null; // input var okay; sanitization okay
			if ( 'HTTP/1.1' !== $protocol && 'HTTP/1.0' !== $protocol ) {
				$protocol = 'HTTP/1.0';
			}
			$status_header = "$protocol $code $message";
			header( $status_header, true, $code );

			// Remove headers added by nocache_headers()
			foreach ( array_keys( wp_get_nocache_headers() ) as $name ) {
				header_remove( $name );
			}
			foreach ( $r['headers'] as $name => $value ) {
				header( "$name: $value" );
			}
			if ( 304 !== $code ) {
				echo wp_remote_retrieve_body( $r ); // xss ok (we're passing things through on purpose)
			}
			die();
		} catch ( Exception $e ) {
			$code = $e->getCode();
			if ( $code < 400 || $code >= 600 ) {
				$code = $e->getCode();
			}
			status_header( $code );
			die( esc_html( $e->getMessage() ) );
		}
	}

	/**
	 * Formulate the response based on the request $params.
	 *
	 * @param array $params {
	 *     @type string $nonce
	 *     @type string $host
	 *     @type string $path
	 *     @type string $query
	 *     @type string $if_none_match
	 *     @type string $if_modified_since
	 * }
	 * @return array $r {
	 *     @type array $response {
	 *         @type int $code
	 *         @type string $message
	 *     }
	 *     @type array $headers
	 *     @type string $body
	 * }
	 *
	 * @throws Exception
	 */
	function send_proxy_response( array $params ) {
		$params = array_merge(
			array_fill_keys( array( 'nonce', 'host', 'path', 'query', 'if_none_match', 'if_modified_since' ), null ),
			$params
		);

		if ( ! $this->is_proxy_enabled() ) {
			throw new Exception( 'proxy_not_enabled', 401 );
		}
		if ( ! wp_verify_nonce( $params['nonce'], self::MODULE_SLUG ) ) {
			throw new Exception( 'bad_nonce', 403 );
		}

		// Construct the proxy URL for the resource
		$url = 'http://' . $params['host'] . $params['path'];
		if ( $params['query'] ) {
			$url .= '?' . $params['query'];
		}

		$transient_key = sprintf( 'proxied_' . md5( $url ) );
		if ( strlen( $transient_key ) > 40 ) {
			throw new Exception( 'transient_key_too_large', 500 );
		}
		$r = get_transient( $transient_key );
		if ( empty( $r ) ) {
			// @todo We eliminate transient expiration and send if-modified-since/if-none-match to server
			$timeout = $this->config( 'request_timeout' );
			if ( function_exists( 'vip_safe_wp_remote_get' ) ) {
				$fallback_value = '';
				$threshold = 3;
				$r = vip_safe_wp_remote_get( $url, $fallback_value, $threshold, $timeout );
			} else {
				$args = compact( 'timeout' );
				// @codingStandardsIgnoreStart
				$r = wp_remote_get( $url, $args );
				// @codingStandardsIgnoreEnd
			}

			if ( is_wp_error( $r ) ) {
				$r = array(
					'response' => array(
						'code' => 400,
						'message' => $r->get_error_code(),
					),
					'headers' => array(
						'content-type' => 'text/plain',
					),
					'body' => $r->get_error_message(),
				);
			}

			if ( ! isset( $r['headers']['content-length'] ) ) {
				$r['headers']['content-length'] = 0;
			}
			$r['headers']['content-length'] = max( $r['headers']['content-length'], strlen( wp_remote_retrieve_body( $r ) ) );

			if ( $r['headers']['content-length'] > $this->config( 'max_content_length' ) ) {
				$r = array(
					'response' => array(
						'code' => 502,
						'message' => 'Response Too Large',
					),
					'headers' => array(
						'content-type' => 'text/plain',
					),
					'body' => sprintf(
						__( 'Response body (content-length: %1$d) too big for HTTPS Resource Proxy (max_content_length: %2$d).', 'customize-widgets-plus' ),
						$r['headers']['content-length'],
						$this->config( 'max_content_length' )
					),
				);
			}

			if ( ! empty( $r['headers']['expires'] ) ) {
				$cache_ttl = strtotime( $r['headers']['expires'] ) - time();
			} else if ( ! empty( $r['headers']['cache-control'] ) && preg_match( '/max-age=(\d+)/', $r['headers']['cache-control'], $matches ) ) {
				$cache_ttl = intval( $matches[1] );
			} else {
				$cache_ttl = -1;
			}
			$cache_ttl = max( $cache_ttl, $this->config( 'min_cache_ttl' ) );
			$r['headers']['expires'] = str_replace( '+0000', 'GMT', gmdate( 'r', time() + $cache_ttl ) );

			// @todo in addition to the checks for whether the user is logged-in and if in customizer, should we do a check to prevent too many resources from being cached?
			set_transient( $transient_key, $r, $cache_ttl );
		}

		$is_not_modified = false;
		$response_code = wp_remote_retrieve_response_code( $r );
		$response_message = wp_remote_retrieve_response_message( $r );
		if ( 200 === $response_code ) {
			$is_etag_not_modified = (
				! empty( $params['if_none_match'] )
				&&
				isset( $r['headers']['etag'] )
				&&
				( false !== strpos( $params['if_none_match'], $r['headers']['etag'] ) )
			);

			$is_last_modified_not_modified = (
				! empty( $params['if_modified_since'] )
				&&
				isset( $r['headers']['last-modified'] )
				&&
				strtotime( $r['headers']['last-modified'] ) <= strtotime( $params['if_modified_since'] )
			);
			$is_not_modified = ( $is_etag_not_modified || $is_last_modified_not_modified );
			if ( $is_not_modified ) {
				$response_code = 304;
				$response_message = 'Not Modified';
			}
		} else {
			unset( $r['headers']['last-modified'] );
			unset( $r['headers']['etag'] );
		}

		$body = '';
		$forwarded_response_headers = array( 'content-type', 'last-modified', 'etag', 'expires' );
		$headers = wp_array_slice_assoc( $r['headers'], $forwarded_response_headers );

		if ( ! $is_not_modified ) {
			// @todo Content-Encoding deflate/gzip if requested
			$headers['content-length'] = strlen( wp_remote_retrieve_body( $r ) );
			$body = wp_remote_retrieve_body( $r );
		}

		return array(
			'response' => array(
				'code' => $response_code,
				'message' => $response_message,
			),
			'headers' => $headers,
			'body' => $body,
		);
	}

}
