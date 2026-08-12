<?php
/**
 * Email notifications for PSBDx Smart Report Management.
 *
 * Every notification the plugin sends — new report received, AI errors,
 * reply notifications, and the reporter's submission confirmation — is a
 * template an admin can fully edit (subject + HTML body) and individually
 * enable or disable, under Settings → Email.
 *
 * @package PSBDx_Smart_Report_Management
 * @since   1.4.2
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class PSBDX_SRM_Emails
 *
 * @since 1.4.2
 */
class PSBDX_SRM_Emails {

	/**
	 * Option key storing all template overrides (subject/body/enabled per event).
	 * Only overridden fields are stored; anything missing falls back to the
	 * built-in default from get_events().
	 *
	 * @since 1.4.2
	 * @var string
	 */
	const OPTION = 'psbdx_srm_email_templates';

	/**
	 * Option key storing the sender name/email override. Either half can be
	 * empty, in which case that half falls back to WordPress's own default
	 * (the site title, and wordpress@{host}).
	 *
	 * @since 1.4.3
	 * @var string
	 */
	const SENDER_OPTION = 'psbdx_srm_email_sender';

	/**
	 * Option key: whether a reply's shared file should be physically
	 * attached to the notification email. 'yes'/'no' — 'no' by default,
	 * since sending real attachments over email affects deliverability
	 * (spam scoring, size limits) more than an admin may realize. When
	 * off, the {reply_attachment} placeholder resolves to a plain
	 * "Attachment" indicator instead of a real link or file.
	 *
	 * @since 1.4.5
	 * @var string
	 */
	const ATTACH_FILES_OPTION = 'psbdx_srm_email_attach_files';

	/**
	 * Constructor.
	 *
	 * @since 1.4.2
	 */
	public function __construct() {
		add_action( 'psbdx_srm_report_submitted', array( __CLASS__, 'notify_new_report' ), 30 );
		add_action( 'psbdx_srm_reply_added',       array( __CLASS__, 'notify_reply' ), 10, 3 );
		add_action( 'psbdx_srm_ai_error',          array( __CLASS__, 'notify_ai_error' ), 10, 2 );
	}

	/**
	 * Whether reply attachments should be physically attached to
	 * notification emails (site-wide setting, Settings → Email).
	 *
	 * @since  1.4.5
	 * @return bool
	 */
	public static function attach_files_enabled() {
		return ( 'yes' === get_option( self::ATTACH_FILES_OPTION, 'no' ) );
	}

	// =========================================================================
	// SENDER (From name / From email)
	// =========================================================================

	/**
	 * Gets the saved sender override.
	 *
	 * @since 1.4.3
	 * @return array{name: string, email: string}
	 */
	public static function get_sender() {
		$saved = get_option( self::SENDER_OPTION, array() );

		return array(
			'name'  => isset( $saved['name'] ) ? (string) $saved['name'] : '',
			'email' => isset( $saved['email'] ) ? (string) $saved['email'] : '',
		);
	}

	/**
	 * Saves the sender name/email override. Either field can be left blank
	 * to fall back to WordPress's default for that half.
	 *
	 * @since 1.4.3
	 * @param  string $name   Sender ("From") display name, or '' for the default.
	 * @param  string $email  Sender ("From") email address, or '' for the default.
	 * @return void
	 */
	public static function save_sender( $name, $email ) {
		$email = sanitize_email( (string) $email );

		update_option(
			self::SENDER_OPTION,
			array(
				'name'  => sanitize_text_field( (string) $name ),
				'email' => is_email( $email ) ? $email : '',
			),
			false
		);
	}

	/**
	 * Forces the From name for the duration of a single wp_mail() call —
	 * added right before sending and removed right after, so it can never
	 * leak into some other plugin's or WordPress core's own mail sent
	 * later in the same request.
	 *
	 * @since 1.4.3
	 * @return string
	 */
	public static function filter_from_name() {
		return self::get_sender()['name'];
	}

