<?php

namespace CustomizeWidgetsPlus;

class Test_Widget_Number_Incrementing extends Base_Test_Case {

	/**
	 * @see Widget_Number_Incrementing::__construct()
	 */
	function test_construct() {
		$instance = new Widget_Number_Incrementing( $this->plugin );

		$this->assertEquals( 10, has_action( 'wp_ajax_' . Widget_Number_Incrementing::AJAX_ACTION, array( $instance, 'ajax_incr_widget_number' ) ) );
		$this->assertEquals( 10, has_action( 'customize_controls_enqueue_scripts', array( $instance, 'customize_controls_enqueue_scripts' ) ) );
		$this->assertEquals( 10, has_action( 'admin_enqueue_scripts', array( $instance, 'admin_enqueue_scripts' ) ) );
		$this->assertEquals( 10, has_action( 'customize_refresh_nonces', array( $instance, 'filter_customize_refresh_nonces' ) ) );
	}

	/**
	 * @see Widget_Number_Incrementing::get_max_existing_widget_number()
	 */
	function test_get_max_existing_widget_number() {
		$instance = new Widget_Number_Incrementing( $this->plugin );
		wp_widgets_init();
		$widgets_sidebars = call_user_func_array( 'array_merge', wp_get_sidebars_widgets() );
		$widget_id = array_shift( $widgets_sidebars );
		$parsed_widget_id = $this->plugin->parse_widget_id( $widget_id );
		$id_base = $parsed_widget_id['id_base'];

		$registered_widget_objs = $this->plugin->get_registered_widget_objects();
		$settings = $registered_widget_objs[ $id_base ]->get_settings();
		$max_widget_number = max( array_keys( $settings ) );
		$this->assertEquals( $max_widget_number, $instance->get_max_existing_widget_number( $id_base ) );
	}

	/**
	 * @see Widget_Number_Incrementing::add_widget_number_option()
	 * @see Widget_Number_Incrementing::get_widget_number()
	 */
	function test_add_and_get_widget_number_option() {
		$instance = new Widget_Number_Incrementing( $this->plugin );
		add_action( 'widgets_init', function () {
			register_widget( __NAMESPACE__ . '\\Acme_Widget' );
		} );
		wp_widgets_init();
		$this->assertTrue( $instance->add_widget_number_option( Acme_Widget::ID_BASE ) );
		$this->assertFalse( $instance->add_widget_number_option( Acme_Widget::ID_BASE ) );
		$this->assertEquals( 2, $instance->get_widget_number( Acme_Widget::ID_BASE ) );
	}

	/**
	 * @see Widget_Number_Incrementing::set_widget_number()
	 */
	function test_set_widget_number() {
		$instance = new Widget_Number_Incrementing( $this->plugin );
		add_action( 'widgets_init', function () {
			register_widget( __NAMESPACE__ . '\\Acme_Widget' );
		} );
		wp_widgets_init();

		$widget_number = $instance->get_widget_number( Acme_Widget::ID_BASE );
		$this->assertInternalType( 'int', $widget_number );
		$new_widget_number = $instance->set_widget_number( Acme_Widget::ID_BASE, $widget_number - 1 );
		$this->assertEquals( $widget_number, $instance->get_widget_number( acme_Widget::ID_BASE ) );
		$this->assertEquals( $new_widget_number, $widget_number );
		$new_widget_number = $instance->set_widget_number( Acme_Widget::ID_BASE, $widget_number + 1 );
		$this->assertEquals( $new_widget_number, $widget_number + 1 );
		$this->assertEquals( $widget_number + 1, $instance->get_widget_number( Acme_Widget::ID_BASE ) );

	}

	/**
	 * @see Widget_Number_Incrementing::incr_widget_number()
	 */
	function test_incr_widget_number() {
		$instance = new Widget_Number_Incrementing( $this->plugin );
		wp_widgets_init();
		$registered_widget_objs = $this->plugin->get_registered_widget_objects();
		$id_base = key( $registered_widget_objs );

		$initial_widget_number = $instance->get_widget_number( $id_base );
		$this->assertInternalType( 'int', $initial_widget_number );
		$iteration_count = 10;
		for ( $i = 1; $i <= $iteration_count; $i += 1 ) {
			$this->assertEquals( $initial_widget_number + $i, $instance->incr_widget_number( $id_base ) );
			$this->assertEquals( $initial_widget_number + $i, $instance->get_widget_number( $id_base ) );
		}
	}

	/**
	 * @see Widget_Number_Incrementing::request_incr_widget_number()
	 */
	function test_request_incr_widget_number() {
		$instance = new Widget_Number_Incrementing( $this->plugin );
		wp_widgets_init();
		$registered_widget_objs = $this->plugin->get_registered_widget_objects();
		$id_base = key( $registered_widget_objs );

		// -----
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'contributor' ) ) );
		$params = array(
			'nonce' => wp_create_nonce( 'incr_widget_number' ),
			'id_base' => $id_base,
		);
		try {
			$exception = null;
			$instance->request_incr_widget_number( $params );
		} catch ( Exception $e ) {
			$exception = $e;
		}
		$this->assertNotNull( $exception );
		$this->assertEquals( 403, $exception->getCode() );

		// -----
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );
		$params['nonce'] = 'bad_nonce';
		try {
			$exception = null;
			$instance->request_incr_widget_number( $params );
		} catch ( Exception $e ) {
			$exception = $e;
		}
		$this->assertNotNull( $exception );
		$this->assertEquals( 403, $exception->getCode() );

		// -----
		try {
			$exception = null;
			$instance->request_incr_widget_number( array() );
		} catch ( Exception $e ) {
			$exception = $e;
		}
		$this->assertNotNull( $exception );
		$this->assertEquals( 400, $exception->getCode() );

		// -----
		$params['nonce'] = wp_create_nonce( 'incr_widget_number' );
		try {
			$exception = null;
			$instance->request_incr_widget_number( array_merge(
				$params,
				array(
					'id_base' => 'bad_id',
				)
			) );
		} catch ( Exception $e ) {
			$exception = $e;
		}
		$this->assertNotNull( $exception );
		$this->assertEquals( 400, $exception->getCode() );

		// ----
		$previous_widget_number = $instance->get_widget_number( $id_base );
		$result = $instance->request_incr_widget_number( $params );
		$this->assertArrayHasKey( 'number', $result );
		$this->assertEquals( $result['number'], $previous_widget_number + 1 );
	}

}