<?php

namespace CustomizeWidgetsPlus;

/**
 * Customize Settings Snapshot Class
 *
 * Implements snapshots for Customizer settings
 *
 * @package CustomizeWidgetsPlus
 */
class Customize_Settings_Snapshot {

	const POST_TYPE = 'customize_snapshot';
	const AJAX_ACTION = 'customize_update_snapshot';

	/**
	 * @var Plugin
	 */
	public $plugin;

	/**
	 * WP_Customize_Manager instance.
	 *
	 * @access protected
	 * @var \WP_Customize_Manager
	 */
	protected $manager;

	/**
	 * Unique identifier.
	 *
	 * @access protected
	 * @var string
	 */
	protected $uuid;

	/**
	 * Post object for the current snapshot.
	 *
	 * @access protected
	 * @var WP_Post|null
	 */
	protected $post = null;

	/**
	 * JSON-decoded value $_POST['customized'] if present in request.
	 *
	 * Used by Customize_Settings_Snapshot::update_snapshot().
	 *
	 * @var array|null
	 */
	public $post_data;

	/**
	 * Store the snapshot data.
	 *
	 * @access protected
	 * @var array
	 */
	protected $data = array();

	/**
	 * Constructor.
	 *
	 * @access public
	 *
	 * @param Plugin $plugin
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
		$this->init();

		if ( ! did_action( 'setup_theme' ) ) {
			// Note that Customize_Settings_Snapshot::populate_customized_post_var() happens next at priority 1.
			add_action( 'setup_theme', array( $this, 'store_post_data' ), 0 );
		} else {
			$this->store_post_data();
		}

		add_action( 'init', array( $this, 'create_post_type' ), 0 );
		add_action( 'customize_controls_enqueue_scripts', array( $this, 'customize_controls_enqueue_scripts' ) );
		add_action( 'wp_ajax_' . self::AJAX_ACTION, array( $this, 'update_snapshot' ) );
	}

	/**
	 * Decode and store any initial $_POST['customized'] data.
	 *
	 * The value is used by Customize_Settings_Snapshot::update_snapshot().
	 */
	public function store_post_data() {
		if ( isset( $_POST['customized'] ) ) {
			$this->post_data = json_decode( wp_unslash( $_POST['customized'] ), true );
		}
	}

	/**
	 * Initial loader.
	 *
	 * @access public
	 *
	 * @param Plugin $plugin
	 */
	public function init() {
		$uuid = isset( $_GET['customize_settings_snapshot'] ) ? $_GET['customize_settings_snapshot'] : false;

		if ( $uuid && self::is_valid_uuid( $uuid ) ) {
			$this->uuid = $uuid;
		} else {
			$this->uuid = self::generate_uuid();
		}

		/**
		 * @var WP_Customize_Manager $wp_customize
		 */
		global $wp_customize;

		// Bootstrap the Customizer.
		if ( empty( $wp_customize ) && $uuid && self::is_valid_uuid( $uuid ) ) {
			require_once( ABSPATH . WPINC . '/class-wp-customize-manager.php' );
			// @todo This has to be more involved for the front-end. I don't see this just working without any user authentication.
			$wp_customize = new WP_Customize_Manager();
		}

		// Customize manager bootstrap instance.
		$this->manager = $wp_customize;

		$post = $this->post();
		if ( ! $post ) {
			$this->data = array();
		} else {
			// For reason why base64 encoding is used, see Customize_Settings_Snapshot::save().
			$this->data = json_decode( $post->post_content_filtered, true );

			if ( ! empty( $this->data ) ) {
				// For back-compat.
				if ( ! did_action( 'setup_theme' ) ) {
					/*
					 * Note we have to defer until setup_theme since the transaction
					 * can be set beforehand, and wp_magic_quotes() would not have
					 * been called yet, resulting in a $_POST['customized'] that is
					 * double-escaped. Note that this happens at priority 1, which
					 * is immediately after WP_Customize_Manager::store_customized_post_data
					 * which happens at setup_theme priority 0, so that the initial
					 * POST data can be preserved.
					 */
					add_action( 'setup_theme', array( $this, 'populate_customized_post_var' ), 1 );
				} else {
					$this->populate_customized_post_var();
				}
			}
		}
	}

	/**
	 * Export data to JS.
	 */
	public function export_script_data() {
		$exports = array(
			'nonce' => wp_create_nonce( self::AJAX_ACTION ),
			'action' => self::AJAX_ACTION,
			'uuid' => $this->uuid,
			'i18n' => array(
				'buttonText' => __( 'Share URL to preview', 'customize-widgets-plus' ),
			),
		);

		wp_scripts()->add_data(
			$this->plugin->script_handles['customize-settings-snapshot'],
			'data',
			sprintf( 'var _customizeWidgetsPlusCustomizeSettingsSnapshot = %s;', wp_json_encode( $exports ) )
		);
	}

