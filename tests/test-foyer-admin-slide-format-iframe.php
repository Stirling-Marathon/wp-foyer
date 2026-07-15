<?php

class Test_Foyer_Admin_Slide_Format_Iframe extends Foyer_UnitTestCase {

	/**
	 * @since	1.?
	 * @since	1.4.0	Updated to work with slide backgrounds.
	 */
	function test_are_all_iframe_slide_properties_saved() {

		$this->assume_role( 'administrator' );

		$website_url = 'https://dashboards.example.internal';

		$_POST[ Foyer_Slide::post_type_name.'_nonce' ] = wp_create_nonce( Foyer_Slide::post_type_name );
		$_POST['slide_format'] = 'iframe';
		$_POST['slide_background'] = 'default';

		$_POST['slide_iframe_website_url'] = $website_url;

		Foyer_Admin_Slide::save_slide( $this->slide1 );

		$actual = get_post_meta( $this->slide1, 'slide_iframe_website_url', true );
		$this->assertEquals( $website_url, $actual );
	}

	function test_iframe_save_adds_valid_host_to_snapshot_allowlist() {
		$this->assume_role( 'administrator' );
		update_option( Foyer_Settings::option_name, array(
			'webpage_snapshot_allowed_hosts' => array( 'display-01' ),
		) );

		$_POST[ Foyer_Slide::post_type_name.'_nonce' ] = wp_create_nonce( Foyer_Slide::post_type_name );
		$_POST['slide_format'] = 'iframe';
		$_POST['slide_background'] = 'default';
		$_POST['slide_iframe_website_url'] = 'https://Dashboards.example.internal/path?token=redacted';

		Foyer_Admin_Slide::save_slide( $this->slide1 );

		$hosts = Foyer_Settings::get( 'webpage_snapshot_allowed_hosts' );
		$this->assertContains( 'display-01', $hosts );
		$this->assertContains( 'dashboards.example.internal', $hosts );
		$this->assertNotContains( '/path', wp_json_encode( $hosts ) );
	}

	function test_iframe_save_does_not_add_invalid_snapshot_host() {
		$this->assume_role( 'administrator' );
		update_option( Foyer_Settings::option_name, array(
			'webpage_snapshot_allowed_hosts' => array( 'display-01' ),
		) );

		$_POST[ Foyer_Slide::post_type_name.'_nonce' ] = wp_create_nonce( Foyer_Slide::post_type_name );
		$_POST['slide_format'] = 'iframe';
		$_POST['slide_background'] = 'default';
		$_POST['slide_iframe_website_url'] = 'https://user:pass@dashboards.example.internal/path';

		Foyer_Admin_Slide::save_slide( $this->slide1 );

		$hosts = Foyer_Settings::get( 'webpage_snapshot_allowed_hosts' );
		$this->assertEquals( array( 'display-01' ), $hosts );
	}

	function test_iframe_save_does_not_duplicate_existing_snapshot_host() {
		$this->assume_role( 'administrator' );
		update_option( Foyer_Settings::option_name, array(
			'webpage_snapshot_allowed_hosts' => array( 'dashboards.example.internal' ),
		) );

		$_POST[ Foyer_Slide::post_type_name.'_nonce' ] = wp_create_nonce( Foyer_Slide::post_type_name );
		$_POST['slide_format'] = 'iframe';
		$_POST['slide_background'] = 'default';
		$_POST['slide_iframe_website_url'] = 'https://dashboards.example.internal/path';

		Foyer_Admin_Slide::save_slide( $this->slide1 );

		$hosts = Foyer_Settings::get( 'webpage_snapshot_allowed_hosts' );
		$this->assertEquals( array( 'dashboards.example.internal' ), $hosts );
	}

	function test_non_iframe_slide_does_not_update_snapshot_allowlist() {
		$this->assume_role( 'administrator' );
		update_option( Foyer_Settings::option_name, array(
			'webpage_snapshot_allowed_hosts' => array( 'display-01' ),
		) );

		$_POST[ Foyer_Slide::post_type_name.'_nonce' ] = wp_create_nonce( Foyer_Slide::post_type_name );
		$_POST['slide_format'] = 'default';
		$_POST['slide_background'] = 'image';
		$_POST['slide_bg_image_image'] = '';
		$_POST['slide_iframe_website_url'] = 'https://dashboards.example.internal/path';

		Foyer_Admin_Slide::save_slide( $this->slide1 );

		$hosts = Foyer_Settings::get( 'webpage_snapshot_allowed_hosts' );
		$this->assertEquals( array( 'display-01' ), $hosts );
	}
}
