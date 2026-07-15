<?php

/**
 * The channel object model.
 *
 * @since		1.0.0
 *
 * @package		Foyer
 * @subpackage	Foyer/includes
 * @author		Menno Luitjes <menno@mennoluitjes.nl>
 */
class Foyer_Channel {

	/**
	 * The Foyer Channel post type name.
	 *
	 * @since    1.0.0
	 * @access   private
	 * @var      string    $post_type_name    The Foyer Channel post type name.
	 */
	const post_type_name = 'foyer_channel';

	public $ID;
	private $post;

	/**
	 * The slides of this channel.
	 *
	 * @since    1.0.0
	 * @access   private
	 * @var      string    $slides    The slides of this channel.
	 */
	private $slides;

	/**
	 * The slides duration setting of this channel.
	 *
	 * @since    1.0.0
	 * @access   private
	 * @var      string    $slides    The slides duration setting of this channel.
	 */
	private $slides_duration;

	/**
	 * The slides transition setting of this channel.
	 *
	 * @since    1.0.0
	 * @access   private
	 * @var      string    $slides    The slides transition setting of this channel.
	 */
	private $slides_transition;

	/**
	 * Initialize the class and set its properties.
	 *
	 * @since	1.0.0
	 * @param	int or WP_Post	$ID		The id or the WP_Post object of the channel.
	 */
	public function __construct( $ID = false ) {

		if ( $ID instanceof WP_Post ) {
			// $ID is a WP_Post object
			$this->post = $ID;
			$ID = $ID->ID;
		}

		$this->ID = $ID;
	}

	/**
	 * Outputs the channel classes for use in the template.
	 *
	 * The output is escaped, so this method can be used in templates without further escaping.
	 *
	 * @since	1.0.1			Escaped the output.
	 *
	 * @param 	array 	$classes
	 * @return 	string
	 */
	public function classes( $classes = array() ) {

		$classes[] = 'foyer-channel';
		$classes[] = 'foyer-channel-' . intval( $this->ID );
		$classes[] = 'foyer-transition-' . $this->get_slides_transition();

		if ( empty( $classes ) ) {
			return;
		}

		?> class="<?php echo esc_attr( implode( ' ', $classes ) ); ?>" <?php
	}

	/**
	 * Get slides for this channel.
	 *
	 * @since	1.0.0
	 * @since	1.3.2	Only includes slides that are published.
	 *
	 * @access	public
	 * @return	array of Foyer_Slide	The slides for this channel.
	 */
	public function get_slides( $time = null ) {

		$cache_key = $this->get_slide_cache_key( $time, false );

		if ( ! isset( $this->slides[ $cache_key ] ) ) {

			$slides = $this->get_filtered_slides( $time, false );

			$this->slides[ $cache_key ] = $slides;
		}

		return $this->slides[ $cache_key ];
	}

	/**
	 * Get slides that are visible now or scheduled for the future.
	 *
	 * Used by the Roku snapshot worker to pre-generate upcoming iframe snapshots.
	 *
	 * @since	1.8.0-stirling.1
	 *
	 * @access	public
	 * @param DateTimeInterface|null $time The comparison time. Defaults to current site time.
	 * @return	array of Foyer_Slide	The current and future slides for this channel.
	 */
	public function get_current_and_future_slides( $time = null ) {

		$cache_key = $this->get_slide_cache_key( $time, true );

		if ( ! isset( $this->slides[ $cache_key ] ) ) {
			$this->slides[ $cache_key ] = $this->get_filtered_slides( $time, true );
		}

		return $this->slides[ $cache_key ];
	}

	/**
	 * Returns filtered slides while preserving configured order.
	 *
	 * @since	1.8.0-stirling.1
	 *
	 * @param DateTimeInterface|null $time The comparison time.
	 * @param bool                   $include_future Whether future scheduled slides should be included.
	 * @return array
	 */
	private function get_filtered_slides( $time = null, $include_future = false ) {
		$slides = array();
		$posts = get_post_meta( $this->ID, Foyer_Slide::post_type_name, true );

		if ( empty( $posts ) ) {
			return $slides;
		}

		foreach ( $posts as $post ) {

			// Only include slides with post status 'publish'
			if ( 'publish' != get_post_status( $post ) ) {
				continue;
			}

			$slide = new Foyer_Slide( $post );

			if ( $include_future ) {
				if ( ! $slide->is_current_or_future_at( $time ) ) {
					continue;
				}
			}
			else if ( ! $slide->is_visible_at( $time ) ) {
				continue;
			}

			$slides[] = $slide;
		}

		return $slides;
	}

	/**
	 * Builds a cache key for filtered slide lists.
	 *
	 * @since	1.8.0-stirling.1
	 *
	 * @param DateTimeInterface|null $time The comparison time.
	 * @param bool                   $include_future Whether future slides are included.
	 * @return string
	 */
	private function get_slide_cache_key( $time = null, $include_future = false ) {
		$timestamp = $time instanceof DateTimeInterface ? $time->getTimestamp() : current_datetime()->getTimestamp();

		return ( $include_future ? 'future' : 'visible' ) . ':' . $timestamp;
	}

	/**
	 * Get slides duration setting for this channel as saved in the database.
	 *
	 * @since	1.0.0
	 * @access	public
	 * @return	string		The slides duration setting for this channel as saved in the database.
	 */
	public function get_saved_slides_duration() {
		return get_post_meta( $this->ID, Foyer_Channel::post_type_name . '_slides_duration', true );
	}

	/**
	 * Get slides transition setting for this channel as saved in the database.
	 *
	 * @since	1.0.0
	 * @access	public
	 * @return	string		The slides transition setting for this channel as saved in the database.
	 */
	public function get_saved_slides_transition() {
			return get_post_meta( $this->ID, Foyer_Channel::post_type_name . '_slides_transition', true );
	}

	/**
	 * Get slides duration setting for this channel, or the default slides duration when not set.
	 *
	 * @since	1.0.0
	 * @access	public
	 * @return	string		The slides duration setting for this channel, or the default slides duration when not set.
	 */
	public function get_slides_duration() {

		if ( ! isset( $this->slides_duration ) ) {

			$slides_duration = self::get_saved_slides_duration();
			if ( empty( $slides_duration ) ) {
				$slides_duration = Foyer_Slides::get_default_slides_duration();
			}
			$this->slides_duration = $slides_duration;
		}

		return $this->slides_duration;
	}

	/**
	 * Get slides transition setting for this channel, or the default slides transition when not set.
	 *
	 * @since	1.0.0
	 * @access	public
	 * @return	string		The slides transition setting for this channel, or the default slides transition when not set.
	 */
	public function get_slides_transition() {

		if ( ! isset( $this->slides_transition ) ) {

			$slides_transition = self::get_saved_slides_transition();
			if ( empty( $slides_transition ) ) {
				$slides_transition = Foyer_Slides::get_default_slides_transition();
			}
			$this->slides_transition = $slides_transition;
		}

		return $this->slides_transition;
	}
}
