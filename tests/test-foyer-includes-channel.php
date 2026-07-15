<?php

class Test_Foyer_Channel extends Foyer_UnitTestCase {

	function test_are_all_published_slides_included_in_slides() {

		/* Create three slides */
		$slide_args = array(
			'post_type' => Foyer_Slide::post_type_name,
		);

		$slide_1_id = $this->factory->post->create( $slide_args );
		$slide_2_id = $this->factory->post->create( $slide_args );
		$slide_3_id = $this->factory->post->create( $slide_args );

		/* Create channel with all three slides */
		$channel_args = array(
			'post_type' => Foyer_Channel::post_type_name,
		);

		$channel_id = $this->factory->post->create( $channel_args );
		add_post_meta( $channel_id, Foyer_Slide::post_type_name, array( $slide_1_id, $slide_2_id, $slide_3_id ) );

		$channel = new Foyer_Channel( $channel_id );

		$expected = array(
			new Foyer_Slide( $slide_1_id ),
			new Foyer_Slide( $slide_2_id ),
			new Foyer_Slide( $slide_3_id ),
		);
		$actual = $channel->get_slides();

		$this->assertEquals( $expected, $actual );
	}

	function test_is_trashed_slide_excluded_from_slides() {

		/* Create three slides */
		$slide_args = array(
			'post_type' => Foyer_Slide::post_type_name,
		);

		$slide_1_id = $this->factory->post->create( $slide_args );
		$slide_2_id = $this->factory->post->create( $slide_args );
		$slide_3_id = $this->factory->post->create( $slide_args );

		/* Create channel with all three slides */
		$channel_args = array(
			'post_type' => Foyer_Channel::post_type_name,
		);

		$channel_id = $this->factory->post->create( $channel_args );
		add_post_meta( $channel_id, Foyer_Slide::post_type_name, array( $slide_1_id, $slide_2_id, $slide_3_id ) );

		// Trash one of the posts
		$args = array(
			'ID' => $slide_2_id,
			'post_status' => 'trash',
		);
		wp_update_post( $args );

		$channel = new Foyer_Channel( $channel_id );

		$expected = array(
			new Foyer_Slide( $slide_1_id ),
			new Foyer_Slide( $slide_3_id ),
		);
		$actual = $channel->get_slides();

		$this->assertEquals( $expected, $actual );
	}

	function test_slide_schedule_filters_by_boundary_and_preserves_order() {
		$time = new DateTimeImmutable( '2026-07-14 12:00:00', wp_timezone() );

		$visible_id = $this->factory->post->create( array( 'post_type' => Foyer_Slide::post_type_name ) );
		$future_id = $this->factory->post->create( array( 'post_type' => Foyer_Slide::post_type_name ) );
		$expired_id = $this->factory->post->create( array( 'post_type' => Foyer_Slide::post_type_name ) );
		$starts_now_id = $this->factory->post->create( array( 'post_type' => Foyer_Slide::post_type_name ) );
		$ends_now_id = $this->factory->post->create( array( 'post_type' => Foyer_Slide::post_type_name ) );

		update_post_meta( $future_id, Foyer_Slide::meta_show_from, $time->modify( '+1 minute' )->getTimestamp() );
		update_post_meta( $expired_id, Foyer_Slide::meta_show_until, $time->modify( '-1 minute' )->getTimestamp() );
		update_post_meta( $starts_now_id, Foyer_Slide::meta_show_from, $time->getTimestamp() );
		update_post_meta( $ends_now_id, Foyer_Slide::meta_show_until, $time->getTimestamp() );

		$channel_id = $this->factory->post->create( array( 'post_type' => Foyer_Channel::post_type_name ) );
		add_post_meta( $channel_id, Foyer_Slide::post_type_name, array( $future_id, $visible_id, $expired_id, $starts_now_id, $ends_now_id ) );

		$channel = new Foyer_Channel( $channel_id );
		$actual = wp_list_pluck( $channel->get_slides( $time ), 'ID' );

		$this->assertEquals( array( $visible_id, $starts_now_id ), $actual );
	}

	function test_current_and_future_slides_include_future_but_skip_expired() {
		$time = new DateTimeImmutable( '2026-07-14 12:00:00', wp_timezone() );

		$current_id = $this->factory->post->create( array( 'post_type' => Foyer_Slide::post_type_name ) );
		$future_id = $this->factory->post->create( array( 'post_type' => Foyer_Slide::post_type_name ) );
		$expired_id = $this->factory->post->create( array( 'post_type' => Foyer_Slide::post_type_name ) );

		update_post_meta( $future_id, Foyer_Slide::meta_show_from, $time->modify( '+1 hour' )->getTimestamp() );
		update_post_meta( $expired_id, Foyer_Slide::meta_show_until, $time->modify( '-1 second' )->getTimestamp() );

		$channel_id = $this->factory->post->create( array( 'post_type' => Foyer_Channel::post_type_name ) );
		add_post_meta( $channel_id, Foyer_Slide::post_type_name, array( $current_id, $future_id, $expired_id ) );

		$channel = new Foyer_Channel( $channel_id );
		$actual = wp_list_pluck( $channel->get_current_and_future_slides( $time ), 'ID' );

		$this->assertEquals( array( $current_id, $future_id ), $actual );
	}

	function test_all_slides_include_future_and_expired_slides_in_configured_order() {
		$time = new DateTimeImmutable( '2026-07-14 12:00:00', wp_timezone() );

		$current_id = $this->factory->post->create( array( 'post_type' => Foyer_Slide::post_type_name ) );
		$future_id = $this->factory->post->create( array( 'post_type' => Foyer_Slide::post_type_name ) );
		$expired_id = $this->factory->post->create( array( 'post_type' => Foyer_Slide::post_type_name ) );

		update_post_meta( $future_id, Foyer_Slide::meta_show_from, $time->modify( '+1 hour' )->getTimestamp() );
		update_post_meta( $expired_id, Foyer_Slide::meta_show_until, $time->modify( '-1 hour' )->getTimestamp() );

		$channel_id = $this->factory->post->create( array( 'post_type' => Foyer_Channel::post_type_name ) );
		add_post_meta( $channel_id, Foyer_Slide::post_type_name, array( $future_id, $current_id, $expired_id ) );

		$channel = new Foyer_Channel( $channel_id );
		$actual = wp_list_pluck( $channel->get_all_slides(), 'ID' );

		$this->assertEquals( array( $future_id, $current_id, $expired_id ), $actual );
	}
}
