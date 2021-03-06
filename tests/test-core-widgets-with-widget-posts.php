<?php

namespace CustomizeWidgetsPlus;

if ( ! getenv( 'WP_TESTS_DIR' ) ) {
	throw new \Exception( 'WP_TESTS_DIR env var is empty; expected to point to {develop.svn.wordpress.org}/tests/phpunit/' );
}
require_once getenv( 'WP_TESTS_DIR' ) . '/tests/widgets.php';

/**
 * @group customize-widgets-plus
 */
class Test_Core_With_Widget_Posts extends \Tests_Widgets {

	/**
	 * @var Plugin
	 */
	public $plugin;

	/**
	 * @var int
	 */
	protected $css_concat_init_priority;

	/**
	 * @var int
	 */
	protected $js_concat_init_priority;

	function setUp() {
		global $wp_widget_factory;

		// For why these hooks have to be removed, see https://github.com/Automattic/nginx-http-concat/issues/5
		$this->css_concat_init_priority = has_action( 'init', 'css_concat_init' );
		if ( $this->css_concat_init_priority ) {
			remove_action( 'init', 'css_concat_init', $this->css_concat_init_priority );
		}
		$this->js_concat_init_priority = has_action( 'init', 'js_concat_init' );
		if ( $this->js_concat_init_priority ) {
			remove_action( 'init', 'js_concat_init', $this->js_concat_init_priority );
		}

		parent::setUp();

		$this->plugin = new Plugin();
		$this->plugin->widget_factory = $wp_widget_factory;
		$this->plugin->widget_number_incrementing = new Widget_Number_Incrementing( $this->plugin );
		$this->plugin->widget_posts = new Widget_Posts( $this->plugin );

		$widgets_init_hook = 'widgets_init';
		$callable = array( $wp_widget_factory, '_register_widgets' );
		$priority = has_action( $widgets_init_hook, $callable );
		if ( false !== $priority ) {
			remove_action( $widgets_init_hook, $callable, $priority );
		}
		wp_widgets_init();
		if ( false !== $priority ) {
			add_action( $widgets_init_hook, $callable, $priority );
		}

		$this->plugin->widget_posts->migrate_widgets_from_options();
		$this->plugin->widget_posts->init();
		$this->plugin->widget_posts->prepare_widget_data(); // Has to be called here because of wp_widgets_init() footwork done above.
		$this->plugin->widget_posts->register_instance_post_type(); // Normally called at init action.
	}

	/**
	 * @see \Tests_Widgets::test_wp_widget_get_settings()
	 * @link https://github.com/xwp/wordpress-develop/pull/85
	 */
	function test_wp_widget_get_settings() {
		if ( ! method_exists( '\Tests_Widgets', 'test_wp_widget_get_settings' ) ) {
			$this->markTestSkipped( 'Test requires Core patch from https://github.com/xwp/wordpress-develop/pull/85 to be applied.' );
			return;
		}

		add_filter( 'pre_option_widget_search', function( $value ) {
			$this->assertNotFalse( $value, 'Expected widget_search option to have been short-circuited.' );
			return $value;
		}, 1000 );

		parent::test_wp_widget_get_settings();
	}

	/**
	 * @see \Tests_Widgets::test_wp_widget_save_settings()
	 * @link https://github.com/xwp/wordpress-develop/pull/85
	 */
	function test_wp_widget_save_settings() {
		if ( ! method_exists( '\Tests_Widgets', 'test_wp_widget_save_settings' ) ) {
			$this->markTestSkipped( 'Test requires Core patch from https://github.com/xwp/wordpress-develop/pull/85 to be applied.' );
			return;
		}

		parent::test_wp_widget_save_settings();

		$this->assertEquals( 0, did_action( 'update_option_widget_search' ), 'Expected update_option( "widget_meta" ) to short-circuit.' );
	}

	/**
	 * @see \Tests_Widgets::test_wp_widget_save_settings_delete()
	 * @link https://github.com/xwp/wordpress-develop/pull/85
	 */
	function test_wp_widget_save_settings_delete() {
		if ( ! method_exists( '\Tests_Widgets', 'test_wp_widget_save_settings_delete' ) ) {
			$this->markTestSkipped( 'Test requires Core patch from https://github.com/xwp/wordpress-develop/pull/85 to be applied.' );
			return;
		}

		$deleted_widget_id = null;
		add_action( 'delete_post', function( $post_id ) use ( &$deleted_widget_id ) {
			$post = get_post( $post_id );
			$this->assertContains( $post->post_type, array( Widget_Posts::INSTANCE_POST_TYPE, 'revision' ) );
			$deleted_widget_id = $post->post_name;
		}, 10, 2 );

		parent::test_wp_widget_save_settings_delete();

		$this->assertEquals( 'search-2', $deleted_widget_id );
	}

	/**
	 * Test that registering a widget class and registering a widget instance work together.
	 */
	function test_register_and_unregister_widget_instance() {
		global $wp_widget_factory, $wp_registered_widgets;
		$wp_widget_factory->widgets = $wp_registered_widgets = array(); // WPCS: Global override ok.
		parent::test_register_and_unregister_widget_instance();
	}

	function tearDown() {
		parent::tearDown();
		if ( $this->css_concat_init_priority ) {
			add_action( 'init', 'css_concat_init', $this->css_concat_init_priority );
		}
		if ( $this->js_concat_init_priority ) {
			add_action( 'init', 'js_concat_init', $this->js_concat_init_priority );
		}
	}
}
