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
}
