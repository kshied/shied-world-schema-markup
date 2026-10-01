<?php
/**
 * Settings page with tabbed layout and option registration.
 *
 * @package Shied_World_Schema_Markup
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin settings UI and saves.
 */
class SMSW_Settings {

	/**
	 * Text entered in the key field to remove the stored key.
	 *
	 * @var string
	 */
	const CLEAR_KEY = '__clear__';

	/**
	 * Notices already added during this request.
	 *
	 * The sanitize callback can run twice for one save (update_option() and
	 * then add_option()), so notices are tracked to avoid duplicates.
	 *
	 * @var array<string,bool>
	 */
	private static $ai_notices = array();

	/**
	 * Init hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_filter( 'pre_update_option_' . SMSW_Options::AI_SETTINGS, array( __CLASS__, 'protect_ai_settings' ), 10, 2 );
	}

	/**
	 * Register settings with the Options API.
	 *
	 * @return void
	 */
	public static function register_settings() {
		register_setting(
			'smsw_site_schema_group',
			SMSW_Options::SITE_SCHEMA,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize_site_schema' ),
				'default'           => array(),
			)
		);

		register_setting(
			'smsw_post_types_group',
			SMSW_Options::POST_TYPES,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize_post_types' ),
				'default'           => array( 'post', 'page' ),
			)
		);

		register_setting(
			'smsw_ai_group',
			SMSW_Options::AI_SETTINGS,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize_ai_settings' ),
				'default'           => array(
					'provider' => 'openai',
					'keys'     => array(),
				),
			)
		);

		register_setting(
			'smsw_suggestions_group',
			SMSW_Options::SUGGESTIONS,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize_suggestions' ),
				'default'           => array( 'enabled' => 1 ),
			)
		);

		register_setting(
			'smsw_general_group',
			SMSW_Options::GENERAL,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize_general' ),
				'default'           => array(
					'output_enabled'      => 1,
					'delete_on_uninstall' => 0,
				),
			)
		);
	}

	/**
	 * Sanitize site schema from the builder's hidden JSON field.
	 *
	 * A failed read must never destroy blocks that are already saved. The
	 * post meta box already refuses to write when it cannot read the payload
	 * and reports the reason; this callback applies the same rule, because
	 * options.php would otherwise store an empty array and the Site Schema
	 * would silently stop outputting with no error shown on screen.
	 *
	 * @param mixed $value Incoming value.
	 * @return array<int,array<string,mixed>>
	 */
	public static function sanitize_site_schema( $value ) {
		if ( is_string( $value ) ) {
			// options.php already applies wp_unslash() to every posted option
			// value before this sanitize_option filter runs. Unslashing here
			// a second time would eat the backslash escapes inside the JSON
			// string, so a single saved value containing a quote or a
			// backslash would turn the whole payload into invalid JSON.
			$payload = trim( $value );

			// An empty field means the builder wrote nothing, not that the
			// owner wants the stored blocks deleted. Deleting every block
			// this way would fire whenever the builder JavaScript did not
			// run, which is exactly the silent failure this guards against.
			if ( '' === $payload ) {
				return SMSW_Options::get_site_schema();
			}

			$decoded = json_decode( $payload, true );
			if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $decoded ) ) {
				add_settings_error(
					'smsw_messages',
					'smsw_site_schema',
					__( 'SHIED WORLD Schema Markup could not save the Site Schema because the block data could not be read. Your previously saved blocks were left unchanged. Reload this page and try again.', 'shied-world-schema-markup' ),
					'error'
				);
				return SMSW_Options::get_site_schema();
			}

			$value = $decoded;
		}

		$result = SMSW_Block_Sanitizer::sanitize_blocks( $value );
		if ( is_wp_error( $result ) ) {
			add_settings_error(
				'smsw_messages',
				'smsw_site_schema',
				$result->get_error_message(),
				'error'
			);
			return SMSW_Options::get_site_schema();
		}

		return $result;
	}

	/**
	 * Sanitize enabled post types checklist.
	 *
	 * @param mixed $value Incoming.
	 * @return array<int,string>
	 */
	public static function sanitize_post_types( $value ) {
		if ( ! is_array( $value ) ) {
			return array();
		}
		$registered = get_post_types( array(), 'names' );
		$clean      = array();
		foreach ( $value as $type ) {
			$type = sanitize_key( $type );
			if ( isset( $registered[ $type ] ) && 'attachment' !== $type ) {
				$clean[] = $type;
			}
		}
		return array_values( array_unique( $clean ) );
	}

	/**
	 * Sanitize AI settings, encrypting a submitted key when one is present.
	 *
	 * The key is posted as part of this option payload
	 * (smsw_ai_settings[new_api_key]) so WordPress always passes it to this
	 * callback. Every provider keeps its own encrypted payload in the "keys"
	 * map, so a save only ever changes the entry for the selected provider and
	 * all other providers keep their own stored key untouched.
	 *
	 * Three cases are handled for the selected provider: a blank field removes
	 * that provider's key, __clear__ removes it too, and any other value is
	 * encrypted and replaces only that provider's key.
	 *
	 * @param mixed $value Incoming.
	 * @return array{provider:string,keys:array<string,string>}
	 */
	public static function sanitize_ai_settings( $value ) {
		$existing = SMSW_Options::get_ai_settings();
		$value    = is_array( $value ) ? $value : array();
		$provider = self::sanitize_ai_provider( $value, $existing['provider'] );

		// Base map for this save. The database already holds the keys of all
		// providers, and every provider starts from its own database value,
		// so a save can never drop another provider's payload. When a full
		// keys map is re-sanitized without a submitted key, submitted
		// encrypted payloads that still decrypt may replace the base entry.
		$repeat = isset( $value['keys'] ) && is_array( $value['keys'] ) && ! array_key_exists( 'new_api_key', $value );

		$keys = $existing['keys'];

		if ( isset( $value['keys'] ) && is_array( $value['keys'] ) ) {
			$submitted_keys = self::sanitize_key_map( $value['keys'] );

			if ( $repeat ) {
				foreach ( $submitted_keys as $submitted_provider => $payload ) {
					if ( '' !== SMSW_Crypto::decrypt( $payload ) ) {
						$keys[ $submitted_provider ] = $payload;
					}
				}
			}
		}

		// The saved keys travel with the form as unchanged masked previews in
		// smsw_ai_settings[api_keys]. The selected provider's entry is the
		// keep value, so even a provider switch arrives with the old
		// provider's payload present and nothing is ever dropped by accident.
		if ( isset( $value['api_keys'] ) && is_array( $value['api_keys'] ) ) {
			foreach ( self::sanitize_key_map( $value['api_keys'] ) as $echo_provider => $echo ) {
				if ( SMSW_AI_Suggestion::is_mask( $echo ) ) {
					$plain = SMSW_AI_Suggestion::get_key_for_provider( $echo_provider );

					if ( '' !== $plain && SMSW_AI_Suggestion::mask_key( $plain ) === $echo && ! isset( $keys[ $echo_provider ] ) ) {
						$keys[ $echo_provider ] = SMSW_Options::get_ai_key( $echo_provider );
					}
				}
			}
		}

		$submitted = null;

		if ( array_key_exists( 'new_api_key', $value ) ) {
			$submitted = self::clean_api_key( $value['new_api_key'] );
		}

		// The saved key is shown in the field as a masked preview. If that
		// preview is submitted unchanged it must never be treated as a new key.
		// Every provider is checked, so switching the dropdown and saving
		// cannot store one provider's mask as another provider's key. The
		// shape check also catches a mask left over from a key that can no
		// longer be decrypted on this site.
		// A blank submission means the user deliberately cleared the field, so
		// the stored key for the selected provider is removed by the branch
		// below. Turning a blank submit into null here would silently keep a
		// key the user asked to delete.
		if ( null !== $submitted ) {
			if ( SMSW_AI_Suggestion::is_mask( $submitted ) ) {
				$submitted = null;
			} else {
				foreach ( array_keys( SMSW_AI_Suggestion::get_providers() ) as $slug ) {
					$stored_plain = SMSW_AI_Suggestion::get_key_for_provider( $slug );

					if ( '' !== $stored_plain && SMSW_AI_Suggestion::mask_key( $stored_plain ) === $submitted ) {
						$submitted = null;
						break;
					}
				}
			}
		}

		if ( null !== $submitted ) {
			// Both an empty field and the __clear__ token remove the stored
			// key for the selected provider. A removal notice is only added
			// when a key actually existed, so saving an untouched empty form
			// stays quiet.
			if ( self::CLEAR_KEY === $submitted || '' === $submitted ) {
				$had_key = isset( $keys[ $provider ] ) && is_string( $keys[ $provider ] ) && '' !== $keys[ $provider ];
				unset( $keys[ $provider ] );

				if ( $had_key ) {
					self::add_ai_notice(
						'smsw_ai_cleared',
						sprintf(
							/* translators: %s: provider name. */
							__( 'The saved API key for %s was removed.', 'shied-world-schema-markup' ),
							self::provider_label( $provider )
						),
						'success'
					);
				}
			} else {
				$payload = SMSW_Crypto::encrypt( $submitted );
				if ( '' === $payload ) {
					self::add_ai_notice(
						'smsw_ai_encrypt',
						sprintf(
							/* translators: %s: provider name. */
							__( 'The new API key for %s could not be encrypted, so it was not saved. Check that the PHP OpenSSL extension is enabled and that wp-config.php defines the WordPress salts.', 'shied-world-schema-markup' ),
							self::provider_label( $provider )
						),
						'error'
					);
				} else {
					$keys[ $provider ] = $payload;
					self::add_ai_notice(
						'smsw_ai_saved',
						sprintf(
							/* translators: %s: provider name. */
							__( 'The API key for %s was encrypted and saved. The AI suggestion feature is ready to use it.', 'shied-world-schema-markup' ),
							self::provider_label( $provider )
						),
						'success'
					);
				}
			}
		}

		// A key that was added, replaced or removed invalidates the model list
		// saved under the previous one, so the next settings page load asks the
		// provider again instead of showing ids fetched with the old key.
		$was = isset( $existing['keys'][ $provider ] ) && is_string( $existing['keys'][ $provider ] ) ? $existing['keys'][ $provider ] : '';
		$now = isset( $keys[ $provider ] ) && is_string( $keys[ $provider ] ) ? $keys[ $provider ] : '';

		if ( $was !== $now ) {
			SMSW_AI_Suggestion::invalidate_model_cache( $provider );
		}

		// Only the selected provider's own payload is checked here, so a key
		// stored for another provider can never raise a false warning.
		$current = isset( $keys[ $provider ] ) ? $keys[ $provider ] : '';

		if ( is_array( $current ) ) {
			$current = '';
		}

		if ( '' !== $current && '' === SMSW_Crypto::decrypt( $current ) ) {
			self::add_ai_notice(
				'smsw_ai_unreadable',
				sprintf(
					/* translators: %s: provider name. */
					__( 'The saved API key for %s cannot be decoded on this site, usually because the WordPress salts in wp-config.php changed. Enter the key again to replace it.', 'shied-world-schema-markup' ),
					self::provider_label( $provider )
				),
				'error'
			);
		}

		// Model choices follow the same per-provider shape as the keys, and
		// start from what is already stored so saving a key can never clear
		// another provider's model selection.
		$models = $existing['models'];

		if ( isset( $value['models'] ) && is_array( $value['models'] ) ) {
			foreach ( $value['models'] as $model_provider => $model ) {
				$model_provider = sanitize_key( $model_provider );
				$model          = is_string( $model ) ? trim( $model ) : '';

				if ( '' === $model_provider || '' === $model ) {
					continue;
				}

				// Model ids travel through a select, so they are restricted to
				// the character set every provider actually uses: letters,
				// digits, dot, dash, slash and underscore.
				$model = preg_replace( '/[^A-Za-z0-9._\/-]/', '', $model );

				if ( is_string( $model ) && '' !== $model ) {
					$models[ $model_provider ] = $model;
				}
			}
		}

		return array(
			'provider'          => $provider,
			'keys'              => $keys,
			'models'            => $models,
			// Absent from the form means the checkbox was unticked, which is
			// the intended default, so an empty value is a deliberate "off".
			'send_full_content' => ! empty( $value['send_full_content'] ),
		);
	}

	/**
	 * Human readable label for a provider slug.
	 *
	 * @param string $provider Provider slug.
	 * @return string
	 */
	private static function provider_label( $provider ) {
		$providers = SMSW_AI_Suggestion::get_providers();

		return isset( $providers[ $provider ] ) ? $providers[ $provider ] : $provider;
	}

	/**
	 * Keep only usable encrypted payloads from a keys map.
	 *
	 * @param array<string,mixed> $keys Raw map.
	 * @return array<string,string>
	 */
	private static function sanitize_key_map( $keys ) {
		$clean = array();

		foreach ( $keys as $provider => $payload ) {
			$provider = sanitize_key( $provider );

			if ( '' !== $provider && is_string( $payload ) && '' !== $payload ) {
				$clean[ $provider ] = $payload;
			}
		}

		return $clean;
	}

	/**
	 * Safety net that keeps a plaintext key out of the database.
	 *
	 * The sanitize callback above consumes smsw_ai_settings[new_api_key]. This
	 * filter runs for every write of the option, so a plaintext key can never
	 * be stored even if the option is written through a path that skips that
	 * callback.
	 *
	 * @param mixed $value     New option value.
	 * @param mixed $old_value Stored option value.
	 * @return mixed
	 */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- Filter callback signature.
	public static function protect_ai_settings( $value, $old_value = null ) {
		if ( ! is_array( $value ) || ! array_key_exists( 'new_api_key', $value ) ) {
			return $value;
		}

		return self::sanitize_ai_settings( $value );
	}

	/**
	 * Add a settings notice once per request.
	 *
	 * @param string $code    Notice code.
	 * @param string $message Notice text.
	 * @param string $type    'error' or 'success'.
	 * @return void
	 */
	private static function add_ai_notice( $code, $message, $type ) {
		if ( isset( self::$ai_notices[ $code ] ) ) {
			return;
		}

		self::$ai_notices[ $code ] = true;

		add_settings_error( 'smsw_messages', $code, $message, $type );
	}

	/**
	 * Validate a submitted provider slug against the supported list.
	 *
	 * @param array<string,mixed> $value    Posted option value.
	 * @param string              $fallback Currently stored provider.
	 * @return string
	 */
	private static function sanitize_ai_provider( $value, $fallback ) {
		$providers = SMSW_AI_Suggestion::get_providers();

		if ( isset( $value['provider'] ) && is_string( $value['provider'] ) ) {
			$provider = sanitize_key( $value['provider'] );

			if ( isset( $providers[ $provider ] ) ) {
				return $provider;
			}
		}

		if ( isset( $providers[ $fallback ] ) ) {
			return $fallback;
		}

		return 'openai';
	}

	/**
	 * Clean a submitted API key.
	 *
	 * @param mixed $key Raw submitted value.
	 * @return string
	 */
	private static function clean_api_key( $key ) {
		if ( ! is_string( $key ) ) {
			return '';
		}

		$key = trim( sanitize_text_field( $key ) );

		// Strip quotes that are sometimes pasted along with the key.
		if ( strlen( $key ) > 1 ) {
			$first = substr( $key, 0, 1 );
			$last  = substr( $key, -1 );

			if ( ( '"' === $first && '"' === $last ) || ( "'" === $first && "'" === $last ) ) {
				$key = trim( substr( $key, 1, -1 ) );
			}
		}

		return $key;
	}

	/**
	 * Sanitize suggestion settings.
	 *
	 * @param mixed $value Incoming.
	 * @return array{enabled:int}
	 */
	public static function sanitize_suggestions( $value ) {
		return array(
			'enabled' => ! empty( $value['enabled'] ) ? 1 : 0,
		);
	}

	/**
	 * Sanitize general settings.
	 *
	 * @param mixed $value Incoming.
	 * @return array{output_enabled:int,delete_on_uninstall:int}
	 */
	public static function sanitize_general( $value ) {
		return array(
			'output_enabled'      => ! empty( $value['output_enabled'] ) ? 1 : 0,
			'delete_on_uninstall' => ! empty( $value['delete_on_uninstall'] ) ? 1 : 0,
		);
	}

	/**
	 * Allowed settings tabs in exact order.
	 *
	 * @return array<string,string>
	 */
	public static function get_tabs() {
		return array(
			'site-schema'        => __( 'Site Schema', 'shied-world-schema-markup' ),
			'schema-library'     => __( 'Schema Library', 'shied-world-schema-markup' ),
			'post-type-settings' => __( 'Post Type Settings', 'shied-world-schema-markup' ),
			'suggestion-engine'  => __( 'Suggestion Engine', 'shied-world-schema-markup' ),
			'ai-byok'            => __( 'AI BYOK', 'shied-world-schema-markup' ),
			'validation'         => __( 'Validation', 'shied-world-schema-markup' ),
			'general'            => __( 'General', 'shied-world-schema-markup' ),
			'seo-services'       => __( 'SEO Services', 'shied-world-schema-markup' ),
			'about'              => __( 'About', 'shied-world-schema-markup' ),
		);
	}

	/**
	 * Render the full settings page.
	 *
	 * @return void
	 */
	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$tabs    = self::get_tabs();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only tab switch.
		$current = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'site-schema';
		if ( ! isset( $tabs[ $current ] ) ) {
			$current = 'site-schema';
		}
		// The AI BYOK tab prints its save result once, directly below the Save
		// button, so the same message is not repeated at the top of the page.
		if ( 'ai-byok' !== $current ) {
			settings_errors( 'smsw_messages' );
		}
		?>
		<div class="wrap smsw-wrap">
		<div class="smsw-settings-header">
			<img class="smsw-settings-logo" src="<?php echo esc_url( SMSW_Admin_Menu::get_logo_url() ); ?>" alt="" width="80" height="80" style="display:block;width:80px;height:80px;object-fit:contain;flex-shrink:0;" />

			<div class="smsw-settings-heading">
					<h1><?php esc_html_e( 'SHIED WORLD Schema Markup', 'shied-world-schema-markup' ); ?></h1>
					<p><?php esc_html_e( 'Universal JSON-LD schema markup for your WordPress site.', 'shied-world-schema-markup' ); ?></p>
				</div>

			<?php self::render_seo_header_widget(); ?>
			</div>

			<nav class="nav-tab-wrapper smsw-nav-tabs">
				<?php foreach ( $tabs as $slug => $label ) : ?>
					<a
						href="<?php echo esc_url( admin_url( 'admin.php?page=' . SMSW_Admin_Menu::MENU_SLUG . '&tab=' . $slug ) ); ?>"
						class="nav-tab <?php echo esc_attr( $current === $slug ? 'nav-tab-active' : '' ); ?>"
					><?php echo esc_html( $label ); ?></a>
				<?php endforeach; ?>
			</nav>

			<div class="smsw-tab-content">
				<?php
				switch ( $current ) {
					case 'schema-library':
						self::render_schema_library();
						break;
					case 'post-type-settings':
						self::render_post_type_settings();
						break;
					case 'suggestion-engine':
						self::render_suggestion_engine();
						break;
					case 'ai-byok':
						self::render_ai_byok();
						break;
					case 'validation':
						self::render_validation();
						break;
					case 'general':
						self::render_general();
						break;
					case 'seo-services':
						self::render_seo_services();
						break;

					case 'about':
						self::render_about();
						break;
					case 'site-schema':
					default:
						self::render_site_schema();
						break;
				}
				?>
			</div>

			<p class="smsw-footer-credit">
				<a href="https://shiedworld.com/shied-world-schema-markup-wordpress-plugin/" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'SHIED WORLD Schema Markup', 'shied-world-schema-markup' ); ?></a>
			</p>
			<?php self::render_social_links( 16 ); ?>
		</div>
		<?php
	}

	/**
	 * Site Schema tab.
	 *
	 * @return void
	 */
	private static function render_site_schema() {
		$blocks = SMSW_Options::get_site_schema();
		?>
		<form method="post" action="options.php" id="smsw-site-schema-form">
			<?php settings_fields( 'smsw_site_schema_group' ); ?>
			<div class="smsw-panel smsw-notice-panel">
				<h2><?php esc_html_e( 'Site Schema', 'shied-world-schema-markup' ); ?></h2>
				<p>
					<strong><?php esc_html_e( 'This schema outputs on every page of the site.', 'shied-world-schema-markup' ); ?></strong>
					<?php esc_html_e( 'Use it for Organization, WebSite, LocalBusiness, and other global entities. It merges with page-level blocks into a single @graph output.', 'shied-world-schema-markup' ); ?>
				</p>
			</div>

			<div
				class="smsw-metabox smsw-settings-builder"
				id="smsw-site-schema-builder"
				data-blocks="<?php echo esc_attr( wp_json_encode( $blocks ) ); ?>"
				data-hidden-input="#<?php echo esc_attr( SMSW_Options::SITE_SCHEMA ); ?>"
				data-blocks-container="#smsw-site-blocks"
				data-add-button="#smsw-site-add-block"
			>
				<div id="smsw-site-blocks" class="smsw-blocks"></div>
				<p class="smsw-metabox-actions">
					<button type="button" class="button smsw-btn-primary" id="smsw-site-add-block">
						<?php esc_html_e( 'Add Schema Block', 'shied-world-schema-markup' ); ?>
					</button>
				<input type="hidden" name="<?php echo esc_attr( SMSW_Options::SITE_SCHEMA ); ?>" id="<?php echo esc_attr( SMSW_Options::SITE_SCHEMA ); ?>" value="" />
			</div>

			<?php submit_button( __( 'Save Site Schema', 'shied-world-schema-markup' ) ); ?>
		</form>
		<?php
	}

	/**
	 * Schema Library tab.
	 *
	 * @return void
	 */
	private static function render_schema_library() {
		$types = SMSW_Schema_Types::get_types();
		// Resolved one type at a time. Building every schema.org type at once
		// was what exhausted the PHP memory limit on this page.
		$defs = array();
		foreach ( $types as $key => $label ) {
			if ( SMSW_Schema_Types::CUSTOM_TYPE === $key ) {
				continue;
			}
			$type_defs = SMSW_Schema_Types::get_properties( $key );
			if ( ! empty( $type_defs ) ) {
				$defs[ $key ] = $type_defs;
			}
		}
		?>
		<div class="smsw-panel">
			<h2><?php esc_html_e( 'Schema Library', 'shied-world-schema-markup' ); ?></h2>
			<p><?php esc_html_e( 'Built-in schema.org types and common properties available in the block builder.', 'shied-world-schema-markup' ); ?></p>
		</div>
		<?php foreach ( $types as $key => $label ) : ?>
			<?php if ( SMSW_Schema_Types::CUSTOM_TYPE === $key ) : ?>
				<div class="smsw-panel">
					<h3><?php echo esc_html( $label ); ?></h3>
					<p><?php esc_html_e( 'Enter any @type and paste raw JSON-LD for full control.', 'shied-world-schema-markup' ); ?></p>
				</div>
				<?php continue; ?>
			<?php endif; ?>
			<div class="smsw-panel">
				<h3><?php echo esc_html( $label ); ?></h3>
				<?php if ( ! empty( $defs[ $key ] ) ) : ?>
					<ul class="smsw-library-props">
						<?php foreach ( $defs[ $key ] as $prop ) : ?>
							<li><code><?php echo esc_html( $prop['label'] ); ?></code></li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>
			</div>
		<?php endforeach; ?>
		<?php
	}

	/**
	 * Post Type Settings tab.
	 *
	 * @return void
	 */
	private static function render_post_type_settings() {
		$enabled    = SMSW_Options::get_enabled_post_types();
		$post_types = get_post_types( array(), 'objects' );
		?>
		<form method="post" action="options.php">
			<?php settings_fields( 'smsw_post_types_group' ); ?>
			<div class="smsw-panel">
				<h2><?php esc_html_e( 'Post Type Settings', 'shied-world-schema-markup' ); ?></h2>
				<p><?php esc_html_e( 'Choose which post types show the SHIED WORLD Schema Markup meta box. Defaults: Post and Page.', 'shied-world-schema-markup' ); ?></p>
				<ul class="smsw-checklist">
					<?php foreach ( $post_types as $slug => $obj ) : ?>
						<?php
						if ( 'attachment' === $slug ) {
							continue;
						}
						?>
						<li>
							<label>
								<input
									type="checkbox"
									name="<?php echo esc_attr( SMSW_Options::POST_TYPES ); ?>[]"
									value="<?php echo esc_attr( $slug ); ?>"
									<?php checked( in_array( $slug, $enabled, true ) ); ?>
								/>
								<?php echo esc_html( $obj->labels->singular_name . ' (' . $slug . ')' ); ?>
							</label>
						</li>
					<?php endforeach; ?>
				</ul>
			</div>
			<?php submit_button( __( 'Save Post Type Settings', 'shied-world-schema-markup' ) ); ?>
		</form>
		<?php
	}

	/**
	 * Suggestion Engine tab.
	 *
	 * @return void
	 */
	private static function render_suggestion_engine() {
		$settings = SMSW_Options::get_suggestion_settings();
		?>
		<form method="post" action="options.php">
			<?php settings_fields( 'smsw_suggestions_group' ); ?>
			<div class="smsw-panel">
				<h2><?php esc_html_e( 'Suggestion Engine', 'shied-world-schema-markup' ); ?></h2>
				<p><?php esc_html_e( 'Local rule-based suggestions on the post edit screen. No external API calls.', 'shied-world-schema-markup' ); ?></p>
				<label>
					<input
						type="checkbox"
						name="<?php echo esc_attr( SMSW_Options::SUGGESTIONS ); ?>[enabled]"
						value="1"
						<?php checked( ! empty( $settings['enabled'] ) ); ?>
					/>
					<?php esc_html_e( 'Enable rule-based suggestions', 'shied-world-schema-markup' ); ?>
				</label>
				<ol class="smsw-rules-list">
					<li><?php esc_html_e( 'WooCommerce product -> Product', 'shied-world-schema-markup' ); ?></li>
					<li><?php esc_html_e( 'Post category/tag contains recipe -> Recipe', 'shied-world-schema-markup' ); ?></li>
					<li><?php esc_html_e( 'Question-style headings -> FAQPage', 'shied-world-schema-markup' ); ?></li>
					<li><?php esc_html_e( 'Page about -> AboutPage', 'shied-world-schema-markup' ); ?></li>
					<li><?php esc_html_e( 'Page contact -> ContactPage', 'shied-world-schema-markup' ); ?></li>
					<li><?php esc_html_e( 'Post with author and date -> Article', 'shied-world-schema-markup' ); ?></li>
					<li><?php esc_html_e( 'Ordered list with 3+ steps -> HowTo', 'shied-world-schema-markup' ); ?></li>
					<li><?php esc_html_e( 'Post type contains portfolio/project -> CreativeWork', 'shied-world-schema-markup' ); ?></li>
				</ol>
			</div>
			<?php submit_button( __( 'Save Suggestion Settings', 'shied-world-schema-markup' ) ); ?>
		</form>
		<?php
	}

	/**
	 * AI BYOK tab.
	 *
	 * All status text on this tab is consolidated into one helper line under
	 * the key field plus one save notice below the Save button. The key field
	 * itself always carries a masked preview of the key stored for the
	 * selected provider, so a saved key stays visible as a normal password
	 * style value instead of an empty box.
	 *
	 * @return void
	 */
	private static function render_ai_byok() {
		$settings  = SMSW_Options::get_ai_settings();
		$providers = SMSW_AI_Suggestion::get_providers();
		$provider  = $settings['provider'];

		// Show a preview only for a non-empty key saved for this exact provider.
		$previews = array();
		foreach ( $providers as $slug => $label ) {
			$previews[ $slug ] = '';
			$payload           = isset( $settings['keys'][ $slug ] ) ? $settings['keys'][ $slug ] : '';
			if ( ! is_string( $payload ) || '' === $payload ) {
				continue;
			}

			$plaintext = SMSW_Crypto::decrypt( $payload );
			if ( ! is_string( $plaintext ) || '' === trim( $plaintext ) || SMSW_AI_Suggestion::is_mask( $plaintext ) ) {
				continue;
			}

			$previews[ $slug ] = SMSW_AI_Suggestion::mask_key( $plaintext );
		}
		$preview = isset( $previews[ $provider ] ) ? $previews[ $provider ] : '';

		// Model lists are resolved server side and handed to the picker as JSON,
		// so switching the provider dropdown swaps the options in place
		// without a page reload. get_models() returns the cached live list, or
		// the documented fallback when the fetch is not possible.
		$model_lists = array();
		foreach ( $providers as $slug => $unused_label ) {
			$model_lists[ $slug ] = SMSW_AI_Suggestion::get_models( $slug );
		}

		$model_notes    = SMSW_AI_Suggestion::get_model_config_note();

		// One line per provider saying whether the list under it was read from
		// that provider or is the built-in fallback, and why. Without this a
		// short fallback list and a full live list look identical on screen.
		$model_states = array();
		foreach ( $providers as $slug => $state_label ) {
			$model_states[ $slug ] = SMSW_AI_Suggestion::describe_model_state( $slug, $state_label );
		}
		$full_content   = ! empty( $settings['send_full_content'] );
		$has_key        = SMSW_AI_Suggestion::has_key( $provider );

		?>

		<form method="post" action="options.php">
				<?php settings_fields( 'smsw_ai_group' ); ?>
				<p><?php esc_html_e( 'Bring Your Own Key. Paste a key for OpenAI, Anthropic, Gemini, DeepSeek, Groq, or OpenRouter. Keys are encrypted with openssl_encrypt using salt material from wp-config.php and are never stored in plain text. Each provider keeps its own separate key, and there is no shared fallback key.', 'shied-world-schema-markup' ); ?></p>

				<div class="smsw-field">
					<label for="smsw_ai_provider"><?php esc_html_e( 'Provider', 'shied-world-schema-markup' ); ?></label>
					<select name="<?php echo esc_attr( SMSW_Options::AI_SETTINGS ); ?>[provider]" id="smsw_ai_provider" class="widefat">
						<?php foreach ( $providers as $slug => $label ) : ?>
							<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $settings['provider'], $slug ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</div>

				<div class="smsw-field">
					<label for="smsw_ai_api_key"><?php esc_html_e( 'API key', 'shied-world-schema-markup' ); ?></label>
					<div
						class="smsw-key-wrap"
						id="smsw-ai-key-wrap"
						data-smsw-provider-field="#smsw_ai_provider"
					data-smsw-previews="<?php echo esc_attr( wp_json_encode( $previews ) ); ?>"
						data-smsw-preview-placeholder="<?php esc_attr_e( 'Paste your API key', 'shied-world-schema-markup' ); ?>"
						data-smsw-hidden-name="<?php echo esc_attr( SMSW_Options::AI_SETTINGS . '[api_keys]' ); ?>"
					>
						<input
							type="password"
							name="<?php echo esc_attr( SMSW_Options::AI_SETTINGS ); ?>[new_api_key]"
							id="smsw_ai_api_key"
							class="widefat smsw-key-input"
							value="<?php echo esc_attr( $preview ); ?>"
							autocomplete="off"
							spellcheck="false"
							placeholder="<?php echo esc_attr( '' === $preview ? __( 'Paste your API key', 'shied-world-schema-markup' ) : '' ); ?>"
						/>
						<button
							type="button"
							class="smsw-key-eye"
							id="smsw-ai-key-eye"
							data-smsw-eye-target="#smsw_ai_api_key"
							aria-pressed="false"
							aria-label="<?php esc_attr_e( 'Show API key', 'shied-world-schema-markup' ); ?>"
							data-label-show="<?php esc_attr_e( 'Show API key', 'shied-world-schema-markup' ); ?>"
							data-label-hide="<?php esc_attr_e( 'Hide API key', 'shied-world-schema-markup' ); ?>"
						>
							<svg class="smsw-eye-icon smsw-eye-show" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7Z" /><circle cx="12" cy="12" r="3" /></svg>
							<svg class="smsw-eye-icon smsw-eye-hide" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false" hidden><path d="M9.88 9.88a3 3 0 1 0 4.24 4.24" /><path d="M10.73 5.08A10.43 10.43 0 0 1 12 5c7 0 10 7 10 7a13.16 13.16 0 0 1-1.67 2.68" /><path d="M6.61 6.61A13.526 13.526 0 0 0 2 12s3 7 10 7a9.74 9.74 0 0 0 5.39-1.61" /><line x1="2" y1="2" x2="22" y2="22" /></svg>
						</button>
					</div>
					<p class="description">
						<?php esc_html_e( 'Paste your API key and click Save AI Settings. Saved keys appear as masked previews for the selected provider; providers without a saved key have an empty field. Enter a new key to replace the saved one, or empty the field and save to delete the stored key.', 'shied-world-schema-markup' ); ?>
					</p>
				</div>

				<div
					class="smsw-field smsw-ai-model-field"
					id="smsw-ai-model-field"
					data-smsw-model-lists="<?php echo esc_attr( wp_json_encode( $model_lists ) ); ?>"
					data-smsw-model-selected="<?php echo esc_attr( wp_json_encode( $settings['models'] ) ); ?>"
					data-smsw-model-ajax="<?php echo esc_attr( admin_url( 'admin-ajax.php' ) ); ?>"
					data-smsw-model-nonce="<?php echo esc_attr( wp_create_nonce( SMSW_AI_Suggestion::MODELS_NONCE_ACTION ) ); ?>"
					data-smsw-provider-field="#smsw_ai_provider"
				>
					<label for="smsw_ai_model_<?php echo esc_attr( $provider ); ?>"><?php esc_html_e( 'Model', 'shied-world-schema-markup' ); ?></label>
					<?php
					// One select per provider. The unselected ones stay hidden and
					// act as templates: the script shows the right one and hides
					// the rest, so a provider switch can never submit another
					// provider's model under the active provider's key.
					foreach ( $providers as $slug => $label ) :
						$list   = isset( $model_lists[ $slug ] ) ? $model_lists[ $slug ] : array();
						$chosen = isset( $settings['models'][ $slug ] ) ? $settings['models'][ $slug ] : '';

						// The first entry is the provider's cheapest documented
						// model, so an unconfigured provider still sends something
						// sensible.
						if ( '' === $chosen && ! empty( $list ) ) {
							$chosen = (string) $list[0];
						}
						?>
						<select
							name="<?php echo esc_attr( SMSW_Options::AI_SETTINGS . '[models][' . $slug . ']' ); ?>"
							id="smsw_ai_model_<?php echo esc_attr( $slug ); ?>"
							class="widefat smsw-ai-model-select"
							data-smsw-model-provider="<?php echo esc_attr( $slug ); ?>"
							size="1"
							<?php echo $slug === $provider ? '' : 'hidden'; ?>
						>
							<?php if ( empty( $list ) ) : ?>
								<option value=""><?php esc_html_e( 'No models available. Check your API key and quota.', 'shied-world-schema-markup' ); ?></option>
							<?php else : ?>
								<?php foreach ( $list as $model_id ) : ?>
									<option value="<?php echo esc_attr( $model_id ); ?>" <?php selected( $chosen, $model_id ); ?>><?php echo esc_html( $model_id ); ?></option>
								<?php endforeach; ?>
							<?php endif; ?>
						</select>
						<p
							class="description smsw-ai-model-source"
							data-smsw-model-source="<?php echo esc_attr( $slug ); ?>"
							<?php echo $slug === $provider ? '' : 'hidden'; ?>
						><?php echo esc_html( isset( $model_states[ $slug ] ) ? $model_states[ $slug ] : '' ); ?></p>
						<?php
					endforeach;
					?>
					<p class="description smsw-ai-model-note"><?php echo esc_html( isset( $model_notes[ $provider ] ) ? $model_notes[ $provider ] : '' ); ?></p>
				</div>

				<div class="smsw-field smsw-ai-full-content-field">
					<label for="smsw_ai_full_content">
						<input
							type="checkbox"
							name="<?php echo esc_attr( SMSW_Options::AI_SETTINGS . '[send_full_content]' ); ?>"
							id="smsw_ai_full_content"
							value="1"
							<?php checked( $full_content ); ?>
						/>
						<?php esc_html_e( 'Send full post content to AI suggestions', 'shied-world-schema-markup' ); ?>
					</label>
					<p class="description smsw-ai-full-content-warning">
						<?php esc_html_e( 'Off by default: the plugin sends the post title, all headings, a short excerpt and the URL. That is enough to pick a schema type and costs far fewer tokens. Turn this on to send the post body as well. Uses significantly more tokens and may exceed free-tier API limits faster.', 'shied-world-schema-markup' ); ?>
					</p>
				</div>
				<p class="smsw-ai-save-row">
					<?php submit_button( __( 'Save AI Settings', 'shied-world-schema-markup' ), 'primary', 'submit', false ); ?>
				</p>
				<?php self::render_ai_save_notice(); ?>
			</form>
		<?php
	}

	/**
	 * Single save notice shown next to the Save button on the AI BYOK tab.
	 *
	 * Reuses the messages WordPress already collected for this save, so the
	 * wording stays in one place and the markup matches the green success and
	 * red error notice pattern used elsewhere in this plugin. Errors are read
	 * without clearing them, so the notice at the top of the page still shows.
	 *
	 * @return array<int,array{code:string,message:string,type:string}>
	 */
	private static function ai_save_notices() {
		$notices = array();

		foreach ( get_settings_errors() as $error ) {
			if ( ! isset( $error['code'], $error['message'], $error['type'] ) ) {
				continue;
			}

			if ( isset( $notices[ $error['code'] ] ) ) {
				continue;
			}

			$type = ( 'updated' === $error['type'] ) ? 'success' : $error['type'];

			if ( ! in_array( $type, array( 'success', 'error', 'warning', 'info' ), true ) ) {
				continue;
			}

			$notices[ $error['code'] ] = array(
				'code'    => (string) $error['code'],
				'message' => (string) $error['message'],
				'type'    => $type,
			);
		}

		return array_values( $notices );
	}

	/**
	 * Render the save notice, if any, directly below the Save button.
	 *
	 * @return void
	 */
	private static function render_ai_save_notice() {
		foreach ( self::ai_save_notices() as $notice ) {
			?>
			<div class="notice notice-<?php echo esc_attr( $notice['type'] ); ?> is-dismissible smsw-ai-save-notice">
				<p><?php echo wp_kses_post( $notice['message'] ); ?></p>
			</div>
			<?php
		}
	}

	/**
	 * Validation tab.
	 *
	 * @return void
	 */
	private static function render_validation() {
		?>
		<div class="smsw-panel">
			<h2><?php esc_html_e( 'Validation', 'shied-world-schema-markup' ); ?></h2>
			<p><?php esc_html_e( 'Copy your current site schema below, then paste it into either tester. Rich Results checks Google supported types. Schema.org checks the full vocabulary.', 'shied-world-schema-markup' ); ?></p>
			<div
				class="smsw-metabox smsw-settings-builder"
				id="smsw-validation-builder"
				data-blocks="<?php echo esc_attr( wp_json_encode( SMSW_Options::get_site_schema() ) ); ?>"
				data-hidden-input="#smsw-validation-hidden"
				data-blocks-container="#smsw-validation-blocks"
				data-add-button="#smsw-validation-add"
				data-post-id="0"
				data-preview-target="#smsw-validation-preview"
				data-notice-target="#smsw-validation-copy-notice"
			>
				<div id="smsw-validation-blocks" class="smsw-blocks" hidden></div>
				<p class="smsw-metabox-actions">
					<button type="button" class="button smsw-btn-primary smsw-copy-test-btn" data-smsw-copy="google" data-smsw-preview="#smsw-validation-preview" data-smsw-notice="#smsw-validation-copy-notice" disabled>
						<?php esc_html_e( 'Copy and Test with Google Rich Results', 'shied-world-schema-markup' ); ?>
					</button>
					<button type="button" class="button smsw-btn-primary smsw-copy-test-btn" data-smsw-copy="schemaorg" data-smsw-preview="#smsw-validation-preview" data-smsw-notice="#smsw-validation-copy-notice" disabled>
						<?php esc_html_e( 'Copy and Validate with Schema.org', 'shied-world-schema-markup' ); ?>
					</button>
					<button type="button" class="button" id="smsw-validation-refresh">
						<?php esc_html_e( 'Refresh JSON Preview', 'shied-world-schema-markup' ); ?>
					</button>
					<button type="button" class="button smsw-btn-primary" id="smsw-validation-add" hidden>
						<?php esc_html_e( 'Add Schema Block', 'shied-world-schema-markup' ); ?>
					</button>
				</p>
				<p class="smsw-copy-notice" id="smsw-validation-copy-notice" hidden></p>
				<div class="smsw-preview-panel">
					<h4><?php esc_html_e( 'Site JSON-LD Preview (merged @graph)', 'shied-world-schema-markup' ); ?></h4>
					<textarea id="smsw-validation-preview" class="smsw-json-preview" rows="10" readonly><?php esc_html_e( 'Click Refresh JSON Preview to see the final merged output.', 'shied-world-schema-markup' ); ?></textarea>
				</div>
				<input type="hidden" id="smsw-validation-hidden" value="" />
			</div>
			<p class="description"><?php esc_html_e( 'Preview shows saved site schema merged into one @graph. From a post, use the same copy buttons in the meta box to test that post.', 'shied-world-schema-markup' ); ?></p>
		</div>
		<?php
	}

	/**
	 * General tab.
	 *
	 * @return void
	 */
	private static function render_general() {
		$settings = SMSW_Options::get_general_settings();
		?>
		<form method="post" action="options.php">
			<?php settings_fields( 'smsw_general_group' ); ?>
			<div class="smsw-panel">
				<h2><?php esc_html_e( 'General', 'shied-world-schema-markup' ); ?></h2>
				<label>
					<input
						type="checkbox"
						name="<?php echo esc_attr( SMSW_Options::GENERAL ); ?>[output_enabled]"
						value="1"
						<?php checked( ! empty( $settings['output_enabled'] ) ); ?>
					/>
					<?php esc_html_e( 'Enable frontend JSON-LD output', 'shied-world-schema-markup' ); ?>
				</label>
				<p>
				<label>
					<input
						type="checkbox"
						name="<?php echo esc_attr( SMSW_Options::GENERAL ); ?>[delete_on_uninstall]"
						value="1"
						<?php checked( ! empty( $settings['delete_on_uninstall'] ) ); ?>
					/>
					<?php esc_html_e( 'Delete all plugin data on uninstall', 'shied-world-schema-markup' ); ?>
				</label>
				</p>
				<p><?php esc_html_e( 'Plugin version:', 'shied-world-schema-markup' ); ?> <code><?php echo esc_html( SMSW_VERSION ); ?></code></p>
				<p><?php esc_html_e( 'Supported placeholders:', 'shied-world-schema-markup' ); ?></p>
				<ul class="smsw-placeholder-list">
					<?php foreach ( SMSW_Placeholders::get_supported() as $token ) : ?>
						<li><code>{{<?php echo esc_html( $token ); ?>}}</code></li>
					<?php endforeach; ?>
				</ul>
			</div>
			<?php submit_button( __( 'Save General Settings', 'shied-world-schema-markup' ) ); ?>
		</form>
		<?php
	}


	/**
	 * SEO service links and the icons that represent them.
	 *
	 * This is the single source of truth for every SEO service link in the
	 * plugin. The header widget and the SEO Services tab both read from here,
	 * so a link only ever has to be changed in this one method.
	 *
	 * The icon paths are the official brand glyphs from Simple Icons, which
	 * are released into the public domain under CC0 1.0.
	 *
	 * The platform icons shown in the header widget are listed explicitly by
	 * render_seo_header_widget(), so the website entry can never appear in
	 * the header even though it is held here.
	 *
	 * @return array<string,array<string,string>> Link and icon definitions.
	 */
	public static function get_seo_service_links() {
		return array(
			'fiverr'   => array(
				'url'     => 'https://www.fiverr.com/kazishied/',
				'name'    => __( 'Fiverr', 'shied-world-schema-markup' ),
				'cta'     => __( 'Hire on Fiverr', 'shied-world-schema-markup' ),
				'icon'    => __( 'Hire SEO services on Fiverr', 'shied-world-schema-markup' ),
				'viewbox' => '0 0 24 24',
				'path'    => 'M23.004 15.588a.995.995 0 1 0 .002-1.99.995.995 0 0 0-.002 1.99zm-.996-3.705h-.85c-.546 0-.84.41-.84 1.092v2.466h-1.61v-3.558h-.684c-.547 0-.84.41-.84 1.092v2.466h-1.61v-4.874h1.61v.74c.264-.574.626-.74 1.163-.74h1.972v.74c.264-.574.625-.74 1.162-.74h.527v1.316zm-6.786 1.501h-3.359c.088.546.43.858 1.006.858.43 0 .732-.175.83-.487l1.425.4c-.351.848-1.22 1.364-2.255 1.364-1.748 0-2.549-1.355-2.549-2.515 0-1.14.703-2.505 2.45-2.505 1.856 0 2.471 1.384 2.471 2.408 0 .224-.01.37-.02.477zm-1.562-.945c-.04-.42-.342-.81-.889-.81-.508 0-.81.225-.908.81h1.797zM7.508 15.44h1.416l1.767-4.874h-1.62l-.86 2.837-.878-2.837H5.72l1.787 4.874zm-6.6 0H2.51v-3.558h1.524v3.558h1.591v-4.874H2.51v-.302c0-.332.235-.536.606-.536h.918V8.412H2.85c-1.162 0-1.943.712-1.943 1.755v.4H0v1.316h.908v3.558z',
			),
			'upwork'   => array(
				'url'     => 'https://www.upwork.com/freelancers/~014736ec50a74cfdcb?mp_source=share',
				'name'    => __( 'Upwork', 'shied-world-schema-markup' ),
				'cta'     => __( 'Hire on Upwork', 'shied-world-schema-markup' ),
				'icon'    => __( 'Hire SEO services on Upwork', 'shied-world-schema-markup' ),
				'viewbox' => '0 0 24 24',
				'path'    => 'M18.561 13.158c-1.102 0-2.135-.467-3.074-1.227l.228-1.076.008-.042c.207-1.143.849-3.06 2.839-3.06 1.492 0 2.703 1.212 2.703 2.703-.001 1.489-1.212 2.702-2.704 2.702zm0-8.14c-2.539 0-4.51 1.649-5.31 4.366-1.22-1.834-2.148-4.036-2.687-5.892H7.828v7.112c-.002 1.406-1.141 2.546-2.547 2.548-1.405-.002-2.543-1.143-2.545-2.548V3.492H0v7.112c0 2.914 2.37 5.303 5.281 5.303 2.913 0 5.283-2.389 5.283-5.303v-1.19c.529 1.107 1.182 2.229 1.974 3.221l-1.673 7.873h2.797l1.213-5.71c1.063.679 2.285 1.109 3.686 1.109 3 0 5.439-2.452 5.439-5.45 0-3-2.439-5.439-5.439-5.439z',
			),
			'whatsapp' => array(
				'url'     => 'https://wa.me/8801568381878',
				'name'    => __( 'WhatsApp', 'shied-world-schema-markup' ),
				'cta'     => __( 'Chat on WhatsApp', 'shied-world-schema-markup' ),
				'icon'    => __( 'Chat about SEO services on WhatsApp', 'shied-world-schema-markup' ),
				'viewbox' => '0 0 24 24',
				'path'    => 'M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413Z',
			),
			'website'  => array(
				'url'  => 'https://shiedworld.com/',
				'name' => __( 'Website', 'shied-world-schema-markup' ),
				'cta'  => __( 'Visit shiedworld.com', 'shied-world-schema-markup' ),
			),
		);
	}

	/**
	 * SEO service widget shown on the right of the settings page header.
	 *
	 * Called once from the shared header markup in render_page(), which every
	 * tab renders inside, so the widget is identical on all of them and never
	 * has to be repeated per tab.
	 *
	 * Only the three platform icons are listed. The website link is
	 * deliberately absent from the header and lives on the SEO Services tab.
	 *
	 * @return void
	 */
	private static function render_seo_header_widget() {
		$links     = self::get_seo_service_links();
		$platforms = array( 'fiverr', 'upwork', 'whatsapp' );
		?>
		<div class="smsw-seo-widget">
			<span class="smsw-seo-widget-label"><?php esc_html_e( 'Need SEO Services? Contact Via:', 'shied-world-schema-markup' ); ?></span>
			<ul class="smsw-seo-widget-list">
				<?php foreach ( $platforms as $platform ) : ?>
					<?php
					if ( ! isset( $links[ $platform ]['url'], $links[ $platform ]['path'] ) ) {
						continue;
					}

					$icon  = $links[ $platform ];
					$parts = explode( ' ', $icon['viewbox'] );
					// 22px renders the glyphs at a size that is recognisable at a
					// glance while staying balanced with the 14px label above.
					$width = round( 22 * ( (int) $parts[2] / max( 1, (int) $parts[3] ) ), 1 );
					?>
					<li>
						<a href="<?php echo esc_url( $icon['url'] ); ?>" target="_blank" rel="noopener" aria-label="<?php echo esc_attr( $icon['icon'] ); ?>">
							<svg height="22" width="<?php echo esc_attr( $width ); ?>" viewBox="<?php echo esc_attr( $icon['viewbox'] ); ?>" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" focusable="false"><path fill="currentColor" d="<?php echo esc_attr( $icon['path'] ); ?>" /></svg>
						</a>
					</li>
				<?php endforeach; ?>
			</ul>
		</div>
		<?php
	}

	/**
	 * Official social profiles linked from the About tab and the footer.
	 *
	 * Each path is the official brand glyph for that platform, sourced from
	 * Font Awesome Free, whose icons are released under the Creative Commons
	 * Attribution 4.0 International license.
	 *
	 * @return array<string,array<string,string>> Icon definitions.
	 */
	private static function get_social_icons() {
		return array(
			'instagram' => array(
				'url'     => 'https://www.instagram.com/shiedworld/',
				'label'   => __( 'Follow SHIED WORLD on Instagram', 'shied-world-schema-markup' ),
				'viewbox' => '0 0 448 512',
				'path'    => 'M224.1 141c-63.6 0-114.9 51.3-114.9 114.9s51.3 114.9 114.9 114.9S339 319.5 339 255.9 287.7 141 224.1 141zm0 189.6c-41.1 0-74.7-33.5-74.7-74.7s33.5-74.7 74.7-74.7 74.7 33.5 74.7 74.7-33.6 74.7-74.7 74.7zm146.4-194.3c0 14.9-12 26.8-26.8 26.8-14.9 0-26.8-12-26.8-26.8s12-26.8 26.8-26.8 26.8 12 26.8 26.8zm76.1 27.2c-1.7-35.9-9.9-67.7-36.2-93.9-26.2-26.2-58-34.4-93.9-36.2-37-2.1-147.9-2.1-184.9 0-35.8 1.7-67.6 9.9-93.9 36.1s-34.4 58-36.2 93.9c-2.1 37-2.1 147.9 0 184.9 1.7 35.9 9.9 67.7 36.2 93.9s58 34.4 93.9 36.2c37 2.1 147.9 2.1 184.9 0 35.9-1.7 67.7-9.9 93.9-36.2 26.2-26.2 34.4-58 36.2-93.9 2.1-37 2.1-147.8 0-184.8zM398.8 388c-7.8 19.6-22.9 34.7-42.6 42.6-29.5 11.7-99.5 9-132.1 9s-102.7 2.6-132.1-9c-19.6-7.8-34.7-22.9-42.6-42.6-11.7-29.5-9-99.5-9-132.1s-2.6-102.7 9-132.1c7.8-19.6 22.9-34.7 42.6-42.6 29.5-11.7 99.5-9 132.1-9s102.7-2.6 132.1 9c19.6 7.8 34.7 22.9 42.6 42.6 11.7 29.5 9 99.5 9 132.1s2.7 102.7-9 132.1z',
			),
			'facebook' => array(
				'url'     => 'https://www.facebook.com/shiedworld1',
				'label'   => __( 'Follow SHIED WORLD on Facebook', 'shied-world-schema-markup' ),
				'viewbox' => '0 0 512 512',
				'path'    => 'M504 256C504 119 393 8 256 8S8 119 8 256c0 123.78 99.35 224.35 223.03 247.1v-184.06h-70.89V256h70.89v-54.24c0-70.02 41.72-108.68 105.5-108.68 30.58 0 62.51 5.46 62.51 5.46v68.75h-35.2c-34.65 0-45.44 21.5-45.44 43.56V256h77.42l-12.38 63.14h-65.04v184.06C404.65 480.35 504 379.78 504 256z',
			),
			'linkedin-company' => array(
				'url'     => 'https://www.linkedin.com/company/shied-world',
				'label'   => __( 'Visit the SHIED WORLD company page on LinkedIn', 'shied-world-schema-markup' ),
				'viewbox' => '0 0 448 512',
				'path'    => 'M416 32H31.9C14.3 32 0 46.5 0 64.3v383.4C0 465.5 14.3 480 31.9 480H416c17.6 0 32-14.5 32-32.3V64.3c0-17.8-14.4-32.3-32-32.3zM135.4 416H69V202.2h66.5V416zm-33.2-243c-21.3 0-38.5-17.3-38.5-38.5S80.9 96 102.2 96c21.2 0 38.5 17.3 38.5 38.5 0 21.3-17.2 38.5-38.5 38.5zm282.1 243h-66.4V312c0-24.8-.5-56.7-34.5-56.7-34.6 0-39.9 27-39.9 54.9V416h-66.4V202.2h63.7v29.2h.9c8.9-16.8 30.6-34.5 62.9-34.5 67.2 0 79.7 44.3 79.7 101.9V416z',
			),
			'linkedin-personal' => array(
				'url'     => 'https://www.linkedin.com/in/kazi-md-abdulla-al-shied2/',
				'label'   => __( 'Connect with the plugin author on LinkedIn', 'shied-world-schema-markup' ),
				'viewbox' => '0 0 448 512',
				'path'    => 'M416 32H31.9C14.3 32 0 46.5 0 64.3v383.4C0 465.5 14.3 480 31.9 480H416c17.6 0 32-14.5 32-32.3V64.3c0-17.8-14.4-32.3-32-32.3zM135.4 416H69V202.2h66.5V416zm-33.2-243c-21.3 0-38.5-17.3-38.5-38.5S80.9 96 102.2 96c21.2 0 38.5 17.3 38.5 38.5 0 21.3-17.2 38.5-38.5 38.5zm282.1 243h-66.4V312c0-24.8-.5-56.7-34.5-56.7-34.6 0-39.9 27-39.9 54.9V416h-66.4V202.2h63.7v29.2h.9c8.9-16.8 30.6-34.5 62.9-34.5 67.2 0 79.7 44.3 79.7 101.9V416z',
			),
		);
	}

	/**
	 * Renders the Privacy Policy and Terms and Conditions text links.
	 *
	 * @return void
	 */
	private static function render_policy_links() {
		?>
		<p class="smsw-policy-links">
			<a href="https://shiedworld.com/privacy-policy-shied-world-schema-markup/" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Privacy Policy', 'shied-world-schema-markup' ); ?></a>
			<a href="https://shiedworld.com/terms-conditions-shied-world-schema-markup/" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Terms & Conditions', 'shied-world-schema-markup' ); ?></a>
		</p>
		<?php
	}

	/**
	 * Renders the social media icon row used on the About tab and in the footer.
	 *
	 * Every link opens in a new tab and carries a descriptive aria-label so the
	 * row is usable with a screen reader. The icons are inline SVG paths, so no
	 * icon font or image file is loaded.
	 *
	 * @param int $height Icon height in pixels. The width follows each glyph's
	 *                    own aspect ratio so the shape is never distorted.
	 * @return void
	 */
	private static function render_social_links( $height = 20 ) {
		$height = (int) $height;

		if ( $height < 12 ) {
			$height = 12;
		} elseif ( $height > 32 ) {
			$height = 32;
		}
		?>
		<ul class="smsw-social-links">
			<?php foreach ( self::get_social_icons() as $icon ) : ?>
				<?php
				$parts = explode( ' ', $icon['viewbox'] );
				$width = round( $height * ( (int) $parts[2] / max( 1, (int) $parts[3] ) ), 1 );
				?>
				<li>
					<a href="<?php echo esc_url( $icon['url'] ); ?>" target="_blank" rel="noopener noreferrer" aria-label="<?php echo esc_attr( $icon['label'] ); ?>">
						<svg height="<?php echo esc_attr( $height ); ?>" width="<?php echo esc_attr( $width ); ?>" viewBox="<?php echo esc_attr( $icon['viewbox'] ); ?>" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" focusable="false"><path fill="currentColor" d="<?php echo esc_attr( $icon['path'] ); ?>" /></svg>
					</a>
				</li>
			<?php endforeach; ?>
		</ul>
		<?php
	}

	/**
	 * About tab.
	 *
	 * @return void
	 */
	private static function render_about() {
		$site_url = esc_url( home_url( '/' ) );
		$blogname = esc_attr( get_bloginfo( 'name' ) );
		// Tabs are real page views, not a JS switcher: get_tabs() keys are the
		// same ?tab= values the tab bar links to, so pointing at seo-services
		// is the same URL the visible tab bar already uses.
		$seo_tab_url = admin_url( 'admin.php?page=' . SMSW_Admin_Menu::MENU_SLUG . '&tab=seo-services' );
		?>
		<div class="smsw-panel smsw-about">
			<div class="smsw-about-hero">
				<div>
					<h1 class="smsw-about-title"><?php esc_html_e( 'SHIED WORLD Schema Markup', 'shied-world-schema-markup' ); ?></h1>
					<p class="smsw-about-tagline"><?php esc_html_e( 'Universal JSON-LD schema markup for your WordPress site.', 'shied-world-schema-markup' ); ?></p>
				</div>
			</div>

			<h2><?php esc_html_e( 'About the Creator', 'shied-world-schema-markup' ); ?></h2>
			<p><?php esc_html_e( 'SHIED WORLD Schema Markup was created by Kazi MD Abdulla Al Shied, an SEO specialist and the founder of SHIED WORLD, an agency focused on WordPress Development and Search Engine Optimization-SEO.', 'shied-world-schema-markup' ); ?></p>

			<h2><?php esc_html_e( 'Why This Plugin Was Built', 'shied-world-schema-markup' ); ?></h2>
			<p><?php esc_html_e( 'In the age of AI-powered search, structured schema markup has become more important than ever for helping search engines and AI systems understand your content correctly.', 'shied-world-schema-markup' ); ?></p>
			<p><?php esc_html_e( 'Most existing schema markup plugins only offer a small number of schema types for free and lock the rest behind a paid tier. Most users prefer not to deal with card-based subscription payments for something they could otherwise access freely.', 'shied-world-schema-markup' ); ?></p>
			<?php // "765+" is the public marketing figure and is deliberately a round lower bound, not the raw vocabulary size. The live selectable count is 928 main-namespace rdfs:Class entries, as returned by SMSW_Vocabulary_Parser::get_all_type_names(); even the strictest filter (dropping Enumeration descendants, pure data types and schema:supersededBy types) still leaves roughly 808, so "765+" stays true. Revalidate against that method if the bundled vocabulary file is ever replaced. ?>
			<p><?php esc_html_e( 'This plugin was built to give the entire WordPress community a genuinely complete, unrestricted, fully free schema markup solution, with no artificial limits on which schema types are available. Every schema type supported by this plugin, 765+ of them based on the official schema.org vocabulary, is available completely free, with no premium tier and no paid upgrade required.', 'shied-world-schema-markup' ); ?></p>

			<h2><?php esc_html_e( 'How It Was Built', 'shied-world-schema-markup' ); ?></h2>
			<p><?php esc_html_e( 'It was developed while learning web development, combining personal knowledge with AI-assisted development, and using the full official schema.org vocabulary as the single source of truth for every schema type and property included in this plugin, ensuring accuracy and completeness rather than a hand-picked limited subset.', 'shied-world-schema-markup' ); ?></p>

			<p class="smsw-about-seo-pointer">
				<?php
				printf(
					/* translators: %s: Anchor linking to the SEO Services settings tab. */
					esc_html__( 'Looking for SEO help? Check the %s above.', 'shied-world-schema-markup' ),
					'<a href="' . esc_url( $seo_tab_url ) . '">' . esc_html__( 'SEO Services tab', 'shied-world-schema-markup' ) . '</a>'
				);
				?>
			</p>

			<div class="smsw-about-actions">
				<a href="<?php echo esc_url( self::get_seo_service_links()['website']['url'] ); ?>" class="button button-primary smsw-about-btn" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Visit SHIED WORLD', 'shied-world-schema-markup' ); ?></a>
				<a href="mailto:shied@shiedworld.com" class="button smsw-about-btn"><?php esc_html_e( 'Email Us', 'shied-world-schema-markup' ); ?></a>
			</div>

			<?php self::render_policy_links(); ?>
			<?php self::render_social_links(); ?>
		</div>
		<?php
	}

	/**
	 * SEO Services tab.
	 *
	 * Static display content only. Nothing here is saved, so the tab needs
	 * no settings registration, no nonce and no database option. Every link
	 * comes from get_seo_service_links(), the same source the header widget
	 * uses, so a link is only ever defined once.
	 *
	 * @return void
	 */
	private static function render_seo_services() {
		$links = self::get_seo_service_links();

		$services = array(
			array(
				'title' => __( 'Local SEO', 'shied-world-schema-markup' ),
				'text'  => __( 'Rank higher in local search results and Google Maps for your business area.', 'shied-world-schema-markup' ),
			),
			array(
				'title' => __( 'E-commerce SEO', 'shied-world-schema-markup' ),
				'text'  => __( 'Optimize product pages and category pages to drive organic sales.', 'shied-world-schema-markup' ),
			),
			array(
				'title' => __( 'YouTube SEO', 'shied-world-schema-markup' ),
				'text'  => __( 'Improve video discoverability and channel growth through search optimization.', 'shied-world-schema-markup' ),
			),
			array(
				'title' => __( 'Digital Product SEO', 'shied-world-schema-markup' ),
				'text'  => __( 'SEO strategy for digital products, courses, and downloadable content.', 'shied-world-schema-markup' ),
			),
			array(
				'title' => __( 'National Ranking SEO', 'shied-world-schema-markup' ),
				'text'  => __( 'Compete and rank across your entire country\'s search market.', 'shied-world-schema-markup' ),
			),
			array(
				'title' => __( 'International Ranking SEO', 'shied-world-schema-markup' ),
				'text'  => __( 'Multi-region SEO strategy to rank across global markets.', 'shied-world-schema-markup' ),
			),
		);

		$reasons = array(
			__( 'Hands-on SEO work, directly from the specialist handling your project.', 'shied-world-schema-markup' ),
			__( 'Experience working with local service businesses across the UK, Ireland, Australia, and North America.', 'shied-world-schema-markup' ),
			__( 'Clear, direct communication throughout the project.', 'shied-world-schema-markup' ),
		);
		?>
		<div class="smsw-panel smsw-seo-services">
			<h2><?php esc_html_e( 'Need SEO Services?', 'shied-world-schema-markup' ); ?></h2>
			<p><?php esc_html_e( 'Get professional SEO support from SHIED WORLD across multiple specializations. Reach out through any of the platforms below.', 'shied-world-schema-markup' ); ?></p>

			<h3><?php esc_html_e( 'Services', 'shied-world-schema-markup' ); ?></h3>
			<ul class="smsw-seo-service-list">
				<?php foreach ( $services as $service ) : ?>
					<li>
						<strong><?php echo esc_html( $service['title'] ); ?>:</strong>
						<?php echo esc_html( $service['text'] ); ?>
					</li>
				<?php endforeach; ?>
			</ul>

			<h3><?php esc_html_e( 'Why Work With SHIED WORLD', 'shied-world-schema-markup' ); ?></h3>
			<ul class="smsw-seo-reason-list">
				<?php foreach ( $reasons as $reason ) : ?>
					<li><?php echo esc_html( $reason ); ?></li>
				<?php endforeach; ?>
			</ul>

			<div class="smsw-seo-cta-row">
				<?php foreach ( array( 'fiverr', 'upwork', 'whatsapp' ) as $platform ) : ?>
					<?php if ( ! isset( $links[ $platform ]['url'], $links[ $platform ]['cta'] ) ) : ?>
						<?php continue; ?>
					<?php endif; ?>
					<a href="<?php echo esc_url( $links[ $platform ]['url'] ); ?>" class="button button-primary smsw-seo-cta" target="_blank" rel="noopener"><?php echo esc_html( $links[ $platform ]['cta'] ); ?></a>
				<?php endforeach; ?>
				<?php if ( isset( $links['website']['url'], $links['website']['cta'] ) ) : ?>
					<a href="<?php echo esc_url( $links['website']['url'] ); ?>" class="button smsw-seo-cta smsw-seo-cta-alt" target="_blank" rel="noopener"><?php echo esc_html( $links['website']['cta'] ); ?></a>
				<?php endif; ?>
			</div>

			<p class="smsw-seo-footer-line"><?php esc_html_e( 'SHIED WORLD: Local, General & Ecommerce SEO Services', 'shied-world-schema-markup' ); ?></p>
		</div>
		<?php
	}

}
