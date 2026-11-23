<?php
/**
 * Grouped admin navigation: a short MG SEO submenu and a shared screen shell.
 *
 * Every existing screen keeps its own admin page and slug (?page=mg-seo-…), so links, bookmarks, save handlers,
 * capabilities and per-screen assets work as before. The submenu shows one entry per group; inside a group a
 * shell renders the group header and the navigation between its screens: tiles with icons for settings
 * groups, tabs across the top for screens with wide tables.
 *
 * @package MGSEO
 */

namespace MGSEO\Admin;

use MGSEO\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Admin navigation groups and the screen shell.
 */
final class Navigation {

	/**
	 * Registered MG SEO pages keyed by slug (set while the menu is built).
	 *
	 * Each page: title, menu_title, capability, module (settings pages only).
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private static array $pages = array();

	/**
	 * Layout of the shell currently open (rail|top), for close().
	 *
	 * @var string
	 */
	private static string $layout = '';

	/**
	 * Group definitions in menu order.
	 *
	 * Each group: title, description, layout (rail: section tiles | top: tabs), pages (slugs in order), primary (optional slug of a
	 * settings page whose tabs get their own tiles, with their own icons).
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function groups(): array {
		$groups = array(
			'dashboard'    => array(
				'title'       => __( 'Dashboard', 'mg-seo' ),
				'description' => __( 'Turn modules on or off, check the SEO score of every page and open the reports.', 'mg-seo' ),
				'layout'      => 'top',
				'pages'       => array( Admin::MENU_SLUG, 'mg-seo-overview', 'mg-seo-gsc-reports', 'mg-seo-links-report' ),
			),
			'general'      => array(
				'title'       => __( 'General Settings', 'mg-seo' ),
				'description' => __( 'Site-wide options: content analysis, breadcrumbs, links, images, local SEO, Search Console and robots.txt.', 'mg-seo' ),
				'layout'      => 'rail',
				'pages'       => array( 'mg-seo-analysis', 'mg-seo-breadcrumbs', 'mg-seo-links', 'mg-seo-image', 'mg-seo-local', 'mg-seo-woocommerce', 'mg-seo-gsc', 'mg-seo-tools' ),
			),
			'titles'       => array(
				'title'       => __( 'Titles & Meta', 'mg-seo' ),
				'description' => __( 'How pages look in search results and when shared: title and description templates, robots meta, social cards and structured data.', 'mg-seo' ),
				'layout'      => 'rail',
				'pages'       => array( 'mg-seo-titles', 'mg-seo-social', 'mg-seo-schema' ),
				'primary'     => 'mg-seo-titles',
			),
			'sitemap'      => array(
				'title'       => __( 'Sitemaps', 'mg-seo' ),
				'description' => __( 'Which pages, terms and authors search engines find in the XML sitemaps, plus the news and video sitemaps.', 'mg-seo' ),
				'layout'      => 'rail',
				'pages'       => array( 'mg-seo-sitemap' ),
				'primary'     => 'mg-seo-sitemap',
			),
			'redirections' => array(
				'title'       => __( 'Redirections', 'mg-seo' ),
				'description' => __( 'Redirects, the log of missing pages (404) and how both behave.', 'mg-seo' ),
				'layout'      => 'top',
				'pages'       => array( 'mg-seo-redirects', 'mg-seo-404-monitor', 'mg-seo-redirections' ),
			),
			'tools'        => array(
				'title'       => __( 'Tools', 'mg-seo' ),
				'description' => __( 'Bulk editing, image tools, imports from other plugins, roles and housekeeping.', 'mg-seo' ),
				'layout'      => 'top',
				'pages'       => array( 'mg-seo-bulk', 'mg-seo-image-tools', 'mg-seo-import', 'mg-seo-roles', 'mg-seo-utilities' ),
			),
		);

		/**
		 * Filter the MG SEO admin menu groups.
		 *
		 * Pages that are not listed in any group keep their own submenu entry.
		 *
		 * @param array $groups Group id => array( title, description, layout (rail|top), pages, primary ).
		 */
		return (array) apply_filters( 'mgseo/admin_groups', $groups );
	}

	/**
	 * Remember the registered pages.
	 *
	 * @param array<string, array<string, mixed>> $pages Slug => page.
	 */
	public static function set_pages( array $pages ): void {
		self::$pages = $pages;
	}

	/**
	 * Registered page by slug.
	 *
	 * @param string $slug Page slug.
	 * @return array<string, mixed>|null
	 */
	public static function page( string $slug ): ?array {
		return self::$pages[ $slug ] ?? null;
	}

	/**
	 * Group id of a page.
	 *
	 * @param string $slug Page slug.
	 */
	public static function group_of( string $slug ): ?string {
		foreach ( self::groups() as $id => $group ) {
			if ( in_array( $slug, (array) ( $group['pages'] ?? array() ), true ) ) {
				return (string) $id;
			}
		}
		return null;
	}

	/**
	 * Registered pages of a group, in group order.
	 *
	 * @param string $group Group id.
	 * @return string[]
	 */
	public static function members( string $group ): array {
		$pages = (array) ( self::groups()[ $group ]['pages'] ?? array() );
		return array_values( array_filter( $pages, static fn( $slug ) => isset( self::$pages[ $slug ] ) ) );
	}

	/**
	 * First page of a group the current user may open.
	 *
	 * @param string $group Group id.
	 */
	public static function lead( string $group ): ?string {
		foreach ( self::members( $group ) as $slug ) {
			if ( current_user_can( (string) self::$pages[ $slug ]['capability'] ) ) {
				return $slug;
			}
		}
		return null;
	}

	/**
	 * URL of a page, optionally of one of its tabs.
	 *
	 * @param string $slug Page slug.
	 * @param string $tab  Tab id.
	 */
	public static function url( string $slug, string $tab = '' ): string {
		$args = array( 'page' => $slug );
		if ( '' !== $tab ) {
			$args['tab'] = $tab;
		}
		return add_query_arg( $args, admin_url( 'admin.php' ) );
	}

	/**
	 * Navigation entries of a group for the current user.
	 *
	 * Each entry: slug, label, icon, url, tabs (list of id, label, url, icon) for settings pages with more than one tab.
	 *
	 * @param string $group Group id.
	 * @return array<int, array<string, mixed>>
	 */
	public static function entries( string $group ): array {
		$entries = array();
		foreach ( self::members( $group ) as $slug ) {
			$page = self::$pages[ $slug ];
			if ( ! current_user_can( (string) $page['capability'] ) ) {
				continue;
			}
			$entry  = array(
				'slug'  => $slug,
				'label' => self::label( $slug ),
				'icon'  => self::icon( $slug ),
				'url'   => self::url( $slug ),
				'tabs'  => array(),
			);
			$module = ! empty( $page['module'] ) ? Plugin::instance()->module( (string) $page['module'] ) : null;
			if ( $module ) {
				$tabs = $module->settings();
				if ( count( $tabs ) > 1 ) {
					foreach ( $tabs as $tab ) {
						$entry['tabs'][] = array(
							'id'    => (string) $tab['id'],
							'label' => (string) $tab['title'],
							'url'   => self::url( $slug, (string) $tab['id'] ),
							'icon'  => (string) ( $tab['icon'] ?? self::tab_icon( (string) $page['module'], (string) $tab['id'] ) ),
						);
					}
				}
			}
			$entries[] = $entry;
		}
		return $entries;
	}

	/**
	 * Short navigation label of a page.
	 *
	 * @param string $slug Page slug.
	 */
	public static function label( string $slug ): string {
		$labels = array(
			Admin::MENU_SLUG      => __( 'Modules', 'mg-seo' ),
			'mg-seo-social'       => __( 'Social media', 'mg-seo' ),
			'mg-seo-schema'       => __( 'Schema', 'mg-seo' ),
			'mg-seo-utilities'    => __( 'SEO Utilities', 'mg-seo' ),
			'mg-seo-redirections' => __( 'Settings', 'mg-seo' ),
			'mg-seo-tools'        => __( 'Crawlers & indexing', 'mg-seo' ),
		);
		if ( isset( $labels[ $slug ] ) ) {
			return $labels[ $slug ];
		}
		$page = self::$pages[ $slug ] ?? array();
		if ( ! empty( $page['module'] ) ) {
			return Plugin::title( (string) $page['module'] );
		}
		return (string) ( $page['menu_title'] ?? $page['title'] ?? $slug );
	}

	/**
	 * Icon key of a page.
	 *
	 * @param string $slug Page slug.
	 */
	private static function icon( string $slug ): string {
		$icons = array(
			Admin::MENU_SLUG      => 'modules',
			'mg-seo-overview'     => 'analysis',
			'mg-seo-gsc-reports'  => 'gsc',
			'mg-seo-links-report' => 'links',
			'mg-seo-redirects'    => 'redirections',
			'mg-seo-404-monitor'  => 'missing',
			'mg-seo-redirections' => 'sliders',
			'mg-seo-image-tools'  => 'image',
			'mg-seo-utilities'    => 'tools',
		);
		if ( isset( $icons[ $slug ] ) ) {
			return $icons[ $slug ];
		}
		$page = self::$pages[ $slug ] ?? array();
		if ( ! empty( $page['module'] ) ) {
			return (string) $page['module'];
		}
		$module = (string) preg_replace( '/^mg-seo-/', '', $slug );
		return $module;
	}

	/**
	 * Icon key of a settings tab that has its own tile.
	 *
	 * @param string $module Module id.
	 * @param string $tab    Tab id.
	 */
	private static function tab_icon( string $module, string $tab ): string {
		$icons = array(
			'titles/general'      => 'sliders',
			'titles/home'         => 'home',
			'titles/post_types'   => 'document',
			'titles/taxonomies'   => 'tag',
			'titles/archives'     => 'folder',
			'titles/verification' => 'shield',
			'sitemap/general'     => 'sliders',
			'sitemap/post_types'  => 'document',
			'sitemap/taxonomies'  => 'tag',
			'sitemap/authors'     => 'person',
			'sitemap/news'        => 'news',
			'sitemap/video'       => 'video',
		);
		return $icons[ $module . '/' . $tab ] ?? $module;
	}

	/**
	 * Group id and definition for a page; pages outside every group get a group of their own.
	 *
	 * @param string $slug Page slug.
	 * @return array{0: string, 1: array<string, mixed>}
	 */
	public static function context( string $slug ): array {
		$id = self::group_of( $slug );
		if ( null !== $id ) {
			return array( $id, self::groups()[ $id ] );
		}
		$page = self::$pages[ $slug ] ?? array();
		return array(
			'',
			array(
				'title'       => (string) ( $page['title'] ?? '' ),
				'description' => ! empty( $page['module'] ) ? (string) ( Plugin::labels()[ $page['module'] ][1] ?? '' ) : '',
				'layout'      => 'rail',
				'pages'       => array( $slug ),
				'primary'     => $slug,
			),
		);
	}

	/**
	 * Render a screen inside its group shell.
	 *
	 * Used for screens with their own markup (list tables, reports). Their own top heading becomes the section
	 * heading (h2) under the group title (h1).
	 *
	 * @param string   $slug     Page slug.
	 * @param callable $callback Original page callback.
	 */
	public static function render( string $slug, callable $callback ): void {
		ob_start();
		call_user_func( $callback );
		$html = (string) ob_get_clean();
		if ( ! self::open( $slug ) ) {
			echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by the page callback.
			return;
		}
		echo self::demote_heading( $html ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by the page callback.
		self::close();
	}

	/**
	 * Turn the first h1 of a screen into an h2 (the shell holds the page's h1).
	 *
	 * @param string $html Screen markup.
	 */
	public static function demote_heading( string $html ): string {
		$html = (string) preg_replace( '#<h1(\s[^>]*)?>(.*?)</h1>#s', '<h2$1>$2</h2>', $html, 1 );
		// One notice anchor per screen (the shell's), or WordPress would copy each notice to every anchor.
		return (string) preg_replace( '#<hr class="wp-header-end"\s*/?>#', '', $html );
	}

	/**
	 * Open the shell: header, group navigation and the content column.
	 *
	 * @param string $slug Current page slug.
	 * @param string $tab  Current tab (settings pages).
	 * @return bool False when the page is not shown in a shell (unknown page, no access).
	 */
	public static function open( string $slug, string $tab = '' ): bool {
		if ( ! isset( self::$pages[ $slug ] ) ) {
			return false;
		}
		list( $group_id, $group ) = self::context( $slug );
		$entries                  = '' === $group_id ? self::solo_entries( $slug ) : self::entries( $group_id );
		$layout                   = 'top' === ( $group['layout'] ?? 'rail' ) ? 'top' : 'rail';
		$primary                  = (string) ( $group['primary'] ?? '' );
		self::$layout             = $layout;

		echo '<div class="wrap mgseo-wrap mgseo-shell is-' . esc_attr( $layout ) . '" data-group="' . esc_attr( $group_id ) . '">';
		echo '<header class="mgseo-shell-head">';
		echo '<div class="mgseo-shell-titles"><h1>' . esc_html( (string) $group['title'] ) . '</h1>';
		if ( ! empty( $group['description'] ) ) {
			echo '<p class="mgseo-shell-desc">' . esc_html( (string) $group['description'] ) . '</p>';
		}
		echo '</div></header>';
		echo '<hr class="wp-header-end" />'; // Admin notices are placed here, between the header and the navigation.

		/* translators: %s: name of a group of MG SEO screens, e.g. "Titles & Meta" */
		$nav_label = sprintf( __( '%s: sections', 'mg-seo' ), (string) $group['title'] );

		if ( 'top' === $layout ) {
			if ( count( $entries ) > 1 ) {
				echo '<nav class="mgseo-topnav" aria-label="' . esc_attr( $nav_label ) . '"><ul>';
				foreach ( $entries as $entry ) {
					$current = $entry['slug'] === $slug;
					echo '<li><a class="mgseo-topnav-link' . ( $current ? ' is-current' : '' ) . '" href="' . esc_url( $entry['url'] ) . '"' . ( $current ? ' aria-current="page"' : '' ) . '>';
					echo '<span class="mgseo-nav-label">' . esc_html( $entry['label'] ) . '</span></a></li>';
				}
				echo '</ul></nav>';
			}
			echo '<div class="mgseo-shell-main">';
			return true;
		}

		$children = self::tiles( $entries, $slug, $tab, $primary, $nav_label );
		echo '<div class="mgseo-shell-body">';
		if ( $children ) {
			echo '<nav class="mgseo-subtabs" aria-label="' . esc_attr( $children['label'] ) . '"><ul>';
			foreach ( $children['items'] as $child ) {
				echo '<li><a class="mgseo-subtab' . ( $child['current'] ? ' is-current' : '' ) . '" href="' . esc_url( $child['url'] ) . '"' . ( $child['current'] ? ' aria-current="page"' : '' ) . '>' . esc_html( $child['label'] ) . '</a></li>';
			}
			echo '</ul></nav>';
		}
		echo '<div class="mgseo-shell-main">';
		return true;
	}

	/**
	 * Close the shell opened by open().
	 */
	public static function close(): void {
		echo 'rail' === self::$layout ? '</div></div></div>' : '</div></div>';
		self::$layout = '';
	}

	/**
	 * Entries for a page outside every group: its own tabs.
	 *
	 * @param string $slug Page slug.
	 * @return array<int, array<string, mixed>>
	 */
	private static function solo_entries( string $slug ): array {
		$page   = self::$pages[ $slug ];
		$module = ! empty( $page['module'] ) ? Plugin::instance()->module( (string) $page['module'] ) : null;
		$entry  = array(
			'slug'  => $slug,
			'label' => self::label( $slug ),
			'icon'  => self::icon( $slug ),
			'url'   => self::url( $slug ),
			'tabs'  => array(),
		);
		if ( $module && count( $module->settings() ) > 1 ) {
			foreach ( $module->settings() as $tab ) {
				$entry['tabs'][] = array(
					'id'    => (string) $tab['id'],
					'label' => (string) $tab['title'],
					'url'   => self::url( $slug, (string) $tab['id'] ),
					'icon'  => (string) ( $tab['icon'] ?? (string) $page['module'] ),
				);
			}
		}
		return array( $entry );
	}

	/**
	 * Section tiles: one tile (icon and name) per section of the group, wrapping into rows.
	 *
	 * Tabs of the group's primary page get a tile each; another page with several tabs gets one tile that opens
	 * its first tab, and its tabs are listed as sub-sections under the tiles while it is open.
	 *
	 * @param array  $entries   Entries.
	 * @param string $slug      Current page.
	 * @param string $tab       Current tab.
	 * @param string $primary   Primary page slug.
	 * @param string $nav_label Accessible name of the navigation.
	 * @return array{label: string, items: array<int, array<string, mixed>>}|null Sub-sections of the open tile.
	 */
	private static function tiles( array $entries, string $slug, string $tab, string $primary, string $nav_label ): ?array {
		$items = array();
		foreach ( $entries as $entry ) {
			$is_page = $entry['slug'] === $slug;
			if ( $entry['slug'] === $primary && $entry['tabs'] ) {
				foreach ( $entry['tabs'] as $t ) {
					$items[] = array(
						'label'    => $t['label'],
						'icon'     => $t['icon'],
						'url'      => $t['url'],
						'current'  => $is_page && $t['id'] === $tab,
						'active'   => $is_page && $t['id'] === $tab,
						'children' => array(),
					);
				}
				continue;
			}
			$children = array();
			foreach ( $entry['tabs'] as $t ) {
				$children[] = array(
					'label'   => $t['label'],
					'url'     => $t['url'],
					'current' => $is_page && $t['id'] === $tab,
				);
			}
			$items[] = array(
				'label'    => $entry['label'],
				'icon'     => $entry['icon'],
				'url'      => $entry['tabs'] ? $entry['tabs'][0]['url'] : $entry['url'],
				'current'  => $is_page && ! $entry['tabs'],
				'active'   => $is_page,
				'children' => $children,
			);
		}

		$open = null;
		echo '<nav class="mgseo-tiles" aria-label="' . esc_attr( $nav_label ) . '"><ul>';
		foreach ( $items as $item ) {
			$aria = '';
			if ( $item['current'] ) {
				$aria = ' aria-current="page"';
			} elseif ( $item['active'] ) {
				$aria = ' aria-current="true"';
			}
			echo '<li><a class="mgseo-tile' . ( $item['active'] ? ' is-current' : '' ) . '" href="' . esc_url( $item['url'] ) . '"' . $aria . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static attribute.
			echo '<span class="mgseo-tile-icon">' . ModuleIcons::svg( $item['icon'] ) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- filtered with wp_kses in ModuleIcons.
			echo '<span class="mgseo-tile-label">' . esc_html( $item['label'] ) . '</span></a></li>';
			if ( $item['active'] && $item['children'] ) {
				$open = array(
					'label' => $item['label'],
					'items' => $item['children'],
				);
			}
		}
		echo '</ul></nav>';
		return $open;
	}
}
