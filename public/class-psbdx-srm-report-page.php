<?php
/**
 * Frontend report detail page for PSBDx Smart Report Management.
 *
 * A lightweight "virtual" page — no dedicated WordPress page or rewrite
 * rule needed — reachable by adding `?psbdx_ticket=TICKET-ID` to any URL
 * on the site. Shows a report's full status/category/priority/details and
 * its reply thread (when replies are enabled). Only the person who filed
 * the report (matched by account or, for guests, by email) or an admin
 * can view it; everyone else gets a genuine 404, exactly like requesting
 * a page that doesn't exist.
 *
 * @package PSBDx_Smart_Report_Management
 * @since   1.4.2
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class PSBDX_SRM_Report_Page
 *
 * @since 1.4.2
 */
class PSBDX_SRM_Report_Page {

	/**
	 * The query string parameter that identifies a report to view.
	 *
	 * @since 1.4.2
	 * @var string
	 */
	const PARAM = 'psbdx_ticket';

	/**
	 * The query string parameter carrying a guest's email for verification.
	 *
	 * @since 1.4.2
	 * @var string
	 */
	const EMAIL_PARAM = 'psbdx_email';

	/**
	 * Constructor.
	 *
	 * @since 1.4.2
	 */
	public function __construct() {
		add_action( 'template_redirect', array( $this, 'maybe_render' ) );
	}

	/**
	 * Builds the frontend URL for viewing a report, for use in emails and
	 * the report history table. Guest (non-logged-in) links include the
	 * reporter's email so the page can verify identity without an account;
	 * logged-in owners don't need it (their session already proves it).
	 *
	 * @since 1.4.2
	 * @param  int  $report_id     Report log post ID.
	 * @param  bool $include_email Whether to embed the reporter's email in the URL.
	 * @return string
	 */
	public static function get_url( $report_id, $include_email = false ) {
		$ticket_id = PSBDX_SRM_Helpers::get_ticket_id( $report_id );

		if ( '' === $ticket_id ) {
			return '';
		}

		$args = array( self::PARAM => $ticket_id );

		if ( $include_email ) {
			$email = get_post_meta( $report_id, '_psbdx_reporter_email', true );
			if ( $email ) {
				$args[ self::EMAIL_PARAM ] = rawurlencode( $email );
			}
		}

		return add_query_arg( $args, home_url( '/' ) );
	}

