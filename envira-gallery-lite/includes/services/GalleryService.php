<?php
/**
 * Envira gallery service — gallery reads and mutations.
 *
 * Owns every write to `_eg_gallery_data` made outside the metabox/AJAX save
 * path, so the Abilities layer stays a registration surface and does not become
 * a second source of truth for gallery meta.
 *
 * Derived from the Pro copy at
 * envira-gallery-plugin/envira-gallery/src/Services/GalleryService.php but not
 * identical: Lite omits set_layout() and the built-in layout types, and calls
 * Envira_Gallery_Common methods where Pro calls its global functions. Pro's
 * rollback, read-back and trash hardening is ported.
 *
 * @since 1.13.0
 *
 * @package Envira_Gallery
 */

namespace Envira\Services;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * GalleryService — gallery CRUD used by the Abilities layer.
 *
 * Every method returns a normalised gallery array (see format()) or a WP_Error
 * carrying an HTTP status, so callers can hand the result straight back to REST
 * or MCP without translating.
 *
 * @since 1.13.0
 */
class GalleryService {

	/**
	 * Gallery post type.
	 *
	 * @since 1.13.0
	 *
	 * @var string
	 */
	const POST_TYPE = 'envira';

	/**
	 * Meta key holding the gallery payload.
	 *
	 * @since 1.13.0
	 *
	 * @var string
	 */
	const META_KEY = '_eg_gallery_data';

	// -------------------------------------------------------------------------
	// Reads
	// -------------------------------------------------------------------------

	/**
	 * Return a paginated list of galleries.
	 *
	 * Mirrors the legacy edit.php ownership scoping: without
	 * `envira_edit_others_galleries` a caller only sees their own galleries.
	 *
	 * @since 1.13.0
	 *
	 * @param array $args {
	 *     Optional. Query arguments.
	 *
	 *     @type int    $per_page Galleries per page. Default 20.
	 *     @type int    $page     Page number. Default 1.
	 *     @type string $status   Publish status, or 'any'. Default 'any'.
	 *     @type string $search   Title search term.
	 * }
	 * @return array
	 */
	public function list_galleries( array $args = [] ): array {
		$per_page = isset( $args['per_page'] ) ? (int) $args['per_page'] : 20;
		$page     = isset( $args['page'] ) ? (int) $args['page'] : 1;
		$status   = isset( $args['status'] ) && 'any' !== $args['status'] ? sanitize_key( $args['status'] ) : 'any';

		// 'any' spelled out as the searchable statuses it stands for: WP_Query
		// applies `perm => readable` to private posts only when the statuses are
		// listed, so a bare 'any' listed other authors' private galleries to a
		// caller without read_private_envira_galleries.
		if ( 'any' === $status ) {
			$status = array_values( get_post_stati( [ 'exclude_from_search' => false ] ) );
		}

		$query_args = [
			'post_type'      => self::POST_TYPE,
			'post_status'    => $status,
			'posts_per_page' => $per_page,
			'paged'          => $page,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'no_found_rows'  => false,
			// Core's private filtering: another author's private gallery is listed
			// only for a caller with read_private_envira_galleries, as in wp-admin.
			'perm'           => 'readable',
		];

		if ( ! empty( $args['search'] ) ) {
			$query_args['s'] = sanitize_text_field( $args['search'] );
		}

		if ( ! envira_current_user_can( 'envira_edit_others_galleries' ) ) {
			$query_args['author'] = get_current_user_id();
		}

		$query = new \WP_Query( $query_args );

		return [
			'galleries'   => array_map( [ $this, 'format' ], $query->posts ),
			'total'       => (int) $query->found_posts,
			'page'        => $page,
			'per_page'    => $per_page,
			'total_pages' => (int) $query->max_num_pages,
		];
	}

	/**
	 * Return a single gallery.
	 *
	 * @since 1.13.0
	 *
	 * @param int $id Gallery ID.
	 * @return array|\WP_Error
	 */
	public function get( int $id ) {
		$post = $this->get_gallery_post( $id );

		if ( is_wp_error( $post ) ) {
			return $post;
		}

		// Reads used to answer with image_count 0 for a gallery whose meta could
		// not be read, while writes refused it — so an agent could list a
		// corrupt gallery, believe it empty, and delete it. Reads now agree with
		// writes.
		$readable = $this->read_data( $id );

		if ( is_wp_error( $readable ) ) {
			return $readable;
		}

		return $this->format( $post );
	}

	// -------------------------------------------------------------------------
	// Mutations
	// -------------------------------------------------------------------------

