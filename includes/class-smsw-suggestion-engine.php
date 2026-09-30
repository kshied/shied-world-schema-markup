<?php
/**
 * Rule-based schema suggestion engine (no external APIs).
 *
 * @package Shied_World_Schema_Markup
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Suggests a schema type from local content rules.
 */
class SMSW_Suggestion_Engine {

	/**
	 * Suggest the first matching type for a post.
	 *
	 * @param int|WP_Post $post Post.
	 * @return array{type:string,reason:string}|null
	 */
	public static function suggest( $post ) {
		$post = get_post( $post );
		if ( ! $post ) {
			return null;
		}

		$settings = SMSW_Options::get_suggestion_settings();
		if ( empty( $settings['enabled'] ) ) {
			return null;
		}

		$post_type = $post->post_type;
		$title     = strtolower( (string) get_the_title( $post ) );
		$slug      = strtolower( (string) $post->post_name );
		$content   = (string) $post->post_content;

		// 1. WooCommerce product.
		if ( 'product' === $post_type && class_exists( 'WooCommerce' ) ) {
			return array(
				'type'   => 'Product',
				'reason' => __( 'WooCommerce product detected.', 'shied-world-schema-markup' ),
			);
		}

		// 2. Recipe category or tag on posts.
		if ( 'post' === $post_type && self::has_term_containing( $post->ID, 'recipe' ) ) {
			return array(
				'type'   => 'Recipe',
				'reason' => __( 'A category or tag contains the word recipe.', 'shied-world-schema-markup' ),
			);
		}

		// 3. FAQ style headings with question marks.
		if ( self::looks_like_faq( $content ) ) {
			return array(
				'type'   => 'FAQPage',
				'reason' => __( 'Multiple headings look like questions.', 'shied-world-schema-markup' ),
			);
		}

		// 4. About page.
		if ( 'page' === $post_type && ( false !== strpos( $slug, 'about' ) || false !== strpos( $title, 'about' ) ) ) {
			return array(
				'type'   => 'AboutPage',
				'reason' => __( 'Page slug or title contains about.', 'shied-world-schema-markup' ),
			);
		}

		// 5. Contact page.
		if ( 'page' === $post_type && ( false !== strpos( $slug, 'contact' ) || false !== strpos( $title, 'contact' ) ) ) {
			return array(
				'type'   => 'ContactPage',
				'reason' => __( 'Page slug or title contains contact.', 'shied-world-schema-markup' ),
			);
		}

		// 6. Standard post article.
		if ( 'post' === $post_type && $post->post_author && $post->post_date ) {
			return array(
				'type'   => 'Article',
				'reason' => __( 'Post has an author and published date.', 'shied-world-schema-markup' ),
			);
		}

		// 7. HowTo ordered list with 3+ steps.
		if ( self::looks_like_howto( $content ) ) {
			return array(
				'type'   => 'HowTo',
				'reason' => __( 'Content includes an ordered list with 3 or more steps.', 'shied-world-schema-markup' ),
			);
		}

		// 8. Portfolio / project.
		if ( false !== strpos( $post_type, 'portfolio' ) || false !== strpos( $post_type, 'project' ) ) {
			return array(
				'type'   => 'CreativeWork',
				'reason' => __( 'Post type slug contains portfolio or project.', 'shied-world-schema-markup' ),
			);
		}

		return null;
	}

	/**
	 * Whether a category or tag name/slug contains a needle.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $needle  Needle.
	 * @return bool
	 */
	private static function has_term_containing( $post_id, $needle ) {
		$needle = strtolower( $needle );
		foreach ( array( 'category', 'post_tag' ) as $taxonomy ) {
			$terms = get_the_terms( $post_id, $taxonomy );
			if ( empty( $terms ) || is_wp_error( $terms ) ) {
				continue;
			}
			foreach ( $terms as $term ) {
				$name = strtolower( $term->name );
				$slug = strtolower( $term->slug );
				if ( false !== strpos( $name, $needle ) || false !== strpos( $slug, $needle ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * Detect FAQ-like heading patterns.
	 *
	 * @param string $content Post content.
	 * @return bool
	 */
	private static function looks_like_faq( $content ) {
		$question_headings = 0;

		if ( preg_match_all( '/<!--\s*wp:heading[^>]*-->\s*<h[1-6][^>]*>\s*([^<]*\?)\s*<\/h[1-6]>/i', $content, $matches ) ) {
			$question_headings += count( $matches[1] );
		}

		if ( preg_match_all( '/<h[1-6][^>]*>\s*([^<]*\?)\s*<\/h[1-6]>/i', $content, $matches2 ) ) {
			$question_headings = max( $question_headings, count( $matches2[1] ) );
		}

		return $question_headings >= 2;
	}

	/**
	 * Detect ordered lists with 3+ items.
	 *
	 * @param string $content Post content.
	 * @return bool
	 */
	private static function looks_like_howto( $content ) {
		if ( preg_match_all( '/<ol\b[^>]*>(.*?)<\/ol>/is', $content, $lists ) ) {
			foreach ( $lists[1] as $list_html ) {
				if ( preg_match_all( '/<li\b/i', $list_html, $items ) && count( $items[0] ) >= 3 ) {
					return true;
				}
			}
		}

		if ( preg_match( '/<!--\s*wp:list\s+({.*?})\s+-->/s', $content, $block_match ) ) {
			$attrs = json_decode( $block_match[1], true );
			if ( is_array( $attrs ) && ! empty( $attrs['ordered'] ) ) {
				if ( preg_match_all( '/<!--\s*wp:list-item\b/i', $content, $items2 ) && count( $items2[0] ) >= 3 ) {
					return true;
				}
			}
		}

		return false;
	}
}
