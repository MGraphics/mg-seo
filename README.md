# MG SEO

Complete, privacy-friendly SEO plugin for WordPress by **[MGraphics](https://mgraphics.com.pl)**.

Titles and meta, a schema generator, XML sitemaps (with image, news and video), redirections with a 404 monitor, local SEO, WooCommerce SEO, image SEO, bulk editing, Google Search Console reports, internal link suggestions, and imports from Rank Math and Yoast SEO. Everything runs on your own server: no telemetry, no account, and no external calls unless you configure an integration yourself.

> Polski: kompletna wtyczka SEO dla WordPressa od MGraphics. Zobacz [`mg-seo/readme.txt`](mg-seo/readme.txt), sekcja „Opis (Polski)”.

## Features

| Area | What you get |
|---|---|
| Titles & meta | Templates with variables per post type, taxonomy and archive; per-post title/description; robots (noindex, nofollow, max-snippet, max-image-preview …); canonical URLs; verification codes; RSS before/after text |
| Editor | Score and grouped hints while editing: SEO sidebar with a pinned score button in the block editor, Publish box line and SEO box in the classic editor, an SEO tab and top-bar score in Elementor, a floating panel in Beaver Builder, Divi, Bricks and WPBakery, and the admin bar; analysis of the rendered content (blocks, shortcodes, page builders) with Polish inflection; Google and social media previews; one narrow SEO column with a score filter in post lists |
| SEO overview | Every indexable page sorted by score (worst first) with the 2–3 most important fixes, counts per band; scores recalculated after imports, on demand (`wp mgseo scores recalc`) and daily |
| Social | Open Graph and Twitter Cards with per-post overrides and default images |
| Schema | Site graph (WebSite, Organization/Person, WebPage, BreadcrumbList), generator for Article, FAQ, HowTo, Product, Event, Person, Organization, LocalBusiness, Service and more, custom JSON-LD, templates with conditions, local validation |
| Sitemaps | XML sitemap index at `/sitemap.xml`, post type/taxonomy/author sitemaps (`/sitemap-article.xml`, `/sitemap-page.xml`, `/sitemap-{name}.xml`) with images, Google News and video sitemaps, neutral XSL, 301s from old addresses |
| Redirections | 301/302/307/410/451, regex, automatic slug-change redirects, CSV import/export, 404 monitor |
| Local SEO | Business data, opening hours, multiple locations, address shortcode/block, KML |
| WooCommerce | Product schema (GTIN/MPN/brand, offers, ratings), product Open Graph, URL base options |
| Images | Automatic alt/title attributes, case options, find & replace |
| Tools | Bulk editor + CSV, role manager, robots.txt editor, llms.txt, IndexNow (opt-in), settings export/import |
| Search Console | OAuth with your own Google Cloud project: performance, keyword positions, URL inspection |
| Migration | Import from Rank Math and Yoast, including data left behind after the plugin was deleted; Rank Math/Yoast breadcrumb functions keep working in themes |
| No fingerprint | No HTML comments, plugin names or plugin class names in front-end markup |

## Requirements

- WordPress 6.5+ (tested with 7.1)
- PHP 8.2+

## Installation

1. Download `mg-seo-<version>.zip` from the [latest GitHub release](https://github.com/MGraphics/mg-seo/releases/latest)
   (the attached asset, not the "Source code" archive).
2. In WordPress go to **Plugins → Add New → Upload Plugin**, upload the ZIP and activate it.
3. Open **MG SEO → Dashboard** to pick modules, then **MG SEO → Titles & Meta**.

Updates appear in **Dashboard → Updates** like any other plugin. They come from the
[GitHub Releases](https://github.com/MGraphics/mg-seo/releases) of this public repository through the bundled
[plugin-update-checker](https://github.com/YahnisElsts/plugin-update-checker) (MIT); no GitHub account or token
is needed. To turn update checks off, add `define( 'MGSEO_UPDATE_REPO', '' );` to `wp-config.php`. Copies
installed from WordPress.org never use the self-updater.

## Development

```bash
composer install                       # PHPUnit, WPCS (dev only)
docker compose up -d                   # dev site http://127.0.0.1:8471 (admin/admin)
docker compose run --rm tests          # WordPress integration tests
vendor/bin/phpcs                       # coding standards
bin/build.sh                           # dist/mg-seo-<version>.zip (release asset)
bin/build.sh --wporg                   # WordPress.org package (no self-updater)
bin/check-release-zip.sh dist/mg-seo-<version>.zip  # update check from the built zip (mocked GitHub release)
```

Plugin code lives in [`mg-seo/`](mg-seo); tests in [`tests/`](tests).

## Releasing

1. Bump `Version:` in `mg-seo/mg-seo.php`, `Stable tag:` and the changelog in `mg-seo/readme.txt`.
2. `bin/build.sh`, then `bin/check-release-zip.sh dist/mg-seo-<version>.zip` (must print `OK`).
3. Create a GitHub release with tag `v<version>` and attach `dist/mg-seo-<version>.zip` as a release asset, e.g.
   `gh release create v<version> dist/mg-seo-<version>.zip --repo MGraphics/mg-seo --title "MG SEO <version>"`.
   Sites pick it up on their next update check.

## Author

**MGraphics** — [https://mgraphics.com.pl](https://mgraphics.com.pl)

## License

GPL-2.0-or-later. See [`mg-seo/LICENSE`](mg-seo/LICENSE). Bundled: plugin-update-checker (MIT).