	/**
	 * Create a gallery.
	 *
	 * @since 1.13.0
	 *
	 * @param string $title  Gallery title.
	 * @param string $status Initial publish status.
	 * @return array|\WP_Error
	 */
	public function create( string $title, string $status = 'draft' ) {
		$title  = sanitize_text_field( $title );
		$status = sanitize_key( $status );

		// wp_slash(): wp_insert_post() unslashes its input, which would strip
		// backslashes from the title (`C:\photos` → `C:photos`).
		$post_id = wp_insert_post(
			wp_slash(
				[
					'post_type'   => self::POST_TYPE,
					'post_status' => $status,
					'post_title'  => $title,
					'post_author' => get_current_user_id(),
				]
			),
			true
		);

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		// Initialise gallery meta using the same structure as envira-importer.
		$gallery_data                    = $this->bootstrap_data( (int) $post_id );
		$gallery_data['config']['title'] = $title;

		$post = get_post( $post_id );

		if ( ! $post ) {
			return $this->discard_created(
				(int) $post_id,
				new \WP_Error(
					'envira_create_failed',
					__( 'Gallery created but could not be retrieved.', 'envira-gallery-lite' ),
					[ 'status' => 500 ]
				)
			);
		}

		// Through save_data(), not a bare update_post_meta(), so the new gallery
		// invalidates the all-galleries cache like every other write path.
		$saved = $this->save_data( (int) $post_id, $gallery_data, $post );

		if ( is_wp_error( $saved ) ) {
			return $this->discard_created( (int) $post_id, $saved );
		}

		$post = get_post( $post_id );

		if ( ! $post ) {
			// Do not leave a gallery behind that the caller was told nothing about.
			return $this->discard_created(
				(int) $post_id,
				new \WP_Error(
					'envira_create_failed',
					__( 'Gallery created but could not be retrieved.', 'envira-gallery-lite' ),
					[ 'status' => 500 ]
				)
			);
		}

		return $this->format( $post );
	}

	/**
	 * Update a gallery's title and/or publish status.
	 *
	 * @since 1.13.0
	 *
	 * @param int   $id     Gallery ID.
	 * @param array $fields {
	 *     Fields to change. Omitted keys are left alone.
	 *
	 *     @type string $title  New title.
	 *     @type string $status New publish status.
	 * }
	 * @return array|\WP_Error
	 */
	public function update( int $id, array $fields ) {
		$post = $this->get_editable_gallery_post( $id );

		if ( is_wp_error( $post ) ) {
			return $post;
		}

		$data = [ 'ID' => $id ];

		if ( isset( $fields['title'] ) ) {
			$data['post_title'] = sanitize_text_field( $fields['title'] );
		}

		if ( isset( $fields['status'] ) ) {
			$data['post_status'] = sanitize_key( $fields['status'] );
		}

		if ( count( $data ) <= 1 ) {
			return $this->format( $post );
		}

		// Read the gallery data before writing anything, so an unreadable row is
		// refused with the post untouched, as the error message says. A status-only
		// change is refused too: publishing a gallery whose data cannot be read
		// would put a gallery live that renders nothing.
		$readable = $this->read_data( $id );

		if ( is_wp_error( $readable ) ) {
			return $readable;
		}

		$gallery_data = isset( $data['post_title'] ) ? $readable : null;

		// Slashed for the same reason as in create().
		$result = wp_update_post( wp_slash( $data ), true );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$updated = get_post( $id );

		if ( ! $updated ) {
			return new \WP_Error(
				'envira_update_failed',
				__( 'Gallery updated but could not be retrieved.', 'envira-gallery-lite' ),
				[ 'status' => 500 ]
			);
		}

		// Keep config.title in sync with post_title, and route it through
		// save_data() so the rendered fragment is invalidated. A bare
		// update_post_meta() here left _eg_cache_* and _eg_fragment_* serving
		// the old title.
		if ( null !== $gallery_data ) {
			$gallery_data['config']['title'] = $data['post_title'];

			$saved = $this->save_data( $id, $gallery_data, $updated );

			if ( is_wp_error( $saved ) ) {
				// Put the post back, so a failed update changes nothing.
				$reverted = wp_update_post(
					wp_slash(
						[
							'ID'            => $id,
							'post_title'    => $post->post_title,
							'post_status'   => $post->post_status,
							// Publishing assigns a slug and a publish date; put those back too.
							'post_name'     => $post->post_name,
							'post_date'     => $post->post_date,
							'post_date_gmt' => $post->post_date_gmt,
							'edit_date'     => true,
						]
					),
					true
				);

				if ( is_wp_error( $reverted ) || ! $reverted ) {
					return new \WP_Error(
						'envira_update_failed',
						__( 'The gallery settings could not be saved, and its title and status could not be put back. Check the gallery in wp-admin.', 'envira-gallery-lite' ),
						[ 'status' => 500 ]
					);
				}

				return $saved;
			}
		} else {
			// Status-only change still invalidates the rendered output.
			$this->flush_caches( $id, $this->resolve_slug( $updated, [] ) );
		}

		return $this->format( $updated );
	}

