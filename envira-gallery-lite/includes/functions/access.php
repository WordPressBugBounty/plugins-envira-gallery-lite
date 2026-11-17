<?php
/**
 * Access helper functions.
 *
 * Loaded globally (frontend + admin) via require_global().
 *
 * @package Envira_Gallery
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'envira_current_user_can' ) ) {
	/**
	 * Check whether the current user has access to the given Envira capability.
	 *
	 * @since 1.13.0
	 *
	 * @param array|string $caps Capability name, 'any', or array of capability names.
	 * @param int          $id   Optional gallery ID for meta-cap ownership checks.
	 * @return bool
	 */
	function envira_current_user_can( $caps = [], int $id = 0 ): bool {
		static $cache = [];

		// Build cache key — keep $caps as-is (string 'any' must reach class as string).
		$caps_str = is_array( $caps ) ? implode( ' ', $caps ) : (string) $caps;

		// The parts are joined with a delimiter: plain concatenation collided, so
		// ( 'x', 1, 23 ) and ( 'x', 12, 3 ) both hashed "x123" and the second
		// check returned the first one's answer. The gallery's status is in the
		// key too, because the meta-cap map now resolves a status-dependent
		// capability — a published gallery also needs edit_published_*.
		$status    = $id > 0 ? (string) get_post_status( $id ) : '';
		$cache_key = md5( $caps_str . '|' . $id . '|' . $status . '|' . get_current_user_id() );

		if ( isset( $cache[ $cache_key ] ) ) {
			return $cache[ $cache_key ];
		}

		$result = envira_get_access()->current_user_can( $caps, $id );
		$result = (bool) apply_filters( 'envira_current_user_can', $result, $caps, $id );

		$cache[ $cache_key ] = $result;
		return $cache[ $cache_key ];
	}
}

if ( ! function_exists( 'envira_get_access' ) ) {
	/**
	 * Return the Envira Access\Capabilities singleton.
	 *
	 * @since 1.13.0
	 *
	 * @return \Envira\Access\Capabilities
	 */
	function envira_get_access(): \Envira\Access\Capabilities {
		return \Envira\Access\Capabilities::instance();
	}
}

if ( ! function_exists( 'envira_get_capability_manage_options' ) ) {
	/**
	 * Return the capability name that gates Envira admin options.
	 *
	 * Cached per-request; the filter fires once regardless of how many cap checks run.
	 *
	 * @since 1.13.0
	 *
	 * @return string
	 */
	function envira_get_capability_manage_options(): string {
		static $cap = null;
		if ( null === $cap ) {
			$cap = (string) apply_filters( 'envira_manage_options_cap', 'manage_options' );
		}
		return $cap;
	}
}
