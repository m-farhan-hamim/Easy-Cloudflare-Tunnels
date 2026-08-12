<?php
/**
 * Frontend shortcodes for PSBDx Smart Report Management.
 *
 * @package PSBDx_Smart_Report_Management
 * @since   1.0.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class PSBDX_SRM_Shortcodes
 *
 * Registers and renders the [psbdx_report], [psbdx_user_reports], and
 * [psbdx_faq] shortcodes.
 *
 * @since 1.0.0
 */
class PSBDX_SRM_Shortcodes {

	/**
	 * Build the current front-end request URL for report source tracking.
	 *
	 * @since 1.1.0
	 * @return string Full URL (scheme + host + path + query), or home URL as fallback.
	 */
	private static function get_current_page_url() {
		if ( isset( $_SERVER['HTTP_HOST'], $_SERVER['REQUEST_URI'] ) ) {
			$scheme = is_ssl() ? 'https' : 'http';
			$host   = sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) );
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- esc_url_raw() is applied before output; preg_replace strips header-injection chars.
		$path   = preg_replace( '#[\r\n\0]#', '', wp_unslash( $_SERVER['REQUEST_URI'] ) );

			if ( '' !== $host && '' !== $path ) {
				return esc_url_raw( $scheme . '://' . $host . $path );
			}
		}

		return home_url( '/' );
	}

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		add_shortcode( 'psbdx_report',       array( $this, 'render_report_button' ) );
		add_shortcode( 'psbdx_user_reports', array( $this, 'render_user_reports' ) );
		add_shortcode( 'psbdx_faq',          array( $this, 'render_faq' ) );
	}

	// =========================================================================
	// SHARED REPLY THREAD RENDERING
	// =========================================================================

	/**
	 * Renders the message list for a report's reply thread — used for the
	 * initial page render and re-used verbatim in the AJAX response after a
	 * new reply is posted, so the two never drift out of sync.
	 *
	 * @since  1.4.2
	 * @param  int  $report_id     Report log post ID.
	 * @param  bool $with_wrapper  Whether to include the outer container div
	 *                             (false when swapping just the inner list via AJAX).
	 * @param  bool $is_admin_view Whether this is being shown to an admin
	 *                             (labels the reporter's own messages "Customer"
	 *                             instead of "You").
	 * @return string  HTML.
	 */
	public static function render_thread_html( $report_id, $with_wrapper = true, $is_admin_view = false ) {
		$thread = PSBDX_SRM_Replies::get_thread( $report_id );

		ob_start();
		if ( $with_wrapper ) {
			echo '<div class="psbdx-thread" id="psbdx-thread-' . (int) $report_id . '" data-count="' . (int) count( $thread ) . '">';
		}

		if ( empty( $thread ) ) {
			echo '<p class="psbdx-thread-empty">' . esc_html__( 'No replies yet.', 'psbdx-smart-report-management' ) . '</p>';
		} else {
			foreach ( $thread as $msg ) {
				$role_class = 'psbdx-thread-msg-' . sanitize_html_class( $msg->author_type );
				$role_label = 'ai' === $msg->author_type
					? __( 'AI Assistant', 'psbdx-smart-report-management' )
					: ( 'admin' === $msg->author_type
						? __( 'Support', 'psbdx-smart-report-management' )
						: ( $is_admin_view ? __( 'Customer', 'psbdx-smart-report-management' ) : __( 'You', 'psbdx-smart-report-management' ) ) );
				$avatar_initial = 'ai' === $msg->author_type ? 'AI' : ( 'admin' === $msg->author_type ? 'S' : 'U' );

				$created_gmt = $msg->created_at;
				$local_time  = get_date_from_gmt( $created_gmt, 'M j, g:i a' );
				?>
				<div class="psbdx-thread-msg <?php echo esc_attr( $role_class ); ?>">
					<div class="psbdx-thread-msg-head">
						<span class="psbdx-thread-msg-avatar" aria-hidden="true"><?php echo esc_html( $avatar_initial ); ?></span>
						<span class="psbdx-thread-msg-author"><?php echo esc_html( $role_label ); ?></span>
						<span class="psbdx-thread-msg-time"><?php echo esc_html( $local_time ); ?></span>
					</div>
					<div class="psbdx-thread-msg-body"><?php echo wp_kses_post( wpautop( $msg->message ) ); ?></div>
					<?php
					$reply_attach_id = (int) ( $msg->attachment_id ?? 0 );
					if ( $reply_attach_id && get_post( $reply_attach_id ) ) :
						$attach_url = wp_get_attachment_url( $reply_attach_id );
						$image_src  = wp_get_attachment_image_src( $reply_attach_id, 'medium' );
						?>
						<div class="psbdx-thread-msg-attachment">
							<?php if ( $image_src ) : ?>
								<a href="<?php echo esc_url( $attach_url ); ?>" target="_blank" rel="noopener noreferrer">
									<img src="<?php echo esc_url( $image_src[0] ); ?>" alt="" class="psbdx-thread-msg-attachment-img">
								</a>
							<?php else : ?>
								<a href="<?php echo esc_url( $attach_url ); ?>" target="_blank" rel="noopener noreferrer" class="psbdx-thread-msg-attachment-file">
									<span class="dashicons dashicons-media-default" aria-hidden="true"></span>
									<?php echo esc_html( basename( get_attached_file( $reply_attach_id ) ) ); ?>
								</a>
							<?php endif; ?>
							<?php if ( $is_admin_view && class_exists( 'PSBDX_SRM_Agents' ) ) : ?>
								<button type="button" class="psbdx-thread-msg-attachment-delete psbdx-agent-delete-attachment-btn" data-attachment-id="<?php echo (int) $reply_attach_id; ?>" title="<?php esc_attr_e( 'Delete this attachment', 'psbdx-smart-report-management' ); ?>">
									<span class="dashicons dashicons-trash" aria-hidden="true"></span>
								</button>
							<?php endif; ?>
						</div>
					<?php elseif ( $reply_attach_id ) : // Had a file at the time, but it's since been deleted. ?>
						<div class="psbdx-thread-msg-attachment psbdx-thread-msg-attachment-deleted">
							<span class="dashicons dashicons-trash" aria-hidden="true"></span>
							<?php esc_html_e( 'Deleted Attachment', 'psbdx-smart-report-management' ); ?>
						</div>
					<?php endif; ?>
				</div>
				<?php
			}
		}

		if ( $with_wrapper ) {
			echo '</div>';
		}

		return ob_get_clean();
	}

	// =========================================================================
	// [psbdx_report id="X"]
	// =========================================================================

	/**
	 * Render the report button + modal form shortcode.
	 *
	 * @since  1.0.0
	 * @param  array $atts  Shortcode attributes.
	 * @return string       HTML output.
	 */
	public function render_report_button( $atts ) {
		$atts    = shortcode_atts( array( 'id' => 0, 'mode' => 'popup' ), $atts, 'psbdx_report' );
		$form_id = absint( $atts['id'] );
		$mode    = in_array( $atts['mode'], array( 'popup', 'inline' ), true ) ? $atts['mode'] : 'popup';

		if ( ! $form_id || 'psbdx_report_form' !== get_post_type( $form_id ) ) {
			return '';
		}

		return self::render_form_instance( $form_id, $mode );
	}

	/**
	 * Renders a single form instance in one of three modes. Shared by:
	 * - [psbdx_report id="X"] (mode=popup, the default) — a trigger button
	 *   plus a hidden modal that opens on click.
	 * - [psbdx_report id="X" mode="inline"] — the form embedded directly
	 *   into the page flow, always visible, no button/modal chrome.
	 * - PSBDX_SRM_Ajax::handle_get_popup_form() (mode=popup_only) — just the
	 *   modal markup with no trigger button, injected into the page by
	 *   public.js and opened immediately when a URL-triggered popup link
	 *   is detected (see maybeOpenUrlPopup() in public.js).
	 *
	 * @since  1.4.5
	 * @param  int    $form_id  Report form post ID.
	 * @param  string $mode     'popup' | 'inline' | 'popup_only'.
	 * @return string           HTML output, or '' if the form doesn't exist.
	 */
	public static function render_form_instance( $form_id, $mode = 'popup' ) {
		global $wp;

		if ( ! $form_id || 'psbdx_report_form' !== get_post_type( $form_id ) ) {
			return '';
		}

		// Form settings.
		$btn_text      = get_post_meta( $form_id, '_psbdx_btn_text',        true ) ?: __( 'Report Issue', 'psbdx-smart-report-management' );
		$contact_label = get_post_meta( $form_id, '_psbdx_contact_label',   true ) ?: __( 'WhatsApp Number', 'psbdx-smart-report-management' );
		$contact_req   = ( 'yes' === get_post_meta( $form_id, '_psbdx_contact_required', true ) );
		$show_identity = get_post_meta( $form_id, '_psbdx_show_identity',   true );
		$show_identity = ( '' === $show_identity ) ? 'yes' : $show_identity;
		$cooldown_mins = PSBDX_SRM_Helpers::get_effective_cooldown_mins( $form_id );

		$reasons       = PSBDX_SRM_Helpers::get_form_reasons( $form_id );
		$custom_fields = PSBDX_SRM_Helpers::get_custom_fields( $form_id );

		// Current page context (full URL including path and query; works with subfolder installs).
		$current_url = self::get_current_page_url();
		$woo_order_id = 0;

		if ( function_exists( 'is_account_page' ) && is_account_page() && isset( $wp->query_vars['view-order'] ) ) {
			$woo_order_id  = absint( $wp->query_vars['view-order'] );
			$current_title = sprintf(
				/* translators: %d: order number */
				__( 'Order #%d', 'psbdx-smart-report-management' ),
				$woo_order_id
			);
		} else {
			$current_title = get_the_title();
		}

		// Current user.
		$user         = wp_get_current_user();
		$is_logged_in = ( $user->ID > 0 );
		$user_name    = $is_logged_in ? $user->display_name : '';
		$user_email   = $is_logged_in ? $user->user_email   : '';

		// Rate limit check (display-only; server also enforces).
		$cooldown_msg = '';
		if ( $cooldown_mins > 0 && $is_logged_in ) {
			$transient = get_transient( 'psbdx_cd_' . $user->ID . '_' . $form_id );

			if ( false !== $transient ) {
				$remaining    = (int) ceil( ( $transient + $cooldown_mins * 60 - time() ) / 60 );
				$cooldown_msg = sprintf(
					/* translators: %d: minutes remaining */
					__( 'You already submitted a report recently. Please wait %d more minute(s) before trying again.', 'psbdx-smart-report-management' ),
					max( 1, $remaining )
				);
			}
		}

		$uid   = $form_id . '-' . wp_rand( 1000, 9999 );
		$nonce = wp_create_nonce( 'psbdx_srm_submit_nonce' );

		// Captcha config for this form.
		$captcha_on_form = ( 'yes' === get_post_meta( $form_id, '_psbdx_captcha_enabled', true ) );
		$captcha_provider = PSBDX_SRM_Captcha::active_provider();
		$captcha_active  = $captcha_on_form && '' !== $captcha_provider;
		$captcha_site_key = $captcha_active ? PSBDX_SRM_Captcha::get_opt( $captcha_provider, 'site_key' ) : '';

		// Detect v2 builder forms.
		$form_version = (int) get_post_meta( $form_id, PSBDX_SRM_Form_Builder::VERSION_META_KEY, true );
		$is_v2_form   = ( $form_version >= PSBDX_SRM_Form_Builder::SCHEMA_VERSION );

		$is_popup = ( 'inline' !== $mode );

		ob_start();

		if ( 'popup' === $mode ) :
		?>
		<div class="psbdx-btn-wrap">
			<button class="psbdx-trigger-btn"
				type="button"
				data-target="psbdx-modal-<?php echo esc_attr( $uid ); ?>"
				aria-haspopup="dialog">
				<?php
				// Inline SVG flag icon.
				echo '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M4 15s1-1 4-1 5 2 8 2 4-1 4-1V3s-1 1-4 1-5-2-8-2-4 1-4 1z"/><line x1="4" y1="22" x2="4" y2="15"/></svg>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				?>
				<?php echo esc_html( $btn_text ); ?>
			</button>
		</div>
		<?php endif; ?>

		<?php if ( $is_popup ) : ?>
		<div id="psbdx-modal-<?php echo esc_attr( $uid ); ?>"
			class="psbdx-modal<?php echo ( 'popup_only' === $mode ) ? ' psbdx-modal-url-popup' : ''; ?>"
			role="dialog"
			aria-modal="true"
			aria-labelledby="psbdx-modal-title-<?php echo esc_attr( $uid ); ?>">

			<div class="psbdx-modal-panel">

				<button class="psbdx-modal-close" type="button"
					aria-label="<?php esc_attr_e( 'Close report form', 'psbdx-smart-report-management' ); ?>">
					&times;
				</button>
		<?php else : ?>
		<div class="psbdx-inline-form-wrap" id="psbdx-inline-<?php echo esc_attr( $uid ); ?>">
		<?php endif; ?>

				<div class="psbdx-modal-header">
					<div class="psbdx-modal-icon" aria-hidden="true">
						<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
					</div>
					<h2 class="psbdx-modal-title" id="psbdx-modal-title-<?php echo esc_attr( $uid ); ?>">
						<?php echo esc_html( $btn_text ); ?>
					</h2>
				</div>

				<div class="psbdx-context-bar" role="note">
					<svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
					<span>
						<?php esc_html_e( 'Reporting:', 'psbdx-smart-report-management' ); ?>
						<strong><?php echo esc_html( $current_title ); ?></strong>
					</span>
					<?php if ( $woo_order_id ) : ?>
						<span class="psbdx-order-tag"><?php esc_html_e( 'Order', 'psbdx-smart-report-management' ); ?></span>
					<?php endif; ?>
				</div>

				<?php if ( $cooldown_msg ) : ?>
				<div class="psbdx-notice psbdx-notice-warn" role="alert">
					<?php echo esc_html( $cooldown_msg ); ?>
				</div>
				<?php endif; ?>

				<form class="psbdx-report-form" novalidate <?php echo $cooldown_msg ? 'style="display:none;"' : ''; ?>>
					<input type="hidden" name="action"        value="psbdx_srm_submit">
					<input type="hidden" name="security"      value="<?php echo esc_attr( $nonce ); ?>">
					<input type="hidden" name="form_id"       value="<?php echo esc_attr( $form_id ); ?>">
					<input type="hidden" name="source_url"    value="<?php echo esc_url( $current_url ); ?>">
					<input type="hidden" name="source_title"  value="<?php echo esc_attr( $current_title ); ?>">
					<input type="hidden" name="woo_order_id"  value="<?php echo esc_attr( $woo_order_id ); ?>">

					<?php if ( ! $is_logged_in ) : ?>
					<div class="psbdx-notice psbdx-notice-info">
						<?php esc_html_e( 'You are submitting as a guest. Log in to link reports to your account.', 'psbdx-smart-report-management' ); ?>
					</div>
					<?php elseif ( 'yes' === $show_identity ) : ?>
					<div class="psbdx-identity-card" aria-label="<?php esc_attr_e( 'Submitting as', 'psbdx-smart-report-management' ); ?>">
						<?php echo get_avatar( $user->ID, 36, '', '', array( 'class' => 'psbdx-identity-avatar' ) ); ?>
						<div class="psbdx-identity-meta">
							<span class="psbdx-identity-name"><?php echo esc_html( $user_name ); ?></span>
							<span class="psbdx-identity-email"><?php echo esc_html( $user_email ); ?></span>
						</div>
						<span class="psbdx-identity-badge" title="<?php esc_attr_e( 'Verified from your WordPress account', 'psbdx-smart-report-management' ); ?>" aria-label="<?php esc_attr_e( 'Verified', 'psbdx-smart-report-management' ); ?>">
							<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="#22c55e" aria-hidden="true"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 14.5l-3.5-3.5 1.41-1.41L10 13.67l6.09-6.09 1.41 1.41L10 16.5z"/></svg>
						</span>
					</div>
					<?php endif; ?>

					<?php if ( $is_v2_form ) : ?>

					<?php // ── V2 Builder fields ──────────────────────────────────────── ?>
					<?php
					echo PSBDX_SRM_Form_Renderer::render_fields( $form_id, $uid ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- output is pre-escaped inside render_fields().
					?>

					<?php else : ?>

					<?php // ── V1 legacy fields (backward compatibility) ──────────────── ?>
					<?php
					$contact_id = 'psbdx-contact-' . $uid;
					?>
					<div class="psbdx-field">
						<label for="<?php echo esc_attr( $contact_id ); ?>">
							<?php echo esc_html( $contact_label ); ?>
							<?php if ( $contact_req ) : ?>
								<span class="psbdx-required" aria-label="<?php esc_attr_e( 'required', 'psbdx-smart-report-management' ); ?>">*</span>
							<?php else : ?>
								<span class="psbdx-optional"><?php esc_html_e( 'optional', 'psbdx-smart-report-management' ); ?></span>
							<?php endif; ?>
						</label>
						<input type="text"
							id="<?php echo esc_attr( $contact_id ); ?>"
							name="contact_value"
							<?php echo $contact_req ? 'required' : ''; ?>
							autocomplete="off"
							placeholder="<?php echo esc_attr( $contact_label ); ?>">
					</div>

					<?php foreach ( $custom_fields as $field ) : ?>
					<div class="psbdx-field">
						<label><?php echo esc_html( $field ); ?></label>
						<input type="text"
							name="psbdx_custom[<?php echo esc_attr( $field ); ?>]"
							placeholder="<?php echo esc_attr( $field ); ?>">
					</div>
					<?php endforeach; ?>

					<?php $reason_id = 'psbdx-reason-' . $uid; ?>
					<div class="psbdx-field">
						<label for="<?php echo esc_attr( $reason_id ); ?>">
							<?php esc_html_e( 'Reason', 'psbdx-smart-report-management' ); ?>
							<span class="psbdx-required" aria-label="<?php esc_attr_e( 'required', 'psbdx-smart-report-management' ); ?>">*</span>
						</label>
						<select id="<?php echo esc_attr( $reason_id ); ?>"
							name="report_reason"
							class="psbdx-reason-select"
							required>
							<?php foreach ( $reasons as $reason ) : ?>
								<option value="<?php echo esc_attr( $reason ); ?>"><?php echo esc_html( $reason ); ?></option>
							<?php endforeach; ?>
						</select>
					</div>

					<div class="psbdx-field psbdx-other-reason" style="display:none;">
						<label for="psbdx-other-reason-<?php echo esc_attr( $uid ); ?>">
							<?php esc_html_e( 'Please specify', 'psbdx-smart-report-management' ); ?>
							<span class="psbdx-required" aria-label="<?php esc_attr_e( 'required', 'psbdx-smart-report-management' ); ?>">*</span>
						</label>
						<input type="text"
							id="psbdx-other-reason-<?php echo esc_attr( $uid ); ?>"
							name="custom_reason"
							placeholder="<?php esc_attr_e( 'Describe your reason...', 'psbdx-smart-report-management' ); ?>">
					</div>

					<?php $details_id = 'psbdx-details-' . $uid; ?>
					<div class="psbdx-field">
						<label for="<?php echo esc_attr( $details_id ); ?>">
							<?php esc_html_e( 'Details', 'psbdx-smart-report-management' ); ?>
							<span class="psbdx-required" aria-label="<?php esc_attr_e( 'required', 'psbdx-smart-report-management' ); ?>">*</span>
						</label>
						<textarea id="<?php echo esc_attr( $details_id ); ?>"
							name="report_details"
							required
							rows="4"
							placeholder="<?php esc_attr_e( 'Describe the issue in detail...', 'psbdx-smart-report-management' ); ?>"></textarea>
					</div>

					<?php if ( $captcha_active ) : ?>
					<div class="psbdx-captcha-widget"
						data-provider="<?php echo esc_attr( $captcha_provider ); ?>"
						data-sitekey="<?php echo esc_attr( $captcha_site_key ); ?>"
						id="psbdx-captcha-<?php echo esc_attr( $uid ); ?>">
					</div>
					<?php endif; ?>

					<?php endif; // end v1/v2 conditional. ?>

					<button type="submit" class="psbdx-submit-btn">
						<span class="psbdx-btn-label"><?php esc_html_e( 'Submit Report', 'psbdx-smart-report-management' ); ?></span>
						<span class="psbdx-btn-spinner" aria-hidden="true" style="display:none;">
							<span class="psbdx-spinner"></span>
							<?php esc_html_e( 'Sending&hellip;', 'psbdx-smart-report-management' ); ?>
						</span>
					</button>
				</form>

				<div class="psbdx-form-response" role="alert" aria-live="polite"></div>

		<?php if ( $is_popup ) : ?>
			</div><!-- .psbdx-modal-panel -->
		</div><!-- .psbdx-modal -->
		<?php else : ?>
		</div><!-- .psbdx-inline-form-wrap -->
		<?php endif; ?>
		<?php

		return ob_get_clean();
	}

	// =========================================================================
	// [psbdx_user_reports]
	// =========================================================================

	/**
	 * Render the report history / support-agent portal shortcode.
	 *
	 * For an ordinary customer this is unchanged — just their own report
	 * history. For a support agent or Administrator it switches to a
	 * tabbed portal: My Reports, Assigned Reports, Search Ticket, and
	 * (Administrators/Super Administrators only) Manage Agents.
	 *
	 * @since  1.0.0
	 * @param  array $atts  Shortcode attributes (unused).
	 * @return string       HTML output.
	 */
	public function render_user_reports( $atts ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		if ( ! is_user_logged_in() ) {
			return '<p class="psbdx-notice psbdx-notice-warn">'
				. esc_html__( 'Please log in to view your report history.', 'psbdx-smart-report-management' )
				. '</p>';
		}

		$user_id = get_current_user_id();

		if ( ! class_exists( 'PSBDX_SRM_Agents' ) || ! PSBDX_SRM_Agents::is_agent_or_admin( $user_id ) ) {
			return $this->render_my_reports_only( $user_id );
		}

		return $this->render_agent_portal( $user_id );
	}

	/**
	 * The classic, unchanged view for a plain (non-agent) customer: just
	 * their own report history.
	 *
	 * @since  1.4.5
	 * @param  int $user_id  Current user's WP user ID.
	 * @return string
	 */
	private function render_my_reports_only( $user_id ) {
		$paged = max( 1, get_query_var( 'paged' ) );
		$query = new WP_Query( array(
			'post_type'      => 'psbdx_report_log',
			'post_status'    => 'publish',
			'author'         => $user_id,
			'posts_per_page' => 10,
			'paged'          => $paged,
		) );

		ob_start();
		?>
		<div class="psbdx-history-wrap">
			<div class="psbdx-history-header">
				<h3 class="psbdx-history-title"><?php esc_html_e( 'My Reports', 'psbdx-smart-report-management' ); ?></h3>
				<span class="psbdx-history-total">
					<?php
					printf(
						/* translators: %d: total number of reports */
						esc_html( _n( '%d report', '%d reports', $query->found_posts, 'psbdx-smart-report-management' ) ),
						(int) $query->found_posts
					);
					?>
				</span>
			</div>
			<?php self::render_reports_table( $query, $paged, __( "You haven't submitted any reports yet.", 'psbdx-smart-report-management' ) ); ?>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * The tabbed agent/admin portal: My Reports, Assigned Reports, Search
	 * Ticket, and — for Administrators/Super Administrators — Manage Agents.
	 *
	 * @since  1.4.5
	 * @param  int $user_id  Current user's WP user ID.
	 * @return string
	 */
	private function render_agent_portal( $user_id ) {
		$can_manage_tab = PSBDX_SRM_Agents::can_view_manage_tab( $user_id );
		$nonce          = wp_create_nonce( PSBDX_SRM_Ajax::AGENT_NONCE_ACTION );
		$incoming       = PSBDX_SRM_Agents::get_incoming_handover_requests( $user_id );

		ob_start();
		?>
		<div class="psbdx-agent-portal" data-nonce="<?php echo esc_attr( $nonce ); ?>" data-ajax-url="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>">

			<?php if ( ! empty( $incoming ) ) : ?>
				<div class="psbdx-notice psbdx-notice-warn psbdx-agent-handover-alert">
					<strong><?php esc_html_e( 'Handover requests waiting on you:', 'psbdx-smart-report-management' ); ?></strong>
					<ul>
						<?php foreach ( $incoming as $req ) :
							$requester  = get_userdata( $req->requester_id );
							$report_url = PSBDX_SRM_Report_Page::get_url( (int) $req->report_id );
							?>
							<li>
								<?php
								printf(
									/* translators: 1: requesting agent's name, 2: ticket ID */
									esc_html__( '%1$s wants to take over %2$s.', 'psbdx-smart-report-management' ),
									esc_html( $requester ? $requester->display_name : __( 'An agent', 'psbdx-smart-report-management' ) ),
									esc_html( PSBDX_SRM_Helpers::get_ticket_id( (int) $req->report_id ) )
								);
								?>
								<a href="<?php echo esc_url( $report_url ); ?>"><?php esc_html_e( 'Review it', 'psbdx-smart-report-management' ); ?></a>
							</li>
						<?php endforeach; ?>
					</ul>
				</div>
			<?php endif; ?>

			<div class="psbdx-agent-tabs" role="tablist">
				<button type="button" class="psbdx-agent-tab is-active" data-tab="my-reports"><?php esc_html_e( 'My Reports', 'psbdx-smart-report-management' ); ?></button>
				<button type="button" class="psbdx-agent-tab" data-tab="assigned-reports"><?php esc_html_e( 'Assigned Reports', 'psbdx-smart-report-management' ); ?></button>
				<button type="button" class="psbdx-agent-tab" data-tab="search-ticket"><?php esc_html_e( 'Search Ticket', 'psbdx-smart-report-management' ); ?></button>
				<?php if ( $can_manage_tab ) : ?>
					<button type="button" class="psbdx-agent-tab" data-tab="manage-agents"><?php esc_html_e( 'Manage Agents', 'psbdx-smart-report-management' ); ?></button>
				<?php endif; ?>
			</div>

			<div class="psbdx-agent-tab-panel is-active" data-panel="my-reports">
				<?php echo $this->render_my_reports_only( $user_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fully-escaped output built above. ?>
			</div>

			<div class="psbdx-agent-tab-panel" data-panel="assigned-reports">
				<?php echo self::render_assigned_reports_tab( $user_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			</div>

			<div class="psbdx-agent-tab-panel" data-panel="search-ticket">
				<?php echo self::render_search_ticket_tab(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			</div>

			<?php if ( $can_manage_tab ) : ?>
				<div class="psbdx-agent-tab-panel" data-panel="manage-agents">
					<?php echo self::render_manage_agents_tab( $user_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</div>
			<?php endif; ?>

		</div>

		<?php self::print_agent_portal_assets(); ?>
		<?php
		return ob_get_clean();
	}

	/**
	 * Shared table markup for a report-history WP_Query — used by both My
	 * Reports and Assigned Reports.
	 *
	 * @since  1.4.5
	 * @param  WP_Query $query          The reports to list.
	 * @param  int      $paged          Current page number, for pagination links.
	 * @param  string   $empty_message  Message shown when there are no reports.
	 * @return void
	 */
	private static function render_reports_table( WP_Query $query, $paged, $empty_message ) {
		if ( ! $query->have_posts() ) :
			?>
			<div class="psbdx-empty"><p><?php echo esc_html( $empty_message ); ?></p></div>
			<?php
			return;
		endif;
		?>
		<div class="psbdx-table-scroll">
			<table class="psbdx-history-table">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Ticket ID', 'psbdx-smart-report-management' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Date',   'psbdx-smart-report-management' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Reason', 'psbdx-smart-report-management' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Status', 'psbdx-smart-report-management' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Item',   'psbdx-smart-report-management' ); ?></th>
						<th scope="col"><span class="screen-reader-text"><?php esc_html_e( 'Details', 'psbdx-smart-report-management' ); ?></span></th>
					</tr>
				</thead>
				<tbody>
				<?php while ( $query->have_posts() ) :
					$query->the_post();
					$report_id = get_the_ID();
					$status    = get_post_meta( $report_id, '_psbdx_report_status', true ) ?: 'Processing';
					$ticket_id = PSBDX_SRM_Helpers::get_ticket_id( $report_id );
					$src_title = get_post_meta( $report_id, '_psbdx_source_title',  true );
					$src_url   = get_post_meta( $report_id, '_psbdx_source_url',    true );
					$order_id  = get_post_meta( $report_id, '_psbdx_woo_order_id',  true );
					$sc        = PSBDX_SRM_Helpers::get_status_class( $status );
					$statuses  = PSBDX_SRM_Helpers::get_statuses();
					$s_label   = isset( $statuses[ $status ] ) ? $statuses[ $status ]['label'] : $status;
					$s_style   = PSBDX_SRM_Helpers::get_status_inline_style( $status );
				?>
				<tr>
					<td data-label="<?php esc_attr_e( 'Ticket ID', 'psbdx-smart-report-management' ); ?>">
						<?php if ( $ticket_id ) : ?>
							<code class="psbdx-ticket-code"><?php echo esc_html( $ticket_id ); ?></code>
						<?php else : ?>
							<span class="psbdx-muted">&mdash;</span>
						<?php endif; ?>
					</td>
					<td data-label="<?php esc_attr_e( 'Date', 'psbdx-smart-report-management' ); ?>">
						<time datetime="<?php echo esc_attr( get_the_date( 'Y-m-d' ) ); ?>">
							<?php echo esc_html( get_the_date( 'd M Y' ) ); ?>
						</time>
					</td>
					<td data-label="<?php esc_attr_e( 'Reason', 'psbdx-smart-report-management' ); ?>">
						<?php echo esc_html( wp_trim_words( get_the_title(), 6, '&hellip;' ) ); ?>
					</td>
					<td data-label="<?php esc_attr_e( 'Status', 'psbdx-smart-report-management' ); ?>">
						<span class="psbdx-status-chip <?php echo esc_attr( $sc ); ?>" style="<?php echo esc_attr( $s_style ); ?>">
							<?php echo esc_html( $s_label ); ?>
						</span>
					</td>
					<td data-label="<?php esc_attr_e( 'Item', 'psbdx-smart-report-management' ); ?>">
						<?php if ( $order_id ) : ?>
							<span class="psbdx-status-chip psbdx-status-contacting">
								<?php
								printf(
									/* translators: %s: order number */
									esc_html__( 'Order #%s', 'psbdx-smart-report-management' ),
									esc_html( $order_id )
								);
								?>
							</span>
						<?php elseif ( $src_url ) : ?>
							<a href="<?php echo esc_url( $src_url ); ?>"
								target="_blank"
								rel="noopener noreferrer"
								class="psbdx-item-link">
								<?php echo esc_html( $src_title ?: __( 'View Item', 'psbdx-smart-report-management' ) ); ?>
								<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>
							</a>
						<?php else : ?>
							<span class="psbdx-muted">&mdash;</span>
						<?php endif; ?>
					</td>
					<td data-label="<?php esc_attr_e( 'Details', 'psbdx-smart-report-management' ); ?>">
						<a class="psbdx-view-details-btn" href="<?php echo esc_url( PSBDX_SRM_Report_Page::get_url( $report_id ) ); ?>">
							<?php esc_html_e( 'View & Reply', 'psbdx-smart-report-management' ); ?>
							<svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14"/><path d="m12 5 7 7-7 7"/></svg>
						</a>
					</td>
				</tr>
				<?php endwhile; wp_reset_postdata(); ?>
				</tbody>
			</table>
		</div>

		<?php if ( $query->max_num_pages > 1 ) : ?>
		<nav class="psbdx-pagination" aria-label="<?php esc_attr_e( 'Reports pagination', 'psbdx-smart-report-management' ); ?>">
			<?php
			echo paginate_links( array( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				'total'   => $query->max_num_pages,
				'current' => $paged,
				'format'  => '?paged=%#%',
			) );
			?>
		</nav>
		<?php endif; ?>
		<?php
	}

	/**
	 * Renders the "Assigned Reports" tab: reports currently assigned to
	 * this agent, in any status.
	 *
	 * @since  1.4.5
	 * @param  int $user_id  Agent's WP user ID.
	 * @return string
	 */
	private static function render_assigned_reports_tab( $user_id ) {
		$paged = max( 1, get_query_var( 'paged' ) );
		$query = new WP_Query( array(
			'post_type'      => 'psbdx_report_log',
			'post_status'    => 'publish',
			'posts_per_page' => 10,
			'paged'          => $paged,
			'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				array(
					'key'   => PSBDX_SRM_Agents::ASSIGNED_META,
					'value' => (int) $user_id,
				),
			),
		) );

		ob_start();
		?>
		<div class="psbdx-history-wrap">
			<div class="psbdx-history-header">
				<h3 class="psbdx-history-title"><?php esc_html_e( 'Assigned Reports', 'psbdx-smart-report-management' ); ?></h3>
				<span class="psbdx-history-total">
					<?php
					printf(
						/* translators: %d: total number of reports */
						esc_html( _n( '%d report', '%d reports', $query->found_posts, 'psbdx-smart-report-management' ) ),
						(int) $query->found_posts
					);
					?>
				</span>
			</div>
			<p class="description"><?php esc_html_e( 'Open a report to reply, change its status, abandon it, or ask another agent to take it over.', 'psbdx-smart-report-management' ); ?></p>
			<?php self::render_reports_table( $query, $paged, __( 'No reports are assigned to you right now.', 'psbdx-smart-report-management' ) ); ?>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Renders the "Search Ticket" tab.
	 *
	 * @since  1.4.5
	 * @return string
	 */
	private static function render_search_ticket_tab() {
		ob_start();
		?>
		<div class="psbdx-history-wrap">
			<h3 class="psbdx-history-title"><?php esc_html_e( 'Search Ticket', 'psbdx-smart-report-management' ); ?></h3>
			<p class="description"><?php esc_html_e( "Look up any ticket by its ID to view its full content. You'll only be able to reply if it's assigned to you.", 'psbdx-smart-report-management' ); ?></p>
			<form class="psbdx-agent-search-form" onsubmit="return false;">
				<input type="text" class="psbdx-agent-search-input" placeholder="<?php esc_attr_e( 'e.g. PSRM-20260811-000123', 'psbdx-smart-report-management' ); ?>">
				<button type="submit" class="psbdx-agent-btn psbdx-agent-btn-primary psbdx-agent-search-btn"><?php esc_html_e( 'Search', 'psbdx-smart-report-management' ); ?></button>
			</form>
			<p class="psbdx-agent-search-result" aria-live="polite"></p>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Renders the "Manage Agents" tab (Administrators/Super Administrators
	 * only) — a frontend mirror of the wp-admin Support Agents screen.
	 *
	 * @since  1.4.5
	 * @param  int $actor_id  Current viewer's WP user ID.
	 * @return string
	 */
	private static function render_manage_agents_tab( $actor_id ) {
		$agents   = PSBDX_SRM_Agents::get_all_agents();
		$is_super = PSBDX_SRM_Agents::is_super_admin( $actor_id );

		ob_start();
		?>
		<div class="psbdx-history-wrap">
			<h3 class="psbdx-history-title"><?php esc_html_e( 'Manage Agents', 'psbdx-smart-report-management' ); ?></h3>

			<form class="psbdx-agent-add-form">
				<input type="text" class="psbdx-agent-add-input" placeholder="<?php esc_attr_e( 'Username or email', 'psbdx-smart-report-management' ); ?>" required>
				<button type="submit" class="psbdx-agent-btn psbdx-agent-btn-primary"><?php esc_html_e( 'Add Agent', 'psbdx-smart-report-management' ); ?></button>
			</form>
			<p class="psbdx-agent-manage-status" aria-live="polite"></p>

			<div class="psbdx-table-scroll">
				<table class="psbdx-history-table psbdx-agent-manage-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Agent', 'psbdx-smart-report-management' ); ?></th>
							<th><?php esc_html_e( 'Role', 'psbdx-smart-report-management' ); ?></th>
							<th><?php esc_html_e( 'Rating', 'psbdx-smart-report-management' ); ?></th>
							<th><?php esc_html_e( 'Work Hours', 'psbdx-smart-report-management' ); ?></th>
							<th><?php esc_html_e( 'Actions', 'psbdx-smart-report-management' ); ?></th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ( $agents as $agent ) :
						if ( ! $agent['user'] ) {
							continue;
						}
						$can_manage = PSBDX_SRM_Agents::can_manage_target( $actor_id, $agent['user_id'] );
						$role_label = $agent['is_super'] ? __( 'Super Administrator', 'psbdx-smart-report-management' ) : ( $agent['is_admin'] ? __( 'Administrator', 'psbdx-smart-report-management' ) : __( 'Agent', 'psbdx-smart-report-management' ) );
						?>
						<tr data-user-id="<?php echo (int) $agent['user_id']; ?>"<?php echo $agent['excluded'] ? ' style="opacity:.5;"' : ''; ?>>
							<td data-label="<?php esc_attr_e( 'Agent', 'psbdx-smart-report-management' ); ?>"><?php echo esc_html( $agent['user']->display_name ); ?></td>
							<td data-label="<?php esc_attr_e( 'Role', 'psbdx-smart-report-management' ); ?>"><?php echo esc_html( $role_label ); ?></td>
							<td data-label="<?php esc_attr_e( 'Rating', 'psbdx-smart-report-management' ); ?>"><?php echo PSBDX_SRM_Agents::render_stars( $agent['rating'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped internally. ?></td>
							<td data-label="<?php esc_attr_e( 'Work Hours', 'psbdx-smart-report-management' ); ?>" class="psbdx-agent-manage-cell-hours">
								<?php if ( $can_manage ) : ?>
									<details>
										<summary><?php echo ! empty( $agent['work_hours'] ) ? esc_html__( 'Custom hours', 'psbdx-smart-report-management' ) : esc_html__( 'Always available', 'psbdx-smart-report-management' ); ?></summary>
										<div class="psbdx-agent-hours-editor">
											<?php PSBDX_SRM_Agents_Admin::render_hours_fields( $agent['user_id'] ); ?>
											<button type="button" class="psbdx-agent-btn psbdx-agent-save-hours-btn"><?php esc_html_e( 'Save Hours', 'psbdx-smart-report-management' ); ?></button>
										</div>
									</details>
								<?php else : ?>
									<?php echo ! empty( $agent['work_hours'] ) ? esc_html__( 'Custom hours', 'psbdx-smart-report-management' ) : esc_html__( 'Always available', 'psbdx-smart-report-management' ); ?>
								<?php endif; ?>
							</td>
							<td data-label="<?php esc_attr_e( 'Actions', 'psbdx-smart-report-management' ); ?>" class="psbdx-agent-manage-cell-actions">
								<?php if ( $can_manage ) : ?>
									<button type="button" class="psbdx-agent-btn psbdx-agent-btn-danger psbdx-agent-remove-btn"><?php esc_html_e( 'Remove', 'psbdx-smart-report-management' ); ?></button>
									<?php if ( $is_super && $agent['is_admin'] ) : ?>
										<button type="button" class="psbdx-agent-btn psbdx-agent-toggle-super-btn">
											<?php echo $agent['is_super'] ? esc_html__( 'Revoke Super Admin', 'psbdx-smart-report-management' ) : esc_html__( 'Make Super Admin', 'psbdx-smart-report-management' ); ?>
										</button>
									<?php endif; ?>
								<?php else : ?>
									<em><?php esc_html_e( 'Super Admin only', 'psbdx-smart-report-management' ); ?></em>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Prints the agent portal's CSS + JS once per page, no matter how many
	 * times the shortcode renders.
	 *
	 * @since  1.4.5
	 * @return void
	 */
	private static function print_agent_portal_assets() {
		static $printed = false;
		if ( $printed ) {
			return;
		}
		$printed = true;
		?>
		<script>
		( function () {
			var portal = document.querySelector( '.psbdx-agent-portal' );
			if ( ! portal ) { return; }

			var ajaxUrl = portal.getAttribute( 'data-ajax-url' );
			var nonce   = portal.getAttribute( 'data-nonce' );

			function post( action, params ) {
				var body = new URLSearchParams();
				body.set( 'action', action );
				body.set( 'nonce', nonce );
				Object.keys( params || {} ).forEach( function ( k ) { body.set( k, params[ k ] ); } );
				return fetch( ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body } ).then( function ( r ) { return r.json(); } );
			}

			// Tabs.
			portal.querySelectorAll( '.psbdx-agent-tab' ).forEach( function ( tab ) {
				tab.addEventListener( 'click', function () {
					portal.querySelectorAll( '.psbdx-agent-tab' ).forEach( function ( t ) { t.classList.remove( 'is-active' ); } );
					portal.querySelectorAll( '.psbdx-agent-tab-panel' ).forEach( function ( p ) { p.classList.remove( 'is-active' ); } );
					tab.classList.add( 'is-active' );
					var panel = portal.querySelector( '.psbdx-agent-tab-panel[data-panel="' + tab.getAttribute( 'data-tab' ) + '"]' );
					if ( panel ) { panel.classList.add( 'is-active' ); }
				} );
			} );

			// Search Ticket.
			var searchBtn = portal.querySelector( '.psbdx-agent-search-btn' );
			if ( searchBtn ) {
				searchBtn.addEventListener( 'click', function () {
					var input  = portal.querySelector( '.psbdx-agent-search-input' );
					var result = portal.querySelector( '.psbdx-agent-search-result' );
					if ( ! input.value.trim() ) { return; }
					result.textContent = '<?php echo esc_js( __( 'Searching…', 'psbdx-smart-report-management' ) ); ?>';
					post( 'psbdx_srm_agent_search', { query: input.value.trim() } ).then( function ( res ) {
						if ( res && res.success ) {
							window.location.href = res.data.url;
						} else {
							result.textContent = res && res.data ? res.data : '<?php echo esc_js( __( 'Not found.', 'psbdx-smart-report-management' ) ); ?>';
						}
					} );
				} );
			}

			// Manage Agents: add.
			var addForm = portal.querySelector( '.psbdx-agent-add-form' );
			if ( addForm ) {
				addForm.addEventListener( 'submit', function ( e ) {
					e.preventDefault();
					var input  = portal.querySelector( '.psbdx-agent-add-input' );
					var status = portal.querySelector( '.psbdx-agent-manage-status' );
					post( 'psbdx_srm_agent_manage', { sub_action: 'add', identifier: input.value.trim() } ).then( function ( res ) {
						status.textContent = res && res.success ? '<?php echo esc_js( __( 'Added — reload to see the updated list.', 'psbdx-smart-report-management' ) ); ?>' : ( res && res.data ? res.data : '<?php echo esc_js( __( 'Failed.', 'psbdx-smart-report-management' ) ); ?>' );
					} );
				} );
			}

			// Manage Agents: remove / save hours / toggle super.
			portal.querySelectorAll( '.psbdx-agent-manage-table tr[data-user-id]' ).forEach( function ( row ) {
				var userId = row.getAttribute( 'data-user-id' );

				var removeBtn = row.querySelector( '.psbdx-agent-remove-btn' );
				if ( removeBtn ) {
					removeBtn.addEventListener( 'click', function () {
						if ( ! window.confirm( '<?php echo esc_js( __( 'Remove this agent?', 'psbdx-smart-report-management' ) ); ?>' ) ) { return; }
						post( 'psbdx_srm_agent_manage', { sub_action: 'remove', user_id: userId } ).then( function ( res ) {
							if ( res && res.success ) { row.style.opacity = '.5'; }
						} );
					} );
				}

				var superBtn = row.querySelector( '.psbdx-agent-toggle-super-btn' );
				if ( superBtn ) {
					superBtn.addEventListener( 'click', function () {
						post( 'psbdx_srm_agent_manage', { sub_action: 'toggle_super', user_id: userId } ).then( function ( res ) {
							if ( res && res.success ) { window.location.reload(); }
						} );
					} );
				}

				var saveHoursBtn = row.querySelector( '.psbdx-agent-save-hours-btn' );
				if ( saveHoursBtn ) {
					saveHoursBtn.addEventListener( 'click', function () {
						var params = { sub_action: 'save_hours', user_id: userId };
						row.querySelectorAll( 'input[type="checkbox"]' ).forEach( function ( cb ) {
							if ( cb.checked ) { params[ cb.name.replace( 'psbdx_srm_hours_', '' ) ] = '1'; }
						} );
						row.querySelectorAll( 'input[type="time"]' ).forEach( function ( t ) {
							params[ t.name.replace( 'psbdx_srm_hours_', '' ) ] = t.value;
						} );
						post( 'psbdx_srm_agent_manage', params ).then( function ( res ) {
							saveHoursBtn.textContent = res && res.success ? '<?php echo esc_js( __( 'Saved!', 'psbdx-smart-report-management' ) ); ?>' : '<?php echo esc_js( __( 'Failed', 'psbdx-smart-report-management' ) ); ?>';
						} );
					} );
				}
			} );
		} )();
		</script>
		<?php
	}

	// =========================================================================
	// [psbdx_faq]
	// =========================================================================

	/**
	 * Render the FAQ accordion from the admin-managed list
	 * (Support → FAQ in the admin).
	 *
	 * @since  1.4.1
	 * @return string
	 */
	public function render_faq() {
		$items = PSBDX_SRM_Helpers::get_faq_items();

		if ( empty( $items ) ) {
			return '';
		}

		ob_start();
		?>
		<div class="psbdx-faq-wrap">

			<?php if ( count( $items ) > 4 ) : ?>
			<div class="psbdx-faq-search">
				<svg class="psbdx-faq-search-icon" xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
				<input type="search" class="psbdx-faq-search-input" id="psbdx-faq-search-input"
					placeholder="<?php esc_attr_e( 'Search questions…', 'psbdx-smart-report-management' ); ?>"
					aria-label="<?php esc_attr_e( 'Search frequently asked questions', 'psbdx-smart-report-management' ); ?>">
			</div>
			<?php endif; ?>

			<div class="psbdx-faq" id="psbdx-faq-list">
				<?php foreach ( $items as $i => $item ) : ?>
					<details class="psbdx-faq-item">
						<summary class="psbdx-faq-question">
							<span class="psbdx-faq-q-badge" aria-hidden="true">Q</span>
							<span class="psbdx-faq-q-text"><?php echo esc_html( $item['question'] ); ?></span>
							<span class="psbdx-faq-toggle-icon" aria-hidden="true"></span>
						</summary>
						<div class="psbdx-faq-answer">
							<span class="psbdx-faq-a-badge" aria-hidden="true">A</span>
							<div class="psbdx-faq-a-text"><?php echo wp_kses_post( wpautop( $item['answer'] ) ); ?></div>
						</div>
					</details>
				<?php endforeach; ?>
			</div>

			<p class="psbdx-faq-no-results" id="psbdx-faq-no-results" hidden>
				<?php esc_html_e( 'No questions match your search.', 'psbdx-smart-report-management' ); ?>
			</p>

		</div>
		<?php
		return ob_get_clean();
	}
}
