<?php
/**
 * Placeholder variable replacement.
 *
 * @package Shied_World_Schema_Markup
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Replaces {{placeholder}} tokens with WordPress values.
 */
class SMSW_Placeholders {

	/**
	 * Matches an unresolved {{token}} in any string.
	 */
	const TOKEN_PATTERN = '/\{\{[^{}]+\}\}/';

	/**
	 * Supported placeholder keys (without braces).
	 *
	 * @return array<int,string>
	 */
	public static function get_supported() {
		return array(
			'post_title',
			'post_url',
			'post_excerpt',
			'meta_description',
			'featured_image',
			'site_name',
			'site_url',
			'site_logo',
			'author_name',
			'post_date',
		);
	}

	/**
	 * Resolve the meta description for a post with this priority:
	 * Rank Math, then Yoast SEO, then All in One SEO, then the post excerpt.
	 *
	 * Only reads other plugins data with get_post_meta. Never writes.
	 *
	 * @param WP_Post $post Post object.
	 * @return string
	 */
	private static function get_meta_description( $post ) {
		$keys = array(
			'rank_math_description',
			'_yoast_wpseo_metadesc',
			'_aioseo_description',
		);

		foreach ( $keys as $key ) {
			$value = get_post_meta( $post->ID, $key, true );
			if ( is_string( $value ) && '' !== trim( $value ) ) {
				return $value;
			}
		}

		return has_excerpt( $post ) ? get_the_excerpt( $post ) : wp_trim_words( wp_strip_all_tags( $post->post_content ), 40, '...' );
	}

	/**
	 * Site logo URL for the {{site_logo}} placeholder.
	 *
	 * Prefers the Customizer logo, then the Site Icon, and returns an
	 * empty string when no site image is configured.
	 *
	 * @return string
	 */
	private static function get_site_logo() {
		$logo_id = (int) get_theme_mod( 'custom_logo' );
		if ( $logo_id ) {
			$logo = wp_get_attachment_image_url( $logo_id, 'full' );
			if ( is_string( $logo ) && '' !== $logo ) {
				return $logo;
			}
		}

		$icon_id = (int) get_option( 'site_icon' );
		if ( $icon_id ) {
			$icon = wp_get_attachment_image_url( $icon_id, 'full' );
			if ( is_string( $icon ) && '' !== $icon ) {
				return $icon;
			}
		}

		return '';
	}

	/**
	 * Build replacement map for a post.
	 *
	 * @param int|WP_Post|null $post Post ID, object, or null for current.
	 * @return array<string,string>
	 */
	public static function get_map( $post = null ) {
		$post = get_post( $post );
		$map  = array(
			'{{site_name}}'        => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
			'{{site_url}}'         => home_url( '/' ),
			'{{site_logo}}'        => self::get_site_logo(),
			'{{post_title}}'       => '',
			// Site wide blocks have no post to link to, so they fall back to the
			// site home URL. An empty string here would publish a required
			// property with no value, which schema validators reject.
			'{{post_url}}'         => home_url( '/' ),
			'{{post_excerpt}}'     => '',
			'{{meta_description}}' => '',
			'{{featured_image}}'   => '',
			'{{author_name}}'      => '',
			'{{post_date}}'        => '',
		);

		if ( ! $post ) {
			return $map;
		}

		$map['{{post_title}}'] = get_the_title( $post );
		$map['{{post_url}}']   = get_permalink( $post );
		$map['{{post_date}}']  = get_the_date( 'c', $post );

		$excerpt                 = has_excerpt( $post ) ? get_the_excerpt( $post ) : wp_trim_words( wp_strip_all_tags( $post->post_content ), 40, '...' );
		$map['{{post_excerpt}}'] = $excerpt;

		$map['{{meta_description}}'] = self::get_meta_description( $post );

		$thumb = get_the_post_thumbnail_url( $post, 'full' );
		if ( $thumb ) {
			$map['{{featured_image}}'] = $thumb;
		}

		$author = get_the_author_meta( 'display_name', (int) $post->post_author );
		if ( $author ) {
			$map['{{author_name}}'] = $author;
		}

		return $map;
	}

	/**
	 * Whether a string still contains an unresolved {{token}}.
	 *
	 * @param mixed $text Value to test.
	 * @return bool
	 */
	public static function has_unresolved_token( $text ) {
		return is_string( $text ) && (bool) preg_match( self::TOKEN_PATTERN, $text );
	}

	/**
	 * Whether a string is nothing but a single placeholder token.
	 *
	 * A value like "{{post_title}}" is a deferred default the plugin inserted,
	 * never something a person deliberately typed, so it may be dropped when it
	 * has no value to give. A value the user typed around a token, such as
	 * "Written by {{post_title}}", is real content and is never treated this way.
	 *
	 * @param mixed $value Value to test.
	 * @return bool
	 */
	public static function is_token_only( $value ) {
		if ( ! is_string( $value ) ) {
			return false;
		}

		$trimmed = trim( $value );

		if ( '' === $trimmed ) {
			return false;
		}

		return 1 === preg_match( '/^\{\{[^{}]+\}\}$/', $trimmed );
	}

	/**
	 * Remove any placeholder token that survived replacement.
	 *
	 * A token is only ever a stored default that the renderer swaps for a real
	 * value. If it cannot be resolved, printing it would put the literal text
	 * "{{post_title}}" into the page, so the token is stripped and a property
	 * that held nothing else is dropped rather than published as an empty value.
	 *
	 * The pre-replacement values are needed to tell an unresolved default apart
	 * from an empty value the user typed on purpose, so both are passed in.
	 *
	 * @param mixed $before Values before replacement.
	 * @param mixed $after  Values after replacement.
	 * @return mixed
	 */
	public static function clean_replaced( $before, $after ) {
		if ( is_string( $after ) ) {
			return preg_replace( self::TOKEN_PATTERN, '', $after );
		}

		if ( ! is_array( $after ) ) {
			return $after;
		}

		foreach ( $after as $key => $value ) {
			$prev          = is_array( $before ) && array_key_exists( $key, $before ) ? $before[ $key ] : null;
			$was_token_only = self::is_token_only( $prev );

			$after[ $key ] = self::clean_replaced( $prev, $value );

			if ( $was_token_only && is_string( $after[ $key ] ) && '' === trim( $after[ $key ] ) ) {
				unset( $after[ $key ] );
			}
		}

		return $after;
	}

	/**
	 * Replace placeholders in a string.
	 *
	 * @param string               $text Text with placeholders.
	 * @param int|WP_Post|null     $post Post context.
	 * @param array<string,string> $map  Optional prebuilt map.
	 * @return string
	 */
	public static function replace( $text, $post = null, $map = null ) {
		if ( ! is_string( $text ) || '' === $text ) {
			return '';
		}
		if ( null === $map ) {
			$map = self::get_map( $post );
		}
		return strtr( $text, $map );
	}

	/**
	 * Recursively replace placeholders in arrays and strings.
	 *
	 * @param mixed                $data Data.
	 * @param int|WP_Post|null     $post Post context.
	 * @param array<string,string> $map  Optional prebuilt map.
	 * @return mixed
	 */
	public static function replace_deep( $data, $post = null, $map = null ) {
		if ( null === $map ) {
			$map = self::get_map( $post );
		}

		if ( is_string( $data ) ) {
			return self::replace( $data, $post, $map );
		}

		if ( is_array( $data ) ) {
			foreach ( $data as $key => $value ) {
				$data[ $key ] = self::replace_deep( $value, $post, $map );
			}
		}

		return $data;
	}
}