	/**
	 * Forces the From email for the duration of a single wp_mail() call.
	 * Same leak-avoidance reasoning as filter_from_name().
	 *
	 * Note: some hosts/SMTP setups will still show their own authenticated
	 * mailbox as the envelope sender regardless of this header, since not
	 * every mail transport honors an arbitrary From address.
	 *
	 * @since 1.4.3
	 * @return string
	 */
	public static function filter_from_email() {
		return self::get_sender()['email'];
	}

	// =========================================================================
	// EVENT DEFINITIONS
	// =========================================================================

	/**
	 * All email events the plugin can send, with their defaults and the
	 * placeholders available to each — shown to the admin under Settings →
	 * Email so they know what they can use in a given template.
	 *
	 * @since 1.4.2
	 * @return array
	 */
	public static function get_events() {
		static $events = null;

		if ( null !== $events ) {
			return $events;
		}

		$common_placeholders = array( '{site_name}', '{site_url}', '{ticket_id}', '{report_title}', '{report_status}', '{report_category}', '{report_priority}', '{reporter_name}', '{reporter_email}', '{source_title}', '{source_url}', '{admin_report_url}', '{report_view_url}', '{date}', '{time}' );

		$events = array(
			'new_report'          => array(
				'label'           => __( 'New Report Received (to Admin)', 'psbdx-smart-report-management' ),
				'description'     => __( 'Sent to the site admin email whenever a new report is submitted.', 'psbdx-smart-report-management' ),
				'default_enabled' => true,
				'default_subject' => __( 'New report received — {ticket_id}', 'psbdx-smart-report-management' ),
				'default_body'    => "<p>" . __( 'A new report has just come in on {site_name}.', 'psbdx-smart-report-management' ) . "</p>\n<p><strong>" . __( 'Ticket:', 'psbdx-smart-report-management' ) . "</strong> {ticket_id}<br>\n<strong>" . __( 'From:', 'psbdx-smart-report-management' ) . "</strong> {reporter_name} ({reporter_email})<br>\n<strong>" . __( 'Reported from:', 'psbdx-smart-report-management' ) . "</strong> <a href=\"{source_url}\">{source_title}</a></p>\n<p><strong>{report_title}</strong></p>\n<p><a href=\"{admin_report_url}\">" . __( 'View & respond to this report', 'psbdx-smart-report-management' ) . "</a></p>",
				'placeholders'    => $common_placeholders,
			),
			'report_confirmation' => array(
				'label'           => __( 'Report Confirmation (to Reporter)', 'psbdx-smart-report-management' ),
				'description'     => __( 'Sent to the reporter\'s email right after they submit a report. Only sent if an email address was collected.', 'psbdx-smart-report-management' ),
				'default_enabled' => true,
				'default_subject' => __( 'We received your report — {ticket_id}', 'psbdx-smart-report-management' ),
				'default_body'    => "<p>" . __( 'Hi {reporter_name},', 'psbdx-smart-report-management' ) . "</p>\n<p>" . __( 'Thanks for letting us know. We\'ve received your report and will get back to you soon.', 'psbdx-smart-report-management' ) . "</p>\n<p><strong>" . __( 'Your ticket ID:', 'psbdx-smart-report-management' ) . "</strong> {ticket_id}</p>\n<p><a href=\"{report_view_url}\">" . __( 'View your report and reply', 'psbdx-smart-report-management' ) . "</a></p>\n<p>&mdash; {site_name}</p>",
				'placeholders'    => $common_placeholders,
			),
			'ai_error'            => array(
				'label'           => __( 'AI Error (to Admin)', 'psbdx-smart-report-management' ),
				'description'     => __( 'Sent to the site admin email whenever an automated AI action (classification or auto-reply) fails while AI features are enabled.', 'psbdx-smart-report-management' ),
				'default_enabled' => true,
				'default_subject' => __( 'AI error on {site_name}', 'psbdx-smart-report-management' ),
				'default_body'    => "<p>" . __( 'An automated AI action failed on {site_name}.', 'psbdx-smart-report-management' ) . "</p>\n<p><strong>" . __( 'Action:', 'psbdx-smart-report-management' ) . "</strong> {ai_action}<br>\n<strong>" . __( 'Ticket:', 'psbdx-smart-report-management' ) . "</strong> {ticket_id}<br>\n<strong>" . __( 'Error:', 'psbdx-smart-report-management' ) . "</strong> {ai_error_message}</p>\n<p><a href=\"{admin_report_url}\">" . __( 'View the report', 'psbdx-smart-report-management' ) . "</a> &middot; <a href=\"{ai_log_url}\">" . __( 'View the AI Response Log', 'psbdx-smart-report-management' ) . "</a></p>",
				'placeholders'    => array_merge( $common_placeholders, array( '{ai_action}', '{ai_error_message}', '{ai_log_url}' ) ),
			),
			'reply_to_admin'      => array(
				'label'           => __( 'New Reply (to Admin)', 'psbdx-smart-report-management' ),
				'description'     => __( 'Sent to the site admin email when the reporter posts a follow-up reply.', 'psbdx-smart-report-management' ),
				'default_enabled' => true,
				'default_subject' => __( 'New reply on {ticket_id}', 'psbdx-smart-report-management' ),
				'default_body'    => "<p>" . __( '{reply_author_name} replied on ticket {ticket_id}:', 'psbdx-smart-report-management' ) . "</p>\n<blockquote>{reply_message}</blockquote>\n{reply_attachment}\n<p><a href=\"{admin_report_url}\">" . __( 'View & reply', 'psbdx-smart-report-management' ) . "</a></p>",
				'placeholders'    => array_merge( $common_placeholders, array( '{reply_author_name}', '{reply_message}', '{reply_attachment}' ) ),
			),
			'reply_to_user'       => array(
				'label'           => __( 'New Reply (to Reporter)', 'psbdx-smart-report-management' ),
				'description'     => __( 'Sent to the reporter\'s email when an admin — or AI, if enabled — replies to their report.', 'psbdx-smart-report-management' ),
				'default_enabled' => true,
				'default_subject' => __( 'You have a new reply — {ticket_id}', 'psbdx-smart-report-management' ),
				'default_body'    => "<p>" . __( 'Hi {reporter_name},', 'psbdx-smart-report-management' ) . "</p>\n<p>" . __( 'There\'s a new reply on your report ({ticket_id}):', 'psbdx-smart-report-management' ) . "</p>\n<blockquote>{reply_message}</blockquote>\n{reply_attachment}\n<p><a href=\"{report_view_url}\">" . __( 'View & reply', 'psbdx-smart-report-management' ) . "</a></p>\n<p>&mdash; {site_name}</p>",
				'placeholders'    => array_merge( $common_placeholders, array( '{reply_author_name}', '{reply_message}', '{reply_attachment}' ) ),
			),
			'agent_assigned'      => array(
				'label'           => __( 'Report Assigned (to Agent)', 'psbdx-smart-report-management' ),
				'description'     => __( 'Sent to a support agent when a report is assigned to them (automatically, by handover, or manually by an admin).', 'psbdx-smart-report-management' ),
				'default_enabled' => true,
				'default_subject' => __( 'A report has been assigned to you — {ticket_id}', 'psbdx-smart-report-management' ),
				'default_body'    => "<p>" . __( 'Hi {agent_name},', 'psbdx-smart-report-management' ) . "</p>\n<p>" . __( 'A report has been assigned to you on {site_name}.', 'psbdx-smart-report-management' ) . "</p>\n<p><strong>" . __( 'Ticket:', 'psbdx-smart-report-management' ) . "</strong> {ticket_id}<br>\n<strong>{report_title}</strong></p>\n<p><a href=\"{admin_report_url}\">" . __( 'View & respond to this report', 'psbdx-smart-report-management' ) . "</a></p>",
				'placeholders'    => array_merge( $common_placeholders, array( '{agent_name}' ) ),
			),
			'agent_assigned'      => array(
				'label'           => __( 'Report Assigned (to Agent)', 'psbdx-smart-report-management' ),
				'description'     => __( 'Sent to the specific support agent a report was automatically or manually assigned to.', 'psbdx-smart-report-management' ),
				'default_enabled' => true,
				'default_subject' => __( 'A report has been assigned to you — {ticket_id}', 'psbdx-smart-report-management' ),
				'default_body'    => "<p>" . __( 'Hi {agent_name},', 'psbdx-smart-report-management' ) . "</p>\n<p>" . __( 'A report has been assigned to you on {site_name}.', 'psbdx-smart-report-management' ) . "</p>\n<p><strong>" . __( 'Ticket:', 'psbdx-smart-report-management' ) . "</strong> {ticket_id}<br>\n<strong>" . __( 'From:', 'psbdx-smart-report-management' ) . "</strong> {reporter_name} ({reporter_email})</p>\n<p><strong>{report_title}</strong></p>\n<p><a href=\"{admin_report_url}\">" . __( 'View & respond to this report', 'psbdx-smart-report-management' ) . "</a></p>",
				'placeholders'    => array_merge( $common_placeholders, array( '{agent_name}' ) ),
			),
		);

		return $events;
	}

