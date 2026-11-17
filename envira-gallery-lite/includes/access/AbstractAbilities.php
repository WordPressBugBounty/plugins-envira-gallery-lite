<?php
/**
 * Envira Access Abilities — shared base.
 *
 * Registers Envira gallery operations as WordPress Abilities so they are
 * discoverable by AI agents and MCP clients via the WordPress Abilities Explorer.
 *
 * Holds every definition shared by Lite and Pro. Lite and Pro each provide a
 * subclass: Lite with a hardcoded list, Pro with a filterable one. This class is
 * a registration surface only — gallery reads and writes go through
 * Envira\Services\GalleryService.
 *
 * The Lite and Pro copies of this file are kept identical apart from the text
 * domain and the @since version, and Test_Access_Abilities_Parity fails the
 * build when they drift. Lite-only or Pro-only behaviour belongs in the
 * subclass or GalleryService, never here.
 *
 * Requires WordPress 6.9+. Hooks are skipped when wp_register_ability() is absent —
 * no-op on older WordPress installations.
 *
 * @since 1.13.0
 *
 * @package Envira_Gallery
 */

namespace Envira\Access;

use Envira\Services\GalleryService;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * AbstractAbilities — shared Envira Abilities API registration surface.
 *
 * @since 1.13.0
 */
abstract class AbstractAbilities {

	/**
	 * Ability namespace, also used as the category slug.
	 *
	 * Must stay 'envira-gallery' — NOT the plugin text domain. Lite and Pro
	 * register the same ability names on purpose, so an MCP client or agent
	 * configured against a Lite site keeps working after an upgrade to Pro. The
	 * two plugins are mutually exclusive, so the names never collide.
	 *
	 * @since 1.13.0
	 *
	 * @var string
	 */
	const ABILITY_NAMESPACE = 'envira-gallery';

	/**
	 * Setting key holding the write opt-in.
	 *
	 * The key envira_get_setting() would resolve to the
	 * `envira_gallery_abilities_write_enabled` option. The option itself is read
	 * and written directly through WRITE_SETTING_OPTION.
	 *
	 * @since 1.13.0
	 *
	 * @var string
	 */
	const WRITE_SETTING_KEY = 'abilities_write_enabled';

	/**
	 * Full option name behind WRITE_SETTING_KEY.
	 *
	 * Pro's envira_get_setting() prefixes its keys with `envira_gallery_`; this
	 * is the resolved name, so Lite can read and write the same option without
	 * that helper.
	 *
	 * @since 1.13.0
	 *
	 * @var string
	 */
	const WRITE_SETTING_OPTION = 'envira_gallery_abilities_write_enabled';

	/**
	 * Singleton instances, keyed by concrete class name.
	 *
	 * @since 1.13.0
	 *
	 * @var array<string, static>
	 */
	private static $instances = [];

	/**
	 * Gallery service.
	 *
	 * @since 1.13.0
	 *
	 * @var GalleryService|null
	 */
	private $galleries;

	/**
	 * Ability names wp_register_ability() rejected on this request.
	 *
	 * @since 1.13.0
	 *
	 * @var string[]
	 */
	protected $failed_registrations = [];

	/**
	 * Whether wp_register_ability_category() accepted the category on this request.
	 *
	 * Null until register_category() has run.
	 *
	 * @since 1.13.0
	 *
	 * @var bool|null
	 */
	protected $category_registered = null;

	/**
	 * Return the singleton for the concrete class.
	 *
	 * @since 1.13.0
	 *
	 * @return static
	 */
	public static function instance() {
		$class = static::class;

		if ( ! isset( self::$instances[ $class ] ) ) {
			self::$instances[ $class ] = new static();
		}

		return self::$instances[ $class ];
	}

	/**
	 * Register WordPress hooks. No-op on WordPress < 6.9.
	 *
	 * @since 1.13.0
	 *
	 * @return void
	 */
	public function init() {
		if ( ! $this->is_abilities_api_available() ) {
			return;
		}

		add_action( 'wp_abilities_api_categories_init', [ $this, 'register_category' ] );
		add_action( 'wp_abilities_api_init', [ $this, 'register_abilities' ] );
	}

	/**
	 * Whether the WordPress Abilities API is present.
	 *
	 * @since 1.13.0
	 *
	 * @return bool
	 */
	public function is_abilities_api_available(): bool {
		return function_exists( 'wp_register_ability' ) && function_exists( 'wp_register_ability_category' );
	}

	/**
	 * Register the envira-gallery ability category.
	 *
	 * @since 1.13.0
	 *
	 * @return void
	 */
	public function register_category() {
		$this->category_registered = null !== wp_register_ability_category(
			self::ABILITY_NAMESPACE,
			[
				'label'       => __( 'Envira Gallery', 'envira-gallery-lite' ),
				'description' => __( 'Manage Envira photo galleries — list, retrieve, create, update, and delete galleries.', 'envira-gallery-lite' ),
			]
		);
	}

