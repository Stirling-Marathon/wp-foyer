<?php

/**
 * Centralized settings access for the Stirling Foyer fork.
 *
 * @package Foyer
 * @subpackage Foyer/includes
 */
class Foyer_Settings {

	const option_name = 'foyer_settings';

	/**
	 * Returns the default settings.
	 *
	 * @return array
	 */
	static function get_defaults() {
		return array(
			'transition_duration_seconds' => 1.5,
			'content_refresh_seconds' => 300,
			'forced_reload_seconds' => 28800,
			'roku_manifest_refresh_seconds' => 60,
			'webpage_snapshot_refresh_seconds' => 120,
			'webpage_snapshot_width' => 1920,
			'webpage_snapshot_height' => 1080,
			'webpage_snapshot_timeout_seconds' => 30,
			'webpage_snapshot_settle_seconds' => 2,
			'webpage_snapshot_allowed_hosts' => array(),
		);
	}

	/**
	 * Returns validation rules for settings fields.
	 *
	 * @return array
	 */
	static function get_schema() {
		return array(
			'transition_duration_seconds' => array(
				'type' => 'float',
				'min' => 0,
				'max' => 10,
			),
			'content_refresh_seconds' => array(
				'type' => 'int',
				'min' => 30,
				'max' => 86400,
			),
			'forced_reload_seconds' => array(
				'type' => 'int',
				'min' => 300,
				'max' => 604800,
			),
			'roku_manifest_refresh_seconds' => array(
				'type' => 'int',
				'min' => 10,
				'max' => 3600,
			),
			'webpage_snapshot_refresh_seconds' => array(
				'type' => 'int',
				'min' => 30,
				'max' => 86400,
			),
			'webpage_snapshot_width' => array(
				'type' => 'int',
				'min' => 320,
				'max' => 7680,
			),
			'webpage_snapshot_height' => array(
				'type' => 'int',
				'min' => 240,
				'max' => 4320,
			),
			'webpage_snapshot_timeout_seconds' => array(
				'type' => 'int',
				'min' => 5,
				'max' => 120,
			),
			'webpage_snapshot_settle_seconds' => array(
				'type' => 'float',
				'min' => 0,
				'max' => 30,
			),
			'webpage_snapshot_allowed_hosts' => array(
				'type' => 'hosts',
			),
		);
	}

	/**
	 * Returns all settings, merged with defaults and sanitized.
	 *
	 * @return array
	 */
	static function get_settings() {
		$settings = get_option( self::option_name, array() );

		return self::sanitize( $settings );
	}

	/**
	 * Returns a single setting.
	 *
	 * @param string $key The setting key.
	 * @return mixed|null
	 */
	static function get( $key ) {
		$settings = self::get_settings();

		if ( array_key_exists( $key, $settings ) ) {
			return $settings[ $key ];
		}

		return null;
	}

	/**
	 * Sanitizes settings for storage and runtime use.
	 *
	 * @param array $input Raw settings.
	 * @return array
	 */
	static function sanitize( $input ) {
		$defaults = self::get_defaults();
		$schema = self::get_schema();
		$output = array();

		if ( ! is_array( $input ) ) {
			$input = array();
		}

		foreach ( $defaults as $key => $default ) {
			$value = isset( $input[ $key ] ) ? $input[ $key ] : $default;
			$rules = $schema[ $key ];

			if ( 'hosts' === $rules['type'] ) {
				$output[ $key ] = self::sanitize_hosts( $value );
				continue;
			}

			if ( 'float' === $rules['type'] ) {
				$value = floatval( $value );
			} else {
				$value = intval( $value );
			}

			if ( $value < $rules['min'] ) {
				$value = $rules['min'];
			}

			if ( $value > $rules['max'] ) {
				$value = $rules['max'];
			}

			$output[ $key ] = $value;
		}

		return $output;
	}

	/**
	 * Sanitizes an allowed-host list.
	 *
	 * @param array|string $input Host list.
	 * @return array
	 */
	private static function sanitize_hosts( $input ) {
		if ( is_string( $input ) ) {
			$input = preg_split( '/[\r\n,]+/', $input );
		}

		if ( ! is_array( $input ) ) {
			$input = array();
		}

		$hosts = array();

		foreach ( $input as $host ) {
			$host = Foyer_Roku_Snapshots::normalize_host( $host );

			if ( is_wp_error( $host ) ) {
				continue;
			}

			$hosts[] = $host;
		}

		$hosts = array_values( array_unique( $hosts ) );

		return $hosts;
	}
}
