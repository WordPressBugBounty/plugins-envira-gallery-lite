<?php
/**
 * Common class.
 *
 * @since 1.0.0
 *
 * @package Envira_Gallery
 * @author  Envira Gallery Team
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Envira Common Class
 *
 * @since 1.0.0
 */
class Envira_Gallery_Common {

	/**
	 * Holds the class object.
	 *
	 * @since 1.0.0
	 *
	 * @var object
	 */
	public static $instance;

	/**
	 * Path to the file.
	 *
	 * @since 1.0.0
	 *
	 * @var string
	 */
	public $file = __FILE__;

	/**
	 * Holds the base class object.
	 *
	 * @since 1.0.0
	 *
	 * @var object
	 */
	public $base;

	/**
	 * Primary class constructor.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {

		// Load the base class object.
		$this->base = Envira_Gallery_Lite::get_instance();
		$this->hooks();
	}

	/**
	 * Class Hooks
	 *
	 * @since 1.8.7
	 */
	public function hooks() {
		add_filter( 'wp_get_attachment_url', [ $this, 'fix_ssl_images' ], 10, 1 );
		add_filter( 'envira_image_src', [ $this, 'fix_ssl_images' ], 10, 1 );
		add_filter( 'envira_output_src', [ $this, 'fix_ssl_images' ], 10, 1 );
	}

	/**
	 * The actual fix.
	 *
	 * @param string $url The url to fix.
	 *
	 * @return string
	 */
	public function fix_ssl_images( $url ) {
		return function_exists( 'is_ssl' ) && is_ssl() ? str_replace( 'http://', 'https://', $url ) : $url;
	}

	/**
	 * Helper method for retrieving columns.
	 *
	 * @since 1.0.0
	 *
	 * @return array Array of column data.
	 */
	public function get_columns() {

		$columns = [
			[
				'value' => '0',
				'name'  => __( 'Automatic', 'envira-gallery-lite' ),
			],
			[
				'value' => '1',
				'name'  => __( 'One Column (1)', 'envira-gallery-lite' ),
			],
			[
				'value' => '2',
				'name'  => __( 'Two Columns (2)', 'envira-gallery-lite' ),
			],
			[
				'value' => '3',
				'name'  => __( 'Three Columns (3)', 'envira-gallery-lite' ),
			],
			[
				'value' => '4',
				'name'  => __( 'Four Columns (4)', 'envira-gallery-lite' ),
			],
			[
				'value' => '5',
				'name'  => __( 'Five Columns (5)', 'envira-gallery-lite' ),
			],
			[
				'value' => '6',
				'name'  => __( 'Six Columns (6)', 'envira-gallery-lite' ),
			],
		];

		return apply_filters( 'envira_gallery_columns', $columns );
	}

	/**
	 * Helper method for retrieving gallery themes.
	 *
	 * @since 1.0.0
	 *
	 * @return array Array of gallery theme data.
	 */
	public function get_gallery_themes() {

		$themes = [
			[
				'value' => 'base',
				'name'  => __( 'Base', 'envira-gallery-lite' ),
				'file'  => $this->base->file,
			],
		];

		return apply_filters( 'envira_gallery_gallery_themes', $themes );
	}

	/**
	 * Helper method for retrieving justified gallery themes.
	 *
	 * @since 1.1.1
	 *
	 * @return array Array of gallery theme data.
	 */
	public function get_justified_gallery_themes() {

		$themes = [
			[
				'value' => 'normal',
				'name'  => __( 'Normal', 'envira-gallery-lite' ),
				'file'  => $this->base->file,
			],
		];

		return apply_filters( 'envira_gallery_justified_gallery_themes', $themes );
	}

	/**
	 * Sanitizes the justified gallery theme parameter to prevent XSS.
	 *
	 * @since 1.12.4
	 *
	 * @param string $theme The theme value to sanitize.
	 * @return string Sanitized theme value.
	 */
	public function sanitize_justified_gallery_theme( $theme ) {
		// Get valid themes
		$valid_themes       = $this->get_justified_gallery_themes();
		$valid_theme_values = wp_list_pluck( $valid_themes, 'value' );

		// Anything non-scalar cannot be a theme slug, and trim() raises an uncaught
		// TypeError on an array under PHP 8 - a 500 on the REST write that reaches here
		// with a client-supplied config value.
		if ( ! is_scalar( $theme ) ) {
			return $this->get_config_default( 'justified_gallery_theme' );
		}

		// Sanitize the input - remove any HTML tags and trim whitespace
		$theme = sanitize_text_field( trim( (string) $theme ) );

		// Check if theme is in the list of valid themes
		if ( in_array( $theme, $valid_theme_values, true ) ) {
			return $theme;
		}

		// Default to 'normal' if invalid theme provided
		return $this->get_config_default( 'justified_gallery_theme' );
	}

	/**
	 * Helper method for retrieving display description options.
	 *
	 * @since 1.3.7.3
	 *
	 * @return array Array of description placement options.
	 */
	public function get_display_description_options() {

		$descriptions = [
			[
				'name'  => __( 'Do not display', 'envira-gallery-lite' ),
				'value' => 0,
			],
			[
				'name'  => __( 'Display above galleries', 'envira-gallery-lite' ),
				'value' => 'above',
			],
			[
				'name'  => __( 'Display below galleries', 'envira-gallery-lite' ),
				'value' => 'below',
			],
		];

		return apply_filters( 'envira_gallery_display_description_options', $descriptions );
	}

