<?php
/**
 * Settings export, import and reset.
 *
 * @package MGSEO
 */

namespace MGSEO\Modules\Tools;

use MGSEO\Admin\Settings;
use MGSEO\Module;
use MGSEO\Modules\Redirections\Repository;
use MGSEO\Options;
use MGSEO\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * JSON export format:
 * { "format": "mg-seo-settings", "version": 1, "plugin_version": "…", "exported": "ISO date",
 *   "settings": { group: { key: value } }, "modules": { id: bool }, "redirections": [ rows ]? }
 */
final class Transfer {

	public const FORMAT = 'mg-seo-settings';

	/**
	 * Build the export array.
	 *
	 * @param bool $with_redirections Include redirections.
	 */
	public static function export( bool $with_redirections = false ): array {
		$modules = get_option( 'mgseo_modules', array() );
		$data    = array(
			'format'         => self::FORMAT,
			'version'        => 1,
			'plugin_version' => defined( 'MGSEO_VERSION' ) ? MGSEO_VERSION : '',
			'exported'       => gmdate( 'c' ),
			'settings'       => Options::all(),
			'modules'        => is_array( $modules ) ? $modules : array(),
		);
		if ( $with_redirections && self::redirections_available() ) {
			$data['redirections'] = array_map(
				static fn( $row ) => array_intersect_key( $row, array_flip( array( 'sources', 'url_to', 'type', 'status', 'category', 'hits', 'last_accessed', 'created' ) ) ),
				Repository::all()
			);
		}
		/**
		 * Filter the settings export.
		 *
		 * @param array $data Export data.
		 */
		return apply_filters( 'mgseo/tools/export', $data );
	}

	/**
	 * Whether the redirections table can be used.
	 */
	public static function redirections_available(): bool {
		return class_exists( Repository::class ) && Plugin::instance()->is_module_active( 'redirections' ) && Repository::table_exists();
	}

	/**
	 * Import an export array.
	 *
	 * @param mixed $data              Decoded JSON.
	 * @param bool  $with_redirections Import redirections when present.
	 * @return array{groups: int, modules: int, redirections: int, skipped: int}|\WP_Error
	 */
	public static function import( mixed $data, bool $with_redirections = true ): array|\WP_Error {
		if ( ! is_array( $data ) || ( $data['format'] ?? '' ) !== self::FORMAT || ! isset( $data['settings'] ) || ! is_array( $data['settings'] ) ) {
			return new \WP_Error( 'invalid_format', __( 'This is not an MG SEO settings file.', 'mg-seo' ) );
		}
		$registry = Plugin::registry();
		$current  = Options::all();
		$groups   = 0;
		foreach ( $data['settings'] as $group => $values ) {
			$group = sanitize_key( (string) $group );
			if ( ! isset( $registry[ $group ] ) || ! is_array( $values ) ) {
				continue;
			}
			$current[ $group ] = self::sanitize_group( $group, $values );
			++$groups;
		}
		Options::save( $current );
		Options::flush_cache();

		$modules = 0;
		if ( isset( $data['modules'] ) && is_array( $data['modules'] ) ) {
			$states = get_option( 'mgseo_modules', array() );
			$states = is_array( $states ) ? $states : array();
			foreach ( $data['modules'] as $id => $enabled ) {
				$id = sanitize_key( (string) $id );
				if ( isset( $registry[ $id ] ) && empty( $registry[ $id ]['required'] ) ) {
					$states[ $id ] = (bool) $enabled;
					++$modules;
				}
			}
			update_option( 'mgseo_modules', $states );
			foreach ( $states as $id => $enabled ) {
				$class = $registry[ $id ]['class'] ?? '';
				if ( $enabled && $class && class_exists( $class ) ) {
					( new $class() )->activate();
				}
			}
		}

		$created = 0;
		$skipped = 0;
		if ( $with_redirections && isset( $data['redirections'] ) && is_array( $data['redirections'] ) && self::redirections_available() ) {
			foreach ( $data['redirections'] as $row ) {
				if ( ! is_array( $row ) || empty( $row['sources'] ) || ! is_array( $row['sources'] ) ) {
					++$skipped;
					continue;
				}
				$first = $row['sources'][0];
				if ( is_array( $first ) && isset( $first['pattern'] ) && Repository::find_by_source( (string) $first['pattern'], (string) ( $first['comparison'] ?? 'exact' ) ) ) {
					++$skipped;
					continue;
				}
				$id = Repository::create( $row );
				is_wp_error( $id ) ? ++$skipped : ++$created;
			}
		}
		update_option( 'mgseo_flush_rewrite', 1 );
		/**
		 * Fires after settings were imported.
		 *
		 * @param array $data Imported data.
		 */
		do_action( 'mgseo/tools/imported', $data );
		return array(
			'groups'       => $groups,
			'modules'      => $modules,
			'redirections' => $created,
			'skipped'      => $skipped,
		);
	}

	/**
	 * Sanitize imported values of one group with the module's field schema.
	 * Keys without a schema field (internal state) are kept, sanitized as text unless the user has unfiltered_html.
	 *
	 * @param string $group  Module id.
	 * @param array  $values Values.
	 */
	private static function sanitize_group( string $group, array $values ): array {
		$fields = array();
		$class  = Plugin::registry()[ $group ]['class'] ?? '';
		$module = Plugin::instance()->module( $group );
		if ( ! $module && $class && class_exists( $class ) ) {
			$module = new $class();
		}
		if ( $module instanceof Module ) {
			foreach ( $module->settings() as $tab ) {
				foreach ( $tab['fields'] ?? array() as $field ) {
					if ( isset( $field['id'] ) ) {
						$fields[ $field['id'] ] = $field;
					}
				}
			}
		}
		$clean = array();
		foreach ( $values as $key => $value ) {
			$key = (string) $key;
			if ( '' === $key || sanitize_key( $key ) !== strtolower( $key ) ) {
				continue;
			}
			if ( isset( $fields[ $key ] ) ) {
				if ( in_array( $fields[ $key ]['type'] ?? '', array( 'heading' ), true ) ) {
					continue;
				}
				$clean[ $key ] = Settings::sanitize( $fields[ $key ], $value );
				continue;
			}
			if ( current_user_can( 'unfiltered_html' ) ) {
				$clean[ $key ] = $value;
			} else {
				$clean[ $key ] = is_array( $value ) ? map_deep( $value, 'sanitize_text_field' ) : ( is_scalar( $value ) ? sanitize_text_field( (string) $value ) : null );
			}
		}
		return $clean;
	}

	/**
	 * Reset all settings to defaults (module states and redirections are kept unless asked).
	 *
	 * @param bool $modules Also reset module on/off states.
	 */
	public static function reset( bool $modules = false ): void {
		$keep = array();
		$key  = Options::get( 'tools', 'indexnow_key', '' );
		if ( '' !== $key ) {
			$keep['tools'] = array( 'indexnow_key' => $key );
		}
		Options::save( $keep );
		Options::flush_cache();
		if ( $modules ) {
			delete_option( 'mgseo_modules' );
		}
		update_option( 'mgseo_flush_rewrite', 1 );
		/**
		 * Fires after settings were reset.
		 */
		do_action( 'mgseo/tools/reset' );
	}
}
