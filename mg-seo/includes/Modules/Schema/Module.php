<?php
/**
 * Schema (structured data) module.
 *
 * @package MGSEO
 */

namespace MGSEO\Modules\Schema;

use MGSEO\Context;
use MGSEO\Head;
use MGSEO\Meta;
use MGSEO\Module as Base;
use MGSEO\Modules\Titles\Module as Titles;
use MGSEO\Options;
use MGSEO\Rest;

defined( 'ABSPATH' ) || exit;

/**
 * JSON-LD graph, schema generator meta box tab, templates, FAQ / How-to blocks and validation.
 */
final class Module extends Base {

	/**
	 * Module id.
	 */
	public function id(): string {
		return 'schema';
	}

	/**
	 * Register hooks.
	 */
	public function boot(): void {
		add_action( 'mgseo/head', array( $this, 'output' ), 90 );
		add_action( 'init', array( Blocks::class, 'register' ) );
		add_action( 'init', array( $this, 'register_assets' ), 5 );
		add_filter( 'mgseo/metabox_tabs', array( $this, 'metabox_tab' ), 10, 2 );
		add_action( 'mgseo/rest_routes', array( $this, 'routes' ) );
		add_action( 'enqueue_block_editor_assets', array( $this, 'block_editor_assets' ) );
		add_action( 'enqueue_block_assets', array( $this, 'canvas_assets' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'admin_assets' ) );
		add_action( 'admin_notices', array( $this, 'notices' ) );
		add_filter( 'mgseo/variables', array( $this, 'variables' ) );
		add_filter( 'mgseo/variable_descriptions', array( $this, 'variable_descriptions' ) );
	}

