<?php
/**
 * Captcha support for PSBDx Smart Report Management.
 *
 * Handles reCAPTCHA v2/v3, hCaptcha, and Cloudflare Turnstile.
 *
 * @package PSBDx_Smart_Report_Management
 * @since   1.2.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class PSBDX_SRM_Captcha
 *
 * Static helpers for captcha configuration, rendering, and server-side verification.
 *
 * @since 1.2.0
 */
class PSBDX_SRM_Captcha {

	/**
	 * Option key prefix.
	 *
	 * @since 1.2.0
	 * @var string
	 */
	const OPT_PREFIX = 'psbdx_srm_captcha_';

	/**
	 * Supported captcha providers.
	 *
	 * @since 1.2.0
	 * @return string[]
	 */
	public static function providers() {
		return array( 'recaptcha', 'hcaptcha', 'turnstile' );
	}

	// ── Option helpers ─────────────────────────────────────────────────────

	/**
	 * Get an option for a given provider and field.
	 *
	 * @since 1.2.0
	 * @param  string $provider  Provider slug.
	 * @param  string $field     Field name (e.g. 'site_key').
	 * @return string
	 */
	public static function get_opt( $provider, $field ) {
		return (string) get_option( self::OPT_PREFIX . $provider . '_' . $field, '' );
	}

	/**
	 * Update an option for a given provider and field.
	 *
	 * @since 1.2.0
	 * @param  string $provider  Provider slug.
	 * @param  string $field     Field name.
	 * @param  mixed  $value     Value to store.
	 * @return bool
	 */
	public static function update_opt( $provider, $field, $value ) {
		return update_option( self::OPT_PREFIX . $provider . '_' . $field, $value, false );
	}

	/**
	 * Whether a provider is enabled.
	 *
	 * @since 1.2.0
	 * @param  string $provider  Provider slug.
	 * @return bool
	 */
	public static function is_enabled( $provider ) {
		return '1' === self::get_opt( $provider, 'enabled' );
	}

