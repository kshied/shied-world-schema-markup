<?php
/**
 * Bring your own key AI schema type suggestions.
 *
 * @package Shied_World_Schema_Markup
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Requests a schema type suggestion from a user supplied AI provider key.
 *
 * The plugin never ships a key. Every request uses the key saved for the
 * selected provider under Schema Markup > AI BYOK, decrypted on the fly.
 */
class SMSW_AI_Suggestion {

	/**
	 * AJAX action and nonce action used by the block builder.
	 *
	 * @var string
	 */
	const NONCE_ACTION = 'smsw_ai_suggest';

	/**
	 * Nonce action used by the AJAX handler that returns a provider's models.
	 *
	 * Separate from NONCE_ACTION so the models endpoint can be audited on its
	 * own and revoked independently of the suggestion call.
	 *
	 * @var string
	 */
	const MODELS_NONCE_ACTION = 'smsw_ai_models';

	/**
	 * How long a fetched model list is cached, in seconds.
	 *
	 * 24 hours. Model lineups change slowly, so a day is frequent enough to
	 * pick up a new release and rare enough that the models endpoint is not
	 * called on ordinary admin page loads.
	 *
	 * @var int
	 */
	const MODEL_CACHE_TTL = DAY_IN_SECONDS;

	/**
	 * Transient key prefix for a cached model list.
	 *
	 * @var string
	 */
	const MODEL_CACHE_PREFIX = 'smsw_ai_models_';

	/**
	 * How long a failed models call is remembered before it is retried.
	 *
	 * A failed fetch is never stored as a model list, only as this short
	 * pause, so a provider that refused the key is not asked again on every
	 * admin page load while still being retried within minutes rather than
	 * a day. Saving a new key clears the pause immediately.
	 *
	 * @var int
	 */
	const MODEL_RETRY_GAP = 300;

	/**
	 * Transient key prefix recording where each model list came from.
	 *
	 * @var string
	 */
	const MODEL_STATE_PREFIX = 'smsw_ai_models_state_';

	/**
	 * Characters of body text sent in the default lightweight context.
	 *
	 * @var int
	 */
	const EXCERPT_LIMIT = 350;

	/**
	 * Characters of body text sent when full content mode is enabled.
	 *
	 * @var int
	 */
	const FULL_CONTENT_LIMIT = 3500;

	/**
	 * Maximum output tokens requested from a provider.
	 *
	 * Reasoning capable models are billed for their thinking tokens and those
	 * are counted against this same budget, so the old ceiling of 500 left a
	 * thinking model only a handful of tokens to write its answer in. A
	 * captured Gemini 2.5 Flash reply spent 479 tokens on internal reasoning
	 * and managed 17 tokens of actual answer before being cut off mid JSON.
	 * Classifying one page needs roughly 300 tokens of answer, so the ceiling
	 * is raised to cover a generous reasoning pass plus a complete answer.
	 * The real saving is made by capping the thinking budget below, not by
	 * starving the answer.
	 *
	 * @var int
	 */
	const MAX_OUTPUT_TOKENS = 1500;

	/**
	 * Thinking token ceiling for Gemini reasoning models.
	 *
	 * Choosing a schema type is a classification task, and Google documents
	 * minimal thinking as the correct setting for classification. Because
	 * thinking tokens count against maxOutputTokens, an uncapped reasoning
	 * model can consume the entire budget before writing anything. Zero
	 * disables thinking outright on the models that accept it.
	 *
	 * @var int
	 */
	const GEMINI_THINKING_BUDGET = 0;

	/**
	 * Smallest thinking budget Gemini accepts, for the Pro models that refuse
	 * zero.
	 *
	 * @var int
	 */
	const GEMINI_MIN_THINKING_BUDGET = 128;

	/**
	 * Most suggestions ever returned to the builder UI.
	 *
	 * @var int
	 */
	const MAX_SUGGESTIONS = 3;

	/**
	 * Why the provider stopped generating the last answer.
	 *
	 * Every provider names this differently, so it is normalised into one
	 * string here: Gemini uses candidates[0].finishReason, the OpenAI
	 * compatible providers use choices[0].finish_reason, and Anthropic uses
	 * stop_reason. A reasoning model can spend the whole output budget on
	 * internal thinking and stop mid-answer, and that is the only reliable way
	 * to tell a cut-off reply apart from a model that had nothing to suggest.
	 *
	 * @var string
	 */
	private static $last_finish_reason = '';

	/**
	 * Per provider model-list configuration.
	 *
	 * endpoint   Models-list URL, or '' when the provider has no public
	 *            models endpoint we could verify.
	 * auth       How the saved key is attached: 'bearer', 'x-api-key' or
	 *            'query-key'.
	 * url_style  How the id reaches the completion URL: 'path' (Gemini) or
	 *            'plain' (the id goes in the JSON body).
	 * fallbacks  Hardcoded ids used when the live fetch fails. The first entry
	 *            is the default selection: the smallest/cheapest chat model
	 *            documented for that provider.
	 * skip       Substrings marking a model as unusable for short text
	 *            classification. Matched case-insensitively. Deliberately
	 *            not named "exclude": this is local configuration, never a
	 *            WP_Query argument.
	 *
	 * The fallback ids were read from each provider's own documentation rather
	 * than guessed. get_model_config_note() records which ones still need a
	 * human to confirm them.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function get_model_config() {
		// Each endpoint below is that provider's own API host. The site
		// owner's key travels from their browser straight to it, so the AI
		// provider check is switched off for this config array only.
		// phpcs:disable PluginCheck.CodeAnalysis.AIProvider
		return array(
			'openai'     => array(
				'endpoint'  => 'https://api.openai.com/v1/models',
				'auth'      => 'bearer',
				'url_style' => 'plain',
				'fallbacks' => array(
					'gpt-6-luna',
					'gpt-6-sol',
					'gpt-6-astra',
				),
				'skip'      => array( 'embedding', 'dall-e', 'tts', 'whisper', 'moderation', 'realtime', 'audio', 'gpt-image', 'gpt-audio', 'transcribe', 'guard', 'search', 'browse' ),
			),
			'anthropic'  => array(
				'endpoint'  => 'https://api.anthropic.com/v1/models',
				'auth'      => 'x-api-key',
				'url_style' => 'plain',
				'fallbacks' => array(
					'claude-haiku-4-5-20251001',
					'claude-sonnet-5',
					'claude-opus-5-5',
				),
				'skip'      => array( 'embedding' ),
			),
			'gemini'     => array(
				'endpoint'  => 'https://generativelanguage.googleapis.com/v1beta/models',
				'auth'      => 'query-key',
				'url_style' => 'path',
				'fallbacks' => array(
					'gemini-3.8-flash-lite',
					'gemini-3.8-flash',
					'gemini-3.7-flash',
				),
				'skip'      => array( 'embedding', 'imagen', 'tts', 'live', 'robotics', 'veo', 'aqa', 'image', 'audio', 'speech', 'ocr' ),
			),
			'deepseek'   => array(
				'endpoint'  => 'https://api.deepseek.com/models',
				'auth'      => 'bearer',
				'url_style' => 'plain',
				'fallbacks' => array(
					'deepseek-flash',
					'deepseek-v4-pro',
				),
				'skip'      => array( 'embedding' ),
			),
			'groq'       => array(
				'endpoint'  => 'https://api.groq.com/openai/v1/models',
				'auth'      => 'bearer',
				'url_style' => 'plain',
				'fallbacks' => array(
					'openai/gpt-oss-20b',
					'llama-3.1-8b-instant',
					'llama-3.3-70b-versatile',
				),
				'skip'      => array( 'whisper', 'guard', 'orpheus', 'audio', 'vision', 'tts', 'stt' ),
			),
			'openrouter' => array(
				// The endpoint is public, but the saved key is still sent: it
				// raises rate limits and keeps the call attributable.
				'endpoint'  => 'https://openrouter.ai/api/v1/models?output_modalities=text',
				'auth'      => 'bearer',
				'url_style' => 'plain',
				'fallbacks' => array(
					'openai/gpt-6-luna',
					'openai/gpt-6-sol',
				),
				'skip'      => array( 'embedding', 'image', 'tts', 'whisper', 'moderation', 'audio', 'guard' ),
			),
		);
		// phpcs:enable PluginCheck.CodeAnalysis.AIProvider
	}

	/**
	 * Per provider note about how trustworthy the fallback list is.
	 *
	 * Surfaced in the settings screen so a site owner can see at a glance
	 * which provider's default still deserves a manual check.
	 *
	 * @return array<string,string>
	 */
	public static function get_model_config_note() {
		return array(
			'openai'     => __( 'Default checked against the OpenAI model catalog. Pick another if your account does not offer it.', 'shied-world-schema-markup' ),
			'anthropic'  => __( 'Default checked against the Anthropic models overview. Pick another if your account does not offer it.', 'shied-world-schema-markup' ),
			'gemini'     => __( 'Default checked against the Gemini models list. Pick another if your account does not offer it.', 'shied-world-schema-markup' ),
			'deepseek'   => __( 'Default checked against the DeepSeek list models response. Pick another if your account does not offer it.', 'shied-world-schema-markup' ),
			'groq'       => __( 'Default checked against the Groq supported models page. Pick another if your account does not offer it.', 'shied-world-schema-markup' ),
			'openrouter' => __( 'Default checked against the OpenRouter models list. Pick another if your account does not offer it.', 'shied-world-schema-markup' ),
		);
	}

