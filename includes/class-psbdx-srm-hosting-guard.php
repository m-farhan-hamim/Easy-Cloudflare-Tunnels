<?php
/**
 * Restricted-hosting detection for the PSBDx SRM external API.
 *
 * Some free hosting providers — InfinityFree and its sister brands being
 * the most commonly reported — sit behind aggressive edge/anti-abuse
 * protection that blocks or JS-challenges inbound HTTP requests that
 * don't look like a normal browser visit. A REST API is, by definition,
 * exactly that kind of request: an external caller (a chatbot, another
 * app) hitting the site directly with no browser involved. On that kind
 * of host, PSBDX_SRM_API's endpoints exist and look configured correctly,
 * but every real call from outside gets intercepted before it ever
 * reaches WordPress — the feature just silently doesn't work, and it's
 * very hard for an admin to tell why.
 *
 * This class tries to catch that ahead of time in two ways:
 *   1. A quick, no-network hostname check against a short list of
 *      hosting patterns known (as of this writing) to behave this way.
 *   2. A live self-test: WordPress asks itself to fetch its own
 *      lightweight /ping route over the public internet — the same
 *      round trip an external caller would have to make — and sees
 *      whether a normal API reply comes back. This is the same
 *      technique Core's own Site Health "REST API availability" check
 *      uses, and it's the real source of truth; the hostname list is
 *      just a fast first guess.
 *
 * When the live test fails (or can't be run at all — also a bad sign),
 * the external API is treated as unavailable: the sensitive REST routes
 * stop registering, and Settings → API shows a clear explanation instead
 * of a form that looks like it should work but doesn't. An admin who's
 * sure their host is fine (or has since gotten it whitelisted) can flip
 * a manual override to turn the feature back on regardless.
 *
 * @package PSBDx_Smart_Report_Management
 * @since   1.4.3
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class PSBDX_SRM_Hosting_Guard
 *
 * @since 1.4.3
 */
class PSBDX_SRM_Hosting_Guard {

	/**
	 * Option storing the last live self-test result:
	 * array( 'tested_at' => int, 'reachable' => bool, 'reason' => string ).
	 *
	 * @since 1.4.3
	 * @var string
	 */
	const STATUS_OPTION = 'psbdx_srm_api_hosting_status';

	/**
	 * Option: admin has manually confirmed the API should stay on
	 * regardless of what detection says.
	 *
	 * @since 1.4.3
	 * @var string
	 */
	const OVERRIDE_OPTION = 'psbdx_srm_api_force_enabled';

	/**
	 * How long a self-test result is trusted before re-testing.
	 *
	 * @since 1.4.3
	 * @var int
	 */
	const CACHE_TTL = 12 * HOUR_IN_SECONDS;

	/**
	 * Hostname suffixes for free-hosting brands commonly reported to block
	 * inbound API-style requests from other domains. Not exhaustive, not
	 * authoritative on its own — it only feeds a fast first impression;
	 * the live self-test below is what actually decides.
	 *
	 * @since 1.4.3
	 * @var string[]
	 */
	const KNOWN_RESTRICTED_HOST_SUFFIXES = array(
		'infinityfreeapp.com',
		'infinityfree.net',
		'epizy.com',
		'rf.gd',
		'great-site.net',
		'wuaze.com',
		'42web.io',
		'x10.mx',
		'x10hosting.com',
		'000webhostapp.com',
	);

	/**
	 * Cron hook used to run the very first self-test in the background on
	 * a freshly-activated site, instead of ever running it inline from a
	 * hot path (see get_cached_status()).
	 *
	 * @since 1.4.3
	 * @var string
	 */
	const INITIAL_CHECK_HOOK = 'psbdx_srm_hosting_initial_check';

