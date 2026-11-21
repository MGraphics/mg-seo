<?php
/**
 * Schema type definitions (forms of the schema generator).
 *
 * @package MGSEO
 */

namespace MGSEO\Modules\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Type definitions: label, field list and defaults per entity type.
 *
 * Field format: array(
 *   'key'         => schema.org property ('@type' for a subtype selector),
 *   'label'       => UI label,
 *   'type'        => text|textarea|url|image|date|datetime|time|duration|number|select|checks|toggle|list|group|repeater,
 *   'options'     => array( value => label ) for select/checks,
 *   'default'     => default value for new entities (may contain %variables%),
 *   'html'        => true when limited HTML is allowed (textarea),
 *   'schema_type' => @type of a group / repeater item,
 *   'types'       => list of selectable @types of a group (first is the default),
 *   'only'        => list of parent @types for which the field applies,
 *   'fields'      => sub-fields of a group / repeater item,
 *   'help'        => short description,
 * ). Stored entity data uses the same shape as schema.org JSON-LD.
 */
final class Types {

	/**
	 * Cached definitions.
	 *
	 * @var array<string, array<string, mixed>>|null
	 */
	private static ?array $defs = null;

	/**
	 * LocalBusiness subtypes offered in the generator.
	 *
	 * @return string[]
	 */
	public static function local_business_types(): array {
		return array(
			'LocalBusiness',
			'AnimalShelter',
			'AutomotiveBusiness',
			'AutoRepair',
			'AutoDealer',
			'ChildCare',
			'Dentist',
			'DryCleaningOrLaundry',
			'EmergencyService',
			'EmploymentAgency',
			'EntertainmentBusiness',
			'FinancialService',
			'AccountingService',
			'InsuranceAgency',
			'FoodEstablishment',
			'Bakery',
			'BarOrPub',
			'CafeOrCoffeeShop',
			'FastFoodRestaurant',
			'Restaurant',
			'GovernmentOffice',
			'HealthAndBeautyBusiness',
			'BeautySalon',
			'DaySpa',
			'HairSalon',
			'HealthClub',
			'HomeAndConstructionBusiness',
			'Electrician',
			'GeneralContractor',
			'HVACBusiness',
			'Plumber',
			'RoofingContractor',
			'InternetCafe',
			'LegalService',
			'Attorney',
			'Notary',
			'Library',
			'LodgingBusiness',
			'Hotel',
			'BedAndBreakfast',
			'MedicalBusiness',
			'MedicalClinic',
			'Optician',
			'Pharmacy',
			'Physician',
			'ProfessionalService',
			'RealEstateAgent',
			'SelfStorage',
			'ShoppingCenter',
			'SportsActivityLocation',
			'Store',
			'AutoPartsStore',
			'BikeStore',
			'BookStore',
			'ClothingStore',
			'ComputerStore',
			'ElectronicsStore',
			'FlowerShop',
			'FurnitureStore',
			'GardenStore',
			'GroceryStore',
			'HardwareStore',
			'JewelryStore',
			'PetStore',
			'ShoeStore',
			'SportingGoodsStore',
			'ToyStore',
			'TouristInformationCenter',
			'TravelAgency',
		);
	}

	/**
	 * Organization subtypes.
	 *
	 * @return string[]
	 */
	public static function organization_types(): array {
		return array( 'Organization', 'Corporation', 'NGO', 'EducationalOrganization', 'GovernmentOrganization', 'MedicalOrganization', 'NewsMediaOrganization', 'OnlineStore', 'PerformingGroup', 'SportsOrganization', 'Airline', 'Consortium', 'FundingScheme', 'LibrarySystem', 'ResearchOrganization', 'WorkersUnion' );
	}

	/**
	 * Event subtypes.
	 *
	 * @return string[]
	 */
	public static function event_types(): array {
		return array( 'Event', 'BusinessEvent', 'ChildrensEvent', 'ComedyEvent', 'CourseInstance', 'DanceEvent', 'DeliveryEvent', 'EducationEvent', 'ExhibitionEvent', 'Festival', 'FoodEvent', 'Hackathon', 'LiteraryEvent', 'MusicEvent', 'PublicationEvent', 'SaleEvent', 'ScreeningEvent', 'SocialEvent', 'SportsEvent', 'TheaterEvent', 'VisualArtsEvent' );
	}

	/**
	 * Base form type for any @type (BlogPosting → Article, Dentist → LocalBusiness …).
	 *
	 * @param string|array $type Type or list of types.
	 */
	public static function base_type( string|array $type ): string {
		$list = is_array( $type ) ? array_values( $type ) : array( $type );
		foreach ( $list as $t ) {
			$t = (string) $t;
			if ( in_array( $t, array( 'Article', 'BlogPosting', 'NewsArticle', 'TechArticle', 'ScholarlyArticle', 'Report' ), true ) ) {
				return 'Article';
			}
			if ( in_array( $t, self::local_business_types(), true ) ) {
				return 'LocalBusiness';
			}
			if ( 'Organization' !== $t && in_array( $t, self::organization_types(), true ) ) {
				return 'Organization';
			}
			if ( in_array( $t, self::event_types(), true ) && 'CourseInstance' !== $t ) {
				return 'Event';
			}
			if ( in_array( $t, array( 'SoftwareApplication', 'MobileApplication', 'WebApplication', 'VideoGame' ), true ) ) {
				return 'SoftwareApplication';
			}
			if ( in_array( $t, array( 'Product', 'ProductGroup', 'IndividualProduct', 'ProductModel', 'Vehicle', 'Car' ), true ) ) {
				return 'Product';
			}
		}
		return (string) ( $list[0] ?? '' );
	}