	/**
	 * Register all abilities.
	 *
	 * @since 1.13.0
	 *
	 * @return void
	 */
	public function register_abilities() {
		$this->failed_registrations = [];

		foreach ( $this->get_ability_definitions() as $name => $args ) {
			// wp_register_ability() returns null on a rejected registration —
			// duplicate name, malformed schema — after a _doing_it_wrong() that
			// is invisible with WP_DEBUG off. Discarding that made a failed
			// registration indistinguishable from a successful one, including on
			// the settings screen an administrator would open to diagnose it.
			if ( null === wp_register_ability( $name, $args ) ) {
				$this->failed_registrations[] = $name;
			}
		}
	}

	/**
	 * Ability names that are not in the registry.
	 *
	 * The registry fires wp_abilities_api_init lazily, the first time anything
	 * asks it for an ability, so a screen that never asks would see no failures
	 * at all. Asking here primes it, which makes the answer true on any request
	 * after init rather than only on the REST or MCP request that registered.
	 *
	 * @since 1.13.0
	 *
	 * @return string[]
	 */
	public function get_failed_registrations(): array {
		if ( ! $this->is_abilities_api_available() || ! did_action( 'init' ) || ! function_exists( 'wp_has_ability' ) ) {
			return $this->failed_registrations;
		}

		$failed = [];

		foreach ( array_keys( $this->get_ability_definitions() ) as $name ) {
			if ( ! wp_has_ability( $name ) ) {
				$failed[] = (string) $name;
			}
		}

		return $failed;
	}

	/**
	 * Whether the envira-gallery ability category is registered.
	 *
	 * Every ability is registered into this category, and core rejects an
	 * ability whose category is missing, so a rejected category takes all of
	 * them down. Reporting it separately names the root cause.
	 *
	 * @since 1.13.0
	 *
	 * @return bool
	 */
	public function is_category_registered(): bool {
		if ( ! $this->is_abilities_api_available() || ! did_action( 'init' ) || ! function_exists( 'wp_has_ability_category' ) ) {
			return false !== $this->category_registered;
		}

		// wp_has_ability() primes both registries, the category one first.
		wp_has_ability( self::ABILITY_NAMESPACE . '/list-galleries' );

		return wp_has_ability_category( self::ABILITY_NAMESPACE );
	}

	/**
	 * Return the ability definitions to register.
	 *
	 * Lite returns the common set unchanged; Pro runs it through a filter.
	 *
	 * @since 1.13.0
	 *
	 * @return array<string, array>
	 */
	public function get_ability_definitions(): array {
		return $this->get_common_ability_definitions();
	}

	/**
	 * Definitions shared by Lite and Pro.
	 *
	 * @since 1.13.0
	 *
	 * @return array<string, array>
	 */
	protected function get_common_ability_definitions(): array {
		$ns = self::ABILITY_NAMESPACE;

		return [
			$ns . '/list-galleries'          => $this->definition_list_galleries(),
			$ns . '/get-gallery'             => $this->definition_get_gallery(),
			$ns . '/describe-gallery-schema' => $this->definition_describe_gallery_schema(),
			$ns . '/create-gallery'          => $this->definition_create_gallery(),
			$ns . '/update-gallery'          => $this->definition_update_gallery(),
			$ns . '/delete-gallery'          => $this->definition_delete_gallery(),
			$ns . '/add-images'              => $this->definition_add_images(),
		];
	}

	// -------------------------------------------------------------------------
	// Write gate
	// -------------------------------------------------------------------------

	/**
	 * Whether the write abilities are enabled.
	 *
	 * Reads the opt-in setting as the default, then runs it through the
	 * `envira_abilities_allow_write` filter so it can still be overridden
	 * programmatically in either direction.
	 *
	 * Default is false: an agent cannot create, update, delete or modify a
	 * gallery until a site administrator opts in.
	 *
	 * @since 1.13.0
	 *
	 * @return bool
	 */
	public function write_enabled(): bool {
		// Read the option directly rather than through envira_get_setting(): that
		// helper is Pro-only, and this base is shared with Lite, which has no
		// equivalent. The key is the same one envira_get_setting() resolves to.
		$default = (bool) get_option( self::WRITE_SETTING_OPTION, false );

		/**
		 * Filters whether the Envira write abilities are enabled.
		 *
		 * @since 1.13.0
		 *
		 * @param bool   $enabled Whether writes are enabled. Defaults to the
		 *                        `envira_gallery_abilities_write_enabled` option
		 *                        (false when absent).
		 * @param static $handler The abilities handler.
		 */
		return (bool) apply_filters( 'envira_abilities_allow_write', $default, $this );
	}

