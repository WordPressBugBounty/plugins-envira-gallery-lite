<?php
/**
 * Envira Gallery — AI & MCP settings tab.
 *
 * Two jobs:
 *
 * 1. Own the write opt-in. Envira's write abilities refuse to execute, and are
 *    hidden from MCP entirely, until an administrator turns them on here.
 * 2. Tell the administrator whether an MCP bridge is installed. Abilities with
 *    no bridge are registered but unreachable by any AI agent, which is the
 *    single most common reason "the abilities do nothing".
 *
 * @since 1.13.0
 *
 * @package Envira_Gallery
 */

namespace Envira\Admin;

use Envira\Access\Abilities;
use Envira\Access\AbstractAbilities;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Abilities_Settings — the AI & MCP tab under Envira Gallery → Settings.
 *
 * @since 1.13.0
 */
class Abilities_Settings {

	/**
	 * Nonce action and field name for the write toggle form.
	 *
	 * @since 1.13.0
	 *
	 * @var string
	 */
	const NONCE = 'envira-abilities-nonce';

	/**
	 * Known MCP bridge plugins, keyed by wp.org slug.
	 *
	 * @since 1.13.0
	 *
	 * @var array<string, array{basename: string, label: string}>
	 */
	const BRIDGES = [
		'vibe-ai'     => [
			'basename' => 'vibe-ai/vibe-ai.php',
			'label'    => 'WPVibe',
		],
		'mcp-adapter' => [
			'basename' => 'mcp-adapter/mcp-adapter.php',
			'label'    => 'MCP Adapter',
		],
	];

	/**
	 * Register hooks.
	 *
	 * @since 1.13.0
	 *
	 * @return void
	 */
	public function hooks() {
		add_filter( 'envira_gallery_settings_tab_nav', [ $this, 'add_tab' ] );
		add_action( 'envira_gallery_tab_settings_ai_mcp', [ $this, 'render_tab' ] );
		add_action( 'admin_init', [ $this, 'maybe_save' ] );
	}

	/**
	 * Add the AI & MCP tab.
	 *
	 * Hidden on WordPress < 6.9, where there is no Abilities API to configure.
	 *
	 * @since 1.13.0
	 *
	 * @param array $tabs Existing settings tabs.
	 * @return array
	 */
	public function add_tab( $tabs ) {
		if ( ! is_array( $tabs ) || ! $this->abilities_api_available() ) {
			return $tabs;
		}

		$tabs['ai_mcp'] = __( 'AI & MCP', 'envira-gallery-lite' );

		return $tabs;
	}

	/**
	 * Persist the write toggle.
	 *
	 * @since 1.13.0
	 *
	 * @return void
	 */
	public function maybe_save() {
		if ( ! isset( $_POST['envira-abilities-submit'] ) ) {
			return;
		}

		if ( ! isset( $_POST[ self::NONCE ] ) ) {
			return;
		}

		$nonce = sanitize_text_field( wp_unslash( $_POST[ self::NONCE ] ) );

		// Both rejections below fail closed, which is correct — but say so.
		// Returning silently redisplayed the stored value, so a submission
		// discarded by an expired nonce looked identical to one that saved.
		if ( ! wp_verify_nonce( $nonce, self::NONCE ) ) {
			add_action( 'envira_gallery_settings_ai_mcp_tab_notice', [ $this, 'expired_notice' ] );

			return;
		}

		if ( ! current_user_can( envira_get_capability_manage_options() ) ) {
			add_action( 'envira_gallery_settings_ai_mcp_tab_notice', [ $this, 'forbidden_notice' ] );

			return;
		}

		$enabled = ! empty( $_POST['envira_abilities_write_enabled'] );

		$value = $enabled ? 1 : 0;

		update_option( AbstractAbilities::WRITE_SETTING_OPTION, $value );

		// update_option() returns false both when the write fails and when the
		// value is unchanged, so read it back. This option is the write gate:
		// reporting a revoke as saved when it was not would leave agents writing.
		if ( (int) get_option( AbstractAbilities::WRITE_SETTING_OPTION, 0 ) !== $value ) {
			add_action( 'envira_gallery_settings_ai_mcp_tab_notice', [ $this, 'save_failed_notice' ] );

			return;
		}

		add_action( 'envira_gallery_settings_ai_mcp_tab_notice', [ $this, 'saved_notice' ] );
	}