	/**
	 * Settings schema.
	 */
	public function settings(): array {
		$types = array(
			'' => __( 'None (WebPage only)', 'mg-seo' ),
		);
		foreach ( Types::all() as $type => $def ) {
			if ( in_array( $type, array( 'FAQPage', 'HowTo' ), true ) ) {
				continue;
			}
			$types[ $type ] = 'Article' === $type ? __( 'Article (type from the setting below)', 'mg-seo' ) : $def['label'];
		}
		$pt_fields = array(
			array(
				'id'      => 'article_type',
				'type'    => 'select',
				'label'   => __( 'Article type', 'mg-seo' ),
				'default' => 'BlogPosting',
				'options' => array(
					'Article'     => 'Article',
					'BlogPosting' => 'BlogPosting',
					'NewsArticle' => 'NewsArticle',
				),
				'desc'    => __( 'Used when a post type default is "Article".', 'mg-seo' ),
			),
		);
		foreach ( Context::post_types() as $name => $object ) {
			$pt_fields[] = array(
				'id'      => 'pt_' . $name . '_type',
				'type'    => 'select',
				/* translators: %s: post type plural name */
				'label'   => sprintf( __( 'Default schema: %s', 'mg-seo' ), $object->labels->name ),
				'default' => Entities::fallback_default( $name ),
				'options' => $types,
			);
		}

		return array(
			array(
				'id'          => 'general',
				'title'       => __( 'Knowledge Graph', 'mg-seo' ),
				'description' => sprintf(
					/* translators: %s: variable name */
					__( 'Who the site represents. Used for the Organization or Person node, the article publisher and the %s variable.', 'mg-seo' ),
					'<code>%org_name%</code>'
				),
				'fields'      => array(
					array(
						'id'      => 'kg_type',
						'type'    => 'select',
						'label'   => __( 'The site represents', 'mg-seo' ),
						'default' => 'organization',
						'options' => array(
							'organization' => __( 'An organization', 'mg-seo' ),
							'person'       => __( 'A person', 'mg-seo' ),
						),
					),
					array(
						'id'    => 'org_heading',
						'type'  => 'heading',
						'label' => __( 'Organization', 'mg-seo' ),
					),
					array(
						'id'      => 'org_name',
						'type'    => 'text',
						'label'   => __( 'Name', 'mg-seo' ),
						'default' => '',
						'desc'    => __( 'Empty: the site title.', 'mg-seo' ),
					),
					array(
						'id'      => 'org_alt_name',
						'type'    => 'text',
						'label'   => __( 'Alternate name', 'mg-seo' ),
						'default' => '',
					),
					array(
						'id'      => 'org_legal_name',
						'type'    => 'text',
						'label'   => __( 'Legal name', 'mg-seo' ),
						'default' => '',
					),
					array(
						'id'      => 'org_type',
						'type'    => 'select',
						'label'   => __( 'Organization type', 'mg-seo' ),
						'default' => 'Organization',
						'options' => array_combine( Types::organization_types(), Types::organization_types() ),
					),
					array(
						'id'      => 'org_logo',
						'type'    => 'image',
						'label'   => __( 'Logo', 'mg-seo' ),
						'default' => '',
						'desc'    => __( 'Square or rectangular image, at least 112 × 112 px.', 'mg-seo' ),
					),
					array(
						'id'      => 'org_url',
						'type'    => 'url',
						'label'   => __( 'URL', 'mg-seo' ),
						'default' => '',
						'desc'    => __( 'Empty: the home URL.', 'mg-seo' ),
					),
					array(
						'id'      => 'org_description',
						'type'    => 'textarea',
						'label'   => __( 'Description', 'mg-seo' ),
						'default' => '',
					),
					array(
						'id'      => 'org_email',
						'type'    => 'text',
						'label'   => __( 'Email', 'mg-seo' ),
						'default' => '',
					),
					array(
						'id'      => 'org_phone',
						'type'    => 'text',
						'label'   => __( 'Phone', 'mg-seo' ),
						'default' => '',
					),
					array(
						'id'          => 'org_founding_date',
						'type'        => 'text',
						'label'       => __( 'Founding date', 'mg-seo' ),
						'default'     => '',
						'placeholder' => 'YYYY-MM-DD',
					),
					array(
						'id'       => 'same_as',
						'type'     => 'textarea',
						'label'    => __( 'Social profiles', 'mg-seo' ),
						'default'  => array(),
						'rows'     => 5,
						'desc'     => __( 'One URL per line (Facebook, LinkedIn, Instagram, YouTube, Wikipedia …). The Facebook page and X account from the Social settings are added automatically.', 'mg-seo' ),
						'sanitize' => array( self::class, 'sanitize_urls' ),
					),
					array(
						'id'       => 'contact_points',
						'type'     => 'callback',
						'label'    => __( 'Contact points', 'mg-seo' ),
						'default'  => array(),
						'callback' => array( self::class, 'render_contact_points' ),
						'sanitize' => array( self::class, 'sanitize_contact_points' ),
					),
					array(
						'id'    => 'person_heading',
						'type'  => 'heading',
						'label' => __( 'Person', 'mg-seo' ),
						'desc'  => __( 'Used when the site represents a person.', 'mg-seo' ),
					),
					array(
						'id'      => 'person_name',
						'type'    => 'text',
						'label'   => __( 'Name', 'mg-seo' ),
						'default' => '',
					),
					array(
						'id'      => 'person_job_title',
						'type'    => 'text',
						'label'   => __( 'Job title', 'mg-seo' ),
						'default' => '',
					),
					array(
						'id'      => 'person_image',
						'type'    => 'image',
						'label'   => __( 'Photo', 'mg-seo' ),
						'default' => '',
					),
					array(
						'id'    => 'website_heading',
						'type'  => 'heading',
						'label' => __( 'Website', 'mg-seo' ),
					),
					array(
						'id'      => 'website_name',
						'type'    => 'text',
						'label'   => __( 'Website name', 'mg-seo' ),
						'default' => '',
						'desc'    => __( 'Site name shown by Google. Empty: the site title.', 'mg-seo' ),
					),
					array(
						'id'      => 'website_alt_name',
						'type'    => 'text',
						'label'   => __( 'Alternate website name', 'mg-seo' ),
						'default' => '',
					),
					array(
						'id'           => 'search_action',
						'type'         => 'toggle',
						'label'        => __( 'Sitelinks search box', 'mg-seo' ),
						'toggle_label' => __( 'Add a SearchAction to the WebSite node', 'mg-seo' ),
						'default'      => true,
					),
				),
			),
			array(
				'id'          => 'post_types',
				'title'       => __( 'Post types', 'mg-seo' ),
				'description' => __( 'Schema added automatically to posts that have no schema of their own in the SEO box.', 'mg-seo' ),
				'fields'      => $pt_fields,
			),
			array(
				'id'          => 'templates',
				'title'       => __( 'Templates', 'mg-seo' ),
				'description' => __( 'Reusable schema added to every post that matches the display conditions (unless a post turns templates off in its SEO box). A template is skipped on posts that already have schema of the same type.', 'mg-seo' ),
				'fields'      => array(
					array(
						'id'       => 'templates',
						'type'     => 'callback',
						'label'    => __( 'Schema templates', 'mg-seo' ),
						'default'  => array(),
						'callback' => array( Templates::class, 'render_field' ),
						'sanitize' => array( Templates::class, 'sanitize' ),
					),
				),
			),
		);
	}

