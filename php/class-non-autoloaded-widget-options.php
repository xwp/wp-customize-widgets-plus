<?php

namespace CustomizeWidgetsPlus;

/**
 * Widgets are stored in options (normally). Multi-widgets store all of their
 * instances in one big serialized array specific to that type. When there are
 * many widgets of a given type, the size of the serialized array can grow very
 * large. What's more is that WP_Widget does not explicitly add_option(... 'no' ) before
 * calling update_option(), and so all of the settings get added with autoloading.
 * This is very bad when using Memcached Object Cache specifically because it
 * can result in the total alloptions cache key to become larger than 1MB and
 * result in Memcache failing to store the value in the cache. On WordPress.com
 * the result is a "Matt's fault" error which has to be fixed by the VIP team.
 * Widget settings should not be stored in serialized arrays to begin with; each
 * widget instance should be stored in a custom post type. But until this is done
 * we should stop autoloading options.
 *
 * @link https://core.trac.wordpress.org/ticket/26876 "Default widgets causing unnecessary database queries"
 * @link https://core.trac.wordpress.org/ticket/23909 "Widgets settings loaded and instances registered unnecessarily"
 *
 * @package CustomizeWidgetsPlus
 */
class Non_Autoloaded_Widget_Options {

	/**
	 * @param Plugin $plugin
	 */
	public $plugin;

	/**
	 * @param Plugin $plugin
	 */
	function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;

		add_action( 'widgets_init', array( $this, 'fix_widget_options' ), 90 ); // must be before 100 when widgets are rendered
	}

	/**
	 * @action widget_init, 90
	 */
	function fix_widget_options() {

		/**
		 * @var \wpdb $wpdb
		 */
		global $wpdb;

		/**
		 * @var \WP_Widget_Factory $wp_widget_factory
		 */
		global $wp_widget_factory;

		$alloptions = wp_load_alloptions();
		if ( ! is_array( $alloptions ) ) {
			$this->plugin->trigger_warning( sprintf( 'wp_load_alloptions() did not return an array but a "%s"; object cache may be corrupted and may need to be flushed. Please follow https://core.trac.wordpress.org/ticket/31245', gettype( $alloptions ) ) );
			$alloptions = array();
		}

		$autoloaded_option_names = array_keys( $alloptions );
		$pending_unautoload_option_names = array();

		foreach ( $wp_widget_factory->widgets as $widget_obj ) {
			/**
			 * @var \WP_Widget $widget_obj
			 */

			$is_already_autoloaded = in_array( $widget_obj->option_name, $autoloaded_option_names );

			if ( $is_already_autoloaded ) {
				// Add to list of options that we need to unautoload
				$pending_unautoload_option_names[] = $widget_obj->option_name;
			} else {
				// Preemptively do add_option() before the widget will ever have a chance to update_option()
				add_option( $widget_obj->option_name, array(), '', 'no' );
			}
		}

		// Unautoload options and flush alloptions cache.
		if ( ! empty( $pending_unautoload_option_names ) ) {
			$sql_in = join( ',', array_fill( 0, count( $pending_unautoload_option_names ), '%s' ) );
			$sql = "UPDATE $wpdb->options SET autoload = 'no' WHERE option_name IN ( $sql_in )";
			$wpdb->query( $wpdb->prepare( $sql, $pending_unautoload_option_names ) ); // db call okay; cache okay
			wp_cache_delete( 'alloptions', 'options' );
		}
	}
}
