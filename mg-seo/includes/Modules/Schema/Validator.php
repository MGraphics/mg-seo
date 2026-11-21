<?php
/**
 * Local structured data validator.
 *
 * @package MGSEO
 */

namespace MGSEO\Modules\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Checks nodes against required / recommended properties derived from the Google Search Central
 * structured data documentation (rich result features). Runs locally: no external requests.
 *
 * Rule format per type: required (list of paths; "a|b" = any of), one_of (groups where one must exist),
 * recommended (paths), nested (property => type of nested objects to validate).
 */
final class Validator {

	/**
	 * Rules per base type.
	 *
	 * @return array<string, array<string, array>>
	 */
	public static function rules(): array {
		$rules = array(
			'Article'             => array(
				'recommended' => array( 'headline', 'image', 'datePublished', 'dateModified', 'author', 'author.name', 'author.url', 'publisher' ),
			),
			'FAQPage'             => array(
				'required' => array( 'mainEntity' ),
				'nested'   => array( 'mainEntity' => 'Question' ),
			),
			'Question'            => array(
				'required' => array( 'name', 'acceptedAnswer', 'acceptedAnswer.text' ),
			),
			'HowTo'               => array(
				'required'    => array( 'name', 'step' ),
				'recommended' => array( 'image', 'totalTime', 'estimatedCost', 'supply', 'tool', 'description' ),
				'nested'      => array( 'step' => 'HowToStep' ),
			),
			'HowToStep'           => array(
				'required'    => array( 'text|itemListElement' ),
				'recommended' => array( 'name', 'url', 'image' ),
			),
			'Product'             => array(
				'required'    => array( 'name' ),
				'one_of'      => array( array( 'offers', 'review', 'aggregateRating' ) ),
				'recommended' => array( 'image', 'description', 'sku', 'brand', 'gtin|gtin8|gtin12|gtin13|gtin14|mpn|isbn' ),
				'nested'      => array(
					'offers'          => 'Offer',
					'aggregateRating' => 'AggregateRating',
					'review'          => 'Review',
				),
			),
			'Offer'               => array(
				'required'    => array( 'price|lowPrice|priceSpecification', 'priceCurrency|priceSpecification' ),
				'recommended' => array( 'availability', 'url', 'priceValidUntil' ),
			),
			'AggregateRating'     => array(
				'required' => array( 'ratingValue', 'ratingCount|reviewCount' ),
			),
			'Rating'              => array(
				'required' => array( 'ratingValue' ),
			),
			'Review'              => array(
				'required'    => array( 'author', 'author.name', 'reviewRating', 'reviewRating.ratingValue' ),
				'recommended' => array( 'datePublished', 'reviewBody' ),
				'nested'      => array( 'reviewRating' => 'Rating' ),
			),
			'Event'               => array(
				'required'    => array( 'name', 'startDate', 'location' ),
				'recommended' => array( 'description', 'endDate', 'eventStatus', 'eventAttendanceMode', 'image', 'offers', 'organizer', 'performer' ),
				'nested'      => array(
					'location' => 'Location',
					'offers'   => 'Offer',
				),
			),
			'Location'            => array(),
			'Place'               => array(
				'required'    => array( 'address' ),
				'recommended' => array( 'name' ),
			),
			'VirtualLocation'     => array(
				'required' => array( 'url' ),
			),
			'LocalBusiness'       => array(
				'required'    => array( 'name', 'address' ),
				'recommended' => array( 'address.streetAddress', 'address.addressLocality', 'address.postalCode', 'address.addressCountry', 'telephone', 'url', 'geo', 'openingHoursSpecification', 'priceRange', 'image' ),
				'nested'      => array( 'aggregateRating' => 'AggregateRating' ),
			),
			'Organization'        => array(
				'recommended' => array( 'name', 'url', 'logo', 'sameAs', 'address|contactPoint|telephone|email' ),
			),
			'Person'              => array(
				'required'    => array( 'name' ),
				'recommended' => array( 'url', 'image', 'sameAs' ),
			),
			'Recipe'              => array(
				'required'    => array( 'name', 'image' ),
				'recommended' => array( 'author', 'datePublished', 'description', 'prepTime', 'cookTime', 'totalTime', 'keywords', 'nutrition.calories', 'recipeCategory', 'recipeCuisine', 'recipeIngredient', 'recipeInstructions', 'recipeYield', 'aggregateRating', 'video' ),
				'nested'      => array(
					'recipeInstructions' => 'HowToStep',
					'aggregateRating'    => 'AggregateRating',
				),
			),
			'VideoObject'         => array(
				'required'    => array( 'name', 'thumbnailUrl', 'uploadDate' ),
				'recommended' => array( 'description', 'contentUrl|embedUrl', 'duration' ),
			),
			'BreadcrumbList'      => array(
				'required' => array( 'itemListElement' ),
			),
			'JobPosting'          => array(
				'required'    => array( 'title', 'description', 'datePosted', 'hiringOrganization', 'hiringOrganization.name' ),
				'recommended' => array( 'validThrough', 'employmentType', 'baseSalary', 'identifier', 'directApply' ),
			),
			'SoftwareApplication' => array(
				'required'    => array( 'name', 'offers', 'offers.price' ),
				'one_of'      => array( array( 'aggregateRating', 'review' ) ),
				'recommended' => array( 'applicationCategory', 'operatingSystem' ),
				'nested'      => array( 'aggregateRating' => 'AggregateRating' ),
			),
			'Course'              => array(
				'required'    => array( 'name', 'description' ),
				'recommended' => array( 'provider', 'offers', 'hasCourseInstance' ),
			),
			'Service'             => array(
				'recommended' => array( 'name', 'serviceType', 'provider', 'areaServed', 'description' ),
			),
			'Book'                => array(
				'required'    => array( 'name' ),
				'recommended' => array( 'author', 'url', 'isbn' ),
			),
			'WebSite'             => array(
				'required' => array( 'name', 'url' ),
			),
		);
		/**
		 * Filter validator rules.
		 *
		 * @param array $rules Rules per type.
		 */
		return apply_filters( 'mgseo/schema/validator_rules', $rules );
	}