	/**
	 * Delete or trash a gallery.
	 *
	 * @since 1.13.0
	 *
	 * @param int  $id    Gallery ID.
	 * @param bool $force True to delete permanently, false to trash.
	 * @return array|\WP_Error
	 */
	public function delete( int $id, bool $force = false ) {
		$post = $this->get_gallery_post( $id );

		if ( is_wp_error( $post ) ) {
			return $post;
		}

		// Refuse a gallery whose data cannot be read: the caller cannot know what
		// it is destroying, and every other write path refuses it too.
		$readable = $this->read_data( $id );

		if ( is_wp_error( $readable ) ) {
			return $readable;
		}

		// Read the slug before the post goes away — the cache keys need it.
		$stored = get_post_meta( $id, self::META_KEY, true );
		$slug   = is_array( $stored ) && ! empty( $stored['config']['slug'] )
			? (string) $stored['config']['slug']
			: (string) $post->post_name;

		// wp_delete_post() only routes to the trash for posts and pages, so for
		// the envira post type `force: false` would delete permanently. Trash it
		// explicitly. wp_trash_post() deletes outright when EMPTY_TRASH_DAYS is 0.
		$permanent = $force || ! EMPTY_TRASH_DAYS;

		// Already in the trash: finish the trash routine rather than report it.
		// A gallery trashed outside the admin container (WP-CLI, core REST) or by
		// an earlier envira_trash_incomplete never got its inactive flag.
		if ( ! $permanent && 'trash' === $post->post_status ) {
			$marked = $this->mark_trashed( $id );
			$this->flush_caches( $id, $slug );

			if ( ! $marked ) {
				return new \WP_Error(
					'envira_trash_incomplete',
					__( 'The gallery is in the trash, but could not be marked inactive.', 'envira-gallery-lite' ),
					[ 'status' => 500 ]
				);
			}

			return [
				'deleted' => true,
				'trashed' => true,
				'id'      => $id,
			];
		}

		// On a permanent delete the membership records go first, so a failure
		// leaves the gallery in place rather than deleted with stale records.
		$removed = [];

		if ( $permanent ) {
			$removed = $this->remove_image_memberships( $id, $stored );

			if ( ! is_array( $removed ) ) {
				return new \WP_Error(
					'envira_delete_failed',
					false === $removed
						? __( 'The gallery was not deleted: its image membership records could not be updated.', 'envira-gallery-lite' )
						: __( 'The gallery was not deleted, and some of its image membership records could not be put back. Re-save the gallery in wp-admin to repair them.', 'envira-gallery-lite' ),
					[ 'status' => 500 ]
				);
			}
		}

		$result = $permanent ? wp_delete_post( $id, true ) : wp_trash_post( $id );

		if ( ! $result ) {
			$restored = $this->restore_meta( $removed, '_eg_has_gallery' );

			return new \WP_Error(
				'envira_delete_failed',
				$restored
					? __( 'Failed to delete gallery.', 'envira-gallery-lite' )
					: __( 'Failed to delete gallery, and its image membership records could not be fully restored. Re-save the gallery in wp-admin to repair them.', 'envira-gallery-lite' ),
				[ 'status' => 500 ]
			);
		}

		if ( ! $permanent && ! $this->mark_trashed( $id ) ) {
			$this->flush_caches( $id, $slug );

			return new \WP_Error(
				'envira_trash_incomplete',
				__( 'The gallery was moved to the trash, but could not be marked inactive.', 'envira-gallery-lite' ),
				[ 'status' => 500 ]
			);
		}

		// Envira's own trash/delete handlers live on Admin_Container, which is
		// only instantiated under is_admin() || DOING_CRON. Abilities run on
		// REST and MCP requests, where is_admin() is false, so the flush has to
		// happen here or the deleted gallery keeps being served from cache.
		// Flushing is idempotent, so it is safe when those handlers did run.
		$this->flush_caches( $id, $slug );

		return [
			'deleted' => true,
			'trashed' => ! $permanent,
			'id'      => $id,
		];
	}

	/**
	 * Take a permanently deleted gallery off its images' _eg_has_gallery.
	 *
	 * The add_images() method writes this gallery's id onto each image's
	 * _eg_has_gallery, so removing the gallery has to take it back off. The only existing
	 * cleanup, delete_gallery_association(), is hooked on delete_attachment and
	 * neither fires here nor covers this direction. Leaving it behind points
	 * the Random widget's frontend meta_query and the Comments addon at a
	 * gallery that no longer exists. Not run on trash: untrash_gallery() never
	 * rebuilds this meta, so a restored gallery would lose it.
	 *
	 * @since 1.13.0
	 *
	 * @param int   $id     Gallery ID.
	 * @param mixed $stored The gallery's _eg_gallery_data before deletion.
	 * @return array|false|null Previous `_eg_has_gallery` values keyed by attachment
	 *                          ID; false when a write failed and was undone; null
	 *                          when a write failed and the undo failed too.
	 */
	private function remove_image_memberships( int $id, $stored ) {
		$images = is_array( $stored ) && isset( $stored['gallery'] ) && is_array( $stored['gallery'] ) ? array_keys( $stored['gallery'] ) : [];

		// _eg_in_gallery can name images the gallery data no longer holds (an image removed from the
		// data only); their _eg_has_gallery points here too.
		$in_gallery = get_post_meta( $id, '_eg_in_gallery', true );
		$images     = array_values( array_unique( array_map( 'intval', array_merge( $images, is_array( $in_gallery ) ? $in_gallery : [] ) ) ) );
		$changed    = [];

		foreach ( $images as $attachment_id ) {
			$attachment_id = (int) $attachment_id;
			$has_gallery   = get_post_meta( $attachment_id, '_eg_has_gallery', true );

			if ( ! is_array( $has_gallery ) || ! in_array( $id, array_map( 'intval', $has_gallery ), true ) ) {
				continue;
			}

			$remaining = array_values( array_diff( array_map( 'intval', $has_gallery ), [ $id ] ) );

			if ( ! $this->write_meta( $attachment_id, '_eg_has_gallery', $remaining ? $remaining : '' ) ) {
				// The caller refuses the delete either way. A failed undo leaves an
				// image without this gallery's id while the gallery still holds it,
				// so delete_gallery_association() would miss it: say so.
				$restored = $this->restore_meta( $changed, '_eg_has_gallery' );

				return $restored ? false : null;
			}

			$changed[ $attachment_id ] = $has_gallery;
		}

		return $changed;
	}

