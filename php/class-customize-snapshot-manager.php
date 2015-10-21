<?php

namespace CustomizeWidgetsPlus;

/**
 * Customize Snapshot Manager Class
 *
 * Implements a snapshot manager for Customizer settings
 *
 * @package CustomizeWidgetsPlus
 */
class Customize_Snapshot_Manager {

	/**
	 * Post type.
	 * @type string
	 */
	const POST_TYPE = 'customize_snapshot';

	/**
	 * Action nonce.
	 * @type string
	 */
	const AJAX_ACTION = 'customize_update_snapshot';

	/**
	 * @var Plugin
	 */
	public $plugin;

	/**
	 * JSON-decoded value $_POST['customized'] if present in request.
	 *
	 * Used by Customize_Snapshot_Manager::update_snapshot().
	 *
	 * @access protected
	 * @var array|null
	 */
	protected $post_data;

	/**
	 * Customize_Snapshot instance.
	 *
	 * @access protected
	 * @var Customize_Snapshot
	 */
	protected $snapshot;

	/**
	 * Constructor.
	 *
	 * @access public
	 *
	 * @param Plugin $plugin
	 */
	public function __construct( Plugin $plugin ) {
		// Bail if our conditions are not met.
		if ( ! ( ( isset( $_REQUEST['wp_customize'] ) && 'on' == $_REQUEST['wp_customize'] )
			|| ( is_admin() && 'customize.php' == basename( $_SERVER['PHP_SELF'] ) )
			|| ( isset( $_REQUEST['customize_snapshot_uuid'] ) )
		) ) {
			return;
		}

		$this->plugin = $plugin;

		if ( ! did_action( 'setup_theme' ) ) {
			// Note that Customize_Snapshot::populate_customized_post_var() happens next at priority 1.
			add_action( 'setup_theme', array( $this, 'store_post_data' ), 0 );
		} else {
			$this->store_post_data();
		}

		/**
		 * @var \WP_Customize_Manager $wp_customize
		 */
		global $wp_customize;

		$uuid = isset( $_REQUEST['customize_snapshot_uuid'] ) ? $_REQUEST['customize_snapshot_uuid'] : null;

		// Bootstrap the Customizer.
		if ( empty( $wp_customize ) && $uuid ) {
			require_once( ABSPATH . WPINC . '/class-wp-customize-manager.php' );
			$wp_customize = new \WP_Customize_Manager();
		}
		$this->snapshot = new Customize_Snapshot( $wp_customize, $uuid );

		add_action( 'init', array( $this, 'create_post_type' ), 0 );
		add_action( 'customize_controls_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
		add_action( 'wp_ajax_' . self::AJAX_ACTION, array( $this, 'update_snapshot' ) );
	}

	/**
	 * Decode and store any initial $_POST['customized'] data.
	 *
	 * The value is used by Customize_Snapshot_Manager::update_snapshot().
	 */
	public function store_post_data() {
		if ( isset( $_POST['customized'] ) ) {
			$this->post_data = json_decode( wp_unslash( $_POST['customized'] ), true );
		}
	}

	/**
	 * Create the custom post type.
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
			'supports' => array( 'title', 'author', 'revisions' ),
		);

		register_post_type( self::POST_TYPE, $args );
	}

	/**
	 * Enqueue styles & scripts for the Customizer.
	 *
	 * @action customize_controls_enqueue_scripts
	 */
	public function enqueue_scripts() {
		$handle = 'customize-snapshot';

		// Enqueue styles.
		wp_enqueue_style( $this->plugin->style_handles[ $handle ] );

		// Enqueue scripts.
		wp_enqueue_script( $this->plugin->script_handles[ $handle ] );

		// Script data array.
		$exports = array(
			'nonce' => wp_create_nonce( self::AJAX_ACTION ),
			'action' => self::AJAX_ACTION,
			'uuid' => $this->snapshot->uuid(),
			'i18n' => array(
				'buttonText' => __( 'Share URL to preview', 'customize-widgets-plus' ),
			),
		);

		// Export data to JS.
		wp_scripts()->add_data(
			$this->plugin->script_handles[ $handle ],
			'data',
			sprintf( 'var _customizeWidgetsPlusCustomizeSnapshot = %s;', wp_json_encode( $exports ) )
		);
	}

	/**
	 * Update snapshots via AJAX.
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
		if ( empty( $_REQUEST['customize_snapshot_uuid'] ) ) {
			status_header( 400 );
			wp_send_json_error( 'invalid_customize_snapshot_uuid' );
		}
		if ( empty( $this->post_data ) ) {
			status_header( 400 );
			wp_send_json_error( 'missing_customized_json' );
		}

		$post = $this->snapshot->post();
		$post_type = get_post_type_object( self::POST_TYPE );
		$authorized = ( $post ?
			current_user_can( $post_type->cap->edit_post, $post->ID )
			:
			current_user_can( $post_type->cap->create_posts )
		);
		if ( ! $authorized ) {
			status_header( 403 );
			wp_send_json_error( 'unauthorized' );
		}

		$manager = $this->snapshot->manager();
		$new_setting_ids = array_diff( array_keys( $this->post_data ), array_keys( $manager->settings() ) );
		$manager->add_dynamic_settings( wp_array_slice_assoc( $this->post_data, $new_setting_ids ) );

		foreach ( $manager->settings() as $setting ) {
			// @todo delete settings that were deleted dynamically on the client (not just those which the user hasn't the cap to change)
			if ( $setting->check_capabilities() && array_key_exists( $setting->id, $this->post_data ) ) {
				$value = $this->post_data[ $setting->id ];
				$this->snapshot->set( $setting, $value );
			}
		}

		$r = $this->snapshot->save();
		if ( is_wp_error( $r ) ) {
			status_header( 500 );
			wp_send_json_error( $r->get_error_message() );
		}

		$response = array(
			'snapshot_uuid' => $this->snapshot->uuid(),
			'snapshot_settings' => $this->snapshot->data(), // send back sanitized settings so that the UI can be updated to reflect the PHP-sanitized values
		);

		wp_send_json_success( $response );
	}
}
