<?php
/**
 * Capabilities class.
 *
 * @since 1.3.7
 *
 * @package Envira_Gallery
 * @author  Envira Team
 */

namespace Envira\Admin;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {

	exit;

}

/**
 * Capabilities class.
 *
 * @since 1.8.9
 *
 * @package Envira_Gallery
 * @author  Envira Team
 */
class Envira_Capabilities {

	/**
	 * Option recording which permission policy migration has run.
	 *
	 * @since 1.16.3
	 */
	const POLICY_VERSION_OPTION = 'envira_permissions_policy_version';

	/**
	 * Current permission policy migration. Bump to re-apply the policy once more on upgrade.
	 *
	 * @since 1.16.3
	 */
	const POLICY_VERSION = 1;

	/**
	 * Roles the capability bootstrap copies gallery capabilities onto.
	 *
	 * @since 1.16.3
	 *
	 * @var array
	 */
	private static $roles = [
		'administrator',
		'editor',
		'author',
		'contributor',
		'subscriber',
	];

	/**
	 * Every gallery capability the bootstrap can copy onto a role.
	 *
	 * @since 1.16.3
	 *
	 * @var array
	 */
	private static $capabilities = [
		'edit_envira_gallery',
		'read_envira_gallery',
		'delete_envira_gallery',
		'edit_envira_galleries',
		'edit_other_envira_galleries',
		'edit_others_envira_galleries',
		'publish_envira_galleries',
		'read_private_envira_galleries',
		'delete_envira_galleries',
		'delete_private_envira_galleries',
		'delete_published_envira_galleries',
		'delete_others_envira_galleries',
		'edit_private_envira_galleries',
		'edit_published_envira_galleries',
		'create_envira_galleries',
	];

	/**
	 * Primary class constructor.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		_deprecated_function( __CLASS__ . '::__construct', '1.13.0 of Envira Gallery Lite', 'Envira\Access\Capabilities' );

		// Register capabilities.
		add_action( 'admin_init', [ $this, 'add_capabilities' ] );
		self::register_access_hooks();
	}

	/**
	 * Registers the gallery list and edit-screen access guards.
	 *
	 * Static, so the plugin's own bootstrap can register them without
	 * constructing this deprecated class. Before 1.13.0 the constructor was the
	 * only place these were hooked, so dropping the construction also dropped
	 * the author scoping of the galleries list.
	 *
	 * @since 1.13.0
	 *
	 * @return void
	 */
	public static function register_access_hooks() {
		if ( false === has_filter( 'pre_get_posts', [ __CLASS__, 'show_only_user_envira_galleries' ] ) ) {
			add_filter( 'pre_get_posts', [ __CLASS__, 'show_only_user_envira_galleries' ] );
		}

		if ( false === has_action( 'current_screen', [ __CLASS__, 'restrict_envira_gallery_access' ] ) ) {
			add_action( 'current_screen', [ __CLASS__, 'restrict_envira_gallery_access' ] );
		}
	}

	/**
	 * Restricts access to Envira Galleries based on user capabilities.
	 *
	 * This function is hooked into the current_screen action, and checks if the current user has the required capability to edit other users' galleries.
	 * If the user doesn't have the capability, they're redirected to the Envira Galleries page.
	 *
	 * @return void
	 */
	public static function restrict_envira_gallery_access() {
		$screen = get_current_screen();
		if ( ! $screen || 'envira' !== $screen->post_type ) {
			return;
		}

		$gallery_id = isset( $_GET['post'] ) ? absint( sanitize_key( $_GET['post'] ) ) : null; // phpcs:ignore WordPress.Security.NonceVerification

		if ( ! $gallery_id ) {
			return;
		}

		$gallery = get_post( $gallery_id );
		$author  = $gallery ? (int) $gallery->post_author : 0;

		if ( get_current_user_id() === $author ) {
			return;
		}

		// If the user doesn't have the capability to edit other users' galleries, redirect to listing.

		if ( ! current_user_can( 'edit_others_envira_galleries' ) ) {
			wp_safe_redirect( admin_url( 'edit.php?post_type=envira' ) );
			exit;
		}
	}

	/**
	 * Restricts access to Envira Galleries based on user capabilities.
	 *
	 * This function is hooked into the pre_get_posts action, and checks if the current user has the required capability to edit other users' galleries.
	 * If the user doesn't have the capability, they're only shown their own galleries.
	 *
	 * @param WP_Query $query The WP_Query object.
	 *
	 * @return WP_Query
	 */
	public static function show_only_user_envira_galleries( $query ) {
		global $pagenow;

		if ( 'edit.php' !== $pagenow || ! $query->is_admin ) {
			return $query;
		}

		if ( 'envira' !== $query->get( 'post_type' ) ) {
			return $query;
		}

		if ( ! current_user_can( 'edit_others_envira_galleries' ) ) {
			global $user_ID;
			$query->set( 'author', $user_ID );
		}
		return $query;
	}

