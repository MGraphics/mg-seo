<?php
/**
 * Schema graph tests.
 *
 * @package MGSEO
 */

namespace MGSEO\Tests;

use MGSEO\Meta;
use MGSEO\Modules\Schema\Graph;

/**
 * Schema module tests.
 *
 * @covers \MGSEO\Modules\Schema\Graph
 * @covers \MGSEO\Modules\Schema\Module
 */
class SchemaGraphTest extends TestCase {

	/**
	 * Graph nodes of a URL.
	 *
	 * @param string $url URL.
	 * @return array{0: array<string, array>, 1: string, 2: array} nodes by @id, raw head, all scripts
	 */
	private function graph_for( string $url ): array {
		$head    = $this->head_for( $url );
		$scripts = $this->json_ld( $head );
		$this->assertNotEmpty( $scripts, 'No JSON-LD printed' );
		foreach ( $scripts as $script ) {
			$this->assertIsArray( $script, 'JSON-LD is not valid JSON' );
		}
		$graph = $scripts[0];
		$this->assertSame( 'https://schema.org', $graph['@context'] );
		$nodes = array();
		foreach ( $graph['@graph'] as $node ) {
			$nodes[ $node['@id'] ?? spl_object_hash( (object) $node ) ] = $node;
		}
		return array( $nodes, $head, $scripts );
	}

	/**
	 * Graph id helper.
	 */
	public function test_graph_id_helper(): void {
		$this->assertSame( home_url( '/' ) . '#organization', Graph::id( 'organization' ) );
		$this->assertSame( 'https://example.org/a/#webpage', Graph::id( 'webpage', 'https://example.org/a/#old' ) );
	}

	/**
	 * Home graph.
	 */
	public function test_home_graph(): void {
		$this->set_option( 'schema', 'org_name', 'MGraphics Studio' );
		$this->set_option( 'schema', 'same_as', array( 'https://www.facebook.com/example', 'https://www.linkedin.com/company/example' ) );
		$this->set_option( 'schema', 'org_logo', 'https://cdn.example.com/logo.png' );
		$this->set_option(
			'schema',
			'contact_points',
			array(
				array(
					'contactType' => 'customer service',
					'telephone'   => '+48 500 600 700',
				),
			)
		);
		list( $nodes, $head, $scripts ) = $this->graph_for( home_url( '/' ) );
		$this->assertCount( 1, $scripts );
		$org = $nodes[ home_url( '/#organization' ) ];
		$this->assertSame( 'Organization', $org['@type'] );
		$this->assertSame( 'MGraphics Studio', $org['name'] );
		$this->assertSame( 'https://cdn.example.com/logo.png', $org['logo']['url'] );
		$this->assertSame( home_url( '/#logo' ), $org['logo']['@id'] );
		$this->assertContains( 'https://www.linkedin.com/company/example', $org['sameAs'] );
		$this->assertSame( 'ContactPoint', $org['contactPoint'][0]['@type'] );
		$this->assertArrayNotHasKey( 'email', $org['contactPoint'][0], 'Empty properties must be removed' );

		$site = $nodes[ home_url( '/#website' ) ];
		$this->assertSame( 'WebSite', $site['@type'] );
		$this->assertSame( array( '@id' => home_url( '/#organization' ) ), $site['publisher'] );
		$this->assertSame( 'SearchAction', $site['potentialAction']['@type'] );
		$this->assertStringContainsString( '{search_term_string}', $site['potentialAction']['target']['urlTemplate'] );

		$page = $nodes[ home_url( '/#webpage' ) ];
		$this->assertSame( 'WebPage', $page['@type'] );
		$this->assertSame( array( '@id' => home_url( '/#organization' ) ), $page['about'] );
		$this->assertSame( array( '@id' => home_url( '/#website' ) ), $page['isPartOf'] );

		$this->set_option( 'schema', 'search_action', false );
		list( $nodes ) = $this->graph_for( home_url( '/' ) );
		$this->assertArrayNotHasKey( 'potentialAction', $nodes[ home_url( '/#website' ) ] );
	}

