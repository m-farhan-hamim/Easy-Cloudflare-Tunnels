<?php
/**
 * Settings and maintenance (repair) tools for PSBDx Smart Report Management.
 *
 * @package PSBDx_Smart_Report_Management
 * @since   1.2.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class PSBDX_SRM_Admin_Tools
 *
 * Registers Settings (custom statuses) and Repair & Reset admin pages.
 *
 * @since 1.2.0
 */
class PSBDX_SRM_Admin_Tools {

	/**
	 * Settings submenu slug.
	 *
	 * @since 1.2.0
	 * @var string
	 */
	const PAGE_SETTINGS = 'psbdx-srm-settings';

	/**
	 * Repair submenu slug.
	 *
	 * @since 1.2.0
	 * @var string
	 */
	const PAGE_REPAIR = 'psbdx-srm-repair';

	/**
	 * Constructor.
	 *
	 * @since 1.2.0
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register_pages' ), 100 );
		add_action( 'admin_init', array( $this, 'handle_settings_save' ) );
		add_action( 'admin_init', array( $this, 'handle_repair_actions' ) );
		add_action( 'wp_ajax_psbdx_srm_test_captcha_creds', array( $this, 'ajax_test_captcha_creds' ) );
		add_action( 'wp_ajax_psbdx_srm_test_ai_prompt',     array( $this, 'ajax_test_ai_prompt' ) );
	}

	/**
	 * Registers submenu pages under the main PSBDx Reports menu.
	 *
	 * @since 1.2.0
	 * @return void
	 */
	public function register_pages() {
		add_submenu_page(
			PSBDX_SRM_Post_Types::ADMIN_MENU_SLUG,
			__( 'Report Settings', 'psbdx-smart-report-management' ),
			__( 'Settings', 'psbdx-smart-report-management' ),
			'manage_options',
			self::PAGE_SETTINGS,
			array( $this, 'render_settings_page' )
		);

		add_submenu_page(
			PSBDX_SRM_Post_Types::ADMIN_MENU_SLUG,
			__( 'Repair & Reset', 'psbdx-smart-report-management' ),
			__( 'Repair & Reset', 'psbdx-smart-report-management' ),
			'manage_options',
			self::PAGE_REPAIR,
			array( $this, 'render_repair_page' )
		);
	}

	/**
	 * Saves custom statuses from the settings form.
	 *
	 * @since 1.2.0
	 * @return void
	 */
	public function handle_settings_save() {
		$is_status_save     = isset( $_POST['psbdx_srm_save_settings'] );
		$is_global_save     = isset( $_POST['psbdx_srm_save_rate_limit'] );
		$is_captcha_save    = isset( $_POST['psbdx_srm_save_captcha'] );
		$is_categories_save = isset( $_POST['psbdx_srm_save_categories'] );
		$is_ai_save         = isset( $_POST['psbdx_srm_save_ai'] );
		$is_email_save      = isset( $_POST['psbdx_srm_save_email'] );

		if ( ! $is_status_save && ! $is_global_save && ! $is_captcha_save && ! $is_categories_save && ! $is_ai_save && ! $is_email_save ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( $is_categories_save ) {
			check_admin_referer( 'psbdx_srm_categories_settings' );
			$this->save_categories_settings();
			return;
		}

		if ( $is_email_save ) {
			check_admin_referer( 'psbdx_srm_email_settings' );

			// save_templates() expects the raw (still magic-quotes-slashed)
			// $_POST value and unslashes each field itself — do NOT
			// wp_unslash() here too, or a literal backslash the admin typed
			// into a subject/body (e.g. a Windows path) gets stripped twice.
			$posted = isset( $_POST['psbdx_email'] ) ? $_POST['psbdx_email'] : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Missing -- nonce already verified above; unslashed + sanitized field-by-field in save_templates().
			PSBDX_SRM_Emails::save_templates( is_array( $posted ) ? $posted : array() );

			$sender = isset( $_POST['psbdx_email_sender'] ) && is_array( $_POST['psbdx_email_sender'] ) ? wp_unslash( $_POST['psbdx_email_sender'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Missing -- nonce already verified above; sanitized in save_sender().
			PSBDX_SRM_Emails::save_sender( $sender['name'] ?? '', $sender['email'] ?? '' );

			update_option( PSBDX_SRM_Emails::ATTACH_FILES_OPTION, isset( $_POST['psbdx_email_attach_files'] ) ? 'yes' : 'no', false );

			add_settings_error(
				'psbdx_srm_settings',
				'email_saved',
				__( 'Email settings saved.', 'psbdx-smart-report-management' ),
				'success'
			);

			return;
		}

		if ( $is_ai_save ) {
			check_admin_referer( 'psbdx_srm_ai_settings' );
			$this->save_ai_settings();
			return;
		}

		if ( $is_global_save ) {
			check_admin_referer( 'psbdx_srm_global_rate_limit' );

			$mins = isset( $_POST['psbdx_srm_global_rate_limit_mins'] )
				? (int) $_POST['psbdx_srm_global_rate_limit_mins']
				: 30;

			$mins = min( 1440, max( 0, $mins ) );

			update_option( PSBDX_SRM_Helpers::GLOBAL_RATE_LIMIT_OPTION, $mins, false );

			add_settings_error(
				'psbdx_srm_settings',
				'global_rate_saved',
				__( 'Global rate limit saved.', 'psbdx-smart-report-management' ),
				'success'
			);

			return;
		}

		if ( $is_captcha_save ) {
			check_admin_referer( 'psbdx_srm_captcha_settings' );
			$this->save_captcha_settings();
			return;
		}

		check_admin_referer( 'psbdx_srm_settings' );

		$keys    = isset( $_POST['psbdx_srm_status_key'] ) ? array_map( 'sanitize_key', wp_unslash( (array) $_POST['psbdx_srm_status_key'] ) ) : array();
		$labels  = isset( $_POST['psbdx_srm_status_label'] ) ? array_map( 'sanitize_text_field', wp_unslash( (array) $_POST['psbdx_srm_status_label'] ) ) : array();
		$bgs     = isset( $_POST['psbdx_srm_status_bg'] ) ? array_map( 'sanitize_hex_color', wp_unslash( (array) $_POST['psbdx_srm_status_bg'] ) ) : array();
		$colors  = isset( $_POST['psbdx_srm_status_color'] ) ? array_map( 'sanitize_hex_color', wp_unslash( (array) $_POST['psbdx_srm_status_color'] ) ) : array();
		$remove  = isset( $_POST['psbdx_srm_status_remove'] ) ? array_map( 'sanitize_key', wp_unslash( (array) $_POST['psbdx_srm_status_remove'] ) ) : array();

		if ( ! is_array( $keys ) || ! is_array( $labels ) || ! is_array( $bgs ) || ! is_array( $colors ) ) {
			return;
		}

		$count   = count( $labels );
		$new_map = array();

		for ( $i = 0; $i < $count; $i++ ) {
			$label = isset( $labels[ $i ] ) ? sanitize_text_field( $labels[ $i ] ) : '';

			if ( '' === $label ) {
				continue;
			}

			$key = isset( $keys[ $i ] ) ? sanitize_key( $keys[ $i ] ) : '';

			if ( '' === $key || 0 !== strpos( $key, 'psbdx_c_', 0 ) ) {
				$key = 'psbdx_c_' . strtolower( wp_generate_password( 10, false, false ) );
			}

			if ( in_array( $key, $remove, true ) ) {
				continue;
			}

			$bg_raw    = isset( $bgs[ $i ] ) ? (string) $bgs[ $i ] : '';
			$color_raw = isset( $colors[ $i ] ) ? (string) $colors[ $i ] : '';

			$new_map[ $key ] = array(
				'label' => $label,
				'bg'    => $bg_raw,
				'color' => $color_raw,
			);
		}

		$new_map = PSBDX_SRM_Helpers::sanitize_custom_status_map( $new_map );

		update_option( PSBDX_SRM_Helpers::CUSTOM_STATUSES_OPTION, $new_map, false );

		add_settings_error(
			'psbdx_srm_settings',
			'settings_saved',
			__( 'Custom statuses saved.', 'psbdx-smart-report-management' ),
			'success'
		);
	}

	/**
	 * Saves admin-defined report categories from the Categories tab.
	 *
	 * @since 1.4.1
	 * @return void
	 */
	private function save_categories_settings() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce verified via check_admin_referer() in handle_settings_save() before this private method is called.
		$labels = isset( $_POST['psbdx_srm_category_label'] )
			? array_map( 'sanitize_text_field', wp_unslash( (array) $_POST['psbdx_srm_category_label'] ) )
			: array();
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$categories = PSBDX_SRM_Helpers::sanitize_report_categories( $labels );

		update_option( PSBDX_SRM_Helpers::CATEGORIES_OPTION, $categories, false );

		add_settings_error(
			'psbdx_srm_settings',
			'categories_saved',
			__( 'Report categories saved.', 'psbdx-smart-report-management' ),
			'success'
		);
	}

	/**
	 * Saves the AI settings (Settings → AI → Manage).
	 *
	 * @since 1.4.1
	 * @return void
	 */
	private function save_ai_settings() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce verified via check_admin_referer() in handle_settings_save() before this private method is called.
		$enabled = isset( $_POST['psbdx_srm_ai_enabled'] ) ? '1' : '0';

		// Never silently enable AI on a site that cannot actually run it.
		if ( '1' === $enabled && ! PSBDX_SRM_AI::is_wp_version_supported() ) {
			$enabled = '0';

			add_settings_error(
				'psbdx_srm_settings',
				'ai_wp_version',
				sprintf(
					/* translators: %s: minimum required WordPress version */
					__( 'AI features could not be enabled: WordPress %s or higher is required.', 'psbdx-smart-report-management' ),
					PSBDX_SRM_AI::MIN_WP_VERSION
				),
				'error'
			);
		}

		update_option( PSBDX_SRM_AI::ENABLED_OPTION, $enabled, false );

		$models = isset( $_POST['psbdx_srm_ai_model_preference'] )
			? sanitize_text_field( wp_unslash( $_POST['psbdx_srm_ai_model_preference'] ) )
			: '';
		update_option( PSBDX_SRM_AI::MODEL_OPTION, $models, false );

		$max_tokens = isset( $_POST['psbdx_srm_ai_max_tokens'] ) ? (int) $_POST['psbdx_srm_ai_max_tokens'] : 600;
		update_option( PSBDX_SRM_AI::MAX_TOKENS_OPTION, min( 8000, max( 50, $max_tokens ) ), false );

		$temperature = isset( $_POST['psbdx_srm_ai_temperature'] ) ? (float) $_POST['psbdx_srm_ai_temperature'] : 0.2;
		update_option( PSBDX_SRM_AI::TEMPERATURE_OPTION, min( 2.0, max( 0.0, $temperature ) ), false );

		$reply_enabled = isset( $_POST['psbdx_srm_ai_reply_enabled'] ) ? '1' : '0';

		if ( '1' === $reply_enabled && '1' !== $enabled ) {
			// Can't allow AI replies site-wide if AI itself isn't enabled/available.
			$reply_enabled = '0';
		}

		update_option( PSBDX_SRM_AI::REPLY_ENABLED_OPTION, $reply_enabled, false );
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		add_settings_error(
			'psbdx_srm_settings',
			'ai_saved',
			__( 'AI settings saved.', 'psbdx-smart-report-management' ),
			'success'
		);
	}

	/**
	 * AJAX: send a one-off test prompt through the AI Client using the
	 * currently saved (not necessarily yet submitted) settings.
	 *
	 * @since 1.4.1
	 * @return void
	 */
	public function ajax_test_ai_prompt() {
		check_ajax_referer( 'psbdx_srm_test_ai_prompt', 'security' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'psbdx-smart-report-management' ) );
		}