	/**
	 * Sanitize a list of URLs (textarea, one per line).
	 *
	 * @param mixed $value Raw value.
	 * @return string[]
	 */
	public static function sanitize_urls( mixed $value ): array {
		$lines = is_array( $value ) ? $value : preg_split( '/\r\n|\r|\n/', (string) $value );
		$lines = array_map( static fn( $line ) => esc_url_raw( trim( (string) $line ) ), (array) $lines );
		return array_values( array_unique( array_filter( $lines ) ) );
	}

	/**
	 * Render the contact points repeater.
	 *
	 * @param array  $field Field.
	 * @param mixed  $value Value.
	 * @param string $name  Input name.
	 */
	public static function render_contact_points( array $field, mixed $value, string $name ): void {
		$rows   = is_array( $value ) ? array_values( $value ) : array();
		$rows[] = array();
		$cols   = array(
			'contactType' => __( 'Type (e.g. customer service)', 'mg-seo' ),
			'telephone'   => __( 'Phone', 'mg-seo' ),
			'email'       => __( 'Email', 'mg-seo' ),
			'areaServed'  => __( 'Area served', 'mg-seo' ),
		);
		echo '<input type="hidden" name="' . esc_attr( $name ) . '[__present]" value="1" />';
		echo '<table class="widefat mgseo-schema-contacts"><thead><tr>';
		foreach ( $cols as $label ) {
			echo '<th scope="col">' . esc_html( $label ) . '</th>';
		}
		echo '<th></th></tr></thead><tbody>';
		foreach ( $rows as $i => $row ) {
			echo '<tr>';
			foreach ( array_keys( $cols ) as $key ) {
				printf(
					'<td><input type="text" class="widefat" name="%s" value="%s" aria-label="%s" /></td>',
					esc_attr( $name . '[' . $i . '][' . $key . ']' ),
					esc_attr( (string) ( $row[ $key ] ?? '' ) ),
					esc_attr( $cols[ $key ] )
				);
			}
			echo '<td><button type="button" class="button-link mgseo-schema-row-remove">' . esc_html__( 'Remove', 'mg-seo' ) . '</button></td></tr>';
		}
		echo '</tbody></table><p><button type="button" class="button mgseo-schema-row-add">' . esc_html__( 'Add contact point', 'mg-seo' ) . '</button></p>';
	}

	/**
	 * Sanitize contact points.
	 *
	 * @param mixed $value Raw value.
	 * @return array<int, array<string, string>>
	 */
	public static function sanitize_contact_points( mixed $value ): array {
		$out = array();
		foreach ( is_array( $value ) ? $value : array() as $key => $row ) {
			if ( '__present' === $key || ! is_array( $row ) ) {
				continue;
			}
			$clean = array(
				'contactType' => sanitize_text_field( (string) ( $row['contactType'] ?? '' ) ),
				'telephone'   => sanitize_text_field( (string) ( $row['telephone'] ?? '' ) ),
				'email'       => sanitize_email( (string) ( $row['email'] ?? '' ) ),
				'areaServed'  => sanitize_text_field( (string) ( $row['areaServed'] ?? '' ) ),
			);
			if ( '' !== $clean['telephone'] || '' !== $clean['email'] ) {
				$out[] = $clean;
			}
		}
		return $out;
	}

	/**
	 * Print the JSON-LD graph.
	 */
	public function output(): void {
		$result = Graph::build();
		if ( $result['graph'] ) {
			Head::json_ld(
				array(
					'@context' => 'https://schema.org',
					'@graph'   => $result['graph'],
				)
			);
		}
		foreach ( $result['scripts'] as $script ) {
			if ( is_array( $script ) && $script ) {
				Head::json_ld( $script );
			}
		}
	}

