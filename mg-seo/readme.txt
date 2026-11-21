=== MG SEO ===
Contributors: mgraphics
Tags: seo, schema, sitemap, redirections, local seo
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.2
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Complete SEO toolkit: titles and meta, schema generator, XML sitemaps, redirections, local SEO, WooCommerce SEO and imports from Rank Math and Yoast.

== Description ==

**MG SEO** is a complete, privacy-friendly SEO plugin made by [MGraphics](https://mgraphics.com.pl). Every feature runs on your own server: no telemetry, no account, no external calls unless you configure an integration yourself.

= Features =

* **Titles & meta** – templates with variables for every post type, taxonomy and archive, per-post title/description, robots meta (incl. max-snippet / max-image-preview), canonical URLs, webmaster verification codes.
* **SEO box** in the block editor and the classic editor with a Google snippet preview and live focus keyword analysis (20+ checks, multiple keywords, SEO score in post lists).
* **Open Graph & Twitter Cards** with per-post overrides.
* **Schema generator** – Article, FAQ, HowTo, Product, Event, Person, Organization, LocalBusiness, Service, Recipe, Course, JobPosting, Video and custom JSON-LD; schema templates with display conditions; local validation against Google rich result rules.
* **XML sitemaps** with images, plus Google News and video sitemaps.
* **Redirections** (301, 302, 307, 410, 451, regex) with automatic redirects on slug changes, CSV import/export and a **404 monitor**.
* **Breadcrumbs** with BreadcrumbList schema (shortcode, block, PHP function).
* **Local SEO** – business data, opening hours, multiple locations, address shortcode, KML.
* **WooCommerce SEO** – Product schema with GTIN/MPN/brand, offers and ratings, product Open Graph, URL base options.
* **Image SEO** – automatic alt and title attributes, find & replace.
* **Bulk editing** of titles, descriptions, keywords and robots, CSV import/export.
* **Search Console** integration with your own Google Cloud project (performance reports, keyword positions, URL inspection).
* **Internal link suggestions**, link counter and orphan page report.
* **Import** from Rank Math and Yoast SEO – also from data left in the database after the plugin was removed.
* **Role manager**, robots.txt editor, llms.txt, IndexNow (opt-in), settings export/import.
* **No fingerprint** – no HTML comments, plugin names or plugin class names in your front-end markup.

= Opis (Polski) =

**MG SEO** to kompletna wtyczka SEO od [MGraphics](https://mgraphics.com.pl), dbająca o prywatność: wszystko działa na Twoim serwerze, bez telemetrii, bez konta i bez połączeń zewnętrznych, dopóki sam nie skonfigurujesz integracji.

* Szablony tytułów i opisów ze zmiennymi, robots meta, kanoniczne adresy URL, kody weryfikacyjne.
* Panel SEO w edytorze blokowym i klasycznym z podglądem wyniku Google i analizą fraz kluczowych na żywo.
* Open Graph i Twitter Cards.
* Generator schema (Article, FAQ, HowTo, Product, Event, Person, Organization, LocalBusiness, Service i własny JSON-LD), szablony schema z warunkami, walidacja zgodna z regułami Google.
* Mapy witryny XML z obrazami, Google News i wideo.
* Przekierowania (także regex), automatyczne przekierowania po zmianie adresu, monitor błędów 404.
* Okruszki (breadcrumbs) ze schema BreadcrumbList.
* Lokalne SEO, WooCommerce SEO, SEO obrazków, masowa edycja, Google Search Console (własny projekt Google), sugestie linków wewnętrznych.
* Import z Rank Math i Yoast SEO – również z danych pozostawionych w bazie po usunięciu tych wtyczek.
* Menedżer ról, edytor robots.txt, llms.txt, IndexNow (po włączeniu), eksport/import ustawień.
* Tryb bez „odcisku palca”: żadnych komentarzy HTML ani nazw wtyczki w kodzie strony.

== Installation ==

1. Upload the `mg-seo` folder to `/wp-content/plugins/` or install the ZIP via Plugins → Add New → Upload.
2. Activate the plugin.
3. Go to **MG SEO → Dashboard** to choose modules, then **MG SEO → Titles & Meta**.
4. Switching from Rank Math or Yoast? Open **MG SEO → Import & Export** and run the importer (it also works after the old plugin was deleted).

== Frequently Asked Questions ==

= Does MG SEO send any data to external servers? =

No. MG SEO has no telemetry. External requests happen only for features you explicitly configure: Google Search Console (your own Google Cloud project), IndexNow (when enabled) and update checks against the public MGraphics GitHub releases (github.com/MGraphics/mg-seo) for copies not installed from WordPress.org (define `MGSEO_UPDATE_REPO` as an empty string to disable them).

= Can I switch from Rank Math without losing my data? =

Yes. The importer reads Rank Math's titles, descriptions, focus keywords, robots, canonical URLs, social data, schema, primary categories, settings and redirections directly from the database, so it works even after Rank Math has been removed. Breadcrumb functions and shortcodes used by themes for Rank Math or Yoast keep working.

= Does it add anything to my page source that reveals the plugin? =

No. MG SEO prints only standard tags (title, meta, link, JSON-LD) without comments or plugin-specific class names.

= Czy wtyczka działa po polsku? =

Tak, interfejs jest dostępny po polsku i po angielsku.

== Changelog ==

= 0.1.0 =
* First release.
* Self-hosted updates come from GitHub Releases of github.com/MGraphics/mg-seo (release asset `mg-seo-<version>.zip`).
