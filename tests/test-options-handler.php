<?php

class Test_Options_Handler extends \WP_Mock\Tools\TestCase {
	public function setUp() : void {
		\WP_Mock::setUp();
	}

	public function tearDown() : void {
		\WP_Mock::tearDown();
	}

	public function test_options_handler_register() {
		\WP_Mock::userFunction( 'get_option', array(
			'times'  => 1,
			'args'   => array( 'share_on_pixelfed_settings' ),
			'return' => array(),
		) );

		$options_handler = new \Share_On_Pixelfed\Options_Handler();

		\WP_Mock::expectActionAdded( 'admin_menu', array( $options_handler, 'create_menu' ) );

		$options_handler->register();

		$this->assertHooksAdded();
	}
}
