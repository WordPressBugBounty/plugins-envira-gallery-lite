<?php
/**
 * Convert Gallery Common Class.
 *
 * @since 1.9.1
 *
 * @package Envira Gallery
 * @author  Envira Gallery Team <support@enviragallery.com>
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Convert Gallery Common Class.
 */
class Convert_Gallery_Common {
	/**
	 * Galleries skipped in the current run because none of their images could be used.
	 *
	 * @since 1.16.2
	 *
	 * @var int
	 */
	protected $convert_refused = 0;

	/**
	 * Keeps only images the user may put into a gallery, with src taken from the attachment.
	 *
	 * An image passes if the user can add the attachment, or the source post (which the
	 * user can edit) already shows it: it is referenced in the saved content or attached
	 * to the post. Core's own gallery block and shortcode already render those, so
	 * converting them exposes nothing new. The client-supplied URL is never stored.
	 *
	 * @since 1.16.2
	 *
	 * @param array    $images     Images as [ id, url, title, alt ].
	 * @param int|null $post_id    Source post ID.
	 * @param int[]    $source_ids Other posts the gallery pulls attachments from, e.g. [gallery id=X].
	 * @return array
	 */
	public function filter_convertible_images( $images, $post_id, $source_ids = [] ) {
		$post_id    = absint( $post_id );
		$referenced = [];

		if ( $post_id ) {
			$content = (string) get_post_field( 'post_content', $post_id );

			preg_match_all( '/wp-image-(\d+)|"id":(\d+)/', $content, $matches );
			$referenced = array_merge( $matches[1], $matches[2] );

			preg_match_all( '/\[gallery[^\]]*\b(?:ids|include)\s*=\s*["\']?([\d,\s]+)/', $content, $shortcodes );
			foreach ( $shortcodes[1] as $list ) {
				$referenced = array_merge( $referenced, explode( ',', $list ) );
			}

			$referenced = array_filter( array_map( 'absint', $referenced ) );
		}

		$sources = array_filter( array_map( 'absint', array_merge( [ $post_id ], (array) $source_ids ) ) );

		$allowed = [];

		foreach ( (array) $images as $image ) {
			if ( ! is_array( $image ) || ! isset( $image['id'] ) || ! is_scalar( $image['id'] ) ) {
				continue;
			}

			$image_id = absint( $image['id'] );
			$url      = $image_id ? wp_get_attachment_url( $image_id ) : false;

			if ( ! $url ) {
				continue;
			}

			// Only a live attachment counts as already shown by the post; not trashed or private.
			// Its parent must also be public or editable by the user: the converted gallery is
			// published, and the content (ids, [gallery id=X]) is written by the user.
			$parent  = (int) wp_get_post_parent_id( $image_id );
			$in_post = $post_id
				&& 'inherit' === get_post_field( 'post_status', $image_id )
				&& ( in_array( $image_id, $referenced, true ) || in_array( $parent, $sources, true ) )
				&& ( ! $parent || current_user_can( 'edit_post', $parent )
					|| ( 'publish' === get_post_status( $parent ) && '' === get_post_field( 'post_password', $parent ) ) );

			if ( ! $in_post && ! envira_gallery_user_can_insert_attachment( $image_id ) ) {
				continue;
			}

			$image['id']    = $image_id;
			$image['url']   = $url;
			$image['title'] = isset( $image['title'] ) && is_scalar( $image['title'] ) ? sanitize_text_field( $image['title'] ) : '';
			$image['alt']   = isset( $image['alt'] ) && is_scalar( $image['alt'] ) ? sanitize_text_field( $image['alt'] ) : '';
			$allowed[]      = $image;
		}

		return $allowed;
	}

