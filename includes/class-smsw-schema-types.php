<?php
/**
 * Hardcoded schema.org type definitions and common properties.
 *
 * @package Shied_World_Schema_Markup
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Schema type registry for Phase 2.
 */
class SMSW_Schema_Types {

	/**
	 * Custom / Other option key.
	 *
	 * @var string
	 */
	const CUSTOM_TYPE = 'Custom';

	/**
	 * Get predefined schema types.
	 *
	 * @return array<string, string> Type key => label.
	 */
	public static function get_types() {
		return array(
			'Article'             => 'Article',
			'Product'             => 'Product',
			'LocalBusiness'       => 'LocalBusiness',
			'Organization'        => 'Organization',
			'FAQPage'             => 'FAQPage',
			'HowTo'               => 'HowTo',
			'Review'              => 'Review',
			'Event'               => 'Event',
			'Person'              => 'Person',
			'Service'             => 'Service',
			'WebPage'             => 'WebPage',
			'AboutPage'           => 'AboutPage',
			'ContactPage'         => 'ContactPage',
			'BreadcrumbList'      => 'BreadcrumbList',
			'JobPosting'          => 'JobPosting',
			'Course'              => 'Course',
			'SoftwareApplication' => 'SoftwareApplication',
			'Recipe'              => 'Recipe',
			'CreativeWork'        => 'CreativeWork',
			self::CUSTOM_TYPE     => __( 'Custom / Other', 'shied-world-schema-markup' ),
		);
	}

	/**
	 * Get schema types grouped by category for the searchable dropdown.
	 *
	 * Every supported type key appears in exactly one group.
	 *
	 * @return array<string, array<int,string>> Group label => type keys.
	 */
	public static function get_type_groups() {
		$groups = array(
			'Content'           => array( 'Article', 'Recipe', 'HowTo', 'CreativeWork', 'Course' ),
			'Business'          => array( 'LocalBusiness', 'Organization', 'Service', 'JobPosting' ),
			'Commerce'          => array( 'Product', 'Review', 'SoftwareApplication' ),
			'People and Events' => array( 'Person', 'Event' ),
			'Pages'             => array( 'WebPage', 'AboutPage', 'ContactPage', 'BreadcrumbList' ),
			'Other'             => array( 'FAQPage' ),
		);

		$custom_label = __( 'Custom / Other', 'shied-world-schema-markup' );

		return array(
			$custom_label => array( self::CUSTOM_TYPE ),
		) + $groups;
	}

	/**
	 * Map vocabulary range data to an editor field type.
	 *
	 * @param mixed $range Range data from the parser.
	 * @return string
	 */
	private static function vocab_field_type( $range ) {
		$ranges = is_array( $range ) ? $range : array( $range );
		$ranges = array_map( 'strval', $ranges );
		if ( in_array( 'URL', $ranges, true ) ) {
			return 'url';
		}
		if ( array_intersect( array( 'Number', 'Integer', 'Float' ), $ranges ) ) {
			return 'number';
		}
		if ( in_array( 'DateTime', $ranges, true ) ) {
			return 'datetime-local';
		}
		if ( in_array( 'Date', $ranges, true ) ) {
			return 'date';
		}
		if ( in_array( 'Boolean', $ranges, true ) ) {
			return 'select';
		}
		if ( count( $ranges ) === 1 && in_array( $ranges[0], array( 'Text', 'CssSelectorType', 'PronounceableText', 'XPathType' ), true ) ) {
			return 'text';
		}
		if ( ! empty( $ranges ) && count( array_intersect( $ranges, array( 'Text', 'URL', 'Number', 'Integer', 'Float', 'Date', 'DateTime', 'Boolean', 'Time' ) ) ) === 0 ) {
			return 'textarea';
		}
		return 'text';
	}

	/**
	 * Generic placeholder defaults keyed by property name only.
	 *
	 * Applies to every type so new vocabulary types get the same helpful
	 * defaults the hardcoded common types already use.
	 *
	 * @param string $name Property name.
	 * @return string
	 */
	private static function vocab_default_placeholder( $name ) {
		static $map = array(
			'name'          => '{{post_title}}',
			'headline'      => '{{post_title}}',
			'description'   => '{{meta_description}}',
			'url'           => '{{post_url}}',
			'image'         => '{{featured_image}}',
			'datePublished' => '{{post_date}}',
		);
		return isset( $map[ $name ] ) ? $map[ $name ] : '';
	}

	/**
	 * Reduces a schema.org property description to one short hint line.
	 *
	 * rdfs:comment values run from a short clause to a full sentence or more.
	 * The builder shows these as inline helper text under a field, so the text
	 * is cut to the first sentence and then hard-capped. Whitespace is
	 * collapsed, and markup is stripped, because a few comments in the
	 * vocabulary carry inline tags.
	 *
	 * @param string $description Raw rdfs:comment text.
	 * @return string One-line hint, or an empty string when there is none.
	 */
	private static function shorten_hint( $description ) {
		$text = wp_strip_all_tags( (string) $description, true );
		$text = html_entity_decode( $text, ENT_QUOTES, 'UTF-8' );
		$text = preg_replace( '/\s+/u', ' ', $text );
		$text = trim( (string) $text );

		if ( '' === $text ) {
			return '';
		}

		// Keep the first sentence only, but do not cut in the middle of an
		// abbreviation such as "e.g." or a decimal number.
		if ( preg_match( '/^(.{20,}?)[.!?](?:\s|$)/u', $text, $m ) ) {
			$text = $m[1];
		}

		$limit = 120;
		if ( self::strlen( $text ) > $limit ) {
			$cut   = self::substr( $text, 0, $limit );
			$space = self::strrpos( $cut, ' ' );
			if ( false !== $space && $space > 40 ) {
				$cut = self::substr( $cut, 0, $space );
			}
			$text = rtrim( $cut ) . '…';
		}

		return $text;
	}

	/**
	 * Multibyte-safe strlen.
	 *
	 * Property descriptions are ASCII in practice, but a few carry
	 * non-Latin text, and mbstring is not guaranteed to be present.
	 *
	 * @param string $text Subject.
	 * @return int
	 */
	private static function strlen( $text ) {
		if ( function_exists( 'mb_strlen' ) ) {
			return (int) mb_strlen( $text, 'UTF-8' );
		}
		return (int) strlen( $text );
	}

	/**
	 * Multibyte-safe substr.
	 *
	 * @param string   $text   Subject.
	 * @param int      $start  Start offset.
	 * @param int|null $length Optional length.
	 * @return string
	 */
	private static function substr( $text, $start, $length = null ) {
		if ( function_exists( 'mb_substr' ) ) {
			return (string) mb_substr( $text, $start, $length, 'UTF-8' );
		}
		return null === $length ? substr( $text, $start ) : substr( $text, $start, $length );
	}

	/**
	 * Multibyte-safe strrpos.
	 *
	 * @param string $haystack Subject.
	 * @param string $needle   Needle.
	 * @return int|false
	 */
	private static function strrpos( $haystack, $needle ) {
		if ( function_exists( 'mb_strrpos' ) ) {
			$pos = mb_strrpos( $haystack, $needle, 0, 'UTF-8' );
			return false === $pos ? false : (int) $pos;
		}
		return strrpos( $haystack, $needle );
	}

	/**
	 * Merge vocabulary definitions into a hardcoded type's field list.
	 *
	 * Legacy flat helper keys such as offers_price are removed whenever
	 * the vocabulary provides a nested sub-form for the same property, and
	 * every omitted placeholder is filled with a bare sample value.
	 *
	 * @param array<string,array> $existing Hardcoded definitions.
	 * @param array<string,array> $vocab    Vocabulary definitions.
	 * @return array<string,array>
	 */
	private static function merge_vocab_into_hardcoded( $existing, $vocab ) {
		$out = $existing;
		foreach ( $vocab as $k => $def ) {
			if ( ! isset( $out[ $k ] ) ) {
				$out[ $k ] = $def;
				continue;
			}

			// Preserve handcrafted category metadata when available.
			if ( isset( $out[ $k ]['category'] ) && ! isset( $def['category'] ) ) {
				$def['category'] = $out[ $k ]['category'];
			}

			$out[ $k ] = $def;
		}
		foreach ( $vocab as $k => $def ) {
			if ( empty( $def['nestedType'] ) ) {
				continue;
			}
			$prefix = (string) $k . '_';
			foreach ( array_keys( $out ) as $flat ) {
				if ( 0 === strpos( (string) $flat, $prefix ) ) {
					unset( $out[ $flat ] );
				}
			}
			if ( isset( $out[ $k ]['type'] ) && in_array( $out[ $k ]['type'], array( 'text', 'textarea' ), true ) ) {
				$out[ $k ] = $def;
			}
		}
		foreach ( $out as $k => $def ) {
			if ( empty( $def['placeholder'] ) && ! array_key_exists( 'nestedType', $def ) ) {
				$out[ $k ]['placeholder'] = self::vocab_leaf_placeholder( (string) $k, self::range_from_type( isset( $def['type'] ) ? $def['type'] : 'text' ) );
			}
		}
		return $out;
	}

