<?php

/**
 * Admin settings page for the Stirling Foyer fork.
 *
 * @package Foyer
 * @subpackage Foyer/admin
 */
class Foyer_Admin_Settings {

	/**
	 * Adds the settings submenu page.
	 *
	 * @return void
	 */
	static function add_settings_page() {
		add_submenu_page(
			'foyer',
			__( 'Foyer Settings', 'foyer' ),
			__( 'Settings', 'foyer' ),
			'manage_options',
			'foyer-settings',
			array( __CLASS__, 'render_settings_page' )
		);
	}

	/**
	 * Registers settings, sections, and fields.
	 *
	 * @return void
	 */
	static function register_settings() {
		register_setting(
			'foyer_settings',
			Foyer_Settings::option_name,
			array( 'Foyer_Settings', 'sanitize' )
		);

		add_settings_section(
			'foyer_settings_browser',
			__( 'Browser Player', 'foyer' ),
			array( __CLASS__, 'render_browser_section' ),
			'foyer-settings'
		);

		add_settings_section(
			'foyer_settings_roku',
			__( 'Roku and Snapshots', 'foyer' ),
			array( __CLASS__, 'render_roku_section' ),
			'foyer-settings'
		);

		self::add_field( 'content_refresh_seconds', __( 'Content refresh interval', 'foyer' ), 'foyer_settings_browser', '1' );
		self::add_field( 'forced_reload_seconds', __( 'Forced reload interval', 'foyer' ), 'foyer_settings_browser', '1' );
		self::add_field( 'roku_manifest_refresh_seconds', __( 'Roku manifest refresh interval', 'foyer' ), 'foyer_settings_roku', '1' );
		self::add_field( 'webpage_snapshot_refresh_seconds', __( 'Webpage snapshot refresh interval', 'foyer' ), 'foyer_settings_roku', '1' );
		self::add_field( 'webpage_snapshot_width', __( 'Webpage snapshot width', 'foyer' ), 'foyer_settings_roku', '1', __( 'pixels', 'foyer' ) );
		self::add_field( 'webpage_snapshot_height', __( 'Webpage snapshot height', 'foyer' ), 'foyer_settings_roku', '1', __( 'pixels', 'foyer' ) );
		self::add_field( 'webpage_snapshot_timeout_seconds', __( 'Webpage snapshot timeout', 'foyer' ), 'foyer_settings_roku', '1' );
		self::add_field( 'webpage_snapshot_settle_seconds', __( 'Webpage snapshot settle delay', 'foyer' ), 'foyer_settings_roku', '0.1' );
		self::add_textarea_field( 'webpage_snapshot_allowed_hosts', __( 'Webpage snapshot allowed hosts', 'foyer' ), 'foyer_settings_roku' );
	}

	/**
	 * Adds a settings field.
	 *
	 * @param string $key The setting key.
	 * @param string $label The field label.
	 * @param string $section The settings section.
	 * @param string $step The number input step.
	 * @return void
	 */
	private static function add_field( $key, $label, $section, $step, $unit = null ) {
		if ( null === $unit ) {
			$unit = __( 'seconds', 'foyer' );
		}

		add_settings_field(
			$key,
			$label,
			array( __CLASS__, 'render_number_field' ),
			'foyer-settings',
			$section,
			array(
				'key' => $key,
				'step' => $step,
				'unit' => $unit,
			)
		);
	}

	/**
	 * Adds a textarea settings field.
	 *
	 * @param string $key The setting key.
	 * @param string $label The field label.
	 * @param string $section The settings section.
	 * @return void
	 */
	private static function add_textarea_field( $key, $label, $section ) {
		add_settings_field(
			$key,
			$label,
			array( __CLASS__, 'render_hosts_field' ),
			'foyer-settings',
			$section,
			array(
				'key' => $key,
			)
		);
	}

	/**
	 * Renders the browser section description.
	 *
	 * @return void
	 */
	static function render_browser_section() {
		echo '<p>' . esc_html__( 'Timing values used by the existing Foyer browser player.', 'foyer' ) . '</p>';
	}

	/**
	 * Renders the Roku section description.
	 *
	 * @return void
	 */
	static function render_roku_section() {
		echo '<p>' . esc_html__( 'Shared values reserved for the Roku manifest and webpage snapshot phases.', 'foyer' ) . '</p>';
	}

	/**
	 * Renders a number input field.
	 *
	 * @param array $args Field arguments.
	 * @return void
	 */
	static function render_number_field( $args ) {
		$key = $args['key'];
		$schema = Foyer_Settings::get_schema();
		$value = Foyer_Settings::get( $key );

		printf(
			'<input name="%1$s[%2$s]" id="%2$s" type="number" min="%3$s" max="%4$s" step="%5$s" value="%6$s" class="small-text" />',
			esc_attr( Foyer_Settings::option_name ),
			esc_attr( $key ),
			esc_attr( $schema[ $key ]['min'] ),
			esc_attr( $schema[ $key ]['max'] ),
			esc_attr( $args['step'] ),
			esc_attr( $value )
		);

		echo ' ';
		echo esc_html( $args['unit'] );
	}

	/**
	 * Renders the allowed-hosts textarea.
	 *
	 * @param array $args Field arguments.
	 * @return void
	 */
	static function render_hosts_field( $args ) {
		$key = $args['key'];
		$value = Foyer_Settings::get( $key );

		if ( is_array( $value ) ) {
			$value = implode( "\n", $value );
		}

		printf(
			'<textarea name="%1$s[%2$s]" id="%2$s" rows="5" cols="40" class="large-text code">%3$s</textarea>',
			esc_attr( Foyer_Settings::option_name ),
			esc_attr( $key ),
			esc_textarea( $value )
		);
	}

	/**
	 * Renders the settings page.
	 *
	 * @return void
	 */
	static function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'foyer' ) );
		}

		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'Foyer Settings', 'foyer' ); ?></h1>
			<form action="options.php" method="post">
				<?php
				settings_fields( 'foyer_settings' );
				do_settings_sections( 'foyer-settings' );
				submit_button();
				?>
			</form>
		</div>
		<?php
	}
}
