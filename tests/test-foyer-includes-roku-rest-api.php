<?php

class Test_Foyer_Includes_Roku_REST_API extends Foyer_UnitTestCase {

	private $image_downsize_attachment_ids = array();

	function setUp() {
		parent::setUp();

		$this->image_downsize_attachment_ids = array();
		add_filter( 'image_downsize', array( $this, 'filter_image_downsize' ), 10, 3 );
		update_option( Foyer_Settings::option_name, array(
			'roku_manifest_refresh_seconds' => 90,
		) );
	}

	function tearDown() {
		remove_filter( 'image_downsize', array( $this, 'filter_image_downsize' ), 10 );
		delete_option( Foyer_Settings::option_name );

		parent::tearDown();
	}

	function filter_image_downsize( $downsize, $id, $size ) {
		if ( in_array( $id, $this->image_downsize_attachment_ids ) && 'foyer' === $size ) {
			return array( 'http://example.internal/wp-content/uploads/foyer-test.jpg', 1920, 1080, false );
		}

		return $downsize;
	}

	function test_displays_endpoint_returns_only_published_displays() {
		$published_id = $this->factory->post->create( array(
			'post_type' => Foyer_Display::post_type_name,
			'post_title' => 'Office Upstairs',
			'post_name' => 'office-upstairs',
			'post_status' => 'publish',
		) );

		$this->factory->post->create( array(
			'post_type' => Foyer_Display::post_type_name,
			'post_title' => 'Draft Display',
			'post_name' => 'draft-display',
			'post_status' => 'draft',
		) );

		$request = new WP_REST_Request( 'GET', '/foyer/v1/displays' );
		$response = Foyer_Roku_REST_API::get_displays( $request );
		$data = $response->get_data();

		$this->assertCount( 1, $data['displays'] );
		$this->assertEquals( $published_id, $data['displays'][0]['id'] );
		$this->assertEquals( 'office-upstairs', $data['displays'][0]['slug'] );
		$this->assertEquals( 'Office Upstairs', $data['displays'][0]['name'] );
	}

	function test_manifest_uses_scheduled_channel_and_preserves_slide_order() {
		$default_channel_id = $this->create_channel( 'Default Channel', array() );
		$slide_1_id = $this->create_image_slide();
		$slide_2_id = $this->create_image_slide();
		$scheduled_channel_id = $this->create_channel( 'Scheduled Channel', array( $slide_2_id, $slide_1_id ), 12, 'slide' );

		$display_id = $this->factory->post->create( array(
			'post_type' => Foyer_Display::post_type_name,
			'post_title' => 'Office Upstairs',
			'post_name' => 'office-upstairs',
			'post_status' => 'publish',
		) );

		add_post_meta( $display_id, Foyer_Channel::post_type_name, $default_channel_id );
		add_post_meta( $display_id, 'foyer_display_schedule', array(
			'channel' => $scheduled_channel_id,
			'start' => strtotime( '-10 minutes' ),
			'end' => strtotime( '+10 minutes' ),
		), false );

		$manifest = Foyer_Roku_REST_API::build_display_manifest( get_post( $display_id ) );

		$this->assertEquals( 1, $manifest['schemaVersion'] );
		$this->assertEquals( 90, $manifest['refreshSeconds'] );
		$this->assertEquals( $scheduled_channel_id, $manifest['channel']['id'] );
		$this->assertEquals( 12, $manifest['channel']['durationSeconds'] );
		$this->assertEquals( 'slide', $manifest['channel']['transition'] );
		$this->assertArrayNotHasKey( 'transitionDurationSeconds', $manifest['channel'] );
		$this->assertEquals( $slide_2_id, $manifest['slides'][0]['id'] );
		$this->assertEquals( $slide_1_id, $manifest['slides'][1]['id'] );
		$this->assertEquals( 'image', $manifest['slides'][0]['type'] );
		$this->assertEquals( 'foyer-image', $manifest['slides'][0]['sourceType'] );
		$this->assertEquals( 'http://example.internal/wp-content/uploads/foyer-test.jpg', $manifest['slides'][0]['url'] );
		$this->assertArrayNotHasKey( 'durationSeconds', $manifest['slides'][0] );
		$this->assertArrayHasKey( 'revision', $manifest );
	}