	/**
	 * Helper method for retrieving display sorting options.
	 *
	 * @since 1.3.8
	 *
	 * @return array Array of sorting options
	 */
	public function get_sorting_options() {

		$options = [
			[
				'name'  => __( 'No Sorting', 'envira-gallery-lite' ),
				'value' => 0,
			],
			[
				'name'  => __( 'Random', 'envira-gallery-lite' ),
				'value' => 1, // Deliberate, as we map the 'random' config key which was a true/false.
			],
			[
				'name'  => __( 'Published Date', 'envira-gallery-lite' ),
				'value' => 'date',
			],
			[
				'name'  => __( 'Filename', 'envira-gallery-lite' ),
				'value' => 'src',
			],
			[
				'name'  => __( 'Title', 'envira-gallery-lite' ),
				'value' => 'title',
			],
			[
				'name'  => __( 'Caption', 'envira-gallery-lite' ),
				'value' => 'caption',
			],
			[
				'name'  => __( 'Alt', 'envira-gallery-lite' ),
				'value' => 'alt',
			],
			[
				'name'  => __( 'URL', 'envira-gallery-lite' ),
				'value' => 'link',
			],
		];

		return apply_filters( 'envira_gallery_sorting_options', $options );
	}

	/**
	 * Helper method for retrieving sorting directions
	 *
	 * @since 1.3.8
	 *
	 * @return array Array of sorting directions
	 */
	public function get_sorting_directions() {

		$directions = [
			[
				'name'  => __( 'Ascending (A-Z)', 'envira-gallery-lite' ),
				'value' => 'ASC',
			],
			[
				'name'  => __( 'Descending (Z-A)', 'envira-gallery-lite' ),
				'value' => 'DESC',
			],
		];

		return apply_filters( 'envira_gallery_sorting_directions', $directions );
	}

	/**
	 * Helper method for retrieving lightbox themes.
	 *
	 * @since 1.0.0
	 *
	 * @return array Array of lightbox theme data.
	 */
	public function get_lightbox_themes() {

		$themes = [
			[
				'value' => 'base',
				'name'  => __( 'Legacy', 'envira-gallery-lite' ),
				'file'  => $this->base->file,
			],
		];

		return apply_filters( 'envira_gallery_lightbox_themes', $themes );
	}

	/**
	 * Helper method for retrieving image sizes.
	 *
	 * @since 1.3.6
	 *
	 * @global array $_wp_additional_image_sizes Array of registered image sizes.
	 *
	 * @param   bool $wordpress_only     WordPress Only (excludes the default and envira_gallery_random options).
	 * @return  array                       Array of image size data.
	 */
	public function get_image_sizes( $wordpress_only = false ) {

		if ( ! $wordpress_only ) {
			$sizes = [
				[
					'value' => 'default',
					'name'  => __( 'Default', 'envira-gallery-lite' ),
				],
			];
		}

		global $_wp_additional_image_sizes;
		$wp_sizes = get_intermediate_image_sizes();
		foreach ( $wp_sizes as $size ) {
			if ( isset( $_wp_additional_image_sizes[ $size ] ) ) {
				$width  = absint( $_wp_additional_image_sizes[ $size ]['width'] );
				$height = absint( $_wp_additional_image_sizes[ $size ]['height'] );
			} else {
				$width  = absint( get_option( $size . '_size_w' ) );
				$height = absint( get_option( $size . '_size_h' ) );
			}

			if ( ! $width && ! $height ) {
				$sizes[] = [
					'value' => $size,
					'name'  => ucwords( str_replace( [ '-', '_' ], ' ', $size ) ),
				];
			} else {
				$sizes[] = [
					'value'  => $size,
					'name'   => ucwords( str_replace( [ '-', '_' ], ' ', $size ) ) . ' (' . $width . ' &#215; ' . $height . ')',
					'width'  => $width,
					'height' => $height,
				];
			}
		}

		// Add Random option.
		if ( ! $wordpress_only ) {
			$sizes[] = [
				'value' => 'envira_gallery_random',
				'name'  => __( 'Random', 'envira-gallery-lite' ),
			];
		}

		$sizes[] = [
			'value' => 'full',
			'name'  => __( 'Original Image', 'envira-gallery-lite' ),
		];

		return apply_filters( 'envira_gallery_image_sizes', $sizes );
	}

	/**
	 * Helper method for retrieving title displays.
	 *
	 * @since 1.0.0
	 *
	 * @return array Array of title display data.
	 */
	public function get_title_displays() {

		$displays = [
			[
				'value' => 'float',
				'name'  => __( 'Float', 'envira-gallery-lite' ),
			],
			[
				'value' => 'float_wrap',
				'name'  => __( 'Float (Wrapped)', 'envira-gallery-lite' ),
			],
			[
				'value' => 'inside',
				'name'  => __( 'Inside', 'envira-gallery-lite' ),
			],
			[
				'value' => 'outside',
				'name'  => __( 'Outside', 'envira-gallery-lite' ),
			],
			[
				'value' => 'over',
				'name'  => __( 'Over', 'envira-gallery-lite' ),
			],
		];

		return apply_filters( 'envira_gallery_title_displays', $displays );
	}

