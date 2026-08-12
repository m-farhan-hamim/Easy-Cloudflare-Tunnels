<?php
/**
 * External API for PSBDx Smart Report Management.
 *
 * Lets an admin issue API keys (each restricted to specific whitelisted
 * domains and/or server IPs) so an external system — a chatbot, another
 * app, an integration partner — can fill in and submit a report form
 * without visiting the site's frontend page directly.
 *
 * Flow:
 *   1. POST /start        → open a session against a form, get back a
 *                            field schema + a session_id.
 *   2. POST /field         → fill one field at a time by handle. Filling
 *                            an "email" field triggers an OTP sent to
 *                            that address instead of storing it directly.
 *   3. POST /verify-otp    → confirm the emailed code to unlock that field.
 *   4. POST /submit        → once all required fields are present (and any
 *                            required email is OTP-verified), creates the
 *                            actual report and returns its ticket ID.
 *   5. GET  /ticket/{id}/status → look up a ticket's current status.
 *
 * Every call (except none — all of them) must be authenticated with an
 * API key + secret, and the caller's Origin/Referer domain and/or server
 * IP must match what the admin whitelisted for that key.
 *
 * @package PSBDx_Smart_Report_Management
 * @since   1.4.3
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class PSBDX_SRM_API
 *
 * @since 1.4.3
 */
class PSBDX_SRM_API {

	/**
	 * Option storing the installed table schema version.
	 *
	 * @since 1.4.3
	 * @var string
	 */
	const DB_VERSION_OPTION = 'psbdx_srm_api_db_version';

	/**
	 * Current table schema version.
	 *
	 * @since 1.4.3
	 * @var string
	 */
	const DB_VERSION = '1.0';

	/**
	 * REST namespace.
	 *
	 * @since 1.4.3
	 * @var string
	 */
	const API_NAMESPACE = 'psbdx-srm/v1';

	/**
	 * Post meta key (on psbdx_report_form) — whether this form can be
	 * filled/submitted via the external API.
	 *
	 * @since 1.4.3
	 * @var string
	 */
	const API_ENABLED_META = '_psbdx_api_enabled';

	/**
	 * How long an API session stays open before it expires.
	 *
	 * @since 1.4.3
	 * @var int
	 */
	const SESSION_TTL = 30 * MINUTE_IN_SECONDS;

	/**
	 * How long an emailed OTP code stays valid.
	 *
	 * @since 1.4.3
	 * @var int
	 */
	const OTP_TTL = 10 * MINUTE_IN_SECONDS;

	/**
	 * Transient key (per admin user) used to show a freshly generated
	 * secret exactly once on the Settings → API screen.
	 *
	 * @since 1.4.3
	 * @var string
	 */
	const NEW_KEY_TRANSIENT_PREFIX = 'psbdx_srm_new_api_key_';

	/**
	 * Option: site-wide switch that injects an extra, always-mandatory
	 * Email field into every API-enabled form's schema — independent of
	 * whatever fields that form already defines, including any email
	 * field of its own. See get_api_fields().
	 *
	 * @since 1.4.4
	 * @var string
	 */
	const ALWAYS_REQUIRE_EMAIL_OPTION = 'psbdx_srm_api_always_require_email';

	/**
	 * Reserved field handle for the injected email field. Deliberately
	 * namespaced so it can never collide with a handle an admin picked in
	 * the Form Builder.
	 *
	 * @since 1.4.4
	 * @var string
	 */
	const INJECTED_EMAIL_HANDLE = '_psbdx_api_verified_email';

	/**
	 * How many failed authentication attempts (wrong key or wrong secret)
	 * from the same IP are tolerated within AUTH_FAILURE_WINDOW before
	 * that IP is locked out for AUTH_LOCKOUT_DURATION. Domain/IP
	 * whitelist mismatches don't count toward this — those usually mean
	 * a legitimate key's own caller is misconfigured, not an attack.
	 *
	 * @since 1.4.4
	 * @var int
	 */
	const AUTH_MAX_FAILURES = 15;

	/**
	 * Rolling window the failure count above is measured over.
	 *
	 * @since 1.4.4
	 * @var int
	 */
	const AUTH_FAILURE_WINDOW = 15 * MINUTE_IN_SECONDS;

	/**
	 * How long an IP stays locked out after exceeding AUTH_MAX_FAILURES.
	 *
	 * @since 1.4.4
	 * @var int
	 */
	const AUTH_LOCKOUT_DURATION = 15 * MINUTE_IN_SECONDS;

	/**
	 * Maximum number of OTP emails a single session is allowed to trigger
	 * (i.e. how many times its email field can be (re)submitted) before
	 * /field starts refusing. Without this, a caller could resubmit an
	 * email field indefinitely and use the OTP endpoint as a free way to
	 * repeatedly email an arbitrary address.
	 *
	 * @since 1.4.4
	 * @var int
	 */
	const MAX_OTP_SENDS_PER_SESSION = 3;

	/**
	 * Maximum OTP emails a single API key can trigger within
	 * OTP_VOLUME_WINDOW, across all of its sessions combined — a second,
	 * broader backstop against using the endpoint as a mass-mailer.
	 *
	 * @since 1.4.4
	 * @var int
	 */
	const MAX_OTP_SENDS_PER_KEY = 30;

	/**
	 * Rolling window the per-key OTP volume above is measured over.
	 *
	 * @since 1.4.4
	 * @var int
	 */
	const OTP_VOLUME_WINDOW = HOUR_IN_SECONDS;

	/**
	 * Constructor.
	 *
	 * @since 1.4.3
	 */
	public function __construct() {
		add_action( 'admin_init', array( __CLASS__, 'maybe_install_tables' ) );
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );

		// Settings → API tab, registered the same extensible way the
		// Push Notification add-on registers its own tab, even though
		// this one ships with core.
		add_filter( 'psbdx_srm_settings_tabs', array( $this, 'add_settings_tab' ) );
		add_action( 'psbdx_srm_settings_tab_content', array( $this, 'render_settings_tab' ) );
		add_action( 'admin_init', array( $this, 'handle_admin_actions' ) );

