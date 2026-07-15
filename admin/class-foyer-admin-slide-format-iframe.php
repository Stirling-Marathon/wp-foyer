<?php

/**
 * Adds admin functionality for the Iframe slide format.
 *
 * @since		1.3.0
 *
 * @package		Foyer
 * @subpackage	Foyer/admin
 * @author		Menno Luitjes <menno@mennoluitjes.nl>
 */
class Foyer_Admin_Slide_Format_Iframe {

	/**
	 * Saves additional data for the Iframe slide format.
	 *
	 * @since	1.3.0
	 *
	 * @param	int		$post_id	The ID of the post being saved.
	 * @return	void
	 */
	static function save_slide( $post_id ) {
		$slide_iframe_website_url = sanitize_text_field( $_POST['slide_iframe_website_url'] );
		update_post_meta( $post_id, 'slide_iframe_website_url', $slide_iframe_website_url );
		self::maybe_add_snapshot_allowed_host( $slide_iframe_website_url );
	}

	/**
	 * Adds a valid iframe URL host to the Roku snapshot allowlist.
	 *
	 * @since	1.8.0-stirling.1
	 *
	 * @param string $url Submitted iframe URL.
	 * @return void
	 */
	private static function maybe_add_snapshot_allowed_host( $url ) {
		$url = trim( (string) $url );

		if ( '' === $url ) {
			return;
		}

		$url_data = Foyer_Roku_Snapshots::get_valid_url_data( $url );
		if ( is_wp_error( $url_data ) ) {
			Foyer_Admin_Slide::add_admin_notice(
				'error',
				__( 'The iframe URL host was not added to the Roku snapshot allowlist because the URL is not valid for snapshots.', 'foyer' )
			);
			return;
		}

		$host = $url_data['host'];
		$settings = Foyer_Settings::get_settings();
		$hosts = empty( $settings['webpage_snapshot_allowed_hosts'] ) ? array() : $settings['webpage_snapshot_allowed_hosts'];

		if ( in_array( $host, $hosts, true ) ) {
			return;
		}

		$hosts[] = $host;
		$settings['webpage_snapshot_allowed_hosts'] = $hosts;
		update_option( Foyer_Settings::option_name, Foyer_Settings::sanitize( $settings ) );

		Foyer_Admin_Slide::add_admin_notice(
			'success',
			sprintf(
				/* translators: %s: host name. */
				__( 'Added %s to the Roku snapshot allowed hosts.', 'foyer' ),
				$host
			)
		);
	}

	/**
	 * Outputs the meta box for the Iframe slide format.
	 *
	 * @since	1.3.0
	 *
	 * @param	WP_Post	$post	The post of the current slide.
	 * @return	void
	 */
	static function slide_meta_box( $post ) {
		$slide_iframe_website_url = get_post_meta( $post->ID, 'slide_iframe_website_url', true );

		$https = ( 0 === stripos( 'https://', get_permalink() ) );
		$placeholder = __( 'https://...', 'foyer' );

		?><table class="form-table">
			<tbody>
				<tr>
					<th scope="row">
						<label for="slide_iframe_website_url"><?php _e( 'Web page URL', 'foyer' ); ?></label>
					</th>
					<td>
						<input type="text" name="slide_iframe_website_url" id="slide_iframe_website_url" placeholder="<?php echo $placeholder; ?>" class="all-options"
							value="<?php echo esc_url( $slide_iframe_website_url ); ?>" />
						<?php if ( $https ) { ?>
							<p><?php _e( 'Be sure to use an https URL', 'foyer' ); ?></p>
						<?php } ?>
					</td>
				</tr>
			</tbody>
		</table><?php
	}
}
