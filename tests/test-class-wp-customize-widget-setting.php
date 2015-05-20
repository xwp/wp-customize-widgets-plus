<?php

namespace CustomizeWidgetsPlus;

class Test_WP_Customize_Widget_Setting extends Base_Test_Case {

	/**
	 * @var \WP_Customize_Manager
	 */
	public $wp_customize_manager;

	/**
	 * @var Efficient_Multidimensional_Setting_Sanitizing
	 */
	public $efficient_multidimensional_setting_sanitizing;

	function setUp() {
		require_once ABSPATH . WPINC . '/class-wp-customize-manager.php';
		parent::setUp();
	}

	function init_customizer() {
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );
		$this->wp_customize_manager = new \WP_Customize_Manager();
		$this->efficient_multidimensional_setting_sanitizing = new Efficient_Multidimensional_Setting_Sanitizing( $this->plugin, $this->wp_customize_manager );
	}

	/**
	 * @param string $id_base
	 * @return array
	 */
	function get_sample_widget_instance_data( $id_base ) {
		$instances = get_option( "widget_{$id_base}" );
		$number = key( $instances );
		$instance = $instances[ $number ];
		$widget_id = "$id_base-$number";
		$setting_id = "widget_{$id_base}[$number]";
		$this->assertArrayHasKey( 'title', $instance );
		return compact( 'instances', 'number', 'instance', 'setting_id', 'widget_id' );
	}

	/**
	 * @see WP_Customize_Widget_Setting::__construct()
	 */
	function test_construct() {
		$this->init_customizer();
		wp_widgets_init();

		$id_base = 'categories';
		$sample_data = $this->get_sample_widget_instance_data( $id_base );
		$new_setting = new WP_Customize_Widget_Setting( $this->wp_customize_manager, $sample_data['setting_id'] );
		$this->assertEquals( 'widget', $new_setting->type );
		$this->assertEquals( $id_base, $new_setting->widget_id_base );
		$this->assertEquals( $sample_data['number'], $new_setting->widget_number );
	}

	/**
	 * @see WP_Customize_Widget_Setting::value()
	 */
	function test_value() {
		$this->init_customizer();
		wp_widgets_init();

		$id_base = 'categories';
		$sample_data = $this->get_sample_widget_instance_data( $id_base );
		$old_setting = new \WP_Customize_Setting( $this->wp_customize_manager, $sample_data['setting_id'], array(
			'type' => 'option',
		) );
		$new_setting = new WP_Customize_Widget_Setting( $this->wp_customize_manager, $sample_data['setting_id'] );

		$this->assertEquals( $sample_data['instance'], $old_setting->value() );
		$this->assertEquals( $old_setting->value(), $new_setting->value() );
	}

	/**
	 * @see WP_Customize_Widget_Setting::preview()
	 */
	function test_preview() {
		$this->init_customizer();
		wp_widgets_init();
		$widget_data = $this->get_sample_widget_instance_data( 'archives' );

		$setting = new WP_Customize_Widget_Setting( $this->wp_customize_manager, $widget_data['setting_id'] );
		$value = $setting->value();
		$override_title = 'BAR PREVIEWED VALUE';
		$this->assertNotEquals( $value['title'], $override_title );

		$value['title'] = $override_title;
		$this->wp_customize_manager->set_post_value( $setting->id, $value );
		$setting->preview();
		$this->assertEquals( $value['title'], $override_title );
	}

	/**
	 * @see WP_Customize_Widget_Setting::update()
	 */
	function test_save() {
		$this->init_customizer();
		wp_widgets_init();
		$widget_data = $this->get_sample_widget_instance_data( 'archives' );

		$setting = new WP_Customize_Widget_Setting( $this->wp_customize_manager, $widget_data['setting_id'] );
		$value = $setting->value();
		$override_title = 'BAR UPDATED VALUE';
		$this->assertNotEquals( $value['title'], $override_title );

		$value['title'] = $override_title;
		$this->wp_customize_manager->set_post_value( $setting->id, $value );
		$setting->save( $value );
		$saved_value = $setting->value();

		$this->assertEquals( $override_title, $saved_value['title'] );

		$instances = get_option( 'widget_archives' );
		$this->assertEquals( $saved_value, $instances[ $widget_data['number'] ] );
	}

}
