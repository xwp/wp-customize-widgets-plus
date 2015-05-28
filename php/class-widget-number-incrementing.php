<?php

namespace CustomizeWidgetsPlus;

/**
 * Implements fixes for Core issue #32183: Widget ID auto-increments conflict for concurrent users
 *
 * The stored widget_number option provides a centralized auto-increment number
 * for whenever a widget is instantiated, even widgets in the Customizer that
 * are not yet saved.
 *
 * @link https://core.trac.wordpress.org/ticket/32183
 *
 * @package CustomizeWidgetsPlus
 */
class Widget_Number_Incrementing {

	const AJAX_ACTION = 'incr_widget_number';

	/**
	 * @param Plugin $plugin
	 */
	public $plugin;

	/**
	 * @var \WP_Widget[]
	 */
	public $widget_objs;

	/**
	 * @param Plugin $plugin
	 */
	function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;

		add_action( 'widgets_init', array( $this, 'store_widget_objects' ), 90 );
		add_action( 'wp_ajax_' . self::AJAX_ACTION, array( $this, 'ajax_incr_widget_number' ) );
		add_action( 'customize_controls_enqueue_scripts', array( $this, 'customize_controls_enqueue_scripts' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'admin_enqueue_scripts' ) );
		add_filter( 'customize_refresh_nonces', array( $this, 'filter_customize_refresh_nonces' ) );
	}

	/**
	 * @action widgets_init, 90
	 */
	function store_widget_objects() {
		$this->widget_objs = array();
		foreach ( $this->plugin->widget_factory->widgets as $widget_obj ) {
			/** @var \WP_Widget $widget_obj */
			if ( "widget_{$widget_obj->id_base}" !== $widget_obj->option_name ) {
				continue;
			}
			$this->widget_objs[ $widget_obj->id_base ] = $widget_obj;
		}
	}

	/**
	 * @see Widget_Management::gather_registered_widget_types()
	 * @see Widget_Management::get_widget_number()
	 * @see Widget_Management::set_widget_number()
	 * @param string $id_base
	 *
	 * @throws Exception
	 * @return string
	 */
	protected function get_option_key_for_widget_number( $id_base ) {
		$option_name = "{$id_base}_max_widget_number";
		if ( strlen( $option_name ) > 64 ) {
			throw new Exception( "option_name is too long: $option_name" );
		}
		return $option_name;
	}

	/**
	 * Get the max existing widget number for a given id_base.
	 *
	 * @param $id_base
	 * @see \next_widget_id_number()
	 *
	 * @return int
	 */
	function get_max_existing_widget_number( $id_base ) {
		$widget_obj = $this->widget_objs[ $id_base ];
		// @todo There should be a pre_existing_widget_numbers, pre_max_existing_widget_number filter, or pre_existing_widget_ids to short circuit the expensive WP_Widget::get_settings()

		$settings = $widget_obj->get_settings();
		if ( $settings instanceof \ArrayAccess && method_exists( $settings, 'getArrayCopy' ) ) {
			/** @see Widget_Settings */
			$settings = $settings->getArrayCopy( $settings );
		}

		$widget_numbers = array_keys( $settings );
		$widget_numbers[] = 2; // multi-widgets start numbering at 2
		return max( $widget_numbers );
	}

	/**
	 * @param string $id_base
	 * @return bool
	 */
	function add_widget_number_option( $id_base ) {
		$was_added = false;
		$option_name = $this->get_option_key_for_widget_number( $id_base );
		if ( false === get_option( $option_name, false ) ) {
			$widget_number = $this->get_max_existing_widget_number( $id_base );
			// Note: We must use non-autoloaded option due to https://core.trac.wordpress.org/ticket/31245
			$was_added = add_option( $this->get_option_key_for_widget_number( $id_base ), $widget_number, '', 'no' );
		}
		return $was_added;
	}

	/**
	 * Get the maximum widget number used for a given id_base.
	 *
	 * @param string $id_base
	 *
	 * @return int
	 */
	function get_widget_number( $id_base ) {
		$number = get_option( $this->get_option_key_for_widget_number( $id_base ), false );
		if ( false === $number ) {
			return $this->get_max_existing_widget_number( $id_base );
		} else {
			return intval( $number );
		}
	}

	/**
	 * Set the maximum widget number used for the given id_base.
	 *
	 * @param string $id_base
	 * @param int $number
	 * @throws Exception
	 * @return int
	 */
	function set_widget_number( $id_base, $number ) {
		$this->add_widget_number_option( $id_base );
		$existing_number = $this->get_widget_number( $id_base );
		$number = max(
			2, // multi-widget numbering starts here
			$this->get_widget_number( $id_base ),
			$this->get_max_existing_widget_number( $id_base ),
			$number
		);
		if ( $existing_number !== $number ) {
			update_option( $this->get_option_key_for_widget_number( $id_base ), $number );
		}
		return $number;
	}

	/**
	 * Increment and return the widget number for the given id_base.
	 *
	 * @see \wp_cache_incr()
	 * @param string $id_base
	 * @throws Exception
	 * @return int
	 */
	function incr_widget_number( $id_base ) {
		$this->add_widget_number_option( $id_base );
		$number = max(
			2, // multi-widget numbering starts here
			$this->get_widget_number( $id_base ),
			$this->get_max_existing_widget_number( $id_base )
		);
		$number += 1;
		if ( ! update_option( $this->get_option_key_for_widget_number( $id_base ), $number ) ) {
			throw new Exception( 'Unable to increment option.', 500 );
		}
		return $number;
	}

	/**
	 * Export data to JS.
	 */
	function export_script_data() {
		$exports = array(
			'nonce' => wp_create_nonce( self::AJAX_ACTION ),
			'action' => self::AJAX_ACTION,
			'retryCount' => 5,
		);

		wp_scripts()->add_data(
			$this->plugin->script_handles['widget-number-incrementing'],
			'data',
			sprintf( 'var _customizeWidgetsPlusWidgetNumberIncrementingExports = %s;', wp_json_encode( $exports ) )
		);
	}

	/**
	 * @action admin_enqueue_scripts
	 */
	function admin_enqueue_scripts() {
		if ( 'widgets' !== get_current_screen()->id ) {
			return;
		}

		$admin_widgets_script = wp_scripts()->registered['admin-widgets'];
		$admin_widgets_script->src = $this->plugin->dir_url . 'core-patched/wp-admin/js/widgets.js';
		$admin_widgets_script->deps[] = $this->plugin->script_handles['widget-number-incrementing'];
		$admin_widgets_script->deps[] = 'wp-util';
		$this->export_script_data();
	}

	/**
	 * Add script which integrates with Widget Customizer.
	 *
	 * @action customize_controls_enqueue_scripts
	 */
	function customize_controls_enqueue_scripts() {
		wp_enqueue_script( $this->plugin->script_handles['widget-number-incrementing-customizer'] );
		$this->export_script_data();
	}

	/**
	 * Handle Ajax request to increment the widget number.
	 *
	 * @see Widget_Management::request_incr_widget_number()
	 */
	function ajax_incr_widget_number() {
		try {
			if ( ! isset( $_SERVER['REQUEST_METHOD'] ) || 'POST' !== $_SERVER['REQUEST_METHOD'] ) { // input var okay; sanitization ok
				throw new Exception( 'POST method required', 405 );
			}
			$params = array(
				'nonce'   => isset( $_POST['nonce'] )  ? wp_unslash( sanitize_text_field( $_POST['nonce'] ) )  : null, // input var okay
				'id_base' => isset( $_POST['idBase'] ) ? wp_unslash( sanitize_text_field( $_POST['idBase'] ) ) : null, // input var okay
			);
			wp_send_json_success( $this->request_incr_widget_number( $params ) );
		} catch ( Exception $e ) {
			if ( $e->getCode() >= 400 && $e->getCode() < 600 ) {
				$code = $e->getCode();
				$message = $e->getMessage();
			} else {
				$code = 500;
				$message = 'Exception';
			}
			wp_send_json_error( compact( 'code', 'message' ) );
		}
	}

	/**
	 * Do logic for incr_widget_number Ajax request.
	 *
	 * @see Widget_Management::ajax_incr_widget_number()
	 * @param array $params
	 * @return array
	 * @throws Exception
	 */
	function request_incr_widget_number( array $params ) {
		if ( ! is_user_logged_in() ) {
			throw new Exception( 'not_logged_in', 403 );
		}
		if ( ! current_user_can( 'edit_theme_options' ) ) {
			throw new Exception( 'unauthorized', 403 );
		}
		if ( empty( $params['nonce'] ) ) {
			throw new Exception( 'missing_nonce_param', 400 );
		}
		if ( empty( $params['id_base'] ) ) {
			throw new Exception( 'missing_id_base_param', 400 );
		}
		if ( ! wp_verify_nonce( $params['nonce'], 'incr_widget_number' ) ) {
			throw new Exception( 'invalid_nonce', 403 );
		}
		if ( ! $this->plugin->is_recognized_widget_id_base( $params['id_base'] ) ) {
			throw new Exception( 'unrecognized_id_base', 400 );
		}

		$number = $this->incr_widget_number( $params['id_base'] );
		return compact( 'number' );
	}

	/**
	 * @filter customize_refresh_nonces
	 * @param array $nonces
	 * @return array
	 */
	function filter_customize_refresh_nonces( $nonces ) {
		$nonces['incrWidgetNumber'] = wp_create_nonce( 'incr_widget_number' );
		return $nonces;
	}
}