	/**
	 * Whether ANY provider is enabled.
	 *
	 * @since 1.2.0
	 * @return bool
	 */
	public static function any_enabled() {
		foreach ( self::providers() as $p ) {
			if ( self::is_enabled( $p ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Returns the first enabled provider slug, or empty string.
	 *
	 * @since 1.2.0
	 * @return string
	 */
	public static function active_provider() {
		foreach ( self::providers() as $p ) {
			if ( self::is_enabled( $p ) ) {
				return $p;
			}
		}
		return '';
	}

	// ── Credential verification (AJAX) ──────────────────────────────────────

	/**
	 * Test credentials for a provider by submitting a deliberately invalid token
	 * to the provider's verify endpoint and inspecting the error codes.
	 *
	 * Strategy: we send a clearly-garbage token. The provider will reject it.
	 * We then examine the returned error-codes array:
	 *   - If ANY code in the list is a known SECRET-KEY error → credentials bad.
	 *   - If the list contains ONLY token-level errors (or success=true, which
	 *     some test keys return) → the secret was accepted; credentials are good.
	 *   - If success=false and error-codes is empty or absent → treat as bad
	 *     credentials (unknown rejection; safer to fail closed).
	 *
	 * Each provider's documented error codes:
	 *   reCAPTCHA  : invalid-input-secret, missing-input-secret
	 *   hCaptcha   : invalid-input-secret, missing-input-secret, invalid-sitekey
	 *   Turnstile  : invalid-input-secret, missing-input-secret,
	 *                invalid-parsed-secret, sitekey-secret-mismatch,
	 *                bad-request (returned when the secret format is wrong)
	 *
	 * @since 1.2.0
	 * @param  string $provider   Provider slug ('recaptcha', 'hcaptcha', 'turnstile').
	 * @param  string $site_key   Site key supplied by the user.
	 * @param  string $secret_key Secret key supplied by the user.
	 * @return array{ok: bool, message: string}
	 */
	public static function test_credentials( $provider, $site_key, $secret_key ) {
		if ( ! in_array( $provider, self::providers(), true ) ) {
			return array( 'ok' => false, 'message' => __( 'Unknown provider.', 'psbdx-smart-report-management' ) );
		}

		$site_key   = trim( $site_key );
		$secret_key = trim( $secret_key );

		if ( '' === $site_key || '' === $secret_key ) {
			return array( 'ok' => false, 'message' => __( 'Site key and secret key are both required.', 'psbdx-smart-report-management' ) );
		}

		// Error codes that mean the SECRET is wrong (provider-specific).
		// Token-level errors (invalid-input-response, timeout-or-duplicate,
		// invalid-widget-id, etc.) are explicitly NOT in this list so we can
		// treat them as a positive signal that the secret was accepted.
		$secret_error_codes = array(
			// All providers.
			'invalid-input-secret',
			'missing-input-secret',
			// hCaptcha.
			'invalid-sitekey',
			// Cloudflare Turnstile.
			'invalid-parsed-secret',
			'sitekey-secret-mismatch',
			'bad-request', // Returned by Turnstile when the secret format is unparseable.
		);

		// Token-level errors: if the error-codes array contains ONLY these, the
		// secret was accepted and we treat credentials as valid.
		$token_error_codes = array(
			'invalid-input-response',
			'missing-input-response',
			'timeout-or-duplicate',
			'invalid-widget-id',
			'invalid-keys',     // Turnstile token-level.
			'invalid-token',    // Turnstile token-level.
		);

		$response = wp_remote_post(
			self::verify_url( $provider ),
			array(
				'timeout'    => 15,
				'sslverify'  => true,
				'body'       => array(
					'secret'   => $secret_key,
					'response' => 'psbdx-credential-test-' . wp_generate_password( 12, false ),
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return array(
				'ok'      => false,
				'message' => sprintf(
					/* translators: %1$s: provider label, %2$s: error message */
					__( 'Could not reach %1$s servers: %2$s', 'psbdx-smart-report-management' ),
					self::label( $provider ),
					$response->get_error_message()
				),
			);
		}

		$http_code = (int) wp_remote_retrieve_response_code( $response );
		$body      = json_decode( wp_remote_retrieve_body( $response ), true );

		// Non-200 without a parseable body → network/firewall issue.
		if ( $http_code !== 200 && ! is_array( $body ) ) {
			return array(
				'ok'      => false,
				'message' => sprintf(
					/* translators: %d: HTTP status code */
					__( 'Unexpected HTTP %d from the captcha server. Check your server\'s outbound firewall.', 'psbdx-smart-report-management' ),
					$http_code
				),
			);
		}

		if ( ! is_array( $body ) ) {
			return array(
				'ok'      => false,
				'message' => __( 'The captcha server returned an unreadable response.', 'psbdx-smart-report-management' ),
			);
		}

		// If the provider accepted our garbage token outright (test mode keys),
		// the secret is definitely valid.
		if ( ! empty( $body['success'] ) ) {
			return array(
				'ok'      => true,
				/* translators: %s: provider label */
				'message' => sprintf( __( '%s credentials verified successfully.', 'psbdx-smart-report-management' ), self::label( $provider ) ),
			);
		}

		// success = false. Now inspect error-codes.
		$errors = isset( $body['error-codes'] ) && is_array( $body['error-codes'] )
			? array_map( 'strval', $body['error-codes'] )
			: array();

		// Any secret-level error code → bad credentials. Check this first.
		foreach ( $errors as $code ) {
			if ( in_array( $code, $secret_error_codes, true ) ) {
				return array(
					'ok'      => false,
					'message' => sprintf(
						/* translators: %s: raw error code from provider */
						__( 'Invalid credentials (%s). Please double-check your site key and secret key.', 'psbdx-smart-report-management' ),
						esc_html( $code )
					),
				);
			}
		}

		// No secret errors found. We must now confirm there is at least one
		// recognised token-level error, proving the secret reached the server
		// and was accepted before the token was rejected.
		if ( empty( $errors ) ) {
			// success=false with no error-codes — this is ambiguous. Fail closed.
			return array(
				'ok'      => false,
				'message' => __( 'The captcha server rejected the request without specifying a reason. Please verify your credentials and try again.', 'psbdx-smart-report-management' ),
			);
		}

		$has_known_token_error = false;
		foreach ( $errors as $code ) {
			if ( in_array( $code, $token_error_codes, true ) ) {
				$has_known_token_error = true;
				break;
			}
		}

		if ( $has_known_token_error ) {
			// Secret was accepted; the only failure is that our test token was
			// invalid (expected). Credentials are good.
			return array(
				'ok'      => true,
				/* translators: %s: provider label */
				'message' => sprintf( __( '%s credentials verified successfully.', 'psbdx-smart-report-management' ), self::label( $provider ) ),
			);
		}

		// Unrecognised error code(s) — unknown failure. Fail closed and surface
		// the raw codes so the admin knows what to look up.
		return array(
			'ok'      => false,
			'message' => sprintf(
				/* translators: %s: comma-separated error codes from provider */
				__( 'The captcha server returned an unrecognised error: %s', 'psbdx-smart-report-management' ),
				esc_html( implode( ', ', $errors ) )
			),
		);
	}

	// ── Server-side verification ───────────────────────────────────────────

	/**
	 * Verify a captcha token submitted with a report form.
	 *
	 * @since 1.2.0
	 * @param  string $provider  Provider slug.
	 * @param  string $token     Token from the frontend widget.
	 * @return bool
	 */
	public static function verify( $provider, $token ) {
		if ( '' === $token ) {
			return false;
		}

		$secret = self::get_opt( $provider, 'secret_key' );
		if ( '' === $secret ) {
			return false;
		}

		$body = array(
			'secret'   => $secret,
			'response' => $token,
		);

		// Turnstile and reCAPTCHA accept a remoteip parameter.
		if ( 'hcaptcha' !== $provider ) {
			$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
			if ( $ip ) {
				$body['remoteip'] = $ip;
			}
		}

		$response = wp_remote_post(
			self::verify_url( $provider ),
			array(
				'timeout' => 10,
				'body'    => $body,
			)
		);

		if ( is_wp_error( $response ) ) {
			return false;
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		return is_array( $data ) && ! empty( $data['success'] );
	}

	// ── Static data ────────────────────────────────────────────────────────

	/**
	 * Human-readable label for a provider.
	 *
	 * @since 1.2.0
	 * @param  string $provider  Provider slug.
	 * @return string
	 */
	public static function label( $provider ) {
		$map = array(
			'recaptcha' => 'Google reCAPTCHA',
			'hcaptcha'  => 'hCaptcha',
			'turnstile' => 'Cloudflare Turnstile',
		);
		return isset( $map[ $provider ] ) ? $map[ $provider ] : $provider;
	}

	/**
	 * Verification endpoint for a provider.
	 *
	 * @since 1.2.0
	 * @param  string $provider  Provider slug.
	 * @return string
	 */
	public static function verify_url( $provider ) {
		$map = array(
			'recaptcha' => 'https://www.google.com/recaptcha/api/siteverify',
			'hcaptcha'  => 'https://api.hcaptcha.com/siteverify',
			'turnstile' => 'https://challenges.cloudflare.com/turnstile/v0/siteverify',
		);
		return isset( $map[ $provider ] ) ? $map[ $provider ] : '';
	}

	/**
	 * Frontend JS script URL for a provider.
	 *
	 * @since 1.2.0
	 * @param  string $provider  Provider slug.
	 * @param  string $site_key  Site key (used for reCAPTCHA v3 render param).
	 * @return string
	 */
	public static function script_url( $provider, $site_key = '' ) {
		switch ( $provider ) {
			case 'recaptcha':
				return 'https://www.google.com/recaptcha/api.js?render=explicit';
			case 'hcaptcha':
				return 'https://js.hcaptcha.com/1/api.js?render=explicit';
			case 'turnstile':
				return 'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit';
		}
		return '';
	}

	/**
	 * The response field name used by each provider.
	 *
	 * @since 1.2.0
	 * @param  string $provider  Provider slug.
	 * @return string
	 */
	public static function response_field( $provider ) {
		$map = array(
			'recaptcha' => 'g-recaptcha-response',
			'hcaptcha'  => 'h-captcha-response',
			'turnstile' => 'cf-turnstile-response',
		);
		return isset( $map[ $provider ] ) ? $map[ $provider ] : 'captcha-response';
	}
}
