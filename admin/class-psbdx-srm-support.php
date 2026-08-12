<?php
/**
 * Support page for PSBDx Smart Report Management.
 *
 * Provides a form that sends a support ticket — including optional system
 * information — to support@dev.psbdx.xyz via wp_mail().
 *
 * @package PSBDx_Smart_Report_Management
 * @since   1.3.1
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class PSBDX_SRM_Support
 *
 * Registers the Support submenu page and handles form submission.
 *
 * @since 1.3.1
 */
class PSBDX_SRM_Support {

	/**
	 * Submenu slug.
	 *
	 * @since 1.3.1
	 * @var string
	 */
	const PAGE_SUPPORT = 'psbdx-srm-support';

	/**
	 * Support destination email.
	 *
	 * @since 1.3.1
	 * @var string
	 */
	const SUPPORT_EMAIL = 'support@dev.psbdx.xyz';

	/**
	 * Nonce action.
	 *
	 * @since 1.3.1
	 * @var string
	 */
	const NONCE_ACTION = 'psbdx_srm_support_submit';

	/**
	 * Maximum attachment size in bytes (5 MB).
	 *
	 * @since 1.3.1
	 * @var int
	 */
	const MAX_ATTACHMENT_SIZE = 5242880;

	/**
	 * Allowed attachment MIME types.
	 *
	 * @since 1.3.1
	 * @var string[]
	 */
	const ALLOWED_MIME_TYPES = array(
		'image/jpeg',
		'image/png',
		'image/gif',
		'image/webp',
		'application/pdf',
		'text/plain',
		'application/zip',
		'application/x-zip-compressed',
	);

	/**
	 * Constructor.
	 *
	 * @since 1.3.1
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register_page' ), 110 );
		add_action( 'admin_init', array( $this, 'handle_submit' ) );
	}

	/**
	 * Register the Support submenu page.
	 *
	 * @since  1.3.1
	 * @return void
	 */
	public function register_page() {
		add_submenu_page(
			PSBDX_SRM_Post_Types::ADMIN_MENU_SLUG,
			__( 'Support', 'psbdx-smart-report-management' ),
			__( 'Support', 'psbdx-smart-report-management' ),
			'manage_options',
			self::PAGE_SUPPORT,
			array( $this, 'render_page' )
		);
	}

	// =========================================================================
	// FORM SUBMISSION
	// =========================================================================

