<?php

namespace CustomizeWidgetsPlus;

/**
 * Main plugin bootstrap file.
 */
class Plugin extends Plugin_Base {

	/**
	 * Mapping of short handles to fully-qualified ones.
	 *
	 * @var string[]
	 */
	public $script_handles = array();

	/**
	 * Mapping of short handles to fully-qualified ones.
	 *
	 * @var string[]
	 */
	public $style_handles = array();

	/**
	 * @var Non_Autoloaded_Widget_Options
	 */
	public $non_autoloaded_widget_options;

	/**
	 * @var Widget_Number_Incrementing
	 */
	public $widget_number_incrementing;

	/**
	 * @var HTTPS_Resource_Proxy
	 */
	public $https_resource_proxy;

	/**
	 * @var Efficient_Multidimensional_Setting_Sanitizing
	 */
	public $efficient_multidimensional_setting_sanitizing;

	/**
	 * @var \WP_Widget_Factory
	 */
	public $widget_factory;

	/**
	 * @param array $config
	 */
	public function __construct( $config = array() ) {

		// @todo If running unit tests, we can just skip adding this action
		add_action( 'after_setup_theme', array( $this, 'init' ) );

		parent::__construct(); // autoload classes and set $slug, $dir_path, and $dir_url vars

		$default_config = array(
			'disable_widgets_init' => false,
			'disable_widgets_factory' => false,
			'active_modules' => array(
				/*
				 * Note that non_autoloaded_widget_options is disabled by default
				 * on WordPress.com because it has no effect since all options
				 * are autoloaded. We need to go deeper and stop storing widgets
				 * in options altogether, e.g. in a custom post type.
				 * See https://github.com/Automattic/vip-quickstart/issues/430#issuecomment-102183247
				 */
				'non_autoloaded_widget_options' => ! $this->is_wpcom_vip_prod(),

				'widget_number_incrementing' => true,
				'https_resource_proxy' => true,
				'efficient_multidimensional_setting_sanitizing' => true,
			),
			'https_resource_proxy' => HTTPS_Resource_Proxy::default_config(),
		);

		$this->config = array_merge( $default_config, $config );
	}

	/**
	 * Return whether the given module is active.
	 *
	 * @param string $module_name
	 * @throws Exception
	 * @return bool
	 */
	function is_module_active( $module_name ) {
		if ( ! array_key_exists( $module_name, $this->config['active_modules'] ) ) {
			throw new Exception( "Unrecognized module_name: $module_name" );
		}
		return ! empty( $this->config['active_modules'][ $module_name ] );
	}

	/**
	 * @action after_setup_theme
	 */
	function init() {
		global $wp_customize, $wp_widget_factory;
		$this->widget_factory = $wp_widget_factory;
		$this->config = apply_filters( 'customize_widgets_plus_plugin_config', $this->config, $this );

		add_action( 'wp_default_scripts', array( $this, 'register_scripts' ), 11 );
		add_action( 'wp_default_styles', array( $this, 'register_styles' ), 11 );
		add_action( 'customize_controls_enqueue_scripts', array( $this, 'customize_controls_enqueue_scripts' ) );

		if ( $this->is_running_unit_tests() ) {
			$this->disable_widgets_init();
			$this->config['active_modules'] = array_fill_keys( array_keys( $this->config['active_modules'] ), false );
		}

		if ( $this->config['disable_widgets_init'] ) {
			$this->disable_widgets_init();
		}
		if ( $this->config['disable_widgets_factory'] ) {
			$this->disable_widgets_factory();
		}

		if ( $this->is_module_active( 'non_autoloaded_widget_options' ) ) {
			$this->non_autoloaded_widget_options = new Non_Autoloaded_Widget_Options( $this );
		}
		if ( $this->is_module_active( 'widget_number_incrementing' ) ) {
			$this->widget_number_incrementing = new Widget_Number_Incrementing( $this );
		}
		if ( $this->is_module_active( 'https_resource_proxy' ) ) {
			$this->https_resource_proxy = new HTTPS_Resource_Proxy( $this );
		}
		if ( $this->is_module_active( 'efficient_multidimensional_setting_sanitizing' ) && ! empty( $wp_customize ) ) {
			$this->efficient_multidimensional_setting_sanitizing = new Efficient_Multidimensional_Setting_Sanitizing( $this, $wp_customize );
		}
	}

	/**
	 * Register scripts.
	 *
	 * @param \WP_Scripts $wp_scripts
	 * @action wp_default_scripts
	 */
	function register_scripts( \WP_Scripts $wp_scripts ) {
		$slug = 'base';
		$handle = "{$this->slug}-{$slug}";
		$src = $this->dir_url . 'js/base.js';
		$deps = array();
		$wp_scripts->add( $handle, $src, $deps );
		$this->script_handles[ $slug ] = $handle;

		$slug = 'widget-number-incrementing';
		$handle = "{$this->slug}-{$slug}";
		$src = $this->dir_url . 'js/widget-number-incrementing.js';
		$deps = array( $this->script_handles['base'], 'wp-util' );
		$wp_scripts->add( $handle, $src, $deps );
		$this->script_handles[ $slug ] = $handle;

		$slug = 'widget-number-incrementing-customizer';
		$handle = "{$this->slug}-{$slug}";
		$src = $this->dir_url . 'js/widget-number-incrementing-customizer.js';
		$deps = array( 'customize-widgets', $this->script_handles['widget-number-incrementing'] );
		$wp_scripts->add( $handle, $src, $deps );
		$this->script_handles[ $slug ] = $handle;

		$slug = 'https-resource-proxy';
		$handle = "{$this->slug}-{$slug}";
		$src = $this->dir_url . 'js/https-resource-proxy.js';
		$deps = array( 'jquery' );
		$wp_scripts->add( $handle, $src, $deps );
		$this->script_handles[ $slug ] = $handle;
	}

