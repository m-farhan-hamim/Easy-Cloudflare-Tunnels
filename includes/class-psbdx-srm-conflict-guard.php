<?php
/**
 * Detects activation conflicts and deactivates offending plugins to avoid fatals.
 *
 * @package PSBDx_Smart_Report_Management
 * @since   1.2.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class PSBDX_SRM_Conflict_Guard
 *
 * After any third-party plugin is activated, runs a lightweight health check.
 * If this plugin's post types or core class are missing, the last-activated
 * plugin is deactivated and an admin notice explains why.
 *
 * Also performs a deeper security scan on every admin page load and notifies
 * the admin when any integrity check fails.
 *
 * @since 1.2.0
 */
class PSBDX_SRM_Conflict_Guard {

	/**
	 * Option key storing the plugin file pending a post-activation check.
	 *
	 * @since 1.2.0
	 * @var string
	 */
	const PENDING_OPTION = 'psbdx_srm_pending_activation_check';

	/**
	 * Transient key for the security scan result cache (5 minutes).
	 *
	 * @since 1.3.1
	 * @var string
	 */
	const SECURITY_TRANSIENT = 'psbdx_srm_security_scan';

	/**
	 * Constructor.
	 *
	 * @since 1.2.0
	 */
	public function __construct() {
		add_action( 'activated_plugin',  array( $this, 'on_activated_plugin' ), 20, 2 );
		add_action( 'deactivated_plugin', array( $this, 'on_deactivated_plugin' ), 20 );
		add_action( 'admin_notices',     array( $this, 'render_conflict_notice' ) );
		add_action( 'admin_notices',     array( $this, 'render_security_notice' ) );
		add_action( 'admin_init',        array( $this, 'run_security_scan' ) );
	}

	// =========================================================================
	// ACTIVATION CONFLICT DETECTION
	// =========================================================================

	/**
	 * Schedules a shutdown health check when another plugin is activated.
	 *
	 * @since 1.2.0
	 * @param string $plugin        Relative plugin path from WP_PLUGIN_DIR.
	 * @param bool   $network_wide  Whether the plugin was network-activated.
	 * @return void
	 */
	public function on_activated_plugin( $plugin, $network_wide ) {
		if ( $network_wide ) {
			return;
		}

		if ( ! apply_filters( 'psbdx_srm_conflict_guard_enabled', true ) ) {
			return;
		}

		if ( plugin_basename( PSBDX_SRM_FILE ) === $plugin ) {
			return;
		}

		update_option(
			self::PENDING_OPTION,
			array(
				'plugin' => $plugin,
				'time'   => time(),
			),
			false
		);

		add_action( 'shutdown', array( $this, 'verify_after_activation' ), 999 );
	}

	/**
	 * Bust the security scan cache whenever any plugin is deactivated.
	 *
	 * @since 1.3.1
	 * @return void
	 */
	public function on_deactivated_plugin() {
		delete_transient( self::SECURITY_TRANSIENT );
	}

	/**
	 * Runs at shutdown: if health fails, deactivate the plugin that was just activated.
	 *
	 * @since 1.2.0
	 * @return void
	 */
	public function verify_after_activation() {
		$pending = get_option( self::PENDING_OPTION );

		delete_option( self::PENDING_OPTION );

		if ( ! is_array( $pending ) || empty( $pending['plugin'] ) ) {
			return;
		}

		if ( $this->health_ok() ) {
			// Bust the security scan cache so it re-checks with the new plugin active.
			delete_transient( self::SECURITY_TRANSIENT );
			return;
		}

		$target = $pending['plugin'];

		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		deactivate_plugins( $target, true );

		$notice = array(
			'plugin' => $target,
			'time'   => isset( $pending['time'] ) ? (int) $pending['time'] : time(),
		);

		set_transient( 'psbdx_srm_conflict_notice_' . get_current_user_id(), $notice, HOUR_IN_SECONDS );

		/**
		 * Fires after this plugin auto-deactivated another plugin due to a failed health check.
		 *
		 * @since 1.2.0
		 * @param string $target  Plugin basename that was deactivated.
		 * @param array  $pending Pending activation payload.
		 */
		do_action( 'psbdx_srm_auto_deactivated_plugin', $target, $pending );
	}

	// =========================================================================
	// SECURITY SCAN
	// =========================================================================

	/**
	 * Run a lightweight security/integrity scan and cache the results.
	 *
	 * Called on admin_init so it doesn't block front-end requests.
	 * Results are cached for 5 minutes to avoid repeated DB queries.
	 *
	 * @since 1.3.1
	 * @return void
	 */
	public function run_security_scan() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$cached = get_transient( self::SECURITY_TRANSIENT );

