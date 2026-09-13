<?php
declare(strict_types=1);

namespace CB\Backups\Support;

defined( 'ABSPATH' ) || exit;

/** Bootstrap v1 dependency readiness only: PHP, Base presence and Core API. */
final class Requirements {
	public static function api_compatible( string $available, string $required ): bool {
		if ( 1 !== preg_match( '/^(\d+)\.(\d+)$/', $available, $available_match ) ) {
			return false;
		}
		if ( 1 !== preg_match( '/^(\d+)\.(\d+)$/', $required, $required_match ) ) {
			return false;
		}

		return (int) $available_match[1] === (int) $required_match[1]
			&& (int) $available_match[2] >= (int) $required_match[2];
	}

	/** @return string[] */
	public static function issues(): array {
		$issues = [];

		if ( version_compare( PHP_VERSION, CB_BACKUPS_MIN_PHP, '<' ) ) {
			$issues[] = 'php-version';
		}

		if ( ! defined( 'CB_CORE_API_VERSION' ) ) {
			$issues[] = 'base-missing';
			return $issues;
		}

		if ( ! self::api_compatible( (string) CB_CORE_API_VERSION, CB_BACKUPS_REQUIRED_API ) ) {
			$issues[] = 'base-api-incompatible';
		}

		return array_values( array_unique( $issues ) );
	}

	public static function runtime_ready(): bool {
		return [] === self::issues();
	}

	public static function operator_message(): string {
		$issues = self::issues();
		$issue  = (string) ( $issues[0] ?? '' );

		switch ( $issue ) {
			case 'php-version':
				return sprintf(
					/* translators: 1: required PHP version, 2: current PHP version. */
					__( 'PHP %1$s or newer is required. This server runs PHP %2$s.', 'core-blueprint-backups' ),
					CB_BACKUPS_MIN_PHP,
					PHP_VERSION
				);

			case 'base-missing':
				return __( 'An active Core Blueprint Base installation is required.', 'core-blueprint-backups' );

			case 'base-api-incompatible':
				return sprintf(
					/* translators: 1: required Core API version, 2: available Core API version. */
					__( 'Core API %1$s or a newer compatible minor version is required. This site provides %2$s.', 'core-blueprint-backups' ),
					CB_BACKUPS_REQUIRED_API,
					defined( 'CB_CORE_API_VERSION' ) ? (string) CB_CORE_API_VERSION : __( 'none', 'core-blueprint-backups' )
				);

			default:
				return __( 'Ready', 'core-blueprint-backups' );
		}
	}
}