	/**
	 * Create an Envira Gallery using the WordPress Gallery block data.
	 *
	 * @param  array $passed_data wp gallery passed data array.
	 * @return array
	 */
	public function create_envira_gallery_using_wp_gallery( $passed_data ) {
		$post_id       = $passed_data['post_id'] ?? null;
		$columns       = $passed_data['columns'] ?? 0;
		$size_slug     = $passed_data['size_slug'] ?? 'thumbnail';
		$link_target   = $passed_data['link_target'] ?? '';
		$images        = $passed_data['images'] ?? [];
		$block_content = $passed_data['block_content'] ?? '';

		// Only attachments the caller may use, matching the REST and AJAX image writers. The
		// converted gallery is published, so without this an Author could publish another
		// user's attachment, e.g. one belonging to a private post. WP-CLI runs as no user.
		$skipped = 0;

		if ( ! ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			$requested = is_array( $images ) ? count( $images ) : 0;
			$images    = $this->filter_convertible_images( $images, $post_id, $passed_data['source_ids'] ?? [] );
			$skipped   = $requested - count( $images );

			if ( empty( $images ) ) {
				return [
					'error'   => __( 'You are not allowed to add the selected images to a gallery.', 'envira-gallery-lite' ),
					'refused' => true,
				];
			}
		}

		$date_now      = wp_date( 'Y-m-d H:i:s' );
		$gallery_title = sprintf( 'Converted-%s', $date_now );

		if ( ! empty( $post_id ) ) {
			// Save the block content to post meta using a unique meta key.
			$date_prefix = wp_date( 'Ymd_His' );
			$meta_key    = 'gallery_block_bkp_' . wp_rand( 1000, 9999 ) . '_' . $date_prefix;
			update_post_meta( $post_id, $meta_key, $block_content );

			// Get the post title.
			$post_title = get_the_title( $post_id );

			if ( ! empty( $post_title ) ) {
				// Truncate title to 15-20 characters.
				$truncated_title = strlen( $post_title ) > 20 ? substr( $post_title, 0, 20 ) : $post_title;

				// Generate a unique gallery title.
				$gallery_title = sprintf(
					'%s-%d-Converted-%s',
					$truncated_title,
					$post_id,
					$date_now
				);
			}
		}

		$gallery_post_args = [
			'ID'          => 0,
			'post_type'   => 'envira',
			'post_status' => 'publish',
			'post_title'  => $gallery_title,
		];

		$gallery_post = wp_insert_post( $gallery_post_args );

		if ( is_wp_error( $gallery_post ) ) {
			return [
				'error' => __(
					'There was a problem with the Envira gallery creation process. Please try again.',
					'envira-gallery-lite'
				),
			];
		} else {
			$gallery_id = $gallery_post;

			$gallery_data = [];

			$common = new Envira_Gallery_Common();

			// Loop through the defaults and prepare them to be stored.
			$defaults = $common->get_config_defaults( $gallery_id );

			foreach ( $defaults as $key => $default ) {
				$gallery_data['config'][ $key ] = $default;
			}

			// Update Fields.
			$gallery_data['id']                = $gallery_id;
			$gallery_data['config']['title']   = $gallery_title;
			$gallery_data['config']['slug']    = sanitize_title( $gallery_title );
			$gallery_data['config']['columns'] = '3';

			if ( ! empty( $columns ) ) {
				$gallery_data['config']['columns'] = (string) $columns;
			}

			if ( ! empty( $size_slug ) ) {
				$gallery_data['config']['image_size'] = $size_slug;
			}

			foreach ( $images as $image ) {
				$image_id     = $image['id'];
				$images_ids[] = $image_id;

				$image_title = ! empty( $image['title'] ) ? $image['title'] : pathinfo( wp_parse_url( $image['url'], PHP_URL_PATH ), PATHINFO_FILENAME );

				$gallery_data['gallery'][ $image_id ] = [
					'status'          => 'active',
					'src'             => $image['url'],
					'title'           => $image_title,
					'link'            => $image['url'],
					'alt'             => $image['alt'],
					'caption'         => '',
					'thumb'           => '',
					'link_new_window' => ( '_blank' === $link_target ) ? 1 : 0,
				];
			}

			// Update envira gallery meta data.
			update_post_meta( $gallery_data['id'], '_eg_in_gallery', $images_ids );
			update_post_meta( $gallery_data['id'], '_eg_gallery_data', $gallery_data );

			$response_data = [
				'gallery_id'     => $gallery_data['id'],
				'title'          => $gallery_data['config']['title'],
				'columns'        => $gallery_data['config']['columns'],
				'margins'        => 'custom',
				'custom_margins' => $gallery_data['config']['margin'],
				'message'        => __( 'Converted successfully. Don\'t forget to save your changes!', 'envira-gallery-lite' ),
			];

			// Some images were refused or could not be resolved: say so instead of a plain success.
			if ( $skipped > 0 ) {
				$response_data['skipped']         = $skipped;
				$response_data['skipped_message'] = __( 'Some images were not converted because you are not allowed to use them.', 'envira-gallery-lite' );
			}

			return $response_data;
		}
	}

