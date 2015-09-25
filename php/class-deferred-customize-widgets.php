<?php

namespace CustomizeWidgetsPlus;

/**
 * Defer embedding of widget controls until the widget section is expanded.
 * Also defer serializing data of the widget settings and controls until shutdown.
 *
 * @package CustomizeWidgetsPlus
 */
class Deferred_Customize_Widgets {

	const MODULE_SLUG = 'deferred_customize_widgets';

	/**
	 * Plugin instance.
	 *
	 * @var Plugin
	 */
	public $plugin;

	/**
	 * Manager instance.
	 *
	 * @var \WP_Customize_Manager
	 */
	public $manager;

	/**
	 * Controls deferred for exporting at shutdown.
	 *
	 * @var \WP_Customize_Control[]
	 */
	public $customize_controls;

	/**
	 * Settings deferred for exporting at shutdown.
	 *
	 * @var \WP_Customize_Setting[]
	 */
	public $customize_settings;

	/**
	 * Return the config entry for the supplied key, or all configs if not supplied.
	 *
	 * @param string $key  Config key.
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
	 * Construct.
	 *
	 * @param Plugin $plugin  Instance of plugin.
	 */
	function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
		add_action( 'customize_register', array( $this, 'init' ) );
	}

	/**
	 * Initialize plugin Customizer functionality.
	 *
	 * @param \WP_Customize_Manager $manager
	 */
	function init( \WP_Customize_Manager $manager ) {
		$this->manager = $manager;
		$has_json_encode_peak_memory_fix = method_exists( $this->manager, 'customize_pane_settings' );
		$has_deferred_dom_widgets = method_exists( $this->manager->widgets, 'get_widget_control_parts' );

		// The fix is already in Core, so no-op.
		if ( $has_json_encode_peak_memory_fix && $has_deferred_dom_widgets ) {
			return;
		}

		add_action( 'customize_controls_enqueue_scripts', array( $this, 'enqueue_scripts' ) );

		if ( ! $has_json_encode_peak_memory_fix ) {
			add_action( 'customize_controls_print_footer_scripts', array( $this, 'defer_serializing_data_until_shutdown' ) );
		} else {
			add_action( 'customize_controls_print_footer_scripts', array( $this, 'fixup_widget_control_params_for_dom_deferral' ), 1001 );
		}

		// @todo Skip loading any widget settings or controls until after the page loads? This could cause problems.
	}

	/**
	 * Enqueue scripts for Customizer controls.
	 *
	 * @action customize_controls_enqueue_scripts
	 */
	function enqueue_scripts() {
		wp_enqueue_script( $this->plugin->script_handles['deferred-customize-widgets'] );
	}

	/**
	 * Break up a widget control's content into its container wrapper, (outer) widget control, and (inner) widget form.
	 *
	 * For a Core merge, all of this should be made part of WP_Widget_Form_Customize_Control::json().
	 */
	function fixup_widget_control_params_for_dom_deferral() {
		?>
		<script>
			_.each( _wpCustomizeSettings.controls, function( controlParams ) {
				var matches;
				if ( 'widget_form' !== controlParams.type || ! controlParams.content ) {
					return;
				}
				matches = controlParams.content.match( /^(\s*<li[^>]+>\s*)((?:.|\s)+?<div class="widget-content">)((?:.|\s)+?)(<\/div>\s*<input type="hidden" name="widget-id"(?:.|\s)+?)<\/li>\s*$/ );
				if ( ! matches ) {
					return;
				}
				controlParams.widget_content = jQuery.trim( matches[3] );
				controlParams.widget_control = jQuery.trim( matches[2] + matches[4] );
				controlParams.content = matches[1] + '</li>';
			} );
		</script>
		<?php
	}

	/**
	 * Temporarily remove widget settings and controls from the Manager so that
	 * they won't be serialized at once in _wpCustomizeSettings. This greatly
	 * reduces the peak memory usage.
	 *
	 * This is only relevant in WordPress versions older than 4.4-alpha-33636-src,
	 * with the changes introduced in Trac #33898.
	 *
	 * @link https://core.trac.wordpress.org/ticket/33898
	 */
	function defer_serializing_data_until_shutdown() {
		$this->customize_controls = array();
		$controls = $this->manager->controls();
		foreach ( $controls as $control ) {
			if ( $control instanceof \WP_Widget_Form_Customize_Control || $control instanceof \WP_Widget_Area_Customize_Control ) {
				$this->customize_controls[ $control->id ] = $control;
				$this->manager->remove_control( $control->id );
			}
		}

		/*
		 * Note: There is currently a Core dependency issue where the control for WP_Widget_Area_Customize_Control
		 * must be processed after the control for WP_Widget_Form_Customize_Control, as otherwise the sidebar
		 * does not initialize properly (specifically in regards to the reorder-toggle button. So this is why
		 * we are including the WP_Widget_Area_Customize_Controls among those which are deferred.
		 */

		$this->customize_settings = array();
		$settings = $this->manager->settings();
		foreach ( $settings as $setting ) {
			if ( preg_match( '/^(widget_.+?\[\d+\]|sidebars_widgets\[.+?\])$/', $setting->id ) ) {
				$this->customize_settings[ $setting->id ] = $setting;
				$this->manager->remove_setting( $setting->id );
			}
		}

		// We have to use shutdown because no action is triggered after _wpCustomizeSettings is written.
		add_action( 'shutdown', array( $this, 'export_data_with_peak_memory_usage_minimized' ), 10 );
		add_action( 'shutdown', array( $this, 'fixup_widget_control_params_for_dom_deferral' ), 11 );
	}

	/**
	 * Amend the _wpCustomizeSettings JS object with the widget settings and controls
	 * one-by-one in a loop using wp_json_encode() so that peak memory usage is kept low.
	 *
	 * This is only relevant in WordPress versions older than 4.4-alpha-33636-src,
	 * with the changes introduced in Trac #33898.
	 *
	 * @link https://core.trac.wordpress.org/ticket/33898
	 */
	function export_data_with_peak_memory_usage_minimized() {
		// Re-add the constructs to WP_Customize_manager, in case they need to refer to each other.
		foreach ( $this->customize_settings as $setting ) {
			$this->manager->add_setting( $setting );
		}
		foreach ( $this->customize_controls as $control ) {
			$this->manager->add_control( $control );
		}

		echo '<script type="text/javascript">';

		// Serialize settings one by one to improve memory usage.
		echo "// Export to wp.customize.settings.settings:\n";
		echo "(function ( s ){\n";
		foreach ( $this->customize_settings as $setting ) {
			if ( $setting->check_capabilities() ) {
				printf(
					"s[%s] = %s;\n",
					wp_json_encode( $setting->id ),
					wp_json_encode( array(
						'value'     => $setting->js_value(),
						'transport' => $setting->transport,
						'dirty'     => $setting->dirty,
					) )
				);
			}
		}
		echo "})( _wpCustomizeSettings.settings );\n";

		// Serialize controls one by one to improve memory usage.
		echo "// Export to wp.customize.settings.controls:\n";
		echo "(function ( c ){\n";
		foreach ( $this->customize_controls as $control ) {
			if ( $control->check_capabilities() ) {
				printf(
					"c[%s] = %s;\n",
					wp_json_encode( $control->id ),
					wp_json_encode( $control->json() )
				);
			}
		}
		echo "})( _wpCustomizeSettings.controls );\n";

		// Re-handle widget/area control autofocus since they were removed when checked before.
		if ( isset( $_GET['autofocus']['control'] ) ) {
			$autofocus_control_id = wp_unslash( $_GET['autofocus']['control'] ); // input var okay; sanitization ok
			if ( isset( $this->customize_controls[ $autofocus_control_id ] ) ) {
				printf( "_wpCustomizeSettings.autofocus.control = %s;\n", wp_json_encode( $autofocus_control_id ) );
			}
		}
		echo '</script>';
	}
}