	/**
	 * Validate one node.
	 *
	 * @param array $node  Node.
	 * @param array $graph Other nodes (to resolve @id references).
	 * @return array{errors: string[], warnings: string[]}
	 */
	public static function validate( array $node, array $graph = array() ): array {
		$index = array();
		foreach ( $graph as $other ) {
			if ( is_array( $other ) && ! empty( $other['@id'] ) && is_string( $other['@id'] ) ) {
				$index[ $other['@id'] ] = $other;
			}
		}
		if ( ! empty( $node['@id'] ) && is_string( $node['@id'] ) ) {
			$index[ $node['@id'] ] = $node;
		}
		$result = array(
			'errors'   => array(),
			'warnings' => array(),
		);
		self::check( $node, self::type_of( $node ), '', $index, $result, 0 );
		$result['errors']   = array_values( array_unique( $result['errors'] ) );
		$result['warnings'] = array_values( array_unique( $result['warnings'] ) );
		return $result;
	}

	/**
	 * Validate a list of nodes.
	 *
	 * @param array $nodes Nodes.
	 * @return array<int, array{id: string, type: string, errors: string[], warnings: string[]}>
	 */
	public static function validate_all( array $nodes ): array {
		$out = array();
		foreach ( $nodes as $node ) {
			if ( ! is_array( $node ) ) {
				continue;
			}
			$type  = is_array( $node['@type'] ?? '' ) ? implode( ', ', $node['@type'] ) : (string) ( $node['@type'] ?? '' );
			$out[] = array(
				'id'   => (string) ( $node['@id'] ?? '' ),
				'type' => $type,
			) + self::validate( $node, $nodes );
		}
		return $out;
	}

