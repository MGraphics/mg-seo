<?php
/**
 * Line icons for the module cards on the dashboard and the admin navigation.
 *
 * Drawn for MG SEO on a 24 px grid: 1.75 px stroke, round caps and joins, no fills, so they take the text colour.
 *
 * @package MGSEO
 */

namespace MGSEO\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Module icons.
 */
final class ModuleIcons {

	/**
	 * Inner SVG markup per module id.
	 *
	 * @return array<string, string>
	 */
	private static function shapes(): array {
		return array(
			// A "T" with lines of text: title and description templates.
			'titles'       => '<path d="M3.5 5h10M8.5 5v14"/><path d="M14 11h6.5M14 15h6.5M14 19h4"/>',
			// Three connected nodes: sharing.
			'social'       => '<circle cx="17.5" cy="5.5" r="2.5"/><circle cx="6.5" cy="12" r="2.5"/><circle cx="17.5" cy="18.5" r="2.5"/><path d="m8.7 10.7 6.6-3.9M8.7 13.3l6.6 3.9"/>',
			// A gauge: the SEO score.
			'analysis'     => '<path d="M4 16.5a8 8 0 0 1 16 0"/><path d="m12 16.5 3.6-4.4"/><circle cx="12" cy="16.5" r="1.3"/><path d="M4 20h16"/>',
			// Page › page: a trail.
			'breadcrumbs'  => '<rect x="2.5" y="8" width="6.5" height="8" rx="1.6"/><rect x="15" y="8" width="6.5" height="8" rx="1.6"/><path d="m10.8 9.3 2.2 2.7-2.2 2.7"/>',
			// A page tree.
			'sitemap'      => '<rect x="9.5" y="3" width="5" height="4.5" rx="1.2"/><rect x="2.5" y="16.5" width="5" height="4.5" rx="1.2"/><rect x="9.5" y="16.5" width="5" height="4.5" rx="1.2"/><rect x="16.5" y="16.5" width="5" height="4.5" rx="1.2"/><path d="M12 7.5v9M5 16.5V12h14v4.5"/>',
			// Curly braces: JSON-LD.
			'schema'       => '<path d="M8.5 4.5H8a2 2 0 0 0-2 2v3A2.5 2.5 0 0 1 3.5 12 2.5 2.5 0 0 1 6 14.5v3a2 2 0 0 0 2 2h.5"/><path d="M15.5 4.5h.5a2 2 0 0 1 2 2v3a2.5 2.5 0 0 0 2.5 2.5 2.5 2.5 0 0 0-2.5 2.5v3a2 2 0 0 1-2 2h-.5"/><path d="M10 12h.01M14 12h.01"/>',
			// A path that turns: a redirect.
			'redirections' => '<path d="M4.5 19.5V14a5.5 5.5 0 0 1 5.5-5.5h9.5"/><path d="m16 5 3.5 3.5L16 12"/>',
			// A map pin.
			'local'        => '<path d="M12 21s-6.5-5.6-6.5-11a6.5 6.5 0 0 1 13 0c0 5.4-6.5 11-6.5 11z"/><circle cx="12" cy="10" r="2.3"/>',
			// A shopping bag.
			'woocommerce'  => '<path d="M5.5 8h13l-.9 11.1a1.5 1.5 0 0 1-1.5 1.4H7.9a1.5 1.5 0 0 1-1.5-1.4z"/><path d="M9 10.5V7a3 3 0 0 1 6 0v3.5"/>',
			// A picture.
			'image'        => '<rect x="3.5" y="4.5" width="17" height="15" rx="2.5"/><circle cx="9" cy="9.5" r="1.6"/><path d="m4 17 4.5-4.5 3.5 3.5 2.5-2.5 5 5"/>',
			// Stacked sheets: many posts at once.
			'bulk'         => '<path d="M12 3.5 3.5 8l8.5 4.5L20.5 8z"/><path d="m3.5 12 8.5 4.5 8.5-4.5"/><path d="m3.5 16 8.5 4.5 8.5-4.5"/>',
			// A magnifier with a rising line: search performance.
			'gsc'          => '<circle cx="10.5" cy="10.5" r="6.5"/><path d="m15.3 15.3 5.2 5.2"/><path d="m7.3 12.3 2.2-2.2 1.8 1.8 2.4-2.9"/>',
			// Two chain links.
			'links'        => '<path d="M10 14a4 4 0 0 0 5.7 0l3-3a4 4 0 0 0-5.7-5.7l-1 1"/><path d="M14 10a4 4 0 0 0-5.7 0l-3 3a4 4 0 0 0 5.7 5.7l1-1"/>',
			// Arrows in and out.
			'import'       => '<path d="M8 4v13M4.5 13.5 8 17l3.5-3.5"/><path d="M16 20V7M12.5 10.5 16 7l3.5 3.5"/>',
			// Two people.
			'roles'        => '<circle cx="9" cy="8" r="3.2"/><path d="M3.5 19.5a5.5 5.5 0 0 1 11 0"/><path d="M15.5 5.2a3 3 0 0 1 0 5.6M17.5 14.2a5.5 5.5 0 0 1 3 5.3"/>',
			// A toolbox.
			'tools'        => '<rect x="3" y="8" width="18" height="12" rx="2"/><path d="M9 8V6.5A1.5 1.5 0 0 1 10.5 5h3A1.5 1.5 0 0 1 15 6.5V8M3 13.5h18"/>',
		);
	}

