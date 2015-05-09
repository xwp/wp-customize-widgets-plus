<?php

namespace CustomizeWidgetsPlus;

class Test_Efficient_Multidimensional_Setting_Sanitizing extends Base_Test_Case {

	/**
	 * @var \WP_Customize_Manager
	 */
	public $wp_customize_manager;

	function setUp() {
		require_once ABSPATH . WPINC . '/class-wp-customize-manager.php';
		parent::setUp();
	}

	function init_customizer() {
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );
		$this->wp_customize_manager = new \WP_Customize_Manager();
	}

	/**
	 * @see Efficient_Multidimensional_Setting_Sanitizing::__construct()
	 */
	function test_construct() {
		$this->init_customizer();
		$instance = new Efficient_Multidimensional_Setting_Sanitizing( $this->plugin, $this->wp_customize_manager );

		$this->assertEquals( 10, has_filter( 'customize_dynamic_setting_class', array( $instance, 'filter_dynamic_setting_class' ) ) );
		$this->assertNotFalse( has_action( 'wp_loaded', array( $instance, 'register_widget_instance_settings_early' ) ) );
	}

	/**
	 * @see Efficient_Multidimensional_Setting_Sanitizing::filter_dynamic_setting_class()
	 */
	function test_filter_dynamic_setting_class() {
		$this->init_customizer();
		$instance = new Efficient_Multidimensional_Setting_Sanitizing( $this->plugin, $this->wp_customize_manager );

		$setting_class = apply_filters( 'customize_dynamic_setting_class', 'WP_Customize_Setting', 'widget_text[2]' );
		$this->assertEquals( '\\' . __NAMESPACE__ . '\\WP_Customize_Widget_Setting', $setting_class );
		$this->assertEquals( $setting_class, $instance->filter_dynamic_setting_class( 'WP_Customize_Setting', 'widget_text[2]' ) );

		$this->assertEquals( 'WP_Customize_Setting', $instance->filter_dynamic_setting_class( 'WP_Customize_Setting', 'foo' ) );
	}

	/**
	 * @see Efficient_Multidimensional_Setting_Sanitizing::register_widget_instance_settings_early()
	 * @see Efficient_Multidimensional_Setting_Sanitizing::register_widget_settings()
	 */
	function test_register_widget_instance_settings_early() {
		$this->init_customizer();
		$instance = new Efficient_Multidimensional_Setting_Sanitizing( $this->plugin, $this->wp_customize_manager );
		wp_widgets_init();

		$instance->register_widget_instance_settings_early();
		$this->wp_customize_manager->wp_loaded();
		$wp = new \WP();
		$wp->main();

		foreach ( $this->wp_customize_manager->settings() as $setting_id => $setting ) {
			if ( preg_match( '/^widget_/', $setting_id ) ) {
				$this->assertInstanceOf( __NAMESPACE__ . '\\WP_Customize_Widget_Setting', $setting );
			}
		}
	}
}
