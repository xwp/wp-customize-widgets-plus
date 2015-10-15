<?php

namespace CustomizeWidgetsPlus;

abstract class Base_Test_Case extends \WP_UnitTestCase {

	/**
	 * @var Plugin
	 */
	public $plugin;

	/**
	 * @var int
	 */
	protected $css_concat_init_priority;

	/**
	 * @var int
	 */
	protected $js_concat_init_priority;

	function setUp() {
		$this->plugin = get_plugin_instance();
		remove_action( 'widgets_init', 'twentyfourteen_widgets_init' );
		remove_action( 'customize_register', 'twentyfourteen_customize_register' );
		remove_all_actions( 'send_headers' ); // prevent X-hacker header in VIP Quickstart

		// For why these hooks have to be removed, see https://github.com/Automattic/nginx-http-concat/issues/5
		$this->css_concat_init_priority = has_action( 'init', 'css_concat_init' );
		if ( $this->css_concat_init_priority ) {
			remove_action( 'init', 'css_concat_init', $this->css_concat_init_priority );
		}
		$this->js_concat_init_priority = has_action( 'init', 'js_concat_init' );
		if ( $this->js_concat_init_priority ) {
			remove_action( 'init', 'js_concat_init', $this->js_concat_init_priority );
		}

		parent::setUp();
	}

	function clean_up_global_scope() {
		global $wp_registered_sidebars, $wp_registered_widgets, $wp_registered_widget_controls, $wp_registered_widget_updates, $wp_post_types, $wp_customize;
		// @codingStandardsIgnoreStart
		$wp_registered_sidebars = $wp_registered_widgets = $wp_registered_widget_controls = $wp_registered_widget_updates = array();
		// @codingStandardsIgnoreEnd
		$this->plugin->widget_factory->widgets = array();
		unset( $wp_post_types[ Widget_Posts::INSTANCE_POST_TYPE ] );
		$wp_customize = null;
		parent::clean_up_global_scope();
	}

	function tearDown() {
		parent::tearDown();
		if ( $this->css_concat_init_priority ) {
			add_action( 'init', 'css_concat_init', $this->css_concat_init_priority );
		}
		if ( $this->js_concat_init_priority ) {
			add_action( 'init', 'js_concat_init', $this->js_concat_init_priority );
		}
	}
}