	/**
	 * Extra variables for schema values.
	 *
	 * @param array $vars Variables.
	 * @return array
	 */
	public function variables( array $vars ): array {
		$post = static function ( ?object $obj ): ?\WP_Post {
			if ( $obj instanceof \WP_Post ) {
				return $obj;
			}
			$ctx = Context::current();
			return null === $obj && $ctx['object'] instanceof \WP_Post ? $ctx['object'] : null;
		};
		return $vars + array(
			'seo_title'       => static function ( ?object $obj ) use ( $post ): string {
				$p = $post( $obj );
				return $p ? Titles::post_title( $p ) : '';
			},
			'seo_description' => static function ( ?object $obj ) use ( $post ): string {
				$p = $post( $obj );
				return $p ? Titles::post_description( $p ) : '';
			},
			'post_thumbnail'  => static function ( ?object $obj ) use ( $post ): string {
				$p = $post( $obj );
				return $p ? (string) get_the_post_thumbnail_url( $p, 'full' ) : '';
			},
			'org_url'         => static function (): string {
				$url = (string) Options::get( 'schema', 'org_url', '' );
				return '' !== $url ? $url : home_url( '/' );
			},
			'org_logo'        => static fn() => (string) Options::get( 'schema', 'org_logo', '' ),
		);
	}

	/**
	 * Descriptions of the extra variables.
	 *
	 * @param array $descriptions Descriptions.
	 * @return array
	 */
	public function variable_descriptions( array $descriptions ): array {
		return $descriptions + array(
			'seo_title'       => __( 'Resolved SEO title of the post', 'mg-seo' ),
			'seo_description' => __( 'Resolved meta description of the post', 'mg-seo' ),
			'post_thumbnail'  => __( 'Featured image URL', 'mg-seo' ),
			'org_url'         => __( 'Organization URL from schema settings', 'mg-seo' ),
			'org_logo'        => __( 'Organization logo URL from schema settings', 'mg-seo' ),
		);
	}

	/**
	 * Schema tab in the SEO box.
	 *
	 * @param array    $tabs Tabs.
	 * @param \WP_Post $post Post.
	 * @return array
	 */
	public function metabox_tab( array $tabs, \WP_Post $post ): array {
		$tabs[] = array(
			'id'         => 'schema',
			'title'      => __( 'Schema', 'mg-seo' ),
			'capability' => 'mgseo_edit_advanced',
			'priority'   => 40,
			'render'     => array( $this, 'render_tab' ),
			'save'       => array( $this, 'save_tab' ),
		);
		return $tabs;
	}