	function test_invalid_and_unpublished_display_slugs_return_404() {
		$this->factory->post->create( array(
			'post_type' => Foyer_Display::post_type_name,
			'post_title' => 'Draft Display',
			'post_name' => 'draft-display',
			'post_status' => 'draft',
		) );

		$invalid_request = new WP_REST_Request( 'GET', '/foyer/v1/displays/missing-display' );
		$invalid_request->set_param( 'slug', 'missing-display' );
		$invalid_response = Foyer_Roku_REST_API::get_display_manifest( $invalid_request );

		$draft_request = new WP_REST_Request( 'GET', '/foyer/v1/displays/draft-display' );
		$draft_request->set_param( 'slug', 'draft-display' );
		$draft_response = Foyer_Roku_REST_API::get_display_manifest( $draft_request );

		$this->assertWPError( $invalid_response );
		$invalid_error_data = $invalid_response->get_error_data();
		$this->assertEquals( 404, $invalid_error_data['status'] );
		$this->assertWPError( $draft_response );
		$draft_error_data = $draft_response->get_error_data();
		$this->assertEquals( 404, $draft_error_data['status'] );
	}

	function test_iframe_slides_return_unsupported_record_and_warning() {
		$slide_id = $this->create_iframe_slide();
		$channel_id = $this->create_channel( 'Web Channel', array( $slide_id ) );
		$display_id = $this->create_display( 'Web Display', 'web-display', $channel_id );

		$manifest = Foyer_Roku_REST_API::build_display_manifest( get_post( $display_id ) );

		$this->assertCount( 1, $manifest['slides'] );
		$this->assertEquals( 'unsupported', $manifest['slides'][0]['type'] );
		$this->assertEquals( 'foyer-iframe', $manifest['slides'][0]['sourceType'] );
		$this->assertCount( 1, $manifest['warnings'] );
		$this->assertEquals( $slide_id, $manifest['warnings'][0]['slideId'] );
		$this->assertEquals( 'snapshot_not_available', $manifest['warnings'][0]['code'] );
	}

	function test_iframe_slides_with_successful_snapshot_return_snapshot_image() {
		$slide_id = $this->create_iframe_slide();
		$channel_id = $this->create_channel( 'Web Channel', array( $slide_id ) );
		$display_id = $this->create_display( 'Web Display', 'web-display', $channel_id );
		$snapshot = $this->create_snapshot_for_slide( $slide_id, 'first image content' );

		$manifest = Foyer_Roku_REST_API::build_display_manifest( get_post( $display_id ) );

		$this->assertCount( 1, $manifest['slides'] );
		$this->assertEquals( 'image', $manifest['slides'][0]['type'] );
		$this->assertEquals( 'foyer-iframe-snapshot', $manifest['slides'][0]['sourceType'] );
		$this->assertEquals( $snapshot['url'], $manifest['slides'][0]['url'] );
		$this->assertEquals( $snapshot['revision'], $manifest['slides'][0]['revision'] );
		$this->assertEmpty( $manifest['warnings'] );
		$this->assertNotContains( $snapshot['path'], wp_json_encode( $manifest ) );
	}

	function test_manifest_revision_changes_when_snapshot_revision_changes() {
		$slide_id = $this->create_iframe_slide();
		$channel_id = $this->create_channel( 'Web Channel', array( $slide_id ) );
		$display_id = $this->create_display( 'Web Display', 'web-display', $channel_id );

		$this->create_snapshot_for_slide( $slide_id, 'first image content' );
		$first_manifest = Foyer_Roku_REST_API::build_display_manifest( get_post( $display_id ) );

		$this->create_snapshot_for_slide( $slide_id, 'second image content' );
		$second_manifest = Foyer_Roku_REST_API::build_display_manifest( get_post( $display_id ) );

		$this->assertNotEquals( $first_manifest['slides'][0]['revision'], $second_manifest['slides'][0]['revision'] );
		$this->assertNotEquals( $first_manifest['revision'], $second_manifest['revision'] );
	}

