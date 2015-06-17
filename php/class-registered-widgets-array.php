<?php

namespace CustomizeWidgetsPlus;

/**
 * Wrapper around $wp_registered_widgets, $wp_registered_widget_controls, or $wp_registered_widget_updates
 *
 * @package CustomizeWidgetsPlus
 */
class Registered_Widgets_Array extends \ArrayIterator {

	/**
	 * @var Optimized_Widget_Registration
	 */
	public $module;

	/**
	 * @var string
	 */
	public $original_variable_name;

	/**
	 * @param Optimized_Widget_Registration $module
	 * @param string                        $original_variable_name
	 * @param array                         $array
	 *
	 * @throws Exception
	 */
	function __construct( Optimized_Widget_Registration $module, $original_variable_name, $array ) {
		$this->module = $module;
		if ( ! in_array( $original_variable_name, array( '$wp_registered_widgets', '$wp_registered_widget_controls', '$wp_registered_widget_updates' ) ) ) {
			throw new Exception( 'Unexpected $original_variable_name: ' . $original_variable_name );
		}
		$this->original_variable_name = $original_variable_name;
		parent::__construct( $array );
	}

	/**
	 * Determine if a widget ID has been registered or is available to register.
	 *
	 * @param string $widget_id
	 * @return bool
	 */
	function offsetExists( $widget_id ) {
		if ( parent::offsetExists( $widget_id ) ) {
			return true;
		}
		$parsed_widget_id = $this->module->plugin->parse_widget_id( $widget_id );
		if ( empty( $parsed_widget_id ) || empty( $this->module->widget_objs[ $parsed_widget_id['id_base'] ] ) ) {
			return false;
		}
		$widget_obj = $this->module->widget_objs[ $parsed_widget_id['id_base'] ];
		$settings = $widget_obj->get_settings();
		return isset( $settings[ $parsed_widget_id[ 'widget_number' ] ] );
	}

	/**
	 * Get the registered widget instance, control, or updates.
	 *
	 * Register's widget on demand when accessed.
	 *
	 * @param string $widget_id
	 *
	 * @return array|null
	 * @throws Exception
	 */
	function offsetGet( $widget_id ) {
		if ( parent::offsetExists( $widget_id ) ) {
			return parent::offsetGet( $widget_id );
		}
		$parsed_widget_id = $this->module->plugin->parse_widget_id( $widget_id );
		if ( empty( $parsed_widget_id ) || empty( $this->module->widget_objs[ $parsed_widget_id['id_base'] ] ) ) {
			return null;
		}
		$widget_obj = $this->module->widget_objs[ $parsed_widget_id['id_base'] ];
		$settings = $widget_obj->get_settings();
		if ( ! isset( $settings[ $parsed_widget_id[ 'widget_number' ] ] ) ) {
			// This should actually be PHP undefined index notice.
			return null;
		}
		$widget_obj->_set( $parsed_widget_id[ 'widget_number' ] );
		$widget_obj->_register_one( $parsed_widget_id[ 'widget_number' ] );

		if ( ! parent::offsetExists( $widget_id ) ) {
			throw new Exception( "Failed to JIT-register widget ($widget_id) for access to $this->original_variable_name" );
		}
		return parent::offsetGet( $widget_id );
	}

}