	/**
	 * Rule type for a node.
	 *
	 * @param array  $node     Node.
	 * @param string $fallback Fallback type.
	 */
	private static function type_of( array $node, string $fallback = '' ): string {
		$type = $node['@type'] ?? $fallback;
		if ( '' === $type || array() === $type ) {
			return $fallback;
		}
		$base  = Types::base_type( $type );
		$rules = self::rules();
		if ( isset( $rules[ $base ] ) ) {
			return $base;
		}
		foreach ( (array) $type as $t ) {
			if ( isset( $rules[ (string) $t ] ) ) {
				return (string) $t;
			}
		}
		return $base;
	}

	/**
	 * Check a node against rules.
	 *
	 * @param array  $node   Node.
	 * @param string $type   Rule type.
	 * @param string $prefix Path prefix for messages.
	 * @param array  $index  Id index.
	 * @param array  $result Result (by reference).
	 * @param int    $depth  Recursion depth.
	 */
	private static function check( array $node, string $type, string $prefix, array $index, array &$result, int $depth ): void {
		if ( $depth > 5 ) {
			return;
		}
		$node  = self::deref( $node, $index );
		$rules = self::rules()[ $type ] ?? array();
		if ( 'Location' === $type ) {
			$type  = self::type_of( $node, 'Place' );
			$rules = self::rules()[ $type ] ?? array();
		}

		foreach ( $rules['required'] ?? array() as $path ) {
			if ( ! self::has( $node, $path, $index ) ) {
				/* translators: %s: property path */
				$result['errors'][] = sprintf( __( 'Missing required property: %s', 'mg-seo' ), self::label( $prefix, $path ) );
			}
		}
		foreach ( $rules['one_of'] ?? array() as $group ) {
			$ok = false;
			foreach ( $group as $path ) {
				$ok = $ok || self::has( $node, $path, $index );
			}
			if ( ! $ok ) {
				$result['errors'][] = sprintf(
					/* translators: %s: list of property names */
					__( 'One of these properties is required: %s', 'mg-seo' ),
					implode( ', ', array_map( static fn( $p ) => self::label( $prefix, $p ), $group ) )
				);
			}
		}
		foreach ( $rules['recommended'] ?? array() as $path ) {
			if ( ! self::has( $node, $path, $index ) ) {
				/* translators: %s: property path */
				$result['warnings'][] = sprintf( __( 'Missing recommended property: %s', 'mg-seo' ), self::label( $prefix, $path ) );
			}
		}

		self::check_values( $node, $type, $prefix, $result );

		foreach ( $rules['nested'] ?? array() as $key => $nested_type ) {
			if ( ! isset( $node[ $key ] ) || ! is_array( $node[ $key ] ) ) {
				continue;
			}
			$items = array_is_list( $node[ $key ] ) ? $node[ $key ] : array( $node[ $key ] );
			foreach ( $items as $i => $item ) {
				if ( ! is_array( $item ) ) {
					continue;
				}
				$item = self::deref( $item, $index );
				$sub  = 'Location' === $nested_type ? 'Location' : self::type_of( $item, $nested_type );
				if ( ! isset( self::rules()[ $sub ] ) ) {
					$sub = $nested_type;
				}
				$path = ( '' !== $prefix ? $prefix . '.' : '' ) . $key . ( count( $items ) > 1 ? '[' . ( $i + 1 ) . ']' : '' );
				self::check( $item, $sub, $path, $index, $result, $depth + 1 );
			}
		}
	}