	/**
	 * Check the write gate.
	 *
	 * Subclasses may extend this. Neither Lite nor Pro adds a license check:
	 * license gating was dropped by design (#5604).
	 *
	 * @since 1.13.0
	 *
	 * @return true|\WP_Error True when writes are allowed.
	 */
	protected function check_write_gate() {
		if ( ! $this->write_enabled() ) {
			return new \WP_Error(
				'envira_abilities_writes_disabled',
				__( 'Envira gallery write abilities are disabled. Enable them under Envira Gallery → Settings → AI & MCP.', 'envira-gallery-lite' ),
				[ 'status' => 403 ]
			);
		}

		return true;
	}

	/**
	 * Gate shared by every write permission callback.
	 *
	 * @since 1.13.0
	 *
	 * @param string $cap Capability to check.
	 * @param int    $id  Optional gallery ID for meta-cap checks.
	 * @return true|\WP_Error
	 */
	protected function check_write_permission( string $cap, int $id = 0 ) {
		$gate = $this->check_write_gate();

		if ( is_wp_error( $gate ) ) {
			return $gate;
		}

		return $this->check_cap( $cap, $id );
	}

	/**
	 * Check a capability and translate a failure into a WP_Error.
	 *
	 * A bare `false` tells a calling agent nothing, so it retries. A WP_Error
	 * carries a code, a message and a 403 the agent can act on.
	 *
	 * @since 1.13.0
	 *
	 * @param string $cap Capability to check.
	 * @param int    $id  Optional gallery ID for meta-cap checks.
	 * @return true|\WP_Error
	 */
	protected function check_cap( string $cap, int $id = 0 ) {
		if ( envira_current_user_can( $cap, $id ) ) {
			return true;
		}

		return new \WP_Error(
			'envira_abilities_forbidden',
			__( 'You do not have permission to perform this action on Envira galleries.', 'envira-gallery-lite' ),
			[ 'status' => 403 ]
		);
	}

	/**
	 * Require a gallery ID on abilities whose capability is per-gallery.
	 *
	 * @since 1.13.0
	 *
	 * @param mixed  $input Ability input.
	 * @param string $key   Input key holding the gallery ID.
	 * @return int|\WP_Error Gallery ID, or WP_Error when absent.
	 */
	protected function require_gallery_id( $input, string $key = 'id' ) {
		$input = $this->normalize_input( $input );
		$id    = (int) ( $input[ $key ] ?? 0 );

		if ( $id <= 0 ) {
			return new \WP_Error(
				'envira_abilities_missing_id',
				/* translators: %s: input property name. */
				sprintf( __( 'A valid gallery ID is required in "%s".', 'envira-gallery-lite' ), $key ),
				[ 'status' => 400 ]
			);
		}

		return $id;
	}

	// -------------------------------------------------------------------------
	// Ability definitions
	// -------------------------------------------------------------------------

	/**
	 * Definition: list-galleries.
	 *
	 * @since 1.13.0
	 *
	 * @return array
	 */
	protected function definition_list_galleries(): array {
		return [
			'label'               => __( 'List Galleries', 'envira-gallery-lite' ),
			'description'         => __( 'Returns a paginated list of Envira galleries with metadata.', 'envira-gallery-lite' ),
			'category'            => self::ABILITY_NAMESPACE,
			'execute_callback'    => [ $this, 'execute_list_galleries' ],
			'permission_callback' => [ $this, 'can_list_galleries' ],
			// Every field is optional: the top-level default lets a call with no
			// input through, as core applies it only when the input is null.
			'input_schema'        => [
				'type'                 => 'object',
				'additionalProperties' => false,
				'default'              => [],
				'properties'           => [
					'per_page' => [
						'type'        => 'integer',
						'description' => __( 'Galleries per page (1–100).', 'envira-gallery-lite' ),
						'default'     => 20,
						'minimum'     => 1,
						'maximum'     => 100,
					],
					'page'     => [
						'type'        => 'integer',
						'description' => __( 'Page number.', 'envira-gallery-lite' ),
						'default'     => 1,
						'minimum'     => 1,
					],
					'status'   => [
						'type'        => 'string',
						'description' => __( 'Filter by publish status.', 'envira-gallery-lite' ),
						'enum'        => [ 'publish', 'draft', 'any' ],
						'default'     => 'any',
					],
					'search'   => [
						'type'        => 'string',
						'description' => __( 'Search galleries by title.', 'envira-gallery-lite' ),
					],
				],
			],
			'output_schema'       => [
				'type'       => 'object',
				'properties' => [
					'galleries'   => [
						'type'  => 'array',
						'items' => $this->gallery_schema(),
					],
					'total'       => [
						'type'        => 'integer',
						'description' => __( 'Total galleries matching the query.', 'envira-gallery-lite' ),
					],
					'page'        => [ 'type' => 'integer' ],
					'per_page'    => [ 'type' => 'integer' ],
					'total_pages' => [ 'type' => 'integer' ],
				],
			],
			'meta'                => $this->read_meta(),
		];
	}

