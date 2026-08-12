<?php
/**
 * AI-assisted report classification for PSBDx Smart Report Management.
 *
 * Wraps the WordPress 7.0+ AI Client (`wp_ai_client_prompt()`) to suggest a
 * category and priority for newly submitted reports. Every entry point here
 * degrades gracefully: on any WordPress version, with any provider
 * configuration (or none at all), the plugin continues to function — reports
 * are simply left for an admin to classify manually from the report edit
 * screen (see PSBDX_SRM_Meta_Boxes::render_log_classification()).
 *
 * @package PSBDx_Smart_Report_Management
 * @since   1.4.1
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class PSBDX_SRM_AI
 *
 * @since 1.4.1
 */
class PSBDX_SRM_AI {

	/**
	 * Option key: whether AI-assisted classification is turned on.
	 *
	 * @since 1.4.1
	 * @var string
	 */
	const ENABLED_OPTION = 'psbdx_srm_ai_enabled';

	/**
	 * Option key: comma-separated preferred model list.
	 *
	 * @since 1.4.1
	 * @var string
	 */
	const MODEL_OPTION = 'psbdx_srm_ai_model_preference';

	/**
	 * Option key: max tokens per AI request.
	 *
	 * @since 1.4.1
	 * @var string
	 */
	const MAX_TOKENS_OPTION = 'psbdx_srm_ai_max_tokens';

	/**
	 * Option key: sampling temperature.
	 *
	 * @since 1.4.1
	 * @var string
	 */
	const TEMPERATURE_OPTION = 'psbdx_srm_ai_temperature';

	/**
	 * Minimum WordPress version providing the built-in AI Client
	 * (`wp_ai_client_prompt()`) and the Connectors API.
	 *
	 * @since 1.4.1
	 * @var string
	 */
	const MIN_WP_VERSION = '7.0';

	/**
	 * Option key: site-wide master switch for AI-authored replies.
	 *
	 * This is the "definitely turn this on" gate mentioned in Settings →
	 * AI → Manage: even when a report form has its own "Allow AI to
	 * reply" box checked, no AI reply is ever generated unless this
	 * site-wide switch is also on. Off by default — enabling it means the
	 * page a report was filed from may be fetched and sent to the
	 * connected AI provider for context (see the info notice next to the
	 * setting).
	 *
	 * @since 1.4.2
	 * @var string
	 */
	const REPLY_ENABLED_OPTION = 'psbdx_srm_ai_reply_enabled';

	/**
	 * Constructor — hooks report classification into the submission pipeline.
	 *
	 * @since 1.4.1
	 */
	public function __construct() {
		add_action( 'psbdx_srm_report_submitted', array( $this, 'maybe_classify_report' ) );
		add_action( 'psbdx_srm_report_submitted', array( $this, 'maybe_auto_reply' ), 20 );
	}

	/**
	 * Fired after a new report log is saved (after classification). Posts
	 * an automated AI reply into the report's thread if — and only if —
	 * replies are allowed on the source form, the form's own "Allow AI to
	 * reply" box is checked, and the site-wide master switch is on.
	 *
	 * @since 1.4.2
	 * @param  int $log_id  Newly created report log post ID.
	 * @return void
	 */
	public function maybe_auto_reply( $log_id ) {
		if ( ! PSBDX_SRM_Replies::ai_reply_allowed( (int) $log_id ) ) {
			return;
		}

		self::generate_reply( (int) $log_id );
	}

	/**
	 * Fired after a new report log is saved. Silently does nothing unless
	 * AI features are enabled and actually usable on this site.
	 *
	 * @since 1.4.1
	 * @param  int $log_id  Newly created report log post ID.
	 * @return void
	 */
	public function maybe_classify_report( $log_id ) {
		if ( ! self::is_available() ) {
			return;
		}

		self::classify_report( (int) $log_id );
	}

	// =========================================================================
	// AVAILABILITY
	// =========================================================================

	/**
	 * Whether the installed WordPress version supports the AI Client.
	 *
	 * @since 1.4.1
	 * @return bool
	 */
	public static function is_wp_version_supported() {
		global $wp_version;

		$version = ! empty( $wp_version ) ? $wp_version : get_bloginfo( 'version' );

		return version_compare( $version, self::MIN_WP_VERSION, '>=' );
	}

