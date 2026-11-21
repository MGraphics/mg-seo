<?php
/**
 * Image SEO module.
 *
 * @package MGSEO
 */

namespace MGSEO\Modules\Image;

use MGSEO\Module as Base;
use MGSEO\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Automatic alt/title attributes, alt on upload, find & replace and a missing alt report.
 */
final class Module extends Base {

	public const PAGE = 'mg-seo-image-tools';

	/**
	 * Module id.
	 */
	public function id(): string {
		return 'image';
	}

	/**
	 * Register hooks.
	 */
	public function boot(): void {
		add_filter( 'the_content', array( Attributes::class, 'filter_content' ), 20 );
		add_filter( 'widget_block_content', array( Attributes::class, 'filter_content' ), 20 );
		add_filter( 'wp_get_attachment_image_attributes', array( Attributes::class, 'filter_attributes' ), 20, 2 );
		add_action( 'add_attachment', array( Attributes::class, 'on_upload' ), 20 );
		add_action( 'mgseo/rest_routes', array( Tools::class, 'routes' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
	}

	/**
	 * Settings schema.
	 */
	public function settings(): array {
		$cases = array(
			'none'     => __( 'Keep as is', 'mg-seo' ),
			'sentence' => __( 'Sentence case', 'mg-seo' ),
			'title'    => __( 'Title Case', 'mg-seo' ),
			'lower'    => __( 'lowercase', 'mg-seo' ),
			'upper'    => __( 'UPPERCASE', 'mg-seo' ),
		);
		/* translators: %s: list of image variables */
		$vars_desc = sprintf( __( 'Image variables: %s. Other variables describe the post that shows the image.', 'mg-seo' ), '%filename%, %imagetitle%, %imagealt%, %imagecaption%' );
		return array(
			array(
				'id'          => 'general',
				'title'       => __( 'Image SEO', 'mg-seo' ),
				'description' => __( 'Attributes are added when the page is rendered; the media library is not changed (except by the upload options and the tools).', 'mg-seo' ),
				'fields'      => array(
					array(
						'id'           => 'add_alt',
						'type'         => 'toggle',
						'label'        => __( 'Missing alt', 'mg-seo' ),
						'toggle_label' => __( 'Add missing alt attributes', 'mg-seo' ),
						'default'      => true,
					),
					array(
						'id'        => 'alt_template',
						'type'      => 'text',
						'label'     => __( 'Alt template', 'mg-seo' ),
						'default'   => '%filename%',
						'variables' => true,
						'desc'      => $vars_desc,
					),
					array(
						'id'           => 'overwrite_alt',
						'type'         => 'toggle',
						'label'        => __( 'Existing alt', 'mg-seo' ),
						'toggle_label' => __( 'Replace existing alt attributes with the template too', 'mg-seo' ),
						'default'      => false,
					),
					array(
						'id'           => 'add_title',
						'type'         => 'toggle',
						'label'        => __( 'Missing title', 'mg-seo' ),
						'toggle_label' => __( 'Add missing title attributes', 'mg-seo' ),
						'default'      => false,
					),
					array(
						'id'        => 'title_template',
						'type'      => 'text',
						'label'     => __( 'Title template', 'mg-seo' ),
						'default'   => '%imagetitle%',
						'variables' => true,
					),
					array(
						'id'           => 'overwrite_title',
						'type'         => 'toggle',
						'label'        => __( 'Existing title', 'mg-seo' ),
						'toggle_label' => __( 'Replace existing title attributes with the template too', 'mg-seo' ),
						'default'      => false,
					),
					array(
						'id'      => 'alt_case',
						'type'    => 'select',
						'label'   => __( 'Alt text case', 'mg-seo' ),
						'options' => $cases,
						'default' => 'none',
					),
					array(
						'id'      => 'title_case',
						'type'    => 'select',
						'label'   => __( 'Title case', 'mg-seo' ),
						'options' => $cases,
						'default' => 'none',
					),
					array(
						'id'           => 'case_existing',
						'type'         => 'toggle',
						'label'        => __( 'Case of existing values', 'mg-seo' ),
						'toggle_label' => __( 'Apply the case change to existing alt/title attributes as well', 'mg-seo' ),
						'default'      => false,
					),
					array(
						'id'      => 'targets',
						'type'    => 'multicheck',
						'label'   => __( 'Apply to', 'mg-seo' ),
						'options' => array(
							'content'     => __( 'Images in post content', 'mg-seo' ),
							'attachment'  => __( 'Featured images and other theme images', 'mg-seo' ),
							'woocommerce' => __( 'WooCommerce product images', 'mg-seo' ),
						),
						'default' => array( 'content', 'attachment', 'woocommerce' ),
					),
					array(
						'id'    => 'upload_heading',
						'type'  => 'heading',
						'label' => __( 'On upload', 'mg-seo' ),
					),
					array(
						'id'           => 'upload_alt',
						'type'         => 'toggle',
						'label'        => __( 'Alt text on upload', 'mg-seo' ),
						'toggle_label' => __( 'Save alt text for new images from the template', 'mg-seo' ),
						'default'      => true,
					),
					array(
						'id'        => 'upload_alt_template',
						'type'      => 'text',
						'label'     => __( 'Upload alt template', 'mg-seo' ),
						'default'   => '%filename%',
						'variables' => true,
					),
					array(
						'id'           => 'upload_title',
						'type'         => 'toggle',
						'label'        => __( 'Readable title on upload', 'mg-seo' ),
						'toggle_label' => __( 'Turn "my-photo_02.jpg" into the title "My photo 02"', 'mg-seo' ),
						'default'      => true,
					),
				),
			),
		);
	}

	/**
	 * Tools page.
	 */
	public function admin_pages(): array {
		return array(
			array(
				'slug'       => self::PAGE,
				'title'      => __( 'Image SEO tools', 'mg-seo' ),
				'menu_title' => __( 'Image SEO tools', 'mg-seo' ),
				'capability' => 'mgseo_bulk_edit',
				'callback'   => array( $this, 'render_tools' ),
				'position'   => 55,
			),
		);
	}

	/**
	 * Remove module data.
	 */
	public function uninstall(): void {
		$all = Options::all();
		unset( $all['image'] );
		Options::save( $all );
	}

	/**
	 * Assets on the tools page.
	 *
	 * @param string $hook Hook suffix.
	 */
	public function assets( $hook ): void {
		if ( ! is_string( $hook ) || ! str_contains( $hook, self::PAGE ) ) {
			return;
		}
		wp_enqueue_style( 'mgseo-image', MGSEO_URL . 'assets/image.css', array(), MGSEO_VERSION );
		wp_enqueue_script( 'mgseo-image', MGSEO_URL . 'assets/image.js', array( 'wp-api-fetch', 'wp-i18n' ), MGSEO_VERSION, true );
		wp_set_script_translations( 'mgseo-image', 'mg-seo', MGSEO_DIR . 'languages' );
		wp_localize_script(
			'mgseo-image',
			'mgseoImage',
			array(
				'path' => '/' . \MGSEO\Rest::NAMESPACE . '/image/',
			)
		);
	}

	/**
	 * Render the tools page.
	 */
	public function render_tools(): void {
		if ( ! current_user_can( 'mgseo_bulk_edit' ) || ! current_user_can( 'upload_files' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'mg-seo' ) );
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- pagination only.
		$paged    = isset( $_GET['paged'] ) ? max( 1, absint( wp_unslash( $_GET['paged'] ) ) ) : 1;
		$per_page = 20;
		$total    = Tools::missing_count();
		$ids      = Tools::missing( $paged, $per_page );
		_prime_post_caches( $ids, false, true );
		$labels = array(
			'alt'         => __( 'Alt text', 'mg-seo' ),
			'title'       => __( 'Title', 'mg-seo' ),
			'caption'     => __( 'Caption', 'mg-seo' ),
			'description' => __( 'Description', 'mg-seo' ),
		);
		?>
		<div class="wrap mgseo-wrap mgseo-image-tools">
			<h1><?php esc_html_e( 'Image SEO tools', 'mg-seo' ); ?></h1>

			<h2><?php esc_html_e( 'Find & replace in the media library', 'mg-seo' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Changes are written to the media library. Preview first; replacing runs in batches of 50 images.', 'mg-seo' ); ?></p>
			<form id="mgseo-image-replace" class="mgseo-image-form">
				<p>
					<label><?php esc_html_e( 'Find', 'mg-seo' ); ?> <input type="text" name="find" class="regular-text" required /></label>
					<label><?php esc_html_e( 'Replace with', 'mg-seo' ); ?> <input type="text" name="replace" class="regular-text" /></label>
				</p>
				<fieldset>
					<?php foreach ( $labels as $field => $label ) : ?>
						<label><input type="checkbox" name="fields" value="<?php echo esc_attr( $field ); ?>" <?php checked( 'alt' === $field ); ?> /> <?php echo esc_html( $label ); ?></label>
					<?php endforeach; ?>
					<label><input type="checkbox" name="case_sensitive" value="1" /> <?php esc_html_e( 'Case sensitive', 'mg-seo' ); ?></label>
				</fieldset>
				<p>
					<button type="button" class="button" data-action="preview"><?php esc_html_e( 'Preview', 'mg-seo' ); ?></button>
					<button type="button" class="button button-primary" data-action="replace"><?php esc_html_e( 'Replace', 'mg-seo' ); ?></button>
					<span class="mgseo-image-status" aria-live="polite"></span>
				</p>
				<table class="widefat striped mgseo-image-preview" hidden>
					<thead><tr><th><?php esc_html_e( 'Image', 'mg-seo' ); ?></th><th><?php esc_html_e( 'Field', 'mg-seo' ); ?></th><th><?php esc_html_e( 'Before', 'mg-seo' ); ?></th><th><?php esc_html_e( 'After', 'mg-seo' ); ?></th></tr></thead>
					<tbody></tbody>
				</table>
			</form>

			<h2>
				<?php
				/* translators: %d: number of images */
				echo esc_html( sprintf( _n( 'Images without alt text (%d)', 'Images without alt text (%d)', $total, 'mg-seo' ), $total ) );
				?>
			</h2>
			<?php if ( $total ) : ?>
				<p>
					<button type="button" class="button" id="mgseo-image-fill" data-confirm="<?php esc_attr_e( 'Save alt text from the upload template for all images without alt text?', 'mg-seo' ); ?>"><?php esc_html_e( 'Fill all from the upload template', 'mg-seo' ); ?></button>
					<span class="mgseo-image-fill-status" aria-live="polite"></span>
				</p>
				<table class="widefat striped mgseo-missing-alt">
					<thead><tr><th><?php esc_html_e( 'Image', 'mg-seo' ); ?></th><th><?php esc_html_e( 'File', 'mg-seo' ); ?></th><th><?php esc_html_e( 'Used in', 'mg-seo' ); ?></th><th><?php esc_html_e( 'Alt text', 'mg-seo' ); ?></th></tr></thead>
					<tbody>
					<?php
					foreach ( $ids as $id ) :
						$attachment = get_post( $id );
						if ( ! $attachment ) {
							continue;
						}
						$parent     = $attachment->post_parent ? get_post( $attachment->post_parent ) : null;
						$suggestion = Attributes::upload_alt( $attachment );
						?>
						<tr data-id="<?php echo esc_attr( (string) $id ); ?>">
							<td><?php echo wp_get_attachment_image( $id, array( 60, 60 ), false, array( 'alt' => '' ) ); ?></td>
							<td><a href="<?php echo esc_url( (string) get_edit_post_link( $id ) ); ?>"><?php echo esc_html( wp_basename( (string) get_attached_file( $id ) ) ); ?></a><br /><span class="description"><?php echo esc_html( $attachment->post_title ); ?></span></td>
							<td><?php echo $parent ? '<a href="' . esc_url( (string) get_edit_post_link( $parent->ID ) ) . '">' . esc_html( get_the_title( $parent ) ) . '</a>' : '—'; ?></td>
							<td>
								<input type="text" class="regular-text" value="" placeholder="<?php echo esc_attr( $suggestion ); ?>" aria-label="<?php esc_attr_e( 'Alt text', 'mg-seo' ); ?>" />
								<button type="button" class="button mgseo-save-alt"><?php esc_html_e( 'Save', 'mg-seo' ); ?></button>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				<?php
				$pages = (int) ceil( $total / $per_page );
				if ( $pages > 1 ) {
					echo '<div class="tablenav"><div class="tablenav-pages">';
					echo wp_kses_post(
						(string) paginate_links(
							array(
								'base'    => add_query_arg( 'paged', '%#%' ),
								'format'  => '',
								'current' => $paged,
								'total'   => $pages,
							)
						)
					);
					echo '</div></div>';
				}
				?>
			<?php else : ?>
				<p><?php esc_html_e( 'Every image in the media library has alt text.', 'mg-seo' ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}
}