	/**
	 * Whether a provider's fallback list is present and non empty.
	 *
	 * @param string $provider Provider slug.
	 * @return bool
	 */
	public static function has_verified_fallbacks( $provider ) {
		$config = self::get_model_config();

		return isset( $config[ $provider ] ) && ! empty( $config[ $provider ]['fallbacks'] );
	}

	/**
	 * Config for one provider, merged over safe empty defaults.
	 *
	 * @param string $provider Provider slug.
	 * @return array<string,mixed>
	 */
	private static function model_config( $provider ) {
		$config = self::get_model_config();
		$blank  = array(
			'endpoint'  => '',
			'auth'      => 'bearer',
			'url_style' => 'plain',
			'fallbacks' => array(),
			'skip'      => array(),
		);

		return isset( $config[ $provider ] ) ? array_merge( $blank, $config[ $provider ] ) : $blank;
	}

	/**
	 * Whether a model id is excluded from the picker.
	 *
	 * The substring list is deliberately generous. A model that is not a
	 * general text generator cannot answer a schema-type classification
	 * prompt, so offering it would only produce failed calls.
	 *
	 * @param string $model   Model id.
	 * @param array  $skip    Disqualifying substrings.
	 * @return bool
	 */
	private static function is_excluded_model( $model, $skip ) {
		$model = strtolower( (string) $model );

		foreach ( $skip as $needle ) {
			$needle = strtolower( (string) $needle );

			if ( '' !== $needle && false !== strpos( $model, $needle ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Model ids for one provider, from cache when possible.
	 *
	 * Only a list that was genuinely fetched from the provider is cached. The
	 * hardcoded fallback is returned when the live fetch is impossible or it
	 * fails, but it is deliberately never written to the cache: caching it is
	 * what made the settings screen keep offering two or three built-in ids
	 * long after a working key had been saved, because the stored fallback
	 * satisfied the cache check below and the provider's own endpoint was
	 * then never called again until the entry expired.
	 *
	 * @param string $provider Provider slug.
	 * @param bool   $force    Bypass the cache and ask the provider again.
	 * @return array<int,string>
	 */
	public static function get_models( $provider, $force = false ) {
		$provider = sanitize_key( $provider );

		if ( ! array_key_exists( $provider, self::get_model_config() ) ) {
			return array();
		}

		$config    = self::model_config( $provider );
		$cache_key = self::MODEL_CACHE_PREFIX . $provider;

		if ( ! $force ) {
			$cached = get_transient( $cache_key );

			if ( is_array( $cached ) && ! empty( $cached ) ) {
				$cached = array_values( array_map( 'strval', $cached ) );

				// A list that was really read from the provider stays useful
				// after a later fetch fails, so the note has to stop saying
				// "built-in defaults" the moment real ids are on screen again.
				if ( 'fallback' === self::get_model_state( $provider )['source'] ) {
					self::record_model_state( $provider, '', count( $cached ) );
				}

				return $cached;
			}
		}

		$key = self::get_key_for_provider( $provider );

		if ( '' === $config['endpoint'] ) {
			self::record_model_state( $provider, 'no_endpoint' );
			return array_values( self::fallback_models( $config ) );
		}

		if ( '' === $key ) {
			self::record_model_state( $provider, 'no_key' );
			return array_values( self::fallback_models( $config ) );
		}

		// A provider that just rejected this key, or that could not be
		// reached, is not asked again on every admin page load and on every
		// suggestion call. Saving a new key clears the gap immediately.
		if ( ! $force && get_transient( self::model_retry_key( $provider ) ) ) {
			self::record_model_state( $provider, 'retry_pending' );
			return array_values( self::fallback_models( $config ) );
		}

		$result = self::fetch_models( $provider, $key, $config );

		if ( '' === $result['reason'] && ! empty( $result['models'] ) ) {
			set_transient( $cache_key, $result['models'], self::MODEL_CACHE_TTL );
			delete_transient( self::model_retry_key( $provider ) );
			self::record_model_state( $provider, '', count( $result['models'] ) );
			return $result['models'];
		}

		set_transient( self::model_retry_key( $provider ), time(), self::MODEL_RETRY_GAP );
		self::record_model_state( $provider, '' === $result['reason'] ? 'empty' : $result['reason'] );
		return array_values( self::fallback_models( $config ) );
	}

	/**
	 * Forget the cached model list and the failure state of one or all
	 * providers.
	 *
	 * Called when a key is saved, replaced or deleted, so the next page load
	 * asks the provider again instead of serving an entry that was written
	 * under the previous key or before any key existed at all.
	 *
	 * @param string $provider Provider slug, or '' for every provider.
	 * @return void
	 */
	public static function invalidate_model_cache( $provider = '' ) {
		$provider = sanitize_key( $provider );
		$slugs    = '' === $provider ? array_keys( self::get_model_config() ) : array( $provider );

		foreach ( $slugs as $slug ) {
			delete_transient( self::MODEL_CACHE_PREFIX . $slug );
			delete_transient( self::model_retry_key( $slug ) );
			delete_transient( self::MODEL_STATE_PREFIX . $slug );
		}
	}

	/**
	 * Where the model list shown for a provider last came from.
	 *
	 * @param string $provider Provider slug.
	 * @return array{source:string,reason:string,count:int}
	 */
	public static function get_model_state( $provider ) {
		$provider = sanitize_key( $provider );
		$state    = get_transient( self::MODEL_STATE_PREFIX . $provider );

		if ( ! is_array( $state ) || ! isset( $state['source'] ) ) {
			return array(
				'source' => 'unknown',
				'reason' => '',
				'count'  => 0,
			);
		}

		return array(
			'source' => (string) $state['source'],
			'reason' => isset( $state['reason'] ) ? (string) $state['reason'] : '',
			'count'  => isset( $state['count'] ) ? (int) $state['count'] : 0,
		);
	}

	/**
	 * One line telling the site owner whether the list on screen is a live one.
	 *
	 * The settings screen shows this under the model picker, so a fallback
	 * list is never mistaken for the provider's real lineup, and the reason it
	 * fell back is visible without reading a log file.
	 *
	 * @param string $provider Provider slug.
	 * @param string $label    Provider label.
	 * @return string
	 */
	public static function describe_model_state( $provider, $label ) {
		$state  = self::get_model_state( $provider );
		$reason = $state['reason'];

		if ( 'live' === $state['source'] ) {
			/* translators: 1: number of models, 2: provider name. */
			return sprintf( __( '%1$d models were read from %2$s and are current.', 'shied-world-schema-markup' ), $state['count'], $label );
		}

		/* translators: %s: provider name. */
		$defaults = sprintf( __( 'Built-in defaults for %s: the live list was not available.', 'shied-world-schema-markup' ), $label );

		if ( 0 === strpos( $reason, 'http_' ) ) {
			$code = (int) substr( $reason, 5 );

			if ( 401 === $code || 403 === $code ) {
				/* translators: %d: HTTP status code. */
				return $defaults . ' ' . sprintf( __( 'The provider refused the saved key (HTTP %d): check or re-enter it.', 'shied-world-schema-markup' ), $code );
			}

			/* translators: %d: HTTP status code. */
			return $defaults . ' ' . sprintf( __( 'The provider replied with HTTP status %d.', 'shied-world-schema-markup' ), $code );
		}

		switch ( $reason ) {
			case 'no_key':
				/* translators: %s: provider name. */
				return sprintf( __( 'No API key is saved for %s yet, so built-in defaults are offered. Save a key to get the provider\'s own list.', 'shied-world-schema-markup' ), $label );
			case 'no_endpoint':
				return __( 'This provider has no model list endpoint, so built-in defaults are offered.', 'shied-world-schema-markup' );
			case 'transport':
				return $defaults . ' ' . __( 'The provider could not be reached from this server.', 'shied-world-schema-markup' );
			case 'invalid_json':
				return $defaults . ' ' . __( 'The reply was not readable as JSON.', 'shied-world-schema-markup' );
			case 'retry_pending':
				return $defaults . ' ' . __( 'Another attempt follows shortly.', 'shied-world-schema-markup' );
			case 'empty':
				return $defaults . ' ' . __( 'The reply listed no text model this plugin can use.', 'shied-world-schema-markup' );
			default:
				return $defaults;
		}
	}

	/**
	 * Record whether the last list was live, and why it was not when it was
	 * not.
	 *
	 * @param string $provider Provider slug.
	 * @param string $reason   Empty string after a live fetch, otherwise a
	 *                         machine readable failure reason.
	 * @param int    $count    Models in the live list, when there is one.
	 * @return void
	 */
	private static function record_model_state( $provider, $reason, $count = 0 ) {
		set_transient(
			self::MODEL_STATE_PREFIX . $provider,
			array(
				'source' => '' === $reason ? 'live' : 'fallback',
				'reason' => (string) $reason,
				'count'  => (int) $count,
			),
			self::MODEL_CACHE_TTL
		);
	}

	/**
	 * Transient naming the moment a provider's models call last failed.
	 *
	 * @param string $provider Provider slug.
	 * @return string
	 */
	private static function model_retry_key( $provider ) {
		return self::MODEL_CACHE_PREFIX . $provider . '_retry';
	}

	/**
	 * The model id to use for a provider right now.
	 *
	 * Prefers the saved selection when the provider still offers it, otherwise
	 * falls back to the cheapest documented model. This is what stops a
	 * provider retiring a model from silently breaking a working setup: the
	 * stale id is replaced instead of being sent and rejected.
	 *
	 * @param string $provider Provider slug.
	 * @return string
	 */
	public static function get_selected_model( $provider ) {
		$provider = sanitize_key( $provider );
		$models   = self::get_models( $provider );
		$settings = SMSW_Options::get_ai_settings();

		$saved = isset( $settings['models'][ $provider ] ) ? (string) $settings['models'][ $provider ] : '';

		if ( '' !== $saved && in_array( $saved, $models, true ) ) {
			return $saved;
		}

		return isset( $models[0] ) ? (string) $models[0] : '';
	}

	/**
	 * Hardcoded ids for a provider, filtered like a live list would be.
	 *
	 * @param array<string,mixed> $config Provider config.
	 * @return array<int,string>
	 */
	private static function fallback_models( $config ) {
		$out = array();

		foreach ( $config['fallbacks'] as $model ) {
			$model = (string) $model;

			if ( '' !== $model && ! self::is_excluded_model( $model, $config['skip'] ) ) {
				$out[] = $model;
			}
		}

		return $out;
	}

	/**
	 * Call a provider's models endpoint and return the chat-capable ids.
	 *
	 * Every provider is asked over the same transport with the same timeout,
	 * and each answer is judged on its own: the reason a fetch did not produce
	 * a usable list is returned to the caller instead of being swallowed, so a
	 * valid reply can never be mistaken for a failure and a failure can never
	 * be mistaken for a live list.
	 *
	 * @param string              $provider Provider slug.
	 * @param string              $key      Plaintext API key.
	 * @param array<string,mixed> $config   Provider config.
	 * @return array{models:array<int,string>,reason:string} reason is '' on success.
	 */
	private static function fetch_models( $provider, $key, $config ) {
		$url     = (string) $config['endpoint'];
		$headers = array( 'accept' => 'application/json' );

		switch ( $config['auth'] ) {
			case 'x-api-key':
				// Anthropic documents x-api-key plus the version header.
				$headers['x-api-key']         = $key;
				$headers['anthropic-version'] = '2023-06-01';
				break;

			case 'query-key':
				// Gemini documents the key as a query parameter.
				$url .= ( false === strpos( $url, '?' ) ? '?' : '&' ) . 'key=' . rawurlencode( $key );
				break;

			case 'bearer':
			default:
				// OpenAI, Groq, DeepSeek and OpenRouter all document
				// Authorization: Bearer <key>.
				$headers['Authorization'] = 'Bearer ' . $key;
				break;
		}

		// The URL is that provider's own models endpoint, taken from the
		// config above, and the key is the owner's own saved key. No server
		// run by the plugin author is involved at any point.
		// phpcs:disable PluginCheck.CodeAnalysis.AIProvider
		$response = wp_remote_get(
			$url,
			array(
				'timeout' => 15,
				'headers' => $headers,
			)
		);
		// phpcs:enable PluginCheck.CodeAnalysis.AIProvider

		if ( is_wp_error( $response ) ) {
			// A transport level failure: the host could not reach the provider
			// at all (DNS, firewall, SSL, cURL error 6/28/35), so there is no
			// status code or body to judge.
			return self::models_failure( 'transport' );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$raw  = (string) wp_remote_retrieve_body( $response );

		if ( $code < 200 || $code >= 300 ) {
			return self::models_failure( 'http_' . $code );
		}

		$data = json_decode( $raw, true );

		if ( ! is_array( $data ) ) {
			return self::models_failure( 'invalid_json' );
		}

		$models = self::parse_models( $provider, $data, $config['skip'] );

		if ( empty( $models ) ) {
			return self::models_failure( 'empty' );
		}

		return array(
			'models' => $models,
			'reason' => '',
		);
	}

	/**
	 * Shape of a models fetch that produced nothing usable.
	 *
	 * @param string $reason Machine readable reason, surfaced in the UI.
	 * @return array{models:array<int,string>,reason:string}
	 */
	private static function models_failure( $reason ) {
		return array(
			'models' => array(),
			'reason' => (string) $reason,
		);
	}

	/**
	 * Pull chat-capable model ids out of a provider's models payload.
	 *
	 * Each provider wraps its list differently, so each shape is read
	 * explicitly instead of assuming one shared envelope:
	 *
	 *   OpenAI / Anthropic / DeepSeek / Groq -> { data: [ { id } ] }
	 *   OpenRouter -> { data: [ { id, architecture: { input_modalities } } ] }
	 *   Gemini -> { models: [ { name, supportedGenerationMethods } ] }
	 *
	 * @param string            $provider Provider slug.
	 * @param array<int,mixed>  $data     Decoded payload.
	 * @param array<int,string> $skip      Disqualifying substrings.
	 * @return array<int,string>
	 */
	private static function parse_models( $provider, $data, $skip ) {
		$out = array();

		if ( 'gemini' === $provider ) {
			$rows = isset( $data['models'] ) && is_array( $data['models'] ) ? $data['models'] : array();

			foreach ( $rows as $row ) {
				if ( ! is_array( $row ) || ! isset( $row['name'] ) || ! is_string( $row['name'] ) ) {
					continue;
				}

				// Gemini only serves generateContent on the models listing it.
				$methods = isset( $row['supportedGenerationMethods'] ) && is_array( $row['supportedGenerationMethods'] )
					? $row['supportedGenerationMethods']
					: array();

				if ( ! empty( $methods ) && ! in_array( 'generateContent', $methods, true ) ) {
					continue;
				}

				$id = (string) preg_replace( '#^models/#', '', $row['name'] );

				if ( '' !== $id && ! self::is_excluded_model( $id, $skip ) ) {
					$out[] = $id;
				}
			}

			return array_values( array_unique( $out ) );
		}

		// Both envelopes are accepted here on purpose: 'data' is what OpenAI,
		// Anthropic, Groq, DeepSeek and OpenRouter return, while a provider
		// that wraps its list in 'models' still parses instead of coming back
		// empty for a naming difference.
		$rows = array();

		foreach ( array( 'data', 'models' ) as $key ) {
			if ( isset( $data[ $key ] ) && is_array( $data[ $key ] ) ) {
				$rows = $data[ $key ];
				break;
			}
		}

		foreach ( $rows as $row ) {
			// A provider that lists plain ids rather than objects still works.
			if ( ! is_array( $row ) ) {
				if ( ! is_string( $row ) ) {
					continue;
				}

				$row = array( 'id' => $row );
			}

			$id = '';

			if ( isset( $row['id'] ) && is_string( $row['id'] ) ) {
				$id = $row['id'];
			} elseif ( isset( $row['canonical_slug'] ) && is_string( $row['canonical_slug'] ) ) {
				$id = $row['canonical_slug'];
			}

			$id = trim( $id );

			if ( '' === $id || self::is_excluded_model( $id, $skip ) ) {
				continue;
			}

			// OpenRouter states each model's input/output modalities. Anything
			// that is not text-in/text-out cannot answer this prompt.
			if ( 'openrouter' === $provider && isset( $row['architecture'] ) && is_array( $row['architecture'] ) ) {
				$inputs  = isset( $row['architecture']['input_modalities'] ) && is_array( $row['architecture']['input_modalities'] )
					? $row['architecture']['input_modalities']
					: null;
				$outputs = isset( $row['architecture']['output_modalities'] ) && is_array( $row['architecture']['output_modalities'] )
					? $row['architecture']['output_modalities']
					: null;

				if ( null !== $inputs && ! in_array( 'text', $inputs, true ) ) {
					continue;
				}

				if ( null !== $outputs && ! in_array( 'text', $outputs, true ) ) {
					continue;
				}
			}

			$out[] = $id;
		}

		return array_values( array_unique( $out ) );
	}

	/**
	 * AJAX: return the model list for one provider.
	 *
	 * @return void
	 */
	public static function ajax_models() {
		if ( ! check_ajax_referer( self::MODELS_NONCE_ACTION, 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'shied-world-schema-markup' ) ) );
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'shied-world-schema-markup' ) ) );
		}

		// The picker may send these on the query string or in a POST body; the nonce was verified above.
		// phpcs:disable WordPress.Security.NonceVerification
		if ( isset( $_POST['provider'] ) ) {
			$provider = sanitize_key( wp_unslash( $_POST['provider'] ) );
		} elseif ( isset( $_GET['provider'] ) ) {
			$provider = sanitize_key( wp_unslash( $_GET['provider'] ) );
		} else {
			$provider = '';
		}

		$force = isset( $_POST['force'] ) || isset( $_GET['force'] );
		// phpcs:enable WordPress.Security.NonceVerification

		if ( '' === $provider || ! array_key_exists( $provider, self::get_model_config() ) ) {
			// Returning an empty success here would let the client replace a
			// perfectly good list with the "no models" placeholder, so an
			// unusable provider is an error instead: the client then leaves
			// whatever it already rendered untouched.
			wp_send_json_error( array( 'message' => __( 'Unknown AI provider requested.', 'shied-world-schema-markup' ) ) );
		}

		$models = self::get_models( $provider, (bool) $force );

		if ( empty( $models ) ) {
			// get_models() falls back to the documented list for every known
			// provider, so reaching here means the config itself is empty.
			wp_send_json_error( array( 'message' => __( 'No models available for this provider.', 'shied-world-schema-markup' ) ) );
		}

		$providers = self::get_providers();
		$label     = isset( $providers[ $provider ] ) ? $providers[ $provider ] : $provider;

		wp_send_json_success(
			array(
				'provider' => $provider,
				'models'   => $models,
				'state'    => self::get_model_state( $provider ),
				'note'     => self::describe_model_state( $provider, $label ),
			)
		);
	}