	/**
	 * Write a post meta value and confirm it landed.
	 *
	 * The update_post_meta() function returns false both on failure and when
	 * the value is unchanged, so its return cannot tell the two apart. Read the
	 * value back instead. An empty string deletes the key.
	 *
	 * @since 1.13.0
	 *
	 * @param int    $object_id Post ID.
	 * @param string $key       Meta key.
	 * @param mixed  $value     Value, or '' to delete.
	 * @return bool True when the stored value now matches.
	 */
	private function write_meta( int $object_id, string $key, $value ): bool {
		if ( '' === $value ) {
			delete_post_meta( $object_id, $key );

			return '' === get_post_meta( $object_id, $key, true );
		}

		update_post_meta( $object_id, $key, $value );

		return get_post_meta( $object_id, $key, true ) === $value;
	}

	/**
	 * Put back meta values recorded before a multi-record write.
	 *
	 * @since 1.13.0
	 *
	 * @param array  $previous Previous values keyed by post ID ('' = was absent).
	 * @param string $key      Meta key.
	 * @return bool True when every value was restored.
	 */
	private function restore_meta( $previous, string $key ): bool {
		$ok = true;

		foreach ( (array) $previous as $object_id => $value ) {
			$ok = $this->write_meta( (int) $object_id, $key, $value ) && $ok;
		}

		return $ok;
	}

	/**
	 * Run the gallery trash routine when wp-admin's handler did not.
	 *
	 * The admin trash_gallery() handler sets `status => inactive`, which the shortcode checks
	 * before rendering, and fires envira_gallery_trash for addons. It is hooked
	 * on wp_trash_post only where the admin container loads (is_admin() or
	 * cron), which REST and MCP requests are not.
	 *
	 * @since 1.13.0
	 *
	 * @param int $id Gallery ID.
	 * @return bool False when the inactive flag could not be stored.
	 */
	private function mark_trashed( int $id ) {
		$gallery_data = get_post_meta( $id, self::META_KEY, true );

		if ( ! is_array( $gallery_data ) || ( isset( $gallery_data['status'] ) && 'inactive' === $gallery_data['status'] ) ) {
			return true;
		}

		$gallery_data['status'] = 'inactive';
		update_post_meta( $id, self::META_KEY, wp_slash( $gallery_data ) );

		$stored = get_post_meta( $id, self::META_KEY, true );

		if ( ! is_array( $stored ) || 'inactive' !== ( $stored['status'] ?? '' ) ) {
			return false;
		}

		/** This action is documented in the admin trash_gallery() handler. */
		do_action( 'envira_gallery_trash', $id, $gallery_data );

		return true;
	}

