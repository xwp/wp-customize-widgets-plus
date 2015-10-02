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
	 * Widget Posts.
	 *
	 * @var Widget_Posts
	 */
	public $widget_posts;

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

		if ( ! preg_match( static::WIDGET_SETTING_ID_PATTERN, $id, $matches ) ) {
			throw new Exception( "Illegal widget setting ID: $id" );
		}
		$this->widget_id_base = $matches['widget_id_base'];

		if ( isset( $matches['widget_number'] ) ) {
			$this->widget_number = intval( $matches['widget_number'] );
		}

		parent::__construct( $manager, $id, $args );

		if ( empty( $this->widget_posts ) ) {
			throw new Exception( 'Missing argument: widget_posts' );
		}
	}

	/**
	 * Get the instance data for a given widget setting.
	 *
	 * @return string
	 * @throws Exception
	 */
	public function value() {
		$value = $this->default;

		if ( ! isset( $this->widget_posts->current_widget_type_values[ $this->widget_id_base ] ) ) {
			throw new Exception( "current_widget_type_values not set yet for $this->widget_id_base. Current action: " . current_action() );
		}
		if ( isset( $this->widget_posts->current_widget_type_values[ $this->widget_id_base ][ $this->widget_number ] ) ) {
			$value = $this->widget_posts->current_widget_type_values[ $this->widget_id_base ][ $this->widget_number ];
		}
		return $value;
	}

	/**
	 * Handle previewing the widget setting.
	 *
	 * Because this can be called very early in the WordPress execution flow, as
	 * early as after_setup_theme, if widgets_init hasn't been called yet then
	 * the preview logic is deferred to the widgets_init action.
	 *
	 * @see WP_Customize_Widget_Setting::apply_preview()
	 * @throws Exception
	 *
	 * @return void
	 */
	public function preview() {
		if ( $this->is_previewed ) {
			return;
		}
		$this->is_previewed = true;

		if ( did_action( 'widgets_init' ) ) {
			$this->apply_preview();
		} else {
			$priority = has_action( 'widgets_init', array( $this->widget_posts, 'capture_widget_settings_for_customizer' ) );
			if ( empty( $priority ) ) {
				throw new Exception( 'Expected widgets_init action to do Widget_Posts::capture_widget_settings_for_customizer()' );
			}
			$priority += 1;
			add_action( 'widgets_init', array( $this, 'apply_preview' ), $priority );
		}
	}

	/**
	 * Optionally-deferred continuation logic from the preview method.
	 *
	 * @see WP_Customize_Widget_Setting::preview()
	 *
	 * @throws Exception
	 * @return void
	 */
	public function apply_preview() {
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
			( $this->id === $this->manager->widgets->get_setting_id( sanitize_text_field( wp_unslash( $_REQUEST['widget-id'] ) ) ) ) // WPCS: input var okay, [not needed after WPCS upgrade:] sanitization ok.
		);
		if ( $is_null_because_previewing_new_widget ) {
			$value = array();
		}
		if ( ! is_null( $value ) ) {
			$this->widget_posts->current_widget_type_values[ $this->widget_id_base ][ $this->widget_number ] = $value;
		}
	}

	/**
	 * Save the value of the widget setting.
	 *
	 * @param array|mixed $value The value to update.
	 * @return void
	 */
	protected function update( $value ) {
		// @todo Maybe more elegant to do $sanitizing->widget_objs[ $this->widget_id_base ]->get_settings() or $sanitizing->current_widget_type_values[ $this->widget_id_base ]
		$option_name = "widget_{$this->widget_id_base}";
		$option_value = get_option( $option_name, array() );
		$option_value[ $this->widget_number ] = $value;
		$option_value['_multiwidget'] = 1; // Ensure this is set so that wp_convert_widget_settings() won't be called in WP_Widget::get_settings().
		update_option( $option_name, $option_value );

		if ( ! $this->is_previewed ) {
			$this->widget_posts->current_widget_type_values[ $this->widget_id_base ][ $this->widget_number ] = $value;
		}
	}
}
