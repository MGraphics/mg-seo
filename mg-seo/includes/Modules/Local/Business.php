<?php
/**
 * Local business data model: fields, sanitizing and schema.org nodes.
 *
 * @package MGSEO
 */

namespace MGSEO\Modules\Local;

use MGSEO\Meta;
use MGSEO\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Business data is a flat array with the keys returned by keys(); the same keys are used for the
 * global settings (Options group `local`) and for each location post (`_mgseo_local` meta).
 */
final class Business {

	public const META = 'local';

	public const DAYS = array( 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday' );

	/**
	 * Data keys.
	 *
	 * @return string[]
	 */
	public static function keys(): array {
		return array(
			'type',
			'name',
			'description',
			'logo',
			'image',
			'street',
			'locality',
			'region',
			'postal_code',
			'country',
			'lat',
			'lng',
			'phones',
			'email',
			'url',
			'price_range',
			'payment',
			'area_served',
			'social',
			'vat_id',
			'tax_id',
			'regon',
			'krs',
			'hours',
			'special_hours',
		);
	}

	/**
	 * Schema.org LocalBusiness and its common subtypes (value => label).
	 *
	 * @return array<string, string>
	 */
	public static function types(): array {
		$types = array(
			'LocalBusiness',
			'ProfessionalService',
			'AccountingService',
			'Attorney',
			'Notary',
			'LegalService',
			'FinancialService',
			'InsuranceAgency',
			'BankOrCreditUnion',
			'RealEstateAgent',
			'EmploymentAgency',
			'TravelAgency',
			'Store',
			'BikeStore',
			'BookStore',
			'ClothingStore',
			'ComputerStore',
			'ConvenienceStore',
			'DepartmentStore',
			'ElectronicsStore',
			'Florist',
			'FurnitureStore',
			'GardenStore',
			'GroceryStore',
			'HardwareStore',
			'HobbyShop',
			'HomeGoodsStore',
			'JewelryStore',
			'LiquorStore',
			'MobilePhoneStore',
			'MusicStore',
			'OfficeEquipmentStore',
			'OutletStore',
			'PetStore',
			'ShoeStore',
			'SportingGoodsStore',
			'TireShop',
			'ToyStore',
			'WholesaleStore',
			'FoodEstablishment',
			'Bakery',
			'BarOrPub',
			'Brewery',
			'CafeOrCoffeeShop',
			'FastFoodRestaurant',
			'IceCreamShop',
			'Restaurant',
			'Winery',
			'HealthAndBeautyBusiness',
			'BeautySalon',
			'DaySpa',
			'HairSalon',
			'HealthClub',
			'NailSalon',
			'TattooParlor',
			'MedicalBusiness',
			'Dentist',
			'MedicalClinic',
			'Optician',
			'Pharmacy',
			'Physician',
			'Physiotherapy',
			'VeterinaryCare',
			'HomeAndConstructionBusiness',
			'Electrician',
			'GeneralContractor',
			'HVACBusiness',
			'HousePainter',
			'Locksmith',
			'MovingCompany',
			'Plumber',
			'RoofingContractor',
			'AutomotiveBusiness',
			'AutoBodyShop',
			'AutoDealer',
			'AutoPartsStore',
			'AutoRental',
			'AutoRepair',
			'AutoWash',
			'GasStation',
			'MotorcycleDealer',
			'MotorcycleRepair',
			'LodgingBusiness',
			'BedAndBreakfast',
			'Campground',
			'Hostel',
			'Hotel',
			'Motel',
			'Resort',
			'VacationRental',
			'EntertainmentBusiness',
			'ArtGallery',
			'MovieTheater',
			'NightClub',
			'SportsActivityLocation',
			'ExerciseGym',
			'GolfCourse',
			'SportsClub',
			'TennisComplex',
			'ChildCare',
			'DryCleaningOrLaundry',
			'EmergencyService',
			'GovernmentOffice',
			'InternetCafe',
			'Library',
			'RecyclingCenter',
			'SelfStorage',
			'ShoppingCenter',
			'TouristInformationCenter',
		);
		$list  = array();
		foreach ( $types as $type ) {
			$list[ $type ] = trim( (string) preg_replace( '/(?<!^)([A-Z][a-z])/', ' $1', $type ) );
		}
		/**
		 * Filter the selectable LocalBusiness types.
		 *
		 * @param array $list schema.org type => label.
		 */
		return apply_filters( 'mgseo/local/types', $list );
	}

	/**
	 * Contact point types (Google's recommended contactType values).
	 *
	 * @return array<string, string>
	 */
	public static function contact_types(): array {
		return array(
			'customer service'    => __( 'Customer service', 'mg-seo' ),
			'sales'               => __( 'Sales', 'mg-seo' ),
			'technical support'   => __( 'Technical support', 'mg-seo' ),
			'billing support'     => __( 'Billing support', 'mg-seo' ),
			'reservations'        => __( 'Reservations', 'mg-seo' ),
			'emergency'           => __( 'Emergency', 'mg-seo' ),
			'credit card support' => __( 'Credit card support', 'mg-seo' ),
			'bill payment'        => __( 'Bill payment', 'mg-seo' ),
			'package tracking'    => __( 'Package tracking', 'mg-seo' ),
			'roadside assistance' => __( 'Roadside assistance', 'mg-seo' ),
		);
	}

	/**
	 * Field definitions in the generic settings schema format.
	 *
	 * @param bool $location Definitions for a location post (no global-only hints).
	 * @return array<string, array<int, array<string, mixed>>> group => fields
	 */
	public static function fields( bool $location = false ): array {
		$fields = array(
			'business' => array(
				array(
					'id'      => 'type',
					'type'    => 'select',
					'label'   => __( 'Business type', 'mg-seo' ),
					'options' => self::types(),
					'default' => 'LocalBusiness',
					'desc'    => __( 'The most specific schema.org type that describes the business.', 'mg-seo' ),
				),
				array(
					'id'      => 'name',
					'type'    => 'text',
					'label'   => __( 'Business name', 'mg-seo' ),
					'default' => '',
					'desc'    => $location ? __( 'Leave empty to use the location title.', 'mg-seo' ) : __( 'Leave empty to use the Organization name from the schema settings or the site title.', 'mg-seo' ),
				),
				array(
					'id'      => 'description',
					'type'    => 'textarea',
					'label'   => __( 'Description', 'mg-seo' ),
					'default' => '',
				),
				array(
					'id'      => 'logo',
					'type'    => 'image',
					'label'   => __( 'Logo', 'mg-seo' ),
					'default' => '',
				),
				array(
					'id'      => 'image',
					'type'    => 'image',
					'label'   => __( 'Photo of the business', 'mg-seo' ),
					'default' => '',
					'desc'    => __( 'Recommended by Google for local results. The logo is used when empty.', 'mg-seo' ),
				),
				array(
					'id'    => 'address_heading',
					'type'  => 'heading',
					'label' => __( 'Address', 'mg-seo' ),
				),
				array(
					'id'      => 'street',
					'type'    => 'text',
					'label'   => __( 'Street address', 'mg-seo' ),
					'default' => '',
				),
				array(
					'id'      => 'postal_code',
					'type'    => 'text',
					'label'   => __( 'Postal code', 'mg-seo' ),
					'default' => '',
				),
				array(
					'id'      => 'locality',
					'type'    => 'text',
					'label'   => __( 'City', 'mg-seo' ),
					'default' => '',
				),
				array(
					'id'      => 'region',
					'type'    => 'text',
					'label'   => __( 'Region / state', 'mg-seo' ),
					'default' => '',
				),
				array(
					'id'       => 'country',
					'type'     => 'text',
					'label'    => __( 'Country code', 'mg-seo' ),
					'default'  => '',
					'desc'     => __( 'Two-letter ISO 3166-1 code, e.g. PL.', 'mg-seo' ),
					'sanitize' => static fn( $value ) => strtoupper( substr( sanitize_text_field( (string) $value ), 0, 3 ) ),
				),
				array(
					'id'       => 'lat',
					'type'     => 'text',
					'label'    => __( 'Latitude', 'mg-seo' ),
					'default'  => '',
					'desc'     => __( 'Decimal degrees, e.g. 50.2649.', 'mg-seo' ),
					'sanitize' => array( self::class, 'sanitize_coordinate' ),
				),
				array(
					'id'       => 'lng',
					'type'     => 'text',
					'label'    => __( 'Longitude', 'mg-seo' ),
					'default'  => '',
					'desc'     => __( 'Decimal degrees, e.g. 19.0238.', 'mg-seo' ),
					'sanitize' => array( self::class, 'sanitize_coordinate' ),
				),
			),
			'contact'  => array(
				array(
					'id'       => 'phones',
					'type'     => 'callback',
					'label'    => __( 'Phone numbers', 'mg-seo' ),
					'default'  => array(),
					'callback' => array( Admin::class, 'render_phones' ),
					'sanitize' => array( self::class, 'sanitize_phones' ),
					'desc'     => __( 'The first number is the main telephone. Use the international format, e.g. +48 123 456 789.', 'mg-seo' ),
				),
				array(
					'id'       => 'email',
					'type'     => 'text',
					'label'    => __( 'Email', 'mg-seo' ),
					'default'  => '',
					'sanitize' => static fn( $value ) => sanitize_email( (string) $value ),
				),
				array(
					'id'      => 'url',
					'type'    => 'url',
					'label'   => __( 'Website URL', 'mg-seo' ),
					'default' => '',
					'desc'    => $location ? __( 'Leave empty to use the location page.', 'mg-seo' ) : __( 'Leave empty to use the site address.', 'mg-seo' ),
				),
				array(
					'id'      => 'price_range',
					'type'    => 'text',
					'label'   => __( 'Price range', 'mg-seo' ),
					'default' => '',
					'desc'    => __( 'For example $$ or 100–500 PLN.', 'mg-seo' ),
				),
				array(
					'id'      => 'payment',
					'type'    => 'text',
					'label'   => __( 'Payment accepted', 'mg-seo' ),
					'default' => '',
					'desc'    => __( 'Comma separated, e.g. Cash, Credit card, BLIK.', 'mg-seo' ),
				),
				array(
					'id'      => 'area_served',
					'type'    => 'text',
					'label'   => __( 'Area served', 'mg-seo' ),
					'default' => '',
					'desc'    => __( 'Comma separated cities or regions.', 'mg-seo' ),
				),
				array(
					'id'       => 'social',
					'type'     => 'textarea',
					'label'    => __( 'Social profiles', 'mg-seo' ),
					'default'  => '',
					'desc'     => __( 'One URL per line (Facebook, Instagram, LinkedIn, Google Business Profile …).', 'mg-seo' ),
					'sanitize' => array( self::class, 'sanitize_urls' ),
				),
				array(
					'id'    => 'ids_heading',
					'type'  => 'heading',
					'label' => __( 'Company identifiers', 'mg-seo' ),
				),
				array(
					'id'      => 'vat_id',
					'type'    => 'text',
					'label'   => __( 'VAT ID', 'mg-seo' ),
					'default' => '',
					'desc'    => __( 'EU VAT number, e.g. PL1234567890 (schema vatID).', 'mg-seo' ),
				),
				array(
					'id'      => 'tax_id',
					'type'    => 'text',
					'label'   => __( 'Tax ID (NIP)', 'mg-seo' ),
					'default' => '',
					'desc'    => __( 'National tax identification number (schema taxID).', 'mg-seo' ),
				),
				array(
					'id'      => 'regon',
					'type'    => 'text',
					'label'   => __( 'REGON', 'mg-seo' ),
					'default' => '',
				),
				array(
					'id'      => 'krs',
					'type'    => 'text',
					'label'   => __( 'KRS', 'mg-seo' ),
					'default' => '',
				),
			),
			'hours'    => array(
				array(
					'id'       => 'hours',
					'type'     => 'callback',
					'label'    => __( 'Opening hours', 'mg-seo' ),
					'default'  => array(),
					'callback' => array( Admin::class, 'render_hours' ),
					'sanitize' => array( self::class, 'sanitize_hours' ),
					'desc'     => __( 'Several ranges per day are separated with commas, e.g. 08:00-12:00, 13:00-17:00. A closing time earlier than the opening time means the next day.', 'mg-seo' ),
				),
				array(
					'id'       => 'special_hours',
					'type'     => 'textarea',
					'label'    => __( 'Holidays and special hours', 'mg-seo' ),
					'default'  => '',
					'rows'     => 4,
					'desc'     => __( 'One date per line: "2026-12-24 | 08:00-12:00", "2026-12-25 | closed" or a range "2026-08-01 - 2026-08-14 | closed".', 'mg-seo' ),
					'sanitize' => array( self::class, 'sanitize_special_hours' ),
				),
			),
		);
		return $fields;
	}

	/**
	 * Flat list of all field definitions.
	 *
	 * @param bool $location For a location post.
	 * @return array<int, array<string, mixed>>
	 */
	public static function flat_fields( bool $location = false ): array {
		return array_merge( ...array_values( self::fields( $location ) ) );
	}

	/**
	 * Global business data from settings.
	 *
	 * @return array<string, mixed>
	 */
	public static function from_options(): array {
		$data = array();
		foreach ( self::keys() as $key ) {
			$data[ $key ] = Options::get( 'local', $key, '' );
		}
		if ( '' === trim( (string) $data['name'] ) ) {
			$org          = (string) Options::get( 'schema', 'org_name', '' );
			$data['name'] = '' !== $org ? $org : get_bloginfo( 'name' );
		}
		if ( '' === trim( (string) $data['url'] ) ) {
			$data['url'] = home_url( '/' );
		}
		return self::normalize( $data );
	}

	/**
	 * Business data of a location post.
	 *
	 * @param \WP_Post $post Location post.
	 * @return array<string, mixed>
	 */
	public static function from_post( \WP_Post $post ): array {
		$stored = Meta::post( $post->ID, self::META, array() );
		$data   = is_array( $stored ) ? $stored : array();
		if ( '' === trim( (string) ( $data['name'] ?? '' ) ) ) {
			$data['name'] = html_entity_decode( wp_strip_all_tags( get_the_title( $post ) ), ENT_QUOTES, 'UTF-8' );
		}
		if ( '' === trim( (string) ( $data['url'] ?? '' ) ) ) {
			$data['url'] = (string) get_permalink( $post );
		}
		if ( '' === trim( (string) ( $data['image'] ?? '' ) ) && has_post_thumbnail( $post ) ) {
			$data['image'] = (string) get_the_post_thumbnail_url( $post, 'full' );
		}
		if ( '' === trim( (string) ( $data['description'] ?? '' ) ) && has_excerpt( $post ) ) {
			$data['description'] = wp_strip_all_tags( $post->post_excerpt );
		}
		$data['post_id'] = $post->ID;
		return self::normalize( $data );
	}

	/**
	 * Fill missing keys with empty values.
	 *
	 * @param array<string, mixed> $data Data.
	 * @return array<string, mixed>
	 */
	public static function normalize( array $data ): array {
		foreach ( self::keys() as $key ) {
			if ( ! array_key_exists( $key, $data ) || null === $data[ $key ] ) {
				$data[ $key ] = in_array( $key, array( 'phones', 'hours' ), true ) ? array() : '';
			}
		}
		if ( ! is_array( $data['phones'] ) ) {
			$data['phones'] = array();
		}
		if ( ! is_array( $data['hours'] ) ) {
			$data['hours'] = array();
		}
		if ( ! isset( self::types()[ (string) $data['type'] ] ) ) {
			$data['type'] = 'LocalBusiness';
		}
		return $data;
	}

	/**
	 * Whether the data describes a physical business (has an address).
	 *
	 * @param array<string, mixed> $data Data.
	 */
	public static function has_address( array $data ): bool {
		return '' !== trim( (string) $data['street'] ) || '' !== trim( (string) $data['locality'] );
	}

	/**
	 * Build the LocalBusiness properties (without @id).
	 *
	 * @param array<string, mixed> $data Business data.
	 * @return array<string, mixed>
	 */
	public static function properties( array $data ): array {
		$data  = self::normalize( $data );
		$props = array(
			'@type'       => $data['type'],
			'name'        => (string) $data['name'],
			'description' => (string) $data['description'],
			'url'         => (string) $data['url'],
		);
		if ( '' !== $data['logo'] ) {
			$props['logo'] = array(
				'@type' => 'ImageObject',
				'url'   => (string) $data['logo'],
			);
		}
		$image = '' !== $data['image'] ? $data['image'] : $data['logo'];
		if ( '' !== $image ) {
			$props['image'] = (string) $image;
		}
		$address = array_filter(
			array(
				'@type'           => 'PostalAddress',
				'streetAddress'   => (string) $data['street'],
				'addressLocality' => (string) $data['locality'],
				'addressRegion'   => (string) $data['region'],
				'postalCode'      => (string) $data['postal_code'],
				'addressCountry'  => (string) $data['country'],
			),
			static fn( $v ) => '' !== $v
		);
		if ( count( $address ) > 1 ) {
			$props['address'] = $address;
		}
		if ( '' !== $data['lat'] && '' !== $data['lng'] ) {
			$props['geo']    = array(
				'@type'     => 'GeoCoordinates',
				'latitude'  => (float) $data['lat'],
				'longitude' => (float) $data['lng'],
			);
			$props['hasMap'] = self::map_link( $data, 'google' );
		}
		$phones = self::phones( $data );
		if ( $phones ) {
			$props['telephone'] = $phones[0]['number'];
			$points             = array();
			foreach ( $phones as $phone ) {
				$points[] = array(
					'@type'       => 'ContactPoint',
					'telephone'   => $phone['number'],
					'contactType' => '' !== $phone['type'] ? $phone['type'] : 'customer service',
				);
			}
			$props['contactPoint'] = $points;
		}
		if ( '' !== $data['email'] ) {
			$props['email'] = (string) $data['email'];
		}
		$props['priceRange']      = (string) $data['price_range'];
		$props['paymentAccepted'] = (string) $data['payment'];
		$areas                    = self::split_list( (string) $data['area_served'] );
		if ( $areas ) {
			$props['areaServed'] = 1 === count( $areas ) ? $areas[0] : $areas;
		}
		$same_as = self::lines( (string) $data['social'] );
		if ( $same_as ) {
			$props['sameAs'] = $same_as;
		}
		$props['vatID'] = (string) $data['vat_id'];
		$props['taxID'] = (string) $data['tax_id'];
		$identifiers    = array();
		foreach ( array(
			'regon' => 'REGON',
			'krs'   => 'KRS',
		) as $key => $property ) {
			if ( '' !== trim( (string) $data[ $key ] ) ) {
				$identifiers[] = array(
					'@type'      => 'PropertyValue',
					'propertyID' => $property,
					'value'      => trim( (string) $data[ $key ] ),
				);
			}
		}
		if ( $identifiers ) {
			$props['identifier'] = 1 === count( $identifiers ) ? $identifiers[0] : $identifiers;
		}
		$hours = self::opening_hours( (array) $data['hours'] );
		if ( $hours ) {
			$props['openingHoursSpecification'] = $hours;
		}
		$special = self::special_hours( (string) $data['special_hours'] );
		if ( $special ) {
			$props['specialOpeningHoursSpecification'] = $special;
		}
		$props = array_filter( $props, static fn( $v ) => '' !== $v && array() !== $v );

		/**
		 * Filter LocalBusiness properties.
		 *
		 * @param array $props Properties.
		 * @param array $data  Business data.
		 */
		return apply_filters( 'mgseo/local/business', $props, $data );
	}

	/**
	 * Parsed phone list.
	 *
	 * @param array<string, mixed> $data Data.
	 * @return array<int, array{number: string, type: string}>
	 */
	public static function phones( array $data ): array {
		$list = array();
		foreach ( (array) ( $data['phones'] ?? array() ) as $row ) {
			$number = is_array( $row ) ? trim( (string) ( $row['number'] ?? '' ) ) : trim( (string) $row );
			if ( '' === $number ) {
				continue;
			}
			$list[] = array(
				'number' => $number,
				'type'   => is_array( $row ) ? (string) ( $row['type'] ?? '' ) : '',
			);
		}
		return $list;
	}

	/**
	 * Opening hours as OpeningHoursSpecification list (days with identical ranges are grouped).
	 *
	 * @param array<string, mixed> $hours day => array( mode, ranges ).
	 * @return array<int, array<string, mixed>>
	 */
	public static function opening_hours( array $hours ): array {
		$groups = array();
		foreach ( self::DAYS as $day ) {
			foreach ( self::day_ranges( $hours[ $day ] ?? array() ) as $range ) {
				$key = $range[0] . '-' . $range[1];
				if ( ! isset( $groups[ $key ] ) ) {
					$groups[ $key ] = array(
						'days'  => array(),
						'range' => $range,
					);
				}
				$groups[ $key ]['days'][] = ucfirst( $day );
			}
		}
		$spec = array();
		foreach ( $groups as $group ) {
			$spec[] = array(
				'@type'     => 'OpeningHoursSpecification',
				'dayOfWeek' => $group['days'],
				'opens'     => $group['range'][0],
				'closes'    => $group['range'][1],
			);
		}
		return $spec;
	}

	/**
	 * Ranges of one day.
	 *
	 * @param mixed $day Day value (array mode/ranges).
	 * @return array<int, array{0: string, 1: string}>
	 */
	public static function day_ranges( mixed $day ): array {
		if ( ! is_array( $day ) ) {
			return array();
		}
		$mode = (string) ( $day['mode'] ?? '' );
		if ( '24h' === $mode ) {
			return array( array( '00:00', '23:59' ) );
		}
		if ( 'open' !== $mode ) {
			return array();
		}
		return self::parse_ranges( (string) ( $day['ranges'] ?? '' ) );
	}

	/**
	 * Parse "08:00-12:00, 13:00-17:00".
	 *
	 * @param string $ranges Ranges.
	 * @return array<int, array{0: string, 1: string}>
	 */
	public static function parse_ranges( string $ranges ): array {
		$out = array();
		foreach ( preg_split( '/[,;\n]+/', $ranges ) as $part ) {
			if ( preg_match( '/^\s*(\d{1,2})[:.](\d{2})\s*[-–—]\s*(\d{1,2})[:.](\d{2})\s*$/u', (string) $part, $m ) ) {
				$open  = self::time( (int) $m[1], (int) $m[2] );
				$close = self::time( (int) $m[3], (int) $m[4] );
				if ( $open && $close ) {
					$out[] = array( $open, $close );
				}
			}
		}
		return $out;
	}

	/**
	 * Normalised HH:MM or '' when invalid.
	 *
	 * @param int $hour   Hour.
	 * @param int $minute Minute.
	 */
	private static function time( int $hour, int $minute ): string {
		if ( 24 === $hour && 0 === $minute ) {
			return '23:59';
		}
		if ( $hour < 0 || $hour > 23 || $minute < 0 || $minute > 59 ) {
			return '';
		}
		return sprintf( '%02d:%02d', $hour, $minute );
	}

	/**
	 * Special opening hours (holidays).
	 *
	 * @param string $text Lines "date[ - date] | closed|HH:MM-HH:MM".
	 * @return array<int, array<string, mixed>>
	 */
	public static function special_hours( string $text ): array {
		$spec = array();
		foreach ( self::parse_special( $text ) as $row ) {
			$ranges = $row['closed'] ? array( array( '00:00', '00:00' ) ) : $row['ranges'];
			foreach ( $ranges as $range ) {
				$spec[] = array(
					'@type'        => 'OpeningHoursSpecification',
					'validFrom'    => $row['from'],
					'validThrough' => $row['to'],
					'opens'        => $range[0],
					'closes'       => $range[1],
				);
			}
		}
		return $spec;
	}

	/**
	 * Parse special hours lines.
	 *
	 * @param string $text Text.
	 * @return array<int, array{from: string, to: string, closed: bool, ranges: array}>
	 */
	public static function parse_special( string $text ): array {
		$rows = array();
		foreach ( self::lines( $text ) as $line ) {
			if ( ! preg_match( '/^(\d{4}-\d{2}-\d{2})(?:\s*[-–]\s*(\d{4}-\d{2}-\d{2}))?\s*\|\s*(.+)$/u', $line, $m ) ) {
				continue;
			}
			$from   = $m[1];
			$to     = '' !== $m[2] ? $m[2] : $m[1];
			$value  = trim( $m[3] );
			$closed = (bool) preg_match( '/^(closed|zamkni[eę]te|-)$/iu', $value );
			$ranges = $closed ? array() : self::parse_ranges( $value );
			if ( ! $closed && ! $ranges ) {
				continue;
			}
			$rows[] = array(
				'from'   => $from,
				'to'     => $to,
				'closed' => $closed,
				'ranges' => $ranges,
			);
		}
		return $rows;
	}

	/**
	 * Map link for the business.
	 *
	 * @param array<string, mixed> $data     Data.
	 * @param string               $provider google|osm.
	 */
	public static function map_link( array $data, string $provider = 'google' ): string {
		$has_geo = '' !== (string) ( $data['lat'] ?? '' ) && '' !== (string) ( $data['lng'] ?? '' );
		if ( 'osm' === $provider ) {
			if ( $has_geo ) {
				return sprintf( 'https://www.openstreetmap.org/?mlat=%1$s&mlon=%2$s#map=17/%1$s/%2$s', rawurlencode( (string) $data['lat'] ), rawurlencode( (string) $data['lng'] ) );
			}
			return 'https://www.openstreetmap.org/search?query=' . rawurlencode( self::address_line( $data ) );
		}
		$query = $has_geo ? $data['lat'] . ',' . $data['lng'] : self::address_line( $data );
		return 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode( (string) $query );
	}

	/**
	 * Single-line address.
	 *
	 * @param array<string, mixed> $data Data.
	 */
	public static function address_line( array $data ): string {
		$parts = array(
			(string) ( $data['street'] ?? '' ),
			trim( ( $data['postal_code'] ?? '' ) . ' ' . ( $data['locality'] ?? '' ) ),
			(string) ( $data['region'] ?? '' ),
			(string) ( $data['country'] ?? '' ),
		);
		return implode( ', ', array_filter( array_map( 'trim', $parts ) ) );
	}

	/**
	 * Non-empty trimmed lines.
	 *
	 * @param string $text Text.
	 * @return string[]
	 */
	public static function lines( string $text ): array {
		return array_values( array_filter( array_map( 'trim', preg_split( '/\r\n|\r|\n/', $text ) ) ) );
	}

	/**
	 * Comma separated list.
	 *
	 * @param string $text Text.
	 * @return string[]
	 */
	public static function split_list( string $text ): array {
		return array_values( array_filter( array_map( 'trim', explode( ',', $text ) ) ) );
	}

	/**
	 * Sanitize opening hours.
	 *
	 * @param mixed $value Raw value.
	 * @return array<string, array{mode: string, ranges: string}>
	 */
	public static function sanitize_hours( mixed $value ): array {
		$value = is_array( $value ) ? $value : array();
		$out   = array();
		foreach ( self::DAYS as $day ) {
			$row  = isset( $value[ $day ] ) && is_array( $value[ $day ] ) ? $value[ $day ] : array();
			$mode = sanitize_key( (string) ( $row['mode'] ?? '' ) );
			$mode = in_array( $mode, array( 'open', 'closed', '24h' ), true ) ? $mode : '';
			if ( '24h' === (string) ( $row['mode'] ?? '' ) ) {
				$mode = '24h';
			}
			$ranges = array_map( static fn( $r ) => $r[0] . '-' . $r[1], self::parse_ranges( sanitize_text_field( (string) ( $row['ranges'] ?? '' ) ) ) );
			if ( '' === $mode && $ranges ) {
				$mode = 'open';
			}
			$out[ $day ] = array(
				'mode'   => $mode,
				'ranges' => implode( ', ', $ranges ),
			);
		}
		return $out;
	}

	/**
	 * Sanitize phone rows.
	 *
	 * @param mixed $value Raw value.
	 * @return array<int, array{number: string, type: string}>
	 */
	public static function sanitize_phones( mixed $value ): array {
		$out = array();
		foreach ( is_array( $value ) ? $value : array() as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$number = trim( (string) preg_replace( '/[^0-9+()\-\s.\/]/', '', sanitize_text_field( (string) ( $row['number'] ?? '' ) ) ) );
			if ( '' === $number ) {
				continue;
			}
			$type  = sanitize_text_field( (string) ( $row['type'] ?? '' ) );
			$out[] = array(
				'number' => $number,
				'type'   => isset( self::contact_types()[ $type ] ) ? $type : 'customer service',
			);
		}
		return $out;
	}

	/**
	 * Sanitize a coordinate.
	 *
	 * @param mixed $value Raw value.
	 */
	public static function sanitize_coordinate( mixed $value ): string {
		$value = str_replace( ',', '.', trim( (string) $value ) );
		if ( ! is_numeric( $value ) || abs( (float) $value ) > 180 ) {
			return '';
		}
		return (string) round( (float) $value, 7 );
	}

	/**
	 * Sanitize a list of URLs (one per line).
	 *
	 * @param mixed $value Raw value.
	 */
	public static function sanitize_urls( mixed $value ): string {
		$urls = array_filter( array_map( 'esc_url_raw', self::lines( (string) $value ) ) );
		return implode( "\n", $urls );
	}

	/**
	 * Sanitize special hours (keeps only valid lines).
	 *
	 * @param mixed $value Raw value.
	 */
	public static function sanitize_special_hours( mixed $value ): string {
		$lines = array();
		foreach ( self::parse_special( sanitize_textarea_field( (string) $value ) ) as $row ) {
			$date    = $row['from'] === $row['to'] ? $row['from'] : $row['from'] . ' - ' . $row['to'];
			$hours   = $row['closed'] ? 'closed' : implode( ', ', array_map( static fn( $r ) => $r[0] . '-' . $r[1], $row['ranges'] ) );
			$lines[] = $date . ' | ' . $hours;
		}
		return implode( "\n", $lines );
	}
}
