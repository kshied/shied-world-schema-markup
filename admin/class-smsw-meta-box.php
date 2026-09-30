<?php
/**
 * Post editor meta box for named schema blocks.
 *
 * @package Shied_World_Schema_Markup
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Meta box registration, render, and save.
 */
class SMSW_Meta_Box {

	/**
	 * Meta box ID for the main schema blocks editor.
	 *
	 * Note: WordPress uses this as the outer postbox wrapper id. The inner
	 * block list uses class selectors only, so getElementById always finds
	 * the visible postbox and never an inner duplicate.
	 *
	 * @var string
	 */
	const BOX_ID = 'smsw_schema_blocks';

	/**
	 * Side meta box ID for the jump panel.
	 *
	 * @var string
	 */
	const SIDE_BOX_ID = 'smsw_schema_side_jump';

	/**
	 * Transient key prefix for validation errors.
	 *
	 * @var string
	 */
	const NOTICE_KEY = 'smsw_save_error_';

	/**
	 * Transient key prefix for warning notices (non blocking).
	 *
	 * @var string
	 */
	const WARNING_KEY = 'smsw_save_warning_';

	/**
	 * Init hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'add_meta_boxes', array( __CLASS__, 'register' ) );
		add_action( 'save_post', array( __CLASS__, 'save' ), 10, 2 );
	}

	/**
	 * Register meta boxes only on enabled post types.
	 *
	 * @return void
	 */
	public static function register() {
		foreach ( SMSW_Options::get_enabled_post_types() as $post_type ) {
			if ( ! post_type_exists( $post_type ) ) {
				continue;
			}

			add_meta_box(
				self::BOX_ID,
				__( 'SHIED WORLD Schema Markup', 'shied-world-schema-markup' ),
				array( __CLASS__, 'render' ),
				$post_type,
				'normal',
				'default'
			);

			add_meta_box(
				self::SIDE_BOX_ID,
				// Panel header. Deliberately a different string from the button
				// label below: "Go to Schema Blocks" is this plugin's internal
				// naming and means nothing to a user who has not seen the plugin
				// before, so the section is titled after the plugin instead. The
				// button underneath keeps the original wording.
				__( 'Schema Markup', 'shied-world-schema-markup' ),
				array( __CLASS__, 'render_side' ),
				$post_type,
				'side',
				'high'
			);
		}
	}

	/**
	 * Render the small side meta box with a jump button to the main box.
	 *
	 * Shows a live count of the schema blocks saved on the post so the number
	 * stays visible even while the main meta box is collapsed. The block
	 * builder JavaScript refreshes the number whenever blocks are added,
	 * duplicated, or deleted.
	 *
	 * The footer script binds this button directly by id, because Gutenberg
	 * React swallows bubbled clicks inside the meta boxes sidebar. It is a
	 * button with no href on purpose, so the browser can never fall back to a
	 * native hash jump, even if React strips the listener from the node.
	 *
	 * @param WP_Post|null $post Current post.
	 * @return void
	 */
	public static function render_side( $post = null ) {
		$count = 0;

		if ( $post instanceof WP_Post ) {
			$blocks = get_post_meta( $post->ID, SMSW_META_KEY, true );

			if ( is_array( $blocks ) ) {
				$count = count( $blocks );
			}
		}
		?>
		<?php
		// NOTE: this wrapper was a <p>, which cannot legally contain the <p>
		// note below. The HTML parser silently closed it early, hoisting the
		// note and the count line out of .smsw-side-panel and breaking every
		// ".smsw-side-panel .smsw-*" descendant selector. A <div> restores the
		// intended parent/child structure and the flex layout.
		?>
		<div class="smsw-side-panel">
			<button type="button" class="button smsw-side-panel-title" id="smsw-side-jump">
				<?php esc_html_e( 'Go to Schema Blocks', 'shied-world-schema-markup' ); ?>
			</button>
			<p class="smsw-side-panel-note">
				<span class="screen-reader-text"><?php esc_html_e( 'Note:', 'shied-world-schema-markup' ); ?></span>
				<?php
				// Double quotes because the message itself contains apostrophes
				// and quoted UI labels. Wording is fixed: it names two exact
				// WordPress UI strings users must match on screen.
				echo esc_html__(
					"If clicking 'Go to Schema Blocks' doesn't do anything, scroll down and open the 'Meta Boxes' section, then click on 'SHIED WORLD Schema Markup' inside it to expand it.",
					'shied-world-schema-markup'
				);
				?>
			</p>
			<span class="smsw-side-panel-count" id="smsw-side-panel-count">
				<span class="smsw-side-panel-count-label"><?php esc_html_e( 'Schema blocks:', 'shied-world-schema-markup' ); ?></span>
				<span class="smsw-side-panel-count-num"><?php echo esc_html( (string) $count ); ?></span>
			</span>
		</div>
		<?php
	}

