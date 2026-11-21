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

		return <<<XSL
<?xml version="1.0" encoding="UTF-8"?>
<xsl:stylesheet version="1.0"
	xmlns:xsl="http://www.w3.org/1999/XSL/Transform"
	xmlns:s="http://www.sitemaps.org/schemas/sitemap/0.9"
	xmlns:image="http://www.google.com/schemas/sitemap-image/1.1"
	xmlns:video="http://www.google.com/schemas/sitemap-video/1.1"
	xmlns:news="http://www.google.com/schemas/sitemap-news/0.9"
	exclude-result-prefixes="s image video news">
<xsl:output method="html" version="1.0" encoding="UTF-8" indent="yes" doctype-system="about:legacy-compat"/>
<xsl:template match="/">
<html lang="{$lang}">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1"/>
<meta name="robots" content="noindex, follow"/>
<title>{$title}</title>
<style>
body{margin:0;padding:24px 16px;font:14px/1.5 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;color:#1d2327;background:#f6f7f7}
main{max-width:1100px;margin:0 auto}
h1{font-size:24px;margin:0 0 8px}
p{margin:0 0 12px;color:#50575e}
table{width:100%;border-collapse:collapse;background:#fff;border:1px solid #dcdcde}
th,td{text-align:left;padding:8px 10px;border-bottom:1px solid #f0f0f1;vertical-align:top}
th{background:#f0f0f1;font-weight:600;white-space:nowrap}
td.n,th.n{text-align:right;width:1%;white-space:nowrap}
td.d{white-space:nowrap;width:1%}
tr:nth-child(even) td{background:#fafafa}
a{color:#2271b1;text-decoration:none;word-break:break-all}
a:hover{text-decoration:underline}
</style>
</head>
<body>
<main>
<h1>{$title}</h1>
<p>{$intro}</p>
<xsl:choose>
<xsl:when test="s:sitemapindex">
<p>{$index_desc} {$count_s} <xsl:value-of select="count(s:sitemapindex/s:sitemap)"/></p>
<table>
<thead><tr><th>{$col_map}</th><th class="d">{$col_mod}</th></tr></thead>
<tbody>
<xsl:for-each select="s:sitemapindex/s:sitemap">
<tr>
<td><a href="{s:loc}"><xsl:value-of select="s:loc"/></a></td>
<td class="d"><xsl:value-of select="concat(substring(s:lastmod,1,10),' ',substring(s:lastmod,12,5))"/></td>
</tr>
</xsl:for-each>
</tbody>
</table>
</xsl:when>
<xsl:otherwise>
<p><a href="{$index_url}">&#8592; {$back}</a> &#183; {$count_u} <xsl:value-of select="count(s:urlset/s:url)"/></p>
<table>
<thead><tr>
<th>{$col_url}</th>
<xsl:if test="s:urlset/s:url/image:image"><th class="n">{$col_img}</th></xsl:if>
<xsl:if test="s:urlset/s:url/video:video"><th class="n">{$col_vid}</th></xsl:if>
<xsl:choose>
<xsl:when test="s:urlset/s:url/news:news"><th class="d">{$col_pub}</th></xsl:when>
<xsl:otherwise><th class="d">{$col_mod}</th></xsl:otherwise>
</xsl:choose>
</tr></thead>
<tbody>
<xsl:variable name="hasimg" select="count(s:urlset/s:url/image:image) &gt; 0"/>
<xsl:variable name="hasvid" select="count(s:urlset/s:url/video:video) &gt; 0"/>
<xsl:for-each select="s:urlset/s:url">
<tr>
<td><a href="{s:loc}"><xsl:value-of select="s:loc"/></a><xsl:if test="news:news"><br/><xsl:value-of select="news:news/news:title"/></xsl:if><xsl:for-each select="video:video"><br/><xsl:value-of select="video:title"/></xsl:for-each></td>
<xsl:if test="\$hasimg"><td class="n"><xsl:value-of select="count(image:image)"/></td></xsl:if>
<xsl:if test="\$hasvid"><td class="n"><xsl:value-of select="count(video:video)"/></td></xsl:if>
<xsl:choose>
<xsl:when test="news:news"><td class="d"><xsl:value-of select="concat(substring(news:news/news:publication_date,1,10),' ',substring(news:news/news:publication_date,12,5))"/></td></xsl:when>
<xsl:otherwise><td class="d"><xsl:value-of select="concat(substring(s:lastmod,1,10),' ',substring(s:lastmod,12,5))"/></td></xsl:otherwise>
</xsl:choose>
</tr>
</xsl:for-each>
</tbody>
</table>
</xsl:otherwise>
</xsl:choose>
</main>
</body>
</html>
</xsl:template>
</xsl:stylesheet>

XSL;
	}
}
