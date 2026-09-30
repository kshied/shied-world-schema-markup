<?php
/**
 * Top-level admin menu and asset loading.
 *
 * @package Shied_World_Schema_Markup
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers Schema Markup admin menu.
 */
class SMSW_Admin_Menu {

	/**
	 * Menu slug.
	 *
	 * @var string
	 */
	const MENU_SLUG = 'shied-world-schema-markup';

	/**
	 * Init hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_action( 'wp_ajax_smsw_type_properties', array( __CLASS__, 'ajax_type_properties' ) );
	}

	/**
	 * AJAX: full official property definitions for one schema type.
	 *
	 * Read-only. Accepts a valid nonce, or any logged-in user who can edit
	 * posts, so stale cached admin pages cannot break the editor.
	 *
	 * @return void
	 */
	public static function ajax_type_properties() {
		if ( ! check_ajax_referer( 'smsw_preview_json', 'nonce', false ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Your session expired. Reload this page and try again.', 'shied-world-schema-markup' ),
				),
				403
			);
		}

		if ( ! current_user_can( 'edit_posts' ) && ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Your session expired. Reload this page and try again.', 'shied-world-schema-markup' ),
				),
				403
			);
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Verified above via check_ajax_referer().
		$type = isset( $_POST['type'] ) ? sanitize_text_field( wp_unslash( $_POST['type'] ) ) : '';

		if ( '' === $type || ! preg_match( '/^[A-Za-z0-9_\-]+$/', $type ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Invalid schema type.', 'shied-world-schema-markup' ),
				),
				400
			);
		}

		$defs = SMSW_Schema_Types::get_editor_properties( $type );

		wp_send_json_success(
			array(
				'type'       => $type,
				'properties' => is_array( $defs ) ? $defs : array(),
				// The essential/advanced split travels with the type it belongs to
				// so the builder can render the right groups without the page
				// having to carry a classification for every schema.org type.
				'essential'  => SMSW_Schema_Types::get_essential_keys( $type ),
			)
		);
	}

	/**
	 * Logo URL for settings header branding.
	 *
	 * @return string
	 */
	public static function get_logo_url() {
		return SMSW_PLUGIN_URL . 'assets/images/shied-world-schema-markup-logo.png';
	}

	/**
	 * Register top-level menu.
	 *
	 * @return void
	 */
	public static function register_menu() {
		add_menu_page(
			__( 'SHIED WORLD Schema Markup', 'shied-world-schema-markup' ),
			__( 'Schema Markup', 'shied-world-schema-markup' ),
			'manage_options',
			self::MENU_SLUG,
			array( 'SMSW_Settings', 'render_page' ),
			'dashicons-editor-code',
			58
		);
	}

	/**
	 * Shared localize payload for the block builder.
	 *
	 * @param int $post_id Optional post ID for editor context.
	 * @return array<string,mixed>
	 */
	public static function get_builder_localize( $post_id = 0 ) {
		$vocab_types = array();
		if ( class_exists( 'SMSW_Vocabulary_Parser' ) ) {
			try {
				$parser = new SMSW_Vocabulary_Parser();
				$names  = $parser->get_all_type_names();
				if ( is_array( $names ) ) {
					foreach ( $names as $name ) {
						$name = (string) $name;
						if ( '' !== $name ) {
							$vocab_types[ $name ] = $name;
						}
					}
				}
			} catch ( Exception $e ) {
				// The vocabulary parser is optional. When it cannot load, the
				// built in type list below is used as the fallback, so the
				// exception carries no action for the user.
				unset( $e );
			}
		}
		return array(
			'types'             => SMSW_Schema_Types::get_types(),
			// Property definitions and essential keys are deliberately NOT
			// shipped for every type here. Doing so sent roughly 12.5 MB of
			// JSON to the browser and forced PHP to expand all 928 schema.org
			// classes on every admin page load, which exhausted the memory
			// limit. The builder already has a lazy loader: it asks for the one
			// type on screen through smsw_type_properties, which now also
			// returns that type's essential keys.
			'properties'        => array(),
			'customType'        => SMSW_Schema_Types::CUSTOM_TYPE,
			'ajaxUrl'           => admin_url( 'admin-ajax.php' ),
			'nonce'             => wp_create_nonce( 'smsw_preview_json' ),
			'aiNonce'           => wp_create_nonce( 'smsw_ai_suggest' ),
			'placeholders'      => SMSW_Placeholders::get_supported(),
			'vocabularyTypes'   => $vocab_types,
			'postId'            => absint( $post_id ),
			'permalink'         => $post_id ? get_permalink( $post_id ) : '',
			'previewNonce'      => wp_create_nonce( 'smsw_preview_json' ),
			'hasAiKey'          => SMSW_AI_Suggestion::has_key(),
			'placeholderValues' => SMSW_Placeholders::get_map( $post_id ? $post_id : null ),
			'warningRules'      => SMSW_Schema_Validator::get_js_rules(),
			'postTitle'         => $post_id ? (string) get_the_title( $post_id ) : '',
			'typeGroups'        => SMSW_Schema_Types::get_type_groups(),
			// Type => ordered essential property keys. Filled in per type by the
			// smsw_type_properties response, so the builder never re-derives the
			// split per block and no page has to classify every schema.org class.
			// A type with no entry yet falls back to showing all of its fields.
			'essentialMap'      => array(),
			'i18n'              => array(
				'schemaType'        => __( 'Schema type', 'shied-world-schema-markup' ),
				'selectType'        => __( 'Search and select a schema type...', 'shied-world-schema-markup' ),
				'enabled'           => __( 'Enabled', 'shied-world-schema-markup' ),
				'duplicate'         => __( 'Duplicate', 'shied-world-schema-markup' ),
				'delete'            => __( 'Delete', 'shied-world-schema-markup' ),
				'customType'        => __( '@type value', 'shied-world-schema-markup' ),
				'customJson'        => __( 'Raw JSON-LD', 'shied-world-schema-markup' ),
				'properties'        => __( 'Properties', 'shied-world-schema-markup' ),
				'confirmDelete'     => __( 'Delete this schema block?', 'shied-world-schema-markup' ),
				'newBlock'          => __( 'New Schema Block', 'shied-world-schema-markup' ),
				'noTypes'           => __( 'No matching types.', 'shied-world-schema-markup' ),
				'previewEmpty'      => __( 'Nothing to output. Enable at least one site or page schema block.', 'shied-world-schema-markup' ),
				'aiLoading'         => __( 'Asking AI...', 'shied-world-schema-markup' ),
				'aiError'           => __( 'AI suggestion failed.', 'shied-world-schema-markup' ),
				'aiAccept'          => __( 'Use AI suggestion', 'shied-world-schema-markup' ),
				'aiDismiss'         => __( 'Dismiss', 'shied-world-schema-markup' ),
				// Per-card label, shown once per returned suggestion.
				'aiSuggestedType'   => __( 'Suggested Schema Type', 'shied-world-schema-markup' ),
				// Shown when the AI returns no suggestion worth adding.
				'aiCovered'         => __( 'This page already has good schema coverage.', 'shied-world-schema-markup' ),
				'warningPrefix'     => __( 'Warning:', 'shied-world-schema-markup' ),
				'copyEmpty'         => __( 'Nothing to copy yet. Generate a preview first.', 'shied-world-schema-markup' ),
				'copyManual'        => __( 'Automatic copy failed. Please manually select and copy the text from the preview box.', 'shied-world-schema-markup' ),
				'copyOk'            => __( 'Copied to clipboard.', 'shied-world-schema-markup' ),
				'copySchemaOk'      => __( 'Copied. Paste into the validator that is about to open.', 'shied-world-schema-markup' ),
				'copyDismiss'       => __( 'Dismiss', 'shied-world-schema-markup' ),
				'validateThis'      => __( 'Validate This Schema', 'shied-world-schema-markup' ),
				'preview'           => __( 'Preview', 'shied-world-schema-markup' ),
				'previewCopy'       => __( 'Copy', 'shied-world-schema-markup' ),
				'previewCopied'     => __( 'Copied!', 'shied-world-schema-markup' ),
				'loadingProperties' => __( 'Loading full properties...', 'shied-world-schema-markup' ),
				'validateBlockOk'   => __( 'Copied. Opening Google Rich Results Test.', 'shied-world-schema-markup' ),
				'showMoreFields'   => __( 'Show more fields', 'shied-world-schema-markup' ),
				'showFewerFields'  => __( 'Show fewer fields', 'shied-world-schema-markup' ),
				// Count is inserted into the middle of the sentence, so the
				// position rule above applies; %d is the hidden-field count.
				/* translators: %d: number of fields that are currently hidden. */
				'moreFieldsCount'  => __( 'Show more fields (%d)', 'shied-world-schema-markup' ),
				'advancedFields'   => __( 'Advanced fields', 'shied-world-schema-markup' ),
			),
		);
	}

	/**
	 * Enqueue admin CSS/JS on plugin screens and post editor.
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public static function enqueue_assets( $hook ) {
		$is_settings = ( 'toplevel_page_' . self::MENU_SLUG === $hook );
		$is_post     = in_array( $hook, array( 'post.php', 'post-new.php' ), true );

		if ( ! $is_settings && ! $is_post ) {
			return;
		}

		wp_enqueue_style(
			'smsw-admin',
			SMSW_PLUGIN_URL . 'assets/css/admin.css',
			array(),
			smsw_asset_version( 'assets/css/admin.css' )
		);

		// Show/hide eye toggle for the AI BYOK key field (settings page only).
		if ( $is_settings ) {
			wp_enqueue_script(
				'smsw-ai-key-toggle',
				SMSW_PLUGIN_URL . 'assets/js/ai-key-toggle.js',
				array(),
				smsw_asset_version( 'assets/js/ai-key-toggle.js' ),
				true
			);
		}

		$post_id = 0;
		if ( $is_post ) {
			global $post;
			$post_id = ( $post && isset( $post->ID ) ) ? (int) $post->ID : 0;
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only editor context; no state change.
			if ( ! $post_id && isset( $_GET['post'] ) ) {
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Same read-only context as above.
				$post_id = absint( $_GET['post'] );
			}
		}

		wp_enqueue_script(
			'smsw-block-builder',
			SMSW_PLUGIN_URL . 'assets/js/block-builder-v2.js',
			array( 'jquery' ),
			smsw_asset_version( 'assets/js/block-builder-v2.js' ),
			true
		);

		wp_localize_script(
			'smsw-block-builder',
			'smswMetaBox',
			self::get_builder_localize( $post_id )
		);
	}
}
