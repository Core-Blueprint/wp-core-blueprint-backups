<?php
declare(strict_types=1);

namespace CB\Backups\Support;

use WP_User;

defined( 'ABSPATH' ) || exit;

final class Capabilities {
	public const MANAGE = 'cb_manage_backups';

	public static function boot(): void {
		add_filter( 'user_has_cap', [ self::class, 'derive_manage_cap' ], 20, 4 );
		add_filter( 'core_blueprint_capability_catalog', [ self::class, 'catalog' ] );
	}

	/**
	 * Backups is a high-impact surface. Its capability is deliberately derived
	 * from already-governed Base trust anchors rather than stored independently:
	 * administrators (`manage_options`) and CB operators (`cb_manage_permissions`).
	 * A direct DB injection of cb_manage_backups therefore cannot bypass Base's
	 * privileged-access boundary.
	 *
	 * @param array<string,bool> $allcaps
	 * @param string[]           $caps
	 * @param array<int,mixed>   $args
	 * @return array<string,bool>
	 */
	public static function derive_manage_cap( array $allcaps, array $caps, array $args, WP_User $user ): array {
		if ( ! in_array( self::MANAGE, $caps, true ) && ! array_key_exists( self::MANAGE, $allcaps ) ) {
			return $allcaps;
		}
		$allcaps[ self::MANAGE ] = ! empty( $allcaps['manage_options'] ) || ! empty( $allcaps['cb_manage_permissions'] );
		return $allcaps;
	}

	/** @param array<string,array<string,mixed>> $catalog @return array<string,array<string,mixed>> */
	public static function catalog( array $catalog ): array {
		$catalog[ self::MANAGE ] = [
			'label'       => __( 'Manage backups', 'core-blueprint-backups' ),
			'group'       => __( 'Core Blueprint', 'core-blueprint-backups' ),
			'source'      => 'Core Blueprint Backups',
			'description' => __( 'Create, download, verify, schedule, delete and restore Core Blueprint backups. This authority is derived from Administrator or CB Operator trust and cannot be granted independently.', 'core-blueprint-backups' ),
		];
		return $catalog;
	}
}