	/**
	 * Output the "session expired" notice.
	 *
	 * @since 1.13.0
	 *
	 * @return void
	 */
	public function expired_notice() {
		?>
		<div class="notice notice-error inline">
			<p><?php esc_html_e( 'Your session expired, so the AI & MCP settings were not saved. Please try again.', 'envira-gallery-lite' ); ?></p>
		</div>
		<?php
	}

	/**
	 * Output the "not permitted" notice.
	 *
	 * @since 1.13.0
	 *
	 * @return void
	 */
	public function forbidden_notice() {
		?>
		<div class="notice notice-error inline">
			<p><?php esc_html_e( 'You do not have permission to change the AI & MCP settings.', 'envira-gallery-lite' ); ?></p>
		</div>
		<?php
	}

	/**
	 * Output the "settings not saved" notice.
	 *
	 * @since 1.13.0
	 *
	 * @return void
	 */
	public function save_failed_notice() {
		?>
		<div class="notice notice-error inline">
			<p><?php esc_html_e( 'The AI & MCP settings could not be saved, so the previous setting is still in effect. Please try again.', 'envira-gallery-lite' ); ?></p>
		</div>
		<?php
	}

	/**
	 * Output the "settings saved" notice.
	 *
	 * @since 1.13.0
	 *
	 * @return void
	 */
	public function saved_notice() {
		?>
		<div class="notice notice-success inline">
			<p><?php esc_html_e( 'AI & MCP settings saved.', 'envira-gallery-lite' ); ?></p>
		</div>
		<?php
	}

