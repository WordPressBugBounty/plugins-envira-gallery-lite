<?php
/**
 * Envira Gallery Rest Class.
 *
 * @since 1.8.5
 *
 * @package Envira Gallery
 * @author  Envira Gallery Team <support@enviragallery.com>
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Envira Rest Class
 */
class Envira_Rest {

	/**
	 * Singleton Var
	 *
	 * @var object
	 */
	public static $instance = null;

	/**
	 * Base Object
	 *
	 * @var object
	 */
	public $base;

	/**
	 * Envira Common Object
	 *
	 * @var object
	 */
	public $common;

	/**
	 * Images refused by the last gallery_data update in this request.
	 *
	 * @since 1.16.2
	 *
	 * @var int
	 */
	private $gallery_skipped = 0;

	/**
	 * Class Constructor
	 *
	 * @since 1.8.5
	 */
	public function __construct() {

		// Load the base class object.
		$this->base   = Envira_Gallery_Lite::get_instance();
		$this->common = Envira_Gallery_Common::get_instance();

		add_action( 'rest_api_init', [ $this, 'register_post_meta' ] );
		add_filter( 'rest_pre_insert_envira', [ $this, 'reject_refused_gallery_items' ], 10, 2 );
		add_filter( 'rest_prepare_envira', [ $this, 'add_gallery_skipped' ], 10, 1 );
	}

	/**
	 * Reports how many images the gallery_data update refused, so the block can say so.
	 *
	 * Core runs field update callbacks before preparing the response, so the count is
	 * already set when this filter runs. Only added when something was refused.
	 *
	 * @since 1.16.2
	 *
	 * @param WP_REST_Response $response Response object.
	 * @return WP_REST_Response
	 */
	public function add_gallery_skipped( $response ) {
		if ( $this->gallery_skipped > 0 && $response instanceof WP_REST_Response ) {
			$data                    = $response->get_data();
			$data['gallery_skipped'] = $this->gallery_skipped;
			$response->set_data( $data );
		}

		// Consumed: a later item in the same request (e.g. /batch/v1) starts from zero.
		$this->gallery_skipped = 0;

		return $response;
	}

	/**
	 * Refuses a gallery create/update whose images are all ones the user may not add.
	 *
	 * The gallery_data field callback runs after the post is saved, so refusing there
	 * would still leave a new, empty, published gallery behind on create. Checking here
	 * stops the request before anything is written.
	 *
	 * @since 1.16.2
	 *
	 * @param stdClass|WP_Error $prepared_post Post about to be inserted.
	 * @param WP_REST_Request   $request       Request object.
	 * @return stdClass|WP_Error
	 */
	public function reject_refused_gallery_items( $prepared_post, $request ) {

		if ( is_wp_error( $prepared_post ) ) {
			return $prepared_post;
		}

		$value = $request->get_param( 'gallery_data' );

		if ( ! is_array( $value ) || empty( $value['gallery'] ) || ! is_array( $value['gallery'] ) ) {
			return $prepared_post;
		}

		$gallery_id = isset( $prepared_post->ID ) ? (int) $prepared_post->ID : 0;

		foreach ( $value['gallery'] as $image ) {
			if ( is_array( $image ) && isset( $image['id'] ) && is_scalar( $image['id'] ) && envira_gallery_user_can_insert_attachment( $image['id'], $gallery_id ) ) {
				return $prepared_post;
			}
		}

		return new WP_Error( 'rest_forbidden', esc_html__( 'You are not allowed to add the selected images to this gallery.', 'envira-gallery-lite' ), [ 'status' => 403 ] );
	}

	/**
	 * Helper Method to register Envira gallery Meta
	 *
	 * @since 1.8.5
	 *
	 * @return void
	 */
	public function register_post_meta() {

		register_rest_field(
			'envira',
			'gallery_data',
			[
				'get_callback'    => [ $this, 'get_gallery_data' ],
				'update_callback' => [ $this, 'update_gallery_data' ],
			]
		);
	}

