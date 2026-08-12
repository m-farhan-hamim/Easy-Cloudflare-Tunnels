<?php
/**
 * AJAX handler for PSBDx Smart Report Management.
 *
 * @package PSBDx_Smart_Report_Management
 * @since   1.0.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class PSBDX_SRM_Ajax
 *
 * Handles the AJAX submission of report forms from the frontend.
 * Both logged-in and logged-out (nopriv) submissions are accepted;
 * rate limiting is applied only to logged-in users.
 *
 * @since 1.0.0
 */
class PSBDX_SRM_Ajax {

	/**
	 * AJAX action name.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	const ACTION = 'psbdx_srm_submit';

	/**
	 * Nonce action name.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	const NONCE_ACTION = 'psbdx_srm_submit_nonce';

	/**
	 * AJAX action name for a reporter posting a reply to their own report.
	 *
	 * @since 1.4.2
	 * @var string
	 */
	const REPLY_ACTION = 'psbdx_srm_submit_reply';

	/**
	 * Nonce action name for reporter replies.
	 *
	 * @since 1.4.2
	 * @var string
	 */
	const REPLY_NONCE_ACTION = 'psbdx_srm_submit_reply_nonce';

	/**
	 * AJAX action name for polling a report's thread for new messages
	 * while the page is open, so a reply from the other side shows up
	 * without needing a manual reload.
	 *
	 * @since 1.4.2
	 * @var string
	 */
	const POLL_ACTION = 'psbdx_srm_poll_thread';

	/**
	 * Nonce action name for polling a thread.
	 *
	 * @since 1.4.2
	 * @var string
	 */
	const POLL_NONCE_ACTION = 'psbdx_srm_poll_thread_nonce';

	/**
	 * AJAX action name for fetching a form's popup modal markup, used by
	 * the URL-popup feature (see PSBDX_SRM_Popup_Link) when a visitor's
	 * URL matches the "?<form_id>" trigger pattern on a page that doesn't
	 * otherwise have the [psbdx_report] shortcode on it.
	 *
	 * @since 1.4.5
	 * @var string
	 */
	const POPUP_ACTION = 'psbdx_srm_get_popup_form';

	/**
	 * Nonce action shared by every logged-in-only agent-portal AJAX
	 * endpoint (reply, abandon, change status, handover, search, etc.) —
	 * see the [psbdx_user_reports] agent tabs in PSBDX_SRM_Shortcodes.
	 *
	 * @since 1.4.5
	 * @var string
	 */
	const AGENT_NONCE_ACTION = 'psbdx_srm_agent_portal_nonce';

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		add_action( 'wp_ajax_' . self::ACTION,        array( $this, 'handle' ) );
		add_action( 'wp_ajax_nopriv_' . self::ACTION, array( $this, 'handle' ) );

		// Agent-portal actions: logged-in only, every handler re-checks
		// PSBDX_SRM_Agents::is_agent_or_admin() itself.
		add_action( 'wp_ajax_psbdx_srm_agent_reply',            array( $this, 'handle_agent_reply' ) );
		add_action( 'wp_ajax_psbdx_srm_agent_abandon',           array( $this, 'handle_agent_abandon' ) );
		add_action( 'wp_ajax_psbdx_srm_agent_claim',             array( $this, 'handle_agent_claim' ) );
		add_action( 'wp_ajax_psbdx_srm_agent_change_status',     array( $this, 'handle_agent_change_status' ) );
		add_action( 'wp_ajax_psbdx_srm_agent_request_handover',  array( $this, 'handle_agent_request_handover' ) );
		add_action( 'wp_ajax_psbdx_srm_agent_offer_handover',    array( $this, 'handle_agent_offer_handover' ) );
		add_action( 'wp_ajax_psbdx_srm_agent_respond_handover',  array( $this, 'handle_agent_respond_handover' ) );
		add_action( 'wp_ajax_psbdx_srm_agent_cancel_handover',   array( $this, 'handle_agent_cancel_handover' ) );
		add_action( 'wp_ajax_psbdx_srm_agent_delete_attachment', array( $this, 'handle_agent_delete_attachment' ) );
		add_action( 'wp_ajax_psbdx_srm_agent_search',            array( $this, 'handle_agent_search' ) );
		add_action( 'wp_ajax_psbdx_srm_agent_manage',            array( $this, 'handle_agent_manage' ) );

		// Replies are open to guests too: access is verified per-request
		// against report ownership (logged-in) or a matching reporter email
		// (guest) — see PSBDX_SRM_Replies::can_access_report().
		add_action( 'wp_ajax_' . self::REPLY_ACTION,        array( $this, 'handle_reply' ) );
		add_action( 'wp_ajax_nopriv_' . self::REPLY_ACTION, array( $this, 'handle_reply' ) );

		add_action( 'wp_ajax_psbdx_srm_reopen_report',        array( $this, 'handle_reopen_report' ) );
		add_action( 'wp_ajax_nopriv_psbdx_srm_reopen_report',  array( $this, 'handle_reopen_report' ) );
		add_action( 'wp_ajax_' . self::POLL_ACTION,        array( $this, 'handle_poll_thread' ) );
		add_action( 'wp_ajax_nopriv_' . self::POLL_ACTION, array( $this, 'handle_poll_thread' ) );

		// Popup-link markup is public by design (that's the whole point —
		// any visitor landing on a "?<id>" URL should see it), gated only
		// by the per-form "Enable popup link" checkbox and a light IP throttle.
		add_action( 'wp_ajax_' . self::POPUP_ACTION,        array( $this, 'handle_get_popup_form' ) );
		add_action( 'wp_ajax_nopriv_' . self::POPUP_ACTION, array( $this, 'handle_get_popup_form' ) );

