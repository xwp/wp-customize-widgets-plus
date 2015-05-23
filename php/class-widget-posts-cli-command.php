<?php

namespace CustomizeWidgetsPlus;

/**
 * Manage widget instances stored in posts.
 *
 * @package CustomizeWidgetsPlus
 */
class Widget_Posts_CLI_Command extends \WP_CLI_Command {

	/**
	 * @var Widget_Posts
	 */
	public $widget_posts;

	/**
	 * This gets set by Widget_Posts::__construct()
	 *
	 * @var Plugin
	 */
	static public $plugin_instance;

	/**
	 * @return Widget_Posts
	 */
	public function get_widget_posts() {
		if ( ! isset( static::$plugin_instance->widget_posts ) ) {
			static::$plugin_instance->widget_posts = new Widget_Posts( static::$plugin_instance );
		}
		return static::$plugin_instance->widget_posts;
	}

	/**
	 * Enable looking for widgets in posts instead of options. You should run migrate first.
	 */
	public function enable() {
		if ( get_option( 'widget_posts_enabled' ) ) {
			\WP_CLI::warning( 'Widget Posts already enabled.' );
		} else {
			$result = update_option( 'widget_posts_enabled', true );
			if ( $result ) {
				\WP_CLI::success( 'Widget Posts enabled.' );
			} else {
				\WP_CLI::error( 'Failed to enable Widget Posts.' );
			}
		}
	}

	/**
	 * Disable looking for widgets in posts instead of options.
	 */
	public function disable() {
		if ( ! get_option( 'widget_posts_enabled' ) ) {
			\WP_CLI::warning( 'Widget Posts already disabled.' );
		} else {
			$result = update_option( 'widget_posts_enabled', false );
			if ( $result ) {
				\WP_CLI::success( 'Widget Posts disabled.' );
			} else {
				\WP_CLI::error( 'Failed to disable Widget Posts.' );
			}
		}
	}

	/**
	 * Migrate widget instances from options into posts. Posts that already exist for given widget IDs will not be-imported unless --update is supplied.
	 *
	 * ## OPTIONS
	 *
	 * --update
	 * : Update any widget instance posts already migrated/imported. This would override any changes made since the last migration.
	 *
	 * --dry-run
	 * : Show what would be migrated.
	 *
	 * --verbose
	 * : Show more info about what is going on.
	 *
	 * @param array [$id_bases]
	 * @param array $options
	 * @synopsis [<id_bases>...] [--dry-run] [--update] [--verbose]
	 */
	public function migrate( $id_bases, $options ) {
		try {
			$widget_posts = $this->get_widget_posts();

			$options = array_merge(
				array(
					'update' => false,
					'verbose' => false,
					'dry-run' => false,
				),
				$options
			);
			if ( empty( $id_bases ) ) {
				$id_bases = array_keys( $widget_posts->widget_objs );
			} else {
				foreach ( $id_bases as $id_base ) {
					if ( ! array_key_exists( $id_base, $widget_posts->widget_objs ) ) {
						\WP_CLI::error( "Unrecognized id_base: $id_base" );
					}
				}
			}

			$updated_count = 0;
			$skipped_count = 0;
			$inserted_count = 0;
			$failed_count = 0;

			add_action( 'widget_posts_import_skip_existing', function ( $context ) use ( $options, &$skipped_count ) {
				// $context is compact( 'widget_id', 'instance', 'widget_number', 'id_base' )
				if ( $options['verbose'] ) {
					\WP_CLI::line( "Skipping already-imported widget $context[widget_id]." );
				}
				$skipped_count += 1;
			} );

			add_action( 'widget_posts_import_success', function ( $context ) use ( $options, &$updated_count, &$inserted_count ) {
				// $context is compact( 'widget_id', 'post', 'instance', 'widget_number', 'id_base', 'update' )
				if ( $context['update'] ) {
					$message = "Updated widget $context[widget_id].";
					$updated_count += 1;
				} else {
					$message = "Inserted widget $context[widget_id].";
					$inserted_count += 1;
				}
				if ( $options['dry-run'] ) {
					$message .= ' (Dry run.)';
				}
				\WP_CLI::success( $message );
			} );

			add_action( 'widget_posts_import_failure', function ( $context ) use ( &$failed_count ) {
				// $context is compact( 'widget_id', 'exception', 'instance', 'widget_number', 'id_base', 'update' )
				/** @var Exception $exception */
				$exception = $context['exception'];
				\WP_CLI::warning( "Failed to import $context[widget_id]: " . $exception->getMessage() );
				$failed_count += 1;
			} );

			$widget_posts->pre_option_filters_disabled = true;
			foreach ( $id_bases as $id_base ) {
				$widget_obj = $widget_posts->widget_objs[ $id_base ];
				$instances = $widget_obj->get_settings();
				$widget_posts->import_widget_instances( $id_base, $instances, $options );
			}
			$widget_posts->pre_option_filters_disabled = false;

			\WP_CLI::line();
			\WP_CLI::line( "Skipped: $skipped_count" );
			\WP_CLI::line( "Updated: $updated_count" );
			\WP_CLI::line( "Inserted: $inserted_count" );
			\WP_CLI::line( "Failed: $failed_count" );

		} catch ( \Exception $e ) {
			\WP_CLI::error( sprintf( '%s: %s', get_class( $e ), $e->getMessage() ) );
		}
	}

	/**
	 * Import widget instances from a JSON dump mapping widget IDs (e.g. {"search-123":{"title":"Buscar"} }.
	 */
	public function import() {
		\WP_CLI::error( 'Not implemented.' );
	}

	/**
	 * Show the instance data for the given widget ID in JSON format.
	 *
	 * ## OPTIONS
	 *
	 * @param array [$args]
	 * @param array $assoc_args
	 * @synopsis <widget_id>
	 */
	public function show( $args, $assoc_args ) {
		try {
			$widget_id = array_shift( $args );
			unset( $assoc_args );

			$widget_posts = $this->get_widget_posts();
			$post = $widget_posts->get_widget_post( $widget_id );
			if ( ! $post ) {
				\WP_CLI::warning( "Widget post $widget_id does not exist." );
			} else {
				$data = $widget_posts->get_widget_instance_data( $post );
				echo json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
			}
		} catch ( \Exception $e ) {
			\WP_CLI::error( sprintf( '%s: %s', get_class( $e ), $e->getMessage() ) );
		}
	}

}