	/**
	 * Shorthand for a field definition.
	 *
	 * @param string $key   Property.
	 * @param string $label Label.
	 * @param string $type  Field type.
	 * @param array  $extra Extra keys.
	 * @return array<string, mixed>
	 */
	private static function f( string $key, string $label, string $type = 'text', array $extra = array() ): array {
		return array(
			'key'   => $key,
			'label' => $label,
			'type'  => $type,
		) + $extra;
	}

	/**
	 * Select options from a list of values (label = value).
	 *
	 * @param string[] $values Values.
	 * @return array<string, string>
	 */
	private static function same( array $values ): array {
		return array_combine( $values, $values );
	}

	/**
	 * Reusable PostalAddress group.
	 *
	 * @param array $extra Extra keys.
	 * @return array<string, mixed>
	 */
	private static function address( array $extra = array() ): array {
		return self::f(
			'address',
			__( 'Address', 'mg-seo' ),
			'group',
			array(
				'schema_type' => 'PostalAddress',
				'fields'      => array(
					self::f( 'streetAddress', __( 'Street address', 'mg-seo' ) ),
					self::f( 'addressLocality', __( 'City', 'mg-seo' ) ),
					self::f( 'postalCode', __( 'Postal code', 'mg-seo' ) ),
					self::f( 'addressRegion', __( 'Region', 'mg-seo' ) ),
					self::f( 'addressCountry', __( 'Country code', 'mg-seo' ), 'text', array( 'placeholder' => 'PL' ) ),
				),
			) + $extra
		);
	}

	/**
	 * Offer group (Product, Event, Service, Course, SoftwareApplication).
	 *
	 * @param bool $multi Repeater of offers instead of a single offer.
	 * @return array<string, mixed>
	 */
	private static function offers( bool $multi = false ): array {
		$fields = array(
			self::f( 'price', __( 'Price', 'mg-seo' ), 'number', array( 'step' => 'any' ) ),
			self::f( 'priceCurrency', __( 'Currency (ISO 4217)', 'mg-seo' ), 'text', array( 'placeholder' => 'PLN' ) ),
			self::f( 'availability', __( 'Availability', 'mg-seo' ), 'select', array( 'options' => self::availability() ) ),
			self::f( 'url', __( 'URL', 'mg-seo' ), 'url' ),
			self::f( 'priceValidUntil', __( 'Price valid until', 'mg-seo' ), 'date' ),
			self::f( 'validFrom', __( 'Valid from', 'mg-seo' ), 'datetime' ),
			self::f(
				'itemCondition',
				__( 'Condition', 'mg-seo' ),
				'select',
				array(
					'options' => array(
						''                                 => '—',
						'https://schema.org/NewCondition'  => __( 'New', 'mg-seo' ),
						'https://schema.org/UsedCondition' => __( 'Used', 'mg-seo' ),
						'https://schema.org/RefurbishedCondition' => __( 'Refurbished', 'mg-seo' ),
						'https://schema.org/DamagedCondition' => __( 'Damaged', 'mg-seo' ),
					),
				)
			),
			self::f( 'category', __( 'Category', 'mg-seo' ), 'text', array( 'help' => __( 'For example "Free" or "Subscription".', 'mg-seo' ) ) ),
		);
		return self::f(
			'offers',
			__( 'Offer', 'mg-seo' ),
			$multi ? 'repeater' : 'group',
			array(
				'schema_type' => 'Offer',
				'fields'      => $fields,
			)
		);
	}

	/**
	 * Availability options.
	 *
	 * @return array<string, string>
	 */
	private static function availability(): array {
		return array(
			''                                       => '—',
			'https://schema.org/InStock'             => __( 'In stock', 'mg-seo' ),
			'https://schema.org/OutOfStock'          => __( 'Out of stock', 'mg-seo' ),
			'https://schema.org/PreOrder'            => __( 'Pre-order', 'mg-seo' ),
			'https://schema.org/BackOrder'           => __( 'Back order', 'mg-seo' ),
			'https://schema.org/LimitedAvailability' => __( 'Limited availability', 'mg-seo' ),
			'https://schema.org/OnlineOnly'          => __( 'Online only', 'mg-seo' ),
			'https://schema.org/InStoreOnly'         => __( 'In store only', 'mg-seo' ),
			'https://schema.org/SoldOut'             => __( 'Sold out', 'mg-seo' ),
			'https://schema.org/Discontinued'        => __( 'Discontinued', 'mg-seo' ),
		);
	}

	/**
	 * AggregateRating group.
	 *
	 * @return array<string, mixed>
	 */
	private static function aggregate_rating(): array {
		return self::f(
			'aggregateRating',
			__( 'Aggregate rating', 'mg-seo' ),
			'group',
			array(
				'schema_type' => 'AggregateRating',
				'fields'      => array(
					self::f( 'ratingValue', __( 'Rating value', 'mg-seo' ), 'number', array( 'step' => 'any' ) ),
					self::f( 'reviewCount', __( 'Review count', 'mg-seo' ), 'number' ),
					self::f( 'ratingCount', __( 'Rating count', 'mg-seo' ), 'number' ),
					self::f( 'bestRating', __( 'Best rating', 'mg-seo' ), 'number', array( 'placeholder' => '5' ) ),
					self::f( 'worstRating', __( 'Worst rating', 'mg-seo' ), 'number', array( 'placeholder' => '1' ) ),
				),
			)
		);
	}

