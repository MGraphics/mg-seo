<?php
/**
 * Yoast SEO leftover data importer.
 *
 * @package MGSEO
 */

namespace MGSEO\Modules\Import;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- reads and removes Yoast's own rows and tables.

/**
 * Imports Yoast SEO data from the database only: `_yoast_wpseo_*` post meta, the `wpseo_taxonomy_meta`
 * option (terms), `wpseo_*` user meta, the `wpseo`, `wpseo_titles` and `wpseo_social` options and
 * Premium redirects (`wpseo-premium-redirects-base`). Yoast's indexable tables are not needed.
 */
final class Yoast extends Source {

	private const LIKE = '\_yoast\_wpseo\_%';

	/**
	 * Id.
	 */
	public function id(): string {
		return 'yoast';
	}

	/**
	 * Label.
	 */
	public function label(): string {
		return 'Yoast SEO';
	}

	/**
	 * Plugin file.
	 */
	public function plugin_file(): string {
		return 'wordpress-seo/wp-seo.php';
	}

	/**
	 * Scopes.
	 */
	public function scopes(): array {
		return array( 'settings', 'posts', 'terms', 'users', 'redirections' );
	}

	/**
	 * Separator codes used by Yoast.
	 *
	 * @return array<string, string>
	 */
	public static function separators(): array {
		return array(
			'sc-dash'   => '-',
			'sc-ndash'  => '–',
			'sc-mdash'  => '—',
			'sc-colon'  => ':',
			'sc-middot' => '·',
			'sc-bull'   => '•',
			'sc-star'   => '*',
			'sc-smstar' => '⋆',
			'sc-pipe'   => '|',
			'sc-tilde'  => '~',
			'sc-laquo'  => '«',
			'sc-raquo'  => '»',
			'sc-lt'     => '<',
			'sc-gt'     => '>',
		);
	}

	/**
	 * Term meta option (taxonomy => term id => data).
	 *
	 * @return array<string, array<int, array<string, mixed>>>
	 */
	private function term_meta(): array {
		$value = get_option( 'wpseo_taxonomy_meta', array() );
		return is_array( $value ) ? $value : array();
	}

	/**
	 * Flat list of [taxonomy, term id] pairs with Yoast data, sorted by term id.
	 *
	 * @return array<int, string> term id => taxonomy
	 */
	private function term_ids(): array {
		$out = array();
		foreach ( $this->term_meta() as $taxonomy => $terms ) {
			foreach ( is_array( $terms ) ? $terms : array() as $term_id => $data ) {
				if ( is_array( $data ) && $data ) {
					$out[ (int) $term_id ] = (string) $taxonomy;
				}
			}
		}
		ksort( $out );
		return $out;
	}

	/**
	 * Premium redirects.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function redirects(): array {
		$value = get_option( 'wpseo-premium-redirects-base', array() );
		return is_array( $value ) ? array_values( array_filter( $value, 'is_array' ) ) : array();
	}

	/**
	 * Leftover data counts.
	 */
	public function detect(): array {
		$settings = 0;
		foreach ( array( 'wpseo', 'wpseo_titles', 'wpseo_social' ) as $name ) {
			if ( is_array( get_option( $name ) ) ) {
				++$settings;
			}
		}
		return array(
			'settings'     => $settings,
			'posts'        => $this->meta_object_count( 'post', self::LIKE ),
			'terms'        => count( $this->term_ids() ),
			'users'        => count( $this->user_ids( 0, PHP_INT_MAX ) ),
			'redirections' => count( $this->redirects() ),
		);
	}

	/**
	 * Users with Yoast author meta.
	 *
	 * @param int $after Last id.
	 * @param int $limit Limit.
	 * @return int[]
	 */
	private function user_ids( int $after, int $limit ): array {
		global $wpdb;
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT user_id FROM %i WHERE meta_key IN ('wpseo_title','wpseo_metadesc','wpseo_noindex_author') AND meta_value <> '' AND user_id > %d ORDER BY user_id ASC LIMIT %d", $wpdb->usermeta, $after, $limit ) );
		return array_map( 'intval', (array) $ids );
	}