	/**
	 * Bare value placeholder for a property based on its expected range.
	 *
	 * Never returns JSON structure. Only a plain sample value for the
	 * expected data type so users never see syntax in a placeholder.
	 *
	 * @param string $name Property name.
	 * @param mixed  $range Range data from the parser.
	 * @return string
	 */
	private static function vocab_leaf_placeholder( $name, $range ) {
		$ranges  = is_array( $range ) ? $range : array( $range );
		$ranges  = array_map( 'strval', $ranges );
		$name_lc = strtolower( (string) $name );
		$name_hy = preg_replace( '/([a-z0-9])([A-Z])/', '$1 $2', (string) $name );
		$name_hy = preg_replace( '/[_\\-]+/', ' ', $name_hy );
		$name_hy = trim( ucwords( $name_hy ) );

		// URL type: realistic URL example.
		if ( in_array( 'URL', $ranges, true ) ) {
			if ( false !== strpos( $name_lc, 'map' ) ) {
				return 'https://maps.example.com/?q=123+Main+St'; }
			if ( false !== strpos( $name_lc, 'logo' ) ) {
				return '{{site_logo}}'; }
			if ( false !== strpos( $name_lc, 'image' ) ) {
				return 'https://example.com/images/logo.png'; }
			return 'https://example.com/' . strtolower( preg_replace( '/[^a-z0-9]+/i', '-', (string) $name ) );
		}

		// Number type: realistic number based on property name.
		if ( array_intersect( array( 'Number', 'Integer', 'Float' ), $ranges ) ) {
			if ( false !== strpos( $name_lc, 'rating' ) ) {
				return '4.5'; }
			if ( false !== strpos( $name_lc, 'reviewcount' ) || false !== strpos( $name_lc, 'ratingcount' ) ) {
				return '127'; }
			if ( false !== strpos( $name_lc, 'bestrating' ) ) {
				return '5'; }
			if ( false !== strpos( $name_lc, 'worstrating' ) ) {
				return '1'; }
			if ( false !== strpos( $name_lc, 'price' ) ) {
				return '19.99'; }
			if ( false !== strpos( $name_lc, 'latitude' ) || false !== strpos( $name_lc, 'lat' ) ) {
				return '40.7128'; }
			if ( false !== strpos( $name_lc, 'longitude' ) || false !== strpos( $name_lc, 'lng' ) ) {
				return '-74.0060'; }
			if ( false !== strpos( $name_lc, 'weight' ) ) {
				return '1.5'; }
			if ( false !== strpos( $name_lc, 'height' ) ) {
				return '120'; }
			if ( false !== strpos( $name_lc, 'width' ) ) {
				return '80'; }
			if ( false !== strpos( $name_lc, 'duration' ) ) {
				return '90'; }
			if ( false !== strpos( $name_lc, 'attendee' ) || false !== strpos( $name_lc, 'capacity' ) ) {
				return '250'; }
			if ( false !== strpos( $name_lc, 'employee' ) || false !== strpos( $name_lc, 'staff' ) ) {
				return '50'; }
			if ( false !== strpos( $name_lc, 'year' ) ) {
				return '2026'; }
			if ( false !== strpos( $name_lc, 'percent' ) || false !== strpos( $name_lc, 'percentage' ) ) {
				return '85'; }
			if ( false !== strpos( $name_lc, 'age' ) ) {
				return '35'; }
			if ( false !== strpos( $name_lc, 'quantity' ) || false !== strpos( $name_lc, 'amount' ) ) {
				return '3'; }
			if ( false !== strpos( $name_lc, 'count' ) ) {
				return '127'; }
			if ( false !== strpos( $name_lc, 'value' ) ) {
				return '4.5'; }
			return '42';
		}

		// DateTime type.
		if ( in_array( 'DateTime', $ranges, true ) ) {
			if ( false !== strpos( $name_lc, 'start' ) ) {
				return '2026-09-13T09:00:00+00:00'; }
			if ( false !== strpos( $name_lc, 'end' ) ) {
				return '2026-09-13T17:00:00+00:00'; }
			if ( false !== strpos( $name_lc, 'expire' ) || false !== strpos( $name_lc, 'valid' ) || false !== strpos( $name_lc, 'through' ) ) {
				return '2027-01-01T00:00:00+00:00'; }
			return '2026-09-13T19:00:00+00:00';
		}

		// Date type.
		if ( in_array( 'Date', $ranges, true ) ) {
			if ( false !== strpos( $name_lc, 'birth' ) ) {
				return '1990-05-15'; }
			if ( false !== strpos( $name_lc, 'death' ) ) {
				return '2050-12-31'; }
			if ( false !== strpos( $name_lc, 'found' ) ) {
				return '2010-03-22'; }
			if ( false !== strpos( $name_lc, 'start' ) ) {
				return '2026-09-13'; }
			if ( false !== strpos( $name_lc, 'end' ) ) {
				return '2026-12-31'; }
			if ( false !== strpos( $name_lc, 'post' ) ) {
				return '2026-09-13'; }
			if ( false !== strpos( $name_lc, 'modif' ) ) {
				return '2026-09-14'; }
			return '2026-09-13';
		}

		// Time type.
		if ( in_array( 'Time', $ranges, true ) ) {
			if ( false !== strpos( $name_lc, 'start' ) || false !== strpos( $name_lc, 'open' ) ) {
				return '09:00:00'; }
			if ( false !== strpos( $name_lc, 'end' ) || false !== strpos( $name_lc, 'close' ) ) {
				return '17:00:00'; }
			return '12:00:00';
		}

		// Boolean type: no placeholder.
		if ( in_array( 'Boolean', $ranges, true ) ) {
			return ''; }

		// Specific text examples based on property name.
		$text_map = array(
			'telephone'                 => '+1-555-0100',
			'email'                     => 'info@example.com',
			'faxnumber'                 => '+1-555-0101',
			'jobtitle'                  => 'Senior Attorney',
			'title'                     => 'Senior Attorney',
			'legalname'                 => 'Acme Law Group LLC',
			'naics'                     => '541110',
			'isicv4'                    => '6910',
			'duns'                      => '123456789',
			'leicode'                   => '549300ABCDEFGHIJKL12',
			'vatid'                     => 'US123456789',
			'taxid'                     => '12-3456789',
			'currenciesaccepted'        => 'USD, EUR',
			'paymentaccepted'           => 'Cash, Credit Card',
			'pricerange'                => '$$',
			'servescuisine'             => 'Italian',
			'addresslocality'           => 'New York',
			'addressregion'             => 'NY',
			'postalcode'                => '10001',
			'streetaddress'             => '123 Main Street',
			'addresscountry'            => 'US',
			'openinghours'              => 'Mo-Fr 09:00-17:00',
			'hoursavailable'            => 'Mo-Fr 09:00-17:00',
			'smokingallowed'            => 'False',
			'petsallowed'               => 'True',
			'color'                     => 'Midnight Blue',
			'material'                  => 'Stainless Steel',
			'size'                      => 'Large',
			'validthrough'              => '2027-01-01',
			'employmenttype'            => 'Full-time',
			'basesalary'                => '85000',
			'maxprice'                  => '99.99',
			'minprice'                  => '9.99',
			'pricecurrency'             => 'USD',
			'availability'              => 'https://schema.org/InStock',
			'itemcondition'             => 'https://schema.org/NewCondition',
			'category'                  => 'Legal Services',
			'brand'                     => 'Acme',
			'model'                     => 'Model X',
			'sku'                       => 'SKU-67890',
			'gtin'                      => '0123456789012',
			'isbn'                      => '978-3-16-148410-0',
			'numberofpages'             => '320',
			'inlanguage'                => 'en',
			'abstract'                  => 'A brief summary of the content',
			'alternateheadline'         => 'Alternative Headline Here',
			'accessmode'                => 'textual',
			'encoding'                  => 'UTF-8',
			'genre'                     => 'Legal',
			'copyrightyear'             => '2026',
			'version'                   => '2.1.0',
			'operatingsystem'           => 'Windows 11',
			'applicationcategory'       => 'BusinessApplication',
			'fileformat'                => 'application/pdf',
			'contentrating'             => 'PG-13',
			'playerType'                => 'HTML5',
			'typicalagerange'           => '18-65',
			'alumniof'                  => 'Harvard Law School',
			'award'                     => 'Best Law Firm 2025',
			'knowsabout'                => 'Contract Law, Intellectual Property',
			'knowslanguage'             => 'English, Spanish',
			'memberof'                  => 'American Bar Association',
			'affiliation'               => 'American Bar Association',
			'sponsor'                   => 'LegalTech Inc.',
			'author'                    => 'Jane Smith',
			'publisher'                 => 'Acme Publishing',
			'producer'                  => 'Jane Smith',
			'director'                  => 'John Doe',
			'contributor'               => 'Jane Smith',
			'sourceorganization'        => 'Acme Research',
			'about'                     => 'A comprehensive guide to contract law',
			'additionaltype'            => 'https://example.com/type/CustomType',
			'alternatename'             => 'Acme Law',
			'disambiguatingdescription' => 'A law firm specializing in contract law',
			'identifier'                => 'LAW-001',
			'sameas'                    => 'https://www.linkedin.com/company/acme-law',
			'subjectof'                 => 'https://example.com/about',
			'url'                       => 'https://example.com',
		);

		if ( isset( $text_map[ $name_lc ] ) ) {
			return $text_map[ $name_lc ]; }

		// Pattern-based fallback for text properties.
		if ( false !== strpos( $name_lc, 'name' ) || false !== strpos( $name_lc, 'title' ) ) {
			return 'Acme Corporation'; }
		if ( false !== strpos( $name_lc, 'description' ) || false !== strpos( $name_lc, 'summary' ) || false !== strpos( $name_lc, 'abstract' ) ) {
			return 'A brief description of the item.'; }
		if ( false !== strpos( $name_lc, 'address' ) || false !== strpos( $name_lc, 'street' ) ) {
			return '123 Main Street'; }
		if ( false !== strpos( $name_lc, 'organization' ) || false !== strpos( $name_lc, 'company' ) ) {
			return 'Acme Corporation'; }
		if ( false !== strpos( $name_lc, 'person' ) || false !== strpos( $name_lc, 'author' ) || false !== strpos( $name_lc, 'creator' ) ) {
			return 'Jane Smith'; }
		if ( false !== strpos( $name_lc, 'country' ) || false !== strpos( $name_lc, 'region' ) || false !== strpos( $name_lc, 'state' ) ) {
			return 'United States'; }
		if ( false !== strpos( $name_lc, 'city' ) || false !== strpos( $name_lc, 'locality' ) ) {
			return 'New York'; }
		if ( false !== strpos( $name_lc, 'language' ) ) {
			return 'English'; }
		if ( false !== strpos( $name_lc, 'currency' ) ) {
			return 'USD'; }
		if ( false !== strpos( $name_lc, 'format' ) || false !== strpos( $name_lc, 'encoding' ) ) {
			return 'UTF-8'; }
		if ( false !== strpos( $name_lc, 'version' ) ) {
			return '2.1.0'; }
		if ( false !== strpos( $name_lc, 'category' ) || false !== strpos( $name_lc, 'type' ) ) {
			return 'Legal Services'; }
		if ( false !== strpos( $name_lc, 'identifier' ) || false !== strpos( $name_lc, 'code' ) ) {
			return 'ID-12345'; }
		if ( false !== strpos( $name_lc, 'keyword' ) || false !== strpos( $name_lc, 'tag' ) ) {
			return 'legal, attorney, law'; }
		if ( false !== strpos( $name_lc, 'status' ) ) {
			return 'Active'; }

		// Generic fallback.
		return 'Sample ' . $name_hy;
	}