	/**
	 * Helper method for retrieving arrow positions.
	 *
	 * @since 1.3.3.7
	 *
	 * @return array Array of arrow position display data.
	 */
	public function get_arrows_positions() {

		$displays = [
			[
				'value' => 'inside',
				'name'  => __( 'Inside', 'envira-gallery-lite' ),
			],
			[
				'value' => 'outside',
				'name'  => __( 'Outside', 'envira-gallery-lite' ),
			],
		];

		return apply_filters( 'envira_gallery_arrows_positions', $displays );
	}

	/**
	 * Helper method for retrieving lightbox transition effects.
	 *
	 * @since 1.0.0
	 *
	 * @return array Array of transition effect data.
	 */
	public function get_transition_effects() {

		$effects = [
			[
				'value' => 'none',
				'name'  => __( 'No Effect', 'envira-gallery-lite' ),
			],
			[
				'value' => 'fade',
				'name'  => __( 'Fade', 'envira-gallery-lite' ),
			],
			[
				'value' => 'elastic',
				'name'  => __( 'Elastic', 'envira-gallery-lite' ),
			],
		];

		return apply_filters( 'envira_gallery_transition_effects', $effects );
	}

	/**
	 * Helper method for retrieving an array of lightbox transition effect values
	 *
	 * @since 1.4.1.2
	 *
	 * @return array Transition values.
	 */
	public function get_transition_effects_values() {

		// Get effects.
		$effects = $this->get_transition_effects();

		// Build array.
		$effect_values = [];
		foreach ( $effects as $effect ) {
			$effect_values[] = $effect['value'];
		}

		// Return.
		return apply_filters( 'envira_gallery_transition_effects_values', $effect_values, $effects );
	}

	/**
	 * Helper method for retrieving lightbox easing transition effects.
	 *
	 * These are deliberately seperate from get_transition_effects() above, so that
	 * we can determine whether an effect on a Gallery is an easing one or not.
	 *
	 * In turn, that determines the setting keys used for Fancybox (e.g. openEffect vs openEasing)
	 *
	 * @since 1.4.1.2
	 *
	 * @return array Array of easing transition effects
	 */
	public function get_easing_transition_effects() {

		$effects = [
			[
				'value' => 'Swing',
				'name'  => __( 'Swing', 'envira-gallery-lite' ),
			],
		];

		return apply_filters( 'envira_gallery_easing_transition_effects', $effects );
	}

	/**
	 * Helper method for retrieving toolbar positions.
	 *
	 * @since 1.0.0
	 *
	 * @return array Array of toolbar position data.
	 */
	public function get_toolbar_positions() {

		$positions = [
			[
				'value' => 'top',
				'name'  => __( 'Top', 'envira-gallery-lite' ),
			],
			[
				'value' => 'bottom',
				'name'  => __( 'Bottom', 'envira-gallery-lite' ),
			],
		];

		return apply_filters( 'envira_gallery_toolbar_positions', $positions );
	}

	/**
	 * Helper method for retrieving thumbnail positions.
	 *
	 * @since 1.0.0
	 *
	 * @return array Array of thumbnail position data.
	 */
	public function get_thumbnail_positions() {

		$positions = [
			[
				'value' => 'top',
				'name'  => __( 'Top', 'envira-gallery-lite' ),
			],
			[
				'value' => 'bottom',
				'name'  => __( 'Bottom', 'envira-gallery-lite' ),
			],
		];

		return apply_filters( 'envira_gallery_thumbnail_positions', $positions );
	}

