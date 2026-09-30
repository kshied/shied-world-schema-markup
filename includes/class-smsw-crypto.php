<?php
/**
 * Encrypt and decrypt sensitive option values.
 *
 * @package Shied_World_Schema_Markup
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * AES-256-GCM helpers using WordPress salt material.
 *
 * AUTH_KEY is used first so payloads written by versions using the same
 * preferred material remain readable. The other WordPress salts are fallbacks,
 * so the plugin still works on a site whose wp-config.php has no AUTH_KEY. No
 * shared key is ever shipped with the plugin.
 *
 * Payloads are versioned and authenticated. Legacy AES-256-CBC payloads are
 * deliberately rejected because they have no authentication tag; accepting
 * them would leave saved keys vulnerable to undetected tampering. Users must
 * re-enter keys stored by those earlier versions.
 */
class SMSW_Crypto {

	/**
	 * Packet prefix used to identify authenticated payloads.
	 *
	 * @var string
	 */
	private const PACKET_PREFIX = 'smsw-gcm-v1:';

	/**
	 * Additional authenticated data binding ciphertext to this key format.
	 *
	 * @var string
	 */
	private const AAD = 'shied-world-schema-markup/ai-key/v1';

	/**
	 * AES-GCM initialization-vector length in bytes.
	 *
	 * @var int
	 */
	private const IV_LENGTH = 12;

	/**
	 * AES-GCM authentication-tag length in bytes.
	 *
	 * @var int
	 */
	private const TAG_LENGTH = 16;

	/**
	 * Salt values that may be used as key material, most preferred first.
	 *
	 * @return array<int,string>
	 */
	private static function key_material_candidates() {
		$candidates = array();

		foreach ( array( 'AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY' ) as $constant ) {
			if ( ! defined( $constant ) ) {
				continue;
			}

			$value = constant( $constant );
			if ( is_string( $value ) && '' !== $value ) {
				$candidates[] = $value;
			}
		}

		if ( function_exists( 'wp_salt' ) ) {
			$salt = wp_salt( 'auth' );
			if ( is_string( $salt ) && '' !== $salt ) {
				$candidates[] = $salt;
			}
		}

		return array_values( array_unique( $candidates ) );
	}

	/**
	 * Encrypt plaintext.
	 *
	 * @param string $plaintext Value to encrypt.
	 * @return string Base64 payload or empty string on failure.
	 */
	public static function encrypt( $plaintext ) {
		$plaintext = (string) $plaintext;
		if ( '' === $plaintext ) {
			return '';
		}

		if ( ! function_exists( 'openssl_encrypt' ) || ! function_exists( 'openssl_get_cipher_methods' ) ) {
			return '';
		}

		if ( ! in_array( 'aes-256-gcm', openssl_get_cipher_methods(), true ) ) {
			return '';
		}

		foreach ( self::key_material_candidates() as $material ) {
			$iv = openssl_random_pseudo_bytes( self::IV_LENGTH );
			if ( false === $iv || self::IV_LENGTH !== strlen( $iv ) ) {
				return '';
			}

			$tag   = '';
			$key   = hash( 'sha256', $material, true );
			$cipher = openssl_encrypt(
				$plaintext,
				'aes-256-gcm',
				$key,
				OPENSSL_RAW_DATA,
				$iv,
				$tag,
				self::AAD,
				self::TAG_LENGTH
			);

			if ( false !== $cipher && self::TAG_LENGTH === strlen( $tag ) ) {
				// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- Storage encoding.
				return base64_encode( self::PACKET_PREFIX . $iv . $tag . $cipher );
			}
		}

		return '';
	}

	/**
	 * Decrypt a current, authenticated payload produced by encrypt().
	 *
	 * Legacy AES-256-CBC payloads intentionally return an empty string because
	 * their integrity cannot be verified.
	 *
	 * @param string $payload Base64 payload.
	 * @return string Plaintext or empty string on failure.
	 */
	public static function decrypt( $payload ) {
		if ( '' === (string) $payload ) {
			return '';
		}

		if ( ! function_exists( 'openssl_decrypt' ) ) {
			return '';
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- Packet decode; false handled below.
		$raw = base64_decode( $payload, true );
		if ( false === $raw ) {
			return '';
		}

		$prefix_length = strlen( self::PACKET_PREFIX );
		if ( strlen( $raw ) < $prefix_length + self::IV_LENGTH + self::TAG_LENGTH + 1 ) {
			return '';
		}

		if ( 0 !== strpos( $raw, self::PACKET_PREFIX ) ) {
			return '';
		}

		$payload_without_prefix = substr( $raw, $prefix_length );
		$iv                     = substr( $payload_without_prefix, 0, self::IV_LENGTH );
		$tag                    = substr( $payload_without_prefix, self::IV_LENGTH, self::TAG_LENGTH );
		$cipher                 = substr( $payload_without_prefix, self::IV_LENGTH + self::TAG_LENGTH );

		foreach ( self::key_material_candidates() as $material ) {
			$plain = openssl_decrypt(
				$cipher,
				'aes-256-gcm',
				hash( 'sha256', $material, true ),
				OPENSSL_RAW_DATA,
				$iv,
				$tag,
				self::AAD
			);

			if ( false !== $plain && '' !== $plain ) {
				return $plain;
			}
		}

		return '';
	}
}
