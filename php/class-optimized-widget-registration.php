<?php

namespace CustomizeWidgetsPlus;

/**
 * @package CustomizeWidgetsPlus
 */
class Optimized_Widget_Registration {

	/**
	 * @var Plugin
	 */
	public $plugin;

	/**
	 * @var \WP_Customize_Manager
	 */
	public $manager;

	/**
	 * @var \WP_Widget[]
	 */
	public $widget_objs;

	/**
	 * @param Plugin $plugin
	 */
	function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;

		//$this->manager = $manager;

		$priority = 92; // Because Widget_Posts::prepare_widget_data() happens at 91.
		add_action( 'widgets_init', array( $this, 'capture_widget_instance_data' ), $priority );

		$priority = $this->plugin->disable_widgets_factory();
		add_action( 'widgets_init', array( $this, 'register_initial_widgets' ), $priority );

		// @todo In customizer, we need to do this instead: add_action( 'wp', array( $this, 'register_all_sidebars_widgets' ) );
		add_action( 'dynamic_sidebar_before', array( $this, 'register_sidebar_widgets' ), 10, 2 );

		// @todo Make $wp_registered_widgets a ArrayIterator which can register widgets on the fly. Needed for Subareas Widgets.
	}

	/**
	 * Since at widgets_init,100 the single instances of widgets get copied out
	 * to the many instances in $wp_registered_widgets, we capture all of the
	 * registered widgets up front so we don't have to search through the big
	 * list later.
	 */
	function capture_widget_instance_data() {
		foreach ( $this->plugin->widget_factory->widgets as $widget_obj ) {
			/** @var \WP_Widget $widget_obj */
			$this->widget_objs[ $widget_obj->id_base ] = $widget_obj;
		}
	}

	/**
	 * Register the widget instance used in Ajax requests.
	 *
	 * @see wp_ajax_save_widget()
	 * @see wp_ajax_update_widget()
	 * @return bool Whether the widget was registered. False if invalid or already registered.
	 */
	function register_ajax_request_widget() {
		if ( ! defined( 'DOING_AJAX' ) || ! DOING_AJAX || ! isset( $_POST['action'] ) ) {
			return false;
		}
		$is_ajax_widget_action = ( 'save-widget' === $_POST['action'] || 'update-widget' == $_POST['action'] );
		if ( ! $is_ajax_widget_action ) {
			return false;
		}
		if ( ! isset( $_POST['widget-id'] ) ) {
			return false;
		}
		$widget_id = sanitize_key( wp_unslash( $_POST['widget-id'] ) );
		return $this->register_single_widget( $widget_id );
	}

	/**
	 * Register just the widgets that are needed for the current request.
	 *
	 * @see \WP_Widget_Factory::_register_widgets()
	 */
	function register_initial_widgets() {
		global $pagenow;

		global $wp_registered_widgets, $wp_widget_factory;
		$registered_id_bases = array_unique( array_map( '_get_widget_id_base', array_keys( $wp_registered_widgets ) ) );

		foreach ( $wp_widget_factory->widgets as $widget_class => $widget_obj ) {
			/** @var \WP_Widget $widget_obj */

			// Don't register new widget if old widget with the same id is already registered.
			if ( in_array( $widget_obj->id_base, $registered_id_bases, true ) ) {
				unset( $wp_widget_factory->widgets[ $widget_class ] );
				continue;
			}

			if ( 'widgets.php' === $pagenow ) {
				// Register all widget instances since they will all be used on the widgets admin page.
				$widget_obj->_register();
			} else {
				// Only register the template. Additional widgets will be registered later as needed.
				$widget_obj->_set( 1 );
				$widget_obj->_register_one( 1 );
			}
		}

		if ( 'widgets.php' !== $pagenow ) {
			$this->register_ajax_request_widget();

			global $wp_registered_widgets, $wp_registered_widget_controls, $wp_registered_widget_updates;
			$wp_registered_widgets = new Registered_Widgets_Array( $this, '$wp_registered_widgets', $wp_registered_widgets );
			$wp_registered_widget_controls = new Registered_Widgets_Array( $this, '$wp_registered_widget_controls', $wp_registered_widget_controls );
			$wp_registered_widget_updates = new Registered_Widgets_Array( $this, '$wp_registered_widget_updates', $wp_registered_widget_updates );
		}
	}

	/**
	 * Register widgets for all sidebars, other than inactive widgets and orphaned widgets.
	 *
	 * @return int Number of widgets registered.
	 */
	function register_all_sidebars_widgets() {
		$registered_count = 0;
		$sidebars_widgets = wp_get_sidebars_widgets();
		foreach ( $sidebars_widgets as $sidebar_id => $widget_ids ) {
			if ( preg_match( '/^(wp_inactive_widgets|orphaned_widgets_\d+)$/', $sidebar_id ) ) {
				continue;
			}
			foreach ( $widget_ids as $widget_id ) {
				if ( $this->register_single_widget( $widget_id ) ) {
					$registered_count += 1;
				}
			}
		}
		return $registered_count;
	}

	/**
	 * @param string $sidebar_id
	 * @action dynamic_sidebar_before
	 * @return int Number of widgets registered.
	 */
	function register_sidebar_widgets( $sidebar_id ) {
		$sidebars_widgets = wp_get_sidebars_widgets();
		$registered_count = 0;
		if ( isset( $sidebars_widgets[ $sidebar_id ] ) ) {
			foreach ( $sidebars_widgets[ $sidebar_id ] as $widget_id ) {
				if ( $this->register_single_widget( $widget_id ) ) {
					$registered_count += 1;
				}
			}
		}
		return $registered_count;

	}

	/**
	 * @param string $widget_id
	 *
	 * @return bool Whether the widget was registered. False if already registered or invalid.
	 */
	function register_single_widget( $widget_id ) {
		$parsed_widget_id = $this->plugin->parse_widget_id( $widget_id );
		if ( empty( $parsed_widget_id ) || empty( $parsed_widget_id['widget_number'] ) ) {
			return false;
		}
		if ( ! isset( $this->widget_objs[ $parsed_widget_id['id_base'] ] ) ) {
			return false;
		}
		$widget_id = $parsed_widget_id['id_base'] . '-' . $parsed_widget_id['widget_number'];
		if ( isset( $wp_registered_widgets[ $widget_id ] ) ) {
			return false;
		}
		$widget_obj = $this->widget_objs[ $parsed_widget_id['id_base'] ];
		$settings = $widget_obj->get_settings(); // @todo we should prevent this from being called multiple times
		if ( ! isset( $settings[ $parsed_widget_id['widget_number'] ] ) ) {
			return false;
		}
		$widget_obj->_set( $parsed_widget_id['widget_number'] );
		$widget_obj->_register_one( $parsed_widget_id['widget_number'] );
		return true;
	}

}
