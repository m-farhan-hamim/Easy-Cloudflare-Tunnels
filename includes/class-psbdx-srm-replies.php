<?php
/**
 * Reply threads for PSBDx Smart Report Management.
 *
 * Stores the back-and-forth conversation (admin, reporter, and — when
 * enabled — AI) that happens on top of a submitted report, in a small
 * custom table. Whether replies are allowed at all, and whether AI is
 * allowed to post into the thread, are both controlled per report-form
 * (see PSBDX_SRM_Form_Builder settings tab) and gated overall by the
 * global switches under Settings → AI → Manage.
 *
 * @package PSBDx_Smart_Report_Management
 * @since   1.4.2
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class PSBDX_SRM_Replies
 *
 * @since 1.4.2
 */
class PSBDX_SRM_Replies {

	/**
	 * Option storing the installed table schema version.
	 *
	 * @since 1.4.2
	 * @var string
	 */
	const DB_VERSION_OPTION = 'psbdx_srm_replies_db_version';

	/**
	 * Current table schema version.
	 *
	 * @since 1.4.2
	 * @var string
	 */
	const DB_VERSION = '1.1';

	/**
	 * Post meta key (on psbdx_report_log) recording which form the report
	 * came from, so per-form reply settings can be resolved later.
	 *
	 * @since 1.4.2
	 * @var string
	 */
	const SOURCE_FORM_META = '_psbdx_source_form_id';

	/**
	 * Post meta key (on psbdx_report_form) — master per-form switch.
	 *
	 * @since 1.4.2
	 * @var string
	 */
	const ALLOW_REPLIES_META = '_psbdx_allow_replies';

	/**
	 * Post meta key (on psbdx_report_form) — per-form AI reply switch.
	 * Only takes effect when ALLOW_REPLIES_META is also 'yes' *and* the
	 * global Settings → AI → Manage switch is enabled.
	 *
	 * @since 1.4.2
	 * @var string
	 */
	const ALLOW_AI_REPLY_META = '_psbdx_allow_ai_reply';

	/**
	 * Constructor.
	 *
	 * @since 1.4.2
	 */
	public function __construct() {
		add_action( 'admin_init', array( __CLASS__, 'maybe_install_table' ) );
	}

	// =========================================================================
	// TABLE MANAGEMENT
	// =========================================================================

	/**
	 * The fully-prefixed table name for the current site.
	 *
	 * @since 1.4.2
	 * @return string
	 */
	public static function table_name() {
		global $wpdb;

		return $wpdb->prefix . 'psbdx_srm_replies';
	}

	/**
	 * Creates the table if it doesn't exist yet, or the schema has changed.
	 *
	 * @since 1.4.2
	 * @return void
	 */
	public static function maybe_install_table() {
		if ( get_option( self::DB_VERSION_OPTION ) === self::DB_VERSION ) {
			return;
		}

		self::install_table();
	}

	/**
	 * Creates (or updates) the psbdx_srm_replies table via dbDelta().
	 *
	 * @since 1.4.2
	 * @return void
	 */
	public static function install_table() {
		global $wpdb;

		$table           = self::table_name();
		$charset_collate = $wpdb->get_charset_collate();

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		// phpcs:disable WordPress.DB.PreparedSQLPlaceholders.DBParameterAllowedList -- dbDelta() requires a literal string, no placeholders.
		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			report_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			author_type VARCHAR(10) NOT NULL DEFAULT 'admin',
			author_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			author_name VARCHAR(190) NOT NULL DEFAULT '',
			message LONGTEXT NULL,
			attachment_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			ai_improved TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY report_id (report_id),
			KEY created_at (created_at)
		) {$charset_collate};";
		// phpcs:enable

		dbDelta( $sql );

