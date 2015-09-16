<?php

namespace CustomizeWidgetsPlus;

/**
 * Store widgets in posts as opposed to options.
 *
 * This makes non_autoloaded_widget_options obsolete.
 *
 * @package CustomizeWidgetsPlus
 */
class Deferred_Customize_Widgets {

	const MODULE_SLUG = 'deferred_customize_widgets';

	/**
	 * @var Plugin
	 */
	public $plugin;

	/**
	 * @var \WP_Customize_Manager
	 */
	public $manager;

	/**
	 * @var \WP_Customize_Control[]
	 */
	public $customize_controls;

	/**
	 * @var \WP_Customize_Setting[]
	 */
	public $customize_settings;

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

		add_action( 'customize_controls_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
		add_action( 'customize_controls_print_footer_scripts', array( $this, 'defer_serializing_data_to_shutdown' ) );

		// @todo Skip loading any widget settings or controls until after the page loads? This could cause problems.
		// @todo For each widget control, we can register a wrapper widget control that proxies calls to the underlying one, _except_ for the content method
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
	 * Temporarily remove widget settings and controls from the Manager so that
	 * they won't be serialized at once in _wpCustomizeSettings. This greatly
	 * reduces the peak memory usage.
	 */
	function defer_serializing_data_to_shutdown() {
		/** @var \WP_Customize_Manager $wp_customize */
		global $wp_customize;

		$this->customize_controls = array();
		$controls = $wp_customize->controls();
		foreach ( $controls as $control ) {
			if ( $control instanceof \WP_Widget_Form_Customize_Control || $control instanceof \WP_Widget_Area_Customize_Control ) {
				$this->customize_controls[ $control->id ] = $control;
				$wp_customize->remove_control( $control->id );
			}
		}
		/*
		 * Note: There is currently a Core dependency issue where the control for WP_Widget_Area_Customize_Control
		 * must be processed after the control for WP_Widget_Form_Customize_Control, as otherwise the sidebar
		 * does not initialize properly (specifically in regards to the reorder-toggle button. So this is why
		 * we are including the WP_Widget_Area_Customize_Controls among those which are deferred.
		 */

		$this->customize_settings = array();
		$settings = $wp_customize->settings();
		foreach ( $settings as $setting ) {
			if ( preg_match( '/^(widget_.+?\[\d+\]|sidebars_widgets\[.+?\])$/', $setting->id ) ) {
				$this->customize_settings[ $setting->id ] = $setting;
				$wp_customize->remove_setting( $setting->id );
			}
		}

		// We have to use shutdown because no action is triggered after _wpCustomizeSettings is written.
		add_action( 'shutdown', array( $this, 'export_data_to_client' ) );
	}

	/**
	 * Amend the _wpCustomizeSettings JS object with the widget settings and controls
	 * one-by-one in a loop using wp_json_encode() so that peak memory usage is kept low.
	 */
	function export_data_to_client() {
		/** @var \WP_Customize_Manager $wp_customize */
		global $wp_customize;

		// Re-add the constructs to WP_Customize_manager, in case they need to refer to each other.
		foreach ( $this->customize_settings as $setting ) {
			$wp_customize->add_setting( $setting );
		}
		foreach ( $this->customize_controls as $control ) {
			$wp_customize->add_control( $control );
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

				$params = $control->json();

				if ( $control instanceof \WP_Widget_Form_Customize_Control ) {
					// Normalize whitespace to cut down on \t\n escapes.
					$params['content'] = preg_replace( '/\s+/', ' ', $params['content'] );

					// Extract the widget_content for embedding once the widget is expanded.
					$pattern  = '(?P<before_widget_content>^.+?<div class="widget-content">)';
					$pattern .= '(?P<widget_content>.+)';
					$pattern .= '(?P<after_widget_content></div>\s*<input type="hidden" name="widget-id".+?$)';
					if ( preg_match( '#' . $pattern . '#s', $params['content'], $matches ) ) {
						$params['content'] = $matches['before_widget_content'] . $matches['after_widget_content'];
						$params['widget_content'] = trim( $matches['widget_content'] );
					}
				}

				printf(
					"c[%s] = %s;\n",
					wp_json_encode( $control->id ),
					wp_json_encode( $params )
				);
			}
		}
		echo "})( _wpCustomizeSettings.controls );\n";

		// Re-handle widget/area control autofocus since they were removed when checked before.
		if ( isset( $_GET['autofocus']['control'] ) ) {
			$autofocus_control_id = wp_unslash( $_GET['autofocus']['control'] );
			if ( isset( $this->customize_controls[ $autofocus_control_id ] ) ) {
				printf( "_wpCustomizeSettings.autofocus.control = %s;\n", wp_json_encode( $autofocus_control_id ) );
			}
		}
		echo '</script>';
	}
}
