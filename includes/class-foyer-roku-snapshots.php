<?php

/**
 * Snapshot helpers for Roku-compatible iframe output.
 *
 * @package Foyer
 * @subpackage Foyer/includes
 */
class Foyer_Roku_Snapshots {

	const upload_dir_name = 'foyer-roku';
	const meta_path = 'foyer_roku_snapshot_path';
	const meta_url = 'foyer_roku_snapshot_url';
	const meta_revision = 'foyer_roku_snapshot_revision';
	const meta_generated_at = 'foyer_roku_snapshot_generated_at';
	const meta_last_attempt_at = 'foyer_roku_snapshot_last_attempt_at';
	const meta_last_error = 'foyer_roku_snapshot_last_error';

	/**
	 * Returns iframe snapshot jobs for active slides on published displays.
	 *
	 * @return array
	 */
	static function get_active_iframe_slide_jobs() {
		$jobs_by_slide_id = array();
		$display_posts = Foyer_Displays::get_posts( array(
			'post_status' => 'publish',
			'orderby' => 'ID',
			'order' => 'ASC',
		) );

		foreach ( $display_posts as $display_post ) {
			$display = new Foyer_Display( $display_post );
			$channel_id = $display->get_active_channel();

			if ( empty( $channel_id ) || 'publish' !== get_post_status( $channel_id ) ) {
				continue;
			}

			$channel = new Foyer_Channel( $channel_id );

			foreach ( $channel->get_current_and_future_slides() as $slide ) {
				if ( 'iframe' !== $slide->get_format() ) {
					continue;
				}

				$url = get_post_meta( $slide->ID, 'slide_iframe_website_url', true );
				$slide_id = intval( $slide->ID );

				if ( isset( $jobs_by_slide_id[ $slide_id ] ) ) {
					continue;
				}

				$jobs_by_slide_id[ $slide_id ] = array(
					'display_id' => intval( $display_post->ID ),
					'channel_id' => intval( $channel_id ),
					'slide_id' => $slide_id,
					'url' => $url,
				);
			}
		}

		return array_values( $jobs_by_slide_id );
	}

	/**
	 * Validates an iframe URL against scheme and host settings.
	 *
	 * @param string $url URL to validate.
	 * @return array|WP_Error
	 */
	static function validate_url( $url ) {
		$url = trim( (string) $url );

		if ( '' === $url ) {
			return self::error( 'empty_url', __( 'The iframe URL is empty.', 'foyer' ) );
		}

		$url_data = self::get_valid_url_data( $url );
		if ( is_wp_error( $url_data ) ) {
			return $url_data;
		}

		$host = $url_data['host'];

		$allowed_hosts = self::get_allowed_hosts();
		if ( ! in_array( $host, $allowed_hosts, true ) ) {
			return self::error( 'host_not_allowed', __( 'The iframe URL host is not allowed for Roku snapshots.', 'foyer' ) );
		}

		return array(
			'url' => esc_url_raw( $url ),
			'scheme' => $url_data['scheme'],
			'host' => $host,
		);
	}

	/**
	 * Validates an iframe URL without checking the snapshot host allowlist.
	 *
	 * @param string $url URL to validate.
	 * @return array|WP_Error
	 */
	static function get_valid_url_data( $url ) {
		$url = trim( (string) $url );

		if ( '' === $url ) {
			return self::error( 'empty_url', __( 'The iframe URL is empty.', 'foyer' ) );
		}

		$parts = parse_url( $url );

		if ( empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return self::error( 'invalid_url', __( 'The iframe URL must include a scheme and host.', 'foyer' ) );
		}

		$scheme = strtolower( $parts['scheme'] );
		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
			return self::error( 'unsupported_url_scheme', __( 'Only http and https iframe URLs are allowed.', 'foyer' ) );
		}

		if ( ! empty( $parts['user'] ) || ! empty( $parts['pass'] ) ) {
			return self::error( 'url_credentials_not_allowed', __( 'Iframe URLs must not include credentials.', 'foyer' ) );
		}

		$host = self::normalize_host( $parts['host'] );
		if ( is_wp_error( $host ) ) {
			return $host;
		}