	/**
	 * Return the nested type name for a range, or empty for a basic type.
	 *
	 * @param mixed $range Range data from the parser.
	 * @return string
	 */
	private static function vocab_nested_type( $range ) {
		$ranges = is_array( $range ) ? $range : array( $range );
		$ranges = array_map( 'strval', $ranges );
		$basic  = array( 'Text', 'URL', 'Number', 'Integer', 'Float', 'Date', 'DateTime', 'Boolean', 'Time', 'CssSelectorType', 'PronounceableText', 'XPathType' );
		foreach ( $ranges as $r ) {
			if ( '' !== $r && ! in_array( $r, $basic, true ) ) {
				return $r;
			}
		}
		return '';
	}

	/**
	 * Pseudo range for a legacy field type used to fill missing placeholders.
	 *
	 * @param string $type Field type.
	 * @return array<int,string>
	 */
	private static function range_from_type( $type ) {
		if ( 'url' === $type ) {
			return array( 'URL' );
		}
		if ( 'number' === $type ) {
			return array( 'Number' );
		}
		if ( 'date' === $type ) {
			return array( 'Date' );
		}
		if ( 'datetime-local' === $type ) {
			return array( 'DateTime' );
		}
		return array( 'Text' );
	}

	/**
	 * Get common property field definitions for a type.
	 *
	 * Each property: key => array( label, type [text|textarea|url], placeholder hint ).
	 *
	 * @param string $type Schema @type.
	 * @return array<string, array{label:string,type:string,placeholder:string}>
	 */
	public static function get_properties( $type ) {
		return self::get_type_definitions( $type );
	}

	/**
	 * In-request memo of the resolved definitions for one type.
	 *
	 * @var array<string,array<string,mixed>>
	 */
	private static $type_defs_memo = array();

	/**
	 * Property definitions for exactly one schema type.
	 *
	 * This is the resolver every request path uses. It merges the curated
	 * definitions for the type with the properties the bundled schema.org
	 * vocabulary declares for it, including everything it inherits, and
	 * returns editor-ready field definitions.
	 *
	 * It deliberately does not call get_all_property_definitions(). Building
	 * every type at once is what exhausted the PHP memory limit, and no caller
	 * needs more than the types a page actually touches.
	 *
	 * @param string $type Schema @type.
	 * @return array<string, array<string,mixed>> Field definitions keyed by property.
	 */
	private static function get_type_definitions( $type ) {
		$type = (string) $type;

		if ( '' === $type || self::CUSTOM_TYPE === $type ) {
			return array();
		}

		if ( isset( self::$type_defs_memo[ $type ] ) ) {
			return self::$type_defs_memo[ $type ];
		}

		$hardcoded = self::get_hardcoded_property_definitions();
		$base      = isset( $hardcoded[ $type ] ) && is_array( $hardcoded[ $type ] ) ? $hardcoded[ $type ] : array();
		$vocab     = self::build_vocabulary_properties( $type );

		if ( empty( $base ) ) {
			$defs = self::with_autofill_category( $vocab );
		} elseif ( empty( $vocab ) ) {
			$defs = self::with_autofill_category( $base );
		} else {
			$defs = self::with_autofill_category( self::merge_vocab_into_hardcoded( $base, $vocab ) );
		}

		self::$type_defs_memo[ $type ] = $defs;

		return $defs;
	}

	/**
	 * Essential property keys for exactly one schema type.
	 *
	 * The per type counterpart of get_essential_property_map(), so a page can
	 * ask for the fields it is about to render without classifying every type
	 * in the vocabulary first.
	 *
	 * @param string $type Schema @type.
	 * @return array<int,string> Ordered essential property keys.
	 */
	public static function get_essential_keys( $type ) {
		$type = (string) $type;

		if ( '' === $type || self::CUSTOM_TYPE === $type ) {
			return array();
		}

		return self::classify_type( $type, self::get_type_definitions( $type ) );
	}


	/**
	 * In-request copy of the property definitions.
	 *
	 * get_all_property_definitions() is called more than once in a single admin
	 * request: the builder payload needs the full set, and the essential field
	 * map needs it again to classify each type. Without this memo the second
	 * call re-reads the cached blob and unserialises a second, complete copy of
	 * it, which on a 256 MB host exhausts the memory limit and takes the whole
	 * admin page down with a fatal error.
	 *
	 * PHP arrays are copy-on-write, so handing the same array to every caller
	 * costs no extra memory as long as none of them modify it.
	 *
	 * @var array<string,mixed>|null
	 */
	private static $definitions_memo = null;

	/**
	 * In-request copy of the essential property map.
	 *
	 * @var array<string,array<int,string>>|null
	 */
	private static $essential_map_memo = null;

