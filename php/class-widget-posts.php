<?php

namespace CustomizeWidgetsPlus;

/**
 * Store widgets in posts as opposed to options.
 *
 * This makes non_autoloaded_widget_options obsolete.
 *
 * @package CustomizeWidgetsPlus
 */
class Widget_Posts {

	const MODULE_SLUG = 'widget_posts';

	const INSTANCE_POST_TYPE = 'widget_instance';

	/**
	 * @var Plugin
	 */
	public $plugin;

	/**
	 * @var \WP_Customize_Manager
	 */
	public $manager;

	/**
	 * @var \WP_Widget[]
	 */
	public $widget_objs;

	/**
	 * @return array
	 */
	static function default_config() {
		return array();
	}

	/**
	 * Return the config entry for the supplied key, or all configs if not supplied.
	 *
	 * @param string $key
	 * @return array|mixed
	 */
	function config( $key = null ) {
		if ( is_null( $key ) ) {
			return $this->plugin->config[ static::MODULE_SLUG ];
		} else if ( isset( $this->plugin->config[ static::MODULE_SLUG ][ $key ] ) ) {
			return $this->plugin->config[ static::MODULE_SLUG ][ $key ];
		} else {
			return null;
		}
	}

	/**
	 * @param Plugin $plugin
	 */
	function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;

		add_option( 'widget_posts_migrated', false, '', 'yes' );
		add_action( 'widgets_init', array( $this, 'store_widget_objects' ), 90 );