	/**
	 * Enqueue scripts for Customizer controls.
	 *
	 * @action customize_controls_enqueue_scripts
	 */
	public function customize_controls_enqueue_scripts() {
		$handle = 'customize-settings-snapshot';
		wp_enqueue_style( $this->plugin->style_handles[ $handle ] );
		wp_enqueue_script( $this->plugin->script_handles[ $handle ] );
		$this->export_script_data();
	}

	/**
	 * Creates a snapshot with AJAX.
	 */
	public function update_snapshot() {
		if ( ! check_ajax_referer( self::AJAX_ACTION, 'nonce', false ) ) {
			status_header( 400 );
			wp_send_json_error( 'bad_nonce' );
		}
		if ( 'POST' !== $_SERVER['REQUEST_METHOD'] ) {
			status_header( 405 );
			wp_send_json_error( 'bad_method' );
		}
		if ( ! current_user_can( 'customize' ) ) {
			status_header( 403 );
			wp_send_json_error( 'customize_not_allowed' );
		}
		if ( empty( $_REQUEST['customize_settings_snapshot'] ) ) {
			status_header( 400 );
			wp_send_json_error( 'invalid_customize_settings_snapshot' );
		}
		if ( empty( $this->post_data ) ) {
			status_header( 400 );
			wp_send_json_error( 'missing_customized_json' );
		}

		$post = $this->post();
		$post_type = get_post_type_object( Customize_Settings_Snapshot::POST_TYPE );
		$authorized = ( $post ?
			current_user_can( $post_type->cap->edit_post, $post->ID )
			:
			current_user_can( $post_type->cap->create_posts )
		);
		if ( ! $authorized ) {
			status_header( 403 );
			wp_send_json_error( 'unauthorized' );
		}

		$new_setting_ids = array_diff( array_keys( $this->post_data ), array_keys( $this->manager->settings() ) );
		$this->manager->add_dynamic_settings( wp_array_slice_assoc( $this->post_data, $new_setting_ids ) );

		foreach ( $this->manager->settings() as $setting ) {
			// @todo delete settings that were deleted dynamically on the client (not just those which the user hasn't the cap to change)
			if ( $setting->check_capabilities() && array_key_exists( $setting->id, $this->post_data ) ) {
				$value = $this->post_data[ $setting->id ];
				$this->set( $setting, $value );
			}
		}

		$r = $this->save();
		if ( is_wp_error( $r ) ) {
			status_header( 500 );
			wp_send_json_error( $r->get_error_message() );
		}

		$response = array(
			'snapshot_uuid' => $this->uuid,
			'snapshot_settings' => $this->data(), // send back sanitized settings so that the UI can be updated to reflect the PHP-sanitized values
		);

		wp_send_json_success( $response );
	}

	/**
	 * Create the post type.
	 *
	 * @access public
	 */
	public function create_post_type() {
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
			'supports' => array( 'author', 'revisions' ),
		);