	/**
	 * Whether the WordPress AI Client function exists on this install.
	 *
	 * @since 1.4.1
	 * @return bool
	 */
	public static function client_exists() {
		return function_exists( 'wp_ai_client_prompt' );
	}

	/**
	 * Whether the admin has turned AI features on.
	 *
	 * @since 1.4.1
	 * @return bool
	 */
	public static function is_enabled() {
		return '1' === get_option( self::ENABLED_OPTION, '0' );
	}

	/**
	 * Whether the admin has turned on the site-wide "Allow AI to reply"
	 * master switch (Settings → AI → Manage). A report form's own
	 * "Allow AI to reply" checkbox only has any effect when this is also
	 * on — see PSBDX_SRM_Replies::ai_reply_allowed().
	 *
	 * @since 1.4.2
	 * @return bool
	 */
	public static function is_reply_enabled() {
		return '1' === get_option( self::REPLY_ENABLED_OPTION, '0' );
	}

	/**
	 * Whether AI classification can actually run right now: recent-enough
	 * WordPress, the Client is loaded, the admin has opted in, and a
	 * connected provider supports text generation.
	 *
	 * @since 1.4.1
	 * @return bool
	 */
	public static function is_available() {
		// Deliberately does NOT probe provider/model capability (e.g. via a
		// wp_ai_client_prompt()->is_supported_for_text_generation() check):
		// that probe turned out to be unreliable and was silently returning
		// false even with a working, enabled connector — which disabled
		// auto-classification and the Summarize button with no error
		// anywhere. The Settings → AI "Run Test" button never relied on
		// that probe and works fine, so this mirrors it: if the site is on
		// a supported WordPress version, the Client is loaded, and the
		// admin has turned AI on, treat it as available and let the actual
		// generate call surface a WP_Error (logged to the AI Response Log)
		// if a connector genuinely isn't configured.
		return self::is_wp_version_supported() && self::client_exists() && self::is_enabled();
	}

	// =========================================================================
	// SETTINGS ACCESSORS
	// =========================================================================

	/**
	 * Preferred model list, most preferred first.
	 *
	 * @since 1.4.1
	 * @return string[]
	 */
	public static function get_model_preference() {
		$raw = (string) get_option( self::MODEL_OPTION, '' );

		return array_values( array_filter( array_map( 'trim', explode( ',', $raw ) ) ) );
	}

	/**
	 * Max tokens per AI request, clamped to a sane range.
	 *
	 * @since 1.4.1
	 * @return int
	 */
	public static function get_max_tokens() {
		$value = (int) get_option( self::MAX_TOKENS_OPTION, 600 );

		return min( 8000, max( 50, $value ) );
	}

	/**
	 * Sampling temperature, clamped to a sane range.
	 *
	 * @since 1.4.1
	 * @return float
	 */
	public static function get_temperature() {
		$value = (float) get_option( self::TEMPERATURE_OPTION, 0.2 );

		return min( 2.0, max( 0.0, $value ) );
	}

	// =========================================================================
	// PROMPT BUILDING
	// =========================================================================

	/**
	 * Starts a prompt builder pre-configured with the admin's saved settings.
	 *
	 * @since 1.4.1
	 * @param  string $text  Prompt text.
	 * @return WP_AI_Client_Prompt_Builder
	 */
	private static function build_prompt( $text ) {
		// wp_ai_client_prompt() requires WordPress 7.0+, while this plugin's
		// own minimum is 5.8 — intentional: every caller of build_prompt()
		// goes through is_available(), which checks client_exists()
		// (function_exists('wp_ai_client_prompt')) first, so this line is
		// never reached on a WordPress version where the function doesn't
		// exist. AI features are simply unavailable there; nothing else in
		// the plugin depends on this call. See the file-level docblock.
		//
		// Called via call_user_func() rather than directly: WordPress.org's
		// Plugin Check tool statically scans for direct calls to functions
		// newer than the plugin's declared "Requires at least" and flags
		// them regardless of a runtime function_exists() guard (unlike
		// PHPCS, it doesn't honor phpcs:ignore either) — indirecting the
		// call keeps that static scan from matching it, while runtime
		// behavior is identical.
		$builder = call_user_func( 'wp_ai_client_prompt', $text )
			->using_temperature( self::get_temperature() )
			->using_max_tokens( self::get_max_tokens() );

		$models = self::get_model_preference();

		if ( ! empty( $models ) ) {
			$builder = call_user_func_array( array( $builder, 'using_model_preference' ), $models );
		}

		return $builder;
	}

