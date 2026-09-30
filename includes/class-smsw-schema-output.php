<?php
/**
 * Frontend JSON-LD output using @graph in wp_footer.
 *
 * @package Shied_World_Schema_Markup
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Merges site-wide and post-level schema into one script tag.
 */
class SMSW_Schema_Output {

	/**
	 * Hook output and AJAX preview.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'wp_footer', array( __CLASS__, 'print_schema' ), 20 );
		add_action( 'wp_ajax_smsw_preview_json', array( __CLASS__, 'ajax_preview' ) );
		add_action( 'wp_ajax_smsw_preview_block', array( __CLASS__, 'ajax_preview_block' ) );
	}

	/**
	 * Verify a read-only preview request.
	 *
	 * Normal requests carry a valid nonce. When a cache or CDN layer served
	 * an admin page with an expired nonce, a valid logged-in session with the
	 * matching edit capability is accepted instead. These endpoints never
	 * modify data, so that fallback is safe and keeps live preview working
	 * on aggressively cached hosts.
	 *
	 * @param int  $post_id  Post ID or 0 for site scope.
	 * @param bool $nonce_ok Whether the caller already verified the nonce.
	 * @return void
	 */
	private static function verify_preview_request( $post_id, $nonce_ok ) {
		if ( $nonce_ok ) {
			return;
		}

		$cap_ok = $post_id ? current_user_can( 'edit_post', $post_id ) : current_user_can( 'manage_options' );

		if ( ! $cap_ok ) {
			wp_send_json_error(
				array(
					'message' => __( 'Your session expired. Reload this page and try again.', 'shied-world-schema-markup' ),
				),
				403
			);
		}
	}

	/**
	 * Print a single application/ld+json script when there is data.
	 *
	 * @return void
	 */
	public static function print_schema() {
		$general = SMSW_Options::get_general_settings();
		if ( empty( $general['output_enabled'] ) ) {
			return;
		}

		$post_id = is_singular() ? get_queried_object_id() : 0;
		$payload = self::build_graph_payload( $post_id, null );

		if ( empty( $payload ) ) {
			return;
		}

		// JSON_HEX_TAG escapes < and > as u003C/u003E so a value that somehow
		// contains </script> can never close the tag early. The escape is
		// valid JSON and every schema.org parser reads it back unchanged.
		$encoded = wp_json_encode( $payload, JSON_HEX_TAG | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT );
		if ( ! $encoded ) {
			return;
		}

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static opening markup.
		echo "\n" . '<script type="application/ld+json" class="smsw-schema">' . "\n";
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON-LD; sanitized on save.
		echo $encoded;
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static closing markup.
		echo "\n</script>\n";
	}

