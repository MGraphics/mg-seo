<?php
/**
 * Minimal WP-CLI stand-in so command classes can be tested without WP-CLI (records output).
 *
 * @package MGSEO
 */

// phpcs:disable Generic.Files.OneObjectStructurePerFile, Universal.Namespaces.DisallowCurlyBraceSyntax, Universal.Namespaces.OneDeclarationPerFile

namespace {
	if ( ! class_exists( 'WP_CLI' ) ) {
		/**
		 * Recording stub.
		 */
		class WP_CLI {
			/**
			 * Recorded lines: array( type, message ).
			 *
			 * @var array<int, array{0: string, 1: string}>
			 */
			public static array $lines = array();

			public static function log( $message ): void {
				self::$lines[] = array( 'log', (string) $message );
			}

			public static function success( $message ): void {
				self::$lines[] = array( 'success', (string) $message );
			}

			public static function warning( $message ): void {
				self::$lines[] = array( 'warning', (string) $message );
			}

			public static function error( $message ): void {
				self::$lines[] = array( 'error', (string) $message );
				throw new \RuntimeException( (string) $message );
			}
		}
	}
}

namespace WP_CLI\Utils {
	if ( ! function_exists( 'WP_CLI\Utils\get_flag_value' ) ) {
		function get_flag_value( $assoc_args, $flag, $default = null ) {
			return $assoc_args[ $flag ] ?? $default;
		}
	}
	if ( ! function_exists( 'WP_CLI\Utils\format_items' ) ) {
		function format_items( $format, $items, $fields ): void {
			foreach ( $items as $item ) {
				\WP_CLI::log( (string) wp_json_encode( array_intersect_key( (array) $item, array_flip( (array) $fields ) ) ) );
			}
		}
	}
}