	/**
	 * Read one request parameter from either the POST body or the query
	 * string. Only ever called after check_ajax_referer() has passed.
	 *
	 * @param string $name Parameter name.
	 * @return string Sanitized value, or an empty string when absent.
	 */
	private static function request_param( $name ) {
		// phpcs:disable WordPress.Security.NonceVerification -- Only caller verified the nonce first.
		if ( isset( $_POST[ $name ] ) ) {
			$value = sanitize_key( wp_unslash( $_POST[ $name ] ) );
		} elseif ( isset( $_GET[ $name ] ) ) {
			$value = sanitize_key( wp_unslash( $_GET[ $name ] ) );
		} else {
			$value = '';
		}
		// phpcs:enable WordPress.Security.NonceVerification

		return $value;
	}

	/**
	 * Marker kept inside masked key previews.
	 *
	 * @var string
	 */
	const MASK_MARKER = '********';

	/**
	 * Register the AJAX handlers.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'wp_ajax_smsw_ai_suggest', array( __CLASS__, 'ajax_suggest' ) );
		add_action( 'wp_ajax_smsw_ai_models', array( __CLASS__, 'ajax_models' ) );
	}

	/**
	 * Supported providers, slug => label.
	 *
	 * @return array<string,string>
	 */
	public static function get_providers() {
		return array(
			'openai'     => 'OpenAI',
			'anthropic'  => 'Anthropic',
			'gemini'     => 'Gemini',
			'deepseek'   => 'DeepSeek',
			'groq'       => 'Groq',
			'openrouter' => 'OpenRouter',
		);
	}

