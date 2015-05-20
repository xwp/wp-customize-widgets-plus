<?php

namespace CustomizeWidgetsPlus;

/**
 * Class WP_Customize_Widget_Setting
 *
 * @package CustomizeWidgetsPlus
 *
 */
class WP_Customize_Widget_Setting extends \WP_Customize_Setting {

	const WIDGET_SETTING_ID_PATTERN = '/^(?P<customize_id_base>widget_(?P<widget_id_base>.+?))(?:\[(?P<widget_number>\d+)\])?$/';

	/**
	 * @var string
	 */
	public $type = 'widget';

	/**
	 * @var string
	 */
	public $widget_id_base;

	/**
	 * @var int
	 */
	public $widget_number;

	/**
	 * Whether or not preview() was called.
	 *
	 * @see WP_Customize_Widget_Setting::preview()
	 *
	 * @var bool
	 */
	public $is_previewed = false;

	/**
	 * @var Efficient_Multidimensional_Setting_Sanitizing
	 */
	public $efficient_multidimensional_setting_sanitizing;

	/**
	 * Constructor.
	 *
	 * Any supplied $args override class property defaults.
	 *
	 * @param \WP_Customize_Manager $manager
	 * @param string               $id      An specific ID of the setting. Can be a
	 *                                      theme mod or option name.
	 * @param array                $args    Setting arguments.
	 * @throws Exception if $id is not valid for a widget
	 */
	public function __construct( $manager, $id, $args = array() ) {
		unset( $args['type'] );

		if ( empty( $manager->efficient_multidimensional_setting_sanitizing ) ) {
			throw new Exception( 'Expected WP_Customize_Manager::$efficient_multidimensional_setting_sanitizing to be set.' );
		}
		$this->efficient_multidimensional_setting_sanitizing = $manager->efficient_multidimensional_setting_sanitizing;

		if ( ! preg_match( static::WIDGET_SETTING_ID_PATTERN, $id, $matches ) ) {
			throw new Exception( "Illegal widget setting ID: $id" );
		}
		// @todo validate that the $id_base is for a valid WP_Widget?
		$this->widget_id_base = $matches['widget_id_base'];
		if ( isset( $matches['widget_number'] ) ) {
			$this->widget_number = intval( $matches['widget_number'] );
		}

		if ( ! array_key_exists( $this->widget_id_base, $this->efficient_multidimensional_setting_sanitizing->current_widget_type_values ) ) {
			$this->efficient_multidimensional_setting_sanitizing->current_widget_type_values[ $this->widget_id_base ] = get_option( "widget_{$this->widget_id_base}", array() );
			add_filter( "pre_option_widget_{$this->widget_id_base}", array( $this, 'filter_pre_option_widget_settings' ) );
		}

		parent::__construct( $manager, $id, $args );
	}

	/**
	 * Pre-option filter which intercepts the expensive WP_Customize_Setting::_preview_filter().
	 *
	 * @see WP_Customize_Setting::_preview_filter()
	 * @param null|array $pre_value
	 * @return array
	 */
	function filter_pre_option_widget_settings( $pre_value ) {
		if ( ! $this->efficient_multidimensional_setting_sanitizing->disabled_filtering_pre_option_widget_settings ) {
			$pre_value = $this->efficient_multidimensional_setting_sanitizing->current_widget_type_values[ $this->widget_id_base ];
		}
		return $pre_value;
	}

	/**
	 * Get the instance data for a given widget setting.
	 *
	 * @return string
	 */
	public function value() {
		$value = $this->default;
		if ( array_key_exists( $this->widget_number, $this->efficient_multidimensional_setting_sanitizing->current_widget_type_values[ $this->widget_id_base ] ) ) {
			$value = $this->efficient_multidimensional_setting_sanitizing->current_widget_type_values[ $this->widget_id_base ][ $this->widget_number ];
		}
		return $value;
	}

	/**
	 * Handle previewing the widget setting.
	 */
	public function preview() {
		if ( $this->is_previewed ) {
			return;
		}
		if ( ! isset( $this->_original_value ) ) {
			$this->_original_value = $this->value();
		}
		if ( ! isset( $this->_previewed_blog_id ) ) {
			$this->_previewed_blog_id = get_current_blog_id();
		}
		$value = $this->post_value();
		$is_null_because_previewing_new_widget = (
			is_null( $value )
			&&
			$this->manager->doing_ajax( 'update-widget' )
			&&
			isset( $_REQUEST['widget-id'] ) // input var okay
			&&
			( $this->id === $this->manager->widgets->get_setting_id( wp_unslash( sanitize_text_field( $_REQUEST['widget-id'] ) ) ) ) // input var okay
		);
		if ( $is_null_because_previewing_new_widget ) {
			$value = array();
		}
		if ( ! is_null( $value ) ) {
			$this->efficient_multidimensional_setting_sanitizing->current_widget_type_values[ $this->widget_id_base ][ $this->widget_number ] = $value;
		}
		$this->is_previewed = true;
	}

	/**
	 * Save the value of the widget setting.
	 *
	 * @param array $value The value to update.
	 * @return mixed The result of saving the value.
	 */
	protected function update( $value ) {
		$option_name = "widget_{$this->widget_id_base}";
		$option_value = get_option( $option_name, array() );
		$option_value[ $this->widget_number ] = $value;
		update_option( $option_name, $option_value );

		if ( ! $this->is_previewed ) {
			$this->efficient_multidimensional_setting_sanitizing->current_widget_type_values[ $this->widget_id_base ][ $this->widget_number ] = $value;
		}
	}
}