		$prompt = isset( $_POST['prompt'] ) ? sanitize_textarea_field( wp_unslash( $_POST['prompt'] ) ) : '';
		$result = PSBDX_SRM_AI::test_prompt( $prompt );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}

		wp_send_json_success( $result );
	}

	/**
	 * Handles repair POST actions (scan is rendered on GET; destructive ops on POST).
	 *
	 * @since 1.2.0
	 * @return void
	 */
	public function handle_repair_actions() {
		if ( ! is_admin() ) {
			return;
		}

		if ( empty( $_POST['psbdx_srm_repair_action'] ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		check_admin_referer( 'psbdx_srm_repair' );

		$action = sanitize_key( wp_unslash( $_POST['psbdx_srm_repair_action'] ) );

		if ( 'clear_rate_limits' === $action ) {
			$deleted = $this->delete_rate_limit_transients();
			add_settings_error(
				'psbdx_srm_repair',
				'cleared_limits',
				sprintf(
					/* translators: %d: number of deleted options rows */
					__( 'Cleared %d rate-limit entries from the database.', 'psbdx-smart-report-management' ),
					(int) $deleted
				),
				'success'
			);
		}

		if ( 'fix_status_meta' === $action ) {
			$fixed = $this->fix_invalid_status_meta();
			add_settings_error(
				'psbdx_srm_repair',
				'fixed_meta',
				sprintf(
					/* translators: %d: number of updated meta rows */
					__( 'Normalized %d report status meta value(s) to “Processing”.', 'psbdx-smart-report-management' ),
					(int) $fixed
				),
				'success'
			);
		}

		if ( 'clear_captcha_keys' === $action ) {
			$this->clear_captcha_options();
			add_settings_error(
				'psbdx_srm_repair',
				'captcha_cleared',
				__( 'All saved captcha keys and settings have been cleared.', 'psbdx-smart-report-management' ),
				'success'
			);
		}

		if ( 'clear_security_cache' === $action ) {
			delete_transient( PSBDX_SRM_Conflict_Guard::SECURITY_TRANSIENT );
			delete_transient( 'psrm_legacy_form_count' );
			add_settings_error(
				'psbdx_srm_repair',
				'security_cache_cleared',
				__( 'Security scan cache cleared. The scan will re-run on the next admin page load.', 'psbdx-smart-report-management' ),
				'success'
			);
		}
	}

	/**
	 * Settings screen: custom statuses.
	 *
	 * @since 1.2.0
	 * @return void
	 */
	public function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		settings_errors( 'psbdx_srm_settings' );

		/**
		 * Filters the Settings page's tab list. Core tabs are listed first;
		 * addons (e.g. the Push Notification add-on) can append their own
		 * tab here instead of registering a separate admin page, so it
		 * shows up as a normal tab in this same screen.
		 *
		 * @since 1.4.2
		 * @param array $tabs  Map of tab slug => tab label.
		 */
		$tabs = apply_filters(
			'psbdx_srm_settings_tabs',
			array(
				'status'     => __( 'Status', 'psbdx-smart-report-management' ),
				'categories' => __( 'Categories', 'psbdx-smart-report-management' ),
				'rate-limit' => __( 'Global Rate Limiting', 'psbdx-smart-report-management' ),
				'captcha'    => __( 'Captcha', 'psbdx-smart-report-management' ),
				'ai'         => __( 'AI', 'psbdx-smart-report-management' ),
				'email'      => __( 'Email', 'psbdx-smart-report-management' ),
			)
		);

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only tab param; value is allowlisted on the next line.
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'status';
		$tab = array_key_exists( $tab, $tabs ) ? $tab : 'status';
		?>
		<div class="wrap psbdx-srm-tools">
			<h1><?php esc_html_e( 'Report settings', 'psbdx-smart-report-management' ); ?></h1>
			<h2 class="nav-tab-wrapper">
				<?php foreach ( $tabs as $tab_slug => $tab_label ) : ?>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE_SETTINGS . '&tab=' . $tab_slug ) ); ?>" class="nav-tab <?php echo $tab_slug === $tab ? 'nav-tab-active' : ''; ?>"><?php echo esc_html( $tab_label ); ?></a>
				<?php endforeach; ?>
			</h2>
			<?php
			if ( 'status' === $tab ) {
				$this->render_status_settings_tab();
			} elseif ( 'categories' === $tab ) {
				$this->render_categories_settings_tab();
			} elseif ( 'rate-limit' === $tab ) {
				$this->render_rate_limit_settings_tab();
			} elseif ( 'captcha' === $tab ) {
				$this->render_captcha_settings_tab();
			} elseif ( 'ai' === $tab ) {
				$this->render_ai_settings_tab();
			} elseif ( 'email' === $tab ) {
				$this->render_email_settings_tab();
			} elseif ( has_action( 'psbdx_srm_settings_tab_content' ) ) {
				/**
				 * Fires to render the content of a non-core tab (one added
				 * via the `psbdx_srm_settings_tabs` filter above). The
				 * hooked callback is responsible for checking $tab itself
				 * and only rendering when it matches its own slug.
				 *
				 * @since 1.4.2
				 * @param string $tab  The current tab slug.
				 */
				do_action( 'psbdx_srm_settings_tab_content', $tab );
			} else {
				$this->render_coming_soon_tab( __( 'This tab has no content yet.', 'psbdx-smart-report-management' ) );
			}
			?>
		</div>
		<?php
	}

	/**
	 * Status tab content.
	 *
	 * @since 1.2.0
	 * @return void
	 */
	private function render_status_settings_tab() {
		$custom = PSBDX_SRM_Helpers::sanitize_custom_status_map( PSBDX_SRM_Helpers::get_custom_statuses_stored() );
		$built  = PSBDX_SRM_Helpers::get_default_statuses();
		?>
		<p class="description">
			<?php esc_html_e( 'Add unlimited custom report statuses with their own label and colours. Built-in statuses cannot be removed here.', 'psbdx-smart-report-management' ); ?>
		</p>
		<h2 class="title"><?php esc_html_e( 'Built-in statuses', 'psbdx-smart-report-management' ); ?></h2>
		<table class="widefat striped" style="max-width:920px;">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Key', 'psbdx-smart-report-management' ); ?></th>
					<th><?php esc_html_e( 'Label', 'psbdx-smart-report-management' ); ?></th>
					<th><?php esc_html_e( 'Preview', 'psbdx-smart-report-management' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $built as $key => $row ) : ?>
				<tr>
					<td><code><?php echo esc_html( $key ); ?></code></td>
					<td><?php echo esc_html( $row['label'] ); ?></td>
					<td>
						<span class="psbdx-badge" style="<?php echo esc_attr( PSBDX_SRM_Helpers::get_status_inline_style( $key ) ); ?>padding:4px 10px;border-radius:999px;">
							<?php echo esc_html( $row['label'] ); ?>
						</span>
					</td>
				</tr>
				<?php endforeach; ?>
			</tbody>
		</table>

		<form method="post" action="" style="margin-top:24px;max-width:920px;" id="psbdx-srm-status-form">
			<?php wp_nonce_field( 'psbdx_srm_settings' ); ?>
			<h2 class="title"><?php esc_html_e( 'Custom statuses', 'psbdx-smart-report-management' ); ?></h2>
			<table class="widefat psbdx-srm-status-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Label', 'psbdx-smart-report-management' ); ?></th>
						<th><?php esc_html_e( 'Background', 'psbdx-smart-report-management' ); ?></th>
						<th><?php esc_html_e( 'Text', 'psbdx-smart-report-management' ); ?></th>
						<th><?php esc_html_e( 'Action', 'psbdx-smart-report-management' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $custom as $key => $row ) : ?>
					<tr>
						<td>
							<input type="hidden" name="psbdx_srm_status_key[]" value="<?php echo esc_attr( $key ); ?>">
							<input type="text" class="regular-text" name="psbdx_srm_status_label[]" value="<?php echo esc_attr( $row['label'] ); ?>" required>
						</td>
						<td><input type="text" class="psbdx-srm-color" name="psbdx_srm_status_bg[]" value="<?php echo esc_attr( $row['bg'] ); ?>" pattern="^#([A-Fa-f0-9]{6}|[A-Fa-f0-9]{3})$" placeholder="#e2e8f0"></td>
						<td><input type="text" class="psbdx-srm-color" name="psbdx_srm_status_color[]" value="<?php echo esc_attr( $row['color'] ); ?>" pattern="^#([A-Fa-f0-9]{6}|[A-Fa-f0-9]{3})$" placeholder="#475569"></td>
						<td><button type="button" class="button-link-delete psbdx-srm-remove-status-row" data-status-key="<?php echo esc_attr( $key ); ?>"><?php esc_html_e( 'Remove', 'psbdx-smart-report-management' ); ?></button></td>
					</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<p>
				<button type="button" id="psbdx-srm-add-status-row" class="button">
					<?php esc_html_e( 'Add another status', 'psbdx-smart-report-management' ); ?>
				</button>
			</p>
			<p>
				<button type="submit" name="psbdx_srm_save_settings" class="button button-primary" value="1">
					<?php esc_html_e( 'Save custom statuses', 'psbdx-smart-report-management' ); ?>
				</button>
			</p>
		</form>
		<script>
			(function () {
				const addBtn = document.getElementById('psbdx-srm-add-status-row');
				const form = document.getElementById('psbdx-srm-status-form');
				const table = document.querySelector('.psbdx-srm-status-table tbody');
				if (!addBtn || !form || !table) {
					return;
				}

				addBtn.addEventListener('click', function () {
					const row = document.createElement('tr');
					row.innerHTML =
						'<td><input type="hidden" name="psbdx_srm_status_key[]" value=""><input type="text" class="regular-text" name="psbdx_srm_status_label[]" value="" placeholder="<?php echo esc_js( __( 'New status label', 'psbdx-smart-report-management' ) ); ?>"></td>' +
						'<td><input type="text" class="psbdx-srm-color" name="psbdx_srm_status_bg[]" value="#e2e8f0" pattern="^#([A-Fa-f0-9]{6}|[A-Fa-f0-9]{3})$"></td>' +
						'<td><input type="text" class="psbdx-srm-color" name="psbdx_srm_status_color[]" value="#475569" pattern="^#([A-Fa-f0-9]{6}|[A-Fa-f0-9]{3})$"></td>' +
						'<td><button type="button" class="button-link-delete psbdx-srm-remove-status-row"><?php echo esc_js( __( 'Remove', 'psbdx-smart-report-management' ) ); ?></button></td>';
					table.appendChild(row);
				});

				table.addEventListener('click', function (event) {
					const target = event.target;
					if (!target.classList.contains('psbdx-srm-remove-status-row')) {
						return;
					}

					const row = target.closest('tr');
					if (!row) {
						return;
					}

					const key = target.getAttribute('data-status-key');
					if (key) {
						const hidden = document.createElement('input');
						hidden.type = 'hidden';
						hidden.name = 'psbdx_srm_status_remove[]';
						hidden.value = key;
						form.appendChild(hidden);
					}
					row.remove();
				});
			})();
		</script>
		<?php
	}

	/**
	 * Global rate-limiting tab content.
	 *
	 * @since 1.2.0
	 * @return void
	 */
	private function render_rate_limit_settings_tab() {
		$global_mins = PSBDX_SRM_Helpers::get_global_rate_limit_mins();
		?>
		<p class="description">
			<?php esc_html_e( 'Set a global cooldown for report forms. If a specific report form has its own cooldown configured, that form-level value overrides this global setting.', 'psbdx-smart-report-management' ); ?>
		</p>
		<form method="post" action="" style="max-width:920px;">
			<?php wp_nonce_field( 'psbdx_srm_global_rate_limit' ); ?>
			<table class="form-table" role="presentation">
				<tbody>
					<tr>
						<th scope="row"><label for="psbdx_srm_global_rate_limit_mins"><?php esc_html_e( 'Global cooldown (minutes)', 'psbdx-smart-report-management' ); ?></label></th>
						<td>
							<input type="number" min="0" max="1440" class="small-text" id="psbdx_srm_global_rate_limit_mins" name="psbdx_srm_global_rate_limit_mins" value="<?php echo esc_attr( $global_mins ); ?>">
							<p class="description"><?php esc_html_e( 'Use 0 to disable global cooldown.', 'psbdx-smart-report-management' ); ?></p>
						</td>
					</tr>
				</tbody>
			</table>
			<p>
				<button type="submit" name="psbdx_srm_save_rate_limit" class="button button-primary" value="1">
					<?php esc_html_e( 'Save global rate limit', 'psbdx-smart-report-management' ); ?>
				</button>
			</p>
		</form>
		<?php
	}

	/**
	 * Categories tab content.
	 *
	 * @since 1.4.1
	 * @return void
	 */
	private function render_categories_settings_tab() {
		$categories = PSBDX_SRM_Helpers::get_report_categories();
		?>
		<p class="description">
			<?php esc_html_e( 'Define the categories reports can be sorted into. These power the manual Category dropdown on the report edit screen and, when AI features are enabled, constrain the AI\'s category suggestion to this exact list. Leave the list empty to let the AI propose its own category for each report instead.', 'psbdx-smart-report-management' ); ?>
		</p>

		<form method="post" action="" style="max-width:920px;" id="psbdx-srm-categories-form">
			<?php wp_nonce_field( 'psbdx_srm_categories_settings' ); ?>
			<table class="widefat psbdx-srm-category-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Category label', 'psbdx-smart-report-management' ); ?></th>
						<th style="width:100px;"><?php esc_html_e( 'Action', 'psbdx-smart-report-management' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $categories as $cat ) : ?>
					<tr>
						<td><input type="text" class="regular-text" name="psbdx_srm_category_label[]" value="<?php echo esc_attr( $cat ); ?>" required></td>
						<td><button type="button" class="button-link-delete psbdx-srm-remove-category-row"><?php esc_html_e( 'Remove', 'psbdx-smart-report-management' ); ?></button></td>
					</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<p>
				<button type="button" id="psbdx-srm-add-category-row" class="button">
					<?php esc_html_e( 'Add another category', 'psbdx-smart-report-management' ); ?>
				</button>
			</p>
			<p>
				<button type="submit" name="psbdx_srm_save_categories" class="button button-primary" value="1">
					<?php esc_html_e( 'Save categories', 'psbdx-smart-report-management' ); ?>
				</button>
			</p>
		</form>
		<script>
			(function () {
				const addBtn = document.getElementById('psbdx-srm-add-category-row');
				const table  = document.querySelector('.psbdx-srm-category-table tbody');
				if (!addBtn || !table) {
					return;
				}

				addBtn.addEventListener('click', function () {
					const row = document.createElement('tr');
					row.innerHTML =
						'<td><input type="text" class="regular-text" name="psbdx_srm_category_label[]" value="" placeholder="<?php echo esc_js( __( 'New category', 'psbdx-smart-report-management' ) ); ?>"></td>' +
						'<td><button type="button" class="button-link-delete psbdx-srm-remove-category-row"><?php echo esc_js( __( 'Remove', 'psbdx-smart-report-management' ) ); ?></button></td>';
					table.appendChild(row);
				});

				table.addEventListener('click', function (event) {
					if (!event.target.classList.contains('psbdx-srm-remove-category-row')) {
						return;
					}
					const row = event.target.closest('tr');
					if (row) {
						row.remove();
					}
				});
			})();
		</script>
		<?php
	}

	/**
	 * AI tab content (Manage / Knowledgebase sub-tabs).
	 *
	 * @since 1.4.1
	 * @return void
	 */
	private function render_ai_settings_tab() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only subtab param; value is allowlisted on the next line.
		$subtab = isset( $_GET['subtab'] ) ? sanitize_key( wp_unslash( $_GET['subtab'] ) ) : 'manage';
		$subtab = in_array( $subtab, array( 'manage', 'knowledgebase' ), true ) ? $subtab : 'manage';

		$base_url = admin_url( 'admin.php?page=' . self::PAGE_SETTINGS . '&tab=ai' );
		?>
		<h2 class="nav-tab-wrapper" style="margin-bottom:20px;">
			<a href="<?php echo esc_url( $base_url . '&subtab=manage' ); ?>" class="nav-tab <?php echo 'manage' === $subtab ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'Manage', 'psbdx-smart-report-management' ); ?></a>
			<a href="<?php echo esc_url( $base_url . '&subtab=knowledgebase' ); ?>" class="nav-tab <?php echo 'knowledgebase' === $subtab ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'Knowledgebase', 'psbdx-smart-report-management' ); ?></a>
		</h2>
		<?php
		if ( 'knowledgebase' === $subtab ) {
			$this->render_coming_soon_tab( __( 'AI-powered knowledgebase suggestions are coming in a future release.', 'psbdx-smart-report-management' ) );
			return;
		}

		$this->render_ai_manage_subtab();
	}

	/**
	 * AI → Manage sub-tab: enable toggle, model/token/temperature settings,
	 * and a test-request tool.
	 *
	 * @since 1.4.1
	 * @return void
	 */
	private function render_ai_manage_subtab() {
		$wp_version = get_bloginfo( 'version' );
		$wp_ok      = PSBDX_SRM_AI::is_wp_version_supported();
		$client_ok  = PSBDX_SRM_AI::client_exists();
		$ready      = $wp_ok && $client_ok;
		?>
		<p class="description">
			<?php esc_html_e( 'Let AI automatically suggest a category and priority for every new report. This requires WordPress 7.0 or higher with an AI provider connected under Settings → Connectors. Whether or not this is enabled, admins can always classify reports manually from the report edit screen.', 'psbdx-smart-report-management' ); ?>
		</p>

		<?php if ( ! $wp_ok ) : ?>
			<div class="notice notice-warning inline" style="margin:0 0 16px;">
				<p>
					<?php
					printf(
						/* translators: %s: currently installed WordPress version, bolded */
						esc_html__( 'AI features require WordPress 7.0 or higher. This site is running version %s, so the controls below are disabled until WordPress is updated.', 'psbdx-smart-report-management' ),
						'<strong>' . esc_html( $wp_version ) . '</strong>'
					);
					?>
				</p>
			</div>
		<?php elseif ( ! $client_ok ) : ?>
			<div class="notice notice-warning inline" style="margin:0 0 16px;">
				<p><?php esc_html_e( 'WordPress 7.0+ was detected, but the built-in AI Client is not available on this install, so the controls below are disabled.', 'psbdx-smart-report-management' ); ?></p>
			</div>
		<?php elseif ( PSBDX_SRM_AI::is_enabled() ) : ?>
			<div class="notice notice-info inline" style="margin:0 0 16px;">
				<p>
					<?php
					printf(
						/* translators: 1: link to the Connectors settings screen, 2: link to the test tool further down this page */
						esc_html__( 'Make sure a provider is connected under %1$s, then use the %2$s below to confirm it responds correctly.', 'psbdx-smart-report-management' ),
						'<a href="' . esc_url( admin_url( 'options-general.php?page=connectors' ) ) . '">' . esc_html__( 'Settings → Connectors', 'psbdx-smart-report-management' ) . '</a>',
						'<a href="#psbdx-ai-test-btn">' . esc_html__( 'test request tool', 'psbdx-smart-report-management' ) . '</a>'
					);
					?>
				</p>
			</div>
		<?php endif; ?>

		<form method="post" action="" style="max-width:920px;" id="psbdx-ai-settings-form">
			<?php wp_nonce_field( 'psbdx_srm_ai_settings' ); ?>
			<fieldset <?php disabled( $ready, false ); ?>>
				<table class="form-table" role="presentation">
					<tbody>
						<tr>
							<th scope="row"><?php esc_html_e( 'Enable AI features', 'psbdx-smart-report-management' ); ?></th>
							<td>
								<label class="psbdx-toggle">
									<input type="checkbox" name="psbdx_srm_ai_enabled" value="1" <?php checked( PSBDX_SRM_AI::is_enabled() ); ?>>
									<span class="psbdx-toggle-slider"></span>
								</label>
								<p class="description"><?php esc_html_e( 'When enabled, every new report is automatically sent to the connected AI provider to suggest a category and priority.', 'psbdx-smart-report-management' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="psbdx_srm_ai_model_preference"><?php esc_html_e( 'Preferred model(s)', 'psbdx-smart-report-management' ); ?></label></th>
							<td>
								<input type="text" class="large-text" id="psbdx_srm_ai_model_preference" name="psbdx_srm_ai_model_preference" value="<?php echo esc_attr( get_option( PSBDX_SRM_AI::MODEL_OPTION, '' ) ); ?>" placeholder="claude-sonnet-4-6, gemini-3.1-pro-preview, gpt-5.4">
								<p class="description"><?php esc_html_e( 'Comma-separated model preference, most preferred first. The first available model from a connected provider is used; leave blank to let WordPress choose automatically.', 'psbdx-smart-report-management' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="psbdx_srm_ai_max_tokens"><?php esc_html_e( 'Token limit', 'psbdx-smart-report-management' ); ?></label></th>
							<td>
								<input type="number" min="50" max="8000" class="small-text" id="psbdx_srm_ai_max_tokens" name="psbdx_srm_ai_max_tokens" value="<?php echo esc_attr( PSBDX_SRM_AI::get_max_tokens() ); ?>">
								<p class="description"><?php esc_html_e( 'Maximum tokens allowed per AI request (50–8000).', 'psbdx-smart-report-management' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="psbdx_srm_ai_temperature"><?php esc_html_e( 'Temperature', 'psbdx-smart-report-management' ); ?></label></th>
							<td>
								<input type="number" min="0" max="2" step="0.1" class="small-text" id="psbdx_srm_ai_temperature" name="psbdx_srm_ai_temperature" value="<?php echo esc_attr( PSBDX_SRM_AI::get_temperature() ); ?>">
								<p class="description"><?php esc_html_e( 'Lower values (e.g. 0.1–0.3) give more consistent, repeatable category and priority decisions.', 'psbdx-smart-report-management' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<?php esc_html_e( 'Allow AI to reply', 'psbdx-smart-report-management' ); ?>
								<span class="dashicons dashicons-info-outline psbdx-info-icon" tabindex="0"
									title="<?php esc_attr_e( 'Turning this on lets AI post replies into a report\'s conversation for any form that also has its own "Allow AI to reply" option checked. When it replies, the plugin sends the AI some data about the page the customer reported from (its visible text content) so the reply can be grounded in what the customer was actually looking at, in addition to the report text itself and the conversation so far.', 'psbdx-smart-report-management' ); ?>"
									aria-label="<?php esc_attr_e( 'More information about Allow AI to reply', 'psbdx-smart-report-management' ); ?>">
								</span>
							</th>
							<td>
								<label class="psbdx-toggle">
									<input type="checkbox" name="psbdx_srm_ai_reply_enabled" value="1" <?php checked( PSBDX_SRM_AI::is_reply_enabled() ); ?>>
									<span class="psbdx-toggle-slider"></span>
								</label>
								<p class="description">
									<?php esc_html_e( 'Site-wide master switch for AI-authored replies. A report form\'s own "Allow AI to reply" checkbox (under its Settings tab, next to "Allow Replies") only works when this is also turned on.', 'psbdx-smart-report-management' ); ?>
								</p>
							</td>
						</tr>
					</tbody>
				</table>
				<p>
					<button type="submit" name="psbdx_srm_save_ai" class="button button-primary" value="1">
						<?php esc_html_e( 'Save AI Settings', 'psbdx-smart-report-management' ); ?>
					</button>
				</p>
			</fieldset>
		</form>

		<hr>

		<h2 class="title"><?php esc_html_e( 'Send a test request', 'psbdx-smart-report-management' ); ?></h2>
		<p class="description"><?php esc_html_e( 'Verify your connected AI provider responds correctly before relying on it for live reports. This uses the settings above, whether or not they have been saved yet.', 'psbdx-smart-report-management' ); ?></p>

		<fieldset <?php disabled( $ready, false ); ?> style="max-width:920px;">
			<textarea id="psbdx-ai-test-prompt" class="large-text" rows="3"><?php echo esc_textarea( __( 'Reply with a short confirmation that the connection works.', 'psbdx-smart-report-management' ) ); ?></textarea>
			<p>
				<button type="button" class="button" id="psbdx-ai-test-btn" data-nonce="<?php echo esc_attr( wp_create_nonce( 'psbdx_srm_test_ai_prompt' ) ); ?>">
					<?php esc_html_e( 'Run Test', 'psbdx-smart-report-management' ); ?>
				</button>
				<span class="psbdx-captcha-test-result" id="psbdx-ai-test-result"></span>
			</p>
		</fieldset>

		<script>
		(function () {
			const btn = document.getElementById('psbdx-ai-test-btn');
			if (!btn) {
				return;
			}

			btn.addEventListener('click', function () {
				const promptEl = document.getElementById('psbdx-ai-test-prompt');
				const result   = document.getElementById('psbdx-ai-test-result');
				const nonce    = btn.getAttribute('data-nonce');
				if (!promptEl || !result) {
					return;
				}

				btn.disabled       = true;
				result.className   = 'psbdx-captcha-test-result psbdx-captcha-test-pending';
				result.textContent = '<?php echo esc_js( __( 'Testing…', 'psbdx-smart-report-management' ) ); ?>';

				const body = new URLSearchParams();
				body.append('action', 'psbdx_srm_test_ai_prompt');
				body.append('security', nonce);
				body.append('prompt', promptEl.value);

				fetch(ajaxurl, { method: 'POST', body: body, credentials: 'same-origin' })
					.then(function (r) { return r.json(); })
					.then(function (data) {
						btn.disabled = false;
						if (data.success) {
							const model = data.data && data.data.model ? '[' + data.data.model + '] ' : '';
							result.className   = 'psbdx-captcha-test-result psbdx-captcha-test-ok';
							result.textContent = '\u2713 ' + model + (data.data ? data.data.text : '');
						} else {
							result.className   = 'psbdx-captcha-test-result psbdx-captcha-test-fail';
							result.textContent = '\u2717 ' + (data.data || '<?php echo esc_js( __( 'Request failed.', 'psbdx-smart-report-management' ) ); ?>');
						}
					})
					.catch(function () {
						btn.disabled = false;
						result.className   = 'psbdx-captcha-test-result psbdx-captcha-test-fail';
						result.textContent = '<?php echo esc_js( __( 'Network error.', 'psbdx-smart-report-management' ) ); ?>';
					});
			});
		})();
		</script>
		<?php
	}

	/**
	 * Settings screen: Email tab — enable/edit every outgoing email template.
	 *
	 * @since 1.4.2
	 * @return void
	 */
	private function render_email_settings_tab() {
		?>
		<?php $sender = PSBDX_SRM_Emails::get_sender(); ?>
		<form method="post" action="">
			<?php wp_nonce_field( 'psbdx_srm_email_settings' ); ?>

			<p class="description" style="max-width:920px;">
				<?php esc_html_e( 'Every email the plugin can send is listed below. Turn any of them off, and rewrite the subject or body to match your voice — HTML is allowed in the body (links, formatting, images, etc.). Use the placeholders shown under each template; anything you don\'t use is simply left out of that email.', 'psbdx-smart-report-management' ); ?>
			</p>

			<div class="psbdx-email-tpl">
				<div class="psbdx-email-tpl-header">
					<span class="dashicons dashicons-admin-users" aria-hidden="true"></span>
					<strong><?php esc_html_e( 'Sender', 'psbdx-smart-report-management' ); ?></strong>
				</div>
				<div class="psbdx-email-tpl-body">
					<p class="description"><?php esc_html_e( 'Controls the "From" name and address on every email this plugin sends. Leave either one blank to use the site\'s normal default for that half.', 'psbdx-smart-report-management' ); ?></p>

					<p>
						<label for="psbdx-email-sender-name"><strong><?php esc_html_e( 'Sender name', 'psbdx-smart-report-management' ); ?></strong></label><br>
						<input type="text" class="regular-text" id="psbdx-email-sender-name"
							name="psbdx_email_sender[name]"
							placeholder="<?php echo esc_attr( get_bloginfo( 'name' ) ); ?>"
							value="<?php echo esc_attr( $sender['name'] ); ?>">
						<p class="description"><?php esc_html_e( 'Forces this exact name as the sender on every email — e.g. "Support Team" instead of the site title.', 'psbdx-smart-report-management' ); ?></p>
					</p>

					<p>
						<label for="psbdx-email-sender-email"><strong><?php esc_html_e( 'Sender email', 'psbdx-smart-report-management' ); ?></strong></label><br>
						<input type="email" class="regular-text" id="psbdx-email-sender-email"
							name="psbdx_email_sender[email]"
							placeholder="<?php echo esc_attr( 'wordpress@' . wp_parse_url( home_url(), PHP_URL_HOST ) ); ?>"
							value="<?php echo esc_attr( $sender['email'] ); ?>">
						<p class="description"><?php esc_html_e( 'Requests this exact address as the sender on every email. Some hosts/SMTP setups may still show their own mailbox regardless — this depends on how mail is actually delivered on your server.', 'psbdx-smart-report-management' ); ?></p>
					</p>

					<p>
						<label>
							<input type="checkbox" name="psbdx_email_attach_files" value="1" <?php checked( PSBDX_SRM_Emails::attach_files_enabled() ); ?>>
							<strong><?php esc_html_e( 'Add attachments to email', 'psbdx-smart-report-management' ); ?></strong>
						</label>
						<p class="description"><?php esc_html_e( 'When a reply includes a shared file, physically attach it to the reply notification email. When off (default), the email just shows an "Attachment" note instead of the real file or a link to it — safer for deliverability and privacy.', 'psbdx-smart-report-management' ); ?></p>
					</p>
				</div>
			</div>

			<?php foreach ( PSBDX_SRM_Emails::get_events() as $key => $event ) : ?>
				<?php $tpl = PSBDX_SRM_Emails::get_template( $key ); ?>
				<div class="psbdx-email-tpl">
					<div class="psbdx-email-tpl-header">
						<span class="dashicons dashicons-email-alt" aria-hidden="true"></span>
						<strong><?php echo esc_html( $event['label'] ); ?></strong>
						<label class="psbdx-toggle psbdx-email-tpl-toggle">
							<input type="checkbox" name="psbdx_email[<?php echo esc_attr( $key ); ?>][enabled]" value="1" <?php checked( $tpl['enabled'] ); ?>>
							<span class="psbdx-toggle-slider"></span>
						</label>
					</div>
					<div class="psbdx-email-tpl-body">
						<p class="description"><?php echo esc_html( $event['description'] ); ?></p>

						<p>
							<label for="psbdx-email-subject-<?php echo esc_attr( $key ); ?>"><strong><?php esc_html_e( 'Subject', 'psbdx-smart-report-management' ); ?></strong></label><br>
							<input type="text" class="large-text" id="psbdx-email-subject-<?php echo esc_attr( $key ); ?>"
								name="psbdx_email[<?php echo esc_attr( $key ); ?>][subject]"
								value="<?php echo esc_attr( $tpl['subject'] ); ?>">
						</p>

						<p>
							<label for="psbdx-email-body-<?php echo esc_attr( $key ); ?>"><strong><?php esc_html_e( 'Body (HTML allowed)', 'psbdx-smart-report-management' ); ?></strong></label><br>
							<textarea class="large-text code" id="psbdx-email-body-<?php echo esc_attr( $key ); ?>"
								name="psbdx_email[<?php echo esc_attr( $key ); ?>][body]"
								rows="8"><?php echo esc_textarea( $tpl['body'] ); ?></textarea>
						</p>

						<p class="description">
							<strong><?php esc_html_e( 'Available placeholders:', 'psbdx-smart-report-management' ); ?></strong>
							<?php foreach ( $event['placeholders'] as $ph ) : ?>
								<code style="margin-right:4px;"><?php echo esc_html( $ph ); ?></code>
							<?php endforeach; ?>
						</p>
					</div>
				</div>
			<?php endforeach; ?>

			<p>
				<button type="submit" name="psbdx_srm_save_email" class="button button-primary" value="1">
					<?php esc_html_e( 'Save Email Settings', 'psbdx-smart-report-management' ); ?>
				</button>
			</p>
		</form>
		<?php
	}

	/**
	 * Generic content renderer for tabs not yet implemented.
	 *
	 * @since 1.2.0
	 * @param string $message Notice text.
	 * @return void
	 */
	private function render_coming_soon_tab( $message ) {
		?>
		<div class="notice notice-info inline">
			<p><strong><?php esc_html_e( 'Coming soon', 'psbdx-smart-report-management' ); ?></strong></p>
			<p><?php echo esc_html( $message ); ?></p>
		</div>
		<?php
	}

	/**
	 * Repair & reset screen.
	 *
	 * @since 1.2.0
	 * @return void
	 */
	public function render_repair_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		settings_errors( 'psbdx_srm_repair' );

		$scan         = $this->run_diagnostic_scan();
		$sec_issues   = (array) get_transient( PSBDX_SRM_Conflict_Guard::SECURITY_TRANSIENT );
		$legacy_count = PSBDX_SRM_Form_Builder::count_legacy_forms();
		?>
		<div class="wrap psbdx-srm-tools">
			<h1><?php esc_html_e( 'Repair & Reset', 'psbdx-smart-report-management' ); ?></h1>
			<p class="description">
				<?php esc_html_e( 'Run a read-only scan to verify plugin data, then use the reset tools below to recover from conflicts, rate-limit locks, or invalid data.', 'psbdx-smart-report-management' ); ?>
			</p>

			<?php /* Security status banner */ ?>
			<?php if ( ! empty( $sec_issues ) || $legacy_count > 0 ) : ?>
			<div class="notice notice-error inline" style="border-left-color:#b91c1c;margin-bottom:20px;">
				<p><strong><?php esc_html_e( 'Security issues detected:', 'psbdx-smart-report-management' ); ?></strong></p>
				<ul style="list-style:disc;margin-left:1.5em;">
					<?php if ( $legacy_count > 0 ) : ?>
						<li><?php echo esc_html( sprintf(
							/* translators: %d: number of legacy forms */
							_n( '%d form still uses the legacy v1 builder.', '%d forms still use the legacy v1 builder.', $legacy_count, 'psbdx-smart-report-management' ),
							$legacy_count
						) ); ?>
						<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=psbdx_report_form' ) ); ?>"><?php esc_html_e( 'Migrate forms &rarr;', 'psbdx-smart-report-management' ); ?></a></li>
					<?php endif; ?>
					<?php foreach ( $sec_issues as $issue ) : ?>
						<li><?php echo wp_kses( $issue, array( 'a' => array( 'href' => array() ) ) ); ?></li>
					<?php endforeach; ?>
				</ul>
			</div>
			<?php else : ?>
			<div class="notice notice-success inline" style="margin-bottom:20px;">
				<p><span class="dashicons dashicons-shield-alt" aria-hidden="true" style="color:#16a34a;"></span> <strong><?php esc_html_e( 'No security issues found. All plugin systems are running normally.', 'psbdx-smart-report-management' ); ?></strong></p>
			</div>
			<?php endif; ?>

			<h2 class="title"><?php esc_html_e( 'Diagnostic scan', 'psbdx-smart-report-management' ); ?></h2>
			<table class="widefat striped" style="max-width:920px;">
				<tbody>
					<?php foreach ( $scan['lines'] as $line ) : ?>
					<tr>
						<td><?php echo esc_html( $line['label'] ); ?></td>
						<td>
							<?php if ( ! empty( $line['ok'] ) ) : ?>
								<span class="dashicons dashicons-yes-alt" style="color:#16a34a;" aria-hidden="true"></span>
							<?php else : ?>
								<span class="dashicons dashicons-warning" style="color:#ca8a04;" aria-hidden="true"></span>
							<?php endif; ?>
							<?php echo esc_html( $line['detail'] ); ?>
						</td>
					</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<?php if ( ! empty( $scan['invalid_status_sample'] ) ) : ?>
			<p><strong><?php esc_html_e( 'Invalid status samples:', 'psbdx-smart-report-management' ); ?></strong>
				<code><?php echo esc_html( implode( ', ', $scan['invalid_status_sample'] ) ); ?></code>
			</p>
			<?php endif; ?>

			<h2 class="title" style="margin-top:2em;"><?php esc_html_e( 'Maintenance actions', 'psbdx-smart-report-management' ); ?></h2>
			<ul style="max-width:920px;">
				<li>
					<a href="<?php echo esc_url( PSBDX_SRM_Setup_Wizard::get_restart_url() ); ?>" class="button">
						<?php esc_html_e( 'Restart Setup Wizard', 'psbdx-smart-report-management' ); ?>
					</a>
					<p class="description"><?php esc_html_e( 'Re-run the guided setup at any time — your current settings are shown pre-filled, and nothing is changed until you click "Finish Setup" again.', 'psbdx-smart-report-management' ); ?></p>
				</li>
				<li style="margin-top:16px;">
					<form method="post" style="display:inline;" onsubmit="return confirm('<?php echo esc_js( __( 'Clear all report cooldown / rate-limit locks? Users will be able to submit again immediately.', 'psbdx-smart-report-management' ) ); ?>');">
						<?php wp_nonce_field( 'psbdx_srm_repair' ); ?>
						<input type="hidden" name="psbdx_srm_repair_action" value="clear_rate_limits">
						<button type="submit" class="button"><?php esc_html_e( 'Clear rate-limit transients', 'psbdx-smart-report-management' ); ?></button>
					</form>
					<p class="description"><?php esc_html_e( 'Removes stored cooldown entries (psbdx_cd_*) from the options table so logged-in users are no longer blocked by old rate limits.', 'psbdx-smart-report-management' ); ?></p>
				</li>
				<li style="margin-top:16px;">
					<form method="post" style="display:inline;" onsubmit="return confirm('<?php echo esc_js( __( 'Normalize unknown status values to "Processing"? This updates report log meta in the database.', 'psbdx-smart-report-management' ) ); ?>');">
						<?php wp_nonce_field( 'psbdx_srm_repair' ); ?>
						<input type="hidden" name="psbdx_srm_repair_action" value="fix_status_meta">
						<button type="submit" class="button" <?php disabled( empty( $scan['invalid_status_count'] ) ); ?>>
							<?php esc_html_e( 'Fix invalid status meta', 'psbdx-smart-report-management' ); ?>
						</button>
					</form>
					<p class="description"><?php esc_html_e( 'Sets any report status not recognized by the plugin back to "Processing".', 'psbdx-smart-report-management' ); ?></p>
				</li>
				<li style="margin-top:16px;">
					<form method="post" style="display:inline;" onsubmit="return confirm('<?php echo esc_js( __( 'Clear all saved captcha keys? This will disable captcha on all forms until new keys are re-entered.', 'psbdx-smart-report-management' ) ); ?>');">
						<?php wp_nonce_field( 'psbdx_srm_repair' ); ?>
						<input type="hidden" name="psbdx_srm_repair_action" value="clear_captcha_keys">
						<button type="submit" class="button button-link-delete"
							<?php disabled( ! PSBDX_SRM_Captcha::any_enabled() ); ?>>
							<?php esc_html_e( 'Reset captcha keys', 'psbdx-smart-report-management' ); ?>
						</button>
					</form>
					<?php if ( ! empty( $scan['captcha_issues'] ) ) : ?>
					<span class="dashicons dashicons-warning" style="color:#ca8a04;vertical-align:middle;" aria-hidden="true"></span>
					<strong style="color:#92400e;"><?php esc_html_e( 'Captcha configuration issues detected — see scan above.', 'psbdx-smart-report-management' ); ?></strong>
					<?php endif; ?>
					<p class="description"><?php esc_html_e( 'Deletes all saved captcha site keys and secret keys from the database and disables captcha site-wide. Use this to recover from a misconfiguration that is breaking your forms.', 'psbdx-smart-report-management' ); ?></p>
				</li>
				<li style="margin-top:16px;">
					<form method="post" style="display:inline;">
						<?php wp_nonce_field( 'psbdx_srm_repair' ); ?>
						<input type="hidden" name="psbdx_srm_repair_action" value="clear_security_cache">
						<button type="submit" class="button"><?php esc_html_e( 'Refresh security scan', 'psbdx-smart-report-management' ); ?></button>
					</form>
					<p class="description"><?php esc_html_e( 'Clears the cached security scan result and forces an immediate re-check. Use this after activating or deactivating plugins to get an updated security status.', 'psbdx-smart-report-management' ); ?></p>
				</li>
			</ul>

			<h2 class="title" style="margin-top:2em;"><?php esc_html_e( 'Import / Export (CSV)', 'psbdx-smart-report-management' ); ?></h2>
			<p class="description" style="max-width:920px;">
				<?php esc_html_e( 'Back up or migrate your reports and report forms as CSV. Each export includes every setting/field in a single "meta_json" column, so importing the same file back in (here, or on another site) restores everything exactly. Reports are matched for update by ticket ID; forms are matched by exact title — anything that doesn\'t match is created as new.', 'psbdx-smart-report-management' ); ?>
			</p>
			<div class="psbdx-csv-grid">
				<div class="psbdx-csv-card">
					<h3><?php esc_html_e( 'Reports', 'psbdx-smart-report-management' ); ?></h3>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-bottom:12px;">
						<?php wp_nonce_field( 'psbdx_srm_export_reports' ); ?>
						<input type="hidden" name="action" value="psbdx_srm_export_reports">
						<button type="submit" class="button"><?php esc_html_e( 'Export reports to CSV', 'psbdx-smart-report-management' ); ?></button>
					</form>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
						<?php wp_nonce_field( 'psbdx_srm_import_reports' ); ?>
						<input type="hidden" name="action" value="psbdx_srm_import_reports">
						<input type="file" name="psbdx_csv_file" accept=".csv" required>
						<button type="submit" class="button" onclick="return confirm('<?php echo esc_js( __( 'Import reports from this CSV now?', 'psbdx-smart-report-management' ) ); ?>');">
							<?php esc_html_e( 'Import reports', 'psbdx-smart-report-management' ); ?>
						</button>
					</form>
				</div>
				<div class="psbdx-csv-card">
					<h3><?php esc_html_e( 'Forms', 'psbdx-smart-report-management' ); ?></h3>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-bottom:12px;">
						<?php wp_nonce_field( 'psbdx_srm_export_forms' ); ?>
						<input type="hidden" name="action" value="psbdx_srm_export_forms">
						<button type="submit" class="button"><?php esc_html_e( 'Export forms to CSV', 'psbdx-smart-report-management' ); ?></button>
					</form>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
						<?php wp_nonce_field( 'psbdx_srm_import_forms' ); ?>
						<input type="hidden" name="action" value="psbdx_srm_import_forms">
						<input type="file" name="psbdx_csv_file" accept=".csv" required>
						<button type="submit" class="button" onclick="return confirm('<?php echo esc_js( __( 'Import forms from this CSV now?', 'psbdx-smart-report-management' ) ); ?>');">
							<?php esc_html_e( 'Import forms', 'psbdx-smart-report-management' ); ?>
						</button>
					</form>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Runs checks that WordPress and this plugin expose reliably.
	 *
	 * @since 1.2.0
	 * @return array{lines: array<int, array{label: string, detail: string, ok: bool}>, invalid_status_count: int, invalid_status_sample: string[], captcha_issues: int}
	 */
	private function run_diagnostic_scan() {
		global $wpdb;

		$lines   = array();
		$allowed = array_keys( PSBDX_SRM_Helpers::get_statuses() );

		$lines[] = array(
			'label'  => __( 'Database connection', 'psbdx-smart-report-management' ),
			'detail' => __( 'wpdb is available.', 'psbdx-smart-report-management' ),
			'ok'     => true,
		);

		$posts_table = $wpdb->posts;
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-off admin diagnostic; caching would return stale data.
		$ok_posts    = (bool) $wpdb->get_var(
			$wpdb->prepare( 'SHOW TABLES LIKE %s', $posts_table )
		);
		$lines[]     = array(
			'label'  => __( 'Core posts table', 'psbdx-smart-report-management' ),
			'detail' => $ok_posts ? $posts_table : __( 'Posts table not found (unexpected).', 'psbdx-smart-report-management' ),
			'ok'     => $ok_posts,
		);

		$form_ok = post_type_exists( 'psbdx_report_form' );
		$log_ok  = post_type_exists( 'psbdx_report_log' );
		$lines[] = array(
			'label'  => __( 'Plugin post types registered', 'psbdx-smart-report-management' ),
			'detail' => $form_ok && $log_ok
				? __( 'psbdx_report_form and psbdx_report_log are registered.', 'psbdx-smart-report-management' )
				: __( 'One or both post types are missing from this request.', 'psbdx-smart-report-management' ),
			'ok'     => $form_ok && $log_ok,
		);

		$order_form = (int) get_option( 'psbdx_global_order_form_id', 0 );
		$prod_form  = (int) get_option( 'psbdx_global_product_form_id', 0 );

		$lines[] = array(
			'label'  => __( 'Global form options', 'psbdx-smart-report-management' ),
			'detail' => $this->describe_form_option( $order_form, $prod_form ),
			'ok'     => $this->form_options_ok( $order_form, $prod_form ),
		);

		$log_counts_obj = wp_count_posts( 'psbdx_report_log' );
		$published_logs = isset( $log_counts_obj->publish ) ? (int) $log_counts_obj->publish : 0;

		$lines[] = array(
			'label'  => __( 'Report logs', 'psbdx-smart-report-management' ),
			/* translators: %d: number of published logs */
			'detail' => sprintf( __( '%d published report(s) in the database.', 'psbdx-smart-report-management' ), $published_logs ),
			'ok'     => true,
		);

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-off admin diagnostic; caching would return stale data.
		$invalid_values = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT pm.meta_value FROM {$wpdb->postmeta} pm
				INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
				WHERE p.post_type = %s AND pm.meta_key = %s",
				'psbdx_report_log',
				'_psbdx_report_status'
			)
		);

		$invalid_sample = array();
		$invalid_count  = 0;

		if ( is_array( $invalid_values ) ) {
			foreach ( $invalid_values as $value ) {
				$value = (string) $value;

				if ( '' === $value ) {
					continue;
				}

				if ( in_array( $value, $allowed, true ) ) {
					continue;
				}

				++$invalid_count;

				if ( count( $invalid_sample ) < 8 ) {
					$invalid_sample[] = $value;
				}
			}
		}

		$lines[] = array(
			'label'  => __( 'Report status meta', 'psbdx-smart-report-management' ),
			'detail' => $invalid_count
				/* translators: %d: count of invalid meta values */
				? sprintf( __( '%d distinct invalid status value(s) detected.', 'psbdx-smart-report-management' ), $invalid_count )
				: __( 'All stored statuses match the plugin’s allowed list.', 'psbdx-smart-report-management' ),
			'ok'     => 0 === $invalid_count,
		);

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-off admin diagnostic; caching would return stale data.
		$rate_rows = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				$wpdb->esc_like( '_transient_psbdx_cd_' ) . '%',
				$wpdb->esc_like( '_transient_timeout_psbdx_cd_' ) . '%'
			)
		);

		$lines[] = array(
			'label'  => __( 'Rate-limit rows', 'psbdx-smart-report-management' ),
			/* translators: %d: rows in options table */
			'detail' => sprintf( __( '%d matching option row(s) for report cooldowns.', 'psbdx-smart-report-management' ), $rate_rows ),
			'ok'     => true,
		);

		// ── Captcha configuration health ──────────────────────────────────────
		$captcha_lines  = $this->scan_captcha_config();
		$lines          = array_merge( $lines, $captcha_lines['lines'] );
		$captcha_issues = $captcha_lines['issues'];

		return array(
			'lines'                 => $lines,
			'invalid_status_count'  => $invalid_count,
			'invalid_status_sample' => $invalid_sample,
			'captcha_issues'        => $captcha_issues,
		);
	}

	/**
	 * Scan captcha configuration for problems.
	 *
	 * Checks whether saved credentials are structurally plausible (non-empty,
	 * correct format prefix) and whether the active provider's keys are stored.
	 * Does NOT re-verify against the live API — that is the user's job via the
	 * "Test Credentials" button. Structural checks are instant and offline.
	 *
	 * @since 1.2.0
	 * @return array{lines: array<int, array{label:string,detail:string,ok:bool}>, issues: int}
	 */
	private function scan_captcha_config() {
		$lines  = array();
		$issues = 0;

		$any_enabled = PSBDX_SRM_Captcha::any_enabled();
		$provider    = PSBDX_SRM_Captcha::active_provider();

		if ( ! $any_enabled ) {
			$lines[] = array(
				'label'  => __( 'Captcha', 'psbdx-smart-report-management' ),
				'detail' => __( 'No captcha provider is enabled. Forms without captcha are unprotected.', 'psbdx-smart-report-management' ),
				'ok'     => true, // Not an error — it is a valid deliberate choice.
			);
			return array( 'lines' => $lines, 'issues' => $issues );
		}

		$label      = PSBDX_SRM_Captcha::label( $provider );
		$site_key   = PSBDX_SRM_Captcha::get_opt( $provider, 'site_key' );
		$secret_key = PSBDX_SRM_Captcha::get_opt( $provider, 'secret_key' );

		// 1. Check keys are present.
		if ( '' === $site_key || '' === $secret_key ) {
			++$issues;
			$lines[] = array(
				'label'  => sprintf(
					/* translators: %s: provider label */
					__( 'Captcha (%s)', 'psbdx-smart-report-management' ),
					$label
				),
				'detail' => __( 'Provider is enabled but one or both keys are missing. Forms with captcha enabled will not render correctly.', 'psbdx-smart-report-management' ),
				'ok'     => false,
			);
			return array( 'lines' => $lines, 'issues' => $issues );
		}

		// 2. Structural key format checks (offline — no API call).
		$format_ok  = true;
		$format_msg = '';

		if ( 'turnstile' === $provider ) {
			// Cloudflare Turnstile keys always start with "0x".
			if ( strncmp( $site_key, '0x', 2 ) !== 0 || strncmp( $secret_key, '0x', 2 ) !== 0 ) {
				$format_ok  = false;
				$format_msg = __( 'Cloudflare Turnstile keys should start with "0x". Your keys may be from a different provider.', 'psbdx-smart-report-management' );
			}
		} elseif ( 'recaptcha' === $provider ) {
			// reCAPTCHA v2 site keys are 40-character strings.
			// Only warn if clearly too short; don't block on length alone.
			if ( strlen( $site_key ) < 20 || strlen( $secret_key ) < 20 ) {
				$format_ok  = false;
				$format_msg = __( 'reCAPTCHA keys appear too short. Please verify you copied the full key from the Google reCAPTCHA console.', 'psbdx-smart-report-management' );
			}
		} elseif ( 'hcaptcha' === $provider ) {
			// hCaptcha site keys are UUIDs (36 chars). Secret keys are hex strings starting with "0x".
			if ( strlen( $site_key ) < 10 || strlen( $secret_key ) < 10 ) {
				$format_ok  = false;
				$format_msg = __( 'hCaptcha keys appear too short. Please verify you copied the full key from the hCaptcha dashboard.', 'psbdx-smart-report-management' );
			}
		}

		if ( ! $format_ok ) {
			++$issues;
			$lines[] = array(
				'label'  => sprintf(
					/* translators: %s: provider label */
					__( 'Captcha key format (%s)', 'psbdx-smart-report-management' ),
					$label
				),
				'detail' => $format_msg,
				'ok'     => false,
			);
		} else {
			$lines[] = array(
				'label'  => sprintf(
					/* translators: %s: provider label */
					__( 'Captcha (%s)', 'psbdx-smart-report-management' ),
					$label
				),
				'detail' => __( 'Provider is enabled and keys are saved. Use the Captcha Settings tab to re-verify keys against the live API.', 'psbdx-smart-report-management' ),
				'ok'     => true,
			);
		}

		// 3. Check whether any form actually has captcha turned on.
		// Direct query avoids meta_query (slow-query sniff) and is accurate for a diagnostic read.
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-off admin diagnostic count; caching would give stale results.
		$captcha_form_count = (int) $wpdb->get_var(
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

		$lines[] = array(
			'label'  => __( 'Forms with captcha enabled', 'psbdx-smart-report-management' ),
			/* translators: %d: number of forms */
			'detail' => sprintf( __( '%d form(s) have captcha turned on.', 'psbdx-smart-report-management' ), $captcha_form_count ),
			'ok'     => true,
		);

		return array( 'lines' => $lines, 'issues' => $issues );
	}

	/**
	 * Delete all stored captcha options for all providers.
	 *
	 * @since 1.2.0
	 * @return void
	 */
	private function clear_captcha_options() {
		foreach ( PSBDX_SRM_Captcha::providers() as $provider ) {
			delete_option( PSBDX_SRM_Captcha::OPT_PREFIX . $provider . '_enabled' );
			delete_option( PSBDX_SRM_Captcha::OPT_PREFIX . $provider . '_site_key' );
			delete_option( PSBDX_SRM_Captcha::OPT_PREFIX . $provider . '_secret_key' );
		}
	}

	/**
	 * @since 1.2.0
	 * @param int $order_form Global order form ID.
	 * @param int $prod_form  Global product form ID.
	 * @return string
	 */
	private function describe_form_option( $order_form, $prod_form ) {
		$parts = array();

		if ( $order_form ) {
			$parts[] = sprintf(
				/* translators: %d: post ID */
				__( 'Order form ID %1$d — %2$s', 'psbdx-smart-report-management' ),
				$order_form,
				$this->form_exists_label( $order_form )
			);
		} else {
			$parts[] = __( 'No global order form selected.', 'psbdx-smart-report-management' );
		}

		if ( $prod_form ) {
			$parts[] = sprintf(
				/* translators: %d: post ID */
				__( 'Product form ID %1$d — %2$s', 'psbdx-smart-report-management' ),
				$prod_form,
				$this->form_exists_label( $prod_form )
			);
		} else {
			$parts[] = __( 'No global product form selected.', 'psbdx-smart-report-management' );
		}

		return implode( ' ', $parts );
	}

	/**
	 * @since 1.2.0
	 * @param int $order_form Global order form ID.
	 * @param int $prod_form  Global product form ID.
	 * @return bool
	 */
	private function form_options_ok( $order_form, $prod_form ) {
		if ( $order_form && ! $this->form_post_ok( $order_form ) ) {
			return false;
		}

		if ( $prod_form && ! $this->form_post_ok( $prod_form ) ) {
			return false;
		}

		return true;
	}

	/**
	 * @since 1.2.0
	 * @param int $post_id Form post ID.
	 * @return bool
	 */
	private function form_post_ok( $post_id ) {
		return 'psbdx_report_form' === get_post_type( $post_id );
	}

	/**
	 * @since 1.2.0
	 * @param int $post_id Form post ID.
	 * @return string
	 */
	private function form_exists_label( $post_id ) {
		return $this->form_post_ok( $post_id )
			? __( 'valid', 'psbdx-smart-report-management' )
			: __( 'missing or wrong type', 'psbdx-smart-report-management' );
	}

	/**
	 * Deletes cooldown-related transients from the options table.
	 *
	 * @since 1.2.0
	 * @return int Rows deleted.
	 */
	private function delete_rate_limit_transients() {
		global $wpdb;

		$sql = $wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
			$wpdb->esc_like( '_transient_psbdx_cd_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_psbdx_cd_' ) . '%'
		);

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- built with prepare(); DELETE on transients, no sensible cache key.
		return (int) $wpdb->query( $sql );
	}

	/**
	 * Sets unrecognized status meta values back to Processing.
	 *
	 * @since 1.2.0
	 * @return int Number of meta rows updated.
	 */
	private function fix_invalid_status_meta() {
		global $wpdb;

		$allowed  = array_keys( PSBDX_SRM_Helpers::get_statuses() );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- repair tool read; must reflect live DB state.
		$values   = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT pm.meta_value FROM {$wpdb->postmeta} pm
				INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
				WHERE p.post_type = %s AND pm.meta_key = %s",
				'psbdx_report_log',
				'_psbdx_report_status'
			)
		);

		if ( ! is_array( $values ) ) {
			return 0;
		}

		$invalid = array();

		foreach ( $values as $value ) {
			$value = (string) $value;

			if ( '' === $value || in_array( $value, $allowed, true ) ) {
				continue;
			}

			$invalid[] = $value;
		}

		if ( ! $invalid ) {
			return 0;
		}

		$updated = 0;

		foreach ( $invalid as $bad ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- repair UPDATE; must write directly to fix meta values.
			$updated += (int) $wpdb->query(
				$wpdb->prepare(
					"UPDATE {$wpdb->postmeta} pm
					INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
					SET pm.meta_value = %s
					WHERE p.post_type = %s AND pm.meta_key = %s AND pm.meta_value = %s",
					'Processing',
					'psbdx_report_log',
					'_psbdx_report_status',
					$bad
				)
			);
		}

		return $updated;
	}

	// =========================================================================
	// CAPTCHA SETTINGS
	// =========================================================================

	/**
	 * AJAX: test captcha credentials before saving.
	 *
	 * @since 1.2.0
	 * @return void
	 */
	public function ajax_test_captcha_creds() {
		check_ajax_referer( 'psbdx_srm_test_captcha', 'security' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'psbdx-smart-report-management' ) );
		}

		$provider   = isset( $_POST['provider'] )   ? sanitize_key( wp_unslash( $_POST['provider'] ) )           : '';
		$site_key   = isset( $_POST['site_key'] )   ? sanitize_text_field( wp_unslash( $_POST['site_key'] ) )    : '';
		$secret_key = isset( $_POST['secret_key'] ) ? sanitize_text_field( wp_unslash( $_POST['secret_key'] ) )  : '';

		$result = PSBDX_SRM_Captcha::test_credentials( $provider, $site_key, $secret_key );

		if ( $result['ok'] ) {
			wp_send_json_success( $result['message'] );
		} else {
			wp_send_json_error( $result['message'] );
		}
	}

	/**
	 * Save captcha settings from POST.
	 *
	 * @since 1.2.0
	 * @return void
	 */
	private function save_captcha_settings() {
		foreach ( PSBDX_SRM_Captcha::providers() as $provider ) {
			// phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce verified via check_admin_referer() in handle_settings_save() before this private method is called.
			$enabled    = isset( $_POST[ 'psbdx_captcha_' . $provider . '_enabled' ] ) ? '1' : '0';
			$site_key   = isset( $_POST[ 'psbdx_captcha_' . $provider . '_site_key' ] )
				? sanitize_text_field( wp_unslash( $_POST[ 'psbdx_captcha_' . $provider . '_site_key' ] ) )
				: '';
			$secret_key = isset( $_POST[ 'psbdx_captcha_' . $provider . '_secret_key' ] )
				? sanitize_text_field( wp_unslash( $_POST[ 'psbdx_captcha_' . $provider . '_secret_key' ] ) )
				: '';
			// phpcs:enable WordPress.Security.NonceVerification.Missing

			// If enabling, ensure keys are provided.
			if ( '1' === $enabled && ( '' === $site_key || '' === $secret_key ) ) {
				$enabled = '0';
			}

			// Only one provider can be active at a time; disable others when enabling one.
			if ( '1' === $enabled ) {
				foreach ( PSBDX_SRM_Captcha::providers() as $other ) {
					if ( $other !== $provider ) {
						PSBDX_SRM_Captcha::update_opt( $other, 'enabled', '0' );
					}
				}
			}

			PSBDX_SRM_Captcha::update_opt( $provider, 'enabled', $enabled );

			if ( '' !== $site_key ) {
				PSBDX_SRM_Captcha::update_opt( $provider, 'site_key', $site_key );
			}

			if ( '' !== $secret_key ) {
				PSBDX_SRM_Captcha::update_opt( $provider, 'secret_key', $secret_key );
			}
		}

		add_settings_error(
			'psbdx_srm_settings',
			'captcha_saved',
			__( 'Captcha settings saved.', 'psbdx-smart-report-management' ),
			'success'
		);
	}

	/**
	 * Render the captcha settings tab.
	 *
	 * @since 1.2.0
	 * @return void
	 */
	private function render_captcha_settings_tab() {
		$providers = array(
			'recaptcha' => array(
				'label'     => 'Google reCAPTCHA',
				'doc_url'   => 'https://dev.psbdx.xyz/how-to-enable-recaptcha-on-psrm/',
				'site_ph'   => '6Lcxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx',
				'secret_ph' => '6Lcxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx',
				'theme'     => '#4285F4',
				'icon'      => 'dashicons-shield-alt',
			),
			'hcaptcha'  => array(
				'label'     => 'hCaptcha',
				'doc_url'   => 'https://dev.psbdx.xyz/how-to-enable-hcaptcha-on-psrm/',
				'site_ph'   => '10000000-ffff-ffff-ffff-000000000001',
				'secret_ph' => '0x0000000000000000000000000000000000000000',
				'theme'     => '#00C179',
				'icon'      => 'dashicons-lock',
			),
			'turnstile' => array(
				'label'     => 'Cloudflare Turnstile',
				'doc_url'   => 'https://dev.psbdx.xyz/how-to-enable-turnstile-on-psrm/',
				'site_ph'   => '0x4AAAAAAA_xxxxxxxxxxxxxxxxx',
				'secret_ph' => '0x4AAAAAAA_xxxxxxxxxxxxxxxxxxxxxxxxxx',
				'theme'     => '#F6821F',
				'icon'      => 'dashicons-cloud',
			),
		);

		$nonce_test = wp_create_nonce( 'psbdx_srm_test_captcha' );
		?>
		<p class="description">
			<?php esc_html_e( 'Enable one captcha provider for your report forms. Only one provider can be active at a time. Before saving, use the "Test Credentials" button to verify your keys work correctly.', 'psbdx-smart-report-management' ); ?>
		</p>

		<form method="post" action="" style="max-width:920px;" id="psbdx-captcha-settings-form">
			<?php wp_nonce_field( 'psbdx_srm_captcha_settings' ); ?>

			<?php foreach ( $providers as $slug => $info ) :
				$is_enabled  = PSBDX_SRM_Captcha::is_enabled( $slug );
				$site_key    = PSBDX_SRM_Captcha::get_opt( $slug, 'site_key' );
				$secret_key  = PSBDX_SRM_Captcha::get_opt( $slug, 'secret_key' );
				$card_id     = 'psbdx-captcha-card-' . $slug;
				$toggle_id   = 'psbdx_captcha_' . $slug . '_enabled';
			?>
			<div class="psbdx-captcha-card <?php echo $is_enabled ? 'psbdx-captcha-card--active' : ''; ?>"
				id="<?php echo esc_attr( $card_id ); ?>"
				style="border-left-color:<?php echo esc_attr( $info['theme'] ); ?>;">

				<div class="psbdx-captcha-card-header">
					<div class="psbdx-captcha-card-title">
						<span class="dashicons <?php echo esc_attr( $info['icon'] ); ?>"
							style="color:<?php echo esc_attr( $info['theme'] ); ?>;" aria-hidden="true"></span>
						<strong><?php echo esc_html( $info['label'] ); ?></strong>
					</div>
					<div class="psbdx-captcha-card-controls">
						<a href="<?php echo esc_url( $info['doc_url'] ); ?>" target="_blank" rel="noopener noreferrer"
							class="psbdx-captcha-doc-link">
							<?php esc_html_e( 'Documentation', 'psbdx-smart-report-management' ); ?>
							<span class="dashicons dashicons-external" aria-hidden="true"></span>
						</a>
						<label class="psbdx-toggle" title="<?php /* translators: %s: captcha provider name */ echo esc_attr( sprintf( __( 'Enable %s', 'psbdx-smart-report-management' ), $info['label'] ) ); ?>">
							<input type="checkbox"
								name="<?php echo esc_attr( 'psbdx_captcha_' . $slug . '_enabled' ); ?>"
								id="<?php echo esc_attr( $toggle_id ); ?>"
								value="1"
								class="psbdx-captcha-provider-toggle"
								data-card="<?php echo esc_attr( $card_id ); ?>"
								data-provider="<?php echo esc_attr( $slug ); ?>"
								<?php checked( $is_enabled ); ?>>
							<span class="psbdx-toggle-slider"></span>
						</label>
					</div>
				</div>

				<div class="psbdx-captcha-card-body"
					style="<?php echo $is_enabled ? '' : 'display:none;'; ?>">

					<div class="psbdx-captcha-fields">
						<div class="psbdx-captcha-field-row">
							<label for="psbdx_captcha_<?php echo esc_attr( $slug ); ?>_site_key">
								<strong><?php esc_html_e( 'Site Key', 'psbdx-smart-report-management' ); ?></strong>
							</label>
							<input type="text"
								id="psbdx_captcha_<?php echo esc_attr( $slug ); ?>_site_key"
								name="psbdx_captcha_<?php echo esc_attr( $slug ); ?>_site_key"
								class="regular-text psbdx-captcha-site-key"
								value="<?php echo esc_attr( $site_key ); ?>"
								placeholder="<?php echo esc_attr( $info['site_ph'] ); ?>"
								autocomplete="off">
						</div>

						<div class="psbdx-captcha-field-row">
							<label for="psbdx_captcha_<?php echo esc_attr( $slug ); ?>_secret_key">
								<strong><?php esc_html_e( 'Secret Key', 'psbdx-smart-report-management' ); ?></strong>
							</label>
							<div class="psbdx-captcha-secret-wrap">
								<input type="password"
									id="psbdx_captcha_<?php echo esc_attr( $slug ); ?>_secret_key"
									name="psbdx_captcha_<?php echo esc_attr( $slug ); ?>_secret_key"
									class="regular-text psbdx-captcha-secret-key"
									value="<?php echo esc_attr( $secret_key ); ?>"
									placeholder="<?php echo esc_attr( $info['secret_ph'] ); ?>"
									autocomplete="off">
								<button type="button" class="button psbdx-toggle-secret"
									aria-label="<?php esc_attr_e( 'Show/hide secret key', 'psbdx-smart-report-management' ); ?>">
									<span class="dashicons dashicons-visibility" aria-hidden="true"></span>
								</button>
							</div>
						</div>
					</div>

					<div class="psbdx-captcha-test-row">
						<button type="button"
							class="button psbdx-test-captcha-btn"
							data-provider="<?php echo esc_attr( $slug ); ?>"
							data-nonce="<?php echo esc_attr( $nonce_test ); ?>"
							data-card="<?php echo esc_attr( $card_id ); ?>">
							<?php esc_html_e( 'Test Credentials', 'psbdx-smart-report-management' ); ?>
						</button>
						<span class="psbdx-captcha-test-result" id="psbdx-test-result-<?php echo esc_attr( $slug ); ?>"></span>
					</div>

				</div><!-- .psbdx-captcha-card-body -->
			</div><!-- .psbdx-captcha-card -->
			<?php endforeach; ?>

			<p style="margin-top:20px;">
				<button type="submit" name="psbdx_srm_save_captcha" class="button button-primary" value="1">
					<?php esc_html_e( 'Save Captcha Settings', 'psbdx-smart-report-management' ); ?>
				</button>
			</p>
		</form>

		<script>
		(function () {
			// Toggle card body when the enable switch is flipped.
			document.querySelectorAll('.psbdx-captcha-provider-toggle').forEach(function (toggle) {
				toggle.addEventListener('change', function () {
					var card = document.getElementById(toggle.getAttribute('data-card'));
					var body = card ? card.querySelector('.psbdx-captcha-card-body') : null;
					if (!body) return;

					// Disable other toggles (one at a time).
					if (toggle.checked) {
						document.querySelectorAll('.psbdx-captcha-provider-toggle').forEach(function (t) {
							if (t !== toggle && t.checked) {
								t.checked = false;
								t.dispatchEvent(new Event('change'));
							}
						});
					}

					body.style.display = toggle.checked ? '' : 'none';
					card.classList.toggle('psbdx-captcha-card--active', toggle.checked);
				});
			});

			// Show/hide secret key.
			document.querySelectorAll('.psbdx-toggle-secret').forEach(function (btn) {
				btn.addEventListener('click', function () {
					var input = btn.previousElementSibling;
					var icon  = btn.querySelector('.dashicons');
					if (!input) return;
					if ('password' === input.type) {
						input.type = 'text';
						if (icon) { icon.classList.replace('dashicons-visibility', 'dashicons-hidden'); }
					} else {
						input.type = 'password';
						if (icon) { icon.classList.replace('dashicons-hidden', 'dashicons-visibility'); }
					}
				});
			});

			// Test credentials via AJAX.
			document.querySelectorAll('.psbdx-test-captcha-btn').forEach(function (btn) {
				btn.addEventListener('click', function () {
					var card     = document.getElementById(btn.getAttribute('data-card'));
					var provider = btn.getAttribute('data-provider');
					var nonce    = btn.getAttribute('data-nonce');
					var result   = document.getElementById('psbdx-test-result-' + provider);
					var siteKey  = card ? card.querySelector('.psbdx-captcha-site-key')   : null;
					var secretKey= card ? card.querySelector('.psbdx-captcha-secret-key') : null;

					if (!siteKey || !secretKey || !result) return;

					btn.disabled     = true;
					result.className = 'psbdx-captcha-test-result psbdx-captcha-test-pending';
					result.textContent = '<?php echo esc_js( __( 'Testing\u2026', 'psbdx-smart-report-management' ) ); ?>';

					var body = new URLSearchParams();
					body.append('action',     'psbdx_srm_test_captcha_creds');
					body.append('security',   nonce);
					body.append('provider',   provider);
					body.append('site_key',   siteKey.value);
					body.append('secret_key', secretKey.value);

					fetch(ajaxurl, { method: 'POST', body: body, credentials: 'same-origin' })
						.then(function (r) { return r.json(); })
						.then(function (data) {
							btn.disabled = false;
							if (data.success) {
								result.className = 'psbdx-captcha-test-result psbdx-captcha-test-ok';
								result.textContent = '\u2713 ' + data.data;
							} else {
								result.className = 'psbdx-captcha-test-result psbdx-captcha-test-fail';
								result.textContent = '\u2717 ' + (data.data || '<?php echo esc_js( __( 'Verification failed.', 'psbdx-smart-report-management' ) ); ?>');
							}
						})
						.catch(function () {
							btn.disabled = false;
							result.className = 'psbdx-captcha-test-result psbdx-captcha-test-fail';
							result.textContent = '<?php echo esc_js( __( 'Network error.', 'psbdx-smart-report-management' ) ); ?>';
						});
				});
			});
		}());
		</script>
		<?php
	}
}