	/**
	 * Render the tab.
	 *
	 * @since 1.13.0
	 *
	 * @return void
	 */
	public function render_tab() {
		$handler       = Abilities::instance();
		$write_enabled = $handler->write_enabled();
		$bridge        = $this->get_active_bridge();
		$abilities     = $this->get_registered_abilities();
		$failed        = Abilities::instance()->get_failed_registrations();
		$category_ok   = Abilities::instance()->is_category_registered();
		$settings_url  = add_query_arg(
			[
				'post_type' => 'envira',
				'page'      => 'envira-gallery-settings',
			],
			admin_url( 'edit.php' )
		);
		?>
		<div id="envira-settings-ai-mcp">
			<?php do_action( 'envira_gallery_settings_ai_mcp_tab_notice' ); ?>

			<p class="description">
				<?php
				esc_html_e( 'Envira registers your galleries with the WordPress Abilities API so AI assistants can list, read and edit them. Reading is always available to users who can already see galleries. Editing is off until you turn it on below.', 'envira-gallery-lite' );
				?>
			</p>

			<form action="<?php echo esc_url( $settings_url . '#!envira-tab-ai_mcp' ); ?>" method="post">
				<table class="form-table">
					<tbody>
						<tr id="envira-settings-abilities-write">
							<th scope="row">
								<label for="envira_abilities_write_enabled"><?php esc_html_e( 'Allow AI to edit galleries', 'envira-gallery-lite' ); ?></label>
							</th>
							<td>
								<input
									type="checkbox"
									id="envira_abilities_write_enabled"
									name="envira_abilities_write_enabled"
									value="1"
									<?php checked( $write_enabled ); ?>
								/>
								<p class="description">
									<?php
									esc_html_e( 'When off, AI assistants can read galleries but cannot create, update, delete or add images to them, and the editing tools are hidden from them entirely. Users still need the matching Envira permission either way.', 'envira-gallery-lite' );
									?>
								</p>
							</td>
						</tr>
						<tr>
							<?php wp_nonce_field( self::NONCE, self::NONCE ); ?>
							<th scope="row"><?php submit_button( __( 'Save', 'envira-gallery-lite' ), 'primary', 'envira-abilities-submit', false ); ?></th>
							<td>&nbsp;</td>
						</tr>
					</tbody>
				</table>
			</form>

			<h3><?php esc_html_e( 'AI assistant connection', 'envira-gallery-lite' ); ?></h3>


			<?php if ( $bridge ) : ?>
				<p>
					<?php
					printf(
						/* translators: %s: bridge plugin name. */
						esc_html__( '%s is active. Your galleries are reachable by any AI assistant connected through it.', 'envira-gallery-lite' ),
						'<strong>' . esc_html( $bridge['label'] ) . '</strong>'
					);
					?>
				</p>
			<?php else : ?>
				<p>
					<?php
					esc_html_e( 'Abilities on their own are not reachable by an AI assistant — a bridge plugin has to expose them. None is active on this site, so nothing below is visible to any assistant yet.', 'envira-gallery-lite' );
					?>
				</p>
				<?php $this->render_bridge_actions(); ?>
			<?php endif; ?>

			<h3><?php esc_html_e( 'Gallery abilities', 'envira-gallery-lite' ); ?></h3>

			<?php if ( ! $category_ok ) : ?>
				<div class="notice notice-error inline">
					<p>
						<?php
						printf(
							/* translators: %s: ability category name. */
							esc_html__( 'WordPress rejected the %s ability category, so none of the abilities below could be registered. Check the debug log for details.', 'envira-gallery-lite' ),
							'<code>' . esc_html( AbstractAbilities::ABILITY_NAMESPACE ) . '</code>'
						);
						?>
					</p>
				</div>
			<?php elseif ( $failed ) : ?>
				<div class="notice notice-error inline">
					<p>
						<?php
						printf(
							/* translators: %s: comma-separated ability names. */
							esc_html__( 'WordPress rejected these abilities, so no assistant can use them: %s. Check the debug log for details.', 'envira-gallery-lite' ),
							'<code>' . implode( '</code>, <code>', array_map( 'esc_html', $failed ) ) . '</code>'
						);
						?>
					</p>
				</div>
			<?php endif; ?>

			<p class="description">
				<?php
				esc_html_e( 'These are the abilities Envira declares. An assistant sees them only once WordPress has registered them, which happens on the requests it makes rather than on this screen.', 'envira-gallery-lite' );
				?>
			</p>

			<?php if ( empty( $abilities ) ) : ?>
				<p><?php esc_html_e( 'Envira declares no abilities on this request.', 'envira-gallery-lite' ); ?></p>
			<?php else : ?>
				<table class="widefat striped">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'Ability', 'envira-gallery-lite' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Type', 'envira-gallery-lite' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Visible to AI', 'envira-gallery-lite' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $abilities as $ability ) : ?>
							<tr>
								<td>
									<strong><?php echo esc_html( $ability['label'] ); ?></strong><br />
									<code><?php echo esc_html( $ability['name'] ); ?></code>
								</td>
								<td>
									<?php
									if ( $ability['destructive'] ) {
										esc_html_e( 'Deletes data', 'envira-gallery-lite' );
									} elseif ( $ability['readonly'] ) {
										esc_html_e( 'Read only', 'envira-gallery-lite' );
									} else {
										esc_html_e( 'Edits data', 'envira-gallery-lite' );
									}
									?>
								</td>
								<td>
									<?php
									echo $ability['mcp_public']
										? esc_html__( 'Yes', 'envira-gallery-lite' )
										: esc_html__( 'Hidden', 'envira-gallery-lite' );
									?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Output install/activate links for the known bridge plugins.
	 *
	 * Uses the core plugin install and activate screens rather than fetching or
	 * unpacking anything directly.
	 *
	 * @since 1.13.0
	 *
	 * @return void
	 */
	private function render_bridge_actions() {
		$installed = $this->get_installed_plugins();

		echo '<p>';

		foreach ( self::BRIDGES as $slug => $bridge ) {
			$is_installed = isset( $installed[ $bridge['basename'] ] );

			if ( $is_installed && current_user_can( 'activate_plugins' ) ) {
				$url = wp_nonce_url(
					self_admin_url( 'plugins.php?action=activate&plugin=' . rawurlencode( $bridge['basename'] ) ),
					'activate-plugin_' . $bridge['basename']
				);

				printf(
					'<a class="button" href="%1$s">%2$s</a> ',
					esc_url( $url ),
					/* translators: %s: bridge plugin name. */
					esc_html( sprintf( __( 'Activate %s', 'envira-gallery-lite' ), $bridge['label'] ) )
				);

				continue;
			}

			if ( ! $is_installed && current_user_can( 'install_plugins' ) ) {
				$url = self_admin_url( 'plugin-install.php?tab=plugin-information&plugin=' . rawurlencode( $slug ) );

				printf(
					'<a class="button" href="%1$s">%2$s</a> ',
					esc_url( $url ),
					/* translators: %s: bridge plugin name. */
					esc_html( sprintf( __( 'Get %s', 'envira-gallery-lite' ), $bridge['label'] ) )
				);
			}
		}

		echo '</p>';
	}