	/**
	 * Provider selected on the AI BYOK tab.
	 *
	 * @return string
	 */
	public static function get_current_provider() {
		$settings = SMSW_Options::get_ai_settings();
		$provider = isset( $settings['provider'] ) ? sanitize_key( $settings['provider'] ) : 'openai';

		if ( '' === $provider || ! array_key_exists( $provider, self::get_providers() ) ) {
			$provider = 'openai';
		}

		return $provider;
	}

	/**
	 * Decrypted key saved for one provider, or an empty string.
	 *
	 * @param string $provider Provider slug.
	 * @return string
	 */
	public static function get_key_for_provider( $provider ) {
		$provider = sanitize_key( $provider );

		if ( '' === $provider ) {
			return '';
		}

		$payload = SMSW_Options::get_ai_key( $provider );

		if ( ! is_string( $payload ) || '' === $payload ) {
			return '';
		}

		$plain = SMSW_Crypto::decrypt( $payload );
		$plain = is_string( $plain ) ? trim( $plain ) : '';

		if ( '' === $plain || self::is_mask( $plain ) ) {
			return '';
		}

		return $plain;
	}

	/**
	 * Whether a usable key is saved for the selected or a given provider.
	 *
	 * @param string $provider Optional provider slug.
	 * @return bool
	 */
	public static function has_key( $provider = '' ) {
		if ( '' === $provider ) {
			$provider = self::get_current_provider();
		}

		return '' !== self::get_key_for_provider( $provider );
	}