	/**
	 * Render the Schema tab.
	 *
	 * @param \WP_Post $post Post.
	 */
	public function render_tab( \WP_Post $post ): void {
		$stored   = Entities::stored( $post->ID );
		$default  = Entities::default_entity( $post );
		$entities = null === $stored ? array_values( array_filter( array( $default ) ) ) : $stored;
		$errors   = get_transient( 'mgseo_schema_errors_' . get_current_user_id() );
		if ( is_array( $errors ) && $errors ) {
			delete_transient( 'mgseo_schema_errors_' . get_current_user_id() );
			foreach ( $errors as $error ) {
				echo '<div class="notice notice-error inline"><p>' . esc_html( (string) $error ) . '</p></div>';
			}
		}
		$templates = array();
		foreach ( Templates::all() as $template ) {
			if ( Templates::matches( $template, $post ) ) {
				$templates[] = (string) ( $template['name'] ?? $template['type'] ?? '' );
			}
		}
		$blocks    = array_map( static fn( $e ) => $e['type'], Blocks::entities( $post ) );
		$permalink = (string) get_permalink( $post );
		$webpage   = (string) Meta::post( $post->ID, Entities::META_WEBPAGE, '' );
		?>
		<div class="mgseo-schema" data-post-id="<?php echo esc_attr( (string) $post->ID ); ?>" data-default="<?php echo esc_attr( null === $stored ? '1' : '0' ); ?>">
			<input type="hidden" name="mgseo[schema_tab]" value="1" />
			<input type="hidden" class="mgseo-schema-json" name="mgseo[schema_json]" value="<?php echo esc_attr( (string) wp_json_encode( $entities ) ); ?>" />
			<input type="hidden" class="mgseo-schema-changed" name="mgseo[schema_changed]" value="0" />
			<input type="hidden" class="mgseo-schema-reset" name="mgseo[schema_reset]" value="0" />
			<p class="mgseo-schema-status description">
				<?php
				if ( null === $stored ) {
					echo $default ? esc_html__( 'This post uses the default schema of its post type. Edit it below to customise it for this post.', 'mg-seo' ) : esc_html__( 'No default schema for this post type. Add schema below.', 'mg-seo' );
				} else {
					esc_html_e( 'This post has its own schema.', 'mg-seo' );
				}
				?>
			</p>
			<div class="mgseo-schema-entities" aria-live="polite"></div>
			<p class="mgseo-schema-add">
				<label for="mgseo-schema-add-type" class="screen-reader-text"><?php esc_html_e( 'Schema type', 'mg-seo' ); ?></label>
				<select id="mgseo-schema-add-type" class="mgseo-schema-add-type">
					<?php foreach ( Types::all() as $type => $def ) : ?>
						<option value="<?php echo esc_attr( $type ); ?>"><?php echo esc_html( $def['label'] ); ?></option>
					<?php endforeach; ?>
					<option value="custom"><?php esc_html_e( 'Custom JSON-LD', 'mg-seo' ); ?></option>
				</select>
				<button type="button" class="button mgseo-schema-add-button"><?php esc_html_e( 'Add schema', 'mg-seo' ); ?></button>
				<?php if ( null !== $stored && $default ) : ?>
					<button type="button" class="button-link mgseo-schema-reset-button"><?php esc_html_e( 'Use the post type default again', 'mg-seo' ); ?></button>
				<?php endif; ?>
			</p>
			<p class="mgseo-row">
				<label for="mgseo-schema-webpage"><strong><?php esc_html_e( 'Page type', 'mg-seo' ); ?></strong></label>
				<select id="mgseo-schema-webpage" name="mgseo[schema_webpage_type]">
					<?php foreach ( Graph::webpage_types() as $value => $label ) : ?>
						<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $webpage, $value ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
			</p>
			<p class="mgseo-row">
				<label class="mgseo-inline"><input type="checkbox" name="mgseo[schema_no_templates]" value="1" <?php checked( (bool) Meta::post( $post->ID, Entities::META_NO_TEMPLATE, false ) ); ?> /> <?php esc_html_e( 'Do not add schema templates to this post', 'mg-seo' ); ?></label>
				<?php if ( $templates ) : ?>
					<?php /* translators: %s: list of template names */ ?>
					<br /><span class="description"><?php echo esc_html( sprintf( __( 'Matching templates: %s', 'mg-seo' ), implode( ', ', $templates ) ) ); ?></span>
				<?php endif; ?>
				<?php if ( $blocks ) : ?>
					<?php /* translators: %s: list of schema types */ ?>
					<br /><span class="description"><?php echo esc_html( sprintf( __( 'Added automatically from blocks in the content: %s', 'mg-seo' ), implode( ', ', array_unique( $blocks ) ) ) ); ?></span>
				<?php endif; ?>
			</p>
			<p class="mgseo-schema-tools">
				<button type="button" class="button mgseo-schema-validate"><?php esc_html_e( 'Validate schema', 'mg-seo' ); ?></button>
				<?php if ( $permalink && 'publish' === $post->post_status ) : ?>
					<a class="button" href="<?php echo esc_url( 'https://search.google.com/test/rich-results?url=' . rawurlencode( $permalink ) ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Test in Google Rich Results', 'mg-seo' ); ?><span class="screen-reader-text"> <?php esc_html_e( '(opens in a new tab)', 'mg-seo' ); ?></span></a>
				<?php else : ?>
					<span class="description"><?php esc_html_e( 'Publish the post to test it with Google Rich Results.', 'mg-seo' ); ?></span>
				<?php endif; ?>
			</p>
			<div class="mgseo-schema-results" aria-live="polite"></div>
		</div>
		<?php
	}