		register_post_type( self::POST_TYPE, $args );
	}

	/**
	 * Populate $_POST['customized'] wth the snapshot's data for back-compat.
	 *
	 * Plugins used to have to dynamically register settings by inspecting the
	 * $_POST['customized'] var and manually re-parse and inspect to see if it
	 * contains settings that wouldn't be registered otherwise. This ensures
	 * that these plugins will continue to work.
	 *
	 * Note that this can't be called prior to the setup_theme action or else
	 * magic quotes may end up getting added twice.
	 */
	public function populate_customized_post_var() {
		$_POST['customized'] = add_magic_quotes( wp_json_encode( $this->data ) );
		$_REQUEST['customized'] = $_POST['customized'];
	}

	/**
	 * Generate a snapshot uuid
	 *
	 * @return string
	 */
	static public function generate_uuid() {
		return sprintf( '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
			mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ),
			mt_rand( 0, 0xffff ),
			mt_rand( 0, 0x0fff ) | 0x4000,
			mt_rand( 0, 0x3fff ) | 0x8000,
			mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff )
		);
	}

	/**
	 * Determine whether the supplied UUID is in the right format.
	 *
	 * @param string $uuid
	 *
	 * @return bool
	 */
	static public function is_valid_uuid( $uuid ) {
		return 0 !== preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $uuid );
	}

	/**
	 * Get the snapshot uuid.
	 */
	public function get_uuid() {
		return $this->uuid;
	}

	/**
	 * Get the snapshot post associated with the provided UUID, or null if it does not exist.
	 *
	 * @return WP_Post|null
	 */
	public function post() {
		if ( $this->post ) {
			return $this->post;
		}

		$post_stati = array_merge(
			array( 'any' ),
			array_values( get_post_stati( array( 'exclude_from_search' => true ) ) )
		);

		add_action( 'pre_get_posts', array( $this, '_override_wp_query_is_single' ) );
		$posts = get_posts( array(
			'name' => $this->uuid,
			'posts_per_page' => 1,
			'post_type' => self::POST_TYPE,
			'post_status' => $post_stati,
		) );
		remove_action( 'pre_get_posts', array( $this, '_override_wp_query_is_single' ) );

		if ( empty( $posts ) ) {
			$this->post = null;
		} else {
			$this->post = array_shift( $posts );
		}

		return $this->post;
	}

	/**
	 * This is needed to ensure that draft posts can be queried by name.
	 *
	 * @param WP_Query $query
	 */
	public function _override_wp_query_is_single( $query ) {
		$query->is_single = false;
	}

	/**
	 * Get the value for a setting in the snapshot.
	 *
	 * @param WP_Customize_Setting|string $setting
	 * @param mixed $default Return value if the snapshot lacks a value for the given setting.
	 * @return mixed
	 */
	public function get( $setting, $default = null ) {
		if ( is_string( $setting ) ) {
			$setting_obj = $this->manager->get_setting( $setting );
			if ( $setting_obj ) {
				$setting_id = $setting_obj->id;
				$setting = $setting_obj;
			} else {
				$setting_id = $setting;
				$setting = null;
			}
			unset( $setting_obj );
		} else {
			$setting_id = $setting->id;
		}
		/**
		 * @var WP_Customize_Setting|null $setting
		 * @var string $setting_id
		 */

		if ( ! isset( $this->data[ $setting_id ] ) ) {
			// @todo Should this instead return $setting_obj->default? Or only if is_null( $default )?
			return $default;
		}

		$value = $this->data[ $setting_id ];

		unset( $setting );
		// @todo if ( $setting ) { $setting->sanitize( wp_slash( $value ) ); } ?

		return $value;
	}

	/**
	 * Return all settings' values in the snapshot.
	 *
	 * @return array
	 */
	public function data() {
		// @todo just return $this->data; ?
		$values = array();
		foreach ( array_keys( $this->data ) as $setting_id ) {
			$values[ $setting_id ] = $this->get( $setting_id );
		}
		return $values;
	}

	/**
	 * Return the Customizer settings corresponding to the data contained in the snapshot.
	 *
	 * @return WP_Customize_Setting[]
	 */
	public function settings() {
		$settings = array();
		foreach ( array_keys( $this->data ) as $setting_id ) {
			$setting = $this->manager->get_setting( $setting_id );
			if ( $setting ) {
				$settings[] = $setting;
			}
		}
		return $settings;
	}

	/**
	 * Get the status of the snapshot.
	 *
	 * @return string|null
	 */
	public function status() {
		return $this->post ? get_post_status( $this->post->ID ) : null;
	}

	/**
	 * Store a setting's sanitized value in the snapshot's data.
	 *
	 * @param WP_Customize_Setting $setting
	 * @param mixed $value Must be JSON-serializable
	 */
	public function set( \WP_Customize_Setting $setting, $value ) {
		$value = wp_slash( $value ); // WP_Customize_Setting::sanitize() erroneously does wp_unslash again
		$value = $setting->sanitize( $value );
		$this->data[ $setting->id ] = $value;
	}

	/**
	 * Return whether the snapshot was saved (created/inserted) yet.
	 *
	 * @return bool
	 */
	public function saved() {
		return ! empty( $this->post );
	}

	/**
	 * Persist the data in the snapshot post content.
	 *
	 * @param string $status
	 *
	 * @return null|WP_Error
	 */
	public function save( $status = 'draft' ) {

		$options = 0;
		if ( defined( 'JSON_UNESCAPED_SLASHES' ) ) {
			$options |= JSON_UNESCAPED_SLASHES;
		}
		if ( defined( 'JSON_PRETTY_PRINT' ) ) {
			$options |= JSON_PRETTY_PRINT;
		}

		$post_content = wp_json_encode( $this->data, $options );

		if ( ! $this->post ) {
			$postarr = array(
				'post_type' => self::POST_TYPE,
				'post_name' => $this->uuid,
				'post_status' => $status,
				'post_author' => get_current_user_id(),
				'post_content_filtered' => $post_content,
			);
			$r = wp_insert_post( $postarr, true );
			if ( is_wp_error( $r ) ) {
				return $r;
			}
			$this->post = get_post( $r );
		} else {
			$postarr = array(
				'ID' => $this->post->ID,
				'post_content_filtered' => wp_slash( $post_content ),
				'post_status' => $status,
			);
			$r = wp_update_post( $postarr, true );
			if ( is_wp_error( $r ) ) {
				return $r;
			}
		}

		return null;
	}
}
