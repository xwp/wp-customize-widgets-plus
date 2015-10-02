<?php

namespace CustomizeWidgetsPlus;

class Test_WP_Customize_Widget_Setting extends Base_Test_Case {

	/**
	 * @var \WP_Customize_Manager
	 */
	public $customize_manager;

	/**
	 * @var Widget_Posts
	 */
	public $widget_posts;

	/**
	 * Set up.
	 */
	function setUp() {
		global $wp_customize;
		require_once ABSPATH . WPINC . '/class-wp-customize-manager.php';
		parent::setUp();

		$this->widget_posts = new Widget_Posts( $this->plugin );
		$this->plugin->widget_number_incrementing = new Widget_Number_Incrementing( $this->plugin );
		$this->widget_posts->init();
		wp_widgets_init();
		$this->widget_posts->register_instance_post_type();
		$this->widget_posts->migrate_widgets_from_options();

		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );
		$wp_customize = $this->customize_manager = new \WP_Customize_Manager();
		$this->widget_posts->add_customize_hooks(); // This did nothing in init since Customizer was not loaded.

		// Make sure the widgets get re-registered now using the new settings source.
		global $wp_registered_widgets;
		$wp_registered_widgets = array();
		wp_widgets_init();
		do_action( 'wp_loaded' );
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
		$id_base = 'categories';
		$sample_data = $this->get_sample_widget_instance_data( $id_base );
		$args = $this->customize_manager->widgets->get_setting_args( $sample_data['setting_id'] );
		$new_setting = new WP_Customize_Widget_Setting( $this->customize_manager, $sample_data['setting_id'], $args );
		$this->assertEquals( 'widget', $new_setting->type );
		$this->assertEquals( $id_base, $new_setting->widget_id_base );
		$this->assertEquals( $sample_data['number'], $new_setting->widget_number );
	}

	/**
	 * @see WP_Customize_Widget_Setting::value()
	 */
	function test_value() {
		$id_base = 'categories';
		$sample_data = $this->get_sample_widget_instance_data( $id_base );
		$old_setting = new \WP_Customize_Setting( $this->customize_manager, $sample_data['setting_id'], array(
			'type' => 'option',
		) );
		$args = $this->customize_manager->widgets->get_setting_args( $sample_data['setting_id'] );
		$new_setting = new WP_Customize_Widget_Setting( $this->customize_manager, $sample_data['setting_id'], $args );

		$this->assertEquals( $sample_data['instance'], $old_setting->value() );
		$this->assertEquals( $old_setting->value(), $new_setting->value() );
	}

	/**
	 * @see WP_Customize_Widget_Setting::preview()
	 */
	function test_preview() {
		$widget_data = $this->get_sample_widget_instance_data( 'archives' );

		$args = $this->customize_manager->widgets->get_setting_args( $widget_data['setting_id'] );
		$setting = new WP_Customize_Widget_Setting( $this->customize_manager, $widget_data['setting_id'], $args );
		$value = $setting->value();
		$override_title = 'BAR PREVIEWED VALUE';
		$this->assertNotEquals( $value['title'], $override_title );

		$value['title'] = $override_title;
		$this->customize_manager->set_post_value( $setting->id, $value );
		$setting->preview();
		$this->assertEquals( $value['title'], $override_title );
	}

	/**
	 * @see WP_Customize_Widget_Setting::update()
	 */
	function test_save() {
		$widget_data = $this->get_sample_widget_instance_data( 'archives' );

		$args = $this->customize_manager->widgets->get_setting_args( $widget_data['setting_id'] );
		$setting = new WP_Customize_Widget_Setting( $this->customize_manager, $widget_data['setting_id'], $args );
		$value = $setting->value();
		$override_title = 'BAR UPDATED VALUE';
		$this->assertNotEquals( $value['title'], $override_title );

		$value['title'] = $override_title;
		$this->customize_manager->set_post_value( $setting->id, $this->customize_manager->widgets->sanitize_widget_js_instance( $value ) );
		$setting->save();
		$saved_value = $setting->value();

		$this->assertEquals( $override_title, $saved_value['title'] );

		$instances = get_option( 'widget_archives' );
		$this->assertEquals( $saved_value, $instances[ $widget_data['number'] ] );
	}
}
