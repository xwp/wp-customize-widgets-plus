<?php

namespace CustomizeWidgetsPlus;

$_tests_dir = getenv( 'WP_TESTS_DIR' );
if ( empty( $_tests_dir ) ) {
	$_tests_dir = '/tmp/wordpress-tests-lib';
}
require_once $_tests_dir . '/tests/widgets.php';

class Test_Core_With_Widget_Posts extends \Tests_Widgets {

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

	function test_wp_widget_get_settings() {
		if ( ! method_exists( '\Tests_Widgets', 'test_wp_widget_get_settings' ) ) {
			$this->markTestSkipped( 'Test requires Core patch to be applied.' );
			return;
		}

		add_filter( 'pre_option_widget_search', function ( $value ) {
			$this->assertNotFalse( $value, 'Expected widget_search option to have been short-circuited.' );
			return $value;
		}, 1000 );

		parent::test_wp_widget_get_settings();
	}

	function test_wp_widget_save_settings() {
		if ( ! method_exists( '\Tests_Widgets', 'test_wp_widget_save_settings' ) ) {
			$this->markTestSkipped( 'Test requires Core patch to be applied.' );
			return;
		}

		parent::test_wp_widget_save_settings();

		$this->assertEquals( 0, did_action( 'update_option_widget_search' ), 'Expected update_option( "widget_meta" ) to short-circuit.' );
	}

}