	function test_invalid_snapshot_metadata_does_not_break_manifest() {
		$slide_id = $this->create_iframe_slide();
		$channel_id = $this->create_channel( 'Web Channel', array( $slide_id ) );
		$display_id = $this->create_display( 'Web Display', 'web-display', $channel_id );

		update_post_meta( $slide_id, Foyer_Roku_Snapshots::meta_path, '/not/a/real/snapshot.png' );
		update_post_meta( $slide_id, Foyer_Roku_Snapshots::meta_url, 'http://example.internal/wp-content/uploads/foyer-roku/slide-' . $slide_id . '.png' );
		update_post_meta( $slide_id, Foyer_Roku_Snapshots::meta_revision, 'bad-revision' );

		$manifest = Foyer_Roku_REST_API::build_display_manifest( get_post( $display_id ) );

		$this->assertEquals( 'unsupported', $manifest['slides'][0]['type'] );
		$this->assertEquals( 'snapshot_not_available', $manifest['warnings'][0]['code'] );
	}

	function test_unpublished_slides_are_not_included_in_manifest() {
		$published_slide_id = $this->create_image_slide();
		$draft_slide_id = $this->create_image_slide( 'draft' );
		$channel_id = $this->create_channel( 'Mixed Channel', array( $published_slide_id, $draft_slide_id ) );
		$display_id = $this->factory->post->create( array(
			'post_type' => Foyer_Display::post_type_name,
			'post_status' => 'publish',
		) );
		add_post_meta( $display_id, Foyer_Channel::post_type_name, $channel_id );

		$manifest = Foyer_Roku_REST_API::build_display_manifest( get_post( $display_id ) );

		$this->assertCount( 1, $manifest['slides'] );
		$this->assertEquals( $published_slide_id, $manifest['slides'][0]['id'] );
	}

	function test_manifest_excludes_future_and_expired_slides() {
		$visible_slide_id = $this->create_image_slide();
		$future_slide_id = $this->create_image_slide();
		$expired_slide_id = $this->create_image_slide();
		$now = current_datetime();

		update_post_meta( $future_slide_id, Foyer_Slide::meta_show_from, $now->modify( '+10 minutes' )->getTimestamp() );
		update_post_meta( $expired_slide_id, Foyer_Slide::meta_show_until, $now->modify( '-10 minutes' )->getTimestamp() );

		$channel_id = $this->create_channel( 'Scheduled Slides', array( $future_slide_id, $visible_slide_id, $expired_slide_id ) );
		$display_id = $this->create_display( 'Scheduled Display', 'scheduled-display', $channel_id );

		$manifest = Foyer_Roku_REST_API::build_display_manifest( get_post( $display_id ) );

		$this->assertCount( 1, $manifest['slides'] );
		$this->assertEquals( $visible_slide_id, $manifest['slides'][0]['id'] );
	}

	function test_manifest_warnings_only_include_eligible_slides() {
		$visible_iframe_id = $this->create_iframe_slide();
		$future_iframe_id = $this->create_iframe_slide();
		$now = current_datetime();

		update_post_meta( $future_iframe_id, Foyer_Slide::meta_show_from, $now->modify( '+10 minutes' )->getTimestamp() );

		$channel_id = $this->create_channel( 'Iframe Slides', array( $visible_iframe_id, $future_iframe_id ) );
		$display_id = $this->create_display( 'Iframe Display', 'iframe-display', $channel_id );

		$manifest = Foyer_Roku_REST_API::build_display_manifest( get_post( $display_id ) );

		$this->assertCount( 1, $manifest['slides'] );
		$this->assertEquals( $visible_iframe_id, $manifest['slides'][0]['id'] );
		$this->assertCount( 1, $manifest['warnings'] );
		$this->assertEquals( $visible_iframe_id, $manifest['warnings'][0]['slideId'] );
	}