	/**
	 * Value checks: dates, durations, URLs, ratings, BreadcrumbList items, event dates.
	 *
	 * @param array  $node   Node.
	 * @param string $type   Type.
	 * @param string $prefix Prefix.
	 * @param array  $result Result.
	 */
	private static function check_values( array $node, string $type, string $prefix, array &$result ): void {
		foreach ( array( 'datePublished', 'dateModified', 'startDate', 'endDate', 'uploadDate', 'datePosted', 'validThrough', 'priceValidUntil' ) as $key ) {
			if ( isset( $node[ $key ] ) && is_string( $node[ $key ] ) && ! self::is_iso_date( $node[ $key ] ) ) {
				/* translators: %s: property path */
				$result['errors'][] = sprintf( __( 'Invalid date (use ISO 8601): %s', 'mg-seo' ), self::label( $prefix, $key ) );
			}
		}
		foreach ( array( 'totalTime', 'prepTime', 'cookTime', 'duration' ) as $key ) {
			if ( isset( $node[ $key ] ) && is_string( $node[ $key ] ) && ! preg_match( '/^P(?=\d|T\d)(\d+Y)?(\d+M)?(\d+W)?(\d+D)?(T(?=\d)(\d+H)?(\d+M)?(\d+(?:\.\d+)?S)?)?$/', $node[ $key ] ) ) {
				/* translators: %s: property path */
				$result['errors'][] = sprintf( __( 'Invalid duration (use ISO 8601, e.g. PT1H30M): %s', 'mg-seo' ), self::label( $prefix, $key ) );
			}
		}
		foreach ( array( 'url', 'thumbnailUrl', 'contentUrl', 'embedUrl', 'logo' ) as $key ) {
			$value = $node[ $key ] ?? null;
			if ( is_string( $value ) && '' !== $value && ! preg_match( '#^https?://#i', $value ) ) {
				/* translators: %s: property path */
				$result['errors'][] = sprintf( __( 'URL must be absolute: %s', 'mg-seo' ), self::label( $prefix, $key ) );
			}
		}
		if ( in_array( $type, array( 'Rating', 'AggregateRating' ), true ) && isset( $node['ratingValue'] ) ) {
			$value = $node['ratingValue'];
			$best  = isset( $node['bestRating'] ) && is_numeric( $node['bestRating'] ) ? (float) $node['bestRating'] : 5.0;
			$worst = isset( $node['worstRating'] ) && is_numeric( $node['worstRating'] ) ? (float) $node['worstRating'] : 1.0;
			if ( ! is_numeric( $value ) ) {
				/* translators: %s: property path */
				$result['errors'][] = sprintf( __( 'Rating must be a number: %s', 'mg-seo' ), self::label( $prefix, 'ratingValue' ) );
			} elseif ( (float) $value > $best || (float) $value < $worst ) {
				/* translators: %s: property path */
				$result['errors'][] = sprintf( __( 'Rating is outside the bestRating / worstRating range: %s', 'mg-seo' ), self::label( $prefix, 'ratingValue' ) );
			}
		}
		if ( 'Offer' === $type && isset( $node['price'] ) && ! is_numeric( $node['price'] ) ) {
			/* translators: %s: property path */
			$result['errors'][] = sprintf( __( 'Price must be a number without currency symbols: %s', 'mg-seo' ), self::label( $prefix, 'price' ) );
		}
		if ( 'Offer' === $type && isset( $node['priceCurrency'] ) && is_string( $node['priceCurrency'] ) && ! preg_match( '/^[A-Z]{3}$/', $node['priceCurrency'] ) ) {
			/* translators: %s: property path */
			$result['errors'][] = sprintf( __( 'Currency must be a 3-letter ISO 4217 code: %s', 'mg-seo' ), self::label( $prefix, 'priceCurrency' ) );
		}
		if ( 'Event' === $type && ! empty( $node['startDate'] ) && ! empty( $node['endDate'] ) && is_string( $node['startDate'] ) && is_string( $node['endDate'] ) ) {
			if ( strtotime( $node['endDate'] ) < strtotime( $node['startDate'] ) ) {
				$result['errors'][] = __( 'The event ends before it starts (endDate is earlier than startDate).', 'mg-seo' );
			}
		}
		if ( 'BreadcrumbList' === $type && isset( $node['itemListElement'] ) && is_array( $node['itemListElement'] ) ) {
			$items = $node['itemListElement'];
			if ( count( $items ) < 2 ) {
				$result['warnings'][] = __( 'A breadcrumb trail should have at least two items.', 'mg-seo' );
			}
			$last = count( $items ) - 1;
			foreach ( array_values( $items ) as $i => $item ) {
				if ( ! is_array( $item ) || ! isset( $item['position'], $item['name'] ) ) {
					/* translators: %d: item number */
					$result['errors'][] = sprintf( __( 'Breadcrumb item %d needs position and name.', 'mg-seo' ), $i + 1 );
				} elseif ( $i !== $last && empty( $item['item'] ) ) {
					/* translators: %d: item number */
					$result['errors'][] = sprintf( __( 'Breadcrumb item %d needs an item URL.', 'mg-seo' ), $i + 1 );
				}
			}
		}
		if ( 'FAQPage' === $type && isset( $node['mainEntity'] ) && is_array( $node['mainEntity'] ) && ! array_is_list( $node['mainEntity'] ) ) {
			$result['warnings'][] = __( 'FAQPage should list its questions in mainEntity.', 'mg-seo' );
		}
		if ( 'JobPosting' === $type ) {
			$remote = isset( $node['jobLocationType'] ) && 'TELECOMMUTE' === $node['jobLocationType'];
			if ( ! $remote && empty( $node['jobLocation'] ) ) {
				/* translators: %s: property path */
				$result['errors'][] = sprintf( __( 'Missing required property: %s', 'mg-seo' ), self::label( $prefix, 'jobLocation' ) );
			}
			if ( $remote && empty( $node['applicantLocationRequirements'] ) ) {
				/* translators: %s: property path */
				$result['errors'][] = sprintf( __( 'Missing required property: %s', 'mg-seo' ), self::label( $prefix, 'applicantLocationRequirements' ) );
			}
		}
	}

