<?php
/**
 * First-run Setup Wizard for PSBDx Smart Report Management.
 *
 * On a brand-new install, this gates every other SRM admin screen (Report
 * Forms, Report Logs, Settings, Repair & Reset, Support, FAQ, AI Log) behind
 * a short guided setup — the admin is redirected here until they finish or
 * explicitly skip it. Sites upgrading from a version that predates this
 * wizard are never gated (see on_activation() below): only installs with no
 * prior activation record and no existing report forms are considered
 * "first install".
 *
 * @package PSBDx_Smart_Report_Management
 * @since   1.4.5
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class PSBDX_SRM_Setup_Wizard
 *
 * @since 1.4.5
 */
class PSBDX_SRM_Setup_Wizard {

	/**
	 * Hidden admin page slug (registered with a null parent, so it never
	 * shows up in any menu — only reachable via the redirects below or a
	 * direct link).
	 *
	 * @since 1.4.5
	 * @var string
	 */
	const PAGE_SETUP = 'psbdx-srm-setup';

	/**
	 * Option storing whether setup has been finished (or skipped).
	 * 'yes'/'no'. Absence is treated the same as 'no' by is_complete(),
	 * but on_activation() always makes sure it's explicitly set so an
	 * upgrade from an older version is never mistaken for a first install.
	 *
	 * @since 1.4.5
	 * @var string
	 */
	const OPTION_COMPLETE = 'psbdx_srm_setup_complete';

	/**
	 * One-time transient that triggers the redirect-to-wizard on the very
	 * next admin page load after activation (standard "activation redirect"
	 * pattern) — never fires on bulk-activation of multiple plugins.
	 *
	 * @since 1.4.5
	 * @var string
	 */
	const REDIRECT_TRANSIENT = 'psbdx_srm_activation_redirect';

	/**
	 * Nonce action for the wizard form.
	 *
	 * @since 1.4.5
	 * @var string
	 */
	const NONCE_ACTION = 'psbdx_srm_setup_wizard';

	/**
	 * Nonce field name.
	 *
	 * @since 1.4.5
	 * @var string
	 */
	const NONCE_FIELD = 'psbdx_srm_setup_wizard_nonce';

	/**
	 * AJAX action for the "Send a test email" button on the Mailing step.
	 *
	 * @since 1.4.5
	 * @var string
	 */
	const TEST_EMAIL_ACTION = 'psbdx_srm_wizard_test_email';

	/**
	 * Constructor.
	 *
	 * @since 1.4.5
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register_hidden_page' ) );
		add_action( 'admin_init', array( $this, 'maybe_activation_redirect' ), 5 );
		add_action( 'admin_init', array( $this, 'handle_save' ), 8 );
		add_action( 'admin_init', array( $this, 'maybe_enforce_gate' ), 10 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_notices', array( $this, 'render_created_form_notice' ) );
		add_action( 'wp_ajax_' . self::TEST_EMAIL_ACTION, array( $this, 'ajax_send_test_email' ) );
	}

	// =========================================================================
	// ACTIVATION
	// =========================================================================

	/**
	 * Called from the plugin's activation callback. Decides whether this
	 * looks like a genuinely fresh install (no prior setup-complete record
	 * AND no report forms already exist) versus an upgrade of a site that
	 * was already using the plugin before this wizard was added — only the
	 * former gets gated and redirected.
	 *
	 * @since 1.4.5
	 * @return void
	 */
	public static function on_activation() {
		if ( false === get_option( self::OPTION_COMPLETE, false ) ) {
			$has_existing_forms = ! empty( get_posts( array(
				'post_type'      => 'psbdx_report_form',
				'post_status'    => 'any',
				'posts_per_page' => 1,
				'fields'         => 'ids',
			) ) );

			update_option( self::OPTION_COMPLETE, $has_existing_forms ? 'yes' : 'no', false );
		}

		if ( 'no' === get_option( self::OPTION_COMPLETE ) ) {
			set_transient( self::REDIRECT_TRANSIENT, 1, 5 * MINUTE_IN_SECONDS );
		}
	}

