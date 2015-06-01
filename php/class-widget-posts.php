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

	const ENABLED_FLAG_OPTION_NAME = 'enabled_widget_posts';

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
	 * Blog ID for which the functionality is active.
	 *
	 * If the get_current_blog_id() does not match this later, then the option
	 * filtering is bypassed. This was put in place by request of WordPress VIP.
	 *
	 * @see Widget_Posts::filter_pre_option_widget_settings()
	 * @see Widget_Posts::filter_pre_update_option_widget_settings()
	 *
	 * @var int
	 */
	public $active_blog_id;

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
		$this->active_blog_id = get_current_blog_id();

		add_option( self::ENABLED_FLAG_OPTION_NAME, 'no', '', 'yes' );
		add_action( 'widgets_init', array( $this, 'store_widget_objects' ), 90 );

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			Widget_Posts_CLI_Command::$plugin_instance = $this->plugin;
			\WP_CLI::add_command( 'widget-posts', __NAMESPACE__ . '\\Widget_Posts_CLI_Command' );

			register_shutdown_function( function () {
				$last_error = error_get_last();
				if ( ! empty( $last_error ) && in_array( $last_error['type'], array( \E_ERROR, \E_USER_ERROR, \E_RECOVERABLE_ERROR ) ) ) {
					\WP_CLI::warning( sprintf( '%s (type: %d, line: %d, file: %s)', $last_error['message'], $last_error['type'], $last_error['line'], $last_error['file'] ) );
				}
			} );
		}

		if ( $this->is_enabled() ) {
			$this->init();
		}
	}

	/**
	 * Whether the functionality is enabled.
	 *
	 * @return bool Enabled.
	 */
	function is_enabled() {
		return 'yes' === get_option( self::ENABLED_FLAG_OPTION_NAME );
	}

	/**
	 * Enable the functionality (for the next load).
	 *
	 * @return bool Whether it was able to update the enabled state.
	 */
	function enable() {
		return update_option( self::ENABLED_FLAG_OPTION_NAME, 'yes' );
	}

	/**
	 * Disable the functionality (for the next load).
	 *
	 * @return bool Whether it was able to update the enabled state.
	 */
	function disable() {
		return update_option( self::ENABLED_FLAG_OPTION_NAME, 'no' );
	}

	/**
	 * Add the hooks for the primary functionality.
	 */
	function init() {
		add_action( 'widgets_init', array( $this, 'prepare_widget_data' ), 91 );
		add_action( 'init', array( $this, 'register_instance_post_type' ) );
	}

	/**
	 * Register the widget_instance post type.
	 *
	 * @action init
	 * @return object The post type object.
	 * @throws Exception
	 */
	function register_instance_post_type() {

		// Add required filters and actions for this post type.
		$hooks = array(
			'wp_insert_post_data' => array( $this, 'preserve_content_filtered' ),
			'delete_post' => array( $this, 'flush_widget_instance_numbers_cache' ),
			'save_post_' . static::INSTANCE_POST_TYPE => array( $this, 'flush_widget_instance_numbers_cache' ),
			'export_wp' => array( $this, 'setup_export' ),
			'wp_import_post_data_processed' => array( $this, 'filter_wp_import_post_data_processed' ),
		);
		foreach ( $hooks as $hook => $callback ) {
			// Note that add_action() and has_action() is an aliases for add_filter() and has_filter()
			if ( ! has_filter( $hook, $callback ) ) {
				add_filter( $hook, $callback, 10, PHP_INT_MAX );
			}
		}

		$post_type_object = get_post_type_object( static::INSTANCE_POST_TYPE );
		if ( $post_type_object ) {
			return $post_type_object;
		}

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
		$r = register_post_type( static::INSTANCE_POST_TYPE, $args );
		if ( is_wp_error( $r ) ) {
			throw new Exception( $r->get_error_message() );
		}

		return $r;
	}

	/**
	 * When exporting widget instance posts from WordPress, export the post_content_filtered as the post_content.
	 *
	 * @see Widget_Posts::filter_wp_import_post_data_processed()
	 *
	 * @action export_wp
	 */
	function setup_export() {
		add_action( 'the_post', function ( $post ) {
			if ( static::INSTANCE_POST_TYPE === $post->post_type  ) {
				$post->post_content = $post->post_content_filtered;
			}
		} );
	}

	/**
	 * Restore post_content into post_content_filtered when importing via WordPress Importer plugin.
	 *
	 * @see Widget_Posts::setup_export()
	 * @filter wp_import_post_data_processed
	 *
	 * @param array $postdata
	 * @return array
	 */
	function filter_wp_import_post_data_processed( $postdata ) {
		if ( static::INSTANCE_POST_TYPE === $postdata['post_type'] ) {
			$postdata['post_content_filtered'] = $postdata['post_content'];
			$instance = Widget_Posts::parse_post_content_filtered( $postdata['post_content'] );
			$postdata['post_content'] = $postdata['post_content'] = wp_json_encode( $instance, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );;
		}
		return $postdata;
	}

	/**
	 * @see \WP_Widget::get_settings()
	 *
	 * @param string $id_base Widget ID Base.
	 * @param array $instances Mapping of widget numbers to instance arrays.
	 * @param array [$options]
	 * @throws Exception
	 */
	function import_widget_instances( $id_base, array $instances, array $options = array() ) {
		if ( ! array_key_exists( $id_base, $this->widget_objs ) ) {
			throw new Exception( "Unrecognized or unsupported widget type: $id_base" );
		}
		$this->register_instance_post_type(); // In case the Widget Posts is not enabled, or init hasn't fired.

		$options = wp_parse_args( $options, array(
			'update' => false,
			'dry-run' => false,
		) );
		unset( $instances['_multiwidget'] );
		foreach ( $instances as $key => $instance ) {
			try {
				$widget_number = intval( $key );
				$widget_id = "$id_base-$widget_number";
				if ( ! $widget_number || $widget_number < 2 ) {
					throw new Exception( "Expected array key to be integer >= 2, but got $key" );
				}

				if ( ! is_array( $instance ) ) {
					throw new Exception( "Instance data for $widget_id is not an array." );
				}

				$existing_post = $this->get_widget_post( $widget_id );
				if ( ! $options['update'] && $existing_post ) {
					do_action( 'widget_posts_import_skip_existing', compact( 'widget_id', 'instance', 'widget_number', 'id_base' ) );
					continue;
				}
				$update = ! empty( $existing_post );

				$post = null;
				if ( ! $options['dry-run'] ) {
					// When importing we assume already sanitized so that the update() callback won't be called with an empty $old_instance.
					$post = $this->update_widget( $widget_id, $instance, array( 'needs_sanitization' => false ) );
				}
				do_action( 'widget_posts_import_success', compact( 'widget_id', 'post', 'instance', 'widget_number', 'id_base', 'update' ) );
			} catch ( Exception $exception ) {
				do_action( 'widget_posts_import_failure', compact( 'widget_id', 'exception', 'instance', 'widget_number', 'id_base', 'update' ) );
			}
			$this->plugin->stop_the_insanity();
		}
	}

	/**
	 * Whether or not filter_pre_option_widget_settings() and
	 * filter_pre_update_option_widget_settings() should no-op.
	 *
	 * @see Widget_Posts::migrate_widgets_from_options()
	 * @see Widget_Posts::import_widget_instances()
	 *
	 * @var bool
	 */
	public $pre_option_filters_disabled = false;

	/**
	 * Import instances from each registered widget.
	 *
	 * @param array $options
	 */
	function migrate_widgets_from_options( $options = array() ) {
		$options = wp_parse_args( $options, array(
			'update' => false,
		) );

		if ( ! $this->plugin->is_running_unit_tests() && ! defined( 'WP_IMPORTING' ) ) {
			define( 'WP_IMPORTING', true );
		}
		$this->pre_option_filters_disabled = true;
		foreach ( $this->widget_objs as $id_base => $widget_obj ) {
			$instances = $widget_obj->get_settings(); // Note that $this->pre_option_filters_disabled must be true so this returns an array, not a Widget_Settings.
			$this->import_widget_instances( $id_base, $instances, $options );
		}
		$this->pre_option_filters_disabled = false;
	}

	/**
	 *
	 * @action widgets_init, 90
	 */
	function store_widget_objects() {
		$this->widget_objs = array();
		foreach ( $this->plugin->widget_factory->widgets as $widget_obj ) {
			/** @var \WP_Widget $widget_obj */
			if ( $this->plugin->is_normal_multi_widget( $widget_obj ) ) {
				$this->widget_objs[ $widget_obj->id_base ] = $widget_obj;
			}
		}
	}

	/**
	 * This happens before Efficient_Multidimensional_Setting_Sanitizing::capture_widget_instance_data()
	 * so that we have a chance to inject Widget_Settings ArrayIterators populated with data
	 * from the widget_instance post type.
	 *
	 * @see Efficient_Multidimensional_Setting_Sanitizing::capture_widget_instance_data()
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
	 * @see Efficient_Multidimensional_Setting_Sanitizing::capture_widget_instance_data()
	 * @filter pre_option_{WP_Widget::$option_name}, 20
	 *
	 * @return Widget_Settings|mixed
	 */
	function filter_pre_option_widget_settings( $pre ) {
		$matches = array();
		$should_filter = (
			! $this->pre_option_filters_disabled
			&&
			get_current_blog_id() === $this->active_blog_id
			&&
			preg_match( '/^pre_option_widget_(.+)/', current_filter(), $matches )
		);
		if ( ! $should_filter ) {
			return $pre;
		}
		$id_base = $matches[1];

		if ( false === $pre ) {
			$instances = $this->get_widget_instance_numbers( $id_base ); // A.K.A. shallow widget instances.
			$settings = new Widget_Settings( $instances );
		} else if ( is_array( $pre ) ) {
			$settings = new Widget_Settings( $pre );
		} else if ( $pre instanceof Widget_Settings ) {
			$settings = $pre;
		} else {
			return $pre;
		}

		if ( has_filter( "option_widget_{$id_base}" ) ) {
			$filtered_settings = apply_filters( "option_widget_{$id_base}", $settings );

			// Detect when a filter blew away our nice ArrayIterator.
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
	 * @param Widget_Settings|array $value
	 * @param Widget_Settings|array $old_value
	 * @return array
	 */
	function filter_pre_update_option_widget_settings( $value, $old_value ) {
		global $wp_customize;

		$is_widget_customizer_short_circuiting = (
			isset( $wp_customize )
			&&
			$wp_customize instanceof \WP_Customize_Manager
			&&
			// Because we do not have access to $wp_customize->widgets->_is_capturing_option_updates.
			has_filter( 'pre_update_option', array( $wp_customize->widgets, 'capture_filter_pre_update_option' ), 10, 3 )
		);
		if ( $is_widget_customizer_short_circuiting ) {
			return $value;
		}

		// Get literal arrays for comparison since two separate instances can never be identical (===)
		$value_array = ( $value instanceof Widget_Settings ? $value->getArrayCopy() : $value );
		$old_value_array = ( $old_value instanceof Widget_Settings ? $old_value->getArrayCopy() : $old_value );

		$matches = array();
		$should_filter = (
			! $this->pre_option_filters_disabled
			&&
			get_current_blog_id() === $this->active_blog_id
			&&
			( $value_array !== $old_value_array )
			&&
			( $value instanceof Widget_Settings )
			&&
			preg_match( '/pre_update_option_widget_(.+)/', current_filter(), $matches )
		);
		if ( ! $should_filter ) {
			return $value;
		}
		$id_base = $matches[1];
		$widget_settings = $value;

		$instances = $widget_settings->getArrayCopy();
		foreach ( $instances as $widget_number => $instance ) {
			$widget_number = intval( $widget_number );
			if ( ! $widget_number || $widget_number < 2 || ! is_array( $instance ) ) { // Note that non-arrays are most likely widget_instance post IDs.
				continue;
			}
			$widget_id = "$id_base-$widget_number";

			// Note that sanitization isn't needed because the widget's update callback has already been called.
			$this->update_widget( $widget_id, $instance, array( 'needs_sanitization' => false ) );
			// @todo catch exception.
		}

		foreach ( $widget_settings->unset_widget_numbers as $widget_number ) {
			$widget_id = "$id_base-$widget_number";
			$post = $this->get_widget_post( $widget_id );
			if ( $post ) {
				// @todo eventually we should allow trashing
				wp_delete_post( $post->ID, true );
			}
		}
		$widget_settings->unset_widget_numbers = array();

		/*
		 * We return the old value so that update_option() short circuits,
		 * in the same way that WP_Customize_Widgets::start_capturing_option_updates() works.
		 * So note that we only do this if Widget Customizer isn't already short-circuiting things.
		 */
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

		// Widget numbers start at 2, so ensure this is the case.
		unset( $numbers[0] );
		unset( $numbers[1] );

		return $numbers;
	}

	/**
	 * Get the widget instance post associated with a given widget ID.
	 *
	 * @param string $widget_id
	 * @return \WP_Post|null
	 */
	function get_widget_post( $widget_id ) {

		$parsed_widget_id = $this->plugin->parse_widget_id( $widget_id );
		if ( ! $parsed_widget_id ) {
			return null;
		}
		if ( ! $parsed_widget_id['widget_number'] || $parsed_widget_id['widget_number'] < 2 ) {
			return null;
		}

		// @todo it may be better to just do a SQL query here: $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM $wpdb->posts WHERE post_type = %s AND post_name = %s", array( static::INSTANCE_POST_TYPE, $widget_id ) ) )
		// @todo we can do object cache for the widget ID to post ID lookup; remember to clear when the post gets deleted

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
			$instance = array();
		} else {
			$instance = static::get_post_content_filtered( $post );
		}
		return $instance;
	}

	/**
	 * Encode a widget instance array for storage in post_content_filtered.
	 *
	 * We use base64-encoding to prevent WordPress slashing to corrupt the
	 * serialized string.
	 *
	 * @param array $instance
	 * @return string base64-encoded PHP-serialized string
	 */
	static function encode_post_content_filtered( array $instance ) {
		return base64_encode( serialize( $instance ) );
	}

	/**
	 * Parse the post_content_filtered, which is a base64-encoded PHP-serialized string.
	 *
	 * @param \WP_Post $post
	 * @return array
	 */
	static function get_post_content_filtered( \WP_Post $post ) {
		if ( static::INSTANCE_POST_TYPE !== $post->post_type ) {
			return array();
		}
		if ( empty( $post->post_content_filtered ) ) {
			return array();
		}
		return static::parse_post_content_filtered( $post->post_content_filtered );
	}

	/**
	 * Parse the post_content_filtered from its base64-encodd PHP-serialized string.
	 *
	 * @param string $post_content_filtered
	 *
	 * @return array
	 */
	static function parse_post_content_filtered( $post_content_filtered ) {
		$decoded_instance = base64_decode( $post_content_filtered, true );
		if ( false !== $decoded_instance ) {
			$instance = unserialize( $decoded_instance );
		} else if ( is_serialized( $post_content_filtered, true ) ) {
			$instance = unserialize( $post_content_filtered );
		} else {
			$instance = array();
		}
		if ( ! is_array( $instance ) ) {
			$instance = array();
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
	 * @param array [$options] {
	 *     @type bool $needs_sanitization
	 * }
	 * @return \WP_Post
	 *
	 * @throws Exception
	 */
	function insert_widget( $id_base, $instance = array(), $options = array() ) {
		if ( ! array_key_exists( $id_base, $this->widget_objs ) ) {
			throw new Exception( "Unrecognized widget id_base: $id_base" );
		}
		$options = wp_parse_args( $options, array(
			'needs_sanitization' => true,
		) );

		if ( $options['needs_sanitization'] ) {
			$instance = $this->sanitize_instance( $id_base, $instance );
		}

		$widget_number = $this->plugin->widget_number_incrementing->incr_widget_number( $id_base );
		$post_arr = array(
			'post_name' => "$id_base-$widget_number",
			'post_content' => wp_json_encode( $instance, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ), // For search indexing and post revision UI.
			'post_content_filtered' => static::encode_post_content_filtered( $instance ),
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
	 * @param array [$options] {
	 *     @type bool $needs_sanitization
	 * }
	 * @throws Exception
	 * @return \WP_Post
	 */
	function update_widget( $widget_id, $instance = array(), $options = array() ) {
		$options = wp_parse_args( $options, array(
			'needs_sanitization' => true,
		) );

		$parsed_widget_id = $this->plugin->parse_widget_id( $widget_id );
		if ( empty( $parsed_widget_id ) ) {
			throw new Exception( "Invalid widget_id: $widget_id" );
		}
		if ( ! $parsed_widget_id['widget_number'] || $parsed_widget_id['widget_number'] < 2 ) {
			throw new Exception( "Widgets must start numbering at 2: $widget_id" );
		}

		$post_id = null;
		$post = $this->get_widget_post( $widget_id );
		if ( $post ) {
			$post_id = $post->ID;
		}
		if ( $options['needs_sanitization'] ) {
			if ( $post ) {
				$old_instance = $this->get_widget_instance_data( $post );
			} else {
				$old_instance = array();
			}
			$instance = $this->sanitize_instance( $parsed_widget_id['id_base'], $instance, $old_instance );
		}

		// Make sure that we have the max stored.
		$this->plugin->widget_number_incrementing->set_widget_number( $parsed_widget_id['id_base'], $parsed_widget_id['widget_number'] );

		$post_arr = array(
			'post_title' => ! empty( $instance['title'] ) ? $instance['title'] : '',
			'post_name' => $widget_id,
			'post_content' => wp_json_encode( $instance, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ), // For search indexing and post revision UI.
			'post_content_filtered' => static::encode_post_content_filtered( $instance ),
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
			$data['post_content_filtered'] = $previous_content_filtered;
		}
		return $data;
	}

	/**
	 * Flush the widget instance numbers cache when a post is updated or deleted.
	 *
	 * @param int $post_id
	 * @action save_post_widget_instance
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