	/**
	 * Process a batch.
	 *
	 * @param string $scope Scope.
	 * @param int    $after Last id.
	 * @param int    $limit Limit.
	 */
	public function batch( string $scope, int $after, int $limit ): array {
		return match ( $scope ) {
			'settings'     => $this->import_settings(),
			'posts'        => $this->import_posts( $after, $limit ),
			'terms'        => $this->import_terms( $after, $limit ),
			'users'        => $this->import_users( $after, $limit ),
			'redirections' => $this->import_redirections( $after, $limit ),
			default        => array(
				'last'      => 0,
				'processed' => 0,
				'done'      => true,
			),
		};
	}

	/**
	 * Convert a template.
	 *
	 * @param mixed $value Value.
	 */
	private function tpl( mixed $value ): ?string {
		if ( null === $value || is_array( $value ) || '' === (string) $value ) {
			return null;
		}
		return Converter::yoast( (string) $value, $this->log );
	}

	/**
	 * Import settings.
	 *
	 * @return array{last: int, processed: int, done: bool}
	 */
	private function import_settings(): array {
		$titles = get_option( 'wpseo_titles', array() );
		$titles = is_array( $titles ) ? $titles : array();
		$social = get_option( 'wpseo_social', array() );
		$social = is_array( $social ) ? $social : array();
		$main   = get_option( 'wpseo', array() );
		$main   = is_array( $main ) ? $main : array();
		$t      = static fn( string $key ) => $titles[ $key ] ?? null;

		if ( isset( $titles['separator'] ) ) {
			$sep = self::separators()[ (string) $titles['separator'] ] ?? html_entity_decode( (string) $titles['separator'], ENT_QUOTES, 'UTF-8' );
			$this->put_setting( 'titles', 'separator', $sep );
		}
		$this->put_setting( 'titles', 'home_title', $this->tpl( $t( 'title-home-wpseo' ) ) );
		$this->put_setting( 'titles', 'home_description', $this->tpl( $t( 'metadesc-home-wpseo' ) ) );
		foreach ( array_keys( $titles ) as $key ) {
			if ( preg_match( '/^title-tax-(.+)$/', $key, $m ) ) {
				$tax = $m[1];
				$this->put_setting( 'titles', 'tax_' . $tax . '_title', $this->tpl( $t( $key ) ) );
				$this->put_setting( 'titles', 'tax_' . $tax . '_description', $this->tpl( $t( 'metadesc-tax-' . $tax ) ) );
				$this->put_setting( 'titles', 'tax_' . $tax . '_robots', ! empty( $titles[ 'noindex-tax-' . $tax ] ) ? array( 'noindex' ) : array() );
				continue;
			}
			if ( preg_match( '/^title-ptarchive-(.+)$/', $key, $m ) ) {
				$this->put_setting( 'titles', 'pt_' . $m[1] . '_archive_title', $this->tpl( $t( $key ) ) );
				$this->put_setting( 'titles', 'pt_' . $m[1] . '_archive_description', $this->tpl( $t( 'metadesc-ptarchive-' . $m[1] ) ) );
				continue;
			}
			if ( preg_match( '/^title-(.+)$/', $key, $m ) && ! str_ends_with( $m[1], '-wpseo' ) && post_type_exists( $m[1] ) ) {
				$pt = $m[1];
				$this->put_setting( 'titles', 'pt_' . $pt . '_title', $this->tpl( $t( $key ) ) );
				$this->put_setting( 'titles', 'pt_' . $pt . '_description', $this->tpl( $t( 'metadesc-' . $pt ) ) );
				$this->put_setting( 'titles', 'pt_' . $pt . '_robots', ! empty( $titles[ 'noindex-' . $pt ] ) ? array( 'noindex' ) : array() );
			}
		}
		if ( isset( $titles['disable-author'] ) ) {
			$this->put_setting( 'titles', 'author_archive', empty( $titles['disable-author'] ) );
		}
		$this->put_setting( 'titles', 'author_title', $this->tpl( $t( 'title-author-wpseo' ) ) );
		$this->put_setting( 'titles', 'author_description', $this->tpl( $t( 'metadesc-author-wpseo' ) ) );
		if ( isset( $titles['noindex-author-wpseo'] ) ) {
			$this->put_setting( 'titles', 'author_robots', ! empty( $titles['noindex-author-wpseo'] ) ? array( 'noindex' ) : array() );
		}
		if ( isset( $titles['disable-date'] ) ) {
			$this->put_setting( 'titles', 'date_archive', empty( $titles['disable-date'] ) );
		}
		$this->put_setting( 'titles', 'date_title', $this->tpl( $t( 'title-archive-wpseo' ) ) );
		if ( isset( $titles['noindex-archive-wpseo'] ) ) {
			$this->put_setting( 'titles', 'date_robots', ! empty( $titles['noindex-archive-wpseo'] ) ? array( 'noindex' ) : array() );
		}
		$this->put_setting( 'titles', 'search_title', $this->tpl( $t( 'title-search-wpseo' ) ) );
		$this->put_setting( 'titles', '404_title', $this->tpl( $t( 'title-404-wpseo' ) ) );
		if ( isset( $titles['disable-attachment'] ) ) {
			$this->put_setting( 'titles', 'attachment_redirect', ! empty( $titles['disable-attachment'] ) );
		}
		foreach ( array(
			'rssbefore' => 'rss_before',
			'rssafter'  => 'rss_after',
		) as $from => $to ) {
			if ( isset( $titles[ $from ] ) ) {
				$this->put_setting( 'titles', $to, str_replace( array( '%%POSTLINK%%', '%%BLOGLINK%%' ), array( '%postlink%', '%sitelink%' ), (string) $titles[ $from ] ) );
			}
		}

		// Verification codes live in `wpseo` (Pinterest in `wpseo_social` in older versions).
		$verify = array(
			'googleverify'    => 'verify_google',
			'msverify'        => 'verify_bing',
			'yandexverify'    => 'verify_yandex',
			'baiduverify'     => 'verify_baidu',
			'pinterestverify' => 'verify_pinterest',
		);
		foreach ( $verify as $from => $to ) {
			$value = (string) ( $main[ $from ] ?? $social[ $from ] ?? '' );
			if ( '' !== $value ) {
				$this->put_setting( 'titles', $to, sanitize_text_field( $value ) );
			}
		}

		// Social.
		if ( ! empty( $social['og_default_image'] ) ) {
			$this->put_setting( 'social', 'default_image', esc_url_raw( (string) $social['og_default_image'] ) );
		}
		if ( ! empty( $social['og_frontpage_image'] ) ) {
			$this->put_setting( 'titles', 'home_og_image', esc_url_raw( (string) $social['og_frontpage_image'] ) );
		}
		if ( ! empty( $social['facebook_site'] ) ) {
			$this->put_setting( 'social', 'facebook_page', esc_url_raw( (string) $social['facebook_site'] ) );
		}
		if ( ! empty( $social['fbadminapp'] ) ) {
			$this->put_setting( 'social', 'facebook_app_id', sanitize_text_field( (string) $social['fbadminapp'] ) );
		}
		if ( ! empty( $social['twitter_site'] ) ) {
			$this->put_setting( 'social', 'twitter_site', ltrim( sanitize_text_field( (string) $social['twitter_site'] ), '@' ) );
		}
		if ( in_array( (string) ( $social['twitter_card_type'] ?? '' ), array( 'summary', 'summary_large_image' ), true ) ) {
			$this->put_setting( 'social', 'twitter_card', (string) $social['twitter_card_type'] );
		}
		$same_as = Converter::url_list( is_array( $social['other_social_urls'] ?? null ) ? $social['other_social_urls'] : array() );
		foreach ( array( 'instagram_url', 'linkedin_url', 'myspace_url', 'pinterest_url', 'youtube_url', 'wikipedia_url' ) as $legacy ) {
			if ( ! empty( $social[ $legacy ] ) ) {
				$same_as[] = esc_url_raw( (string) $social[ $legacy ] );
			}
		}
		if ( $same_as ) {
			$this->put_setting( 'schema', 'same_as', array_values( array_unique( array_filter( $same_as ) ) ) );
		}

		// Knowledge graph.
		$person = 'person' === (string) ( $titles['company_or_person'] ?? '' );
		if ( isset( $titles['company_or_person'] ) ) {
			$this->put_setting( 'schema', 'kg_type', $person ? 'person' : 'organization' );
		}
		if ( $person ) {
			$user = get_userdata( (int) ( $titles['company_or_person_user_id'] ?? 0 ) );
			if ( $user ) {
				$this->put_setting( 'schema', 'person_name', $user->display_name );
			}
			if ( ! empty( $titles['person_logo'] ) ) {
				$this->put_setting( 'schema', 'person_image', esc_url_raw( (string) $titles['person_logo'] ) );
			}
		} else {
			if ( ! empty( $titles['company_name'] ) ) {
				$this->put_setting( 'schema', 'org_name', sanitize_text_field( (string) $titles['company_name'] ) );
			}
			if ( ! empty( $titles['company_alternate_name'] ) ) {
				$this->put_setting( 'schema', 'org_alt_name', sanitize_text_field( (string) $titles['company_alternate_name'] ) );
			}
			if ( ! empty( $titles['company_logo'] ) ) {
				$this->put_setting( 'schema', 'org_logo', esc_url_raw( (string) $titles['company_logo'] ) );
			}
		}
		if ( ! empty( $titles['website_name'] ) ) {
			$this->put_setting( 'schema', 'website_name', sanitize_text_field( (string) $titles['website_name'] ) );
		}
		if ( ! empty( $titles['alternate_website_name'] ) ) {
			$this->put_setting( 'schema', 'website_alt_name', sanitize_text_field( (string) $titles['alternate_website_name'] ) );
		}
		foreach ( array_keys( $titles ) as $key ) {
			if ( preg_match( '/^schema-article-type-(.+)$/', $key, $m ) && post_type_exists( $m[1] ) ) {
				$type = (string) $titles[ $key ];
				if ( 'None' === $type ) {
					$this->put_setting( 'schema', 'pt_' . $m[1] . '_type', '' );
				} elseif ( in_array( $type, array( 'Article', 'BlogPosting', 'NewsArticle', 'TechArticle', 'ScholarlyArticle', 'Report', 'SocialMediaPosting', 'SatiricalArticle', 'AdvertiserContentArticle' ), true ) ) {
					$this->put_setting( 'schema', 'pt_' . $m[1] . '_type', 'Article' );
					if ( 'post' === $m[1] && in_array( $type, array( 'Article', 'BlogPosting', 'NewsArticle' ), true ) ) {
						$this->put_setting( 'schema', 'article_type', $type );
					}
				}
			}
		}

		// Breadcrumbs.
		if ( ! empty( $titles['breadcrumbs-enable'] ) ) {
			$this->enable_module( 'breadcrumbs' );
		}
		$crumbs = array(
			'breadcrumbs-sep'           => 'separator',
			'breadcrumbs-home'          => 'home_label',
			'breadcrumbs-prefix'        => 'prefix',
			'breadcrumbs-searchprefix'  => 'search_format',
			'breadcrumbs-404crumb'      => 'label_404',
			'breadcrumbs-archiveprefix' => 'archive_format',
		);
		foreach ( $crumbs as $from => $to ) {
			if ( ! isset( $titles[ $from ] ) ) {
				continue;
			}
			$value = html_entity_decode( sanitize_text_field( (string) $titles[ $from ] ), ENT_QUOTES, 'UTF-8' );
			if ( in_array( $to, array( 'search_format', 'archive_format' ), true ) ) {
				$value = '' === $value ? '%s' : trim( $value ) . ' %s';
			}
			$this->put_setting( 'breadcrumbs', $to, $value );
		}
		if ( isset( $titles['breadcrumbs-display-blog-page'] ) ) {
			$this->put_setting( 'breadcrumbs', 'show_blog', ! empty( $titles['breadcrumbs-display-blog-page'] ) );
		}

		// Sitemaps.
		if ( isset( $main['enable_xml_sitemap'] ) && $main['enable_xml_sitemap'] ) {
			$this->enable_module( 'sitemap' );
		}

		$written = $this->flush_settings();
		$this->log->count( 'settings', 'imported', $written );
		return array(
			'last'      => 0,
			'processed' => $written,
			'done'      => true,
		);
	}

