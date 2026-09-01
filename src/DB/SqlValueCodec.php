<?php
declare(strict_types=1);

namespace CB\Backups\DB;

use RuntimeException;

defined( 'ABSPATH' ) || exit;

/** The format-v1 SQL literal contract. Values are never sanitized as application data. */
final class SqlValueCodec {
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
