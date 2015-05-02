<?php

namespace CustomizeWidgetsPlus;

abstract class Base_Test_Case extends \WP_UnitTestCase {

	/**
	 * @var Plugin
	 */
	public $plugin;

	function setUp() {
		$this->plugin = get_plugin_instance();
		parent::setUp();
	}

	function clean_up_global_scope() {
		global $wp_registered_sidebars, $wp_registered_widgets, $wp_registered_widget_controls, $wp_registered_widget_updates;
		// @codingStandardsIgnoreStart
		$wp_registered_sidebars = $wp_registered_widgets = $wp_registered_widget_controls = $wp_registered_widget_updates = array();
		// @codingStandardsIgnoreEnd
		$this->plugin->widget_factory->widgets = array();
		parent::clean_up_global_scope();
	}

}