	/**
	 * Registers Envira Gallery capabilities for each Role, if they don't already exist.
	 *
	 * If capabilities don't exist, they're copied from Posts. This ensures users prior to 1.8.9
	 * get like-for-like behaviour in Envira and don't notice the new capabilities.
	 *
	 * @since 1.0.0
	 */
	public function add_capabilities() {

		// Bail out if it is settings page update action. - Ignore nonce check as we don't use post data here.
		// phpcs:ignore WordPress.Security.NonceVerification
		if ( isset( $_POST['envira-permissions-nonce'] ) && sanitize_text_field( wp_unslash( $_POST['envira-permissions-nonce'] ) ) !== null ) {
			return;
		}

		self::register_capabilities();
	}

	/**
	 * Registers Envira Gallery Lite capabilities for each role, if absent.
	 *
	 * Static, and separate from add_capabilities(), so the plugin's own bootstrap
	 * can register capabilities without constructing this class. The constructor
	 * carries a _deprecated_function() notice, and the internal call site on
	 * admin_init was tripping it on every admin request — including
	 * admin-ajax.php, where the notice can print ahead of the JSON response.
	 * Mirrors \Envira\Utils\Capabilities::register_capabilities() in Pro.
	 *
	 * @since 1.13.0
	 *
	 * @return void
	 */
	public static function register_capabilities() {

		// Grab the administrator role, and if it already has an Envira capability key defined, bail
		// as we only need to register our capabilities once.
		$administrator = get_role( 'administrator' );
		if ( ! $administrator instanceof \WP_Role ) {
			// No administrator role to copy from: nothing sensible to register, and dereferencing
			// null here fatals the request (Pro #5584). The activation hook now reaches this too.
			return;
		}
		if ( $administrator->has_cap( 'edit_other_envira_galleries' ) ) {
			return;
		}

		// If here, we need to assign capabilities.
		foreach ( self::$roles as $role_name ) {
			// Properly get the role as WP_Role object.
			$role = get_role( $role_name );
			if ( ! is_object( $role ) ) {
				continue;
			}

			// Map this Role's Post capabilities to our Envira Gallery capabilities.
			$caps = [
				'edit_envira_gallery'               => $role->has_cap( 'edit_posts' ),
				'read_envira_gallery'               => $role->has_cap( 'read' ),
				'delete_envira_gallery'             => $role->has_cap( 'delete_posts' ),

				'edit_envira_galleries'             => $role->has_cap( 'edit_posts' ),
				'edit_other_envira_galleries'       => $role->has_cap( 'edit_others_posts' ),
				'edit_others_envira_galleries'      => $role->has_cap( 'edit_others_posts' ),
				'publish_envira_galleries'          => $role->has_cap( 'publish_posts' ),
				'read_private_envira_galleries'     => $role->has_cap( 'read_private_posts' ),

				'delete_envira_galleries'           => $role->has_cap( 'delete_posts' ),
				'delete_private_envira_galleries'   => $role->has_cap( 'delete_private_posts' ),
				'delete_published_envira_galleries' => $role->has_cap( 'delete_published_posts' ),
				'delete_others_envira_galleries'    => $role->has_cap( 'delete_others_posts' ),
				'edit_private_envira_galleries'     => $role->has_cap( 'edit_private_posts' ),
				'edit_published_envira_galleries'   => $role->has_cap( 'edit_published_posts' ),
				'create_envira_galleries'           => $role->has_cap( 'edit_posts' ),
			];

			// Add the above Envira capabilities to this Role.
			foreach ( $caps as $envira_cap => $value ) {
				// Don't add if value is false.
				if ( ! $value ) {
					continue;
				}
				$role->add_cap( $envira_cap );
			}
		}

		// Apply the default permission policy now, rather than on the first Settings page load.
		// The mapping above gives Contributor create, edit and delete gallery capabilities.
		self::apply_permission_policy();
	}