	/**
	 * Process gallery shortcodes.
	 *
	 * @param string $updated_content Updated content.
	 * @param int    $post_id         Post ID.
	 * @param bool   $needs_update    Needs update.
	 * @return WP_REST_Response|void
	 */
	public function process_gallery_shortcodes( &$updated_content, $post_id, &$needs_update ) {
		if ( has_shortcode( $updated_content, 'gallery' ) ) {
			preg_match_all( '/\[gallery(.*?)\]/', $updated_content, $matches, PREG_SET_ORDER );

			foreach ( $matches as $shortcode ) {
				$shortcode_string = $shortcode[0];
				$shortcode_attrs  = shortcode_parse_atts( $shortcode[1] );

				// Extract gallery attributes.
				$ids        = isset( $shortcode_attrs['ids'] ) ? explode( ',', $shortcode_attrs['ids'] ) : [];
				$include    = isset( $shortcode_attrs['include'] ) ? explode( ',', $shortcode_attrs['include'] ) : [];
				$exclude    = isset( $shortcode_attrs['exclude'] ) ? explode( ',', $shortcode_attrs['exclude'] ) : [];
				$columns    = isset( $shortcode_attrs['columns'] ) ? absint( $shortcode_attrs['columns'] ) : 3;
				$size_slug  = isset( $shortcode_attrs['size'] ) ? sanitize_text_field( $shortcode_attrs['size'] ) : 'thumbnail';
				$gallery_id = isset( $shortcode_attrs['id'] ) ? absint( $shortcode_attrs['id'] ) : 0;

				// Fetch attachments for the shortcode.
				if ( empty( $ids ) ) {
					$query_args = [
						'post_type'      => 'attachment',
						'post_status'    => 'inherit',
						'posts_per_page' => -1,
						'fields'         => 'ids',
						'post_parent'    => $gallery_id ? $gallery_id : $post_id,
					];

					if ( ! empty( $include ) ) {
						$query_args['post__in'] = array_map( 'absint', $include );
					}

					if ( ! empty( $exclude ) ) {
						$query_args['post__not_in'] = array_map( 'absint', $exclude );
					}

					$ids = get_posts( $query_args );
				}

				// Create gallery images.
				$images = [];
				foreach ( $ids as $id ) {
					$image_id    = absint( $id );
					$image_url   = wp_get_attachment_url( $image_id );
					$image_title = get_the_title( $image_id );
					$image_alt   = get_post_meta( $image_id, '_wp_attachment_image_alt', true );

					if ( $image_url ) {
						$images[] = [
							'id'    => $image_id,
							'url'   => $image_url,
							'title' => $image_title,
							'alt'   => $image_alt,
						];
					}
				}

				if ( ! empty( $images ) ) {
					$passed_data = [
						'post_id'       => $post_id,
						'columns'       => $columns,
						'size_slug'     => $size_slug,
						'link_target'   => '_self',
						'images'        => $images,
						'block_content' => $shortcode_string,
						'source_ids'    => $gallery_id ? [ $gallery_id ] : [],
					];

					$created_result = $this->create_envira_gallery_using_wp_gallery( $passed_data );

					// None of this gallery's images can be used: leave it as is and carry on with the rest.
					if ( ! empty( $created_result['refused'] ) ) {
						++$this->convert_refused;
						continue;
					}

					if ( isset( $created_result['error'] ) && ! empty( $created_result['error'] ) ) {
						return new WP_REST_Response( [ 'error' => $created_result['error'] ], 400 );
					} else {
						$envira_shortcode = "[envira-gallery id='{$created_result['gallery_id']}']";
						$updated_content  = str_replace( $shortcode_string, $envira_shortcode, $updated_content );
						$needs_update     = true;
					}
				}
			}
		}
	}

