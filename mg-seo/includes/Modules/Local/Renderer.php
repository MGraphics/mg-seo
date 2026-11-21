<?php
/**
 * Front-end HTML for business data (shortcode and block).
 *
 * @package MGSEO
 */

namespace MGSEO\Modules\Local;

use MGSEO\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Renders address, contact, opening hours, company ids and map with generic semantic markup.
 */
final class Renderer {

	public const PARTS = array( 'name', 'address', 'contact', 'hours', 'ids', 'social', 'map' );

	/**
	 * Shortcode / block entry point.
	 *
	 * Attributes: show (comma list of parts or "all"), location (id, comma list of ids, "all" or empty for the
	 * current location / global business), map (none|link|embed, default from settings), class, heading (h2-h6).
	 *
	 * @param array|string $atts Attributes.
	 */
	public static function shortcode( $atts = array() ): string {
		$atts  = shortcode_atts(
			array(
				'show'     => 'all',
				'location' => '',
				'map'      => '',
				'class'    => 'contact-info',
				'heading'  => 'h3',
			),
			is_array( $atts ) ? $atts : array(),
			'mgseo_local'
		);
		$parts = self::parts( (string) $atts['show'] );
		$items = self::resolve( (string) $atts['location'] );
		if ( ! $items ) {
			return '';
		}
		$args = array(
			'parts'   => $parts,
			'map'     => in_array( $atts['map'], array( 'none', 'link', 'embed' ), true ) ? $atts['map'] : (string) Options::get( 'local', 'map_mode', 'link' ),
			'class'   => (string) $atts['class'],
			'heading' => in_array( $atts['heading'], array( 'h2', 'h3', 'h4', 'h5', 'h6', 'p' ), true ) ? $atts['heading'] : 'h3',
			'link'    => count( $items ) > 1,
		);
		$html = '';
		foreach ( $items as $data ) {
			$html .= self::render( $data, $args );
		}
		return $html;
	}

	/**
	 * Requested parts.
	 *
	 * @param string $show Comma list.
	 * @return string[]
	 */
	public static function parts( string $show ): array {
		$show = array_filter( array_map( 'trim', explode( ',', strtolower( $show ) ) ) );
		// Accept aliases used by other plugins' shortcodes.
		$aliases = array(
			'phone'   => 'contact',
			'email'   => 'contact',
			'opening' => 'hours',
			'company' => 'ids',
			'vat'     => 'ids',
		);
		$parts   = array();
		foreach ( $show as $part ) {
			if ( 'all' === $part ) {
				return self::PARTS;
			}
			$part = $aliases[ $part ] ?? $part;
			if ( in_array( $part, self::PARTS, true ) ) {
				$parts[] = $part;
			}
		}
		return $parts ? array_values( array_unique( $parts ) ) : self::PARTS;
	}

	/**
	 * Business data sets to render.
	 *
	 * @param string $location Location attribute.
	 * @return array<int, array<string, mixed>>
	 */
	public static function resolve( string $location ): array {
		$location = trim( $location );
		if ( Locations::enabled() && '' !== $location ) {
			$ids   = 'all' === $location ? array() : array_filter( array_map( 'absint', explode( ',', $location ) ) );
			$posts = ( 'all' === $location || $ids ) ? Locations::all( $ids ) : array();
			return array_map( array( Business::class, 'from_post' ), $posts );
		}
		if ( '' === $location && Locations::enabled() && is_singular( Locations::POST_TYPE ) ) {
			$post = get_queried_object();
			if ( $post instanceof \WP_Post ) {
				return array( Business::from_post( $post ) );
			}
		}
		return array( Business::from_options() );
	}

	/**
	 * Render one business.
	 *
	 * @param array<string, mixed> $data Business data.
	 * @param array<string, mixed> $args parts, map, class, heading, link.
	 */
	public static function render( array $data, array $args ): string {
		$data  = Business::normalize( $data );
		$parts = (array) ( $args['parts'] ?? self::PARTS );
		$out   = '';

		if ( in_array( 'name', $parts, true ) && '' !== (string) $data['name'] ) {
			$tag  = (string) ( $args['heading'] ?? 'h3' );
			$name = esc_html( (string) $data['name'] );
			if ( ! empty( $args['link'] ) && ! empty( $data['post_id'] ) ) {
				$name = '<a href="' . esc_url( (string) get_permalink( (int) $data['post_id'] ) ) . '">' . $name . '</a>';
			}
			$out .= '<' . $tag . '>' . $name . '</' . $tag . '>';
		}

		$address = in_array( 'address', $parts, true ) ? self::address_lines( $data ) : array();
		$contact = in_array( 'contact', $parts, true ) ? self::contact_lines( $data ) : array();
		if ( $address || $contact ) {
			$out .= '<address>';
			if ( $address ) {
				$out .= '<span>' . implode( '<br />', $address ) . '</span>';
			}
			if ( $address && $contact ) {
				$out .= '<br />';
			}
			$out .= implode( '<br />', $contact );
			$out .= '</address>';
		}
		if ( in_array( 'hours', $parts, true ) ) {
			$out .= self::hours_table( $data );
		}
		if ( in_array( 'ids', $parts, true ) ) {
			$out .= self::ids( $data );
		}
		if ( in_array( 'social', $parts, true ) ) {
			$out .= self::social( $data );
		}
		if ( in_array( 'map', $parts, true ) ) {
			$out .= self::map( $data, (string) ( $args['map'] ?? 'link' ) );
		}
		if ( '' === $out ) {
			return '';
		}
		$class = trim( (string) ( $args['class'] ?? '' ) );
		return '<div' . ( '' !== $class ? ' class="' . esc_attr( $class ) . '"' : '' ) . '>' . $out . '</div>';
	}