	/**
	 * Helper method for setting default config values.
	 *
	 * @since 1.0.0
	 *
	 * @global int $id      The current post ID.
	 * @global object $post The current post object.
	 * @param string $key   The default config key to retrieve.
	 * @return string       Key value on success, false on failure.
	 */
	public function get_config_default( $key ) {

		global $id, $post;

		// Get the current post ID. If ajax, grab it from the $_POST variable.
		if ( defined( 'DOING_AJAX' ) && DOING_AJAX && isset( $_POST['post_id'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- get_config_default() is a read-only helper; nonce verification is the responsibility of the AJAX action handler that calls this method.
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- See note above; read-only helper, nonce belongs to calling AJAX handler. Value is sanitized with absint().
			$post_id = absint( wp_unslash( $_POST['post_id'] ) );
		} else {
			$post_id = isset( $post->ID ) ? $post->ID : (int) $id;
		}

		// Prepare default values.
		$defaults = $this->get_config_defaults( $post_id );

		// Return the key specified.
		return isset( $defaults[ $key ] ) ? $defaults[ $key ] : false;
	}

	/**
	 * Retrieves the slider config defaults.
	 *
	 * @since 1.0.0
	 *
	 * @param int $post_id The current post ID.
	 * @return array       Array of slider config defaults.
	 */
	public function get_config_defaults( $post_id ) {

		// Prepare default values.
		$defaults = [
			// Images Tab.
			'type'                       => 'default',

			// Config Tab.
			'columns'                    => '0',
			'justified_row_height'       => 150, // automatic/justified layout.
			'justified_gallery_theme'    => 'normal',
			'justified_margins'          => 1,
			'gallery_theme'              => 'base',
			'display_description'        => 0,
			'description'                => '',
			'gutter'                     => 10,
			'margin'                     => 10,
			'random'                     => 0,
			'sorting_direction'          => 'ASC',
			'image_size'                 => 'default', // Default = uses the below crop_width and crop_height.
			'image_sizes_random'         => [],
			'crop_width'                 => 640,
			'crop_height'                => 480,
			'crop'                       => 0,
			'dimensions'                 => 0,
			'isotope'                    => 1,
			'css_animations'             => 1,
			'css_opacity'                => 100,
			'lazy_loading'               => 1, // lazy loading 'ON' for new galleries.
			'lazy_loading_delay'         => 500,

			// Lightbox.
			'lightbox_enabled'           => 1,
			'lightbox_theme'             => 'base',
			'lightbox_image_size'        => 'default',
			'title_display'              => 'float',
			'arrows'                     => 1,
			'arrows_position'            => 'inside',
			'keyboard'                   => 1,
			'mousewheel'                 => 1,
			'toolbar'                    => 1,
			'toolbar_title'              => 0,
			'toolbar_position'           => 'top',
			'aspect'                     => 1,
			'loop'                       => 1,
			'lightbox_open_close_effect' => 'fade',
			'effect'                     => 'fade',
			'html5'                      => 0,

			// Thumbnails.
			'thumbnails'                 => 1,
			'thumbnails_width'           => 75,
			'thumbnails_height'          => 50,
			'thumbnails_position'        => 'bottom',

			// Mobile.
			'mobile'                     => 1,
			'mobile_width'               => 320,
			'mobile_height'              => 240,
			'mobile_lightbox'            => 1,
			'mobile_touchwipe'           => 1,
			'mobile_touchwipe_close'     => 0,
			'mobile_arrows'              => 1,
			'mobile_toolbar'             => 1,
			'mobile_thumbnails'          => 1,
			'mobile_thumbnails_width'    => 75,
			'mobile_thumbnails_height'   => 50,

			// Misc.
			'title'                      => '',
			'slug'                       => '',
			'classes'                    => [],
			'rtl'                        => 0,
		];

		// For Lite, change some defaults.
		$defaults['toolbar']           = 0;
		$defaults['thumbnails']        = 0;
		$defaults['mobile_touchwipe']  = 0;
		$defaults['mobile_toolbar']    = 0;
		$defaults['mobile_thumbnails'] = 0;

		// Allow devs to filter the defaults.
		$defaults = apply_filters( 'envira_gallery_defaults', $defaults, $post_id );

		return $defaults;
	}

	/**
	 * Returns an array of supported file type groups and file types
	 *
	 * @since 1.3.3.2
	 *
	 * @return array Supported File Types
	 */
	public function get_supported_filetypes() {

		$supported_file_types = [
			[
				'title'      => __( 'Image Files', 'envira-gallery-lite' ),
				'extensions' => 'jpg,jpeg,jpe,gif,png,bmp,tif,tiff,JPG,JPEG,JPE,GIF,PNG,BMP,TIF,TIFF,webp,WEBP',
			],
		];

		// Allow Developers and Addons to filter the supported file types.
		$supported_file_types = apply_filters( 'envira_gallery_supported_file_types', $supported_file_types );

		return $supported_file_types;
	}

	/**
	 * Returns an array of support file types in full MIME format
	 *
	 * @since 1.4.0.2
	 *
	 * @return array Supported File Types
	 */
	public function get_supported_filetypes_mimes() {

		$supported_file_types = [
			'image/jpg',
			'image/jpeg',
			'image/jpe',
			'image/gif',
			'image/png',
			'image/bmp',
			'image/tif',
			'image/tiff',
			'image/webp',
		];

		// Allow Developers and Addons to filter the supported file types.
		$supported_file_types = apply_filters( 'envira_gallery_supported_file_types_mimes', $supported_file_types );

		return $supported_file_types;
	}

	/**
	 * Returns an array of positions for new images to be added to in an existing Gallery
	 *
	 * @since 1.3.3.6
	 *
	 * @return array
	 */
	public function get_media_positions() {

		$positions = [
			[
				'value' => 'before',
				'name'  => __( 'Before Existing Images', 'envira-gallery-lite' ),
			],
			[
				'value' => 'after',
				'name'  => __( 'After Existing Images', 'envira-gallery-lite' ),
			],
		];

		return apply_filters( 'envira_gallery_media_positions', $positions );
	}

	/**
	 * Returns an array of media deletion options, when an Envira Gallery is deleted
	 *
	 * @since 1.3.5.1
	 *
	 * @return array
	 */
	public function get_media_delete_options() {

		$options = [
			[
				'value' => '',
				'name'  => __( 'No', 'envira-gallery-lite' ),
			],
			[
				'value' => '1',
				'name'  => __( 'Yes', 'envira-gallery-lite' ),
			],
		];

		return apply_filters( 'envira_gallery_media_delete_options', $options );
	}

	/**
	 * API method for cropping images.
	 *
	 * @since 1.0.0
	 *
	 * @global object $wpdb The $wpdb database object.
	 *
	 * @param string $url      The URL of the image to resize.
	 * @param int    $width       The width for cropping the image.
	 * @param int    $height      The height for cropping the image.
	 * @param bool   $crop       Whether or not to crop the image (default yes).
	 * @param string $align    The crop position alignment.
	 * @param int    $quality    Image Quality level.
	 * @param bool   $retina     Whether or not to make a retina copy of image.
	 * @param array  $data      Array of gallery data (optional).
	 * @param bool   $force_overwrite      Forces an overwrite even if the thumbnail already exists (useful for applying watermarks).
	 *
	 * @return WP_Error|string Return WP_Error on error, URL of resized image on success.
	 */
	public function resize_image( $url, $width = null, $height = null, $crop = true, $align = 'c', $quality = 100, $retina = false, $data = [], $force_overwrite = false ) {

		global $wpdb;

		// Get common vars.
		$args = [ $url, $width, $height, $crop, $align, $quality, $retina, $data ];

		// Filter args.
		$args = apply_filters( 'envira_gallery_resize_image_args', $args );

		// Coerce the dimensions before any arithmetic. These come from gallery config,
		// and the REST writer used to merge crop_width/crop_height without sanitizing,
		// so a stored non-numeric value reached "$width * $retina" in get_image_info()
		// and raised an uncaught TypeError on PHP 8 - a fatal on the front end, not a
		// bad thumbnail. Sanitizing the writer stops new poisoned values; this stops the
		// ones already stored. absint() is a no-op for every legitimate value. Note it
		// does not necessarily end in the no-size WP_Error: get_image_info() tests
		// "$width && ! $height" before the both-empty bail, so ONE poisoned axis is
		// recomputed from the original's aspect ratio and the resize succeeds at a
		// size nobody asked for. That is the aspect-preserving behaviour the function
		// already had for a caller passing a single dimension; only a poisoned pair
		// reaches the error.
		//
		// Coerced on $args, AFTER the filter, because $args is what get_image_info()
		// unpacks. Coercing the function parameters and writing those back would
		// overwrite whatever envira_gallery_resize_image_args set, silently discarding
		// the only dimension effect that filter has. The local $width/$height are left
		// alone because nothing reads them again on this path - get_image_info() does
		// the dimension arithmetic on $args, and the keys extract( $common ) brings
		// into scope below are dir/name/ext/orig_*/dest_*/file_path, not width/height.
		if ( isset( $args[1] ) ) {
			$args[1] = ( null === $args[1] ) ? null : absint( $args[1] );
		}

		if ( isset( $args[2] ) ) {
			$args[2] = ( null === $args[2] ) ? null : absint( $args[2] );
		}

		// Don't resize images that don't belong to this site's URL.
		// Strip ?lang=fr from blog's URL - WPML adds this on.
		// and means our next statement fails.
		if ( is_multisite() ) {
			$site_url = preg_replace( '/\?.*/', '', network_site_url() );
		} else {
			$site_url = preg_replace( '/\?.*/', '', get_bloginfo( 'url' ) );
		}

		// WPML check - if there is a /fr or any domain in the url, then remove that from the $site_url.
		if ( defined( 'ICL_LANGUAGE_CODE' ) ) {
			if ( strpos( $site_url, '/' . ICL_LANGUAGE_CODE ) !== false ) {
				$site_url = str_replace( '/' . ICL_LANGUAGE_CODE, '', $site_url );
			}
		}

		if ( strpos( $url, $site_url ) === false ) {
			return $url;
		}

		// Get image info.
		$common = $this->get_image_info( $args );

		// Unpack variables if an array, otherwise return WP_Error.
		if ( is_wp_error( $common ) ) {
			return $common;
		} else {
			extract( $common );
		}

		// If the destination width/height values are the same as the original, don't do anything.
		if ( ! $force_overwrite && $orig_width === $dest_width && $orig_height === $dest_height ) {
			return $url;
		}

		// If the file doesn't exist yet, we need to create it.
		if ( ! file_exists( $dest_file_name ) || ( file_exists( $dest_file_name ) && $force_overwrite ) ) {
			// We only want to resize Media Library images, so we can be sure they get deleted correctly when appropriate.
			$get_attachment = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $wpdb->posts WHERE guid=%s", $url ) ); // @codingStandardsIgnoreLine

			// Load the WordPress image editor.
			$editor = wp_get_image_editor( $file_path );

			// If an editor cannot be found, the user needs to have GD or Imagick installed.
			if ( is_wp_error( $editor ) ) {
				return new WP_Error( 'envira-gallery-error-no-editor', __( 'No image editor could be selected. Please verify with your webhost that you have either the GD or Imagick image library compiled with your PHP install on your server.', 'envira-gallery-lite' ) );
			}

			// Set the image editor quality.
			$editor->set_quality( $quality );

			// If cropping, process cropping.
			if ( $crop ) {
				$src_x = 0;
				$src_y = 0;
				$src_w = $orig_width;
				$src_h = $orig_height;

				$cmp_x = $orig_width / $dest_width;
				$cmp_y = $orig_height / $dest_height;

				// Calculate x or y coordinate and width or height of source.
				if ( $cmp_x > $cmp_y ) {
					$src_w = round( $orig_width / $cmp_x * $cmp_y );
					$src_x = round( ( $orig_width - ( $orig_width / $cmp_x * $cmp_y ) ) / 2 );
				} elseif ( $cmp_y > $cmp_x ) {
					$src_h = round( $orig_height / $cmp_y * $cmp_x );
					$src_y = round( ( $orig_height - ( $orig_height / $cmp_y * $cmp_x ) ) / 2 );
				}

				// Positional cropping.
				if ( $align && 'c' !== $align ) {
					if ( strpos( $align, 't' ) !== false || strpos( $align, 'tr' ) !== false || strpos( $align, 'tl' ) !== false ) {
						$src_y = 0;
					}

					if ( strpos( $align, 'b' ) !== false || strpos( $align, 'br' ) !== false || strpos( $align, 'bl' ) !== false ) {
						$src_y = $orig_height - $src_h;
					}

					if ( strpos( $align, 'l' ) !== false ) {
						$src_x = 0;
					}

					if ( strpos( $align, 'r' ) !== false ) {
						$src_x = $orig_width - $src_w;
					}
				}

				// Crop the image.
				$editor->crop( $src_x, $src_y, $src_w, $src_h, $dest_width, $dest_height );
			} else {
				// Just resize the image.
				$editor->resize( $dest_width, $dest_height );
			}

			// Save the image.
			$saved = $editor->save( $dest_file_name );

			// Print possible out of memory errors.
			if ( is_wp_error( $saved ) ) {
				wp_delete_file( $dest_file_name );
				return $saved;
			}

			// Add the resized dimensions and alignment to original image metadata, so the images
			// can be deleted when the original image is delete from the Media Library.
			if ( $get_attachment ) {
				$metadata = wp_get_attachment_metadata( $get_attachment[0]->ID );

				if ( isset( $metadata['image_meta'] ) ) {
					$md = $saved['width'] . 'x' . $saved['height'];

					if ( $crop ) {
						$md .= $align ? "_$align" : '_c';
					}

					$metadata['image_meta']['resized_images'][] = $md;
					wp_update_attachment_metadata( $get_attachment[0]->ID, $metadata );
				}
			}

			// Set the resized image URL.
			$resized_url = str_replace( basename( $url ), basename( $saved['path'] ), $url );
		} else {
			// Set the resized image URL.
			$resized_url = str_replace( basename( $url ), basename( $dest_file_name ), $url );
		}

		// Return the resized image URL.
		return apply_filters( 'envira_gallery_resize_image_resized_url', $resized_url );
	}

	/**
	 * Write a diagnostic at most once per key per day, using one aggregate transient.
	 *
	 * Keyed-per-attachment transients were two wp_options rows each - the value plus
	 * its timeout - and the callers walk every image of every gallery, so on a site
	 * whose media library lost its stored sizes the table grew by two rows per
	 * affected image, then again every 24 hours, with nothing cleaning them up
	 * (flush_gallery_caches() only clears _eg_cache_*). nextgen-gallery#476 came off
	 * a report of ~1.5M such rows. One bucket holds the whole set instead, so the
	 * cost is two rows per bucket no matter how large the library is.
	 *
	 * The map is capped, and when it fills the oldest half is evicted so recording
	 * continues. Logging a key without recording it would drop the 24h bound for
	 * every key past the cap - on the bulk-migration population these callers exist
	 * for, more than 200 affected attachments in one bucket is the expected case,
	 * not an edge one - leaving only the per-request budgets to hold back an
	 * anonymously-reachable path. Evicting keeps both bounds in force; the cost is
	 * that a long-tail attachment can be reported again sooner than 24h.
	 *
	 * @since 1.16.0
	 *
	 * @param string $bucket  Short bucket name, e.g. 'missing_dims'.
	 * @param string $key     Identifier for the thing being reported, e.g. an attachment id.
	 * @param string $message The line to write. Callers must sanitize anything interpolated.
	 * @return bool Whether a line was written.
	 */
	public function log_once_per_day( $bucket, $key, $message ) {

		$transient = 'envira_log_' . $bucket;
		$seen      = get_transient( $transient );

		if ( ! is_array( $seen ) ) {
			$seen = [];
		}

		if ( isset( $seen[ $key ] ) ) {
			return false;
		}

		if ( count( $seen ) >= 200 ) {
			$seen = array_slice( $seen, 100, null, true );
		}

		$seen[ $key ] = 1;

		set_transient( $transient, $seen, DAY_IN_SECONDS );

		error_log( $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log

		return true;
	}

	/**
	 * The resize_image() error codes that are routine rather than faults.
	 *
	 * These two mean "this image cannot be cropped here, and that is expected": the
	 * source lives on stream-wrapped/remote storage, or the file is absent. On an
	 * offloaded site they are true of every image on every render, so callers serve
	 * the un-cropped source and stay quiet. Any code NOT in this list is a real
	 * fault - a lost image editor, unusable crop dimensions, a failed save, a path
	 * that resolved outside uploads - and every caller logs those.
	 *
	 * 'envira-gallery-error-invalid-path' is deliberately NOT here. By the time it
	 * can be returned, the stream-wrapper and unresolvable-basedir bails have
	 * already claimed the offload cases, and file_exists() has not yet run - so it
	 * means a file that exists locally resolved outside the uploads directory. That
	 * is either the traversal-shaped src the containment check exists to stop or a
	 * real misconfiguration, and a security control tripping should not be silent.
	 * It stays in get_undumped_resize_error_codes() so it is logged, never printed.
	 *
	 * One accessor rather than a copy per caller. Three hand-maintained copies of
	 * this list, two of them suppression lists and one an inverted expected list,
	 * drifted twice on consecutive changes: a code added to one file and not the
	 * others silently flipped a fault between "logged", "dumped into the page" and
	 * "swallowed". Add a code here and every caller agrees by construction.
	 *
	 * @since 1.16.0
	 *
	 * @return array Error codes that need no diagnostic.
	 */
	public function get_routine_resize_error_codes() {

		return [
			'envira-gallery-error-remote-file',
			'envira-gallery-error-no-file',
		];
	}

	/**
	 * Resize error codes that must never be printed into a page or an admin screen.
	 *
	 * The routine codes above, plus the two faults that must be logged but never
	 * shown: an unresolvable uploads directory, and a path that resolved outside
	 * uploads. Both are real problems, and both are true of many images at once, so
	 * dumping them inline would print a block per image (twice per item on the
	 * front end, where src and retina both resize) rather than telling anyone
	 * something new. Their signal belongs in the log.
	 *
	 * @since 1.16.0
	 *
	 * @return array Error codes that must not produce on-screen output.
	 */
	public function get_undumped_resize_error_codes() {

		return array_merge(
			$this->get_routine_resize_error_codes(),
			[
				'envira-gallery-error-invalid-path',
				'envira-gallery-error-uploads-unresolved',
			]
		);
	}

	/**
	 * Helper method to return common information about an image.
	 *
	 * @since 1.0.0
	 *
	 * @param array $args      List of resizing args to expand for gathering info.
	 * @return WP_Error|string Return WP_Error on error, array of data on success.
	 */
	public function get_image_info( $args ) {

		// Unpack arguments.
		list( $url, $width, $height, $crop, $align, $quality, $retina, $data ) = $args;

		// Return an error if no URL is present.
		if ( empty( $url ) ) {
			return new WP_Error( 'envira-gallery-error-no-url', __( 'No image URL specified for cropping.', 'envira-gallery-lite' ) );
		}

		// Get the image file path.
		$urlinfo       = wp_parse_url( $url );
		$wp_upload_dir = wp_upload_dir();

		// Interpret the file path of the image.
		if ( preg_match( '/\/[0-9]{4}\/[0-9]{2}\/.+$/', $urlinfo['path'], $matches ) ) {
			$file_path = $wp_upload_dir['basedir'] . $matches[0];
		} else {
			$pathinfo    = wp_parse_url( $url );
			$content_dir = defined( 'WP_CONTENT_DIR' ) ? WP_CONTENT_DIR : '/wp-content/';
			$uploads_dir = is_multisite() ? '/files/' : '/' . str_replace( ABSPATH, '', WP_CONTENT_DIR ) . '/';
			if ( is_multisite() ) {
				$uploads_dir = '/files/';
			} elseif ( defined( 'UPLOADS' ) ) {
				$uploads_dir = '/' . UPLOADS . '/';
			} else {
				$uploads_dir = '/' . str_replace( ABSPATH, '', WP_CONTENT_DIR ) . '/';
			}
			$file_path = isset( $_SERVER['SCRIPT_NAME'] ) ? ABSPATH . str_replace( dirname( sanitize_text_field( wp_unslash( $_SERVER['SCRIPT_NAME'] ) ) ) . '/', '', strstr( $pathinfo['path'], $uploads_dir ) ) : false;
			$file_path = preg_replace( '/(\/\/)/', '/', $file_path );

		}

		// Never run file_exists()/getimagesize() against a stream-wrapped path (s3://,
		// iu://, etc.) - a slow or unhealthy remote blocks the request until
		// max_execution_time is hit, and this runs once per gallery image, so a
		// gallery-heavy block-editor request dies with nothing logged. Bail before any
		// stat instead; callers fall back to the un-cropped source. Checked before the
		// "//" collapse above would matter, since collapsing "scheme://" into "scheme:/"
		// hides the wrapper. See envira-gallery-plugin#5544.
		if ( $file_path && preg_match( '#^[a-z][a-z0-9+.\-]*://#i', $file_path ) ) {
			return new WP_Error( 'envira-gallery-error-remote-file', __( 'Cannot crop an image stored on a remote/stream-wrapped filesystem.', 'envira-gallery-lite' ) );
		}

		// Resolve the canonical path and reject anything that escapes the uploads directory.
		// $url is a function argument that ultimately traces back to gallery image data which can be
		// attacker-influenced (e.g. via edit_posts); the regex .+ above captures ../ sequences,
		// allowing traversal to files like wp-config.php that getimagesize() would then read.
		//
		// wp_normalize_path() after realpath() matters on Windows: realpath() returns
		// backslash-separated paths there while trailingslashit() always appends a forward
		// slash, so without normalizing, the strpos() comparison never matches and every
		// image on a Windows-hosted site is rejected as out-of-bounds.
		$real_uploads_base = realpath( $wp_upload_dir['basedir'] );
		$real_uploads_base = $real_uploads_base ? wp_normalize_path( $real_uploads_base ) : $real_uploads_base;
		if ( $file_path ) {
			$real_file_path = realpath( $file_path );
			$real_file_path = $real_file_path ? wp_normalize_path( $real_file_path ) : $real_file_path;
			// Fail CLOSED. Requiring $real_uploads_base to be truthy before rejecting meant
			// the check was skipped outright whenever realpath() could not resolve the
			// uploads dir - which is exactly what happens when basedir is a stream wrapper
			// (iu://, s3://). A crafted gallery src taking the ABSPATH-rooted branch above
			// carries no scheme, so the stream-wrapper bail does not catch it, and it could
			// then be stat'd, read, and have a derivative written beside it outside uploads.
			// If containment cannot be established, refuse rather than proceed.
			//
			// Its own error code, deliberately NOT 'envira-gallery-error-invalid-path':
			// that code means "this src tried to escape uploads", which every caller
			// suppresses because it is routine on offloaded media. An unresolvable
			// basedir is a site-wide fault (open_basedir, a missing or unreadable
			// wp-content/uploads) that un-crops every image everywhere, so it has to
			// stay loud.
			if ( ! $real_uploads_base ) {
				return new WP_Error( 'envira-gallery-error-uploads-unresolved', __( 'The uploads directory could not be resolved, so the image path cannot be verified.', 'envira-gallery-lite' ) );
			}

			if ( $real_file_path && 0 !== strpos( $real_file_path, trailingslashit( $real_uploads_base ) ) ) {
				return new WP_Error( 'envira-gallery-error-invalid-path', __( 'The image path is outside the allowed uploads directory.', 'envira-gallery-lite' ) );
			}
			if ( $real_file_path ) {
				$file_path = $real_file_path;
			}
		}

		// Attempt to stream and import the image if it does not exist based on URL provided.
		if ( ! file_exists( $file_path ) ) {
			return new WP_Error( 'envira-gallery-error-no-file', __( 'No file could be found for the image URL specified.', 'envira-gallery-lite' ) );
		}

		// Get original image size.
		$size = getimagesize( $file_path );

		// If no size data obtained, return an error.
		if ( ! $size ) {
			return new WP_Error( 'envira-gallery-error-no-size', __( 'The dimensions of the original image could not be retrieved for cropping.', 'envira-gallery-lite' ) );
		}

		// Set original width and height.
		list( $orig_width, $orig_height, $orig_type ) = $size;

		// Generate width or height if not provided.
		if ( $width && ! $height ) {
			$height = floor( $orig_height * ( $width / $orig_width ) );
		} elseif ( $height && ! $width ) {
			$width = floor( $orig_width * ( $height / $orig_height ) );
		} elseif ( ! $width && ! $height ) {
			return new WP_Error( 'envira-gallery-error-no-size', __( 'The dimensions of the original image could not be retrieved for cropping.', 'envira-gallery-lite' ) );
		}

		// Allow for different retina image sizes.
		$retina = $retina ? ( true === $retina ? 2 : $retina ) : 1;

		// Destination width and height variables.
		$dest_width  = $width * $retina;
		$dest_height = $height * $retina;

		// Some additional info about the image.
		$info = pathinfo( $file_path );
		$dir  = $info['dirname'];
		$ext  = $info['extension'];
		$name = wp_basename( $file_path, ".$ext" );

		// Suffix applied to filename.
		$suffix = "{$dest_width}x{$dest_height}";

		// Set alignment information on the file.
		if ( $crop ) {

			// Allowlisted, because $suffix goes straight into $dest_file_name below and
			// that path is what $editor->save() writes after core calls
			// wp_mkdir_p( dirname( $filename ) ). $align traces back to the gallery's
			// crop_position, which the REST writer stores verbatim from client JSON, so
			// "../" segments in it reached the destination path. Anything unrecognised
			// falls back to the default centre crop rather than being interpolated.
			$valid_aligns = [ 'c', 't', 'b', 'l', 'r', 'tl', 'tr', 'bl', 'br' ];
			$align        = ( is_string( $align ) && in_array( $align, $valid_aligns, true ) ) ? $align : 'c';

			$suffix .= "_$align";
		}

		// Get the destination file name.
		$dest_file_name = "{$dir}/{$name}-{$suffix}.{$ext}";

		// Return the info.
		$info = [
			'dir'            => $dir,
			'name'           => $name,
			'ext'            => $ext,
			'suffix'         => $suffix,
			'orig_width'     => $orig_width,
			'orig_height'    => $orig_height,
			'orig_type'      => $orig_type,
			'dest_width'     => $dest_width,
			'dest_height'    => $dest_height,
			'file_path'      => $file_path,
			'dest_file_name' => $dest_file_name,
		];

		return $info;
	}

	/**
	 * Helper method to flush gallery caches once a gallery is updated.
	 *
	 * @since 1.0.0
	 *
	 * @param int    $post_id The current post ID.
	 * @param string $slug The unique gallery slug.
	 */
	public function flush_gallery_caches( $post_id, $slug = '' ) {

		// Delete known gallery caches. '_eg_cache_<id>' is the pre-1.16.2 key.
		delete_transient( '_eg_cache_id_' . $post_id );
		delete_transient( '_eg_cache_' . $post_id );
		delete_transient( '_eg_cache_all' );

		// Possibly delete slug gallery cache if available.
		if ( ! empty( $slug ) ) {
			delete_transient( '_eg_cache_slug_' . $slug );
			delete_transient( '_eg_cache_' . $slug );
		}

		// Run a hook for Addons to access.
		do_action( 'envira_gallery_flush_caches', $post_id, $slug );
	}

	/**
	 * Helper method to return the max execution time for scripts.
	 *
	 * @since 1.0.0
	 */
	public function get_max_execution_time() {

		$time = ini_get( 'max_execution_time' );
		return ! $time || empty( $time ) ? (int) 0 : $time;
	}

	/**
	 * Helper method to return the transient expiration time
	 *
	 * @since 1.3.6.4
	 *
	 * @param string $plugin Plugin Slug.
	 *
	 * @return int Expiration Time (in seconds)
	 */
	public function get_transient_expiration_time( $plugin = 'envira-gallery-lite' ) {

		// Define the default.
		$default = DAY_IN_SECONDS;

		// Allow devs to filter this depending on the plugin.
		$default = apply_filters( 'envira_gallery_get_transient_expiration_time', $default, $plugin );

		// Return.
		return $default;
	}

	/**
	 * Returns the singleton instance of the class.
	 *
	 * @since 1.0.0
	 *
	 * @return object The Envira_Gallery_Common object.
	 */
	public static function get_instance() {

		if ( ! isset( self::$instance ) && ! ( self::$instance instanceof Envira_Gallery_Common ) ) {
			self::$instance = new Envira_Gallery_Common();
		}

		return self::$instance;
	}
}

// Load the common class.
$envira_gallery_common = Envira_Gallery_Common::get_instance();