	/**
	 * Add media library images to a gallery.
	 *
	 * @since 1.13.0
	 *
	 * @param int   $id             Gallery ID.
	 * @param int[] $attachment_ids Attachment IDs to add.
	 * @return array|\WP_Error {
	 *     Gallery plus the outcome per supplied ID.
	 *
	 *     @type array $gallery Normalised gallery.
	 *     @type int[] $added   IDs actually added.
	 *     @type int[] $skipped IDs dropped — not an attachment, or not readable by this account.
	 *     @type int[] $existing IDs already in the gallery, left untouched.
	 * }
	 */
	public function add_images( int $id, array $attachment_ids ) {
		$post = $this->get_editable_gallery_post( $id );

		if ( is_wp_error( $post ) ) {
			return $post;
		}

		$attachment_ids = array_filter( array_map( 'absint', $attachment_ids ) );

		if ( empty( $attachment_ids ) ) {
			return new \WP_Error(
				'envira_no_images',
				__( 'No valid attachment IDs provided.', 'envira-gallery-lite' ),
				[ 'status' => 400 ]
			);
		}

		$gallery_data = $this->read_data( $id );

		if ( is_wp_error( $gallery_data ) ) {
			return $gallery_data;
		}

		$added       = [];
		$skipped     = [];
		$existing    = [];
		$memberships = [];

		foreach ( $attachment_ids as $attachment_id ) {
			// Never rewrite an image that is already in the gallery: its record
			// holds the title, caption, alt, link and tags the site owner set.
			// Same guard as envira_gallery_ajax_insert_images() (#95).
			if ( isset( $gallery_data['gallery'][ $attachment_id ] ) ) {
				$existing[] = $attachment_id;
				continue;
			}

			$attachment = get_post( $attachment_id );

			if ( ! $attachment || 'attachment' !== $attachment->post_type ) {
				$skipped[] = $attachment_id;
				continue;
			}

			// Authorize the source, not just the destination gallery, using the
			// plugin's own rule for this operation rather than a looser one.
			// envira_gallery_ajax_insert_images() applies exactly this guard
			// (includes/admin/ajax.php), added by the security hardening that
			// closed the Author-level write paths — so the two insert surfaces
			// agree. `read_post` would have been weaker: it passes for any
			// attachment whose parent is published.
			if (
				(int) $attachment->post_author !== get_current_user_id()
				&& ! current_user_can( 'edit_others_posts' )
			) {
				$skipped[] = $attachment_id;
				continue;
			}

			$src = esc_url_raw( (string) wp_get_attachment_url( $attachment_id ) );
			$alt = get_post_meta( $attachment_id, '_wp_attachment_image_alt', true );

			// thumb is left empty, matching envira_gallery_ajax_prepare_gallery_data().
			// It is NOT populated by envira_gallery_saved_settings: the only
			// listeners on that action in the tree are the AI description
			// scheduler and envira-albums' cache clear. Nothing crops here.
			// Normalized at write time, matching the canonical save paths, so
			// render-time escaping encodes once. The helper this mirrors is the
			// one writer the team's convergence fixes have not reached yet.
			$gallery_data['gallery'][ $attachment_id ] = [
				'status'  => 'active',
				'src'     => $src,
				'title'   => wp_kses_post( (string) $attachment->post_title ),
				'link'    => $src,
				'alt'     => $alt ? sanitize_text_field( (string) $alt ) : '',
				'caption' => wp_kses_post( (string) $attachment->post_excerpt ),
				'thumb'   => '',
				'tags'    => [],
			];

			// Gallery membership is recorded in three places, and writing only
			// the _eg_gallery_data copy is a known defect class (#5619, #4692):
			// delete_gallery_association() reads _eg_has_gallery to clean up when
			// an attachment is deleted, and the media modal's dedupe reads
			// _eg_in_gallery. Written before save_data(), below, and undone if the
			// save fails.
			$has_gallery = get_post_meta( $attachment_id, '_eg_has_gallery', true );
			$has_gallery = is_array( $has_gallery ) ? $has_gallery : [];

			if ( ! in_array( $id, array_map( 'intval', $has_gallery ), true ) ) {
				$has_gallery[]                 = $id;
				$memberships[ $attachment_id ] = $has_gallery;
			}

			$added[] = $attachment_id;
		}

		if ( ! $added && $existing ) {
			// Nothing new to add; the ids already present are not an error.
			return [
				'gallery'  => $this->format( $post ),
				'added'    => [],
				'skipped'  => array_values( $skipped ),
				'existing' => array_values( $existing ),
			];
		}

		if ( ! $added ) {
			return new \WP_Error(
				'envira_no_images',
				__( 'None of the supplied IDs are media library attachments this account may use.', 'envira-gallery-lite' ),
				[ 'status' => 400 ]
			);
		}

		// _eg_in_gallery post meta is the authoritative list; every canonical
		// writer read-modify-writes it. The in_gallery key inside
		// _eg_gallery_data is not maintained by them, so it cannot be the source.
		$in_gallery = get_post_meta( $id, '_eg_in_gallery', true );
		$in_gallery = is_array( $in_gallery ) ? array_map( 'intval', $in_gallery ) : [];

		foreach ( $added as $attachment_id ) {
			if ( ! in_array( $attachment_id, $in_gallery, true ) ) {
				$in_gallery[] = $attachment_id;
			}
		}

		$gallery_data['in_gallery'] = $in_gallery;

		// Membership first, then the gallery, so the save hooks only ever see a
		// write that stays. Each write is read back (update_post_meta() returns
		// false for an unchanged value too) and every step is undone on failure,
		// so the three membership records never end up out of step.
		$previous_in = get_post_meta( $id, '_eg_in_gallery', true );
		$written     = [];
		$failed      = ! $this->write_meta( $id, '_eg_in_gallery', $in_gallery );

		foreach ( $failed ? [] : $memberships as $attachment_id => $has_gallery ) {
			$previous = get_post_meta( $attachment_id, '_eg_has_gallery', true );

			if ( ! $this->write_meta( $attachment_id, '_eg_has_gallery', $has_gallery ) ) {
				$failed = true;
				break;
			}

			$written[ $attachment_id ] = $previous;
		}

		$saved = $failed ? null : $this->save_data( $id, $gallery_data, $post );

		if ( $failed || is_wp_error( $saved ) ) {
			$restored = $this->restore_meta( $written, '_eg_has_gallery' )
				&& $this->restore_meta( [ $id => $previous_in ], '_eg_in_gallery' );

			if ( is_wp_error( $saved ) && $restored ) {
				return $saved;
			}

			return new \WP_Error(
				'envira_membership_failed',
				$restored
					? __( 'The images could not be added: the gallery membership records could not be written, so nothing was changed.', 'envira-gallery-lite' )
					: __( 'The images could not be added, and the gallery membership records could not be fully restored. Re-save the gallery in wp-admin to repair them.', 'envira-gallery-lite' ),
				[ 'status' => 500 ]
			);
		}

		// Report the partial case explicitly. Returning a bare gallery made a
		// partial write indistinguishable from a complete one, so a caller that
		// asked for five images and got three had no way to tell.
		return [
			'gallery'  => $this->format( $post ),
			'added'    => $added,
			'skipped'  => array_values( $skipped ),
			'existing' => array_values( $existing ),
		];
	}