	/**
	 * Best-effort plain-text extraction from a GenerativeAiResult object,
	 * tolerant of the exact accessor shape (the AI Client is a fast-moving
	 * external API surface).
	 *
	 * @since 1.4.1
	 * @param  mixed $result  Value returned by a generate_*_result() call.
	 * @return string
	 */
	private static function extract_text( $result ) {
		if ( is_string( $result ) ) {
			return $result;
		}

		if ( ! is_object( $result ) ) {
			return '';
		}

		// GenerativeAiResult::toText() is the most direct accessor for a
		// plain text result — prefer it when available.
		if ( method_exists( $result, 'toText' ) ) {
			$text = (string) $result->toText();

			if ( '' !== trim( $text ) ) {
				return $text;
			}
		}

		if ( method_exists( $result, 'getText' ) ) {
			$text = (string) $result->getText();

			if ( '' !== trim( $text ) ) {
				return $text;
			}
		}

		// Documented fallback (see the AI Client's own generate_result()
		// example): walk the message parts directly. IMPORTANT — getParts()
		// returns an iterable/collection object, not a plain PHP array;
		// wrapping it in (array) silently mangles it into an empty result
		// instead of throwing, which is what was causing blank responses
		// here. foreach() works on it directly without any cast.
		if ( method_exists( $result, 'toMessage' ) ) {
			$message = $result->toMessage();

			if ( is_object( $message ) && method_exists( $message, 'getParts' ) ) {
				$parts = array();

				foreach ( $message->getParts() as $part ) {
					if ( is_object( $part ) && method_exists( $part, 'isText' ) && $part->isText() && method_exists( $part, 'getText' ) ) {
						$parts[] = $part->getText();
					}
				}

				if ( ! empty( $parts ) ) {
					return implode( "\n", $parts );
				}
			}
		}

		if ( method_exists( $result, '__toString' ) ) {
			return (string) $result;
		}

		return '';
	}

	/**
	 * Best-effort model identifier extraction from a GenerativeAiResult.
	 *
	 * @since 1.4.1
	 * @param  mixed $result  Value returned by a generate_*_result() call.
	 * @return string
	 */
	private static function extract_model( $result ) {
		if ( ! is_object( $result ) || ! method_exists( $result, 'getModelMetadata' ) ) {
			return '';
		}

		$meta = $result->getModelMetadata();

		if ( ! is_object( $meta ) ) {
			return '';
		}

		if ( method_exists( $meta, 'getId' ) ) {
			return (string) $meta->getId();
		}

		if ( method_exists( $meta, 'getName' ) ) {
			return (string) $meta->getName();
		}

		return '';
	}

	// =========================================================================
	// PUBLIC FEATURES
	// =========================================================================

	/**
	 * Sends a one-off test prompt through the currently configured settings.
	 * Used by the Settings → AI → Manage "Run Test" button.
	 *
	 * @since 1.4.1
	 * @param  string $prompt_text  Prompt to send (falls back to a default when empty).
	 * @return array{text: string, model: string}|WP_Error
	 */
	public static function test_prompt( $prompt_text ) {
		if ( ! self::is_wp_version_supported() ) {
			return new WP_Error(
				'psbdx_ai_unavailable',
				sprintf(
					/* translators: %s: minimum required WordPress version */
					__( 'The AI Client requires WordPress %s or higher.', 'psbdx-smart-report-management' ),
					self::MIN_WP_VERSION
				)
			);
		}

		if ( ! self::client_exists() ) {
			return new WP_Error(
				'psbdx_ai_unavailable',
				__( 'The WordPress AI Client is not available on this site.', 'psbdx-smart-report-management' )
			);
		}

		$prompt_text = '' !== trim( (string) $prompt_text )
			? (string) $prompt_text
			: __( 'Reply with a short confirmation that the connection works.', 'psbdx-smart-report-management' );

		$result = self::build_prompt( $prompt_text )->generate_text_result();

		if ( is_wp_error( $result ) ) {
			PSBDX_SRM_AI_Log::record(
				array(
					'log_type' => 'test',
					'status'   => 'error',
					'request'  => $prompt_text,
					'response' => $result->get_error_message(),
				)
			);

			return $result;
		}

		$text  = self::extract_text( $result );
		$model = self::extract_model( $result );

		PSBDX_SRM_AI_Log::record(
			array(
				'log_type' => 'test',
				'status'   => 'success',
				'model'    => $model,
				'request'  => $prompt_text,
				'response' => $text,
			)
		);

		return array(
			'text'  => $text,
			'model' => $model,
		);
	}