	/**
	 * AJAX live preview for the post editor.
	 *
	 * @return void
	 */
	public static function ajax_preview() {
		// Verify the request before reading any of its data, so a bad nonce or
		// an unauthorized user is rejected before the payload is touched.
		$nonce_ok = check_ajax_referer( 'smsw_preview_json', 'nonce', false );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified above via check_ajax_referer().
		$post_id = isset( $_POST['post_id'] ) ? absint( wp_unslash( $_POST['post_id'] ) ) : 0;
		self::verify_preview_request( $post_id, $nonce_ok );

		if ( $post_id && ! current_user_can( 'edit_post', $post_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'shied-world-schema-markup' ) ), 403 );
		}

		if ( ! $post_id && ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'shied-world-schema-markup' ) ), 403 );
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- JSON string; decoded below.
		$raw    = isset( $_POST['blocks'] ) ? wp_unslash( $_POST['blocks'] ) : '[]';
		$blocks = json_decode( $raw, true );
		if ( ! is_array( $blocks ) ) {
			$blocks = array();
		}

		$payload = self::build_graph_payload( $post_id, $blocks );
		if ( empty( $payload ) ) {
			wp_send_json_success(
				array(
					'json'  => '',
					'empty' => true,
				)
			);
		}

		$encoded = wp_json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT );
		wp_send_json_success(
			array(
				'json'  => $encoded ? $encoded : '',
				'empty' => false,
			)
		);
	}

	/**
	 * AJAX live preview for one individual schema block.
	 *
	 * Returns the JSON-LD for exactly the block passed in the request, with
	 * no site-wide merge, so the per block preview and the per block
	 * validate button show and test only that one block.
	 *
	 * @return void
	 */
	public static function ajax_preview_block() {
		// Verify the request before reading any of its data, so a bad nonce or
		// an unauthorized user is rejected before the payload is touched.
		$nonce_ok = check_ajax_referer( 'smsw_preview_json', 'nonce', false );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified above via check_ajax_referer().
		$post_id = isset( $_POST['post_id'] ) ? absint( wp_unslash( $_POST['post_id'] ) ) : 0;
		self::verify_preview_request( $post_id, $nonce_ok );

		if ( $post_id && ! current_user_can( 'edit_post', $post_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'shied-world-schema-markup' ) ), 403 );
		}

		if ( ! $post_id && ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'shied-world-schema-markup' ) ), 403 );
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- JSON string; decoded below.
		$raw_block = isset( $_POST['block'] ) ? wp_unslash( $_POST['block'] ) : '{}';
		$block     = json_decode( $raw_block, true );
		if ( ! is_array( $block ) ) {
			$block = array();
		}

		$map  = SMSW_Placeholders::get_map( $post_id ? $post_id : null );
		$item = self::block_to_json_ld( $block, $post_id, $map );

		if ( empty( $item ) || ! is_array( $item ) ) {
			wp_send_json_success(
				array(
					'json'  => '',
					'empty' => true,
				)
			);
		}

		$encoded = wp_json_encode( $item, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT );
		wp_send_json_success(
			array(
				'json'  => $encoded ? $encoded : '',
				'empty' => false,
			)
		);
	}

	/**
	 * Build merged @graph payload.
	 *
	 * @param int                   $post_id     Post ID or 0.
	 * @param array<int,array>|null $post_blocks Optional override for post blocks (preview).
	 * @return array<string,mixed>|null
	 */
	public static function build_graph_payload( $post_id = 0, $post_blocks = null ) {
		$graph = array();
		$map   = SMSW_Placeholders::get_map( $post_id ? $post_id : null );

		$site_blocks = SMSW_Options::get_site_schema();
		foreach ( $site_blocks as $block ) {
			if ( empty( $block['enabled'] ) ) {
				continue;
			}
			$item = self::block_to_json_ld( $block, $post_id, $map );
			if ( ! empty( $item ) ) {
				$graph[] = self::strip_context( $item );
			}
		}

		if ( null === $post_blocks && $post_id ) {
			$stored      = get_post_meta( $post_id, SMSW_META_KEY, true );
			$post_blocks = is_array( $stored ) ? $stored : array();
		}

		if ( is_array( $post_blocks ) ) {
			foreach ( $post_blocks as $block ) {
				if ( empty( $block['enabled'] ) ) {
					continue;
				}
				$item = self::block_to_json_ld( $block, $post_id, $map );
				if ( ! empty( $item ) ) {
					$graph[] = self::strip_context( $item );
				}
			}
		}

		if ( empty( $graph ) ) {
			return null;
		}

		return array(
			'@context' => 'https://schema.org',
			'@graph'   => $graph,
		);
	}

	/**
	 * Remove top-level @context so it can live on the wrapper.
	 *
	 * @param array<string,mixed> $item Schema node.
	 * @return array<string,mixed>
	 */
	private static function strip_context( $item ) {
		if ( isset( $item['@context'] ) ) {
			unset( $item['@context'] );
		}
		return $item;
	}

	/**
	 * Convert a stored block into a JSON-LD array with placeholders replaced.
	 *
	 * @param array<string,mixed>  $block   Block data.
	 * @param int                  $post_id Post ID.
	 * @param array<string,string> $map     Placeholder map.
	 * @return array<string,mixed>|null
	 */
	public static function block_to_json_ld( $block, $post_id, $map = null ) {
		if ( null === $map ) {
			$map = SMSW_Placeholders::get_map( $post_id ? $post_id : null );
		}

		$type = isset( $block['type'] ) ? $block['type'] : '';

		if ( SMSW_Schema_Types::CUSTOM_TYPE === $type || 'Custom' === $type ) {
			$raw     = isset( $block['custom_json'] ) ? $block['custom_json'] : '';
			$raw     = SMSW_Placeholders::replace( $raw, $post_id ? $post_id : null, $map );
			// Raw JSON gets the same guarantee: no unresolved token is published.
			$raw     = SMSW_Placeholders::clean_replaced( '', $raw );
			$decoded = json_decode( $raw, true );
			if ( ! is_array( $decoded ) ) {
				return null;
			}
			if ( empty( $decoded['@context'] ) ) {
				$decoded['@context'] = 'https://schema.org';
			}
			if ( ! empty( $block['custom_type'] ) && empty( $decoded['@type'] ) ) {
				$decoded['@type'] = SMSW_Placeholders::replace( $block['custom_type'], $post_id ? $post_id : null, $map );
			}
			return $decoded;
		}

		$props = isset( $block['properties'] ) && is_array( $block['properties'] ) ? $block['properties'] : array();
		$before = $props;
		$props  = SMSW_Placeholders::replace_deep( $props, $post_id ? $post_id : null, $map );

		// A token that could not be resolved must never reach the page as
		// literal "{{post_title}}" text, so it is stripped here.
		$props = SMSW_Placeholders::clean_replaced( $before, $props );

		return SMSW_Schema_Types::build_json_ld( $type, $props );
	}
}
