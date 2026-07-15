<?php

class Test_Foyer_Includes_Roku_Snapshots extends Foyer_UnitTestCase {

	function setUp() {
		parent::setUp();

		update_option( Foyer_Settings::option_name, array(
			'webpage_snapshot_allowed_hosts' => array( 'display-01', 'dashboards.example.internal' ),
		) );
	}

	function tearDown() {
		delete_option( Foyer_Settings::option_name );

		parent::tearDown();
	}

	function test_validate_url_allows_http_and_https_for_allowed_hosts() {
		$http = Foyer_Roku_Snapshots::validate_url( 'http://display-01/display/dashboard' );
		$https = Foyer_Roku_Snapshots::validate_url( 'https://dashboards.example.internal/display/dashboard' );

		$this->assertFalse( is_wp_error( $http ) );
		$this->assertFalse( is_wp_error( $https ) );
		$this->assertEquals( 'display-01', $http['host'] );
		$this->assertEquals( 'dashboards.example.internal', $https['host'] );
	}

	function test_validate_url_rejects_unsafe_schemes_and_credentials() {
		$schemes = array(
			'file:///etc/passwd',
			'ftp://display-01/example',
			'data:text/html,hello',
			'javascript:alert(1)',
			'chrome://version',
			'about:blank',
		);

		foreach ( $schemes as $url ) {
			$this->assertWPError( Foyer_Roku_Snapshots::validate_url( $url ) );
		}

		$this->assertWPError( Foyer_Roku_Snapshots::validate_url( 'http://user:pass@display-01/display' ) );
	}

	function test_validate_url_rejects_hosts_outside_allowlist() {
		$result = Foyer_Roku_Snapshots::validate_url( 'http://example.com/display' );

		$this->assertWPError( $result );
		$this->assertEquals( 'host_not_allowed', $result->get_error_code() );
	}

	function test_normalize_host_rejects_malformed_hosts_and_ip_literals() {
		$this->assertEquals( 'display-01', Foyer_Roku_Snapshots::normalize_host( 'DISPLAY-01.' ) );
		$this->assertWPError( Foyer_Roku_Snapshots::normalize_host( 'bad/host' ) );
		$this->assertWPError( Foyer_Roku_Snapshots::normalize_host( '127.0.0.1' ) );
	}

	function test_snapshot_filename_is_deterministic() {
		$this->assertEquals( 'slide-15.png', Foyer_Roku_Snapshots::get_snapshot_filename( 15 ) );
		$this->assertEquals( 'slide-15.png', Foyer_Roku_Snapshots::get_snapshot_filename( '15' ) );
	}

	function test_revision_is_deterministic_from_file_contents() {
		$target = Foyer_Roku_Snapshots::get_snapshot_target( 15 );
		file_put_contents( $target['path'], 'same content' );

		$first = Foyer_Roku_Snapshots::calculate_file_revision( $target['path'] );
		$second = Foyer_Roku_Snapshots::calculate_file_revision( $target['path'] );

		$this->assertEquals( $first, $second );
	}

	function test_atomic_replacement_updates_snapshot_after_temp_is_complete() {
		$target = Foyer_Roku_Snapshots::get_snapshot_target( 16 );
		$temp = Foyer_Roku_Snapshots::get_temp_snapshot_path( 16 );

		file_put_contents( $temp, 'new snapshot' );
		$result = Foyer_Roku_Snapshots::replace_snapshot_file( $temp, $target['path'] );

		$this->assertTrue( $result );
		$this->assertEquals( 'new snapshot', file_get_contents( $target['path'] ) );
	}

	function test_previous_snapshot_is_preserved_after_failed_replacement() {
		$target = Foyer_Roku_Snapshots::get_snapshot_target( 17 );
		file_put_contents( $target['path'], 'previous snapshot' );

		$result = Foyer_Roku_Snapshots::replace_snapshot_file( $target['path'] . '.missing', $target['path'] );

		$this->assertWPError( $result );
		$this->assertEquals( 'previous snapshot', file_get_contents( $target['path'] ) );
	}

	function test_snapshot_jobs_include_current_and_future_iframe_slides_but_skip_expired() {
		$now = current_datetime();
		$current_iframe_id = $this->create_iframe_slide( 'http://display-01/current' );
		$future_iframe_id = $this->create_iframe_slide( 'http://display-01/future' );
		$expired_iframe_id = $this->create_iframe_slide( 'http://display-01/expired' );

		update_post_meta( $future_iframe_id, Foyer_Slide::meta_show_from, $now->modify( '+10 minutes' )->getTimestamp() );
		update_post_meta( $expired_iframe_id, Foyer_Slide::meta_show_until, $now->modify( '-10 minutes' )->getTimestamp() );

		$channel_id = $this->factory->post->create( array(
			'post_type' => Foyer_Channel::post_type_name,
			'post_status' => 'publish',
		) );
		add_post_meta( $channel_id, Foyer_Slide::post_type_name, array( $current_iframe_id, $future_iframe_id, $expired_iframe_id ) );

		$display_id = $this->factory->post->create( array(
			'post_type' => Foyer_Display::post_type_name,
			'post_status' => 'publish',
		) );
		add_post_meta( $display_id, Foyer_Channel::post_type_name, $channel_id );

		$jobs = Foyer_Roku_Snapshots::get_active_iframe_slide_jobs();
		$slide_ids = wp_list_pluck( $jobs, 'slide_id' );

		$this->assertContains( $current_iframe_id, $slide_ids );
		$this->assertContains( $future_iframe_id, $slide_ids );
		$this->assertNotContains( $expired_iframe_id, $slide_ids );
		$this->assertEquals( $display_id, $jobs[0]['display_id'] );
		$this->assertEquals( $channel_id, $jobs[0]['channel_id'] );
	}

	function test_get_valid_url_data_normalizes_host_without_requiring_allowlist() {
		$result = Foyer_Roku_Snapshots::get_valid_url_data( 'https://EXAMPLE.internal/path?token=redacted' );

		$this->assertFalse( is_wp_error( $result ) );
		$this->assertEquals( 'example.internal', $result['host'] );
		$this->assertEquals( 'https', $result['scheme'] );
	}

	private function create_iframe_slide( $url ) {
		$slide_id = $this->factory->post->create( array(
			'post_type' => Foyer_Slide::post_type_name,
			'post_status' => 'publish',
		) );
		add_post_meta( $slide_id, 'slide_format', 'iframe' );
		add_post_meta( $slide_id, 'slide_iframe_website_url', $url );

		return $slide_id;
	}
}