	/**
	 * Handle the support form POST.
	 *
	 * @since  1.3.1
	 * @return void
	 */
	public function handle_submit() {
		if ( ! isset( $_POST['psbdx_srm_support_submit'] ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		check_admin_referer( self::NONCE_ACTION );

		$name        = isset( $_POST['psrm_support_name'] )        ? sanitize_text_field( wp_unslash( $_POST['psrm_support_name'] ) )        : '';
		$email       = isset( $_POST['psrm_support_email'] )       ? sanitize_email( wp_unslash( $_POST['psrm_support_email'] ) )             : '';
		$description = isset( $_POST['psrm_support_description'] ) ? sanitize_textarea_field( wp_unslash( $_POST['psrm_support_description'] ) ) : '';
		$include_info = isset( $_POST['psrm_support_include_info'] ) && '1' === $_POST['psrm_support_include_info'];

		if ( '' === $name || '' === $email || '' === $description ) {
			add_settings_error(
				'psbdx_srm_support',
				'missing_fields',
				__( 'Please fill in all required fields (Name, Email, Description).', 'psbdx-smart-report-management' ),
				'error'
			);
			return;
		}

		if ( ! is_email( $email ) ) {
			add_settings_error(
				'psbdx_srm_support',
				'invalid_email',
				__( 'Please enter a valid contact email address.', 'psbdx-smart-report-management' ),
				'error'
			);
			return;
		}

		// Build message body — a nicely formatted HTML email instead of a
		// bare plain-text dump, so the request is easy to scan at a glance.
		$body = $this->build_email_html( $name, $email, $description, $include_info );

		// Handle optional file attachments (up to 3 files).
		$attachments = array();
		$temp_files  = array();

		if ( ! empty( $_FILES['psrm_support_attachments']['name'][0] ) ) {
			if ( ! function_exists( 'wp_handle_upload' ) ) {
				require_once ABSPATH . 'wp-admin/includes/file.php';
			}

			$files = $_FILES['psrm_support_attachments']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- validated field-by-field below.

			$count = min( 3, count( $files['name'] ) );

			for ( $i = 0; $i < $count; $i++ ) {
				if ( UPLOAD_ERR_OK !== (int) $files['error'][ $i ] ) {
					continue;
				}

				$size = (int) $files['size'][ $i ];
				if ( $size > self::MAX_ATTACHMENT_SIZE || $size <= 0 ) {
					continue;
				}

				$tmp  = $files['tmp_name'][ $i ];
				$mime = mime_content_type( $tmp );

				if ( ! in_array( $mime, self::ALLOWED_MIME_TYPES, true ) ) {
					continue;
				}

				$single_file = array(
					'name'     => $files['name'][ $i ],
					'type'     => $mime,
					'tmp_name' => $tmp,
					'error'    => $files['error'][ $i ],
					'size'     => $files['size'][ $i ],
				);

				// wp_handle_upload() is WordPress's sanctioned way to accept a
				// submitted file: it re-validates the extension/mime against
				// core's allow-list, sanitizes the filename, guarantees a
				// unique destination, and moves it into place. Calling
				// move_uploaded_file() directly from plugin code is flagged
				// by the WordPress Plugin Check tool (and discouraged in
				// general) because it bypasses all of that.
				$moved = wp_handle_upload(
					$single_file,
					array(
						// We've already verified the nonce ourselves in
						// handle_submit(); this isn't the standard media
						// upload form, so there's no matching 'action' to check.
						'test_form' => false,
					)
				);

				if ( ! empty( $moved['file'] ) && empty( $moved['error'] ) ) {
					$attachments[] = $moved['file'];
					$temp_files[]  = $moved['file'];
				}
			}
		}

		// Build headers.
		$from_email = get_option( 'admin_email' );
		$from_name  = get_option( 'blogname' );

		$headers = array(
			'Content-Type: text/html; charset=UTF-8',
			'From: ' . sanitize_text_field( $from_name ) . ' <' . sanitize_email( $from_email ) . '>',
			'Reply-To: ' . sanitize_text_field( $name ) . ' <' . sanitize_email( $email ) . '>',
		);

		$subject = sprintf(
			/* translators: %s: site URL */
			__( '[PSRM Support] Request from %s', 'psbdx-smart-report-management' ),
			esc_url( home_url() )
		);

		$sent = wp_mail(
			self::SUPPORT_EMAIL,
			$subject,
			$body,
			$headers,
			$attachments
		);

		// Clean up temp files.
		foreach ( $temp_files as $file ) {
			if ( file_exists( $file ) ) {
				wp_delete_file( $file );
			}
		}

		if ( $sent ) {
			add_settings_error(
				'psbdx_srm_support',
				'sent',
				__( 'Your support request has been sent successfully. We will get back to you at the email address you provided.', 'psbdx-smart-report-management' ),
				'success'
			);
		} else {
			add_settings_error(
				'psbdx_srm_support',
				'failed',
				__( 'The support request could not be sent. Please check that your site email is configured correctly, or contact support@dev.psbdx.xyz directly.', 'psbdx-smart-report-management' ),
				'error'
			);
		}
	}

	// =========================================================================
	// RENDER PAGE
	// =========================================================================

	/**
	 * Render the Support admin page.
	 *
	 * @since  1.3.1
	 * @return void
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		settings_errors( 'psbdx_srm_support' );

		?>
		<div class="wrap psbdx-srm-tools">
			<h1>
				<span class="dashicons dashicons-sos" aria-hidden="true" style="vertical-align:middle;margin-right:6px;"></span>
				<?php esc_html_e( 'Support', 'psbdx-smart-report-management' ); ?>
			</h1>

			<?php $this->render_contact_tab(); ?>
		</div><!-- .wrap -->
		<?php
	}

	/**
	 * Render the support request form.
	 *
	 * @since  1.4.1
	 * @return void
	 */
	private function render_contact_tab() {
		$current_user  = wp_get_current_user();
		$default_name  = $current_user->display_name;
		$default_email = $current_user->user_email;
		?>
			<p class="description">
				<?php esc_html_e( 'Having an issue or a question? Fill in the form below and our team will get back to you as soon as possible.', 'psbdx-smart-report-management' ); ?>
			</p>

			<div class="psbdx-support-layout">

				<?php /* Main support form */ ?>
				<div>
					<form method="post" enctype="multipart/form-data" style="max-width:680px;">
						<?php wp_nonce_field( self::NONCE_ACTION ); ?>

						<table class="form-table" style="margin-top:0;">
							<tr>
								<th scope="row">
									<label for="psrm_support_name">
										<?php esc_html_e( 'Your Name', 'psbdx-smart-report-management' ); ?>
										<span aria-hidden="true" style="color:#b91c1c;">*</span>
									</label>
								</th>
								<td>
									<input type="text"
										id="psrm_support_name"
										name="psrm_support_name"
										class="regular-text"
										value="<?php echo esc_attr( $default_name ); ?>"
										required>
								</td>
							</tr>
							<tr>
								<th scope="row">
									<label for="psrm_support_email">
										<?php esc_html_e( 'Contact Email', 'psbdx-smart-report-management' ); ?>
										<span aria-hidden="true" style="color:#b91c1c;">*</span>
									</label>
								</th>
								<td>
									<input type="email"
										id="psrm_support_email"
										name="psrm_support_email"
										class="regular-text"
										value="<?php echo esc_attr( $default_email ); ?>"
										required>
									<p class="description"><?php esc_html_e( 'We will reply to this address.', 'psbdx-smart-report-management' ); ?></p>
								</td>
							</tr>
							<tr>
								<th scope="row">
									<label for="psrm_support_description">
										<?php esc_html_e( 'Description', 'psbdx-smart-report-management' ); ?>
										<span aria-hidden="true" style="color:#b91c1c;">*</span>
									</label>
								</th>
								<td>
									<textarea id="psrm_support_description"
										name="psrm_support_description"
										class="large-text"
										rows="8"
										required
										placeholder="<?php esc_attr_e( 'Describe your issue in as much detail as possible. What did you expect to happen? What actually happened?', 'psbdx-smart-report-management' ); ?>"></textarea>
								</td>
							</tr>
							<tr>
								<th scope="row">
									<?php esc_html_e( 'Attachments', 'psbdx-smart-report-management' ); ?>
								</th>
								<td>
									<input type="file"
										id="psrm_support_attachments"
										name="psrm_support_attachments[]"
										multiple
										accept=".jpg,.jpeg,.png,.gif,.webp,.pdf,.txt,.zip">
									<p class="description">
										<?php esc_html_e( 'Optional. Accepted: JPG, PNG, GIF, WebP, PDF, TXT, ZIP. Max 5 MB per file, up to 3 files.', 'psbdx-smart-report-management' ); ?>
									</p>
								</td>
							</tr>
							<tr>
								<th scope="row">
									<?php esc_html_e( 'System Information', 'psbdx-smart-report-management' ); ?>
								</th>
								<td>
									<label>
										<input type="checkbox"
											id="psrm_support_include_info"
											name="psrm_support_include_info"
											value="1"
											checked>
										<?php esc_html_e( 'Include system information with this request', 'psbdx-smart-report-management' ); ?>
									</label>
									<p class="description">
										<?php esc_html_e( 'Sends your WordPress and PHP versions, active and inactive plugins, and active theme. This helps our team diagnose issues faster.', 'psbdx-smart-report-management' ); ?>
										<a href="#" id="psrm-preview-info-toggle" style="margin-left:4px;"><?php esc_html_e( 'Preview what will be sent', 'psbdx-smart-report-management' ); ?> &#9660;</a>
									</p>

									<div id="psrm-info-preview" style="display:none;margin-top:10px;">
										<pre style="background:#f8f8f8;border:1px solid #ddd;padding:12px;max-height:220px;overflow:auto;font-size:12px;white-space:pre-wrap;border-radius:4px;"><?php echo esc_html( $this->build_system_info() ); ?></pre>
									</div>
								</td>
							</tr>
						</table>

						<p style="margin-top:16px;">
							<button type="submit" name="psbdx_srm_support_submit" class="button button-primary" value="1">
								<span class="dashicons dashicons-email-alt" aria-hidden="true" style="vertical-align:middle;margin-right:4px;"></span>
								<?php esc_html_e( 'Send Support Request', 'psbdx-smart-report-management' ); ?>
							</button>
						</p>
					</form>

					<script>
					(function () {
						var toggle = document.getElementById('psrm-preview-info-toggle');
						var panel  = document.getElementById('psrm-info-preview');
						if (!toggle || !panel) return;
						toggle.addEventListener('click', function (e) {
							e.preventDefault();
							var open = panel.style.display !== 'none';
							panel.style.display = open ? 'none' : '';
							toggle.innerHTML = open
								? '<?php echo esc_js( __( 'Preview what will be sent', 'psbdx-smart-report-management' ) ); ?> &#9660;'
								: '<?php echo esc_js( __( 'Hide preview', 'psbdx-smart-report-management' ) ); ?> &#9650;';
						});
					}());
					</script>
				</div>

				<?php /* Info sidebar */ ?>
				<div>
					<div style="background:#f0f7fb;border:1px solid #bae6fd;border-radius:8px;padding:18px 20px;">
						<h3 style="margin-top:0;display:flex;align-items:center;gap:8px;">
							<span class="dashicons dashicons-info-outline" aria-hidden="true" style="color:#0369a1;"></span>
							<?php esc_html_e( 'Before you write', 'psbdx-smart-report-management' ); ?>
						</h3>
						<ul style="padding-left:1.2em;margin:0;">
							<li style="margin-bottom:8px;">
								<a href="https://dev.psbdx.xyz/documentations/psbdx-smart-report-managment/" target="_blank" rel="noopener noreferrer">
									<?php esc_html_e( 'Read the documentation', 'psbdx-smart-report-management' ); ?>
									<span class="dashicons dashicons-external" aria-hidden="true" style="font-size:14px;vertical-align:middle;"></span>
								</a>
							</li>
							<li style="margin-bottom:8px;">
								<a href="<?php echo esc_url( admin_url( 'admin.php?page=psbdx-srm-repair' ) ); ?>">
									<?php esc_html_e( 'Run the diagnostic scan', 'psbdx-smart-report-management' ); ?>
								</a>
								<?php esc_html_e( ' — it can pinpoint many common issues automatically.', 'psbdx-smart-report-management' ); ?>
							</li>
							<li>
								<?php esc_html_e( 'Include the system information checkbox — it helps us help you faster.', 'psbdx-smart-report-management' ); ?>
							</li>
						</ul>

						<hr style="border:none;border-top:1px solid #bae6fd;margin:16px 0;">
						<p style="margin:0;font-size:12px;color:#64748b;">
							<?php
							printf(
								/* translators: %s: support email */
								esc_html__( 'You can also email us directly at %s', 'psbdx-smart-report-management' ),
								'<a href="mailto:' . esc_attr( self::SUPPORT_EMAIL ) . '">' . esc_html( self::SUPPORT_EMAIL ) . '</a>'
							);
							?>
						</p>
					</div>
				</div>

			</div><!-- .psbdx-support-layout -->
		<?php
	}

	// =========================================================================
	// EMAIL FORMATTING
	// =========================================================================

	/**
	 * Builds the HTML support request email.
	 *
	 * Inline-styled (email clients don't reliably load external/`<style>`
	 * CSS) but kept simple: a header band, a details table, the reporter's
	 * description in its own card, and — when requested — the system
	 * information in a monospace block.
	 *
	 * @since  1.4.1
	 * @param  string $name          Reporter's name.
	 * @param  string $email         Reporter's email.
	 * @param  string $description   Issue description (plain text; line breaks preserved).
	 * @param  bool   $include_info  Whether to append system information.
	 * @return string  Full HTML email body.
	 */
	private function build_email_html( $name, $email, $description, $include_info ) {
		$site_name = get_option( 'blogname' );
		$site_url  = home_url();

		ob_start();
		?>
		<!DOCTYPE html>
		<html>
		<body style="margin:0;padding:0;background:#f1f5f9;font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;">
			<div style="max-width:600px;margin:0 auto;padding:24px 16px;">

				<div style="background:#1e293b;border-radius:10px 10px 0 0;padding:20px 24px;">
					<p style="margin:0;color:#94a3b8;font-size:12px;letter-spacing:0.5px;text-transform:uppercase;">
						<?php esc_html_e( 'PSBDx Smart Report Management', 'psbdx-smart-report-management' ); ?>
					</p>
					<h1 style="margin:4px 0 0;color:#fff;font-size:20px;">
						<?php esc_html_e( 'New Support Request', 'psbdx-smart-report-management' ); ?>
					</h1>
				</div>

				<div style="background:#ffffff;border:1px solid #e2e8f0;border-top:none;padding:24px;">

					<table role="presentation" style="width:100%;border-collapse:collapse;margin-bottom:20px;">
						<tr>
							<td style="padding:4px 0;color:#64748b;font-size:13px;width:90px;"><?php esc_html_e( 'From', 'psbdx-smart-report-management' ); ?></td>
							<td style="padding:4px 0;color:#1e293b;font-size:13px;font-weight:600;"><?php echo esc_html( $name ); ?> &lt;<?php echo esc_html( $email ); ?>&gt;</td>
						</tr>
						<tr>
							<td style="padding:4px 0;color:#64748b;font-size:13px;"><?php esc_html_e( 'Site', 'psbdx-smart-report-management' ); ?></td>
							<td style="padding:4px 0;color:#1e293b;font-size:13px;">
								<a href="<?php echo esc_url( $site_url ); ?>" style="color:#4f46e5;text-decoration:none;"><?php echo esc_html( $site_name . ' (' . $site_url . ')' ); ?></a>
							</td>
						</tr>
						<tr>
							<td style="padding:4px 0;color:#64748b;font-size:13px;"><?php esc_html_e( 'Plugin Version', 'psbdx-smart-report-management' ); ?></td>
							<td style="padding:4px 0;color:#1e293b;font-size:13px;"><?php echo esc_html( PSBDX_SRM_VERSION ); ?></td>
						</tr>
					</table>

					<h2 style="margin:0 0 8px;font-size:13px;text-transform:uppercase;letter-spacing:0.5px;color:#64748b;">
						<?php esc_html_e( 'Description', 'psbdx-smart-report-management' ); ?>
					</h2>
					<div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:14px 16px;color:#1e293b;font-size:14px;line-height:1.6;white-space:pre-wrap;">
						<?php echo wp_kses_post( nl2br( esc_html( $description ) ) ); ?>
					</div>

					<?php if ( $include_info ) : ?>
					<h2 style="margin:24px 0 8px;font-size:13px;text-transform:uppercase;letter-spacing:0.5px;color:#64748b;">
						<?php esc_html_e( 'System Information', 'psbdx-smart-report-management' ); ?>
					</h2>
					<pre style="background:#0f172a;color:#e2e8f0;border-radius:8px;padding:14px 16px;font-size:12px;line-height:1.6;overflow-x:auto;white-space:pre-wrap;"><?php echo esc_html( $this->build_system_info() ); ?></pre>
					<?php endif; ?>

				</div>

				<p style="text-align:center;color:#94a3b8;font-size:11px;margin-top:16px;">
					<?php esc_html_e( 'Sent via the Support form in PSBDx Smart Report Management.', 'psbdx-smart-report-management' ); ?>
				</p>

			</div>
		</body>
		</html>
		<?php
		return (string) ob_get_clean();
	}

	// =========================================================================
	// SYSTEM INFORMATION
	// =========================================================================

	/**
	 * Build a plain-text system information report.
	 *
	 * Includes: WordPress version, PHP version, active plugins, inactive plugins,
	 * active theme, and this plugin's version.
	 *
	 * @since  1.3.1
	 * @return string
	 */
	private function build_system_info() {
		global $wp_version;

		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$info  = "=== System Information ===\n\n";
		$info .= 'Site URL:            ' . home_url() . "\n";
		$info .= 'WordPress Version:   ' . $wp_version . "\n";
		$info .= 'PHP Version:         ' . PHP_VERSION . "\n";
		$info .= 'PSRM Version:        ' . PSBDX_SRM_VERSION . "\n\n";

		// Active plugins.
		$active_plugins  = (array) get_option( 'active_plugins', array() );
		$all_plugins     = get_plugins();
		$info .= "--- Active Plugins ---\n";

		foreach ( $active_plugins as $plugin_file ) {
			$plugin_file = (string) $plugin_file;
			$data        = isset( $all_plugins[ $plugin_file ] ) ? $all_plugins[ $plugin_file ] : array( 'Name' => $plugin_file, 'Version' => '' );
			$info       .= '  ' . sanitize_text_field( $data['Name'] ) . ' (' . sanitize_text_field( $data['Version'] ) . ")\n";
		}

		// Inactive plugins.
		$inactive = array_diff_key( $all_plugins, array_flip( $active_plugins ) );
		if ( $inactive ) {
			$info .= "\n--- Inactive Plugins ---\n";
			foreach ( $inactive as $plugin_file => $data ) {
				$info .= '  ' . sanitize_text_field( $data['Name'] ) . ' (' . sanitize_text_field( $data['Version'] ) . ")\n";
			}
		}

		// Active theme.
		$theme = wp_get_theme();
		$info .= "\n--- Active Theme ---\n";
		$info .= '  ' . sanitize_text_field( $theme->get( 'Name' ) ) . ' ' . sanitize_text_field( $theme->get( 'Version' ) );

		$parent = $theme->parent();
		if ( $parent ) {
			$info .= ' (child of: ' . sanitize_text_field( $parent->get( 'Name' ) ) . ')';
		}

		$info .= "\n";

		return $info;
	}
}