		// @todo WP-CLI script for converting widget_options
		if ( get_option( 'widget_posts_migrated' ) ) {
			$this->init();
		}
	}

	/**
	 * Add the hooks for the primary functionality.
	 */
	function init() {
		add_action( 'widgets_init', array( $this, 'prepare_widget_data' ), 91 );
		add_action( 'init', array( $this, 'register_instance_post_type' ) );
		add_filter( 'wp_insert_post_data', array( $this, 'preserve_content_filtered' ), 10, 2 ); // @todo priority 20? Before or after Widget_Instance_Post_Edit::insert_post_name()?
		add_action( 'delete_post', array( $this, 'flush_widget_instance_numbers_cache' ) );
		add_action( 'save_post', array( $this, 'flush_widget_instance_numbers_cache' ) );
	}

	/**
	 * Register the widget_instance post type.
	 *
	 * @action init
	 */
	function register_instance_post_type() {

		$labels = array(
			'name'               => _x( 'Widget Instances', 'post type general name', 'mandatory-widgets' ),
			'singular_name'      => _x( 'Widget Instance', 'post type singular name', 'mandatory-widgets' ),
			'menu_name'          => _x( 'Widget Instances', 'admin menu', 'mandatory-widgets' ),
			'name_admin_bar'     => _x( 'Widget Instance', 'add new on admin bar', 'mandatory-widgets' ),
			'add_new'            => _x( 'Add New', 'Widget', 'mandatory-widgets' ),
			'add_new_item'       => __( 'Add New Widget Instance', 'mandatory-widgets' ),
			'new_item'           => __( 'New Widget Instance', 'mandatory-widgets' ),
			'edit_item'          => __( 'Edit Widget Instance', 'mandatory-widgets' ),
			'view_item'          => __( 'View Widget Instance', 'mandatory-widgets' ),
			'all_items'          => __( 'All Widget Instances', 'mandatory-widgets' ),
			'search_items'       => __( 'Search Widget instances', 'mandatory-widgets' ),
			'not_found'          => __( 'No widget instances found.', 'mandatory-widgets' ),
			'not_found_in_trash' => __( 'No widget instances found in Trash.', 'mandatory-widgets' ),
		);

		$args = array(
			'labels' => $labels,
			'public' => false,
			'capability_type' => static::INSTANCE_POST_TYPE,
			'map_meta_cap' => true,
			'hierarchical' => false,
			'delete_with_user' => false,
			'menu_position' => null,
			'supports' => array( 'none' ), // @todo 'revisions' when there is a UI.
		);
		register_post_type( static::INSTANCE_POST_TYPE, $args );
	}

	/**
	 * @see \WP_Widget::get_settings()
	 *
	 * @param string $id_base
	 * @param array $instances
	 * @throws Exception
	 */
	function import_widget_instances( $id_base, array $instances ) {
		if ( ! array_key_exists( $id_base, $this->widget_objs ) ) {
			throw new Exception( "Unrecognized $id_base widget ID base." );
		}
		unset( $instances['_multiwidget'] );
		foreach ( $instances as $widget_number => $instance ) {
			$widget_number = absint( $widget_number );
			if ( ! $widget_number ) {
				continue;
			}

			$widget_id = "$id_base-$widget_number";
			$this->update_widget( $widget_id, $instance );
			$this->plugin->stop_the_insanity();
		}
	}

	public $is_migrating_widgets_from_options = false;

	/**
	 *
	 */
	function migrate_widgets_from_options() {
		if ( ! $this->plugin->is_running_unit_tests() && ! defined( 'WP_IMPORTING' ) ) {
			define( 'WP_IMPORTING', true );
		}
		$this->is_migrating_widgets_from_options = true;
		foreach ( $this->widget_objs as $id_base => $widget_obj ) {
			$instances = $widget_obj->get_settings();
			$this->import_widget_instances( $id_base, $instances );
		}
		$this->is_migrating_widgets_from_options = false;
	}

	/**
	 *
	 * @action widgets_init, 90
	 */
	function store_widget_objects() {

		foreach ( $this->plugin->widget_factory->widgets as $widget_obj ) {
			/** @var \WP_Widget $widget_obj */
			if ( "widget_{$widget_obj->id_base}" !== $widget_obj->option_name ) {
				continue;
			}
			$this->widget_objs[ $widget_obj->id_base ] = $widget_obj;
		}
	}

	/**
	 *
	 * @action widgets_init, 91
	 */
	function prepare_widget_data() {
		foreach ( $this->widget_objs as $id_base => $widget_obj ) {
			add_filter( "pre_option_{$widget_obj->option_name}", array( $this, 'filter_pre_option_widget_settings' ), 20 );
			add_filter( "pre_update_option_{$widget_obj->option_name}", array( $this, 'filter_pre_update_option_widget_settings' ), 20, 2 );
		}
	}

	/**
	 * Return the Widget_Settings ArrayObject on the pre_option filter for the widget option.
	 *
	 * @param null|array $pre
	 *
	 * Note that this is after 10 so it is compatible with WP_Customize_Widgets::capture_filter_pre_get_option()
	 *
	 * @see WP_Customize_Widgets::capture_filter_pre_get_option()
	 * @filter pre_option_{WP_Widget::$option_name}, 20
	 *
	 * @return Widget_Settings|mixed
	 */
	function filter_pre_option_widget_settings( $pre ) {
		$matches = array();
		$should_filter = (
			false === $pre
			&&
			! $this->is_migrating_widgets_from_options
			&&
			preg_match( '/pre_option_widget_(.+)/', current_filter(), $matches )
		);
		if ( ! $should_filter ) {
			return $pre;
		}
		$id_base = $matches[1];
		$instances = $this->get_widget_instance_numbers( $id_base ); // A.K.A. shallow widget instances.

		if ( has_filter( "option_widget_{$id_base}" ) ) {
			$filtered_instances = array_fill_keys( array_keys( $instances ), array() );
			$filtered_instances = apply_filters( "option_widget_{$id_base}", $filtered_instances ); // Warning: filters may expect widget instances to be present.
			$filtered_instances = array_filter( $filtered_instances, function ( $value ) {
				return ! empty( $value );
			} );
			$instances = array_merge( $instances, $filtered_instances );
		}

		$settings = new Widget_Settings( $instances );

		if ( has_filter( "option_widget_{$id_base}" ) ) {
			$filtered_settings = apply_filters( "option_widget_{$id_base}", $settings );

			// Detect when a filter blew away our nice ArrayObject.
			if ( $filtered_settings !== $settings ) {
				$settings = new Widget_Settings( $filtered_settings );
			}
		}

		return $settings;
	}

	/**
	 * Note that this that this is designed to not conflict with Widget Customizer,
	 * and the capturing of update_option() calls.
	 *
	 * @see WP_Customize_Widgets::capture_filter_pre_update_option()
	 *
	 * @param array $value
	 * @param array $old_value
	 * @return array
	 */
	function filter_pre_update_option_widget_settings( $value, $old_value ) {
		$matches = array();
		$should_filter = (
			! $this->is_migrating_widgets_from_options
			&&
			( $value !== $old_value )
			&&
			( $value instanceof \ArrayAccess )
			&&
			preg_match( '/pre_update_option_widget_(.+)/', current_filter(), $matches )
		);
		if ( ! $should_filter ) {
			return $value;
		}
		$id_base = $matches[1];

		$instances = $value->getArrayCopy();
		foreach ( $instances as $widget_number => $instance ) {
			$widget_number = absint( $widget_number );
			if ( ! $widget_number || ! is_array( $instance ) ) { // Note that non-arrays are most likely widget_instance post IDs.
				continue;
			}
			$widget_id = "$id_base-$widget_number";

			$this->update_widget( $widget_id, $instance );
		}

		// Return the old value so that update_option() short circuits.
		return $old_value;
	}

	/**
	 * Get all numbers for widget instances for the given $id_base mapped to the widget_instance post IDs.
	 *
	 * @param string $id_base
	 *
	 * @return int[] Mapping of widget instance numbers to post IDs.
	 */
	function get_widget_instance_numbers( $id_base ) {
		/** @var \wpdb $wpdb */
		global $wpdb;
		$numbers = wp_cache_get( $id_base, 'widget_instance_numbers' );
		if ( false === $numbers ) {
			$post_type = static::INSTANCE_POST_TYPE;
			$widget_id_hyphen_strpos = strlen( $id_base ) + 1; // Start at the last hyphen in the widget ID

			/*
			 * Get all widget numbers from widget_instance posts, where the post_name
			 * fields consist of $id_base-$widget_number, such as "text-123".
			 * Note that there is no LIMIT on this query, but it is reasoned that
			 * this is not a risk since the amount of data returned is very small (an array of digits),
			 * and we're interacting with post_type and post_name fields, which are both
			 * indexed in MySQL. Note also that the pattern in the post_name LIKE condition does
			 * not start with % so it will not do a full table scan.
			 */
			$results = $wpdb->get_results( $wpdb->prepare(
				"
					SELECT ID as post_id, SUBSTRING( post_name, %d + 1 ) as widget_number
					FROM $wpdb->posts
					WHERE
						post_type = %s
						AND
						post_name LIKE %s
						AND
						SUBSTRING( post_name, %d + 1 ) REGEXP '^[0-9]+$'
				",
				array(
					$widget_id_hyphen_strpos,
					$post_type,
					$wpdb->esc_like( $id_base . '-' ) . '%',
					$widget_id_hyphen_strpos,
				)
			) ); // WPCS: db call ok.

			$numbers = array();
			foreach ( $results as $result ) {
				$numbers[ intval( $result->widget_number ) ] = intval( $result->post_id );
			}

			wp_cache_set( $id_base, $numbers, 'widget_instance_numbers' );
		}
		return $numbers;
	}

	/**
	 * Get the widget instance post associated witha given widget ID.
	 *
	 * @param string $widget_id
	 * @return \WP_Post|null
	 */
	function get_widget_post( $widget_id ) {
		// @todo it may be better to just do a SQL query here: $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM $wpdb->posts WHERE post_type = %s AND post_name = %s", array( static::INSTANCE_POST_TYPE, $widget_id ) ) )

		$post_stati = array_merge(
			array( 'any' ),
			array_values( get_post_stati( array( 'exclude_from_search' => true ) ) ) // otherwise, we could get duplicates
		);

		// Force is_single and is_page to be false, so that draft and scheduled posts can be queried by name
		$married = function ( $q ) {
			$q->is_single = false;
		};
		add_action( 'pre_get_posts', $married );

		$query = new \WP_Query( array(
			'post_status' => $post_stati,
			'posts_per_page' => 1,
			'post_type' => static::INSTANCE_POST_TYPE,
			'name' => $widget_id,
		) );

		remove_action( 'pre_get_posts', $married );

		$post = array_shift( $query->posts );
		return $post;
	}

	/**
	 * Get the instance data associated with a widget post.
	 *
	 * @param int|\WP_Post|string $post Widget ID, post, or widget_ID string.
	 * @return array
	 */
	function get_widget_instance_data( $post ) {
		if ( is_string( $post ) ) {
			$post = $this->get_widget_post( $post );
		} else {
			$post = get_post( $post );
		}

		if ( empty( $post ) ) {
			$this->plugin->trigger_warning( 'Invalid widget post' );
			$instance = array();
		} else if ( static::INSTANCE_POST_TYPE !== $post->post_type ) {
			$this->plugin->trigger_warning( "Invalid widget post type: $post->post_type" );
			$instance = array();
		} else if ( empty( $post->post_content_filtered ) ) {
			$instance = array(); // uninitialized widget post
		} else if ( ! is_serialized( $post->post_content_filtered, true ) ) {
			$this->plugin->trigger_warning( "Expected post $post->ID to have valid serialized data, but got: $post->post_content_filtered" );
			$instance = array();
		} else {
			$instance = unserialize( $post->post_content_filtered );
			if ( ! is_array( $instance ) ) {
				$this->plugin->trigger_warning( "Expected post $post->ID to have an array, but got: $post->post_content_filtered" );
				$instance = array();
			}
		}
		return $instance;
	}

	/**
	 * Sanitize a widget's instance array by passing it through the widget's update callback.
	 *
	 * @see \WP_Widget::update()
	 *
	 * @param string $id_base
	 * @param array $new_instance
	 * @param array $old_instance
	 * @return array
	 *
	 * @throws Exception
	 */
	public function sanitize_instance( $id_base, $new_instance, $old_instance = array() ) {
		if ( ! array_key_exists( $id_base, $this->widget_objs ) ) {
			throw new Exception( "Unrecognized widget id_base: $id_base" );
		}
		if ( ! is_array( $new_instance ) ) {
			throw new Exception( 'new_instance data must be an array' );
		}
		if ( ! is_array( $old_instance ) ) {
			throw new Exception( 'old_instance data must be an array' );
		}
		$widget_obj = $this->widget_objs[ $id_base ];
		// @codingStandardsIgnoreStart
		$instance = @ $widget_obj->update( $new_instance, $old_instance ); // silencing errors because we can get undefined index notices
		// @codingStandardsIgnoreEnd
		return $instance;
	}

	/**
	 * Create a new widget instance.
	 *
	 * @param string $id_base
	 * @param array $instance
	 * @return \WP_Post
	 *
	 * @throws Exception
	 */
	function insert_widget( $id_base, $instance = array() ) {
		if ( ! array_key_exists( $id_base, $this->widget_objs ) ) {
			throw new Exception( "Unrecognized widget id_base: $id_base" );
		}

		$instance = $this->sanitize_instance( $id_base, $instance );
		$widget_number = $this->plugin->widget_number_incrementing->incr_widget_number( $id_base );
		$post_arr = array(
			'post_name' => "$id_base-$widget_number",
			// @todo 'post_content' => wp_json_encode( $instance ), // for search indexing
			'post_content_filtered' => serialize( $instance ),
			'post_status' => 'publish',
			'post_type' => static::INSTANCE_POST_TYPE,
		);
		$r = wp_insert_post( $post_arr, true );
		if ( is_wp_error( $r ) ) {
			throw new Exception( sprintf(
				'Failed to insert/update widget instance "%s": %s',
				$post_arr['post_name'],
				$r->get_error_code()
			) );
		}
		$post = get_post( $r );
		return $post;
	}

	/**
	 * Update an existing widget.
	 *
	 * @param string $widget_id
	 * @param array $instance
	 * @throws Exception
	 * @return \WP_Post
	 */
	function update_widget( $widget_id, $instance = array() ) {
		$parsed_widget_id = $this->plugin->parse_widget_id( $widget_id );
		if ( empty( $parsed_widget_id ) ) {
			throw new Exception( "Invalid widget_id: $widget_id" );
		}

		$post_id = null;
		$old_instance = array();
		$post = $this->get_widget_post( $widget_id );
		if ( $post ) {
			$post_id = $post->ID;
			try {
				$old_instance = $this->get_widget_instance_data( $post );
			} catch ( Exception $e ) {
				$old_instance = array();
			}
		}
		$instance = $this->sanitize_instance( $parsed_widget_id['id_base'], $instance, $old_instance );

		// Make sure that we have the max stored
		$this->plugin->widget_number_incrementing->set_widget_number( $parsed_widget_id['id_base'], $parsed_widget_id['widget_number'] );

		$post_arr = array(
			'post_title' => ! empty( $instance['title'] ) ? $instance['title'] : '',
			'post_name' => $widget_id,
			// @todo 'post_content' => wp_json_encode( $instance ), // for search indexing
			'post_content_filtered' => serialize( $instance ),
			'post_status' => 'publish',
			'post_type' => static::INSTANCE_POST_TYPE,
		);
		if ( $post_id ) {
			$post_arr['ID'] = $post_id;
		}

		$r = wp_insert_post( $post_arr, true );
		if ( is_wp_error( $r ) ) {
			throw new Exception( sprintf(
				'Failed to insert/update widget instance "%s": %s',
				$post_arr['post_name'],
				$r->get_error_code()
			) );
		}
		$post = get_post( (int) $r );
		return $post;
	}

	/**
	 * Make sure that wp_update_post() doesn't clear out our content.
	 *
	 * @param array $data    An array of slashed post data.
	 * @param array $postarr An array of sanitized, but otherwise unmodified post data.
	 * @return array
	 *
	 * @filter wp_insert_post_data
	 */
	function preserve_content_filtered( $data, $postarr ) {
		$should_preserve = (
			! empty( $postarr['ID'] )
			&&
			static::INSTANCE_POST_TYPE === $postarr['post_type']
			&&
			empty( $data['post_content_filtered'] )
		);
		if ( $should_preserve ) {
			$previous_content_filtered = get_post( $postarr['ID'] )->post_content_filtered;
			$previous_content_filtered = wp_slash( $previous_content_filtered ); // >:-(
			$data['post_content_filtered'] = $previous_content_filtered;
		}
		return $data;
	}

	/**
	 * Flush the widget instance numbers cache when a post is updated or deleted.
	 *
	 * @param int $post_id
	 * @action save_post
	 * @action delete_post
	 */
	function flush_widget_instance_numbers_cache( $post_id ) {
		$post = get_post( $post_id );
		if ( static::INSTANCE_POST_TYPE !== $post->post_type ) {
			return;
		}
		$parsed_widget_id = $this->plugin->parse_widget_id( $post->post_name );
		if ( empty( $parsed_widget_id['id_base'] ) ) {
			return;
		}
		wp_cache_delete( $parsed_widget_id['id_base'], 'widget_instance_numbers' );
	}


}