	/**
	 * Whether a given event key is valid.
	 *
	 * @since 1.4.2
	 * @param  string $key  Event key.
	 * @return bool
	 */
	public static function is_valid_event( $key ) {
		return isset( self::get_events()[ $key ] );
	}

	/**
	 * Gets the effective template (saved override merged over defaults) for
	 * an event.
	 *
	 * @since 1.4.2
	 * @param  string $key  Event key.
	 * @return array{enabled: bool, subject: string, body: string}
	 */
	public static function get_template( $key ) {
		$events = self::get_events();

		if ( ! isset( $events[ $key ] ) ) {
			return array(
				'enabled' => false,
				'subject' => '',
				'body'    => '',
			);
		}

		$defaults = $events[ $key ];
		$saved    = get_option( self::OPTION, array() );
		$override = isset( $saved[ $key ] ) && is_array( $saved[ $key ] ) ? $saved[ $key ] : array();

		return array(
			'enabled' => isset( $override['enabled'] ) ? (bool) $override['enabled'] : (bool) $defaults['default_enabled'],
			'subject' => isset( $override['subject'] ) && '' !== $override['subject'] ? $override['subject'] : $defaults['default_subject'],
			'body'    => isset( $override['body'] ) && '' !== $override['body'] ? $override['body'] : $defaults['default_body'],
		);
	}