		add_action( self::DEFERRED_HOOK, array( $this, 'process_deferred' ), 10, 2 );
	}

	/**
	 * Hook used to run deferred, slow work (AI classification, AI
	 * auto-reply, email notifications) on a separate request via WP-Cron,
	 * for hosts where fastcgi_finish_request() isn't available and the
	 * visitor's own request would otherwise have to sit and wait for it.
	 *
	 * @since 1.4.4
	 * @var string
	 */
	const DEFERRED_HOOK = 'psbdx_srm_process_deferred';

	/**
	 * Sends a JSON success response to the browser immediately and, where
	 * the server supports it (PHP-FPM), closes out the HTTP connection —
	 * so the caller can keep running slow, synchronous work afterward
	 * (AI classification, AI auto-reply, email notifications) without any
	 * chance of it delaying the visitor's response or — on a host with a
	 * tight `max_execution_time` — fataling the request they're watching.
	 *
	 * Unlike wp_send_json_success(), this does NOT terminate execution:
	 * the caller is expected to do its follow-up work (if this returned
	 * true) or defer it (if it returned false), then call exit() itself.
	 *
	 * @since 1.4.3
	 * @param  array $data  Response payload (same shape passed to wp_send_json_success()).
	 * @return bool  True if the HTTP connection was actually closed out
	 *               already (fastcgi_finish_request() ran) — safe to keep
	 *               doing slow work inline below. False if the connection
	 *               is still open — the caller should hand any slow work
	 *               off to defer_work() instead of running it here, or the
	 *               visitor's browser will keep waiting on it regardless.
	 */
	private function respond_then_continue( array $data ) {
		if ( ! headers_sent() ) {
			header( 'Content-Type: application/json; charset=' . get_option( 'blog_charset' ) );
		}

		echo wp_json_encode( array( 'success' => true, 'data' => $data ) );

		if ( function_exists( 'fastcgi_finish_request' ) ) {
			// Best case (PHP-FPM, most modern hosts): the browser gets its
			// response right now, and everything below this call runs
			// server-side with nobody waiting on it.
			fastcgi_finish_request();
			$finished = true;
		} else {
			// No FPM available — flush what we can, but the connection to
			// the browser genuinely stays open until this request ends, so
			// anything slow still needs to be run out-of-band (see
			// defer_work()) rather than inline here.
			while ( ob_get_level() > 0 ) {
				ob_end_flush();
			}
			flush();
			$finished = false;
		}

		// A slow or briefly-degraded AI provider (or a slow page fetch for
		// reply-context) should never be able to fatal this request with a
		// "Maximum execution time exceeded" error. @-suppressed because
		// set_time_limit() is disabled outright on some hosts, which is
		// fine — it's a best-effort safety net, not a requirement.
		@set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, Squiz.PHP.DiscouragedFunctions.Discouraged

		return $finished;
	}

	/**
	 * Hands slow, non-essential work off to a one-off WP-Cron event on its
	 * own separate request, instead of running it inline and keeping the
	 * visitor's connection open until it finishes. Used as the fallback
	 * for hosts where respond_then_continue() couldn't already close the
	 * connection out via fastcgi_finish_request().
	 *
	 * spawn_cron() nudges that event to run right away via a non-blocking
	 * loopback request, rather than waiting for the next site visit to
	 * trigger WP-Cron; if the loopback can't fire for some reason (some
	 * locked-down hosts), the event still runs on the next normal page
	 * load — later than ideal, but never blocking, and never lost.
	 *
	 * @since 1.4.4
	 * @param  string $type  'submission' or 'reply' — see process_deferred().
	 * @param  int    $id    Report log post ID.
	 * @return void
	 */
	private function defer_work( $type, $id ) {
		wp_schedule_single_event( time(), self::DEFERRED_HOOK, array( $type, (int) $id ) );

		if ( function_exists( 'spawn_cron' ) ) {
			spawn_cron();
		}
	}

	/**
	 * WP-Cron callback for defer_work() — runs on its own request, well
	 * away from whichever visitor originally triggered it.
	 *
	 * @since 1.4.4
	 * @param  string $type  'submission' or 'reply'.
	 * @param  int    $id    Report log post ID.
	 * @return void
	 */
	public function process_deferred( $type, $id ) {
		$id = (int) $id;

		if ( 'submission' === $type ) {
			do_action( 'psbdx_srm_report_submitted', $id );
		} elseif ( 'reply' === $type ) {
			PSBDX_SRM_AI::generate_reply( $id );
		}
	}

	/**
	 * Returns the popup modal markup for a form, for the URL-popup feature.
	 * Guest-accessible on purpose (see class docblock), gated by:
	 * - the form must exist, be published, and have "Enable popup link"
	 *   checked (PSBDX_SRM_Popup_Link::is_enabled()) — off by default;
	 * - a light per-IP throttle (30 requests / 10 minutes) so the endpoint
	 *   can't be used to enumerate form IDs at scale.
	 *
	 * @since  1.4.5
	 * @return void  Terminates with wp_send_json_success() or wp_send_json_error().
	 */
	public function handle_get_popup_form() {
		$ip  = PSBDX_SRM_Helpers::get_client_ip();
		$key = 'psbdx_popup_rl_' . md5( $ip );
		$hit = (int) get_transient( $key );

		if ( $hit >= 30 ) {
			wp_send_json_error( __( 'Too many requests. Please try again shortly.', 'psbdx-smart-report-management' ) );
		}

		set_transient( $key, $hit + 1, 10 * MINUTE_IN_SECONDS );

		$form_id = absint( $_GET['form_id'] ?? ( $_POST['form_id'] ?? 0 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.Missing -- public read-only lookup, no state changed; gated by per-form opt-in + IP throttle above.

		if ( ! $form_id
			|| 'psbdx_report_form' !== get_post_type( $form_id )
			|| 'publish' !== get_post_status( $form_id )
			|| ! PSBDX_SRM_Popup_Link::is_enabled( $form_id )
		) {
			wp_send_json_error( __( 'This form is not available as a popup.', 'psbdx-smart-report-management' ) );
		}

		wp_send_json_success( array(
			'html' => PSBDX_SRM_Shortcodes::render_form_instance( $form_id, 'popup_only' ),
		) );
	}

	/**
	 * Handle a background poll for a report's thread — lets the report
	 * detail page (and the admin Conversation box) pick up a reply from
	 * the other side while the page is still open, without a manual reload.
	 * Read-only: just returns the current thread and its message count.
	 *
	 * @since  1.4.2
	 * @return void  Terminates with wp_send_json_success() or wp_send_json_error().
	 */
	public function handle_poll_thread() {
		if ( ! check_ajax_referer( self::POLL_NONCE_ACTION, 'security', false ) ) {
			wp_send_json_error( __( 'Your session has expired. Please refresh the page.', 'psbdx-smart-report-management' ) );
		}

		$report_id = absint( $_POST['report_id'] ?? 0 );
		$email     = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';

		if ( ! $report_id || 'psbdx_report_log' !== get_post_type( $report_id ) ) {
			wp_send_json_error( __( 'Invalid report.', 'psbdx-smart-report-management' ) );
		}

		if ( ! PSBDX_SRM_Replies::can_access_report( $report_id, $email ) ) {
			wp_send_json_error( __( 'Not allowed.', 'psbdx-smart-report-management' ) );
		}

		$is_admin_view = current_user_can( 'edit_posts' );

		wp_send_json_success( array(
			'count'              => count( PSBDX_SRM_Replies::get_thread( $report_id ) ),
			'thread_html'        => PSBDX_SRM_Shortcodes::render_thread_html( $report_id, true, $is_admin_view ),
			'thread_html_inner'  => PSBDX_SRM_Shortcodes::render_thread_html( $report_id, false, $is_admin_view ),
		) );
	}

	/**
	 * Handle a reporter (or admin) posting a reply from the frontend report
	 * detail page. Accepts either a logged-in report owner, an admin, or a
	 * guest who supplies the email address the report was filed with.
	 *
	 * @since  1.4.2
	 * @return void  Terminates with wp_send_json_success() or wp_send_json_error().
	 */
	public function handle_reply() {
		if ( ! check_ajax_referer( self::REPLY_NONCE_ACTION, 'security', false ) ) {
			wp_send_json_error( __( 'Your session has expired. Please refresh the page and try again.', 'psbdx-smart-report-management' ) );
		}

		$report_id = absint( $_POST['report_id'] ?? 0 );
		$message   = isset( $_POST['message'] ) ? sanitize_textarea_field( wp_unslash( $_POST['message'] ) ) : '';
		$email     = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';

		if ( ! $report_id || 'psbdx_report_log' !== get_post_type( $report_id ) ) {
			wp_send_json_error( __( 'Invalid report.', 'psbdx-smart-report-management' ) );
		}

		if ( ! PSBDX_SRM_Replies::can_access_report( $report_id, $email ) ) {
			wp_send_json_error( __( 'You can only reply to your own reports.', 'psbdx-smart-report-management' ) );
		}

		// A non-admin agent can view any report (see the Agents branch in
		// PSBDX_SRM_Replies::can_access_report()) but, per design, can only
		// send a message on one that's actually assigned to them — replying
		// as an agent to an unassigned/other-agent's ticket happens through
		// the dedicated agent endpoints instead (see handle_agent_reply()).
		if (
			is_user_logged_in()
			&& (int) get_post_field( 'post_author', $report_id ) !== get_current_user_id()
			&& ! current_user_can( 'edit_posts' )
			&& class_exists( 'PSBDX_SRM_Agents' )
			&& PSBDX_SRM_Agents::is_agent_or_admin( get_current_user_id() )
			&& PSBDX_SRM_Agents::get_assigned_agent( $report_id ) !== get_current_user_id()
		) {
			wp_send_json_error( __( 'This report isn\'t assigned to you.', 'psbdx-smart-report-management' ) );
		}

		if ( ! PSBDX_SRM_Replies::replies_allowed( $report_id ) ) {
			wp_send_json_error( __( 'Replies are not enabled for this report.', 'psbdx-smart-report-management' ) );
		}

		if ( class_exists( 'PSBDX_SRM_Agents' ) && PSBDX_SRM_Agents::is_report_locked( $report_id ) ) {
			wp_send_json_error( __( 'This report is marked Solved. Reopen it to send another message.', 'psbdx-smart-report-management' ) );
		}

		// A reply attachment is optional and shared alongside the message —
		// see PSBDX_SRM_Ajax::validate_and_upload_file() for the shared
		// validation/upload logic (also used by v2 Attachment fields and
		// the admin-side reply box).
		$attachment_id = 0;
		$file          = $this->extract_flat_uploaded_file( 'reply_attachment' );

		if ( $file && UPLOAD_ERR_NO_FILE !== $file['error'] ) {
			$attachment_id = self::validate_and_upload_file(
				$file,
				__( 'Attachment', 'psbdx-smart-report-management' ),
				self::REPLY_ATTACHMENT_TYPES,
				0,
				self::REPLY_ATTACHMENT_MAX_KB
			);
		}

		if ( '' === trim( $message ) && ! $attachment_id ) {
			wp_send_json_error( __( 'Please write a message or attach a file before sending.', 'psbdx-smart-report-management' ) );
		}

		if ( current_user_can( 'edit_posts' ) ) {
			$user        = wp_get_current_user();
			$author_type = 'admin';
			$author_id   = $user->ID;
			$author_name = $user->display_name;
		} elseif ( is_user_logged_in() ) {
			$user        = wp_get_current_user();
			$author_type = 'user';
			$author_id   = $user->ID;
			$author_name = $user->display_name;
		} else {
			$author_type = 'user';
			$author_id   = 0;
			$author_name = get_post_meta( $report_id, '_psbdx_reporter_email', true ) ?: __( 'Customer', 'psbdx-smart-report-management' );
		}

		$reply_id = PSBDX_SRM_Replies::add_reply( $report_id, $author_type, $author_id, $author_name, $message, false, $attachment_id );

		if ( ! $reply_id ) {
			wp_send_json_error( __( 'Failed to save your reply. Please try again.', 'psbdx-smart-report-management' ) );
		}

		if ( $attachment_id ) {
			wp_update_post( array( 'ID' => $attachment_id, 'post_parent' => $report_id ) );
		}

		$thread_html = PSBDX_SRM_Shortcodes::render_thread_html( $report_id, true );

		// Give the AI a chance to respond automatically to the follow-up,
		// same as it does for the initial report — gated the same way, and
		// only when it wasn't the admin themselves who just replied. This
		// runs AFTER the response below, so a slow AI provider can't turn
		// posting a reply into a fatal timeout for the person replying;
		// the existing thread-polling mechanism picks up the AI's reply a
		// moment later, the same way it already picks up a reply from
		// whichever side didn't just send this one.
		$should_auto_reply = ( 'admin' !== $author_type && PSBDX_SRM_Replies::ai_reply_allowed( $report_id ) );

		$finished = $this->respond_then_continue(
			array(
				'thread_html' => $thread_html,
				'message'     => __( 'Your reply has been sent.', 'psbdx-smart-report-management' ),
			)
		);

		if ( $should_auto_reply ) {
			if ( $finished ) {
				PSBDX_SRM_AI::generate_reply( $report_id );
			} else {
				$this->defer_work( 'reply', $report_id );
			}
		}

		exit;
	}

	/**
	 * Reopens a Solved report so replies can resume — usable by the
	 * reporter (logged in or via the same email-verification model as
	 * handle_reply()) or any agent/admin viewer.
	 *
	 * @since  1.4.6
	 * @return void  Terminates with wp_send_json_success()/error().
	 */
	public function handle_reopen_report() {
		if ( ! check_ajax_referer( self::REPLY_NONCE_ACTION, 'security', false ) ) {
			wp_send_json_error( __( 'Your session has expired. Please refresh the page and try again.', 'psbdx-smart-report-management' ) );
		}

		$report_id = absint( $_POST['report_id'] ?? 0 );
		$email     = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';

		if ( ! $report_id || 'psbdx_report_log' !== get_post_type( $report_id ) ) {
			wp_send_json_error( __( 'Invalid report.', 'psbdx-smart-report-management' ) );
		}

		if ( ! PSBDX_SRM_Replies::can_access_report( $report_id, $email ) ) {
			wp_send_json_error( __( 'You can only reopen your own reports.', 'psbdx-smart-report-management' ) );
		}

		if ( ! class_exists( 'PSBDX_SRM_Agents' ) || ! PSBDX_SRM_Agents::reopen_report( $report_id, get_current_user_id() ) ) {
			wp_send_json_error( __( 'This report isn\'t marked Solved.', 'psbdx-smart-report-management' ) );
		}

		wp_send_json_success( array(
			'message' => __( 'The report has been reopened.', 'psbdx-smart-report-management' ),
		) );
	}

	/**
	 * Pulls one file's info out of the nested $_FILES['psrm_v2'][...][handle]
	 * structure PHP produces for a file input named `psrm_v2[<handle>]`,
	 * into the flat shape wp_handle_upload() expects.
	 *
	 * @since  1.4.5
	 * @param  string $handle  Field handle.
	 * @return array{name:string,type:string,tmp_name:string,error:int,size:int}|null
	 */
	private function extract_v2_uploaded_file( $handle ) {
		if ( ! isset( $_FILES['psrm_v2']['name'][ $handle ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce already verified by the caller (handle()) before this method is ever reached; this only reads upload metadata.
			return null;
		}

		return array(
			'name'     => sanitize_file_name( wp_unslash( $_FILES['psrm_v2']['name'][ $handle ] ) ), // phpcs:ignore WordPress.Security.NonceVerification.Missing -- see above.
			'type'     => sanitize_text_field( wp_unslash( $_FILES['psrm_v2']['type'][ $handle ] ) ), // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotValidated -- see above; index existence is checked by the isset() this method starts with.
			// Server-generated tmp path — deliberately not run through
			// wp_unslash()/sanitizers, which could mangle it.
			'tmp_name' => $_FILES['psrm_v2']['tmp_name'][ $handle ], // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotValidated, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- see above; a server-generated path, not user input.
			'error'    => (int) $_FILES['psrm_v2']['error'][ $handle ], // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotValidated -- see above; cast to int.
			'size'     => (int) $_FILES['psrm_v2']['size'][ $handle ], // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotValidated -- see above; cast to int.
		);
	}

	/**
	 * Default allowed extensions and max size (KB) for a reply attachment
	 * — replies aren't tied to a specific form field, so unlike an
	 * Attachment-type field there's no per-field admin setting to pull
	 * these from.
	 *
	 * @since 1.4.5
	 * @var array|int
	 */
	const REPLY_ATTACHMENT_TYPES  = array( 'jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf' );
	const REPLY_ATTACHMENT_MAX_KB = 10240; // 10 MB.

	/**
	 * Pulls one file's info out of a plain (non-nested) $_FILES[$key] entry
	 * — used for the reply-attachment file input, which isn't nested under
	 * an array of field handles the way psrm_v2[...] fields are.
	 *
	 * @since  1.4.5
	 * @param  string $key  $_FILES key.
	 * @return array{name:string,type:string,tmp_name:string,error:int,size:int}|null
	 */
	private function extract_flat_uploaded_file( $key ) {
		if ( ! isset( $_FILES[ $key ]['name'] ) || '' === $_FILES[ $key ]['name'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce already verified by the caller (handle_reply()) before this method is ever reached; this only reads upload metadata.
			return null;
		}

		return array(
			'name'     => sanitize_file_name( wp_unslash( $_FILES[ $key ]['name'] ) ), // phpcs:ignore WordPress.Security.NonceVerification.Missing -- see above.
			'type'     => sanitize_text_field( wp_unslash( $_FILES[ $key ]['type'] ) ), // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotValidated -- see above; index existence is checked by the isset() this method starts with.
			'tmp_name' => $_FILES[ $key ]['tmp_name'], // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotValidated, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- server-generated path, not user input; see above.
			'error'    => (int) $_FILES[ $key ]['error'], // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotValidated -- see above; cast to int.
			'size'     => (int) $_FILES[ $key ]['size'], // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotValidated -- see above; cast to int.
		);
	}

	/**
	 * Validates one uploaded file (extension, size) and, if it passes,
	 * actually moves it into the uploads dir and creates its attachment
	 * post. Shared by the v2 Attachment field type and reply attachments
	 * (both here and in PSBDX_SRM_Meta_Boxes::ajax_add_admin_reply()) so
	 * the rules only live in one place.
	 *
	 * Terminates the request via wp_send_json_error() on failure, same as
	 * every other per-field validation in the submission handler — callers
	 * don't need their own error handling, just check the return value is
	 * a valid ID before using it.
	 *
	 * @since  1.4.5
	 * @param  array  $file           A file array in wp_handle_upload()'s expected shape.
	 * @param  string $label          Human-readable label for error messages.
	 * @param  array  $allowed_types  Lowercase extensions without the dot.
	 * @param  int    $min_kb         Minimum size in KB (0 = no minimum).
	 * @param  int    $max_kb         Maximum size in KB (0 = no maximum).
	 * @return int  New attachment post ID (post_parent is left at 0 — set it once you have a parent post to attach to).
	 */
	public static function validate_and_upload_file( array $file, $label, array $allowed_types, $min_kb = 0, $max_kb = 0 ) {
		if ( UPLOAD_ERR_OK !== $file['error'] ) {
			wp_send_json_error(
				/* translators: %s: field/context label */
				sprintf( __( 'The "%s" file failed to upload. Please try again.', 'psbdx-smart-report-management' ), $label )
			);
		}

		$ext = strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) );

		if ( ! empty( $allowed_types ) && ! in_array( $ext, $allowed_types, true ) ) {
			wp_send_json_error(
				sprintf(
					/* translators: 1: field/context label, 2: comma-separated allowed extensions */
					__( '"%1$s" only accepts these file types: %2$s.', 'psbdx-smart-report-management' ),
					$label,
					strtoupper( implode( ', ', $allowed_types ) )
				)
			);
		}

		if ( $min_kb > 0 && $file['size'] < $min_kb * 1024 ) {
			wp_send_json_error(
				sprintf(
					/* translators: 1: field/context label, 2: minimum size, human readable */
					__( 'The "%1$s" file is too small — it must be at least %2$s.', 'psbdx-smart-report-management' ),
					$label,
					size_format( $min_kb * 1024 )
				)
			);
		}
		if ( $max_kb > 0 && $file['size'] > $max_kb * 1024 ) {
			wp_send_json_error(
				sprintf(
					/* translators: 1: field/context label, 2: maximum size, human readable */
					__( 'The "%1$s" file is too large — it must be no more than %2$s.', 'psbdx-smart-report-management' ),
					$label,
					size_format( $max_kb * 1024 )
				)
			);
		}

		if ( ! function_exists( 'wp_handle_upload' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}

		$moved = wp_handle_upload( $file, array( 'test_form' => false ) );

		if ( ! is_array( $moved ) || isset( $moved['error'] ) ) {
			wp_send_json_error(
				sprintf(
					/* translators: 1: field/context label, 2: upload error message */
					__( 'The "%1$s" file could not be saved: %2$s', 'psbdx-smart-report-management' ),
					$label,
					isset( $moved['error'] ) ? $moved['error'] : __( 'unknown error', 'psbdx-smart-report-management' )
				)
			);
		}

		$attach_id = wp_insert_attachment(
			array(
				'post_mime_type' => $moved['type'],
				'post_title'     => sanitize_file_name( pathinfo( $moved['file'], PATHINFO_FILENAME ) ),
				'post_content'   => '',
				'post_status'    => 'inherit',
			),
			$moved['file']
		);

		if ( ! $attach_id || is_wp_error( $attach_id ) ) {
			wp_send_json_error(
				/* translators: %s: field/context label */
				sprintf( __( 'The "%s" file could not be saved. Please try again.', 'psbdx-smart-report-management' ), $label )
			);
		}

		wp_update_attachment_metadata( $attach_id, wp_generate_attachment_metadata( $attach_id, $moved['file'] ) );

		return (int) $attach_id;
	}

	/**
	 * Handle the AJAX report submission.
	 *
	 * @since  1.0.0
	 * @return void  Terminates with wp_send_json_success() or wp_send_json_error().
	 */
	public function handle() {
		// 1. Verify nonce.
		check_ajax_referer( self::NONCE_ACTION, 'security' );

		// 2. Collect and sanitize user input.
		$form_id      = absint( $_POST['form_id']      ?? 0 );
		$source_url   = esc_url_raw( wp_unslash( $_POST['source_url']   ?? '' ) );
		$source_title = sanitize_text_field( wp_unslash( $_POST['source_title'] ?? '' ) );
		$woo_order_id = absint( $_POST['woo_order_id'] ?? 0 );

		// Detect v2 vs v1 form.
		$form_version = (int) get_post_meta( $form_id, PSBDX_SRM_Form_Builder::VERSION_META_KEY, true );
		$is_v2        = ( $form_version >= PSBDX_SRM_Form_Builder::SCHEMA_VERSION );

		// V1-specific fields.
		$contact       = '';
		$reason        = '';
		$custom_reason = '';
		$details       = '';

		if ( ! $is_v2 ) {
			$contact       = sanitize_text_field( wp_unslash( $_POST['contact_value']  ?? '' ) );
			$reason        = sanitize_text_field( wp_unslash( $_POST['report_reason']  ?? '' ) );
			$custom_reason = sanitize_text_field( wp_unslash( $_POST['custom_reason']  ?? '' ) );
			$details       = sanitize_textarea_field( wp_unslash( $_POST['report_details'] ?? '' ) );
		}

		// 3. Basic required-field validation (v1 only; v2 validated per-schema below).
		if ( ! $form_id ) {
			wp_send_json_error(
				__( 'Please fill in all required fields.', 'psbdx-smart-report-management' )
			);
		}
		if ( ! $is_v2 && ( empty( $reason ) || empty( $details ) ) ) {
			wp_send_json_error(
				__( 'Please fill in all required fields.', 'psbdx-smart-report-management' )
			);
		}

		// 4. Validate form exists.
		if ( 'psbdx_report_form' !== get_post_type( $form_id ) ) {
			wp_send_json_error(
				__( 'Invalid form.', 'psbdx-smart-report-management' )
			);
		}

		// 4b. Captcha verification (if enabled on this form).
		$captcha_on_form = ( 'yes' === get_post_meta( $form_id, '_psbdx_captcha_enabled', true ) );
		$provider        = PSBDX_SRM_Captcha::active_provider();

		if ( $captcha_on_form && '' !== $provider ) {
			$response_field = PSBDX_SRM_Captcha::response_field( $provider );
			$token          = isset( $_POST[ $response_field ] ) ? sanitize_text_field( wp_unslash( $_POST[ $response_field ] ) ) : '';

			if ( ! PSBDX_SRM_Captcha::verify( $provider, $token ) ) {
				wp_send_json_error(
					__( 'Captcha verification failed. Please try again.', 'psbdx-smart-report-management' )
				);
			}
		}

		// 5. Validate required contact field.
		$contact_label = get_post_meta( $form_id, '_psbdx_contact_label',    true ) ?: __( 'WhatsApp Number', 'psbdx-smart-report-management' );
		$contact_req   = ( 'yes' === get_post_meta( $form_id, '_psbdx_contact_required', true ) );

		if ( $contact_req && empty( $contact ) ) {
			wp_send_json_error(
				sprintf(
					/* translators: %s: field label */
					__( 'The "%s" field is required.', 'psbdx-smart-report-management' ),
					$contact_label
				)
			);
		}

		// 6. Rate limiting — logged-in users only.
		$user         = wp_get_current_user();
		$is_logged_in = ( $user->ID > 0 );

		$cooldown_mins = PSBDX_SRM_Helpers::get_effective_cooldown_mins( $form_id );

		if ( $cooldown_mins > 0 && $is_logged_in ) {
			$transient_key = 'psbdx_cd_' . $user->ID . '_' . $form_id;

			if ( false !== get_transient( $transient_key ) ) {
				wp_send_json_error(
					__( 'You submitted a report too recently. Please wait before trying again.', 'psbdx-smart-report-management' )
				);
			}
		}

		// 7. Resolve identity server-side (never from POST data).
		$reporter_name  = $is_logged_in ? $user->display_name : __( 'Guest', 'psbdx-smart-report-management' );
		$reporter_email = $is_logged_in ? $user->user_email   : '';

		// 8. Build post title and content.
		$content_parts = array();
		$post_title    = '';

		if ( $reporter_email ) {
			$content_parts[] = '<strong>' . esc_html__( 'Email', 'psbdx-smart-report-management' ) . ':</strong> ' . esc_html( $reporter_email );
		}

		if ( $is_v2 ) {
			// ── V2: read and validate psrm_v2 fields against the stored schema ──
			$schema      = json_decode( get_post_meta( $form_id, PSBDX_SRM_Form_Builder::FIELDS_META_KEY, true ), true );
			$schema      = is_array( $schema ) ? $schema : array();
			$v2_parts    = array();
			$post_title  = $reporter_name;

			// Unslash the whole psrm_v2 array first; sanitize per-field below.
			$raw_v2 = isset( $_POST['psrm_v2'] ) && is_array( $_POST['psrm_v2'] )
				? wp_unslash( (array) $_POST['psrm_v2'] ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized field-by-field below.
				: array();

			// Attachment fields upload/validate inline below (see the
			// 'attachment' branch) and are linked to $log_id once it
			// exists, right after step 11 inserts the report post.
			$pending_attachments = array();

			foreach ( $schema as $field_def ) {
				$handle   = sanitize_key( $field_def['handle'] ?? '' );
				$label    = sanitize_text_field( $field_def['label'] ?? '' );
				$required = ! empty( $field_def['required'] );
				$type     = sanitize_key( $field_def['type'] ?? '' );

				if ( '' === $handle || 'captcha' === $type ) {
					continue;
				}

				$value = '';

				if ( 'name' === $type ) {
					$first = sanitize_text_field( $raw_v2[ $handle . '_first' ] ?? '' );
					$last  = sanitize_text_field( $raw_v2[ $handle . '_last' ]  ?? '' );
					$value = trim( $first . ' ' . $last );
					if ( $required && '' === trim( $first ) ) {
						wp_send_json_error(
							/* translators: %s: field label */
							sprintf( __( 'The "%s" first name field is required.', 'psbdx-smart-report-management' ), $label )
						);
					}
				} elseif ( 'checkbox' === $type ) {
					$raw_arr  = isset( $raw_v2[ $handle ] ) && is_array( $raw_v2[ $handle ] )
						? array_map( 'sanitize_text_field', $raw_v2[ $handle ] )
						: array();
					$other_v  = sanitize_text_field( $raw_v2[ $handle . '_other' ] ?? '' );
					if ( ( $key = array_search( '__other__', $raw_arr, true ) ) !== false ) {
						unset( $raw_arr[ $key ] );
						if ( '' !== $other_v ) {
							$raw_arr[] = esc_html__( 'Other', 'psbdx-smart-report-management' ) . ': ' . $other_v;
						}
					}
					$value = implode( ', ', $raw_arr );
					if ( $required && empty( $raw_arr ) ) {
						wp_send_json_error(
							sprintf(
								/* translators: %s: form field label, e.g. "Reason" */
								__( 'The "%s" field is required.', 'psbdx-smart-report-management' ),
								$label
							)
						);
					}
				} elseif ( 'attachment' === $type ) {
					$file = $this->extract_v2_uploaded_file( $handle );

					if ( ! $file || UPLOAD_ERR_NO_FILE === $file['error'] ) {
						if ( $required ) {
							wp_send_json_error(
								/* translators: %s: form field label */
								sprintf( __( 'The "%s" field is required.', 'psbdx-smart-report-management' ), $label )
							);
						}
					} else {
						$allowed_types = is_array( $field_def['allowed_types'] ?? null ) && ! empty( $field_def['allowed_types'] )
							? $field_def['allowed_types']
							: PSBDX_SRM_Form_Builder::ATTACHMENT_DEFAULT_TYPES;
						$min_kb = (int) ( $field_def['min_size_kb'] ?? 0 );
						$max_kb = (int) ( $field_def['max_size_kb'] ?? 5120 );

						// Terminates via wp_send_json_error() on any validation/upload
						// failure, same as every other field-type check in this loop.
						$attach_id = self::validate_and_upload_file( $file, $label, $allowed_types, $min_kb, $max_kb );

						if ( $attach_id ) {
							$pending_attachments[ $handle ] = $attach_id;
							$v2_parts[] = '<strong>' . esc_html( $label ) . ':</strong> '
								. '<a href="' . esc_url( wp_get_attachment_url( $attach_id ) ) . '" target="_blank" rel="noopener noreferrer">'
								. esc_html( basename( get_attached_file( $attach_id ) ) ) . '</a>';
						}
					}
				} elseif ( 'review' === $type ) {
					$max_stars = (int) ( $field_def['max_stars'] ?? 5 );
					$max_stars = $max_stars > 0 ? $max_stars : 5;
					$stars     = min( $max_stars, absint( $raw_v2[ $handle ] ?? 0 ) );

					if ( $required && $stars < 1 ) {
						wp_send_json_error(
							/* translators: %s: form field label */
							sprintf( __( 'The "%s" field is required.', 'psbdx-smart-report-management' ), $label )
						);
					}

					if ( $stars > 0 ) {
						$value = sprintf(
							/* translators: 1: chosen number of stars, 2: max possible stars */
							__( '%1$d out of %2$d stars', 'psbdx-smart-report-management' ),
							$stars,
							$max_stars
						);
					}
				} else {
					$raw_val = sanitize_text_field( $raw_v2[ $handle ] ?? '' );
					// Handle "Other" for select and radio.
					if ( '__other__' === $raw_val ) {
						$other_v = sanitize_text_field( $raw_v2[ $handle . '_other' ] ?? '' );
						$raw_val = '' !== $other_v
							? esc_html__( 'Other', 'psbdx-smart-report-management' ) . ': ' . $other_v
							: esc_html__( 'Other', 'psbdx-smart-report-management' );
					}
					$value = 'paragraph' === $type
						? sanitize_textarea_field( $raw_v2[ $handle ] ?? '' )
						: $raw_val;
					if ( $required && '' === trim( $value ) ) {
						wp_send_json_error(
							sprintf(
								/* translators: %s: form field label, e.g. "Email Address" */
								__( 'The "%s" field is required.', 'psbdx-smart-report-management' ),
								$label
							)
						);
					}
				}

				if ( '' !== trim( $value ) ) {
					$v2_parts[] = '<strong>' . esc_html( $label ) . ':</strong> '
						. ( 'paragraph' === $type ? '<br>' . nl2br( esc_html( $value ) ) : esc_html( $value ) );
				}
			}

			if ( $v2_parts ) {
				$content_parts[] = '<hr>' . implode( '<br>', $v2_parts );
			}

		} else {
			// ── V1 legacy content building ──────────────────────────────────────

			// Build final reason string (validate "Other" requires a specification).
			$other_label = __( 'Other', 'psbdx-smart-report-management' );
			if ( $other_label === $reason ) {
				if ( '' === trim( $custom_reason ) ) {
					wp_send_json_error(
						__( 'Please specify your reason when selecting "Other".', 'psbdx-smart-report-management' )
					);
				}
				$reason = sprintf(
					/* translators: %s: custom reason text */
					__( 'Other: %s', 'psbdx-smart-report-management' ),
					$custom_reason
				);
			}

			$contact_label = get_post_meta( $form_id, '_psbdx_contact_label', true ) ?: __( 'WhatsApp Number', 'psbdx-smart-report-management' );

			if ( $contact ) {
				$content_parts[] = '<strong>' . esc_html( $contact_label ) . ':</strong> ' . esc_html( $contact );
			}

			$content_parts[] = '<strong>' . esc_html__( 'Details', 'psbdx-smart-report-management' ) . ':</strong><br>' . nl2br( esc_html( $details ) );

			// Extra custom fields (only labels configured on this form).
			if ( isset( $_POST['psbdx_custom'] ) && is_array( $_POST['psbdx_custom'] ) ) {
				$extra_parts    = array();
				$allowed_labels = array_flip( PSBDX_SRM_Helpers::get_custom_fields( $form_id ) );
				$raw_custom     = wp_unslash( (array) $_POST['psbdx_custom'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized on the next line.
				$posted_custom  = array_map( 'sanitize_text_field', $raw_custom );

				foreach ( $posted_custom as $lbl => $val ) {
					$lbl = sanitize_text_field( $lbl );
					if ( '' === $lbl || ! isset( $allowed_labels[ $lbl ] ) ) {
						continue;
					}
					if ( is_array( $val ) ) {
						continue;
					}
					$extra_parts[] = '<strong>' . esc_html( $lbl ) . ':</strong> ' . esc_html( sanitize_text_field( $val ) );
				}

				if ( $extra_parts ) {
					$content_parts[] = '<hr><strong>' . esc_html__( 'Extra Info', 'psbdx-smart-report-management' ) . ':</strong><br>' . implode( '<br>', $extra_parts );
				}
			}

			$post_title = sanitize_text_field( $reporter_name . ' | ' . $reason );
		}

		// 9. Finalise post content.

		$post_content = implode( '<br>', $content_parts );

		// 10. Resolve WooCommerce order ID (with security validation).
		$validated_order_id = PSBDX_SRM_Helpers::resolve_woo_order_id(
			$woo_order_id,
			$source_url,
			$is_logged_in ? $user->ID : 0
		);

		// 11. Insert the report log post.
		$log_id = wp_insert_post( array(
			'post_type'    => 'psbdx_report_log',
			'post_title'   => $post_title,
			'post_content' => wp_kses_post( $post_content ),
			'post_status'  => 'publish',
			'post_author'  => $is_logged_in ? $user->ID : 0,
		), true );

		if ( is_wp_error( $log_id ) ) {
			wp_send_json_error(
				__( 'Failed to save the report. Please try again.', 'psbdx-smart-report-management' )
			);
		}

		// 11b. Now that the report post exists, attach any uploaded files
		// to it (they were uploaded during field validation above, before
		// this post existed) and record each under its field handle so an
		// admin or a future feature (CSV export, etc.) can look one up
		// directly instead of parsing it back out of post_content.
		if ( $is_v2 && ! empty( $pending_attachments ) ) {
			foreach ( $pending_attachments as $handle => $attach_id ) {
				wp_update_post( array( 'ID' => $attach_id, 'post_parent' => $log_id ) );
				update_post_meta( $log_id, '_psbdx_attachment_' . $handle, $attach_id );
			}
		}

		// Rate limit only after a successful save (avoids locking users out on failures).
		if ( $cooldown_mins > 0 && $is_logged_in ) {
			set_transient( 'psbdx_cd_' . $user->ID . '_' . $form_id, time(), $cooldown_mins * MINUTE_IN_SECONDS );
		}

		// 12. Save report meta.
		$ticket_id = PSBDX_SRM_Helpers::generate_ticket_id();
		update_post_meta( $log_id, PSBDX_SRM_Helpers::TICKET_ID_META, $ticket_id );
		update_post_meta( $log_id, '_psbdx_source_url',     $source_url );
		update_post_meta( $log_id, '_psbdx_source_title',   $source_title );
		update_post_meta( $log_id, '_psbdx_reporter_email', $reporter_email );
		update_post_meta( $log_id, PSBDX_SRM_Replies::SOURCE_FORM_META, $form_id );
		PSBDX_SRM_Helpers::update_report_status( $log_id, 'Processing', array( 'source' => 'submission' ) );

		if ( $validated_order_id ) {
			update_post_meta( $log_id, '_psbdx_woo_order_id', $validated_order_id );
		}

		/**
		 * Fires after a report log has been created and its core meta saved.
		 *
		 * PSBDX_SRM_AI hooks in here to (optionally) suggest a category and
		 * priority, and to generate an automatic reply. Both can involve a
		 * slow, blocking call to an external AI provider (and, for the
		 * auto-reply, an extra page fetch first) — so the success response
		 * below is sent to the browser first. Where the host supports
		 * fastcgi_finish_request() this fires immediately afterward, in the
		 * same request, with the connection already closed out. Where it
		 * doesn't, running it here would still leave the visitor's browser
		 * waiting on the AI calls to finish — so instead it's handed off to
		 * a separate WP-Cron request via defer_work(), which is what
		 * actually decouples report submission from AI/email latency on
		 * every host, not just PHP-FPM ones.
		 *
		 * @since 1.4.1
		 * @param int $log_id Newly created report log post ID.
		 */
		$finished = $this->respond_then_continue(
			array(
				'message'   => __( 'Your report has been submitted successfully. Thank you!', 'psbdx-smart-report-management' ),
				'ticket_id' => $ticket_id,
			)
		);

		if ( $finished ) {
			do_action( 'psbdx_srm_report_submitted', $log_id );
		} else {
			$this->defer_work( 'submission', $log_id );
		}

		exit;
	}

	// =========================================================================
	// AGENT PORTAL — [psbdx_user_reports] tabs for support agents/admins
	// =========================================================================

	/**
	 * Verifies the shared agent-portal nonce and that the current user is a
	 * logged-in agent or admin, terminating the request otherwise.
	 *
	 * @since  1.4.5
	 * @return int  The current user's WP user ID (guaranteed non-zero if this returns).
	 */
	private function require_agent() {
		check_ajax_referer( self::AGENT_NONCE_ACTION, 'nonce' );

		$user_id = get_current_user_id();

		if ( ! $user_id || ! PSBDX_SRM_Agents::is_agent_or_admin( $user_id ) ) {
			wp_send_json_error( __( 'Not allowed.', 'psbdx-smart-report-management' ) );
		}

		return $user_id;
	}

	/**
	 * Validates a report ID from $_POST and confirms it's a real report.
	 *
	 * @since  1.4.5
	 * @return int  Report log post ID (terminates on failure).
	 */
	private function require_report() {
		$report_id = isset( $_POST['report_id'] ) ? (int) $_POST['report_id'] : 0;

		if ( ! $report_id || 'psbdx_report_log' !== get_post_type( $report_id ) ) {
			wp_send_json_error( __( 'Invalid report.', 'psbdx-smart-report-management' ) );
		}

		return $report_id;
	}

	/**
	 * Agent posts a reply into a report's thread. Only the currently
	 * assigned agent (or an Administrator) can reply — an agent who merely
	 * viewed the ticket via Search can't send a message, per design.
	 *
	 * @since  1.4.5
	 * @return void  Terminates with wp_send_json_success()/error().
	 */
	public function handle_agent_reply() {
		$user_id   = $this->require_agent();
		$report_id = $this->require_report();

		$assigned = PSBDX_SRM_Agents::get_assigned_agent( $report_id );
		$is_admin = PSBDX_SRM_Agents::is_admin_role( $user_id );

		if ( $assigned !== $user_id && ! $is_admin ) {
			wp_send_json_error( __( 'This report isn\'t assigned to you.', 'psbdx-smart-report-management' ) );
		}

		if ( ! PSBDX_SRM_Replies::replies_allowed( $report_id ) ) {
			wp_send_json_error( __( 'Replies are not enabled for this report.', 'psbdx-smart-report-management' ) );
		}

		if ( PSBDX_SRM_Agents::is_report_locked( $report_id ) ) {
			wp_send_json_error( __( 'This report is marked Solved. Reopen it to send another message.', 'psbdx-smart-report-management' ) );
		}

		$message = isset( $_POST['message'] ) ? sanitize_textarea_field( wp_unslash( $_POST['message'] ) ) : '';

		$attachment_id = 0;
		$file          = $this->extract_flat_uploaded_file( 'reply_attachment' );

		if ( $file && UPLOAD_ERR_NO_FILE !== $file['error'] ) {
			$attachment_id = self::validate_and_upload_file(
				$file,
				__( 'Attachment', 'psbdx-smart-report-management' ),
				self::REPLY_ATTACHMENT_TYPES,
				0,
				self::REPLY_ATTACHMENT_MAX_KB
			);
		}

		if ( '' === $message && ! $attachment_id ) {
			wp_send_json_error( __( 'Please write a message or attach a file before sending.', 'psbdx-smart-report-management' ) );
		}

		$user     = get_userdata( $user_id );
		$reply_id = PSBDX_SRM_Replies::add_reply( $report_id, 'admin', $user_id, $user ? $user->display_name : '', $message, false, $attachment_id );

		if ( ! $reply_id ) {
			wp_send_json_error( __( 'Failed to save your reply. Please try again.', 'psbdx-smart-report-management' ) );
		}

		PSBDX_SRM_Agents::log_action( $report_id, $user_id, 'replied' );

		wp_send_json_success( array(
			'thread_html' => PSBDX_SRM_Shortcodes::render_thread_html( $report_id, false, true ),
		) );
	}

	/**
	 * Agent abandons a report they're currently assigned to.
	 *
	 * @since  1.4.5
	 * @return void
	 */
	public function handle_agent_abandon() {
		$user_id   = $this->require_agent();
		$report_id = $this->require_report();

		if ( ! PSBDX_SRM_Agents::abandon_report( $report_id, $user_id ) ) {
			wp_send_json_error( __( 'This report isn\'t assigned to you.', 'psbdx-smart-report-management' ) );
		}

		wp_send_json_success();
	}

	/**
	 * Agent claims an unassigned report from the Search Ticket tab.
	 *
	 * @since  1.4.5
	 * @return void
	 */
	public function handle_agent_claim() {
		$user_id   = $this->require_agent();
		$report_id = $this->require_report();

		if ( ! PSBDX_SRM_Agents::claim_report( $report_id, $user_id ) ) {
			wp_send_json_error( __( 'This report is already assigned.', 'psbdx-smart-report-management' ) );
		}

		wp_send_json_success();
	}

	/**
	 * Agent (assigned, or any Administrator) changes a report's status.
	 *
	 * @since  1.4.5
	 * @return void
	 */
	public function handle_agent_change_status() {
		$user_id   = $this->require_agent();
		$report_id = $this->require_report();

		$assigned = PSBDX_SRM_Agents::get_assigned_agent( $report_id );
		if ( $assigned !== $user_id && ! PSBDX_SRM_Agents::is_admin_role( $user_id ) ) {
			wp_send_json_error( __( 'This report isn\'t assigned to you.', 'psbdx-smart-report-management' ) );
		}

		$status = isset( $_POST['status'] ) ? sanitize_text_field( wp_unslash( $_POST['status'] ) ) : '';

		if ( ! PSBDX_SRM_Helpers::is_valid_status_key( $status ) ) {
			wp_send_json_error( __( 'Invalid status.', 'psbdx-smart-report-management' ) );
		}

		PSBDX_SRM_Helpers::update_report_status( $report_id, $status, array( 'source' => 'agent' ) );
		PSBDX_SRM_Agents::log_action( $report_id, $user_id, 'status_changed', array( 'status' => $status ) );

		wp_send_json_success();
	}

	/**
	 * Agent requests that the currently assigned agent hand a report over.
	 *
	 * @since  1.4.5
	 * @return void
	 */
	public function handle_agent_request_handover() {
		$user_id   = $this->require_agent();
		$report_id = $this->require_report();

		$request_id = PSBDX_SRM_Agents::request_handover( $report_id, $user_id );

		if ( ! $request_id ) {
			wp_send_json_error( __( 'A handover can\'t be requested for this report right now.', 'psbdx-smart-report-management' ) );
		}

		wp_send_json_success();
	}

	/**
	 * The currently assigned agent offers the report to a specific other
	 * agent (from the report detail page's "request other agents to
	 * manage this report" control).
	 *
	 * @since  1.4.5
	 * @return void
	 */
	public function handle_agent_offer_handover() {
		$user_id   = $this->require_agent();
		$report_id = $this->require_report();
		$target_id = isset( $_POST['target_agent_id'] ) ? (int) $_POST['target_agent_id'] : 0;

		if ( ! $target_id || ! PSBDX_SRM_Agents::offer_handover( $report_id, $user_id, $target_id ) ) {
			wp_send_json_error( __( 'Unable to offer this report right now.', 'psbdx-smart-report-management' ) );
		}

		wp_send_json_success();
	}

	/**
	 * The current holder accepts or declines a pending handover request.
	 *
	 * @since  1.4.5
	 * @return void
	 */
	public function handle_agent_respond_handover() {
		$user_id    = $this->require_agent();
		$request_id = isset( $_POST['request_id'] ) ? (int) $_POST['request_id'] : 0;
		$accept     = ! empty( $_POST['accept'] );

		if ( ! $request_id || ! PSBDX_SRM_Agents::respond_handover( $request_id, $accept, $user_id ) ) {
			wp_send_json_error( __( 'Unable to respond to this request.', 'psbdx-smart-report-management' ) );
		}

		wp_send_json_success();
	}

	/**
	 * The requester cancels their own pending handover request.
	 *
	 * @since  1.4.5
	 * @return void
	 */
	public function handle_agent_cancel_handover() {
		$user_id    = $this->require_agent();
		$request_id = isset( $_POST['request_id'] ) ? (int) $_POST['request_id'] : 0;

		if ( ! $request_id || ! PSBDX_SRM_Agents::cancel_handover( $request_id, $user_id ) ) {
			wp_send_json_error( __( 'Unable to cancel this request.', 'psbdx-smart-report-management' ) );
		}

		wp_send_json_success();
	}

	/**
	 * Agent deletes an attachment shared in a report's reply thread.
	 *
	 * @since  1.4.5
	 * @return void
	 */
	public function handle_agent_delete_attachment() {
		$user_id       = $this->require_agent();
		$report_id     = $this->require_report();
		$attachment_id = isset( $_POST['attachment_id'] ) ? (int) $_POST['attachment_id'] : 0;

		$assigned = PSBDX_SRM_Agents::get_assigned_agent( $report_id );
		if ( $assigned !== $user_id && ! PSBDX_SRM_Agents::is_admin_role( $user_id ) ) {
			wp_send_json_error( __( 'This report isn\'t assigned to you.', 'psbdx-smart-report-management' ) );
		}

		if ( ! $attachment_id || ! PSBDX_SRM_Agents::delete_chat_attachment( $report_id, $attachment_id, $user_id ) ) {
			wp_send_json_error( __( 'Unable to delete that attachment.', 'psbdx-smart-report-management' ) );
		}

		wp_send_json_success( array(
			'thread_html' => PSBDX_SRM_Shortcodes::render_thread_html( $report_id, false, true ),
		) );
	}

	/**
	 * Agent searches for a report by ticket ID (Search Ticket tab).
	 *
	 * @since  1.4.5
	 * @return void
	 */
	public function handle_agent_search() {
		$this->require_agent();

		$query     = isset( $_POST['query'] ) ? sanitize_text_field( wp_unslash( $_POST['query'] ) ) : '';
		$report_id = PSBDX_SRM_Agents::search_report( $query );

		if ( ! $report_id ) {
			wp_send_json_error( __( 'No report found with that ticket ID.', 'psbdx-smart-report-management' ) );
		}

		wp_send_json_success( array(
			'url' => PSBDX_SRM_Report_Page::get_url( $report_id ),
		) );
	}

	/**
	 * Frontend "Manage Agents" tab — mirrors the wp-admin Support Agents
	 * page's add/remove/save-hours/toggle-super actions for Administrators
	 * and Super Administrators who'd rather not leave the frontend.
	 *
	 * @since  1.4.5
	 * @return void
	 */
	public function handle_agent_manage() {
		check_ajax_referer( self::AGENT_NONCE_ACTION, 'nonce' );

		$actor_id = get_current_user_id();

		if ( ! $actor_id || ! PSBDX_SRM_Agents::can_view_manage_tab( $actor_id ) ) {
			wp_send_json_error( __( 'Not allowed.', 'psbdx-smart-report-management' ) );
		}

		$sub_action = isset( $_POST['sub_action'] ) ? sanitize_key( wp_unslash( $_POST['sub_action'] ) ) : '';

		switch ( $sub_action ) {

			case 'add':
				$identifier = isset( $_POST['identifier'] ) ? sanitize_text_field( wp_unslash( $_POST['identifier'] ) ) : '';
				$user       = is_email( $identifier ) ? get_user_by( 'email', $identifier ) : get_user_by( 'login', $identifier );

				if ( ! $user ) {
					wp_send_json_error( __( 'No user found with that username or email.', 'psbdx-smart-report-management' ) );
				}

				PSBDX_SRM_Agents::add_agent( $user->ID, $actor_id );
				wp_send_json_success();
				break;

			case 'remove':
				$target_id = isset( $_POST['user_id'] ) ? (int) $_POST['user_id'] : 0;

				if ( ! PSBDX_SRM_Agents::can_manage_target( $actor_id, $target_id ) ) {
					wp_send_json_error( __( 'Only a Super Administrator can manage another administrator.', 'psbdx-smart-report-management' ) );
				}

				PSBDX_SRM_Agents::remove_agent( $target_id );
				wp_send_json_success();
				break;

			case 'save_hours':
				$target_id = isset( $_POST['user_id'] ) ? (int) $_POST['user_id'] : 0;

				if ( ! PSBDX_SRM_Agents::can_manage_target( $actor_id, $target_id ) ) {
					wp_send_json_error( __( 'You are not allowed to edit this agent\'s hours.', 'psbdx-smart-report-management' ) );
				}

				$hours = array();
				for ( $d = 0; $d <= 6; $d++ ) {
					$hours[ $d ] = array(
						'enabled' => ! empty( $_POST[ 'enabled_' . $d ] ),
						'start'   => isset( $_POST[ 'start_' . $d ] ) ? sanitize_text_field( wp_unslash( $_POST[ 'start_' . $d ] ) ) : '09:00',
						'end'     => isset( $_POST[ 'end_' . $d ] ) ? sanitize_text_field( wp_unslash( $_POST[ 'end_' . $d ] ) ) : '18:00',
					);
				}

				PSBDX_SRM_Agents::set_work_hours( $target_id, $hours );
				wp_send_json_success();
				break;

			case 'toggle_super':
				if ( ! PSBDX_SRM_Agents::is_super_admin( $actor_id ) ) {
					wp_send_json_error( __( 'Only a Super Administrator can promote or demote other administrators.', 'psbdx-smart-report-management' ) );
				}

				$target_id = isset( $_POST['user_id'] ) ? (int) $_POST['user_id'] : 0;

				if ( PSBDX_SRM_Agents::is_super_admin( $target_id ) ) {
					PSBDX_SRM_Agents::remove_super_admin( $target_id );
				} else {
					PSBDX_SRM_Agents::add_super_admin( $target_id );
				}

				wp_send_json_success();
				break;

			default:
				wp_send_json_error( __( 'Unknown action.', 'psbdx-smart-report-management' ) );
		}
	}
}
