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
	 * @see Efficient_Multidimensional_Setting_Sanitizing::capture_widget_instance_data()
	 * @see Efficient_Multidimensional_Setting_Sanitizing::filter_pre_option_widget_settings()
	 * @see Efficient_Multidimensional_Setting_Sanitizing::disable_filtering_pre_option_widget_settings()
	 * @see Efficient_Multidimensional_Setting_Sanitizing::enable_filtering_pre_option_widget_settings()
	 */
	function test_capture_widget_instance_data() {
		$is_widget_posts_enabled = (
			$this->plugin->is_module_active( 'widget_posts' )
			&&
			$this->plugin->widget_posts->is_enabled()
		);
		$this->assertFalse( $is_widget_posts_enabled );

		$this->init_customizer();
		$module = new Efficient_Multidimensional_Setting_Sanitizing( $this->plugin, $this->wp_customize_manager );
		$this->assertEmpty( $module->current_widget_type_values );
		wp_widgets_init();
		$this->assertNotEmpty( $module->current_widget_type_values );

		foreach ( $module->current_widget_type_values as $id_base => $instances ) {
			$this->assertArrayHasKey( $id_base, $module->widget_objs );
			$widget_obj = $module->widget_objs[ $id_base ];
			$this->assertInternalType( 'array', $instances );
			$this->assertArrayHasKey( '_multiwidget', $instances );

			$this->assertEquals( true, has_filter( "pre_option_widget_{$id_base}" ) );
			$this->assertEquals( $instances, get_option( "widget_{$id_base}" ) );

			unset( $instances['_multiwidget'] );
			$settings = $widget_obj->get_settings();
			$this->assertEquals( $instances, $settings );

			$instances[100] = array( 'text' => 'Hello World' );
			$module->current_widget_type_values[ $id_base ] = $instances;
			$settings = $widget_obj->get_settings();
			$this->assertArrayHasKey( 100, $settings );
			$this->assertEquals( $instances[100], $settings[100] );

			$module->disable_filtering_pre_option_widget_settings();
			$settings = $widget_obj->get_settings();
			$this->assertArrayNotHasKey( 100, $settings );

			$module->enable_filtering_pre_option_widget_settings();
			$this->assertArrayHasKey( 100, $widget_obj->get_settings() );
		}
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
