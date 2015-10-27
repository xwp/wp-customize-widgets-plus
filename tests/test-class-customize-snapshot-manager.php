<?php

namespace CustomizeWidgetsPlus;

class Test_Customize_Snapshot_Manager extends Base_Test_Case {

	/**
	 * @see Customize_Snapshot_Manager::__construct()
	 */
	function test_construct() {
		$manager = new Customize_Snapshot_Manager( $this->plugin );
		$this->assertInstanceOf( 'CustomizeWidgetsPlus\Customize_Snapshot_Manager', $manager );
	}

	/**
	 * @see Customize_Snapshot_Manager::store_post_data()
	 */
	function test_store_post_data() {
		$this->markTestIncomplete( 'This test has not been implemented.' );
	}

	/**
	 * @see Customize_Snapshot_Manager::create_post_type()
	 */
	function test_create_post_type() {
		$this->markTestIncomplete( 'This test has not been implemented.' );
	}

	/**
	 * @see Customize_Snapshot_Manager::enqueue_scripts()
	 */
	function test_register_scripts() {
		$this->markTestIncomplete( 'This test has not been implemented.' );
	}

	/**
	 * @see Customize_Snapshot_Manager::update_snapshot()
	 */
	function test_update_snapshot() {
		$this->markTestIncomplete( 'This test has not been implemented.' );
	}

	/**
	 * @see Customize_Snapshot_Manager::preview()
	 */
	function test_preview() {
		$this->markTestIncomplete( 'This test has not been implemented.' );
	}

}