	/**
	 * Robots from Yoast's per-object flags.
	 *
	 * @param string $noindex  '1'/'noindex' = noindex, '2'/'index' = index, else default.
	 * @param string $nofollow '1' = nofollow.
	 * @param string $adv      Comma separated advanced directives.
	 * @return string[]
	 */
	public static function robots( string $noindex, string $nofollow, string $adv ): array {
		$out = array();
		if ( in_array( $noindex, array( '1', 'noindex' ), true ) ) {
			$out[] = 'noindex';
		} elseif ( in_array( $noindex, array( '2', 'index' ), true ) ) {
			$out[] = 'index';
		}
		if ( '1' === $nofollow ) {
			$out[] = 'nofollow';
		}
		if ( '' !== $adv && 'none' !== $adv && '-' !== $adv ) {
			$out = array_merge( $out, Converter::robots( $adv ) );
		}
		return Converter::robots( $out );
	}

	/**
	 * Import posts.
	 *
	 * @param int $after Last id.
	 * @param int $limit Limit.
	 * @return array{last: int, processed: int, done: bool}
	 */
	private function import_posts( int $after, int $limit ): array {
		$ids = $this->meta_object_ids( 'post', self::LIKE, $after, $limit );
		foreach ( $ids as $id ) {
			$meta = $this->raw_meta( 'post', $id, self::LIKE );
			$get  = static fn( string $key ) => $meta[ '_yoast_wpseo_' . $key ] ?? '';
			$n    = 0;
			$n   += (int) $this->put_meta( 'post', $id, 'title', $this->tpl( $get( 'title' ) ) );
			$n   += (int) $this->put_meta( 'post', $id, 'description', $this->tpl( $get( 'metadesc' ) ) );
			$keys = array_filter( array( trim( (string) $get( 'focuskw' ) ) ) );
			$more = json_decode( (string) $get( 'focuskeywords' ), true );
			foreach ( is_array( $more ) ? $more : array() as $row ) {
				if ( is_array( $row ) && '' !== trim( (string) ( $row['keyword'] ?? '' ) ) ) {
					$keys[] = trim( (string) $row['keyword'] );
				}
			}
			$n += (int) $this->put_meta( 'post', $id, 'focus_keyword', implode( ',', array_unique( $keys ) ) );
			$n += (int) $this->put_meta( 'post', $id, 'robots', self::robots( (string) $get( 'meta-robots-noindex' ), (string) $get( 'meta-robots-nofollow' ), (string) $get( 'meta-robots-adv' ) ) );
			$n += (int) $this->put_meta( 'post', $id, 'canonical', esc_url_raw( (string) $get( 'canonical' ) ) );
			$n += (int) $this->put_meta( 'post', $id, 'og_title', $this->tpl( $get( 'opengraph-title' ) ) );
			$n += (int) $this->put_meta( 'post', $id, 'og_description', $this->tpl( $get( 'opengraph-description' ) ) );
			$n += (int) $this->put_meta( 'post', $id, 'og_image', esc_url_raw( (string) $get( 'opengraph-image' ) ) );
			$n += (int) $this->put_meta( 'post', $id, 'og_image_id', (int) $get( 'opengraph-image-id' ) );
			$n += (int) $this->put_meta( 'post', $id, 'twitter_title', $this->tpl( $get( 'twitter-title' ) ) );
			$n += (int) $this->put_meta( 'post', $id, 'twitter_description', $this->tpl( $get( 'twitter-description' ) ) );
			$n += (int) $this->put_meta( 'post', $id, 'twitter_image', esc_url_raw( (string) $get( 'twitter-image' ) ) );
			$n += (int) $this->put_meta( 'post', $id, 'breadcrumb_title', sanitize_text_field( (string) $get( 'bctitle' ) ) );
			if ( '1' === (string) $get( 'is_cornerstone' ) ) {
				$n += (int) $this->put_meta( 'post', $id, 'pillar', true );
			}
			foreach ( $meta as $key => $value ) {
				if ( preg_match( '/^_yoast_wpseo_primary_(.+)$/', $key, $m ) && (int) $value > 0 ) {
					$n += (int) $this->put_meta( 'post', $id, 'primary_' . sanitize_key( $m[1] ), (int) $value );
				}
			}
			if ( is_numeric( $get( 'linkdex' ) ) ) {
				$n += (int) $this->put_meta( 'post', $id, 'score', max( 0, min( 100, (int) $get( 'linkdex' ) ) ) );
			}
			$this->log->count( 'posts', $n ? 'imported' : 'skipped' );
			$this->log->count( 'posts', 'fields', $n );
		}
		return array(
			'last'      => $ids ? (int) end( $ids ) : $after,
			'processed' => count( $ids ),
			'done'      => count( $ids ) < $limit,
		);
	}

