<?php
/**
 * Plugin container and module registry.
 *
 * @package MGSEO
 */

namespace MGSEO;

defined( 'ABSPATH' ) || exit;

/**
 * Main plugin class.
 */
final class Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var Plugin|null
	 */
	private static ?Plugin $instance = null;

	/**
	 * Booted module instances keyed by id.
	 *
	 * @var array<string, Module>
	 */
	private array $modules = array();

	/**
	 * Whether boot() already ran.
	 *
	 * @var bool
	 */
	private bool $booted = false;

	/**
	 * Get the singleton.
	 */
	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Module definitions.
	 *
	 * Each entry: class, default (enabled on fresh install), required (cannot be disabled), requires (dependency).
	 * Human labels live in labels() so nothing is translated before `init`.
	 *
	 * @return array<string, array{class: class-string<Module>, default: bool, required?: bool, requires?: string}>
	 */
	public static function registry(): array {
		$registry = array(
			'titles'       => array(
				'class'    => Modules\Titles\Module::class,
				'default'  => true,
				'required' => true,
			),
			'social'       => array(
				'class'   => Modules\Social\Module::class,
				'default' => true,
			),
			'analysis'     => array(
				'class'   => Modules\Analysis\Module::class,
				'default' => true,
			),
			'breadcrumbs'  => array(
				'class'   => Modules\Breadcrumbs\Module::class,
				'default' => true,
			),
			'sitemap'      => array(
				'class'   => Modules\Sitemap\Module::class,
				'default' => true,
			),
			'schema'       => array(
				'class'   => Modules\Schema\Module::class,
				'default' => true,
			),
			'redirections' => array(
				'class'   => Modules\Redirections\Module::class,
				'default' => true,
			),
			'local'        => array(
				'class'   => Modules\Local\Module::class,
				'default' => false,
			),
			'woocommerce'  => array(
				'class'    => Modules\WooCommerce\Module::class,
				'default'  => true,
				'requires' => 'woocommerce',
			),
			'image'        => array(
				'class'   => Modules\Image\Module::class,
				'default' => false,
			),
			'bulk'         => array(
				'class'   => Modules\Bulk\Module::class,
				'default' => true,
			),
			'gsc'          => array(
				'class'   => Modules\SearchConsole\Module::class,
				'default' => false,
			),
			'links'        => array(
				'class'   => Modules\Links\Module::class,
				'default' => true,
			),
			'import'       => array(
				'class'    => Modules\Import\Module::class,
				'default'  => true,
				'required' => true,
			),
			'roles'        => array(
				'class'    => Modules\Roles\Module::class,
				'default'  => true,
				'required' => true,
			),
			'tools'        => array(
				'class'   => Modules\Tools\Module::class,
				'default' => true,
			),
		);

		/**
		 * Filter the module registry.
		 *
		 * @param array $registry Module definitions.
		 */
		return apply_filters( 'mgseo/modules', $registry );
	}

	/**
	 * Translated module titles and descriptions (call on or after `init`).
	 *
	 * @return array<string, array{0: string, 1: string}> id => array( title, description )
	 */
	public static function labels(): array {
		$labels = array(
			'titles'       => array( __( 'Titles & Meta', 'mg-seo' ), __( 'Title and description templates, robots meta, canonical URLs and verification codes.', 'mg-seo' ) ),
			'social'       => array( __( 'Social (Open Graph & Twitter)', 'mg-seo' ), __( 'Open Graph and Twitter Card tags for shares on Facebook, LinkedIn, X and messengers.', 'mg-seo' ) ),
			'analysis'     => array( __( 'Content Analysis', 'mg-seo' ), __( 'Focus keyword analysis and SEO score in the editor and post lists.', 'mg-seo' ) ),
			'breadcrumbs'  => array( __( 'Breadcrumbs', 'mg-seo' ), __( 'Breadcrumb trail (shortcode, block and PHP function) with BreadcrumbList schema.', 'mg-seo' ) ),
			'sitemap'      => array( __( 'XML Sitemaps', 'mg-seo' ), __( 'XML sitemaps with images, plus Google News and video sitemaps.', 'mg-seo' ) ),
			'schema'       => array( __( 'Schema (Structured Data)', 'mg-seo' ), __( 'JSON-LD graph, schema generator with templates, custom JSON-LD and validation.', 'mg-seo' ) ),
			'redirections' => array( __( 'Redirections & 404 Monitor', 'mg-seo' ), __( '301/302/307/410/451 redirects with regex, auto redirects on slug change and a 404 log.', 'mg-seo' ) ),
			'local'        => array( __( 'Local SEO', 'mg-seo' ), __( 'Business data, multiple locations, opening hours, address shortcode and LocalBusiness schema.', 'mg-seo' ) ),
			'woocommerce'  => array( __( 'WooCommerce SEO', 'mg-seo' ), __( 'Product schema, GTIN/brand fields, product variables and WooCommerce URL options.', 'mg-seo' ) ),
			'image'        => array( __( 'Image SEO', 'mg-seo' ), __( 'Automatic alt and title attributes from templates, case changes and find & replace.', 'mg-seo' ) ),
			'bulk'         => array( __( 'Bulk Editing', 'mg-seo' ), __( 'Edit SEO titles, descriptions, keywords and robots for many posts at once, plus CSV import/export.', 'mg-seo' ) ),
			'gsc'          => array( __( 'Search Console', 'mg-seo' ), __( 'Google Search Console performance and URL inspection using your own Google Cloud project.', 'mg-seo' ) ),
			'links'        => array( __( 'Internal Links', 'mg-seo' ), __( 'Internal link suggestions and link counts computed locally.', 'mg-seo' ) ),
			'import'       => array( __( 'Import & Export', 'mg-seo' ), __( 'Import from Rank Math and Yoast SEO, export and import MG SEO settings.', 'mg-seo' ) ),
			'roles'        => array( __( 'Role Manager', 'mg-seo' ), __( 'Choose which roles may edit SEO settings, per-post SEO data, redirections and reports.', 'mg-seo' ) ),
			'tools'        => array( __( 'Tools', 'mg-seo' ), __( 'robots.txt editor, llms.txt, IndexNow and housekeeping.', 'mg-seo' ) ),
		);
		/**
		 * Filter module labels.
		 *
		 * @param array $labels id => array( title, description ).
		 */
		return apply_filters( 'mgseo/module_labels', $labels );
	}

	/**
	 * Title of a module.
	 *
	 * @param string $id Module id.
	 */
	public static function title( string $id ): string {
		return self::labels()[ $id ][0] ?? $id;
	}

	/**
	 * Boot the plugin: load translations, boot active modules, admin.
	 */
	public function boot(): void {
		if ( $this->booted ) {
			return;
		}
		$this->booted = true;

		add_action(
			'init',
			static function (): void {
				load_plugin_textdomain( 'mg-seo', false, dirname( plugin_basename( MGSEO_FILE ) ) . '/languages' );
			},
			0
		);

		Installer::maybe_upgrade();
		Updater::boot();

		foreach ( self::registry() as $id => $def ) {
			if ( ! $this->is_module_active( $id ) ) {
				continue;
			}
			if ( ! class_exists( $def['class'] ) ) {
				continue;
			}
			$module = new $def['class']();
			if ( ! $module instanceof Module ) {
				continue;
			}
			$this->modules[ $id ] = $module;
		}

		Head::instance()->boot();
		Stealth::boot();

		foreach ( $this->modules as $module ) {
			$module->boot();
		}

		if ( is_admin() ) {
			Admin\Admin::instance()->boot();
		}
		Rest::boot();

		/**
		 * Fires after MG SEO modules are booted.
		 *
		 * @param Plugin $plugin Plugin instance.
		 */
		do_action( 'mgseo/loaded', $this );
	}

	/**
	 * Whether a module's dependency is available.
	 *
	 * @param string $requires Dependency key.
	 */
	public static function dependency_met( string $requires ): bool {
		return match ( $requires ) {
			'woocommerce' => class_exists( 'WooCommerce' ) || defined( 'WC_VERSION' ),
			default       => true,
		};
	}

	/**
	 * Whether a module is enabled (and its dependency met).
	 *
	 * @param string $id Module id.
	 */
	public function is_module_active( string $id ): bool {
		$registry = self::registry();
		if ( ! isset( $registry[ $id ] ) ) {
			return false;
		}
		$def = $registry[ $id ];
		if ( ! empty( $def['requires'] ) && ! self::dependency_met( $def['requires'] ) ) {
			return false;
		}
		if ( ! empty( $def['required'] ) ) {
			return true;
		}
		$states = get_option( 'mgseo_modules', array() );
		if ( is_array( $states ) && array_key_exists( $id, $states ) ) {
			return (bool) $states[ $id ];
		}
		return (bool) $def['default'];
	}

	/**
	 * Enable or disable a module (takes effect on next request).
	 *
	 * @param string $id      Module id.
	 * @param bool   $enabled State.
	 */
	public function set_module_state( string $id, bool $enabled ): void {
		$states        = get_option( 'mgseo_modules', array() );
		$states        = is_array( $states ) ? $states : array();
		$states[ $id ] = $enabled;
		update_option( 'mgseo_modules', $states );
		if ( $enabled ) {
			$def = self::registry()[ $id ] ?? null;
			if ( $def && class_exists( $def['class'] ) ) {
				( new $def['class']() )->activate();
			}
		}
		flush_rewrite_rules( false );
	}

	/**
	 * Get a booted module.
	 *
	 * @param string $id Module id.
	 */
	public function module( string $id ): ?Module {
		return $this->modules[ $id ] ?? null;
	}

	/**
	 * All booted modules.
	 *
	 * @return array<string, Module>
	 */
	public function modules(): array {
		return $this->modules;
	}

	/**
	 * Test helper: forget booted state so boot() can run again.
	 *
	 * @internal
	 */
	public static function reset(): void {
		self::$instance = null;
		Options::flush_cache();
	}
}