	/**
	 * Rest API callback to get gallery data.
	 *
	 * @param [type] $object_data Post Object.
	 * @param [type] $field_name Rest Field Name.
	 * @param [type] $request Rest Request.
	 * @return array
	 */
	public function get_gallery_data( $object_data, $field_name, $request ) {

		$data = get_post_meta( $object_data['id'], '_eg_gallery_data', true );

		if ( ! is_array( $data ) ) {
			$data = [];
		}

		// A password-protected gallery keeps its images behind the password, as core does for
		// post content: editors, or a request carrying the right ?password=. Config stays, so
		// the block editor's reads of gallery_data.config keep working.
		$post     = get_post( $object_data['id'] );
		$password = $request instanceof WP_REST_Request ? (string) $request->get_param( 'password' ) : '';

		if ( $post && '' !== $post->post_password && ! current_user_can( 'edit_post', $post->ID ) && ! hash_equals( $post->post_password, $password ) ) {
			$data['gallery'] = [];
		}

		$i      = 0;
		$images = [];
		$url    = false;

		if ( isset( $data['gallery'] ) && is_array( $data['gallery'] ) ) {
			foreach ( $data['gallery'] as $id => $item ) {

				// Skip over images that are pending (ignore if in Preview mode).
				if ( isset( $item['status'] ) && 'pending' === $item['status'] && ! is_preview() ) {
					continue;
				}

				$width  = null;
				$height = null;

				// Set when the largest-cached-size rescue is what answered, so the log
				// below still names the attachment: the rescue produces positive numbers
				// that would otherwise falsify its "! $width || ! $height" gate.
				$rescued_size_name = null;
				$imagesrc          = $this->get_image_src( $id, $item, $data, false, false );

				// Prefer dimensions WordPress (or resize_image()) has already recorded, so
				// this loop never has to touch the filesystem. Which record is authoritative
				// depends on what get_image_src() actually returned for this gallery's
				// settings - it hands back a registered size for some configurations and a
				// freshly cropped derivative for others, and those disagree.
				$attachment_meta = wp_get_attachment_metadata( $id );
				$image_size      = $this->get_config( 'image_size', $data );
				$crop_enabled    = (bool) $this->get_config( 'crop', $data );

				if ( 'default' === $image_size && $crop_enabled ) {

					// get_image_src() routed through Envira_Gallery_Common::resize_image(),
					// which writes its dimensions into the filename as "-WxH" (plus a "_c" or
					// "_$align" marker). Trust that suffix over the configured crop size: when
					// the resize fails, get_image_src() now falls back to the un-cropped
					// source, which carries no crop suffix - so width/height correctly stay
					// null and cascade to the fallbacks below rather than advertising a crop
					// that never happened.
					$suffix_dims = $this->get_dimensions_from_filename( $imagesrc, $attachment_meta );

					if ( $suffix_dims ) {
						list( $width, $height ) = $suffix_dims;
					}
				} elseif ( 'default' === $image_size && ! $crop_enabled ) {

					$closest_size = $this->find_clostest_size( $data );
					$isize        = ( is_string( $closest_size ) && '' !== $closest_size ) ? $closest_size : 'full';

					if ( isset( $attachment_meta['sizes'][ $isize ] ) && is_array( $attachment_meta['sizes'][ $isize ] ) ) {

						$size_dims = $this->usable_dimensions(
							isset( $attachment_meta['sizes'][ $isize ]['width'] ) ? $attachment_meta['sizes'][ $isize ]['width'] : null,
							isset( $attachment_meta['sizes'][ $isize ]['height'] ) ? $attachment_meta['sizes'][ $isize ]['height'] : null
						);

						if ( $size_dims ) {
							list( $width, $height ) = $size_dims;
						}
					}
				} elseif ( is_string( $image_size ) && ! in_array( $image_size, [ 'full', 'envira_gallery_random' ], true )
					&& isset( $attachment_meta['sizes'][ $image_size ] ) && is_array( $attachment_meta['sizes'][ $image_size ] ) ) {

					// A specific registered WordPress size was requested - use that size's own
					// metadata, since it rarely shares the original's aspect ratio.
					$size_dims = $this->usable_dimensions(
						isset( $attachment_meta['sizes'][ $image_size ]['width'] ) ? $attachment_meta['sizes'][ $image_size ]['width'] : null,
						isset( $attachment_meta['sizes'][ $image_size ]['height'] ) ? $attachment_meta['sizes'][ $image_size ]['height'] : null
					);

					if ( $size_dims ) {
						list( $width, $height ) = $size_dims;
					}
				}

				if ( ! $width || ! $height ) {

					// Try the filename suffix first. This is what catches
					// 'envira_gallery_random', where get_image_src() picks a registered size
					// at random and the chosen size is no longer knowable from the config.
					$suffix_dims = $this->get_dimensions_from_filename( $imagesrc, $attachment_meta );

					if ( $suffix_dims ) {

						list( $width, $height ) = $suffix_dims;

					} elseif ( is_array( $attachment_meta ) && $this->usable_dimensions(
						isset( $attachment_meta['width'] ) ? $attachment_meta['width'] : null,
						isset( $attachment_meta['height'] ) ? $attachment_meta['height'] : null
					) ) {

						// Top-level metadata describes whatever file WordPress considers
						// "full". Unlike Pro - whose envira_get_image_src() returns the
						// pre-scale original via wp_get_original_image_url() and so needs an
						// 'original_image' guard - Lite asks for wp_get_attachment_image_src(
						// $id, 'full' ), which returns the "-scaled" derivative those same
						// keys describe. No guard needed here.
						list( $width, $height ) = $this->usable_dimensions( $attachment_meta['width'], $attachment_meta['height'] );

					} else {

						// Get the image file path.
						$urlinfo       = wp_parse_url( $imagesrc );
						$wp_upload_dir = wp_upload_dir();

						// Interpret the file path of the image.
						if ( preg_match( '/\/[0-9]{4}\/[0-9]{2}\/.+$/', $urlinfo['path'], $matches ) ) {

							$file_path = $wp_upload_dir['basedir'] . $matches[0];

						} else {

							$content_dir = defined( 'WP_CONTENT_DIR' ) ? WP_CONTENT_DIR : '/wp-content/';
							$uploads_dir = is_multisite() ? '/files/' : $content_dir;
							$file_path   = trailingslashit( $wp_upload_dir['basedir'] ) . basename( $urlinfo['path'] );

						}

						// Never stat or read a stream-wrapped path (s3://, iu://, ...) - each call
						// is a cloud round-trip, and this loop runs for every image of every
						// gallery the block editor preloads, so a slow remote runs the request
						// past max_execution_time and the editor dies with nothing logged.
						// Computed before the "//" collapse below, which would otherwise turn
						// "scheme://" into "scheme:/" and hide the wrapper from this check.
						$is_remote = (bool) preg_match( '#^[a-z][a-z0-9+.\-]*://#i', $file_path );

						if ( $is_remote ) {

							// Can't safely measure a remote path. Ask WordPress for the size it
							// considers "full" rather than leaving width/height null, which
							// publishes as 0x0 and breaks the editor's aspect-ratio maths.
							$fallback_src = wp_get_attachment_image_src( $id, 'full' );

							// Test for usable values, not merely present ones, through the same
							// helper as every other cached read. When top-level metadata is
							// missing, image_downsize() finds no 'full' entry in $meta['sizes']
							// (WordPress only writes that for PDF previews), its own rescue
							// branch reads the same absent keys, and it returns
							// [ $url, 0, 0, false ]. An isset() check passes on that and
							// publishes 0x0 - and would leave the log below unreachable for
							// precisely the case it documents. image_downsize() reads the same
							// untrusted metadata, so a corrupt record reaches here too, and
							// truthy junk would skip the terminal fallback and the log exactly
							// as a zero pair used to.
							if ( is_array( $fallback_src ) ) {

								$fallback_dims = $this->usable_dimensions(
									isset( $fallback_src[1] ) ? $fallback_src[1] : null,
									isset( $fallback_src[2] ) ? $fallback_src[2] : null
								);

								if ( $fallback_dims ) {
									list( $width, $height ) = $fallback_dims;
								}
							}
						} else {

							$file_path = preg_replace( '/(\/\/)/', '/', $file_path );

							// Keep path resolution inside the uploads directory so a crafted
							// gallery src (e.g. carrying "../" segments) can't stat or read
							// arbitrary files. realpath() collapses the "../" before the
							// comparison, and the trailing slash stops a sibling directory
							// such as "uploads-old" from passing the prefix test.
							//
							// Normalize both sides: realpath() returns backslashes on Windows
							// while trailingslashit() appends a forward slash, so an
							// un-normalized comparison never matches there - every local image
							// would silently fall through to 0x0.
							$real_path = realpath( $file_path );
							$real_base = realpath( $wp_upload_dir['basedir'] );
							$real_path = $real_path ? wp_normalize_path( $real_path ) : $real_path;
							$real_base = $real_base ? wp_normalize_path( $real_base ) : $real_base;

							if ( $real_path && $real_base && 0 === strpos( $real_path, trailingslashit( $real_base ) ) && is_file( $real_path ) ) {

								$imagesize = getimagesize( $real_path );

								if ( is_array( $imagesize ) ) {
									list( $width, $height ) = $imagesize;
								}
							}
						}

						// Everything above can still leave width/height null, and not only on
						// the remote branch: realpath() failing, containment rejecting the
						// path, or getimagesize() returning false all land here on a LOCAL
						// path too. That matters most on WP Offload Media, which leaves
						// basedir local while deleting the files, so $is_remote is false and
						// the rescue that used to live inside it never ran.
						if ( ! $width || ! $height ) {

							// Take the largest cached intermediate size rather than publish
							// 0x0, which downstream layout code divides by (see #5588).
							// Partial metadata - top-level width/height absent but 'sizes'
							// populated - is common after a bulk offload migration.
							$largest = 0;

							if ( ! empty( $attachment_meta['sizes'] ) && is_array( $attachment_meta['sizes'] ) ) {
								foreach ( $attachment_meta['sizes'] as $size_name => $size_meta ) {

									if ( ! is_array( $size_meta ) ) {
										continue;
									}

									// Validate before comparing, not after: a corrupt record
									// holding the string "Array" satisfies both ! empty() and
									// > $largest under PHP 8 string comparison, so it would win
									// the largest-size contest and then publish as 0.
									$size_dims = $this->usable_dimensions(
										isset( $size_meta['width'] ) ? $size_meta['width'] : null,
										isset( $size_meta['height'] ) ? $size_meta['height'] : null
									);

									if ( $size_dims && $size_dims[0] > $largest ) {
										$largest           = $size_dims[0];
										$width             = $size_dims[0];
										$height            = $size_dims[1];
										$rescued_size_name = is_string( $size_name ) ? $size_name : null;
									}
								}
							}

							// Point src at the file these dimensions actually describe.
							// The rescue reads an intermediate size while $imagesrc still
							// held whatever get_image_src() returned, so where the largest
							// cached size is a hard crop - a theme or WooCommerce square -
							// the published ratio belonged to a different file and the
							// gallery laid out at the wrong shape.
							//
							// Ask WordPress for that size's URL rather than string-building
							// it from dirname( $imagesrc ). Offload plugins (WP Offload
							// Media, Infinite Uploads), CDN rewriters, WPML and multisite
							// /files/ handling all hook the attachment-URL accessors, and a
							// hand-built sibling URL bypasses every one of them - on exactly
							// the offloaded sites this rescue exists for. If WordPress
							// cannot answer, keep the src we already had rather than
							// publishing a guess.
							if ( $rescued_size_name ) {
								$rescued_src = wp_get_attachment_image_src( $id, $rescued_size_name );

								if ( is_array( $rescued_src ) && ! empty( $rescued_src[0] ) && is_string( $rescued_src[0] ) ) {
									$imagesrc = $rescued_src[0];
								} else {
									// Dimensions without a matching file would publish the
									// wrong ratio again, so drop them and let the terminal
									// fallback answer.
									$width  = null;
									$height = null;
								}
							}
						}
					}
				}

				// Terminal fallback: never publish 0x0. The cascade above can still leave
				// these null - cached dimensions stored as literal 0 or corrupt, realpath()
				// failing, containment rejecting the path, or getimagesize() returning
				// false - and intval( null ) publishes 0x0, which downstream layout
				// divides by.
				if ( ! $width || ! $height ) {

					$last_resort = wp_get_attachment_image_src( $id, 'full' );

					if ( is_array( $last_resort ) ) {
						$last_resort_dims = $this->usable_dimensions(
							isset( $last_resort[1] ) ? $last_resort[1] : null,
							isset( $last_resort[2] ) ? $last_resort[2] : null
						);

						if ( $last_resort_dims ) {
							list( $width, $height ) = $last_resort_dims;
						}
					}
				}

				// This image is about to publish as 0x0 and the attachment needs its
				// metadata regenerated. Record it once.
				//
				// Placed AFTER the terminal fallback, not inside the branch that measures:
				// nested in there it fired on attachments the fallback went on to rescue
				// correctly, and each false alarm burned that attachment's 24h transient -
				// so a later, genuine 0x0 on the same image was silent, which is the one
				// case the log exists for. It also could not see a 0x0 that arrived by a
				// path skipping the measurement at all.
				//
				// Deliberately NOT gated on WP_DEBUG, matching Pro: the affected sites are
				// production sites on offloaded media, where a 0x0 image is invisible
				// precisely because nothing is logged.
				//
				// Three bounds, because this path is anonymously reachable - core's
				// check_read_permission() returns true for any published post. The
				// transient bounds it across requests; the static array bounds it within
				// one; and the request budget caps the total, so a transient store that
				// silently drops writes (an object-cache drop-in with a dead backend)
				// degrades to a handful of lines rather than one per image, and cannot turn
				// a 300-image gallery into 300 transient writes plus 300 log lines on the
				// very loop #5544 exists to make cheap.
				//
				// Known bound, accepted: with a working transient store coverage advances
				// across requests, because the budget is spent only when a line is written
				// and an already-reported attachment short-circuits on the transient without
				// spending it. With a store that drops writes, the same first ten
				// attachments spend the budget every request and later ones are never named
				// individually. The failure class is still reported; capping the volume on
				// an anonymously-reachable path is the point.
				static $logged_missing_dims = [];
				static $missing_dims_budget = 0;
				static $logged_rescued_dims = [];
				static $rescued_dims_budget = 0;

				// Two buckets, two budgets. A successful rescue is worth reporting - the
				// attachment still needs its metadata regenerated - but it is not the same
				// event as publishing 0x0, and sharing the key meant a benign rescue burned
				// that attachment's 24h key, so a later genuine 0x0 on the same image went
				// unreported: the failure an earlier round relocated this whole block to
				// eliminate. Sharing the budget also let routine rescue chatter starve the
				// fault line.
				if ( $rescued_size_name && $width && $height ) {

					if ( $rescued_dims_budget < 10 && ! isset( $logged_rescued_dims[ $id ] ) ) {

						$logged_rescued_dims[ $id ] = true;
						++$rescued_dims_budget;

						$this->common->log_once_per_day(
							'rescued_dims',
							(string) (int) $id,
							sprintf( 'Envira Gallery Lite: attachment %d has no usable top-level dimensions; published the "%s" size instead. Regenerate its metadata.', (int) $id, preg_replace( '/[^A-Za-z0-9_.\-]/', '', $rescued_size_name ) )
						);
					}
				} elseif ( ( ! $width || ! $height ) && $missing_dims_budget < 10 && ! isset( $logged_missing_dims[ $id ] ) ) {

					$logged_missing_dims[ $id ] = true;
					++$missing_dims_budget;

					// Only the attachment ID is interpolated: $imagesrc traces back to
					// _eg_gallery_data, which an edit_posts-capable user controls, and
					// newlines in it would forge log entries.
					$this->common->log_once_per_day(
						'missing_dims',
						(string) (int) $id,
						sprintf( 'Envira Gallery Lite: no cached dimensions for attachment %d; regenerate its metadata. Further reports for this attachment are suppressed for 24 hours.', (int) $id )
					);
				}

				$item['src']    = $imagesrc;
				$item['id']     = $id;
				$item['height'] = intval( $height );
				$item['width']  = intval( $width );
				$images[ $i ]   = $item;

				++$i;
			}
			$data['gallery'] = $images;

		}

		if ( ! isset( $data['config'] ) || ! is_array( $data['config'] ) ) {
			$data['config'] = [];
		}

		$data['config']['title'] = wp_strip_all_tags( get_the_title( $object_data['id'] ) );

		// Allow the data to be filtered before it is stored and used to create the gallery output.
		$data = apply_filters( 'envira_gallery_pre_data', $data, $object_data['id'] );

		return $data;
	}

