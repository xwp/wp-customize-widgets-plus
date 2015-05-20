<?php
/**
 * Class WP_Customize_Widget_Setting.
 *
 * @package CustomizeWidgetsPlus
 */

namespace CustomizeWidgetsPlus;

/**
 * Subclass of WP_Customize_Setting to represent widget settings.
 *
 * @package CustomizeWidgetsPlus
 */
class WP_Customize_Widget_Setting extends \WP_Customize_Setting {

	const WIDGET_SETTING_ID_PATTERN = '/^(?P<customize_id_base>widget_(?P<widget_id_base>.+?))(?:\[(?P<widget_number>\d+)\])?$/';

	/**
	 * Setting type.
	 *
	 * @var string
	 */
	public $type = 'widget';

	/**
	 * Widget ID Base.
	 *
	 * @see \WP_Widget::$id_base
	 *
	 * @var string
	 */
	public $widget_id_base;

	/**
	 * Instance of \WP_Widget for this setting.
	 *
	 * @var \WP_Widget
	 */
	public $widget_obj;

	/**
	 * The multi widget number for this setting.
	 *
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
	 * Plugin's instance of Efficient_Multidimensional_Setting_Sanitizing.
	 *
	 * @var Efficient_Multidimensional_Setting_Sanitizing
	 */
	public $efficient_multidimensional_setting_sanitizing;

	/**
	 * Constructor.
	 *
	 * Any supplied $args override class property defaults.
	 *
	 * @param \WP_Customize_Manager $manager Manager instance.
	 * @param string                $id      An specific ID of the setting. Can be a
	 *                                       theme mod or option name.
	 * @param array                 $args    Setting arguments.
	 * @throws Exception If $id is not valid for a widget.
	 */
	public function __construct( \WP_Customize_Manager $manager, $id, array $args = array() ) {
		unset( $args['type'] );

		if ( empty( $manager->efficient_multidimensional_setting_sanitizing ) ) {
			throw new Exception( 'Expected WP_Customize_Manager::$efficient_multidimensional_setting_sanitizing to be set.' );
		}
		$this->efficient_multidimensional_setting_sanitizing = $manager->efficient_multidimensional_setting_sanitizing;

		if ( ! preg_match( static::WIDGET_SETTING_ID_PATTERN, $id, $matches ) ) {
			throw new Exception( "Illegal widget setting ID: $id" );
		}
		$this->widget_id_base = $matches['widget_id_base'];

		if ( ! did_action( 'widgets_init' ) ) {
			throw new Exception( 'Expected widgets_init to have already been actioned.' );
		}
		if ( array_key_exists( $this->widget_id_base, $this->efficient_multidimensional_setting_sanitizing->widget_objs ) ) {
			$this->widget_obj = $this->efficient_multidimensional_setting_sanitizing->widget_objs[ $this->widget_id_base ];
		}

		if ( isset( $matches['widget_number'] ) ) {
			$this->widget_number = intval( $matches['widget_number'] );
		}

		if ( ! array_key_exists( $this->widget_id_base, $this->efficient_multidimensional_setting_sanitizing->current_widget_type_values ) ) {
			$this->efficient_multidimensional_setting_sanitizing->current_widget_type_values[ $this->widget_id_base ] = get_option( "widget_{$this->widget_id_base}", array() );
			add_filter( "pre_option_widget_{$this->widget_id_base}", array( $this, 'filter_pre_option_widget_settings' ) ); // Beware conflict with Widget Posts.
		}

		parent::__construct( $manager, $id, $args );
	}

	/**
	 * Pre-option filter which intercepts the expensive WP_Customize_Setting::_preview_filter().
	 *
	 * @see WP_Customize_Setting::_preview_filter()
	 * @param null|array $pre_value Value that, if set, short-circuits the normal get_option() return value.
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
		$sanitizing = $this->efficient_multidimensional_setting_sanitizing;
		if ( array_key_exists( $this->widget_number, $sanitizing->current_widget_type_values[ $this->widget_id_base ] ) ) {
			$value = $sanitizing->current_widget_type_values[ $this->widget_id_base ][ $this->widget_number ];
		}
		return $value;
	}

	/**
	 * Handle previewing the widget setting.
	 *
	 * @return void
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
			isset( $_REQUEST['widget-id'] ) // WPCS: input var okay.
			&&
			( $this->id === $this->manager->widgets->get_setting_id( wp_unslash( sanitize_text_field( $_REQUEST['widget-id'] ) ) ) ) // WPCS: input var okay.
		);
		if ( $is_null_because_previewing_new_widget ) {
			$value = array();
		}
		$sanitizing = $this->efficient_multidimensional_setting_sanitizing;
		if ( ! is_null( $value ) ) {
			$sanitizing->current_widget_type_values[ $this->widget_id_base ][ $this->widget_number ] = $value;
		}
		$this->is_previewed = true;
	}

	/**
	 * Save the value of the widget setting.
	 *
	 * @param array|mixed $value The value to update.
	 * @return void
	 */
	protected function update( $value ) {
		$option_name = "widget_{$this->widget_id_base}";
		$option_value = get_option( $option_name, array() );
		$option_value[ $this->widget_number ] = $value;
		update_option( $option_name, $option_value );

		if ( ! $this->is_previewed ) {
			$sanitizing = $this->efficient_multidimensional_setting_sanitizing;
			$sanitizing->current_widget_type_values[ $this->widget_id_base ][ $this->widget_number ] = $value;
		}
	}
}