	/**
	 * All property definitions for every schema.org type.
	 *
	 * Returns the complete official property set for all schema.org classes, with
	 * inherited properties, declaring_class, depth_level, superseded_by,
	 * and correct placeholders.
	 *
	 * This is a bulk helper kept for maintenance and tooling. It is NOT used on
	 * any request path, and the result is deliberately not stored in a
	 * transient: the array is roughly 16 MB once serialised, and reading a row
	 * that large back out of the database is what exhausted the PHP memory
	 * limit and took the admin down with it. Use get_type_definitions() or
	 * get_essential_keys() for a single type instead.
	 *
	 * @return array<string, array<string, array{label:string,type:string,placeholder:string,depth:int,nestedType:string}>>
	 */
	public static function get_all_property_definitions() {
		if ( is_array( self::$definitions_memo ) ) {
			return self::$definitions_memo;
		}

		$all = self::build_complete_property_definitions();

		foreach ( $all as $type => $defs ) {
			$all[ $type ] = self::with_autofill_category( $defs );
		}

		self::$definitions_memo = $all;

		return self::$definitions_memo;
	}

	/**
	 * Build the complete property definitions array for all 928 types.
	 *
	 * 928 is the real vocabulary size. Public facing copy rounds this down to
	 * "765+" on purpose; see render_about() in the settings screen.
	 *
	 * @return array<string, array<string, array{label:string,type:string,placeholder:string,depth:int,nestedType:string}>>
	 */
	private static function build_complete_property_definitions() {
		$all = array();

		$hardcoded = self::get_hardcoded_property_definitions();
		if ( is_array( $hardcoded ) ) {
			foreach ( $hardcoded as $type => $defs ) {
				$all[ $type ] = $defs;
			}
		}

		if ( ! class_exists( 'SMSW_Vocabulary_Parser' ) ) {
			return $all;
		}

		try {
			$parser = new SMSW_Vocabulary_Parser();
			$names  = $parser->get_all_type_names();
		} catch ( Exception $e ) {
			return $all;
		}

		if ( ! is_array( $names ) || empty( $names ) ) {
			return $all;
		}

		// One line schema.org description per property, used as the inline
		// field hint in the builder. Fetched once here rather than per type.
		$descriptions = array();
		try {
			$descriptions = $parser->get_property_descriptions();
		} catch ( Exception $e ) {
			$descriptions = array();
		}
		if ( ! is_array( $descriptions ) ) {
			$descriptions = array();
		}

		foreach ( $names as $name ) {
			$name = (string) $name;
			if ( '' === $name ) {
				continue;
			}

			try {
				$props = $parser->get_properties_for_type( $name );
			} catch ( Exception $e ) {
				continue;
			}

			if ( ! is_array( $props ) || empty( $props ) ) {
				continue;
			}

			$defs = array();

			foreach ( $props as $prop ) {
				if ( ! is_array( $prop ) || empty( $prop['name'] ) ) {
					continue;
				}
				if ( isset( $prop['superseded_by'] ) && null !== $prop['superseded_by'] && '' !== $prop['superseded_by'] ) {
					continue;
				}

				$pname  = (string) $prop['name'];
				$prange = isset( $prop['range'] ) ? $prop['range'] : array();
				$depth  = isset( $prop['depth_level'] ) ? (int) $prop['depth_level'] : 99;

				$nested_type = self::vocab_nested_type( $prange );
				$field_type  = self::vocab_field_type( $prange );

				if ( '' !== $nested_type ) {
					$field_type = 'nested';
				}

				$placeholder = self::vocab_default_placeholder( $pname );
				if ( empty( $placeholder ) ) {
					$placeholder = self::vocab_leaf_placeholder( $pname, $prange );
				}

				$hint = isset( $descriptions[ $pname ] ) ? self::shorten_hint( $descriptions[ $pname ] ) : '';

				$defs[ $pname ] = array(
					'label'       => $pname,
					'type'        => $field_type,
					'placeholder' => $placeholder,
					'depth'       => $depth,
					'nestedType'  => $nested_type,
					'hint'        => $hint,
				);
			}

			if ( empty( $defs ) ) {
				continue;
			}

			uasort(
				$defs,
				function ( $a, $b ) {
					$da = isset( $a['depth'] ) ? (int) $a['depth'] : 99;
					$db = isset( $b['depth'] ) ? (int) $b['depth'] : 99;
					if ( $da === $db ) {
						return strcmp( (string) $a['label'], (string) $b['label'] );
					}
					return $da < $db ? -1 : 1;
				}
			);

			foreach ( $defs as $k => $def ) {
				if ( ! isset( $defs[ $k ]['depth'] ) ) {
					$defs[ $k ]['depth'] = 99;
				}
			}

			if ( ! isset( $all[ $name ] ) ) {
				$all[ $name ] = $defs;
			} else {
				$all[ $name ] = self::merge_vocab_into_hardcoded( $all[ $name ], $defs );
			}
		}

		return $all;
	}

	/**
	 * Auto-fill category for one property definition.
	 *
	 * A field is Category A (auto-fillable from WordPress data) when its
	 * placeholder is a bare {{token}}, and Category B (manual content or a
	 * static sample value) otherwise. Deriving this from the placeholder
	 * keeps the editor, the localized property definitions, and the
	 * placeholder engine on the same contract.
	 *
	 * @param array<string,mixed> $def Property definition.
	 * @return string 'A' or 'B'.
	 */
	private static function autofill_category( $def ) {
		$placeholder = isset( $def['placeholder'] ) ? (string) $def['placeholder'] : '';
		if ( '' !== $placeholder && 1 === preg_match( '/^\{\{[a-z_]+\}\}$/', $placeholder ) ) {
			return 'A';
		}
		return 'B';
	}

	/**
	 * Google documented "Required" and "Recommended" properties per type.
	 *
	 * Sourced from Google Search Central structured data documentation and
	 * ordered required first. The essential cap only ever demotes recommended
	 * properties, so a type Google documents can exceed the soft cap when its
	 * required list is genuinely that long.
	 *
	 * A pipe in a key means the first key the type actually defines wins, which
	 * keeps one row usable for near synonyms.
	 *
	 * @return array<string,array<int,string>> Type => ordered property keys.
	 */
	private static function google_essential_map() {
		return array(
			// Article / news.
			'Article'             => array( 'headline', 'image', 'datePublished', 'author', 'dateModified', 'description' ),
			'NewsArticle'         => array( 'headline', 'image', 'datePublished', 'author', 'dateModified', 'description' ),
			'BlogPosting'         => array( 'headline', 'image', 'datePublished', 'author', 'dateModified', 'description' ),
			'TechArticle'         => array( 'headline', 'image', 'datePublished', 'author', 'dateModified' ),
			'Report'              => array( 'headline', 'author', 'datePublished', 'image' ),
			'ScholarlyArticle'    => array( 'headline', 'author', 'datePublished' ),
			// Person / organization.
			'Person'              => array( 'name', 'url', 'image', 'jobTitle', 'sameAs|description', 'worksFor|affiliation' ),
			'Organization'        => array( 'name', 'url', 'logo', 'sameAs|description', 'contactPoint|address' ),
			'Corporation'         => array( 'name', 'url', 'logo', 'sameAs' ),
			'LocalBusiness'       => array( 'name', 'image', 'address', 'telephone', 'url', 'priceRange' ),
			// Products.
			'Product'             => array( 'name', 'image', 'description', 'brand', 'offers|aggregateRating|review', 'sku|mpn|gtin', 'offers' ),
			'ProductGroup'        => array( 'name', 'image', 'description', 'brand', 'offers|aggregateRating', 'variants|hasVariant' ),
			'Vehicle'             => array( 'name', 'brand|manufacturer', 'model', 'vehicleIdentificationNumber|sku', 'offers|aggregateRating' ),
			'Car'                 => array( 'name', 'brand|manufacturer', 'model', 'offers|aggregateRating' ),
			// Offers and reviews.
			'Offer'               => array( 'price', 'priceCurrency', 'availability|priceValidUntil', 'url', 'priceValidUntil' ),
			'AggregateOffer'      => array( 'lowPrice', 'highPrice|offerCount', 'priceCurrency', 'offers' ),
			'Review'              => array( 'author|itemReviewed', 'reviewRating', 'reviewBody', 'datePublished' ),
			'AggregateRating'     => array( 'ratingValue', 'ratingCount|reviewCount', 'bestRating|worstRating' ),
			'Rating'              => array( 'ratingValue', 'bestRating|worstRating', 'ratingCount|author' ),
			// Events.
			'Event'               => array( 'name', 'startDate', 'location', 'endDate|description', 'offers|image', 'eventStatus' ),
			// Places.
			'Place'               => array( 'name', 'address', 'geo|image', 'url|telephone' ),
			'Restaurant'          => array( 'name', 'address', 'servesCuisine|priceRange', 'image|url', 'menu|openingHours' ),
			// Media.
			'VideoObject'         => array( 'name', 'thumbnailUrl', 'uploadDate', 'description', 'duration', 'contentUrl' ),
			'AudioObject'         => array( 'name', 'contentUrl', 'uploadDate|encodingFormat', 'duration' ),
			'ImageObject'         => array( 'url', 'width', 'height', 'caption' ),
			// Jobs.
			'JobPosting'          => array( 'title', 'description', 'datePosted', 'hiringOrganization', 'validThrough|employmentType' ),
			// Recipes, courses, software.
			'Recipe'              => array( 'name', 'image', 'author', 'datePublished', 'description', 'recipeIngredient|recipeInstructions' ),
			'Course'              => array( 'name', 'description', 'provider', 'offers' ),
			'SoftwareApplication' => array( 'name', 'operatingSystem', 'applicationCategory', 'offers|aggregateRating' ),
			'MobileApplication'   => array( 'name', 'operatingSystem', 'applicationCategory', 'offers|aggregateRating' ),
			// Site and page level.
			'WebSite'             => array( 'name', 'url', 'potentialAction' ),
			'WebPage'             => array( 'name', 'url', 'datePublished|dateModified' ),
			'FAQPage'             => array( 'mainEntity', 'name|url', 'description' ),
			'QAPage'              => array( 'mainEntity', 'name|url', 'description' ),
			'BreadcrumbList'      => array( 'itemListElement', 'name|url' ),
			'ItemList'            => array( 'itemListElement', 'name|url', 'numberOfItems' ),
			'HowTo'               => array( 'name', 'step', 'totalTime|estimatedCost' ),
			// Commerce extras.
			'Demand'              => array( 'name', 'sku|gtin', 'url', 'seller' ),
			'MerchantReturnPolicy' => array( 'applicableCountry', 'returnPolicyCategory', 'returnMethod' ),
			'ShippingDetails'     => array( 'shippingRate|shippingDestination' ),
		);
	}

