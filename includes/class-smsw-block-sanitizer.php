<?php
/**
 * Shared sanitization for schema blocks.
 *
 * @package Shied_World_Schema_Markup
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sanitize and validate schema block arrays.
 */
class SMSW_Block_Sanitizer {

	/**
	 * Sanitize a list of blocks. Returns WP_Error on invalid JSON.
	 *
	 * @param mixed $blocks Raw blocks.
	 * @return array<int,array<string,mixed>>|WP_Error
	 */
	public static function sanitize_blocks( $blocks ) {
		if ( ! is_array( $blocks ) ) {
			return array();
		}

		$sanitized = array();
		foreach ( $blocks as $index => $block ) {
			$result = self::sanitize_block( $block, $index );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			$sanitized[] = $result;
		}

		return $sanitized;
	}

	/**
	 * Sanitize one block.
	 *
	 * @param mixed $block Raw block.
	 * @param int   $index Index for messages.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function sanitize_block( $block, $index = 0 ) {
		if ( ! is_array( $block ) ) {
			return new WP_Error(
				'smsw_invalid_block',
				sprintf(
					/* translators: %d: block number */
					__( 'SHIED WORLD Schema Markup could not save: block %d is invalid.', 'shied-world-schema-markup' ),
					(int) $index + 1
				)
			);
		}

		$all_types = SMSW_Schema_Types::get_types();
		if ( class_exists( 'SMSW_Vocabulary_Parser' ) ) {
			try {
				$parser = new SMSW_Vocabulary_Parser();
				$names  = $parser->get_all_type_names();
				if ( is_array( $names ) ) {
					foreach ( $names as $vname ) {
						$all_types[ $vname ] = $vname;
					}
				}
			} catch ( Exception $e ) {
				// phpcs:ignore Generic.CodeAnalysis.EmptyStatement -- Vocabulary is optional; sanitizer uses the static type list.
			}
		}
		$types = array_keys( $all_types );
		$raw_type = isset( $block['type'] ) ? sanitize_text_field( $block['type'] ) : '';
		$type     = in_array( $raw_type, $types, true ) ? $raw_type : '';

		$clean = array(
			'id'          => isset( $block['id'] ) ? sanitize_text_field( $block['id'] ) : uniqid( 'smsw_', true ),
			'type'        => $type,
			'name'        => isset( $block['name'] ) ? sanitize_text_field( $block['name'] ) : '',
			'enabled'     => ! empty( $block['enabled'] ),
			'properties'  => array(),
			'custom_type' => '',
			'custom_json' => '',
		);

		if ( SMSW_Schema_Types::CUSTOM_TYPE === $type ) {
			$clean['custom_type'] = isset( $block['custom_type'] ) ? sanitize_text_field( $block['custom_type'] ) : '';
			$custom_json          = isset( $block['custom_json'] ) ? (string) $block['custom_json'] : '';

			if ( '' !== trim( $custom_json ) ) {
				json_decode( $custom_json );
				if ( JSON_ERROR_NONE !== json_last_error() ) {
					return new WP_Error(
						'smsw_invalid_json',
						self::json_error_message( $type, $index )
					);
				}
			}

			$clean['custom_json'] = self::sanitize_json_string( $custom_json );
		} else {
			$defs  = SMSW_Schema_Types::get_properties( $type );
			$props = isset( $block['properties'] ) && is_array( $block['properties'] ) ? $block['properties'] : array();

			foreach ( $defs as $key => $def ) {
				if ( ! array_key_exists( $key, $props ) || is_array( $props[ $key ] ) ) {
					continue;
				}
				$value = trim( (string) $props[ $key ] );
				if ( '' === $value ) {
					continue;
				}
				$field_type = isset( $def['type'] ) ? (string) $def['type'] : 'text';
				if ( 'url' === $field_type ) {
					if ( false !== strpos( $value, '{{' ) ) {
						$clean['properties'][ $key ] = sanitize_text_field( $value );
					} else {
						$clean['properties'][ $key ] = esc_url_raw( $value );
					}
				} elseif ( 'textarea' === $field_type || 'nested' === $field_type ) {
					$clean['properties'][ $key ] = sanitize_textarea_field( $props[ $key ] );
				} else {
					$clean['properties'][ $key ] = sanitize_text_field( $props[ $key ] );
				}
			}

			foreach ( $props as $key => $value ) {
				if ( isset( $defs[ $key ] ) ) {
					continue;
				}
				if ( ! preg_match( '/^[A-Za-z0-9_]+$/', (string) $key ) ) {
					continue;
				}
				if ( is_array( $value ) ) {
					$cv = self::sanitize_nested( $value );
					if ( null !== $cv ) {
						$clean['properties'][ $key ] = $cv;
					}
					continue;
				}
				if ( is_string( $value ) && false !== strpos( $value, '{' ) ) {
					json_decode( $value );
					if ( JSON_ERROR_NONE === json_last_error() ) {
						$clean['properties'][ $key ] = self::sanitize_json_string( $value );
						continue;
					}
				}
				if ( 'true' === $value || 'false' === $value ) {
					$clean['properties'][ $key ] = sanitize_text_field( $value );
					continue;
				}
				$clean['properties'][ $key ] = sanitize_text_field( $value );
			}

			foreach ( array_keys( $defs ) as $key ) {
				if ( ! isset( $props[ $key ] ) || ! is_array( $props[ $key ] ) ) {
					continue;
				}
				$cv = self::sanitize_nested( $props[ $key ] );
				if ( null !== $cv ) {
					$clean['properties'][ $key ] = $cv;
				}
			}

			foreach ( array( 'mainEntity_json', 'step_json', 'itemListElement_json' ) as $json_field ) {
				if ( empty( $clean['properties'][ $json_field ] ) ) {
					continue;
				}
				json_decode( $clean['properties'][ $json_field ] );
				if ( JSON_ERROR_NONE !== json_last_error() ) {
					return new WP_Error(
						'smsw_invalid_json',
						sprintf(
							/* translators: 1: block label, 2: field key */
							__( 'SHIED WORLD Schema Markup could not save: invalid JSON syntax in %1$s (%2$s).', 'shied-world-schema-markup' ),
							$clean['name'] ? $clean['name'] : sprintf(
								/* translators: %d: block number */
								__( 'block %d', 'shied-world-schema-markup' ),
								(int) $index + 1
							),
							$json_field
						)
					);
				}
			}
		}

		return $clean;
	}

	/**
	 * Re-encode valid JSON for safe storage.
	 *
	 * @param string $json Raw JSON.
	 * @return string
	 */
	public static function sanitize_json_string( $json ) {
		if ( '' === trim( (string) $json ) ) {
			return '';
		}
		$decoded = json_decode( $json, true );
		if ( ! is_array( $decoded ) ) {
			return '';
		}
		$reencoded = wp_json_encode( $decoded, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT );
		return $reencoded ? $reencoded : '';
	}

	/**
	 * Build a JSON error message for a block.
	 *
	 * @param string $type  Block schema type.
	 * @param int    $index Block index.
	 * @return string
	 */
	private static function json_error_message( $type, $index ) {
		$label = $type ? $type : sprintf(
			/* translators: %d: block number */
			__( 'block %d', 'shied-world-schema-markup' ),
			(int) $index + 1
		);
		return sprintf(
			/* translators: %s: block type or number */
			__( 'SHIED WORLD Schema Markup could not save: invalid JSON syntax in %s.', 'shied-world-schema-markup' ),
			$label
		);
	}

	/**
	 * Sanitize one nested object value coming from a recursive sub-form.
	 *
	 * Every leaf is sanitized as plain text, nested arrays recurse, and
	 * empty leaves are omitted. A nested object that ends up with nothing
	 * but its type marker, or that ends up completely empty, is omitted
	 * entirely from the stored block.
	 *
	 * @param mixed $value Raw nested value.
	 * @return array<string,mixed>|null Clean array or null when empty.
	 */
	private static function sanitize_nested( $value ) {
		if ( ! is_array( $value ) ) {
			return null;
		}
		$out = array();
		foreach ( $value as $k => $v ) {
			if ( ! preg_match( '/^@?[A-Za-z0-9_]+$/', (string) $k ) ) {
				continue;
			}
			if ( is_array( $v ) ) {
				$cv = self::sanitize_nested( $v );
				if ( null !== $cv ) {
					$out[ $k ] = $cv;
				}
				continue;
			}
			$sv = trim( (string) $v );
			if ( '' === $sv ) {
				continue;
			}
			$out[ $k ] = sanitize_text_field( $sv );
		}
		if ( empty( $out ) ) {
			return null;
		}
		if ( 1 === count( $out ) && isset( $out['@type'] ) ) {
			return null;
		}
		return $out;
	}
}
