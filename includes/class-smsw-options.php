<?php
/**
 * Plugin options helpers and defaults.
 *
 * @package Shied_World_Schema_Markup
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Central option keys and getters.
 */
class SMSW_Options {

	const SITE_SCHEMA = 'smsw_site_schema_blocks';
	const POST_TYPES  = 'smsw_enabled_post_types';
	const AI_SETTINGS = 'smsw_ai_settings';
	const SUGGESTIONS = 'smsw_suggestion_settings';
	const GENERAL     = 'smsw_general_settings';

	/**
	 * Ensure defaults exist.
	 *
	 * @return void
	 */
	public static function ensure_defaults() {
		if ( false === get_option( self::SITE_SCHEMA, false ) ) {
			add_option( self::SITE_SCHEMA, array() );
		}
		if ( false === get_option( self::POST_TYPES, false ) ) {
			add_option( self::POST_TYPES, array( 'post', 'page' ) );
		}
		if ( false === get_option( self::AI_SETTINGS, false ) ) {
			add_option(
				self::AI_SETTINGS,
				array(
					'provider'          => 'openai',
					'keys'              => array(),
					'models'            => array(),
					// The lightweight context is the default: cheaper, and
					// better for schema type classification.
					'send_full_content' => 0,
				)
			);
		}
		if ( false === get_option( self::SUGGESTIONS, false ) ) {
			add_option(
				self::SUGGESTIONS,
				array(
					'enabled' => 1,
				)
			);
		}
		if ( false === get_option( self::GENERAL, false ) ) {
			add_option(
				self::GENERAL,
				array(
					'output_enabled'      => 1,
					'delete_on_uninstall' => 0,
				)
			);
		}
	}

	/**
	 * Site-wide schema blocks.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function get_site_schema() {
		$blocks = get_option( self::SITE_SCHEMA, array() );
		return is_array( $blocks ) ? $blocks : array();
	}

	/**
	 * Enabled post types for the meta box.
	 *
	 * @return array<int,string>
	 */
	public static function get_enabled_post_types() {
		$types = get_option( self::POST_TYPES, array( 'post', 'page' ) );
		if ( ! is_array( $types ) ) {
			return array( 'post', 'page' );
		}
		return array_values( array_filter( array_map( 'sanitize_key', $types ) ) );
	}

	/**
	 * AI settings: selected provider, one encrypted key per provider, the model
	 * chosen per provider, and the full-content toggle.
	 *
	 * The stored option keeps every provider's encrypted payload under its own
	 * key inside the "keys" map, so saving a key for one provider never touches
	 * the key stored for any other provider. Model choices live in a parallel
	 * "models" map with the same per-provider shape.
	 *
	 * @return array{provider:string,keys:array<string,string>,models:array<string,string>,send_full_content:bool}
	 */
	public static function get_ai_settings() {
		$settings = get_option( self::AI_SETTINGS, array() );
		if ( ! is_array( $settings ) ) {
			$settings = array();
		}

		$provider = isset( $settings['provider'] ) ? sanitize_key( $settings['provider'] ) : 'openai';
		if ( '' === $provider ) {
			$provider = 'openai';
		}

		$keys = array();
		if ( isset( $settings['keys'] ) && is_array( $settings['keys'] ) ) {
			foreach ( $settings['keys'] as $stored_provider => $payload ) {
				$stored_provider = sanitize_key( $stored_provider );
				if ( '' !== $stored_provider && is_string( $payload ) && '' !== $payload ) {
					$keys[ $stored_provider ] = $payload;
				}
			}
		}

		// Model ids are plain text, never secrets, so only trimming is needed.
		// They are deliberately not validated against the provider list here:
		// a model can be retired between two page loads, and this getter must
		// never depend on a network call.
		$models = array();
		if ( isset( $settings['models'] ) && is_array( $settings['models'] ) ) {
			foreach ( $settings['models'] as $stored_provider => $model ) {
				$stored_provider = sanitize_key( $stored_provider );
				$model           = is_string( $model ) ? trim( $model ) : '';

				if ( '' !== $stored_provider && '' !== $model ) {
					$models[ $stored_provider ] = $model;
				}
			}
		}

		// Only explicit per-provider entries are keys. A legacy shared payload
		// must not be assigned to a provider that has never saved its own key.

		return array(
			'provider'          => $provider,
			'keys'              => $keys,
			'models'            => $models,
			// Off unless the site owner explicitly turns it on.
			'send_full_content' => ! empty( $settings['send_full_content'] ),
		);
	}

	/**
	 * Encrypted payload stored for one specific provider.
	 *
	 * @param string $provider Provider slug.
	 * @return string
	 */
	public static function get_ai_key( $provider ) {
		$settings = self::get_ai_settings();
		$provider = sanitize_key( $provider );

		return isset( $settings['keys'][ $provider ] ) ? $settings['keys'][ $provider ] : '';
	}

	/**
	 * Suggestion engine settings.
	 *
	 * @return array{enabled:int}
	 */
	public static function get_suggestion_settings() {
		$settings = get_option( self::SUGGESTIONS, array() );
		if ( ! is_array( $settings ) ) {
			$settings = array();
		}
		return array(
			'enabled' => empty( $settings['enabled'] ) ? 0 : 1,
		);
	}

	/**
	 * General settings.
	 *
	 * @return array{output_enabled:int,delete_on_uninstall:int}
	 */
	public static function get_general_settings() {
		$settings = get_option( self::GENERAL, array() );
		if ( ! is_array( $settings ) ) {
			$settings = array();
		}
		return array(
			'output_enabled'      => ! isset( $settings['output_enabled'] ) || ! empty( $settings['output_enabled'] ) ? 1 : 0,
			'delete_on_uninstall' => ! empty( $settings['delete_on_uninstall'] ) ? 1 : 0,
		);
	}
}