	/**
	 * Render meta box markup.
	 *
	 * @param WP_Post $post Current post.
	 * @return void
	 */
	public static function render( $post ) {
		wp_nonce_field( 'smsw_save_schema_blocks', 'smsw_schema_blocks_nonce' );

		$blocks = get_post_meta( $post->ID, SMSW_META_KEY, true );
		if ( ! is_array( $blocks ) ) {
			$blocks = array();
		}

		$suggestion = SMSW_Suggestion_Engine::suggest( $post );
		$has_ai_key = SMSW_AI_Suggestion::has_key();
		?>
		<button type="button" class="smsw-modal-close"><?php esc_html_e( '× Close Schema Builder', 'shied-world-schema-markup' ); ?></button>
		<div class="smsw-metabox" id="smsw-metabox"
			data-blocks="<?php echo esc_attr( wp_json_encode( $blocks ) ); ?>"
			data-hidden-input="#smsw-schema-blocks-json"
			data-blocks-container="#smsw-blocks"
			data-add-button="#smsw-add-block"
			data-post-id="<?php echo esc_attr( (string) $post->ID ); ?>"
			data-preview-target="#smsw-json-preview"
		>
			<?php if ( $suggestion ) : ?>
				<div class="smsw-suggestion-panel" id="smsw-rule-suggestion" data-type="<?php echo esc_attr( $suggestion['type'] ); ?>">
					<strong><?php esc_html_e( 'Suggested Schema Type', 'shied-world-schema-markup' ); ?>:</strong>
					<span class="smsw-suggestion-type"><?php echo esc_html( $suggestion['type'] ); ?></span>
					<span class="smsw-suggestion-reason"><?php echo esc_html( $suggestion['reason'] ); ?></span>
					<p class="smsw-suggestion-actions">
						<button type="button" class="button smsw-btn-primary" id="smsw-use-suggestion">
							<?php esc_html_e( 'Use this suggestion', 'shied-world-schema-markup' ); ?>
						</button>
						<button type="button" class="button" id="smsw-dismiss-suggestion">
							<?php esc_html_e( 'Dismiss', 'shied-world-schema-markup' ); ?>
						</button>
					</p>
				</div>
			<?php endif; ?>

			<div class="smsw-ai-panel" id="smsw-ai-panel">
				<button type="button" class="button" id="smsw-ai-suggest" <?php disabled( ! $has_ai_key ); ?>>
					<?php esc_html_e( 'Get AI Suggestion', 'shied-world-schema-markup' ); ?>
				</button>
				<?php if ( ! $has_ai_key ) : ?>
					<span class="smsw-ai-hint"><?php esc_html_e( 'Add your own API key under Schema Markup > AI BYOK.', 'shied-world-schema-markup' ); ?></span>
				<?php endif; ?>
				<div id="smsw-ai-result" class="smsw-ai-result" hidden></div>
			</div>

			<p class="smsw-metabox-intro">
				<?php esc_html_e( 'Add schema blocks for this content. Each block outputs structured JSON-LD markup.', 'shied-world-schema-markup' ); ?>
			</p>

			<div class="smsw-blocks-wrap">
				<?php
				/*
				 * The id is required, not decorative: block-builder-v2.js resolves
				 * this container through the data-blocks-container selector
				 * ( "#smsw-blocks" ), and admin-bar.js looks the node up with
				 * getElementById( 'smsw-blocks' ) when the side panel scrolls to
				 * the builder. The Site Schema and Validation builders both
				 * render id + class for the same reason. Without the id both
				 * lookups return null and the Add button silently does nothing.
				 */
				?>
				<div id="smsw-blocks" class="smsw-blocks"></div>
			</div>

			<p class="smsw-metabox-actions">
				<button type="button" class="button smsw-btn-primary" id="smsw-add-block">
					<?php esc_html_e( 'Add Schema Block', 'shied-world-schema-markup' ); ?>
				</button>
			</p>
			<div class="smsw-validation-section">
				<h3><?php esc_html_e( 'Validate Your Schema', 'shied-world-schema-markup' ); ?></h3>
				<p><?php esc_html_e( 'Check this page\'s live schema, or copy the code to test manually.', 'shied-world-schema-markup' ); ?></p>
				<div class="smsw-validation-buttons">
					<button type="button" class="button smsw-btn-primary smsw-copy-test-btn" data-smsw-copy="google-url" data-smsw-preview="#smsw-json-preview" data-smsw-notice="#smsw-copy-notice">
						<?php esc_html_e( 'Test This Page\'s URL', 'shied-world-schema-markup' ); ?>
					</button>
					<button type="button" class="button smsw-btn-primary smsw-copy-test-btn" data-smsw-copy="schemaorg" data-smsw-preview="#smsw-json-preview" data-smsw-notice="#smsw-copy-notice">
						<?php esc_html_e( 'Copy and Validate with Schema.org', 'shied-world-schema-markup' ); ?>
					</button>
				</div>
			</div>
			<div class="smsw-preview-wrap">
				<label class="smsw-field-label" for="smsw-json-preview"><?php esc_html_e( 'Preview', 'shied-world-schema-markup' ); ?></label>
				<textarea id="smsw-json-preview" class="smsw-json-preview" rows="10" readonly></textarea>
			</div>
			<p class="smsw-copy-notice" id="smsw-copy-notice" hidden></p>

			<input type="hidden" name="smsw_schema_blocks_json" id="smsw-schema-blocks-json" value="" />
		</div>
		<?php
	}