	/**
	 * Soft cap on how many fields are shown without expanding.
	 *
	 * Google's documented lists above already sit inside this range, so the cap
	 * only shapes the generated fallback for types Google does not document.
	 */
	const ESSENTIAL_CAP = 8;

	/**
	 * How many fields a type Google does not document shows before the toggle.
	 *
	 * The fallback is aiming at a useful short list rather than a complete one,
	 * so it sits below ESSENTIAL_CAP: a user who has not heard of a type still
	 * wants a handful of plausible fields, not eight inherited boilerplate ones.
	 */
	const FALLBACK_TARGET = 5;

	/**
	 * Floor on how many fields are shown before the toggle is needed.
	 *
	 * Without this a type that declares none of the universal fields and no
	 * properties of its own would open completely collapsed, which reads as a
	 * broken block rather than a summary.
	 */
	const MIN_ESSENTIAL = 3;

	/**
	 * Properties that are essential for almost every type.
	 *
	 * Applied in this order, and only when the type actually defines them. This
	 * is the first stage of the fallback for types Google does not document.
	 */
	const UNIVERSAL_ESSENTIAL = array( 'name', 'description', 'url' );

	/**
	 * Essential property keys for every type, keyed by type name.
	 *
	 * This is the single source of truth for the essential/advanced split. It is
	 * built once, cached, and shipped to the builder, so the split is defined
	 * as data and never recomputed per block in JavaScript.
	 *
	 * @return array<string,array<int,string>> Type => ordered essential keys.
	 */
	public static function get_essential_property_map() {
		if ( is_array( self::$essential_map_memo ) ) {
			return self::$essential_map_memo;
		}

		$cache_key = 'smsw_essential_map_v1_' . SMSW_VERSION;
		$cached    = get_transient( $cache_key );

		if ( false !== $cached && is_array( $cached ) ) {
			self::$essential_map_memo = $cached;
			return self::$essential_map_memo;
		}

		$defs = self::get_all_property_definitions();
		$map  = array();

		foreach ( $defs as $type => $type_defs ) {
			$map[ $type ] = self::classify_type( (string) $type, $type_defs );
		}

		// Release the reference to the full definitions before caching the map,
		// so the peak does not hold both structures longer than necessary.
		$defs = null;

		set_transient( $cache_key, $map, WEEK_IN_SECONDS );
		self::$essential_map_memo = $map;

		return self::$essential_map_memo;
	}

	/**
	 * Essential property keys for one type.
	 *
	 * @param string $type Schema @type.
	 * @return array<int,string> Ordered essential keys that the type defines.
	 */
	public static function get_essential_properties( $type ) {
		return self::get_essential_keys( $type );
	}

	/**
	 * Decides which of a type's properties are essential.
	 *
	 * Google documented types use the curated list, resolved against the
	 * properties the type really has. Everything else falls back to a
	 * deterministic rule so the split is consistent across all ~900 types:
	 * the universal fields the type defines, then its own properties, meaning
	 * the ones it declares directly rather than inheriting from a parent.
	 *
	 * @param string                              $type      Schema @type.
	 * @param array<string,array<string,mixed>>   $defs      Property definitions.
	 * @return array<int,string> Ordered essential keys, all present in $defs.
	 */
	private static function classify_type( $type, $defs ) {
		$google = self::google_essential_map();
		$picked = array();

		if ( isset( $google[ $type ] ) ) {
			foreach ( $google[ $type ] as $spec ) {
				$key = self::resolve_key_spec( $spec, $defs );
				if ( '' !== $key && ! in_array( $key, $picked, true ) ) {
					$picked[] = $key;
				}
			}
			// A Google list is curated to the 5-8 range, so it is never trimmed
			// here. Required properties must stay visible even if that is long.
			return $picked;
		}

		// Fallback stage 1: the universal fields, in a fixed order.
		foreach ( self::UNIVERSAL_ESSENTIAL as $key ) {
			if ( isset( $defs[ $key ] ) && ! in_array( $key, $picked, true ) ) {
				$picked[] = $key;
			}
		}

		// Fallback stage 2: fill up to FALLBACK_TARGET with the properties that
		// define this type. depth_level 0 is a property the type declares itself,
		// which is the strongest "this is the field that matters" signal the
		// vocabulary gives us. Most types declare nothing directly, so the
		// shallowest remaining depths are taken next, in depth order and then
		// alphabetically so the result is identical on every request.
		$byDepth = array();
		foreach ( $defs as $key => $def ) {
			if ( in_array( $key, $picked, true ) ) {
				continue;
			}
			$depth     = isset( $def['depth'] ) ? (int) $def['depth'] : 1;
			$byDepth[] = array(
				'depth' => $depth,
				'key'   => (string) $key,
			);
		}
		usort(
			$byDepth,
			function ( $a, $b ) {
				if ( $a['depth'] === $b['depth'] ) {
					return strcmp( $a['key'], $b['key'] );
				}
				return $a['depth'] < $b['depth'] ? -1 : 1;
			}
		);

		$target = max( self::MIN_ESSENTIAL, self::FALLBACK_TARGET );
		foreach ( $byDepth as $entry ) {
			if ( count( $picked ) >= $target ) {
				break;
			}
			$picked[] = $entry['key'];
		}

		return $picked;
	}

	/**
	 * Resolves a curated key that may offer alternatives separated by a pipe.
	 *
	 * @param string                            $spec  Key or pipe separated keys.
	 * @param array<string,array<string,mixed>> $defs  Property definitions.
	 * @return string First matching key, or an empty string when none match.
	 */
	private static function resolve_key_spec( $spec, $defs ) {
		$options = explode( '|', (string) $spec );
		foreach ( $options as $opt ) {
			if ( isset( $defs[ $opt ] ) ) {
				return $opt;
			}
		}
		return '';
	}

	/**
	 * Stamp an explicit auto-fill category onto every definition.
	 *
	 * @param array<string,array<string,mixed>> $defs Property definitions keyed by field id.
	 * @return array<string,array<string,mixed>>
	 */
	private static function with_autofill_category( $defs ) {
		foreach ( $defs as $key => $def ) {
			if ( ! is_array( $def ) ) {
				continue;
			}
			$defs[ $key ]['category'] = self::autofill_category( $def );
		}
		return $defs;
	}