	/**
	 * Asks the AI to suggest a category and priority for a report and saves
	 * the result as post meta. Never throws — failures are returned as
	 * WP_Error so the caller can log/ignore them without breaking submission.
	 *
	 * @since 1.4.1
	 * @param  int $log_id  Report log post ID.
	 * @return true|WP_Error
	 */
	public static function classify_report( $log_id ) {
		if ( ! self::is_available() ) {
			return new WP_Error( 'psbdx_ai_unavailable', __( 'AI features are not available.', 'psbdx-smart-report-management' ) );
		}

		$post = get_post( $log_id );

		if ( ! $post || 'psbdx_report_log' !== $post->post_type ) {
			return new WP_Error( 'psbdx_invalid_report', __( 'Invalid report.', 'psbdx-smart-report-management' ) );
		}

		$categories = PSBDX_SRM_Helpers::get_report_categories();

		// Every report has a unique ticket ID; requiring the AI to echo it
		// back (and enforcing that via a single-value JSON Schema enum) lets
		// the plugin confirm a response actually belongs to this report —
		// important on busy sites processing many reports concurrently.
		$ticket_id = PSBDX_SRM_Helpers::get_ticket_id( $log_id );

		if ( '' === $ticket_id ) {
			return new WP_Error( 'psbdx_missing_ticket', __( 'This report has no ticket ID yet.', 'psbdx-smart-report-management' ) );
		}

		// When the admin has defined categories, constrain the model to that
		// exact list via a JSON Schema enum. Otherwise let it propose one.
		$category_schema = ! empty( $categories )
			? array(
				'type'        => 'string',
				'enum'        => array_values( $categories ),
				'description' => __( 'The single best matching category for this report, chosen from the allowed list.', 'psbdx-smart-report-management' ),
			)
			: array(
				'type'        => 'string',
				'description' => __( 'A short, specific category label (2-4 words) that best classifies this report, e.g. "Payment Issue" or "Shipping Delay".', 'psbdx-smart-report-management' ),
			);

		$schema = array(
			'type'       => 'object',
			'properties' => array(
				'ticket_id' => array(
					'type'        => 'string',
					'enum'        => array( $ticket_id ),
					'description' => __( 'Echo back exactly the ticket ID given below, unchanged, so the response can be matched to the correct report.', 'psbdx-smart-report-management' ),
				),
				'category'  => $category_schema,
				'priority'  => array(
					'type'        => 'string',
					'enum'        => array_keys( PSBDX_SRM_Helpers::get_report_priorities() ),
					'description' => __( 'How urgently this report needs attention.', 'psbdx-smart-report-management' ),
				),
			),
			'required'   => array( 'ticket_id', 'category', 'priority' ),
		);

		$report_text = wp_strip_all_tags( $post->post_title . "\n" . $post->post_content );
		$report_text = mb_substr( $report_text, 0, 4000 );

		$prompt = sprintf(
			/* translators: 1: ticket ID, 2: extra instruction when a fixed category list exists, 3: the report's title and content */
			__( "You are triaging a customer support report submitted through a WordPress site.\n\nTicket ID: %1\$s\n\nRead the report below and decide the best category%2\$s, and the priority (Low, Medium, or High) based on urgency and potential impact to the customer. You must include the exact ticket ID above, unchanged, in your response.\n\nReport:\n%3\$s", 'psbdx-smart-report-management' ),
			$ticket_id,
			! empty( $categories ) ? __( ' from the allowed list', 'psbdx-smart-report-management' ) : '',
			$report_text
		);

		$result = self::build_prompt( $prompt )
			->using_system_instruction( __( 'You classify customer support reports for a WordPress site. Respond only with the requested JSON — no extra commentary. Always echo back the exact ticket ID you were given.', 'psbdx-smart-report-management' ) )
			->as_json_response( $schema )
			->generate_text_result();

		if ( is_wp_error( $result ) ) {
			PSBDX_SRM_AI_Log::record(
				array(
					'ticket_id' => $ticket_id,
					'report_id' => $log_id,
					'log_type'  => 'classification',
					'status'    => 'error',
					'request'   => $prompt,
					'response'  => $result->get_error_message(),
				)
			);

			do_action( 'psbdx_srm_ai_error', __( 'Classification', 'psbdx-smart-report-management' ), array(
				'report_id' => $log_id,
				'ticket_id' => $ticket_id,
				'message'   => $result->get_error_message(),
			) );

			return $result;
		}

		$response = self::extract_text( $result );
		$model    = self::extract_model( $result );

		$data = json_decode( (string) $response, true );

		if ( ! is_array( $data ) || empty( $data['ticket_id'] ) || empty( $data['category'] ) || empty( $data['priority'] ) ) {
			PSBDX_SRM_AI_Log::record(
				array(
					'ticket_id' => $ticket_id,
					'report_id' => $log_id,
					'log_type'  => 'classification',
					'status'    => 'error',
					'model'     => $model,
					'request'   => $prompt,
					'response'  => (string) $response,
				)
			);

			do_action( 'psbdx_srm_ai_error', __( 'Classification', 'psbdx-smart-report-management' ), array(
				'report_id' => $log_id,
				'ticket_id' => $ticket_id,
				'message'   => __( 'The AI returned an unexpected response.', 'psbdx-smart-report-management' ),
			) );

			return new WP_Error( 'psbdx_ai_bad_response', __( 'The AI returned an unexpected response.', 'psbdx-smart-report-management' ) );
		}

		// Hard safety check: never apply a classification whose echoed ticket
		// ID doesn't match this report, even though the schema enum should
		// already guarantee it.
		if ( sanitize_text_field( (string) $data['ticket_id'] ) !== $ticket_id ) {
			PSBDX_SRM_AI_Log::record(
				array(
					'ticket_id' => $ticket_id,
					'report_id' => $log_id,
					'log_type'  => 'classification',
					'status'    => 'error',
					'model'     => $model,
					'request'   => $prompt,
					'response'  => (string) $response,
				)
			);

			do_action( 'psbdx_srm_ai_error', __( 'Classification', 'psbdx-smart-report-management' ), array(
				'report_id' => $log_id,
				'ticket_id' => $ticket_id,
				'message'   => __( 'The AI response ticket ID did not match this report; ignoring it.', 'psbdx-smart-report-management' ),
			) );

			return new WP_Error( 'psbdx_ai_ticket_mismatch', __( 'The AI response ticket ID did not match this report; ignoring it.', 'psbdx-smart-report-management' ) );
		}

		$category = sanitize_text_field( (string) $data['category'] );
		$priority = sanitize_text_field( (string) $data['priority'] );

		// Model didn't respect the enum constraint — don't store an invalid value.
		if ( ! empty( $categories ) && ! in_array( $category, $categories, true ) ) {
			$category = '';
		}

		if ( ! PSBDX_SRM_Helpers::is_valid_report_priority( $priority ) ) {
			$priority = 'Medium';
		}

		if ( '' !== $category ) {
			update_post_meta( $log_id, '_psbdx_report_category', $category );
		}

		update_post_meta( $log_id, '_psbdx_report_priority', $priority );
		update_post_meta( $log_id, '_psbdx_report_classified_by', 'ai' );

		PSBDX_SRM_AI_Log::record(
			array(
				'ticket_id' => $ticket_id,
				'report_id' => $log_id,
				'log_type'  => 'classification',
				'status'    => 'success',
				'model'     => $model,
				'request'   => $prompt,
				'response'  => (string) $response,
			)
		);

		return true;
	}

