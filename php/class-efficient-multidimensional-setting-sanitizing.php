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
	 * All current instance data for registered widgets.
	 *
	 * @see WP_Customize_Widget_Setting::__construct()
	 * @see WP_Customize_Widget_Setting::value()
	 * @see WP_Customize_Widget_Setting::preview()
	 * @see WP_Customize_Widget_Setting::update()
	 * @see WP_Customize_Widget_Setting::filter_pre_option_widget_settings()
	 *
	 * @var array
	 */
	public $current_widget_type_values = array();

	/**
	 * Whether or not all of the filter_pre_option_widget_settings handlers should no-op.
	 *
	 * This is set to true at customize_save action, and returned to false at customize_save_after.
	 *
	 * @var bool
	 */
	public $disabled_filtering_pre_option_widget_settings = false;

	/**
	 * @var \WP_Widget[]
	 */
	public $widget_objs;

	/**
	 * @param Plugin $plugin
	 * @param \WP_Customize_Manager $manager
	 */
	function __construct( Plugin $plugin, \WP_Customize_Manager $manager ) {
		$this->plugin = $plugin;
		$this->manager = $manager;
		$this->manager->efficient_multidimensional_setting_sanitizing = $this;

		add_filter( 'customize_dynamic_setting_class', array( $this, 'filter_dynamic_setting_class' ), 10, 2 );
		$priority = 92; // Because Widget_Posts::prepare_widget_data() happens at 91.
		add_action( 'widgets_init', array( $this, 'capture_widget_instance_data' ), $priority );

		// Note that customize_register happens at wp_loaded, so we register the settings just before
		$priority = has_action( 'wp_loaded', array( $this->manager, 'wp_loaded' ) );
		$priority -= 1;
		add_action( 'wp_loaded', array( $this, 'register_widget_instance_settings_early' ), $priority );

		add_action( 'customize_save', array( $this, 'disable_filtering_pre_option_widget_settings' ) );
		add_action( 'customize_save_after', array( $this, 'enable_filtering_pre_option_widget_settings' ) );
	}

	/**
	 * Since at widgets_init,100 the single instances of widgets get copied out
	 * to the many instances in $wp_registered_widgets, we capture all of the
	 * registered widgets up front so we don't have to search through the big
	 * list later. At this time we also add the pre_option filter to use the
	 * settings as retrieved once from the database, and then manipulated in a
	 * pre-unserialized data structure ready to be re-serialized once when the
	 * Customizer setting is saved.
	 *
	 * @see WP_Customize_Widget_Setting::__construct()
	 * @see Widget_Posts::filter_pre_option_widget_settings()
	 * @see Widget_Posts::prepare_widget_data()
	 */
	function capture_widget_instance_data() {
		$is_widget_posts_enabled = (
			$this->plugin->is_module_active( 'widget_posts' )
			&&
			$this->plugin->widget_posts->is_enabled()
		);

		foreach ( $this->plugin->widget_factory->widgets as $widget_obj ) {
			/** @var \WP_Widget $widget_obj */
			$this->widget_objs[ $widget_obj->id_base ] = $widget_obj;

			/*
			 * Get widget settings once, after Widget_Posts::prepare_widget_data()
			 * has run, so we can get Widget_Settings ArrayIterators.
			 */
			$settings = $widget_obj->get_settings();

			// Sanity check.
			$expect_array_iterator = (
				$is_widget_posts_enabled
				&&
				$this->plugin->is_normal_multi_widget( $widget_obj )
			);
			if ( $expect_array_iterator && ! ( $settings instanceof Widget_Settings ) ) {
				throw new Exception( "Expected settings for $widget_obj->id_base to be a Widget_Settings instance since the Widget_Posts module is active." );
			}

			/*
			 * Restore _multiwidget array key since it gets stripped by WP_Widget::get_settings()
			 * and we're going to use this $settings data in a pre_option filter,
			 * and we want to ensure that wp_convert_widget_settings() will not be
			 * called later when WP_Widget::get_settings() is again called
			 * and does get_option( "widget_{$id_base}" ). Note that if this is
			 * a Widget_Settings instance, it wil do a no-op and offsetExists()
			 * will always return true for the '_multiwidget' key.
			 */
			$settings['_multiwidget'] = 1;

			/*
			 * Store the settings to be used by WP_Customize_Widget_Setting::value(),
			 * and in pre_option_{$id_base} filter.
			 */
			$this->current_widget_type_values[ $widget_obj->id_base ] = $settings;

			// Note that this happens _before_ Widget_Posts::filter_pre_option_widget_settings().
			add_filter( "pre_option_widget_{$widget_obj->id_base}", function ( $pre_value ) use ( $widget_obj ) {
				return $this->filter_pre_option_widget_settings( $pre_value, $widget_obj );
			} );
		}
	}

	/**
	 * Pre-option filter which intercepts the expensive WP_Customize_Setting::_preview_filter().
	 *
	 * @see WP_Customize_Setting::_preview_filter()
	 * @param null|array $pre_value Value that, if set, short-circuits the normal get_option() return value.
	 * @param \WP_Widget $widget_obj
	 * @return array
	 * @throws Exception
	 */
	function filter_pre_option_widget_settings( $pre_value, $widget_obj ) {
		if ( ! $this->disabled_filtering_pre_option_widget_settings ) {
			if ( ! isset( $this->current_widget_type_values[ $widget_obj->id_base ] ) ) {
				throw new Exception( "current_widget_type_values not set yet for $widget_obj->id_base" );
			}
			$pre_value = $this->current_widget_type_values[ $widget_obj->id_base ];
		}
		return $pre_value;
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
		if ( is_admin() ) {
			$this->register_widget_settings();
		}
		$priority = 9; // Before \WP_Customize_Manager::customize_register() is called
		add_action( 'wp', array( $this, 'register_widget_settings' ), $priority );
	}

	/**
	 * Register widget settings just in time.
	 *
	 * This is called right before customize_widgets action, so we be first in
	 * line to register the widget settings, so we can use WP_Customize_Widget_Setting()
	 * as opposed to the default setting. Core needs a way to filter the setting
	 * class used when settings are created, as has been done for customize_dynamic_setting_class.
	 *
	 * @todo Propose that WP_Customize_Manager::add_setting( $id, $args ) allow the setting class 'WP_Customize_Setting' to be filtered.
	 *
	 * @action wp_loaded
	 * @see \WP_Customize_Widgets::customize_register()
	 */
	function register_widget_settings() {
		global $wp_registered_widgets;
		if ( empty( $wp_registered_widgets ) ) {
			$this->plugin->trigger_warning( '$wp_registered_widgets is empty.' );
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

	/**
	 * @see WP_Customize_Widget_Setting::filter_pre_option_widget_settings()
	 * @action customize_save
	 */
	function disable_filtering_pre_option_widget_settings() {
		$this->disabled_filtering_pre_option_widget_settings = true;
	}

	/**
	 * @see WP_Customize_Widget_Setting::filter_pre_option_widget_settings()
	 * @action customize_save_after
	 */
	function enable_filtering_pre_option_widget_settings() {
		$this->disabled_filtering_pre_option_widget_settings = false;
	}
}
