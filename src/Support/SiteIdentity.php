<?php
declare(strict_types=1);

namespace CB\Backups\Support;

use RuntimeException;

defined( 'ABSPATH' ) || exit;

/** Configured identity must not depend on a web/cron/CLI request's TLS context. */
final class SiteIdentity {
	public static function current(): array {
		$site = (string) get_option( 'siteurl', '' );
		$home = (string) get_option( 'home', $site );
		if ( '' === $home ) $home = $site;
		$result = [];
		foreach ( [ 'home_url' => $home, 'site_url' => $site ] as $key => $url ) {
			$parts = wp_parse_url( $url );
			if ( ! is_array( $parts ) || empty( $parts['host'] ) || ! in_array( strtolower( (string) ( $parts['scheme'] ?? '' ) ), [ 'http', 'https' ], true ) ) throw new RuntimeException( 'Configured WordPress site identity is invalid.' );
			$result[ $key ] = trailingslashit( $url );
		}
		return $result;
	}
}