	// -------------------------------------------------------------------------
	// Formatting
	// -------------------------------------------------------------------------

	/**
	 * Normalise a gallery WP_Post into a consistent array.
	 *
	 * @since 1.13.0
	 *
	 * @param \WP_Post $post Gallery post object.
	 * @return array
	 */
	public function format( \WP_Post $post ): array {
		$gallery_data = get_post_meta( $post->ID, self::META_KEY, true );
		// Only arrays are counted or passed on: one partly corrupt gallery (e.g. `gallery` saved as
		// a string) would otherwise throw a TypeError in count() or the array-typed resolvers and
		// take down the whole list-galleries response.
		$images = is_array( $gallery_data ) && isset( $gallery_data['gallery'] ) && is_array( $gallery_data['gallery'] ) ? $gallery_data['gallery'] : [];
		$config = is_array( $gallery_data ) && isset( $gallery_data['config'] ) && is_array( $gallery_data['config'] ) ? $gallery_data['config'] : [];

		$gallery = [
			'id'          => $post->ID,
			'title'       => $post->post_title,
			'slug'        => $this->resolve_slug( $post, $config ),
			'status'      => $post->post_status,
			// Omitted rather than emitted empty when unresolvable: '' is not a
			// valid date-time and would fail the ability's own output schema
			// after the write had already landed. Neither field is required.
			'created'     => $this->to_rfc3339( $post->post_date_gmt, $post->post_date ),
			'modified'    => $this->to_rfc3339( $post->post_modified_gmt, $post->post_modified ),
			'url'         => (string) get_permalink( $post->ID ),
			// get_edit_post_link() returns null for a caller who cannot edit the
			// post, and the output schema declares these as strings — a null
			// failed validation with ability_invalid_output *after* the gallery
			// had already been written.
			'edit_url'    => (string) get_edit_post_link( $post->ID, 'raw' ),
			'image_count' => count( $images ),
			'layout'      => $this->resolve_layout( $config ),
		];

		foreach ( [ 'created', 'modified' ] as $field ) {
			if ( '' === $gallery[ $field ] ) {
				unset( $gallery[ $field ] );
			}
		}

		return $gallery;
	}

	/**
	 * Return every available layout key.
	 *
	 * Lite ships a single automatic layout; the alternatives are a Pro feature,
	 * which is also why Lite registers no set-gallery-layout ability.
	 *
	 * @since 1.13.0
	 *
	 * @return string[]
	 */
	public static function get_layout_keys(): array {
		return [ 'automatic' ];
	}

	// -------------------------------------------------------------------------
	// Internals
	// -------------------------------------------------------------------------

	/**
	 * Remove a gallery create() could not finish, and say if that failed too.
	 *
	 * @since 1.13.0
	 *
	 * @param int       $post_id The partly created gallery.
	 * @param \WP_Error $error   Why create() stopped.
	 * @return \WP_Error
	 */
	private function discard_created( int $post_id, \WP_Error $error ): \WP_Error {
		if ( wp_delete_post( $post_id, true ) ) {
			return $error;
		}

		return new \WP_Error(
			$error->get_error_code(),
			sprintf(
				/* translators: 1: original error message, 2: gallery ID. */
				__( '%1$s The partly created gallery (ID %2$d) could not be removed.', 'envira-gallery-lite' ),
				$error->get_error_message(),
				$post_id
			),
			[ 'status' => 500 ]
		);
	}

