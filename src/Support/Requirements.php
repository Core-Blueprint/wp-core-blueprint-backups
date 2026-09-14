<?php
declare(strict_types=1);

namespace CB\Backups\Support;

defined( 'ABSPATH' ) || exit;

/** Bootstrap v1 and Backups product readiness. */
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

	/** @return string[] Stable Bootstrap v1 issue IDs. */
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

	/** Canonical untranslated activation explanation. */
	public static function activation_message(): string {
		$issue = self::primary_issue();

		switch ( $issue ) {
			case 'php-version':
				return sprintf(
					'PHP %1$s or newer is required. This server runs PHP %2$s.',
					CB_BACKUPS_MIN_PHP,
					PHP_VERSION
				);

			case 'base-missing':
				return 'Core Blueprint must be installed and active.';

			case 'base-api-incompatible':
				return sprintf(
					'Core API %1$s or a newer compatible minor version is required. Available Core API: %2$s.',
					CB_BACKUPS_REQUIRED_API,
					defined( 'CB_CORE_API_VERSION' ) ? (string) CB_CORE_API_VERSION : 'none'
				);

			default:
				return 'Ready';
		}
	}

	public static function operator_message(): string {
		$issue = self::primary_issue();

		switch ( $issue ) {
			case 'php-version':
				return sprintf(
					/* translators: 1: required PHP version, 2: current PHP version. */
					__( 'PHP %1$s or newer is required. This server runs PHP %2$s.', 'core-blueprint-backups' ),
					CB_BACKUPS_MIN_PHP,
					PHP_VERSION
				);

			case 'base-missing':
				return __( 'Core Blueprint must be installed and active.', 'core-blueprint-backups' );

			case 'base-api-incompatible':
				return sprintf(
					/* translators: 1: required Core API version, 2: available Core API version. */
					__( 'Core API %1$s or a newer compatible minor version is required. Available Core API: %2$s.', 'core-blueprint-backups' ),
					CB_BACKUPS_REQUIRED_API,
					defined( 'CB_CORE_API_VERSION' ) ? (string) CB_CORE_API_VERSION : __( 'none', 'core-blueprint-backups' )
				);

			default:
				return __( 'Ready', 'core-blueprint-backups' );
		}
	}

	/** Product-specific public Base contracts; intentionally outside Bootstrap v1. */
	public static function base_contracts_ready(): bool {
		return class_exists( '\\CB\\Core\\Database\\SchemaRegistry' )
			&& interface_exists( '\\CB\\Core\\Admin\\Page' )
			&& class_exists( '\\CB\\Core\\Admin\\PageRegistry' )
			&& class_exists( '\\CB\\Core\\Admin\\SettingsRegistry' )
			&& class_exists( '\\CB\\Core\\ExtensionRegistry' )
			&& class_exists( '\\CB\\Core\\Governance\\Audit' )
			&& class_exists( '\\CB\\Core\\Governance\\EventRegistry' );
	}

	public static function product_ready(): bool {
		return self::base_contracts_ready() && class_exists( 'ZipArchive' );
	}

	/** Untranslated product activation explanation for the early activation path. */
	public static function product_activation_message(): string {
		if ( ! self::base_contracts_ready() ) {
			return 'Required Core Blueprint Base contracts are unavailable.';
		}
		if ( ! class_exists( 'ZipArchive' ) ) {
			return 'Core Blueprint Backups requires the PHP ZIP extension.';
		}
		return 'Ready';
	}

	public static function product_operator_message(): string {
		if ( ! self::base_contracts_ready() ) {
			return __( 'Required Core Blueprint Base contracts are unavailable.', 'core-blueprint-backups' );
		}
		if ( ! class_exists( 'ZipArchive' ) ) {
			return __( 'Core Blueprint Backups requires the PHP ZIP extension.', 'core-blueprint-backups' );
		}
		return __( 'Ready', 'core-blueprint-backups' );
	}

	private static function primary_issue(): string {
		$issues = self::issues();
		return (string) ( $issues[0] ?? '' );
	}
}