	/**
	 * Post graph with default article.
	 */
	public function test_post_graph_with_default_article(): void {
		$user = self::factory()->user->create(
			array(
				'display_name' => 'Jan Kowalski',
				'role'         => 'author',
			)
		);
		Meta::set_user( $user, 'social_twitter', '@jank' );
		$cat                            = self::factory()->category->create( array( 'name' => 'Poradniki' ) );
		$post                           = self::factory()->post->create_and_get(
			array(
				'post_title'    => 'Jak wybrać hosting',
				'post_content'  => 'Jeden dwa trzy cztery pięć.',
				'post_author'   => $user,
				'post_category' => array( $cat ),
				'tags_input'    => array( 'hosting', 'serwery' ),
				'post_date'     => '2026-03-10 12:00:00',
			)
		);
		$url                            = get_permalink( $post );
		list( $nodes, $head, $scripts ) = $this->graph_for( $url );
		$this->assertCount( 1, $scripts, 'Breadcrumbs must not print a second script when Schema is active' );

		$article = $nodes[ $url . '#article' ];
		$this->assertSame( 'BlogPosting', $article['@type'] );
		$this->assertSame( 'Jak wybrać hosting', $article['headline'] );
		$this->assertMatchesRegularExpression( '/^2026-03-10T12:00:00[+-]\d{2}:\d{2}$/', $article['datePublished'] );
		$this->assertSame( array( '@id' => home_url( '/#author-' . $user ) ), $article['author'] );
		$this->assertSame( array( '@id' => home_url( '/#organization' ) ), $article['publisher'] );
		$this->assertSame( array( '@id' => $url . '#webpage' ), $article['mainEntityOfPage'] );
		$this->assertSame( 'Poradniki', $article['articleSection'] );
		$this->assertSame( 'hosting, serwery', $article['keywords'] );
		$this->assertSame( 5, $article['wordCount'] );
		$this->assertSame( get_bloginfo( 'language' ), $article['inLanguage'] );

		$author = $nodes[ home_url( '/#author-' . $user ) ];
		$this->assertSame( 'Person', $author['@type'] );
		$this->assertSame( 'Jan Kowalski', $author['name'] );
		$this->assertContains( 'https://x.com/jank', $author['sameAs'] );

		$page = $nodes[ $url . '#webpage' ];
		$this->assertSame( 'WebPage', $page['@type'] );
		$this->assertSame( array( '@id' => $url . '#breadcrumb' ), $page['breadcrumb'] );
		$this->assertSame( 'BreadcrumbList', $nodes[ $url . '#breadcrumb' ]['@type'] );

		$this->set_option( 'schema', 'article_type', 'NewsArticle' );
		list( $nodes ) = $this->graph_for( $url );
		$this->assertSame( 'NewsArticle', $nodes[ $url . '#article' ]['@type'] );
	}

	/**
	 * Primary image.
	 */
	public function test_primary_image(): void {
		$post = self::factory()->post->create_and_get( array( 'post_title' => 'Z obrazkiem' ) );
		Meta::set_post( $post->ID, 'og_image', 'https://cdn.example.com/share.jpg' );
		$url           = get_permalink( $post );
		list( $nodes ) = $this->graph_for( $url );
		$image         = $nodes[ $url . '#primaryimage' ];
		$this->assertSame( 'ImageObject', $image['@type'] );
		$this->assertSame( 'https://cdn.example.com/share.jpg', $image['url'] );
		$this->assertSame( array( '@id' => $url . '#primaryimage' ), $nodes[ $url . '#webpage' ]['primaryImageOfPage'] );
		$this->assertSame( array( '@id' => $url . '#primaryimage' ), $nodes[ $url . '#article' ]['image'] );
	}

	/**
	 * Page has no article and custom webpage type.
	 */
	public function test_page_has_no_article_and_custom_webpage_type(): void {
		$page = self::factory()->post->create_and_get(
			array(
				'post_type'  => 'page',
				'post_title' => 'O nas',
			)
		);
		Meta::set_post( $page->ID, 'schema_webpage_type', 'AboutPage' );
		$url           = get_permalink( $page );
		list( $nodes ) = $this->graph_for( $url );
		$this->assertArrayNotHasKey( $url . '#article', $nodes );
		$this->assertSame( 'AboutPage', $nodes[ $url . '#webpage' ]['@type'] );

		$this->set_option( 'schema', 'pt_page_type', 'Article' );
		list( $nodes ) = $this->graph_for( $url );
		$this->assertSame( 'BlogPosting', $nodes[ $url . '#article' ]['@type'] );
	}