	/**
	 * Constructor.
	 *
	 * @since 1.4.3
	 */
	public function __construct() {
		add_action( 'wp_ajax_psbdx_srm_recheck_api_hosting', array( $this, 'ajax_recheck' ) );
		add_action( 'wp_ajax_psbdx_srm_set_api_override', array( $this, 'ajax_set_override' ) );
		add_action( self::INITIAL_CHECK_HOOK, array( __CLASS__, 'run_initial_check' ) );

		// Nothing tested yet (fresh install/update) — get a result in the
		// background shortly, rather than leaving the API's availability
		// undetermined until an admin happens to open Settings → API.
		if ( false === get_option( self::STATUS_OPTION, false ) && ! wp_next_scheduled( self::INITIAL_CHECK_HOOK ) ) {
			wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::INITIAL_CHECK_HOOK );
		}
	}

	/**
	 * WP-Cron callback: runs the first-ever self-test.
	 *
	 * @since 1.4.3
	 * @return void
	 */
	public static function run_initial_check() {
		self::get_status( true );
	}

	// =========================================================================
	// FAST, NO-NETWORK HEURISTIC
	// =========================================================================

	/**
	 * Whether the site's own hostname matches a known restricted-hosting
	 * pattern. Cheap, synchronous, no network call.
	 *
	 * @since 1.4.3
	 * @return bool
	 */
	public static function hostname_looks_restricted() {
		$host = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );

		if ( '' === $host ) {
			return false;
		}

		foreach ( self::KNOWN_RESTRICTED_HOST_SUFFIXES as $suffix ) {
			if ( $host === $suffix || ( strlen( $host ) > strlen( $suffix ) + 1 && '.' . $suffix === substr( $host, -1 - strlen( $suffix ) ) ) ) {
				return true;
			}
		}

		return false;
	}

	// =========================================================================
	// LIVE SELF-TEST
	// =========================================================================

	/**
	 * Reads whatever self-test result is currently cached — no network
	 * call, ever. Safe to call from a hot path (register_routes() fires
	 * on every REST API request to the site, not just ours).
	 *
	 * If nothing has been tested yet, this defaults to "reachable" —
	 * fail-open rather than fail-closed — so a freshly-installed site
	 * behaves normally until the background initial check (or an admin
	 * opening Settings → API) actually finds a problem.
	 *
	 * @since 1.4.3
	 * @return array{tested_at: int, reachable: bool, reason: string}
	 */
	public static function get_cached_status() {
		$cached = get_option( self::STATUS_OPTION, array() );

		if ( ! isset( $cached['tested_at'], $cached['reachable'] ) ) {
			return array( 'tested_at' => 0, 'reachable' => true, 'reason' => '' );
		}

		return $cached;
	}

	/**
	 * Returns the cached self-test result, running a fresh one first if
	 * there isn't a recent one (or $force_refresh is true). This can
	 * make a live network request — only call it from an admin-initiated
	 * path (the Settings → API screen, the "Re-check now" button, or the
	 * scheduled initial check), never from something like
	 * register_routes() that runs on every REST request site-wide; use
	 * get_cached_status() there instead.
	 *
	 * @since 1.4.3
	 * @param  bool $force_refresh  Ignore the cache and test again now.
	 * @return array{tested_at: int, reachable: bool, reason: string}
	 */
	public static function get_status( $force_refresh = false ) {
		$cached = get_option( self::STATUS_OPTION, array() );

		if ( ! $force_refresh && isset( $cached['tested_at'], $cached['reachable'] ) && ( time() - (int) $cached['tested_at'] ) < self::CACHE_TTL ) {
			return $cached;
		}

		$status = self::run_loopback_test();
		update_option( self::STATUS_OPTION, $status, false );

		return $status;
	}

	/**
	 * Does the actual test: asks WordPress to fetch its own /ping REST
	 * route over the public internet — the same path an external API
	 * caller would have to take — and checks whether a normal reply
	 * comes back.
	 *
	 * @since 1.4.3
	 * @return array{tested_at: int, reachable: bool, reason: string}
	 */
	private static function run_loopback_test() {
		$result = array(
			'tested_at' => time(),
			'reachable' => false,
			'reason'    => '',
		);

		$response = wp_remote_get(
			rest_url( 'psbdx-srm/v1/ping' ),
			array(
				'timeout'   => 8,
				'sslverify' => apply_filters( 'https_local_ssl_verify', false ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- this is WordPress core's own filter (used to control loopback-request SSL verification), not one this plugin defines.
			)
		);

		if ( is_wp_error( $response ) ) {
			$result['reason'] = $response->get_error_message();
			return $result;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 === $code && is_array( $body ) && ! empty( $body['pong'] ) ) {
			$result['reachable'] = true;
			return $result;
		}

		$result['reason'] = sprintf(
			/* translators: %d: HTTP status code */
			__( 'Got back HTTP %d instead of a normal API reply — something between the request and WordPress (likely the host\'s own edge/anti-abuse protection) is intercepting it.', 'psbdx-smart-report-management' ),
			$code
		);

		return $result;
	}

	// =========================================================================
	// THE VERDICT THE REST OF THE API READS
	// =========================================================================

	/**
	 * Whether the external API should be active at all. This is what
	 * PSBDX_SRM_API checks before registering its sensitive routes and
	 * before showing the "create a key" form.
	 *
	 * Deliberately reads the cache only (see get_cached_status()) — this
	 * is called from register_routes(), which fires on every REST API
	 * request to the site, not just this plugin's own routes, so it must
	 * never trigger a live network call itself.
	 *
	 * @since 1.4.3
	 * @return bool
	 */
	public static function api_should_be_active() {
		if ( get_option( self::OVERRIDE_OPTION ) ) {
			return true;
		}

		$status = self::get_cached_status();

		return ! empty( $status['reachable'] );
	}

	// =========================================================================
	// ADMIN AJAX (Settings → API "Re-check now" / override toggle)
	// =========================================================================

	/**
	 * AJAX: re-runs the live self-test right now and returns the result.
	 *
	 * @since 1.4.3
	 * @return void
	 */
	public function ajax_recheck() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'You do not have permission to do this.', 'psbdx-smart-report-management' ), 403 );
		}

		check_ajax_referer( 'psbdx_srm_api_hosting' );

		wp_send_json_success( self::get_status( true ) );
	}

	/**
	 * AJAX: toggles the manual "enable anyway" override.
	 *
	 * @since 1.4.3
	 * @return void
	 */
	public function ajax_set_override() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'You do not have permission to do this.', 'psbdx-smart-report-management' ), 403 );
		}

		check_ajax_referer( 'psbdx_srm_api_hosting' );

		update_option( self::OVERRIDE_OPTION, ! empty( $_POST['enabled'] ) ? 'yes' : '', false ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above via check_ajax_referer().

		wp_send_json_success( array( 'active' => self::api_should_be_active() ) );
	}
}
