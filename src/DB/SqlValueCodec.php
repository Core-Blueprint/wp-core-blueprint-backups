<?php
declare(strict_types=1);

namespace CB\Backups\DB;

use RuntimeException;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exceptions are internal diagnostics; UI/HTTP presentation boundaries escape them.

/** The format-v1 SQL literal contract. Values are never sanitized as application data. */
final class SqlValueCodec {
	/**
	 * Run one resumable dump/import tick with deterministic SQL semantics.
	 *
	 * TIMESTAMP values are converted by MySQL/MariaDB through the current
	 * session time zone. For portable backups both export reads and restore
	 * writes therefore run in UTC, independent of the source/destination host.
	 * The caller's SQL mode and time zone are restored before returning.
	 */
	public static function with_dump_mode( callable $callback ): array {
		global $wpdb;

		$old_mode = $wpdb->get_var( 'SELECT @@SESSION.SQL_MODE' );
		if ( null === $old_mode || '' !== $wpdb->last_error ) throw new RuntimeException( 'Cannot inspect database SQL mode.' );

		$old_time_zone = $wpdb->get_var( 'SELECT @@SESSION.time_zone' );
		if ( null === $old_time_zone || '' !== $wpdb->last_error ) throw new RuntimeException( 'Cannot inspect database time zone.' );

		$mode = implode( ',', array_filter( explode( ',', (string) $old_mode ), static fn ( string $flag ): bool => 'NO_BACKSLASH_ESCAPES' !== strtoupper( $flag ) ) );
		$mode_changed = false;
		$time_zone_changed = false;

		try {
			if ( false === $wpdb->query( $wpdb->prepare( 'SET SESSION SQL_MODE=%s', $mode ) ) ) {
				throw new RuntimeException( 'Cannot set database dump SQL mode.' );
			}
			$mode_changed = true;

			if ( false === $wpdb->query( $wpdb->prepare( 'SET SESSION time_zone=%s', '+00:00' ) ) ) {
				throw new RuntimeException( 'Cannot set database dump time zone to UTC.' );
			}
			$time_zone_changed = true;

			return $callback();
		} finally {
			$restore_errors = [];
			if ( $time_zone_changed && false === $wpdb->query( $wpdb->prepare( 'SET SESSION time_zone=%s', (string) $old_time_zone ) ) ) {
				$restore_errors[] = 'time zone';
			}
			if ( $mode_changed && false === $wpdb->query( $wpdb->prepare( 'SET SESSION SQL_MODE=%s', (string) $old_mode ) ) ) {
				$restore_errors[] = 'SQL mode';
			}
			if ( $restore_errors ) {
				throw new RuntimeException( 'Cannot restore database session ' . implode( ' and ', $restore_errors ) . '.' );
			}
		}
	}

	public static function encode( ?string $value, bool $binary = false ): string {
		if ( null === $value ) return 'NULL';
		if ( $binary ) return "UNHEX('" . bin2hex( $value ) . "')";
		global $wpdb;
		$prepared = $wpdb->prepare( '%s', $value );
		if ( ! is_string( $prepared ) || strlen( $prepared ) < 2 ) {
			throw new RuntimeException( 'Database value could not be encoded.' );
		}
		// prepare() protects literal percent signs until query execution. A dump
		// is not executed by this request, so remove that request-local state now.
		return $wpdb->remove_placeholder_escape( $prepared );
	}

	public static function decode( string $token ): ?string {
		if ( 'NULL' === strtoupper( $token ) ) return null;
		if ( preg_match( "/^UNHEX\\('([0-9a-f]*)'\\)$/iD", $token, $match ) ) {
			$value = hex2bin( $match[1] );
			if ( false === $value ) throw new RuntimeException( 'Invalid binary SQL value.' );
			return $value;
		}
		if ( strlen( $token ) < 2 || "'" !== $token[0] || "'" !== $token[ strlen( $token ) - 1 ] ) {
			throw new RuntimeException( 'Unsupported SQL value token.' );
		}
		$body = substr( $token, 1, -1 );
		$out = '';
		$length = strlen( $body );
		for ( $i = 0; $i < $length; ++$i ) {
			$char = $body[ $i ];
			if ( "'" === $char ) {
				if ( $i + 1 >= $length || "'" !== $body[ $i + 1 ] ) throw new RuntimeException( 'Unescaped SQL quote.' );
				$out .= "'";
				++$i;
				continue;
			}
			if ( '\\' !== $char ) { $out .= $char; continue; }
			if ( ++$i >= $length ) throw new RuntimeException( 'Incomplete SQL escape.' );
			$next = $body[ $i ];
			$out .= match ( $next ) {
				'0' => "\0", 'n' => "\n", 'r' => "\r", 'Z' => chr( 26 ),
				default => $next,
			};
		}
		return $out;
	}
}