	/**
	 * Whether a given event's email is currently turned on.
	 *
	 * @since 1.4.2
	 * @param  string $key  Event key.
	 * @return bool
	 */
	public static function is_enabled( $key ) {
		return self::get_template( $key )['enabled'];
	}

	/**
	 * Saves admin-submitted template overrides. HTML in the body is allowed
	 * (run through wp_kses_post, which permits the common safe tags/attributes
	 * used in email bodies — paragraphs, links, formatting, images, etc.).
	 *
	 * @since 1.4.2
	 * @param  array $posted  Raw $_POST['psbdx_email'][$event_key] => array( enabled, subject, body ).
	 * @return void
	 */
	public static function save_templates( array $posted ) {
		$clean = array();

		foreach ( self::get_events() as $key => $defaults ) {
			$row = isset( $posted[ $key ] ) && is_array( $posted[ $key ] ) ? $posted[ $key ] : array();

			$clean[ $key ] = array(
				'enabled' => ! empty( $row['enabled'] ),
				'subject' => isset( $row['subject'] ) ? sanitize_text_field( wp_unslash( $row['subject'] ) ) : '',
				'body'    => isset( $row['body'] ) ? wp_kses_post( wp_unslash( $row['body'] ) ) : '',
			);
		}

		update_option( self::OPTION, $clean, false );
	}

	// =========================================================================
	// SENDING
	// =========================================================================

	/**
	 * Substitutes {placeholder} tokens in a string.
	 *
	 * @since 1.4.2
	 * @param  string $text          Subject or body text.
	 * @param  array  $placeholders  Map of '{token}' => value.
	 * @return string
	 */
	private static function render( $text, array $placeholders ) {
		return strtr( (string) $text, $placeholders );
	}

