<?php
/**
 * Sitemap XSL stylesheet.
 *
 * @package MGSEO
 */

namespace MGSEO\Modules\Sitemap;

defined( 'ABSPATH' ) || exit;

/**
 * Human-readable view of sitemaps in browsers. Neutral markup without any plugin branding.
 */
final class Stylesheet {

	/**
	 * XSL document.
	 */
	public static function xsl(): string {
		$t = static fn( string $text ): string => Renderer::esc( $text );

		$title      = $t( __( 'XML Sitemap', 'mg-seo' ) );
		$intro      = $t( __( 'This XML sitemap is meant for search engines. It lists the URLs of this site that should be indexed.', 'mg-seo' ) );
		$index_desc = $t( __( 'This index lists the individual sitemaps of the site.', 'mg-seo' ) );
		$count_s    = $t( __( 'Number of sitemaps:', 'mg-seo' ) );
		$count_u    = $t( __( 'Number of URLs:', 'mg-seo' ) );
		$col_map    = $t( __( 'Sitemap', 'mg-seo' ) );
		$col_url    = $t( __( 'URL', 'mg-seo' ) );
		$col_img    = $t( __( 'Images', 'mg-seo' ) );
		$col_vid    = $t( __( 'Videos', 'mg-seo' ) );
		$col_pub    = $t( __( 'Published', 'mg-seo' ) );
		$col_mod    = $t( __( 'Last modified', 'mg-seo' ) );
		$back       = $t( __( 'All sitemaps', 'mg-seo' ) );
		$index_url  = $t( wp_make_link_relative( Builder::url( 'index' ) ) );
		$lang       = $t( str_replace( '_', '-', determine_locale() ) );

		$template = (string) file_get_contents( __DIR__ . '/stylesheet.xsl' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local template file.
		return strtr(
			$template,
			array(
				'{{back}}' => $back,
				'{{col_img}}' => $col_img,
				'{{col_map}}' => $col_map,
				'{{col_mod}}' => $col_mod,
				'{{col_pub}}' => $col_pub,
				'{{col_url}}' => $col_url,
				'{{col_vid}}' => $col_vid,
				'{{count_s}}' => $count_s,
				'{{count_u}}' => $count_u,
				'{{index_desc}}' => $index_desc,
				'{{index_url}}' => $index_url,
				'{{intro}}' => $intro,
				'{{lang}}' => $lang,
				'{{title}}' => $title,
			)
		);
	}
}
