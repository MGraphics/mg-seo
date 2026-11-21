<?php
/**
 * Rank Math leftover data importer.
 *
 * @package MGSEO
 */

namespace MGSEO\Modules\Import;

use MGSEO\Modules\Schema\Types;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- reads and removes Rank Math's own tables and rows.

/**
 * Imports Rank Math (free) data purely from the database: `rank_math_*` post/term/user meta,
 * `rank-math-options-*` options and the `{prefix}rank_math_redirections` / `_404_logs` tables.
 * No Rank Math code is loaded or called; the data format is documented in rank-math-data-map.md.
 */
final class RankMath extends Source {

	/**
	 * Meta key prefix as a LIKE pattern.
	 */
	private const LIKE = 'rank\_math\_%';

	/**
	 * Id.
	 */
	public function id(): string {
		return 'rankmath';
	}

	/**
	 * Label.
	 */
	public function label(): string {
		return 'Rank Math';
	}

	/**
	 * Plugin file.
	 */
	public function plugin_file(): string {
		return 'seo-by-rank-math/rank-math.php';
	}

	/**
	 * Scopes.
	 */
	public function scopes(): array {
		return self::SCOPES;
	}

	/**
	 * Leftover data counts.
	 */
	public function detect(): array {
		global $wpdb;
		$settings = 0;
		foreach ( array( 'general', 'titles', 'sitemap' ) as $name ) {
			if ( is_array( get_option( 'rank-math-options-' . $name ) ) ) {
				++$settings;
			}
		}
		return array(
			'settings'     => $settings,
			'posts'        => $this->post_count(),
			'terms'        => $this->meta_object_count( 'term', self::LIKE ),
			'users'        => $this->meta_object_count( 'user', self::LIKE ),
			'redirections' => $this->table_exists( $wpdb->prefix . 'rank_math_redirections' ) ? (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $wpdb->prefix . 'rank_math_redirections' ) ) : 0,
			'schema'       => $this->meta_object_count( 'post', 'rank\_math\_schema\_%' ),
			'log404'       => $this->table_exists( $wpdb->prefix . 'rank_math_404_logs' ) ? (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $wpdb->prefix . 'rank_math_404_logs' ) ) : 0,
		);
	}

	/**
	 * Posts with SEO meta (excluding pure bookkeeping keys).
	 */
	private function post_count(): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(DISTINCT post_id) FROM %i WHERE meta_key IN (' . implode( ',', array_fill( 0, count( self::post_keys() ), '%s' ) ) . ')', array_merge( array( $wpdb->postmeta ), self::post_keys() ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- placeholders generated.
	}

	/**
	 * Rank Math post meta keys holding SEO data.
	 *
	 * @return string[]
	 */
	private static function post_keys(): array {
		return array( 'rank_math_title', 'rank_math_description', 'rank_math_focus_keyword', 'rank_math_robots', 'rank_math_advanced_robots', 'rank_math_canonical_url', 'rank_math_facebook_title', 'rank_math_facebook_description', 'rank_math_facebook_image', 'rank_math_twitter_title', 'rank_math_twitter_description', 'rank_math_twitter_image', 'rank_math_twitter_card_type', 'rank_math_primary_category', 'rank_math_pillar_content', 'rank_math_breadcrumb_title', 'rank_math_seo_score' );
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
			'posts'        => $this->objects( 'post', $after, $limit ),
			'terms'        => $this->objects( 'term', $after, $limit ),
			'users'        => $this->objects( 'user', $after, $limit ),
			'redirections' => $this->import_redirections( $after, $limit ),
			'schema'       => $this->import_schema( $after, $limit ),
			'log404'       => $this->import_404( $after, $limit ),
			default        => array(
				'last'      => 0,
				'processed' => 0,
				'done'      => true,
			),
		};
	}

	/**
	 * Read a Rank Math options array.
	 *
	 * @param string $name general|titles|sitemap.
	 * @return array<string, mixed>
	 */
	private function options( string $name ): array {
		$value = get_option( 'rank-math-options-' . $name, array() );
		return is_array( $value ) ? $value : array();
	}

	/**
	 * Convert a template.
	 *
	 * @param mixed $value Value.
	 */
	private function tpl( mixed $value ): ?string {
		if ( null === $value || is_array( $value ) ) {
			return null;
		}
		return Converter::rank_math( (string) $value, $this->log );
	}

	/**
	 * Robots without the implicit "index".
	 *
	 * @param mixed $value Value.
	 * @return string[]
	 */
	private static function type_robots( mixed $value ): array {
		return array_values( array_diff( Converter::robots( $value ), array( 'index' ) ) );
	}

	/**
	 * Import settings.
	 *
	 * @return array{last: int, processed: int, done: bool}
	 */
	private function import_settings(): array {
		$titles  = $this->options( 'titles' );
		$general = $this->options( 'general' );
		$sitemap = $this->options( 'sitemap' );
		$modules = get_option( 'rank_math_modules', array() );
		$modules = is_array( $modules ) ? $modules : array();
		$t       = static fn( string $key ) => $titles[ $key ] ?? null;
		$g       = static fn( string $key ) => $general[ $key ] ?? null;
		$bool    = static fn( mixed $v ) => null === $v ? null : Converter::bool( $v );

		if ( ! $titles && ! $general && ! $sitemap ) {
			return array(
				'last'      => 0,
				'processed' => 0,
				'done'      => true,
			);
		}

		// Titles & Meta: general.
		if ( null !== $t( 'title_separator' ) ) {
			$this->put_setting( 'titles', 'separator', html_entity_decode( (string) $t( 'title_separator' ), ENT_QUOTES, 'UTF-8' ) );
		}
		$this->put_setting( 'titles', 'capitalize', $bool( $t( 'capitalize_titles' ) ) );
		if ( null !== $t( 'robots_global' ) ) {
			$this->put_setting( 'titles', 'robots_global', self::type_robots( $t( 'robots_global' ) ) );
		}
		$advanced = Converter::advanced_robots( $t( 'advanced_robots_global' ) );
		if ( isset( $advanced['max-snippet'] ) ) {
			$this->put_setting( 'titles', 'max_snippet', (int) $advanced['max-snippet'] );
		}
		if ( isset( $advanced['max-video-preview'] ) ) {
			$this->put_setting( 'titles', 'max_video_preview', (int) $advanced['max-video-preview'] );
		}
		if ( isset( $advanced['max-image-preview'] ) ) {
			$this->put_setting( 'titles', 'max_image_preview', $advanced['max-image-preview'] );
		}
		$this->put_setting( 'titles', 'noindex_empty_terms', $bool( $t( 'noindex_empty_taxonomies' ) ) );
		if ( null !== $t( 'noindex_paginated_pages' ) || null !== $t( 'noindex_archive_subpages' ) ) {
			$this->put_setting( 'titles', 'noindex_paged', Converter::bool( $t( 'noindex_paginated_pages' ) ) || Converter::bool( $t( 'noindex_archive_subpages' ) ) );
		}
		$this->put_setting( 'titles', 'noindex_password', $bool( $t( 'noindex_password_protected' ) ) );
		$this->put_setting( 'titles', 'attachment_redirect', $bool( $g( 'attachment_redirect_urls' ) ) );

		// Homepage.
		$this->put_setting( 'titles', 'home_title', $this->tpl( $t( 'homepage_title' ) ) );
		$this->put_setting( 'titles', 'home_description', $this->tpl( $t( 'homepage_description' ) ) );
		if ( null !== $t( 'homepage_custom_robots' ) ) {
			$this->put_setting( 'titles', 'home_robots', Converter::bool( $t( 'homepage_custom_robots' ) ) ? self::type_robots( $t( 'homepage_robots' ) ) : array() );
		}
		if ( '' !== (string) $t( 'homepage_facebook_image' ) ) {
			$this->put_setting( 'titles', 'home_og_image', esc_url_raw( (string) $t( 'homepage_facebook_image' ) ) );
		}
		if ( '' !== (string) $t( 'homepage_facebook_title' ) || '' !== (string) $t( 'homepage_facebook_description' ) ) {
			$this->log->warning( __( 'Homepage Open Graph title/description from Rank Math were not imported (MG SEO uses the homepage SEO title and description, or the static front page\'s own social fields).', 'mg-seo' ) );
		}

		// Post types and taxonomies.
		foreach ( array_keys( $titles ) as $key ) {
			if ( preg_match( '/^pt_(.+)_title$/', $key, $m ) ) {
				$pt = $m[1];
				if ( ! post_type_exists( $pt ) ) {
					continue;
				}
				$this->put_setting( 'titles', 'pt_' . $pt . '_title', $this->tpl( $t( $key ) ) );
				$this->put_setting( 'titles', 'pt_' . $pt . '_description', $this->tpl( $t( 'pt_' . $pt . '_description' ) ) );
				$custom = Converter::bool( $t( 'pt_' . $pt . '_custom_robots' ) );
				$this->put_setting( 'titles', 'pt_' . $pt . '_robots', $custom ? self::type_robots( $t( 'pt_' . $pt . '_robots' ) ) : array() );
				$own_advanced = Converter::advanced_robots( $t( 'pt_' . $pt . '_advanced_robots' ) );
				if ( $custom && $own_advanced && $own_advanced !== $advanced ) {
					/* translators: %s: post type name */
					$this->log->warning( sprintf( __( 'Post type "%s" had its own max-snippet/max-image-preview values in Rank Math; MG SEO only has global values (set them per post if needed).', 'mg-seo' ), $pt ) );
				}
				if ( null !== $t( 'pt_' . $pt . '_archive_title' ) ) {
					$this->put_setting( 'titles', 'pt_' . $pt . '_archive_title', $this->tpl( $t( 'pt_' . $pt . '_archive_title' ) ) );
					$this->put_setting( 'titles', 'pt_' . $pt . '_archive_description', $this->tpl( $t( 'pt_' . $pt . '_archive_description' ) ) );
				}
				$snippet = (string) $t( 'pt_' . $pt . '_default_rich_snippet' );
				$type    = self::schema_type( $snippet );
				if ( null !== $type ) {
					$this->put_setting( 'schema', 'pt_' . $pt . '_type', $type );
				}
				if ( 'post' === $pt && in_array( (string) $t( 'pt_post_default_article_type' ), array( 'Article', 'BlogPosting', 'NewsArticle' ), true ) ) {
					$this->put_setting( 'schema', 'article_type', (string) $t( 'pt_post_default_article_type' ) );
				}
				if ( 'page' === $pt && (int) get_option( 'page_for_posts' ) ) {
					// Rank Math titles the posts page with the page template.
					$this->put_setting( 'titles', 'blog_title', $this->tpl( $t( $key ) ) );
				}
				continue;
			}
			if ( preg_match( '/^tax_(.+)_title$/', $key, $m ) ) {
				$tax = $m[1];
				if ( ! taxonomy_exists( $tax ) ) {
					continue;
				}
				$this->put_setting( 'titles', 'tax_' . $tax . '_title', $this->tpl( $t( $key ) ) );
				$this->put_setting( 'titles', 'tax_' . $tax . '_description', $this->tpl( $t( 'tax_' . $tax . '_description' ) ) );
				$this->put_setting( 'titles', 'tax_' . $tax . '_robots', Converter::bool( $t( 'tax_' . $tax . '_custom_robots' ) ) ? self::type_robots( $t( 'tax_' . $tax . '_robots' ) ) : array() );
			}
		}

		// Author, date, search, 404.
		if ( null !== $t( 'disable_author_archives' ) ) {
			$this->put_setting( 'titles', 'author_archive', ! Converter::bool( $t( 'disable_author_archives' ) ) );
		}
		$this->put_setting( 'titles', 'author_title', $this->tpl( $t( 'author_archive_title' ) ) );
		$this->put_setting( 'titles', 'author_description', $this->tpl( $t( 'author_archive_description' ) ) );
		if ( null !== $t( 'author_custom_robots' ) ) {
			$this->put_setting( 'titles', 'author_robots', Converter::bool( $t( 'author_custom_robots' ) ) ? self::type_robots( $t( 'author_robots' ) ) : array() );
		}
		if ( null !== $t( 'disable_date_archives' ) ) {
			$this->put_setting( 'titles', 'date_archive', ! Converter::bool( $t( 'disable_date_archives' ) ) );
		}
		$this->put_setting( 'titles', 'date_title', $this->tpl( $t( 'date_archive_title' ) ) );
		if ( null !== $t( 'date_archive_robots' ) ) {
			$this->put_setting( 'titles', 'date_robots', self::type_robots( $t( 'date_archive_robots' ) ) );
		}
		$this->put_setting( 'titles', 'search_title', $this->tpl( $t( 'search_title' ) ) );
		$this->put_setting( 'titles', '404_title', $this->tpl( $t( '404_title' ) ) );

		// Verification.
		foreach ( array( 'google', 'bing', 'yandex', 'baidu', 'pinterest', 'norton' ) as $engine ) {
			if ( '' !== (string) $g( $engine . '_verify' ) ) {
				$this->put_setting( 'titles', 'verify_' . $engine, sanitize_text_field( (string) $g( $engine . '_verify' ) ) );
			}
		}
		if ( '' !== (string) $g( 'custom_webmaster_tags' ) ) {
			$this->log->warning( __( 'Custom webmaster tags from Rank Math were not imported.', 'mg-seo' ) );
		}

		// RSS.
		foreach ( array(
			'rss_before_content' => 'rss_before',
			'rss_after_content'  => 'rss_after',
		) as $from => $to ) {
			if ( null !== $g( $from ) ) {
				$this->put_setting( 'titles', $to, $this->rss( (string) $g( $from ) ) );
			}
		}

		$this->import_social_settings( $t );
		$this->import_schema_settings( $t, $modules );
		$this->import_breadcrumbs_settings( $g );
		$this->import_sitemap_settings( $sitemap, $modules );
		$this->import_redirection_settings( $g, $modules );

		foreach ( array( 'add_img_alt', 'add_img_title' ) as $key ) {
			if ( Converter::bool( $g( $key ) ) ) {
				$this->log->warning( __( 'Rank Math Image SEO (automatic alt/title) was enabled: configure the MG SEO Image SEO module manually.', 'mg-seo' ) );
			}
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
	 * Map a Rank Math default rich snippet to a MG SEO schema type ('' = none, null = leave).
	 *
	 * @param string $snippet Rank Math value.
	 */
	private static function schema_type( string $snippet ): ?string {
		return match ( strtolower( $snippet ) ) {
			'article'              => 'Article',
			'product'              => 'Product',
			'book'                 => 'Book',
			'course'               => 'Course',
			'event'                => 'Event',
			'jobposting'           => 'JobPosting',
			'recipe'               => 'Recipe',
			'restaurant'           => 'LocalBusiness',
			'service'              => 'Service',
			'software'             => 'SoftwareApplication',
			'video'                => 'VideoObject',
			'person'               => 'Person',
			'off', '', '0', 'false' => '',
			default                => null,
		};
	}

	/**
	 * Convert RSS placeholders.
	 *
	 * @param string $text Text.
	 */
	private function rss( string $text ): string {
		$text = str_replace( array( '%POSTLINK%', '%BLOGLINK%' ), array( '%postlink%', '%sitelink%' ), $text );
		if ( preg_match_all( '/%(AUTHORLINK|BLOGDESCLINK|FEATUREDIMAGE)%/', $text, $m ) ) {
			foreach ( $m[0] as $unknown ) {
				$this->log->unknown_variable( $unknown );
			}
		}
		return $text;
	}

	/**
	 * Social settings.
	 *
	 * @param callable $t Titles option getter.
	 */
	private function import_social_settings( callable $t ): void {
		if ( '' !== (string) $t( 'open_graph_image' ) ) {
			$this->put_setting( 'social', 'default_image', esc_url_raw( (string) $t( 'open_graph_image' ) ) );
		}
		if ( '' !== (string) $t( 'social_url_facebook' ) ) {
			$this->put_setting( 'social', 'facebook_page', esc_url_raw( (string) $t( 'social_url_facebook' ) ) );
		}
		if ( '' !== (string) $t( 'facebook_app_id' ) ) {
			$this->put_setting( 'social', 'facebook_app_id', sanitize_text_field( (string) $t( 'facebook_app_id' ) ) );
		}
		if ( '' !== (string) $t( 'twitter_author_names' ) ) {
			$this->put_setting( 'social', 'twitter_site', ltrim( sanitize_text_field( (string) $t( 'twitter_author_names' ) ), '@' ) );
		}
		$card = (string) $t( 'twitter_card_type' );
		if ( in_array( $card, array( 'summary', 'summary_large_image' ), true ) ) {
			$this->put_setting( 'social', 'twitter_card', $card );
		}
		if ( '' !== (string) $t( 'facebook_author_urls' ) ) {
			$this->put_setting( 'social', 'facebook_author', esc_url_raw( (string) $t( 'facebook_author_urls' ) ) );
		}
		if ( '' !== (string) $t( 'facebook_admin_id' ) ) {
			$this->log->warning( __( 'The Facebook admin id (fb:admins) is not supported by MG SEO and was skipped.', 'mg-seo' ) );
		}
	}

	/**
	 * Knowledge graph, local business and schema defaults.
	 *
	 * @param callable $t       Titles option getter.
	 * @param array    $modules Rank Math active modules.
	 */
	private function import_schema_settings( callable $t, array $modules ): void {
		$is_person = 'person' === (string) $t( 'knowledgegraph_type' );
		if ( null !== $t( 'knowledgegraph_type' ) ) {
			$this->put_setting( 'schema', 'kg_type', $is_person ? 'person' : 'organization' );
		}
		$name = sanitize_text_field( (string) $t( 'knowledgegraph_name' ) );
		$logo = esc_url_raw( (string) $t( 'knowledgegraph_logo' ) );
		if ( $is_person ) {
			$this->put_setting( 'schema', 'person_name', '' !== $name ? $name : null );
			$this->put_setting( 'schema', 'person_image', '' !== $logo ? $logo : null );
		} else {
			$this->put_setting( 'schema', 'org_name', '' !== $name ? $name : null );
			$this->put_setting( 'schema', 'org_logo', '' !== $logo ? $logo : null );
		}
		$text = static fn( string $key ) => '' !== trim( (string) $t( $key ) ) ? sanitize_text_field( (string) $t( $key ) ) : null;
		$this->put_setting( 'schema', 'website_name', $text( 'website_name' ) );
		$this->put_setting( 'schema', 'website_alt_name', $text( 'website_alternate_name' ) );
		$this->put_setting( 'schema', 'org_alt_name', $text( 'website_alternate_name' ) );
		$description = trim( (string) $t( 'organization_description' ) );
		$this->put_setting( 'schema', 'org_description', '' !== $description ? sanitize_textarea_field( $description ) : null );
		$this->put_setting( 'schema', 'org_email', '' !== (string) $t( 'email' ) ? sanitize_email( (string) $t( 'email' ) ) : null );
		$url = esc_url_raw( (string) $t( 'url' ) );
		if ( '' !== $url && untrailingslashit( $url ) !== untrailingslashit( home_url() ) ) {
			$this->put_setting( 'schema', 'org_url', $url );
		}

		$phones   = array();
		$contacts = array();
		foreach ( is_array( $t( 'phone_numbers' ) ) ? $t( 'phone_numbers' ) : array() as $row ) {
			$number = trim( (string) ( $row['number'] ?? '' ) );
			if ( '' === $number ) {
				continue;
			}
			$type       = (string) ( $row['type'] ?? 'customer support' );
			$type       = 'customer support' === $type ? 'customer service' : $type;
			$phones[]   = array(
				'number' => $number,
				'type'   => $type,
			);
			$contacts[] = array(
				'contactType' => $type,
				'telephone'   => $number,
				'email'       => '',
				'areaServed'  => '',
			);
		}
		if ( $phones ) {
			$this->put_setting( 'schema', 'org_phone', $phones[0]['number'] );
			$this->put_setting( 'schema', 'contact_points', $contacts );
		}
		$extra = array();
		foreach ( is_array( $t( 'additional_info' ) ) ? $t( 'additional_info' ) : array() as $row ) {
			if ( '' !== trim( (string) ( $row['value'] ?? '' ) ) ) {
				$extra[ (string) ( $row['type'] ?? '' ) ] = sanitize_text_field( (string) $row['value'] );
			}
		}
		$this->put_setting( 'schema', 'org_legal_name', $extra['legalName'] ?? null );
		$this->put_setting( 'schema', 'org_founding_date', $extra['foundingDate'] ?? null );

		$same_as = Converter::url_list( (string) $t( 'social_additional_profiles' ) );
		if ( $same_as ) {
			$this->put_setting( 'schema', 'same_as', $same_as );
		}

		// Local SEO: Rank Math turns the organization into a LocalBusiness when its Local SEO module is on.
		$address = is_array( $t( 'local_address' ) ) ? $t( 'local_address' ) : array();
		if ( in_array( 'local-seo', $modules, true ) && ! $is_person && ( array_filter( $address ) || $phones ) ) {
			$this->enable_module( 'local' );
			$this->put_setting( 'local', 'type', '' !== (string) $t( 'local_business_type' ) ? sanitize_text_field( (string) $t( 'local_business_type' ) ) : 'LocalBusiness' );
			$this->put_setting( 'local', 'name', '' !== $name ? $name : null );
			$this->put_setting( 'local', 'description', '' !== $description ? sanitize_textarea_field( $description ) : null );
			$this->put_setting( 'local', 'logo', '' !== $logo ? $logo : null );
			$map = array(
				'streetAddress'   => 'street',
				'addressLocality' => 'locality',
				'addressRegion'   => 'region',
				'postalCode'      => 'postal_code',
				'addressCountry'  => 'country',
			);
			foreach ( $map as $from => $to ) {
				if ( '' !== trim( (string) ( $address[ $from ] ?? '' ) ) ) {
					$this->put_setting( 'local', $to, sanitize_text_field( (string) $address[ $from ] ) );
				}
			}
			$geo = array_map( 'trim', explode( ',', (string) $t( 'geo' ) ) );
			if ( 2 === count( $geo ) && is_numeric( $geo[0] ) && is_numeric( $geo[1] ) ) {
				$this->put_setting( 'local', 'lat', $geo[0] );
				$this->put_setting( 'local', 'lng', $geo[1] );
			}
			if ( $phones ) {
				$this->put_setting( 'local', 'phones', $phones );
			}
			$this->put_setting( 'local', 'email', '' !== (string) $t( 'email' ) ? sanitize_email( (string) $t( 'email' ) ) : null );
			$this->put_setting( 'local', 'price_range', $text( 'price_range' ) );
			$this->put_setting( 'local', 'vat_id', $extra['vatID'] ?? null );
			$this->put_setting( 'local', 'tax_id', $extra['taxID'] ?? null );
			$hours = self::opening_hours( is_array( $t( 'opening_hours' ) ) ? $t( 'opening_hours' ) : array() );
			if ( $hours ) {
				$this->put_setting( 'local', 'hours', $hours );
			}
		}
	}

	/**
	 * Convert Rank Math opening hours rows ([{day: Monday, time: 09:00-17:00}]) to MG SEO's day map.
	 *
	 * @param array $rows Rows.
	 * @return array<string, array{mode: string, ranges: string}>
	 */
	public static function opening_hours( array $rows ): array {
		$days = array( 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday' );
		$map  = array_fill_keys(
			$days,
			array(
				'mode'   => 'closed',
				'ranges' => '',
			)
		);
		$any  = false;
		foreach ( $rows as $row ) {
			$day  = strtolower( (string) ( $row['day'] ?? '' ) );
			$time = preg_replace( '/\s+/', '', (string) ( $row['time'] ?? '' ) );
			if ( ! isset( $map[ $day ] ) || '' === $time ) {
				continue;
			}
			$any         = true;
			$ranges      = '' !== $map[ $day ]['ranges'] ? $map[ $day ]['ranges'] . ', ' . $time : $time;
			$map[ $day ] = array(
				'mode'   => '00:00-23:59' === $time || '00:00-24:00' === $time ? '24h' : 'open',
				'ranges' => $ranges,
			);
		}
		return $any ? $map : array();
	}

	/**
	 * Breadcrumb settings.
	 *
	 * @param callable $g General option getter.
	 */
	private function import_breadcrumbs_settings( callable $g ): void {
		if ( null === $g( 'breadcrumbs' ) ) {
			return;
		}
		if ( Converter::bool( $g( 'breadcrumbs' ) ) ) {
			$this->enable_module( 'breadcrumbs' );
		}
		$map = array(
			'breadcrumbs_separator'      => 'separator',
			'breadcrumbs_home_label'     => 'home_label',
			'breadcrumbs_prefix'         => 'prefix',
			'breadcrumbs_archive_format' => 'archive_format',
			'breadcrumbs_search_format'  => 'search_format',
			'breadcrumbs_404_label'      => 'label_404',
		);
		foreach ( $map as $from => $to ) {
			if ( null !== $g( $from ) ) {
				$this->put_setting( 'breadcrumbs', $to, html_entity_decode( sanitize_text_field( (string) $g( $from ) ), ENT_QUOTES, 'UTF-8' ) );
			}
		}
		foreach ( array(
			'breadcrumbs_home'              => 'show_home',
			'breadcrumbs_blog_page'         => 'show_blog',
			'breadcrumbs_remove_post_title' => 'hide_current',
		) as $from => $to ) {
			if ( null !== $g( $from ) ) {
				$this->put_setting( 'breadcrumbs', $to, Converter::bool( $g( $from ) ) );
			}
		}
	}

	/**
	 * Sitemap settings.
	 *
	 * @param array $sitemap Rank Math sitemap options.
	 * @param array $modules Active modules.
	 */
	private function import_sitemap_settings( array $sitemap, array $modules ): void {
		if ( ! $sitemap ) {
			return;
		}
		if ( in_array( 'sitemap', $modules, true ) ) {
			$this->enable_module( 'sitemap' );
		}
		if ( isset( $sitemap['items_per_page'] ) && (int) $sitemap['items_per_page'] > 0 ) {
			$this->put_setting( 'sitemap', 'links_per_sitemap', (int) $sitemap['items_per_page'] );
		}
		foreach ( array(
			'include_images'         => 'include_images',
			'include_featured_image' => 'include_featured',
			'authors_sitemap'        => 'authors',
		) as $from => $to ) {
			if ( isset( $sitemap[ $from ] ) ) {
				$this->put_setting( 'sitemap', $to, Converter::bool( $sitemap[ $from ] ) );
			}
		}
		foreach ( array( 'exclude_posts', 'exclude_terms' ) as $key ) {
			if ( isset( $sitemap[ $key ] ) && '' !== trim( (string) $sitemap[ $key ] ) ) {
				$this->put_setting( 'sitemap', $key, implode( ',', array_filter( array_map( 'absint', explode( ',', (string) $sitemap[ $key ] ) ) ) ) );
			}
		}
		if ( isset( $sitemap['exclude_roles'] ) && is_array( $sitemap['exclude_roles'] ) ) {
			$this->put_setting( 'sitemap', 'author_exclude_roles', array_values( array_map( 'sanitize_key', $sitemap['exclude_roles'] ) ) );
		}
		foreach ( $sitemap as $key => $value ) {
			if ( preg_match( '/^(pt|tax)_(.+)_sitemap$/', (string) $key, $m ) && ( 'pt' === $m[1] ? post_type_exists( $m[2] ) : taxonomy_exists( $m[2] ) ) ) {
				$this->put_setting( 'sitemap', $m[1] . '_' . $m[2] . '_include', Converter::bool( $value ) );
			}
		}
	}

	/**
	 * Redirection and 404 monitor settings.
	 *
	 * @param callable $g       General option getter.
	 * @param array    $modules Active modules.
	 */
	private function import_redirection_settings( callable $g, array $modules ): void {
		if ( in_array( 'redirections', $modules, true ) ) {
			$this->enable_module( 'redirections' );
		}
		$this->put_setting( 'redirections', 'log_404', in_array( '404-monitor', $modules, true ) );
		$code = (int) $g( 'redirections_header_code' );
		if ( in_array( $code, array( 301, 302, 307 ), true ) ) {
			$this->put_setting( 'redirections', 'default_type', (string) $code );
		}
		if ( null !== $g( 'redirections_debug' ) ) {
			$this->put_setting( 'redirections', 'debug', Converter::bool( $g( 'redirections_debug' ) ) );
		}
		$fallback = (string) $g( 'redirections_fallback' );
		if ( '' !== $fallback ) {
			$this->put_setting(
				'redirections',
				'fallback',
				match ( $fallback ) {
				'homepage' => 'home',
				'custom'   => 'custom',
				default    => 'default',
				}
			);
			if ( 'custom' === $fallback ) {
				$this->put_setting( 'redirections', 'fallback_url', esc_url_raw( (string) $g( 'redirections_custom_url' ) ) );
			}
		}
		if ( in_array( (string) $g( '404_monitor_mode' ), array( 'simple', 'advanced' ), true ) ) {
			$this->put_setting( 'redirections', 'log_mode', (string) $g( '404_monitor_mode' ) );
		}
		if ( (int) $g( '404_monitor_limit' ) > 0 ) {
			$this->put_setting( 'redirections', 'log_max_rows', (int) $g( '404_monitor_limit' ) );
		}
		if ( null !== $g( '404_monitor_ignore_query_parameters' ) ) {
			$this->put_setting( 'redirections', 'log_ignore_query', Converter::bool( $g( '404_monitor_ignore_query_parameters' ) ) );
		}
	}

	/**
	 * Import a batch of posts, terms or users.
	 *
	 * @param string $type  post|term|user.
	 * @param int    $after Last id.
	 * @param int    $limit Limit.
	 * @return array{last: int, processed: int, done: bool}
	 */
	private function objects( string $type, int $after, int $limit ): array {
		$scope = $type . 's';
		$ids   = 'user' === $type ? $this->user_ids( $after, $limit ) : $this->meta_object_ids( $type, self::LIKE, $after, $limit );
		foreach ( $ids as $id ) {
			$meta    = $this->raw_meta( $type, $id, self::LIKE );
			$written = match ( $type ) {
				'term'  => $this->import_term( $id, $meta ),
				'user'  => $this->import_user( $id, $meta ),
				default => $this->import_post( $id, $meta ),
			};
			$this->log->count( $scope, $written ? 'imported' : 'skipped' );
			$this->log->count( $scope, 'fields', $written );
		}
		return array(
			'last'      => $ids ? (int) end( $ids ) : $after,
			'processed' => count( $ids ),
			'done'      => count( $ids ) < $limit,
		);
	}

	/**
	 * Users with Rank Math meta or the profile fields Rank Math adds.
	 *
	 * @param int $after Last id.
	 * @param int $limit Limit.
	 * @return int[]
	 */
	private function user_ids( int $after, int $limit ): array {
		global $wpdb;
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT user_id FROM %i WHERE ( meta_key LIKE %s OR ( meta_key IN ('twitter','facebook','additional_profile_urls') AND meta_value <> '' ) ) AND user_id > %d ORDER BY user_id ASC LIMIT %d", $wpdb->usermeta, self::LIKE, $after, $limit ) );
		return array_map( 'intval', (array) $ids );
	}

	/**
	 * Number of objects a scope iterates (for progress bars).
	 *
	 * @param string $scope Scope.
	 */
	public function total( string $scope ): int {
		return match ( $scope ) {
			'posts' => $this->meta_object_count( 'post', self::LIKE ),
			'users' => count( $this->user_ids( 0, PHP_INT_MAX ) ),
			default => (int) ( $this->detect()[ $scope ] ?? 0 ),
		};
	}

	/**
	 * Fields shared by posts and terms.
	 *
	 * @param string $type post|term.
	 * @param int    $id   Id.
	 * @param array  $meta Raw meta.
	 */
	private function import_common( string $type, int $id, array $meta ): int {
		$n   = 0;
		$get = static fn( string $key ) => $meta[ 'rank_math_' . $key ] ?? null;

		$n       += (int) $this->put_meta( $type, $id, 'title', null !== $get( 'title' ) ? $this->tpl( $get( 'title' ) ) : null );
		$n       += (int) $this->put_meta( $type, $id, 'description', null !== $get( 'description' ) ? $this->tpl( $get( 'description' ) ) : null );
		$keywords = implode( ',', array_filter( array_map( 'trim', explode( ',', (string) $get( 'focus_keyword' ) ) ) ) );
		$n       += (int) $this->put_meta( $type, $id, 'focus_keyword', $keywords );
		if ( null !== $get( 'robots' ) ) {
			$robots = Converter::robots( $get( 'robots' ) );
			// A stored Rank Math robots list replaces the type defaults, so an index directive is explicit.
			if ( ! in_array( 'noindex', $robots, true ) && ! in_array( 'index', $robots, true ) ) {
				array_unshift( $robots, 'index' );
			}
			$n += (int) $this->put_meta( $type, $id, 'robots', $robots );
		}
		if ( 'post' === $type && null !== $get( 'advanced_robots' ) ) {
			$advanced = Converter::advanced_robots( $get( 'advanced_robots' ) );
			$defaults = array(
				'max-snippet'       => '-1',
				'max-video-preview' => '-1',
				'max-image-preview' => 'large',
			);
			if ( $advanced && $advanced !== $defaults ) {
				$n += (int) $this->put_meta( $type, $id, 'advanced_robots', $advanced );
			}
		}
		$n += (int) $this->put_meta( $type, $id, 'canonical', esc_url_raw( (string) $get( 'canonical_url' ) ) );
		$n += (int) $this->put_meta( $type, $id, 'og_title', null !== $get( 'facebook_title' ) ? $this->tpl( $get( 'facebook_title' ) ) : null );
		$n += (int) $this->put_meta( $type, $id, 'og_description', null !== $get( 'facebook_description' ) ? $this->tpl( $get( 'facebook_description' ) ) : null );
		$n += (int) $this->put_meta( $type, $id, 'og_image', esc_url_raw( (string) $get( 'facebook_image' ) ) );
		if ( 'post' === $type ) {
			$n += (int) $this->put_meta( $type, $id, 'og_image_id', (int) $get( 'facebook_image_id' ) );
		}
		// Twitter fields only when Rank Math was told not to reuse the Facebook data.
		if ( 'off' === (string) $get( 'twitter_use_facebook' ) ) {
			$n += (int) $this->put_meta( $type, $id, 'twitter_title', null !== $get( 'twitter_title' ) ? $this->tpl( $get( 'twitter_title' ) ) : null );
			$n += (int) $this->put_meta( $type, $id, 'twitter_description', null !== $get( 'twitter_description' ) ? $this->tpl( $get( 'twitter_description' ) ) : null );
			$n += (int) $this->put_meta( $type, $id, 'twitter_image', esc_url_raw( (string) $get( 'twitter_image' ) ) );
		}
		$card = (string) $get( 'twitter_card_type' );
		if ( in_array( $card, array( 'summary', 'summary_large_image' ), true ) ) {
			$n += (int) $this->put_meta( $type, $id, 'twitter_card', $card );
		}
		return $n;
	}

	/**
	 * Import one post.
	 *
	 * @param int   $id   Post id.
	 * @param array $meta Raw meta.
	 */
	private function import_post( int $id, array $meta ): int {
		$n = $this->import_common( 'post', $id, $meta );
		foreach ( $meta as $key => $value ) {
			if ( preg_match( '/^rank_math_primary_(.+)$/', $key, $m ) && (int) $value > 0 ) {
				$n += (int) $this->put_meta( 'post', $id, 'primary_' . sanitize_key( $m[1] ), (int) $value );
			}
		}
		if ( isset( $meta['rank_math_pillar_content'] ) && Converter::bool( $meta['rank_math_pillar_content'] ) ) {
			$n += (int) $this->put_meta( 'post', $id, 'pillar', true );
		}
		$n += (int) $this->put_meta( 'post', $id, 'breadcrumb_title', sanitize_text_field( (string) ( $meta['rank_math_breadcrumb_title'] ?? '' ) ) );
		if ( isset( $meta['rank_math_seo_score'] ) && is_numeric( $meta['rank_math_seo_score'] ) ) {
			$n += (int) $this->put_meta( 'post', $id, 'score', max( 0, min( 100, (int) $meta['rank_math_seo_score'] ) ) );
		}
		return $n;
	}

	/**
	 * Import one term.
	 *
	 * @param int   $id   Term id.
	 * @param array $meta Raw meta.
	 */
	private function import_term( int $id, array $meta ): int {
		if ( ! get_term( $id ) ) {
			return 0;
		}
		return $this->import_common( 'term', $id, $meta );
	}

	/**
	 * Import one user (author archive SEO and social profiles).
	 *
	 * @param int   $id   User id.
	 * @param array $meta Raw rank_math_* meta.
	 */
	private function import_user( int $id, array $meta ): int {
		if ( ! get_userdata( $id ) ) {
			return 0;
		}
		$n  = (int) $this->put_meta( 'user', $id, 'title', isset( $meta['rank_math_title'] ) ? $this->tpl( $meta['rank_math_title'] ) : null );
		$n += (int) $this->put_meta( 'user', $id, 'description', isset( $meta['rank_math_description'] ) ? $this->tpl( $meta['rank_math_description'] ) : null );
		if ( isset( $meta['rank_math_robots'] ) ) {
			$robots = Converter::robots( $meta['rank_math_robots'] );
			$n     += (int) $this->put_meta( 'user', $id, 'robots', in_array( 'noindex', $robots, true ) ? array( 'noindex' ) : array( 'index' ) );
		}
		$n += $this->import_user_profiles( $id );
		return $n;
	}

	/**
	 * Social profile fields Rank Math adds to user profiles (twitter, facebook, additional_profile_urls).
	 *
	 * @param int $id User id.
	 */
	private function import_user_profiles( int $id ): int {
		$n       = 0;
		$twitter = ltrim( sanitize_text_field( (string) get_user_meta( $id, 'twitter', true ) ), '@' );
		$n      += (int) $this->put_meta( 'user', $id, 'social_twitter', $twitter );
		$n      += (int) $this->put_meta( 'user', $id, 'social_facebook', esc_url_raw( (string) get_user_meta( $id, 'facebook', true ) ) );
		foreach ( Converter::url_list( (string) get_user_meta( $id, 'additional_profile_urls', true ) ) as $url ) {
			$key = Converter::profile_key( $url );
			if ( '' !== $key && 'twitter' !== $key ) {
				$n += (int) $this->put_meta( 'user', $id, 'social_' . $key, $url );
			}
		}
		return $n;
	}

	/**
	 * Map of Rank Math ids to MG SEO ids for idempotent re-runs.
	 *
	 * @return array<string, array<int, int>>
	 */
	private static function map(): array {
		$map = get_option( 'mgseo_import_map', array() );
		$map = is_array( $map ) ? $map : array();
		return isset( $map['rankmath'] ) && is_array( $map['rankmath'] ) ? $map['rankmath'] : array();
	}

	/**
	 * Store the id map.
	 *
	 * @param array $rm Map for this source.
	 */
	private static function save_map( array $rm ): void {
		$map             = get_option( 'mgseo_import_map', array() );
		$map             = is_array( $map ) ? $map : array();
		$map['rankmath'] = $rm;
		update_option( 'mgseo_import_map', $map, false );
	}

	/**
	 * Import redirections.
	 *
	 * @param int $after Last id.
	 * @param int $limit Limit.
	 * @return array{last: int, processed: int, done: bool}
	 */
	private function import_redirections( int $after, int $limit ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'rank_math_redirections';
		if ( ! $this->table_exists( $table ) ) {
			return array(
				'last'      => 0,
				'processed' => 0,
				'done'      => true,
			);
		}
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE id > %d ORDER BY id ASC LIMIT %d', $table, $after, $limit ), ARRAY_A );
		$map  = self::map();
		foreach ( (array) $rows as $row ) {
			$rm_id   = (int) $row['id'];
			$sources = array();
			$raw     = maybe_unserialize( (string) $row['sources'] );
			foreach ( is_array( $raw ) ? $raw : array() as $source ) {
				if ( ! is_array( $source ) || '' === (string) ( $source['pattern'] ?? '' ) ) {
					continue;
				}
				$sources[] = array(
					'pattern'     => (string) $source['pattern'],
					'comparison'  => (string) ( $source['comparison'] ?? 'exact' ),
					'ignore_case' => 'case' === (string) ( $source['ignore'] ?? '' ),
				);
			}
			if ( ! $sources ) {
				$this->log->count( 'redirections', 'skipped' );
				continue;
			}
			$type = (int) $row['header_code'];
			$args = array(
				'sources'  => $sources,
				'url_to'   => (string) $row['url_to'],
				'type'     => in_array( $type, array( 301, 302, 307, 410, 451 ), true ) ? $type : 301,
				'status'   => 'active' === (string) $row['status'] ? 'active' : 'inactive',
				'category' => 'Rank Math',
				'hits'     => (int) $row['hits'],
				'created'  => self::date( (string) $row['created'] ),
			);
			$last = self::date( (string) ( $row['last_accessed'] ?? '' ) );
			if ( $last ) {
				$args['last_accessed'] = $last;
			}
			$result = $this->write_redirection( $rm_id, $args, $map );
			$this->log->count( 'redirections', $result );
		}
		if ( ! $this->dry_run ) {
			self::save_map( $map );
		}
		$last_id = $rows ? (int) end( $rows )['id'] : $after;
		return array(
			'last'      => $last_id,
			'processed' => count( (array) $rows ),
			'done'      => count( (array) $rows ) < $limit,
		);
	}

	/**
	 * Valid MySQL date or null.
	 *
	 * @param string $date Date.
	 */
	private static function date( string $date ): ?string {
		return '' === $date || str_starts_with( $date, '0000' ) ? null : $date;
	}

	/**
	 * Create (or update/skip) one redirection.
	 *
	 * @param int   $rm_id Rank Math id.
	 * @param array $args  Repository args.
	 * @param array $map   Id map (by reference).
	 * @return string Counter key.
	 */
	private function write_redirection( int $rm_id, array $args, array &$map ): string {
		$repo = '\\MGSEO\\Modules\\Redirections\\Repository';
		if ( ! class_exists( $repo ) ) {
			$this->log->warning( __( 'The Redirections module is not available; redirections were skipped.', 'mg-seo' ) );
			return 'skipped';
		}
		$existing = isset( $map['redirections'][ $rm_id ] ) ? $repo::get( (int) $map['redirections'][ $rm_id ] ) : null;
		if ( ! $existing ) {
			$first    = $args['sources'][0];
			$existing = $repo::find_by_source( $first['pattern'], $first['comparison'] );
		}
		if ( $existing ) {
			$map['redirections'][ $rm_id ] = (int) $existing['id'];
			if ( ! $this->overwrite ) {
				return 'duplicates';
			}
			if ( $this->dry_run ) {
				$this->log->change( sprintf( 'redirection #%d updated from Rank Math #%d', (int) $existing['id'], $rm_id ) );
				return 'updated';
			}
			unset( $args['created'] );
			$result = $repo::update( (int) $existing['id'], $args );
			if ( is_wp_error( $result ) ) {
				$this->log->warning( sprintf( 'Rank Math redirection #%d: %s', $rm_id, $result->get_error_message() ) );
				return 'errors';
			}
			return 'updated';
		}
		if ( $this->dry_run ) {
			$this->log->change( sprintf( 'redirection %s → %s (%d)', $args['sources'][0]['pattern'], $args['url_to'], $args['type'] ) );
			return 'imported';
		}
		$id = $repo::create( $args );
		if ( is_wp_error( $id ) ) {
			$this->log->warning( sprintf( 'Rank Math redirection #%d: %s', $rm_id, $id->get_error_message() ) );
			return 'errors';
		}
		$map['redirections'][ $rm_id ] = (int) $id;
		return 'imported';
	}

	/**
	 * Import schema entities (rank_math_schema_<Type> post meta) and FAQ blocks.
	 *
	 * @param int $after Last post id.
	 * @param int $limit Limit.
	 * @return array{last: int, processed: int, done: bool}
	 */
	private function import_schema( int $after, int $limit ): array {
		$ids = $this->meta_object_ids( 'post', 'rank\_math\_schema\_%', $after, $limit );
		foreach ( $ids as $id ) {
			$meta     = $this->raw_meta( 'post', $id, 'rank\_math\_schema\_%' );
			$entities = array();
			foreach ( $meta as $raw ) {
				$schema = maybe_unserialize( $raw );
				if ( is_array( $schema ) ) {
					$entity = $this->schema_entity( $schema );
					if ( $entity ) {
						$entities[] = $entity;
					}
				}
			}
			$post = get_post( $id );
			$faq  = $post ? self::faq_entity( (string) $post->post_content ) : null;
			if ( $faq ) {
				$entities[] = $faq;
			}
			$this->log->count( 'schema', $this->store_schema( $id, $entities ) );
		}
		$done = count( $ids ) < $limit;
		if ( $done ) {
			$this->import_faq_blocks();
		}
		return array(
			'last'      => $ids ? (int) end( $ids ) : $after,
			'processed' => count( $ids ),
			'done'      => $done,
		);
	}

	/**
	 * Posts that use the Rank Math FAQ block but have no Rank Math schema meta.
	 */
	private function import_faq_blocks(): void {
		global $wpdb;
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM %i WHERE post_content LIKE %s AND post_status IN ('publish','private','draft','future','pending') LIMIT 1000", $wpdb->posts, '%' . $wpdb->esc_like( '<!-- wp:rank-math/faq-block' ) . '%' ) );
		foreach ( array_map( 'intval', (array) $ids ) as $id ) {
			if ( $this->raw_meta( 'post', $id, 'rank\_math\_schema\_%' ) ) {
				continue;
			}
			$post = get_post( $id );
			$faq  = $post ? self::faq_entity( (string) $post->post_content ) : null;
			if ( $faq ) {
				// Rank Math printed the FAQ next to the post type's default schema; keep that default too.
				$entities = array();
				$store    = '\\MGSEO\\Modules\\Schema\\Entities';
				if ( class_exists( $store ) ) {
					$default = $store::default_entity( $post );
					if ( $default ) {
						$entities[] = $default;
					}
				}
				$entities[] = $faq;
				$this->log->count( 'schema', $this->store_schema( $id, $entities ) );
			}
		}
	}

	/**
	 * Store entities for a post.
	 *
	 * @param int   $id       Post id.
	 * @param array $entities Entities.
	 * @return string Counter key.
	 */
	private function store_schema( int $id, array $entities ): string {
		if ( ! $entities ) {
			return 'skipped';
		}
		if ( ! $this->overwrite && metadata_exists( 'post', $id, '_mgseo_schema' ) ) {
			return 'skipped';
		}
		if ( $this->dry_run ) {
			$this->log->change( sprintf( 'post #%d: schema %s', $id, implode( ', ', wp_list_pluck( $entities, 'type' ) ) ) );
			return 'imported';
		}
		$store = '\\MGSEO\\Modules\\Schema\\Entities';
		if ( class_exists( $store ) ) {
			$store::store( $id, $entities );
		} else {
			update_post_meta( $id, '_mgseo_schema', wp_slash( (string) wp_json_encode( $entities, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ) );
		}
		return 'imported';
	}

	/**
	 * Convert one stored Rank Math schema array to a MG SEO entity.
	 *
	 * @param array $schema Rank Math schema (with `metadata`).
	 * @return array|null
	 */
	public function schema_entity( array $schema ): ?array {
		$meta = is_array( $schema['metadata'] ?? null ) ? $schema['metadata'] : array();
		unset( $schema['metadata'] );
		$type = $schema['@type'] ?? '';
		if ( '' === $type || array() === $type ) {
			return null;
		}
		$id     = 'rm-' . sanitize_key( (string) ( $meta['shortcode'] ?? md5( (string) wp_json_encode( $schema ) ) ) );
		$custom = 'custom' === (string) ( $meta['type'] ?? '' );
		$data   = $this->schema_values( $schema );
		if ( ! $custom ) {
			$data = self::strip_schema_defaults( $data );
		}
		$base = class_exists( Types::class ) ? Types::base_type( $type ) : ( is_array( $type ) ? (string) reset( $type ) : (string) $type );
		if ( ! $custom && class_exists( Types::class ) && ! Types::get( $base ) ) {
			// Types MG SEO has no form for (Music, Dataset …) are kept as custom JSON-LD.
			$custom = true;
		}
		return array(
			'id'     => $id,
			'type'   => $custom ? ( is_array( $type ) ? (string) reset( $type ) : (string) $type ) : $base,
			'custom' => $custom,
			'data'   => $data,
		);
	}

	/**
	 * Convert template variables in all string values.
	 *
	 * @param array $data Data.
	 * @return array
	 */
	private function schema_values( array $data ): array {
		foreach ( $data as $key => $value ) {
			if ( is_array( $value ) ) {
				$data[ $key ] = $this->schema_values( $value );
			} elseif ( is_string( $value ) ) {
				$data[ $key ] = Converter::rank_math( $value, $this->log, Converter::CONTEXT_SCHEMA );
			}
		}
		return $data;
	}

	/**
	 * Drop Rank Math's generated placeholders that MG SEO fills on its own (author, dates, featured image)
	 * and empty nested objects.
	 *
	 * @param array $data Data.
	 * @return array
	 */
	private static function strip_schema_defaults( array $data ): array {
		$is_default_image = static fn( $v ) => is_array( $v ) && isset( $v['url'] ) && '%post_thumbnail%' === $v['url'];
		if ( isset( $data['image'] ) && $is_default_image( $data['image'] ) ) {
			unset( $data['image'] );
		}
		if ( isset( $data['author'] ) && is_array( $data['author'] ) && '%name%' === ( $data['author']['name'] ?? '' ) && 'Person' === ( $data['author']['@type'] ?? '' ) ) {
			unset( $data['author'] );
		}
		foreach ( array( 'datePublished', 'dateModified' ) as $key ) {
			if ( isset( $data[ $key ] ) && is_string( $data[ $key ] ) && preg_match( '/^%(date|modified)\(c\)%$/', $data[ $key ] ) ) {
				unset( $data[ $key ] );
			}
		}
		return self::prune( $data );
	}

	/**
	 * Remove empty values and objects that only carry an @type.
	 *
	 * @param array $data Data.
	 * @return array
	 */
	private static function prune( array $data ): array {
		foreach ( $data as $key => $value ) {
			if ( is_array( $value ) ) {
				$value = self::prune( $value );
				$keys  = array_diff( array_keys( $value ), array( '@type' ) );
				if ( ! $keys ) {
					unset( $data[ $key ] );
					continue;
				}
				$data[ $key ] = $value;
			} elseif ( '' === $value || null === $value ) {
				unset( $data[ $key ] );
			}
		}
		return array_is_list( $data ) ? array_values( $data ) : $data;
	}

	/**
	 * FAQPage entity from Rank Math FAQ blocks in content.
	 *
	 * @param string $content Post content.
	 * @return array|null
	 */
	public static function faq_entity( string $content ): ?array {
		if ( ! str_contains( $content, 'wp:rank-math/faq-block' ) ) {
			return null;
		}
		$questions = array();
		foreach ( parse_blocks( $content ) as $block ) {
			self::collect_faq( $block, $questions );
		}
		if ( ! $questions ) {
			return null;
		}
		return array(
			'id'     => 'rm-faq',
			'type'   => 'FAQPage',
			'custom' => false,
			'data'   => array( 'mainEntity' => $questions ),
		);
	}

	/**
	 * Collect questions from a block tree.
	 *
	 * @param array $block     Parsed block.
	 * @param array $questions Questions (by reference).
	 */
	private static function collect_faq( array $block, array &$questions ): void {
		if ( 'rank-math/faq-block' === ( $block['blockName'] ?? '' ) ) {
			foreach ( (array) ( $block['attrs']['questions'] ?? array() ) as $q ) {
				if ( ! is_array( $q ) || ( isset( $q['visible'] ) && ! $q['visible'] ) ) {
					continue;
				}
				$name = wp_strip_all_tags( (string) ( $q['title'] ?? '' ) );
				$text = wp_kses_post( (string) ( $q['content'] ?? '' ) );
				if ( '' === $name || '' === $text ) {
					continue;
				}
				$questions[] = array(
					'@type'          => 'Question',
					'name'           => $name,
					'acceptedAnswer' => array(
						'@type' => 'Answer',
						'text'  => $text,
					),
				);
			}
		}
		foreach ( (array) ( $block['innerBlocks'] ?? array() ) as $inner ) {
			self::collect_faq( $inner, $questions );
		}
	}

	/**
	 * Import the 404 log.
	 *
	 * @param int $after Last id.
	 * @param int $limit Limit.
	 * @return array{last: int, processed: int, done: bool}
	 */
	private function import_404( int $after, int $limit ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'rank_math_404_logs';
		$log   = '\\MGSEO\\Modules\\Redirections\\Log404';
		if ( ! $this->table_exists( $table ) || ! class_exists( $log ) || ! $this->table_exists( $log::table() ) ) {
			return array(
				'last'      => 0,
				'processed' => 0,
				'done'      => true,
			);
		}
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE id > %d ORDER BY id ASC LIMIT %d', $table, $after, $limit ), ARRAY_A );
		foreach ( (array) $rows as $row ) {
			$uri = '/' . ltrim( (string) $row['uri'], '/' );
			if ( $this->dry_run ) {
				$this->log->change( '404 ' . $uri );
				$this->log->count( 'log404', 'imported' );
				continue;
			}
			$date = self::date( (string) $row['accessed'] ) ?? current_time( 'mysql', true );
			$wpdb->query(
				$wpdb->prepare(
					'INSERT INTO %i (hash, uri, referer, user_agent, ip, hits, first_seen, last_seen) VALUES (%s, %s, %s, %s, %s, %d, %s, %s)
					ON DUPLICATE KEY UPDATE hits = GREATEST(hits, VALUES(hits)), last_seen = GREATEST(last_seen, VALUES(last_seen))',
					$log::table(),
					md5( 's|' . $uri ),
					$uri,
					esc_url_raw( (string) $row['referer'] ),
					mb_substr( sanitize_text_field( (string) $row['user_agent'] ), 0, 255 ),
					'',
					max( 1, (int) $row['times_accessed'] ),
					$date,
					$date
				)
			);
			$this->log->count( 'log404', 'imported' );
		}
		return array(
			'last'      => $rows ? (int) end( $rows )['id'] : $after,
			'processed' => count( (array) $rows ),
			'done'      => count( (array) $rows ) < $limit,
		);
	}

	/**
	 * Delete Rank Math leftovers.
	 *
	 * @return array<string, int>
	 */
	public function cleanup(): array {
		global $wpdb;
		$out            = array(
			'postmeta' => $this->delete_meta_like( 'post', self::LIKE ),
			'termmeta' => $this->delete_meta_like( 'term', self::LIKE ),
			'usermeta' => $this->delete_meta_like( 'user', self::LIKE ),
		);
		$options        = $wpdb->get_col( $wpdb->prepare( 'SELECT option_name FROM %i WHERE option_name LIKE %s OR option_name LIKE %s', $wpdb->options, 'rank-math-%', 'rank\_math\_%' ) );
		$out['options'] = 0;
		foreach ( (array) $options as $name ) {
			$out['options'] += (int) delete_option( $name );
		}
		$out['tables'] = 0;
		foreach ( array( 'rank_math_redirections', 'rank_math_redirections_cache', 'rank_math_404_logs', 'rank_math_internal_links', 'rank_math_internal_meta', 'rank_math_analytics_gsc', 'rank_math_analytics_objects', 'rank_math_analytics_inspections', 'rank_math_analytics_ga', 'rank_math_analytics_adsense', 'rank_math_analytics_keyword_manager' ) as $table ) {
			if ( $this->table_exists( $wpdb->prefix . $table ) ) {
				$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange -- removing the uninstalled plugin's tables on explicit request.
				++$out['tables'];
			}
		}
		foreach ( array( 'rank_math/content-ai/update_prompts', 'rank_math/redirection/clean_trashed', 'rank_math/links/internal_links', 'rank_math/analytics/data_fetch' ) as $hook ) {
			wp_clear_scheduled_hook( $hook );
		}
		$map = get_option( 'mgseo_import_map', array() );
		if ( is_array( $map ) ) {
			unset( $map['rankmath'] );
			update_option( 'mgseo_import_map', $map, false );
		}
		return $out;
	}
}