	/**
	 * Definition: get-gallery.
	 *
	 * @since 1.13.0
	 *
	 * @return array
	 */
	protected function definition_get_gallery(): array {
		return [
			'label'               => __( 'Get Gallery', 'envira-gallery-lite' ),
			'description'         => __( 'Retrieves a single Envira gallery by ID.', 'envira-gallery-lite' ),
			'category'            => self::ABILITY_NAMESPACE,
			'execute_callback'    => [ $this, 'execute_get_gallery' ],
			'permission_callback' => [ $this, 'can_get_gallery' ],
			'input_schema'        => [
				'type'                 => 'object',
				'additionalProperties' => false,
				'required'             => [ 'id' ],
				'properties'           => [
					'id' => [
						'type'        => 'integer',
						'description' => __( 'Gallery ID.', 'envira-gallery-lite' ),
						'minimum'     => 1,
					],
				],
			],
			'output_schema'       => $this->gallery_schema(),
			'meta'                => $this->read_meta(),
		];
	}

	/**
	 * Definition: describe-gallery-schema.
	 *
	 * Lets an agent ask what is writable instead of guessing at gallery config
	 * keys. Hidden from MCP when writes are gated off, so agents do not see an
	 * editing surface they cannot act on. REST exposure is unaffected.
	 *
	 * @since 1.13.0
	 *
	 * @return array
	 */
	protected function definition_describe_gallery_schema(): array {
		return [
			'label'               => __( 'Describe Gallery Schema', 'envira-gallery-lite' ),
			'description'         => __( 'Describes which gallery fields and display layouts can be created or edited, and whether writes are currently enabled.', 'envira-gallery-lite' ),
			'category'            => self::ABILITY_NAMESPACE,
			'execute_callback'    => [ $this, 'execute_describe_gallery_schema' ],
			'permission_callback' => [ $this, 'can_describe_gallery_schema' ],
			// No `properties` key: an empty PHP array serialises to JSON `[]` (an
			// array, not an object `{}`), which stricter Abilities API validators
			// reject as a malformed schema.
			'input_schema'        => [
				'type'                 => 'object',
				'additionalProperties' => false,
				'default'              => [],
			],
			'output_schema'       => [
				'type'       => 'object',
				'properties' => [
					'writes_enabled'  => [
						'type'        => 'boolean',
						'description' => __( 'Whether the write abilities will currently execute.', 'envira-gallery-lite' ),
					],
					'statuses'        => [
						'type'  => 'array',
						'items' => [ 'type' => 'string' ],
					],
					'layouts'         => [
						'type'  => 'array',
						'items' => [
							'type'       => 'object',
							'properties' => [
								'key'   => [ 'type' => 'string' ],
								'label' => [ 'type' => 'string' ],
							],
						],
					],
					'writable_fields' => [
						'type'  => 'array',
						'items' => [
							'type'       => 'object',
							'properties' => [
								'name'      => [ 'type' => 'string' ],
								'type'      => [ 'type' => 'string' ],
								'abilities' => [
									'type'  => 'array',
									'items' => [ 'type' => 'string' ],
								],
							],
						],
					],
				],
			],
			'meta'                => $this->read_meta( [ 'mcp_public' => $this->write_enabled() ] ),
		];
	}

	/**
	 * Definition: create-gallery.
	 *
	 * @since 1.13.0
	 *
	 * @return array
	 */
	protected function definition_create_gallery(): array {
		return [
			'label'               => __( 'Create Gallery', 'envira-gallery-lite' ),
			'description'         => __( 'Creates a new Envira gallery.', 'envira-gallery-lite' ),
			'category'            => self::ABILITY_NAMESPACE,
			'execute_callback'    => [ $this, 'execute_create_gallery' ],
			'permission_callback' => [ $this, 'can_create_gallery' ],
			'input_schema'        => [
				'type'                 => 'object',
				'additionalProperties' => false,
				'required'             => [ 'title' ],
				'properties'           => [
					'title'  => [
						'type'        => 'string',
						'description' => __( 'Gallery title.', 'envira-gallery-lite' ),
						'minLength'   => 1,
						'maxLength'   => 255,
					],
					'status' => [
						'type'        => 'string',
						'description' => __( 'Initial publish status.', 'envira-gallery-lite' ),
						'enum'        => [ 'publish', 'draft' ],
						'default'     => 'draft',
					],
				],
			],
			'output_schema'       => $this->gallery_schema(),
			'meta'                => $this->write_meta( [ 'idempotent' => false ] ),
		];
	}

