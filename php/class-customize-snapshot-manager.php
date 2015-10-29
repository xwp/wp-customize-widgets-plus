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

		$uuid = isset( $_REQUEST['customize_snapshot_uuid'] ) ? $_REQUEST['customize_snapshot_uuid'] : null;
		$scope = isset( $_REQUEST['scope'] ) ? $_REQUEST['scope'] : 'dirty';
		$apply_dirty = ( 'dirty' === $scope );

		// Bootstrap the Customizer.
		if ( empty( $GLOBALS['wp_customize'] ) || ! ( $GLOBALS['wp_customize'] instanceof \WP_Customize_Manager ) && $uuid ) {
			require_once( ABSPATH . WPINC . '/class-wp-customize-manager.php' );
			$GLOBALS['wp_customize'] = new \WP_Customize_Manager();
		}

		$this->snapshot = new Customize_Snapshot( $GLOBALS['wp_customize'], $uuid, $apply_dirty );

		add_action( 'init', array( $this, 'create_post_type' ), 0 );
		add_action( 'customize_controls_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
		add_action( 'wp_ajax_' . self::AJAX_ACTION, array( $this, 'update_snapshot' ) );
		add_action( 'customize_save_after', array( $this, 'save_snapshot' ) );

		$this->preview();
	}

	/**
	 * Decode and store any $_POST['snapshot_customized'] data.
	 *
	 * The value is used by Customize_Snapshot_Manager::update_snapshot().
	 */
	public function store_post_data() {
		if ( isset( $_POST['snapshot_customized'] ) ) {
			$this->post_data = json_decode( wp_unslash( $_POST['snapshot_customized'] ), true );
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
			'is_preview' => $this->snapshot->is_preview(),
			'scope' => ( isset( $_GET['scope'] ) ? $_GET['scope'] : 'dirty' ),
			'i18n' => array(
				'saveButton' => __( 'Save', 'customize-widgets-plus' ),
				'cancelButton' => __( 'Cancel', 'customize-widgets-plus' ),
				'shareButton' => __( 'Share URL to preview', 'customize-widgets-plus' ),
				'updateMsg' => __( 'Clicking "Save" will update the current snapshot.', 'customize-widgets-plus' ),
				'errorMsg' => __( 'The snapshot could not be saved.', 'customize-widgets-plus' ),
				'previewTitle' => __( 'Preview URL', 'customize-widgets-plus' ),
				'formTitle' => ( $this->snapshot->is_preview() ?
					__( 'Update Snapshot', 'customize-widgets-plus' ) :
					__( 'Snapshot Scope', 'customize-widgets-plus' )
				),
				'dirtyLabel' => __( 'Diff Snapshot (preview dirty settings)', 'customize-widgets-plus' ),
				'fullLabel' => __( 'Full Snapshot (preview all settings)', 'customize-widgets-plus' ),
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
	 * Get the Customize_Snapshot instance.
	 *
	 * @return Customize_Snapshot
	 */
	public function snapshot() {
		return $this->snapshot;
	}

	/**
	 * Save a snapshot. 
	 *
	 * @param WP_Customize_Manager $manager WP_Customize_Manager instance.
	 * @param string $status The post status.
	 * @return null|WP_Error
	 */
	public function save( \WP_Customize_Manager $manager, $status = 'draft' ) {
		$new_setting_ids = array_diff( array_keys( $this->post_data ), array_keys( $manager->settings() ) );
		$manager->add_dynamic_settings( wp_array_slice_assoc( $this->post_data, $new_setting_ids ) );

		foreach ( $manager->settings() as $setting ) {
			if ( $setting->check_capabilities() && array_key_exists( $setting->id, $this->post_data ) ) {
				$value = $this->post_data[ $setting->id ];
				$this->snapshot->set( $setting, $value );
			}
		}

		$r = $this->snapshot->save( $status );
		if ( is_wp_error( $r ) ) {
			status_header( 500 );
			wp_send_json_error( $r->get_error_message() );
		}
	}

	/**
	 * Save snapshots via AJAX.
	 *
	 * Fires at `customize_save_after` to update and publish the snapshot. 
	 *
	 * @param WP_Customize_Manager $manager WP_Customize_Manager instance.
	 */
	public function save_snapshot( \WP_Customize_Manager $manager ) {
		if ( ! current_user_can( 'customize' ) ) {
			status_header( 403 );
			wp_send_json_error( 'customize_not_allowed' );
		}

		$uuid = null;
		if ( ! empty( $_POST['snapshot_uuid'] ) ) {
			$uuid = $_POST['snapshot_uuid'];
		}

		if ( $uuid && $this->snapshot->is_valid_uuid( $uuid ) ) {
			$this->snapshot->set_uuid( $uuid );
			$this->save( $manager, 'publish' );
		}
	}

	/**
	 * Update snapshots via AJAX.
	 */
	public function update_snapshot() {
		if ( ! check_ajax_referer( self::AJAX_ACTION, 'nonce', false ) ) {
			status_header( 400 );
			wp_send_json_error( 'bad_nonce' );
		}

		if ( ! current_user_can( 'customize' ) ) {
			status_header( 403 );
			wp_send_json_error( 'customize_not_allowed' );
		}

		if ( 'POST' !== $_SERVER['REQUEST_METHOD'] ) {
			status_header( 405 );
			wp_send_json_error( 'bad_method' );
		}

		if ( empty( $_POST['customize_snapshot_uuid'] ) ) {
			status_header( 400 );
			wp_send_json_error( 'invalid_customize_snapshot_uuid' );
		}

		if ( empty( $_POST['scope'] ) ) {
			status_header( 400 );
			wp_send_json_error( 'invalid_customize_snapshot_scope' );
		}

		if ( empty( $this->post_data ) ) {
			status_header( 400 );
			wp_send_json_error( 'missing_snapshot_customized' );
		}

		if ( empty( $_POST['preview'] ) ) {
			status_header( 400 );
			wp_send_json_error( 'missing_preview' );
		}

		// Set the snapshot UUID.
		$this->snapshot->set_uuid( $_POST['customize_snapshot_uuid'] );
		$uuid = $this->snapshot->uuid();
		$next_uuid = $uuid;

		$post = $this->snapshot->post();
		$post_type = get_post_type_object( self::POST_TYPE );
		$authorized = ( $post ?
			current_user_can( $post_type->cap->edit_post, $post->ID ) :
			current_user_can( $post_type->cap->create_posts )
		);
		if ( ! $authorized ) {
			status_header( 403 );
			wp_send_json_error( 'unauthorized' );
		}

		$this->snapshot->apply_dirty = ( 'dirty' === $_POST['scope'] );
		$manager = $this->snapshot->manager();
		$this->save( $manager, 'draft' );

		// Set a new UUID every time Share is clicked, when the user is not previewing a snapshot.
		if ( 'on' !== $_POST['preview'] ) {
			$next_uuid = $this->snapshot->reset_uuid();
		}

		$response = array(
			'customize_snapshot_uuid' => $uuid, // Current UUID.
			'customize_snapshot_next_uuid' => $next_uuid, // Next UUID if not previewing, else current UUID.
			'customize_snapshot_settings' => $this->snapshot->values(), // Send back sanitized settings values.
		);

		wp_send_json_success( $response );
	}

	/**
	 * Preview a snapshot.
	 */
	public function preview() {
		if ( true === $this->snapshot->is_preview() ) {
			// @todo Preview a UUID by playing the saved data on top of the current settings.
		}
	}
}
