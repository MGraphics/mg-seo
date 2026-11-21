<?php
/**
 * Settings storage.
 *
 * @package MGSEO
 */

namespace MGSEO;

defined( 'ABSPATH' ) || exit;

/**
 * All module settings live in one autoloaded option `mgseo_settings`:
 * array( group => array( key => value ) ). Defaults come from module settings schemas.
 */
final class Options {

	public const OPTION = 'mgseo_settings';

	/**
	 * Cached stored values.
	 *
	 * @var array<string, array<string, mixed>>|null
	 */
	private static ?array $values = null;

	/**
	 * Cached defaults per group.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private static array $defaults = array();

	/**
	 * Get a setting.
	 *
	 * @param string $group    Group (module id).
	 * @param string $key      Key.
	 * @param mixed  $fallback Fallback when neither stored nor defaulted.
	 * @return mixed
	 */
	public static function get( string $group, string $key, mixed $fallback = null ): mixed {
		$values = self::all();
		if ( isset( $values[ $group ] ) && is_array( $values[ $group ] ) && array_key_exists( $key, $values[ $group ] ) ) {
			return $values[ $group ][ $key ];
		}
		$defaults = self::defaults( $group );
		if ( array_key_exists( $key, $defaults ) ) {
			return $defaults[ $key ];
		}
		return $fallback;
	}

	/**
	 * Get a whole group merged with defaults.
	 *
	 * @param string $group Group.
	 * @return array<string, mixed>
	 */
	public static function group( string $group ): array {
		$values = self::all();
		$stored = isset( $values[ $group ] ) && is_array( $values[ $group ] ) ? $values[ $group ] : array();
		return array_merge( self::defaults( $group ), $stored );
	}

	/**
	 * Set one setting.
	 *
	 * @param string $group Group.
	 * @param string $key   Key.
	 * @param mixed  $value Value.
	 */
	public static function set( string $group, string $key, mixed $value ): void {
		$values                   = self::all();
		$values[ $group ]         = isset( $values[ $group ] ) && is_array( $values[ $group ] ) ? $values[ $group ] : array();
		$values[ $group ][ $key ] = $value;
		self::save( $values );
	}

	/**
	 * Replace a whole group.
	 *
	 * @param string               $group  Group.
	 * @param array<string, mixed> $values Values.
	 */
	public static function set_group( string $group, array $values ): void {
		$all           = self::all();
		$all[ $group ] = $values;
		self::save( $all );
	}

	/**
	 * Stored values.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function all(): array {
		if ( null === self::$values ) {
			$stored       = get_option( self::OPTION, array() );
			self::$values = is_array( $stored ) ? $stored : array();
		}
		return self::$values;
	}

	/**
	 * Persist values.
	 *
	 * @param array<string, array<string, mixed>> $values Values.
	 */
	public static function save( array $values ): void {
		self::$values = $values;
		update_option( self::OPTION, $values, true );
	}

	/**
	 * Defaults for a group, read from the module's settings schema.
	 *
	 * @param string $group Group.
	 * @return array<string, mixed>
	 */
	public static function defaults( string $group ): array {
		if ( isset( self::$defaults[ $group ] ) ) {
			return self::$defaults[ $group ];
		}
		$defaults = array();
		$registry = Plugin::registry();
		$module   = Plugin::instance()->module( $group );
		if ( ! $module && isset( $registry[ $group ] ) && class_exists( $registry[ $group ]['class'] ) ) {
			$module = new $registry[ $group ]['class']();
		}
		if ( $module instanceof Module ) {
			foreach ( $module->settings() as $tab ) {
				foreach ( $tab['fields'] ?? array() as $field ) {
					if ( isset( $field['id'] ) && array_key_exists( 'default', $field ) ) {
						$defaults[ $field['id'] ] = $field['default'];
					}
				}
			}
		}
		/**
		 * Filter defaults for an options group.
		 *
		 * @param array  $defaults Defaults.
		 * @param string $group    Group.
		 */
		self::$defaults[ $group ] = apply_filters( 'mgseo/option_defaults', $defaults, $group );
		return self::$defaults[ $group ];
	}

	/**
	 * Clear caches (after external update_option or in tests).
	 */
	public static function flush_cache(): void {
		self::$values   = null;
		self::$defaults = array();
	}
}
