<?php
/**
 * Envira Access Capabilities — Lite implementation.
 *
 * @since 1.13.0
 *
 * @package Envira_Gallery
 */

namespace Envira\Access;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Capabilities — single entry point for all Envira permission checks.
 *
 * Pro and Lite each ship their own Envira\Access\Capabilities. Lite's registry
 * methods return hardcoded arrays; Pro's are filterable via apply_filters().
 *
 * @since 1.13.0
 */
class Capabilities {

	/**
	 * Singleton instance.
	 *
	 * @since 1.13.0
	 *
	 * @var self
	 */
	private static $instance;

	/**
	 * Return the singleton.
	 *
	 * @since 1.13.0
	 *
	 * @return self
	 */
	public static function instance(): self {
		if ( ! isset( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Register hooks — called once during plugin boot.
	 *
	 * @since 1.13.0
	 *
	 * @return void
	 */
	public function init() {
		$this->hooks();
	}

	/**
	 * Register WordPress hooks.
	 *
	 * @since 1.13.0
	 *
	 * @return void
	 */
	private function hooks() {
		// Guard prevents double-registration if init() is called more than once.
		if ( ! has_filter( 'map_meta_cap', [ $this, 'filter_map_meta_cap' ] ) ) {
			add_filter( 'map_meta_cap', [ $this, 'filter_map_meta_cap' ], 10, 4 );
		}
	}

	/**
	 * Check whether the current user has at least one of the given capabilities.
	 *
	 * @since 1.13.0
	 *
	 * @param array|string $caps Capability name(s) or 'any'.
	 * @param int          $id   Optional gallery ID for meta-cap checks.
	 * @return bool
	 */
	public function current_user_can( $caps = [], int $id = 0 ): bool {
		return (bool) $this->get_first_valid_cap( $caps, $id );
	}

	/**
	 * Return the first menu-gate capability string from a set of capability names.
	 *
	 * Falls back to envira_get_capability_manage_options() if none resolve.
	 *
	 * @since 1.13.0
	 *
	 * @param array $caps Capability names to check.
	 * @return string
	 */
	public function get_menu_cap( array $caps ): string {
		$cap = $this->get_first_valid_cap( $caps );
		return $cap ? $cap : envira_get_capability_manage_options();
	}

	/**
	 * Registered primitive capabilities.
	 *
	 * Keys are new-format cap names; values are human-readable labels.
	 * Actual role checks use get_legacy_cap() to translate to old-format names.
	 *
	 * @since 1.13.0
	 *
	 * @return array<string, string>
	 */
	public function get_caps(): array {
		// Cache per-request.
		static $cached = null;
		if ( null !== $cached ) {
			return $cached;
		}
		$cached = [
			'envira_create_galleries'           => __( 'Create Galleries', 'envira-gallery-lite' ),
			'envira_edit_galleries'             => __( 'Edit Own Galleries', 'envira-gallery-lite' ),
			'envira_edit_others_galleries'      => __( "Edit Others' Galleries", 'envira-gallery-lite' ),
			'envira_publish_galleries'          => __( 'Publish Galleries', 'envira-gallery-lite' ),
			'envira_read_private_galleries'     => __( 'Read Private Galleries', 'envira-gallery-lite' ),
			'envira_delete_galleries'           => __( 'Delete Own Galleries', 'envira-gallery-lite' ),
			'envira_delete_others_galleries'    => __( "Delete Others' Galleries", 'envira-gallery-lite' ),
			'envira_edit_published_galleries'   => __( 'Edit Published Galleries', 'envira-gallery-lite' ),
			'envira_edit_private_galleries'     => __( 'Edit Private Galleries', 'envira-gallery-lite' ),
			'envira_delete_published_galleries' => __( 'Delete Published Galleries', 'envira-gallery-lite' ),
			'envira_delete_private_galleries'   => __( 'Delete Private Galleries', 'envira-gallery-lite' ),
		];
		return $cached;
	}

	/**
	 * Meta capability → ownership-based primitive resolution.
	 *
	 * Phase 1: resolves to OLD-format primitives so WP role checks hit the caps
	 * that Permissions UI manages. Phase 2: change values to new-format primitives
	 * after register_capabilities() is implemented.
	 *
	 * @since 1.13.0
	 *
	 * @return array<string, array{own: string, others: string, published?: string, private?: string}>
	 */
	public function get_meta_caps(): array {
		static $cached = null;
		if ( null !== $cached ) {
			return $cached;
		}
		$cached = [
			'envira_edit_gallery'   => [
				'own'       => 'edit_envira_galleries',
				'others'    => 'edit_others_envira_galleries',
				'published' => 'edit_published_envira_galleries',
				'private'   => 'edit_private_envira_galleries',
			],
			'envira_delete_gallery' => [
				'own'       => 'delete_envira_galleries',
				'others'    => 'delete_others_envira_galleries',
				'published' => 'delete_published_envira_galleries',
				'private'   => 'delete_private_envira_galleries',
			],
			'envira_read_gallery'   => [
				'own'    => 'read',
				'others' => 'read_private_envira_galleries',
			],
		];
		return $cached;
	}

	/**
	 * Return a single meta-cap entry, or null if the cap is not a registered meta cap.
	 *
	 * @since 1.13.0
	 *
	 * @param string $meta_cap Capability name to look up.
	 * @return array{own: string, others: string, published?: string, private?: string}|null
	 */
	public function get_meta_cap( string $meta_cap ) {
		return isset( $this->get_meta_caps()[ $meta_cap ] ) ? $this->get_meta_caps()[ $meta_cap ] : null;
	}

	/**
	 * Category capability → array of primitives (any-of check).
	 *
	 * @since 1.13.0
	 *
	 * @return array<string, string[]>
	 */
	public function get_category_caps(): array {
		static $cached = null;
		if ( null !== $cached ) {
			return $cached;
		}
		$cached = [
			'create_galleries' => [ 'envira_create_galleries' ],
			'edit_galleries'   => [ 'envira_edit_galleries', 'envira_edit_others_galleries' ],
			'delete_galleries' => [ 'envira_delete_galleries', 'envira_delete_others_galleries' ],
		];
		return $cached;
	}

	/**
	 * From a cap value ('any', string, or array), return the first passing cap string.
	 *
	 * Returns '' if none pass.
	 *
	 * @since 1.13.0
	 *
	 * @param array|string $caps Capability name(s) or 'any'.
	 * @param int          $id   Optional gallery ID.
	 * @return string
	 */
	protected function get_first_valid_cap( $caps, int $id = 0 ): string {
		$manage_cap = envira_get_capability_manage_options();

		// Admins bypass all checks.
		if ( current_user_can( $manage_cap ) ) {
			return $manage_cap;
		}

		if ( empty( $caps ) ) {
			return '';
		}

		// 'any' → expand to all registered primitives.
		if ( 'any' === $caps ) {
			$caps = array_keys( $this->get_caps() );
		}

		foreach ( (array) $caps as $cap ) {
			$validated = $this->validate( $cap, $id );
			if ( $validated ) {
				return $validated;
			}
		}

		return '';
	}

	/**
	 * Resolve and validate a single capability string.
	 *
	 * Resolution order:
	 * 1. Meta cap with $id  → resolve via filter_map_meta_cap hook, return primitive.
	 * 2. Category cap       → recurse through get_first_valid_cap().
	 * 3. Primitive cap      → normalize via maybe_prefix_cap(), check via get_legacy_cap() bridge.
	 *
	 * Returns resolved cap string on success, '' on failure.
	 * Meta caps called WITHOUT a $id always return '' — an ID is required for ownership resolution.
	 *
	 * @since 1.13.0
	 *
	 * @param string $cap Capability name to validate.
	 * @param int    $id  Optional gallery ID.
	 * @return string
	 */
	protected function validate( string $cap, int $id = 0 ): string {
		// Meta cap path — only when an ID is provided.
		if ( ! empty( $id ) ) {
			$meta_cap = $this->get_meta_cap( $cap );
			if ( ! empty( $meta_cap ) ) {
				// current_user_can fires filter_map_meta_cap, which maps to old primitive.
				// WP then checks that old primitive on the role.
				if ( current_user_can( $cap, $id ) ) {
					$resolved = map_meta_cap( $cap, get_current_user_id(), $id );
					return ! empty( $resolved[0] ) ? $resolved[0] : '';
				}
				return '';
			}
		}

		// Category cap path.
		$category_caps = $this->get_category_caps()[ $cap ] ?? [];
		if ( ! empty( $category_caps ) ) {
			return $this->get_first_valid_cap( $category_caps, $id );
		}

		// Primitive cap path.
		$cap = $this->maybe_prefix_cap( $cap ); // normalize to new format.

		if ( ! array_key_exists( $cap, $this->get_caps() ) ) {
			return ''; // Not a registered Envira cap.
		}

		// Bridge: check old-format cap that the Permissions UI manages.
		$legacy = $this->get_legacy_cap( $cap );
		$check  = $legacy ? $legacy : $cap; // Fall back to new-format if no legacy equivalent.

		return current_user_can( $check ) ? $check : '';
	}

	/**
	 * Resolve Envira meta caps to old-format primitives via WP map_meta_cap filter.
	 *
	 * Phase 1: returns old-format so WP role checks hit caps Permissions UI manages.
	 * Phase 2: returns new-format after register_capabilities() lands.
	 *
	 * @since 1.13.0
	 *
	 * @param array  $caps    WP-accumulated capability array.
	 * @param string $cap     The capability being checked.
	 * @param int    $user_id User ID performing the check.
	 * @param array  $args    Extra arguments (args[0] = gallery ID).
	 * @return array
	 */
	public function filter_map_meta_cap( array $caps, string $cap, int $user_id, array $args ): array {
		$meta_cap = $this->get_meta_cap( $cap );

		if ( empty( $meta_cap ) ) {
			return $caps; // Not an Envira meta cap — pass through unchanged.
		}

		$gallery_id = isset( $args[0] ) ? absint( $args[0] ) : 0;

		if ( ! $gallery_id ) {
			return $caps;
		}

		$gallery = get_post( $gallery_id );

		if ( ! $gallery || 'envira' !== $gallery->post_type ) {
			return $caps;
		}

		$mapped = ( (int) $user_id === (int) $gallery->post_author )
			? $meta_cap['own']
			: $meta_cap['others'];

		if ( ! $mapped ) {
			return $caps;
		}

		$required = [ $mapped ];

		// Mirror core's post meta-cap logic: ownership alone is not enough on a
		// gallery that is already published or private. Without this the caps
		// resolved here are laxer than the ones wp-admin enforces through the
		// post type's own capability map, so a contributor who may not edit
		// their own published post could still edit their own published gallery
		// through any caller that routes via these new cap names.
		$status_cap = $this->get_status_cap( $meta_cap, $this->get_effective_status( $gallery ) );

		if ( $status_cap ) {
			$required[] = $status_cap;
		}

		return $required;
	}

	/**
	 * Return the status a gallery's caps are resolved against.
	 *
	 * A trashed gallery is judged by the status it had before it was trashed,
	 * as core's map_meta_cap() does, so trashing a published gallery does not
	 * let an owner without the published cap delete or restore it.
	 *
	 * @since 1.13.0
	 *
	 * @param \WP_Post $gallery Gallery post.
	 * @return string
	 */
	private function get_effective_status( \WP_Post $gallery ): string {
		if ( 'trash' !== $gallery->post_status ) {
			return (string) $gallery->post_status;
		}

		return (string) get_post_meta( $gallery->ID, '_wp_trash_meta_status', true );
	}

	/**
	 * Return the published/private primitive that applies to a gallery's status.
	 *
	 * @since 1.13.0
	 *
	 * @param array  $meta_cap    The meta-cap registry entry being resolved.
	 * @param string $post_status The gallery's post status.
	 * @return string Capability name, or '' when the status needs no extra cap.
	 */
	private function get_status_cap( array $meta_cap, string $post_status ): string {
		$key = '';

		if ( 'publish' === $post_status || 'future' === $post_status ) {
			$key = 'published';
		} elseif ( 'private' === $post_status ) {
			$key = 'private';
		}

		if ( '' === $key ) {
			return '';
		}

		return isset( $meta_cap[ $key ] ) ? (string) $meta_cap[ $key ] : '';
	}

	/**
	 * Translate any Envira cap name variant to canonical new-format (envira_* prefix).
	 *
	 * Uses an explicit static map — no regex — to handle the edit_other / edit_others
	 * inconsistency in existing code and the four granular caps missing from earlier spec
	 * versions.
	 *
	 * Paired with get_legacy_cap(): if a primitive cap is added to one, add it to the other.
	 *
	 * @since 1.13.0
	 *
	 * @param string $cap Capability name to normalize.
	 * @return string Canonical new-format name, or the original string if unrecognized.
	 */
	protected function maybe_prefix_cap( string $cap ): string {
		static $aliases = [
			// Old meta cap format (verb_envira_noun → envira_verb_noun).
			'edit_envira_gallery'               => 'envira_edit_gallery',
			'read_envira_gallery'               => 'envira_read_gallery',
			'delete_envira_gallery'             => 'envira_delete_gallery',

			// Old primitive cap format — both singular/plural "other" normalized to plural.
			'edit_envira_galleries'             => 'envira_edit_galleries',
			'edit_other_envira_galleries'       => 'envira_edit_others_galleries',
			'edit_others_envira_galleries'      => 'envira_edit_others_galleries',
			'publish_envira_galleries'          => 'envira_publish_galleries',
			'read_private_envira_galleries'     => 'envira_read_private_galleries',
			'delete_envira_galleries'           => 'envira_delete_galleries',
			'delete_others_envira_galleries'    => 'envira_delete_others_galleries',
			'create_envira_galleries'           => 'envira_create_galleries',

			// Granular caps used by Permissions::map_permissions() — must not silently fail.
			'edit_published_envira_galleries'   => 'envira_edit_published_galleries',
			'edit_private_envira_galleries'     => 'envira_edit_private_galleries',
			'delete_published_envira_galleries' => 'envira_delete_published_galleries',
			'delete_private_envira_galleries'   => 'envira_delete_private_galleries',

			// Short forms (no prefix at all).
			'edit_gallery'                      => 'envira_edit_gallery',
			'read_gallery'                      => 'envira_read_gallery',
			'delete_gallery'                    => 'envira_delete_gallery',
			'edit_galleries'                    => 'envira_edit_galleries',
			'create_galleries'                  => 'envira_create_galleries',
			'publish_galleries'                 => 'envira_publish_galleries',
		];

		return $aliases[ $cap ] ?? $cap;
	}

	/**
	 * Map new-format primitive cap names back to old-format equivalents on WP roles.
	 *
	 * Phase 1 bridge: keeps Permissions UI working without modification.
	 * Remove in Phase 2 after register_capabilities() adds new-format caps to roles.
	 *
	 * Paired with maybe_prefix_cap(): if a primitive cap is added to one, add it to the other.
	 *
	 * @since 1.13.0
	 *
	 * @param string $new_cap New-format capability name.
	 * @return string Old-format equivalent, or '' if none exists.
	 */
	protected function get_legacy_cap( string $new_cap ): string {
		static $legacy = [
			'envira_create_galleries'           => 'create_envira_galleries',
			'envira_edit_galleries'             => 'edit_envira_galleries',
			'envira_edit_others_galleries'      => 'edit_others_envira_galleries',
			'envira_publish_galleries'          => 'publish_envira_galleries',
			'envira_read_private_galleries'     => 'read_private_envira_galleries',
			'envira_delete_galleries'           => 'delete_envira_galleries',
			'envira_delete_others_galleries'    => 'delete_others_envira_galleries',
			'envira_edit_published_galleries'   => 'edit_published_envira_galleries',
			'envira_edit_private_galleries'     => 'edit_private_envira_galleries',
			'envira_delete_published_galleries' => 'delete_published_envira_galleries',
			'envira_delete_private_galleries'   => 'delete_private_envira_galleries',
		];

		return $legacy[ $new_cap ] ?? '';
	}
}
