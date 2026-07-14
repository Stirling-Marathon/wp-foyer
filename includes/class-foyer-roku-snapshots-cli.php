<?php

/**
 * WP-CLI command for Roku webpage snapshots.
 *
 * @package Foyer
 * @subpackage Foyer/includes
 */
class Foyer_Roku_Snapshots_CLI {

	/**
	 * Refreshes snapshots for active iframe slides.
	 *
	 * ## OPTIONS
	 *
	 * [--node=<path>]
	 * : Node.js executable path. Defaults to FOYER_ROKU_NODE_BIN or "node".
	 *
	 * [--worker=<path>]
	 * : Snapshot worker path. Defaults to bin/foyer-roku-snapshot-worker.js.
	 *
	 * ## EXAMPLES
	 *
	 *     wp foyer roku-snapshots refresh
	 *     wp foyer roku-snapshots refresh --node=/usr/bin/node
	 *
	 * @param array $args Positional args.
	 * @param array $assoc_args Associative args.
	 * @return void
	 */
	public function refresh( $args, $assoc_args ) {
		$started = microtime( true );
		$node = $this->get_node_path( $assoc_args );
		$worker = $this->get_worker_path( $assoc_args );
		$settings = Foyer_Settings::get_settings();
		$jobs = Foyer_Roku_Snapshots::get_active_iframe_slide_jobs();
		$counts = array(
			'processed' => 0,
			'updated' => 0,
			'unchanged' => 0,
			'failed' => 0,
			'skipped' => 0,
		);

		Foyer_Roku_Snapshots::cleanup_temp_files();

		foreach ( $jobs as $job ) {
			$counts['processed']++;
			$result = $this->process_job( $job, $settings, $node, $worker );
			$counts[ $result ]++;
		}

		$duration = round( microtime( true ) - $started, 3 );

		WP_CLI::log( sprintf(
			'Roku snapshots complete processed=%d updated=%d unchanged=%d failed=%d skipped=%d duration=%ss',
			$counts['processed'],
			$counts['updated'],
			$counts['unchanged'],
			$counts['failed'],
			$counts['skipped'],
			$duration
		) );

		if ( $counts['failed'] > 0 ) {
			WP_CLI::halt( 1 );
		}
	}

	/**
	 * Processes one snapshot job.
	 *
	 * @param array  $job Snapshot job.
	 * @param array  $settings Settings.
	 * @param string $node Node executable.
	 * @param string $worker Worker script.
	 * @return string Result key.
	 */
	private function process_job( $job, $settings, $node, $worker ) {
		$validated_url = Foyer_Roku_Snapshots::validate_url( $job['url'] );
		$target = Foyer_Roku_Snapshots::get_snapshot_target( $job['slide_id'] );
		$temp_path = Foyer_Roku_Snapshots::get_temp_snapshot_path( $job['slide_id'] );
		$started = microtime( true );

		if ( is_wp_error( $validated_url ) ) {
			Foyer_Roku_Snapshots::record_failure( $job['slide_id'], $validated_url->get_error_message() );
			$this->log_job( $job, '', 'skipped', $validated_url->get_error_code(), 0 );
			return 'skipped';
		}

		if ( is_wp_error( $target ) || is_wp_error( $temp_path ) ) {
			$error = is_wp_error( $target ) ? $target : $temp_path;
			Foyer_Roku_Snapshots::record_failure( $job['slide_id'], $error->get_error_message() );
			$this->log_job( $job, $validated_url['host'], 'failed', $error->get_error_code(), 0 );
			return 'failed';
		}

		$payload = array(
			'url' => $validated_url['url'],
			'output' => $temp_path,
			'viewport' => array(
				'width' => intval( $settings['webpage_snapshot_width'] ),
				'height' => intval( $settings['webpage_snapshot_height'] ),
			),
			'timeoutMs' => intval( $settings['webpage_snapshot_timeout_seconds'] ) * 1000,
			'settleMs' => intval( floatval( $settings['webpage_snapshot_settle_seconds'] ) * 1000 ),
			'allowedHosts' => $settings['webpage_snapshot_allowed_hosts'],
		);

		$job_file = $this->write_job_file( $payload );

		if ( is_wp_error( $job_file ) ) {
			Foyer_Roku_Snapshots::record_failure( $job['slide_id'], $job_file->get_error_message() );
			$this->log_job( $job, $validated_url['host'], 'failed', $job_file->get_error_code(), 0 );
			return 'failed';
		}

		$worker_result = $this->run_worker( $node, $worker, $job_file );
		@unlink( $job_file );

		if ( is_wp_error( $worker_result ) ) {
			@unlink( $temp_path );
			Foyer_Roku_Snapshots::record_failure( $job['slide_id'], $worker_result->get_error_message() );
			$this->log_job( $job, $validated_url['host'], 'failed', $worker_result->get_error_message(), microtime( true ) - $started );
			return 'failed';
		}

		$temp_revision = Foyer_Roku_Snapshots::calculate_file_revision( $temp_path );

		if ( is_wp_error( $temp_revision ) ) {
			@unlink( $temp_path );
			Foyer_Roku_Snapshots::record_failure( $job['slide_id'], $temp_revision->get_error_message() );
			$this->log_job( $job, $validated_url['host'], 'failed', $temp_revision->get_error_code(), microtime( true ) - $started );
			return 'failed';
		}

		$current_snapshot = Foyer_Roku_Snapshots::get_successful_snapshot( $job['slide_id'] );

		if ( $current_snapshot && $current_snapshot['revision'] === $temp_revision ) {
			@unlink( $temp_path );
			Foyer_Roku_Snapshots::record_success( $job['slide_id'], $target['path'], $target['url'], $temp_revision );
			$this->log_job( $job, $validated_url['host'], 'unchanged', '', microtime( true ) - $started );
			return 'unchanged';
		}

		$replace = Foyer_Roku_Snapshots::replace_snapshot_file( $temp_path, $target['path'] );

		if ( is_wp_error( $replace ) ) {
			@unlink( $temp_path );
			Foyer_Roku_Snapshots::record_failure( $job['slide_id'], $replace->get_error_message() );
			$this->log_job( $job, $validated_url['host'], 'failed', $replace->get_error_code(), microtime( true ) - $started );
			return 'failed';
		}

		Foyer_Roku_Snapshots::record_success( $job['slide_id'], $target['path'], $target['url'], $temp_revision );
		$this->log_job( $job, $validated_url['host'], 'updated', '', microtime( true ) - $started );

		return 'updated';
	}