		return array(
			'url' => esc_url_raw( $url ),
			'scheme' => $scheme,
			'host' => $host,
		);
	}

	/**
	 * Returns normalized allowed hosts.
	 *
	 * @return array
	 */
	static function get_allowed_hosts() {
		$hosts = Foyer_Settings::get( 'webpage_snapshot_allowed_hosts' );

		if ( ! is_array( $hosts ) ) {
			$hosts = array();
		}

		return $hosts;
	}

	/**
	 * Normalizes a host setting.
	 *
	 * @param string $host Host value.
	 * @return string|WP_Error
	 */
	static function normalize_host( $host ) {
		$host = strtolower( trim( (string) $host ) );
		$host = trim( $host, " \t\n\r\0\x0B." );

		if ( '' === $host ) {
			return self::error( 'invalid_host', __( 'Host cannot be empty.', 'foyer' ) );
		}

		if ( false !== strpos( $host, '://' ) ) {
			$parts = parse_url( $host );
			$host = empty( $parts['host'] ) ? '' : strtolower( $parts['host'] );
		}

		if ( false !== strpos( $host, '@' ) || false !== strpos( $host, '/' ) || false !== strpos( $host, '\\' ) ) {
			return self::error( 'invalid_host', __( 'Host is malformed.', 'foyer' ) );
		}

		if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
			return self::error( 'ip_hosts_not_allowed', __( 'IP address hosts are not allowed by default.', 'foyer' ) );
		}

		if ( ! preg_match( '/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)*$/', $host ) ) {
			return self::error( 'invalid_host', __( 'Host is malformed.', 'foyer' ) );
		}

		return $host;
	}

	/**
	 * Returns public snapshot metadata for a slide when a valid snapshot exists.
	 *
	 * @param int $slide_id Slide ID.
	 * @return array|false
	 */
	static function get_successful_snapshot( $slide_id ) {
		$path = get_post_meta( $slide_id, self::meta_path, true );
		$url = get_post_meta( $slide_id, self::meta_url, true );
		$revision = get_post_meta( $slide_id, self::meta_revision, true );
		$generated_at = get_post_meta( $slide_id, self::meta_generated_at, true );

		if ( empty( $path ) || empty( $url ) || empty( $revision ) ) {
			return false;
		}

		if ( ! self::is_snapshot_path_allowed( $path ) || ! file_exists( $path ) || ! is_readable( $path ) ) {
			return false;
		}

		return array(
			'url' => esc_url_raw( $url ),
			'revision' => sanitize_text_field( $revision ),
			'generatedAt' => sanitize_text_field( $generated_at ),
		);
	}

	/**
	 * Records a successful snapshot.
	 *
	 * @param int    $slide_id Slide ID.
	 * @param string $path Snapshot path.
	 * @param string $url Snapshot URL.
	 * @param string $revision Snapshot revision.
	 * @return void
	 */
	static function record_success( $slide_id, $path, $url, $revision ) {
		update_post_meta( $slide_id, self::meta_path, $path );
		update_post_meta( $slide_id, self::meta_url, esc_url_raw( $url ) );
		update_post_meta( $slide_id, self::meta_revision, sanitize_text_field( $revision ) );
		update_post_meta( $slide_id, self::meta_generated_at, gmdate( 'Y-m-d\TH:i:s\Z' ) );
		update_post_meta( $slide_id, self::meta_last_attempt_at, gmdate( 'Y-m-d\TH:i:s\Z' ) );
		delete_post_meta( $slide_id, self::meta_last_error );
	}

	/**
	 * Records a failed snapshot attempt without removing previous success metadata.
	 *
	 * @param int    $slide_id Slide ID.
	 * @param string $message Error message.
	 * @return void
	 */
	static function record_failure( $slide_id, $message ) {
		update_post_meta( $slide_id, self::meta_last_attempt_at, gmdate( 'Y-m-d\TH:i:s\Z' ) );
		update_post_meta( $slide_id, self::meta_last_error, self::sanitize_error_message( $message ) );
	}

	/**
	 * Returns snapshot upload directory data, creating it when needed.
	 *
	 * @return array|WP_Error
	 */
	static function get_snapshot_upload_dir() {
		$uploads = wp_upload_dir();

		if ( ! empty( $uploads['error'] ) ) {
			return self::error( 'upload_dir_unavailable', $uploads['error'] );
		}

		$dir = trailingslashit( $uploads['basedir'] ) . self::upload_dir_name;
		$url = trailingslashit( $uploads['baseurl'] ) . self::upload_dir_name;

		if ( ! wp_mkdir_p( $dir ) ) {
			return self::error( 'snapshot_dir_unavailable', __( 'Could not create the Roku snapshot directory.', 'foyer' ) );
		}

		@chmod( $dir, 0755 );

		return array(
			'path' => $dir,
			'url' => $url,
		);
	}

	/**
	 * Returns deterministic snapshot target data for a slide.
	 *
	 * @param int $slide_id Slide ID.
	 * @return array|WP_Error
	 */
	static function get_snapshot_target( $slide_id ) {
		$upload_dir = self::get_snapshot_upload_dir();

		if ( is_wp_error( $upload_dir ) ) {
			return $upload_dir;
		}

		$filename = self::get_snapshot_filename( $slide_id );

		return array(
			'path' => trailingslashit( $upload_dir['path'] ) . $filename,
			'url' => trailingslashit( $upload_dir['url'] ) . $filename,
			'filename' => $filename,
		);
	}

	/**
	 * Returns a deterministic public filename for a slide snapshot.
	 *
	 * @param int $slide_id Slide ID.
	 * @return string
	 */
	static function get_snapshot_filename( $slide_id ) {
		return 'slide-' . intval( $slide_id ) . '.png';
	}

	/**
	 * Returns a temp path in the snapshot upload directory.
	 *
	 * @param int $slide_id Slide ID.
	 * @return string|WP_Error
	 */
	static function get_temp_snapshot_path( $slide_id ) {
		$upload_dir = self::get_snapshot_upload_dir();

		if ( is_wp_error( $upload_dir ) ) {
			return $upload_dir;
		}

		return trailingslashit( $upload_dir['path'] ) . 'slide-' . intval( $slide_id ) . '-' . wp_generate_password( 12, false, false ) . '.tmp.png';
	}

	/**
	 * Replaces the public snapshot after a completed render.
	 *
	 * @param string $temp_path Temporary PNG path.
	 * @param string $target_path Final PNG path.
	 * @return true|WP_Error
	 */
	static function replace_snapshot_file( $temp_path, $target_path ) {
		if ( ! file_exists( $temp_path ) || ! is_readable( $temp_path ) ) {
			return self::error( 'missing_temp_snapshot', __( 'The rendered temporary snapshot is missing.', 'foyer' ) );
		}

		if ( ! self::is_snapshot_path_allowed( $temp_path ) || ! self::is_snapshot_path_allowed( $target_path ) ) {
			return self::error( 'invalid_snapshot_path', __( 'Snapshot path is outside the controlled directory.', 'foyer' ) );
		}

		if ( @rename( $temp_path, $target_path ) ) {
			@chmod( $target_path, 0644 );
			return true;
		}

		return self::error( 'snapshot_replace_failed', __( 'Could not replace the public snapshot file.', 'foyer' ) );
	}

	/**
	 * Calculates a deterministic revision from file contents.
	 *
	 * @param string $path Snapshot path.
	 * @return string|WP_Error
	 */
	static function calculate_file_revision( $path ) {
		if ( ! self::is_snapshot_path_allowed( $path ) || ! file_exists( $path ) || ! is_readable( $path ) ) {
			return self::error( 'snapshot_not_readable', __( 'Snapshot file is not readable.', 'foyer' ) );
		}

		return hash_file( 'sha256', $path );
	}

	/**
	 * Removes stale temporary files.
	 *
	 * @param int $max_age_seconds Maximum age.
	 * @return int
	 */
	static function cleanup_temp_files( $max_age_seconds = 86400 ) {
		$upload_dir = self::get_snapshot_upload_dir();

		if ( is_wp_error( $upload_dir ) ) {
			return 0;
		}

		$removed = 0;
		$files = glob( trailingslashit( $upload_dir['path'] ) . '*.tmp.png' );

		if ( empty( $files ) ) {
			return 0;
		}

		foreach ( $files as $file ) {
			if ( filemtime( $file ) < time() - intval( $max_age_seconds ) && self::is_snapshot_path_allowed( $file ) ) {
				if ( @unlink( $file ) ) {
					$removed++;
				}
			}
		}

		return $removed;
	}

	/**
	 * Returns a concise URL label for logging.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	static function get_log_host( $url ) {
		$parts = parse_url( $url );

		if ( empty( $parts['host'] ) ) {
			return '';
		}

		return strtolower( $parts['host'] );
	}

	/**
	 * Checks that a path is inside the snapshot upload directory.
	 *
	 * @param string $path Path.
	 * @return bool
	 */
	private static function is_snapshot_path_allowed( $path ) {
		$upload_dir = self::get_snapshot_upload_dir();

		if ( is_wp_error( $upload_dir ) ) {
			return false;
		}

		$base = wp_normalize_path( trailingslashit( $upload_dir['path'] ) );
		$path = wp_normalize_path( $path );

		return 0 === strpos( $path, $base );
	}

	/**
	 * Sanitizes stored error text.
	 *
	 * @param string $message Error message.
	 * @return string
	 */
	private static function sanitize_error_message( $message ) {
		$message = sanitize_text_field( $message );

		if ( function_exists( 'mb_substr' ) ) {
			return mb_substr( $message, 0, 240 );
		}

		return substr( $message, 0, 240 );
	}

	/**
	 * Creates a WP_Error.
	 *
	 * @param string $code Error code.
	 * @param string $message Error message.
	 * @return WP_Error
	 */
	private static function error( $code, $message ) {
		return new WP_Error( $code, $message );
	}
}