	/**
	 * Rating group.
	 *
	 * @param string $key Property.
	 * @return array<string, mixed>
	 */
	private static function rating( string $key = 'reviewRating' ): array {
		return self::f(
			$key,
			__( 'Rating', 'mg-seo' ),
			'group',
			array(
				'schema_type' => 'Rating',
				'fields'      => array(
					self::f( 'ratingValue', __( 'Rating value', 'mg-seo' ), 'number', array( 'step' => 'any' ) ),
					self::f( 'bestRating', __( 'Best rating', 'mg-seo' ), 'number', array( 'placeholder' => '5' ) ),
					self::f( 'worstRating', __( 'Worst rating', 'mg-seo' ), 'number', array( 'placeholder' => '1' ) ),
				),
			)
		);
	}

	/**
	 * Party (Person / Organization) group.
	 *
	 * @param string   $key   Property.
	 * @param string   $label Label.
	 * @param string[] $types Selectable types.
	 * @return array<string, mixed>
	 */
	private static function party( string $key, string $label, array $types = array( 'Person', 'Organization' ) ): array {
		return self::f(
			$key,
			$label,
			'group',
			array(
				'types'  => $types,
				'fields' => array(
					self::f( 'name', __( 'Name', 'mg-seo' ) ),
					self::f( 'url', __( 'URL', 'mg-seo' ), 'url' ),
				),
			)
		);
	}

	/**
	 * Review repeater (Product, LocalBusiness …).
	 *
	 * @return array<string, mixed>
	 */
	private static function reviews(): array {
		return self::f(
			'review',
			__( 'Reviews', 'mg-seo' ),
			'repeater',
			array(
				'schema_type' => 'Review',
				'fields'      => array(
					self::party( 'author', __( 'Author', 'mg-seo' ) ),
					self::rating(),
					self::f( 'reviewBody', __( 'Review text', 'mg-seo' ), 'textarea' ),
					self::f( 'datePublished', __( 'Date', 'mg-seo' ), 'date' ),
				),
			)
		);
	}

	/**
	 * Step repeater (HowTo / Recipe).
	 *
	 * @param string $key Property.
	 * @return array<string, mixed>
	 */
	private static function steps( string $key ): array {
		return self::f(
			$key,
			__( 'Steps', 'mg-seo' ),
			'repeater',
			array(
				'schema_type' => 'HowToStep',
				'fields'      => array(
					self::f( 'name', __( 'Step name', 'mg-seo' ) ),
					self::f( 'text', __( 'Instructions', 'mg-seo' ), 'textarea', array( 'html' => true ) ),
					self::f( 'image', __( 'Image', 'mg-seo' ), 'image' ),
					self::f( 'url', __( 'URL (anchor of the step on the page)', 'mg-seo' ), 'url' ),
				),
			)
		);
	}