	/**
	 * Import terms (from the wpseo_taxonomy_meta option).
	 *
	 * @param int $after Last term id.
	 * @param int $limit Limit.
	 * @return array{last: int, processed: int, done: bool}
	 */
	private function import_terms( int $after, int $limit ): array {
		$all   = $this->term_meta();
		$ids   = array_filter( $this->term_ids(), static fn( $id ) => $id > $after, ARRAY_FILTER_USE_KEY );
		$batch = array_slice( $ids, 0, $limit, true );
		foreach ( $batch as $term_id => $taxonomy ) {
			$data = (array) ( $all[ $taxonomy ][ $term_id ] ?? array() );
			if ( ! get_term( $term_id, $taxonomy ) ) {
				$this->log->count( 'terms', 'skipped' );
				continue;
			}
			$get    = static fn( string $key ) => (string) ( $data[ 'wpseo_' . $key ] ?? '' );
			$n      = 0;
			$n     += (int) $this->put_meta( 'term', $term_id, 'title', $this->tpl( $get( 'title' ) ) );
			$n     += (int) $this->put_meta( 'term', $term_id, 'description', $this->tpl( $get( 'desc' ) ) );
			$n     += (int) $this->put_meta( 'term', $term_id, 'focus_keyword', sanitize_text_field( $get( 'focuskw' ) ) );
			$robots = in_array( $get( 'noindex' ), array( 'noindex', 'index' ), true ) ? array( $get( 'noindex' ) ) : array();
			$n     += (int) $this->put_meta( 'term', $term_id, 'robots', $robots );
			$n     += (int) $this->put_meta( 'term', $term_id, 'canonical', esc_url_raw( $get( 'canonical' ) ) );
			$n     += (int) $this->put_meta( 'term', $term_id, 'og_title', $this->tpl( $get( 'opengraph-title' ) ) );
			$n     += (int) $this->put_meta( 'term', $term_id, 'og_description', $this->tpl( $get( 'opengraph-description' ) ) );
			$n     += (int) $this->put_meta( 'term', $term_id, 'og_image', esc_url_raw( $get( 'opengraph-image' ) ) );
			$this->log->count( 'terms', $n ? 'imported' : 'skipped' );
			$this->log->count( 'terms', 'fields', $n );
		}
		$last = $batch ? (int) array_key_last( $batch ) : $after;
		return array(
			'last'      => $last,
			'processed' => count( $batch ),
			'done'      => count( $batch ) < $limit || count( $ids ) <= $limit,
		);
	}

