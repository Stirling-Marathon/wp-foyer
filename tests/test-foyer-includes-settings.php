<?php

class Test_Foyer_Includes_Settings extends Foyer_UnitTestCase {

	function test_get_defaults_returns_phase_one_defaults() {
		$defaults = Foyer_Settings::get_defaults();

		$this->assertEquals( 1.5, $defaults['transition_duration_seconds'] );
		$this->assertEquals( 300, $defaults['content_refresh_seconds'] );
		$this->assertEquals( 28800, $defaults['forced_reload_seconds'] );
		$this->assertEquals( 60, $defaults['roku_manifest_refresh_seconds'] );
		$this->assertEquals( 120, $defaults['webpage_snapshot_refresh_seconds'] );
		$this->assertEquals( 1920, $defaults['webpage_snapshot_width'] );
		$this->assertEquals( 1080, $defaults['webpage_snapshot_height'] );
		$this->assertEquals( 30, $defaults['webpage_snapshot_timeout_seconds'] );
		$this->assertEquals( 2, $defaults['webpage_snapshot_settle_seconds'] );
		$this->assertContains( 'of-k9', $defaults['webpage_snapshot_allowed_hosts'] );
	}

	function test_sanitize_uses_defaults_for_missing_values() {
		$settings = Foyer_Settings::sanitize( array(
			'transition_duration_seconds' => '2.25',
		) );

		$this->assertEquals( 2.25, $settings['transition_duration_seconds'] );
		$this->assertEquals( 300, $settings['content_refresh_seconds'] );
		$this->assertEquals( 28800, $settings['forced_reload_seconds'] );
	}

	function test_sanitize_clamps_values_to_safe_ranges() {
		$settings = Foyer_Settings::sanitize( array(
			'transition_duration_seconds' => '99',
			'content_refresh_seconds' => '1',
			'forced_reload_seconds' => '1',
			'roku_manifest_refresh_seconds' => '1',
			'webpage_snapshot_refresh_seconds' => '1',
			'webpage_snapshot_width' => '1',
			'webpage_snapshot_height' => '99999',
			'webpage_snapshot_timeout_seconds' => '1',
			'webpage_snapshot_settle_seconds' => '999',
		) );

		$this->assertEquals( 10, $settings['transition_duration_seconds'] );
		$this->assertEquals( 30, $settings['content_refresh_seconds'] );
		$this->assertEquals( 300, $settings['forced_reload_seconds'] );
		$this->assertEquals( 10, $settings['roku_manifest_refresh_seconds'] );
		$this->assertEquals( 30, $settings['webpage_snapshot_refresh_seconds'] );
		$this->assertEquals( 320, $settings['webpage_snapshot_width'] );
		$this->assertEquals( 4320, $settings['webpage_snapshot_height'] );
		$this->assertEquals( 5, $settings['webpage_snapshot_timeout_seconds'] );
		$this->assertEquals( 30, $settings['webpage_snapshot_settle_seconds'] );
	}

	function test_sanitize_normalizes_allowed_hosts() {
		$settings = Foyer_Settings::sanitize( array(
			'webpage_snapshot_allowed_hosts' => "OF-K9\nhttp://of-k9.stirling/display\nfile://bad\n192.168.1.1\nbad/host",
		) );

		$this->assertEquals( array( 'of-k9', 'of-k9.stirling' ), $settings['webpage_snapshot_allowed_hosts'] );
	}

	function test_get_returns_sanitized_stored_setting() {
		update_option( Foyer_Settings::option_name, array(
			'transition_duration_seconds' => '3.5',
			'content_refresh_seconds' => '45',
		) );

		$this->assertEquals( 3.5, Foyer_Settings::get( 'transition_duration_seconds' ) );
		$this->assertEquals( 45, Foyer_Settings::get( 'content_refresh_seconds' ) );
	}
}
