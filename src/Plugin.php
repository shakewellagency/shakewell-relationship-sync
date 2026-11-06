<?php
/**
 * Plugin bootstrap.
 *
 * @package Shakewell\RelationshipSync
 */

namespace Shakewell\RelationshipSync;

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/Sync.php';
require_once __DIR__ . '/Cli.php';

/**
 * Wires the plugin into WordPress.
 */
final class Plugin {

	/**
	 * Registers hooks. Called on plugins_loaded.
	 */
	public static function boot(): void {
		foreach ( Sync::TYPES as $type ) {
			add_filter( 'acf/update_value/type=' . $type, array( Sync::class, 'on_update' ), 11, 3 );
		}
		add_action( 'admin_notices', array( self::class, 'missing_acf_notice' ) );
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'shakewell-relationship-sync', Cli::class );
		}
	}

	/**
	 * ACF is a runtime requirement (free or Pro), so check late and say so.
	 *
	 * @param bool|null $acf_active Whether ACF is active; null to detect it.
	 */
	public static function missing_acf_notice( ?bool $acf_active = null ): void {
		$acf_active ??= function_exists( 'acf_get_field_groups' );
		if ( $acf_active || ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		printf(
			'<div class="notice notice-error"><p>%s</p></div>',
			esc_html__( 'Shakewell Two-way Relationships for ACF needs Advanced Custom Fields (free or Pro) to be installed and active.', 'shakewell-relationship-sync' )
		);
	}
}
