<?php

namespace CustomizeWidgetsPlus;

/**
 * @group customize-widgets-plus
 */
class Test_Optimized_Widget_Registration extends Base_Test_Case {

	/**
	 * @var \WP_Customize_Manager
	 */
	public $wp_customize_manager;

	function init_customizer() {
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );
		$this->wp_customize_manager = new \WP_Customize_Manager();
	}

	/**
	 * @see Optimized_Widget_Registration::__construct()
	 */
	function test_construct() {
		$this->markTestIncomplete( 'Tests needed for methods in Optimized_Widget_Registration' );
	}
}