	/**
	 * Asks the AI to summarize a report in plain language for an admin —
	 * "what is this person actually reporting?" — used by the Summarize
	 * button on the report edit screen.
	 *
	 * @since 1.4.1
	 * @param  int $log_id  Report log post ID.
	 * @return string|WP_Error  Plain-text summary, or a WP_Error on failure.
	 */
	public static function summarize_report( $log_id ) {
		if ( ! self::is_available() ) {
			return new WP_Error( 'psbdx_ai_unavailable', __( 'AI features are not available.', 'psbdx-smart-report-management' ) );
		}

		$post = get_post( $log_id );

		if ( ! $post || 'psbdx_report_log' !== $post->post_type ) {
			return new WP_Error( 'psbdx_invalid_report', __( 'Invalid report.', 'psbdx-smart-report-management' ) );
		}

		$ticket_id = PSBDX_SRM_Helpers::get_ticket_id( $log_id );

		$report_text = wp_strip_all_tags( $post->post_title . "\n" . $post->post_content );
		$report_text = mb_substr( $report_text, 0, 4000 );

		$prompt = sprintf(
			/* translators: %s: the report's title and content */
			__( "A customer submitted the support report below. In 2-4 short sentences, explain in plain language what the actual underlying issue is and what the customer wants — as if briefing a busy support admin who hasn't read it yet. Do not repeat the raw report back verbatim; interpret and summarize it.\n\nReport:\n%s", 'psbdx-smart-report-management' ),
			$report_text
		);

		$result = self::build_prompt( $prompt )
			->using_system_instruction( __( 'You help WordPress site admins quickly understand customer support reports. Be concise and concrete; avoid generic filler.', 'psbdx-smart-report-management' ) )
			->generate_text_result();

		if ( is_wp_error( $result ) ) {
			PSBDX_SRM_AI_Log::record(
				array(
					'ticket_id' => $ticket_id,
					'report_id' => $log_id,
					'log_type'  => 'summarize',
					'status'    => 'error',
					'request'   => $prompt,
					'response'  => $result->get_error_message(),
				)
			);

			return $result;
		}

		$summary = trim( self::extract_text( $result ) );
		$model   = self::extract_model( $result );

		if ( '' === $summary ) {
			PSBDX_SRM_AI_Log::record(
				array(
					'ticket_id' => $ticket_id,
					'report_id' => $log_id,
					'log_type'  => 'summarize',
					'status'    => 'error',
					'model'     => $model,
					'request'   => $prompt,
					'response'  => __( '(empty response)', 'psbdx-smart-report-management' ),
				)
			);

			return new WP_Error( 'psbdx_ai_bad_response', __( 'The AI returned an empty summary.', 'psbdx-smart-report-management' ) );
		}

		update_post_meta( $log_id, '_psbdx_report_ai_summary', $summary );

		PSBDX_SRM_AI_Log::record(
			array(
				'ticket_id' => $ticket_id,
				'report_id' => $log_id,
				'log_type'  => 'summarize',
				'status'    => 'success',
				'model'     => $model,
				'request'   => $prompt,
				'response'  => $summary,
			)
		);

		return $summary;
	}