	/**
	 * Clear the inactive flag when a gallery leaves the trash outside wp-admin.
	 *
	 * Trashing through the abilities sets `status => inactive` on every
	 * request type, but the admin untrash_gallery() handler that clears it
	 * only loads under is_admin() or cron. A WP-CLI or core REST untrash would
	 * otherwise restore a gallery that never renders. Hooked on untrashed_post,
	 * which fires only once the post is actually out of the trash, and after
	 * the admin untrash_post handler, so in wp-admin it finds nothing to do.
	 *
	 * @since 1.13.0
	 *
	 * @param int $post_id Post being untrashed.
	 * @return void
	 */
	public static function untrash_outside_admin( $post_id ) {
		if ( self::POST_TYPE !== get_post_type( $post_id ) ) {
			return;
		}

		$gallery_data = get_post_meta( (int) $post_id, self::META_KEY, true );

		if ( ! is_array( $gallery_data ) || ! isset( $gallery_data['status'] ) || 'inactive' !== $gallery_data['status'] ) {
			return;
		}

		unset( $gallery_data['status'] );
		update_post_meta( (int) $post_id, self::META_KEY, wp_slash( $gallery_data ) );

		// Only announce the untrash once the flag is really gone. A hook has no
		// caller to report to, so a failure goes to the debug log.
		$stored = get_post_meta( (int) $post_id, self::META_KEY, true );

		if ( ! is_array( $stored ) || isset( $stored['status'] ) ) {
			error_log( sprintf( 'Envira Gallery: gallery %d was restored from the trash but is still marked inactive; re-save it in wp-admin.', (int) $post_id ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log

			return;
		}

		// A method in Lite, a function in Pro (see flush_caches()).
		if ( class_exists( 'Envira_Gallery_Common' ) ) {
			$slug = ! empty( $stored['config']['slug'] ) ? (string) $stored['config']['slug'] : (string) get_post_field( 'post_name', (int) $post_id );
			\Envira_Gallery_Common::get_instance()->flush_gallery_caches( (int) $post_id, $slug );
		}

		/** This action is documented in the admin untrash_gallery() handler. */
		do_action( 'envira_gallery_untrash', (int) $post_id, $gallery_data );
	}

	/**
	 * Fetch a gallery that may be edited: not in the trash.
	 *
	 * The wp-admin editor refuses a trashed post (wp-admin/post.php). Editing one
	 * here bypassed the untrash routine: update() could set post_status out of
	 * the trash while _eg_gallery_data kept `status => inactive`, so the gallery
	 * reported "publish" and rendered nothing. Restoring is wp-admin's job.
	 *
	 * @since 1.13.0
	 *
	 * @param int $id Gallery ID.
	 * @return \WP_Post|\WP_Error
	 */
	private function get_editable_gallery_post( int $id ) {
		$post = $this->get_gallery_post( $id );

		if ( ! is_wp_error( $post ) && 'trash' === $post->post_status ) {
			return new \WP_Error(
				'envira_gallery_trashed',
				__( 'This gallery is in the trash. Restore it in wp-admin before editing it.', 'envira-gallery-lite' ),
				[ 'status' => 409 ]
			);
		}

		return $post;
	}

	/**
	 * Fetch a post and confirm it is a gallery.
	 *
	 * @since 1.13.0
	 *
	 * @param int $id Gallery ID.
	 * @return \WP_Post|\WP_Error
	 */
	private function get_gallery_post( int $id ) {
		$post = $id > 0 ? get_post( $id ) : null;

		if ( ! $post || self::POST_TYPE !== $post->post_type ) {
			return new \WP_Error(
				'envira_not_found',
				__( 'Gallery not found.', 'envira-gallery-lite' ),
				[ 'status' => 404 ]
			);
		}

		return $post;
	}

	/**
	 * Read the gallery payload, repairing any missing structure.
	 *
	 * Returns a WP_Error when the stored value exists but cannot be read, so no
	 * caller can overwrite an unreadable row with empty defaults.
	 *
	 * @since 1.13.0
	 *
	 * @param int $id Gallery ID.
	 * @return array|\WP_Error
	 */
	private function read_data( int $id ) {
		$gallery_data = get_post_meta( $id, self::META_KEY, true );

		// Genuinely absent: get_post_meta() returns '' for a missing key. Only
		// this case may be bootstrapped.
		if ( '' === $gallery_data || null === $gallery_data || [] === $gallery_data ) {
			return $this->bootstrap_data( $id );
		}

		// Present but unreadable — get_post_meta() hands back the raw string when
		// the stored value fails to unserialize (a truncated longtext after a bad
		// import, a mangled migration). Bootstrapping here would look like a
		// repair and then be written straight back over the real row by
		// save_data(), destroying every image record. Issue #5568 warns that the
		// obvious is_array() patch "converts a loud fatal into quiet data loss".
		// Refuse the write instead, so the row stays recoverable.
		if ( ! is_array( $gallery_data ) ) {
			return new \WP_Error(
				'envira_unreadable_gallery_data',
				__( 'This gallery&#8217;s stored data could not be read, so it was left unchanged. The record may be corrupt and needs repairing before the gallery can be edited.', 'envira-gallery-lite' ),
				[ 'status' => 500 ]
			);
		}

		$gallery_data['id'] = $gallery_data['id'] ?? $id;

		foreach ( [ 'gallery', 'in_gallery', 'config' ] as $key ) {
			// Absent is fine — a new or partially built gallery. Present but the
			// wrong type is the partial-corruption case from #5568, and resetting
			// it to [] here would be persisted by save_data(), deleting every
			// image, caption and sort order. Same refusal as the root guard.
			if ( ! isset( $gallery_data[ $key ] ) ) {
				$gallery_data[ $key ] = [];
				continue;
			}

			if ( ! is_array( $gallery_data[ $key ] ) ) {
				return new \WP_Error(
					'envira_unreadable_gallery_data',
					__( 'This gallery&#8217;s stored data could not be read, so it was left unchanged. The record may be corrupt and needs repairing before the gallery can be edited.', 'envira-gallery-lite' ),
					[ 'status' => 500 ]
				);
			}
		}

		return $gallery_data;
	}

	/**
	 * Build a fresh gallery payload.
	 *
	 * @since 1.13.0
	 *
	 * @param int $id Gallery ID.
	 * @return array
	 */
	private function bootstrap_data( int $id ): array {
		// Lite exposes the defaults as a method on Envira_Gallery_Common rather
		// than the envira_get_config_defaults() function Pro defines.
		$defaults = class_exists( 'Envira_Gallery_Common' )
			? \Envira_Gallery_Common::get_instance()->get_config_defaults( $id )
			: [];
		$defaults = is_array( $defaults ) ? $defaults : [];

		// An empty config is the shape behind the #5534 / #5542 warning storms:
		// the renderers read config.type without a guard in places, so a gallery
		// minted without it emits warnings per image into page output. The guard
		// above is deliberate (this class is the shared writer for Lite too), so
		// supply a minimum viable config rather than storing nothing.
		if ( empty( $defaults ) ) {
			$defaults = [
				'type'    => 'default',
				'layout'  => 'automatic',
				'columns' => '4',
				'slug'    => '',
				'title'   => '',
			];
		}

		return [
			'id'         => $id,
			'gallery'    => [],
			'in_gallery' => [],
			'config'     => $defaults,
		];
	}

	/**
	 * Persist the gallery payload and fire the usual save side effects.
	 *
	 * @since 1.13.0
	 *
	 * @param int      $id           Gallery ID.
	 * @param array    $gallery_data Payload to store.
	 * @param \WP_Post $post         Gallery post object.
	 * @return true|\WP_Error
	 */
	private function save_data( int $id, array $gallery_data, \WP_Post $post ) {
		$before = get_post_meta( $id, self::META_KEY, true );

		// update_post_meta() also returns false for a genuine no-op, so only
		// treat false as a failure when the payload actually differs. Otherwise
		// the save hooks below would fire, and a success payload be returned,
		// for a write that never landed.
		// wp_slash(): update_post_meta() unslashes its value, which would strip
		// every backslash from titles and captions, and make re-saving one that
		// is already stored look like a failed write.
		if ( ! update_post_meta( $id, self::META_KEY, wp_slash( $gallery_data ) ) && $before !== $gallery_data ) {
			return new \WP_Error(
				'envira_save_failed',
				__( 'The gallery could not be saved.', 'envira-gallery-lite' ),
				[ 'status' => 500 ]
			);
		}

		do_action( 'envira_gallery_saved_settings', $gallery_data, $id, $post );

		// resolve_slug(), not config['slug'] — create() never populates the config
		// slug (its default is ''), and flush_gallery_caches() silently skips the
		// slug transient when it is empty. The shortcode caches under post_name,
		// so an ability-created gallery kept serving stale HTML after every write.
		$this->flush_caches( $id, $this->resolve_slug( $post, $gallery_data['config'] ?? [] ) );

		return true;
	}

	/**
	 * Flush a gallery's caches.
	 *
	 * Isolated so every write path shares one implementation. Stale fragments
	 * after a programmatic write are a recurring defect here (ce8f0b23e,
	 * 78d156870, 45f08904c), and issue #5594 reports that the customer-facing
	 * remedy under Tools does nothing, so a write path that forgets to flush
	 * leaves no workaround.
	 *
	 * @since 1.13.0
	 *
	 * @param int    $id   Gallery ID.
	 * @param string $slug Gallery slug, when known.
	 * @return void
	 */
	private function flush_caches( int $id, string $slug = '' ) {
		// Same story as the config defaults: a method in Lite, a function in Pro.
		if ( class_exists( 'Envira_Gallery_Common' ) ) {
			\Envira_Gallery_Common::get_instance()->flush_gallery_caches( $id, $slug );
		}
	}

	/**
	 * Resolve the layout key a gallery is currently using.
	 *
	 * @since 1.13.0
	 *
	 * @param array $config Gallery config.
	 * @return string
	 */
	private function resolve_layout( array $config ): string {
		// Lite is automatic-only, and the ability's own output schema declares
		// the enum as get_layout_keys(), so reporting a Pro-imported config.type
		// would fail that schema. This is a product limit, not a swallowed error.
		unset( $config );

		return 'automatic';
	}

	/**
	 * Resolve a usable slug for a gallery.
	 *
	 * `post_name` is empty until a post is first published, so fall back to the
	 * gallery config slug and then to the slug WordPress would assign, rather
	 * than returning an empty string for every draft.
	 *
	 * @since 1.13.0
	 *
	 * @param \WP_Post $post   Gallery post object.
	 * @param array    $config Gallery config.
	 * @return string
	 */
	private function resolve_slug( \WP_Post $post, array $config ): string {
		if ( ! empty( $post->post_name ) ) {
			return $post->post_name;
		}

		if ( ! empty( $config['slug'] ) ) {
			return (string) $config['slug'];
		}

		return sanitize_title( $post->post_title );
	}

	/**
	 * Format a stored date as RFC 3339 UTC.
	 *
	 * WordPress leaves the `_gmt` columns at '0000-00-00 00:00:00' until a post
	 * is published, so drafts have to be converted from the local column instead.
	 * Returns an empty string only when both columns are unusable.
	 *
	 * @since 1.13.0
	 *
	 * @param string $gmt   Value of the `_gmt` column.
	 * @param string $local Value of the local column.
	 * @return string
	 */
	private function to_rfc3339( string $gmt, string $local ): string {
		$zeroed = '0000-00-00 00:00:00';

		$utc = '';

		if ( ! empty( $gmt ) && $zeroed !== $gmt ) {
			$utc = $gmt;
		} elseif ( ! empty( $local ) && $zeroed !== $local ) {
			$utc = get_gmt_from_date( $local );
		}

		if ( '' === $utc ) {
			return '';
		}

		// Matches what mysql_to_rfc3339() emits — that function name trips the
		// PHPCompatibility mysql_ extension sniff, so build the string directly.
		$timestamp = strtotime( $utc . ' UTC' );

		return false === $timestamp ? '' : gmdate( 'Y-m-d\TH:i:s', $timestamp );
	}
}