	/**
	 * Rest API updater callback.
	 *
	 * @since 1.8.5
	 *
	 * @param array  $value Value to update.
	 * @param object $post_object Post Object.
	 * @param string $field_name Meta field name.
	 *
	 * @return array
	 */
	public function update_gallery_data( $value, $post_object, $field_name ) {

		$this->gallery_skipped = 0;

		// Enforce capability check — register_rest_field() has no permission_callback,
		// so authorization must be checked here to prevent any authenticated user from writing gallery data.
		if ( ! current_user_can( 'edit_post', $post_object->ID ) ) { // High06: deny if caller lacks edit_post capability — the ! negation is required and must not be removed.
			return new WP_Error( 'rest_forbidden', __( 'Permission denied.', 'envira-gallery-lite' ), [ 'status' => 403 ] );
		}

		$gallery_data = get_post_meta( $post_object->ID, '_eg_gallery_data', true );

		// If Gallery Data is emptyy prepare it.
		if ( ! is_array( $gallery_data ) ) {
			$gallery_data = [];
		}

		if ( empty( $gallery_data['config'] ) ) {
			$gallery_data['config'] = [];

			$common = new Envira_Gallery_Common();
			// Loop through the defaults and prepare them to be stored.
			$defaults = $common->get_config_defaults( $post_object->ID );

			foreach ( $defaults as $key => $default ) {

				$gallery_data['config'][ $key ] = $default;

			}
		}

		// Update Fields.
		$gallery_data['id'] = $post_object->ID;
		// wp_strip_all_tags() to match every sibling writer of this key
		// (metaboxes.php, admin/common.php, and this class's own read path). The key
		// had been blank since 2024 because of the property typo fixed with it, so it
		// re-enters a config array whose sanitizer has no 'title' case and whose
		// consumers were never exercised with a populated value.
		$gallery_data['config']['title'] = wp_strip_all_tags( $post_object->post_title );

		// is_array() is load-bearing, not defensive tidying. register_rest_field() gives
		// this field no schema, so $value is whatever JSON the client sent. When config
		// arrives as a STRING, every branch of sanitize_config_values() is skipped -
		// each one tests isset( $config['key'] ), and a non-numeric string offset is
		// always false - and wp_parse_args() then hands the string to wp_parse_str(),
		// which parse_str()s the payload into real config keys. The whole allowlist was
		// bypassable by changing one field's JSON type.
		if ( isset( $value['config'] ) && ! is_array( $value['config'] ) ) {

			// Refuse rather than drop. Falling through stored every other field and
			// returned truthy, so a client that double-encoded the field - "config":
			// "{\"columns\":3}" is an ordinary serialization slip - got a 200 with the
			// gallery echoed back and not one config key saved, indefinitely, with
			// nothing logged and no way to tell it from a save that worked.
			return new WP_Error( 'envira_invalid_config', __( 'Gallery config must be an object.', 'envira-gallery-lite' ), [ 'status' => 400 ] );
		}

		if ( isset( $value['config'] ) ) {
			// Sanitize config values before saving to prevent XSS
			$value['config']        = $this->sanitize_config_values( $value['config'] );
			$gallery_data['config'] = wp_parse_args( $value['config'], $gallery_data['config'] );
		}

		// Attachment ids arrive as raw client JSON and are used as array keys in
		// _eg_gallery_data. A non-numeric key survives PHP's key coercion and is later
		// interpolated into front-end attributes, so cast once here rather than trusting
		// every downstream reader. absint() also stops an array reaching get_post_meta()
		// or unset(), which is a TypeError under PHP 8.
		$attach_id = isset( $value['attach_id'] ) && is_scalar( $value['attach_id'] ) ? absint( $value['attach_id'] ) : 0;

		// Refuse the branches that need an attachment id when it did not survive that
		// cast, rather than letting 0 through. With 0, remove_image unset a key that
		// cannot exist and array_search() could not match, so the image stayed in the
		// gallery behind a 200; update_image was worse, persisting gallery[0] with a
		// title and no id, src or status, which then reached the render loop as a broken
		// item. Both were silent, and both were louder before the cast existed - a
		// non-scalar raised a TypeError and an absent key a warning.
		if ( ( isset( $value['remove_image'] ) || isset( $value['update_image'] ) ) && ! $attach_id ) {
			return new WP_Error( 'envira_invalid_attach_id', __( 'A valid attachment ID is required.', 'envira-gallery-lite' ), [ 'status' => 400 ] );
		}

		if ( isset( $value['remove_image'] ) ) {
			$in_gallery  = get_post_meta( $post_object->ID, '_eg_in_gallery', true );
			$has_gallery = get_post_meta( $attach_id, '_eg_has_gallery', true );

			// Unset the image from the gallery, in_gallery and has_gallery checkers.
			unset( $gallery_data['gallery'][ $attach_id ] );

			$key = array_search( $attach_id, (array) $in_gallery, true );

			if ( false !== $key ) {
				unset( $in_gallery[ $key ] );
			}

			$has_key = array_search( $post_object->ID, (array) $has_gallery, true );

			if ( false !== $has_key ) {
				unset( $has_gallery[ $has_key ] );
			}
		}

		if ( isset( $value['update_image'] ) ) {

			// Rename an image that is IN this gallery, or refuse. A non-zero id was the
			// only requirement before, and the array write auto-created a slot - so a
			// rename for an unrelated attachment inserted an item with a title and no
			// id, src or status, which the render loop then walked because only
			// 'pending' is skipped.
			if ( ! isset( $gallery_data['gallery'][ $attach_id ] ) || ! is_array( $gallery_data['gallery'][ $attach_id ] ) ) {
				return new WP_Error( 'envira_image_not_in_gallery', __( 'That image is not in this gallery.', 'envira-gallery-lite' ), [ 'status' => 404 ] );
			}

			$update_image = isset( $value['updated_image'] ) && is_array( $value['updated_image'] ) ? $value['updated_image'] : [];

			// Sanitized the way the AJAX writer of these same two keys does. trim() alone
			// raised an uncaught TypeError on an array, and stored the value unfiltered.
			if ( isset( $update_image['title'] ) && is_scalar( $update_image['title'] ) ) {
				$gallery_data['gallery'][ $attach_id ]['title'] = trim( wp_kses_post( (string) $update_image['title'] ) );
			}
			if ( isset( $update_image['caption'] ) && is_scalar( $update_image['caption'] ) ) {
				$gallery_data['gallery'][ $attach_id ]['caption'] = trim( wp_kses_post( (string) $update_image['caption'] ) );
			}
		}

		if ( isset( $value['gallery'] ) ) {
			$requested = count( (array) $value['gallery'] );
			$accepted  = 0;

			foreach ( (array) $value['gallery'] as $i => $image ) {

				// Same reasoning as $attach_id above: this id becomes a gallery array key.
				if ( ! is_array( $image ) || ! isset( $image['id'] ) || ! is_scalar( $image['id'] ) ) {
					continue;
				}

				$image_id = absint( $image['id'] );

				if ( ! $image_id ) {
					continue;
				}

				// Only attachments the caller may use, matching the AJAX image writers.
				// Without this, any post id was read back here, exposing the title and
				// excerpt of posts the caller cannot otherwise read. An image already in
				// this gallery stays allowed, so re-saving a legacy gallery keeps working.
				if ( ! envira_gallery_user_can_insert_attachment( $image_id, $post_object->ID ) ) {
					continue;
				}

				$gallery_data = envira_gallery_ajax_prepare_gallery_data( $gallery_data, $image_id );
				++$accepted;
			}

			$this->gallery_skipped = $requested - $accepted;
		}

		// Flush gallery cache.
		$this->common->flush_gallery_caches( $post_object->ID );

		return update_post_meta( $post_object->ID, '_eg_gallery_data', $gallery_data );
	}
	/**
	 * Helper method to accept a cached dimension pair only when it is really a size.
	 *
	 * Stored image metadata is not trustworthy on the sites this class has to cope
	 * with. A zero pair is common after a bulk offload migration, and a naive SQL
	 * search-replace against a serialized array leaves the literal string "Array"
	 * in place of the numbers (nextgen-gallery#882). Both are truthy, so an
	 * ! empty() test copies them straight out, skips the rescue, the measurement and
	 * the error_log, and publishes 0x0 once intval() finally sees them - which
	 * downstream layout divides by (envira-gallery-plugin#5588).
	 *
	 * @since 1.16.0
	 *
	 * @param mixed $width  Candidate width from stored metadata.
	 * @param mixed $height Candidate height from stored metadata.
	 * @return array{0:int,1:int}|null [ width, height ] when both are positive numbers, else null.
	 */
	private function usable_dimensions( $width, $height ) {

		if ( ! is_numeric( $width ) || ! is_numeric( $height ) ) {
			return null;
		}

		$width  = (int) $width;
		$height = (int) $height;

		if ( $width < 1 || $height < 1 ) {
			return null;
		}

		return [ $width, $height ];
	}

