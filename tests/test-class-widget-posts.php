<?php

namespace CustomizeWidgetsPlus;

class Test_Widget_Posts extends Base_Test_Case {

	/**
	 * @var Widget_Posts
	 */
	public $module;

	function setUp() {
		parent::setUp();
		$this->plugin->widget_number_incrementing = new Widget_Number_Incrementing( $this->plugin );
	}

	function init_and_migrate() {
		$this->module = new Widget_Posts( $this->plugin );
		$this->module->init();
		wp_widgets_init();
		$this->module->register_instance_post_type();
		$this->module->migrate_widgets_from_options();

		// Make sure the widgets get re-registered now using the new settings source.
		global $wp_registered_widgets;
		$wp_registered_widgets = array();
		wp_widgets_init();
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
	 * @see Widget_Posts::default_config()
	 * @see Widget_Posts::config()
	 */
	function test_config() {
		$this->init_and_migrate();
		$this->assertInternalType( 'array', Widget_Posts::default_config() );
		$this->assertArrayHasKey( 'capability', Widget_Posts::default_config() );
		$this->assertInternalType( 'array', $this->module->config() );
		$this->assertArrayHasKey( 'capability', $this->module->config() );
		$this->assertInternalType( 'string', $this->module->config( 'capability' ) );
		$this->assertNull( $this->module->config( 'bad' ) );
	}

	/**
	 * @see Widget_Posts::is_enabled()
	 * @see Widget_Posts::enable()
	 * @see Widget_Posts::disable()
	 */
	function test_is_enabled() {
		$this->init_and_migrate();
		$this->module->enable();
		$this->assertTrue( $this->module->is_enabled() );
		$this->module->disable();
		$this->assertFalse( $this->module->is_enabled() );
		$this->module->enable();
		$this->assertTrue( $this->module->is_enabled() );
	}

	/**
	 * @see Widget_Posts::check_widget_instance_is_empty()
	 */
	function test_check_widget_instance_is_empty() {
		$widget_posts = new Widget_Posts( $this->plugin );

		$this->assertNull( $widget_posts->check_widget_instance_is_empty( null, array( 'post_type' => 'post' ) ) );

		$this->assertTrue( $widget_posts->check_widget_instance_is_empty( null, array(
			'post_type' => Widget_Posts::INSTANCE_POST_TYPE,
			'post_content' => '',
		) ) );

		$this->assertTrue( $widget_posts->check_widget_instance_is_empty( null, array(
			'post_type' => Widget_Posts::INSTANCE_POST_TYPE,
			'post_content' => '#### BAD',
		) ) );

		$this->assertTrue( $widget_posts->check_widget_instance_is_empty( null, array(
			'post_type' => Widget_Posts::INSTANCE_POST_TYPE,
			'post_content' => wp_slash( Widget_Posts::encode_json( 'HELLO WORLD' ) ),
		) ) );

		$this->assertFalse( $widget_posts->check_widget_instance_is_empty( null, array(
			'post_type' => Widget_Posts::INSTANCE_POST_TYPE,
			'post_content' => wp_slash( Widget_Posts::encode_json( (object) array( 'foo' => 'bar' ) ) ),
		) ) );

		$this->assertFalse( $widget_posts->check_widget_instance_is_empty( null, array(
			'post_type' => Widget_Posts::INSTANCE_POST_TYPE,
			'post_content' => wp_slash( Widget_Posts::encode_json( array( 'title' => 'hello \\o/' ) ) ),
		) ) );
		$this->assertFalse( $widget_posts->check_widget_instance_is_empty( null, array(
			'post_type' => Widget_Posts::INSTANCE_POST_TYPE,
			'post_content' => Widget_Posts::encode_json( array( 'title' => 'hello \\o/' ) ),
		) ) );

		$this->assertFalse( $widget_posts->check_widget_instance_is_empty( null, array(
			'post_type' => Widget_Posts::INSTANCE_POST_TYPE,
			'post_content' => wp_slash( Widget_Posts::encode_json( array() ) ),
		) ) );
		$this->assertFalse( $widget_posts->check_widget_instance_is_empty( null, array(
			'post_type' => Widget_Posts::INSTANCE_POST_TYPE,
			'post_content' => Widget_Posts::encode_json( array() ),
		) ) );

		$this->assertTrue( $widget_posts->check_widget_instance_is_empty( null, array(
			'post_type' => Widget_Posts::INSTANCE_POST_TYPE,
			'post_content' => wp_slash( Widget_Posts::encode_json( array() ) ),
			'ID' => 123,
		) ) );
		$this->assertTrue( $widget_posts->check_widget_instance_is_empty( null, array(
			'post_type' => Widget_Posts::INSTANCE_POST_TYPE,
			'post_content' => Widget_Posts::encode_json( array() ),
			'ID' => 123,
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
		$this->assertEquals( 10, has_action( 'delete_post', array( $instance, 'flush_widget_instance_numbers_cache' ) ) );
		$this->assertEquals( 10, has_action( 'save_post_' . Widget_Posts::INSTANCE_POST_TYPE, array( $instance, 'flush_widget_instance_numbers_cache' ) ) );
	}

	/**
	 * @see Widget_Posts::remove_add_new_submenu()
	 */
	function test_remove_add_new_submenu() {
		global $submenu;
		$module = new Widget_Posts( $this->plugin );
		$submenu[ 'edit.php?post_type=' . Widget_Posts::INSTANCE_POST_TYPE ][10] = array();
		$module->remove_add_new_submenu();
		$this->assertFalse( isset( $submenu[ 'edit.php?post_type=' . Widget_Posts::INSTANCE_POST_TYPE ][10] ) );
	}

	/**
	 * @see Widget_Posts::columns_header()
	 */
	function test_columns_header() {
		$module = new Widget_Posts( $this->plugin );
		$columns = $module->columns_header( array( 'cb' => '', 'date' => '' ) );
		$this->assertArrayHasKey( 'title', $columns );
		$this->assertArrayHasKey( 'widget_id', $columns );
	}

	/**
	 * @see Widget_Posts::custom_column_row()
	 */
	function test_custom_column_row() {
		$this->init_and_migrate();
		$post = $this->module->insert_widget( 'text', array( 'text' => 'hi' ) );

		ob_start();
		$this->module->custom_column_row( 'widget_id', $post->ID );
		$content = ob_get_clean();

		$this->assertNotEmpty( $content );
		$this->assertContains( $post->post_name, $content );
	}

	/**
	 * @see Widget_Posts::custom_sortable_column()
	 */
	function test_custom_sortable_column() {
		$module = new Widget_Posts( $this->plugin );
		$columns = $module->custom_sortable_column( array() );
		$this->assertArrayHasKey( 'widget_id', $columns );
		$this->assertEquals( 'post_name', $columns['widget_id'] );
	}

	/**
	 * @see Widget_Posts::filter_bulk_actions()
	 */
	function test_filter_bulk_actions() {
		$module = new Widget_Posts( $this->plugin );
		$actions = $module->filter_bulk_actions( array(
			'edit' => true,
			'trash' => true,
		) );
		$this->assertEmpty( $actions );
	}

	/**
	 * @see Widget_Posts::filter_post_row_actions()
	 */
	function test_filter_post_row_actions() {
		$this->init_and_migrate();
		$actions = $this->module->filter_post_row_actions(
			array(
				'view' => true,
				'trash' => true,
				'inline hide-if-no-js' => true,
			),
			$this->module->get_widget_post( 'search-2' )
		);
		$this->assertEmpty( $actions );
	}

	/**
	 * @see Widget_Posts::filter_posts_search()
	 */
	function test_filter_posts_search() {
		$this->init_and_migrate();

		// Search by ID base.
		$query = new \WP_Query( array(
			'post_type' => Widget_Posts::INSTANCE_POST_TYPE,
			's' => 'search',
		) );
		$posts = $query->get_posts();
		$this->assertCount( 1, $posts );
		$this->assertStringStartsWith( 'search-', $posts[0]->post_name );

		// Search by Widget ID.
		$query = new \WP_Query( array(
			'post_type' => Widget_Posts::INSTANCE_POST_TYPE,
			's' => 'search-2',
		) );
		$posts = $query->get_posts();
		$this->assertCount( 1, $posts );
		$this->assertEquals( 'search-2', $posts[0]->post_name );

		// Search by Widget ID.
		$query = new \WP_Query( array(
			'post_type' => Widget_Posts::INSTANCE_POST_TYPE,
			's' => 'no-way',
		) );
		$posts = $query->get_posts();
		$this->assertCount( 0, $posts );

		// Search by widget content.
		$post = $this->module->insert_widget( 'text', array(
			'text' => 'hello my world',
		) );
		$query = new \WP_Query( array(
			'post_type' => Widget_Posts::INSTANCE_POST_TYPE,
			's' => 'my world',
		) );
		$posts = $query->get_posts();
		$this->assertCount( 1, $posts );
		$this->assertEquals( $post->ID, $posts[0]->ID );
	}

	/**
	 * @filter Widget_Posts::filter_post_revision_field_post_content()
	 */
	function test_filter_post_revision_field_to_suppress_display() {
		$this->init_and_migrate();
		$post = $this->module->get_widget_post( 'search-2' );
		$this->assertNotEmpty( $post );
		$instance = $this->module->get_post_content( $post );
		$instance['title'] = 'hello';
		$this->module->update_widget( $post->post_name, $instance );

		$revisions = get_posts( array(
			'post_type' => 'revision',
			'post_status' => 'any',
			'post_parent' => $post->ID,
		) );
		$this->assertNotEmpty( $revisions );
		$revision = array_shift( $revisions );

		$this->assertInternalType( 'array', json_decode( $this->module->filter_post_revision_field_post_content( $revision->post_content, 'post_content', $revision, 'to' ), true ) );
	}

	/**
	 * @filter Widget_Posts::setup_metaboxes()
	 */
	function test_setup_metaboxes() {
		global $wp_meta_boxes;
		$module = new Widget_Posts( $this->plugin );
		$id = 'widget_instance';
		$screen = convert_to_screen( Widget_Posts::INSTANCE_POST_TYPE );
		$page = $screen->id;
		$context = 'normal';
		$priority = 'high';

		$this->assertTrue( empty( $wp_meta_boxes[ $page ][ $context ][ $priority ][ $id ] ) );
		$module->setup_metaboxes();
		$this->assertFalse( empty( $wp_meta_boxes[ $page ][ $context ][ $priority ][ $id ] ) );
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

			$this->assertEquals( $original_instances[ $widget_number ], Widget_Posts::get_post_content( $post ) );
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

	/**
	 * @see Widget_Posts::get_widget_post()
	 */
	function test_get_widget_post() {
		$this->init_and_migrate();

		$instance = array(
			'title' => '\\o/',
			'text' => 'Hello.',
		);

		$post_stati = array( 'publish', 'private', 'draft', 'trash' );
		$post_array = array(
			'post_content' => wp_slash( Widget_Posts::encode_json( $instance ) ),
			'post_type' => Widget_Posts::INSTANCE_POST_TYPE,
			'post_status' => 'publish',
		);

		foreach ( $post_stati as $i => $post_status ) {
			$post_name = sprintf( 'text-%d', $i + 10 );
			$post_id = wp_insert_post( array_merge( $post_array, compact( 'post_status', 'post_name' ) ) );
			$widget_id = $post_name;

			$widget_post = $this->module->get_widget_post( $widget_id );
			$this->assertInstanceOf( '\WP_Post', $widget_post, "Expected to find widget post for status $post_status." );
			$this->assertEquals( $post_id, $widget_post->ID );
		}
	}

	/**
	 * @see Widget_Posts::get_widget_instance_data()
	 */
	function test_get_widget_instance_data() {
		$instance = array(
			'title' => '\\o/',
			'text' => 'Hello.',
		);
		$widget_id = 'text-125';

		$post_id = wp_insert_post( array(
			'post_name' => $widget_id,
			'post_content' => wp_slash( Widget_Posts::encode_json( $instance ) ),
			'post_type' => Widget_Posts::INSTANCE_POST_TYPE,
			'post_status' => 'publish',
		) );
		$this->assertNotEmpty( $post_id );

		$module = new Widget_Posts( $this->plugin );

		// Get widget instance data by post ID.
		$saved_instance1 = $module->get_widget_instance_data( $post_id );
		$this->assertEquals( $instance, $saved_instance1 );

		// Get widget instance by WP_Post.
		$saved_instance2 = $module->get_widget_instance_data( get_post( $post_id ) );
		$this->assertEquals( $instance, $saved_instance2 );

		// Get widget instance by widget ID.
		$saved_instance3 = $module->get_widget_instance_data( $widget_id );
		$this->assertEquals( $instance, $saved_instance3 );
	}

	/**
	 * @see Widget_Posts::get_post_content()
	 */
	function test_get_post_content() {
		global $wpdb;
		$this->init_and_migrate();

		$defaults = array(
			'post_type' => Widget_Posts::INSTANCE_POST_TYPE,
			'post_status' => 'publish',
		);

		/*
		 * The post_content_filtered used to be revisioned. Note that in spite of
		 * doing this here, it may actually fail to store the post_content_filtered
		 * later because \_wp_post_revision_fields() uses a static var for storing the
		 * revision fields. So this is why you see below that WPDB::update() is
		 * also called.
		 */
		add_filter( '_wp_post_revision_fields', function( $fields ) {
			$fields['post_content_filtered'] = 'Raw';
			return $fields;
		} );

		$instance = array(
			'title' => '\\o/',
			'text' => 'Hello.',
		);

		// Test legacy storage in post_content_filtered only.
		$widget_id = 'text-123';
		$post_content_filtered = base64_encode( serialize( $instance ) );
		$wpdb->insert( $wpdb->posts, array_merge( $defaults, array(
			'post_name' => $widget_id,
			'post_title' => 'food',
			'post_content' => '',
			'post_content_filtered' => base64_encode( serialize( $instance ) ),
		) ) ); // WPCS: db call ok.
		$post_id = $wpdb->insert_id;
		$this->assertNotEmpty( $post_id );
		$saved_instance = Widget_Posts::get_post_content( get_post( $post_id ) );
		$this->assertEquals( $instance, $saved_instance );
		$revision_post_id = _wp_put_post_revision( $post_id );
		$wpdb->update( $wpdb->posts, compact( 'post_content_filtered' ), array( 'ID' => $revision_post_id ) ); // WPCS: db call ok. Cache ok.
		clean_post_cache( $revision_post_id ); // See comment above with _wp_post_revision_fields filter for why.
		$revision_post = get_post( $revision_post_id );
		$this->assertNotEmpty( $revision_post->post_content_filtered );
		$this->assertInternalType( 'int', $revision_post_id );
		$saved_revision_instance = Widget_Posts::get_post_content( get_post( $revision_post_id ) );
		$this->assertEquals( $saved_instance, $saved_revision_instance );

		// Test legacy storage in post_content_filtered, after importing via WP Importer.
		$widget_id = 'text-124';
		$post_content_filtered = base64_encode( serialize( $instance ) );
		$post_id = wp_insert_post( array_merge( $defaults, array(
			'post_name' => $widget_id,
			'post_content' => wp_slash( Widget_Posts::encode_json( array_merge( $instance, array( 'title' => 'Not Canonical' ) ) ) ),
			'post_content_filtered' => $post_content_filtered,
		) ) );
		$this->assertNotEmpty( $post_id );
		$saved_instance = Widget_Posts::get_post_content( get_post( $post_id ) );
		$this->assertEquals( $instance, $saved_instance );
		$revision_post_id = _wp_put_post_revision( $post_id );
		$wpdb->update( $wpdb->posts, compact( 'post_content_filtered' ), array( 'ID' => $revision_post_id ) ); // WPCS: db call ok. Cache ok.
		$this->assertInternalType( 'int', $revision_post_id );
		clean_post_cache( $revision_post_id ); // See comment above with _wp_post_revision_fields filter for why.
		$saved_revision_instance = Widget_Posts::get_post_content( get_post( $revision_post_id ) );
		$this->assertEquals( $saved_instance, $saved_revision_instance );

		// Test new storage of JSON directly in post_content.
		$widget_id = 'text-125';
		$post_id = wp_insert_post( array_merge( $defaults, array(
			'post_name' => $widget_id,
			'post_content' => wp_slash( Widget_Posts::encode_json( $instance ) ),
		) ) );
		$this->assertNotEmpty( $post_id );
		$saved_instance = Widget_Posts::get_post_content( get_post( $post_id ) );
		$this->assertEquals( $instance, $saved_instance );
		$revision_post_id = _wp_put_post_revision( $post_id );
		$this->assertInternalType( 'int', $revision_post_id );
		$saved_revision_instance = Widget_Posts::get_post_content( get_post( $revision_post_id ) );
		$this->assertEquals( $saved_instance, $saved_revision_instance );

		// This should return nothing since it is not a valid post type.
		$widget_id = 'text-126';
		$post_id = wp_insert_post( array_merge( $defaults, array(
			'post_type' => 'post',
			'post_name' => $widget_id,
			'post_content' => wp_slash( Widget_Posts::encode_json( $instance ) ),
		) ) );
		$this->assertNotEmpty( $post_id );
		$this->assertEmpty( Widget_Posts::get_post_content( get_post( $post_id ) ) );
	}

	/**
	 * @see Widget_Posts::insert_widget()
	 * @see Widget_Posts::update_widget()
	 */
	function test_insert_and_update_widget() {
		$this->init_and_migrate();
		kses_init_filters();
		$this->assertFalse( current_user_can( 'unfiltered_html' ) );

		$default_instance = array(
			'text' => '<script>document.write("hello \\\\o/" + \'\\o/\');</script>',
		);
		$instances = array(
			array( 'title' => 'Yay \o/' ),
			array( 'title' => 'Yay \\o//' ),
			array( 'title' => 'Yay \\\\o///' ),
			array( 'title' => 'Yay \\' ),
		);
		$post_objects = array();
		foreach ( $instances as $instance ) {
			$instance = array_merge( $default_instance, $instance );

			/*
			 * Test without sanitization (e.g. import)
			 */
			$post = $this->module->insert_widget( 'text', $instance, array( 'needs_sanitization' => false ) );
			$post_objects[] = $post;

			$inserted_instance = $this->module->get_widget_instance_data( $post );
			$this->assertEquals( $instance, $inserted_instance, $instance['title'] );
			$this->assertArrayNotHasKey( 'filter', $instance );
			$this->assertEquals( $instance['title'], $post->post_title );

			// Now update the same post.
			$update_instance = $instance;
			$update_instance['text'] .= '<script>hello.world()</script>';
			$post = $this->module->update_widget( $post->post_name, $update_instance, array( 'needs_sanitization' => false ) );
			$post_objects[] = $post;
			$updated_instance = $this->module->get_widget_instance_data( $post->ID );
			$this->assertEquals( $update_instance, $updated_instance );
			$this->assertStringStartsWith( 'text-', $post->post_name );

			// Ensure revisions are inserted as expected.
			$post_revisions = array_values( wp_get_post_revisions( $post, array( 'order' => 'ASC' ) ) );
			$this->assertCount( 2, $post_revisions );
			$this->assertEquals( $inserted_instance, json_decode( $post_revisions[0]->post_content, true ) );
			$this->assertEquals( $updated_instance, json_decode( $post_revisions[1]->post_content, true ) );

			/*
			 * Test with sanitization
			 */
			$sanitized_post = $this->module->insert_widget( 'text', $instance, array( 'needs_sanitization' => true ) );
			$post_objects[] = $sanitized_post;
			$sanitized_inserted_instance = $this->module->get_widget_instance_data( $sanitized_post );
			$this->assertNotEmpty( $sanitized_inserted_instance );
			$this->assertArrayHasKey( 'filter', $sanitized_inserted_instance );
			$this->assertNotEquals( $inserted_instance, $sanitized_inserted_instance );
			$this->assertEquals( wp_kses_post( stripslashes( $inserted_instance['text'] ) ), $sanitized_inserted_instance['text'] );

			// Now update the same post.
			$post_objects[] = $this->module->update_widget( $sanitized_post->post_name, $update_instance, array( 'needs_sanitization' => true ) );
			$sanitized_updated_instance = $this->module->get_widget_instance_data( $sanitized_post->ID );
			$this->assertNotEquals( $updated_instance, $sanitized_updated_instance );
			$this->assertEquals( wp_kses_post( stripslashes( $update_instance['text'] ) ), $sanitized_updated_instance['text'] );

			// Ensure revisions are inserted as expected.
			$post_revisions = array_values( wp_get_post_revisions( $sanitized_post, array( 'order' => 'ASC' ) ) );
			$this->assertCount( 2, $post_revisions );
			$this->assertEquals( $sanitized_inserted_instance, json_decode( $post_revisions[0]->post_content, true ) );
			$this->assertEquals( $sanitized_updated_instance, json_decode( $post_revisions[1]->post_content, true ) );
		}

		foreach ( $post_objects as $post ) {
			$this->assertStringStartsNotWith( '0000-00-00', $post->post_date );
			$this->assertStringStartsNotWith( '0000-00-00', $post->post_modified );
			$this->assertEquals( Widget_Posts::INSTANCE_POST_TYPE, $post->post_type );
			$this->assertEmpty( $post->post_excerpt );
			$this->assertEquals( 'publish', $post->post_status );
			$this->assertEmpty( $post->post_content_filtered );
		}
	}

	/**
	 * @see Widget_Posts::insert_widget()
	 */
	function test_unserializable_instance() {
		$this->init_and_migrate();

		$bad_instances = array();

		// Make sure failure when inserting non-JSON serializable arrays.
		$turtle = new \stdClass();
		$turtle->turtle = &$turtle;
		$bad_instances[] = array(
			'title' => 'recursion',
			'text' => $turtle,
		);
		$bad_instances[] = array(
			'title' => 'fo',
			'text' => (object) array( 'a' => 'b' ),
		);
		$bad_instances[] = array(
			'wpdb' => $GLOBALS['wpdb'],
		);

		foreach ( $bad_instances as $instance ) {
			$exception = null;
			try {
				$this->module->insert_widget( 'text', $instance, array( 'needs_sanitization' => false ) );
			} catch ( \Exception $e ) {
				$exception = $e;
			}
			$this->assertNotEmpty( $exception );
		}
	}

	/**
	 * Test fundamental concepts in how the widget instance data is stored.
	 */
	function test_storage_mechanics() {
		global $wpdb;

		$default_instance = array(
			'text' => '<script>document.write("hello \\o/" + \'\\o/\');</script>',
		);

		// @todo Make sure that content_save_pre gets applied before wp_insert_post_data.

		$instances = array(
			array( 'title' => 'Yay \o/' ),
			array( 'title' => 'Yay " \' \o/' ),
			array( 'title' => 'Yay \\o//' ),
			array( 'title' => 'Yay \\\\o///' ),
			array( 'title' => 'Yay \\' ),
			array( 'title' => "Yay \x00" ),
		);
		$widget_number = 1;
		kses_remove_filters(); // @todo This has to be removable.
		foreach ( $instances as $instance ) {
			$instance = array_merge( $default_instance, $instance );

			$widget_number += 1;
			$post_id = wp_insert_post( array(
				'title' => $instance['title'],
				'post_name' => "text-$widget_number",
				'post_type' => Widget_Posts::INSTANCE_POST_TYPE,
				'post_content' => wp_slash( Widget_Posts::encode_json( $instance ) ),
			) );
			$this->assertInternalType( 'int', $post_id );
			$post = get_post( $post_id );
			$decoded = json_decode( $post->post_content, true );
			$this->assertEquals( $decoded, $instance );
			$raw_data = $wpdb->get_var( $wpdb->prepare( "SELECT post_content FROM $wpdb->posts WHERE ID = %d", $post_id ) ); // WPCS: db call ok; cache ok.
			$this->assertEquals( $instance, json_decode( $raw_data, true ) );
		}
	}

	/**
	 * @see Widget_Posts::move_encoded_data_to_post_content()
	 */
	function test_move_encoded_data_to_post_content() {
		$this->init_and_migrate();

		$post_arrays = array(
			// Test widget instance added via insert_widget().
			array(
				'post_title' => 'test',
				'post_type' => Widget_Posts::INSTANCE_POST_TYPE,
				'post_name' => 'search-10',
				'post_content' => '',
				'post_content_filtered' => base64_encode( serialize( array( 'title' => 'test \\ "me"' ) ) ),
			),
			// Test widget instance added via WordPress Importer under WordPress 4.3.
			array(
				'post_title' => 'test',
				'post_type' => Widget_Posts::INSTANCE_POST_TYPE,
				'post_name' => 'search-11',
				'post_content' => wp_slash( Widget_Posts::encode_json( array( 'title' => 'hello' ) ) ),
				'post_content_filtered' => base64_encode( serialize( array( 'title' => 'hello' ) ) ),
			),
		);

		// Insert post using old storage mechanism.
		$original_posts = array();
		remove_filter( 'wp_insert_post_empty_content', array( $this->module, 'check_widget_instance_is_empty' ), 10 );
		foreach ( $post_arrays as $post_arr ) {
			$post_id = wp_insert_post( $post_arr );
			$this->assertNotEmpty( $post_id );
			$original_posts[ $post_id ] = get_post( $post_id );
		}
		add_filter( 'wp_insert_post_empty_content', array( $this->module, 'check_widget_instance_is_empty' ), 10, 2 );

		$success_count = 0;
		$warning_count = 0;
		add_action( 'widget_posts_content_moved_message', function( $args ) use ( &$success_count, &$warning_count ) {
			if ( 'success' === $args['type'] ) {
				$success_count += 1;
			} else if ( 'warning' === $args['type'] ) {
				$warning_count += 1;
			}
		} );

		$this->module->move_encoded_data_to_post_content( array(
			'dry-run' => true,
			'sleep-interval' => 0,
			'sleep-duration' => 0,
		) );

		$this->assertEquals( 2, $success_count );
		$this->assertEquals( 0, $warning_count );

		foreach ( $original_posts as $post_id => $old_post ) {
			$check_post = get_post( $post_id );
			$this->assertEquals( $old_post, $check_post );
			$this->assertEmpty( get_post_meta( $post_id, '_widget_post_previous_data', true ) );
		}

		$success_count = 0;
		$this->module->move_encoded_data_to_post_content( array(
			'dry-run' => false,
			'sleep-interval' => 0,
			'sleep-duration' => 0,
		) );
		$this->assertEquals( 2, $success_count );
		$this->assertEquals( 0, $warning_count );

		foreach ( $original_posts as $post_id => $old_post ) {
			$check_post = get_post( $post_id );
			$this->assertNotEquals( $old_post, $check_post );
			$this->assertNotEmpty( $check_post->post_content );
			$instance = json_decode( $check_post->post_content, true );
			$this->assertInternalType( 'array', $instance );
			$this->assertEmpty( $check_post->post_content_filtered );
			$this->assertNotEmpty( $check_post->post_content );
			$this->assertEquals( unserialize( base64_decode( $old_post->post_content_filtered ) ), $instance );
			$backup = get_post_meta( $post_id, '_widget_post_previous_data', true );
			$this->assertNotEmpty( $backup );
			$this->assertEquals( $old_post, unserialize( base64_decode( $backup ) ) );
		}
	}

	/**
	 * Check importing from WXR that encoded widget instance data in base64-encoded PHP-serialized string.
	 *
	 * @see Widget_Posts::filter_wp_import_post_data_processed()
	 */
	function test_filter_wp_import_post_data_processed() {
		$this->init_and_migrate();

		$instance = array( 'title' => 'Hi \o/', 'text' => 'Here is "text". More \'quotes\'. <script>alert(1)</script>' );

		// Make sure that initially-encoded base64-encoded PHP-serialized data gets honored.
		$postdata = apply_filters( 'wp_import_post_data_processed', array(
			'post_name' => 'text-3',
			'post_content' => base64_encode( serialize( $instance ) ),
			'post_type' => Widget_Posts::INSTANCE_POST_TYPE,
		) );
		$this->assertEquals( $instance, json_decode( base64_decode( $postdata['post_content'] ), true ) );
		$r = wp_insert_post( $postdata, true );
		$this->assertNotInstanceOf( '\WP_Error', $r, $r instanceof \WP_Error ? $r->get_error_message() : null );
		$this->assertNotEmpty( $r );
		$post = get_post( $r );
		$this->assertEquals( $instance, json_decode( $post->post_content, true ) );

		// Make sure that initially-JSON content gets encoded as base64 so that wp_insert_post() won't trip.
		$postdata = apply_filters( 'wp_import_post_data_processed', array(
			'post_name' => 'text-4',
			'post_content' => json_encode( $instance ),
			'post_type' => Widget_Posts::INSTANCE_POST_TYPE,
		) );
		$this->assertEquals( $instance, json_decode( base64_decode( $postdata['post_content'] ), true ) );
		$r = wp_insert_post( $postdata, true );
		$this->assertNotInstanceOf( '\WP_Error', $r, $r instanceof \WP_Error ? $r->get_error_message() : null );
		$this->assertNotEmpty( $r );
		$post = get_post( $r );
		$this->assertEquals( $instance, json_decode( $post->post_content, true ) );
	}

	/**
	 * @see Widget_Posts::filter_wp_insert_post_data()
	 */
	function test_filter_wp_insert_post_data() {
		$this->init_and_migrate();
		$instance = array( 'title' => 'Hi \o/', 'text' => 'Here is "text". More \'quotes\'.', 'filter' => false );
		$unslashed_json = Widget_Posts::encode_json( $instance );
		$slashed_json = wp_slash( Widget_Posts::encode_json( $instance ) );

		$post_arr = array(
			'post_type' => Widget_Posts::INSTANCE_POST_TYPE,
			'post_content' => $unslashed_json,
		);
		$data = apply_filters( 'wp_insert_post_data', $post_arr, $post_arr );
		$this->assertEquals( $instance, json_decode( wp_unslash( $data['post_content'] ), true ) );

		$post_arr = array(
			'post_type' => Widget_Posts::INSTANCE_POST_TYPE,
			'post_content' => $slashed_json,
		);
		$data = apply_filters( 'wp_insert_post_data', $post_arr, $post_arr );
		$this->assertEquals( $instance, json_decode( wp_unslash( $data['post_content'] ), true ) );
	}

	/**
	 * Ensure that we can post JSON right in as post_content if we slash it and remove kses filters.
	 */
	function test_wp_insert_post() {
		$this->init_and_migrate();
		kses_remove_filters(); // This is required
		$instance = array( 'title' => 'Hi \o/', 'text' => 'Here is "text". More \'quotes\'.<script>alert(1)</script>', 'filter' => false );

		$r = wp_insert_post( array(
			'post_name' => 'text-2',
			'post_type' => Widget_Posts::INSTANCE_POST_TYPE,
			'post_content' => wp_slash( Widget_Posts::encode_json( $instance ) ),
		), true );
		$this->assertNotInstanceOf( '\WP_Error', $r, $r instanceof \WP_Error ? $r->get_error_message() : '' );
		$post = get_post( $r );
		$this->assertEquals( $instance, json_decode( $post->post_content, true ) );
	}

	/**
	 * Make sure that wp_update_post() doesn't corrupt a widget instance containing JSON with strings with escapes.
	 */
	function test_wp_update_post() {
		$this->init_and_migrate();
		$instance = array( 'title' => 'Hi \o/', 'text' => 'Here is "text". More \'quotes\'.', 'filter' => false );
		$post = $this->module->insert_widget( 'text', $instance );

		$r = wp_update_post( array(
			'ID' => $post->ID,
			'post_excerpt' => 'Test',
		) );
		$this->assertEquals( $r, $post->ID );
		$updated_post = get_post( $r );

		$updated_instance = json_decode( $updated_post->post_content, true );
		$this->assertEquals( $instance, $updated_instance );
	}

	/**
	 * @see \CustomizeWidgetsPlus\Widget_Posts::handle_legacy_content_export
	 */
	function test_handle_legacy_content_export() {
		$this->init_and_migrate();
		$instance = array( 'title' => 'Foo & Bar', 'text' => 'Lorem Ipsum \\o/', 'filter' => false );
		$post = $this->module->insert_widget( 'text', $instance, array( 'needs_sanitization' => false ) );
		$this->assertEquals( $instance, json_decode( $post->post_content, true ) );

		// Update the post to look like a legacy post.
		$post_arr = $post->to_array();
		$post_arr['post_content_filtered'] = base64_encode( serialize( $instance ) );
		$post_arr['post_content'] = wp_kses_post( Widget_Posts::encode_json( $instance ) );
		wp_update_post( wp_slash( $post_arr ) );
		$post = get_post( $post_arr['ID'] );

		// Simulate logic for exporting the post.
		$GLOBALS['post'] = $post;
		setup_postdata( $post );
		$post_content = apply_filters( 'the_content_export', $post->post_content );

		$this->assertNotEquals( $post->post_content, $post_content );
		$this->assertEquals( $instance, json_decode( $post_content, true ) );
	}
}