	/**
	 * Term author and search pages.
	 */
	public function test_term_author_and_search_pages(): void {
		$cat  = self::factory()->category->create( array( 'name' => 'Aktualności' ) );
		$user = self::factory()->user->create( array( 'role' => 'author' ) );
		self::factory()->post->create(
			array(
				'post_category' => array( $cat ),
				'post_author'   => $user,
			)
		);
		$url           = get_category_link( $cat );
		list( $nodes ) = $this->graph_for( $url );
		$this->assertSame( 'CollectionPage', $nodes[ $url . '#webpage' ]['@type'] );
		$this->assertArrayHasKey( $url . '#breadcrumb', $nodes );

		$url           = get_author_posts_url( $user );
		list( $nodes ) = $this->graph_for( $url );
		$this->assertSame( 'ProfilePage', $nodes[ $url . '#webpage' ]['@type'] );
		$this->assertSame( array( '@id' => home_url( '/#author-' . $user ) ), $nodes[ $url . '#webpage' ]['mainEntity'] );
		$this->assertArrayHasKey( home_url( '/#author-' . $user ), $nodes );

		list( $nodes ) = $this->graph_for( home_url( '/?s=hosting' ) );
		$types         = array_column( array_values( $nodes ), '@type' );
		$this->assertContains( 'SearchResultsPage', $types );
	}

	/**
	 * Organization and graph filters.
	 */
	public function test_organization_and_graph_filters(): void {
		$org_filter   = static function ( array $node ): array {
			$node['@type']   = 'LocalBusiness';
			$node['address'] = array(
				'@type'           => 'PostalAddress',
				'addressLocality' => 'Kraków',
			);
			return $node;
		};
		$graph_filter = static function ( array $nodes, array $ctx ): array {
			$nodes[] = array(
				'@type' => 'Thing',
				'@id'   => $ctx['canonical'] . '#extra',
				'name'  => 'Extra',
			);
			return $nodes;
		};
		add_filter( 'mgseo/schema/organization', $org_filter );
		add_filter( 'mgseo/schema/graph', $graph_filter, 10, 2 );
		list( $nodes ) = $this->graph_for( home_url( '/' ) );
		remove_filter( 'mgseo/schema/organization', $org_filter );
		remove_filter( 'mgseo/schema/graph', $graph_filter, 10 );
		$this->assertSame( 'LocalBusiness', $nodes[ home_url( '/#organization' ) ]['@type'] );
		$this->assertSame( 'Kraków', $nodes[ home_url( '/#organization' ) ]['address']['addressLocality'] );
		$this->assertSame( 'Extra', $nodes[ home_url( '/#extra' ) ]['name'] );
	}

	/**
	 * Person knowledge graph.
	 */
	public function test_person_knowledge_graph(): void {
		$this->set_option( 'schema', 'kg_type', 'person' );
		$this->set_option( 'schema', 'person_name', 'Michał Nowak' );
		$post          = self::factory()->post->create_and_get();
		$url           = get_permalink( $post );
		list( $nodes ) = $this->graph_for( $url );
		$this->assertSame( 'Person', $nodes[ home_url( '/#person' ) ]['@type'] );
		$this->assertSame( 'Michał Nowak', $nodes[ home_url( '/#person' ) ]['name'] );
		$this->assertArrayNotHasKey( home_url( '/#organization' ), $nodes );
		$this->assertSame( array( '@id' => home_url( '/#person' ) ), $nodes[ $url . '#article' ]['publisher'] );
	}

	/**
	 * Password protected post has no entities.
	 */
	public function test_password_protected_post_has_no_entities(): void {
		$post          = self::factory()->post->create_and_get(
			array(
				'post_title'    => 'Tajne',
				'post_password' => 'secret',
			)
		);
		$url           = get_permalink( $post );
		list( $nodes ) = $this->graph_for( $url );
		$this->assertArrayNotHasKey( $url . '#article', $nodes );
		$this->assertArrayHasKey( $url . '#webpage', $nodes );
		$this->assertArrayNotHasKey( 'description', $nodes[ $url . '#webpage' ] );
	}

	/**
	 * No plugin fingerprint and valid json.
	 */
	public function test_no_plugin_fingerprint_and_valid_json(): void {
		$post = self::factory()->post->create_and_get( array( 'post_title' => 'Czysto' ) );
		foreach ( array( home_url( '/' ), get_permalink( $post ) ) as $url ) {
			$head = $this->head_for( $url );
			preg_match_all( '#<script type="application/ld\+json">(.*?)</script>#s', $head, $m );
			foreach ( $m[1] as $json ) {
				$this->assertNotNull( json_decode( $json, true ), 'Invalid JSON: ' . json_last_error_msg() );
				$this->assertDoesNotMatchRegularExpression( '/mgseo|mg-seo|mg_seo|rank-?math|yoast/i', $json );
				$this->assertStringNotContainsString( '""', $json, 'Empty string properties must be removed' );
				$this->assertStringNotContainsString( '[]', $json, 'Empty arrays must be removed' );
			}
		}
	}
}