	/**
	 * Return the first active bridge plugin, if any.
	 *
	 * @since 1.13.0
	 *
	 * @return array{basename: string, label: string}|null
	 */
	private function get_active_bridge() {
		if ( ! function_exists( 'is_plugin_active' ) ) {
			include_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		foreach ( self::BRIDGES as $bridge ) {
			if ( is_plugin_active( $bridge['basename'] ) ) {
				return $bridge;
			}
		}

		// An adapter may also be loaded as a library rather than a plugin.
		if ( class_exists( '\\WP\\MCP\\Core\\McpAdapter' ) ) {
			return self::BRIDGES['mcp-adapter'];
		}

		return null;
	}

	/**
	 * Return the installed plugin list.
	 *
	 * @since 1.13.0
	 *
	 * @return array
	 */
	private function get_installed_plugins(): array {
		if ( ! function_exists( 'get_plugins' ) ) {
			include_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		return (array) get_plugins();
	}

	/**
	 * Summarise the Envira abilities registered on this request.
	 *
	 * Reads the definitions from the handler rather than the registry: the
	 * registry is only populated on requests where `wp_abilities_api_init` has
	 * already fired, which is not guaranteed on a settings screen.
	 *
	 * @since 1.13.0
	 *
	 * @return array<int, array{name: string, label: string, readonly: bool, destructive: bool, mcp_public: bool}>
	 */
	private function get_registered_abilities(): array {
		if ( ! $this->abilities_api_available() ) {
			return [];
		}

		$rows = [];

		foreach ( Abilities::instance()->get_ability_definitions() as $name => $args ) {
			if ( ! is_array( $args ) ) {
				continue;
			}

			$annotations = isset( $args['meta']['annotations'] ) && is_array( $args['meta']['annotations'] )
				? $args['meta']['annotations']
				: [];

			$rows[] = [
				'name'        => (string) $name,
				'label'       => isset( $args['label'] ) ? (string) $args['label'] : (string) $name,
				'readonly'    => ! empty( $annotations['readonly'] ),
				'destructive' => ! empty( $annotations['destructive'] ),
				'mcp_public'  => ! empty( $args['meta']['mcp']['public'] ),
			];
		}

		return $rows;
	}

	/**
	 * Whether the WordPress Abilities API is present.
	 *
	 * @since 1.13.0
	 *
	 * @return bool
	 */
	private function abilities_api_available(): bool {
		return Abilities::instance()->is_abilities_api_available();
	}
}