	/**
	 * Save the Schema tab.
	 *
	 * @param int   $post_id Post id.
	 * @param array $data    Unslashed data.
	 */
	public function save_tab( int $post_id, array $data ): void {
		if ( empty( $data['schema_tab'] ) || ! current_user_can( 'mgseo_edit_advanced' ) ) {
			return;
		}
		Meta::set_post( $post_id, Entities::META_NO_TEMPLATE, ! empty( $data['schema_no_templates'] ) );
		$webpage = sanitize_text_field( (string) ( $data['schema_webpage_type'] ?? '' ) );
		Meta::set_post( $post_id, Entities::META_WEBPAGE, isset( Graph::webpage_types()[ $webpage ] ) ? $webpage : '' );

		if ( empty( $data['schema_changed'] ) ) {
			return;
		}
		if ( ! empty( $data['schema_reset'] ) ) {
			Entities::store( $post_id, null );
			return;
		}
		$errors   = array();
		$previous = Entities::stored( $post_id ) ?? array();
		$list     = Entities::sanitize_list( (string) ( $data['schema_json'] ?? '' ), $errors, $previous );
		Entities::store( $post_id, $list );
		if ( $errors ) {
			set_transient( 'mgseo_schema_errors_' . get_current_user_id(), $errors, 300 );
		}
	}

