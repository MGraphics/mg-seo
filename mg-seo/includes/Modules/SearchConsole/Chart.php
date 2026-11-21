<?php
/**
 * Inline SVG charts (no JavaScript, no external libraries).
 *
 * @package MGSEO
 */

namespace MGSEO\Modules\SearchConsole;

defined( 'ABSPATH' ) || exit;

/**
 * Renders simple line charts server-side.
 */
final class Chart {

	/**
	 * Line chart.
	 *
	 * @param array<string, float|int|null> $series date => value (null = no data).
	 * @param array<string, mixed>          $opts   label (string), invert (bool: lower is better), class (string),
	 *                                              format (callable for axis labels), width, height, sparkline (bool).
	 */
	public static function line( array $series, array $opts = array() ): string {
		$opts   = wp_parse_args(
			$opts,
			array(
				'label'     => '',
				'invert'    => false,
				'class'     => '',
				'format'    => static fn( $v ) => number_format_i18n( (float) $v ),
				'width'     => 640,
				'height'    => 160,
				'sparkline' => false,
			)
		);
		$width  = (int) $opts['width'];
		$height = (int) $opts['height'];
		$spark  = (bool) $opts['sparkline'];
		$left   = $spark ? 2 : 48;
		$right  = $spark ? 2 : 10;
		$top    = $spark ? 2 : 10;
		$bottom = $spark ? 2 : 22;
		$plot_w = max( 1, $width - $left - $right );
		$plot_h = max( 1, $height - $top - $bottom );

		$values = array_values( array_filter( $series, static fn( $v ) => null !== $v ) );
		$keys   = array_keys( $series );
		$count  = count( $series );
		$min    = $values ? (float) min( $values ) : 0.0;
		$max    = $values ? (float) max( $values ) : 0.0;
		if ( ! $opts['invert'] ) {
			$min = min( 0.0, $min );
		}
		if ( $max - $min < 0.0001 ) {
			$max = $min + 1;
		}

		$segments = array();
		$current  = array();
		$i        = 0;
		foreach ( $series as $value ) {
			if ( null === $value ) {
				if ( $current ) {
					$segments[] = $current;
				}
				$current = array();
				++$i;
				continue;
			}
			$x         = $left + ( $count > 1 ? $plot_w * $i / ( $count - 1 ) : $plot_w / 2 );
			$rel       = ( (float) $value - $min ) / ( $max - $min );
			$y         = $opts['invert'] ? $top + $plot_h * $rel : $top + $plot_h * ( 1 - $rel );
			$current[] = round( $x, 1 ) . ',' . round( $y, 1 );
			++$i;
		}
		if ( $current ) {
			$segments[] = $current;
		}

		$svg  = sprintf(
			'<svg class="mgseo-gsc-chart %1$s" viewBox="0 0 %2$d %3$d" role="img" aria-label="%4$s" xmlns="http://www.w3.org/2000/svg">',
			esc_attr( (string) $opts['class'] ),
			$width,
			$height,
			esc_attr( (string) $opts['label'] )
		);
		$svg .= '<title>' . esc_html( (string) $opts['label'] ) . '</title>';
		if ( ! $spark ) {
			$fmt  = $opts['format'];
			$hi   = $opts['invert'] ? $min : $max;
			$lo   = $opts['invert'] ? $max : $min;
			$svg .= sprintf( '<line class="mgseo-gsc-grid" x1="%1$d" y1="%2$d" x2="%3$d" y2="%2$d" />', $left, $top, $width - $right );
			$svg .= sprintf( '<line class="mgseo-gsc-grid" x1="%1$d" y1="%2$d" x2="%3$d" y2="%2$d" />', $left, $top + $plot_h, $width - $right );
			$svg .= sprintf( '<text class="mgseo-gsc-axis" x="%d" y="%d" text-anchor="end">%s</text>', $left - 6, $top + 4, esc_html( (string) $fmt( $hi ) ) );
			$svg .= sprintf( '<text class="mgseo-gsc-axis" x="%d" y="%d" text-anchor="end">%s</text>', $left - 6, $top + $plot_h + 4, esc_html( (string) $fmt( $lo ) ) );
			if ( $keys ) {
				$svg .= sprintf( '<text class="mgseo-gsc-axis" x="%d" y="%d" text-anchor="start">%s</text>', $left, $height - 4, esc_html( self::date_label( (string) reset( $keys ) ) ) );
				$svg .= sprintf( '<text class="mgseo-gsc-axis" x="%d" y="%d" text-anchor="end">%s</text>', $width - $right, $height - 4, esc_html( self::date_label( (string) end( $keys ) ) ) );
			}
		}
		foreach ( $segments as $points ) {
			if ( 1 === count( $points ) ) {
				list( $x, $y ) = explode( ',', $points[0] );
				$svg          .= sprintf( '<circle class="mgseo-gsc-dot" cx="%s" cy="%s" r="2.5" />', esc_attr( $x ), esc_attr( $y ) );
				continue;
			}
			$svg .= '<polyline class="mgseo-gsc-line" fill="none" vector-effect="non-scaling-stroke" points="' . esc_attr( implode( ' ', $points ) ) . '" />';
		}
		$svg .= '</svg>';
		return $svg;
	}

	/**
	 * Localised short date.
	 *
	 * @param string $date Y-m-d.
	 */
	private static function date_label( string $date ): string {
		$ts = strtotime( $date . ' 12:00:00 UTC' );
		return $ts ? wp_date( get_option( 'date_format', 'Y-m-d' ), $ts, new \DateTimeZone( 'UTC' ) ) : $date;
	}
}