	/**
	 * Escaped address lines.
	 *
	 * @param array<string, mixed> $data Data.
	 * @return string[]
	 */
	private static function address_lines( array $data ): array {
		$lines = array(
			esc_html( (string) $data['street'] ),
			esc_html( trim( $data['postal_code'] . ' ' . $data['locality'] ) ),
			esc_html( (string) $data['region'] ),
		);
		return array_values( array_filter( $lines, static fn( $l ) => '' !== $l ) );
	}

	/**
	 * Escaped contact lines (phones, email, website).
	 *
	 * @param array<string, mixed> $data Data.
	 * @return string[]
	 */
	private static function contact_lines( array $data ): array {
		$lines = array();
		$types = Business::contact_types();
		foreach ( Business::phones( $data ) as $i => $phone ) {
			$tel     = preg_replace( '/[^0-9+]/', '', $phone['number'] );
			$label   = $i > 0 && isset( $types[ $phone['type'] ] ) ? esc_html( $types[ $phone['type'] ] ) . ': ' : '';
			$lines[] = $label . '<a href="' . esc_url( 'tel:' . $tel, array( 'tel' ) ) . '">' . esc_html( $phone['number'] ) . '</a>';
		}
		if ( '' !== (string) $data['email'] ) {
			$email   = antispambot( (string) $data['email'] );
			$lines[] = '<a href="' . esc_url( 'mailto:' . $email, array( 'mailto' ) ) . '">' . esc_html( $email ) . '</a>';
		}
		return $lines;
	}

	/**
	 * Opening hours table.
	 *
	 * @param array<string, mixed> $data Data.
	 */
	public static function hours_table( array $data ): string {
		$hours = (array) $data['hours'];
		$rows  = '';
		$today = Business::DAYS[ (int) wp_date( 'N' ) - 1 ] ?? '';
		$any   = false;
		foreach ( Admin::day_labels() as $day => $label ) {
			$row  = isset( $hours[ $day ] ) && is_array( $hours[ $day ] ) ? $hours[ $day ] : array();
			$mode = (string) ( $row['mode'] ?? '' );
			if ( '' === $mode ) {
				continue;
			}
			$any = true;
			if ( 'closed' === $mode ) {
				$text = esc_html__( 'Closed', 'mg-seo' );
			} elseif ( '24h' === $mode ) {
				$text = esc_html__( 'Open 24 hours', 'mg-seo' );
			} else {
				$ranges = array_map(
					static fn( $r ) => esc_html( self::format_time( $r[0] ) . '–' . self::format_time( $r[1] ) ),
					Business::parse_ranges( (string) ( $row['ranges'] ?? '' ) )
				);
				$text   = $ranges ? implode( ', ', $ranges ) : esc_html__( 'Closed', 'mg-seo' );
			}
			$current = $day === $today ? ' aria-current="date"' : '';
			$rows   .= '<tr' . $current . '><th scope="row">' . esc_html( $label ) . '</th><td>' . $text . '</td></tr>';
		}
		foreach ( Business::parse_special( (string) $data['special_hours'] ) as $special ) {
			if ( $special['to'] < wp_date( 'Y-m-d' ) ) {
				continue;
			}
			$any   = true;
			$date  = $special['from'] === $special['to'] ? self::format_date( $special['from'] ) : self::format_date( $special['from'] ) . ' – ' . self::format_date( $special['to'] );
			$text  = $special['closed'] ? esc_html__( 'Closed', 'mg-seo' ) : implode( ', ', array_map( static fn( $r ) => esc_html( self::format_time( $r[0] ) . '–' . self::format_time( $r[1] ) ), $special['ranges'] ) );
			$rows .= '<tr><th scope="row">' . esc_html( $date ) . '</th><td>' . $text . '</td></tr>';
		}
		if ( ! $any ) {
			return '';
		}
		return '<table><caption>' . esc_html__( 'Opening hours', 'mg-seo' ) . '</caption><tbody>' . $rows . '</tbody></table>';
	}

