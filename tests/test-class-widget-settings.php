<?php

namespace CustomizeWidgetsPlus;

class Test_Widget_Settings extends Base_Test_Case {

	function setUp() {
		parent::setUp();
		$this->plugin->widget_number_incrementing = new Widget_Number_Incrementing( $this->plugin );
		$this->plugin->widget_posts = new Widget_Posts( $this->plugin );
		wp_widgets_init();
	}

	/**
	 * @param string $id_base
	 * @param int $count
	 *
	 * @return Widget_Settings
	 */
	function create_widget_settings( $id_base, $count = 3 ) {
		$instances = array();
		for ( $i = 2; $i < 2 + $count; $i += 1 ) {
			$post = $this->plugin->widget_posts->insert_widget( $id_base, array(
				'title' => "Hello world for widget_posts $i",
			) );
			$instances[ $i ] = $post->ID;
		}
		$settings = new Widget_Settings( $instances );
		return $settings;
	}

	/**
	 * @see Widget_Settings::offsetGet()
	 */
	function test_offset_get() {
		$settings = $this->create_widget_settings( 'meta', 3 );
		$shallow_settings = $settings->getArrayCopy();
		foreach ( $shallow_settings as $widget_number => $post_id ) {
			$this->assertInternalType( 'int', $widget_number );
			$this->assertInternalType( 'int', $post_id );
		}

		$this->assertEquals( 1, $settings['_multiwidget'] );
		$this->assertNull( $settings[100] );
		$instance = $settings[2];
		$this->assertInternalType( 'array', $instance );
		$this->assertContains( 'widget_posts', $instance['title'] );

		foreach ( array_keys( $shallow_settings ) as $widget_number ) {
			$instance = $settings[ $widget_number ];
			$this->assertInternalType( 'array', $instance );
			$this->assertContains( 'widget_posts', $instance['title'] );
		}
	}

	/**
	 * @see Widget_Settings::offsetSet()
	 */
	function test_offset_set() {
		$settings = $this->create_widget_settings( 'meta', 3 );
		$before = $settings->getArrayCopy();
		$settings['_multiwidget'] = 1;
		$this->assertEquals( $before, $settings->getArrayCopy() );

		$before = $settings->getArrayCopy();
		$settings['sdasd'] = array( 'title' => 'as' );
		$this->assertEquals( $before, $settings->getArrayCopy() );

		$before = $settings->getArrayCopy();
		$settings[4] = 'asdasd';
		$this->assertEquals( $before, $settings->getArrayCopy() );

		$before = $settings->getArrayCopy();
		$settings[50] = array( 'title' => 'Set' );
		$this->assertNotEquals( $before, $settings->getArrayCopy() );
	}

	/**
	 * @see Widget_Settings::offsetExists()
	 */
	function test_exists() {
		$settings = $this->create_widget_settings( 'meta', 3 );
		$this->assertFalse( isset( $settings[1000] ) );
		$this->assertTrue( isset( $settings['_multiwidget'] ) );
		$this->assertTrue( isset( $settings[2] ) );
		$this->assertFalse( isset( $settings[100] ) );
	}

	/**
	 * @see Widget_Settings::offsetUnset()
	 */
	function test_offset_unset() {
		$settings = $this->create_widget_settings( 'meta', 3 );
		$this->assertTrue( isset( $settings['_multiwidget'] ) );
		$before = $settings->getArrayCopy();
		unset( $settings['_multiwidget'] ); // A no-op.
		$this->assertTrue( isset( $settings['_multiwidget'] ) );
		$this->assertEquals( $before, $settings->getArrayCopy() );
	}

	/**
	 * @see Widget_Settings::current()
	 */
	function test_current() {
		$settings = $this->create_widget_settings( 'meta', 3 );
		$shallow_settings = $settings->getArrayCopy();

		foreach ( $settings as $widget_number => $instance ) {
			$this->assertInternalType( 'array', $instance );
			$this->assertContains( 'widget_posts', $instance['title'] );
			$this->assertEquals( $instance, Widget_Posts::get_post_content_filtered( get_post( $shallow_settings[ $widget_number ] ) ) );
		}
	}
}