	/**
	 * Import users.
	 *
	 * @param int $after Last id.
	 * @param int $limit Limit.
	 * @return array{last: int, processed: int, done: bool}
	 */
	private function import_users( int $after, int $limit ): array {
		$ids = $this->user_ids( $after, $limit );
		foreach ( $ids as $id ) {
			$n  = (int) $this->put_meta( 'user', $id, 'title', $this->tpl( get_user_meta( $id, 'wpseo_title', true ) ) );
			$n += (int) $this->put_meta( 'user', $id, 'description', $this->tpl( get_user_meta( $id, 'wpseo_metadesc', true ) ) );
			if ( 'on' === (string) get_user_meta( $id, 'wpseo_noindex_author', true ) ) {
				$n += (int) $this->put_meta( 'user', $id, 'robots', array( 'noindex' ) );
			}
			foreach ( array( 'facebook', 'instagram', 'linkedin', 'pinterest', 'youtube', 'tiktok' ) as $network ) {
				$n += (int) $this->put_meta( 'user', $id, 'social_' . $network, esc_url_raw( (string) get_user_meta( $id, $network, true ) ) );
			}
			$n += (int) $this->put_meta( 'user', $id, 'social_twitter', ltrim( sanitize_text_field( (string) get_user_meta( $id, 'twitter', true ) ), '@' ) );
			$this->log->count( 'users', $n ? 'imported' : 'skipped' );
		}
		return array(
			'last'      => $ids ? (int) end( $ids ) : $after,
			'processed' => count( $ids ),
			'done'      => count( $ids ) < $limit,
		);
	}