	/**
	 * Deterministic masked preview of a stored key.
	 *
	 * The same key always produces the same mask, so the settings form can
	 * recognise an unchanged preview, and the mask reveals nothing usable.
	 *
	 * @param string $plain Plaintext key.
	 * @return string
	 */
	public static function mask_key( $plain ) {
		$plain = is_string( $plain ) ? trim( $plain ) : '';

		if ( '' === $plain ) {
			return '';
		}

		$length = strlen( $plain );
		$head   = substr( $plain, 0, min( 6, $length ) );
		$tail   = $length > 12 ? substr( $plain, -4 ) : '';

		return $head . self::MASK_MARKER . $tail;
	}

	/**
	 * Whether a value looks like a masked preview rather than a real key.
	 *
	 * @param mixed $value Candidate value.
	 * @return bool
	 */
	public static function is_mask( $value ) {
		if ( ! is_string( $value ) || '' === $value ) {
			return false;
		}

		return false !== strpos( $value, self::MASK_MARKER );
	}

	/**
	 * Handle the smsw_ai_suggest AJAX request.
	 *
	 * Error responses deliberately use the default success HTTP status so the
	 * block builder can always read the message field and show it to the user.
	 *
	 * @return void
	 */
	public static function ajax_suggest() {
		if ( ! check_ajax_referer( self::NONCE_ACTION, 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'shied-world-schema-markup' ) ) );
		}

		$post_id = isset( $_POST['post_id'] ) ? absint( wp_unslash( $_POST['post_id'] ) ) : 0;

		if ( $post_id > 0 ) {
			$allowed_request = current_user_can( 'edit_post', $post_id );
		} else {
			$allowed_request = current_user_can( 'manage_options' );
		}

		if ( ! $allowed_request ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to request an AI suggestion for this post.', 'shied-world-schema-markup' ) ) );
		}

		$provider = self::get_current_provider();
		$key      = self::get_key_for_provider( $provider );

		if ( '' === $key ) {
			wp_send_json_error( array( 'message' => __( 'No AI API key is saved. Add your own key under Schema Markup > AI BYOK.', 'shied-world-schema-markup' ) ) );
		}

		$context = self::build_context( $post_id );

		if ( is_wp_error( $context ) ) {
			wp_send_json_error( array( 'message' => $context->get_error_message() ) );
		}

		$exclude = isset( $_POST['exclude'] ) ? sanitize_text_field( wp_unslash( $_POST['exclude'] ) ) : '';
		$used    = array();

		foreach ( explode( ',', $exclude ) as $item ) {
			$item = sanitize_text_field( trim( $item ) );

			if ( '' !== $item ) {
				$used[] = $item;
			}
		}

		$used    = array_values( array_unique( $used ) );
		$allowed = self::allowed_types( $used );

		// When every candidate type is already in use on this content, asking
		// for another one would only repeat what the user already has. The
		// full list is used again so the request can still succeed.
		if ( empty( $allowed ) ) {
			$allowed = self::allowed_types( array() );
		}

		$prompt = self::build_prompt( $context, $allowed );
		$text   = self::request_suggestion( $provider, $key, $prompt );

		if ( is_wp_error( $text ) ) {
			wp_send_json_error( array( 'message' => $text->get_error_message() ) );
		}

		$parsed = self::parse_suggestion( $text, $allowed );

		if ( is_wp_error( $parsed ) ) {
			wp_send_json_error( array( 'message' => $parsed->get_error_message() ) );
		}

		// A list is returned even when it is empty. The builder decides
		// whether an empty list means "already well covered" and shows that
		// message, so this is a success rather than a failure.
		wp_send_json_success(
			array(
				'suggestions' => $parsed,
				'model'       => self::get_selected_model( $provider ),
			)
		);
	}

	/**
	 * Content summary used to ask the AI for type suggestions.
	 *
	 * By default this is deliberately cheap. Schema type classification is a
	 * structural judgement: the shape of a page (its headings, its length, its
	 * type) predicts the right schema type far better than its prose. So the
	 * default payload is the title, the full heading outline, a short excerpt
	 * and the URL, instead of a large slab of raw body text.
	 *
	 * When the site owner opts into full content, build_context() adds the body
	 * text back up to a larger cap. Either way the call is bounded.
	 *
	 * @param int $post_id Post ID, 0 for site context.
	 * @return array<string,mixed>|WP_Error
	 */
	private static function build_context( $post_id ) {
		// Off by default: the lightweight context is the cheaper, better one.
		$full_content = self::uses_full_content();

		if ( $post_id > 0 ) {
			$post = get_post( $post_id );

			if ( ! $post ) {
				return new WP_Error( 'smsw_ai_post', __( 'Post not found.', 'shied-world-schema-markup' ) );
			}

			$raw = strip_shortcodes( (string) $post->post_content );

			// Headings are read from the markup before tags are stripped, so the
			// hierarchy survives.
			$headings = self::extract_headings( $raw );

			$text = trim( (string) preg_replace( '/\s+/', ' ', wp_strip_all_tags( $raw ) ) );

			// A short slice of the body is enough signal for classification.
			$excerpt_limit = $full_content ? self::FULL_CONTENT_LIMIT : self::EXCERPT_LIMIT;
			$body          = self::limit_chars( $text, $excerpt_limit );

			$context = array(
				'scope'     => 'single',
				'type'      => (string) $post->post_type,
				'terms'     => self::post_terms( $post ),
				'title'     => (string) get_the_title( $post ),
				'headings'  => $headings,
				'excerpt'   => has_excerpt( $post ) ? self::limit_chars( wp_strip_all_tags( (string) get_the_excerpt( $post ) ), 300 ) : '',
				'content'   => $body,
				'url'       => (string) get_permalink( $post ),
				'full_mode' => $full_content,
			);

			return $context;
		}

		return array(
			'scope'     => 'site',
			'type'      => 'site',
			'terms'     => '',
			'title'     => (string) get_bloginfo( 'name' ),
			'headings'  => array(),
			'excerpt'   => self::limit_chars( (string) get_bloginfo( 'description' ), 300 ),
			'content'   => '',
			'url'       => (string) home_url( '/' ),
			'full_mode' => $full_content,
		);
	}

	/**
	 * Whether the site owner opted into sending full post content.
	 *
	 * @return bool
	 */
	private static function uses_full_content() {
		$settings = SMSW_Options::get_ai_settings();

		return ! empty( $settings['send_full_content'] );
	}

	/**
	 * Trim a string to a character budget on a word boundary.
	 *
	 * @param string $text  Source text.
	 * @param int    $limit Maximum characters.
	 * @return string
	 */
	private static function limit_chars( $text, $limit ) {
		$text = trim( (string) $text );

		if ( strlen( $text ) <= $limit ) {
			return $text;
		}

		$cut = substr( $text, 0, $limit );
		// Prefer to end on a whole word so the model never sees a half token.
		$space = strrpos( $cut, ' ' );

		if ( false !== $space && $space > 0 ) {
			$cut = substr( $cut, 0, $space );
		}

		return trim( $cut ) . '...';
	}

	/**
	 * Ordered heading outline from post content.
	 *
	 * H1 to H6 are matched in one pass, so the list keeps document order
	 * rather than being grouped by level. A question-shaped heading is the
	 * single strongest signal for FAQPage, which is why the outline is sent
	 * even in the cheap default mode.
	 *
	 * @param string $html Raw post content.
	 * @return array<int,string> Up to 25 headings.
	 */
	private static function extract_headings( $html ) {
		$out = array();

		if ( ! preg_match_all( '#<h([1-6])\b[^>]*>(.*?)</h\1>#is', (string) $html, $matches, PREG_SET_ORDER ) ) {
			return $out;
		}

		foreach ( $matches as $match ) {
			$text = trim( (string) preg_replace( '/\s+/', ' ', wp_strip_all_tags( $match[2] ) ) );

			if ( '' === $text ) {
				continue;
			}

			// The level is kept so the model can see a question under an H2
			// differently from a question at the top level.
			$out[] = 'h' . $match[1] . ': ' . $text;

			if ( count( $out ) >= 25 ) {
				break;
			}
		}

		return $out;
	}

	/**
	 * Primary taxonomy terms for a post, if any are assigned.
	 *
	 * @param WP_Post $post Post object.
	 * @return string
	 */
	private static function post_terms( $post ) {
		if ( ! function_exists( 'get_the_terms' ) || ! isset( $post->ID ) ) {
			return '';
		}

		$taxonomies = get_object_taxonomies( (string) $post->post_type );

		if ( is_wp_error( $taxonomies ) || empty( $taxonomies ) ) {
			return '';
		}

		foreach ( $taxonomies as $taxonomy ) {
			$terms = get_the_terms( $post->ID, $taxonomy );

			if ( is_wp_error( $terms ) || empty( $terms ) ) {
				continue;
			}

			$names = array();

			foreach ( $terms as $term ) {
				$names[] = $term->name;
			}

			if ( ! empty( $names ) ) {
				return $taxonomy . ': ' . implode( ', ', $names );
			}
		}

		return '';
	}

	/**
	 * Type keys the AI may return, minus the ones already in use.
	 *
	 * @param array<int,string> $used Types already present on the content.
	 * @return array<string,string>
	 */
	private static function allowed_types( $used ) {
		$types = SMSW_Schema_Types::get_types();

		unset( $types[ SMSW_Schema_Types::CUSTOM_TYPE ] );

		foreach ( $used as $used_type ) {
			unset( $types[ $used_type ] );
		}

		return $types;
	}

	/**
	 * Instruction text sent to the provider.
	 *
	 * Asks for a short ranked list rather than a single type, so the user gets
	 * a couple of genuinely different options to choose from. The JSON-only
	 * contract is stated as firmly as the single-suggestion version was,
	 * because a model that wraps the array in prose is unusable here.
	 *
	 * @param array<string,mixed>  $context Content summary.
	 * @param array<string,string> $allowed Allowed type keys.
	 * @return string
	 */
	private static function build_prompt( $context, $allowed ) {
		$max   = self::MAX_SUGGESTIONS;
		$lines = array();

		$lines[] = 'You pick schema.org types for the page described below.';
		$lines[] = 'Return up to ' . $max . ' DIFFERENT matching types, best first.';
		$lines[] = 'Answer with one JSON array only. No prose, no explanation, no code fences.';
		$lines[] = 'Format: [{"type":"TYPE","reason":"WHY"},{"type":"TYPE","reason":"WHY"}]';
		$lines[] = 'Each object has exactly two keys: "type" and "reason".';
		$lines[] = 'Every "type" must be one of these exact values: ' . implode( ', ', array_keys( $allowed ) );
		$lines[] = 'Each "reason" is one short sentence, 120 characters maximum, naming the evidence you used.';
		$lines[] = 'The types must be meaningfully different from each other, not variations of one idea.';
		$lines[] = 'If no listed type genuinely fits, return an empty array [] and nothing else.';
		$lines[] = '';

		$lines[] = 'Page scope: ' . $context['scope'];
		$lines[] = 'Post type: ' . $context['type'];

		if ( '' !== $context['terms'] ) {
			$lines[] = 'Category or tag: ' . $context['terms'];
		}

		$lines[] = 'Title: ' . $context['title'];

		if ( ! empty( $context['headings'] ) ) {
			$lines[] = 'Headings, in document order:';
			foreach ( $context['headings'] as $heading ) {
				$lines[] = '  - ' . $heading;
			}
		}

		if ( '' !== $context['excerpt'] ) {
			$lines[] = 'Summary: ' . $context['excerpt'];
		}

		if ( '' !== $context['content'] ) {
			$lines[] = 'Content: ' . $context['content'];
		}

		$lines[] = 'URL: ' . $context['url'];

		return implode( "\n", $lines );
	}

	/**
	 * Send the prompt to the selected provider and return its answer text.
	 *
	 * @param string $provider Provider slug.
	 * @param string $key      Plaintext API key.
	 * @param string $prompt   Instruction text.
	 * @return string|WP_Error
	 */
	private static function request_suggestion( $provider, $key, $prompt ) {
		$system = 'You are a strict schema.org classifier. You answer with the requested JSON array only.';
		$url    = '';
		$body   = array();
		$config = self::model_config( $provider );
		$model  = self::get_selected_model( $provider );

		// Cleared up front so a value left over from an earlier call in the
		// same request can never be read as this answer's finish reason.
		self::$last_finish_reason = '';

		// A provider whose model list cannot be resolved at all still has to
		// send something. Rather than a hardcoded id that can silently rot the
		// way "gemini-2.0-flash" did, the configured default is used.
		if ( '' === $model ) {
			$model = isset( $config['fallbacks'][0] ) ? (string) $config['fallbacks'][0] : '';
		}

		$headers = array( 'content-type' => 'application/json' );

		// Each branch below calls the provider the site owner chose, on that
		// provider's own API host, with the key they saved themselves. The
		// request never passes through a server run by the plugin author,
		// which is the whole point of the BYOK feature, so the AI provider
		// check is switched off for this switch only.
		// phpcs:disable PluginCheck.CodeAnalysis.AIProvider
		switch ( $provider ) {
			case 'anthropic':
				$url  = 'https://api.anthropic.com/v1/messages';
				$body = array(
					'model'      => $model,
					'max_tokens' => self::MAX_OUTPUT_TOKENS,
					'system'     => $system,
					'messages'   => array(
						array(
							'role'    => 'user',
							'content' => $prompt,
						),
					),
				);

				$headers['x-api-key']         = $key;
				$headers['anthropic-version'] = '2023-06-01';
				break;

			case 'gemini':
				// Gemini takes the model as a URL path segment rather than a
				// body field, so the selected id is placed there.
				$path = '' !== $model ? rawurlencode( $model ) : '';
				$url  = 'https://generativelanguage.googleapis.com/v1beta/models/' . $path . ':generateContent?key=' . rawurlencode( $key );
				$body = array(
					'systemInstruction' => array(
						'parts' => array( array( 'text' => $system ) ),
					),
					'contents'          => array(
						array(
							'role'  => 'user',
							'parts' => array( array( 'text' => $prompt ) ),
						),
					),
					'generationConfig'  => array(
						'temperature'     => 0.2,
						'maxOutputTokens' => self::MAX_OUTPUT_TOKENS,
					),
				);

				$thinking = self::gemini_thinking_config( $model );

				if ( null !== $thinking ) {
					$body['generationConfig']['thinkingConfig'] = $thinking;
				}
				break;

			case 'deepseek':
				$url                      = 'https://api.deepseek.com/chat/completions';
				$body                     = self::chat_body( $model, $system, $prompt );
				$headers['Authorization'] = 'Bearer ' . $key;
				break;

			case 'groq':
				$url                      = 'https://api.groq.com/openai/v1/chat/completions';
				$body                     = self::chat_body( $model, $system, $prompt );
				$headers['Authorization'] = 'Bearer ' . $key;
				break;

			case 'openrouter':
				$url                      = 'https://openrouter.ai/api/v1/chat/completions';
				$body                     = self::chat_body( $model, $system, $prompt );
				$headers['Authorization'] = 'Bearer ' . $key;
				$headers['HTTP-Referer']  = home_url( '/' );
				$headers['X-Title']       = 'SHIED WORLD Schema Markup';
				break;

			case 'openai':
			default:
				$url                      = 'https://api.openai.com/v1/chat/completions';
				$body                     = self::chat_body( $model, $system, $prompt );
				$headers['Authorization'] = 'Bearer ' . $key;
				break;
		}

		// phpcs:enable PluginCheck.CodeAnalysis.AIProvider

		/**
		 * Fires immediately before the provider request is sent.
		 *
		 * Carries the provider, the resolved model id, the URL and the body so
		 * a site owner can confirm the model selection is really in use, and
		 * what context is really sent, without reading the source.
		 *
		 * @param string              $provider Provider slug.
		 * @param string              $model    Resolved model id.
		 * @param string              $url      Request URL.
		 * @param array<string,mixed> $body     Request body.
		 */
		do_action( 'smsw_ai_request', $provider, $model, $url, $body );

		$response = wp_remote_post(
			$url,
			array(
				'timeout' => 30,
				'headers' => $headers,
				'body'    => wp_json_encode( $body ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'smsw_ai_http', __( 'AI request failed. Check your key, quota, and try again.', 'shied-world-schema-markup' ) );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$raw  = (string) wp_remote_retrieve_body( $response );
		$data = json_decode( $raw, true );

		// Recorded so parse_suggestion() can tell a cut-off answer apart from a
		// model that genuinely had nothing to offer.
		self::$last_finish_reason = self::extract_finish_reason( $provider, $data );

		if ( $code < 200 || $code >= 300 ) {
			$detail = self::provider_error_detail( $data );

			if ( '' !== $detail ) {
				return new WP_Error(
					'smsw_ai_provider',
					sprintf(
						/* translators: %s: provider error message. */
						__( 'AI request failed: %s', 'shied-world-schema-markup' ),
						$detail
					)
				);
			}

			return new WP_Error( 'smsw_ai_provider', __( 'AI provider returned an error. Check your API key and quota.', 'shied-world-schema-markup' ) );
		}

		$text = self::extract_text( $provider, $data );

		if ( '' === $text ) {
			return new WP_Error( 'smsw_ai_empty', __( 'AI returned an empty response.', 'shied-world-schema-markup' ) );
		}

		return $text;
	}

	/**
	 * thinkingConfig for a Gemini model, or null when the model has no thinking
	 * support and must not be sent the field at all.
	 *
	 * Gemini counts thinking tokens against maxOutputTokens, so a reasoning
	 * model left uncapped can spend the entire budget reasoning and stop before
	 * writing any answer. Google documents minimal thinking as correct for a
	 * classification task, which is exactly what this is, so thinking is turned
	 * off where the model allows it.
	 *
	 * Two models do not accept the field: the pre 2.5 Flash models reject
	 * thinkingConfig outright, and the 2.5 Pro models refuse a budget of zero.
	 * An unknown id is left uncapped rather than risking a rejected request,
	 * because the larger output ceiling still protects those models.
	 *
	 * @param string $model Resolved Gemini model id.
	 * @return array<string,int|bool>|null
	 */
	private static function gemini_thinking_config( $model ) {
		$model = strtolower( (string) $model );

		if ( '' === $model ) {
			return null;
		}

		// Pre 2.5 Flash models have no thinking stage and reject the field.
		if ( false === strpos( $model, '2.5' ) && 0 !== strpos( $model, 'gemini-3' ) ) {
			return null;
		}

		// Pro models refuse a zero budget and need a small positive one.
		if ( false !== strpos( $model, 'pro' ) ) {
			return array(
				'thinkingBudget'  => self::GEMINI_MIN_THINKING_BUDGET,
				'includeThoughts' => false,
			);
		}

		return array(
			'thinkingBudget'  => self::GEMINI_THINKING_BUDGET,
			'includeThoughts' => false,
		);
	}

	/**
	 * OpenAI compatible chat payload.
	 *
	 * @param string $model  Model name.
	 * @param string $system System instruction.
	 * @param string $prompt User instruction.
	 * @return array<string,mixed>
	 */
	private static function chat_body( $model, $system, $prompt ) {
		return array(
			'model'       => $model,
			'temperature' => 0.2,
			'max_tokens'  => self::MAX_OUTPUT_TOKENS,
			'messages'    => array(
				array(
					'role'    => 'system',
					'content' => $system,
				),
				array(
					'role'    => 'user',
					'content' => $prompt,
				),
			),
		);
	}

	/**
	 * Human readable detail from a provider error payload.
	 *
	 * @param mixed $data Decoded response body.
	 * @return string
	 */
	private static function provider_error_detail( $data ) {
		if ( ! is_array( $data ) ) {
			return '';
		}

		if ( isset( $data['error']['message'] ) && is_string( $data['error']['message'] ) ) {
			return $data['error']['message'];
		}

		if ( isset( $data['error'] ) && is_string( $data['error'] ) ) {
			return $data['error'];
		}

		if ( isset( $data['message'] ) && is_string( $data['message'] ) ) {
			return $data['message'];
		}

		return '';
	}

	/**
	 * Assistant text from a decoded provider response.
	 *
	 * @param string $provider Provider slug.
	 * @param mixed  $data     Decoded response body.
	 * @return string
	 */
	private static function extract_text( $provider, $data ) {
		if ( ! is_array( $data ) ) {
			return '';
		}

		if ( 'anthropic' === $provider ) {
			if ( isset( $data['content'][0]['text'] ) && is_string( $data['content'][0]['text'] ) ) {
				return trim( $data['content'][0]['text'] );
			}

			return '';
		}

		if ( 'gemini' === $provider ) {
			if ( isset( $data['candidates'][0]['content']['parts'][0]['text'] ) && is_string( $data['candidates'][0]['content']['parts'][0]['text'] ) ) {
				return trim( $data['candidates'][0]['content']['parts'][0]['text'] );
			}

			return '';
		}

		if ( isset( $data['choices'][0]['message']['content'] ) && is_string( $data['choices'][0]['message']['content'] ) ) {
			return trim( $data['choices'][0]['message']['content'] );
		}

		return '';
	}

	/**
	 * Why the provider stopped generating, normalised across providers.
	 *
	 * @param string              $provider Provider slug.
	 * @param array<string,mixed> $data    Decoded provider response.
	 * @return string Reason string, or '' when the provider reported none.
	 */
	private static function extract_finish_reason( $provider, $data ) {
		if ( ! is_array( $data ) ) {
			return '';
		}

		// Gemini.
		if ( isset( $data['candidates'][0]['finishReason'] ) ) {
			return (string) $data['candidates'][0]['finishReason'];
		}

		// Anthropic.
		if ( isset( $data['stop_reason'] ) ) {
			return (string) $data['stop_reason'];
		}

		// OpenAI and every provider that copies its shape.
		if ( isset( $data['choices'][0]['finish_reason'] ) ) {
			return (string) $data['choices'][0]['finish_reason'];
		}

		unset( $provider );

		return '';
	}

	/**
	 * Whether the last answer was cut off rather than finished.
	 *
	 * A reasoning model that runs out of budget stops mid sentence, and the
	 * partial text decodes to null. Reporting that as "nothing fits" is what
	 * made an empty page look already covered, so the two are now separate.
	 *
	 * @return bool
	 */
	private static function last_reply_was_truncated() {
		$reason = strtolower( (string) self::$last_finish_reason );

		if ( '' === $reason ) {
			return false;
		}

		return in_array( $reason, array( 'max_tokens', 'length', 'max_output_tokens' ), true );
	}

	/**
	 * User facing message for a cut-off answer.
	 *
	 * @return string
	 */
	private static function truncation_message() {
		return __( 'The AI response was cut off before it finished. Try again, or choose a model with a larger output limit.', 'shied-world-schema-markup' );
	}

	/**
	 * Turn the assistant answer into validated suggestions.
	 *
	 * Accepts the array form the prompt asks for, and tolerates the two shapes
	 * models drift into: a lone object wrapped in an array, and a bare object.
	 * Anything not on the allow list is dropped rather than passed on, and the
	 * result is de-duplicated so the same type cannot appear twice.
	 *
	 * An empty array is a valid, meaningful answer: the model was asked to say
	 * "nothing new fits", which the UI reports as good coverage rather than as
	 * a failure. Only a response with no usable JSON at all is an error.
	 *
	 * @param string               $text    Assistant text.
	 * @param array<string,string> $allowed Allowed type keys.
	 * @return array<int,array{type:string,reason:string}>|WP_Error
	 */
	private static function parse_suggestion( $text, $allowed ) {
		$clean = trim( (string) $text );
		$clean = (string) preg_replace( '/^```[a-zA-Z]*\s*/', '', $clean );
		$clean = (string) preg_replace( '/```\s*$/', '', $clean );
		$clean = trim( $clean );

		$json = self::decode_first_json( $clean );

		// Normalise every accepted shape down to a list of objects.
		$rows = array();

		if ( is_array( $json ) ) {
			if ( isset( $json['type'] ) ) {
				$rows = array( $json );
			} else {
				$rows = $json;
			}
		}

		$out = array();

		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) || ! isset( $row['type'] ) || ! is_string( $row['type'] ) ) {
				continue;
			}

			$type = self::match_allowed_type( $row['type'], $allowed );

			if ( '' === $type ) {
				continue;
			}

			// Never show the same type twice, however the model phrased it.
			foreach ( $out as $seen ) {
				if ( 0 === strcasecmp( $seen['type'], $type ) ) {
					continue 2;
				}
			}

			$reason = isset( $row['reason'] ) && is_string( $row['reason'] ) ? $row['reason'] : '';

			$out[] = array(
				'type'   => $type,
				'reason' => self::limit_text( $reason ),
			);

			if ( count( $out ) >= self::MAX_SUGGESTIONS ) {
				break;
			}
		}

		// A real, well formed answer. Anything below here means the reply could
		// not be trusted, and the three outcomes are kept strictly apart:
		// a genuine empty array is a valid answer, a cut-off answer is a token
		// problem, and anything else is a parse problem.
		if ( ! empty( $out ) ) {
			return $out;
		}

		// The model returned a well formed but empty JSON array. That is its
		// explicit "nothing here fits" signal, so it is a real answer and the
		// builder reports the page as already covered.
		//
		// The is_array() test is what separates this from a broken reply: a
		// truncated answer decodes to null, never to an empty array, so it can
		// no longer be mistaken for "already covered".
		if ( is_array( $json ) && empty( $rows ) ) {
			return array();
		}

		// The model returned content but nothing in it survived validation.
		// Reporting that as "no suggestions" would tell the user their page is
		// well covered when in fact the reply was unusable, so it is surfaced
		// as a retryable error instead.
		//
		// No JSON at all means some models answered with bare type names, so
		// those are matched directly before giving up.
		if ( null === $json ) {
			// A cut-off answer is reported as what it is. Before this, an
			// unfinished reply fell through to the "already covered" message,
			// which told the user their empty page was fully described.
			if ( self::last_reply_was_truncated() ) {
				return new WP_Error( 'smsw_ai_truncated', self::truncation_message() );
			}

			foreach ( array_keys( $allowed ) as $candidate ) {
				if ( preg_match( '/\b' . preg_quote( (string) $candidate, '/' ) . '\b/', $clean ) ) {
					return array(
						array(
							'type'   => (string) $candidate,
							'reason' => '',
						),
					);
				}
			}
		}

		// No JSON and no finish reason pointing at a cut-off reply, so this is a
		// genuine parse failure rather than a token problem.
		return new WP_Error( 'smsw_ai_parse', __( 'Could not parse the AI suggestion. Try again.', 'shied-world-schema-markup' ) );
	}

	/**
	 * Decode the first JSON value in a model answer.
	 *
	 * Models wrap JSON in prose or fences often enough that a plain
	 * json_decode on the whole string is not reliable. Brackets are located by
	 * scanning from the first [ or { and matching the closing one, so an
	 * unbalanced tail (a truncated array, a trailing sentence) still yields the
	 * valid part.
	 *
	 * @param string $text Assistant text.
	 * @return array<int|string,mixed>|null Null when no JSON is present.
	 */
	private static function decode_first_json( $text ) {
		$text = (string) $text;

		// Fast path: the whole answer is valid JSON.
		$decoded = json_decode( $text, true );

		if ( is_array( $decoded ) ) {
			return $decoded;
		}

		$start = strcspn( $text, '[{' );
		$open  = substr( $text, $start, 1 );

		if ( '' === $open || false === strpos( $text, $open ) ) {
			return null;
		}

		$close = ( '[' === $open ) ? ']' : '}';
		$depth = 0;
		$len   = strlen( $text );
		$in_str = false;
		$escaped = false;

		for ( $i = $start; $i < $len; $i++ ) {
			$char = $text[ $i ];

			if ( $in_str ) {
				if ( $escaped ) {
					$escaped = false;
				} elseif ( '\\' === $char ) {
					$escaped = true;
				} elseif ( '"' === $char ) {
					$in_str = false;
				}
				continue;
			}

			if ( '"' === $char ) {
				$in_str = true;
				continue;
			}

			if ( $char === $open ) {
				++$depth;
			} elseif ( $char === $close ) {
				--$depth;

				if ( 0 === $depth ) {
					$slice = substr( $text, $start, $i - $start + 1 );
					$out   = json_decode( $slice, true );

					return is_array( $out ) ? $out : null;
				}
			}
		}

		return null;
	}

	/**
	 * Case insensitive match of a returned type against the allowed keys.
	 *
	 * @param string               $returned Returned type value.
	 * @param array<string,string> $allowed  Allowed type keys.
	 * @return string Matched key, or an empty string when nothing matches.
	 */
	private static function match_allowed_type( $returned, $allowed ) {
		$returned = trim( (string) $returned );

		if ( isset( $allowed[ $returned ] ) ) {
			return $returned;
		}

		foreach ( array_keys( $allowed ) as $candidate ) {
			if ( 0 === strcasecmp( (string) $candidate, $returned ) ) {
				return (string) $candidate;
			}
		}

		return '';
	}

	/**
	 * Trim a short reason field.
	 *
	 * @param string $text Raw reason.
	 * @return string
	 */
	private static function limit_text( $text ) {
		$text = wp_strip_all_tags( (string) $text );
		$text = trim( (string) preg_replace( '/\s+/', ' ', $text ) );

		if ( strlen( $text ) > 200 ) {
			$text = substr( $text, 0, 200 );
		}

		return $text;
	}
}