	/**
	 * Definition: update-gallery.
	 *
	 * @since 1.13.0
	 *
	 * @return array
	 */
	protected function definition_update_gallery(): array {
		return [
			'label'               => __( 'Update Gallery', 'envira-gallery-lite' ),
			'description'         => __( 'Updates the title or publish status of an existing Envira gallery.', 'envira-gallery-lite' ),
			'category'            => self::ABILITY_NAMESPACE,
			'execute_callback'    => [ $this, 'execute_update_gallery' ],
			'permission_callback' => [ $this, 'can_update_gallery' ],
			'input_schema'        => [
				'type'                 => 'object',
				'additionalProperties' => false,
				'required'             => [ 'id' ],
				'properties'           => [
					'id'     => [
						'type'        => 'integer',
						'description' => __( 'Gallery ID.', 'envira-gallery-lite' ),
						'minimum'     => 1,
					],
					'title'  => [
						'type'        => 'string',
						'description' => __( 'New gallery title.', 'envira-gallery-lite' ),
						'minLength'   => 1,
						'maxLength'   => 255,
					],
					'status' => [
						'type'        => 'string',
						'description' => __( 'New publish status.', 'envira-gallery-lite' ),
						'enum'        => [ 'publish', 'draft' ],
					],
				],
			],
			'output_schema'       => $this->gallery_schema(),
			'meta'                => $this->write_meta(),
		];
	}

	/**
	 * Definition: delete-gallery.
	 *
	 * @since 1.13.0
	 *
	 * @return array
	 */
	protected function definition_delete_gallery(): array {
		return [
			'label'               => __( 'Delete Gallery', 'envira-gallery-lite' ),
			'description'         => __( 'Deletes or trashes an Envira gallery.', 'envira-gallery-lite' ),
			'category'            => self::ABILITY_NAMESPACE,
			'execute_callback'    => [ $this, 'execute_delete_gallery' ],
			'permission_callback' => [ $this, 'can_delete_gallery' ],
			'input_schema'        => [
				'type'                 => 'object',
				'additionalProperties' => false,
				'required'             => [ 'id' ],
				'properties'           => [
					'id'    => [
						'type'        => 'integer',
						'description' => __( 'Gallery ID to delete.', 'envira-gallery-lite' ),
						'minimum'     => 1,
					],
					'force' => [
						'type'        => 'boolean',
						'description' => __( 'True to permanently delete; false to move to trash (default).', 'envira-gallery-lite' ),
						'default'     => false,
					],
				],
			],
			'output_schema'       => [
				'type'       => 'object',
				'properties' => [
					'deleted' => [ 'type' => 'boolean' ],
					'trashed' => [
						'type'        => 'boolean',
						'description' => __( 'True when the gallery was moved to the trash, false when it was permanently deleted.', 'envira-gallery-lite' ),
					],
					'id'      => [ 'type' => 'integer' ],
				],
			],
			'meta'                => $this->write_meta( [ 'destructive' => true ] ),
		];
	}

	/**
	 * Definition: add-images.
	 *
	 * @since 1.13.0
	 *
	 * @return array
	 */
	protected function definition_add_images(): array {
		return [
			'label'               => __( 'Add Images to Gallery', 'envira-gallery-lite' ),
			'description'         => __( 'Adds one or more media library images to an existing Envira gallery.', 'envira-gallery-lite' ),
			'category'            => self::ABILITY_NAMESPACE,
			'execute_callback'    => [ $this, 'execute_add_images' ],
			'permission_callback' => [ $this, 'can_add_images' ],
			'input_schema'        => [
				'type'                 => 'object',
				'additionalProperties' => false,
				'required'             => [ 'gallery_id', 'attachment_ids' ],
				'properties'           => [
					'gallery_id'     => [
						'type'        => 'integer',
						'description' => __( 'Gallery ID to add images to.', 'envira-gallery-lite' ),
						'minimum'     => 1,
					],
					'attachment_ids' => [
						'type'        => 'array',
						'description' => __( 'Media library attachment IDs to add.', 'envira-gallery-lite' ),
						'items'       => [
							'type'    => 'integer',
							'minimum' => 1,
						],
						'minItems'    => 1,
					],
				],
			],
			'output_schema'       => [
				'type'       => 'object',
				'properties' => [
					'gallery'  => $this->gallery_schema(),
					'added'    => [
						'type'        => 'array',
						'description' => __( 'Attachment IDs that were added.', 'envira-gallery-lite' ),
						'items'       => [ 'type' => 'integer' ],
					],
					'skipped'  => [
						'type'        => 'array',
						'description' => __( 'Attachment IDs that were not added — not a media library attachment, or not readable by this account.', 'envira-gallery-lite' ),
						'items'       => [ 'type' => 'integer' ],
					],
					'existing' => [
						'type'        => 'array',
						'description' => __( 'Attachment IDs that were already in the gallery and were left unchanged.', 'envira-gallery-lite' ),
						'items'       => [ 'type' => 'integer' ],
					],
				],
			],
			'meta'                => $this->write_meta(),
		];
	}