	/**
	 * Save schema blocks from the editor.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object.
	 * @return void
	 */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- save_post callback signature.
	public static function save( $post_id, $post ) {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( wp_is_post_revision( $post_id ) ) {
			return;
		}

		if ( ! isset( $_POST['smsw_schema_blocks_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['smsw_schema_blocks_nonce'] ) ), 'smsw_save_schema_blocks' ) ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		if ( ! isset( $_POST['smsw_schema_blocks_json'] ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Raw JSON payload; sanitized block-by-block below.
			$raw = wp_unslash( $_POST['smsw_schema_blocks_json'] );
		if ( '' === trim( (string) $raw ) ) {
			delete_post_meta( $post_id, SMSW_META_KEY );
			return;
		}

		$decoded = json_decode( $raw, true );
		if ( null === $decoded && JSON_ERROR_NONE !== json_last_error() ) {
			self::set_error(
				__( 'SHIED WORLD Schema Markup could not save: invalid JSON payload from the editor.', 'shied-world-schema-markup' )
			);
			return;
		}

		if ( ! is_array( $decoded ) ) {
			delete_post_meta( $post_id, SMSW_META_KEY );
			return;
		}

		$sanitized = SMSW_Block_Sanitizer::sanitize_blocks( $decoded );
		if ( is_wp_error( $sanitized ) ) {
			self::set_error( $sanitized->get_error_message() );
			return;
		}

		update_post_meta( $post_id, SMSW_META_KEY, $sanitized );

		$warnings = SMSW_Schema_Validator::get_labeled_warnings( $sanitized );
		if ( ! empty( $warnings ) ) {
			self::set_warnings( $warnings );
		} else {
			self::clear_warnings();
		}
	}

	/**
	 * Store a transient notice for the current user.
	 *
	 * @param string $message Error message.
	 * @return void
	 */
	private static function set_error( $message ) {
		$user_id = get_current_user_id();
		set_transient( self::NOTICE_KEY . $user_id, $message, 60 );
	}

	/**
	 * Store non blocking warning messages for the current user.
	 *
	 * @param array<int,string> $warnings Warning messages.
	 * @return void
	 */
	private static function set_warnings( $warnings ) {
		$user_id = get_current_user_id();
		set_transient( self::WARNING_KEY . $user_id, array_values( $warnings ), 60 );
	}

	/**
	 * Clear stored warnings for the current user.
	 *
	 * @return void
	 */
	private static function clear_warnings() {
		$user_id = get_current_user_id();
		delete_transient( self::WARNING_KEY . $user_id );
	}
}
