<?php

class EPtestMetaPOC extends EP_Test_Base {

	/**
	 * Setup each test.
	 *
	 * @since 0.1.0
	 */
	public function setUp() {
		global $wpdb;
		parent::setUp();
		$wpdb->suppress_errors();

		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );

		wp_set_current_user( $admin_id );

		ep_delete_index();
		ep_put_mapping();

		ep_activate();

		EP_WP_Query_Integration::factory()->setup();

		$this->setup_test_post_type();
	}

	/**
	 * Clean up after each test. Reset our mocks
	 *
	 * @since 0.1.0
	 */
	public function tearDown() {
		parent::tearDown();

		//make sure no one attached to this
		remove_filter( 'ep_sync_terms_allow_hierarchy', array( $this, 'ep_allow_multiple_level_terms_sync' ), 100 );
		$this->fired_actions = array();
	}

	/**
	 * Test Meta preparation
	 */
	public function testMetaPrep() {

		ep_activate();

		ep_create_and_sync_post( array( 'post_content' => 'the post content' ) );
		ep_create_and_sync_post( array( 'post_content' => 'the post content findme' ) );
		ep_create_and_sync_post( array( 'post_content' => 'post content' ), array( 'test_key' => 'findme' ) );

		ep_refresh_index();

		$args = array(
			's'             => 'findme',
			'search_fields' => array(
				'post_title',
				'post_excerpt',
				'post_content',
				'meta' => 'test_key',
			),
		);

		$query = new WP_Query( $args );

		$this->assertEquals( 2, $query->post_count );
		$this->assertEquals( 2, $query->found_posts );

	}

	public function testPrepareMeta() {

		$post_id = ep_create_and_sync_post( array( 'post_content' => 'post content' ), array( 'test_key' => array( 'test_sub_key' => 'findme' ) ) );

		$args = array(
			's'             => 'findme',
			'search_fields' => array(
				'post_title',
				'post_excerpt',
				'post_content',
				'meta' => 'test_key',
			),
		);

		$query = new WP_Query( $args );

		//$post = EP_API::factory()->prepare_post( $post_id );

		//print_r( $post['post_meta'] );

		$this->assertEquals( 1, $query->post_count );
		$this->assertEquals( 1, $query->found_posts );

	}
}