	// -------------------------------------------------------------------------
	// Execute callbacks — thin delegates to GalleryService.
	// -------------------------------------------------------------------------

	/**
	 * Execute: list-galleries.
	 *
	 * @since 1.13.0
	 *
	 * @param mixed $input Validated ability input.
	 * @return array
	 */
	public function execute_list_galleries( $input ) {
		return $this->galleries()->list_galleries( $this->normalize_input( $input ) );
	}

	/**
	 * Execute: get-gallery.
	 *
	 * @since 1.13.0
	 *
	 * @param mixed $input Validated ability input.
	 * @return array|\WP_Error
	 */
	public function execute_get_gallery( $input ) {
		$input = $this->normalize_input( $input );

		return $this->galleries()->get( (int) ( $input['id'] ?? 0 ) );
	}

	/**
	 * Execute: describe-gallery-schema.
	 *
	 * @since 1.13.0
	 *
	 * @return array
	 */
	public function execute_describe_gallery_schema() {
		$ns      = self::ABILITY_NAMESPACE;
		$layouts = function_exists( 'envira_get_layouts' ) ? (array) envira_get_layouts() : [];
		$result  = [];

		foreach ( GalleryService::get_layout_keys() as $key ) {
			// envira_get_layouts() values are arrays keyed by layout slug, each
			// carrying a human-readable `name`.
			$layout = $layouts[ $key ] ?? [];
			$label  = is_array( $layout ) && ! empty( $layout['name'] ) ? (string) $layout['name'] : '';

			$result[] = [
				'key'   => $key,
				// Lite has no envira_get_layouts(), so fall back to the key made
				// presentable rather than showing a bare slug to an agent.
				'label' => '' !== $label ? $label : ucfirst( str_replace( '-', ' ', $key ) ),
			];
		}

		// Derived from what is actually registered, so Lite — which has no
		// set-gallery-layout — does not advertise a field it cannot write, and a
		// Pro addon adding an ability does not have to edit this list.
		$registered = array_keys( $this->get_ability_definitions() );
		$candidates = [
			[
				'name'      => 'title',
				'type'      => 'string',
				'abilities' => [ $ns . '/create-gallery', $ns . '/update-gallery' ],
			],
			[
				'name'      => 'status',
				'type'      => 'string',
				'abilities' => [ $ns . '/create-gallery', $ns . '/update-gallery' ],
			],
			[
				'name'      => 'attachment_ids',
				'type'      => 'array',
				'abilities' => [ $ns . '/add-images' ],
			],
			[
				'name'      => 'layout',
				'type'      => 'string',
				'abilities' => [ $ns . '/set-gallery-layout' ],
			],
		];

		$writable_fields = [];

		foreach ( $candidates as $field ) {
			$available = array_values( array_intersect( $field['abilities'], $registered ) );

			if ( empty( $available ) ) {
				continue;
			}

			$field['abilities'] = $available;
			$writable_fields[]  = $field;
		}

		return [
			'writes_enabled'  => $this->write_enabled(),
			'statuses'        => [ 'publish', 'draft' ],
			'layouts'         => $result,
			'writable_fields' => $writable_fields,
		];
	}

	/**
	 * Execute: create-gallery.
	 *
	 * @since 1.13.0
	 *
	 * @param mixed $input Validated ability input.
	 * @return array|\WP_Error
	 */
	public function execute_create_gallery( $input ) {
		$input = $this->normalize_input( $input );

		return $this->galleries()->create(
			(string) ( $input['title'] ?? '' ),
			(string) ( $input['status'] ?? 'draft' )
		);
	}

	/**
	 * Execute: update-gallery.
	 *
	 * @since 1.13.0
	 *
	 * @param mixed $input Validated ability input.
	 * @return array|\WP_Error
	 */
	public function execute_update_gallery( $input ) {
		$input  = $this->normalize_input( $input );
		$fields = array_intersect_key( $input, array_flip( [ 'title', 'status' ] ) );

		return $this->galleries()->update( (int) ( $input['id'] ?? 0 ), $fields );
	}