	/**
	 * Error notices after saving invalid custom JSON-LD (classic editor / settings screen).
	 */
	public function notices(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || ( 'post' === $screen->base && $screen->is_block_editor() ) ) {
			return;
		}
		$is_settings = str_contains( (string) $screen->id, 'mg-seo-schema' );
		if ( 'post' !== $screen->base && ! $is_settings ) {
			return;
		}
		$key    = 'mgseo_schema_errors_' . get_current_user_id();
		$errors = get_transient( $key );
		if ( ! is_array( $errors ) || ! $errors ) {
			return;
		}
		if ( $is_settings ) {
			delete_transient( $key );
		}
		foreach ( $errors as $error ) {
			echo '<div class="notice notice-error"><p>' . esc_html( (string) $error ) . '</p></div>';
		}
	}

	/**
	 * REST routes.
	 *
	 * @param string $ns Namespace.
	 */
	public function routes( string $ns ): void {
		register_rest_route(
			$ns,
			'/schema/validate',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'rest_validate' ),
				'permission_callback' => static function ( \WP_REST_Request $request ): bool {
					$post_id = (int) $request->get_param( 'post_id' );
					return $post_id > 0 && current_user_can( 'edit_post', $post_id ) && current_user_can( 'mgseo_edit_advanced' );
				},
				'args'                => array(
					'post_id'  => array(
						'type'     => 'integer',
						'required' => true,
					),
					'entities' => array(
						'type'     => array( 'string', 'array' ),
						'required' => false,
					),
				),
			)
		);
	}

	/**
	 * Validate the entities of a post (stored or submitted, unsaved state).
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function rest_validate( \WP_REST_Request $request ): \WP_REST_Response {
		$post = get_post( (int) $request->get_param( 'post_id' ) );
		if ( ! $post ) {
			return new \WP_REST_Response( array( 'message' => __( 'Post not found.', 'mg-seo' ) ), 404 );
		}
		$raw      = $request->get_param( 'entities' );
		$errors   = array();
		$entities = null === $raw ? Entities::for_post( $post ) : Entities::sanitize_list( $raw, $errors );
		if ( null !== $raw ) {
			$extra    = array_filter( Entities::for_post( $post ), static fn( $e ) => ! empty( $e['block'] ) || isset( $e['template'] ) );
			$entities = array_merge( $entities, array_values( $extra ) );
		}
		$setup = Graph::post_env( $post );
		$built = Entities::build_all( $entities, $post, $setup['env'] );
		Context::reset();

		$results = array();
		foreach ( $built['nodes'] as $node ) {
			$results[] = array(
				'id'   => (string) ( $node['@id'] ?? '' ),
				'type' => is_array( $node['@type'] ?? '' ) ? implode( ', ', $node['@type'] ) : (string) ( $node['@type'] ?? '' ),
			) + Validator::validate( $node, array_merge( $setup['graph'], $built['nodes'] ) );
		}
		foreach ( $built['scripts'] as $script ) {
			$nodes = isset( $script['@graph'] ) && is_array( $script['@graph'] ) ? $script['@graph'] : array( $script );
			foreach ( $nodes as $node ) {
				if ( is_array( $node ) ) {
					$results[] = array(
						'id'   => (string) ( $node['@id'] ?? '' ),
						'type' => is_array( $node['@type'] ?? '' ) ? implode( ', ', $node['@type'] ) : (string) ( $node['@type'] ?? '' ),
					) + Validator::validate( $node, $nodes );
				}
			}
		}
		$preview = array(
			'@context' => 'https://schema.org',
			'@graph'   => Graph::finalize( $built['nodes'] ),
		);
		return new \WP_REST_Response(
			array(
				'results' => $results,
				'errors'  => $errors,
				'json'    => (string) wp_json_encode( $preview, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ),
			)
		);
	}

	/**
	 * Register the admin / editor script (no build step).
	 */
	public function register_assets(): void {
		if ( ! is_admin() ) {
			return;
		}
		wp_register_style( 'mgseo-schema', MGSEO_URL . 'assets/schema.css', array(), MGSEO_VERSION );
	}

	/**
	 * Localized data for schema.js.
	 *
	 * @return array<string, mixed>
	 */
	private function script_data(): array {
		$post_types = array();
		foreach ( Context::post_types() as $name => $object ) {
			$post_types[ $name ] = $object->labels->singular_name;
		}
		$taxonomies = array();
		foreach ( Context::taxonomies() as $name => $object ) {
			$taxonomies[ $name ] = $object->labels->singular_name;
		}
		return array(
			'types'      => Types::for_js(),
			'restUrl'    => esc_url_raw( rest_url( Rest::NAMESPACE . '/schema/validate' ) ),
			'nonce'      => wp_create_nonce( 'wp_rest' ),
			'postTypes'  => $post_types,
			'taxonomies' => $taxonomies,
			'i18n'       => array(
				'remove'        => __( 'Remove', 'mg-seo' ),
				'removeConfirm' => __( 'Remove this schema?', 'mg-seo' ),
				'add'           => __( 'Add', 'mg-seo' ),
				'addItem'       => __( 'Add item', 'mg-seo' ),
				'moveUp'        => __( 'Move up', 'mg-seo' ),
				'moveDown'      => __( 'Move down', 'mg-seo' ),
				'type'          => __( 'Type', 'mg-seo' ),
				'custom'        => __( 'Custom JSON-LD', 'mg-seo' ),
				'customHelp'    => __( 'Paste JSON-LD. Without "@context" it is added to the page graph; with "@context" it is printed as a separate script. Values may contain variables.', 'mg-seo' ),
				'invalidJson'   => __( 'Invalid JSON:', 'mg-seo' ),
				'validJson'     => __( 'Valid JSON', 'mg-seo' ),
				'default'       => __( 'Default', 'mg-seo' ),
				'template'      => __( 'Template', 'mg-seo' ),
				'name'          => __( 'Template name', 'mg-seo' ),
				'conditions'    => __( 'Display conditions', 'mg-seo' ),
				'addCondition'  => __( 'Add condition', 'mg-seo' ),
				'include'       => __( 'Include', 'mg-seo' ),
				'exclude'       => __( 'Exclude', 'mg-seo' ),
				'kindAll'       => __( 'Entire site', 'mg-seo' ),
				'kindPostType'  => __( 'Post type', 'mg-seo' ),
				'kindPost'      => __( 'Specific posts (IDs)', 'mg-seo' ),
				'kindTaxonomy'  => __( 'Taxonomy term', 'mg-seo' ),
				'taxonomyHint'  => __( 'taxonomy:term (slug or ID), e.g. category:news or category:* for any term', 'mg-seo' ),
				'postIdsHint'   => __( 'Comma separated IDs', 'mg-seo' ),
				'noConditions'  => __( 'Without an include condition the template is not used.', 'mg-seo' ),
				'validating'    => __( 'Validating…', 'mg-seo' ),
				'noIssues'      => __( 'No issues found.', 'mg-seo' ),
				'errors'        => __( 'Errors', 'mg-seo' ),
				'warnings'      => __( 'Warnings', 'mg-seo' ),
				'preview'       => __( 'JSON-LD preview', 'mg-seo' ),
				'requestFailed' => __( 'Validation request failed.', 'mg-seo' ),
				'empty'         => __( 'No schema yet.', 'mg-seo' ),
				'chooseImage'   => __( 'Choose image', 'mg-seo' ),
				'durationHint'  => __( 'Minutes, "1h 30m" or ISO 8601 (PT1H30M)', 'mg-seo' ),
				'variablesHint' => sprintf(
					/* translators: 1: example variable, 2: example variable */
					__( 'Variables such as %1$s or %2$s are allowed.', 'mg-seo' ),
					'%title%',
					'%excerpt%'
				),
				'collapse'      => __( 'Toggle details', 'mg-seo' ),
				'onePerLine'    => __( 'One per line.', 'mg-seo' ),
				// Block editor.
				'faq'           => __( 'FAQ', 'mg-seo' ),
				'question'      => __( 'Question', 'mg-seo' ),
				'answer'        => __( 'Answer', 'mg-seo' ),
				'addQuestion'   => __( 'Add question', 'mg-seo' ),
				'headingLevel'  => __( 'Heading level', 'mg-seo' ),
				'accordion'     => __( 'Collapsible answers', 'mg-seo' ),
				'howto'         => __( 'How-to', 'mg-seo' ),
				'howtoTitle'    => __( 'Name (empty: post title)', 'mg-seo' ),
				'description'   => __( 'Description', 'mg-seo' ),
				'hours'         => __( 'Hours', 'mg-seo' ),
				'minutes'       => __( 'Minutes', 'mg-seo' ),
				'supplies'      => __( 'Supplies', 'mg-seo' ),
				'tools'         => __( 'Tools', 'mg-seo' ),
				'addSupply'     => __( 'Add supply', 'mg-seo' ),
				'addTool'       => __( 'Add tool', 'mg-seo' ),
				'step'          => __( 'Step', 'mg-seo' ),
				'stepTitle'     => __( 'Step title', 'mg-seo' ),
				'stepText'      => __( 'Instructions', 'mg-seo' ),
				'addStep'       => __( 'Add step', 'mg-seo' ),
				'image'         => __( 'Image', 'mg-seo' ),
				'removeImage'   => __( 'Remove image', 'mg-seo' ),
				'totalTime'     => __( 'Total time', 'mg-seo' ),
				'settings'      => __( 'Settings', 'mg-seo' ),
			),
		);
	}

	/**
	 * Register and localize the script with the given dependencies (once).
	 *
	 * @param string[] $deps Dependencies.
	 */
	private function enqueue_script( array $deps ): void {
		if ( ! wp_script_is( 'mgseo-schema', 'registered' ) ) {
			wp_register_script( 'mgseo-schema', MGSEO_URL . 'assets/schema.js', $deps, MGSEO_VERSION, true );
			wp_localize_script( 'mgseo-schema', 'mgseoSchema', $this->script_data() );
			wp_set_script_translations( 'mgseo-schema', 'mg-seo', MGSEO_DIR . 'languages' );
		}
		wp_enqueue_script( 'mgseo-schema' );
		wp_enqueue_style( 'mgseo-schema' );
	}

	/**
	 * Block editor: FAQ / How-to block editors (and the meta box tab script).
	 */
	public function block_editor_assets(): void {
		$this->enqueue_script( array( 'jquery', 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-i18n', 'wp-api-fetch' ) );
	}

	/**
	 * Iframed editor canvas: icons and layout of the FAQ / How-to block editors (never on the front end).
	 */
	public function canvas_assets(): void {
		if ( ! is_admin() ) {
			return;
		}
		wp_enqueue_style( 'dashicons' );
		wp_enqueue_style( 'mgseo-schema' );
	}

	/**
	 * Classic editor and the schema settings page.
	 *
	 * @param string $hook Hook suffix.
	 */
	public function admin_assets( string $hook ): void {
		$screen      = get_current_screen();
		$is_settings = str_contains( $hook, 'mg-seo-schema' );
		if ( ! $is_settings && ! ( $screen && 'post' === $screen->base && current_user_can( 'mgseo_edit_advanced' ) ) ) {
			return;
		}
		if ( $is_settings ) {
			wp_enqueue_media();
		}
		$this->enqueue_script( array( 'jquery', 'wp-api-fetch' ) );
	}
}