	/**
	 * Sends an event email if it's enabled and a destination address is available.
	 *
	 * @since 1.4.2
	 * @param  string $event_key     One of get_events()'s keys.
	 * @param  string $to            Destination email address.
	 * @param  array  $placeholders  Map of '{token}' => value for this send.
	 * @param  array  $attachments   Optional absolute file paths to physically attach.
	 * @return bool  Whether wp_mail() reported success.
	 */
	public static function send( $event_key, $to, array $placeholders, array $attachments = array() ) {
		if ( ! self::is_valid_event( $event_key ) || ! self::is_enabled( $event_key ) ) {
			return false;
		}

		$to = sanitize_email( $to );

		if ( '' === $to || ! is_email( $to ) ) {
			return false;
		}

		$tpl = self::get_template( $event_key );

		$subject = wp_specialchars_decode( self::render( $tpl['subject'], $placeholders ), ENT_QUOTES );
		$body    = self::render( $tpl['body'], $placeholders );

		$headers = array( 'Content-Type: text/html; charset=UTF-8' );

		$sender = self::get_sender();
		if ( '' !== $sender['name'] ) {
			add_filter( 'wp_mail_from_name', array( __CLASS__, 'filter_from_name' ) );
		}
		if ( '' !== $sender['email'] ) {
			add_filter( 'wp_mail_from', array( __CLASS__, 'filter_from_email' ) );
		}

		$sent = wp_mail( $to, $subject, $body, $headers, $attachments );

		remove_filter( 'wp_mail_from_name', array( __CLASS__, 'filter_from_name' ) );
		remove_filter( 'wp_mail_from', array( __CLASS__, 'filter_from_email' ) );

		return $sent;
	}

	/**
	 * Builds the common placeholder set for a report.
	 *
	 * @since 1.4.2
	 * @param  int $report_id  Report log post ID.
	 * @return array
	 */
	public static function build_report_placeholders( $report_id ) {
		$post = get_post( $report_id );

		$reporter_email = get_post_meta( $report_id, '_psbdx_reporter_email', true );
		$author         = $post ? get_userdata( $post->post_author ) : false;
		$reporter_name  = $author ? $author->display_name : __( 'Guest', 'psbdx-smart-report-management' );

		$statuses = PSBDX_SRM_Helpers::get_statuses();
		$status   = get_post_meta( $report_id, '_psbdx_report_status', true ) ?: 'Processing';
		$status   = isset( $statuses[ $status ] ) ? $statuses[ $status ]['label'] : $status;

		return array(
			'{site_name}'        => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
			'{site_url}'         => home_url( '/' ),
			'{ticket_id}'        => PSBDX_SRM_Helpers::get_ticket_id( $report_id ),
			'{report_title}'     => $post ? wp_strip_all_tags( $post->post_title ) : '',
			'{report_status}'    => $status,
			'{report_category}' => get_post_meta( $report_id, '_psbdx_report_category', true ) ?: __( 'Uncategorized', 'psbdx-smart-report-management' ),
			'{report_priority}' => get_post_meta( $report_id, '_psbdx_report_priority', true ) ?: __( 'Normal', 'psbdx-smart-report-management' ),
			'{reporter_name}'   => $reporter_name,
			'{reporter_email}'  => $reporter_email,
			'{source_title}'    => get_post_meta( $report_id, '_psbdx_source_title', true ),
			'{source_url}'      => get_post_meta( $report_id, '_psbdx_source_url', true ),
			'{admin_report_url}' => get_edit_post_link( $report_id, 'raw' ),
			'{report_view_url}'  => PSBDX_SRM_Report_Page::get_url( $report_id, true ),
			'{date}'             => date_i18n( get_option( 'date_format' ) ),
			'{time}'             => date_i18n( get_option( 'time_format' ) ),
		);
	}

	// =========================================================================
	// EVENT LISTENERS
	// =========================================================================

	/**
	 * Fires after a new report is submitted (and, by priority, after
	 * classification/auto-reply have already run): notifies the admin and
	 * confirms receipt with the reporter.
	 *
	 * @since 1.4.2
	 * @param  int $log_id  New report log post ID.
	 * @return void
	 */
	public static function notify_new_report( $log_id ) {
		$placeholders = self::build_report_placeholders( $log_id );

		self::send( 'new_report', get_option( 'admin_email' ), $placeholders );

		$reporter_email = get_post_meta( $log_id, '_psbdx_reporter_email', true );

		if ( $reporter_email ) {
			self::send( 'report_confirmation', $reporter_email, $placeholders );
		}
	}

