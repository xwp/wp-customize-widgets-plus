<?php

namespace CustomizeWidgetsPlus;

/**
 * Settings for multidimensional options and theme_mods are extremely inefficient
 * to sanitize in Core because all of the Customizer settings registered for
 * the subsets of the option or theme_mod need filters that are added to the
 * entire option or theme mod, meaning sanitizing one single setting will result
 * in all filters for all other settings in that option/theme_mod will also get
 * applied. This functionality seeks to improve this as much as possible,
 * especially for widgets which are the worst offenders.
 *
 * @link https://core.trac.wordpress.org/ticket/32103
 * @package CustomizeWidgetsPlus
 */
class Efficient_Multidimensional_Setting_Sanitizing {

	const WIDGET_SETTING_ID_PATTERN = '/^(?P<customize_id_base>widget_(?P<widget_id_base>.+?))\[(?P<widget_number>\d+)\]$/';

	/**
	 * @var Plugin
	 */
	public $plugin;

	/**
	 * @var \WP_Customize_Manager
	 */
	public $manager;

	/**
	 * @param Plugin $plugin
	 */
	function __construct( Plugin $plugin ) {
		global $wp_customize;
		$this->plugin = $plugin;

		if ( empty( $wp_customize ) ) {
			return;
		}
		$this->manager = $wp_customize;

		add_filter( 'customize_dynamic_setting_class', array( $this, 'filter_dynamic_setting_class' ), 10, 2 );

		$priority = has_action( 'wp_loaded', array( $this->manager, 'wp_loaded' ) );
		$priority -= 1;
		add_action( 'wp_loaded', array( $this, 'register_widget_instance_settings_early' ), $priority );
	}

	/**
	 * Ensure that dynamic settings for widgets use the proper class.
	 *
	 * @see WP_Customize_Widget_Setting
	 * @param string $setting_class WP_Customize_Setting or a subclass.
	 * @param string $setting_id    ID for dynamic setting, usually coming from `$_POST['customized']`.
	 * @return string
	 */
	function filter_dynamic_setting_class( $setting_class, $setting_id ) {
		if ( preg_match( self::WIDGET_SETTING_ID_PATTERN, $setting_id ) ) {
			$setting_class = '\\' . __NAMESPACE__ . '\\WP_Customize_Widget_Setting';
		}
		return $setting_class;
	}

	/**
	 * This must happen immediately before \WP_Customize_Widgets::customize_register(),
	 * in whatever scenario it gets called per \WP_Customize_Widgets::schedule_customize_register()
	 *
	 * @action wp_loaded
	 * @see \WP_Customize_Widgets::schedule_customize_register()
	 * @see \WP_Customize_Manager::customize_register()
	 */
	function register_widget_instance_settings_early() {
		// Register any widgets that have been registered thus far
		$this->register_widget_settings();

		// Register any remaining widgets that are registered after WP is initialized
		$priority = 9; // Before \WP_Customize_Manager::customize_register() is called
		add_action( 'wp', array( $this, 'register_widget_settings' ), $priority );
	}

	/**
	 * Register widget settings just in time.
	 *
	 * This is called right before customize_widgets action, so we be first in
	 * line to regiser the widget settings, so we can use WP_Customize_Widget_Setting()
	 * as opposed to the default setting. Core needs a way to filter the setting
	 * class used when settings are created, as has been done for customize_dynamic_setting_class.
	 *
	 * @action wp_loaded
	 * @see \WP_Customize_Widgets::customize_register()
	 */
	function register_widget_settings() {
		global $wp_registered_widgets;
		if ( empty( $wp_registered_widgets ) ) {
			return;
		}

		/*
		 * Register a setting for all widgets, including those which are active,
		 * inactive, and orphaned since a widget may get suppressed from a sidebar
		 * via a plugin (like Widget Visibility).
		 */
		foreach ( array_keys( $wp_registered_widgets ) as $widget_id ) {
			$setting_id = $this->manager->widgets->get_setting_id( $widget_id );
			if ( ! $this->manager->get_setting( $setting_id ) ) {
				$setting_args = $this->manager->widgets->get_setting_args( $setting_id );
				$setting = new WP_Customize_Widget_Setting( $this->manager, $setting_id, $setting_args );
				$this->manager->add_setting( $setting );
			}
			$new_setting_ids[] = $setting_id;
		}
	}
}
