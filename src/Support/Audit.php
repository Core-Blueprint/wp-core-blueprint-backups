<?php
declare(strict_types=1);

namespace CB\Backups\Support;

defined( 'ABSPATH' ) || exit;

final class Audit {
	/** @param array<string,mixed> $context */
	public static function log( string $event, string $severity = 'info', array $context = [] ): void {
		if ( class_exists( '\\CB\\Core\\Governance\\Audit' ) ) {
			\CB\Core\Governance\Audit::record( $event, $severity, self::sanitise_context( $context ) );
		}
	}

	/** @param array<string,mixed> $context @return array<string,mixed> */
	private static function sanitise_context( array $context ): array {
		unset( $context['password'], $context['key'], $context['secret'], $context['token'], $context['sql'], $context['path'] );
		return $context;
	}
}
