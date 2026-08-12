<?php
/**
 * Admin review notice for PSBDx Smart Report Management.
 *
 * Displays a dismissible admin panel notification asking for a WordPress.org
 * review after 24 hours of activation. Supports three actions:
 *   - "Yes, you deserve it!" → redirects to the review page (permanent dismiss)
 *   - "Nope, I'll review later" → hides for 7 days then re-appears
 *   - "I already reviewed" → hides permanently
 *
 * Multisite-aware: activation timestamp and dismiss state are stored per-site
 * using get_option / update_option (not network options), so each site in a
 * multisite network gets its own independent notice lifecycle.
 *
 * @package PSBDx_Smart_Report_Management
 * @since   1.1.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class PSBDX_SRM_Review_Notice
 *
 * @since 1.1.0
 */
class PSBDX_SRM_Review_Notice {

	/**
	 * WordPress.org review URL.
	 *
	 * @since 1.1.0
	 * @var string
	 */
	const REVIEW_URL = 'https://wordpress.org/plugins/psbdx-smart-report-management/#reviews';

	/**
	 * Option key: Unix timestamp of plugin first activation (per-site).
	 *
	 * @since 1.1.0
	 * @var string
	 */
	const OPT_ACTIVATED = 'psbdx_srm_activated_time';

	/**
	 * Option key: dismiss state.
	 *   'later'     → temporarily dismissed; stores Unix timestamp of dismiss
	 *   'permanent' → permanently dismissed (reviewed or clicked review link)
	 *
	 * @since 1.1.0
	 * @var string
	 */
	const OPT_DISMISSED = 'psbdx_srm_review_dismissed';

	/**
	 * Hours after activation before the notice first appears.
	 *
	 * @since 1.1.0
	 * @var int
	 */
	const HOURS_BEFORE_FIRST = 24;

	/**
	 * Days to snooze when the user clicks "I'll review later".
	 *
	 * @since 1.1.0
	 * @var int
	 */
	const DAYS_SNOOZE = 7;

	/**
	 * Constructor — registers hooks.
	 *
	 * @since 1.1.0
	 */
	public function __construct() {
		add_action( 'admin_notices',            array( $this, 'maybe_render' ) );
		add_action( 'admin_enqueue_scripts',    array( $this, 'enqueue_assets' ) );
		add_action( 'wp_ajax_psbdx_srm_review_action', array( $this, 'handle_ajax' ) );
	}

	/**
	 * Records plugin activation time (per-site) if not already set.
	 *
	 * Should be called from the plugin's register_activation_hook callback.
	 *
	 * On multisite network activation the plugin bootstrap calls this once per
	 * site; sites that still lack the option also get stamped on first load
	 * (see maybe_set_activated_time()).
	 *
	 * @since 1.1.0
	 * @return void
	 */
	public static function on_activation() {
		if ( ! get_option( self::OPT_ACTIVATED ) ) {
			update_option( self::OPT_ACTIVATED, time(), false );
		}
	}

	/**
	 * Lazily sets the activation timestamp the first time the plugin initialises
	 * on a site where the option is absent (covers multisite per-site activation
	 * and any install that pre-dates this feature).
	 *
	 * @since 1.1.0
	 * @return void
	 */
	public static function maybe_set_activated_time() {
		if ( ! get_option( self::OPT_ACTIVATED ) ) {
			update_option( self::OPT_ACTIVATED, time(), false );
		}
	}

	// -------------------------------------------------------------------------
	// Notice rendering
	// -------------------------------------------------------------------------

	/**
	 * Determines whether the notice should be shown and renders it.
	 *
	 * @since 1.1.0
	 * @return void
	 */
	public function maybe_render() {
		// Only show to admins.
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// Check permanent dismissal first (cheapest check).
		$dismissed = get_option( self::OPT_DISMISSED );

		if ( 'permanent' === $dismissed ) {
			return;
		}

		// Check activation timestamp.
		$activated = (int) get_option( self::OPT_ACTIVATED );

		if ( ! $activated ) {
			// Should not happen if on_activation / maybe_set_activated_time ran,
			// but be safe.
			return;
		}

		$now = time();

		// Has 24 hours elapsed since activation?
		if ( $now < $activated + ( self::HOURS_BEFORE_FIRST * HOUR_IN_SECONDS ) ) {
			return;
		}

		// Is the notice snoozed?
		if ( is_array( $dismissed ) && isset( $dismissed['snooze_until'] ) ) {
			if ( $now < (int) $dismissed['snooze_until'] ) {
				return;
			}
		}

		$this->render();
	}

