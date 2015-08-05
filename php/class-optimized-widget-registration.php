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
		global $pagenow;
		$this->plugin = $plugin;

		$priority = 92; // Because Widget_Posts::prepare_widget_data() happens at 91.
		add_action( 'widgets_init', array( $this, 'capture_widget_instance_data' ), $priority );

		if ( 'widgets.php' !== $pagenow ) {
			$priority = $this->plugin->disable_widgets_factory();
			add_action( 'widgets_init', array( $this, 'register_initial_widgets' ), $priority );
			if ( 'customize.php' === $pagenow ) {
				add_action( 'widgets_init', array( $this, 'register_all_sidebars_widgets' ), $priority );
			}
		}

		add_action( 'customize_register', array( $this, 'capture_customize_manager' ) );

		/**
		 * Note that this is at wp:9 so that it happens before WP_Customize_Widgets::customize_register()
		 * in Customizer Preview so that any query-specific widgets will also be registered.
		 * @see \WP_Customize_Widgets::schedule_customize_register()
		 */
		add_action( 'wp', array( $this, 'register_all_sidebars_widgets' ), 9 );

		add_action( 'wp', array( $this, 'register_remaining_sidebars_widgets' ), 11 );
	}

	/**
	 * @param \WP_Customize_Manager $manager
	 * @action customize_register, 1
	 */
	function capture_customize_manager( \WP_Customize_Manager $manager ) {
		$this->manager = $manager;
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
		false && check_ajax_referer(); // temp hack to get around PHP_CodeSniffer complaint

		// @codingStandardsIgnoreStart
		if ( ! defined( 'DOING_AJAX' ) || ! DOING_AJAX || ! isset( $_POST['action'] ) ) { // WPCS: input var ok.
			return false;
		}

		$is_ajax_widget_action = ( 'save-widget' === $_POST['action'] || 'update-widget' === $_POST['action'] ); // WPCS: input var ok.
		// @codingStandardsIgnoreEnd

		if ( ! $is_ajax_widget_action ) {
			return false;
		}
		if ( ! isset( $_POST['widget-id'] ) ) { // WPCS: input var ok.
			return false;
		}

		// @codingStandardsIgnoreStart
		$widget_id = sanitize_key( wp_unslash( $_POST['widget-id'] ) ); // WPCS: input var ok.
		// @codingStandardsIgnoreEnd

		return $this->register_single_widget( $widget_id );
	}

	/**
	 * Register just the widgets that are needed for the current request.
	 *
	 * @see \WP_Widget_Factory::_register_widgets()
	 */
	function register_initial_widgets() {
		global $pagenow, $wp_registered_widgets, $wp_widget_factory;

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
		}
	}

	/**
	 * Register widgets for all sidebars, other than inactive widgets and orphaned widgets.
	 *
	 * @return array Widget IDs newly-registered.
	 */
	function register_all_sidebars_widgets() {
		$registered_widget_ids = array();
		$sidebars_widgets = wp_get_sidebars_widgets();
		foreach ( $sidebars_widgets as $sidebar_id => $widget_ids ) {
			if ( preg_match( '/^(wp_inactive_widgets|orphaned_widgets_\d+)$/', $sidebar_id ) ) {
				continue;
			}
			foreach ( $widget_ids as $widget_id ) {
				if ( $this->register_single_widget( $widget_id ) ) {
					$registered_widget_ids[] = $widget_id;
				}
			}
		}
		return $registered_widget_ids;
	}

	/**
	 * Register any additional widgets which may have been added to the sidebars.
	 *
	 * The Jetpack Widget Visibility conditions as well as the Customizer setting
	 * previews for the sidebars will have applied by wp:10, so after this point
	 * register Customizer settings and controls for any newly-recognized
	 * widgets which are now available after having called preview() on the
	 * sidebars_widgets Customizer settings.
	 *
	 * @see WP_Customize_Widgets::customize_register()
	 */
	function register_remaining_sidebars_widgets() {
		$newly_registered_widgets = $this->register_all_sidebars_widgets();

		if ( ! empty( $this->manager ) && ! empty( $newly_registered_widgets ) ) {
			$this->customize_register_widgets( $newly_registered_widgets );
		}
	}

	/**
	 * Register Customizer settings and controls for the given widget IDs.
	 *
	 * @param array $register_widget_ids
	 */
	function customize_register_widgets( $register_widget_ids ) {
		global $wp_registered_widgets, $wp_registered_widget_controls, $wp_registered_sidebars;

		$new_setting_ids = array();

		$sidebars_widgets = wp_get_sidebars_widgets();

		foreach ( $register_widget_ids as $widget_id ) {
			$setting_id   = $this->manager->widgets->get_setting_id( $widget_id );
			if ( ! $this->manager->get_setting( $setting_id ) ) {
				$setting_args = $this->manager->widgets->get_setting_args( $setting_id );
				$setting = new WP_Customize_Widget_Setting( $this->manager, $setting_id, $setting_args );
				$this->manager->add_setting( $setting );
				$new_setting_ids[] = $setting_id;
			}
		}

		// Add a control for each active widget (located in a sidebar).
		foreach ( $sidebars_widgets as $sidebar_id => $sidebar_widget_ids ) {
			if ( empty( $sidebar_widget_ids ) || ! isset( $wp_registered_sidebars[ $sidebar_id ] ) ) {
				continue;
			}

			foreach ( $sidebar_widget_ids as $i => $widget_id ) {
				$setting_id = $this->manager->widgets->get_setting_id( $widget_id );
				$should_register = (
					in_array( $widget_id, $register_widget_ids )
					&&
					isset( $wp_registered_widgets[ $widget_id ] )
					&&
					! $this->manager->get_control( $setting_id )
				);
				if ( ! $should_register ) {
					continue;
				}

				$registered_widget = $wp_registered_widgets[ $widget_id ];
				$id_base = $wp_registered_widget_controls[ $widget_id ]['id_base'];
				$control = new \WP_Widget_Form_Customize_Control( $this->manager, $setting_id, array(
					'label'          => $registered_widget['name'],
					'section'        => sprintf( 'sidebar-widgets-%s', $sidebar_id ),
					'sidebar_id'     => $sidebar_id,
					'widget_id'      => $widget_id,
					'widget_id_base' => $id_base,
					'priority'       => $i,
					'width'          => $wp_registered_widget_controls[ $widget_id ]['width'],
					'height'         => $wp_registered_widget_controls[ $widget_id ]['height'],
					'is_wide'        => $this->manager->widgets->is_wide_widget( $widget_id ),
				) );
				$this->manager->add_control( $control );
			}
		}

		if ( ! $this->manager->doing_ajax( 'customize_save' ) ) {
			foreach ( $new_setting_ids as $new_setting_id ) {
				$this->manager->get_setting( $new_setting_id )->preview();
			}
		}
	}

	/**
	 * @param string $widget_id
	 *
	 * @return bool Whether the widget was registered. False if already registered or invalid.
	 */
	function register_single_widget( $widget_id ) {
		global $wp_registered_widgets;
		if ( isset( $wp_registered_widgets[ $widget_id ] ) ) {
			return false;
		}
		$parsed_widget_id = $this->plugin->parse_widget_id( $widget_id );
		if ( empty( $parsed_widget_id ) || empty( $parsed_widget_id['widget_number'] ) ) {
			return false;
		}
		if ( ! isset( $this->widget_objs[ $parsed_widget_id['id_base'] ] ) ) {
			return false;
		}
		if ( $widget_id !== $parsed_widget_id['id_base'] . '-' . $parsed_widget_id['widget_number'] ) {
			return false;
		}
		$widget_obj = $this->widget_objs[ $parsed_widget_id['id_base'] ];
		$settings = $widget_obj->get_settings(); // @todo We could improve performance if this is not called multiple times.
		if ( ! isset( $settings[ $parsed_widget_id['widget_number'] ] ) ) {
			return false;
		}
		$widget_obj->_set( $parsed_widget_id['widget_number'] );
		$widget_obj->_register_one( $parsed_widget_id['widget_number'] );
		return true;
	}

}
