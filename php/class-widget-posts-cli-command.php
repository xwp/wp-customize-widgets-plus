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
	protected function get_widget_posts() {
		if ( ! isset( static::$plugin_instance->widget_posts ) ) {
			static::$plugin_instance->widget_posts = new Widget_Posts( static::$plugin_instance );
		}
		return static::$plugin_instance->widget_posts;
	}

	/**
	 * Enable looking for widgets in posts instead of options. You should run migrate first.
	 */
	public function enable() {
		if ( $this->get_widget_posts()->is_enabled() ) {
			\WP_CLI::warning( 'Widget Posts already enabled.' );
		} else {
			$result = $this->get_widget_posts()->enable();
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
		if ( ! $this->get_widget_posts()->is_enabled() ) {
			\WP_CLI::warning( 'Widget Posts already disabled.' );
		} else {
			$result = $this->get_widget_posts()->disable();
			if ( $result ) {
				\WP_CLI::success( 'Widget Posts disabled.' );
			} else {
				\WP_CLI::error( 'Failed to disable Widget Posts.' );
			}
		}
	}

	/**
	 * Number of widgets updated.
	 *
	 * @var int
	 */
	protected $updated_count = 0;

	/**
	 * Number of widgets skipped.
	 *
	 * @var int
	 */
	protected $skipped_count = 0;

	/**
	 * Number of widgets inserted.
	 *
	 * @var int
	 */
	protected $inserted_count = 0;

	/**
	 * Number of import-failed widgets.
	 *
	 * @var int
	 */
	protected $failed_count = 0;

	/**
	 * @param array $options
	 */
	protected function add_import_actions( $options ) {
		$this->updated_count = 0;
		$this->skipped_count = 0;
		$this->inserted_count = 0;
		$this->failed_count = 0;

		add_action( 'widget_posts_import_skip_existing', function ( $context ) use ( $options ) {
			// $context is compact( 'widget_id', 'instance', 'widget_number', 'id_base' )
			\WP_CLI::line( "Skipping already-imported widget $context[widget_id] (to update, call with --update)." );
			$this->skipped_count += 1;
		} );

		add_action( 'widget_posts_import_success', function ( $context ) use ( $options ) {
			// $context is compact( 'widget_id', 'post', 'instance', 'widget_number', 'id_base', 'update' )
			if ( $context['update'] ) {
				$message = "Updated widget $context[widget_id].";
				$this->updated_count += 1;
			} else {
				$message = "Inserted widget $context[widget_id].";
				$this->inserted_count += 1;
			}
			if ( $options['dry-run'] ) {
				$message .= ' (DRY RUN)';
			}
			\WP_CLI::success( $message );
		} );

		add_action( 'widget_posts_import_failure', function ( $context ) {
			// $context is compact( 'widget_id', 'exception', 'instance', 'widget_number', 'id_base', 'update' )
			/** @var Exception $exception */
			$exception = $context['exception'];
			\WP_CLI::warning( "Failed to import $context[widget_id]: " . $exception->getMessage() );
			$this->failed_count += 1;
		} );
	}

	/**
	 * Remove actions added by add_import_actions().
	 *
	 * @see Widget_Posts_CLI_Command::add_import_actions()
	 */
	protected function remove_import_actions() {
		remove_all_actions( 'widget_posts_import_skip_existing' );
		remove_all_actions( 'widget_posts_import_success' );
		remove_all_actions( 'widget_posts_import_failure' );
	}

	/**
	 * Write out summary of data collected by actions in add_import_actions().
	 *
	 * @see Widget_Posts_CLI_Command::add_import_actions()
	 */
	protected function write_import_summary() {
		\WP_CLI::line();
		\WP_CLI::line( "Skipped: $this->skipped_count" );
		\WP_CLI::line( "Updated: $this->updated_count" );
		\WP_CLI::line( "Inserted: $this->inserted_count" );
		\WP_CLI::line( "Failed: $this->failed_count" );
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
			if ( ! defined( 'WP_IMPORTING' ) ) {
				define( 'WP_IMPORTING', true );
			}
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

			$this->add_import_actions( $options );

			// Note we disable the pre_option filters because we need to get the underlying wp_options.
			$widget_posts->pre_option_filters_disabled = true;
			foreach ( $id_bases as $id_base ) {
				$widget_obj = $widget_posts->widget_objs[ $id_base ];
				$instances = $widget_obj->get_settings();
				$widget_posts->import_widget_instances( $id_base, $instances, $options );
			}
			$widget_posts->pre_option_filters_disabled = false;

			$this->write_import_summary();
			$this->remove_import_actions();

		} catch ( \Exception $e ) {
			\WP_CLI::error( sprintf( '%s: %s', get_class( $e ), $e->getMessage() ) );
		}
	}

	/**
	 * Import widget instances from a JSON dump.
	 *
	 * JSON may be in either of two formats:
	 *   {"search-123":{"title":"Buscar"}}
	 * or
	 *   {"search":{"123":{"title":"Buscar"}}}
	 * or
	 *   {"widget_search":{"123":{"title":"Buscar"}}}
	 * or
	 *   {"version":5,"options":{"widget_search":"a:1:{i:123;a:1:{s:5:\"title\";s:6:\"Buscar\";}}"}}
	 *
	 * Posts that already exist for given widget IDs will not be-imported unless --update is supplied.
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
	 * @param array [$args]
	 * @param array $options
	 * @synopsis [<file>] [--dry-run] [--update] [--verbose]
	 */
	public function import( $args, $options ) {
		try {
			if ( ! defined( 'WP_IMPORTING' ) ) {
				define( 'WP_IMPORTING', true );
			}
			$widget_posts = $this->get_widget_posts();

			$file = array_shift( $args );
			if ( '-' === $file ) {
				$file = 'php://stdin';
			}
			$options = array_merge(
				array(
					'update' => false,
					'verbose' => false,
					'dry-run' => false,
				),
				$options
			);

			// @codingStandardsIgnoreStart
			$json = file_get_contents( $file );
			// @codingStandardsIgnoreSEnd
			if ( false === $json ) {
				throw new Exception( "$file could not be read" );
			}

			$data = json_decode( $json, true );
			if ( json_last_error() ) {
				throw new Exception( 'JSON parse error, code: ' . json_last_error() );
			}
			if ( ! is_array( $data ) ) {
				throw new Exception( 'Expected array JSON to be an array.' );
			}

			$this->add_import_actions( $options );

			// Reformat the data structure into a format that import_widget_instances() accepts.
			$first_key = key( $data );
			if ( ! filter_var( $first_key, FILTER_VALIDATE_INT ) ) {
				$is_options_export = (
					isset( $data['version'] )
					&&
					5 === $data['version']
					&&
					isset( $data['options'] )
					&&
					is_array( $data['options'] )
				);
				if ( $is_options_export ) {
					// Format: {"version":5,"options":{"widget_search":"a:1:{i:123;a:1:{s:5:\"title\";s:6:\"Buscar\";}}"}}.
					$instances_by_type = array();
					foreach ( $data['options'] as $option_name => $option_value ) {
						if ( ! preg_match( '/^widget_(?P<id_base>.+)/', $option_name, $matches ) ) {
							continue;
						}
						if ( ! is_serialized( $option_value, true ) ) {
							\WP_CLI::warning( "Option $option_name is not valid serialized data as expected." );
							continue;
						}
						$instances_by_type[ $matches['id_base'] ] = unserialize( $option_value );
					}

				} else if ( array_key_exists( $first_key, $widget_posts->widget_objs ) ) {
					// Format: {"search":{"123":{"title":"Buscar"}}}.
					$instances_by_type = $data;

				} else {
					// Format: {"widget_search":{"123":{"title":"Buscar"}}}.
					$instances_by_type = array();
					foreach ( $data as $key => $value ) {
						if ( ! preg_match( '/^widget_(?P<id_base>.+)/', $key, $matches ) ) {
							throw new Exception( "Unexpected key: $key" );
						}
						$instances_by_type[ $matches['id_base'] ] = $value;
					}
				}
			} else {
				// Format: {"search-123":{"title":"Buscar"}}.
				$instances_by_type = array();
				foreach ( $data as $widget_id => $instance ) {
					$parsed_widget_id = $widget_posts->plugin->parse_widget_id( $widget_id );
					if ( empty( $parsed_widget_id ) || empty( $parsed_widget_id['widget_number'] ) ) {
						\WP_CLI::warning( "Rejecting instance with invalid widget ID: $widget_id" );
						continue;
					}
					if ( ! isset( $instances_by_type[ $parsed_widget_id['id_base'] ] ) ) {
						$instances_by_type[ $parsed_widget_id['id_base'] ] = array();
					}
					$instances_by_type[ $parsed_widget_id['id_base'] ][ $parsed_widget_id['widget_number'] ] = $instance;
				}
			}

			// Import each of the instances.
			foreach ( $instances_by_type as $id_base => $instances ) {
				if ( ! is_array( $instances ) ) {
					\WP_CLI::warning( "Expected array for $id_base instances. Skipping unknown number of widgets." );
					continue;
				}
				try {
					$widget_posts->import_widget_instances( $id_base, $instances, $options );
				} catch ( Exception $e ) {
					\WP_CLI::warning( 'Skipping: ' . $e->getMessage() );
					if ( is_array( $instances ) ) {
						$this->skipped_count += count( $instances );
					}
				}
			}

			$this->write_import_summary();
			$this->remove_import_actions();
		} catch ( \Exception $e ) {
			\WP_CLI::error( sprintf( '%s: %s', get_class( $e ), $e->getMessage() ) );
		}
	}

	/**
	 * Show the instance data for the given widget ID in JSON format.
	 *
	 * ## OPTIONS
	 *
	 * @param array [$args]
	 * @param array $assoc_args
	 * @synopsis <widget_id>
	 * @alias show
	 */
	public function get( $args, $assoc_args ) {
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