	/**
	 * Read image dimensions out of a resized file's own name.
	 *
	 * Both WordPress's registered sizes and Envira_Gallery_Common::resize_image()
	 * encode the result in the filename - "photo-640x480.jpg", or
	 * "photo-640x480_c.jpg" / "photo-640x480_tl.jpg" once a crop or alignment
	 * marker is appended. Reading it back costs nothing and is authoritative for
	 * the file get_image_src() actually returned, which cached attachment
	 * metadata is not once cropping is involved.
	 *
	 * LiteEquiv of: envira_get_dimensions_from_filename()
	 *
	 * @since 1.16.0
	 *
	 * @param string     $src             Image URL or file path.
	 * @param array|null $attachment_meta The attachment's _wp_attachment_metadata, used to
	 *                                    tell a derivative from an original so an original
	 *                                    whose own name ends "-WxH" is not misread.
	 * @return array{0:int,1:int}|null [ width, height ], or null when there is no usable suffix.
	 */
	private function get_dimensions_from_filename( $src, $attachment_meta = null ) {

		if ( empty( $src ) || ! is_string( $src ) || ! preg_match( '/-(\d+)x(\d+)(?:_[a-zA-Z0-9]+)?\.[a-zA-Z0-9]+$/', basename( $src ), $matches ) ) {
			return null;
		}

		// A "-WxH" in the name only describes the file when the file is a derivative.
		// An ORIGINAL upload can carry the same shape - "hero-1600x900.jpg" that is
		// really 800x450 - and trusting it there publishes the filename's numbers over
		// correct cached metadata, which the pre-guard code got right by measuring the
		// file. Compare against the attachment's own stored filenames: if this IS one
		// of them, the suffix is part of the user's name, not a size.
		//
		// 'original_image' is checked as well as 'file' for parity with Pro, where
		// envira_get_image_src() returns the pre-scale original for full/random sizes
		// so only that key matches on an attachment above big_image_size_threshold.
		// Lite asks for wp_get_attachment_image_src( $id, 'full' ), which returns the
		// "-scaled" file, so 'file' is the one that matches here - both are kept so
		// the two products stay comparable. Lite is the stricter of the two: Pro's
		// envira_get_dimensions_from_filename() has no derivative check at all and no
		// zero-axis rejection, so it carries both defects this method fixes. Pro needs
		// the guard added, not loosened; until then the rule is not identical.
		//
		// The guard is unconditional. It used to run only when cached top-level
		// dimensions existed, to avoid pushing an un-answerable attachment into the
		// per-image getimagesize() block #5544 exists to avoid - but that turned it
		// off for precisely the attachments this PR is about. An offloaded attachment
		// with no cached dimensions took the filename's numbers on trust, so
		// "hero-1600x900.jpg" that is really 800x450 published 1600x900, and because
		// those are truthy the measurement, the rescue, the log and the terminal
		// fallback were all skipped: wrong dimensions, no signal. Returning null costs
		// at most one local stat, and on a stream-wrapped path the remote guard below
		// still refuses to stat at all.
		// With no metadata at all - wp_get_attachment_metadata() returns false when
		// _wp_attachment_metadata is missing, which is the regenerate-me case this whole
		// cascade exists for - there is nothing to compare the basename against, so the
		// suffix cannot be shown to describe a derivative. Refuse it rather than trust
		// it: returning the filename's numbers here is a confident wrong answer that
		// skips the measurement, the rescue, the terminal fallback and the log, which is
		// the same defect removing the gate above was meant to close.
		if ( ! is_array( $attachment_meta ) ) {
			return null;
		}

		foreach ( [ 'file', 'original_image' ] as $meta_key ) {

			if ( ! empty( $attachment_meta[ $meta_key ] ) && is_string( $attachment_meta[ $meta_key ] )
				&& basename( $src ) === basename( $attachment_meta[ $meta_key ] ) ) {
				return null;
			}
		}

		// A zero in either axis is not a usable answer, and it is worse than none: the
		// array is truthy, so it short-circuits the cascade past the measurement, the
		// rescue and the only error_log, and then publishes 0x0. A file named
		// "spacer-0x0.png" or "banner-1920x0.jpg" is all it takes.
		if ( (int) $matches[1] < 1 || (int) $matches[2] < 1 ) {
			return null;
		}

		return [ (int) $matches[1], (int) $matches[2] ];
	}