		update_option( self::DB_VERSION_OPTION, self::DB_VERSION, false );
	}

	// =========================================================================
	// PERMISSIONS
	// =========================================================================

	/**
	 * Resolves the report-form ID a given report log was submitted from.
	 *
	 * @since 1.4.2
	 * @param  int $report_id  Report log post ID.
	 * @return int  Form post ID, or 0 if unknown (e.g. reports created
	 *              before 1.4.2 never recorded this).
	 */
	public static function get_source_form_id( $report_id ) {
		return (int) get_post_meta( $report_id, self::SOURCE_FORM_META, true );
	}

	/**
	 * Whether replies (admin ⇄ reporter) are allowed for a given report.
	 *
	 * Reports whose source form can't be determined (e.g. submitted before
	 * this feature existed) default to "not allowed" — an admin can turn
	 * replies on for the form and new reports will pick it up.
	 *
	 * @since 1.4.2
	 * @param  int $report_id  Report log post ID.
	 * @return bool
	 */
	public static function replies_allowed( $report_id ) {
		$form_id = self::get_source_form_id( $report_id );

		if ( ! $form_id ) {
			return false;
		}

		return 'yes' === get_post_meta( $form_id, self::ALLOW_REPLIES_META, true );
	}

	/**
	 * Whether AI is allowed to post automated replies into a given report's
	 * thread. Requires all three: replies allowed on the form, the form's
	 * own "Allow AI to reply" switch, and the site-wide AI Manage switch.
	 *
	 * @since 1.4.2
	 * @param  int $report_id  Report log post ID.
	 * @return bool
	 */
	/**
	 * Post meta key (on psbdx_report_log) — an admin's per-report override
	 * to stop AI from auto-replying to this one report, without touching
	 * the form's or the site's settings. Meant for exactly the "I've taken
	 * this over personally, stop the AI from jumping in" situation — e.g.
	 * once a human admin has stepped into the conversation.
	 *
	 * @since 1.4.2
	 * @var string
	 */
	const AI_REPLY_OFF_META = '_psbdx_ai_reply_off';

	/**
	 * Whether AI auto-replies are configured to be possible for a report at
	 * all — i.e. everything except the per-report admin override: replies
	 * allowed on the form, the form's own "Allow AI to reply" switch, and
	 * the site-wide AI Manage switch. Used to decide whether to even show
	 * the per-report "Turn off AI replies" control in the admin — no point
	 * showing it if AI could never reply here regardless.
	 *
	 * @since 1.4.2
	 * @param  int $report_id  Report log post ID.
	 * @return bool
	 */
	public static function ai_reply_configured( $report_id ) {
		if ( ! self::replies_allowed( $report_id ) ) {
			return false;
		}

		if ( ! PSBDX_SRM_AI::is_reply_enabled() ) {
			return false;
		}

		$form_id = self::get_source_form_id( $report_id );

		return $form_id && 'yes' === get_post_meta( $form_id, self::ALLOW_AI_REPLY_META, true );
	}

	/**
	 * Whether AI is allowed to post automated replies into a given report's
	 * thread right now: everything ai_reply_configured() checks, AND the
	 * admin hasn't turned it off for this specific report.
	 *
	 * @since 1.4.2
	 * @param  int $report_id  Report log post ID.
	 * @return bool
	 */
	public static function ai_reply_allowed( $report_id ) {
		if ( self::is_ai_reply_off( $report_id ) ) {
			return false;
		}

		return self::ai_reply_configured( $report_id );
	}

	/**
	 * Whether an admin has turned off AI auto-replies for this specific
	 * report (regardless of what the form/site settings would otherwise allow).
	 *
	 * @since 1.4.2
	 * @param  int $report_id  Report log post ID.
	 * @return bool
	 */
	public static function is_ai_reply_off( $report_id ) {
		return 'yes' === get_post_meta( $report_id, self::AI_REPLY_OFF_META, true );
	}

	/**
	 * Turns AI auto-replies on or off for one specific report.
	 *
	 * @since 1.4.2
	 * @param  int  $report_id  Report log post ID.
	 * @param  bool $off        True to turn AI replies off for this report, false to allow them again.
	 * @return void
	 */
	public static function set_ai_reply_off( $report_id, $off ) {
		update_post_meta( $report_id, self::AI_REPLY_OFF_META, $off ? 'yes' : 'no' );
	}

	/**
	 * Whether the current visitor is allowed to view/act on a given report
	 * from the frontend: an admin (or anyone with edit_posts), the logged-in
	 * user who filed it, or — for guest reports, or when not logged in — a
	 * visitor who supplies the exact email address the report was filed
	 * with. Used to gate the report detail page and replying from the frontend.
	 *
	 * @since 1.4.2
	 * @param  int    $report_id  Report log post ID.
	 * @param  string $email      Email address supplied by the visitor (e.g. from a query arg or form field), if any.
	 * @return bool
	 */
	public static function can_access_report( $report_id, $email = '' ) {
		$post = get_post( $report_id );

		if ( ! $post || 'psbdx_report_log' !== $post->post_type ) {
			return false;
		}

		if ( current_user_can( 'edit_posts' ) ) {
			return true;
		}

		// Support agents can view (though not necessarily reply to — see
		// PSBDX_SRM_Agents::get_assigned_agent()) any report, e.g. from the
		// Search Ticket tab of [psbdx_user_reports].
		if ( is_user_logged_in() && class_exists( 'PSBDX_SRM_Agents' ) && PSBDX_SRM_Agents::is_agent_or_admin( get_current_user_id() ) ) {
			return true;
		}

		if ( is_user_logged_in() && (int) $post->post_author > 0 && (int) $post->post_author === get_current_user_id() ) {
			return true;
		}

		$email = sanitize_email( (string) $email );

		if ( '' === $email ) {
			return false;
		}

		$stored = get_post_meta( $report_id, '_psbdx_reporter_email', true );

		return $stored && hash_equals( strtolower( trim( $stored ) ), strtolower( trim( $email ) ) );
	}

	// =========================================================================
	// CRUD
	// =========================================================================

	/**
	 * Adds a message to a report's reply thread.
	 *
	 * @since 1.4.2
	 * @param  int    $report_id    Report log post ID.
	 * @param  string $author_type  'admin', 'user', or 'ai'.
	 * @param  int    $author_id    WP user ID, or 0 for guests/AI.
	 * @param  string $author_name  Display name to show in the thread.
	 * @param  string $message      Message body (will be run through wp_kses_post on output).
	 * @param  bool   $ai_improved  Whether an admin message was polished by AI before sending.
	 * @param  int    $attachment_id  Optional attachment post ID shared alongside this message (0 = none).
	 * @return int|false  New row ID, or false on failure.
	 */
	public static function add_reply( $report_id, $author_type, $author_id, $author_name, $message, $ai_improved = false, $attachment_id = 0 ) {
		global $wpdb;

		$author_type   = in_array( $author_type, array( 'admin', 'user', 'ai' ), true ) ? $author_type : 'admin';
		$message       = trim( (string) $message );
		$attachment_id = absint( $attachment_id );

		// A reply needs a message OR an attachment — not necessarily both,
		// since sharing just a photo with no comment is a normal thing to do.
		if ( ( '' === $message && ! $attachment_id ) || ! $report_id ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- writing to our own custom table; no WP API exists for this.
		$inserted = $wpdb->insert(
			self::table_name(),
			array(
				'report_id'      => (int) $report_id,
				'author_type'    => $author_type,
				'author_id'      => (int) $author_id,
				'author_name'    => mb_substr( sanitize_text_field( $author_name ), 0, 190 ),
				'message'        => wp_kses_post( $message ),
				'attachment_id'  => $attachment_id,
				'ai_improved'    => $ai_improved ? 1 : 0,
				'created_at'     => current_time( 'mysql', true ),
			),
			array( '%d', '%s', '%d', '%s', '%s', '%d', '%d', '%s' )
		);

		if ( ! $inserted ) {
			return false;
		}

		$reply_id = (int) $wpdb->insert_id;

		/**
		 * Fires after a reply is successfully added to a report's thread.
		 *
		 * @since 1.4.2
		 * @param int    $report_id    Report log post ID.
		 * @param string $author_type  'admin', 'user', or 'ai'.
		 * @param int    $reply_id     New reply row ID.
		 */
		do_action( 'psbdx_srm_reply_added', (int) $report_id, $author_type, $reply_id );

		return $reply_id;
	}

	/**
	 * Fetches the full reply thread for a report, oldest first.
	 *
	 * @since 1.4.2
	 * @param  int $report_id  Report log post ID.
	 * @return array
	 */
	public static function get_thread( $report_id ) {
		global $wpdb;

		$table = self::table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table; reply threads are small and read live.
		return (array) $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE report_id = %d ORDER BY created_at ASC, id ASC", $report_id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is safe (built from a constant via table_name(), not user input); $wpdb->prepare() can't parameterize identifiers.
		);
	}

	/**
	 * Builds a plain-text transcript of a thread, suitable for feeding to
	 * the AI as conversation history.
	 *
	 * @since 1.4.2
	 * @param  int $report_id  Report log post ID.
	 * @param  int $limit      Max number of most-recent messages to include.
	 * @return string
	 */
	public static function get_thread_as_text( $report_id, $limit = 20 ) {
		$thread = self::get_thread( $report_id );
		$thread = array_slice( $thread, -1 * max( 1, $limit ) );

		$lines = array();

		foreach ( $thread as $row ) {
			$who = 'ai' === $row->author_type
				? __( 'AI Assistant', 'psbdx-smart-report-management' )
				: ( 'admin' === $row->author_type
					? __( 'Support Agent', 'psbdx-smart-report-management' )
					: __( 'Customer', 'psbdx-smart-report-management' ) );

			$lines[] = $who . ': ' . wp_strip_all_tags( $row->message );
		}

		return implode( "\n", $lines );
	}
}
