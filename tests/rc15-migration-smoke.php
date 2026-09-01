<?php
declare(strict_types=1);

namespace {
	define( 'ABSPATH', __DIR__ . '/' );

	function untrailingslashit( string $value ): string { return rtrim( $value, '/\\' ); }
	function trailingslashit( string $value ): string { return untrailingslashit( $value ) . '/'; }
	function home_url( string $path = '' ): string { return 'https://infused.academy' . ( '/' === $path ? '/' : $path ); }
	function site_url( string $path = '' ): string { return 'https://infused.academy' . ( '/' === $path ? '/' : $path ); }
	function wp_parse_url( string $url ): array|false { return parse_url( $url ); }
	function wp_json_encode( mixed $value, int $flags = 0 ): string|false { return json_encode( $value, $flags ); }
	function is_serialized( mixed $data ): bool {
		if ( ! is_string( $data ) || '' === $data ) return false;
		$data = trim( $data );
		if ( 'N;' === $data ) return true;
		if ( strlen( $data ) < 4 || ':' !== $data[1] || ';' !== substr( $data, -1 ) && '}' !== substr( $data, -1 ) ) return false;
		return in_array( $data[0], [ 'a', 'O', 's', 'b', 'i', 'd', 'C', 'E' ], true );
	}

	final class SmokeWpdb {
		public string $prefix = 'wp_';
		public string $options = 'wp_options';
		public function remove_placeholder_escape( string $value ): string { return str_replace( '{smoke-percent}', '%', $value ); }
		public function prepare( string $format, mixed ...$args ): string {
			if ( '%s' !== $format || 1 !== count( $args ) ) throw new \RuntimeException( 'Unexpected prepare call.' );
			$value = (string) $args[0];
			$value = str_replace(
				[ "\\", "\0", "\n", "\r", "'", chr( 26 ) ],
				[ "\\\\", "\\0", "\\n", "\\r", "\\'", "\\Z" ],
				$value
			);
			return str_replace( '%', '{smoke-percent}', "'{$value}'" );
		}
	}
	$wpdb = new SmokeWpdb();
}

namespace CB\Backups\DB {
	final class Schema {
		public static function table(): string { return 'wp_cb_backups_jobs'; }
	}
}

namespace {
	require dirname( __DIR__ ) . '/src/DB/SqlValueCodec.php';
	require dirname( __DIR__ ) . '/src/Restore/MigrationPlan.php';
	require dirname( __DIR__ ) . '/src/Restore/MigrationTransformer.php';

	use CB\Backups\Restore\MigrationPlan;
	use CB\Backups\Restore\MigrationTransformer;

	function smoke_assert( bool $condition, string $message ): void {
		if ( ! $condition ) throw new \RuntimeException( $message );
	}

	$manifest = [
		'format' => 'core-blueprint-backup',
		'schema_version' => 1,
		'backup_type' => 'database',
		'database' => [
			'tables' => [ 'stg_options', 'stg_usermeta', 'stg_posts', 'stg_cb_certificate_artwork' ],
			'table_count' => 4,
		],
		'site' => [
			'home_url' => 'https://staging.infused.academy/',
			'site_url' => 'https://staging.infused.academy/',
			'table_prefix' => 'stg_',
			'multisite' => false,
		],
	];

	$plan = MigrationPlan::build( $manifest );
	smoke_assert( true === $plan['requires_migration'], 'Expected migration plan.' );
	smoke_assert( 'wp_options' === $plan['table_map']['stg_options'], 'Options table was not remapped.' );
	smoke_assert( 'wp_posts' === $plan['table_map']['stg_posts'], 'Posts table was not remapped.' );
	smoke_assert( 'wp_cb_certificate_artwork' === $plan['table_map']['stg_cb_certificate_artwork'], 'Certificate artwork table was not remapped.' );

	$nested_serialized = serialize( [ 'url' => 'https://staging.infused.academy/deep/path' ] );
	$serialized = serialize( [
		'url' => 'https://staging.infused.academy/course/test',
		'nested' => [ 'https://staging.infused.academy/path' ],
		'nested_serialized' => $nested_serialized,
	] );
	$json = '{"endpoint":"https:\/\/staging.infused.academy\/api"}';
	$large_artwork = str_repeat( "svg-rule:fill:red;https://staging.infused.academy/certificate/artwork\n", 20000 );
	smoke_assert( strlen( $large_artwork ) > 1000000, 'Certificate artwork fixture must exceed 1 MB.' );

	$sql = "-- Core Blueprint Backups database export\n-- Format version: 1\nSET FOREIGN_KEY_CHECKS=0;\n";
	$sql .= "\n-- CB TABLE: stg_options\nDROP TABLE IF EXISTS `stg_options`;\nCREATE TABLE `stg_options` (`option_id` bigint, `option_name` varchar(191), `option_value` longtext);\n";
	$sql .= "INSERT INTO `stg_options` (`option_id`, `option_name`, `option_value`) VALUES ('1', 'stg_user_roles', " . $GLOBALS['wpdb']->prepare( '%s', $serialized ) . "), ('2', 'api_json', " . $GLOBALS['wpdb']->prepare( '%s', $json ) . ");\n-- CB END TABLE: stg_options\n";
	$sql .= "\n-- CB TABLE: stg_usermeta\nDROP TABLE IF EXISTS `stg_usermeta`;\nCREATE TABLE `stg_usermeta` (`umeta_id` bigint, `meta_key` varchar(255), `meta_value` longtext);\n";
	$sql .= "INSERT INTO `stg_usermeta` (`umeta_id`, `meta_key`, `meta_value`) VALUES ('1', 'stg_capabilities', 'a:1:{s:13:\"administrator\";b:1;}'), ('2', 'stg_user_level', '10');\n-- CB END TABLE: stg_usermeta\n";
	$sql .= "\n-- CB TABLE: stg_posts\nDROP TABLE IF EXISTS `stg_posts`;\nCREATE TABLE `stg_posts` (`ID` bigint, `guid` varchar(255), `post_content` longtext);\n";
	$sql .= "INSERT INTO `stg_posts` (`ID`, `guid`, `post_content`) VALUES ('1', 'https://staging.infused.academy/?p=1', 'Visit https://staging.infused.academy/course/test');\n-- CB END TABLE: stg_posts\n";
	$sql .= "\n-- CB TABLE: stg_cb_certificate_artwork\nDROP TABLE IF EXISTS `stg_cb_certificate_artwork`;\nCREATE TABLE `stg_cb_certificate_artwork` (`id` bigint, `artwork` longtext);\n";
	// Deliberately keep physical newlines and semicolons inside the quoted artwork value.
	$sql .= "INSERT INTO `stg_cb_certificate_artwork` (`id`, `artwork`) VALUES ('1', '" . $large_artwork . "');\n-- CB END TABLE: stg_cb_certificate_artwork\nSET FOREIGN_KEY_CHECKS=1;\n";
	smoke_assert( substr_count( $sql, "\n" ) > 10000, 'Certificate artwork fixture must span physical SQL lines.' );

