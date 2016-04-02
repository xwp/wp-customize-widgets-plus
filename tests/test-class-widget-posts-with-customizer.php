<?php

namespace CustomizeWidgetsPlus;

/**
 * @group customize-widgets-plus
 */
class Test_Widget_Posts_With_Customizer extends Base_Test_Case {

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
		parent::setUp();

		global $wp_customize;
		require_once ABSPATH . WPINC . '/class-wp-customize-manager.php';

		$initial_search_widgets = array(
			2 => array(
				'title' => 'Search',
			),
			3 => array(
				'title' => 'Find',
			),
			'_multiwidget' => 1,
		);
		update_option( 'widget_search', $initial_search_widgets );

		$this->widget_posts = new Widget_Posts( $this->plugin );
		$this->plugin->widget_number_incrementing = new Widget_Number_Incrementing( $this->plugin );
		$this->widget_posts->init();
		wp_widgets_init();
		$this->widget_posts->register_instance_post_type();
		$this->widget_posts->migrate_widgets_from_options();

		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );
		$wp_customize = $this->customize_manager = new \WP_Customize_Manager();
		$this->assertTrue( $this->widget_posts->add_customize_hooks() ); // This did nothing in init since Customizer was not loaded.

		// Make sure the widgets get re-registered now using the new settings source.
		global $wp_registered_widgets;
		$wp_registered_widgets = array();
		wp_widgets_init();
		do_action( 'wp_loaded' );
	}

	/**
	 * @see \WP_Customize_Setting::preview()
	 */
	function test_customize_widgets_preview() {
		$initial_search_widgets = get_option( 'widget_search' );
		$previewed_setting_id = 'widget_search[2]';
		$override_widget_data = array(
			'title' => 'Buscar',
		);
		$sanitized_widget_instance = $this->customize_manager->widgets->sanitize_widget_js_instance( $override_widget_data );
		$this->customize_manager->set_post_value( $previewed_setting_id, $sanitized_widget_instance );

		$this->customize_manager->widgets->customize_register(); // This calls preview() on the WP_Widget_Setting class, after which we cannot set_post_value()

		$previewed_setting = $this->customize_manager->get_setting( 'widget_search[2]' );
		$unchanged_setting = $this->customize_manager->get_setting( 'widget_search[3]' );
		$this->assertEquals( $override_widget_data, $previewed_setting->value() );
		$this->assertEquals( $initial_search_widgets[3], $unchanged_setting->value() );
	}

	/**
	 * @see \WP_Customize_Setting::save()
	 */
	function test_customize_widgets_save() {
		$initial_search_widgets = get_option( 'widget_search' );

		$widget_id = 'search-2';
		$setting_id = 'widget_search[2]';
		$override_widget_data = array(
			'title' => 'Buscar',
		);
		$sanitized_widget_instance = $this->customize_manager->widgets->sanitize_widget_js_instance( $override_widget_data );
		$this->customize_manager->set_post_value( $setting_id, $sanitized_widget_instance );

		$widget_post = $this->widget_posts->get_widget_post( $widget_id );
		$this->assertEquals( $initial_search_widgets[2], $this->widget_posts->get_widget_instance_data( $widget_post ) );

		do_action( 'customize_save', $this->customize_manager );
		foreach ( $this->customize_manager->settings() as $setting ) {
			/** @var \WP_Customize_Setting $setting */
			$setting->save();
		}
		do_action( 'customize_save_after', $this->customize_manager );

		$saved_data = $this->widget_posts->get_widget_instance_data( $this->widget_posts->get_widget_post( $widget_id ) );
		$this->assertEquals( $override_widget_data, $saved_data, 'Overridden widget data should be saved.' );

		$this->assertEquals(
			$initial_search_widgets[3],
			$this->widget_posts->get_widget_instance_data( $this->widget_posts->get_widget_post( 'search-3' ) ),
			'Untouched widget instance should remain intact.'
		);
	}

	/**
	 * @see Widget_Posts::add_customize_hooks()
	 */
	function test_add_customize_hooks() {
		$this->assertEquals( 10, has_filter( 'customize_dynamic_setting_class', array( $this->widget_posts, 'filter_dynamic_setting_class' ) ) );
		$this->assertNotFalse( has_action( 'wp_loaded', array( $this->widget_posts, 'register_widget_instance_settings_early' ) ) );
	}

	/**
	 * @see Widget_Posts::capture_widget_settings_for_customizer()
	 * @see Widget_Posts::filter_pre_option_widget_settings()
	 * @see Widget_Posts::disable_filtering_pre_option_widget_settings()
	 * @see Widget_Posts::enable_filtering_pre_option_widget_settings()
	 */
	function test_capture_widget_settings_for_customizer() {
		$module = $this->widget_posts;
		$this->assertNotEmpty( $module->current_widget_type_values );

		foreach ( $module->current_widget_type_values as $id_base => $instances ) {
			$this->assertArrayHasKey( $id_base, $module->widget_objs );
			$widget_obj = $module->widget_objs[ $id_base ];
			$this->assertInstanceOf( __NAMESPACE__ . '\\Widget_Settings', $instances );
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
	 * @see Widget_Posts::filter_dynamic_setting_class()
	 */
	function test_filter_dynamic_setting_class() {
		$setting_class = apply_filters( 'customize_dynamic_setting_class', 'WP_Customize_Setting', 'widget_text[2]', array() );
		$this->assertEquals( '\\' . __NAMESPACE__ . '\\WP_Customize_Widget_Setting', $setting_class );
		$this->assertEquals( $setting_class, $this->widget_posts->filter_dynamic_setting_class( 'WP_Customize_Setting', 'widget_text[2]' ) );

		$this->assertEquals( 'WP_Customize_Setting', $this->widget_posts->filter_dynamic_setting_class( 'WP_Customize_Setting', 'foo' ) );
	}

	/**
	 * @see Widget_Posts::register_widget_instance_settings_early()
	 * @see Widget_Posts::register_widget_settings()
	 */
	function test_register_widget_instance_settings_early() {
		$wp = new \WP();
		$wp->main();

		foreach ( $this->customize_manager->settings() as $setting_id => $setting ) {
			if ( preg_match( '/^widget_/', $setting_id ) ) {
				$this->assertInstanceOf( __NAMESPACE__ . '\\WP_Customize_Widget_Setting', $setting );
			}
		}
	}
}
