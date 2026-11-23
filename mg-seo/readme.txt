=== MG SEO ===
Contributors: mgraphics
Tags: seo, schema, sitemap, redirections, local seo
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.2
Stable tag: 0.3.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Complete SEO toolkit: titles and meta, schema generator, XML sitemaps, redirections, local SEO, WooCommerce SEO and imports from Rank Math and Yoast.

== Description ==

**MG SEO** is a complete, privacy-friendly SEO plugin made by [MGraphics](https://mgraphics.com.pl). Every feature runs on your own server: no telemetry, no account, no external calls unless you configure an integration yourself.

= Features =

* **Titles & meta** – templates with variables for every post type, taxonomy and archive, per-post title/description, robots meta (incl. max-snippet / max-image-preview), canonical URLs, webmaster verification codes.
* **SEO box** in the block editor and the classic editor with a Google snippet preview and live focus keyword analysis (20+ checks, multiple keywords, SEO score in post lists).
* **SEO overview** – every indexable page sorted by score (worst first) with the 2–3 changes that matter most, counts per band, a live score badge in the editor header, the Publish box and the admin bar.
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
* Przegląd SEO – wszystkie indeksowane strony posortowane od najsłabszego wyniku, z 2–3 najważniejszymi poprawkami; wynik x/100 widoczny na żywo w nagłówku edytora, w polu „Opublikuj” i na pasku administratora.
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

= 0.3.1 =
* New: the MG SEO dashboard has new module cards: an icon for every module, the name and description, and an on/off switch at the bottom. When a module is on, the card links to its settings (or to its screen, e.g. "Open" for the bulk editor, import and role manager). Modules that are always on or need another plugin say so on the card.
* New: "On: 12 of 16" above the cards and an "Unsaved changes" note next to "Save modules", which stays in view while scrolling.
* Accessibility: the switches are real checkboxes with the switch role (Space toggles, the state, name and description are announced), with a visible focus ring; they work at phone widths and in Windows high contrast mode. Saving works as before (one "Save modules" button, same option).
* Developers: filter `mgseo/module_icon` changes the icon of a module card (inner SVG markup on a 24×24 grid).

= 0.3.0 =
* New: the score and hints are visible while editing. Block editor: a pinned "SEO 21" button opens an SEO sidebar next to the content (score, keyword, hints, title and description in Google). Classic editor: one line in the Publish box and the score in the SEO box header, visible even when the box is collapsed.
* New: Elementor: an "SEO · 68" tab next to Widgets and Globals with the same panel, and the score in the Elementor top bar. The live score is calculated from the page rendered in the preview; fields are saved right away.
* New: Beaver Builder, Divi, Bricks and the WPBakery front-end editor: a floating "SEO 68" button with the same panel (these editors hide the admin bar). Tested with Elementor and Beaver Builder Lite; Divi, Bricks and WPBakery have no free version and follow their documented functions.
* New: scores are calculated from the rendered content (blocks, shortcodes and page builders: Elementor, Beaver Builder, Bricks, Divi, WPBakery), live and stored, so the editor, lists and the overview show the same number. Text in shortcode attributes of builders that are not loaded is still counted.
* New: hints in three groups ("To improve", open and ordered by weight; "Worth considering"; "All good", closed). One cause gives one hint: a keyword missing from the page is one hint with the places where it is missing. Every hint says what to do and why, in plain words ("title in Google" instead of "SEO title", "page address" instead of "slug"), with buttons (edit the title, open the other page using the keyword, show matching pages to link to).
* New: the keyword is recognised in inflected forms and with short words in between ("masaż leczniczy w Krakowie" for "masaż leczniczy Kraków"). Filter `mgseo/analysis/inflection` turns it off.
* New: without a keyword the editors show "No keyword" and one sentence about what to enter instead of a red 40/100; checks that do not need a keyword still run.
* New: hints and the minimum length per content type (MG SEO → Content Analysis). Pages such as Contact or Pricing no longer get blog advice (600 words, a number in the title, an external link, an image).
* New: one way to show a score everywhere (mark, number, word and the number of things to improve): editors, post lists, bulk editor, overview, admin bar. The admin bar menu shows the score, the three most important things and "Open SEO hints".
* New: Google preview with the site name and icon, phone and desktop views, the keyword in bold; title and description fields show the automatic text instead of template variables, counters say "89 characters, 29 too many"; variables are under "Insert variable".
* New: social media preview card (Facebook and LinkedIn, X) next to the fields, image chosen with a thumbnail instead of a URL field.
* New: post lists: one narrow SEO column (mark, number, word; things to improve and the keyword on one line), an "SEO score" filter next to the date and category filters; the link counts column is hidden by default (Screen Options).
* Accessibility: status is never shown by colour alone (shapes, words and screen reader text), contrast of the marks is at least 4.5:1, the SEO box tabs follow the ARIA tab pattern (arrow keys, labelled panels), score changes are announced, the Google preview no longer reads every typed character; accent colour follows the admin colour scheme.
* Fix: "Edit SEO settings" in the block editor did nothing when the meta box pane was collapsed; it is now "Show hints" and opens the SEO sidebar.
* Fix: Polish plural forms in analysis messages ("22 słowa", "53 znaki", "25 słów").
* Fix: the coloured left bar on active module cards in the dashboard is gone (the dashboard gets a separate redesign).
* Developers: REST `POST mg-seo/v1/post/<id>/seo` saves keyword, title and description; `mg-seo/v1/analyze` accepts rendered `html` and returns `groups`, `fix_count`, `summary`, `spoken`; filters `mgseo/analysis/builder`, `mgseo/analysis/builder_content`, `mgseo/analysis/the_content`, `mgseo/analysis/type_rules`, `mgseo/analysis/client_config`, `mgseo/analysis/admin_bar_hints_url`.

= 0.2.0 =
* New: SEO scores are always available. They are calculated after every import (Rank Math, Yoast, CSV), on the fly in post lists, with "Recalculate all" on the list screens and the overview, a bulk action, `wp mgseo scores recalc`, and a daily background pass in small batches (scores depend on other pages, e.g. duplicate focus keywords). Imported Rank Math/Yoast scores are replaced by MG SEO's own analysis.
* New: in post lists a missing score has a "Calculate score" button that fills the cell in place; posts without a focus keyword show "Set a focus keyword" (opens the field) with a suggestion from the title or H1.
* New: MG SEO → Overview lists all indexable pages worst first with their most important fixes in plain language, counts per band (good / needs work / poor / no keyword) and a post type filter.
* New: live score badge while editing: in the block editor header (pinned sidebar button), the pre-publish panel, the classic Publish box and the admin bar (front end and editor screens, so it is visible with any page builder).
* New: REST endpoint `mg-seo/v1/analyze/html` and `MGSEO\Modules\Analysis\Module::analyze_html()` analyse any rendered HTML with a focus keyword (for page builder integrations).
* Fix: sorting the post list by SEO score no longer hides posts without a score.

= 0.1.2 =
* Fix: self-updates now install only the GitHub release asset `mg-seo-<version>.zip`. The updater could previously pick `mg-seo-<version>-wporg.zip` from the same release, which ships without the updater, so the site stopped receiving updates after that.

= 0.1.1 =
* Fix: "Check for updates" no longer fails with a fatal error (Class "Parsedown" not found) when the GitHub release has release notes. The bundled plugin-update-checker now ships complete, including its vendor/ libraries.

= 0.1.0 =
* First release.
* Self-hosted updates come from GitHub Releases of github.com/MGraphics/mg-seo (release asset `mg-seo-<version>.zip`).