	/**
	 * Helper method to retrieve the proper image src attribute based on gallery settings.
	 *
	 * @since 1.7.0
	 *
	 * @param int   $id         The image attachment ID to use.
	 * @param array $item       Gallery item data.
	 * @param array $data       The gallery data to use for retrieval.
	 * @param bool  $mobile        Whether or not to retrieve the mobile image.
	 * @param bool  $retina     Whether to return a retina sized image.
	 * @return string               The proper image src attribute for the image.
	 */
	public function get_image_src( $id, $item, $data, $mobile = false, $retina = false ) {

		// Define variable.
		$src = false;

		// Check for mobile and mobile setting.
		$type = $mobile && $this->get_config( 'mobile', $data ) ? 'mobile' : 'crop'; // 'crop' is misleading here - it's the key that stores the thumbnail width + height

		// If this image is an instagram, we grab the src and don't use the $id.
		// otherwise using the $id refers to a postID in the database and has been known.
		// at times to pull up the wrong thumbnail instead of the instagram one.
		$instagram = false;

		if ( ! empty( $item['src'] ) && strpos( $item['src'], 'cdninstagram' ) !== false ) :
			// using 'cdninstagram' because it seems all urls contain it - but be watchful in the future.
			$instagram = true;
			$src       = $item['src'];
			$image     = $item['src'];
		endif;

		$image_size = $this->get_config( 'image_size', $data );

		if ( ! $src && is_int( $id ) ) : // wp_get_attachment_image_src only accepts $id as integer.

			if ( ( $this->get_config( 'crop', $data ) && 'default' === $image_size ) || 'full' === $image_size ) {

				$src = apply_filters( 'envira_gallery_retina_image_src', wp_get_attachment_image_src( $id, 'full' ), $id, $item, $data, $mobile );

			} elseif ( 'full' !== $image_size && ! $retina ) {

				// Check if this Gallery uses a WordPress defined image size.
				if ( 'default' !== $image_size ) {
					// If image size is envira_gallery_random, get a random image size.
					if ( 'envira_gallery_random' === $image_size ) {

						// Get random image sizes that have been chosen for this Gallery.
						$image_sizes_random = (array) $this->get_config( 'image_sizes_random', $data );

						if ( count( $image_sizes_random ) === 0 ) {
							// The user didn't choose any image sizes - use them all.
							$wordpress_image_sizes           = $this->common->get_image_sizes( true );
							$wordpress_image_size_random_key = array_rand( $wordpress_image_sizes, 1 );
							$image_size                      = $wordpress_image_sizes[ $wordpress_image_size_random_key ]['value'];
						} else {
							$wordpress_image_size_random_key = array_rand( $image_sizes_random, 1 );
							$image_size                      = $image_sizes_random[ $wordpress_image_size_random_key ];
						}

						// Get the random WordPress defined image size.
						$src = wp_get_attachment_image_src( $id, $image_size );
					} else {
						// Get the requested WordPress defined image size.
						$src = wp_get_attachment_image_src( $id, $image_size );
					}
				} else {

					$isize = $this->find_clostest_size( $data ) !== '' ? $this->find_clostest_size( $data ) : 'full';
					$src   = apply_filters( 'envira_gallery_default_image_src', wp_get_attachment_image_src( $id, $isize ), $id, $item, $data, $mobile );

				}
			} else {

				$src = apply_filters( 'envira_gallery_retina_image_src', wp_get_attachment_image_src( $id, 'full' ), $id, $item, $data, $mobile );

			}

		endif;

		// Check if this returned an image.
		if ( ! $src ) {
			// Fallback to the $item's image source.
			$image = $item['src'];
		} elseif ( ! $instagram ) {
			$image = $src[0];
		}

		// If we still don't have an image at this point, something went wrong.
		if ( ! isset( $image ) ) {
			return apply_filters( 'envira_gallery_no_image_src', $item['link'], $id, $item, $data );
		}

		// If the current layout is justified/automatic.
		// if the image size is a WordPress size and we're not requesting a retina image we don't need to resize or crop anything.
		if ( 'default' !== $image_size && ! $retina && 'mobile' !== $type ) {
			return apply_filters( 'envira_gallery_image_src', $image, $id, $item, $data );
		}
		$crop = $this->get_config( 'crop', $data );

		if ( $crop || 'mobile' === $type ) {

			// If the image size is default (i.e. the user has input their own custom dimensions in the Gallery),
			// we may need to resize the image now.
			// This is safe to call every time, as resize_image() will check if the image already exists, preventing thumbnails.
			// from being generated every single time.
			$args = [
				'position' => $this->get_config( 'crop_position', $data ),
				'width'    => $this->get_config( $type . '_width', $data ),
				'height'   => $this->get_config( $type . '_height', $data ),
				'quality'  => 100,
				'retina'   => $retina,
			];

			// If we're requesting a retina image, and the gallery uses a registered WordPress image size,
			// we need use the width and height of that registered WordPress image size - not the gallery's
			// image width and height, which are hidden settings.
			// if this is mobile, go with the mobile image settings, otherwise proceed?
			if ( 'default' !== $image_size && $retina && 'mobile' !== $type ) {
				// Find WordPress registered image size.
				$wordpress_image_sizes = $this->common->get_image_sizes( true ); // true = WordPress only image sizes (excludes random.

				foreach ( $wordpress_image_sizes as $size ) {

					if ( $size['value'] !== $image_size ) {
						continue;
					}

					// We found the image size. Use its dimensions.
					if ( ! empty( $size['width'] ) ) {
						$args['width'] = $size['width'];
					}
					if ( ! empty( $size['height'] ) ) {
						$args['height'] = $size['height'];
					}
					break;

				}
			}

			// Filter.
			$args = apply_filters( 'envira_gallery_crop_image_args', $args );

			// Make sure we're grabbing the full image to crop.
			$image_to_crop = apply_filters( 'envira_gallery_crop_image_src', wp_get_attachment_image_src( $id, 'full' ), $id, $item, $data, $mobile );
			// Check if this returned an image.
			if ( ! $image_to_crop ) {
				// Fallback to the $item's image source.
				$image_to_crop = $item['src'];
			} elseif ( ! $instagram ) {
				$image_to_crop = $src[0];
			}

			$resized_image = $this->common->resize_image( $image_to_crop, $args['width'], $args['height'], true, $this->get_config( 'crop_position', $data ), $args['quality'], $args['retina'], $data );

			// If there is an error, fall back to the un-cropped source rather than false.
			// Returning false here blanked $item['src'] outright, so the block editor and
			// the front end rendered nothing at all. That path is now routinely reached on
			// offloaded media: get_image_info() refuses to crop a stream-wrapped file
			// (s3://, iu://) instead of stalling on it, so an offloaded site would lose
			// every image in every gallery. An un-cropped image is a visible degradation;
			// no image is a broken gallery. See envira-gallery-plugin#5544.
			if ( is_wp_error( $resized_image ) ) {

				// Only the offload codes are expected here. Anything else - a server that
				// lost GD/Imagick ('envira-gallery-error-no-editor'), unset crop dimensions
				// ('envira-gallery-error-no-size'), or a failed $editor->save() - is a real
				// fault that would otherwise serve full-size originals in place of thumbs
				// indefinitely with no signal anywhere. Log those, throttled per attachment
				// the way get_gallery_data() is, and un-gated on WP_DEBUG for the same
				// reason: these are production sites.
				$expected_codes = $this->common->get_routine_resize_error_codes();
				$error_code     = $resized_image->get_error_code();

				if ( ! in_array( $error_code, $expected_codes, true ) ) {

					// Keyed per attachment AND per error code: resize_image() can fail on the
					// same image for materially different reasons, and an id-only key silenced
					// every later fault on that image for a day - including the recurrence
					// after an operator fixed the first one. The code is already reduced to
					// [A-Za-z0-9_.-], so it is safe inside a transient name.
					$error_slug = preg_replace( '/[^A-Za-z0-9_.\-]/', '', (string) $error_code );
					$throttle   = (int) $id . '-' . $error_slug;

					// Same three bounds as the missing-dimensions log above, and for the same
					// reason: this runs once per image per render, so a store that drops
					// transient writes must not restore per-image logging.
					static $logged_resize_errors = [];
					static $resize_error_budget  = 0;

					if ( $resize_error_budget < 10 && ! isset( $logged_resize_errors[ $throttle ] ) ) {

						$logged_resize_errors[ $throttle ] = true;
						++$resize_error_budget;

						// Only the attachment ID and the error code are interpolated, and the
						// code is stripped of newlines so a filtered WP_Error cannot forge
						// log entries.
						$this->common->log_once_per_day(
							'rest_resize_error',
							$throttle,
							sprintf( 'Envira Gallery Lite: could not resize attachment %d (%s); serving the un-cropped image.', (int) $id, $error_slug )
						);
					}
				}

				return apply_filters( 'envira_gallery_image_src', $image, $id, $item, $data );
			}

			return apply_filters( 'envira_gallery_image_src', $resized_image, $id, $item, $data );
		}
		// return full image.
		return apply_filters( 'envira_gallery_image_src', $image, $id, $item, $data );
	}