	/**
	 * Hardcoded curated property definitions for common types.
	 *
	 * @return array<string, array<string, array{label:string,type:string,placeholder:string,category?:string}>>
	 */
	private static function get_hardcoded_property_definitions() {
		return array(
			'Article'       => array(
				'headline'         => array(
					'label'       => 'headline',
					'type'        => 'text',
					'placeholder' => '{{post_title}}',
					'category'    => 'A',
				),
				'description'      => array(
					'label'       => 'description',
					'type'        => 'textarea',
					'placeholder' => '{{meta_description}}',
					'category'    => 'A',
				),
				'image'            => array(
					'label'       => 'image',
					'type'        => 'url',
					'placeholder' => '{{featured_image}}',
					'category'    => 'B',
				),
				'author'           => array(
					'label'       => 'author (name)',
					'type'        => 'text',
					'placeholder' => '{{author_name}}',
				),
				'datePublished'    => array(
					'label'       => 'datePublished',
					'type'        => 'text',
					'placeholder' => '{{post_date}}',
				),
				'dateModified'     => array(
					'label'       => 'dateModified',
					'type'        => 'text',
					'placeholder' => '',
				),
				'publisher'        => array(
					'label'       => 'publisher (name)',
					'type'        => 'text',
					'placeholder' => '{{site_name}}',
				),
				'mainEntityOfPage' => array(
					'label'       => 'mainEntityOfPage',
					'type'        => 'url',
					'placeholder' => '{{post_url}}',
				),
			),
			'Product'       => array(
				'name'                 => array(
					'label'       => 'name',
					'type'        => 'text',
					'placeholder' => '{{post_title}}',
				),
				'description'          => array(
					'label'       => 'description',
					'type'        => 'textarea',
					'placeholder' => '{{meta_description}}',
				),
				'image'                => array(
					'label'       => 'image',
					'type'        => 'url',
					'placeholder' => '{{featured_image}}',
				),
				'brand'                => array(
					'label'       => 'brand (name)',
					'type'        => 'text',
					'placeholder' => '{{site_name}}',
				),
				'offers_price'         => array(
					'label'       => 'offers.price',
					'type'        => 'text',
					'placeholder' => '',
				),
				'offers_priceCurrency' => array(
					'label'       => 'offers.priceCurrency',
					'type'        => 'text',
					'placeholder' => 'USD',
				),
				'offers_availability'  => array(
					'label'       => 'offers.availability',
					'type'        => 'text',
					'placeholder' => 'https://schema.org/InStock',
				),
				'url'                  => array(
					'label'       => 'url',
					'type'        => 'url',
					'placeholder' => '{{post_url}}',
				),
			),
			'LocalBusiness' => array(
				'name'                    => array(
					'label'       => 'name',
					'type'        => 'text',
					'placeholder' => '{{site_name}}',
				),
				'description'             => array(
					'label'       => 'description',
					'type'        => 'textarea',
					'placeholder' => '',
				),
				'image'                   => array(
					'label'       => 'image',
					'type'        => 'url',
					'placeholder' => '',
				),
				'url'                     => array(
					'label'       => 'url',
					'type'        => 'url',
					'placeholder' => '{{site_url}}',
				),
				'telephone'               => array(
					'label'       => 'telephone',
					'type'        => 'text',
					'placeholder' => '',
				),
				'email'                   => array(
					'label'       => 'email',
					'type'        => 'text',
					'placeholder' => '',
				),
				'address_streetAddress'   => array(
					'label'       => 'address.streetAddress',
					'type'        => 'text',
					'placeholder' => '',
				),
				'address_addressLocality' => array(
					'label'       => 'address.addressLocality',
					'type'        => 'text',
					'placeholder' => '',
				),
				'address_addressRegion'   => array(
					'label'       => 'address.addressRegion',
					'type'        => 'text',
					'placeholder' => '',
				),
				'address_postalCode'      => array(
					'label'       => 'address.postalCode',
					'type'        => 'text',
					'placeholder' => '',
				),
				'address_addressCountry'  => array(
					'label'       => 'address.addressCountry',
					'type'        => 'text',
					'placeholder' => '',
				),
			),
			'Organization'  => array(
				'name'        => array(
					'label'       => 'name',
					'type'        => 'text',
					'placeholder' => '{{site_name}}',
				),
				'url'         => array(
					'label'       => 'url',
					'type'        => 'url',
					'placeholder' => '{{site_url}}',
				),
				'logo'        => array(
					'label'       => 'logo',
					'type'        => 'url',
					'placeholder' => '{{site_logo}}',
				),
				'description' => array(
					'label'       => 'description',
					'type'        => 'textarea',
					'placeholder' => '',
				),
				'email'       => array(
					'label'       => 'email',
					'type'        => 'text',
					'placeholder' => '',
				),
				'telephone'   => array(
					'label'       => 'telephone',
					'type'        => 'text',
					'placeholder' => '',
				),
				'sameAs'      => array(
					'label'       => 'sameAs (comma-separated URLs)',
					'type'        => 'textarea',
					'placeholder' => '',
				),
			),
		);
	}

	/**
	 * Full editor property definitions for one schema type.
	 *
	 * Returns the complete official property set: curated fields for the
	 * built-in types plus every property from the bundled schema.org
	 * vocabulary, with no depth or count limits. Loaded on demand by the
	 * editor through AJAX so admin pages stay fast and only ever hold the
	 * types that are actually on screen.
	 *
	 * @param string $type Schema @type.
	 * @return array<string, array{label:string,type:string,placeholder:string,depth:int,nestedType:string}>
	 */
	public static function get_editor_properties( $type ) {
		$type = (string) $type;

		if ( '' === $type || self::CUSTOM_TYPE === $type ) {
			return array();
		}

		return self::get_type_definitions( $type );
	}

	/**
	 * Build the complete vocabulary property list for a single type.
	 *
	 * Every non superseded property of the full inheritance chain is
	 * included, exactly as schema.org publishes it. Per type this is a
	 * fast array walk because the bundled vocabulary is parsed once and
	 * cached in a transient.
	 *
	 * @param string $type Schema @type.
	 * @return array<string, array>
	 */
	private static function build_vocabulary_properties( $type ) {
		if ( ! class_exists( 'SMSW_Vocabulary_Parser' ) ) {
			return array();
		}

		try {
			$parser = SMSW_Vocabulary_Parser::instance();
			$props  = $parser->get_properties_for_type( $type );
		} catch ( Exception $e ) {
			// phpcs:ignore Generic.CodeAnalysis.EmptyStatement -- Vocabulary is optional; empty list returned below.
			return array();
		}

		if ( ! is_array( $props ) || empty( $props ) ) {
			return array();
		}

		$defs = array();

		foreach ( $props as $prop ) {
			if ( ! is_array( $prop ) || empty( $prop['name'] ) ) {
				continue;
			}
			if ( isset( $prop['superseded_by'] ) && null !== $prop['superseded_by'] && '' !== $prop['superseded_by'] ) {
				continue;
			}

			$pname       = (string) $prop['name'];
			$prange      = isset( $prop['range'] ) ? $prop['range'] : array();
			$depth       = isset( $prop['depth_level'] ) ? (int) $prop['depth_level'] : 99;
			$nested_type = self::vocab_nested_type( $prange );
			$field_type  = self::vocab_field_type( $prange );

			if ( '' !== $nested_type ) {
				$field_type = 'nested';
			}

			$placeholder = self::vocab_default_placeholder( $pname );
			if ( empty( $placeholder ) ) {
				$placeholder = self::vocab_leaf_placeholder( $pname, $prange );
			}

			$existing_category = isset( $defs[ $pname ]['category'] ) ? $defs[ $pname ]['category'] : null;

			$defs[ $pname ] = array(
				'label'       => $pname,
				'type'        => $field_type,
				'placeholder' => $placeholder,
				'depth'       => $depth,
				'nestedType'  => $nested_type,
				'category'    => $existing_category,
			);
		}

		if ( empty( $defs ) ) {
			return array();
		}

		uasort(
			$defs,
			function ( $a, $b ) {
				$da = isset( $a['depth'] ) ? (int) $a['depth'] : 99;
				$db = isset( $b['depth'] ) ? (int) $b['depth'] : 99;
				if ( $da === $db ) {
					return strcmp( (string) $a['label'], (string) $b['label'] );
				}
				return $da < $db ? -1 : 1;
			}
		);

		foreach ( $defs as $k => $def ) {
			if ( ! isset( $defs[ $k ]['depth'] ) ) {
				$defs[ $k ]['depth'] = 99;
			}
		}

		return $defs;
	}