	/**
	 * Resolve a reference node.
	 *
	 * @param array $node  Node.
	 * @param array $index Id index.
	 * @return array
	 */
	private static function deref( array $node, array $index ): array {
		if ( isset( $node['@id'] ) && is_string( $node['@id'] ) && 1 === count( $node ) && isset( $index[ $node['@id'] ] ) ) {
			return $index[ $node['@id'] ];
		}
		return $node;
	}

	/**
	 * Whether a (dot / any-of) path has a value.
	 *
	 * @param array  $node  Node.
	 * @param string $path  Path ("a.b", "a|b").
	 * @param array  $index Id index.
	 */
	private static function has( array $node, string $path, array $index ): bool {
		foreach ( explode( '|', $path ) as $alternative ) {
			if ( self::has_path( $node, explode( '.', $alternative ), $index ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Path lookup (arrays: any item may satisfy the path).
	 *
	 * @param mixed $value Value.
	 * @param array $keys  Remaining keys.
	 * @param array $index Id index.
	 */
	private static function has_path( mixed $value, array $keys, array $index ): bool {
		if ( is_array( $value ) ) {
			$value = self::deref( $value, $index );
		}
		if ( ! $keys ) {
			return ! ( null === $value || '' === $value || array() === $value );
		}
		if ( ! is_array( $value ) ) {
			return false;
		}
		if ( array_is_list( $value ) ) {
			foreach ( $value as $item ) {
				if ( self::has_path( $item, $keys, $index ) ) {
					return true;
				}
			}
			return false;
		}
		$key = array_shift( $keys );
		return array_key_exists( $key, $value ) && self::has_path( $value[ $key ], $keys, $index );
	}

	/**
	 * Message label for a path.
	 *
	 * @param string $prefix Prefix.
	 * @param string $path   Path.
	 */
	private static function label( string $prefix, string $path ): string {
		$path = str_replace( '|', ' / ', $path );
		return '' !== $prefix ? $prefix . '.' . $path : $path;
	}

	/**
	 * Whether a string is an ISO 8601 date or date-time.
	 *
	 * @param string $value Value.
	 */
	public static function is_iso_date( string $value ): bool {
		return (bool) preg_match( '/^\d{4}-\d{2}-\d{2}(T\d{2}:\d{2}(:\d{2}(\.\d+)?)?(Z|[+-]\d{2}:?\d{2})?)?$/', $value );
	}
}