	/**
	 * Import Yoast Premium redirects (all in one option, so one batch).
	 *
	 * @param int $after Index offset.
	 * @param int $limit Limit.
	 * @return array{last: int, processed: int, done: bool}
	 */
	private function import_redirections( int $after, int $limit ): array {
		$repo = '\\MGSEO\\Modules\\Redirections\\Repository';
		$all  = $this->redirects();
		$rows = array_slice( $all, $after, $limit );
		foreach ( $rows as $row ) {
			$regex   = 'regex' === (string) ( $row['format'] ?? 'plain' );
			$pattern = (string) ( $row['origin'] ?? '' );
			$type    = (int) ( $row['type'] ?? 301 );
			if ( '' === $pattern || ! class_exists( $repo ) ) {
				$this->log->count( 'redirections', 'skipped' );
				continue;
			}
			$comparison = $regex ? 'regex' : 'exact';
			if ( $repo::find_by_source( $pattern, $comparison ) && ! $this->overwrite ) {
				$this->log->count( 'redirections', 'duplicates' );
				continue;
			}
			if ( $this->dry_run ) {
				$this->log->change( sprintf( 'redirection %s → %s (%d)', $pattern, (string) ( $row['url'] ?? '' ), $type ) );
				$this->log->count( 'redirections', 'imported' );
				continue;
			}
			$result = $repo::create(
				array(
					'sources'  => array(
						array(
							'pattern'     => $pattern,
							'comparison'  => $comparison,
							'ignore_case' => false,
						),
					),
					'url_to'   => (string) ( $row['url'] ?? '' ),
					'type'     => in_array( $type, array( 301, 302, 307, 410, 451 ), true ) ? $type : 301,
					'status'   => 'active',
					'category' => 'Yoast',
				)
			);
			$this->log->count( 'redirections', is_wp_error( $result ) ? 'errors' : 'imported' );
		}
		return array(
			'last'      => $after + count( $rows ),
			'processed' => count( $rows ),
			'done'      => $after + count( $rows ) >= count( $all ),
		);
	}