	/**
	 * Time in the site's time format.
	 *
	 * @param string $time HH:MM.
	 */
	private static function format_time( string $time ): string {
		$format = (string) Options::get( 'local', 'time_format', 'H:i' );
		if ( 'H:i' === $format ) {
			return '23:59' === $time ? '24:00' : $time;
		}
		$ts = strtotime( '1970-01-01 ' . $time . ':00 UTC' );
		return false === $ts ? $time : gmdate( $format, $ts );
	}

	/**
	 * Date in the site's date format.
	 *
	 * @param string $date Y-m-d.
	 */
	private static function format_date( string $date ): string {
		$ts = strtotime( $date . ' 12:00:00 UTC' );
		return false === $ts ? $date : (string) wp_date( (string) get_option( 'date_format' ), $ts, new \DateTimeZone( 'UTC' ) );
	}

	/**
	 * Company identifiers.
	 *
	 * @param array<string, mixed> $data Data.
	 */
	private static function ids( array $data ): string {
		$labels = array(
			'tax_id' => __( 'NIP', 'mg-seo' ),
			'vat_id' => __( 'VAT ID', 'mg-seo' ),
			'regon'  => __( 'REGON', 'mg-seo' ),
			'krs'    => __( 'KRS', 'mg-seo' ),
		);
		$lines  = array();
		foreach ( $labels as $key => $label ) {
			if ( '' !== trim( (string) $data[ $key ] ) ) {
				$lines[] = esc_html( $label . ': ' . trim( (string) $data[ $key ] ) );
			}
		}
		return $lines ? '<p>' . implode( '<br />', $lines ) . '</p>' : '';
	}

	/**
	 * Social profile links.
	 *
	 * @param array<string, mixed> $data Data.
	 */
	private static function social( array $data ): string {
		$items = '';
		foreach ( Business::lines( (string) $data['social'] ) as $url ) {
			$host   = (string) wp_parse_url( $url, PHP_URL_HOST );
			$label  = ucfirst( (string) preg_replace( '/^(www\.|m\.)/', '', $host ) );
			$label  = (string) preg_replace( '/\.[a-z.]+$/', '', $label );
			$items .= '<li><a href="' . esc_url( $url ) . '" rel="me noopener">' . esc_html( '' !== $label ? $label : $url ) . '</a></li>';
		}
		return '' !== $items ? '<ul>' . $items . '</ul>' : '';
	}

	/**
	 * Map link or embed.
	 *
	 * @param array<string, mixed> $data Data.
	 * @param string               $mode none|link|embed.
	 */
	public static function map( array $data, string $mode ): string {
		if ( 'none' === $mode || ( ! Business::has_address( $data ) && ( '' === (string) $data['lat'] || '' === (string) $data['lng'] ) ) ) {
			return '';
		}
		$provider = (string) Options::get( 'local', 'map_provider', 'google' );
		$link     = Business::map_link( $data, $provider );
		// Embeds load third-party content, so they are only printed when the administrator enabled them.
		if ( 'embed' === $mode && Options::get( 'local', 'map_embed_allowed', false ) ) {
			$src   = self::embed_url( $data, $provider );
			$title = sprintf(
				/* translators: %s: business name */
				__( 'Map: %s', 'mg-seo' ),
				(string) $data['name']
			);
			$height = max( 150, (int) Options::get( 'local', 'map_height', 300 ) );
			return '<p><iframe src="' . esc_url( $src ) . '" title="' . esc_attr( $title ) . '" width="100%" height="' . esc_attr( (string) $height ) . '" style="border:0" loading="lazy" referrerpolicy="no-referrer-when-downgrade" allowfullscreen></iframe><br /><a href="' . esc_url( $link ) . '" rel="noopener" target="_blank">' . esc_html__( 'Open larger map', 'mg-seo' ) . '</a></p>';
		}
		return '<p><a href="' . esc_url( $link ) . '" rel="noopener" target="_blank">' . esc_html__( 'Show on map', 'mg-seo' ) . '</a></p>';
	}

	/**
	 * Iframe URL.
	 *
	 * @param array<string, mixed> $data     Data.
	 * @param string               $provider google|osm.
	 */
	private static function embed_url( array $data, string $provider ): string {
		$has_geo = '' !== (string) $data['lat'] && '' !== (string) $data['lng'];
		$zoom    = min( 20, max( 3, (int) Options::get( 'local', 'map_zoom', 15 ) ) );
		if ( 'osm' === $provider && $has_geo ) {
			$lat   = (float) $data['lat'];
			$lng   = (float) $data['lng'];
			$delta = 360 / pow( 2, $zoom );
			$bbox  = implode( ',', array( $lng - $delta, $lat - $delta / 2, $lng + $delta, $lat + $delta / 2 ) );
			return 'https://www.openstreetmap.org/export/embed.html?bbox=' . rawurlencode( $bbox ) . '&layer=mapnik&marker=' . rawurlencode( $lat . ',' . $lng );
		}
		$query = $has_geo ? $data['lat'] . ',' . $data['lng'] : Business::address_line( $data );
		return 'https://maps.google.com/maps?q=' . rawurlencode( (string) $query ) . '&z=' . $zoom . '&output=embed';
	}
}
