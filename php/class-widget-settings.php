<?php
/**
 * Widget Settings.
 *
 * @package CustomizeWidgetsPlus
 */

namespace CustomizeWidgetsPlus;

/**
 * Efficient emulation of the widget settings array as returned by \WP_Widget::get_settings().
 *
 * Mapping of widget numbers to the widget_instance post ID if shallow, or to
 * the widget instance array if the value has been retrieved via offsetGet.
 *
 * @see \WP_Widget::get_settings()
 * @see \WP_Widget::save_settings()
 *
 * \WP_Widget::update_callback(): $all_instances = $this->get_settings();
 * \WP_Widget::update_callback(): $this->save_settings($all_instances);
 * \WP_Widget::form_callback(): $all_instances = $this->get_settings();
 * \WP_Widget::_register(): if ( is_array($settings) ) {
 * \WP_Widget::_register(): foreach ( array_keys($settings) as $number ) {
 * \WP_Widget::display_callback(): $instance = $this->get_settings();
 *
 * @package CustomizeWidgetsPlus
 */
class Widget_Settings extends \ArrayIterator {

	/**
	 * Keep track of all widget instances that were unset, as they will be deleted.
	 *
	 * @var int[] $unset_widget_numbers
	 */
	public $unset_widget_numbers = array();

	/**
	 * @param array $array
	 * @throws Exception
	 */
	function __construct( $array ) {
		// Widget numbers start at 2.
		unset( $array[0] );
		unset( $array[1] );

		parent::__construct( $array );
	}

	/**
	 * \WP_Widget::update_callback(): $old_instance = isset($all_instances[$number]) ? $all_instances[$number] : array();
	 * \WP_Widget::display_callback(): if ( array_key_exists( $this->number, $instance ) ) {
	 * \WP_Widget::get_settings(): if ( !empty($settings) && !array_key_exists('_multiwidget', $settings) ) {
	 *
	 * @param int|string $key Array key.
	 * @return bool
	 */
	public function offsetExists( $key ) {
		if ( '_multiwidget' === $key ) {
			return true;
		}
		return parent::offsetExists( $key );
	}

	/**
	 * \WP_Widget::update_callback(): $old_instance = isset($all_instances[$number]) ? $all_instances[$number] : array();
	 * \WP_Widget::display_callback(): $instance = $instance[$this->number];
	 * \WP_Widget::form_callback(): $instance = $all_instances[ $widget_args['number'] ];
	 *
	 * @param int|string $key Array key.
	 * @return array|int|null
	 */
	public function offsetGet( $key ) {
		if ( '_multiwidget' === $key ) {
			return 1;
		}
		if ( ! $this->offsetExists( $key ) ) {
			return null;
		}
		$value = parent::offsetGet( $key );
		if ( is_int( $value ) ) {
			// Fetch the widget post_content_filtered and store it in the array.
			$post = get_post( $value );
			$value = Widget_Posts::get_post_content_filtered( $post );
			$this->offsetSet( $key, $value );
		}
		return $value;
	}

	/**
	 * \WP_Widget::update_callback(): $all_instances[$number] = $instance;
	 * \WP_Widget::save_settings(): $settings['_multiwidget'] = 1;
	 *
	 * @param int|string $key Array key.
	 * @param mixed      $value The array item value.
	 */
	public function offsetSet( $key, $value ) {
		if ( '_multiwidget' === $key || '__i__' === $key ) {
			return;
		}
		$key = filter_var( $key, FILTER_VALIDATE_INT );
		if ( ! is_int( $key ) ) {
			// @todo _doing_it_wrong()?
			return;
		}
		if ( $key < 2 ) {
			// @todo _doing_it_wrong()?
			return;
		}
		if ( ! is_array( $value ) ) {
			// @todo _doing_it_wrong()?
			return;
		}
		parent::offsetSet( $key, $value );
	}

	/**
	 * \WP_Widget::update_callback(): unset($all_instances[$number]);
	 * \WP_Widget::get_settings(): unset($settings['_multiwidget'], $settings['__i__']);
	 *
	 * @param int|string $key Array key.
	 */
	public function offsetUnset( $key ) {
		if ( '_multiwidget' === $key || '__i__' === $key ) {
			return;
		}
		$key = filter_var( $key, FILTER_VALIDATE_INT );
		if ( $key < 2 ) {
			return;
		}
		$this->unset_widget_numbers[] = $key;
		parent::offsetUnset( $key );
	}

	/**
	 * @return array
	 */
	public function current() {
		return $this->offsetGet( $this->key() );
	}

	/**
	 * Serialize the settings into an array.
	 *
	 * @return string
	 */
	public function serialize() {
		return serialize( $this->getArrayCopy() );
	}

}
