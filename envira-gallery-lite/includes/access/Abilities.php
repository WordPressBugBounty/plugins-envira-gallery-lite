<?php
/**
 * Envira Access Abilities — Lite implementation.
 *
 * Registers Envira gallery operations as WordPress Abilities so they are
 * discoverable by AI agents and MCP clients via the WordPress Abilities Explorer.
 *
 * Every definition, execute callback and permission callback lives on
 * AbstractAbilities. Lite adds nothing: the list is whatever the shared base
 * registers, and it is deliberately not filterable — Pro owns the addon seam.
 *
 * Requires WordPress 6.9+. Hooks are skipped when wp_register_ability() is absent —
 * no-op on older WordPress installations.
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
 * Abilities — Lite registration surface for the WordPress Abilities API.
 *
 * @since 1.13.0
 */
class Abilities extends AbstractAbilities {

	/**
	 * Return the ability definitions to register.
	 *
	 * Hardcoded on purpose. Pro overrides this with an `envira_register_abilities`
	 * filter so its addons can register album, tag and social abilities; Lite has
	 * no addons, so it exposes no seam.
	 *
	 * @since 1.13.0
	 *
	 * @return array<string, array>
	 */
	public function get_ability_definitions(): array {
		return $this->get_common_ability_definitions();
	}
}