	/**
	 * Re-applies the permission policy once on sites that bootstrapped before 1.16.3.
	 *
	 * Those sites still hold the capabilities the bootstrap copied from core post capabilities,
	 * for example Contributor's create, edit and delete. add_capabilities() returns early for
	 * them, so the policy is never applied there.
	 *
	 * @since 1.16.3
	 *
	 * @return void
	 */
	public static function maybe_upgrade_permissions() {
		if ( (int) get_option( self::POLICY_VERSION_OPTION, 0 ) >= self::POLICY_VERSION ) {
			return;
		}

		// Not bootstrapped yet: add_capabilities() applies the policy when it runs.
		$administrator = get_role( 'administrator' );
		if ( ! is_object( $administrator ) || ! $administrator->has_cap( 'edit_other_envira_galleries' ) ) {
			return;
		}

		// Record the run first, so requests arriving at the same time don't all rewrite the roles.
		update_option( self::POLICY_VERSION_OPTION, self::POLICY_VERSION );

		self::save_default_permissions();

		// Remove only. A capability the policy grants but the role lacks was taken away on
		// purpose, for example a Deny set with Members, so it is not added back.
		self::apply_permission_policy( false );

		// The current user's capabilities were read from the roles before init. Re-read them so
		// this request is not authorised with the removed ones.
		$user = wp_get_current_user();
		if ( $user->exists() ) {
			$user->get_role_caps();
		}
	}

	/**
	 * Saves the default for every permission that has no saved policy.
	 *
	 * Envira_Permissions::set_default_permissions() also strips gallery capabilities from every
	 * role with edit_posts, including custom roles granted them elsewhere (for example with
	 * Members). This saves the same defaults without touching any role. A missing option would
	 * otherwise read as "no role", and the policy would strip Editor and Author.
	 *
	 * @since 1.16.3
	 *
	 * @return void
	 */
	private static function save_default_permissions() {
		foreach ( Envira_Permissions::get_default_roles() as $permission => $allowed ) {
			if ( false === get_option( $permission, false ) ) {
				update_option( $permission, maybe_serialize( $allowed ) );
			}
		}

		update_option( 'envira_permissions_default', 'yes' );
	}

	/**
	 * Applies the saved permission policy to the roles the bootstrap copies capabilities onto.
	 *
	 * The bootstrap also re-runs after a role reset (`wp role reset`, User Role Editor's Reset),
	 * when the policy was applied long ago and set_default_permissions() skips. Custom roles are
	 * left alone, so they keep whatever was granted to them elsewhere.
	 *
	 * @since 1.16.3
	 *
	 * @param bool $grant_missing Whether to add policy capabilities the role lacks.
	 *
	 * @return void
	 */
	private static function apply_permission_policy( $grant_missing = true ) {
		// The Envira_Permissions constructor calls set_default_permissions().
		$permissions = new Envira_Permissions();
		$granted     = [];
		$covered     = [];

		foreach ( $permissions->map_permissions() as $permission => $capabilities ) {
			$allowed = get_option( $permission, false );

			if ( false === $allowed ) {
				continue;
			}

			$allowed = (array) maybe_unserialize( $allowed );

			foreach ( self::$roles as $role_name ) {
				$role = get_role( $role_name );

				if ( 'administrator' === $role_name || ! is_object( $role ) ) {
					continue;
				}

				$grant = in_array( $role_name, $allowed, true );

				if ( $grant ) {
					$granted[ $role_name ][ $permission ] = true;
					$covered[ $role_name ]                = array_merge( $covered[ $role_name ] ?? [], $capabilities );
				}

				foreach ( $capabilities as $capability ) {
					if ( $grant ) {
						if ( $grant_missing ) {
							$role->add_cap( $capability );
						}
					} elseif ( ! empty( $role->capabilities[ $capability ] ) ) {
						// Only granted entries: removing a Deny (false) could let another role's grant through.
						$role->remove_cap( $capability );
					}
				}
			}
		}

		// Drop copied capabilities the granted permissions do not cover, so a role the policy
		// grants nothing holds no gallery capabilities at all. No permission maps
		// delete_others_envira_galleries or delete_private_envira_galleries: keep them only for
		// roles allowed both to delete galleries and to edit others' galleries.
		foreach ( self::$roles as $role_name ) {
			$role = get_role( $role_name );

			if ( 'administrator' === $role_name || ! is_object( $role ) ) {
				continue;
			}

			$keep = $covered[ $role_name ] ?? [];

			if ( isset( $granted[ $role_name ]['envira_permissions_delete'], $granted[ $role_name ]['envira_permissions_edit_others'] ) ) {
				$keep[] = 'delete_others_envira_galleries';
				$keep[] = 'delete_private_envira_galleries';
			}

			foreach ( array_diff( self::$capabilities, $keep ) as $capability ) {
				if ( ! empty( $role->capabilities[ $capability ] ) ) {
					$role->remove_cap( $capability );
				}
			}
		}

		update_option( self::POLICY_VERSION_OPTION, self::POLICY_VERSION );

		// The signed-in user's capability list was resolved from their roles before this ran, so
		// it lacks what was just granted; re-resolve it or this request is still refused (Pro #5584).
		$current_user = wp_get_current_user();
		if ( $current_user instanceof \WP_User && $current_user->exists() ) {
			$current_user->for_site( get_current_blog_id() );
		}
	}
}
