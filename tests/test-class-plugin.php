<?php

namespace CustomizeWidgetsPlus;

class Test_Plugin extends Base_Test_Case {

	/**
	 * @var Plugin
	 */
	public $plugin;

	function setUp() {
		$this->plugin = get_plugin_instance();
		parent::setUp();
	}

	/**
	 * @see Plugin::__construct()
	 */
	function test_construct() {
		$this->assertInternalType( 'array', $this->plugin->config );
		$this->assertArrayHasKey( 'disable_widgets_init', $this->plugin->config );
		$this->assertArrayHasKey( 'disable_widgets_factory', $this->plugin->config );
		$this->assertArrayHasKey( 'active_modules', $this->plugin->config );
		$this->assertEquals( 9, has_action( 'after_setup_theme', array( $this->plugin, 'init' ) ) );
	}

	/**
	 * @see Plugin::is_module_active()
	 */
	function test_is_module_active() {
		foreach ( $this->plugin->config['active_modules'] as $module_name => $is_active ) {
			$this->assertEquals( $is_active, $this->plugin->is_module_active( $module_name ) );
		}
	}

	/**
	 * @see Plugin::init()
	 */
	function test_init() {
		$this->plugin->init();
		$this->assertInstanceOf( 'WP_Widget_Factory', $this->plugin->widget_factory );
		$this->assertNotFalse( has_action( 'wp_default_scripts', array( $this->plugin, 'register_scripts' ) ) );
	}

	/**
	 * @see Plugin::register_scripts()
	 */
	function test_register_scripts() {
		$wp_scripts = wp_scripts();

		$handles = array(
			'customize-widgets-plus-base',
			'customize-widgets-plus-widget-number-incrementing',
			'customize-widgets-plus-widget-number-incrementing-customizer',
		);
		foreach ( $handles as $handle ) {
			$this->assertArrayHasKey( $handle, $wp_scripts->registered );
		}
	}

	/**
	 * @see Plugin::disable_widgets_init()
	 */
	function test_disable_widgets_init() {
		if ( ! has_action( 'init', 'wp_widgets_init' ) ) {
			add_action( 'init', 'wp_widgets_init', 1 );
		}
		$this->assertNotFalse( has_action( 'init', 'wp_widgets_init' ) );
		$this->plugin->disable_widgets_init();
		$this->assertFalse( has_action( 'init', 'wp_widgets_init' ) );
	}

	/**
	 * @see Plugin::disable_widgets_factory()
	 */
	function test_disable_widgets_factory() {
		$callable = array( $this->plugin->widget_factory, '_register_widgets' );
		$this->assertNotFalse( has_action( 'widgets_init', $callable ) );
		$this->plugin->disable_widgets_factory();
		$this->assertFalse( has_action( 'widgets_init', $callable ) );
	}

	/**
	 * @see Plugin::is_running_unit_tests()
	 */
	function test_is_running_unit_tests() {
		$this->assertTrue( $this->plugin->is_running_unit_tests() );
	}

	/**
	 * @see Plugin::is_normal_multi_widget()
	 */
	function test_is_normal_multi_widget() {
		$widget_obj = new \WP_Widget( 'foo', 'Foo' );
		$this->assertTrue( $this->plugin->is_normal_multi_widget( $widget_obj ) );

		$widget_obj = new \WP_Widget( 'bar', 'Bar' );
		$widget_obj->option_name = 'baz';
		$this->assertFalse( $this->plugin->is_normal_multi_widget( $widget_obj ) );

		$widget_obj = new \WP_Widget( 'baz', 'Baz' );
		$widget_obj->id_base = 'baaaaz';
		$this->assertFalse( $this->plugin->is_normal_multi_widget( $widget_obj ) );
	}

	/**
	 * @see Plugin::is_registered_multi_widget()
	 */
	function test_is_registered_multi_widget() {
		global $wp_registered_widgets;
		wp_widgets_init();

		$registered_widget = $wp_registered_widgets[ key( $wp_registered_widgets ) ];
		$this->assertTrue( $this->plugin->is_registered_multi_widget( $registered_widget ) );

		$not_multi_registered_widget = $registered_widget;
		$not_multi_registered_widget['callback'] = '__return_empty_string';
		$this->assertFalse( $this->plugin->is_registered_multi_widget( $not_multi_registered_widget ) );
	}

	/**
	 * @see Plugin::get_registered_widget_objects()
	 */
	function test_get_registered_widget_objects() {
		wp_widgets_init();
		$registered_widget_objs = $this->plugin->get_registered_widget_objects();
		$this->assertInternalType( 'array', $registered_widget_objs );
		foreach ( $registered_widget_objs as $key => $registered_widget_obj ) {
			$this->assertInstanceOf( 'WP_Widget', $registered_widget_obj );
			$this->assertEquals( $registered_widget_obj->id_base, $key );
		}
	}

	/**
	 * @see Plugin::is_recognized_widget_id_base()
	 */
	function test_is_recognized_widget_id_base() {
		wp_widgets_init();
		$id_bases = array_keys( $this->plugin->get_registered_widget_objects() );
		foreach ( $id_bases as $id_base ) {
			$this->assertTrue( $this->plugin->is_recognized_widget_id_base( $id_base ) );
		}
		$this->assertFalse( $this->plugin->is_recognized_widget_id_base( 'bad' ) );
	}

	/**
	 * @see Plugin::parse_widget_id()
	 */
	function test_parse_widget_id() {
		$this->assertNull( $this->plugin->parse_widget_id( 'foo' ) );
		$parsed = $this->plugin->parse_widget_id( 'bar-3' );
		$this->assertInternalType( 'array', $parsed );
		$this->assertEquals( 3, $parsed['widget_number'] );
		$this->assertEquals( 'bar', $parsed['id_base'] );
	}

}
