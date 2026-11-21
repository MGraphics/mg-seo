<?php
/**
 * Import & Export module.
 *
 * @package MGSEO
 */

namespace MGSEO\Modules\Import;

use MGSEO\Module as Base;

defined( 'ABSPATH' ) || exit;

/**
 * Imports SEO data left behind by Rank Math and Yoast SEO (the plugins do not need to be installed).
 * Settings JSON export/import lives in the Tools module; per-post CSV lives in Bulk Editing.
 */
final class Module extends Base {

	public const PAGE = 'mg-seo-import';
	public const CAP  = 'mgseo_manage_settings';

	/**
	 * Module id.
	 */
	public function id(): string {
		return 'import';
	}

	/**
	 * Register hooks.
	 */
	public function boot(): void {
		Compat::boot();
		add_action( 'mgseo/rest_routes', array( $this, 'routes' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_action( 'admin_notices', array( $this, 'notice' ) );
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'mgseo import', Cli::class );
		}
	}

	/**
	 * Admin page.
	 */
	public function admin_pages(): array {
		return array(
			array(
				'slug'       => self::PAGE,
				'title'      => __( 'Import & Export', 'mg-seo' ),
				'menu_title' => __( 'Import & Export', 'mg-seo' ),
				'capability' => self::CAP,
				'callback'   => array( Page::class, 'render' ),
				'position'   => 85,
			),
		);
	}

	/**
	 * Enqueue assets on the import page.
	 *
	 * @param string $hook Hook suffix.
	 */
	public function assets( string $hook ): void {
		if ( ! str_ends_with( $hook, self::PAGE ) ) {
			return;
		}
		wp_enqueue_style( 'mgseo-import', MGSEO_URL . 'assets/import.css', array(), MGSEO_VERSION );
		wp_enqueue_script( 'mgseo-import', MGSEO_URL . 'assets/import.js', array( 'wp-api-fetch', 'wp-i18n' ), MGSEO_VERSION, true );
		wp_set_script_translations( 'mgseo-import', 'mg-seo' );
		wp_localize_script(
			'mgseo-import',
			'mgseoImport',
			array(
				'path'   => '/' . \MGSEO\Rest::NAMESPACE . '/import',
				'batch'  => Runner::DEFAULT_BATCH,
				'scopes' => Page::scope_labels(),
			)
		);
	}

	/**
	 * One-time hint on MG SEO screens when leftover data exists and nothing was imported yet.
	 */
	public function notice(): void {
		$screen = get_current_screen();
		if ( ! $screen || ! str_contains( (string) $screen->id, 'mg-seo' ) || str_ends_with( (string) $screen->id, self::PAGE ) || ! current_user_can( self::CAP ) ) {
			return;
		}
		if ( get_option( Log::OPTION ) ) {
			return;
		}
		foreach ( array_keys( Runner::sources() ) as $id ) {
			$source = Runner::source( $id );
			if ( $source && ! $source->plugin_active() && $source->has_data() ) {
				printf(
					'<div class="notice notice-info"><p>%s <a href="%s">%s</a></p></div>',
					/* translators: %s: plugin name */
					esc_html( sprintf( __( 'SEO data left behind by %s was found in the database.', 'mg-seo' ), $source->label() ) ),
					esc_url( admin_url( 'admin.php?page=' . self::PAGE ) ),
					esc_html__( 'Import it', 'mg-seo' )
				);
				return;
			}
		}
	}

	/**
	 * REST routes.
	 *
	 * @param string $ns Namespace.
	 */
	public function routes( string $ns ): void {
		$can = static fn(): bool => current_user_can( self::CAP );
		register_rest_route(
			$ns,
			'/import/detect',
			array(
				'methods'             => 'GET',
				'permission_callback' => $can,
				'callback'            => static fn() => rest_ensure_response( Runner::detect() ),
			)
		);
		register_rest_route(
			$ns,
			'/import/run',
			array(
				'methods'             => 'POST',
				'permission_callback' => $can,
				'args'                => array(
					'source'    => array(
						'type'     => 'string',
						'required' => true,
						'enum'     => array_keys( Runner::sources() ),
					),
					'scope'     => array(
						'type'     => 'string',
						'required' => true,
						'enum'     => Source::SCOPES,
					),
					'after'     => array(
						'type'    => 'integer',
						'default' => 0,
						'minimum' => 0,
					),
					'limit'     => array(
						'type'    => 'integer',
						'default' => Runner::DEFAULT_BATCH,
						'minimum' => 1,
						'maximum' => 1000,
					),
					'overwrite' => array(
						'type'    => 'boolean',
						'default' => false,
					),
					'dry_run'   => array(
						'type'    => 'boolean',
						'default' => false,
					),
					'start'     => array(
						'type'    => 'boolean',
						'default' => false,
					),
				),
				'callback'            => static function ( \WP_REST_Request $request ) {
					$result = Runner::batch(
						(string) $request['source'],
						(string) $request['scope'],
						(int) $request['after'],
						array(
							'limit'     => (int) $request['limit'],
							'overwrite' => (bool) $request['overwrite'],
							'dry_run'   => (bool) $request['dry_run'],
							'start'     => (bool) $request['start'],
						)
					);
					return rest_ensure_response( $result );
				},
			)
		);
		register_rest_route(
			$ns,
			'/import/finish',
			array(
				'methods'             => 'POST',
				'permission_callback' => $can,
				'callback'            => static fn() => rest_ensure_response( Runner::finish() ),
			)
		);
		register_rest_route(
			$ns,
			'/import/log',
			array(
				'methods'             => 'GET',
				'permission_callback' => $can,
				'callback'            => static fn() => rest_ensure_response( Log::load()->to_array() ),
			)
		);
		register_rest_route(
			$ns,
			'/import/cleanup',
			array(
				'methods'             => 'POST',
				'permission_callback' => $can,
				'args'                => array(
					'source'  => array(
						'type'     => 'string',
						'required' => true,
						'enum'     => array_keys( Runner::sources() ),
					),
					'confirm' => array(
						'type'     => 'string',
						'required' => true,
					),
				),
				'callback'            => static function ( \WP_REST_Request $request ) {
					if ( 'DELETE' !== (string) $request['confirm'] ) {
						return new \WP_Error( 'mgseo_import_confirm', __( 'Type DELETE to confirm.', 'mg-seo' ), array( 'status' => 400 ) );
					}
					return rest_ensure_response( Runner::cleanup( (string) $request['source'] ) );
				},
			)
		);
	}

	/**
	 * Data removal.
	 */
	public function uninstall(): void {
		delete_option( Log::OPTION );
		delete_option( 'mgseo_import_map' );
	}
}