	/**
	 * All definitions (translated; call on or after init).
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function all(): array {
		if ( null !== self::$defs ) {
			return self::$defs;
		}
		$name  = self::f( 'name', __( 'Name', 'mg-seo' ), 'text', array( 'default' => '%title%' ) );
		$desc  = self::f( 'description', __( 'Description', 'mg-seo' ), 'textarea', array( 'default' => '%excerpt%' ) );
		$image = self::f( 'image', __( 'Image', 'mg-seo' ), 'image', array( 'help' => __( 'Empty: the featured or share image.', 'mg-seo' ) ) );
		$url   = self::f( 'url', __( 'URL', 'mg-seo' ), 'url' );
		$same  = self::f( 'sameAs', __( 'Profiles (sameAs)', 'mg-seo' ), 'list', array( 'item' => 'url' ) );

		$defs = array(
			'Article'             => array(
				'label'  => __( 'Article', 'mg-seo' ),
				'id'     => 'article',
				'fields' => array(
					self::f( '@type', __( 'Article type', 'mg-seo' ), 'select', array( 'options' => self::same( array( 'Article', 'BlogPosting', 'NewsArticle', 'TechArticle', 'ScholarlyArticle' ) ) ) ),
					self::f( 'headline', __( 'Headline', 'mg-seo' ), 'text', array( 'default' => '%title%' ) ),
					self::f( 'description', __( 'Description', 'mg-seo' ), 'textarea', array( 'help' => __( 'Empty: the meta description.', 'mg-seo' ) ) ),
					$image,
					self::party( 'author', __( 'Author (empty: post author)', 'mg-seo' ) ),
					self::f( 'keywords', __( 'Keywords', 'mg-seo' ), 'text', array( 'help' => __( 'Empty: post tags.', 'mg-seo' ) ) ),
					self::f( 'articleSection', __( 'Section', 'mg-seo' ), 'text', array( 'help' => __( 'Empty: primary category.', 'mg-seo' ) ) ),
				),
			),
			'FAQPage'             => array(
				'label'  => __( 'FAQ', 'mg-seo' ),
				'id'     => 'faq',
				'fields' => array(
					self::f(
						'mainEntity',
						__( 'Questions', 'mg-seo' ),
						'repeater',
						array(
							'schema_type' => 'Question',
							'fields'      => array(
								self::f( 'name', __( 'Question', 'mg-seo' ) ),
								self::f(
									'acceptedAnswer',
									__( 'Answer', 'mg-seo' ),
									'group',
									array(
										'schema_type' => 'Answer',
										'inline'      => true,
										'fields'      => array( self::f( 'text', __( 'Answer', 'mg-seo' ), 'textarea', array( 'html' => true ) ) ),
									)
								),
							),
						)
					),
				),
			),
			'HowTo'               => array(
				'label'  => __( 'How-to', 'mg-seo' ),
				'id'     => 'howto',
				'fields' => array(
					$name,
					$desc,
					$image,
					self::f( 'totalTime', __( 'Total time', 'mg-seo' ), 'duration' ),
					self::f(
						'estimatedCost',
						__( 'Estimated cost', 'mg-seo' ),
						'group',
						array(
							'schema_type' => 'MonetaryAmount',
							'fields'      => array(
								self::f( 'value', __( 'Value', 'mg-seo' ), 'number', array( 'step' => 'any' ) ),
								self::f( 'currency', __( 'Currency', 'mg-seo' ), 'text', array( 'placeholder' => 'PLN' ) ),
							),
						)
					),
					self::f(
						'supply',
						__( 'Supplies', 'mg-seo' ),
						'repeater',
						array(
							'schema_type' => 'HowToSupply',
							'fields'      => array( self::f( 'name', __( 'Supply', 'mg-seo' ) ) ),
						)
					),
					self::f(
						'tool',
						__( 'Tools', 'mg-seo' ),
						'repeater',
						array(
							'schema_type' => 'HowToTool',
							'fields'      => array( self::f( 'name', __( 'Tool', 'mg-seo' ) ) ),
						)
					),
					self::steps( 'step' ),
				),
			),
			'Product'             => array(
				'label'  => __( 'Product', 'mg-seo' ),
				'id'     => 'product',
				'fields' => array(
					$name,
					$desc,
					$image,
					self::f( 'sku', __( 'SKU', 'mg-seo' ) ),
					self::f( 'gtin', __( 'GTIN (EAN/UPC/ISBN)', 'mg-seo' ) ),
					self::f( 'mpn', __( 'MPN', 'mg-seo' ) ),
					self::f(
						'brand',
						__( 'Brand', 'mg-seo' ),
						'group',
						array(
							'schema_type' => 'Brand',
							'inline'      => true,
							'fields'      => array( self::f( 'name', __( 'Brand', 'mg-seo' ) ) ),
						)
					),
					self::offers(),
					self::aggregate_rating(),
					self::reviews(),
				),
			),
			'Event'               => array(
				'label'  => __( 'Event', 'mg-seo' ),
				'id'     => 'event',
				'fields' => array(
					self::f( '@type', __( 'Event type', 'mg-seo' ), 'select', array( 'options' => self::same( array_diff( self::event_types(), array( 'CourseInstance' ) ) ) ) ),
					$name,
					$desc,
					$image,
					self::f( 'startDate', __( 'Start date', 'mg-seo' ), 'datetime' ),
					self::f( 'endDate', __( 'End date', 'mg-seo' ), 'datetime' ),
					self::f(
						'eventStatus',
						__( 'Status', 'mg-seo' ),
						'select',
						array(
							'options' => array(
								'' => '—',
								'https://schema.org/EventScheduled' => __( 'Scheduled', 'mg-seo' ),
								'https://schema.org/EventCancelled' => __( 'Cancelled', 'mg-seo' ),
								'https://schema.org/EventPostponed' => __( 'Postponed', 'mg-seo' ),
								'https://schema.org/EventRescheduled' => __( 'Rescheduled', 'mg-seo' ),
								'https://schema.org/EventMovedOnline' => __( 'Moved online', 'mg-seo' ),
							),
						)
					),
					self::f(
						'eventAttendanceMode',
						__( 'Attendance mode', 'mg-seo' ),
						'select',
						array(
							'options' => array(
								'' => '—',
								'https://schema.org/OfflineEventAttendanceMode' => __( 'In person', 'mg-seo' ),
								'https://schema.org/OnlineEventAttendanceMode' => __( 'Online', 'mg-seo' ),
								'https://schema.org/MixedEventAttendanceMode' => __( 'Mixed', 'mg-seo' ),
							),
						)
					),
					self::f(
						'location',
						__( 'Location', 'mg-seo' ),
						'group',
						array(
							'types'  => array( 'Place', 'VirtualLocation' ),
							'fields' => array(
								self::f( 'name', __( 'Venue name', 'mg-seo' ), 'text', array( 'only' => array( 'Place' ) ) ),
								self::address( array( 'only' => array( 'Place' ) ) ),
								self::f( 'url', __( 'URL', 'mg-seo' ), 'url' ),
							),
						)
					),
					self::offers(),
					self::party( 'performer', __( 'Performer', 'mg-seo' ), array( 'Person', 'PerformingGroup', 'Organization' ) ),
					self::party( 'organizer', __( 'Organizer', 'mg-seo' ), array( 'Organization', 'Person' ) ),
				),
			),
			'Person'              => array(
				'label'  => __( 'Person', 'mg-seo' ),
				'id'     => 'person',
				'fields' => array(
					self::f( 'name', __( 'Name', 'mg-seo' ) ),
					self::f( 'jobTitle', __( 'Job title', 'mg-seo' ) ),
					self::f( 'description', __( 'Description', 'mg-seo' ), 'textarea' ),
					self::f( 'image', __( 'Photo', 'mg-seo' ), 'image' ),
					$url,
					self::f( 'email', __( 'Email', 'mg-seo' ) ),
					self::f( 'telephone', __( 'Phone', 'mg-seo' ) ),
					self::f( 'birthDate', __( 'Birth date', 'mg-seo' ), 'date' ),
					self::address(),
					self::party( 'worksFor', __( 'Works for', 'mg-seo' ), array( 'Organization' ) ),
					$same,
				),
			),
			'Organization'        => array(
				'label'  => __( 'Organization', 'mg-seo' ),
				'id'     => 'organization',
				'fields' => array(
					self::f( '@type', __( 'Organization type', 'mg-seo' ), 'select', array( 'options' => self::same( self::organization_types() ) ) ),
					self::f( 'name', __( 'Name', 'mg-seo' ) ),
					self::f( 'legalName', __( 'Legal name', 'mg-seo' ) ),
					self::f( 'alternateName', __( 'Alternate name', 'mg-seo' ) ),
					self::f( 'description', __( 'Description', 'mg-seo' ), 'textarea' ),
					$url,
					self::f( 'logo', __( 'Logo', 'mg-seo' ), 'image' ),
					self::f( 'email', __( 'Email', 'mg-seo' ) ),
					self::f( 'telephone', __( 'Phone', 'mg-seo' ) ),
					self::address(),
					self::f( 'foundingDate', __( 'Founding date', 'mg-seo' ), 'date' ),
					self::f(
						'contactPoint',
						__( 'Contact points', 'mg-seo' ),
						'repeater',
						array(
							'schema_type' => 'ContactPoint',
							'fields'      => array(
								self::f( 'contactType', __( 'Contact type', 'mg-seo' ), 'text', array( 'placeholder' => 'customer service' ) ),
								self::f( 'telephone', __( 'Phone', 'mg-seo' ) ),
								self::f( 'email', __( 'Email', 'mg-seo' ) ),
							),
						)
					),
					$same,
				),
			),
			'LocalBusiness'       => array(
				'label'  => __( 'Local business', 'mg-seo' ),
				'id'     => 'localbusiness',
				'fields' => array(
					self::f( '@type', __( 'Business type', 'mg-seo' ), 'select', array( 'options' => self::same( self::local_business_types() ) ) ),
					self::f( 'name', __( 'Name', 'mg-seo' ), 'text', array( 'default' => '%org_name%' ) ),
					self::f( 'description', __( 'Description', 'mg-seo' ), 'textarea' ),
					self::f( 'image', __( 'Image', 'mg-seo' ), 'image' ),
					self::f( 'logo', __( 'Logo', 'mg-seo' ), 'image' ),
					$url,
					self::f( 'telephone', __( 'Phone', 'mg-seo' ) ),
					self::f( 'email', __( 'Email', 'mg-seo' ) ),
					self::f( 'priceRange', __( 'Price range', 'mg-seo' ), 'text', array( 'placeholder' => '$$' ) ),
					self::address(),
					self::f(
						'geo',
						__( 'Coordinates', 'mg-seo' ),
						'group',
						array(
							'schema_type' => 'GeoCoordinates',
							'fields'      => array(
								self::f( 'latitude', __( 'Latitude', 'mg-seo' ), 'number', array( 'step' => 'any' ) ),
								self::f( 'longitude', __( 'Longitude', 'mg-seo' ), 'number', array( 'step' => 'any' ) ),
							),
						)
					),
					self::f(
						'openingHoursSpecification',
						__( 'Opening hours', 'mg-seo' ),
						'repeater',
						array(
							'schema_type' => 'OpeningHoursSpecification',
							'fields'      => array(
								self::f( 'dayOfWeek', __( 'Days', 'mg-seo' ), 'checks', array( 'options' => self::days() ) ),
								self::f( 'opens', __( 'Opens', 'mg-seo' ), 'time' ),
								self::f( 'closes', __( 'Closes', 'mg-seo' ), 'time' ),
							),
						)
					),
					self::f( 'servesCuisine', __( 'Cuisine (restaurants)', 'mg-seo' ) ),
					self::f( 'hasMap', __( 'Map URL', 'mg-seo' ), 'url' ),
					self::f( 'areaServed', __( 'Area served', 'mg-seo' ) ),
					self::aggregate_rating(),
					$same,
				),
			),
			'Service'             => array(
				'label'  => __( 'Service', 'mg-seo' ),
				'id'     => 'service',
				'fields' => array(
					$name,
					self::f( 'serviceType', __( 'Service type', 'mg-seo' ) ),
					$desc,
					$image,
					self::party( 'provider', __( 'Provider (empty: the site organization)', 'mg-seo' ), array( 'Organization', 'LocalBusiness', 'Person' ) ),
					self::f( 'areaServed', __( 'Area served', 'mg-seo' ), 'list' ),
					self::f( 'category', __( 'Category', 'mg-seo' ) ),
					self::offers(),
					self::aggregate_rating(),
				),
			),
			'Recipe'              => array(
				'label'  => __( 'Recipe', 'mg-seo' ),
				'id'     => 'recipe',
				'fields' => array(
					$name,
					$desc,
					$image,
					self::party( 'author', __( 'Author (empty: post author)', 'mg-seo' ) ),
					self::f( 'recipeCategory', __( 'Category', 'mg-seo' ) ),
					self::f( 'recipeCuisine', __( 'Cuisine', 'mg-seo' ) ),
					self::f( 'keywords', __( 'Keywords', 'mg-seo' ) ),
					self::f( 'recipeYield', __( 'Yield', 'mg-seo' ), 'text', array( 'placeholder' => '4' ) ),
					self::f( 'prepTime', __( 'Preparation time', 'mg-seo' ), 'duration' ),
					self::f( 'cookTime', __( 'Cooking time', 'mg-seo' ), 'duration' ),
					self::f( 'totalTime', __( 'Total time', 'mg-seo' ), 'duration' ),
					self::f(
						'nutrition',
						__( 'Nutrition', 'mg-seo' ),
						'group',
						array(
							'schema_type' => 'NutritionInformation',
							'fields'      => array( self::f( 'calories', __( 'Calories', 'mg-seo' ), 'text', array( 'placeholder' => '270 calories' ) ) ),
						)
					),
					self::f( 'recipeIngredient', __( 'Ingredients', 'mg-seo' ), 'list' ),
					self::steps( 'recipeInstructions' ),
					self::aggregate_rating(),
				),
			),
			'Course'              => array(
				'label'  => __( 'Course', 'mg-seo' ),
				'id'     => 'course',
				'fields' => array(
					$name,
					$desc,
					self::party( 'provider', __( 'Provider (empty: the site organization)', 'mg-seo' ), array( 'Organization', 'Person' ) ),
					self::f( 'courseCode', __( 'Course code', 'mg-seo' ) ),
					self::offers(),
					self::f(
						'hasCourseInstance',
						__( 'Course instances', 'mg-seo' ),
						'repeater',
						array(
							'schema_type' => 'CourseInstance',
							'fields'      => array(
								self::f( 'courseMode', __( 'Mode', 'mg-seo' ), 'select', array( 'options' => self::same( array( '', 'Online', 'Onsite', 'Blended' ) ) ) ),
								self::f( 'courseWorkload', __( 'Workload (duration)', 'mg-seo' ), 'duration' ),
								self::f( 'startDate', __( 'Start date', 'mg-seo' ), 'date' ),
								self::f( 'endDate', __( 'End date', 'mg-seo' ), 'date' ),
							),
						)
					),
					self::aggregate_rating(),
				),
			),
			'JobPosting'          => array(
				'label'  => __( 'Job posting', 'mg-seo' ),
				'id'     => 'jobposting',
				'fields' => array(
					self::f( 'title', __( 'Job title', 'mg-seo' ), 'text', array( 'default' => '%title%' ) ),
					self::f( 'description', __( 'Description', 'mg-seo' ), 'textarea', array( 'html' => true ) ),
					self::f( 'datePosted', __( 'Date posted (empty: publish date)', 'mg-seo' ), 'date' ),
					self::f( 'validThrough', __( 'Valid through', 'mg-seo' ), 'datetime' ),
					self::f( 'employmentType', __( 'Employment type', 'mg-seo' ), 'checks', array( 'options' => self::same( array( 'FULL_TIME', 'PART_TIME', 'CONTRACTOR', 'TEMPORARY', 'INTERN', 'VOLUNTEER', 'PER_DIEM', 'OTHER' ) ) ) ),
					self::party( 'hiringOrganization', __( 'Hiring organization (empty: the site organization)', 'mg-seo' ), array( 'Organization' ) ),
					self::f(
						'jobLocation',
						__( 'Job location', 'mg-seo' ),
						'group',
						array(
							'schema_type' => 'Place',
							'fields'      => array( self::address() ),
						)
					),
					self::f(
						'jobLocationType',
						__( 'Remote job', 'mg-seo' ),
						'select',
						array(
							'options' => array(
								''            => '—',
								'TELECOMMUTE' => __( 'Fully remote (TELECOMMUTE)', 'mg-seo' ),
							),
						)
					),
					self::f(
						'applicantLocationRequirements',
						__( 'Applicant location (remote jobs)', 'mg-seo' ),
						'group',
						array(
							'schema_type' => 'Country',
							'inline'      => true,
							'fields'      => array( self::f( 'name', __( 'Country', 'mg-seo' ) ) ),
						)
					),
					self::f(
						'baseSalary',
						__( 'Salary', 'mg-seo' ),
						'group',
						array(
							'schema_type' => 'MonetaryAmount',
							'fields'      => array(
								self::f( 'currency', __( 'Currency', 'mg-seo' ), 'text', array( 'placeholder' => 'PLN' ) ),
								self::f(
									'value',
									__( 'Amount', 'mg-seo' ),
									'group',
									array(
										'schema_type' => 'QuantitativeValue',
										'fields'      => array(
											self::f( 'value', __( 'Value', 'mg-seo' ), 'number', array( 'step' => 'any' ) ),
											self::f( 'minValue', __( 'Minimum', 'mg-seo' ), 'number', array( 'step' => 'any' ) ),
											self::f( 'maxValue', __( 'Maximum', 'mg-seo' ), 'number', array( 'step' => 'any' ) ),
											self::f( 'unitText', __( 'Per', 'mg-seo' ), 'select', array( 'options' => self::same( array( '', 'HOUR', 'DAY', 'WEEK', 'MONTH', 'YEAR' ) ) ) ),
										),
									)
								),
							),
						)
					),
					self::f(
						'identifier',
						__( 'Identifier', 'mg-seo' ),
						'group',
						array(
							'schema_type' => 'PropertyValue',
							'fields'      => array(
								self::f( 'name', __( 'Name', 'mg-seo' ) ),
								self::f( 'value', __( 'Value', 'mg-seo' ) ),
							),
						)
					),
					self::f( 'directApply', __( 'Direct apply', 'mg-seo' ), 'toggle' ),
				),
			),
			'SoftwareApplication' => array(
				'label'  => __( 'Software application', 'mg-seo' ),
				'id'     => 'software',
				'fields' => array(
					self::f( '@type', __( 'Application type', 'mg-seo' ), 'select', array( 'options' => self::same( array( 'SoftwareApplication', 'MobileApplication', 'WebApplication', 'VideoGame' ) ) ) ),
					$name,
					$desc,
					$image,
					self::f( 'applicationCategory', __( 'Category', 'mg-seo' ), 'select', array( 'options' => self::same( array( '', 'BusinessApplication', 'DesignApplication', 'DeveloperApplication', 'EducationalApplication', 'EntertainmentApplication', 'FinanceApplication', 'GameApplication', 'HealthApplication', 'LifestyleApplication', 'MultimediaApplication', 'SecurityApplication', 'ShoppingApplication', 'SocialNetworkingApplication', 'TravelApplication', 'UtilitiesApplication' ) ) ) ),
					self::f( 'operatingSystem', __( 'Operating system', 'mg-seo' ) ),
					self::f( 'softwareVersion', __( 'Version', 'mg-seo' ) ),
					self::offers(),
					self::aggregate_rating(),
				),
			),
			'VideoObject'         => array(
				'label'  => __( 'Video', 'mg-seo' ),
				'id'     => 'video',
				'fields' => array(
					$name,
					$desc,
					self::f( 'thumbnailUrl', __( 'Thumbnail URL', 'mg-seo' ), 'image' ),
					self::f( 'uploadDate', __( 'Upload date', 'mg-seo' ), 'datetime' ),
					self::f( 'duration', __( 'Duration', 'mg-seo' ), 'duration' ),
					self::f( 'contentUrl', __( 'Video file URL', 'mg-seo' ), 'url' ),
					self::f( 'embedUrl', __( 'Embed URL', 'mg-seo' ), 'url' ),
				),
			),
			'Book'                => array(
				'label'  => __( 'Book', 'mg-seo' ),
				'id'     => 'book',
				'fields' => array(
					$name,
					$desc,
					$image,
					self::party( 'author', __( 'Author', 'mg-seo' ) ),
					$url,
					self::f( 'isbn', __( 'ISBN', 'mg-seo' ) ),
					self::f(
						'bookFormat',
						__( 'Format', 'mg-seo' ),
						'select',
						array(
							'options' => array(
								''                         => '—',
								'https://schema.org/Hardcover' => __( 'Hardcover', 'mg-seo' ),
								'https://schema.org/Paperback' => __( 'Paperback', 'mg-seo' ),
								'https://schema.org/EBook' => __( 'E-book', 'mg-seo' ),
								'https://schema.org/AudiobookFormat' => __( 'Audiobook', 'mg-seo' ),
							),
						)
					),
					self::f( 'numberOfPages', __( 'Pages', 'mg-seo' ), 'number' ),
					self::f( 'datePublished', __( 'Publication date', 'mg-seo' ), 'date' ),
					self::party( 'publisher', __( 'Publisher', 'mg-seo' ), array( 'Organization', 'Person' ) ),
					self::aggregate_rating(),
				),
			),
			'Review'              => array(
				'label'  => __( 'Review', 'mg-seo' ),
				'id'     => 'review',
				'fields' => array(
					self::f( 'name', __( 'Review title', 'mg-seo' ), 'text', array( 'default' => '%title%' ) ),
					self::f(
						'itemReviewed',
						__( 'Reviewed item', 'mg-seo' ),
						'group',
						array(
							'types'  => array( 'Product', 'Book', 'Course', 'Event', 'LocalBusiness', 'Movie', 'Organization', 'Recipe', 'SoftwareApplication', 'CreativeWorkSeason', 'Game', 'MusicRecording' ),
							'fields' => array(
								self::f( 'name', __( 'Name', 'mg-seo' ) ),
								self::f( 'image', __( 'Image', 'mg-seo' ), 'image' ),
								self::f( 'url', __( 'URL', 'mg-seo' ), 'url' ),
							),
						)
					),
					self::rating(),
					self::party( 'author', __( 'Author (empty: post author)', 'mg-seo' ) ),
					self::f( 'reviewBody', __( 'Review text', 'mg-seo' ), 'textarea', array( 'default' => '%excerpt%' ) ),
					self::f( 'datePublished', __( 'Date (empty: publish date)', 'mg-seo' ), 'date' ),
				),
			),
		);

		/**
		 * Filter schema generator type definitions.
		 *
		 * @param array $defs Definitions keyed by type.
		 */
		self::$defs = apply_filters( 'mgseo/schema/types', $defs );
		return self::$defs;
	}

	/**
	 * Definition of one type.
	 *
	 * @param string $type Type.
	 * @return array<string, mixed>|null
	 */
	public static function get( string $type ): ?array {
		return self::all()[ $type ] ?? null;
	}

	/**
	 * Days of week options.
	 *
	 * @return array<string, string>
	 */
	public static function days(): array {
		return array(
			'Monday'    => __( 'Mon', 'mg-seo' ),
			'Tuesday'   => __( 'Tue', 'mg-seo' ),
			'Wednesday' => __( 'Wed', 'mg-seo' ),
			'Thursday'  => __( 'Thu', 'mg-seo' ),
			'Friday'    => __( 'Fri', 'mg-seo' ),
			'Saturday'  => __( 'Sat', 'mg-seo' ),
			'Sunday'    => __( 'Sun', 'mg-seo' ),
		);
	}

	/**
	 * Default data for a new entity of a type.
	 *
	 * @param string $type Type.
	 * @return array<string, mixed>
	 */
	public static function defaults( string $type ): array {
		$def  = self::get( $type );
		$data = array();
		foreach ( $def['fields'] ?? array() as $field ) {
			if ( isset( $field['default'] ) && '' !== $field['default'] ) {
				$data[ $field['key'] ] = $field['default'];
			}
		}
		return $data;
	}

	/**
	 * Clear cache (tests, locale switch).
	 */
	public static function reset(): void {
		self::$defs = null;
	}

	/**
	 * Sanitize entity data against a field list. Unknown keys are dropped.
	 *
	 * @param array $fields Fields.
	 * @param mixed $data   Raw data.
	 * @param array $types  Allowed @type values for this level (first = default), empty = keep none.
	 * @return array<string, mixed>
	 */
	public static function sanitize_data( array $fields, mixed $data, array $types = array() ): array {
		$data = is_array( $data ) ? $data : array();
		$out  = array();
		if ( $types ) {
			$type         = isset( $data['@type'] ) && is_string( $data['@type'] ) && in_array( $data['@type'], $types, true ) ? $data['@type'] : $types[0];
			$out['@type'] = $type;
		}
		foreach ( $fields as $field ) {
			$key = $field['key'];
			if ( ! array_key_exists( $key, $data ) ) {
				continue;
			}
			$value = self::sanitize_value( $field, $data[ $key ] );
			if ( '@type' === $key ) {
				if ( is_string( $value ) && '' !== $value ) {
					$out['@type'] = $value;
				}
				continue;
			}
			if ( null === $value || '' === $value || array() === $value ) {
				continue;
			}
			$out[ $key ] = $value;
		}
		return $out;
	}

	/**
	 * Sanitize one field value.
	 *
	 * @param array $field Field.
	 * @param mixed $value Raw value.
	 * @return mixed
	 */
	public static function sanitize_value( array $field, mixed $value ): mixed {
		$type = $field['type'] ?? 'text';
		switch ( $type ) {
			case 'group':
				if ( ! is_array( $value ) ) {
					// Inline groups accept a plain string for their single field.
					if ( is_scalar( $value ) && ! empty( $field['inline'] ) && '' !== trim( (string) $value ) ) {
						$value = array( $field['fields'][0]['key'] => $value );
					} else {
						return null;
					}
				}
				$types = $field['types'] ?? ( isset( $field['schema_type'] ) ? array( $field['schema_type'] ) : array() );
				$sub   = self::sanitize_data( $field['fields'] ?? array(), $value, $types );
				return count( array_diff_key( $sub, array( '@type' => 1 ) ) ) ? $sub : null;
			case 'repeater':
				$items = array();
				foreach ( is_array( $value ) ? array_values( $value ) : array() as $item ) {
					$types = $field['types'] ?? ( isset( $field['schema_type'] ) ? array( $field['schema_type'] ) : array() );
					$sub   = self::sanitize_data( $field['fields'] ?? array(), $item, $types );
					if ( count( array_diff_key( $sub, array( '@type' => 1 ) ) ) ) {
						$items[] = $sub;
					}
				}
				return $items;
			case 'list':
				$list = is_array( $value ) ? $value : preg_split( '/\r\n|\r|\n/', (string) $value );
				$list = array_map( static fn( $v ) => self::sanitize_scalar( 'url' === ( $field['item'] ?? '' ) ? 'url' : 'text', $v ), (array) $list );
				return array_values( array_filter( $list, static fn( $v ) => '' !== $v ) );
			case 'checks':
				$list    = is_array( $value ) ? array_map( 'strval', $value ) : array();
				$allowed = array_map( 'strval', array_keys( $field['options'] ?? array() ) );
				return array_values( array_intersect( $list, $allowed ) );
			case 'toggle':
				return ! empty( $value ) && 'false' !== $value ? true : null;
			case 'select':
				$value = is_scalar( $value ) ? (string) $value : '';
				if ( isset( $field['options'] ) && ! array_key_exists( $value, $field['options'] ) && ! self::has_variable( $value ) ) {
					return '';
				}
				return sanitize_text_field( $value );
			default:
				return self::sanitize_scalar( $type, $value, ! empty( $field['html'] ) );
		}
	}

	/**
	 * Sanitize a scalar value.
	 *
	 * @param string $type Field type.
	 * @param mixed  $value Value.
	 * @param bool   $html Allow limited HTML.
	 */
	public static function sanitize_scalar( string $type, mixed $value, bool $html = false ): string {
		if ( ! is_scalar( $value ) ) {
			return '';
		}
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return '';
		}
		switch ( $type ) {
			case 'url':
			case 'image':
				if ( self::has_variable( $value ) ) {
					return sanitize_text_field( $value );
				}
				return esc_url_raw( $value );
			case 'textarea':
				return $html ? trim( wp_kses( $value, self::allowed_html() ) ) : sanitize_textarea_field( $value );
			case 'number':
				$value = str_replace( ',', '.', sanitize_text_field( $value ) );
				return is_numeric( $value ) || self::has_variable( $value ) ? $value : '';
			default:
				return sanitize_text_field( $value );
		}
	}

	/**
	 * HTML allowed in answers / instructions (tags Google accepts in FAQ answers).
	 *
	 * @return array<string, array<string, bool>>
	 */
	public static function allowed_html(): array {
		$tags = array();
		foreach ( array( 'p', 'br', 'ol', 'ul', 'li', 'b', 'strong', 'i', 'em', 'div', 'h2', 'h3', 'h4', 'h5', 'h6' ) as $tag ) {
			$tags[ $tag ] = array();
		}
		$tags['a'] = array(
			'href'   => true,
			'title'  => true,
			'target' => true,
			'rel'    => true,
		);
		return $tags;
	}

	/**
	 * Whether a string contains a %variable%.
	 *
	 * @param string $value Value.
	 */
	public static function has_variable( string $value ): bool {
		return (bool) preg_match( '/%[a-z0-9_]+(?:\([^)%]*\))?%/i', $value );
	}

	/**
	 * Definitions for the admin script (labels already translated).
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function for_js(): array {
		$out = array();
		foreach ( self::all() as $type => $def ) {
			$out[ $type ] = array(
				'label'    => $def['label'],
				'fields'   => $def['fields'],
				'defaults' => self::defaults( $type ),
			);
		}
		return $out;
	}
}
