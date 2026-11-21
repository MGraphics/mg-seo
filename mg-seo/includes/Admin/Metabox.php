<?php
/**
 * Per-post SEO box (classic and block editor).
 *
 * @package MGSEO
 */

namespace MGSEO\Admin;

use MGSEO\Context;
use MGSEO\Helpers;
use MGSEO\Meta;
use MGSEO\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Meta box with tabs. Modules add tabs via the `mgseo/metabox_tabs` filter:
 *   array( 'id' => 'schema', 'title' => 'Schema', 'render' => callable( WP_Post ), 'save' => callable( int $post_id, array $data ), 'capability' => 'mgseo_edit_advanced', 'priority' => 40 )
 * Field names inside a tab must be `mgseo[...]`; $data passed to save() is the unslashed `mgseo` array.
 */
final class Metabox {

	/**
	 * Register hooks.
	 */
	public static function boot(): void {
		add_action( 'add_meta_boxes', array( self::class, 'add' ), 10, 2 );
		add_action( 'save_post', array( self::class, 'save' ), 10, 2 );
		add_action( 'edit_attachment', array( self::class, 'save_attachment' ) );
		add_action( 'init', array( self::class, 'register_meta' ), 20 );
		add_action( 'enqueue_block_editor_assets', array( self::class, 'block_editor_assets' ) );
	}

	/**
	 * Register post meta for REST (read by the block editor panel and integrations).
	 */
	public static function register_meta(): void {
		foreach ( array_keys( Context::post_types() ) as $post_type ) {
			foreach ( Meta::post_keys() as $key => $type ) {
				$args = array(
					'single'        => true,
					'type'          => $type,
					'show_in_rest'  => true,
					'auth_callback' => static fn( $allowed, $meta_key, $post_id ) => Helpers::can_edit_post_seo( (int) $post_id ),
				);
				if ( 'array' === $type ) {
					$args['show_in_rest'] = array(
						'schema' => array(
							'type'  => 'array',
							'items' => array( 'type' => 'string' ),
						),
					);
				} elseif ( 'object' === $type ) {
					$args['show_in_rest'] = array(
						'schema' => array(
							'type'                 => 'object',
							'additionalProperties' => array( 'type' => array( 'string', 'integer' ) ),
						),
					);
				}
				register_post_meta( $post_type, Meta::PREFIX . $key, $args );
			}
		}
	}

	/**
	 * Add the meta box.
	 *
	 * @param string   $post_type Post type.
	 * @param \WP_Post $post      Post.
	 */
	public static function add( string $post_type, $post ): void {
		if ( ! isset( Context::post_types()[ $post_type ] ) && 'attachment' !== $post_type ) {
			return;
		}
		if ( ! current_user_can( 'mgseo_edit_meta' ) ) {
			return;
		}
		if ( in_array( $post_type, (array) Options::get( 'titles', 'metabox_hidden', array() ), true ) ) {
			return;
		}
		add_meta_box( 'mgseo-box', __( 'SEO', 'mg-seo' ), array( self::class, 'render' ), $post_type, 'normal', 'high', array( '__back_compat_meta_box' => false ) );
	}

	/**
	 * Tabs (core + module supplied).
	 *
	 * @param \WP_Post $post Post.
	 * @return array<int, array<string, mixed>>
	 */
	public static function tabs( \WP_Post $post ): array {
		$tabs = array(
			array(
				'id'       => 'general',
				'title'    => __( 'General', 'mg-seo' ),
				'render'   => array( self::class, 'render_general' ),
				'save'     => array( self::class, 'save_general' ),
				'priority' => 10,
			),
			array(
				'id'         => 'advanced',
				'title'      => __( 'Advanced', 'mg-seo' ),
				'render'     => array( self::class, 'render_advanced' ),
				'save'       => array( self::class, 'save_advanced' ),
				'capability' => 'mgseo_edit_advanced',
				'priority'   => 20,
			),
		);
		/**
		 * Filter meta box tabs.
		 *
		 * @param array    $tabs Tabs.
		 * @param \WP_Post $post Post.
		 */
		$tabs = apply_filters( 'mgseo/metabox_tabs', $tabs, $post );
		$tabs = array_filter( $tabs, static fn( $tab ) => empty( $tab['capability'] ) || current_user_can( $tab['capability'] ) );
		usort( $tabs, static fn( $a, $b ) => ( $a['priority'] ?? 50 ) <=> ( $b['priority'] ?? 50 ) );
		return array_values( $tabs );
	}

