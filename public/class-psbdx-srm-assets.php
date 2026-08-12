<?php
/**
 * Frontend asset loading for PSBDx Smart Report Management.
 *
 * @package PSBDx_Smart_Report_Management
 * @since   1.0.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class PSBDX_SRM_Assets
 *
 * Enqueues the public CSS and JS files needed for the report modal
 * and user history table.
 *
 * @since 1.0.0
 */
class PSBDX_SRM_Assets {

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Enqueue public CSS and JS, and pass AJAX data to the script.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	public function enqueue() {
		// Used for icons across the report button, history table, FAQ, and
		// the report detail page. Core only auto-loads this in wp-admin.
		wp_enqueue_style( 'dashicons' );

		wp_enqueue_style(
			'psbdx-srm-public',
			PSBDX_SRM_URL . 'assets/css/public.css',
			array( 'dashicons' ),
			psbdx_srm_asset_ver( 'assets/css/public.css' )
		);

		wp_enqueue_script(
			'psbdx-srm-public',
			PSBDX_SRM_URL . 'assets/js/public.js',
			array(),
			psbdx_srm_asset_ver( 'assets/js/public.js' ),
			true
		);

		// Enqueue captcha provider script if any provider is active.
		$provider = PSBDX_SRM_Captcha::active_provider();
		$captcha_data = array( 'provider' => '', 'siteKey' => '' );

		if ( '' !== $provider ) {
			$site_key  = PSBDX_SRM_Captcha::get_opt( $provider, 'site_key' );
			$script_url = add_query_arg( 'onload', 'psbdxInitCaptcha', PSBDX_SRM_Captcha::script_url( $provider, $site_key ) );

			wp_enqueue_script(
				'psbdx-srm-captcha-api',
				$script_url,
				array(),
				null, // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion
				true
			);

			$captcha_data = array(
				'provider' => $provider,
				'siteKey'  => $site_key,
				'i18n'     => array(
					'captchaRequired' => __( 'Please complete the captcha before submitting.', 'psbdx-smart-report-management' ),
				),
			);
		}

		wp_localize_script( 'psbdx-srm-public', 'psbdxSrm', self::get_localized_data( $captcha_data ) );
	}

	/**
	 * Builds the data passed to the frontend as `window.psbdxSrm`. Shared
	 * with PSBDX_SRM_Report_Page, which prints this manually as a fallback
	 * on themes that don't fire wp_head()/wp_enqueue_scripts (so the
	 * report detail page still works instead of silently losing all its
	 * interactivity).
	 *
	 * @since 1.4.2
	 * @param  array $captcha_data  Captcha provider config, or an empty shape if no provider is active.
	 * @return array
	 */
	public static function get_localized_data( $captcha_data = array( 'provider' => '', 'siteKey' => '' ) ) {
		return array(
			'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
			'captcha'     => $captcha_data,
			'replyNonce'  => wp_create_nonce( 'psbdx_srm_submit_reply_nonce' ),
			'replyAction' => PSBDX_SRM_Ajax::REPLY_ACTION,
			'pollNonce'   => wp_create_nonce( 'psbdx_srm_poll_thread_nonce' ),
			'pollAction'  => PSBDX_SRM_Ajax::POLL_ACTION,
			'popupAction' => PSBDX_SRM_Ajax::POPUP_ACTION,
			'i18n'     => array(
				'sending'       => __( 'Sending\u2026',                              'psbdx-smart-report-management' ),
				'submitted'     => __( 'Report Submitted!',                          'psbdx-smart-report-management' ),
				'thankyou'      => __( 'Thank you. We will get back to you soon.',   'psbdx-smart-report-management' ),
				'error'         => __( 'Submission failed. Please try again.',       'psbdx-smart-report-management' ),
				'networkError'  => __( 'Network error. Please check your connection.', 'psbdx-smart-report-management' ),
				'closeLabel'    => __( 'Close',                                      'psbdx-smart-report-management' ),
				'otherReason'   => __( 'Other',                                      'psbdx-smart-report-management' ),
				'captchaFail'   => __( 'Please complete the captcha before submitting.', 'psbdx-smart-report-management' ),
				'ticketLabel'   => __( 'Your ticket ID:',                            'psbdx-smart-report-management' ),
				'replySending'  => __( 'Submitting with PSBDx\u2026',                'psbdx-smart-report-management' ),
				'replyError'    => __( 'Could not send your reply. Please try again.', 'psbdx-smart-report-management' ),
			),
		);
	}

	/**
	 * Prints a `<link>` tag for a stylesheet directly, bypassing wp_head()
	 * entirely. Used only as a last-resort fallback (see PSBDX_SRM_Report_Page)
	 * for themes whose header.php doesn't call wp_head() — without it,
	 * 'wp_enqueue_scripts' never fires at all and this plugin's frontend
	 * silently loses all styling and interactivity with no error anywhere.
	 *
	 * @since 1.4.2
	 * @param  string $handle  Arbitrary handle, for the id attribute only.
	 * @param  string $url     Stylesheet URL.
	 * @return void
	 */
	public static function print_style_tag( $handle, $url ) {
		printf(
			'<link rel="stylesheet" id="%s-css" href="%s" media="all">' . "\n", // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet -- deliberate last-resort fallback, see docblock above: wp_enqueue_scripts never fires at all for themes that skip wp_head(), so enqueuing wouldn't work here either.
			esc_attr( $handle ),
			esc_url( $url )
		);
	}

	/**
	 * Prints a `<script>` tag directly, bypassing wp_footer() entirely.
	 * Same last-resort fallback purpose as print_style_tag() above.
	 *
	 * @since 1.4.2
	 * @param  string $handle  Arbitrary handle, for the id attribute only.
	 * @param  string $url     Script URL.
	 * @return void
	 */
	public static function print_script_tag( $handle, $url ) {
		printf(
			'<script id="%s-js" src="%s"></script>' . "\n", // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript -- same deliberate fallback as print_style_tag() above.
			esc_attr( $handle ),
			esc_url( $url )
		);
	}
}