		if ( false !== $cached ) {
			return;
		}

		$issues = array();

		// 1. Check both required post types are still registered.
		if ( ! post_type_exists( 'psbdx_report_form' ) || ! post_type_exists( 'psbdx_report_log' ) ) {
			$issues[] = __( 'One or more plugin post types are missing. A plugin conflict may have deregistered them.', 'psbdx-smart-report-management' );
		}

		// 2. Check core helper class is available.
		if ( ! class_exists( 'PSBDX_SRM_Helpers', false ) ) {
			$issues[] = __( 'Core helper class (PSBDX_SRM_Helpers) is not loaded. The plugin may be partially broken.', 'psbdx-smart-report-management' );
		}

		// 3. Check for legacy (unmigrated) forms.
		$legacy_count = PSBDX_SRM_Form_Builder::count_legacy_forms();
		if ( $legacy_count > 0 ) {
			$issues[] = sprintf(
				/* translators: %d: number of legacy forms */
				_n(
					'%d form is still using the legacy v1 builder. Migrate it to maintain frontend security.',
					'%d forms are still using the legacy v1 builder. Migrate them to maintain frontend security.',
					$legacy_count,
					'psbdx-smart-report-management'
				),
				$legacy_count
			);
		}

		// 4. Check if a captcha provider is configured when any form has captcha enabled.
		if ( '' === PSBDX_SRM_Captcha::active_provider() ) {
			global $wpdb;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- security scan; intentionally reads live data.
			$captcha_forms = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->postmeta} pm
					INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
					WHERE p.post_type = %s AND p.post_status = 'publish'
					AND pm.meta_key = %s AND pm.meta_value = %s",
					'psbdx_report_form',
					'_psbdx_captcha_enabled',
					'yes'
				)
			);

			if ( $captcha_forms > 0 ) {
				$issues[] = sprintf(
					/* translators: %1$d: forms count, %2$s: settings link */
					__( '%1$d form(s) have captcha enabled but no captcha provider is configured. %2$s.', 'psbdx-smart-report-management' ),
					$captcha_forms,
					'<a href="' . esc_url( admin_url( 'admin.php?page=psbdx-srm-settings&tab=captcha' ) ) . '">' . esc_html__( 'Configure Captcha', 'psbdx-smart-report-management' ) . '</a>'
				);
			}
		}

		// 5. AI features are turned on, but this site no longer meets the
		// requirements (e.g. WordPress was downgraded, or the AI Client was
		// removed, after AI was enabled). The UI already greys these
		// controls out, but flag it here too since an admin who isn't on
		// the AI tab wouldn't otherwise notice AI silently stopped running.
		if ( class_exists( 'PSBDX_SRM_AI', false ) && PSBDX_SRM_AI::is_enabled()
			&& ( ! PSBDX_SRM_AI::is_wp_version_supported() || ! PSBDX_SRM_AI::client_exists() )
		) {
			$issues[] = sprintf(
				/* translators: 1: minimum required WordPress version, 2: settings link */
				__( 'AI features are enabled but this site no longer meets the requirements (WordPress %1$s+ with the AI Client). Auto-classification and Summarize have silently stopped working. %2$s.', 'psbdx-smart-report-management' ),
				PSBDX_SRM_AI::MIN_WP_VERSION,
				'<a href="' . esc_url( admin_url( 'admin.php?page=psbdx-srm-settings&tab=ai' ) ) . '">' . esc_html__( 'Check Settings → AI', 'psbdx-smart-report-management' ) . '</a>'
			);
		}

		// 6. The AI Response Log table is marked as installed but is
		// actually missing (e.g. dbDelta() failed silently on a host with
		// restricted DB permissions). Try a one-time self-repair first —
		// only report it if that retry doesn't fix it, since this is
		// exactly the kind of drift this scan exists to catch and correct.
		if ( class_exists( 'PSBDX_SRM_AI_Log', false ) ) {
			global $wpdb;
			$ai_log_table = PSBDX_SRM_AI_Log::table_name();
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- security scan; one-off existence check, not a hot path.
			$table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $ai_log_table ) ) === $ai_log_table;

			if ( ! $table_exists ) {
				PSBDX_SRM_AI_Log::install_table();
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- re-check after the repair attempt above.
				$table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $ai_log_table ) ) === $ai_log_table;
			}

			if ( ! $table_exists ) {
				$issues[] = __( 'The AI Response Log database table is missing and could not be created automatically. Your database user may lack CREATE TABLE privileges — AI request/response logging will not work until this is fixed.', 'psbdx-smart-report-management' );
			}
		}

		// 7. AI is enabled and recent requests are consistently failing —
		// surface this proactively rather than making the admin notice on
		// their own that reports have stopped getting classified.
		if ( class_exists( 'PSBDX_SRM_AI', false ) && class_exists( 'PSBDX_SRM_AI_Log', false ) && PSBDX_SRM_AI::is_enabled() ) {
			$recent = PSBDX_SRM_AI_Log::get_recent( 5 );

			if ( count( $recent ) >= 3 ) {
				$error_count = 0;
				foreach ( $recent as $entry ) {
					if ( 'error' === $entry->status ) {
						++$error_count;
					}
				}

				if ( $error_count === count( $recent ) ) {
					$issues[] = sprintf(
						/* translators: %s: link to the AI Response Log */
						__( 'The last several AI requests all failed. AI classification and summaries are likely not working right now. %s to see why.', 'psbdx-smart-report-management' ),
						'<a href="' . esc_url( admin_url( 'admin.php?page=psbdx-srm-ai-log' ) ) . '">' . esc_html__( 'Check the AI Response Log', 'psbdx-smart-report-management' ) . '</a>'
					);
				}
			}
		}

		set_transient( self::SECURITY_TRANSIENT, $issues, 5 * MINUTE_IN_SECONDS );
	}

	/**
	 * Display a persistent security notice if the scan found issues.
	 *
	 * @since 1.3.1
	 * @return void
	 */
	public function render_security_notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$issues = get_transient( self::SECURITY_TRANSIENT );

		if ( ! is_array( $issues ) || empty( $issues ) ) {
			return;
		}

		// Legacy form notice is already rendered by the Form Builder — skip that specific message here.
		$non_legacy = array_filter( $issues, static function ( $msg ) {
			return false === strpos( $msg, 'legacy v1 builder' );
		} );

		if ( empty( $non_legacy ) ) {
			return;
		}

		echo '<div class="notice notice-error psrm-security-notice" style="border-left-color:#b91c1c;">';
		echo '<p><strong>' . esc_html__( 'PSBDx SRM — Security Alert:', 'psbdx-smart-report-management' ) . '</strong></p><ul>';

		foreach ( $non_legacy as $issue ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- contains pre-escaped HTML links built with esc_url/esc_html above.
			echo '<li>' . wp_kses( $issue, array( 'a' => array( 'href' => array() ) ) ) . '</li>';
		}

		echo '</ul></div>';
	}

	// =========================================================================
	// CONFLICT NOTICE
	// =========================================================================

	/**
	 * Whether core plugin surfaces are still registered and loadable.
	 *
	 * @since 1.2.0
	 * @return bool
	 */
	private function health_ok() {
		return post_type_exists( 'psbdx_report_form' )
			&& post_type_exists( 'psbdx_report_log' )
			&& class_exists( 'PSBDX_SRM_Helpers', false );
	}

	/**
	 * Admin notice after an auto-deactivation.
	 *
	 * @since 1.2.0
	 * @return void
	 */
	public function render_conflict_notice() {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		$key     = 'psbdx_srm_conflict_notice_' . get_current_user_id();
		$payload = get_transient( $key );

		if ( ! is_array( $payload ) || empty( $payload['plugin'] ) ) {
			return;
		}

		delete_transient( $key );

		if ( ! function_exists( 'get_plugin_data' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$data   = get_plugin_data( WP_PLUGIN_DIR . '/' . $payload['plugin'], false, false );
		$name   = ! empty( $data['Name'] ) ? $data['Name'] : $payload['plugin'];
		$plugin = esc_html( $name );

		printf(
			'<div class="notice notice-warning is-dismissible"><p><strong>%s</strong> %s</p><p>%s</p></div>',
			esc_html__( 'PSBDx Smart Report Management:', 'psbdx-smart-report-management' ),
			sprintf(
				/* translators: %s: plugin name */
				esc_html__( 'To avoid a fatal error, the plugin %s was deactivated automatically after activation because it conflicted with PSBDx Smart Report Management.', 'psbdx-smart-report-management' ),
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
				$plugin
			),
			esc_html__( 'You can try enabling it again from the Plugins screen. If the problem returns, leave that plugin inactive or contact its author.', 'psbdx-smart-report-management' )
		);
	}
}