	/**
	 * Render the box.
	 *
	 * @param \WP_Post $post Post.
	 */
	public static function render( \WP_Post $post ): void {
		wp_nonce_field( 'mgseo_metabox', 'mgseo_metabox_nonce' );
		$tabs = self::tabs( $post );
		echo '<div class="mgseo-box" data-post-id="' . esc_attr( (string) $post->ID ) . '" data-post-type="' . esc_attr( $post->post_type ) . '">';
		echo '<div class="mgseo-box-tabs" role="tablist">';
		foreach ( $tabs as $i => $tab ) {
			printf(
				'<button type="button" role="tab" class="mgseo-box-tab%s" data-tab="%s" aria-selected="%s">%s</button>',
				0 === $i ? ' is-active' : '',
				esc_attr( $tab['id'] ),
				0 === $i ? 'true' : 'false',
				esc_html( $tab['title'] )
			);
		}
		echo '</div>';
		foreach ( $tabs as $i => $tab ) {
			printf( '<div class="mgseo-box-panel%s" data-panel="%s" role="tabpanel">', 0 === $i ? ' is-active' : '', esc_attr( $tab['id'] ) );
			call_user_func( $tab['render'], $post );
			echo '</div>';
		}
		echo '</div>';
	}

	/**
	 * General tab: snippet preview, keyword, title, description, analysis.
	 *
	 * @param \WP_Post $post Post.
	 */
	public static function render_general( \WP_Post $post ): void {
		$title_tpl = (string) Options::get( 'titles', 'pt_' . $post->post_type . '_title', '%title% %sep% %sitename%' );
		$desc_tpl  = (string) Options::get( 'titles', 'pt_' . $post->post_type . '_description', '%excerpt%' );
		$title     = (string) Meta::post( $post->ID, 'title' );
		$desc      = (string) Meta::post( $post->ID, 'description' );
		$keyword   = (string) Meta::post( $post->ID, 'focus_keyword' );
		$preview_t = \MGSEO\Variables::replace( '' !== $title ? $title : $title_tpl, $post );
		$preview_d = \MGSEO\Variables::replace( '' !== $desc ? $desc : $desc_tpl, $post );
		?>
		<div class="mgseo-serp" aria-live="polite">
			<div class="mgseo-serp-url"><?php echo esc_html( (string) get_permalink( $post ) ); ?></div>
			<div class="mgseo-serp-title"><?php echo esc_html( $preview_t ); ?></div>
			<div class="mgseo-serp-desc"><?php echo esc_html( $preview_d ); ?></div>
		</div>
		<p class="mgseo-row">
			<label for="mgseo-focus-keyword"><strong><?php esc_html_e( 'Focus keywords', 'mg-seo' ); ?></strong></label>
			<input type="text" id="mgseo-focus-keyword" name="mgseo[focus_keyword]" value="<?php echo esc_attr( $keyword ); ?>" class="widefat" placeholder="<?php esc_attr_e( 'Main keyword, secondary keyword, …', 'mg-seo' ); ?>" />
			<span class="description"><?php esc_html_e( 'Separate keywords with commas. The first one is the primary keyword.', 'mg-seo' ); ?></span>
		</p>
		<p class="mgseo-row">
			<label for="mgseo-title"><strong><?php esc_html_e( 'SEO title', 'mg-seo' ); ?></strong> <span class="mgseo-counter" data-for="mgseo-title" data-max="60"></span></label>
			<input type="text" id="mgseo-title" name="mgseo[title]" value="<?php echo esc_attr( $title ); ?>" class="widefat mgseo-has-variables" placeholder="<?php echo esc_attr( $title_tpl ); ?>" data-template="<?php echo esc_attr( $title_tpl ); ?>" data-preview="<?php echo esc_attr( $preview_t ); ?>" />
		</p>
		<p class="mgseo-row">
			<label for="mgseo-description"><strong><?php esc_html_e( 'Meta description', 'mg-seo' ); ?></strong> <span class="mgseo-counter" data-for="mgseo-description" data-max="160"></span></label>
			<textarea id="mgseo-description" name="mgseo[description]" rows="3" class="widefat mgseo-has-variables" placeholder="<?php echo esc_attr( $desc_tpl ); ?>" data-template="<?php echo esc_attr( $desc_tpl ); ?>" data-preview="<?php echo esc_attr( $preview_d ); ?>"><?php echo esc_textarea( $desc ); ?></textarea>
		</p>
		<?php if ( \MGSEO\Plugin::instance()->module( 'analysis' ) ) : ?>
			<div class="mgseo-analysis" data-score="<?php echo esc_attr( (string) Meta::post( $post->ID, 'score', '' ) ); ?>">
				<h4><?php esc_html_e( 'SEO analysis', 'mg-seo' ); ?> <span class="mgseo-score-badge"></span></h4>
				<ul class="mgseo-analysis-list"><li><?php esc_html_e( 'Enter a focus keyword to analyse the content.', 'mg-seo' ); ?></li></ul>
			</div>
		<?php endif; ?>
		<?php
		/**
		 * Fires at the end of the General tab.
		 *
		 * @param \WP_Post $post Post.
		 */
		do_action( 'mgseo/metabox_general', $post );
	}

