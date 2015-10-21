<?php

namespace CustomizeWidgetsPlus;

class Test_Customize_Snapshot extends Base_Test_Case {

	/**
	 * A valid UUID.
	 * @type string
	 */
	const UUID = '65aee1ff-af47-47df-9e14-9c69b3017cd3';

	/**
	 * Post type.
	 * @type string
	 */
	const POST_TYPE = 'customize_snapshot';

	/**
	 * @var \WP_Customize_Manager
	 */
	protected static $manager;

	/**
	 * Boostrap the customizer.
	 */
	public static function setUpBeforeClass() {
		require_once ABSPATH . WPINC . '/class-wp-customize-manager.php';
		self::$manager = new \WP_Customize_Manager();

		$args = array(
			'labels' => array(
				'name' => __( 'Customize Snapshots', 'customize-widgets-plus' ),
				'singular_name' => __( 'Customize Snapshot', 'customize-widgets-plus' ),
			),
			'public' => false,
			'capability_type' => 'post',
			'map_meta_cap' => true,
			'hierarchical' => false,
			'rewrite' => false,
			'delete_with_user' => false,
			'supports' => array( 'title', 'author', 'revisions' ),
		);
		register_post_type( self::POST_TYPE, $args );
	}

	public static function tearDownAfterClass() {
		_unregister_post_type( self::POST_TYPE );
	}

	/**
	 * @see Customize_Snapshot::__construct()
	 */
	function test_construct() {
		$this->markTestIncomplete( 'This test has not been implemented.' );
	}

	/**
	 * @see Customize_Snapshot::populate_customized_post_var()
	 */
	function test_populate_customized_post_var() {
		$this->markTestIncomplete( 'This test has not been implemented.' );
	}

	/**
	 * @see Customize_Snapshot::generate_uuid()
	 */
	function test_generate_uuid() {
		$snapshot = new Customize_Snapshot( self::$manager, null );
		$this->assertInternalType( 'string', $snapshot->generate_uuid() );
	}

	/**
	 * @see Customize_Snapshot::is_valid_uuid()
	 */
	function test_is_valid_uuid() {
		$snapshot = new Customize_Snapshot( self::$manager, null );
		$this->assertTrue( $snapshot->is_valid_uuid( self::UUID ) );
	}

	/**
	 * @see Customize_Snapshot::uuid()
	 */
	function test_uuid() {
		$snapshot = new Customize_Snapshot( self::$manager, self::UUID );
		$this->assertEquals( self::UUID, $snapshot->uuid() );
	}

	/**
	 * @see Customize_Snapshot::uuid()
	 */
	function test_uuid_throws_exception() {
		try {
			new Customize_Snapshot( self::$manager, '1234-invalid-UUID' );
		} catch ( \Exception $e ) {
			$this->assertContains( 'You\'ve entered an invalid snapshot UUID.', $e->getMessage() );
			return;
		}

		$this->fail( 'An expected exception has not been raised.' );
	}

	/**
	 * @see Customize_Snapshot::manager()
	 */
	function test_manager() {
		$snapshot = new Customize_Snapshot( self::$manager, null );
		$this->assertEquals( self::$manager, $snapshot->manager() );
		$this->assertInstanceOf( 'WP_Customize_Manager', $snapshot->manager() );
	}

	/**
	 * @see Customize_Snapshot::is_preview()
	 */
	function test_is_preview() {
		$snapshot = new Customize_Snapshot( self::$manager, self::UUID );
		$this->assertTrue( $snapshot->is_preview() );
	}

	/**
	 * @see Customize_Snapshot::is_preview()
	 */
	function test_is_preview_returns_false() {
		$snapshot = new Customize_Snapshot( self::$manager, null );
		$this->assertFalse( $snapshot->is_preview() );
	}

	/**
	 * @see Customize_Snapshot::post()
	 */
	function test_post() {
		$this->markTestIncomplete( 'This test has not been implemented.' );
	}

	/**
	 * @see Customize_Snapshot::_override_wp_query_is_single()
	 */
	function test_override_wp_query_is_single() {
		$this->markTestIncomplete( 'This test has not been implemented.' );
	}

	/**
	 * @see Customize_Snapshot::get()
	 */
	function test_get() {
		$this->markTestIncomplete( 'This test has not been implemented.' );
	}

	/**
	 * @see Customize_Snapshot::data()
	 */
	function test_data() {
		$this->markTestIncomplete( 'This test has not been implemented.' );
	}

	/**
	 * @see Customize_Snapshot::settings()
	 */
	function test_settings() {
		$this->markTestIncomplete( 'This test has not been implemented.' );
	}

	/**
	 * @see Customize_Snapshot::status()
	 */
	function test_status() {
		$this->markTestIncomplete( 'This test has not been implemented.' );
	}

	/**
	 * @see Customize_Snapshot::set()
	 */
	function test_set() {
		$this->markTestIncomplete( 'This test has not been implemented.' );
	}

	/**
	 * @see Customize_Snapshot::save()
	 */
	function test_save() {
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );
		$snapshot = new Customize_Snapshot( self::$manager, null );
		$this->assertFalse( $snapshot->saved() );
		$snapshot->save();
		$this->assertTrue( $snapshot->saved() );
	}

}
