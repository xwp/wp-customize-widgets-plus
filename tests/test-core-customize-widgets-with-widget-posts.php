<?php

namespace CustomizeWidgetsPlus;

$_tests_dir = getenv( 'WP_TESTS_DIR' );
if ( empty( $_tests_dir ) ) {
	$_tests_dir = '/tmp/wordpress-tests-lib';
}
require_once $_tests_dir . '/tests/customize/widgets.php';

class Test_Core_Customize_Widgets_With_Widget_Posts extends \Tests_WP_Customize_Widgets {

	/**
	 * @var Widget_Posts
	 */
	public $plugin;

	function setUp() {
		global $wp_widget_factory;

		parent::setUp();
		$this->plugin = new Plugin();
		$this->plugin->widget_factory = $wp_widget_factory;
		$this->plugin->widget_number_incrementing = new Widget_Number_Incrementing( $this->plugin );
		$this->plugin->widget_posts = new Widget_Posts( $this->plugin );
		$this->plugin->efficient_multidimensional_setting_sanitizing = new Efficient_Multidimensional_Setting_Sanitizing( $this->plugin, $this->manager );

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
	}

	function test_register_settings() {
		parent::test_register_settings();
		$this->assertInstanceOf( __NAMESPACE__ . '\\WP_Customize_Widget_Setting', $this->manager->get_setting( 'widget_categories[2]' ) );
		$this->assertEquals( 'widget', $this->manager->get_setting( 'widget_categories[2]' )->type );
	}

}
