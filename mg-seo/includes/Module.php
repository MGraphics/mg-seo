<?php
/**
 * Base class for modules.
 *
 * @package MGSEO
 */

namespace MGSEO;

defined( 'ABSPATH' ) || exit;

/**
 * A feature module. Subclasses live in includes/Modules/<Name>/Module.php.
 *
 * Contract:
 * - id(): the registry key (also the Options group name).
 * - boot(): register hooks (runs on plugins_loaded when the module is active).
 * - settings(): settings schema rendered on the module's settings page (see Admin\Settings).
 * - admin_pages(): extra admin subpages (list tables etc.).
 * - activate(): create tables / schedule cron; called on plugin activation and when the module is enabled.
 * - uninstall(): remove module data; called from uninstall.php when data removal is enabled.
 */
abstract class Module {

	/**
	 * Module id (registry key).
	 */
	abstract public function id(): string;

	/**
	 * Register hooks.
	 */
	public function boot(): void {}

	/**
	 * Settings schema.
	 *
	 * Format: list of tabs:
	 *   array(
	 *     'id'     => 'general',
	 *     'title'  => 'General',
	 *     'fields' => array(
	 *        array( 'id' => 'key', 'type' => 'text|textarea|code|toggle|select|multicheck|number|url|image|color|hidden|heading|callback',
	 *               'label' => '', 'desc' => '', 'default' => mixed, 'options' => array( value => label ),
	 *               'placeholder' => '', 'variables' => bool (show variable hint), 'callback' => callable (for type=callback),
	 *               'sanitize' => callable (optional custom sanitizer),
	 *               'show_if' => 'other_field_id' (row shown when that toggle is checked), 'show_if_value' => 'a'|array (…or when that select has one of these values) ),
	 *     ),
	 *   )
	 *
	 * Values are stored in Options group = id(), read with Options::get( id(), 'key' ).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function settings(): array {
		return array();
	}

	/**
	 * Extra admin pages under the MG SEO menu.
	 *
	 * Format: list of array( 'slug' => 'mg-seo-redirections', 'title' => '', 'menu_title' => '', 'capability' => 'mgseo_redirections', 'callback' => callable, 'position' => int ).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function admin_pages(): array {
		return array();
	}

	/**
	 * Menu label of the module's settings page (defaults to the module title).
	 */
	public function settings_menu_title(): string {
		return Plugin::title( $this->id() );
	}

	/**
	 * Capability required to edit this module's settings.
	 */
	public function capability(): string {
		return 'mgseo_manage_settings';
	}

	/**
	 * Activation routine.
	 */
	public function activate(): void {}

	/**
	 * Data removal routine.
	 */
	public function uninstall(): void {}

	/**
	 * Read one of this module's options.
	 *
	 * @param string $key      Option key.
	 * @param mixed  $fallback Value when unset and no schema default.
	 * @return mixed
	 */
	public function option( string $key, mixed $fallback = null ): mixed {
		return Options::get( $this->id(), $key, $fallback );
	}
}