	/**
	 * Register styles.
	 *
	 * @param \WP_Styles $wp_styles
	 * @action wp_default_styles
	 */
	function register_styles( \WP_Styles $wp_styles ) {
		$slug = 'customize-widgets';
		$handle = "{$this->slug}-{$slug}";
		$src = $this->dir_url . 'css/customize-widgets.css';
		$wp_styles->add( $handle, $src );
		$this->style_handles[ $slug ] = $handle;
	}

	/**
	 * @action customize_controls_enqueue_scripts
	 */
	function customize_controls_enqueue_scripts() {
		wp_enqueue_style( $this->style_handles['customize-widgets'] );
	}

	/**
	 * Disable default widgets from being registered.
	 *
	 * @see wp_widgets_init()
	 * @see Plugin::disable_widgets_factory()
	 */
	function disable_widgets_init() {
		$priority = has_action( 'init', 'wp_widgets_init' );
		if ( false !== $priority ) {
			remove_action( 'init', 'wp_widgets_init', $priority );
		}
	}

	/**
	 * Disable the widget factory from registering widgets.
	 *
	 * @see \WP_Widget_Factory::_register_widgets()
	 * @see Plugin::disable_widgets_init()
	 */
	function disable_widgets_factory() {
		$widgets_init_hook = 'widgets_init';
		$callable = array( $this->widget_factory, '_register_widgets' );
		if ( did_action( $widgets_init_hook ) ) {
			trigger_error( 'widgets_init has already been called', E_USER_WARNING );
		}
		$priority = has_action( $widgets_init_hook, $callable );
		if ( false !== $priority ) {
			remove_action( $widgets_init_hook, $callable, $priority );
		}
	}

	/**
	 * Return whether unit tests are currently running.
	 *
	 * @return bool
	 */
	function is_running_unit_tests() {
		return function_exists( 'tests_add_filter' );
	}

	/**
	 * Determine if a given registered widget is a normal multi widget.
	 *
	 * @param array $registered_widget {
	 *     @type string $name
	 *     @type string $id
	 *     @type callable $callback
	 *     @type array $params
	 *     @type string $classname
	 *     @type string $description
	 * }
	 * @return bool
	 */
	function is_registered_multi_widget( array $registered_widget ) {
		$is_multi_widget = ( is_array( $registered_widget['callback'] ) && $registered_widget['callback'][0] instanceof \WP_Widget );
		if ( ! $is_multi_widget ) {
			return false;
		}
		/** @var \WP_Widget $widget_obj */
		$widget_obj = $registered_widget['callback'][0];
		$parsed_widget_id = $this->parse_widget_id( $registered_widget['id'] );
		if ( ! $parsed_widget_id || $parsed_widget_id['id_base'] !== $widget_obj->id_base ) {
			return false;
		}
		if ( ! $this->is_normal_multi_widget( $widget_obj ) ) {
			return false;
		}
		return true;
	}

	/**
	 * Get list of registered WP_Widgets keyed by id_base.
	 *
	 * @return \WP_Widget[]
	 */
	function get_registered_widget_objects() {
		global $wp_registered_widgets;
		$widget_objs = array();
		foreach ( $wp_registered_widgets as $registered_widget ) {
			if ( $this->is_registered_multi_widget( $registered_widget ) ) {
				$widget_obj = $registered_widget['callback'][0];
				$widget_class = get_class( $widget_obj );
				if ( ! array_key_exists( $widget_class, $widget_objs ) ) {
					$widget_objs[ $widget_obj->id_base ] = $widget_obj;
				}
			}
		}
		return $widget_objs;
	}

	/**
	 * @param string $id_base
	 * @return bool
	 */
	function is_recognized_widget_id_base( $id_base ) {
		return array_key_exists( $id_base, $this->get_registered_widget_objects() );
	}

	/**
	 * Parse a widget ID into its components.
	 *
	 * @param string $widget_id
	 * @return array|null {
	 *     @type string $id_base
	 *     @type int $widget_number
	 * }
	 */
	function parse_widget_id( $widget_id ) {
		if ( preg_match( '/^(?P<id_base>.+)-(?P<widget_number>\d+)$/', $widget_id, $matches ) ) {
			$matches['widget_number'] = intval( $matches['widget_number'] );
			// @todo assert that id_base is valid
			return $matches;
		} else {
			return null;
		}
	}

	/**
	 * Determine if an object is a WP_Widget has an id_base and option_name.
	 *
	 * @param \WP_Widget $widget_obj
	 * @return bool
	 */
	function is_normal_multi_widget( $widget_obj ) {
		if ( ! ( $widget_obj instanceof \WP_Widget ) ) {
			return false;
		}
		if ( ! preg_match( '/^widget_(?P<id_base>.+)/', $widget_obj->option_name, $matches ) ) {
			return false;
		}
		if ( $widget_obj->id_base !== $matches['id_base'] ) {
			return false;
		}
		return true;
	}
}