	/**
	 * Outputs the review notice HTML.
	 *
	 * @since 1.1.0
	 * @return void
	 */
	private function render() {
		$nonce = wp_create_nonce( 'psbdx_srm_review_action' );
		?>
		<div class="notice psbdx-srm-review-notice" id="psbdx-srm-review-notice">
			<div class="psbdx-srm-rn-inner">
				<div class="psbdx-srm-rn-icon" aria-hidden="true">⭐</div>
				<div class="psbdx-srm-rn-body">
					<p class="psbdx-srm-rn-heading">
						<?php esc_html_e( 'Enjoying PSBDx Smart Report Management?', 'psbdx-smart-report-management' ); ?>
					</p>
					<p class="psbdx-srm-rn-sub">
						<?php esc_html_e( 'If the plugin has been helpful, a quick review on WordPress.org makes a huge difference. It only takes a minute!', 'psbdx-smart-report-management' ); ?>
					</p>
					<div class="psbdx-srm-rn-actions">
						<a
							href="<?php echo esc_url( self::REVIEW_URL ); ?>"
							target="_blank"
							rel="noopener noreferrer"
							class="button button-primary psbdx-srm-rn-btn"
							data-action="reviewed"
							data-nonce="<?php echo esc_attr( $nonce ); ?>"
						>
							⭐ <?php esc_html_e( 'Yes, you deserve it!', 'psbdx-smart-report-management' ); ?>
						</a>
						<button
							type="button"
							class="button psbdx-srm-rn-btn"
							data-action="later"
							data-nonce="<?php echo esc_attr( $nonce ); ?>"
						>
							🕐 <?php esc_html_e( 'Nope, I\'ll review later', 'psbdx-smart-report-management' ); ?>
						</button>
						<button
							type="button"
							class="button psbdx-srm-rn-btn psbdx-srm-rn-btn-muted"
							data-action="reviewed"
							data-nonce="<?php echo esc_attr( $nonce ); ?>"
						>
							✅ <?php esc_html_e( 'I already reviewed', 'psbdx-smart-report-management' ); ?>
						</button>
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	// -------------------------------------------------------------------------
	// AJAX handler
	// -------------------------------------------------------------------------

	/**
	 * Handles the AJAX dismiss/snooze request sent from the notice buttons.
	 *
	 * Expected POST params:
	 *   nonce  — wp_nonce for 'psbdx_srm_review_action'
	 *   action_type — 'later' | 'reviewed'
	 *
	 * @since 1.1.0
	 * @return void  Sends JSON and exits.
	 */
	public function handle_ajax() {
		check_ajax_referer( 'psbdx_srm_review_action', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Forbidden' ), 403 );
		}

		$action_type = isset( $_POST['action_type'] ) ? sanitize_key( wp_unslash( $_POST['action_type'] ) ) : '';

		if ( 'later' === $action_type ) {
			update_option(
				self::OPT_DISMISSED,
				array( 'snooze_until' => time() + ( self::DAYS_SNOOZE * DAY_IN_SECONDS ) ),
				false
			);
		} elseif ( 'reviewed' === $action_type ) {
			update_option( self::OPT_DISMISSED, 'permanent', false );
		} else {
			wp_send_json_error( array( 'message' => 'Invalid action' ), 400 );
		}

		wp_send_json_success();
	}

	// -------------------------------------------------------------------------
	// Assets
	// -------------------------------------------------------------------------

	/**
	 * Enqueues the inline CSS and JS needed for the review notice.
	 * Only loaded on admin pages where the notice might appear.
	 *
	 * @since 1.1.0
	 * @return void
	 */
	public function enqueue_assets() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// Only bother if the notice might actually render.
		$dismissed = get_option( self::OPT_DISMISSED );
		if ( 'permanent' === $dismissed ) {
			return;
		}

		// Inline CSS — keeps notice styles self-contained.
		$css = '
		.psbdx-srm-review-notice {
			border-left-color: #6366f1;
			padding: 0;
			overflow: hidden;
		}
		.psbdx-srm-rn-inner {
			display: flex;
			align-items: flex-start;
			gap: 14px;
			padding: 16px 18px;
		}
		.psbdx-srm-rn-icon {
			font-size: 28px;
			line-height: 1;
			flex-shrink: 0;
			margin-top: 2px;
		}
		.psbdx-srm-rn-body {
			flex: 1;
			min-width: 0;
		}
		.psbdx-srm-rn-heading {
			font-size: 14px;
			font-weight: 700;
			color: #1e293b;
			margin: 0 0 4px;
		}
		.psbdx-srm-rn-sub {
			font-size: 13px;
			color: #475569;
			margin: 0 0 12px;
		}
		.psbdx-srm-rn-actions {
			display: flex;
			flex-wrap: wrap;
			gap: 8px;
			align-items: center;
		}
		.psbdx-srm-rn-btn {
			font-size: 12px !important;
			height: auto !important;
			padding: 5px 12px !important;
			cursor: pointer;
		}
		.psbdx-srm-rn-btn-muted {
			color: #64748b !important;
			border-color: #cbd5e1 !important;
			background: transparent !important;
			box-shadow: none !important;
		}
		.psbdx-srm-rn-btn-muted:hover {
			color: #334155 !important;
			border-color: #94a3b8 !important;
		}
		';

		wp_register_style( 'psbdx-srm-review-notice', false, array(), PSBDX_SRM_VERSION );
		wp_enqueue_style( 'psbdx-srm-review-notice' );
		wp_add_inline_style( 'psbdx-srm-review-notice', $css );

		// Inline JS.
		$js = '
		(function () {
			document.addEventListener("DOMContentLoaded", function () {
				var notice = document.getElementById("psbdx-srm-review-notice");
				if (!notice) return;

				notice.addEventListener("click", function (e) {
					var btn = e.target.closest("[data-action]");
					if (!btn) return;

					var actionType = btn.dataset.action;
					var nonce      = btn.dataset.nonce;

					// Fire-and-forget AJAX dismiss.
					var body = new URLSearchParams({
						action      : "psbdx_srm_review_action",
						action_type : actionType,
						nonce       : nonce,
					});

					fetch(ajaxurl, { method: "POST", body: body, credentials: "same-origin" });

					// Hide the notice immediately for all button types.
					notice.style.transition = "opacity 0.25s";
					notice.style.opacity    = "0";
					setTimeout(function () { notice.remove(); }, 280);
				});
			});
		}());
		';

		wp_register_script( 'psbdx-srm-review-notice', false, array(), PSBDX_SRM_VERSION, true );
		wp_enqueue_script( 'psbdx-srm-review-notice' );
		wp_add_inline_script( 'psbdx-srm-review-notice', $js );
	}
}