	/**
	 * Icons of admin sections that are not modules (section tiles of the settings screens), same grid and stroke.
	 *
	 * @return array<string, string>
	 */
	private static function sections(): array {
		return array(
			// Four tiles: the modules.
			'modules'  => '<rect x="4" y="4" width="6.5" height="6.5" rx="1.6"/><rect x="13.5" y="4" width="6.5" height="6.5" rx="1.6"/><rect x="4" y="13.5" width="6.5" height="6.5" rx="1.6"/><rect x="13.5" y="13.5" width="6.5" height="6.5" rx="1.6"/>',
			// Two sliders: general options.
			'sliders'  => '<path d="M4 7.5h8.5M17.5 7.5H20M4 16.5h2.5M11.5 16.5H20"/><circle cx="15" cy="7.5" r="2.5"/><circle cx="9" cy="16.5" r="2.5"/>',
			// A house: the homepage.
			'home'     => '<path d="m3.5 11 8.5-7 8.5 7"/><path d="M6 9.5v10h12v-10"/><path d="M10 19.5v-5h4v5"/>',
			// A page with lines: posts and pages.
			'document' => '<path d="M13.5 3.5h-6a1.5 1.5 0 0 0-1.5 1.5v14a1.5 1.5 0 0 0 1.5 1.5h9a1.5 1.5 0 0 0 1.5-1.5V7.5z"/><path d="M13.5 3.5v4h4M9.5 12h5M9.5 15.5h5"/>',
			// A tag: categories and tags.
			'tag'      => '<path d="M3.5 12V5a1.5 1.5 0 0 1 1.5-1.5h7l8.1 8.1a1.5 1.5 0 0 1 0 2.1l-6.4 6.4a1.5 1.5 0 0 1-2.1 0z"/><circle cx="8.5" cy="8.5" r="1.4"/>',
			// A folder: archives and other pages.
			'folder'   => '<path d="M3.5 7A1.5 1.5 0 0 1 5 5.5h4.2l2 2.5H19a1.5 1.5 0 0 1 1.5 1.5V18a1.5 1.5 0 0 1-1.5 1.5H5A1.5 1.5 0 0 1 3.5 18z"/>',
			// A shield with a tick: verified with search engines.
			'shield'   => '<path d="M12 3.5 5 6.2v5.3c0 4.1 2.9 7.6 7 9 4.1-1.4 7-4.9 7-9V6.2z"/><path d="m9 12 2.2 2.2 3.8-4"/>',
			// A person: authors.
			'person'   => '<circle cx="12" cy="8" r="3.5"/><path d="M5 20a7 7 0 0 1 14 0"/>',
			// A newspaper.
			'news'     => '<rect x="3.5" y="4.5" width="17" height="15" rx="2"/><path d="M7.5 9h9M7.5 12.5h9M7.5 16h5"/>',
			// A play button: video.
			'video'    => '<rect x="3.5" y="5.5" width="17" height="13" rx="2.5"/><path d="m10.5 9.5 4 2.5-4 2.5z"/>',
			// A warning sign: missing pages (404).
			'missing'  => '<path d="M10.7 4.3 3 17.8a1.5 1.5 0 0 0 1.3 2.2h15.4a1.5 1.5 0 0 0 1.3-2.2L13.3 4.3a1.5 1.5 0 0 0-2.6 0z"/><path d="M12 9.5v4.5M12 17h.01"/>',
		);
	}

	/**
	 * Fallback for modules added through the `mgseo/modules` filter.
	 */
	private const FALLBACK = '<rect x="4" y="4" width="16" height="16" rx="3.5"/><path d="M12 8.5v7M8.5 12h7"/>';

	/**
	 * Icon markup for a module.
	 *
	 * @param string $id Module id.
	 */
	public static function svg( string $id ): string {
		$shape = self::shapes()[ $id ] ?? self::sections()[ $id ] ?? self::FALLBACK;
		/**
		 * Filter the inner SVG markup (24×24 grid, stroked with currentColor) of a module's dashboard icon.
		 *
		 * @param string $shape Inner SVG markup.
		 * @param string $id    Module id.
		 */
		$shape = (string) apply_filters( 'mgseo/module_icon', $shape, $id );
		return self::wrap( $shape );
	}

	/**
	 * Small arrow used after "Settings" links.
	 */
	public static function arrow(): string {
		return self::wrap( '<path d="M5 12h13.5M13 6.5l5.5 5.5-5.5 5.5"/>' );
	}

	/**
	 * Wrap inner markup in the shared SVG element, keeping only drawing elements inside it.
	 *
	 * @param string $shape Inner SVG markup.
	 */
	private static function wrap( string $shape ): string {
		return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . wp_kses( $shape, self::allowed() ) . '</svg>';
	}

	/**
	 * Allowed SVG elements and attributes.
	 *
	 * @return array<string, array<string, bool>>
	 */
	private static function allowed(): array {
		$common = array(
			'fill'            => true,
			'stroke'          => true,
			'stroke-width'    => true,
			'stroke-linecap'  => true,
			'stroke-linejoin' => true,
			'opacity'         => true,
			'transform'       => true,
		);
		return array(
			'g'        => $common,
			'path'     => $common + array( 'd' => true ),
			'circle'   => $common + array(
				'cx' => true,
				'cy' => true,
				'r'  => true,
			),
			'ellipse'  => $common + array(
				'cx' => true,
				'cy' => true,
				'rx' => true,
				'ry' => true,
			),
			'rect'     => $common + array(
				'x'      => true,
				'y'      => true,
				'width'  => true,
				'height' => true,
				'rx'     => true,
				'ry'     => true,
			),
			'line'     => $common + array(
				'x1' => true,
				'y1' => true,
				'x2' => true,
				'y2' => true,
			),
			'polyline' => $common + array( 'points' => true ),
			'polygon'  => $common + array( 'points' => true ),
		);
	}
}
