<?php

namespace CustomizeWidgetsPlus;

class Test_Widget_Posts extends Base_Test_Case {

	/**
	 * @var \WP_Customize_Manager
	 */
	public $wp_customize_manager;

	/**
	 * @var Widget_Posts
	 */
	public $module;

	function setUp() {
		require_once ABSPATH . WPINC . '/class-wp-customize-manager.php';
		parent::setUp();

		$this->plugin->widget_number_incrementing = new Widget_Number_Incrementing( $this->plugin );
	}

	function init_customizer() {
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );
		$this->wp_customize_manager = new \WP_Customize_Manager();
	}

	function init_and_migrate() {
		$this->module = new Widget_Posts( $this->plugin );
		$this->module->init();
		wp_widgets_init();
		$this->module->register_instance_post_type();
		$this->module->migrate_widgets_from_options();
	}

	/**
	 * @see Widget_Posts::__construct()
	 */
	function test_construct_unmigrated() {
		$this->assertNull( get_option( Widget_Posts::ENABLED_FLAG_OPTION_NAME, null ) );
		$instance = new Widget_Posts( $this->plugin );
		$this->assertEquals( 'no', get_option( Widget_Posts::ENABLED_FLAG_OPTION_NAME, null ) );
		wp_widgets_init();
		$this->assertNotEmpty( $instance->widget_objs );
		$this->assertFalse( has_action( 'widgets_init', array( $instance, 'prepare_widget_data' ) ) );
	}

	/**
	 * @see Widget_Posts::__construct()
	 */
	function test_construct_migrated() {
		update_option( Widget_Posts::ENABLED_FLAG_OPTION_NAME, 'yes' );
		$instance = new Widget_Posts( $this->plugin );
		$this->assertEquals( 90, has_action( 'widgets_init', array( $instance, 'store_widget_objects' ) ) );
	}

	/**
	 * @see Widget_Posts::check_widget_instance_is_empty()
	 */
	function test_check_widget_instance_is_empty() {
		$widget_posts = new Widget_Posts( $this->plugin );

		$this->assertTrue( $widget_posts->check_widget_instance_is_empty( true, array( 'post_type' => 'post' ) ) );

		$this->assertTrue( $widget_posts->check_widget_instance_is_empty( true, array(
			'post_type' => Widget_Posts::INSTANCE_POST_TYPE,
			'post_content_filtered' => '',
		) ) );

		$this->assertTrue( $widget_posts->check_widget_instance_is_empty( true, array(
			'post_type' => Widget_Posts::INSTANCE_POST_TYPE,
			'post_content_filtered' => '#### BAD',
		) ) );

		$this->assertTrue( $widget_posts->check_widget_instance_is_empty( true, array(
			'post_type' => Widget_Posts::INSTANCE_POST_TYPE,
			'post_content_filtered' => base64_encode( 'HELLO WORLD' ),
		) ) );

		$this->assertTrue( $widget_posts->check_widget_instance_is_empty( true, array(
			'post_type' => Widget_Posts::INSTANCE_POST_TYPE,
			'post_content_filtered' => base64_encode( serialize( (object) array( 'foo' => 'bar' ) ) ),
		) ) );

		$this->assertFalse( $widget_posts->check_widget_instance_is_empty( false, array(
			'post_type' => Widget_Posts::INSTANCE_POST_TYPE,
			'post_content_filtered' => base64_encode( serialize( array( 'title' => 'hello' ) ) ),
		) ) );
	}

	/**
	 * @see Widget_Posts::init()
	 */
	function test_init() {
		$instance = new Widget_Posts( $this->plugin );
		$instance->init();
		$this->assertEquals( 90, has_action( 'widgets_init', array( $instance, 'store_widget_objects' ) ) );
		$this->assertEquals( 91, has_action( 'widgets_init', array( $instance, 'prepare_widget_data' ) ) );
		$this->assertEquals( 10, has_action( 'init', array( $instance, 'register_instance_post_type' ) ) );
	}

	/**
	 * @see Widget_Posts::register_instance_post_type()
	 */
	function test_register_instance_post_type() {
		$instance = new Widget_Posts( $this->plugin );
		$post_type_obj = $instance->register_instance_post_type();
		$this->assertInternalType( 'object', $post_type_obj );
		$this->assertNotEmpty( get_post_type_object( Widget_Posts::INSTANCE_POST_TYPE ) );
		$this->assertEquals( 10, has_filter( 'wp_insert_post_data', array( $instance, 'preserve_content_filtered' ) ) );
		$this->assertEquals( 10, has_action( 'delete_post', array( $instance, 'flush_widget_instance_numbers_cache' ) ) );
		$this->assertEquals( 10, has_action( 'save_post_' . Widget_Posts::INSTANCE_POST_TYPE, array( $instance, 'flush_widget_instance_numbers_cache' ) ) );
	}

	/**
	 * @see Widget_Posts::migrate_widgets_from_options()
	 */
	function test_migrate_widgets_from_options() {
		$id_base = 'meta';
		$original_instances = get_option( "widget_{$id_base}" );
		unset( $original_instances['_multiwidget'] );

		$instance = new Widget_Posts( $this->plugin );
		$instance->init();
		wp_widgets_init();
		$instance->register_instance_post_type();

		$this->assertEmpty( $instance->get_widget_instance_numbers( $id_base ) );
		$instance->migrate_widgets_from_options();
		// @todo this should be getting called! wp_cache_delete( $id_base, 'widget_instance_numbers' );
		$this->assertGreaterThan( 0, did_action( 'widget_posts_import_success' ) );
		$shallow_instances = $instance->get_widget_instance_numbers( $id_base );
		$this->assertNotEmpty( $shallow_instances );

		$this->assertEqualSets( array_keys( $original_instances ), array_keys( $shallow_instances ) );

		foreach ( $shallow_instances as $widget_number => $post_id ) {
			$this->assertInternalType( 'int', $widget_number );
			$this->assertInternalType( 'int', $post_id );

			$post = get_post( $post_id );
			$this->assertEquals( Widget_Posts::INSTANCE_POST_TYPE, $post->post_type );
			$this->assertEquals( "$id_base-$widget_number", $post->post_name );

			$this->assertEquals( $original_instances[ $widget_number ], Widget_Posts::get_post_content_filtered( $post ) );
		}

		// Before any Widget_Settings::offsetGet() gets called, try iterating
		$settings = new Widget_Settings( $shallow_instances );
		foreach ( $settings as $widget_number => $hydrated_instance ) {
			$this->assertEquals( $original_instances[ $widget_number ], $hydrated_instance );
		}

		// Now make sure that offsetGet() also does the right thing.
		$settings = new Widget_Settings( $shallow_instances );
		foreach ( $original_instances as $widget_number => $original_instance ) {
			$this->assertArrayHasKey( $widget_number, $settings );
			$this->assertEquals( $original_instance, $settings[ $widget_number ] );
		}

		$this->assertEquals( 0, did_action( 'update_option_widget_meta' ), 'Expected update_option( "widget_meta" ) to short-circuit.' );
	}

	function test_update_post_id_lookup_cache_on_save_post() {
		$this->init_and_migrate();
		$post = $this->module->insert_widget( 'search', array( 'title' => 'Hi' ) );
		$this->assertEquals( $post->ID, wp_cache_get( $post->post_name, Widget_Posts::POST_NAME_TO_POST_ID_CACHE_GROUP ) );
	}

	function test_clear_post_id_lookup_cache_on_delete_post() {
		$this->init_and_migrate();
		$post = $this->module->insert_widget( 'search', array( 'title' => 'Hi' ) );
		$this->assertEquals( $post->ID, wp_cache_get( $post->post_name, Widget_Posts::POST_NAME_TO_POST_ID_CACHE_GROUP ) );
		wp_delete_post( $post->ID, true );
		$this->assertEquals( 0, wp_cache_get( "$post->post_name", Widget_Posts::POST_NAME_TO_POST_ID_CACHE_GROUP ) );
	}

	function test_delete_post_id_lookup_cache_on_pre_post_update() {
		$this->init_and_migrate();
		$post = $this->module->insert_widget( 'search', array( 'title' => 'Hi' ) );
		$this->assertEquals( $post->ID, wp_cache_get( $post->post_name, Widget_Posts::POST_NAME_TO_POST_ID_CACHE_GROUP ) );

		$old_post_name = $post->post_name;
		$new_post_name = $post->post_name . '123';
		$post_data = $post->to_array();
		$post_data['post_name'] = $new_post_name;
		wp_insert_post( $post_data );

		$this->assertEmpty( wp_cache_get( $old_post_name, Widget_Posts::POST_NAME_TO_POST_ID_CACHE_GROUP ) );
		$this->assertEquals( $post->ID, wp_cache_get( $new_post_name, Widget_Posts::POST_NAME_TO_POST_ID_CACHE_GROUP ) );
	}
}
