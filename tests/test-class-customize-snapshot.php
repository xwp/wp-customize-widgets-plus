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
	 * Instance of WP_Customize_Manager which is reset for each test.
	 *
	 * @var \WP_Customize_Manager
	 */
	protected $wp_customize;

	/**
	 * @var \WP_Customize_Setting
	 */
	protected $foo;

	/**
	 * @var \WP_Customize_Setting
	 */
	protected $bar;

	/**
	 * Boostrap the customizer.
	 */
	public static function setUpBeforeClass() {
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
	
	function setUp() {
		parent::setUp();
		require_once( ABSPATH . WPINC . '/class-wp-customize-manager.php' );
		$GLOBALS['wp_customize'] = new \WP_Customize_Manager();
		$this->wp_customize = $GLOBALS['wp_customize'];
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->wp_customize->add_setting( 'foo', array( 'default' => 'foo_default' ) );
		$this->wp_customize->add_setting( 'bar', array( 'default' => 'bar_default' ) );
		$this->foo = $this->wp_customize->get_setting( 'foo' );
		$this->bar = $this->wp_customize->get_setting( 'bar' );
	}

	function tearDown() {
		$this->wp_customize = null;
		unset( $GLOBALS['wp_customize'] );
		unset( $GLOBALS['wp_scripts'] );
		parent::tearDown();
	}

	/**
	 * @see Customize_Snapshot::generate_uuid()
	 */
	function test_generate_uuid() {
		$snapshot = new Customize_Snapshot( $this->wp_customize, null );
		$this->assertInternalType( 'string', $snapshot->generate_uuid() );
	}

	/**
	 * @see Customize_Snapshot::is_valid_uuid()
	 */
	function test_is_valid_uuid() {
		$snapshot = new Customize_Snapshot( $this->wp_customize, null );
		$this->assertTrue( $snapshot->is_valid_uuid( self::UUID ) );
	}

	/**
	 * @see Customize_Snapshot::uuid()
	 */
	function test_uuid() {
		$snapshot = new Customize_Snapshot( $this->wp_customize, self::UUID );
		$this->assertEquals( self::UUID, $snapshot->uuid() );
	}

	/**
	 * @see Customize_Snapshot::uuid()
	 */
	function test_uuid_throws_exception() {
		try {
			new Customize_Snapshot( $this->wp_customize, '1234-invalid-UUID' );
		} catch ( \Exception $e ) {
			$this->assertContains( 'You\'ve entered an invalid snapshot UUID.', $e->getMessage() );
			return;
		}

		$this->fail( 'An expected exception has not been raised.' );
	}

	/**
	 * @see Customize_Snapshot::set_uuid()
	 */
	function test_set_uuid() {
		$snapshot = new Customize_Snapshot( $this->wp_customize, null );
		$this->assertNotEquals( self::UUID, $snapshot->uuid() );
		$snapshot->set_uuid( self::UUID );
		$this->assertEquals( self::UUID, $snapshot->uuid() );
	}

	/**
	 * @see Customize_Snapshot::reset_uuid()
	 */
	function test_reset_uuid() {
		$snapshot = new Customize_Snapshot( $this->wp_customize, null );
		$uuid = $snapshot->uuid();
		$new_uuid = $snapshot->reset_uuid();
		$this->assertNotEquals( $uuid, $new_uuid );
		$this->assertEquals( $new_uuid, $snapshot->uuid() );
	}

	/**
	 * @see Customize_Snapshot::manager()
	 */
	function test_manager() {
		$snapshot = new Customize_Snapshot( $this->wp_customize, null );
		$this->assertEquals( $this->wp_customize, $snapshot->manager() );
		$this->assertInstanceOf( 'WP_Customize_Manager', $snapshot->manager() );
	}

	/**
	 * @see Customize_Snapshot::is_preview()
	 */
	function test_is_preview() {
		$_GET['customize_snapshot_uuid'] = self::UUID;
		$snapshot = new Customize_Snapshot( $this->wp_customize, self::UUID );
		$this->assertTrue( $snapshot->is_preview() );
	}

	/**
	 * @see Customize_Snapshot::is_preview()
	 */
	function test_is_preview_returns_false() {
		$snapshot = new Customize_Snapshot( $this->wp_customize, null );
		$this->assertFalse( $snapshot->is_preview() );
	}

	/**
	 * @see Customize_Snapshot::post()
	 */
	function test_post() {
		$snapshot = new Customize_Snapshot( $this->wp_customize, null );
		$this->assertNull( $snapshot->post() );
		$snapshot->save();
		$snapshot = new Customize_Snapshot( $this->wp_customize, $snapshot->uuid() );
		$this->assertNotNull( $snapshot->post() );
	}

	/**
	 * @see Customize_Snapshot::values()
	 */
	function test_values() {
		// Has no values when '$apply_dirty' is set to 'true'
		$snapshot = new Customize_Snapshot( $this->wp_customize, null, true );
		$snapshot->set( $this->foo, array(
			'value' => 'foo_default',
			'dirty' => false,
		) );

		$snapshot->set( $this->bar, array(
			'value' => 'bar_default',
			'dirty' => false,
		) );
		$this->assertEmpty( $snapshot->values() );
		$snapshot->save();
		$uuid = $snapshot->uuid();

		// Has values when '$apply_dirty' is set to 'false'
		$snapshot = new Customize_Snapshot( $this->wp_customize, $uuid, false );
		$this->assertNotEmpty( $snapshot->values() );

		// Has dirty values
		$snapshot = new Customize_Snapshot( $this->wp_customize, $uuid, true );
		$snapshot->set( $this->bar, array(
			'value' => 'bar_custom',
			'dirty' => true,
		) );
		$this->assertNotEmpty( $snapshot->values() );
	}

	/**
	 * @see Customize_Snapshot::settings()
	 */
	function test_settings() {
		$snapshot = new Customize_Snapshot( $this->wp_customize, null, true );
		$this->assertEmpty( $snapshot->settings() );
		$snapshot->set( $this->foo, array(
			'value' => 'foo_default',
			'dirty' => false,
		) );

		$snapshot->set( $this->bar, array(
			'value' => 'bar_default',
			'dirty' => false,
		) );
		$this->assertNotEmpty( $snapshot->settings() );
	}

	/**
	 * @see Customize_Snapshot::set()
	 * @see Customize_Snapshot::get()
	 */
	function test_set_and_get() {
		$snapshot = new Customize_Snapshot( $this->wp_customize, null );

		$this->assertEmpty( $snapshot->get( $this->foo ) );
		$snapshot->set( $this->foo, array(
			'value' => 'foo_default',
			'dirty' => false,
		) );
		$this->assertNotEmpty( $snapshot->get( $this->foo ) );
		$this->assertNotEmpty( $snapshot->get( 'foo' ) );
		$this->assertEmpty( $snapshot->get( 'bar' ) );
		$this->assertEquals( 'default', $snapshot->get( 'bar', 'default' ) );
		$this->assertNull(  $snapshot->get( 'baz' ) );
	}

	/**
	 * @see Customize_Snapshot::save()
	 */
	function test_save() {
		$snapshot = new Customize_Snapshot( $this->wp_customize, null );

		$snapshot->set( $this->foo, array(
			'value' => 'foo_default',
			'dirty' => false,
		) );

		$snapshot->set( $this->bar, array(
			'value' => 'bar_default',
			'dirty' => false,
		) );

		$this->assertFalse( $snapshot->saved() );
		$snapshot->save();
		$this->assertTrue( $snapshot->saved() );
		$this->assertEquals( 'draft', $snapshot->status() );

		$decoded = json_decode( $snapshot->post()->post_content_filtered, true );
		$this->assertEquals( $decoded['foo'], $snapshot->get( $this->foo ) );
		$this->assertEquals( $decoded['bar'], $snapshot->get( $this->bar ) );

		// Update the Snapshot content
		$snapshot = new Customize_Snapshot( $this->wp_customize, $snapshot->uuid() );
		$snapshot->set( $this->bar, array(
			'value' => 'bar_custom',
			'dirty' => true,
		) );

		$snapshot->save( 'publish' );
		$decoded = json_decode( $snapshot->post()->post_content_filtered, true );
		$this->assertEquals( $decoded['bar'], $snapshot->get( $this->bar ) );
		$this->assertEquals( 'publish', $snapshot->status() );
	}

}
