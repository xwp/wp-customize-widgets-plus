<?php

namespace CustomizeWidgetsPlus;

class Test_Non_Autoloaded_Widget_Options extends Base_Test_Case {

	/**
	 * @var $wpdb
	 */
	public $wpdb;

	function setUp() {
		$this->wpdb = $GLOBALS['wpdb'];
		require_once __DIR__ . '/data/class-acme-widget.php';

		parent::setUp();
		$this->assertEmpty( $this->plugin->widget_factory->widgets );
	}

	/**
	 * @see Non_Autoloaded_Widget_Options::__construct()
	 */
	function test_construct() {
		$instance = new Non_Autoloaded_Widget_Options( $this->plugin );
		$this->assertEquals( 90, has_action( 'widgets_init', array( $instance, 'fix_widget_options' ) ) );
	}

	/**
	 * @see Non_Autoloaded_Widget_Options::fix_widget_options()
	 */
	function test_fix_widget_options_for_previously_registered_widget() {
		wp_widgets_init();
		$wpdb = $this->wpdb;

		$registered_option_names = wp_list_pluck( $this->plugin->get_registered_widget_objects(), 'option_name' );
		$sql_option_names_in = join( ',', array_fill( 0, count( $registered_option_names ), '%s' ) );
		$unautoloaded_widget_option_count = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $wpdb->options WHERE autoload = 'no' AND option_name IN ( $sql_option_names_in )", $registered_option_names ) ); // db call okay; cache okay
		$this->assertEquals( 0, $unautoloaded_widget_option_count );

		$autoloaded_option_names = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM $wpdb->options WHERE autoload = 'yes' AND option_name IN ( $sql_option_names_in )", $registered_option_names ) ); // db call okay; cache okay
		$this->assertEmpty( array_diff( $autoloaded_option_names, $registered_option_names ), 'Expected autoloaded options to be subset of registered options.' );

		$this->plugin->non_autoloaded_widget_options = new Non_Autoloaded_Widget_Options( $this->plugin );
		$this->plugin->non_autoloaded_widget_options->fix_widget_options();

		$unautoloaded_widget_option_count = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $wpdb->options WHERE autoload = 'no' AND option_name IN ( $sql_option_names_in )", $registered_option_names ) ); // db call okay; cache okay
		$this->assertGreaterThan( 0, $unautoloaded_widget_option_count );
	}

	/**
	 * @see Non_Autoloaded_Widget_Options::fix_widget_options()
	 */
	function test_fix_widget_options_for_newly_registered_widget() {

		$this->plugin->non_autoloaded_widget_options = new Non_Autoloaded_Widget_Options( $this->plugin );
		$this->plugin->non_autoloaded_widget_options->fix_widget_options();

		add_action( 'widgets_init', function () {
			register_widget( __NAMESPACE__ . '\\Acme_Widget' );
		} );
		wp_widgets_init();
		$wpdb = $this->wpdb;

		$autoload = $wpdb->get_var( "SELECT autoload FROM $wpdb->options WHERE autoload = 'no' AND option_name = 'widget_acme'" ); // db call okay; cache okay
		$this->assertEquals( 'no', $autoload );
	}
}