	/**
	 * Advanced tab: robots, canonical, breadcrumbs title, primary terms.
	 *
	 * @param \WP_Post $post Post.
	 */
	public static function render_advanced( \WP_Post $post ): void {
		$robots   = (array) Meta::post( $post->ID, 'robots', array() );
		$advanced = (array) Meta::post( $post->ID, 'advanced_robots', array() );
		$index    = in_array( 'noindex', $robots, true ) ? 'noindex' : ( in_array( 'index', $robots, true ) ? 'index' : '' );
		$default  = in_array( 'noindex', (array) Options::get( 'titles', 'pt_' . $post->post_type . '_robots', array() ), true ) ? __( 'No Index', 'mg-seo' ) : __( 'Index', 'mg-seo' );
		?>
		<p class="mgseo-row">
			<label for="mgseo-index"><strong><?php esc_html_e( 'Indexing', 'mg-seo' ); ?></strong></label>
			<select id="mgseo-index" name="mgseo[index]">
				<?php /* translators: %s: default indexing value */ ?>
				<option value=""><?php echo esc_html( sprintf( __( 'Default (%s)', 'mg-seo' ), $default ) ); ?></option>
				<option value="index" <?php selected( $index, 'index' ); ?>><?php esc_html_e( 'Index', 'mg-seo' ); ?></option>
				<option value="noindex" <?php selected( $index, 'noindex' ); ?>><?php esc_html_e( 'No Index', 'mg-seo' ); ?></option>
			</select>
		</p>
		<fieldset class="mgseo-row"><legend><strong><?php esc_html_e( 'Robots meta', 'mg-seo' ); ?></strong></legend>
			<?php foreach ( Helpers::robots_directives() as $key => $label ) : ?>
				<?php
				if ( 'noindex' === $key ) {
					continue;
				}
				?>
				<label class="mgseo-inline"><input type="checkbox" name="mgseo[robots][]" value="<?php echo esc_attr( $key ); ?>" <?php checked( in_array( $key, $robots, true ) ); ?> /> <?php echo esc_html( $label ); ?></label>
			<?php endforeach; ?>
		</fieldset>
		<p class="mgseo-row mgseo-columns">
			<label><?php esc_html_e( 'Max snippet', 'mg-seo' ); ?> <input type="number" min="-1" name="mgseo[advanced_robots][max-snippet]" value="<?php echo esc_attr( (string) ( $advanced['max-snippet'] ?? '' ) ); ?>" class="small-text" /></label>
			<label><?php esc_html_e( 'Max video preview', 'mg-seo' ); ?> <input type="number" min="-1" name="mgseo[advanced_robots][max-video-preview]" value="<?php echo esc_attr( (string) ( $advanced['max-video-preview'] ?? '' ) ); ?>" class="small-text" /></label>
			<label><?php esc_html_e( 'Max image preview', 'mg-seo' ); ?>
				<select name="mgseo[advanced_robots][max-image-preview]">
					<option value=""><?php esc_html_e( 'Default', 'mg-seo' ); ?></option>
					<?php foreach ( array( 'large', 'standard', 'none' ) as $size ) : ?>
						<option value="<?php echo esc_attr( $size ); ?>" <?php selected( $advanced['max-image-preview'] ?? '', $size ); ?>><?php echo esc_html( $size ); ?></option>
					<?php endforeach; ?>
				</select>
			</label>
		</p>
		<p class="mgseo-row">
			<label for="mgseo-canonical"><strong><?php esc_html_e( 'Canonical URL', 'mg-seo' ); ?></strong></label>
			<input type="url" id="mgseo-canonical" name="mgseo[canonical]" value="<?php echo esc_attr( (string) Meta::post( $post->ID, 'canonical' ) ); ?>" class="widefat" placeholder="<?php echo esc_attr( (string) get_permalink( $post ) ); ?>" />
		</p>
		<p class="mgseo-row">
			<label for="mgseo-breadcrumb-title"><strong><?php esc_html_e( 'Breadcrumb title', 'mg-seo' ); ?></strong></label>
			<input type="text" id="mgseo-breadcrumb-title" name="mgseo[breadcrumb_title]" value="<?php echo esc_attr( (string) Meta::post( $post->ID, 'breadcrumb_title' ) ); ?>" class="widefat" />
		</p>
		<?php
		foreach ( get_object_taxonomies( $post->post_type, 'objects' ) as $taxonomy ) {
			if ( ! $taxonomy->hierarchical || ! $taxonomy->public ) {
				continue;
			}
			$terms = get_the_terms( $post, $taxonomy->name );
			if ( ! is_array( $terms ) || count( $terms ) < 2 ) {
				continue;
			}
			$primary = (int) Meta::post( $post->ID, 'primary_' . $taxonomy->name, 0 );
			echo '<p class="mgseo-row"><label><strong>';
			/* translators: %s: taxonomy singular name */
			echo esc_html( sprintf( __( 'Primary %s', 'mg-seo' ), $taxonomy->labels->singular_name ) );
			echo '</strong> <select name="mgseo[primary][' . esc_attr( $taxonomy->name ) . ']"><option value="0">' . esc_html__( 'Automatic', 'mg-seo' ) . '</option>';
			foreach ( $terms as $term ) {
				echo '<option value="' . esc_attr( (string) $term->term_id ) . '" ' . selected( $primary, $term->term_id, false ) . '>' . esc_html( $term->name ) . '</option>';
			}
			echo '</select></label></p>';
		}
		?>
		<p class="mgseo-row">
			<label class="mgseo-inline"><input type="checkbox" name="mgseo[pillar]" value="1" <?php checked( (bool) Meta::post( $post->ID, 'pillar', false ) ); ?> /> <?php esc_html_e( 'Pillar content (cornerstone, preferred in link suggestions)', 'mg-seo' ); ?></label>
			<label class="mgseo-inline"><input type="checkbox" name="mgseo[sitemap_exclude]" value="1" <?php checked( (bool) Meta::post( $post->ID, 'sitemap_exclude', false ) ); ?> /> <?php esc_html_e( 'Exclude from XML sitemap', 'mg-seo' ); ?></label>
		</p>
		<?php
	}