	/**
	 * Fires when a reply is added to a report's thread: emails whoever
	 * *received* the reply — the admin when the reporter replied, or the
	 * reporter when an admin or AI replied.
	 *
	 * @since 1.4.2
	 * @param  int    $report_id    Report log post ID.
	 * @param  string $author_type  'admin', 'user', or 'ai'.
	 * @param  int    $reply_id     New reply row ID.
	 * @return void
	 */
	public static function notify_reply( $report_id, $author_type, $reply_id ) {
		$thread = PSBDX_SRM_Replies::get_thread( $report_id );
		$reply  = null;

		foreach ( $thread as $row ) {
			if ( (int) $row->id === (int) $reply_id ) {
				$reply = $row;
				break;
			}
		}

		if ( ! $reply ) {
			return;
		}

		$placeholders                     = self::build_report_placeholders( $report_id );
		$placeholders['{reply_message}']      = wp_kses_post( wpautop( $reply->message ) );
		$placeholders['{reply_author_name}']  = $reply->author_name;

		$attachment_id = (int) ( $reply->attachment_id ?? 0 );
		$file_paths    = array();

		if ( $attachment_id && 'attachment' === get_post_type( $attachment_id ) ) {
			$filename = basename( get_attached_file( $attachment_id ) );

			if ( self::attach_files_enabled() ) {
				$file_path = get_attached_file( $attachment_id );
				if ( $file_path && file_exists( $file_path ) ) {
					$file_paths[] = $file_path;
				}
				$placeholders['{reply_attachment}'] = '<p><strong>' . esc_html__( 'Attachment:', 'psbdx-smart-report-management' ) . '</strong> '
					. esc_html( $filename ) . ' — ' . esc_html__( 'see the attached file.', 'psbdx-smart-report-management' ) . '</p>';
			} else {
				$placeholders['{reply_attachment}'] = '<p><strong>' . esc_html__( 'Attachment', 'psbdx-smart-report-management' ) . '</strong></p>';
			}
		} else {
			$placeholders['{reply_attachment}'] = '';
		}

		if ( 'user' === $author_type ) {
			self::send( 'reply_to_admin', get_option( 'admin_email' ), $placeholders, $file_paths );
		} else {
			$reporter_email = get_post_meta( $report_id, '_psbdx_reporter_email', true );

			if ( $reporter_email ) {
				self::send( 'reply_to_user', $reporter_email, $placeholders, $file_paths );
			}
		}
	}

	/**
	 * Fires when an automated AI action errors out: notifies the admin.
	 *
	 * @since 1.4.2
	 * @param  string $action   Short label of what failed (e.g. 'Classification', 'Auto-reply').
	 * @param  array  $context  {
	 *     @type int    $report_id  Related report, if any.
	 *     @type string $ticket_id  Related ticket ID, if any.
	 *     @type string $message    Error message.
	 * }
	 * @return void
	 */
	public static function notify_ai_error( $action, array $context ) {
		$report_id = isset( $context['report_id'] ) ? (int) $context['report_id'] : 0;
		$base      = $report_id ? self::build_report_placeholders( $report_id ) : array(
			'{site_name}' => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
			'{site_url}'  => home_url( '/' ),
			'{ticket_id}' => isset( $context['ticket_id'] ) ? $context['ticket_id'] : '',
			'{date}'      => date_i18n( get_option( 'date_format' ) ),
			'{time}'      => date_i18n( get_option( 'time_format' ) ),
		);

		$base['{ai_action}']        = $action;
		$base['{ai_error_message}'] = isset( $context['message'] ) ? $context['message'] : '';
		$base['{ai_log_url}']       = admin_url( 'admin.php?page=' . PSBDX_SRM_AI_Log::PAGE_SLUG );

		if ( ! isset( $base['{admin_report_url}'] ) ) {
			$base['{admin_report_url}'] = '';
		}

		if ( ! isset( $base['{report_view_url}'] ) ) {
			$base['{report_view_url}'] = '';
		}

		self::send( 'ai_error', get_option( 'admin_email' ), $base );
	}
}