	function test_manifest_revision_changes_when_slide_eligibility_changes() {
		$slide_id = $this->create_image_slide();
		$channel_id = $this->create_channel( 'Scheduled Slides', array( $slide_id ) );
		$display_id = $this->create_display( 'Scheduled Display', 'scheduled-display', $channel_id );

		$visible_manifest = Foyer_Roku_REST_API::build_display_manifest( get_post( $display_id ) );
		update_post_meta( $slide_id, Foyer_Slide::meta_show_from, current_datetime()->modify( '+10 minutes' )->getTimestamp() );
		$hidden_manifest = Foyer_Roku_REST_API::build_display_manifest( get_post( $display_id ) );

		$this->assertCount( 1, $visible_manifest['slides'] );
		$this->assertCount( 0, $hidden_manifest['slides'] );
		$this->assertNotEquals( $visible_manifest['revision'], $hidden_manifest['revision'] );
	}

	private function create_channel( $title, $slides, $duration = 8, $transition = 'fade' ) {
		$channel_id = $this->factory->post->create( array(
			'post_type' => Foyer_Channel::post_type_name,
			'post_title' => $title,
			'post_status' => 'publish',
		) );

		add_post_meta( $channel_id, Foyer_Slide::post_type_name, $slides );
		add_post_meta( $channel_id, Foyer_Channel::post_type_name . '_slides_duration', $duration );
		add_post_meta( $channel_id, Foyer_Channel::post_type_name . '_slides_transition', $transition );

		return $channel_id;
	}

	private function create_display( $title, $slug, $channel_id ) {
		$display_id = $this->factory->post->create( array(
			'post_type' => Foyer_Display::post_type_name,
			'post_title' => $title,
			'post_name' => $slug,
			'post_status' => 'publish',
		) );
		add_post_meta( $display_id, Foyer_Channel::post_type_name, $channel_id );

		return $display_id;
	}

	private function create_image_slide( $post_status = 'publish' ) {
		$attachment_id = $this->factory->post->create( array(
			'post_type' => 'attachment',
			'post_status' => 'inherit',
			'post_mime_type' => 'image/jpeg',
			'guid' => 'http://example.internal/wp-content/uploads/foyer-test.jpg',
		) );
		$this->image_downsize_attachment_ids[] = $attachment_id;

		$slide_id = $this->factory->post->create( array(
			'post_type' => Foyer_Slide::post_type_name,
			'post_status' => $post_status,
		) );
		add_post_meta( $slide_id, 'slide_format', 'default' );
		add_post_meta( $slide_id, 'slide_background', 'image' );
		add_post_meta( $slide_id, 'slide_bg_image_image', $attachment_id );

		return $slide_id;
	}

	private function create_iframe_slide() {
		$slide_id = $this->factory->post->create( array(
			'post_type' => Foyer_Slide::post_type_name,
			'post_status' => 'publish',
		) );
		add_post_meta( $slide_id, 'slide_format', 'iframe' );
		add_post_meta( $slide_id, 'slide_iframe_website_url', 'http://example.internal/example-dashboard' );

		return $slide_id;
	}

	private function create_snapshot_for_slide( $slide_id, $contents ) {
		$target = Foyer_Roku_Snapshots::get_snapshot_target( $slide_id );
		file_put_contents( $target['path'], $contents );
		$revision = Foyer_Roku_Snapshots::calculate_file_revision( $target['path'] );
		Foyer_Roku_Snapshots::record_success( $slide_id, $target['path'], $target['url'], $revision );

		return array(
			'path' => $target['path'],
			'url' => $target['url'],
			'revision' => $revision,
		);
	}
}
