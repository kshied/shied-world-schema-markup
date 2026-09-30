<?php
/**
 * Non blocking recommended property checks per schema type.
 *
 * Only invalid JSON syntax blocks saving. Missing recommended
 * properties produce warnings so partial schema can still be saved.
 *
 * @package Shied_World_Schema_Markup
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Validates recommended properties and returns warning strings.
 */
class SMSW_Schema_Validator {

	/**
	 * Address related property keys used by the LocalBusiness rule.
	 *
	 * @var array<int,string>
	 */
	const ADDRESS_KEYS = array(
		'address_streetAddress',
		'address_addressLocality',
		'address_addressRegion',
		'address_postalCode',
		'address_addressCountry',
	);

	/**
	 * Get validation rules keyed by schema type.
	 *
	 * Each rule has an "all" list (every field recommended) or an
	 * "any" list (at least one field recommended) plus a message.
	 *
	 * @return array<string,array{all:array<int,string>,any:array<int,string>,message:string}>
	 */
	public static function get_rules() {
		return array(
			'Product'       => array(
				'all'     => array(),
				'any'     => array( 'offers_price', 'offers_priceCurrency' ),
				'message' => __( 'Product should include offers or price information for rich results.', 'shied-world-schema-markup' ),
			),
			'Event'         => array(
				'all'     => array( 'startDate' ),
				'any'     => array(),
				'message' => __( 'Event should include a startDate.', 'shied-world-schema-markup' ),
			),
			'Article'       => array(
				'all'     => array( 'headline', 'datePublished' ),
				'any'     => array(),
				'message' => __( 'Article should include a headline and datePublished.', 'shied-world-schema-markup' ),
			),
			'LocalBusiness' => array(
				'all'     => array(),
				'any'     => array_merge( array( 'telephone' ), self::ADDRESS_KEYS ),
				'message' => __( 'LocalBusiness should include an address or telephone number.', 'shied-world-schema-markup' ),
			),
			'Review'        => array(
				'all'     => array( 'reviewRating_ratingValue' ),
				'any'     => array(),
				'message' => __( 'Review should include a reviewRating.', 'shied-world-schema-markup' ),
			),
		);
	}

	/**
	 * Get rules in a JSON friendly shape for the block builder script.
	 *
	 * @return array<string,array{all:array<int,string>,any:array<int,string>,message:string}>
	 */
	public static function get_js_rules() {
		return self::get_rules();
	}

	/**
	 * Check whether a property value counts as filled.
	 *
	 * Placeholders such as {{post_title}} count as filled because they
	 * resolve to real values on the frontend.
	 *
	 * @param array<string,mixed> $props Property values.
	 * @param string              $key   Property key.
	 * @return bool
	 */
	private static function has_value( $props, $key ) {
		if ( ! isset( $props[ $key ] ) ) {
			return false;
		}
		$value = $props[ $key ];
		if ( is_array( $value ) ) {
			return ! empty( $value );
		}
		return '' !== trim( (string) $value );
	}

	/**
	 * Get warnings for a single sanitized block.
	 *
	 * @param array<string,mixed> $block Sanitized block.
	 * @return array<int,string>
	 */
	public static function validate_block( $block ) {
		if ( ! is_array( $block ) ) {
			return array();
		}

		if ( array_key_exists( 'enabled', $block ) && empty( $block['enabled'] ) ) {
			return array();
		}

		$type = isset( $block['type'] ) ? (string) $block['type'] : '';
		if ( '' === $type || SMSW_Schema_Types::CUSTOM_TYPE === $type ) {
			return array();
		}

		$rules = self::get_rules();
		if ( ! isset( $rules[ $type ] ) ) {
			return array();
		}

		$props = isset( $block['properties'] ) && is_array( $block['properties'] ) ? $block['properties'] : array();
		$rule  = $rules[ $type ];

		if ( ! empty( $rule['all'] ) ) {
			foreach ( $rule['all'] as $key ) {
				if ( ! self::has_value( $props, $key ) ) {
					return array( $rule['message'] );
				}
			}
			return array();
		}

		if ( ! empty( $rule['any'] ) ) {
			foreach ( $rule['any'] as $key ) {
				if ( self::has_value( $props, $key ) ) {
					return array();
				}
			}
			return array( $rule['message'] );
		}

		return array();
	}

	/**
	 * Get labeled warnings for admin notices, prefixed with block name and type.
	 *
	 * @param mixed $blocks Sanitized blocks.
	 * @return array<int,string>
	 */
	public static function get_labeled_warnings( $blocks ) {
		$labeled = array();
		if ( ! is_array( $blocks ) ) {
			return $labeled;
		}
		foreach ( array_values( $blocks ) as $index => $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}
			foreach ( self::validate_block( $block ) as $message ) {
				$type = isset( $block['type'] ) ? (string) $block['type'] : '';
				/* translators: %d: block position in the list. */
				$label     = $type ? $type : sprintf( __( 'block %d', 'shied-world-schema-markup' ), (int) $index + 1 );
				$labeled[] = sprintf( '%s: %s', $label, $message );
			}
		}
		return $labeled;
	}
}