	/**
	 * Helper method for retrieving config values.
	 *
	 * @since 1.0.0
	 *
	 * @param string $key The config key to retrieve.
	 * @param array  $data The gallery data to use for retrieval.
	 * @return string     Key value on success, default if not set.
	 */
	public function get_config( $key, $data ) {

		return isset( $data['config'][ $key ] ) ? $data['config'][ $key ] : $this->common->get_config_default( $key );
	}

	/**
	 * Sanitizes config values to prevent XSS attacks.
	 *
	 * @since 1.12.4
	 *
	 * @param array $config The config array to sanitize.
	 * @return array Sanitized config array.
	 */
	public function sanitize_config_values( $config ) {

		// Every check below is an isset() on an array key. Given a string, they are all
		// string-offset tests that return false, so the method would return its input
		// untouched and look like it had sanitized it. This is a public method - keep the
		// guard here so any caller gets it, not only update_gallery_data().
		if ( ! is_array( $config ) ) {
			return [];
		}

		// Sanitize description to prevent stored XSS via the REST API.
		if ( isset( $config['description'] ) ) {
			$description = $config['description'];

			if ( ! is_scalar( $description ) ) {
				$description = '';
			}

			$config['description'] = wp_kses_post( (string) $description );
		}

		// Sanitize justified_gallery_theme - ensure it's a valid theme
		if ( isset( $config['justified_gallery_theme'] ) ) {
			$config['justified_gallery_theme'] = $this->common->sanitize_justified_gallery_theme(
				$config['justified_gallery_theme']
			);
		}

		// Sanitize justified_row_height - ensure it's a positive integer
		if ( isset( $config['justified_row_height'] ) ) {
			$row_height = absint( $config['justified_row_height'] );
			if ( $row_height <= 0 ) {
				$row_height = $this->common->get_config_default( 'justified_row_height' );
			}
			$config['justified_row_height'] = $row_height;
		}

		// Normalize boolean config values to actual PHP booleans at ingestion.
		// Shortcode output always casts via (bool) before wp_json_encode(), so storing
		// the string 'false' would evaluate as true (non-empty string). Storing real
		// booleans ensures (bool) casts downstream behave correctly.
		foreach ( [ 'aspect', 'loop', 'mousewheel' ] as $key ) {
			if ( isset( $config[ $key ] ) ) {
				$config[ $key ] = filter_var( $config[ $key ], FILTER_VALIDATE_BOOLEAN );
			}
		}

		// Sanitize arrows - accept boolean-like values supported by FILTER_VALIDATE_BOOLEAN and normalize to 1/0.
		if ( isset( $config['arrows'] ) ) {
			$config['arrows'] = filter_var( $config['arrows'], FILTER_VALIDATE_BOOLEAN ) ? 1 : 0;
		}

		// Sanitize numeric JS values at ingestion — these are emitted bare into a JS numeric
		// context. esc_attr() encodes HTML entities but not commas/parentheses, allowing JS injection.
		// absint() guarantees a non-negative integer, which is safe in an unquoted numeric context.
		foreach ( [ 'thumbnails_width', 'thumbnails_height' ] as $key ) {
			if ( isset( $config[ $key ] ) ) {
				$v              = absint( $config[ $key ] ); // Enforce integer; eliminates injection risk in bare JS numeric context.
				$config[ $key ] = $v > 0 ? $v : $this->common->get_config_default( $key );
			}
		}

		// Same treatment for the crop dimensions. shortcode.php emits these into
		// width="" / height="" and data-envira-width="" with no escaping, so a value
		// carrying a quote breaks out of the attribute. The classic metabox writer
		// absint()s them; this REST writer merged them raw, and it has no schema to
		// coerce types upstream either.
		foreach ( [ 'crop_width', 'crop_height' ] as $key ) {
			if ( isset( $config[ $key ] ) ) {
				$v              = absint( $config[ $key ] );
				$config[ $key ] = $v > 0 ? $v : $this->common->get_config_default( $key );
			}
		}

		// crop_position lands in a filename this plugin writes, so it is allowlisted
		// here as well as at the sink in get_image_info(). image_size is used as an
		// array key on stored metadata, where a non-string raises a PHP 8 TypeError,
		// and the metabox writer already validates it against registered sizes -
		// apply the same policy to the REST writer.
		if ( isset( $config['crop_position'] ) ) {
			$valid_positions  = [ 'c', 't', 'b', 'l', 'r', 'tl', 'tr', 'bl', 'br' ];
			$default_position = $this->common->get_config_default( 'crop_position' );

			// get_config_defaults() has no crop_position entry, so the default is false -
			// store the centre crop the sink falls back to rather than a bare false.
			if ( ! is_string( $default_position ) || ! in_array( $default_position, $valid_positions, true ) ) {
				$default_position = 'c';
			}

			$config['crop_position'] = ( is_string( $config['crop_position'] ) && in_array( $config['crop_position'], $valid_positions, true ) )
				? $config['crop_position']
				: $default_position;
		}

		if ( isset( $config['image_size'] ) ) {
			$valid_image_sizes    = array_column( $this->common->get_image_sizes(), 'value' );
			$config['image_size'] = ( is_string( $config['image_size'] ) && in_array( $config['image_size'], $valid_image_sizes, true ) )
				? $config['image_size']
				: $this->common->get_config_default( 'image_size' );
		}

		// 'dimensions' only ever gates whether those two attributes are printed.
		if ( isset( $config['dimensions'] ) ) {
			$config['dimensions'] = filter_var( $config['dimensions'], FILTER_VALIDATE_BOOLEAN ) ? 1 : 0;
		}

		return $config;
	}