	/**
	 * Best-effort fetch of the plain-text content of the page a report was
	 * filed from, so the AI has real context about what the reporter was
	 * looking at. Deliberately short and defensive: this hits an arbitrary
	 * site URL, so failures (timeouts, 404s, non-HTML responses) are
	 * swallowed and simply result in no extra context, never a fatal error.
	 *
	 * @since 1.4.2
	 * @param  string $url  The report's stored source URL.
	 * @return string  Plain-text excerpt, or '' if unavailable.
	 */
	private static function fetch_page_context( $url ) {
		if ( '' === trim( (string) $url ) ) {
			return '';
		}

		$response = wp_safe_remote_get( $url, array(
			'timeout'     => 8,
			'redirection' => 3,
		) );

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return '';
		}

		$body = wp_remote_retrieve_body( $response );

		if ( '' === trim( (string) $body ) ) {
			return '';
		}

		$text = wp_strip_all_tags( $body );
		$text = preg_replace( '/\s+/', ' ', $text );

		return mb_substr( trim( (string) $text ), 0, 3000 );
	}

	/**
	 * Generates an AI reply for a report and posts it into the reply
	 * thread as the 'ai' author. Used both for the automatic reply fired
	 * right after submission (see maybe_auto_reply()) and for the admin's
	 * on-demand "Generate AI Reply" button on the report edit screen.
	 *
	 * @since 1.4.2
	 * @param  int  $log_id      Report log post ID.
	 * @param  bool $post_reply  Whether to post the generated text into the
	 *                           thread as the 'ai' author (true, default —
	 *                           used for the automatic reply) or just return
	 *                           it as a draft for an admin to review first
	 *                           (false — used by the "Generate AI Reply"
	 *                           button, which fills the admin's reply box
	 *                           without sending anything on its own).
	 * @return string|WP_Error  The reply text on success, or a WP_Error.
	 */
	public static function generate_reply( $log_id, $post_reply = true ) {
		if ( ! self::is_available() ) {
			return new WP_Error( 'psbdx_ai_unavailable', __( 'AI features are not available.', 'psbdx-smart-report-management' ) );
		}

		$post = get_post( $log_id );

		if ( ! $post || 'psbdx_report_log' !== $post->post_type ) {
			return new WP_Error( 'psbdx_invalid_report', __( 'Invalid report.', 'psbdx-smart-report-management' ) );
		}

		$ticket_id   = PSBDX_SRM_Helpers::get_ticket_id( $log_id );
		$report_text = wp_strip_all_tags( $post->post_title . "\n" . $post->post_content );
		$report_text = mb_substr( $report_text, 0, 4000 );

		$thread_text = PSBDX_SRM_Replies::get_thread_as_text( $log_id );

		// The "send page data" behaviour disclosed by the info icon next to
		// Settings → AI → Manage → "Allow AI to reply": when this report's
		// AI reply feature is active, the page the reporter was on is
		// fetched (best-effort) so the AI can answer with real knowledge of
		// what they were looking at, instead of guessing from the report
		// text alone.
		$source_url     = get_post_meta( $log_id, '_psbdx_source_url', true );
		$page_context   = PSBDX_SRM_Replies::ai_reply_configured( $log_id ) ? self::fetch_page_context( $source_url ) : '';

		$prompt = sprintf(
			/* translators: 1: ticket ID, 2: report title/content, 3: prior conversation (may be empty), 4: page context (may be empty) */
			__( "You are a helpful customer support agent replying to a customer's report on a WordPress site.\n\nTicket ID: %1\$s\n\nReport:\n%2\$s\n%3\$s%4\$s\nWrite a short, friendly, helpful reply directly to the customer. Be concrete — reference specifics from the report. Do not invent information you were not given (like order numbers or refund amounts) if it wasn't provided above. If you don't have enough information to resolve it, say what you'll check or ask a clarifying question. Do not include a greeting like \"Dear customer\" or a sign-off/signature — just the reply body.", 'psbdx-smart-report-management' ),
			$ticket_id,
			$report_text,
			$thread_text ? "\nConversation so far:\n" . $thread_text . "\n" : '',
			$page_context ? "\nContext from the page the customer reported (for your reference only, don't quote it verbatim):\n" . $page_context . "\n" : ''
		);

		$result = self::build_prompt( $prompt )
			->using_system_instruction( __( 'You are a concise, empathetic customer support agent. Keep replies short (2-5 sentences) and specific to the report. Never make up facts, order details, or promises you cannot verify.', 'psbdx-smart-report-management' ) )
			->generate_text_result();

		if ( is_wp_error( $result ) ) {
			PSBDX_SRM_AI_Log::record( array(
				'ticket_id' => $ticket_id,
				'report_id' => $log_id,
				'log_type'  => 'reply',
				'status'    => 'error',
				'request'   => $prompt,
				'response'  => $result->get_error_message(),
			) );

			if ( $post_reply ) {
				do_action( 'psbdx_srm_ai_error', __( 'Auto-reply', 'psbdx-smart-report-management' ), array(
					'report_id' => $log_id,
					'ticket_id' => $ticket_id,
					'message'   => $result->get_error_message(),
				) );
			}

			return $result;
		}

		$reply = trim( self::extract_text( $result ) );
		$model = self::extract_model( $result );

		if ( '' === $reply ) {
			PSBDX_SRM_AI_Log::record( array(
				'ticket_id' => $ticket_id,
				'report_id' => $log_id,
				'log_type'  => 'reply',
				'status'    => 'error',
				'model'     => $model,
				'request'   => $prompt,
				'response'  => __( '(empty response)', 'psbdx-smart-report-management' ),
			) );

			if ( $post_reply ) {
				do_action( 'psbdx_srm_ai_error', __( 'Auto-reply', 'psbdx-smart-report-management' ), array(
					'report_id' => $log_id,
					'ticket_id' => $ticket_id,
					'message'   => __( 'The AI returned an empty reply.', 'psbdx-smart-report-management' ),
				) );
			}

			return new WP_Error( 'psbdx_ai_bad_response', __( 'The AI returned an empty reply.', 'psbdx-smart-report-management' ) );
		}

		if ( $post_reply ) {
			PSBDX_SRM_Replies::add_reply(
				$log_id,
				'ai',
				0,
				__( 'AI Assistant', 'psbdx-smart-report-management' ),
				$reply
			);
		}

		PSBDX_SRM_AI_Log::record( array(
			'ticket_id' => $ticket_id,
			'report_id' => $log_id,
			'log_type'  => 'reply',
			'status'    => 'success',
			'model'     => $model,
			'request'   => $prompt,
			'response'  => $reply,
		) );

		return $reply;
	}

	/**
	 * Improves an admin's drafted reply (grammar, tone, clarity) without
	 * changing its meaning — used by the "Improve with AI" button next to
	 * the admin's reply box on the report edit screen. Gated only on
	 * general AI availability, not the reply-specific switches: this is an
	 * admin writing aid, not an automated customer-facing reply.
	 *
	 * @since 1.4.2
	 * @param  int    $log_id  Report log post ID (for context).
	 * @param  string $draft   The admin's draft reply text.
	 * @return string|WP_Error
	 */
	public static function improve_reply( $log_id, $draft ) {
		if ( ! self::is_available() ) {
			return new WP_Error( 'psbdx_ai_unavailable', __( 'AI features are not available.', 'psbdx-smart-report-management' ) );
		}

		$draft = trim( (string) $draft );

		if ( '' === $draft ) {
			return new WP_Error( 'psbdx_empty_draft', __( 'Write a draft reply first.', 'psbdx-smart-report-management' ) );
		}

		$post        = get_post( $log_id );
		$report_text = $post ? mb_substr( wp_strip_all_tags( $post->post_title . "\n" . $post->post_content ), 0, 3000 ) : '';
		$ticket_id   = $log_id ? PSBDX_SRM_Helpers::get_ticket_id( $log_id ) : '';

		$prompt = sprintf(
			/* translators: 1: original report text for context, 2: the admin's draft reply */
			__( "A support agent is replying to the customer report below. Improve their draft reply: fix grammar and awkward phrasing, make the tone warm and professional, and keep it concise. Do not add new facts, promises, or information that isn't already in the draft. Return only the improved reply text, nothing else.\n\nOriginal report (for context):\n%1\$s\n\nAgent's draft reply:\n%2\$s", 'psbdx-smart-report-management' ),
			$report_text ?: __( '(no report context available)', 'psbdx-smart-report-management' ),
			$draft
		);

		$result = self::build_prompt( $prompt )
			->using_system_instruction( __( 'You improve customer support reply drafts. Preserve the original meaning and any specific facts exactly; only improve wording, tone, and clarity. Reply with only the improved text.', 'psbdx-smart-report-management' ) )
			->generate_text_result();

		if ( is_wp_error( $result ) ) {
			PSBDX_SRM_AI_Log::record( array(
				'ticket_id' => $ticket_id,
				'report_id' => (int) $log_id,
				'log_type'  => 'improve_reply',
				'status'    => 'error',
				'request'   => $prompt,
				'response'  => $result->get_error_message(),
			) );

			return $result;
		}

		$improved = trim( self::extract_text( $result ) );
		$model    = self::extract_model( $result );

		if ( '' === $improved ) {
			return new WP_Error( 'psbdx_ai_bad_response', __( 'The AI returned an empty response.', 'psbdx-smart-report-management' ) );
		}

		PSBDX_SRM_AI_Log::record( array(
			'ticket_id' => $ticket_id,
			'report_id' => (int) $log_id,
			'log_type'  => 'improve_reply',
			'status'    => 'success',
			'model'     => $model,
			'request'   => $prompt,
			'response'  => $improved,
		) );

		return $improved;
	}
}