	/**
	 * Save handler.
	 *
	 * @param int      $post_id Post id.
	 * @param \WP_Post $post    Post.
	 */
	public static function save( int $post_id, $post ): void {
		if ( ! isset( $_POST['mgseo_metabox_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['mgseo_metabox_nonce'] ) ), 'mgseo_metabox' ) ) {
			return;
		}
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		if ( ! Helpers::can_edit_post_seo( $post_id ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- each tab sanitizes its own values.
		$data = isset( $_POST['mgseo'] ) && is_array( $_POST['mgseo'] ) ? wp_unslash( $_POST['mgseo'] ) : array();
		foreach ( self::tabs( $post ) as $tab ) {
			if ( isset( $tab['save'] ) && is_callable( $tab['save'] ) ) {
				call_user_func( $tab['save'], $post_id, $data );
			}
		}
		/**
		 * Fires after MG SEO post data is saved from the meta box.
		 *
		 * @param int   $post_id Post id.
		 * @param array $data    Raw (unslashed) data.
		 */
		do_action( 'mgseo/metabox_saved', $post_id, $data );
	}

	/**
	 * Attachments use edit_attachment instead of save_post for some flows.
	 *
	 * @param int $post_id Attachment id.
	 */
	public static function save_attachment( int $post_id ): void {
		$post = get_post( $post_id );
		if ( $post && ! did_action( 'save_post' ) ) {
			self::save( $post_id, $post );
		}
	}

	/**
	 * Save general tab.
	 *
	 * @param int   $post_id Post id.
	 * @param array $data    Data.
	 */
	public static function save_general( int $post_id, array $data ): void {
		if ( array_key_exists( 'title', $data ) ) {
			Meta::set_post( $post_id, 'title', sanitize_text_field( (string) $data['title'] ) );
		}
		if ( array_key_exists( 'description', $data ) ) {
			Meta::set_post( $post_id, 'description', sanitize_textarea_field( (string) $data['description'] ) );
		}
		if ( array_key_exists( 'focus_keyword', $data ) ) {
			$keywords = array_filter( array_map( 'trim', explode( ',', sanitize_text_field( (string) $data['focus_keyword'] ) ) ) );
			Meta::set_post( $post_id, 'focus_keyword', implode( ', ', array_unique( $keywords ) ) );
		}
	}

	/**
	 * Save advanced tab.
	 *
	 * @param int   $post_id Post id.
	 * @param array $data    Data.
	 */
	public static function save_advanced( int $post_id, array $data ): void {
		if ( ! array_key_exists( 'index', $data ) ) {
			return;
		}
		$robots = Helpers::sanitize_robots( $data['robots'] ?? array() );
		$robots = array_diff( $robots, array( 'index', 'noindex' ) );
		$index  = sanitize_key( (string) $data['index'] );
		if ( in_array( $index, array( 'index', 'noindex' ), true ) ) {
			array_unshift( $robots, $index );
		}
		Meta::set_post( $post_id, 'robots', array_values( $robots ) );

		$advanced = array();
		foreach ( (array) ( $data['advanced_robots'] ?? array() ) as $key => $value ) {
			$key = sanitize_key( (string) $key );
			if ( '' === (string) $value ) {
				continue;
			}
			if ( 'max-image-preview' === $key && in_array( $value, array( 'large', 'standard', 'none' ), true ) ) {
				$advanced[ $key ] = $value;
			} elseif ( in_array( $key, array( 'max-snippet', 'max-video-preview' ), true ) ) {
				$advanced[ $key ] = (int) $value;
			}
		}
		Meta::set_post( $post_id, 'advanced_robots', $advanced );
		Meta::set_post( $post_id, 'canonical', esc_url_raw( trim( (string) ( $data['canonical'] ?? '' ) ) ) );
		Meta::set_post( $post_id, 'breadcrumb_title', sanitize_text_field( (string) ( $data['breadcrumb_title'] ?? '' ) ) );
		Meta::set_post( $post_id, 'pillar', ! empty( $data['pillar'] ) );
		Meta::set_post( $post_id, 'sitemap_exclude', ! empty( $data['sitemap_exclude'] ) );
		foreach ( (array) ( $data['primary'] ?? array() ) as $taxonomy => $term_id ) {
			$taxonomy = sanitize_key( (string) $taxonomy );
			if ( taxonomy_exists( $taxonomy ) ) {
				Meta::set_post( $post_id, 'primary_' . $taxonomy, absint( $term_id ) );
			}
		}
	}

	/**
	 * Block editor: sidebar panel showing the SEO score and a shortcut to the box.
	 */
	public static function block_editor_assets(): void {
		if ( ! current_user_can( 'mgseo_edit_meta' ) ) {
			return;
		}
		wp_enqueue_script(
			'mgseo-editor',
			MGSEO_URL . 'assets/editor.js',
			array( 'wp-plugins', 'wp-edit-post', 'wp-element', 'wp-data', 'wp-components', 'wp-i18n', 'wp-editor', 'wp-blocks', 'wp-block-editor', 'wp-server-side-render' ),
			MGSEO_VERSION,
			true
		);
		wp_set_script_translations( 'mgseo-editor', 'mg-seo', MGSEO_DIR . 'languages' );
	}
}
