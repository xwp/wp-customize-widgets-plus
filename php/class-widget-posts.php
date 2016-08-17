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

	const WIDGET_SETTING_ID_PATTERN = '/^(?P<customize_id_base>widget_(?P<widget_id_base>.+?))\[(?P<widget_number>\d+)\]$/';

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
	 * All current instance data for registered widgets.
	 *
	 * @see WP_Customize_Widget_Setting::__construct()
	 * @see WP_Customize_Widget_Setting::value()
	 * @see WP_Customize_Widget_Setting::preview()
	 * @see WP_Customize_Widget_Setting::update()
	 * @see WP_Customize_Widget_Setting::filter_pre_option_widget_settings()
	 *
	 * @var array
	 */
	public $current_widget_type_values = array();

	/**
	 * Whether or not all of the filter_pre_option_widget_settings handlers should no-op.
	 *
	 * This is set to true at customize_save action, and returned to false at customize_save_after.
	 *
	 * @var bool
	 */
	public $disabled_filtering_pre_option_widget_settings = false;

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
		return array(
			'capability' => 'edit_theme_options',
		);
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
		} elseif ( isset( $this->plugin->config[ static::MODULE_SLUG ][ $key ] ) ) {
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

		add_option( static::ENABLED_FLAG_OPTION_NAME, 'no', '', 'yes' );
		add_action( 'widgets_init', array( $this, 'store_widget_objects' ), 90 );

		add_filter( 'wp_insert_post_empty_content', array( $this, 'check_widget_instance_is_empty' ), 10, 2 );

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			Widget_Posts_CLI_Command::$plugin_instance = $this->plugin;
			\WP_CLI::add_command( 'widget-posts', __NAMESPACE__ . '\\Widget_Posts_CLI_Command' );

			register_shutdown_function( function() {
				$last_error = error_get_last();
				if ( ! empty( $last_error ) && in_array( (int) $last_error['type'], array( \E_ERROR, \E_USER_ERROR, \E_RECOVERABLE_ERROR ), true ) ) {
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
		return 'yes' === get_option( static::ENABLED_FLAG_OPTION_NAME );
	}

	/**
	 * Enable the functionality (for the next load).
	 *
	 * @return bool Whether it was able to update the enabled state.
	 */
	function enable() {
		return update_option( static::ENABLED_FLAG_OPTION_NAME, 'yes' );
	}

	/**
	 * Disable the functionality (for the next load).
	 *
	 * @return bool Whether it was able to update the enabled state.
	 */
	function disable() {
		return update_option( static::ENABLED_FLAG_OPTION_NAME, 'no' );
	}

	/**
	 * Add the hooks for the primary functionality.
	 */
	function init() {
		add_action( 'widgets_init', array( $this, 'prepare_widget_data' ), 91 ); // runs before Widget_Posts::capture_widget_settings_for_customizer()
		add_action( 'init', array( $this, 'register_instance_post_type' ) );
		add_filter( 'post_row_actions', array( $this, 'filter_post_row_actions' ), 10, 2 );
		add_filter( sprintf( 'bulk_actions-edit-%s', static::INSTANCE_POST_TYPE ), array( $this, 'filter_bulk_actions' ) );
		add_filter( sprintf( 'manage_%s_posts_columns', static::INSTANCE_POST_TYPE ), array( $this, 'columns_header' ) );
		add_action( sprintf( 'manage_%s_posts_custom_column', static::INSTANCE_POST_TYPE ), array( $this, 'custom_column_row' ), 10, 2 );
		add_filter( sprintf( 'manage_edit-%s_sortable_columns', static::INSTANCE_POST_TYPE ), array( $this, 'custom_sortable_column' ), 10, 2 );
		add_action( 'admin_menu', array( $this, 'remove_add_new_submenu' ) );
		add_action( 'admin_print_styles', array( $this, 'hide_edit_buttons' ) );
		add_filter( 'posts_search', array( $this, 'filter_posts_search' ), 10, 2 );
		add_filter( 'the_content_export', array( $this, 'handle_legacy_content_export' ) );

		$this->add_customize_hooks();
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
			array(
				'tag' => 'delete_post',
				'callback' => array( $this, 'flush_widget_instance_numbers_cache' ),
			),
			array(
				'tag' => 'save_post_' . static::INSTANCE_POST_TYPE,
				'callback' => array( $this, 'flush_widget_instance_numbers_cache' ),
			),
			array(
				'tag' => '_wp_post_revision_field_post_content',
				'callback' => array( $this, 'filter_post_revision_field_post_content' ),
			),
			array(
				'tag' => 'wp_import_post_data_processed',
				'callback' => array( $this, 'filter_wp_import_post_data_processed' ),
			),
			array(
				'tag' => 'wp_insert_post_data',
				'callback' => array( $this, 'filter_wp_insert_post_data' ),
				'priority' => 5,
			),
			array(
				'tag' => 'load-revision.php',
				'callback' => array( $this, 'suspend_kses_for_widget_instance_revision_restore' ),
			),
		);
		foreach ( $hooks as $hook ) {
			// Note that add_action() and has_action() is an aliases for add_filter() and has_filter()
			if ( ! has_filter( $hook['tag'], $hook['callback'] ) ) {
				add_filter( $hook['tag'], $hook['callback'], isset( $hook['priority'] ) ? $hook['priority'] : 10, PHP_INT_MAX );
			}
		}

		$post_type_object = get_post_type_object( static::INSTANCE_POST_TYPE );
		if ( $post_type_object ) {
			return $post_type_object;
		}

		$labels = array(
			'name'               => _x( 'Widget Instances', 'post type general name', 'customize-widgets-plus' ),
			'singular_name'      => _x( 'Widget Instance', 'post type singular name', 'customize-widgets-plus' ),
			'menu_name'          => _x( 'Widget Instances', 'admin menu', 'customize-widgets-plus' ),
			'name_admin_bar'     => _x( 'Widget Instance', 'add new on admin bar', 'customize-widgets-plus' ),
			'add_new'            => _x( 'Add New', 'Widget', 'customize-widgets-plus' ),
			'add_new_item'       => __( 'Add New Widget Instance', 'customize-widgets-plus' ),
			'new_item'           => __( 'New Widget Instance', 'customize-widgets-plus' ),
			'edit_item'          => __( 'Inspect Widget Instance', 'customize-widgets-plus' ),
			'view_item'          => __( 'View Widget Instance', 'customize-widgets-plus' ),
			'all_items'          => __( 'All Widget Instances', 'customize-widgets-plus' ),
			'search_items'       => __( 'Search Widget instances', 'customize-widgets-plus' ),
			'not_found'          => __( 'No widget instances found.', 'customize-widgets-plus' ),
			'not_found_in_trash' => __( 'No widget instances found in Trash.', 'customize-widgets-plus' ),
		);

		$args = array(
			'labels' => $labels,
			'description' => __( 'Widget Instances.', 'customize-widgets-plus' ),
			'public' => true,
			'capability_type' => static::INSTANCE_POST_TYPE,
			'publicly_queryable' => false,
			'query_var' => false,
			'exclude_from_search' => true,
			'show_ui' => true,
			'show_in_nav_menus' => false,
			'show_in_menu' => true,
			'show_in_admin_bar' => false,
			'show_in_customizer' => false,
			'map_meta_cap' => true,
			'hierarchical' => false,
			'delete_with_user' => false,
			'menu_position' => null,
			'supports' => array( 'revisions' ),
			'register_meta_box_cb' => array( $this, 'setup_metaboxes' ),
		);

		$r = register_post_type( static::INSTANCE_POST_TYPE, $args );
		if ( is_wp_error( $r ) ) {
			throw new Exception( $r->get_error_message() );
		}

		// Now override the caps to all be the cap defined in the config
		$config = static::default_config();
		$post_type_object = get_post_type_object( static::INSTANCE_POST_TYPE );
		foreach ( array_keys( (array) $post_type_object->cap ) as $cap ) {
			$post_type_object->cap->$cap = $config['capability'];
		}

		return $r;
	}

	/**
	 * Remove the 'Add New' submenu.
	 */
	function remove_add_new_submenu() {
		global $submenu;
		unset( $submenu[ 'edit.php?post_type=' . static::INSTANCE_POST_TYPE ][10] );
	}

	/**
	 * Hide the 'Add New' button on post listing and post edit pages.
	 *
	 * @action admin_print_styles
	 */
	function hide_edit_buttons() {
		$current_screen = get_current_screen();
		$should_hide = (
			! empty( $current_screen )
			&&
			static::INSTANCE_POST_TYPE === $current_screen->post_type
			&&
			( 'widget_instance' === $current_screen->id || 'edit-widget_instance' === $current_screen->id )
		);
		if ( $should_hide ) {
			echo '<style type="text/css"> h1 > a.page-title-action { display:none; } </style>';
		}
	}

	/**
	 * Custom columns for this post type.
	 *
	 * @param  array $columns
	 * @return array
	 *
	 * @filter manage_{post_type}_posts_columns
	 */
	public function columns_header( $columns ) {
		$columns['title'] = __( 'Widget Name', 'customize-widgets-plus' );
		$columns['widget_id'] = __( 'Widget ID', 'customize-widgets-plus' );
		$custom_order = array( 'cb', 'title', 'widget_id', 'date' );
		$new_columns = array();
		foreach ( $custom_order as $col_name ) {
			$new_columns[ $col_name ] = $columns[ $col_name ];
		}
		 return $new_columns;
	}

	/**
	 * Custom column appears in each row.
	 *
	 * @param string $column  Column name
	 * @param int    $post_id Post ID
	 *
	 * @action manage_{post_type}_posts_custom_column
	 */
	public function custom_column_row( $column, $post_id ) {
		$post = get_post( $post_id );
		switch ( $column ) {
			case 'widget_id':
				echo '<code>' . esc_html( $post->post_name ) . '</code>';
				break;
		}
	}

	/**
	 * Apply custom sorting to columns
	 *
	 * @param array $columns  Column name
	 * @return array
	 */
	public function custom_sortable_column( $columns ) {
		$columns['widget_id'] = 'post_name';

		return $columns;
	}

	/**
	 * Remove the 'view' link
	 *
	 * @param array $actions
	 * @param \WP_Post $post
	 * @return array
	 */
	function filter_post_row_actions( $actions, $post ) {
		if ( static::INSTANCE_POST_TYPE === $post->post_type ) {
			unset( $actions['view'] );
			unset( $actions['trash'] );
			unset( $actions['inline hide-if-no-js'] );
		}
		return $actions;
	}

	/**
	 * Remove bulk action.
	 *
	 * @param array $actions
	 * @return array
	 */
	function filter_bulk_actions( $actions ) {
		unset( $actions['edit'] );
		unset( $actions['trash'] );
		return $actions;
	}

	/**
	 * Add searching by ID base and Widget ID.
	 *
	 * @filter posts_search
	 *
	 * @param string   $search Search SQL for WHERE clause
	 * @param \WP_Query $query  The current WP_Query object
	 *
	 * @return string
	 */
	public function filter_posts_search( $search, $query ) {
		global $wpdb;

		$is_unit_testing = function_exists( 'tests_add_filter' );
		if ( ! is_admin() && ! $is_unit_testing ) {
			return $search;
		}

		$s = $query->get( 's' );
		if ( empty( $s ) || $query->get( 'post_type' ) !== static::INSTANCE_POST_TYPE ) {
			return $search;
		}

		$where = array();

		$id_bases = array_keys( $this->widget_objs );
		$id_bases_pattern = '(' . join( '|', array_map(
			function ( $id_base ) {
				return preg_quote( $id_base, '#' );
			},
			$id_bases
		) ) . ')';

		// Widget ID.
		if ( preg_match_all( '#(\s|^)' . $id_bases_pattern . '-\d+(\s|$)#', $s, $matches ) ) {
			$widget_ids = $matches[0];
			$sql_in = array_fill( 0, count( $widget_ids ), '%s' );
			$sql_in = implode( ', ', $sql_in );
			$where[] = $wpdb->prepare( "( $wpdb->posts.post_name IN ( $sql_in ) )", $widget_ids ); // WPCS: Unprepared SQL ok.
		}

		// Widget ID base.
		if ( preg_match_all( '#(\s|^)(?P<id_base>' . $id_bases_pattern . ')(\s|$)#', $s, $matches ) ) {
			foreach ( $matches['id_base'] as $queried_id_base ) {
				$where[] = $wpdb->prepare( "( $wpdb->posts.post_name LIKE %s )", $wpdb->esc_like( $queried_id_base ) . '-%' );
			}
		}

		/*
		 * Inject additional WHERE conditions.
		 * Given an initial $search that look like the following:
		 *  AND (((wptests_posts.post_title LIKE '%search%') OR (wptests_posts.post_content LIKE '%search%')))
		 * Put the additional OR conditions after the first parenthesis.
		 */
		if ( ! empty( $where ) ) {
			$search = preg_replace(
				'/^(\s*AND\s*\()/',
				'$1' . join( ' OR ', $where ) . ' OR ',
				$search,
				1 // Only replace first.
			);
		}

		return $search;
	}

	/**
	 * Format the widget instance array for diff.
	 *
	 * @param string   $field_value     The current revision field to compare to or from.
	 * @param string   $field           The current revision field.
	 * @param \WP_Post $revision_post   The revision post object to compare to or from.
	 * @param string   $context         The context of whether the current revision is the old
	 *                                    or the new one. Values are 'to' or 'from'.
	 * @return mixed
	 */
	function filter_post_revision_field_post_content( $field_value, $field, $revision_post, $context ) {
		unset( $field, $context );
		$parent_post = get_post( $revision_post->post_parent );
		if ( static::INSTANCE_POST_TYPE === $parent_post->post_type ) {
			$instance = static::get_post_content( $revision_post );
			$field_value = static::encode_json( $instance );
		}
		return $field_value;
	}

	/**
	 * Add the metabox.
	 */
	function setup_metaboxes() {
		$id = 'widget_instance';
		$title = __( 'Data', 'customize-widgets-plus' );
		$callback = array( $this, 'render_data_metabox' );
		$screen = static::INSTANCE_POST_TYPE;
		$context = 'normal';
		$priority = 'high';
		add_meta_box( $id, $title, $callback, $screen, $context, $priority );

		remove_meta_box( 'slugdiv', $screen, 'normal' );
	}

	/**
	 * Render the metabox.
	 *
	 * @param \WP_Post $post
	 */
	function render_data_metabox( $post ) {
		$widget_instance = $this->get_widget_instance_data( $post );

		echo '<h2><code>' . esc_html( $post->post_name ) . '</code></h2>';

		$allowed_tags = array(
			'details' => array( 'class' => true ),
			'pre' => array(),
			'summary' => array(),
		);
		$rendered_instance = sprintf( '<pre>%s</pre>', esc_html( static::encode_json( $widget_instance ) ) );
		echo wp_kses(
			apply_filters( 'rendered_widget_instance_data', $rendered_instance, $widget_instance, $post ),
			$allowed_tags
		);
	}

	/**
	 * Encode JSON with pretty formatting.
	 *
	 * @param $value
	 * @return string
	 */
	static function encode_json( $value ) {
		$flags = 0;
		if ( defined( '\JSON_PRETTY_PRINT' ) ) {
			$flags |= \JSON_PRETTY_PRINT;
		}
		if ( defined( '\JSON_UNESCAPED_SLASHES' ) ) {
			$flags |= \JSON_UNESCAPED_SLASHES;
		}
		return wp_json_encode( $value, $flags );
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
	 * Make sure that JSON post_content gets represented base64-encoded PHP-serialized string gets converted to JSON.
	 *
	 * @filter wp_import_post_data_processed
	 * @param array $postdata
	 * @return array
	 */
	function filter_wp_import_post_data_processed( $postdata ) {
		if ( static::INSTANCE_POST_TYPE !== $postdata['post_type'] ) {
			return $postdata;
		}

		if ( empty( $postdata['post_content'] ) ) {
			return $postdata;
		}

		$instance = $this->parse_instance_from_post_content( $postdata['post_content'] );
		if ( is_array( $instance ) && $this->is_json_serializable( $instance ) ) {
			$postdata['post_content'] = base64_encode( static::encode_json( $instance ) );
		}
		return $postdata;
	}

	/**
	 * Parse instance array out of JSON stored in post_content which may or may not be slashed.
	 *
	 * @param string $post_content JSON or base64-encoded PHP-serialized string or JSON.
	 *
	 * @return array|null
	 */
	protected function parse_instance_from_post_content( $post_content ) {
		if ( empty( $post_content ) ) {
			return null;
		}

		/*
		 * Allow widget instance to be base64-encoded to workaround sanitize_post()
		 * called in wp_insert_post() which applies content_save_pre filters,
		 * which include disastrous kses filters which can corrupt the data.
		 * Allow PHP-serialized content to be base64-encoded in addition to JSON
		 * being base64-encoded.
		 */
		$decoded = base64_decode( trim( $post_content ), true );
		if ( false !== $decoded ) {
			if ( is_serialized( $decoded, true ) ) {
				$instance = unserialize( $decoded );
				if ( ! is_array( $instance ) ) {
					return null;
				} else {
					return $instance;
				}
			} else {
				$post_content = $decoded;
			}
		}

		$instance = json_decode( $post_content, true );
		if ( ! is_array( $instance ) ) {
			$instance = json_decode( wp_unslash( $post_content ), true );
		}
		if ( ! is_array( $instance ) ) {
			return null;
		}
		return $instance;
	}

	/**
	 * Check if the widget instance is empty; make sure that JSON is not malformed.
	 *
	 * The JSON in the post_content can be either slashed or unslashed. Decoding will be attempted for both.
	 *
	 * @param bool $is_empty Is empty.
	 * @param array $post_arr Slashed post array.
	 *
	 * @return bool Empty.
	 */
	function check_widget_instance_is_empty( $is_empty, $post_arr ) {
		if ( static::INSTANCE_POST_TYPE !== $post_arr['post_type'] ) {
			return $is_empty;
		}

		if ( empty( $post_arr['post_content'] ) ) {
			return true;
		}

		// If unable to parse an array from the post content, then the widget_instance should be blocked from being saved.
		$instance = $this->parse_instance_from_post_content( $post_arr['post_content'] );
		if ( ! is_array( $instance ) ) {
			return true;
		}

		// Make sure that the widget instance can be serialized to JSON.
		if ( ! $this->is_json_serializable( $instance ) ) {
			return true;
		}

		$is_new_post = empty( $post_arr['ID'] );

		/*
		 * Failsafe to prevent saving widgets erroneously emptied
		 * Originally introduced to prevent this problem to be happening by a combination
		 * of Customize Contextual Settings and Optimized Widget Registration.
		 */
		if ( empty( $instance ) && ! $is_new_post ) {
			return true;
		}

		return false;
	}

	/**
	 * Ensure that post_content is 'properly'-slashed JSON to deal with the unfortunate wp_unslash() in wp_insert_post().
	 *
	 * @filter wp_insert_post_data
	 * @param array $data An array of slashed post data.
	 * @return array      An array of slash-ensured post data.
	 */
	function filter_wp_insert_post_data( $data ) {
		// Only apply filter to the widget_instance post type.
		if ( static::INSTANCE_POST_TYPE !== $data['post_type'] ) {
			return $data;
		}

		$instance = $this->parse_instance_from_post_content( $data['post_content'] );
		if ( ! is_array( $instance ) ) {
			/*
			 * This condition should never be true because
			 * \CustomizeWidgetsPlus\Widget_Posts::check_widget_instance_is_empty()
			 * will have already short-circuited wp_insert_post().
			 */
			return $data;
		}

		/*
		 * Note that we have to slash the post_content and post_title because wp_insert_post()
		 * does wp_unslash() on the post array data right after the wp_insert_post_data
		 * filter is applied:
		 * https://github.com/xwp/wordpress-develop/blob/4832d8d9330f8fde2599c95f664af37ee6c0d42e/src/wp-includes/post-functions.php#L3177
		 */
		$data['post_content'] = wp_slash( static::encode_json( $instance ) );
		if ( isset( $instance['title'] ) ) {
			$data['post_title'] = wp_slash( $instance['title'] );
		}

		return $data;
	}

	/**
	 * Update database to store widget instance data as JSON in post_content instead
	 * of base64-encoded PHP-serialized data in post_content_filtered.
	 *
	 * This this method will be removed once all sites have migrated their data.
	 *
	 * @param array $args
	 */
	function move_encoded_data_to_post_content( $args ) {
		/** @var \wpdb $wpdb  */
		global $wpdb;

		$posts_per_page = 100;
		$page = 1;

		$args = wp_parse_args( $args, array(
			'dry-run' => false,
			'sleep-interval' => 50,
			'sleep-duration' => 5,
		) );

		$post_stati = array_values( get_post_stati() );
		$post_stati[] = 'any';

		$post_count = array_sum( get_object_vars( wp_count_posts( static::INSTANCE_POST_TYPE ) ) );
		$already_processed_count = 0;
		$warning_count = 0;
		$newly_processed_count = 0;
		$current_post_number = 0;

		$post = null;

		$do_message = function ( $type, $text ) use (
			$post_count,
			$post,
			$already_processed_count,
			$warning_count,
			$newly_processed_count,
			$current_post_number
		) {
			do_action( 'widget_posts_content_moved_message', compact(
				'type',
				'text',
				'post',
				'post_count',
				'already_processed_count',
				'warning_count',
				'newly_processed_count',
				'current_post_number'
			) );
		};

		do {
			$posts = get_posts( array(
				'posts_per_page' => $posts_per_page,
				'paged' => $page,
				'post_type' => static::INSTANCE_POST_TYPE,
				'post_status' => $post_stati,
				'orderby' => 'ID',
				'order' => 'asc',
				'offset' => null, // Temporarily needed until https://core.trac.wordpress.org/changeset/35417 pulled.
			) );

			foreach ( $posts as $post ) {
				$current_post_number += 1;

				// Skip if already migrated.
				if ( empty( $post->post_content_filtered ) && is_array( json_decode( $post->post_content, true ) ) ) {
					$already_processed_count += 1;
					$do_message( 'info', "$post->post_name (post_id:$post->ID) already updated" );
					continue;
				}

				$parsed = $this->plugin->parse_widget_id( $post->post_name );
				if ( empty( $this->widget_objs[ $parsed['id_base'] ] ) ) {
					$warning_count += 1;
					$do_message( 'warning', "$post->post_name (post_id:$post->ID) has an unrecognized id_base: " . $parsed['id_base'] );
					continue;
				}

				$decoded = base64_decode( $post->post_content_filtered, true );
				if ( false === $decoded ) {
					$warning_count += 1;
					$do_message( 'warning', "$post->post_name (post_id:$post->ID) post_content_filtered not base64-encoded" );
					continue;
				}

				$instance = unserialize( $decoded );
				if ( ! is_array( $instance ) ) {
					$warning_count += 1;
					$do_message( 'warning', "$post->post_name (post_id:$post->ID) base64-decoded post_content_filtered not serialized array" );
					continue;
				}

				if ( ! $this->is_json_serializable( $instance ) ) {
					$warning_count += 1;
					$do_message( 'warning', "$post->post_name (post_id:$post->ID) unable to encode as JSON." );
				}

				$json = static::encode_json( $instance );

				if ( ! empty( $args['dry-run'] ) ) {
					$newly_processed_count += 1;
					$do_message( 'success', "[DRY-RUN] $post->post_name (post_id:$post->ID) would update storage" );
				} else {
					// Backup the post data in postmeta just in case something goes wrong.
					$r = add_post_meta(
						$post->ID,
						'_widget_post_previous_data',
						base64_encode( serialize( $post ) ), // Encoding is to prevent wp_unslash() from corrupting serialized data.
						false /* Unique. */
					);
					if ( false === $r ) {
						$warning_count += 1;
						$do_message( 'warning', "$post->post_name (post_id:$post->ID) Failed to backup post object, so skipping." );
						continue;
					}

					$r = $wpdb->update(
						$wpdb->posts,
						array(
							// Note that we do *not* wp_slash() this here because we're not going through wp_insert_post().
							'post_content' => $json,
							// Remove any existing base64-encoded PHP-serialized data.
							'post_content_filtered' => '',
						),
						array(
							'ID' => $post->ID,
						)
					); // WPCS: db call ok. Cache ok.
					if ( false === $r ) {
						$do_message( 'warning', "$post->post_name (post_id:$post->ID): " . $wpdb->last_error );
					} else {
						$newly_processed_count += 1;
						$do_message( 'success', "$post->post_name (post_id:$post->ID) updated storage ($current_post_number of $post_count)" );
						clean_post_cache( $post );

						// @todo Do other update actions?
					}
				}

				$should_sleep = (
					empty( $args['dry-run'] )
					&&
					$newly_processed_count > 0
					&&
					$args['sleep-interval'] > 0
					&&
					0 === $newly_processed_count % $args['sleep-interval']
					&&
					$args['sleep-duration'] > 0
				);
				if ( $should_sleep ) {
					$do_message( 'info', sprintf( 'Sleeping a %d second(s) to let the DB catch up.', $args['sleep-duration'] ) );
					sleep( $args['sleep-duration'] );
				}
			}
			$page += 1;

			// Free up memory
			$this->stop_the_insanity();
		} while ( count( $posts ) );

		$do_message( 'info', "Already processed: $already_processed_count" );
		$do_message( 'info', "Skipped due to warnings: $warning_count" );
		if ( ! empty( $args['dry-run'] ) ) {
			$do_message( 'info', "Would be processed (but not due to dry-run): $newly_processed_count" );
		} else {
			$do_message( 'info', "Encoded-content moved to post_content: $newly_processed_count" );
		}
	}

	/**
	 * Capture the WP_Widget instances for later reference.
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
	 * Inject Widget_Settings ArrayIterators populated with data from the widget_instance post type.
	 *
	 * This happens before Widget_posts::capture_widget_settings_for_customizer() so that we have a chance to
	 * inject Widget_Settings ArrayIterators populated with data from the widget_instance post type.
	 *
	 * @see Widget_Posts::capture_widget_settings_for_customizer()
	 * @action widgets_init, 91
	 */
	function prepare_widget_data() {
		foreach ( $this->widget_objs as $id_base => $widget_obj ) {
			add_filter( "pre_option_{$widget_obj->option_name}", array( $this, 'filter_pre_option_widget_settings' ), 20 );
			add_filter( "pre_update_option_{$widget_obj->option_name}", array( $this, 'filter_pre_update_option_widget_settings' ), 20, 2 );
		}
	}

	/**
	 * Return the Widget_Settings ArrayIterator on the pre_option filter for the widget option.
	 *
	 * @param null|array $pre
	 *
	 * Note that this is after 10 so it is compatible with WP_Customize_Widgets::capture_filter_pre_get_option()
	 *
	 * @see WP_Customize_Widgets::capture_filter_pre_get_option()
	 * @see Widget_Posts::capture_widget_settings_for_customizer()
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
		} elseif ( is_array( $pre ) ) {
			$settings = new Widget_Settings( $pre );
		} elseif ( $pre instanceof Widget_Settings ) {
			$settings = $pre;
		} else {
			return $pre;
		}

		if ( has_filter( "option_widget_{$id_base}" ) ) {
			$filtered_settings = apply_filters( "option_widget_{$id_base}", $settings );

			// Detect when a filter blew away our nice ArrayIterator.
			if ( is_array( $filtered_settings ) ) {
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
			has_filter( 'pre_update_option', array( $wp_customize->widgets, 'capture_filter_pre_update_option' ) )
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
			preg_match( '/pre_update_option_widget_(.+)/', current_filter(), $matches )
		);
		if ( ! $should_filter ) {
			return $value;
		}
		$id_base = $matches[1];

		foreach ( $value_array as $widget_number => $instance ) {
			$widget_number = intval( $widget_number );
			if ( ! $widget_number || $widget_number < 2 || ! is_array( $instance ) ) { // Note that non-arrays are most likely widget_instance post IDs.
				continue;
			}
			$widget_id = "$id_base-$widget_number";

			try {
				// Note that sanitization isn't needed because the widget's update callback has already been called.
				$this->update_widget( $widget_id, $instance, array( 'needs_sanitization' => false ) );
			} catch ( Exception $exception ) {
				add_filter( 'customize_save_response', function( $data ) {
					$data['widget_save_failure'] = true;
					return $data;
				} );
			}
		}

		if ( $value instanceof Widget_Settings ) {
			foreach ( $value->unset_widget_numbers as $widget_number ) {
				$widget_id = "$id_base-$widget_number";
				$post = $this->get_widget_post( $widget_id );
				if ( $post ) {
					// @todo eventually we should allow trashing
					wp_delete_post( $post->ID, true );
				}
			}
			$value->unset_widget_numbers = array();
		}

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
		/*
		 * We have to explicitly query all post stati due to how WP_Query::get_posts()
		 * is written. See https://github.com/xwp/wordpress-develop/blob/4.3.1/src/wp-includes/query.php#L3542-L3549
		 */
		$post_stati = array_values( get_post_stati() );
		$post_stati[] = 'any';

		// Filter to ensure that special characters in post_name get sanitize_title()'ed-away (unlikely for widget posts).
		$filter_sanitize_title = function ( $title, $raw_title ) {
			unset( $title );
			$title = esc_sql( $raw_title );
			return $title;
		};

		$args = array(
			'name'             => $widget_id,
			'post_type'        => static::INSTANCE_POST_TYPE,
			'post_status'      => $post_stati,
			'posts_per_page'   => 1,
			'suppress_filters' => false,
		);

		add_filter( 'sanitize_title', $filter_sanitize_title, 10, 2 );
		$posts = get_posts( $args );
		remove_filter( 'sanitize_title', $filter_sanitize_title, 10 );

		if ( empty( $posts ) ) {
			return null;
		}

		$post = array_shift( $posts );
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
			$instance = static::get_post_content( $post );
		}
		return $instance;
	}

	/**
	 * Get the instance array out of the post_content, which is Base64-encoded PHP-serialized string, or from legacy location.
	 *
	 * A post revision for a widget_instance may also be supplied.
	 *
	 * @param \WP_Post $post  A widget_instance post or a revision post.
	 * @return array
	 */
	static function get_post_content( \WP_Post $post ) {
		if ( static::INSTANCE_POST_TYPE !== $post->post_type ) {
			$parent_post = null;
			if ( 'revision' === $post->post_type ) {
				$parent_post = get_post( $post->post_parent );
			}
			if ( ! $parent_post || static::INSTANCE_POST_TYPE !== $parent_post->post_type ) {
				return array();
			}
		}

		// Handle legacy storage location of widget instance data.
		$decoded = base64_decode( trim( $post->post_content_filtered ), true );
		if ( false !== $decoded && is_serialized( $decoded, true ) ) {
			$instance = unserialize( $decoded );
			return $instance;
		}

		// Try to parse (legacy) post_content_filtered as JSON.
		$content = json_decode( $post->post_content_filtered, true );
		if ( is_array( $content ) ) {
			return $content;
		}

		// Widget instance is stored as JSON in post_content.
		$instance = json_decode( $post->post_content, true );
		if ( is_array( $instance ) ) {
			return $instance;
		}

		return array();
	}

	/**
	 * If a widget_instance post's content hasn't yet had its data migrated from
	 * the legacy post_content_filtered location and into post_content, ensure
	 * that the exported content will be the post_content_filtered instead of
	 * the post_content. If post_content_filtered is present, then this means
	 * that the post_content has had KSES apply and there are HTML entities in the
	 * JSON code.
	 *
	 * @see \CustomizeWidgetsPlus\Widget_Posts::move_encoded_data_to_post_content()
	 * @param string $post_content
	 * @return string Post Content
	 */
	function handle_legacy_content_export( $post_content ) {
		$post = get_post();
		if ( static::INSTANCE_POST_TYPE === $post->post_type ) {
			$instance = $this->get_post_content( $post );
			if ( is_array( $instance ) ) {
				$post_content = static::encode_json( $instance );
			}
		}
		return $post_content;
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
	 * Validate that a widget instance can be serialized to JSON.
	 *
	 * @param array $instance Widget instance.
	 * @return bool Whether $instance can be serialized to JSON.
	 */
	protected function is_json_serializable( $instance ) {
		// @codingStandardsIgnoreStart
		$encoded = @json_encode( $instance );
		// @codingStandardsIgnoreEnd
		if ( json_last_error() ) {
			return false;
		}
		if ( json_decode( $encoded, true ) !== $instance ) {
			return false;
		}
		return true;
	}

	/**
	 * Whether kses filters on content_save_pre are added.
	 *
	 * @var bool
	 */
	protected $kses_suspended = false;

	/**
	 * Suspend kses which runs on content_save_pre and can corrupt JSON in post_content.
	 *
	 * @see \sanitize_post()
	 */
	function suspend_kses() {
		if ( false !== has_filter( 'content_save_pre', 'wp_filter_post_kses' ) ) {
			$this->kses_suspended = true;
			kses_remove_filters();
		}
	}

	/**
	 * Restore kses which runs on content_save_pre and can corrupt JSON in post_content.
	 *
	 * @see \sanitize_post()
	 */
	function restore_kses() {
		if ( $this->kses_suspended ) {
			kses_init_filters();
			$this->kses_suspended = false;
		}
	}

	/**
	 * Make sure that restoring widget_instance revisions doesn't involve kses corrupting the post_content.
	 *
	 * Ideally there would be an action like pre_wp_restore_post_revision instead
	 * of having to hack into the load-revision.php action. But even more ideally
	 * we should be able to disable such content_save_pre filters from even applying
	 * for certain post types, such as those which store JSON in post_content.
	 *
	 * @action load-revision.php
	 */
	function suspend_kses_for_widget_instance_revision_restore() {
		if ( ! isset( $_GET['revision'] ) ) { // WPCS: input var ok.
			return;
		}
		if ( ! isset( $_GET['action'] ) || 'restore' !== $_GET['action'] ) { // WPCS: input var ok, sanitization ok.
			return;
		}
		$revision_post_id = intval( $_GET['revision'] ); // WPCS: input var ok.
		if ( $revision_post_id <= 0 ) {
			return;
		}
		$revision_post = wp_get_post_revision( $revision_post_id );
		if ( empty( $revision_post ) ) {
			return;
		}
		$post = get_post( $revision_post->post_parent );
		if ( empty( $post ) || static::INSTANCE_POST_TYPE !== $post->post_type ) {
			return;
		}

		$this->suspend_kses();
		$that = $this;
		add_action( 'wp_restore_post_revision', function() use ( $that ) {
			$that->restore_kses();
		} );
	}

	/**
	 * Create a new widget instance.
	 *
	 * The widget instance is stored in post_content as JSON.
	 *
	 * @throws Exception
	 *
	 * @param string $id_base
	 * @param array $instance
	 * @param array [$options] {
	 *     @type bool $needs_sanitization
	 * }
	 * @return \WP_Post
	 */
	function insert_widget( $id_base, $instance = array(), $options = array() ) {
		return $this->update_widget( $id_base, $instance, $options );
	}

	/**
	 * Update an existing widget (or insert a new one).
	 *
	 * The widget instance is stored in post_content as JSON. Note that this does
	 * not use wp_insert_post() because it has major issues with indiscriminately
	 * applying content_save_pre filters that included kses processing that
	 * corrupts JSON data, in addition to functions that slash/unslash that can
	 * also corrupt the data.
	 *
	 * @global $wpdb
	 * @throws Exception
	 *
	 * @param string $widget_id Widget ID or ID base (for insertion).
	 * @param array $instance
	 * @param array [$options] {
	 *     @type bool $needs_sanitization
	 * }
	 * @return \WP_Post
	 */
	function update_widget( $widget_id, $instance = array(), $options = array() ) {
		$options = wp_parse_args( $options, array(
			'needs_sanitization' => true,
		) );

		if ( ! is_array( $instance ) ) {
			throw new Exception( 'Instance must be an array.' );
		}
		if ( array_key_exists( $widget_id, $this->widget_objs ) ) {
			$id_base = $widget_id;
			$widget_number = null;
			$widget_id = null;
		} else {
			$parsed_widget_id = $this->plugin->parse_widget_id( $widget_id );
			if ( empty( $parsed_widget_id ) ) {
				throw new Exception( "Invalid widget_id: $widget_id" );
			}
			$id_base = $parsed_widget_id['id_base'];
			if ( $parsed_widget_id['widget_number'] < 2 ) {
				throw new Exception( "Widgets must start numbering at 2: $widget_id" );
			}
			$widget_number = $parsed_widget_id['widget_number'];
		}
		if ( ! array_key_exists( $id_base, $this->widget_objs ) ) {
			throw new Exception( "Unrecognized widget id_base: $id_base" );
		}

		if ( empty( $widget_number ) ) {
			$widget_number = $this->plugin->widget_number_incrementing->incr_widget_number( $id_base );
		}

		$widget_id = "$id_base-$widget_number";
		$widget_post = $this->get_widget_post( $widget_id );
		$is_insert = empty( $widget_post );

		// Sanitize the widget instance array.
		if ( $options['needs_sanitization'] ) {
			if ( $widget_post ) {
				$old_instance = $this->get_widget_instance_data( $widget_post );
			} else {
				$old_instance = array();
			}
			$instance = $this->sanitize_instance( $id_base, $instance, $old_instance );
		}

		/*
		 * Fail-safe to prevent saving widgets erroneously emptied
		 * Originally introduced to prevent this problem to be happening by a combination
		 * of Customize Contextual Settings and Optimized Widget Registration.
		 */
		if ( ! $is_insert && empty( $instance ) ) {
			throw new Exception( 'Unexpected updating widget to empty instance array.' );
		}

		if ( ! $this->is_json_serializable( $instance ) ) {
			throw new Exception( 'Instance cannot be serialized to JSON losslessly.' );
		}

		// Make sure that we have the max stored.
		$this->plugin->widget_number_incrementing->set_widget_number( $id_base, $widget_number );

		$post_content = static::encode_json( $instance );

		if ( $widget_post ) {
			$post_array = $widget_post->to_array();
		} else {
			$post_array = array(
				'post_type' => static::INSTANCE_POST_TYPE,
				'post_name' => $widget_id,
				'post_author' => get_current_user_id(),
				'post_status' => 'publish',
			);
		}

		$post_array = array_merge( $post_array, array(
			'post_content' => $post_content,
			'post_content_filtered' => '', // Make sure any legacy base64-encoded PHP-serialized data is removed.
		) );

		$post_array = wp_slash( $post_array ); // Weep ;-(

		/*
		 * Note that we have to call suspend_kses() because sanitize_post() gets
		 * called in wp_insert_post() before any actions are fired, so we do not
		 * have a chance to automatically suspend the JSON-destructive kses filters.
		 */
		$this->suspend_kses();
		$r = wp_insert_post( $post_array, true );
		if ( ! is_wp_error( $r ) && $is_insert ) {
			// Make sure post revision gets added for insert.
			wp_save_post_revision( $r );
		}
		$this->restore_kses();
		if ( is_wp_error( $r ) ) {
			throw new Exception( $r->get_error_code() );
		}

		$widget_post = get_post( $r );

		return $widget_post;
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

	/*
	 * Begin Customizer-specific code.
	 *
	 * The code here was originally contained in a module called
	 * Efficient_Multidimensional_Setting_Sanitizing, but with the work done in
	 * WordPress Trac #32103, Customizer settings are now efficiently saved by
	 * default. So what is needed primarily here is an integration for Widget
	 * Posts to prevent Widget_Settings instances from being manipulated by
	 * WP_Customize_Setting::multidimensional_replace() which has the effect of
	 * dropping all widget instances that are not part of the replacement.
	 *
	 * See https://core.trac.wordpress.org/ticket/32103
	 */

	/**
	 * Whether or not add_customize_hooks() has completed; prevents duplicate hooks.
	 *
	 * @var bool
	 */
	protected $customize_hooks_added = false;

	/**
	 * Set up hooks for Customizer integration.
	 *
	 * @return bool
	 */
	function add_customize_hooks() {
		global $wp_customize;
		if ( empty( $wp_customize ) || empty( $wp_customize->widgets ) || $this->customize_hooks_added ) {
			return false;
		}
		$this->customize_hooks_added = true;
		$this->manager = $wp_customize;

		add_filter( 'customize_dynamic_setting_class', array( $this, 'filter_dynamic_setting_class' ), 10, 2 );
		add_filter( 'widget_customizer_setting_args', array( $this, 'filter_widget_customizer_setting_args' ) );
		add_action( 'widgets_init', array( $this, 'capture_widget_settings_for_customizer' ), 92 ); // runs after Widget_Posts::prepare_widget_data()

		// Note that customize_register happens at wp_loaded, so we register the settings just before
		$priority = has_action( 'wp_loaded', array( $this->manager, 'wp_loaded' ) );
		$priority -= 1;
		add_action( 'wp_loaded', array( $this, 'register_widget_instance_settings_early' ), $priority );

		add_action( 'customize_save', array( $this, 'disable_filtering_pre_option_widget_settings' ) );
		add_action( 'customize_save_after', array( $this, 'enable_filtering_pre_option_widget_settings' ) );
		return true;
	}

	/**
	 * This must happen immediately before \WP_Customize_Widgets::customize_register(),
	 * in whatever scenario it gets called per \WP_Customize_Widgets::schedule_customize_register()
	 *
	 * @action wp_loaded
	 * @see \WP_Customize_Widgets::schedule_customize_register()
	 * @see \WP_Customize_Manager::customize_register()
	 */
	function register_widget_instance_settings_early() {
		// Register any widgets that have been registered thus far
		$this->register_widget_settings();

		// Register any remaining widgets that are registered after WP is initialized
		$priority = 9; // Before \WP_Customize_Manager::customize_register() is called
		add_action( 'wp', array( $this, 'register_widget_settings' ), $priority );
	}

	/**
	 * Register widget settings just in time.
	 *
	 * This is called right before customize_widgets action, so we be first in
	 * line to register the widget settings, so we can use WP_Customize_Widget_Setting()
	 * as opposed to the default setting. Core needs a way to filter the setting
	 * class used when settings are created, as has been done for customize_dynamic_setting_class.
	 *
	 * @todo Propose that WP_Customize_Manager::add_setting( $id, $args ) allow the setting class 'WP_Customize_Setting' to be filtered.
	 *
	 * @action wp_loaded
	 * @see \WP_Customize_Widgets::customize_register()
	 */
	function register_widget_settings() {
		global $wp_registered_widgets;
		if ( empty( $wp_registered_widgets ) ) {
			$this->plugin->trigger_warning( '$wp_registered_widgets is empty.' );
			return;
		}

		/*
		 * Register a setting for all widgets, including those which are active,
		 * inactive, and orphaned since a widget may get suppressed from a sidebar
		 * via a plugin (like Widget Visibility).
		 */
		foreach ( $wp_registered_widgets as $widget_id => $registered_widget ) {
			$setting_id = $this->manager->widgets->get_setting_id( $widget_id );
			if ( ! $this->manager->get_setting( $setting_id ) ) {
				$setting_args = $this->manager->widgets->get_setting_args( $setting_id );
				$setting = new WP_Customize_Widget_Setting( $this->manager, $setting_id, $setting_args );
				$this->manager->add_setting( $setting );
			}
		}
	}

	/**
	 * Capture widget settings for Customizer.
	 *
	 * Since at widgets_init,100 the single instances of widgets get copied out
	 * to the many instances in $wp_registered_widgets, we capture all of the
	 * registered widgets up front so we don't have to search through the big
	 * list later. At this time we also add the pre_option filter to use the
	 * settings as retrieved once from the database, and then manipulated in a
	 * pre-unserialized data structure ready to be re-serialized once when the
	 * Customizer setting is saved.
	 *
	 * @see WP_Customize_Widget_Setting::__construct()
	 * @see Widget_Posts::filter_pre_option_widget_settings()
	 * @see Widget_Posts::filter_pre_option_widget_settings_for_customizer()
	 * @see Widget_Posts::prepare_widget_data()
	 * @action widgets_init, 92
	 */
	function capture_widget_settings_for_customizer() {
		$self = $this; // For PHP 5.3.
		foreach ( $this->widget_objs as $id_base => $widget_obj ) {

			/*
			 * Get widget settings once, after Widget_Posts::prepare_widget_data()
			 * has run, so we can get Widget_Settings ArrayIterators.
			 */
			$settings = $widget_obj->get_settings();

			// Sanity check.
			if ( $this->plugin->is_normal_multi_widget( $widget_obj ) && ! ( $settings instanceof Widget_Settings ) ) {
				throw new Exception( "Expected settings for $widget_obj->id_base to be a Widget_Settings instance." );
			}

			/*
			 * Restore _multiwidget array key since it gets stripped by WP_Widget::get_settings()
			 * and we're going to use this $settings data in a pre_option filter,
			 * and we want to ensure that wp_convert_widget_settings() will not be
			 * called later when WP_Widget::get_settings() is again called
			 * and does get_option( "widget_{$id_base}" ). Note that if this is
			 * a Widget_Settings instance, it wil do a no-op and offsetExists()
			 * will always return true for the '_multiwidget' key.
			 */
			$settings['_multiwidget'] = 1;

			/*
			 * Store the settings to be used by WP_Customize_Widget_Setting::value(),
			 * and in pre_option_{$id_base} filter.
			 */
			$this->current_widget_type_values[ $widget_obj->id_base ] = $settings;

			// Note that this happens _before_ Widget_Posts::filter_pre_option_widget_settings().
			add_filter( "pre_option_widget_{$widget_obj->id_base}", function( $pre_value ) use ( $widget_obj, $self ) {
				if ( get_current_blog_id() === $self->active_blog_id ) {
					$pre_value = $this->filter_pre_option_widget_settings_for_customizer( $pre_value, $widget_obj );
				}
				return $pre_value;
			} );
		}
	}

	/**
	 * Pre-option filter which intercepts the expensive WP_Customize_Setting::_preview_filter().
	 *
	 * @see WP_Customize_Setting::_preview_filter()
	 * @param null|array $pre_value Value that, if set, short-circuits the normal get_option() return value.
	 * @param \WP_Widget $widget_obj
	 * @return array
	 * @throws Exception
	 */
	function filter_pre_option_widget_settings_for_customizer( $pre_value, $widget_obj ) {
		if ( ! $this->disabled_filtering_pre_option_widget_settings ) {
			if ( ! isset( $this->current_widget_type_values[ $widget_obj->id_base ] ) ) {
				throw new Exception( "current_widget_type_values not set yet for $widget_obj->id_base" );
			}
			$pre_value = $this->current_widget_type_values[ $widget_obj->id_base ];
		}
		return $pre_value;
	}

	/**
	 * Ensure that dynamic settings for widgets use the proper class.
	 *
	 * @see WP_Customize_Widget_Setting
	 * @param string $setting_class WP_Customize_Setting or a subclass.
	 * @param string $setting_id    ID for dynamic setting, usually coming from `$_POST['customized']`.
	 * @return string
	 */
	function filter_dynamic_setting_class( $setting_class, $setting_id ) {
		if ( preg_match( self::WIDGET_SETTING_ID_PATTERN, $setting_id ) ) {
			$setting_class = '\\' . __NAMESPACE__ . '\\WP_Customize_Widget_Setting';
		}
		return $setting_class;
	}

	/**
	 * Ensure that widget_posts is included in a WP_Customize_Widget_Setting arguments.
	 *
	 * @see WP_Customize_Widget_Setting
	 *
	 * @param array $setting_args Array of Customizer setting arguments.
	 * @return array Args.
	 */
	function filter_widget_customizer_setting_args( $setting_args ) {
		$setting_args['widget_posts'] = $this;
		return $setting_args;
	}

	/**
	 * @see WP_Customize_Widget_Setting::filter_pre_option_widget_settings()
	 * @action customize_save
	 */
	function disable_filtering_pre_option_widget_settings() {
		$this->disabled_filtering_pre_option_widget_settings = true;
	}

	/**
	 * @see WP_Customize_Widget_Setting::filter_pre_option_widget_settings()
	 * @action customize_save_after
	 */
	function enable_filtering_pre_option_widget_settings() {
		$this->disabled_filtering_pre_option_widget_settings = false;
	}

	/**
	 * Clear all of the caches for memory management.
	 *
	 * Copied from vip-wp-cli.php@199311
	 *
	 * @see WPCOM_VIP_CLI_Command::stop_the_insanity()
	 */
	protected function stop_the_insanity() {
		/**
		 * @var \WP_Object_Cache $wp_object_cache
		 * @var \wpdb $wpdb
		 */
		global $wpdb, $wp_object_cache;

		$wpdb->queries = array();

		if ( is_object( $wp_object_cache ) ) {
			$wp_object_cache->group_ops = array();
			$wp_object_cache->stats = array();
			$wp_object_cache->memcache_debug = array();
			$wp_object_cache->cache = array();

			if ( method_exists( $wp_object_cache, '__remoteset' ) ) {
				$wp_object_cache->__remoteset(); // important
			}
		}
	}
}