	/**
	 * Helper Method to find closest size
	 *
	 * @param array $data Gallery Data.
	 * @return mixed
	 */
	public function find_clostest_size( $data ) {

		$image_sizes = $this->common->get_image_sizes();
		$dimensions  = $this->get_config( 'dimensions', $data );
		$width       = $this->get_config( 'crop_width', $data );
		$height      = $this->get_config( 'crop_height', $data );
		$match       = false;

		usort( $image_sizes, [ $this, 'usort_callback' ] );

		foreach ( $image_sizes as $num ) {
			if ( isset( $num['width'] ) && isset( $num['height'] ) ) {
				$num['width']  = (int) $num['width'];
				$num['height'] = (int) $num['height'];

				// skip over sizes that are smaller.
				if ( $num['height'] < $height || $num['width'] < $width ) {
					continue;
				}
				if ( $num['width'] > $width && $num['height'] > $height ) {

					if ( false === $match ) {

						$match = true;
						$size  = $num['name'];

						return $size;
					}
				}
			}
		}
		return '';
	}

	/**
	 * Helper function for usort and php 5.3 >.
	 *
	 * @access public
	 * @param mixed $a First sort.
	 * @param mixed $b Second sort.
	 * @return int
	 */
	public function usort_callback( $a, $b ) {
		if ( isset( $a['width'] ) && isset( $b['width'] ) ) {
			if ( intval( $a['width'] ) === intval( $b['width'] ) ) {
				return 0;
			}

			return intval( $a['width'] ) > intval( $b['width'] ) ? 1 : -1;
		}

		return 0;
	}
	/**
	 * Maybe sort the gallery images, if specified in the config
	 *
	 * Note: To ensure backward compat with the previous 'random' config
	 * key, the sorting parameter is still stored in the 'random' config
	 * key.
	 *
	 * @since 1.3.8
	 *
	 * @param   array $data       Gallery Config.
	 * @param   int   $gallery_id Gallery ID.
	 * @return  array               Gallery Config.
	 */
	public function maybe_sort_gallery( $data, $gallery_id ) {

		// Get sorting method.
		$sorting_method    = (string) $this->get_config( 'random', $data );
		$sorting_direction = $this->get_config( 'sorting_direction', $data );

		// Sort images based on method.
		switch ( $sorting_method ) {
			/**
			* Random.
			* - Again, by design, to ensure backward compat when upgrading from 1.3.7.x or older.
			* where we had a 'random' key = 0 or 1. Sorting was introduced in 1.3.8.
			*/
			case '1':
				// Shuffle keys.
				$keys = array_keys( $data['gallery'] );
				shuffle( $keys );

				// Rebuild array in new order.
				$new = [];
				foreach ( $keys as $key ) {
					$new[ $key ] = $data['gallery'][ $key ];
				}

				// Assign back to gallery.
				$data['gallery'] = $new;
				break;

			/**
			* Image Meta.
			*/
			case 'src':
			case 'title':
			case 'caption':
			case 'alt':
			case 'link':
				// Get metadata.
				$keys = [];
				foreach ( $data['gallery'] as $id => $item ) {
					$keys[ $id ] = wp_strip_all_tags( $item[ $sorting_method ] );
				}

				// Sort titles / captions.
				if ( 'ASC' === $sorting_direction ) {
					asort( $keys );
				} else {
					arsort( $keys );
				}

				// Iterate through sorted items, rebuilding gallery.
				$new = [];
				foreach ( $keys as $key => $title ) {
					$new[ $key ] = $data['gallery'][ $key ];
				}

				// Assign back to gallery.
				$data['gallery'] = $new;
				break;

			/**
			* Published Date.
			*/
			case 'date':
				// Get published date for each.
				$keys = [];
				foreach ( $data['gallery'] as $id => $item ) {
					// If the attachment isn't in the Media Library, we can't get a post date - assume now.
					$attachment = get_post( $id );
					if ( ! is_numeric( $id ) || ( false === $attachment ) ) {
						$keys[ $id ] = gmdate( 'Y-m-d H:i:s' );
					} else {
						$keys[ $id ] = $attachment->post_date;
					}
				}

				// Sort titles / captions.
				if ( 'ASC' === $sorting_direction ) {
					asort( $keys );
				} else {
					arsort( $keys );
				}

				// Iterate through sorted items, rebuilding gallery.
				$new = [];
				foreach ( $keys as $key => $title ) {
					$new[ $key ] = $data['gallery'][ $key ];
				}

				// Assign back to gallery.
				$data['gallery'] = $new;
				break;

			/**
			* None.
			* - Do nothing.
			*/
			case '0':
			case '':
				break;

			/**
			* If developers have added their own sort options, let them run them here
			*/
			default:
				$data = apply_filters( 'envira_gallery_sort_gallery', $data, $sorting_method, $gallery_id );
				break;

		}

		return $data;
	}

	/**
	 * Returns the singleton instance of the class.
	 *
	 * @since 1.0.0
	 *
	 * @return object The Envira_Gallery_Posttype object.
	 */
	public static function get_instance() {

		if ( ! isset( self::$instance ) && ! ( self::$instance instanceof Envira_Rest ) ) {
			self::$instance = new self();
		}

		return self::$instance;
	}
}

$envira_rest = Envira_Rest::get_instance();
