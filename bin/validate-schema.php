<?php
/**
 * Dev tool: validate JSON-LD in saved HTML pages with MG SEO's validator (Google rich result rules).
 *
 * Usage (inside the cli container): wp eval-file /repo/bin/validate-schema.php /repo/.local/page1.html [...]
 *
 * @package MGSEO
 */

// phpcs:disable WordPress.WP.AlternativeFunctions,WordPress.Security.EscapeOutput,WordPress.NamingConventions.PrefixAllGlobals

$exit = 0;
foreach ( $args as $file ) {
	$html = (string) file_get_contents( $file );
	preg_match_all( '#<script type="application/ld\+json"[^>]*>(.*?)</script>#s', $html, $m );
	echo "== " . basename( $file ) . ' (' . count( $m[1] ) . " JSON-LD script(s))\n";
	foreach ( $m[1] as $json ) {
		$data = json_decode( $json, true );
		if ( ! is_array( $data ) ) {
			echo "  INVALID JSON\n";
			$exit = 1;
			continue;
		}
		$nodes = $data['@graph'] ?? array( $data );
		foreach ( MGSEO\Modules\Schema\Validator::validate_all( $nodes ) as $row ) {
			$status = $row['errors'] ? 'ERROR' : ( $row['warnings'] ? 'warn ' : 'ok   ' );
			if ( $row['errors'] ) {
				$exit = 1;
			}
			echo "  {$status} {$row['type']}" . ( $row['errors'] ? ' :: ' . implode( '; ', $row['errors'] ) : '' ) . ( $row['warnings'] ? ' (warnings: ' . implode( '; ', $row['warnings'] ) . ')' : '' ) . "\n";
		}
	}
}
exit( $exit );