	/**
	 * Build a JSON-LD array from typed property values.
	 *
	 * @param string               $type   Schema type.
	 * @param array<string,string> $values Property values keyed by field id.
	 * @return array<string,mixed>
	 */
	public static function build_json_ld( $type, $values ) {
		$nested_extra = array();
		if ( is_array( $values ) ) {
			$scalars = array();
			foreach ( $values as $k => $v ) {
				if ( is_array( $v ) ) {
					$nested_extra[ $k ] = $v;
				} else {
					$scalars[ $k ] = $v;
				}
			}
			$values = $scalars;
		}
		$values = is_array( $values ) ? $values : array();

		$schema = array(
			'@context' => 'https://schema.org',
			'@type'    => $type,
		);

		switch ( $type ) {
			case 'Article':
				self::set_if( $schema, 'headline', $values, 'headline' );
				self::set_if( $schema, 'description', $values, 'description' );
				self::set_if( $schema, 'image', $values, 'image' );
				self::set_if( $schema, 'datePublished', $values, 'datePublished' );
				self::set_if( $schema, 'dateModified', $values, 'dateModified' );
				self::set_if( $schema, 'mainEntityOfPage', $values, 'mainEntityOfPage' );
				if ( ! empty( $values['author'] ) ) {
					$schema['author'] = array(
						'@type' => 'Person',
						'name'  => $values['author'],
					);
				}
				if ( ! empty( $values['publisher'] ) ) {
					$schema['publisher'] = array(
						'@type' => 'Organization',
						'name'  => $values['publisher'],
					);
				}
				break;

			case 'Product':
				self::set_if( $schema, 'name', $values, 'name' );
				self::set_if( $schema, 'description', $values, 'description' );
				self::set_if( $schema, 'image', $values, 'image' );
				self::set_if( $schema, 'sku', $values, 'sku' );
				self::set_if( $schema, 'url', $values, 'url' );
				if ( ! empty( $values['brand'] ) ) {
					$schema['brand'] = array(
						'@type' => 'Brand',
						'name'  => $values['brand'],
					);
				}
				$offers = array( '@type' => 'Offer' );
				self::set_if( $offers, 'price', $values, 'offers_price' );
				self::set_if( $offers, 'priceCurrency', $values, 'offers_priceCurrency' );
				self::set_if( $offers, 'availability', $values, 'offers_availability' );
				if ( count( $offers ) > 1 ) {
					$schema['offers'] = $offers;
				}
				break;

			case 'LocalBusiness':
				self::set_if( $schema, 'name', $values, 'name' );
				self::set_if( $schema, 'description', $values, 'description' );
				self::set_if( $schema, 'image', $values, 'image' );
				self::set_if( $schema, 'url', $values, 'url' );
				self::set_if( $schema, 'telephone', $values, 'telephone' );
				self::set_if( $schema, 'email', $values, 'email' );
				$address = array( '@type' => 'PostalAddress' );
				self::set_if( $address, 'streetAddress', $values, 'address_streetAddress' );
				self::set_if( $address, 'addressLocality', $values, 'address_addressLocality' );
				self::set_if( $address, 'addressRegion', $values, 'address_addressRegion' );
				self::set_if( $address, 'postalCode', $values, 'address_postalCode' );
				self::set_if( $address, 'addressCountry', $values, 'address_addressCountry' );
				if ( count( $address ) > 1 ) {
					$schema['address'] = $address;
				}
				break;

			case 'Organization':
				self::set_if( $schema, 'name', $values, 'name' );
				self::set_if( $schema, 'url', $values, 'url' );
				self::set_if( $schema, 'logo', $values, 'logo' );
				self::set_if( $schema, 'description', $values, 'description' );
				self::set_if( $schema, 'email', $values, 'email' );
				self::set_if( $schema, 'telephone', $values, 'telephone' );
				if ( ! empty( $values['sameAs'] ) ) {
					$schema['sameAs'] = self::split_list( $values['sameAs'] );
				}
				break;

			case 'FAQPage':
				self::set_if( $schema, 'name', $values, 'name' );
				self::set_if( $schema, 'description', $values, 'description' );
				if ( ! empty( $values['mainEntity_json'] ) ) {
					$decoded = json_decode( $values['mainEntity_json'], true );
					if ( is_array( $decoded ) ) {
						$schema['mainEntity'] = $decoded;
					}
				}
				break;

			case 'HowTo':
				self::set_if( $schema, 'name', $values, 'name' );
				self::set_if( $schema, 'description', $values, 'description' );
				self::set_if( $schema, 'image', $values, 'image' );
				self::set_if( $schema, 'totalTime', $values, 'totalTime' );
				if ( ! empty( $values['step_json'] ) ) {
					$decoded = json_decode( $values['step_json'], true );
					if ( is_array( $decoded ) ) {
						$schema['step'] = $decoded;
					}
				}
				break;

			case 'Review':
				self::set_if( $schema, 'name', $values, 'name' );
				self::set_if( $schema, 'reviewBody', $values, 'reviewBody' );
				self::set_if( $schema, 'datePublished', $values, 'datePublished' );
				if ( ! empty( $values['author'] ) ) {
					$schema['author'] = array(
						'@type' => 'Person',
						'name'  => $values['author'],
					);
				}
				$item = array();
				if ( ! empty( $values['itemReviewed_type'] ) ) {
					$item['@type'] = $values['itemReviewed_type'];
				}
				self::set_if( $item, 'name', $values, 'itemReviewed_name' );
				if ( ! empty( $item ) ) {
					$schema['itemReviewed'] = $item;
				}
				$rating = array( '@type' => 'Rating' );
				self::set_if( $rating, 'ratingValue', $values, 'reviewRating_ratingValue' );
				self::set_if( $rating, 'bestRating', $values, 'reviewRating_bestRating' );
				if ( count( $rating ) > 1 ) {
					$schema['reviewRating'] = $rating;
				}
				break;

			case 'Event':
				self::set_if( $schema, 'name', $values, 'name' );
				self::set_if( $schema, 'description', $values, 'description' );
				self::set_if( $schema, 'image', $values, 'image' );
				self::set_if( $schema, 'startDate', $values, 'startDate' );
				self::set_if( $schema, 'endDate', $values, 'endDate' );
				self::set_if( $schema, 'eventStatus', $values, 'eventStatus' );
				self::set_if( $schema, 'eventAttendanceMode', $values, 'eventAttendanceMode' );
				self::set_if( $schema, 'url', $values, 'url' );
				$location = array( '@type' => 'Place' );
				self::set_if( $location, 'name', $values, 'location_name' );
				self::set_if( $location, 'address', $values, 'location_address' );
				if ( count( $location ) > 1 ) {
					$schema['location'] = $location;
				}
				break;

			case 'Person':
				self::set_if( $schema, 'name', $values, 'name' );
				self::set_if( $schema, 'url', $values, 'url' );
				self::set_if( $schema, 'image', $values, 'image' );
				self::set_if( $schema, 'jobTitle', $values, 'jobTitle' );
				self::set_if( $schema, 'email', $values, 'email' );
				if ( ! empty( $values['worksFor'] ) ) {
					$schema['worksFor'] = array(
						'@type' => 'Organization',
						'name'  => $values['worksFor'],
					);
				}
				if ( ! empty( $values['sameAs'] ) ) {
					$schema['sameAs'] = self::split_list( $values['sameAs'] );
				}
				break;

			case 'Service':
				self::set_if( $schema, 'name', $values, 'name' );
				self::set_if( $schema, 'description', $values, 'description' );
				self::set_if( $schema, 'areaServed', $values, 'areaServed' );
				self::set_if( $schema, 'serviceType', $values, 'serviceType' );
				self::set_if( $schema, 'url', $values, 'url' );
				if ( ! empty( $values['provider'] ) ) {
					$schema['provider'] = array(
						'@type' => 'Organization',
						'name'  => $values['provider'],
					);
				}
				break;

			case 'WebPage':
				self::set_if( $schema, 'name', $values, 'name' );
				self::set_if( $schema, 'description', $values, 'description' );
				self::set_if( $schema, 'url', $values, 'url' );
				self::set_if( $schema, 'image', $values, 'image' );
				self::set_if( $schema, 'datePublished', $values, 'datePublished' );
				self::set_if( $schema, 'inLanguage', $values, 'inLanguage' );
				break;

			case 'AboutPage':
				self::set_if( $schema, 'name', $values, 'name' );
				self::set_if( $schema, 'description', $values, 'description' );
				self::set_if( $schema, 'url', $values, 'url' );
				self::set_if( $schema, 'about', $values, 'about' );
				break;

			case 'ContactPage':
				self::set_if( $schema, 'name', $values, 'name' );
				self::set_if( $schema, 'description', $values, 'description' );
				self::set_if( $schema, 'url', $values, 'url' );
				break;

			case 'Recipe':
				self::set_if( $schema, 'name', $values, 'name' );
				self::set_if( $schema, 'description', $values, 'description' );
				self::set_if( $schema, 'image', $values, 'image' );
				self::set_if( $schema, 'prepTime', $values, 'prepTime' );
				self::set_if( $schema, 'cookTime', $values, 'cookTime' );
				self::set_if( $schema, 'recipeYield', $values, 'recipeYield' );
				self::set_if( $schema, 'recipeInstructions', $values, 'recipeInstructions' );
				if ( ! empty( $values['author'] ) ) {
					$schema['author'] = array(
						'@type' => 'Person',
						'name'  => $values['author'],
					);
				}
				if ( ! empty( $values['recipeIngredient'] ) ) {
					$lines = preg_split( '/\r\n|\r|\n/', $values['recipeIngredient'] );
					$lines = array_values( array_filter( array_map( 'trim', (array) $lines ) ) );
					if ( ! empty( $lines ) ) {
						$schema['recipeIngredient'] = $lines;
					}
				}
				break;

			case 'CreativeWork':
				self::set_if( $schema, 'name', $values, 'name' );
				self::set_if( $schema, 'description', $values, 'description' );
				self::set_if( $schema, 'image', $values, 'image' );
				self::set_if( $schema, 'url', $values, 'url' );
				self::set_if( $schema, 'datePublished', $values, 'datePublished' );
				if ( ! empty( $values['author'] ) ) {
					$schema['author'] = array(
						'@type' => 'Person',
						'name'  => $values['author'],
					);
				}
				break;

			case 'BreadcrumbList':
				if ( ! empty( $values['itemListElement_json'] ) ) {
					$decoded = json_decode( $values['itemListElement_json'], true );
					if ( is_array( $decoded ) ) {
						$schema['itemListElement'] = $decoded;
					}
				}
				break;

			case 'JobPosting':
				self::set_if( $schema, 'title', $values, 'title' );
				self::set_if( $schema, 'description', $values, 'description' );
				self::set_if( $schema, 'datePosted', $values, 'datePosted' );
				self::set_if( $schema, 'validThrough', $values, 'validThrough' );
				self::set_if( $schema, 'employmentType', $values, 'employmentType' );
				if ( ! empty( $values['hiringOrganization'] ) ) {
					$schema['hiringOrganization'] = array(
						'@type' => 'Organization',
						'name'  => $values['hiringOrganization'],
					);
				}
				if ( ! empty( $values['jobLocation_address'] ) ) {
					$schema['jobLocation'] = array(
						'@type'   => 'Place',
						'address' => $values['jobLocation_address'],
					);
				}
				$salary = array( '@type' => 'MonetaryAmount' );
				self::set_if( $salary, 'currency', $values, 'baseSalary_currency' );
				if ( ! empty( $values['baseSalary_value'] ) ) {
					$salary['value'] = array(
						'@type'    => 'QuantitativeValue',
						'value'    => $values['baseSalary_value'],
						'unitText' => 'YEAR',
					);
				}
				if ( count( $salary ) > 1 ) {
					$schema['baseSalary'] = $salary;
				}
				break;

			case 'Course':
				self::set_if( $schema, 'name', $values, 'name' );
				self::set_if( $schema, 'description', $values, 'description' );
				self::set_if( $schema, 'url', $values, 'url' );
				self::set_if( $schema, 'image', $values, 'image' );
				if ( ! empty( $values['provider'] ) ) {
					$schema['provider'] = array(
						'@type' => 'Organization',
						'name'  => $values['provider'],
					);
				}
				break;

			case 'SoftwareApplication':
				self::set_if( $schema, 'name', $values, 'name' );
				self::set_if( $schema, 'description', $values, 'description' );
				self::set_if( $schema, 'applicationCategory', $values, 'applicationCategory' );
				self::set_if( $schema, 'operatingSystem', $values, 'operatingSystem' );
				self::set_if( $schema, 'url', $values, 'url' );
				self::set_if( $schema, 'image', $values, 'image' );
				$offers = array( '@type' => 'Offer' );
				self::set_if( $offers, 'price', $values, 'offers_price' );
				self::set_if( $offers, 'priceCurrency', $values, 'offers_priceCurrency' );
				if ( count( $offers ) > 1 ) {
					$schema['offers'] = $offers;
				}
				break;

			default:
				return self::build_generic_json_ld( $type, array_merge( $nested_extra, $values ) );
		}

		foreach ( $nested_extra as $k => $v ) {
			if ( isset( $schema[ $k ] ) ) {
				continue; }
			$cv = self::generic_value( $v );
			if ( null !== $cv ) {
				$schema[ $k ] = $cv; }
		}
		foreach ( $values as $k => $v ) {
			if ( isset( $schema[ $k ] ) ) {
				continue; }
			if ( false !== strpos( (string) $k, '_' ) ) {
				continue; }
			$cv = self::generic_value( $v );
			if ( null !== $cv ) {
				$schema[ $k ] = $cv; }
		}

		return $schema;
	}

