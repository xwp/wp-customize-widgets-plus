<?php

namespace CustomizeWidgetsPlus;

if ( ! getenv( 'WP_TESTS_DIR' ) ) {
	throw new \Exception( 'WP_TESTS_DIR env var is empty; expected to point to {develop.svn.wordpress.org}/tests/phpunit/' );
}
require_once getenv( 'WP_TESTS_DIR' ) . '/tests/customize/widgets.php';

/**
 * @group customize-widgets-plus
 */
class Test_Core_Customize_Widgets_With_Widget_Posts extends \Tests_WP_Customize_Widgets {

	/**
	 * @var Widget_Posts
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

	protected $backup_registered_widget_controls;
	protected $backup_registered_widget_updates;

	/**
	 * Set up.
	 */
	function setUp() {
		global $wp_widget_factory, $wp_registered_widget_updates, $wp_registered_widget_controls, $wp_registered_widgets;

		// For why these hooks have to be removed, see https://github.com/Automattic/nginx-http-concat/issues/5
		$this->css_concat_init_priority = has_action( 'init', 'css_concat_init' );
		if ( $this->css_concat_init_priority ) {
			remove_action( 'init', 'css_concat_init', $this->css_concat_init_priority );
		}
		$this->js_concat_init_priority = has_action( 'init', 'js_concat_init' );
		if ( $this->js_concat_init_priority ) {
			remove_action( 'init', 'js_concat_init', $this->js_concat_init_priority );
		}

		$wp_registered_widget_updates = array();
		$wp_registered_widget_controls = array();
		$wp_registered_widgets = array();
		wp_widgets_init();

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

		$this->plugin->widget_posts->enable();
		$this->plugin->widget_posts->migrate_widgets_from_options();
		$this->plugin->widget_posts->init();
		$this->plugin->widget_posts->prepare_widget_data(); // Has to be called here because of wp_widgets_init() footwork done above.
		$this->plugin->widget_posts->register_instance_post_type(); // Normally called at init action.
		$this->plugin->widget_posts->capture_widget_settings_for_customizer(); // Normally called in widgets_init
	}

	function test_register_widget() {
		global $wp_registered_widget_updates, $wp_registered_widget_controls, $wp_registered_widgets;
		$wp_registered_widgets = array();
		$wp_registered_widget_updates = array();
		$wp_registered_widget_controls = array();

		register_widget( 'WP_Widget_Search' );
		wp_widgets_init();
		$this->assertArrayHasKey( 'search-2', $wp_registered_widgets );
		$this->assertArrayHasKey( 'search', $wp_registered_widget_updates );
		$this->assertArrayHasKey( 'search-2', $wp_registered_widget_controls );
	}

	function test_register_settings() {
		parent::test_register_settings();
		$this->assertInstanceOf( __NAMESPACE__ . '\\WP_Customize_Widget_Setting', $this->manager->get_setting( 'widget_categories[2]' ) );
		$this->assertEquals( 'widget', $this->manager->get_setting( 'widget_categories[2]' )->type );

		$this->assertInstanceOf( __NAMESPACE__ . '\\Widget_Settings', get_option( 'widget_categories' ) );
	}

	/**
	 * Test test_customize_register_with_deleted_sidebars.
	 */
	function test_customize_register_with_deleted_sidebars() {
		global $wp_registered_sidebars;
		$wp_registered_sidebars = array(); // WPCS: Global override OK.
		parent::test_customize_register_with_deleted_sidebars();
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