	/**
	 * Intercepts requests carrying the ticket query param and renders the
	 * detail page (or a genuine 404) in place of whatever page was requested.
	 *
	 * @since  1.4.2
	 * @return void  Does not return when it handles the request (exits after rendering or 404ing).
	 */
	public function maybe_render() {
		if ( empty( $_GET[ self::PARAM ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only page view, identity is verified separately below.
			return;
		}

		$ticket_id = sanitize_text_field( wp_unslash( $_GET[ self::PARAM ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$report_id = PSBDX_SRM_Helpers::get_report_by_ticket_id( $ticket_id );

		$email = isset( $_GET[ self::EMAIL_PARAM ] ) ? sanitize_email( wp_unslash( $_GET[ self::EMAIL_PARAM ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( ! $report_id || ! PSBDX_SRM_Replies::can_access_report( $report_id, $email ) ) {
			$this->send_404();
			return;
		}

		$this->render( $report_id, $email );
		exit;
	}

	/**
	 * Serves a genuine 404 response using the active theme's 404 template,
	 * so an unauthorized visitor sees exactly what they'd see for any other
	 * nonexistent page on the site — no hint a report exists at all.
	 *
	 * @since  1.4.2
	 * @return void  Does not return.
	 */
	private function send_404() {
		global $wp_query;

		$wp_query->set_404();
		status_header( 404 );
		nocache_headers();

		$template = get_query_template( '404' );

		if ( $template ) {
			include $template;
		} else {
			wp_die( esc_html__( 'Page not found.', 'psbdx-smart-report-management' ), '', array( 'response' => 404 ) );
		}

		exit;
	}

	/**
	 * Renders the report detail page.
	 *
	 * @since  1.4.2
	 * @param  int    $report_id  Report log post ID.
	 * @param  string $email      The (already-verified) email the visitor used to access the page, if any — carried forward into the reply/solve forms for guests.
	 * @return void
	 */
	private function render( $report_id, $email ) {
		$post       = get_post( $report_id );
		$ticket_id  = PSBDX_SRM_Helpers::get_ticket_id( $report_id );
		$status     = get_post_meta( $report_id, '_psbdx_report_status', true ) ?: 'Processing';
		$statuses   = PSBDX_SRM_Helpers::get_statuses();
		$s_label    = isset( $statuses[ $status ] ) ? $statuses[ $status ]['label'] : $status;
		$s_style    = PSBDX_SRM_Helpers::get_status_inline_style( $status );
		$category   = get_post_meta( $report_id, '_psbdx_report_category', true );
		$priority   = get_post_meta( $report_id, '_psbdx_report_priority', true ) ?: 'Medium';
		$priorities = PSBDX_SRM_Helpers::get_report_priorities();
		$p_label    = isset( $priorities[ $priority ] ) ? $priorities[ $priority ] : $priority;
		$src_title  = get_post_meta( $report_id, '_psbdx_source_title', true );
		$src_url    = get_post_meta( $report_id, '_psbdx_source_url',   true );
		$can_reply  = PSBDX_SRM_Replies::replies_allowed( $report_id );

		$viewer_id             = get_current_user_id();
		$is_agent_viewer       = is_user_logged_in() && class_exists( 'PSBDX_SRM_Agents' ) && PSBDX_SRM_Agents::is_agent_or_admin( $viewer_id );
		$assigned_agent_id     = $is_agent_viewer ? PSBDX_SRM_Agents::get_assigned_agent( $report_id ) : 0;
		$is_assigned_to_viewer = $is_agent_viewer && $assigned_agent_id === $viewer_id;
		$is_locked             = class_exists( 'PSBDX_SRM_Agents' ) && PSBDX_SRM_Agents::is_report_locked( $report_id );

		get_header();

		// Defensive fallback: this plugin's CSS/JS are normally loaded via
		// the standard wp_enqueue_scripts hook, which core only fires when
		// the active theme calls wp_head() in header.php. Some minimal or
		// custom themes skip that call, which would otherwise leave this
		// entire page unstyled and non-interactive with no visible error.
		// If that's the case here, load them directly instead.
		if ( ! wp_style_is( 'psbdx-srm-public', 'done' ) && ! wp_style_is( 'psbdx-srm-public', 'enqueued' ) ) {
			PSBDX_SRM_Assets::print_style_tag( 'dashicons', includes_url( 'css/dashicons.min.css' ) );
			PSBDX_SRM_Assets::print_style_tag( 'psbdx-srm-public', PSBDX_SRM_URL . 'assets/css/public.css?ver=' . psbdx_srm_asset_ver( 'assets/css/public.css' ) );
		}
		?>
		<div class="psbdx-report-page">

			<div class="psbdx-report-hero">
				<div class="psbdx-report-hero-top">
					<span class="psbdx-report-hero-eyebrow"><?php esc_html_e( 'Report', 'psbdx-smart-report-management' ); ?></span>
					<span class="psbdx-status-chip psbdx-status-chip-lg" id="psbdx-report-page-status" style="<?php echo esc_attr( trim( $s_style ) ); ?>"><?php echo esc_html( $s_label ); ?></span>
				</div>
				<code class="psbdx-report-hero-ticket"><?php echo esc_html( $ticket_id ); ?></code>
				<p class="psbdx-report-hero-date"><?php echo esc_html( get_the_date( '', $post ) ); ?></p>
			</div>

			<div class="psbdx-report-page-card">
				<div class="psbdx-report-page-grid">
					<div class="psbdx-report-page-field">
						<span class="psbdx-report-page-label">
							<span class="dashicons dashicons-category" aria-hidden="true"></span>
							<?php esc_html_e( 'Category', 'psbdx-smart-report-management' ); ?>
						</span>
						<span class="psbdx-report-page-value"><?php echo esc_html( $category ?: __( 'Uncategorized', 'psbdx-smart-report-management' ) ); ?></span>
					</div>
					<div class="psbdx-report-page-field">
						<span class="psbdx-report-page-label">
							<span class="dashicons dashicons-flag" aria-hidden="true"></span>
							<?php esc_html_e( 'Priority', 'psbdx-smart-report-management' ); ?>
						</span>
						<span class="psbdx-report-page-value psbdx-priority-<?php echo esc_attr( strtolower( $priority ) ); ?>"><?php echo esc_html( $p_label ); ?></span>
					</div>
					<?php if ( $src_title ) : ?>
					<div class="psbdx-report-page-field psbdx-report-page-field-wide">
						<span class="psbdx-report-page-label">
							<span class="dashicons dashicons-admin-links" aria-hidden="true"></span>
							<?php esc_html_e( 'Item', 'psbdx-smart-report-management' ); ?>
						</span>
						<?php if ( $src_url ) : ?>
							<a class="psbdx-report-page-value" href="<?php echo esc_url( $src_url ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $src_title ); ?> ↗</a>
						<?php else : ?>
							<span class="psbdx-report-page-value"><?php echo esc_html( $src_title ); ?></span>
						<?php endif; ?>
					</div>
					<?php endif; ?>
				</div>

				<div class="psbdx-report-page-details">
					<span class="psbdx-report-page-label">
						<span class="dashicons dashicons-text-page" aria-hidden="true"></span>
						<?php esc_html_e( 'Details', 'psbdx-smart-report-management' ); ?>
					</span>
					<div class="psbdx-report-page-content"><?php echo wp_kses_post( wpautop( $post->post_content ) ); ?></div>
				</div>
			</div>

			<?php if ( $is_agent_viewer ) : ?>
			<?php echo self::render_agent_tools( $report_id, $viewer_id, $assigned_agent_id, $is_assigned_to_viewer, $can_reply, $is_locked ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped internally per-field. ?>
			<?php endif; ?>

			<?php if ( $can_reply && ! $is_agent_viewer ) : ?>
			<div class="psbdx-report-page-card">
				<h2 class="psbdx-report-page-heading">
					<span class="dashicons dashicons-format-chat" aria-hidden="true"></span>
					<?php esc_html_e( 'Conversation', 'psbdx-smart-report-management' ); ?>
				</h2>
				<?php echo PSBDX_SRM_Shortcodes::render_thread_html( $report_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped internally per-field. ?>
				<?php if ( $is_locked ) : ?>
					<div class="psbdx-notice psbdx-notice-warn psbdx-reopen-box" data-report-id="<?php echo (int) $report_id; ?>" data-email="<?php echo esc_attr( $email ); ?>">
						<p><?php esc_html_e( 'This report is marked Solved, so nobody can send a message until it\'s reopened.', 'psbdx-smart-report-management' ); ?></p>
						<button type="button" class="psbdx-agent-btn psbdx-agent-btn-primary psbdx-reopen-btn"><?php esc_html_e( 'Reopen This Report', 'psbdx-smart-report-management' ); ?></button>
						<span class="psbdx-reopen-status" role="status" aria-live="polite"></span>
					</div>
					<script>
					( function () {
						var box = document.currentScript.previousElementSibling;
						var btn = box.querySelector( '.psbdx-reopen-btn' );
						btn.addEventListener( 'click', function () {
							var status = box.querySelector( '.psbdx-reopen-status' );
							status.textContent = '<?php echo esc_js( __( 'Reopening…', 'psbdx-smart-report-management' ) ); ?>';
							var body = new URLSearchParams();
							body.set( 'action', 'psbdx_srm_reopen_report' );
							body.set( 'security', <?php echo wp_json_encode( wp_create_nonce( PSBDX_SRM_Ajax::REPLY_NONCE_ACTION ) ); ?> );
							body.set( 'report_id', box.getAttribute( 'data-report-id' ) );
							body.set( 'email', box.getAttribute( 'data-email' ) );
							fetch( <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>, { method: 'POST', credentials: 'same-origin', body: body } )
								.then( function ( r ) { return r.json(); } )
								.then( function ( res ) {
									if ( res && res.success ) { window.location.reload(); }
									else { status.textContent = res && res.data ? res.data : '<?php echo esc_js( __( 'Failed.', 'psbdx-smart-report-management' ) ); ?>'; }
								} );
						} );
					} )();
					</script>
				<?php else : ?>
					<form class="psbdx-thread-reply-form" data-report-id="<?php echo (int) $report_id; ?>" data-email="<?php echo esc_attr( $email ); ?>">
						<textarea class="psbdx-thread-reply-input" rows="5" placeholder="<?php esc_attr_e( 'Write a reply…', 'psbdx-smart-report-management' ); ?>"></textarea>
						<div class="psbdx-thread-reply-attach-row">
							<label class="psbdx-thread-reply-attach-btn">
								<span class="dashicons dashicons-paperclip" aria-hidden="true"></span>
								<?php esc_html_e( 'Attach a file', 'psbdx-smart-report-management' ); ?>
								<input type="file" class="psbdx-thread-reply-file" accept=".jpg,.jpeg,.png,.gif,.webp,.pdf" hidden>
							</label>
							<span class="psbdx-thread-reply-file-name"></span>
						</div>
						<div class="psbdx-thread-reply-footer">
							<span class="psbdx-thread-reply-status" role="status" aria-live="polite"></span>
							<button type="submit" class="psbdx-thread-reply-send">
								<span class="psbdx-thread-reply-send-label"><?php esc_html_e( 'Send Reply', 'psbdx-smart-report-management' ); ?></span>
								<span class="psbdx-thread-reply-send-spinner" aria-hidden="true"></span>
							</button>
						</div>
					</form>
				<?php endif; ?>
			</div>
			<?php endif; ?>
		</div>
		<?php
		get_footer();

		// Same fallback reasoning as the stylesheet above, for the script
		// this page's buttons and reply form depend on entirely. Checked
		// only now, *after* get_footer() has had its chance to print it via
		// wp_footer() — checking any earlier would wrongly assume a theme
		// with a working header but a footer.php that never calls
		// wp_footer() will still print it later, when it never does.
		// public.js guards itself against running twice, so it's always
		// safe to fall back here even in the rare case both copies load.
		if ( ! wp_script_is( 'psbdx-srm-public', 'done' ) ) {
			echo '<script id="psbdx-srm-public-js-extra">window.psbdxSrm = ' . wp_json_encode( PSBDX_SRM_Assets::get_localized_data() ) . ';</script>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_json_encode() output is safe JS; no user input here.
			PSBDX_SRM_Assets::print_script_tag( 'psbdx-srm-public', PSBDX_SRM_URL . 'assets/js/public.js?ver=' . psbdx_srm_asset_ver( 'assets/js/public.js' ) );
		}
	}

	/**
	 * Renders the Agent Tools card for an agent/admin viewer: assignment
	 * status, reply box (if assigned), status changer, abandon button,
	 * "request other agents to manage this report" control, and — for
	 * Administrators — the full activity log link.
	 *
	 * @since  1.4.5
	 * @param  int  $report_id              Report log post ID.
	 * @param  int  $viewer_id              Current viewer's WP user ID.
	 * @param  int  $assigned_agent_id      Currently assigned agent (0 = unassigned).
	 * @param  bool $is_assigned_to_viewer  Whether the viewer is the current assignee.
	 * @param  bool $can_reply              Whether replies are enabled for this report at all.
	 * @param  bool $is_locked              Whether the report is marked Solved (messaging locked until reopened).
	 * @return string
	 */
	private static function render_agent_tools( $report_id, $viewer_id, $assigned_agent_id, $is_assigned_to_viewer, $can_reply, $is_locked = false ) {
		$nonce           = wp_create_nonce( PSBDX_SRM_Ajax::AGENT_NONCE_ACTION );
		$assigned_agent  = $assigned_agent_id ? get_userdata( $assigned_agent_id ) : false;
		$statuses        = PSBDX_SRM_Helpers::get_statuses();
		$current_status  = get_post_meta( $report_id, '_psbdx_report_status', true ) ?: 'Processing';
		$pending_handover = PSBDX_SRM_Agents::get_pending_handover( $report_id );
		$other_agents    = array();

		foreach ( PSBDX_SRM_Agents::get_all_agents() as $agent ) {
			if ( $agent['excluded'] || ! $agent['user'] || $agent['user_id'] === $viewer_id ) {
				continue;
			}
			$other_agents[] = $agent;
		}

		ob_start();
		?>
		<div class="psbdx-report-page-card psbdx-agent-tools" data-report-id="<?php echo (int) $report_id; ?>" data-nonce="<?php echo esc_attr( $nonce ); ?>">
			<h2 class="psbdx-report-page-heading">
				<span class="dashicons dashicons-admin-users" aria-hidden="true"></span>
				<?php esc_html_e( 'Agent Tools', 'psbdx-smart-report-management' ); ?>
			</h2>

			<p>
				<strong><?php esc_html_e( 'Assigned to:', 'psbdx-smart-report-management' ); ?></strong>
				<?php echo $assigned_agent ? esc_html( $assigned_agent->display_name ) : esc_html__( 'Unassigned', 'psbdx-smart-report-management' ); ?>
			</p>

			<?php if ( $pending_handover ) : ?>
				<p class="psbdx-notice psbdx-notice-warn">
					<?php esc_html_e( 'There is a pending handover request on this report.', 'psbdx-smart-report-management' ); ?>
				</p>
			<?php endif; ?>

			<?php if ( $is_locked ) : ?>
				<p class="psbdx-notice psbdx-notice-warn">
					<?php esc_html_e( 'This report is marked Solved — nobody can message it until it\'s reopened.', 'psbdx-smart-report-management' ); ?>
				</p>
			<?php endif; ?>

			<?php if ( ! $assigned_agent_id && ! $pending_handover ) : ?>
				<button type="button" class="psbdx-agent-btn psbdx-agent-btn-primary psbdx-agent-claim-btn"><?php esc_html_e( 'Claim This Report', 'psbdx-smart-report-management' ); ?></button>
			<?php endif; ?>

			<?php if ( $is_assigned_to_viewer ) : ?>
				<div class="psbdx-agent-tools-row">
					<label>
						<?php esc_html_e( 'Status:', 'psbdx-smart-report-management' ); ?>
						<select class="psbdx-agent-status-select">
							<?php foreach ( $statuses as $key => $info ) : ?>
								<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $current_status, $key ); ?>><?php echo esc_html( $info['label'] ); ?></option>
							<?php endforeach; ?>
						</select>
					</label>
					<button type="button" class="psbdx-agent-btn psbdx-agent-status-save-btn"><?php esc_html_e( 'Update Status', 'psbdx-smart-report-management' ); ?></button>
				</div>

				<div class="psbdx-agent-tools-row">
					<button type="button" class="psbdx-agent-btn psbdx-agent-btn-danger psbdx-agent-abandon-btn"><?php esc_html_e( 'Abandon Report', 'psbdx-smart-report-management' ); ?></button>

					<?php if ( ! $pending_handover && ! empty( $other_agents ) ) : ?>
						<label>
							<?php esc_html_e( 'Request other agent:', 'psbdx-smart-report-management' ); ?>
							<select class="psbdx-agent-handover-target">
								<?php foreach ( $other_agents as $agent ) : ?>
									<option value="<?php echo (int) $agent['user_id']; ?>"><?php echo esc_html( $agent['user']->display_name ); ?></option>
								<?php endforeach; ?>
							</select>
						</label>
						<button type="button" class="psbdx-agent-btn psbdx-agent-offer-handover-btn"><?php esc_html_e( 'Send Handover Request', 'psbdx-smart-report-management' ); ?></button>
					<?php endif; ?>
				</div>

				<?php if ( $can_reply && ! $is_locked ) : ?>
				<h3><?php esc_html_e( 'Reply', 'psbdx-smart-report-management' ); ?></h3>
				<?php echo PSBDX_SRM_Shortcodes::render_thread_html( $report_id, true, true ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<div class="psbdx-agent-tools-row">
					<textarea class="psbdx-agent-reply-input" rows="5" placeholder="<?php esc_attr_e( 'Write a reply…', 'psbdx-smart-report-management' ); ?>"></textarea>
				</div>
				<div class="psbdx-agent-tools-row">
					<label class="psbdx-thread-reply-attach-btn">
						<span class="dashicons dashicons-paperclip" aria-hidden="true"></span>
						<?php esc_html_e( 'Attach a file', 'psbdx-smart-report-management' ); ?>
						<input type="file" class="psbdx-agent-reply-file" accept=".jpg,.jpeg,.png,.gif,.webp,.pdf" hidden>
					</label>
					<span class="psbdx-agent-reply-file-name"></span>
					<button type="button" class="psbdx-agent-btn psbdx-agent-btn-primary psbdx-agent-reply-send-btn"><?php esc_html_e( 'Send Reply', 'psbdx-smart-report-management' ); ?></button>
				</div>
				<p class="psbdx-agent-reply-status" aria-live="polite"></p>
				<p class="description"><?php esc_html_e( 'Agents can remove any attachment shared in this thread (not the report\'s original attachments) using the delete icon next to it.', 'psbdx-smart-report-management' ); ?></p>
				<?php elseif ( $can_reply && $is_locked ) : ?>
				<h3><?php esc_html_e( 'Reply', 'psbdx-smart-report-management' ); ?></h3>
				<?php echo PSBDX_SRM_Shortcodes::render_thread_html( $report_id, true, true ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<p class="psbdx-notice psbdx-notice-warn"><?php esc_html_e( 'This report is marked Solved. Change its status above to reopen it before replying.', 'psbdx-smart-report-management' ); ?></p>
				<?php endif; ?>

			<?php elseif ( $assigned_agent_id && ! $pending_handover ) : ?>
				<button type="button" class="psbdx-agent-btn psbdx-agent-request-handover-btn"><?php esc_html_e( 'Request Handover From Assigned Agent', 'psbdx-smart-report-management' ); ?></button>
				<p class="description"><?php esc_html_e( "You can view this report, but you'll need to be assigned (or have your handover request accepted) before you can reply.", 'psbdx-smart-report-management' ); ?></p>
			<?php endif; ?>

			<p class="psbdx-agent-tools-status" aria-live="polite"></p>
		</div>

		<script>
		( function () {
			var card = document.currentScript.previousElementSibling;
			while ( card && ! card.classList.contains( 'psbdx-agent-tools' ) ) { card = card.previousElementSibling; }
			if ( ! card ) { return; }

			var ajaxUrl = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
			var nonce   = card.getAttribute( 'data-nonce' );
			var reportId = card.getAttribute( 'data-report-id' );
			var statusEl = card.querySelector( '.psbdx-agent-tools-status' );

			function post( action, params, isFormData ) {
				var body;
				if ( isFormData ) {
					body = params;
					body.set( 'action', action );
					body.set( 'nonce', nonce );
					body.set( 'report_id', reportId );
				} else {
					body = new URLSearchParams();
					body.set( 'action', action );
					body.set( 'nonce', nonce );
					body.set( 'report_id', reportId );
					Object.keys( params || {} ).forEach( function ( k ) { body.set( k, params[ k ] ); } );
				}
				return fetch( ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body } ).then( function ( r ) { return r.json(); } );
			}

			function bind( selector, handler ) {
				var el = card.querySelector( selector );
				if ( el ) { el.addEventListener( 'click', handler ); }
			}

			bind( '.psbdx-agent-claim-btn', function () {
				post( 'psbdx_srm_agent_claim' ).then( function ( res ) {
					if ( res && res.success ) { window.location.reload(); }
					else { statusEl.textContent = res && res.data ? res.data : '<?php echo esc_js( __( 'Failed.', 'psbdx-smart-report-management' ) ); ?>'; }
				} );
			} );

			bind( '.psbdx-agent-abandon-btn', function () {
				if ( ! window.confirm( '<?php echo esc_js( __( 'Abandon this report?', 'psbdx-smart-report-management' ) ); ?>' ) ) { return; }
				post( 'psbdx_srm_agent_abandon' ).then( function ( res ) {
					if ( res && res.success ) { window.location.reload(); }
				} );
			} );

			bind( '.psbdx-agent-status-save-btn', function () {
				var select = card.querySelector( '.psbdx-agent-status-select' );
				post( 'psbdx_srm_agent_change_status', { status: select.value } ).then( function ( res ) {
					if ( res && res.success ) {
						statusEl.textContent = '<?php echo esc_js( __( 'Status updated.', 'psbdx-smart-report-management' ) ); ?>';
						window.location.reload();
					} else {
						statusEl.textContent = res && res.data ? res.data : '<?php echo esc_js( __( 'Failed.', 'psbdx-smart-report-management' ) ); ?>';
					}
				} );
			} );

			bind( '.psbdx-agent-request-handover-btn', function () {
				post( 'psbdx_srm_agent_request_handover' ).then( function ( res ) {
					statusEl.textContent = res && res.success ? '<?php echo esc_js( __( 'Handover requested.', 'psbdx-smart-report-management' ) ); ?>' : ( res && res.data ? res.data : '<?php echo esc_js( __( 'Failed.', 'psbdx-smart-report-management' ) ); ?>' );
				} );
			} );

			bind( '.psbdx-agent-offer-handover-btn', function () {
				var select = card.querySelector( '.psbdx-agent-handover-target' );
				post( 'psbdx_srm_agent_offer_handover', { target_agent_id: select.value } ).then( function ( res ) {
					statusEl.textContent = res && res.success ? '<?php echo esc_js( __( 'Handover request sent.', 'psbdx-smart-report-management' ) ); ?>' : ( res && res.data ? res.data : '<?php echo esc_js( __( 'Failed.', 'psbdx-smart-report-management' ) ); ?>' );
				} );
			} );

			var replyFile = card.querySelector( '.psbdx-agent-reply-file' );
			if ( replyFile ) {
				replyFile.addEventListener( 'change', function () {
					var nameEl = card.querySelector( '.psbdx-agent-reply-file-name' );
					nameEl.textContent = replyFile.files[0] ? replyFile.files[0].name : '';
				} );
			}

			bind( '.psbdx-agent-reply-send-btn', function () {
				var textarea = card.querySelector( '.psbdx-agent-reply-input' );
				var replyStatus = card.querySelector( '.psbdx-agent-reply-status' );
				var formData = new FormData();
				formData.set( 'message', textarea.value );
				if ( replyFile && replyFile.files[0] ) { formData.set( 'reply_attachment', replyFile.files[0] ); }
				replyStatus.textContent = '<?php echo esc_js( __( 'Sending…', 'psbdx-smart-report-management' ) ); ?>';
				post( 'psbdx_srm_agent_reply', formData, true ).then( function ( res ) {
					if ( res && res.success ) {
						textarea.value = '';
						if ( replyFile ) { replyFile.value = ''; }
						replyStatus.textContent = '<?php echo esc_js( __( 'Sent!', 'psbdx-smart-report-management' ) ); ?>';
						window.location.reload();
					} else {
						replyStatus.textContent = res && res.data ? res.data : '<?php echo esc_js( __( 'Failed to send.', 'psbdx-smart-report-management' ) ); ?>';
					}
				} );
			} );

			card.addEventListener( 'click', function ( e ) {
				var btn = e.target.closest( '.psbdx-agent-delete-attachment-btn' );
				if ( ! btn ) { return; }
				if ( ! window.confirm( '<?php echo esc_js( __( 'Delete this attachment?', 'psbdx-smart-report-management' ) ); ?>' ) ) { return; }
				post( 'psbdx_srm_agent_delete_attachment', { attachment_id: btn.getAttribute( 'data-attachment-id' ) } ).then( function ( res ) {
					if ( res && res.success ) { window.location.reload(); }
				} );
			} );
		} )();
		</script>
		<?php
		return ob_get_clean();
	}
}