	/**
	 * Writes a worker job JSON file.
	 *
	 * @param array $payload Job payload.
	 * @return string|WP_Error
	 */
	private function write_job_file( $payload ) {
		$job_file = wp_tempnam( 'foyer-roku-snapshot-' );

		if ( ! $job_file ) {
			return new WP_Error( 'job_file_unavailable', __( 'Could not create a temporary worker job file.', 'foyer' ) );
		}

		if ( false === file_put_contents( $job_file, wp_json_encode( $payload ) ) ) {
			@unlink( $job_file );
			return new WP_Error( 'job_file_write_failed', __( 'Could not write the worker job file.', 'foyer' ) );
		}

		@chmod( $job_file, 0600 );

		return $job_file;
	}

	/**
	 * Runs the Node Playwright worker.
	 *
	 * @param string $node Node executable.
	 * @param string $worker Worker script.
	 * @param string $job_file Job JSON file.
	 * @return true|WP_Error
	 */
	private function run_worker( $node, $worker, $job_file ) {
		$command = escapeshellarg( $node ) . ' ' . escapeshellarg( $worker ) . ' ' . escapeshellarg( $job_file ) . ' 2>&1';
		$output = array();
		$status = 0;

		exec( $command, $output, $status );

		if ( 0 !== $status ) {
			$message = empty( $output ) ? __( 'Snapshot worker failed.', 'foyer' ) : implode( ' ', array_slice( $output, -3 ) );
			return new WP_Error( 'snapshot_worker_failed', $this->redact_urls( $message ) );
		}

		return true;
	}

	/**
	 * Gets the Node executable path.
	 *
	 * @param array $assoc_args CLI args.
	 * @return string
	 */
	private function get_node_path( $assoc_args ) {
		if ( ! empty( $assoc_args['node'] ) ) {
			return $assoc_args['node'];
		}

		if ( defined( 'FOYER_ROKU_NODE_BIN' ) ) {
			return FOYER_ROKU_NODE_BIN;
		}

		return 'node';
	}

	/**
	 * Gets the worker script path.
	 *
	 * @param array $assoc_args CLI args.
	 * @return string
	 */
	private function get_worker_path( $assoc_args ) {
		if ( ! empty( $assoc_args['worker'] ) ) {
			return $assoc_args['worker'];
		}

		return FOYER_PLUGIN_PATH . 'bin/foyer-roku-snapshot-worker.js';
	}

	/**
	 * Logs one job result without query strings or secrets.
	 *
	 * @param array  $job Job.
	 * @param string $host Host.
	 * @param string $status Status.
	 * @param string $message Message.
	 * @param float  $duration Duration.
	 * @return void
	 */
	private function log_job( $job, $host, $status, $message, $duration ) {
		WP_CLI::log( sprintf(
			'display=%d channel=%d slide=%d host=%s status=%s duration=%ss message=%s',
			intval( $job['display_id'] ),
			intval( $job['channel_id'] ),
			intval( $job['slide_id'] ),
			sanitize_text_field( $host ),
			sanitize_key( $status ),
			round( floatval( $duration ), 3 ),
			sanitize_text_field( $message )
		) );
	}

	/**
	 * Redacts query strings and paths from URLs in worker output.
	 *
	 * @param string $message Message.
	 * @return string
	 */
	private function redact_urls( $message ) {
		return preg_replace_callback( '#https?://[^\s\'"]+#i', array( $this, 'redact_url_match' ), $message );
	}

	/**
	 * Redacts one URL match.
	 *
	 * @param array $matches Regex matches.
	 * @return string
	 */
	private function redact_url_match( $matches ) {
		$parts = parse_url( $matches[0] );

		if ( empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return '[redacted-url]';
		}

		return strtolower( $parts['scheme'] ) . '://' . strtolower( $parts['host'] ) . '/[redacted]';
	}
}

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	WP_CLI::add_command( 'foyer roku-snapshots', 'Foyer_Roku_Snapshots_CLI' );
}
