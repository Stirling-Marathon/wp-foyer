<?php

/**
 * Read-only REST API for Roku display manifests.
 *
 * @package Foyer
 * @subpackage Foyer/includes
 */
class Foyer_Roku_REST_API {

	const rest_namespace = 'foyer/v1';

	/**
	 * Registers REST routes.
	 *
	 * @return void
	 */
	static function register_routes() {
		register_rest_route(
			self::rest_namespace,
			'/displays',
			array(
				'methods' => WP_REST_Server::READABLE,
				'callback' => array( __CLASS__, 'get_displays' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			self::rest_namespace,
			'/displays/(?P<slug>[a-zA-Z0-9_-]+)',
			array(
				'methods' => WP_REST_Server::READABLE,
				'callback' => array( __CLASS__, 'get_display_manifest' ),
				'permission_callback' => '__return_true',
				'args' => array(
					'slug' => array(
						'required' => true,
						'sanitize_callback' => 'sanitize_title',
					),
				),
			)
		);
	}

	/**
	 * Returns published displays.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response
	 */
	static function get_displays( $request ) {
		$display_posts = Foyer_Displays::get_posts( array(
			'post_status' => 'publish',
			'orderby' => 'title',
			'order' => 'ASC',
		) );

		$displays = array();

		foreach ( $display_posts as $display_post ) {
			$displays[] = self::format_display( $display_post );
		}

		return self::prepare_response( array(
			'displays' => $displays,
		) );
	}

	/**
	 * Returns a display manifest by display slug.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	static function get_display_manifest( $request ) {
		$display_post = self::get_display_post_by_slug( $request->get_param( 'slug' ) );

		if ( ! $display_post ) {
			return self::not_found_error();
		}

		$manifest = self::build_display_manifest( $display_post );

		if ( is_wp_error( $manifest ) ) {
			return $manifest;
		}

		return self::prepare_response( $manifest );
	}

	/**
	 * Builds a display manifest.
	 *
	 * @param WP_Post $display_post Display post.
	 * @return array|WP_Error
	 */
	static function build_display_manifest( $display_post ) {
		if ( ! $display_post || Foyer_Display::post_type_name !== $display_post->post_type || 'publish' !== $display_post->post_status ) {
			return self::not_found_error();
		}

		$display = new Foyer_Display( $display_post );
		$channel_id = $display->get_active_channel();

		if ( empty( $channel_id ) || 'publish' !== get_post_status( $channel_id ) ) {
			return new WP_Error(
				'foyer_no_active_channel',
				__( 'The display does not have a published active channel.', 'foyer' ),
				array( 'status' => 404 )
			);
		}

		$channel_post = get_post( $channel_id );

		if ( ! $channel_post || Foyer_Channel::post_type_name !== $channel_post->post_type ) {
			return self::not_found_error();
		}

		$channel = new Foyer_Channel( $channel_post );
		$warnings = array();
		$duration = intval( $channel->get_slides_duration() );
		$slides = self::build_slides( $channel, $warnings );

		$manifest = array(
			'schemaVersion' => 1,
			'generatedAt' => gmdate( 'Y-m-d\TH:i:s\Z' ),
			'refreshSeconds' => intval( Foyer_Settings::get( 'roku_manifest_refresh_seconds' ) ),
			'display' => self::format_display( $display_post ),
			'channel' => array(
				'id' => intval( $channel_post->ID ),
				'name' => get_the_title( $channel_post ),
				'durationSeconds' => $duration,
				'transition' => $channel->get_slides_transition(),
			),
			'slides' => $slides,
			'warnings' => $warnings,
		);

		$manifest['revision'] = self::generate_revision( $manifest );

		return $manifest;
	}

	/**
	 * Builds manifest slide records.
	 *
	 * @param Foyer_Channel $channel Channel model.
	 * @param array         $warnings Warning records.
	 * @return array
	 */
	private static function build_slides( $channel, &$warnings ) {
		$slides = array();

		foreach ( $channel->get_slides() as $slide ) {
			if ( 'publish' !== get_post_status( $slide->ID ) ) {
				continue;
			}

			$slide_record = self::build_slide( $slide );

			if ( is_wp_error( $slide_record ) ) {
				$warnings[] = array(
					'slideId' => intval( $slide->ID ),
					'code' => $slide_record->get_error_code(),
					'message' => $slide_record->get_error_message(),
				);
				continue;
			}

			if ( ! empty( $slide_record['warning'] ) ) {
				$warnings[] = $slide_record['warning'];
				unset( $slide_record['warning'] );
			}

			$slides[] = $slide_record;
		}

		return $slides;
	}

	/**
	 * Builds a single manifest slide record.
	 *
	 * @param Foyer_Slide $slide Slide model.
	 * @return array|WP_Error
	 */
	private static function build_slide( $slide ) {
		$format = $slide->get_format();
		$background = $slide->get_background();

		if ( 'iframe' === $format ) {
			$snapshot = Foyer_Roku_Snapshots::get_successful_snapshot( $slide->ID );

			if ( $snapshot ) {
				return array(
					'id' => intval( $slide->ID ),
					'type' => 'image',
					'sourceType' => 'foyer-iframe-snapshot',
					'url' => $snapshot['url'],
					'fit' => 'cover',
					'revision' => $snapshot['revision'],
				);
			}

			return array(
				'id' => intval( $slide->ID ),
				'type' => 'unsupported',
				'sourceType' => 'foyer-iframe',
				'revision' => self::generate_slide_revision( $slide ),
				'warning' => array(
					'slideId' => intval( $slide->ID ),
					'code' => 'snapshot_not_available',
					'message' => __( 'No successful Roku snapshot is available for this external webpage slide.', 'foyer' ),
				),
			);
		}

		if ( 'image' === $background ) {
			return self::build_image_slide( $slide );
		}

		return array(
			'id' => intval( $slide->ID ),
			'type' => 'unsupported',
			'sourceType' => 'foyer-' . sanitize_key( $format ),
			'revision' => self::generate_slide_revision( $slide ),
			'warning' => array(
				'slideId' => intval( $slide->ID ),
				'code' => 'unsupported_slide_type',
				'message' => __( 'Roku output is not available for this slide.', 'foyer' ),
			),
		);
	}

	/**
	 * Builds an image-background slide record.
	 *
	 * @param Foyer_Slide $slide Slide model.
	 * @return array|WP_Error
	 */
	private static function build_image_slide( $slide ) {
		$attachment_id = get_post_meta( $slide->ID, 'slide_bg_image_image', true );
		$image = wp_get_attachment_image_src( $attachment_id, 'foyer' );

		if ( empty( $image[0] ) ) {
			return new WP_Error(
				'missing_image_source',
				__( 'The slide image is not available.', 'foyer' )
			);
		}

		return array(
			'id' => intval( $slide->ID ),
			'type' => 'image',
			'sourceType' => 'foyer-image',
			'url' => esc_url_raw( self::absolute_url( $image[0] ) ),
			'fit' => 'cover',
			'revision' => self::generate_slide_revision( $slide ),
		);
	}

	/**
	 * Returns a published display post by slug.
	 *
	 * @param string $slug Display slug.
	 * @return WP_Post|false
	 */
	private static function get_display_post_by_slug( $slug ) {
		$display_posts = get_posts( array(
			'name' => sanitize_title( $slug ),
			'post_type' => Foyer_Display::post_type_name,
			'post_status' => 'publish',
			'posts_per_page' => 1,
		) );

		if ( empty( $display_posts ) ) {
			return false;
		}

		return $display_posts[0];
	}

	/**
	 * Formats a display record.
	 *
	 * @param WP_Post $display_post Display post.
	 * @return array
	 */
	private static function format_display( $display_post ) {
		return array(
			'id' => intval( $display_post->ID ),
			'slug' => $display_post->post_name,
			'name' => get_the_title( $display_post ),
		);
	}

	/**
	 * Prepares a REST response.
	 *
	 * @param array $data Response data.
	 * @return WP_REST_Response
	 */
	private static function prepare_response( $data ) {
		$response = rest_ensure_response( $data );
		$response->header( 'Cache-Control', 'no-cache, must-revalidate, max-age=0' );

		return $response;
	}

	/**
	 * Returns a not found error.
	 *
	 * @return WP_Error
	 */
	private static function not_found_error() {
		return new WP_Error(
			'foyer_display_not_found',
			__( 'Display not found.', 'foyer' ),
			array( 'status' => 404 )
		);
	}

	/**
	 * Returns an absolute URL.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	private static function absolute_url( $url ) {
		if ( 0 === strpos( $url, '//' ) ) {
			return is_ssl() ? 'https:' . $url : 'http:' . $url;
		}

		if ( preg_match( '#^https?://#i', $url ) ) {
			return $url;
		}

		return home_url( $url );
	}

	/**
	 * Generates a stable manifest revision.
	 *
	 * @param array $manifest Manifest data.
	 * @return string
	 */
	private static function generate_revision( $manifest ) {
		unset( $manifest['generatedAt'] );

		return hash( 'sha256', wp_json_encode( $manifest ) );
	}

	/**
	 * Generates a stable slide revision.
	 *
	 * @param Foyer_Slide $slide Slide model.
	 * @return string
	 */
	private static function generate_slide_revision( $slide ) {
		$post = get_post( $slide->ID );
		$revision_data = array(
			'id' => intval( $slide->ID ),
			'modified' => $post ? $post->post_modified_gmt : '',
			'format' => $slide->get_format(),
			'background' => $slide->get_background(),
		);

		if ( 'image' === $slide->get_background() ) {
			$revision_data['attachmentId'] = intval( get_post_meta( $slide->ID, 'slide_bg_image_image', true ) );
		}

		return hash( 'sha256', wp_json_encode( $revision_data ) );
	}
}