	/**
	 * Generic JSON-LD builder for vocabulary derived types.
	 *
	 * Emits plain values directly, decodes nested JSON textarea values,
	 * and coerces true and false strings to real booleans so any of the
	 * 928 types outputs valid JSON-LD without a hardcoded case block.
	 *
	 * @param string               $type   Schema @type.
	 * @param array<string,string> $values Property values.
	 * @return array<string,mixed>
	 */
	private static function build_generic_json_ld( $type, $values ) {
		$schema = array(
			'@context' => 'https://schema.org',
			'@type'    => $type,
		);
		foreach ( $values as $key => $value ) {
			if ( ! preg_match( '/^[A-Za-z0-9_]+$/', (string) $key ) ) {
				continue;
			}
			$cv = self::generic_value( $value );
			if ( null === $cv ) {
				continue;
			}
			$schema[ $key ] = $cv;
		}
		return $schema;
	}

	/**
	 * Normalize one value for JSON-LD output.
	 *
	 * Empty strings and empty nested objects are omitted entirely. Nested
	 * arrays are cleaned recursively so a nested sub-form field group is
	 * emitted exactly as the user filled it, with typed numbers, real
	 * booleans, and no empty leaves anywhere in the structure.
	 *
	 * @param mixed $value Raw value.
	 * @return mixed|null Clean value or null when it should be omitted.
	 */
	private static function generic_value( $value ) {
		if ( is_array( $value ) ) {
			$out = array();
			foreach ( $value as $k => $v ) {
				if ( ! preg_match( '/^@?[A-Za-z0-9_]+$/', (string) $k ) ) {
					continue;
				}
				$cv = self::generic_value( $v );
				if ( null === $cv ) {
					continue;
				}
				$out[ $k ] = $cv;
			}
			if ( empty( $out ) ) {
				return null;
			}
			if ( 1 === count( $out ) ) {
				if ( isset( $out['@type'] ) ) {
					return null;
				}
				$only = reset( $out );
				if ( is_array( $only ) ) {
					return $only;
				}
				return $out;
			}
			return $out;
		}
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return null;
		}
		if ( 'true' === strtolower( $value ) ) {
			return true;
		}
		if ( 'false' === strtolower( $value ) ) {
			return false;
		}
		if ( false !== strpos( $value, '{' ) || false !== strpos( $value, '[' ) ) {
			$decoded = json_decode( $value, true );
			if ( JSON_ERROR_NONE === json_last_error() && is_array( $decoded ) ) {
				return self::generic_value( $decoded );
			}
		}
		if ( preg_match( '/^-?\d+\.\d+$/', $value ) ) {
			return (float) $value;
		}
		if ( preg_match( '/^-?\d+$/', $value ) ) {
			return (int) $value;
		}
		return $value;
	}


	/**
	 * Set a schema key if the source value is non-empty.
	 *
	 * @param array<string,mixed>  $target Target array (by ref).
	 * @param string               $key    Target key.
	 * @param array<string,string> $values Source values.
	 * @param string               $src    Source key.
	 * @return void
	 */
	private static function set_if( &$target, $key, $values, $src ) {
		if ( isset( $values[ $src ] ) && '' !== trim( (string) $values[ $src ] ) ) {
			$target[ $key ] = $values[ $src ];
		}
	}

	/**
	 * Split comma-separated list into trimmed strings.
	 *
	 * @param string $value Raw value.
	 * @return array<int,string>
	 */
	private static function split_list( $value ) {
		$parts = array_map( 'trim', explode( ',', $value ) );
		return array_values( array_filter( $parts, 'strlen' ) );
	}
}