	/**
	 * Process gallery blocks.
	 *
	 * @param array $blocks       Blocks.
	 * @param int   $post_id      Post ID.
	 * @param bool  $needs_update Needs update.
	 * @return WP_REST_Response|void
	 */
	public function process_gallery_blocks( &$blocks, $post_id, &$needs_update ) {
		foreach ( $blocks as &$block ) {
			// If the block is a gallery block.
			if ( 'core/gallery' === $block['blockName'] ) {
				// Extract the attributes.
				$columns       = $block['attrs']['columns'] ?? 3;
				$size_slug     = $block['attrs']['sizeSlug'] ?? 'thumbnail';
				$link_target   = $block['attrs']['linkTo'] ?? '';
				$block_content = serialize_block( $block );

				$images = [];

				// Check if there are inner blocks.
				if ( isset( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
					foreach ( $block['innerBlocks'] as $inner_block ) {
						// If the inner block is an image block.
						if ( 'core/image' === $inner_block['blockName'] ) {
							$image_id = isset( $inner_block['attrs']['id'] ) ? absint( $inner_block['attrs']['id'] ) : 0;
							if ( $image_id ) {
								$image_url   = wp_get_attachment_url( $image_id );
								$image_title = get_the_title( $image_id );

								// Use the `alt` from the HTML if not available in attributes.
								$image_alt = '';
								if ( ! empty( $inner_block['innerHTML'] ) ) {
									$doc = new \DOMDocument();
									libxml_use_internal_errors( true );
									$doc->loadHTML( $inner_block['innerHTML'] );
									libxml_clear_errors();

									$img_tag = $doc->getElementsByTagName( 'img' )->item( 0 );
									if ( $img_tag && $img_tag->hasAttribute( 'alt' ) ) {
										$image_alt = $img_tag->getAttribute( 'alt' );
									}
								}

								// Fallback: If `alt` is not in HTML, try `_wp_attachment_image_alt` meta.
								if ( ! $image_alt ) {
									$image_alt = get_post_meta( $image_id, '_wp_attachment_image_alt', true );
								}

								$images[] = [
									'id'    => $image_id,
									'url'   => $image_url,
									'title' => $image_title,
									'alt'   => $image_alt,
								];
							}
						}
					}
				}

				// Nothing to convert in this block; later blocks still get their turn.
				if ( empty( $images ) || ! is_array( $images ) ) {
					continue;
				}

				$passed_data = [
					'post_id'       => $post_id,
					'columns'       => $columns,
					'size_slug'     => $size_slug,
					'link_target'   => $link_target,
					'images'        => $images,
					'block_content' => $block_content,
				];

				$created_result = $this->create_envira_gallery_using_wp_gallery( $passed_data );

				// None of this gallery's images can be used: leave the block as is and carry on.
				if ( ! empty( $created_result['refused'] ) ) {
					++$this->convert_refused;
					continue;
				}

				if ( isset( $created_result['error'] ) && ! empty( $created_result['error'] ) ) {
					return new WP_REST_Response( [ 'error' => $created_result['error'] ], 400 );
				} else {
					$block['blockName']    = 'envira/envira-gallery';
					$block['attrs']        = [
						'galleryId'        => $created_result['gallery_id'],
						'title'            => $created_result['title'],
						'columns'          => $created_result['columns'],
						'margins'          => $created_result['margins'],
						'custom_margins'   => $created_result['custom_margins'],
						'images'           => $images,
						'lazyload_enabled' => true,
						'isotope'          => true,
						'lightbox_theme'   => 'base_dark',
					];
					$block['innerBlocks']  = [];
					$block['innerHTML']    = '';
					$block['innerContent'] = [
						"<div class='wp-block-envira-envira-gallery'>[envira-gallery id='{$created_result['gallery_id']}']</div>",
					];

					$needs_update = true;
				}
			}

			// Recursively process inner blocks.
			if ( isset( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				$this->process_gallery_blocks( $block['innerBlocks'], $post_id, $needs_update );
			}
		}
	}
}