	/**
	 * Execute: delete-gallery.
	 *
	 * @since 1.13.0
	 *
	 * @param mixed $input Validated ability input.
	 * @return array|\WP_Error
	 */
	public function execute_delete_gallery( $input ) {
		$input = $this->normalize_input( $input );

		return $this->galleries()->delete(
			(int) ( $input['id'] ?? 0 ),
			// Before WP 7.1 the REST run controller passes DELETE query input
			// through unsanitized, so `force` can arrive as the string 'false'.
			rest_sanitize_boolean( $input['force'] ?? false )
		);
	}

	/**
	 * Execute: add-images.
	 *
	 * @since 1.13.0
	 *
	 * @param mixed $input Validated ability input.
	 * @return array|\WP_Error
	 */
	public function execute_add_images( $input ) {
		$input = $this->normalize_input( $input );

		return $this->galleries()->add_images(
			(int) ( $input['gallery_id'] ?? 0 ),
			(array) ( $input['attachment_ids'] ?? [] )
		);
	}

	// -------------------------------------------------------------------------
	// Permission callbacks
	// -------------------------------------------------------------------------

	/**
	 * Permission: list-galleries.
	 *
	 * @since 1.13.0
	 *
	 * @param mixed $input Ability input (may be null before validation).
	 * @return true|\WP_Error
	 */
	public function can_list_galleries( $input = null ) {
		return $this->check_cap( 'any' );
	}

	/**
	 * Permission: get-gallery.
	 *
	 * @since 1.13.0
	 *
	 * @param mixed $input Ability input (may be null before validation).
	 * @return true|\WP_Error
	 */
	public function can_get_gallery( $input = null ) {
		// Derive the id exactly as execute_get_gallery() does. When the two
		// disagreed, an object-shaped input resolved permission through the
		// generic 'any' check while execute still returned the gallery, which
		// bypassed the per-gallery ownership check. Measured: removing the 'any'
		// fallback does not hide the ability from discovery — wp_get_abilities()
		// performs no capability checks while listing.
		$id = $this->require_gallery_id( $input, 'id' );

		if ( is_wp_error( $id ) ) {
			return $id;
		}

		return $this->check_cap( 'envira_edit_gallery', $id );
	}

	/**
	 * Permission: describe-gallery-schema.
	 *
	 * @since 1.13.0
	 *
	 * @param mixed $input Ability input (unused).
	 * @return true|\WP_Error
	 */
	public function can_describe_gallery_schema( $input = null ) {
		return $this->check_cap( 'any' );
	}

	/**
	 * Permission: create-gallery.
	 *
	 * @since 1.13.0
	 *
	 * @param mixed $input Ability input (unused).
	 * @return true|\WP_Error
	 */
	public function can_create_gallery( $input = null ) {
		$allowed = $this->check_write_permission( 'envira_create_galleries' );

		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}

