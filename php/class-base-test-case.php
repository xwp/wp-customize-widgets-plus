<?php

namespace CustomizeWidgetsPlus;

abstract class Base_Test_Case extends \WP_UnitTestCase {

	/**
	 * @var Plugin
	 */
	public $plugin;

	function setUp() {
		$this->plugin = get_plugin_instance();
		remove_action( 'widgets_init', 'twentyfourteen_widgets_init' );
		remove_action( 'customize_register', 'twentyfourteen_customize_register' );
		remove_all_actions( 'send_headers' ); // prevent X-hacker header in VIP Quickstart
		parent::setUp();
	}

	function clean_up_global_scope() {
		global $wp_registered_sidebars, $wp_registered_widgets, $wp_registered_widget_controls, $wp_registered_widget_updates, $wp_post_types;
		// @codingStandardsIgnoreStart
		$wp_registered_sidebars = $wp_registered_widgets = $wp_registered_widget_controls = $wp_registered_widget_updates = array();
		// @codingStandardsIgnoreEnd
		$this->plugin->widget_factory->widgets = array();
		unset( $wp_post_types[ Widget_Posts::INSTANCE_POST_TYPE ] );
		parent::clean_up_global_scope();
	}

}