	/**
	 * Whether setup has been finished or explicitly skipped.
	 *
	 * @since  1.4.5
	 * @return bool
	 */
	public static function is_complete() {
		return ( 'yes' === get_option( self::OPTION_COMPLETE, 'yes' ) );
	}

	/**
	 * URL that reopens the wizard at any time — the page itself is never
	 * gated (see maybe_enforce_gate()), so this doesn't need to reset
	 * anything; landing here just shows the wizard again with current
	 * values pre-filled. Used by the "Restart Setup Wizard" links on the
	 * plugin's action links and the Repair & Reset page.
	 *
	 * @since  1.4.5
	 * @return string
	 */
	public static function get_restart_url() {
		return admin_url( 'admin.php?page=' . self::PAGE_SETUP );
	}

	/**
	 * Redirects to the wizard once, right after activation — skipped
	 * entirely for bulk plugin activation, same convention most plugins
	 * with an onboarding flow follow.
	 *
	 * @since  1.4.5
	 * @return void
	 */
	public function maybe_activation_redirect() {
		if ( ! get_transient( self::REDIRECT_TRANSIENT ) ) {
			return;
		}

		delete_transient( self::REDIRECT_TRANSIENT );

		if ( wp_doing_ajax() || isset( $_GET['activate-multi'] ) || self::is_complete() ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only existence check, not acting on the value.
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE_SETUP ) );
		exit;
	}

	// =========================================================================
	// GATE
	// =========================================================================

	/**
	 * While setup is incomplete, redirects away from any other SRM admin
	 * screen (forms, logs, settings, repair, support, FAQ, AI log) back to
	 * the wizard. Screens outside this plugin are never touched.
	 *
	 * @since  1.4.5
	 * @return void
	 */
	public function maybe_enforce_gate() {
		if ( ! is_admin() || wp_doing_ajax() || self::is_complete() ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$page      = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing check.
		$post_type = isset( $_GET['post_type'] ) ? sanitize_key( wp_unslash( $_GET['post_type'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing check.

		if ( self::PAGE_SETUP === $page ) {
			return; // Already here.
		}

		$is_plugin_screen = ( PSBDX_SRM_Post_Types::ADMIN_MENU_SLUG === $page )
			|| ( 0 === strpos( $page, 'psbdx-srm-' ) )
			|| in_array( $post_type, array( 'psbdx_report_form', 'psbdx_report_log' ), true );

		if ( ! $is_plugin_screen ) {
			return;
		}

		wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE_SETUP ) );
		exit;
	}

	// =========================================================================
	// PAGE REGISTRATION + ASSETS
	// =========================================================================

	/**
	 * Registers the wizard as a hidden admin page (null parent — WordPress
	 * still routes admin.php?page=... to it, it just never appears in any
	 * menu list).
	 *
	 * @since  1.4.5
	 * @return void
	 */
	public function register_hidden_page() {
		add_submenu_page(
			null, // phpcs:ignore WordPress.WP.CapitalPDangit.Misspelled -- intentional: hides this page from all menus.
			__( 'PSBDx Setup', 'psbdx-smart-report-management' ),
			__( 'PSBDx Setup', 'psbdx-smart-report-management' ),
			'manage_options',
			self::PAGE_SETUP,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Enqueues the wizard's own small CSS/JS, only on its own screen.
	 *
	 * @since  1.4.5
	 * @return void
	 */
	public function enqueue_assets() {
		if ( ! isset( $_GET['page'] ) || self::PAGE_SETUP !== $_GET['page'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only screen check.
			return;
		}

		wp_enqueue_style(
			'psbdx-srm-setup-wizard',
			PSBDX_SRM_URL . 'assets/css/setup-wizard.css',
			array(),
			psbdx_srm_asset_ver( 'assets/css/setup-wizard.css' )
		);

		wp_enqueue_script(
			'psbdx-srm-setup-wizard',
			PSBDX_SRM_URL . 'assets/js/setup-wizard.js',
			array(),
			psbdx_srm_asset_ver( 'assets/js/setup-wizard.js' ),
			true
		);

		wp_localize_script( 'psbdx-srm-setup-wizard', 'psbdxSrmWizard', array(
			'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
			'testAction' => self::TEST_EMAIL_ACTION,
			'nonce'      => wp_create_nonce( self::NONCE_ACTION ),
		) );
	}

	// =========================================================================
	// SAVE
	// =========================================================================

	/**
	 * Handles both the "Finish Setup" and "Skip setup" submissions.
	 *
	 * @since  1.4.5
	 * @return void
	 */
	public function handle_save() {
		$is_finish = isset( $_POST['psbdx_srm_wizard_finish'] );
		$is_skip   = isset( $_POST['psbdx_srm_wizard_skip'] );

		if ( ! $is_finish && ! $is_skip ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		check_admin_referer( self::NONCE_ACTION, self::NONCE_FIELD );

		if ( $is_skip ) {
			update_option( self::OPTION_COMPLETE, 'yes', false );
			wp_safe_redirect( admin_url( 'admin.php?page=' . PSBDX_SRM_Post_Types::ADMIN_MENU_SLUG ) );
			exit;
		}

		// Global cooldown / rate limit.
		$mins = isset( $_POST['psbdx_srm_global_rate_limit_mins'] ) ? (int) $_POST['psbdx_srm_global_rate_limit_mins'] : 30;
		update_option( PSBDX_SRM_Helpers::GLOBAL_RATE_LIMIT_OPTION, min( 1440, max( 0, $mins ) ), false );

		// Notification sender (optional — blank fields fall back to WP defaults).
		$sender = isset( $_POST['psbdx_email_sender'] ) && is_array( $_POST['psbdx_email_sender'] )
			? wp_unslash( $_POST['psbdx_email_sender'] ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized field-by-field inside save_sender().
			: array();
		PSBDX_SRM_Emails::save_sender( $sender['name'] ?? '', $sender['email'] ?? '' );

		// Which notification emails are on/off. The wizard only exposes the
		// on/off toggle for each event (not subject/body editing — that
		// stays under Settings → Email), so we update the saved option
		// directly rather than going through save_templates() — that
		// method expects raw, still-slashed $_POST input and would
		// needlessly re-run wp_unslash()/sanitizers on values we already
		// pulled from storage, which can corrupt an admin's own literal
		// backslashes in a custom subject/body (same class of bug fixed
		// previously in the Settings → Email save handler).
		$posted_events   = isset( $_POST['psbdx_email_enabled'] ) && is_array( $_POST['psbdx_email_enabled'] )
			? array_map( 'sanitize_key', wp_unslash( $_POST['psbdx_email_enabled'] ) )
			: array();
		$saved_templates = get_option( PSBDX_SRM_Emails::OPTION, array() );
		foreach ( PSBDX_SRM_Emails::get_events() as $event_key => $event ) {
			if ( ! isset( $saved_templates[ $event_key ] ) || ! is_array( $saved_templates[ $event_key ] ) ) {
				$saved_templates[ $event_key ] = array(
					'subject' => '',
					'body'    => '',
				);
			}
			$saved_templates[ $event_key ]['enabled'] = in_array( $event_key, $posted_events, true );
		}
		update_option( PSBDX_SRM_Emails::OPTION, $saved_templates, false );

		// Optionally create a default report form so there's something to
		// embed right away.
		$created_form_id = 0;

		if ( isset( $_POST['psbdx_wizard_create_form'] ) ) {
			$existing = get_posts( array(
				'post_type'      => 'psbdx_report_form',
				'post_status'    => 'any',
				'posts_per_page' => 1,
				'fields'         => 'ids',
			) );

			if ( empty( $existing ) ) {
				$created_form_id = wp_insert_post( array(
					'post_type'   => 'psbdx_report_form',
					'post_status' => 'publish',
					'post_title'  => __( 'Report an Issue', 'psbdx-smart-report-management' ),
				) );

				if ( $created_form_id && ! is_wp_error( $created_form_id ) ) {
					update_post_meta( $created_form_id, '_psbdx_btn_text',      __( 'Report Issue', 'psbdx-smart-report-management' ) );
					update_post_meta( $created_form_id, '_psbdx_show_identity', 'yes' );
					set_transient( 'psbdx_srm_wizard_created_form', $created_form_id, 5 * MINUTE_IN_SECONDS );
				} else {
					$created_form_id = 0;
				}
			}
		}

		update_option( self::OPTION_COMPLETE, 'yes', false );

		$redirect = $created_form_id
			? admin_url( 'post.php?post=' . $created_form_id . '&action=edit' )
			: admin_url( 'admin.php?page=' . PSBDX_SRM_Post_Types::ADMIN_MENU_SLUG );

		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * AJAX: sends a plain test email to the current admin, using the same
	 * From name/email override the wizard's Mailing step just showed (or
	 * whatever's already saved) — lets an admin confirm outgoing mail
	 * actually works and see who it appears to come from, right from the
	 * wizard, before relying on it for real report notifications.
	 *
	 * @since  1.4.5
	 * @return void  Terminates with wp_send_json_success() or wp_send_json_error().
	 */
	public function ajax_send_test_email() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'You do not have permission to do this.', 'psbdx-smart-report-management' ) );
		}

		check_ajax_referer( self::NONCE_ACTION, 'security' );

		$to = wp_get_current_user()->user_email;

		if ( ! is_email( $to ) ) {
			wp_send_json_error( __( 'Your account has no valid email address.', 'psbdx-smart-report-management' ) );
		}

		$sender = PSBDX_SRM_Emails::get_sender();

		if ( '' !== $sender['name'] ) {
			add_filter( 'wp_mail_from_name', array( 'PSBDX_SRM_Emails', 'filter_from_name' ) );
		}
		if ( '' !== $sender['email'] ) {
			add_filter( 'wp_mail_from', array( 'PSBDX_SRM_Emails', 'filter_from_email' ) );
		}

		$subject = sprintf(
			/* translators: %s: site name */
			__( 'Test email from %s', 'psbdx-smart-report-management' ),
			get_bloginfo( 'name' )
		);
		$body = '<p>' . esc_html__( 'This is a test email from PSBDx Smart Report Management\'s setup wizard.', 'psbdx-smart-report-management' ) . '</p>'
			. '<p>' . esc_html__( "If you're reading this, outgoing mail from your site is working and report notifications will reach you.", 'psbdx-smart-report-management' ) . '</p>';

		$sent = wp_mail( $to, $subject, $body, array( 'Content-Type: text/html; charset=UTF-8' ) );

		remove_filter( 'wp_mail_from_name', array( 'PSBDX_SRM_Emails', 'filter_from_name' ) );
		remove_filter( 'wp_mail_from', array( 'PSBDX_SRM_Emails', 'filter_from_email' ) );

		if ( ! $sent ) {
			wp_send_json_error( __( 'WordPress reported the email failed to send. Check your site\'s mail delivery (an SMTP plugin usually fixes this).', 'psbdx-smart-report-management' ) );
		}

		wp_send_json_success( array(
			/* translators: %s: email address the test was sent to */
			'message' => sprintf( __( 'Test email sent to %s — check your inbox (and spam folder).', 'psbdx-smart-report-management' ), $to ),
		) );
	}

	/**
	 * One-time success notice pointing at the shortcode for the form the
	 * wizard just created, shown on the very next admin page load.
	 *
	 * @since  1.4.5
	 * @return void
	 */
	public function render_created_form_notice() {
		$form_id = (int) get_transient( 'psbdx_srm_wizard_created_form' );

		if ( ! $form_id || 'psbdx_report_form' !== get_post_type( $form_id ) ) {
			return;
		}

		delete_transient( 'psbdx_srm_wizard_created_form' );
		?>
		<div class="notice notice-success is-dismissible">
			<p>
				<?php esc_html_e( 'Your first report form is ready! Add it to any page or post with this shortcode:', 'psbdx-smart-report-management' ); ?>
				<code>[psbdx_report id="<?php echo esc_html( $form_id ); ?>"]</code>
			</p>
		</div>
		<?php
	}

	// =========================================================================
	// RENDER
	// =========================================================================

	/**
	 * Renders the wizard page — five client-side steps, submitted together
	 * in a single request when the admin reaches "Finish Setup".
	 *
	 * @since  1.4.5
	 * @return void
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'psbdx-smart-report-management' ) );
		}

		$cooldown_default = PSBDX_SRM_Helpers::get_global_rate_limit_mins();
		$sender_name_val  = '';
		$sender_email_val = '';

		if ( class_exists( 'PSBDX_SRM_Emails' ) && method_exists( 'PSBDX_SRM_Emails', 'get_sender' ) ) {
			$sender           = PSBDX_SRM_Emails::get_sender();
			$sender_name_val  = is_array( $sender ) ? ( $sender['name'] ?? '' )  : '';
			$sender_email_val = is_array( $sender ) ? ( $sender['email'] ?? '' ) : '';
		}

		$email_events = PSBDX_SRM_Emails::get_events();
		?>
		<div class="wrap psrm-wizard-wrap">
			<div class="psrm-wizard-card">

				<div class="psrm-wizard-progress" id="psrm-wizard-progress" role="progressbar" aria-valuemin="1" aria-valuemax="5" aria-valuenow="1">
					<span class="psrm-wizard-progress-fill" id="psrm-wizard-progress-fill"></span>
				</div>

				<form method="post" id="psrm-wizard-form">
					<?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD ); ?>

					<?php /* ── Step 1: Welcome ─────────────────────────────────── */ ?>
					<section class="psrm-wizard-step psrm-wizard-step-active" data-step="1">
						<div class="psrm-wizard-icon" aria-hidden="true">
							<svg xmlns="http://www.w3.org/2000/svg" width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M4 15s1-1 4-1 5 2 8 2 4-1 4-1V3s-1 1-4 1-5-2-8-2-4 1-4 1z"/><line x1="4" y1="22" x2="4" y2="15"/></svg>
						</div>
						<h1><?php esc_html_e( 'Welcome to PSBDx Smart Report Management', 'psbdx-smart-report-management' ); ?></h1>
						<p><?php esc_html_e( "Let's get a few basics set up before you start collecting reports. This takes about a minute — you can change any of it later from Settings.", 'psbdx-smart-report-management' ); ?></p>
					</section>

					<?php /* ── Step 2: Rate limiting ───────────────────────────── */ ?>
					<section class="psrm-wizard-step" data-step="2">
						<h2><?php esc_html_e( 'Prevent spam submissions', 'psbdx-smart-report-management' ); ?></h2>
						<p class="psrm-wizard-step-desc"><?php esc_html_e( 'A logged-in user has to wait this long before they can submit another report. You can override this per-form later.', 'psbdx-smart-report-management' ); ?></p>
						<label class="psrm-wizard-field">
							<span><?php esc_html_e( 'Cooldown between submissions (minutes)', 'psbdx-smart-report-management' ); ?></span>
							<input type="number" name="psbdx_srm_global_rate_limit_mins" min="0" max="1440" value="<?php echo esc_attr( $cooldown_default ); ?>">
						</label>
						<p class="description"><?php esc_html_e( 'Set to 0 to disable the cooldown entirely.', 'psbdx-smart-report-management' ); ?></p>
					</section>

					<?php /* ── Step 3: Mailing setup ───────────────────────────── */ ?>
					<section class="psrm-wizard-step" data-step="3">
						<h2><?php esc_html_e( 'Mailing setup', 'psbdx-smart-report-management' ); ?></h2>
						<p class="psrm-wizard-step-desc">
							<?php esc_html_e( 'These are the automatic emails your site sends when someone submits a report or replies to one — for example, letting you know a new report came in, or confirming to the reporter that you received their message. Turn each on or off, and set who they appear to come from. You can fine-tune the subject and wording of each later under Settings → Email.', 'psbdx-smart-report-management' ); ?>
						</p>

						<label class="psrm-wizard-field">
							<span><?php esc_html_e( 'From name', 'psbdx-smart-report-management' ); ?></span>
							<input type="text" name="psbdx_email_sender[name]" value="<?php echo esc_attr( $sender_name_val ); ?>" placeholder="<?php echo esc_attr( get_bloginfo( 'name' ) ); ?>">
						</label>
						<label class="psrm-wizard-field">
							<span><?php esc_html_e( 'From email', 'psbdx-smart-report-management' ); ?></span>
							<input type="email" name="psbdx_email_sender[email]" value="<?php echo esc_attr( $sender_email_val ); ?>" placeholder="<?php echo esc_attr( get_option( 'admin_email' ) ); ?>">
						</label>

						<div class="psrm-wizard-email-list">
							<?php foreach ( $email_events as $event_key => $event ) : ?>
							<label class="psrm-wizard-checkbox psrm-wizard-email-row">
								<input type="checkbox" name="psbdx_email_enabled[]" value="<?php echo esc_attr( $event_key ); ?>" <?php checked( PSBDX_SRM_Emails::is_enabled( $event_key ) ); ?>>
								<span>
									<strong><?php echo esc_html( $event['label'] ); ?></strong>
									<span class="psrm-wizard-email-desc"><?php echo esc_html( $event['description'] ); ?></span>
								</span>
							</label>
							<?php endforeach; ?>
						</div>

						<div class="psrm-wizard-test-email">
							<button type="button" class="button" id="psrm-wizard-test-email-btn">
								<?php esc_html_e( 'Send me a test email', 'psbdx-smart-report-management' ); ?>
							</button>
							<span id="psrm-wizard-test-email-result" role="status" aria-live="polite"></span>
						</div>
					</section>

					<?php /* ── Step 4: First form ───────────────────────────────── */ ?>
					<section class="psrm-wizard-step" data-step="4">
						<h2><?php esc_html_e( 'Create your first report form', 'psbdx-smart-report-management' ); ?></h2>
						<p class="psrm-wizard-step-desc"><?php esc_html_e( "We'll set up a starter form called \"Report an Issue\" that you can drop anywhere with a shortcode, and fully customize afterward in the Form Builder.", 'psbdx-smart-report-management' ); ?></p>
						<label class="psrm-wizard-checkbox">
							<input type="checkbox" name="psbdx_wizard_create_form" value="yes" checked>
							<span><?php esc_html_e( 'Create a default report form now', 'psbdx-smart-report-management' ); ?></span>
						</label>
					</section>

					<?php /* ── Step 5: Thank you & finish ──────────────────────────── */ ?>
					<section class="psrm-wizard-step" data-step="5">
						<div class="psrm-wizard-icon psrm-wizard-icon-done" aria-hidden="true">
							<svg xmlns="http://www.w3.org/2000/svg" width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6L9 17l-5-5"/></svg>
						</div>
						<h1><?php esc_html_e( 'Thank you for setting up PSBDx Smart Report Management', 'psbdx-smart-report-management' ); ?></h1>
						<p><?php esc_html_e( 'Click "Finish Setup" to save these settings and start using the plugin. You can come back to this wizard any time from the plugin\'s page on the Plugins screen, or from Repair & Reset.', 'psbdx-smart-report-management' ); ?></p>
						<p class="psrm-wizard-credit">
							<?php esc_html_e( 'Built by M. Farhan Hamim', 'psbdx-smart-report-management' ); ?>
							<span class="psrm-wizard-credit-title"><?php esc_html_e( 'Founder, CEO and Owner of PSBDx', 'psbdx-smart-report-management' ); ?></span>
						</p>
					</section>

					<div class="psrm-wizard-nav">
						<button type="submit" name="psbdx_srm_wizard_skip" value="1" class="psrm-wizard-skip" formnovalidate>
							<?php esc_html_e( 'Skip setup', 'psbdx-smart-report-management' ); ?>
						</button>
						<span class="psrm-wizard-nav-spacer"></span>
						<button type="button" class="button psrm-wizard-back" id="psrm-wizard-back" style="visibility:hidden;">
							<?php esc_html_e( 'Back', 'psbdx-smart-report-management' ); ?>
						</button>
						<button type="button" class="button button-primary psrm-wizard-next" id="psrm-wizard-next">
							<?php esc_html_e( 'Next', 'psbdx-smart-report-management' ); ?>
						</button>
						<button type="submit" name="psbdx_srm_wizard_finish" value="1" class="button button-primary psrm-wizard-finish" id="psrm-wizard-finish" style="display:none;">
							<?php esc_html_e( 'Finish Setup', 'psbdx-smart-report-management' ); ?>
						</button>
					</div>
				</form>
			</div>
		</div>
		<?php
	}
}