		return $this->check_publish_permission( $input );
	}

	/**
	 * Require the publish capability when the caller asks to publish.
	 *
	 * Neither wp_insert_post() nor wp_update_post() performs a capability check
	 * of its own, so without this a role holding only the create/edit caps — a
	 * contributor, say — could publish a gallery through an ability, which
	 * wp-admin would refuse.
	 *
	 * @since 1.13.0
	 *
	 * @param mixed $input Ability input.
	 * @return true|\WP_Error
	 */
	protected function check_publish_permission( $input ) {
		$input = $this->normalize_input( $input );

		// Normalized the way GalleryService saves it (sanitize_key lowercases), so 'Publish' cannot
		// skip this check and still publish should the input schema ever stop rejecting it.
		$status = isset( $input['status'] ) && is_string( $input['status'] ) ? sanitize_key( $input['status'] ) : '';

		if ( 'publish' !== $status ) {
			return true;
		}

		return $this->check_cap( 'envira_publish_galleries' );
	}

	/**
	 * Permission: update-gallery.
	 *
	 * @since 1.13.0
	 *
	 * @param mixed $input Ability input.
	 * @return true|\WP_Error
	 */
	public function can_update_gallery( $input = null ) {
		$allowed = $this->check_write_permission_for( $input, 'id', 'envira_edit_gallery' );

		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}

		return $this->check_publish_permission( $input );
	}

	/**
	 * Permission: delete-gallery.
	 *
	 * @since 1.13.0
	 *
	 * @param mixed $input Ability input.
	 * @return true|\WP_Error
	 */
	public function can_delete_gallery( $input = null ) {
		return $this->check_write_permission_for( $input, 'id', 'envira_delete_gallery' );
	}

	/**
	 * Permission: add-images.
	 *
	 * @since 1.13.0
	 *
	 * @param mixed $input Ability input.
	 * @return true|\WP_Error
	 */
	public function can_add_images( $input = null ) {
		return $this->check_write_permission_for( $input, 'gallery_id', 'envira_edit_gallery' );
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Run the write gate then a per-gallery capability check.
	 *
	 * @since 1.13.0
	 *
	 * @param mixed  $input Ability input.
	 * @param string $key   Input key holding the gallery ID.
	 * @param string $cap   Capability to check.
	 * @return true|\WP_Error
	 */
	protected function check_write_permission_for( $input, string $key, string $cap ) {
		$gate = $this->check_write_gate();

		if ( is_wp_error( $gate ) ) {
			return $gate;
		}

		$id = $this->require_gallery_id( $input, $key );

		if ( is_wp_error( $id ) ) {
			return $id;
		}

		return $this->check_cap( $cap, $id );
	}

	/**
	 * Normalise ability input to an array.
	 *
	 * @since 1.13.0
	 *
	 * @param mixed $input Input data (array, object or null).
	 * @return array
	 */
	protected function normalize_input( $input ): array {
		if ( is_array( $input ) ) {
			return $input;
		}

		if ( is_object( $input ) ) {
			return (array) $input;
		}

		return [];
	}

	/**
	 * Return the gallery service.
	 *
	 * @since 1.13.0
	 *
	 * @return GalleryService
	 */
	protected function galleries(): GalleryService {
		if ( ! $this->galleries instanceof GalleryService ) {
			$this->galleries = new GalleryService();
		}

		return $this->galleries;
	}

	/**
	 * Normalise a gallery WP_Post into a consistent array.
	 *
	 * Public convenience wrapper; delegates to GalleryService::format().
	 *
	 * @since 1.13.0
	 *
	 * @param \WP_Post $post Gallery post object.
	 * @return array
	 */
	public function format_gallery( \WP_Post $post ): array {
		return $this->galleries()->format( $post );
	}

	/**
	 * Meta block for a read-only ability.
	 *
	 * @since 1.13.0
	 *
	 * @param array $overrides Optional. `mcp_public` to override MCP exposure.
	 * @return array
	 */
	protected function read_meta( array $overrides = [] ): array {
		return [
			'annotations'  => [
				'readonly'    => true,
				'destructive' => false,
				'idempotent'  => true,
			],
			'show_in_rest' => true,
			'mcp'          => [
				'public' => array_key_exists( 'mcp_public', $overrides ) ? (bool) $overrides['mcp_public'] : true,
			],
		];
	}

	/**
	 * Meta block for a write ability.
	 *
	 * Writes are hidden from MCP while the write gate is off, so an agent never
	 * sees an operation it cannot perform. REST exposure is unaffected and stays
	 * gated by the capability check.
	 *
	 * @since 1.13.0
	 *
	 * @param array $overrides Optional. `destructive` and/or `idempotent`.
	 * @return array
	 */
	protected function write_meta( array $overrides = [] ): array {
		return [
			'annotations'  => [
				'readonly'    => false,
				'destructive' => ! empty( $overrides['destructive'] ),
				'idempotent'  => array_key_exists( 'idempotent', $overrides ) ? (bool) $overrides['idempotent'] : true,
			],
			'show_in_rest' => true,
			'mcp'          => [
				'public' => $this->write_enabled(),
			],
		];
	}

	/**
	 * JSON Schema fragment describing a single gallery object.
	 *
	 * @since 1.13.0
	 *
	 * @return array
	 */
	protected function gallery_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'id'          => [
					'type'        => 'integer',
					'description' => __( 'Gallery ID.', 'envira-gallery-lite' ),
				],
				'title'       => [
					'type'        => 'string',
					'description' => __( 'Gallery title.', 'envira-gallery-lite' ),
				],
				'slug'        => [
					'type'        => 'string',
					'description' => __( 'Gallery URL slug. Derived from the title until the gallery is first published.', 'envira-gallery-lite' ),
				],
				'status'      => [
					'type'        => 'string',
					'description' => __( 'Publish status.', 'envira-gallery-lite' ),
				],
				'created'     => [
					'type'        => 'string',
					'format'      => 'date-time',
					'description' => __( 'Creation date (UTC, RFC 3339).', 'envira-gallery-lite' ),
				],
				'modified'    => [
					'type'        => 'string',
					'format'      => 'date-time',
					'description' => __( 'Last modified date (UTC, RFC 3339).', 'envira-gallery-lite' ),
				],
				'url'         => [
					'type'        => 'string',
					'description' => __( 'Public gallery URL.', 'envira-gallery-lite' ),
				],
				'edit_url'    => [
					'type'        => 'string',
					'description' => __( 'WordPress admin edit URL.', 'envira-gallery-lite' ),
				],
				'image_count' => [
					'type'        => 'integer',
					'description' => __( 'Number of images in the gallery.', 'envira-gallery-lite' ),
				],
				'layout'      => [
					'type'        => 'string',
					'description' => __( 'Display layout key.', 'envira-gallery-lite' ),
					'enum'        => GalleryService::get_layout_keys(),
				],
			],
		];
	}
}