	$dir = sys_get_temp_dir() . '/cb-backups-rc15-' . bin2hex( random_bytes( 5 ) );
	if ( ! mkdir( $dir, 0700, true ) && ! is_dir( $dir ) ) throw new \RuntimeException( 'Could not create smoke test directory.' );
	$source = $dir . '/database.sql';
	$target = $dir . '/database-migrated.sql';
	file_put_contents( $source, $sql );

	$meta = [];
	for ( $i = 0; $i < 20; ++$i ) {
		$result = MigrationTransformer::tick( $source, $target, $meta, $plan );
		$meta = $result['meta'];
		if ( $result['done'] ) break;
	}
	smoke_assert( ! empty( $result['done'] ), 'Migration transformer did not finish.' );
	smoke_assert( ! empty( $meta['migration_transform_complete'] ), 'Migration completion marker is missing.' );
	$output = (string) file_get_contents( $target );

	smoke_assert( str_contains( $output, '-- CB TABLE: wp_options' ), 'Migrated options boundary missing.' );
	smoke_assert( str_contains( $output, 'INSERT INTO `wp_usermeta`' ), 'Migrated usermeta insert missing.' );
	smoke_assert( str_contains( $output, 'INSERT INTO `wp_cb_certificate_artwork`' ), 'Large multiline certificate artwork insert was not migrated.' );
	smoke_assert( str_contains( $output, "'wp_user_roles'" ), 'user_roles option key was not remapped.' );
	smoke_assert( str_contains( $output, "'wp_capabilities'" ), 'Capabilities meta key was not remapped.' );
	smoke_assert( str_contains( $output, "'wp_user_level'" ), 'User level meta key was not remapped.' );
	smoke_assert( str_contains( $output, 'https://infused.academy/course/test' ), 'Plain URL was not migrated.' );
	smoke_assert( str_contains( $output, 'https://infused.academy/certificate/artwork' ), 'Large multiline certificate artwork URL was not migrated.' );
	smoke_assert( ! str_contains( $output, 'https://staging.infused.academy/certificate/artwork' ), 'Source URL remains in migrated certificate artwork.' );
	$api_position = strpos( $output, "'api_json'" );
	smoke_assert( false !== $api_position, 'JSON migration fixture is missing.' );
	$api_fragment = substr( $output, (int) $api_position, 240 );
	smoke_assert( ! str_contains( $api_fragment, 'staging.infused.academy' ), 'JSON-escaped source URL remains after migration.' );
	smoke_assert( str_contains( $api_fragment, 'infused.academy' ), 'JSON-escaped target URL is missing after migration.' );
	smoke_assert( str_contains( $output, "'https://staging.infused.academy/?p=1'" ), 'Post GUID must remain unchanged.' );

	preg_match( "/'wp_user_roles', '([^']+)'/", $output, $serialized_match );
	smoke_assert( isset( $serialized_match[1] ), 'Serialized migrated value was not found.' );
	$migrated_serialized = str_replace( "\\'", "'", $serialized_match[1] );
	$decoded = unserialize( $migrated_serialized, [ 'allowed_classes' => false ] );
	smoke_assert( is_array( $decoded ), 'Migrated serialized value is invalid.' );
	smoke_assert( 'https://infused.academy/course/test' === $decoded['url'], 'Serialized URL was not migrated.' );
	smoke_assert( 'https://infused.academy/path' === $decoded['nested'][0], 'Nested serialized URL was not migrated.' );
	$decoded_nested = unserialize( (string) $decoded['nested_serialized'], [ 'allowed_classes' => false ] );
	smoke_assert( is_array( $decoded_nested ), 'Nested serialized payload is invalid after migration.' );
	smoke_assert( 'https://infused.academy/deep/path' === $decoded_nested['url'], 'Nested serialized payload URL was not migrated.' );

	$target_manifest = MigrationPlan::target_manifest( $manifest, $plan );
	smoke_assert( 'wp_' === $target_manifest['site']['table_prefix'], 'Target manifest prefix is wrong.' );
	smoke_assert( 'https://infused.academy/' === $target_manifest['site']['site_url'], 'Target manifest URL is wrong.' );
	smoke_assert( [ 'wp_options', 'wp_usermeta', 'wp_posts', 'wp_cb_certificate_artwork' ] === $target_manifest['database']['tables'], 'Target manifest table inventory is wrong.' );

	@unlink( $source ); @unlink( $target ); @rmdir( $dir );
	echo "RC15 migration smoke: PASS\n";
}