	/**
	 * Delete Yoast leftovers.
	 *
	 * @return array<string, int>
	 */
	public function cleanup(): array {
		global $wpdb;
		$out             = array(
			'postmeta' => $this->delete_meta_like( 'post', self::LIKE ),
		);
		$out['usermeta'] = (int) $wpdb->query( $wpdb->prepare( "DELETE FROM %i WHERE meta_key IN ('wpseo_title','wpseo_metadesc','wpseo_noindex_author','wpseo_content_analysis_disable','wpseo_keyword_analysis_disable','wpseo_inclusive_language_analysis_disable','_yoast_wpseo_profile_updated') OR meta_key LIKE %s", $wpdb->usermeta, $wpdb->esc_like( $wpdb->prefix . 'yoast' ) . '%' ) );
		wp_cache_flush_group( 'user_meta' );
		$options        = $wpdb->get_col( $wpdb->prepare( 'SELECT option_name FROM %i WHERE option_name LIKE %s OR option_name LIKE %s OR option_name = %s', $wpdb->options, 'wpseo%', 'yoast\_%', 'wpseo-premium-redirects-base' ) );
		$out['options'] = 0;
		foreach ( (array) $options as $name ) {
			$out['options'] += (int) delete_option( $name );
		}
		$out['tables'] = 0;
		foreach ( array( 'yoast_indexable', 'yoast_indexable_hierarchy', 'yoast_migrations', 'yoast_primary_term', 'yoast_seo_links', 'yoast_seo_meta' ) as $table ) {
			if ( $this->table_exists( $wpdb->prefix . $table ) ) {
				$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange -- removing the uninstalled plugin's tables on explicit request.
				++$out['tables'];
			}
		}
		return $out;
	}
}