		// Form Builder integration: render + save the per-form checkbox.
		add_action( 'psbdx_srm_form_builder_after_replies', array( $this, 'render_form_builder_checkbox' ) );
		add_action( 'save_post', array( $this, 'save_form_builder_checkbox' ), 20 );
	}

	// =========================================================================
	// TABLE MANAGEMENT
	// =========================================================================

	/**
	 * Fully-prefixed API keys table name.
	 *
	 * @since 1.4.3
	 * @return string
	 */
	public static function keys_table() {
		global $wpdb;
		return $wpdb->prefix . 'psbdx_srm_api_keys';
	}

	/**
	 * Fully-prefixed API sessions table name.
	 *
	 * @since 1.4.3
	 * @return string
	 */
	public static function sessions_table() {
		global $wpdb;
		return $wpdb->prefix . 'psbdx_srm_api_sessions';
	}

	/**
	 * Installs the tables if missing or the schema version changed.
	 *
	 * @since 1.4.3
	 * @return void
	 */
	public static function maybe_install_tables() {
		if ( get_option( self::DB_VERSION_OPTION ) === self::DB_VERSION ) {
			return;
		}

		self::install_tables();
	}

	/**
	 * Creates (or updates) the API tables via dbDelta().
	 *
	 * @since 1.4.3
	 * @return void
	 */
	public static function install_tables() {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$keys_table    = self::keys_table();
		$sessions_table = self::sessions_table();

		// phpcs:disable WordPress.DB.PreparedSQLPlaceholders.DBParameterAllowedList -- dbDelta() requires a literal string, no placeholders.
		$sql1 = "CREATE TABLE {$keys_table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			key_id VARCHAR(64) NOT NULL DEFAULT '',
			secret_hash VARCHAR(64) NOT NULL DEFAULT '',
			label VARCHAR(190) NOT NULL DEFAULT '',
			allowed_domains TEXT NULL,
			allowed_ips TEXT NULL,
			status VARCHAR(10) NOT NULL DEFAULT 'active',
			created_at DATETIME NOT NULL,
			last_used_at DATETIME NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY key_id (key_id),
			KEY status (status)
		) {$charset_collate};";

		$sql2 = "CREATE TABLE {$sessions_table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			session_token VARCHAR(64) NOT NULL DEFAULT '',
			api_key_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			form_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			field_values LONGTEXT NULL,
			pending_email_handle VARCHAR(190) NOT NULL DEFAULT '',
			pending_email_value VARCHAR(190) NOT NULL DEFAULT '',
			otp_hash VARCHAR(64) NOT NULL DEFAULT '',
			otp_expires_at DATETIME NULL,
			otp_verified TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
			otp_attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
			status VARCHAR(20) NOT NULL DEFAULT 'in_progress',
			ticket_id VARCHAR(40) NOT NULL DEFAULT '',
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			expires_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY session_token (session_token),
			KEY api_key_id (api_key_id),
			KEY status (status)
		) {$charset_collate};";
		// phpcs:enable

		dbDelta( $sql1 );
		dbDelta( $sql2 );

		update_option( self::DB_VERSION_OPTION, self::DB_VERSION, false );
	}

	// =========================================================================
	// FORM BUILDER INTEGRATION
	// =========================================================================

	/**
	 * Renders the "Allow API" checkbox in the form's Settings tab.
	 *
	 * Hooked via `psbdx_srm_form_builder_after_replies` (added to
	 * PSBDX_SRM_Form_Builder::render_builder() right after the Replies
	 * section) so this whole feature stays in its own file.
	 *
	 * @since 1.4.3
	 * @param  WP_Post $post  Current report-form post.
	 * @return void
	 */
	public function render_form_builder_checkbox( $post ) {
		$enabled     = ( 'yes' === get_post_meta( $post->ID, self::API_ENABLED_META, true ) );
		$has_email   = $this->form_has_email_field( $post->ID );
		$settings_url = admin_url( 'admin.php?page=psbdx-srm-settings&tab=api' );
		?>
		<div class="psrm-settings-section">
			<div class="psrm-settings-section-header">
				<span class="dashicons dashicons-rest-api" aria-hidden="true"></span>
				<strong><?php esc_html_e( 'API Access', 'psbdx-smart-report-management' ); ?></strong>
			</div>
			<div class="psrm-settings-section-body">
				<p class="psrm-hint">
					<?php esc_html_e( 'When enabled, an external system holding a valid API key can fill in and submit this form programmatically (e.g. from a chatbot). Manage API keys and see the endpoint URLs under', 'psbdx-smart-report-management' ); ?>
					<a href="<?php echo esc_url( $settings_url ); ?>"><?php esc_html_e( 'Settings → API', 'psbdx-smart-report-management' ); ?></a>.
				</p>
				<p>
					<label>
						<input type="checkbox" name="psbdx_api_enabled" value="yes" <?php checked( $enabled ); ?>>
						<?php esc_html_e( 'Allow this form to be filled via the API', 'psbdx-smart-report-management' ); ?>
					</label>
				</p>
				<?php if ( $enabled && $has_email ) : ?>
					<p class="description"><?php esc_html_e( 'This form has an Email field — the API will require a one-time code sent to that address before it can be treated as filled in.', 'psbdx-smart-report-management' ); ?></p>
				<?php endif; ?>
				<?php if ( $enabled && ! PSBDX_SRM_Hosting_Guard::api_should_be_active() ) : ?>
					<p class="description" style="color:#b32d2e;">
						<span class="dashicons dashicons-warning"></span>
						<?php esc_html_e( 'The API is currently switched off site-wide (this hosting appears to block inbound API calls) — see Settings → API.', 'psbdx-smart-report-management' ); ?>
					</p>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Saves the per-form API checkbox. Hooked directly to save_post
	 * (rather than depending on the builder's own save() method) so this
	 * stays self-contained; it re-checks the same nonce the builder uses.
	 *
	 * @since 1.4.3
	 * @param  int $post_id  Post ID being saved.
	 * @return void
	 */
	public function save_form_builder_checkbox( $post_id ) {
		if ( ! isset( $_POST[ PSBDX_SRM_Form_Builder::NONCE_FIELD ] )
			|| ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST[ PSBDX_SRM_Form_Builder::NONCE_FIELD ] ) ), PSBDX_SRM_Form_Builder::NONCE_ACTION )
		) {
			return;
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) || 'psbdx_report_form' !== get_post_type( $post_id ) ) {
			return;
		}

		update_post_meta( $post_id, self::API_ENABLED_META, isset( $_POST['psbdx_api_enabled'] ) ? 'yes' : 'no' );
	}

	/**
	 * Whether a given form's schema contains an "email" type field.
	 *
	 * @since 1.4.3
	 * @param  int $form_id  Report form post ID.
	 * @return bool
	 */
	private function form_has_email_field( $form_id ) {
		foreach ( $this->get_form_schema( $form_id ) as $field ) {
			if ( 'email' === ( $field['type'] ?? '' ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Reads a form's v2 builder field schema.
	 *
	 * @since 1.4.3
	 * @param  int $form_id  Report form post ID.
	 * @return array[]
	 */
	private function get_form_schema( $form_id ) {
		$raw    = get_post_meta( $form_id, PSBDX_SRM_Form_Builder::FIELDS_META_KEY, true );
		$schema = json_decode( $raw ? $raw : '[]', true );
		return is_array( $schema ) ? $schema : array();
	}

	/**
	 * API-eligible fields from a form's schema (everything except captcha,
	 * which makes no sense for a server-to-server caller), plus — when
	 * the site-wide "always require a verified email" option is on — a
	 * synthetic extra Email field appended at the end. That injected
	 * field exists purely for the API: it's never part of the form's own
	 * schema in the Form Builder, is added regardless of whether the
	 * form already has an email field of its own, and always goes
	 * through the same OTP verification as any other email field (see
	 * is_mandatory_for_api()).
	 *
	 * @since 1.4.3
	 * @param  int $form_id  Report form post ID.
	 * @return array[]
	 */
	private function get_api_fields( $form_id ) {
		$fields = array_values(
			array_filter(
				$this->get_form_schema( $form_id ),
				function ( $field ) {
					return 'captcha' !== ( $field['type'] ?? '' );
				}
			)
		);

		if ( 'yes' === get_option( self::ALWAYS_REQUIRE_EMAIL_OPTION ) ) {
			$fields[] = array(
				'handle'   => self::INJECTED_EMAIL_HANDLE,
				'type'     => 'email',
				'label'    => __( 'Verified Email', 'psbdx-smart-report-management' ),
				'required' => true,
			);
		}

		return $fields;
	}

	// =========================================================================
	// REST ROUTES
	// =========================================================================

	/**
	 * Registers all REST routes under the psbdx-srm/v1 namespace.
	 *
	 * @since 1.4.3
	 * @return void
	 */
	public function register_routes() {
		// Always available — harmless, and it's what the hosting guard's
		// self-test calls to find out whether external requests can even
		// reach this site at all.
		register_rest_route(
			self::API_NAMESPACE,
			'/ping',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'route_ping' ),
				'permission_callback' => '__return_true',
			)
		);

		if ( ! PSBDX_SRM_Hosting_Guard::api_should_be_active() ) {
			// Detected (or confirmed via a live self-test) that this host
			// blocks inbound API-style requests from other domains —
			// registering the rest of the routes would just be a feature
			// that looks configured but silently never works for anyone
			// calling in from outside. Settings → API explains why and
			// offers a manual override.
			return;
		}

		register_rest_route(
			self::API_NAMESPACE,
			'/fields',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'route_fields' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			self::API_NAMESPACE,
			'/start',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'route_start' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			self::API_NAMESPACE,
			'/field',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'route_field' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			self::API_NAMESPACE,
			'/verify-otp',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'route_verify_otp' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			self::API_NAMESPACE,
			'/submit',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'route_submit' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			self::API_NAMESPACE,
			'/ticket/(?P<ticket_id>[A-Za-z0-9\-]+)/status',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'route_ticket_status' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * GET /ping — a deliberately unauthenticated, information-free health
	 * check. Used by PSBDX_SRM_Hosting_Guard to test whether an external
	 * request can reach this site's REST API at all; also handy for
	 * anyone integrating to confirm the API is reachable before bothering
	 * with a real key.
	 *
	 * @since 1.4.3
	 * @return WP_REST_Response
	 */
	public function route_ping() {
		return rest_ensure_response(
			array(
				'pong' => true,
				'time' => time(),
			)
		);
	}

	// =========================================================================
	// AUTHENTICATION
	// =========================================================================

	/**
	 * Authenticates a REST request against a stored API key: verifies the
	 * key/secret pair, then checks the caller's Origin/Referer domain
	 * and/or server IP against whatever the admin whitelisted for that key.
	 *
	 * Also enforces a per-IP lockout after repeated failed attempts (wrong
	 * key or wrong secret) — see AUTH_MAX_FAILURES.
	 *
	 * @since 1.4.3
	 * @param  WP_REST_Request $request  Current request.
	 * @return object|WP_Error  The api_keys row on success.
	 */
	private function authenticate_request( $request ) {
		global $wpdb;

		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';

		if ( $this->is_locked_out( $ip ) ) {
			return new WP_Error( 'psrm_api_locked_out', __( 'Too many failed authentication attempts from this address. Try again later.', 'psbdx-smart-report-management' ), array( 'status' => 429 ) );
		}

		$key_id = $request->get_header( 'x-psrm-api-key' );
		$secret = $request->get_header( 'x-psrm-api-secret' );

		if ( ! $key_id || ! $secret ) {
			$auth = (string) $request->get_header( 'authorization' );
			if ( $auth && preg_match( '/^Bearer\s+(.+):(.+)$/i', trim( $auth ), $m ) ) {
				$key_id = $m[1];
				$secret = $m[2];
			}
		}

		$key_id = $key_id ? sanitize_text_field( $key_id ) : '';
		$secret = $secret ? trim( (string) $secret ) : '';

		if ( '' === $key_id || '' === $secret ) {
			return new WP_Error( 'psrm_api_unauthorized', __( 'Missing API key or secret.', 'psbdx-smart-report-management' ), array( 'status' => 401 ) );
		}

		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::keys_table() . ' WHERE key_id = %s', $key_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table name comes from self::keys_table(), not user input; the value IS parameterized via $wpdb->prepare()'s %s.

		if ( ! $row || 'active' !== $row->status ) {
			$this->record_auth_failure( $ip );
			return new WP_Error( 'psrm_api_unauthorized', __( 'Invalid or revoked API key.', 'psbdx-smart-report-management' ), array( 'status' => 401 ) );
		}

		if ( ! self::secret_matches( $secret, $row->secret_hash ) ) {
			$this->record_auth_failure( $ip );
			return new WP_Error( 'psrm_api_unauthorized', __( 'Invalid API secret.', 'psbdx-smart-report-management' ), array( 'status' => 401 ) );
		}

		// Domain whitelist (checked against Origin, falling back to Referer).
		$allowed_domains = self::split_list( $row->allowed_domains );
		if ( ! empty( $allowed_domains ) ) {
			$origin = $request->get_header( 'origin' );
			if ( ! $origin ) {
				$origin = $request->get_header( 'referer' );
			}
			$host = $origin ? wp_parse_url( $origin, PHP_URL_HOST ) : '';
			$host = $host ? strtolower( $host ) : '';

			if ( '' === $host || ! in_array( $host, array_map( 'strtolower', $allowed_domains ), true ) ) {
				return new WP_Error( 'psrm_api_forbidden', __( 'This domain is not whitelisted for this API key.', 'psbdx-smart-report-management' ), array( 'status' => 403 ) );
			}
		}

		// IP whitelist.
		$allowed_ips = self::split_list( $row->allowed_ips );
		if ( ! empty( $allowed_ips ) ) {
			if ( '' === $ip || ! in_array( $ip, $allowed_ips, true ) ) {
				return new WP_Error( 'psrm_api_forbidden', __( 'This server IP is not whitelisted for this API key.', 'psbdx-smart-report-management' ), array( 'status' => 403 ) );
			}
		}

		// A successful request from this IP clears its failure count —
		// only *sustained* abuse should ever trigger a lockout.
		$this->clear_auth_failures( $ip );

		$wpdb->update( self::keys_table(), array( 'last_used_at' => current_time( 'mysql', true ) ), array( 'id' => $row->id ), array( '%s' ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- $wpdb->update() prepares/escapes internally; a single-row write doesn't need caching.

		return $row;
	}

	/**
	 * Transient key used to track failed-authentication counts for a
	 * given IP. The IP itself is hashed rather than stored verbatim.
	 *
	 * @since 1.4.4
	 * @param  string $ip  Caller IP address.
	 * @return string
	 */
	private static function auth_failure_key( $ip ) {
		return 'psrm_api_authfail_' . md5( 'psbdx_srm|' . $ip );
	}

	/**
	 * Whether an IP is currently locked out from authenticating.
	 *
	 * @since 1.4.4
	 * @param  string $ip  Caller IP address.
	 * @return bool
	 */
	private function is_locked_out( $ip ) {
		if ( '' === $ip ) {
			return false;
		}

		return (int) get_transient( self::auth_failure_key( $ip ) ) >= self::AUTH_MAX_FAILURES;
	}

	/**
	 * Records one failed authentication attempt for an IP, within a
	 * rolling window — once AUTH_MAX_FAILURES is hit, is_locked_out()
	 * starts refusing that IP for AUTH_LOCKOUT_DURATION.
	 *
	 * @since 1.4.4
	 * @param  string $ip  Caller IP address.
	 * @return void
	 */
	private function record_auth_failure( $ip ) {
		if ( '' === $ip ) {
			return;
		}

		$key   = self::auth_failure_key( $ip );
		$count = (int) get_transient( $key );

		// Once locked out, extend the lockout on every further attempt
		// rather than letting it quietly expire mid-attack; otherwise
		// keep counting within the original failure window.
		$ttl = ( $count + 1 >= self::AUTH_MAX_FAILURES ) ? self::AUTH_LOCKOUT_DURATION : self::AUTH_FAILURE_WINDOW;

		set_transient( $key, $count + 1, $ttl );
	}

	/**
	 * Clears an IP's failure count after a successful authentication.
	 *
	 * @since 1.4.4
	 * @param  string $ip  Caller IP address.
	 * @return void
	 */
	private function clear_auth_failures( $ip ) {
		if ( '' === $ip ) {
			return;
		}

		delete_transient( self::auth_failure_key( $ip ) );
	}

	/**
	 * Verifies a submitted secret against a stored hash.
	 *
	 * New keys are hashed with hash_hmac( 'sha256', $secret, wp_salt( 'auth' ) )
	 * — a site-specific pepper, so a stolen database alone isn't enough to
	 * verify guesses offline against it. Keys created before 1.4.5 were
	 * hashed with plain hash( 'sha256', $secret ); those are still
	 * accepted here so existing keys don't break, but every *new* key
	 * created from 1.4.5 onward uses the peppered scheme — see
	 * hash_secret().
	 *
	 * @since 1.4.4
	 * @param  string $secret       Submitted plaintext secret.
	 * @param  string $stored_hash  Hash on file for this key.
	 * @return bool
	 */
	private static function secret_matches( $secret, $stored_hash ) {
		if ( hash_equals( (string) $stored_hash, self::hash_secret( $secret ) ) ) {
			return true;
		}

		// Legacy (pre-1.4.5) unpeppered hash, kept only for backward compatibility.
		return hash_equals( (string) $stored_hash, hash( 'sha256', $secret ) );
	}

	/**
	 * Hashes a secret for storage using the current (peppered) scheme.
	 * Always use this when creating or rotating a key.
	 *
	 * @since 1.4.4
	 * @param  string $secret  Plaintext secret.
	 * @return string
	 */
	private static function hash_secret( $secret ) {
		return hash_hmac( 'sha256', $secret, wp_salt( 'auth' ) );
	}

	/**
	 * Splits an admin-entered comma/newline separated list into a clean
	 * array of trimmed, non-empty values.
	 *
	 * @since 1.4.3
	 * @param  string $raw  Raw stored value.
	 * @return string[]
	 */
	private static function split_list( $raw ) {
		if ( ! $raw ) {
			return array();
		}

		$parts = preg_split( '/[\s,]+/', (string) $raw );
		return array_values( array_filter( array_map( 'trim', (array) $parts ) ) );
	}

	// =========================================================================
	// ROUTE: /start
	// =========================================================================

	/**
	 * POST /start — opens a new fill-in session for a form.
	 *
	 * @since  1.4.3
	 * @param  WP_REST_Request $request  Current request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function route_start( WP_REST_Request $request ) {
		$key_row = $this->authenticate_request( $request );
		if ( is_wp_error( $key_row ) ) {
			return $key_row;
		}

		$form = $this->resolve_target_form( $request );
		if ( is_wp_error( $form ) ) {
			return $form;
		}

		global $wpdb;

		$session_token = wp_generate_password( 40, false, false );
		$now           = current_time( 'mysql', true );
		$expires       = gmdate( 'Y-m-d H:i:s', time() + self::SESSION_TTL );

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- $wpdb->insert() prepares/escapes internally; a single-row write doesn't need caching.
			self::sessions_table(),
			array(
				'session_token' => $session_token,
				'api_key_id'    => $key_row->id,
				'form_id'       => $form->ID,
				'field_values'  => wp_json_encode( array() ),
				'status'        => 'in_progress',
				'created_at'    => $now,
				'updated_at'    => $now,
				'expires_at'    => $expires,
			),
			array( '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s' )
		);

		return rest_ensure_response(
			array(
				'session_id' => $session_token,
				'form_id'    => $form->ID,
				'form_title' => get_the_title( $form ),
				'expires_in' => self::SESSION_TTL,
				'fields'     => $this->fields_for_response( $form->ID ),
			)
		);
	}

	/**
	 * GET /fields — returns a form's field schema without opening a
	 * session. For a caller that just wants to know what a form looks
	 * like (to build a UI, decide what to collect, or check ahead of
	 * time which fields are mandatory) before committing to the 30-minute
	 * session lifecycle that filling it in via /start + /field implies.
	 *
	 * @since  1.4.4
	 * @param  WP_REST_Request $request  Current request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function route_fields( WP_REST_Request $request ) {
		$key_row = $this->authenticate_request( $request );
		if ( is_wp_error( $key_row ) ) {
			return $key_row;
		}

		$form = $this->resolve_target_form( $request );
		if ( is_wp_error( $form ) ) {
			return $form;
		}

		return rest_ensure_response(
			array(
				'form_id'    => $form->ID,
				'form_title' => get_the_title( $form ),
				'fields'     => $this->fields_for_response( $form->ID ),
			)
		);
	}

	/**
	 * Resolves which form a request targets: the explicit `form_id`
	 * parameter if given, or — when exactly one form has API access
	 * enabled — that form automatically. Shared by /start and /fields so
	 * both apply the exact same selection rules.
	 *
	 * @since  1.4.4
	 * @param  WP_REST_Request $request  Current request.
	 * @return WP_Post|WP_Error
	 */
	private function resolve_target_form( WP_REST_Request $request ) {
		$requested_form_id = absint( $request->get_param( 'form_id' ) );
		$api_forms          = array();

		foreach ( PSBDX_SRM_Helpers::get_published_report_forms() as $form ) {
			if ( 'yes' === get_post_meta( $form->ID, self::API_ENABLED_META, true ) ) {
				$api_forms[] = $form;
			}
		}

		if ( empty( $api_forms ) ) {
			return new WP_Error( 'psrm_api_no_forms', __( 'No report form currently has API access enabled.', 'psbdx-smart-report-management' ), array( 'status' => 404 ) );
		}

		if ( $requested_form_id ) {
			foreach ( $api_forms as $candidate ) {
				if ( $candidate->ID === $requested_form_id ) {
					return $candidate;
				}
			}
			return new WP_Error( 'psrm_api_form_not_allowed', __( 'That form does not exist or does not have API access enabled.', 'psbdx-smart-report-management' ), array( 'status' => 404 ) );
		}

		if ( 1 === count( $api_forms ) ) {
			// Only one form has API access — skip form selection entirely.
			return $api_forms[0];
		}

		return new WP_Error(
			'psrm_api_form_required',
			__( 'More than one form has API access enabled — pass a form_id.', 'psbdx-smart-report-management' ),
			array(
				'status' => 400,
				'forms'  => array_map(
					function ( $f ) {
						return array( 'form_id' => $f->ID, 'title' => get_the_title( $f ) );
					},
					$api_forms
				),
			)
		);
	}

	/**
	 * Builds the field-schema portion of an API response for a form.
	 *
	 * @since 1.4.3
	 * @param  int $form_id  Report form post ID.
	 * @return array[]
	 */
	private function fields_for_response( $form_id ) {
		$out = array();

		foreach ( $this->get_api_fields( $form_id ) as $field ) {
			$entry = array(
				'handle'   => $field['handle'] ?? '',
				'type'     => $field['type'] ?? 'text',
				'label'    => $field['label'] ?? '',
				// Reflects what /submit actually enforces, which — for an
				// email field — is always true regardless of the form's
				// own "Required" toggle. See is_mandatory_for_api().
				'required' => $this->is_mandatory_for_api( $field ),
			);

			if ( ! empty( $field['choices'] ) ) {
				$entry['choices'] = array_values( $field['choices'] );
			}

			if ( 'email' === $entry['type'] ) {
				$entry['requires_otp'] = true;
			}

			$out[] = $entry;
		}

		return $out;
	}

	// =========================================================================
	// SESSION LOADING
	// =========================================================================

	/**
	 * Loads and validates a session by its token for a given API key.
	 *
	 * @since 1.4.3
	 * @param  string $token       Session token from the caller.
	 * @param  object $key_row     Authenticated api_keys row.
	 * @return object|WP_Error
	 */
	private function load_session( $token, $key_row ) {
		global $wpdb;

		$token = sanitize_text_field( (string) $token );

		if ( '' === $token ) {
			return new WP_Error( 'psrm_api_session_required', __( 'session_id is required.', 'psbdx-smart-report-management' ), array( 'status' => 400 ) );
		}

		$session = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::sessions_table() . ' WHERE session_token = %s', $token ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table name comes from self::sessions_table(), not user input; the value IS parameterized via $wpdb->prepare()'s %s.

		if ( ! $session || (int) $session->api_key_id !== (int) $key_row->id ) {
			return new WP_Error( 'psrm_api_session_invalid', __( 'Unknown session.', 'psbdx-smart-report-management' ), array( 'status' => 404 ) );
		}

		if ( 'in_progress' !== $session->status ) {
			return new WP_Error( 'psrm_api_session_closed', __( 'This session is no longer active.', 'psbdx-smart-report-management' ), array( 'status' => 409 ) );
		}

		if ( strtotime( $session->expires_at . ' UTC' ) < time() ) {
			$wpdb->update( self::sessions_table(), array( 'status' => 'expired' ), array( 'id' => $session->id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- $wpdb->update() prepares/escapes internally; a single-row write doesn't need caching.
			return new WP_Error( 'psrm_api_session_expired', __( 'This session has expired. Start a new one.', 'psbdx-smart-report-management' ), array( 'status' => 409 ) );
		}

		return $session;
	}

	// =========================================================================
	// ROUTE: /field
	// =========================================================================

	/**
	 * POST /field — fills a single field on an open session.
	 *
	 * @since  1.4.3
	 * @param  WP_REST_Request $request  Current request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function route_field( WP_REST_Request $request ) {
		$key_row = $this->authenticate_request( $request );
		if ( is_wp_error( $key_row ) ) {
			return $key_row;
		}

		$session = $this->load_session( $request->get_param( 'session_id' ), $key_row );
		if ( is_wp_error( $session ) ) {
			return $session;
		}

		$handle = sanitize_key( (string) $request->get_param( 'handle' ) );
		$value  = $request->get_param( 'value' );

		$field_def = null;
		foreach ( $this->get_api_fields( $session->form_id ) as $field ) {
			if ( ( $field['handle'] ?? '' ) === $handle ) {
				$field_def = $field;
				break;
			}
		}

		if ( ! $field_def ) {
			return new WP_Error( 'psrm_api_unknown_field', __( 'Unknown field handle for this form.', 'psbdx-smart-report-management' ), array( 'status' => 400 ) );
		}

		$type = $field_def['type'];

		// ── Email fields go through OTP instead of being stored directly ──────
		if ( 'email' === $type ) {
			return $this->handle_email_field( $session, $handle, $value );
		}

		$validated = $this->validate_and_sanitize_field( $field_def, $value );
		if ( is_wp_error( $validated ) ) {
			return $validated;
		}

		global $wpdb;

		$field_values = json_decode( $session->field_values, true );
		$field_values = is_array( $field_values ) ? $field_values : array();
		$field_values[ $handle ] = $validated;

		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- $wpdb->update() prepares/escapes internally; a single-row write doesn't need caching.
			self::sessions_table(),
			array(
				'field_values' => wp_json_encode( $field_values ),
				'updated_at'   => current_time( 'mysql', true ),
			),
			array( 'id' => $session->id ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		return rest_ensure_response(
			array(
				'handle'          => $handle,
				'accepted'        => true,
				'missing_required' => $this->missing_required_handles( $session->form_id, $field_values, false ),
			)
		);
	}

	/**
	 * Validates and sanitizes a submitted value against a field's schema.
	 *
	 * @since  1.4.3
	 * @param  array $field_def  Field schema entry.
	 * @param  mixed $value      Raw submitted value.
	 * @return string|WP_Error   Sanitized value ready for storage.
	 */
	private function validate_and_sanitize_field( array $field_def, $value ) {
		$type  = $field_def['type'];
		$label = $field_def['label'] ?? $field_def['handle'];

		switch ( $type ) {
			case 'name':
				if ( is_array( $value ) ) {
					$first = sanitize_text_field( (string) ( $value['first'] ?? '' ) );
					$last  = sanitize_text_field( (string) ( $value['last'] ?? '' ) );
				} else {
					$parts = explode( ' ', sanitize_text_field( (string) $value ), 2 );
					$first = $parts[0] ?? '';
					$last  = $parts[1] ?? '';
				}
				return trim( $first . ' ' . $last );

			case 'number':
				if ( '' !== (string) $value && ! is_numeric( $value ) ) {
					/* translators: %s: field label */
					return new WP_Error( 'psrm_api_invalid_value', sprintf( __( '"%s" must be a number.', 'psbdx-smart-report-management' ), $label ), array( 'status' => 400 ) );
				}
				return sanitize_text_field( (string) $value );

			case 'mobile':
				return sanitize_text_field( (string) $value );

			case 'paragraph':
				return sanitize_textarea_field( (string) $value );

			case 'select':
			case 'radio':
				$choices = array_map( 'strval', $field_def['choices'] ?? array() );
				$value   = sanitize_text_field( (string) $value );
				if ( ! empty( $choices ) && ! in_array( $value, $choices, true ) ) {
					/* translators: %s: field label */
					return new WP_Error( 'psrm_api_invalid_value', sprintf( __( '"%s" is not one of the allowed choices.', 'psbdx-smart-report-management' ), $label ), array( 'status' => 400 ) );
				}
				return $value;

			case 'checkbox':
				$choices  = array_map( 'strval', $field_def['choices'] ?? array() );
				$selected = is_array( $value ) ? array_map( 'sanitize_text_field', $value ) : array_filter( array_map( 'trim', explode( ',', (string) $value ) ) );
				if ( ! empty( $choices ) ) {
					foreach ( $selected as $s ) {
						if ( ! in_array( $s, $choices, true ) ) {
							/* translators: %s: field label */
							return new WP_Error( 'psrm_api_invalid_value', sprintf( __( '"%s" contains a choice that is not allowed.', 'psbdx-smart-report-management' ), $label ), array( 'status' => 400 ) );
						}
					}
				}
				return implode( ', ', $selected );

			default: // text and anything unrecognised.
				return sanitize_text_field( (string) $value );
		}
	}

	/**
	 * Handles filling an "email" field: sends an OTP instead of storing
	 * the address directly.
	 *
	 * Throttled two ways — see check_otp_send_throttle() — so this can't
	 * be used to repeatedly email an arbitrary address for free.
	 *
	 * @since  1.4.3
	 * @param  object $session  Session row.
	 * @param  string $handle   Field handle.
	 * @param  mixed  $value    Submitted email address.
	 * @return WP_REST_Response|WP_Error
	 */
	private function handle_email_field( $session, $handle, $value ) {
		$email = sanitize_email( (string) $value );

		if ( ! is_email( $email ) ) {
			return new WP_Error( 'psrm_api_invalid_email', __( 'That is not a valid email address.', 'psbdx-smart-report-management' ), array( 'status' => 400 ) );
		}

		$throttled = $this->check_otp_send_throttle( $session );
		if ( is_wp_error( $throttled ) ) {
			return $throttled;
		}

		$code     = str_pad( (string) wp_rand( 0, 999999 ), 6, '0', STR_PAD_LEFT );
		$otp_hash = hash( 'sha256', $code . $session->session_token );

		global $wpdb;
		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- $wpdb->update() prepares/escapes internally; a single-row write doesn't need caching.
			self::sessions_table(),
			array(
				'pending_email_handle' => $handle,
				'pending_email_value'  => $email,
				'otp_hash'             => $otp_hash,
				'otp_expires_at'       => gmdate( 'Y-m-d H:i:s', time() + self::OTP_TTL ),
				'otp_verified'         => 0,
				'otp_attempts'         => 0,
				'updated_at'           => current_time( 'mysql', true ),
			),
			array( 'id' => $session->id ),
			array( '%s', '%s', '%s', '%s', '%d', '%d', '%s' ),
			array( '%d' )
		);

		$subject = sprintf(
			/* translators: %s: site name */
			__( 'Your verification code for %s', 'psbdx-smart-report-management' ),
			get_bloginfo( 'name' )
		);
		$body = sprintf(
			/* translators: %s: 6-digit code */
			__( "Your verification code is: %s\n\nThis code expires in 10 minutes.", 'psbdx-smart-report-management' ),
			$code
		);

		wp_mail( $email, $subject, $body );
		$this->record_otp_send( $session );

		return rest_ensure_response(
			array(
				'handle'       => $handle,
				'accepted'     => false,
				'requires_otp' => true,
				'message'      => __( 'A verification code has been sent to this email address. Call /verify-otp with the code before submitting.', 'psbdx-smart-report-management' ),
			)
		);
	}

	/**
	 * Checks both OTP-send limits before sending another code: how many
	 * times this specific session has requested one, and how many this
	 * session's API key has triggered in total recently.
	 *
	 * @since 1.4.4
	 * @param  object $session  Session row.
	 * @return true|WP_Error
	 */
	private function check_otp_send_throttle( $session ) {
		$session_count = (int) get_transient( self::otp_session_throttle_key( $session->session_token ) );
		if ( $session_count >= self::MAX_OTP_SENDS_PER_SESSION ) {
			return new WP_Error(
				'psrm_api_otp_throttled',
				__( 'Too many verification codes requested for this session. Start a new session and try again.', 'psbdx-smart-report-management' ),
				array( 'status' => 429 )
			);
		}

		$key_count = (int) get_transient( self::otp_key_throttle_key( $session->api_key_id ) );
		if ( $key_count >= self::MAX_OTP_SENDS_PER_KEY ) {
			return new WP_Error(
				'psrm_api_otp_throttled',
				__( 'Too many verification codes have been sent using this API key recently. Try again shortly.', 'psbdx-smart-report-management' ),
				array( 'status' => 429 )
			);
		}

		return true;
	}

	/**
	 * Records one OTP send against both throttle counters.
	 *
	 * @since 1.4.4
	 * @param  object $session  Session row.
	 * @return void
	 */
	private function record_otp_send( $session ) {
		$session_key = self::otp_session_throttle_key( $session->session_token );
		set_transient( $session_key, (int) get_transient( $session_key ) + 1, self::SESSION_TTL );

		$key_key = self::otp_key_throttle_key( $session->api_key_id );
		set_transient( $key_key, (int) get_transient( $key_key ) + 1, self::OTP_VOLUME_WINDOW );
	}

	/**
	 * Transient key for the per-session OTP-send counter.
	 *
	 * @since 1.4.4
	 * @param  string $session_token  Session token.
	 * @return string
	 */
	private static function otp_session_throttle_key( $session_token ) {
		return 'psrm_api_otpsess_' . md5( 'psbdx_srm|' . $session_token );
	}

	/**
	 * Transient key for the per-API-key OTP-send volume counter.
	 *
	 * @since 1.4.4
	 * @param  int $api_key_id  api_keys row ID.
	 * @return string
	 */
	private static function otp_key_throttle_key( $api_key_id ) {
		return 'psrm_api_otpvol_' . (int) $api_key_id;
	}

	// =========================================================================
	// ROUTE: /verify-otp
	// =========================================================================

	/**
	 * POST /verify-otp — confirms the emailed code for a pending email field.
	 *
	 * @since  1.4.3
	 * @param  WP_REST_Request $request  Current request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function route_verify_otp( WP_REST_Request $request ) {
		$key_row = $this->authenticate_request( $request );
		if ( is_wp_error( $key_row ) ) {
			return $key_row;
		}

		$session = $this->load_session( $request->get_param( 'session_id' ), $key_row );
		if ( is_wp_error( $session ) ) {
			return $session;
		}

		if ( '' === $session->pending_email_handle ) {
			return new WP_Error( 'psrm_api_no_pending_otp', __( 'There is no email field waiting for verification on this session.', 'psbdx-smart-report-management' ), array( 'status' => 400 ) );
		}

		if ( strtotime( $session->otp_expires_at . ' UTC' ) < time() ) {
			return new WP_Error( 'psrm_api_otp_expired', __( 'That code has expired. Submit the email field again to get a new one.', 'psbdx-smart-report-management' ), array( 'status' => 410 ) );
		}

		if ( (int) $session->otp_attempts >= 5 ) {
			return new WP_Error( 'psrm_api_otp_locked', __( 'Too many incorrect attempts. Submit the email field again to get a new code.', 'psbdx-smart-report-management' ), array( 'status' => 429 ) );
		}

		$code = sanitize_text_field( (string) $request->get_param( 'code' ) );
		$hash = hash( 'sha256', $code . $session->session_token );

		global $wpdb;

		if ( ! hash_equals( (string) $session->otp_hash, $hash ) ) {
			$wpdb->update( self::sessions_table(), array( 'otp_attempts' => (int) $session->otp_attempts + 1 ), array( 'id' => $session->id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- $wpdb->update() prepares/escapes internally; a single-row write doesn't need caching.
			return new WP_Error( 'psrm_api_otp_invalid', __( 'Incorrect code.', 'psbdx-smart-report-management' ), array( 'status' => 400 ) );
		}

		$field_values = json_decode( $session->field_values, true );
		$field_values = is_array( $field_values ) ? $field_values : array();
		$field_values[ $session->pending_email_handle ] = $session->pending_email_value;

		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- $wpdb->update() prepares/escapes internally; a single-row write doesn't need caching.
			self::sessions_table(),
			array(
				'field_values'          => wp_json_encode( $field_values ),
				'otp_verified'          => 1,
				'pending_email_handle'  => '',
				'pending_email_value'   => '',
				'otp_hash'              => '',
				'updated_at'            => current_time( 'mysql', true ),
			),
			array( 'id' => $session->id ),
			array( '%s', '%d', '%s', '%s', '%s', '%s' ),
			array( '%d' )
		);

		return rest_ensure_response(
			array(
				'verified'          => true,
				'handle'            => $session->pending_email_handle,
				'missing_required'  => $this->missing_required_handles( $session->form_id, $field_values, true ),
			)
		);
	}

	/**
	 * Whether a field must be filled (and, for email, OTP-verified) before
	 * an API session can submit — used by missing_required_handles() and
	 * reflected back in the field schema shown to callers.
	 *
	 * An email field is always treated as mandatory for the API, even if
	 * the form itself has that field's "Required" toggle off: an
	 * unverified email can't reliably be used to link the resulting
	 * report back to a real person, which matters more for a
	 * programmatic caller than it does for the ordinary frontend form.
	 *
	 * @since 1.4.4
	 * @param  array $field  Field schema entry.
	 * @return bool
	 */
	private function is_mandatory_for_api( array $field ) {
		return ! empty( $field['required'] ) || 'email' === ( $field['type'] ?? '' );
	}

	/**
	 * Handles of required fields still missing (used to hint the caller
	 * what to send next).
	 *
	 * @since  1.4.3
	 * @param  int   $form_id       Report form post ID.
	 * @param  array $field_values  Currently stored field values.
	 * @param  bool  $email_verified  Whether the pending email (if any) was just verified.
	 * @return string[]
	 */
	private function missing_required_handles( $form_id, array $field_values, $email_verified ) {
		$missing = array();

		foreach ( $this->get_api_fields( $form_id ) as $field ) {
			if ( ! $this->is_mandatory_for_api( $field ) ) {
				continue;
			}
			$handle = $field['handle'] ?? '';
			if ( '' === $handle ) {
				continue;
			}
			if ( ! isset( $field_values[ $handle ] ) || '' === trim( (string) $field_values[ $handle ] ) ) {
				$missing[] = $handle;
			}
		}

		return $missing;
	}

	// =========================================================================
	// ROUTE: /submit
	// =========================================================================

	/**
	 * POST /submit — finalizes a session into an actual report.
	 *
	 * @since  1.4.3
	 * @param  WP_REST_Request $request  Current request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function route_submit( WP_REST_Request $request ) {
		$key_row = $this->authenticate_request( $request );
		if ( is_wp_error( $key_row ) ) {
			return $key_row;
		}

		$session = $this->load_session( $request->get_param( 'session_id' ), $key_row );
		if ( is_wp_error( $session ) ) {
			return $session;
		}

		$field_values = json_decode( $session->field_values, true );
		$field_values = is_array( $field_values ) ? $field_values : array();

		$missing = $this->missing_required_handles( $session->form_id, $field_values, false );
		if ( ! empty( $missing ) ) {
			return new WP_Error(
				'psrm_api_incomplete',
				__( 'Required fields are still missing (or an email field is awaiting OTP verification).', 'psbdx-smart-report-management' ),
				array( 'status' => 400, 'missing' => $missing )
			);
		}

		$schema      = $this->get_api_fields( $session->form_id );
		$content_parts = array();
		$reporter_email = '';

		foreach ( $schema as $field ) {
			$handle = $field['handle'] ?? '';
			$label  = $field['label'] ?? $handle;
			$value  = $field_values[ $handle ] ?? '';

			if ( '' === $handle || '' === trim( (string) $value ) ) {
				continue;
			}

			if ( 'email' === $field['type'] ) {
				$reporter_email = $value;
			}

			$content_parts[] = '<strong>' . esc_html( $label ) . ':</strong> '
				. ( 'paragraph' === $field['type'] ? '<br>' . nl2br( esc_html( $value ) ) : esc_html( $value ) );
		}

		$post_title = $reporter_email ? $reporter_email : __( 'API submission', 'psbdx-smart-report-management' );

		$log_id = wp_insert_post(
			array(
				'post_type'    => 'psbdx_report_log',
				'post_title'   => sanitize_text_field( $post_title ),
				'post_content' => wp_kses_post( implode( '<br>', $content_parts ) ),
				'post_status'  => 'publish',
				'post_author'  => 0,
			),
			true
		);

		if ( is_wp_error( $log_id ) ) {
			return new WP_Error( 'psrm_api_save_failed', __( 'Failed to save the report.', 'psbdx-smart-report-management' ), array( 'status' => 500 ) );
		}

		$ticket_id = PSBDX_SRM_Helpers::generate_ticket_id();
		update_post_meta( $log_id, PSBDX_SRM_Helpers::TICKET_ID_META, $ticket_id );
		update_post_meta( $log_id, '_psbdx_reporter_email', $reporter_email );
		update_post_meta( $log_id, '_psbdx_source_url', '' );
		update_post_meta( $log_id, '_psbdx_source_title', __( 'API', 'psbdx-smart-report-management' ) );
		update_post_meta( $log_id, PSBDX_SRM_Replies::SOURCE_FORM_META, $session->form_id );
		PSBDX_SRM_Helpers::update_report_status( $log_id, 'Processing', array( 'source' => 'api' ) );

		// A REST response can't be "sent then continued" the way the AJAX
		// handlers can (the REST server only writes the response after
		// this callback returns), so classification, auto-reply, and email
		// notifications are handed off to a separate WP-Cron request here
		// instead of running inline — the caller gets its ticket_id back
		// right away, and a slow AI provider can no longer make an API
		// submission hang or fatal.
		wp_schedule_single_event( time(), PSBDX_SRM_Ajax::DEFERRED_HOOK, array( 'submission', $log_id ) );
		if ( function_exists( 'spawn_cron' ) ) {
			spawn_cron();
		}

		global $wpdb;
		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- $wpdb->update() prepares/escapes internally; a single-row write doesn't need caching.
			self::sessions_table(),
			array(
				'status'     => 'completed',
				'ticket_id'  => $ticket_id,
				'updated_at' => current_time( 'mysql', true ),
			),
			array( 'id' => $session->id ),
			array( '%s', '%s', '%s' ),
			array( '%d' )
		);

		return rest_ensure_response(
			array(
				'ticket_id' => $ticket_id,
				'status'    => PSBDX_SRM_Helpers::get_status_label( 'Processing' ),
				'message'   => __( 'Report submitted successfully.', 'psbdx-smart-report-management' ),
			)
		);
	}

	// =========================================================================
	// ROUTE: /ticket/{ticket_id}/status
	// =========================================================================

	/**
	 * GET /ticket/{ticket_id}/status — looks up a ticket's current status.
	 *
	 * @since  1.4.3
	 * @param  WP_REST_Request $request  Current request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function route_ticket_status( WP_REST_Request $request ) {
		$key_row = $this->authenticate_request( $request );
		if ( is_wp_error( $key_row ) ) {
			return $key_row;
		}

		$ticket_id = sanitize_text_field( (string) $request->get_param( 'ticket_id' ) );
		$report_id = PSBDX_SRM_Helpers::get_report_by_ticket_id( $ticket_id );

		if ( ! $report_id ) {
			return new WP_Error( 'psrm_api_ticket_not_found', __( 'No report found with that ticket ID.', 'psbdx-smart-report-management' ), array( 'status' => 404 ) );
		}

		$status = get_post_meta( $report_id, '_psbdx_report_status', true );

		return rest_ensure_response(
			array(
				'ticket_id'     => $ticket_id,
				'status'        => $status ?: 'Processing',
				'status_label'  => PSBDX_SRM_Helpers::get_status_label( $status ?: 'Processing' ),
				'category'      => get_post_meta( $report_id, '_psbdx_report_category', true ),
				'priority'      => get_post_meta( $report_id, '_psbdx_report_priority', true ),
				'created'       => get_the_date( 'c', $report_id ),
				'updated'       => get_the_modified_date( 'c', $report_id ),
			)
		);
	}

	// =========================================================================
	// ADMIN: SETTINGS TAB
	// =========================================================================

	/**
	 * Adds the "API" tab to the Settings page's tab list.
	 *
	 * @since 1.4.3
	 * @param  array $tabs  Existing tab slug => label map.
	 * @return array
	 */
	public function add_settings_tab( $tabs ) {
		$tabs['api'] = __( 'API', 'psbdx-smart-report-management' );
		return $tabs;
	}

	/**
	 * Handles creating/revoking/reactivating/deleting an API key from the
	 * Settings → API screen.
	 *
	 * @since 1.4.3
	 * @return void
	 */
	public function handle_admin_actions() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		global $wpdb;

		if ( isset( $_POST['psbdx_srm_api_save_behavior'] ) ) {
			check_admin_referer( 'psbdx_srm_api_behavior' );

			update_option( self::ALWAYS_REQUIRE_EMAIL_OPTION, ! empty( $_POST['psbdx_api_always_require_email'] ) ? 'yes' : 'no', false );

			add_settings_error( 'psbdx_srm_settings', 'api_behavior_saved', __( 'API settings saved.', 'psbdx-smart-report-management' ), 'success' );
			return;
		}

		if ( isset( $_POST['psbdx_srm_api_create'] ) ) {
			check_admin_referer( 'psbdx_srm_api_create' );

			$label   = isset( $_POST['psbdx_api_label'] ) ? sanitize_text_field( wp_unslash( $_POST['psbdx_api_label'] ) ) : '';
			$domains = isset( $_POST['psbdx_api_domains'] ) ? sanitize_textarea_field( wp_unslash( $_POST['psbdx_api_domains'] ) ) : '';
			$ips     = isset( $_POST['psbdx_api_ips'] ) ? sanitize_textarea_field( wp_unslash( $_POST['psbdx_api_ips'] ) ) : '';

			$key_id = 'psrm_' . strtolower( wp_generate_password( 20, false, false ) );
			$secret = wp_generate_password( 40, false, false );

			$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- $wpdb->insert() prepares/escapes internally; a single-row write doesn't need caching.
				self::keys_table(),
				array(
					'key_id'          => $key_id,
					'secret_hash'     => self::hash_secret( $secret ),
					'label'           => '' !== $label ? $label : __( 'Unnamed key', 'psbdx-smart-report-management' ),
					'allowed_domains' => $domains,
					'allowed_ips'     => $ips,
					'status'          => 'active',
					'created_at'      => current_time( 'mysql', true ),
				),
				array( '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
			);

			// Stash the plaintext secret for a one-time display; it can never
			// be recovered again after this request since only its hash is stored.
			set_transient( self::NEW_KEY_TRANSIENT_PREFIX . get_current_user_id(), array( 'key_id' => $key_id, 'secret' => $secret ), 5 * MINUTE_IN_SECONDS );

			add_settings_error( 'psbdx_srm_settings', 'api_key_created', __( 'API key created. Copy the secret now — it will not be shown again.', 'psbdx-smart-report-management' ), 'success' );
			return;
		}

		if ( isset( $_POST['psbdx_srm_api_toggle'] ) ) {
			check_admin_referer( 'psbdx_srm_api_toggle' );
			$id     = absint( $_POST['api_key_row_id'] ?? 0 );
			$status = ( 'active' === sanitize_key( $_POST['new_status'] ?? '' ) ) ? 'active' : 'revoked';
			$wpdb->update( self::keys_table(), array( 'status' => $status ), array( 'id' => $id ), array( '%s' ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- $wpdb->update() prepares/escapes internally; a single-row write doesn't need caching.
			add_settings_error( 'psbdx_srm_settings', 'api_key_updated', __( 'API key updated.', 'psbdx-smart-report-management' ), 'success' );
			return;
		}

		if ( isset( $_POST['psbdx_srm_api_delete'] ) ) {
			check_admin_referer( 'psbdx_srm_api_delete' );
			$id = absint( $_POST['api_key_row_id'] ?? 0 );
			$wpdb->delete( self::keys_table(), array( 'id' => $id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- $wpdb->delete() prepares/escapes internally; a single-row delete doesn't need caching.
			add_settings_error( 'psbdx_srm_settings', 'api_key_deleted', __( 'API key deleted.', 'psbdx-smart-report-management' ), 'success' );
			return;
		}
	}

	/**
	 * Renders the Settings → API tab content.
	 *
	 * Hooked to `psbdx_srm_settings_tab_content`, so it must check the
	 * current tab itself.
	 *
	 * @since 1.4.3
	 * @param  string $tab  Current tab slug.
	 * @return void
	 */
	public function render_settings_tab( $tab ) {
		if ( 'api' !== $tab ) {
			return;
		}

		global $wpdb;

		$keys      = $wpdb->get_results( 'SELECT * FROM ' . self::keys_table() . ' ORDER BY created_at DESC' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table name comes from self::keys_table(), not user input; no user-supplied values in this query, an admin-only settings screen list.
		$new_key   = get_transient( self::NEW_KEY_TRANSIENT_PREFIX . get_current_user_id() );
		$base_url  = trailingslashit( rest_url( self::API_NAMESPACE ) );

		$api_forms = array();
		foreach ( PSBDX_SRM_Helpers::get_published_report_forms() as $form ) {
			if ( 'yes' === get_post_meta( $form->ID, self::API_ENABLED_META, true ) ) {
				$api_forms[] = $form;
			}
		}

		$hosting_status  = PSBDX_SRM_Hosting_Guard::get_status();
		$hostname_flag   = PSBDX_SRM_Hosting_Guard::hostname_looks_restricted();
		$overridden      = (bool) get_option( PSBDX_SRM_Hosting_Guard::OVERRIDE_OPTION );
		$api_is_active   = PSBDX_SRM_Hosting_Guard::api_should_be_active();
		?>
		<?php if ( ! $api_is_active || ( $hostname_flag && empty( $hosting_status['reachable'] ) ) ) : ?>
			<div class="notice notice-warning" style="padding:14px 16px;margin:0 0 16px;">
				<p style="margin-top:0;">
					<strong><span class="dashicons dashicons-warning" style="color:#dba617;"></span>
					<?php esc_html_e( 'The external API looks unavailable on this hosting.', 'psbdx-smart-report-management' ); ?></strong>
				</p>
				<p>
					<?php
					if ( $hostname_flag ) {
						esc_html_e( 'This site\'s domain matches a hosting pattern known to block inbound API-style requests from other domains (common on some free hosting providers, e.g. the InfinityFree family) — and a', 'psbdx-smart-report-management' );
					} else {
						esc_html_e( 'A', 'psbdx-smart-report-management' );
					}
					?>
					<?php esc_html_e( 'live test — WordPress asking itself to fetch its own API over the public internet, the same round trip an external caller would make — did not get back a normal reply.', 'psbdx-smart-report-management' ); ?>
					<?php if ( ! empty( $hosting_status['reason'] ) ) : ?>
						<br><em><?php echo esc_html( $hosting_status['reason'] ); ?></em>
					<?php endif; ?>
				</p>
				<p><?php esc_html_e( 'The API endpoints (besides this test itself) are switched off so the feature doesn\'t look configured while silently failing for every external caller. Existing keys are untouched.', 'psbdx-smart-report-management' ); ?></p>
				<p>
					<button type="button" class="button" id="psbdx-api-hosting-recheck"><?php esc_html_e( 'Re-check now', 'psbdx-smart-report-management' ); ?></button>
					<label style="margin-left:12px;">
						<input type="checkbox" id="psbdx-api-hosting-override" <?php checked( $overridden ); ?>>
						<?php esc_html_e( 'I\'ve verified this works on my host — enable anyway', 'psbdx-smart-report-management' ); ?>
					</label>
					<span id="psbdx-api-hosting-status" style="margin-left:8px;color:#646970;"></span>
				</p>
			</div>
			<script>
			(function () {
				var recheckBtn = document.getElementById( 'psbdx-api-hosting-recheck' );
				var overrideBox = document.getElementById( 'psbdx-api-hosting-override' );
				var statusEl = document.getElementById( 'psbdx-api-hosting-status' );
				var nonce = '<?php echo esc_js( wp_create_nonce( 'psbdx_srm_api_hosting' ) ); ?>';
				var ajaxUrl = '<?php echo esc_js( admin_url( 'admin-ajax.php' ) ); ?>';

				function post( action, extra ) {
					var body = new URLSearchParams( Object.assign( { action: action, _wpnonce: nonce }, extra || {} ) );
					return fetch( ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body } ).then( function ( r ) { return r.json(); } );
				}

				if ( recheckBtn ) {
					recheckBtn.addEventListener( 'click', function () {
						statusEl.textContent = '<?php echo esc_js( __( 'Checking…', 'psbdx-smart-report-management' ) ); ?>';
						post( 'psbdx_srm_recheck_api_hosting' ).then( function ( res ) {
							if ( res && res.success && res.data && res.data.reachable ) {
								statusEl.textContent = '<?php echo esc_js( __( 'Reachable now — reloading…', 'psbdx-smart-report-management' ) ); ?>';
								window.location.reload();
							} else {
								statusEl.textContent = '<?php echo esc_js( __( 'Still unreachable.', 'psbdx-smart-report-management' ) ); ?>';
							}
						} );
					} );
				}

				if ( overrideBox ) {
					overrideBox.addEventListener( 'change', function () {
						post( 'psbdx_srm_set_api_override', { enabled: overrideBox.checked ? '1' : '' } ).then( function () {
							window.location.reload();
						} );
					} );
				}
			})();
			</script>
		<?php endif; ?>

		<div class="psrm-settings-section">
			<div class="psrm-settings-section-header">
				<span class="dashicons dashicons-rest-api" aria-hidden="true"></span>
				<strong><?php esc_html_e( 'API Endpoints', 'psbdx-smart-report-management' ); ?></strong>
			</div>
			<div class="psrm-settings-section-body">
				<p class="psrm-hint"><?php esc_html_e( 'Every call below requires the X-PSRM-Api-Key and X-PSRM-Api-Secret headers (or an Authorization: Bearer key:secret header) from one of the keys below.', 'psbdx-smart-report-management' ); ?></p>
				<table class="widefat" style="max-width:900px;">
					<tbody>
						<tr><td><strong>POST</strong></td><td><code><?php echo esc_html( $base_url . 'start' ); ?></code></td><td><?php esc_html_e( 'Start a session; returns a session_id + field schema.', 'psbdx-smart-report-management' ); ?></td></tr>
						<tr><td><strong>POST</strong></td><td><code><?php echo esc_html( $base_url . 'field' ); ?></code></td><td><?php esc_html_e( 'Fill one field: session_id, handle, value.', 'psbdx-smart-report-management' ); ?></td></tr>
						<tr><td><strong>POST</strong></td><td><code><?php echo esc_html( $base_url . 'verify-otp' ); ?></code></td><td><?php esc_html_e( 'Confirm an emailed code: session_id, code.', 'psbdx-smart-report-management' ); ?></td></tr>
						<tr><td><strong>POST</strong></td><td><code><?php echo esc_html( $base_url . 'submit' ); ?></code></td><td><?php esc_html_e( 'Finalize: session_id. Returns ticket_id.', 'psbdx-smart-report-management' ); ?></td></tr>
						<tr><td><strong>GET</strong></td><td><code><?php echo esc_html( $base_url . 'ticket/{ticket_id}/status' ); ?></code></td><td><?php esc_html_e( 'Look up a ticket\'s current status.', 'psbdx-smart-report-management' ); ?></td></tr>
					</tbody>
				</table>

				<?php if ( ! empty( $api_forms ) ) : ?>
					<p style="margin-top:12px;"><strong><?php esc_html_e( 'Forms currently enabled for API access:', 'psbdx-smart-report-management' ); ?></strong></p>
					<ul style="list-style:disc;margin-left:20px;">
						<?php foreach ( $api_forms as $form ) : ?>
							<li><?php echo esc_html( get_the_title( $form ) ); ?> (ID <?php echo (int) $form->ID; ?>) — <a href="<?php echo esc_url( get_edit_post_link( $form->ID ) ); ?>"><?php esc_html_e( 'edit', 'psbdx-smart-report-management' ); ?></a></li>
						<?php endforeach; ?>
					</ul>
				<?php else : ?>
					<p class="description"><?php esc_html_e( 'No form has API access enabled yet — turn it on under a form\'s "API Access" section.', 'psbdx-smart-report-management' ); ?></p>
				<?php endif; ?>
			</div>
		</div>

		<div class="psrm-settings-section">
			<div class="psrm-settings-section-header">
				<span class="dashicons dashicons-shield" aria-hidden="true"></span>
				<strong><?php esc_html_e( 'API Behavior', 'psbdx-smart-report-management' ); ?></strong>
			</div>
			<div class="psrm-settings-section-body">
				<form method="post">
					<?php wp_nonce_field( 'psbdx_srm_api_behavior' ); ?>
					<p>
						<label>
							<input type="checkbox" name="psbdx_api_always_require_email" value="1" <?php checked( 'yes' === get_option( self::ALWAYS_REQUIRE_EMAIL_OPTION ) ); ?>>
							<?php esc_html_e( 'Always require a verified email on every API submission', 'psbdx-smart-report-management' ); ?>
						</label>
					</p>
					<p class="description">
						<?php esc_html_e( 'When on, every API-enabled form gets an extra "Verified Email" field added to it for API callers — regardless of whether that form already collects an email of its own. It always requires OTP verification before /submit will succeed, guaranteeing every report created through the API can be reliably linked to a real email address. When off, only a form\'s own email field (if it has one) is subject to verification.', 'psbdx-smart-report-management' ); ?>
					</p>
					<?php submit_button( __( 'Save', 'psbdx-smart-report-management' ), 'secondary', 'psbdx_srm_api_save_behavior' ); ?>
				</form>
			</div>
		</div>

		<?php if ( $new_key ) : ?>
			<div class="notice notice-warning" style="padding:12px;margin:16px 0;">
				<p><strong><?php esc_html_e( 'Copy this secret now — it will not be shown again:', 'psbdx-smart-report-management' ); ?></strong></p>
				<p>
					<?php esc_html_e( 'API Key:', 'psbdx-smart-report-management' ); ?> <code><?php echo esc_html( $new_key['key_id'] ); ?></code><br>
					<?php esc_html_e( 'API Secret:', 'psbdx-smart-report-management' ); ?> <code><?php echo esc_html( $new_key['secret'] ); ?></code>
				</p>
			</div>
			<?php delete_transient( self::NEW_KEY_TRANSIENT_PREFIX . get_current_user_id() ); ?>
		<?php endif; ?>

		<div class="psrm-settings-section">
			<div class="psrm-settings-section-header">
				<span class="dashicons dashicons-admin-network" aria-hidden="true"></span>
				<strong><?php esc_html_e( 'Create a New API Key', 'psbdx-smart-report-management' ); ?></strong>
			</div>
			<div class="psrm-settings-section-body">
				<?php if ( ! $api_is_active ) : ?>
					<p class="description"><?php esc_html_e( 'Hidden while the API is switched off for this hosting — see the notice above. Turn on the override, or fix the underlying restriction, to create a key.', 'psbdx-smart-report-management' ); ?></p>
				<?php else : ?>
					<form method="post">
						<?php wp_nonce_field( 'psbdx_srm_api_create' ); ?>
						<table class="form-table">
							<tr>
								<th><label for="psbdx_api_label"><?php esc_html_e( 'Label', 'psbdx-smart-report-management' ); ?></label></th>
								<td><input type="text" id="psbdx_api_label" name="psbdx_api_label" class="regular-text" placeholder="<?php esc_attr_e( 'e.g. Support Chatbot', 'psbdx-smart-report-management' ); ?>"></td>
							</tr>
							<tr>
								<th><label for="psbdx_api_domains"><?php esc_html_e( 'Allowed domains', 'psbdx-smart-report-management' ); ?></label></th>
								<td>
									<textarea id="psbdx_api_domains" name="psbdx_api_domains" rows="3" class="large-text" placeholder="chat.example.com, app.example.com"></textarea>
									<p class="description"><?php esc_html_e( 'Comma or newline separated. Checked against the caller\'s Origin/Referer header. Leave blank to allow any domain.', 'psbdx-smart-report-management' ); ?></p>
								</td>
							</tr>
							<tr>
								<th><label for="psbdx_api_ips"><?php esc_html_e( 'Allowed server IPs', 'psbdx-smart-report-management' ); ?></label></th>
								<td>
									<textarea id="psbdx_api_ips" name="psbdx_api_ips" rows="3" class="large-text" placeholder="203.0.113.10, 198.51.100.24"></textarea>
									<p class="description"><?php esc_html_e( 'Comma or newline separated. Checked against the caller\'s server IP. Leave blank to allow any IP.', 'psbdx-smart-report-management' ); ?></p>
								</td>
							</tr>
						</table>
						<?php submit_button( __( 'Generate API Key', 'psbdx-smart-report-management' ), 'primary', 'psbdx_srm_api_create' ); ?>
					</form>
				<?php endif; ?>
			</div>
		</div>

		<div class="psrm-settings-section">
			<div class="psrm-settings-section-header">
				<span class="dashicons dashicons-list-view" aria-hidden="true"></span>
				<strong><?php esc_html_e( 'Existing API Keys', 'psbdx-smart-report-management' ); ?></strong>
			</div>
			<div class="psrm-settings-section-body">
				<?php if ( empty( $keys ) ) : ?>
					<p class="description"><?php esc_html_e( 'No API keys yet.', 'psbdx-smart-report-management' ); ?></p>
				<?php else : ?>
					<table class="widefat striped">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Label', 'psbdx-smart-report-management' ); ?></th>
								<th><?php esc_html_e( 'Key ID', 'psbdx-smart-report-management' ); ?></th>
								<th><?php esc_html_e( 'Domains', 'psbdx-smart-report-management' ); ?></th>
								<th><?php esc_html_e( 'IPs', 'psbdx-smart-report-management' ); ?></th>
								<th><?php esc_html_e( 'Status', 'psbdx-smart-report-management' ); ?></th>
								<th><?php esc_html_e( 'Last used', 'psbdx-smart-report-management' ); ?></th>
								<th></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $keys as $k ) : ?>
								<tr>
									<td><?php echo esc_html( $k->label ); ?></td>
									<td><code><?php echo esc_html( $k->key_id ); ?></code></td>
									<td><?php echo esc_html( $k->allowed_domains ?: '—' ); ?></td>
									<td><?php echo esc_html( $k->allowed_ips ?: '—' ); ?></td>
									<td><?php echo esc_html( ucfirst( $k->status ) ); ?></td>
									<td><?php echo esc_html( $k->last_used_at ?: '—' ); ?></td>
									<td>
										<form method="post" style="display:inline;">
											<?php wp_nonce_field( 'psbdx_srm_api_toggle' ); ?>
											<input type="hidden" name="api_key_row_id" value="<?php echo (int) $k->id; ?>">
											<input type="hidden" name="new_status" value="<?php echo 'active' === $k->status ? 'revoked' : 'active'; ?>">
											<button type="submit" name="psbdx_srm_api_toggle" class="button button-small">
												<?php echo 'active' === $k->status ? esc_html__( 'Revoke', 'psbdx-smart-report-management' ) : esc_html__( 'Reactivate', 'psbdx-smart-report-management' ); ?>
											</button>
										</form>
										<form method="post" style="display:inline;" onsubmit="return confirm('<?php echo esc_js( __( 'Delete this API key permanently?', 'psbdx-smart-report-management' ) ); ?>');">
											<?php wp_nonce_field( 'psbdx_srm_api_delete' ); ?>
											<input type="hidden" name="api_key_row_id" value="<?php echo (int) $k->id; ?>">
											<button type="submit" name="psbdx_srm_api_delete" class="button button-small"><?php esc_html_e( 'Delete', 'psbdx-smart-report-management' ); ?></button>
										</form>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}
}
