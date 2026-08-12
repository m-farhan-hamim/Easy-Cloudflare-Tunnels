<?php
/**
 * Support agent management for PSBDx Smart Report Management.
 *
 * Handles the support-agent list (explicitly added agents + every WP
 * Administrator, added automatically), per-agent work hours, automatic
 * "free agent" assignment on new reports (when the source form has Allow
 * Replies turned on), a per-report action log admins can audit, and
 * handover requests between agents.
 *
 * Roles inside this feature:
 * - Agent            : any user added via the "Support Agents" admin
 *                       screen, or any WordPress Administrator (added
 *                       automatically). Can be assigned reports, reply,
 *                       change status, abandon, and request handovers.
 * - Administrator     : a WordPress user with the `manage_options`
 *                       capability. Automatically an agent. Can manage
 *                       (add/remove/edit hours for) plain agents, and see
 *                       the whole agent list, but cannot edit or remove
 *                       *other* administrators from the list.
 * - Super Administrator: a plugin-level designation (see
 *                       SUPER_ADMIN_OPTION), independent of WordPress's
 *                       own multisite super admin concept. Only super
 *                       admins can manage, edit, or remove administrators
 *                       from the support agent list.
 *
 * @package PSBDx_Smart_Report_Management
 * @since   1.4.5
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class PSBDX_SRM_Agents
 *
 * @since 1.4.5
 */
class PSBDX_SRM_Agents {

	/**
	 * Option storing the installed table schema version.
	 *
	 * @since 1.4.5
	 * @var string
	 */
	const DB_VERSION_OPTION = 'psbdx_srm_agents_db_version';

	/**
	 * Current table schema version.
	 *
	 * @since 1.4.5
	 * @var string
	 */
	const DB_VERSION = '1.1';

	/**
	 * Option key: array of user IDs designated as plugin-level Super
	 * Administrators. Independent of WordPress's own is_super_admin(),
	 * which (on a single-site install) is effectively true for every
	 * Administrator and so can't express this distinction on its own.
	 *
	 * @since 1.4.5
	 * @var string
	 */
	const SUPER_ADMIN_OPTION = 'psbdx_srm_super_admin_ids';

	/**
	 * Post meta key: the WP user ID of a report's currently assigned agent
	 * (0 = unassigned).
	 *
	 * @since 1.4.5
	 * @var string
	 */
	const ASSIGNED_META = '_psbdx_assigned_agent';

	/**
	 * Post meta key: GMT datetime the current assignment was made.
	 *
	 * @since 1.4.5
	 * @var string
	 */
	const ASSIGNED_AT_META = '_psbdx_assigned_at';

	/**
	 * Post meta key: how the current assignment came about ('auto',
	 * 'manual', 'claim', or 'handover').
	 *
	 * @since 1.4.5
	 * @var string
	 */
	const ASSIGNED_SOURCE_META = '_psbdx_assignment_source';

	/**
	 * Every agent's starting rating (out of 5 stars) before any completed
	 * or abandoned reports adjust it.
	 *
	 * @since 1.4.6
	 * @var float
	 */
	const DEFAULT_RATING = 2.5;

	/**
	 * Post meta key flagging that a report has already paid out its
	 * "completed" rating bonus, so toggling status back and forth (or an
	 * admin correcting a status) can't be used to farm rating points.
	 *
	 * @since 1.4.6
	 * @var string
	 */
	const RATING_AWARDED_META = '_psbdx_rating_awarded';

	/**
	 * Constructor.
	 *
	 * @since 1.4.5
	 */
	public function __construct() {
		add_action( 'admin_init', array( __CLASS__, 'maybe_install_table' ) );
		add_action( 'admin_init', array( __CLASS__, 'seed_super_admins_if_needed' ) );

		add_action( 'psbdx_srm_report_submitted', array( __CLASS__, 'maybe_lock_no_reply_report' ), 5 );

		// Auto-assignment runs after AI classification (priority 10/20 in
		// PSBDX_SRM_AI) so an eventual "assign by category" refinement has
		// classification data to work with if this is extended later.
		add_action( 'psbdx_srm_report_submitted', array( __CLASS__, 'maybe_auto_assign' ), 25 );

		add_action( 'add_meta_boxes', array( __CLASS__, 'register_meta_box' ) );

		add_action( 'wp_ajax_psbdx_srm_admin_reassign', array( __CLASS__, 'ajax_admin_reassign' ) );

		add_action( 'updated_postmeta', array( __CLASS__, 'maybe_award_completion_rating' ), 10, 4 );
	}

	// =========================================================================
	// TABLE MANAGEMENT
	// =========================================================================

	/**
	 * The agents table name.
	 *
	 * @since 1.4.5
	 * @return string
	 */
	public static function table_name() {
		global $wpdb;
		return $wpdb->prefix . 'psbdx_srm_agents';
	}

	/**
	 * The agent action log table name.
	 *
	 * @since 1.4.5
	 * @return string
	 */
	public static function log_table_name() {
		global $wpdb;
		return $wpdb->prefix . 'psbdx_srm_agent_log';
	}

	/**
	 * The handover requests table name.
	 *
	 * @since 1.4.5
	 * @return string
	 */
	public static function handover_table_name() {
		global $wpdb;
		return $wpdb->prefix . 'psbdx_srm_handover_requests';
	}

	/**
	 * Installs (or upgrades) all three tables if needed.
	 *
	 * @since 1.4.5
	 * @return void
	 */
	public static function maybe_install_table() {
		if ( get_option( self::DB_VERSION_OPTION ) === self::DB_VERSION ) {
			return;
		}
		self::install_table();
	}

	/**
	 * Creates (or updates) the agents/log/handover tables via dbDelta().
	 *
	 * @since 1.4.5
	 * @return void
	 */
	public static function install_table() {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$agents_table   = self::table_name();
		$log_table      = self::log_table_name();
		$handover_table = self::handover_table_name();

		// phpcs:disable WordPress.DB.PreparedSQLPlaceholders.DBParameterAllowedList -- dbDelta() requires literal strings.
		$sql   = array();
		$sql[] = "CREATE TABLE {$agents_table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			work_hours LONGTEXT NULL,
			rating DECIMAL(3,2) NOT NULL DEFAULT 2.50,
			excluded TINYINT UNSIGNED NOT NULL DEFAULT 0,
			added_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
			added_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY user_id (user_id)
		) {$charset_collate};";

		$sql[] = "CREATE TABLE {$log_table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			report_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			agent_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			action VARCHAR(40) NOT NULL DEFAULT '',
			target_user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			meta LONGTEXT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY report_id (report_id),
			KEY created_at (created_at)
		) {$charset_collate};";

		$sql[] = "CREATE TABLE {$handover_table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			report_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			type VARCHAR(10) NOT NULL DEFAULT 'request',
			requester_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			holder_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			status VARCHAR(12) NOT NULL DEFAULT 'pending',
			created_at DATETIME NOT NULL,
			resolved_at DATETIME NULL,
			PRIMARY KEY  (id),
			KEY report_id (report_id),
			KEY status (status)
		) {$charset_collate};";
		// phpcs:enable

		foreach ( $sql as $statement ) {
			dbDelta( $statement );
		}

		update_option( self::DB_VERSION_OPTION, self::DB_VERSION, false );
	}

	// =========================================================================
	// CAPABILITIES / ROLES
	// =========================================================================

	/**
	 * Whether a user holds the WordPress Administrator capability set.
	 *
	 * @since 1.4.5
	 * @param  int $user_id  WP user ID.
	 * @return bool
	 */
	public static function is_admin_role( $user_id ) {
		return user_can( (int) $user_id, 'manage_options' );
	}

	/**
	 * Raw, validated list of plugin-level Super Administrator user IDs.
	 *
	 * @since 1.4.5
	 * @return int[]
	 */
	public static function get_super_admin_ids() {
		$raw = get_option( self::SUPER_ADMIN_OPTION, array() );
		$raw = is_array( $raw ) ? array_map( 'absint', $raw ) : array();

		return array_values( array_unique( array_filter( $raw ) ) );
	}

	/**
	 * The first time this runs (no option saved yet), every current
	 * Administrator is seeded as a Super Administrator, so the feature is
	 * immediately usable instead of locking everyone out of managing it.
	 * From then on, only existing super admins can promote/demote anyone.
	 *
	 * @since 1.4.5
	 * @return void
	 */
	public static function seed_super_admins_if_needed() {
		if ( false !== get_option( self::SUPER_ADMIN_OPTION, false ) ) {
			return;
		}

		$admins = get_users( array( 'role' => 'administrator', 'fields' => 'ID' ) );
		update_option( self::SUPER_ADMIN_OPTION, array_map( 'absint', $admins ), false );
	}

	/**
	 * Whether a user is a plugin-level Super Administrator.
	 *
	 * @since 1.4.5
	 * @param  int $user_id  WP user ID.
	 * @return bool
	 */
	public static function is_super_admin( $user_id ) {
		return in_array( (int) $user_id, self::get_super_admin_ids(), true );
	}

	/**
	 * Grants Super Administrator status. Caller must already have verified
	 * the acting user is itself a super admin.
	 *
	 * @since 1.4.5
	 * @param  int $user_id  WP user ID to promote.
	 * @return void
	 */
	public static function add_super_admin( $user_id ) {
		$ids   = self::get_super_admin_ids();
		$ids[] = (int) $user_id;
		update_option( self::SUPER_ADMIN_OPTION, array_values( array_unique( $ids ) ), false );
	}

	/**
	 * Revokes Super Administrator status. Caller must already have verified
	 * the acting user is itself a super admin.
	 *
	 * @since 1.4.5
	 * @param  int $user_id  WP user ID to demote.
	 * @return void
	 */
	public static function remove_super_admin( $user_id ) {
		$ids = array_diff( self::get_super_admin_ids(), array( (int) $user_id ) );
		update_option( self::SUPER_ADMIN_OPTION, array_values( $ids ), false );
	}

	/**
	 * Whether the given actor is allowed to manage (edit hours for, remove,
	 * exclude) a given target agent.
	 *
	 * - Anyone with manage_options can manage a plain (non-admin) agent.
	 * - Only a Super Administrator can manage another Administrator.
	 * - A user can always edit their own work hours.
	 *
	 * @since 1.4.5
	 * @param  int $actor_id   WP user ID performing the action.
	 * @param  int $target_id  WP user ID being managed.
	 * @return bool
	 */
	public static function can_manage_target( $actor_id, $target_id ) {
		$actor_id  = (int) $actor_id;
		$target_id = (int) $target_id;

		if ( $actor_id === $target_id ) {
			return self::is_agent_or_admin( $actor_id );
		}

		if ( self::is_admin_role( $target_id ) ) {
			return self::is_super_admin( $actor_id );
		}

		return self::is_admin_role( $actor_id );
	}

	/**
	 * Whether a user should see the "Manage Agents" tab at all
	 * (Administrators and Super Administrators only).
	 *
	 * @since 1.4.5
	 * @param  int $user_id  WP user ID.
	 * @return bool
	 */
	public static function can_view_manage_tab( $user_id ) {
		return self::is_admin_role( $user_id );
	}

	// =========================================================================
	// AGENT LIST
	// =========================================================================

	/**
	 * Inserts a row for any WP Administrator who isn't already in the
	 * agents table, so administrators always show up as agents without a
	 * manual step. Safe to call repeatedly (UNIQUE key on user_id).
	 *
	 * @since 1.4.5
	 * @return void
	 */
	public static function sync_admin_rows() {
		global $wpdb;

		$admin_ids = get_users( array( 'role' => 'administrator', 'fields' => 'ID' ) );

		if ( empty( $admin_ids ) ) {
			return;
		}

		$table    = self::table_name();
		$existing = $wpdb->get_col( "SELECT user_id FROM {$table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- small custom table.
		$existing = array_map( 'absint', (array) $existing );
		$now      = current_time( 'mysql', true );

		foreach ( $admin_ids as $uid ) {
			$uid = (int) $uid;
			if ( in_array( $uid, $existing, true ) ) {
				continue;
			}

			$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- writing to our own custom table.
				$table,
				array(
					'user_id'    => $uid,
					'work_hours' => '',
					'rating'     => self::DEFAULT_RATING,
					'excluded'   => 0,
					'added_by'   => 0,
					'added_at'   => $now,
					'updated_at' => $now,
				),
				array( '%d', '%s', '%f', '%d', '%d', '%s', '%s' )
			);
		}
	}

	/**
	 * Full agent list (explicitly-added agents + every WP Administrator),
	 * administrators first.
	 *
	 * @since 1.4.5
	 * @return array<int, array{user_id:int, user: WP_User|false, is_admin: bool, is_super: bool, excluded: bool, work_hours: array, added_at: string}>
	 */
	public static function get_all_agents() {
		global $wpdb;

		self::sync_admin_rows();

		$table = self::table_name();
		$rows  = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY id ASC" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- small custom table.

		$out = array();
		foreach ( (array) $rows as $row ) {
			$user_id = (int) $row->user_id;
			$out[]   = array(
				'user_id'    => $user_id,
				'user'       => get_userdata( $user_id ),
				'is_admin'   => self::is_admin_role( $user_id ),
				'is_super'   => self::is_super_admin( $user_id ),
				'excluded'   => (bool) $row->excluded,
				'work_hours' => self::decode_work_hours( $row->work_hours ),
				'rating'     => self::normalize_rating( $row->rating ),
				'added_at'   => $row->added_at,
			);
		}

		usort( $out, function ( $a, $b ) {
			if ( $a['is_admin'] !== $b['is_admin'] ) {
				return $a['is_admin'] ? -1 : 1;
			}
			$an = $a['user'] ? $a['user']->display_name : '';
			$bn = $b['user'] ? $b['user']->display_name : '';
			return strcasecmp( $an, $bn );
		} );

		return $out;
	}

	/**
	 * Whether a user has an explicit row in the agents table (this is
	 * always true for WP Administrators too, since they're auto-synced).
	 *
	 * @since 1.4.5
	 * @param  int $user_id  WP user ID.
	 * @return bool
	 */
	public static function is_explicit_agent( $user_id ) {
		global $wpdb;
		$table = self::table_name();
		$found = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE user_id = %d AND excluded = 0", (int) $user_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from constant, value is prepared.
		return (bool) $found;
	}

	/**
	 * Whether a user is a usable agent right now: an active (non-excluded)
	 * agent row, or a WP Administrator.
	 *
	 * @since 1.4.5
	 * @param  int $user_id  WP user ID.
	 * @return bool
	 */
	public static function is_agent_or_admin( $user_id ) {
		$user_id = (int) $user_id;
		if ( ! $user_id ) {
			return false;
		}
		if ( self::is_admin_role( $user_id ) ) {
			return true;
		}
		return self::is_explicit_agent( $user_id );
	}

	/**
	 * Adds a user as a support agent.
	 *
	 * @since 1.4.5
	 * @param  int $user_id   WP user ID to add.
	 * @param  int $added_by  WP user ID of the admin adding them.
	 * @return bool
	 */
	public static function add_agent( $user_id, $added_by ) {
		global $wpdb;

		$user_id = (int) $user_id;
		if ( ! $user_id || ! get_userdata( $user_id ) ) {
			return false;
		}

		$table = self::table_name();
		$now   = current_time( 'mysql', true );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table; REPLACE-style upsert via unique key on user_id.
		$existing = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE user_id = %d", $user_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is our own table name constant, not user input; $user_id is bound via %d.

		if ( $existing ) {
			$wpdb->update( $table, array( 'excluded' => 0, 'updated_at' => $now ), array( 'user_id' => $user_id ), array( '%d', '%s' ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, no caching needed for this write.
			return true;
		}

		return (bool) $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$table,
			array(
				'user_id'    => $user_id,
				'work_hours' => '',
				'rating'     => self::DEFAULT_RATING,
				'excluded'   => 0,
				'added_by'   => (int) $added_by,
				'added_at'   => $now,
				'updated_at' => $now,
			),
			array( '%d', '%s', '%f', '%d', '%d', '%s', '%s' )
		);
	}

	/**
	 * Removes a user from the support agent list. A plain agent's row is
	 * deleted outright; an Administrator's row is marked "excluded"
	 * instead (a hard delete would just be re-created by sync_admin_rows()
	 * the next time the list is viewed — excluding is what actually
	 * "removes" an admin from active agent duty while leaving their WP role
	 * untouched).
	 *
	 * @since 1.4.5
	 * @param  int $user_id  WP user ID to remove.
	 * @return void
	 */
	public static function remove_agent( $user_id ) {
		global $wpdb;
		$table   = self::table_name();
		$user_id = (int) $user_id;

		if ( self::is_admin_role( $user_id ) ) {
			$wpdb->update( $table, array( 'excluded' => 1, 'updated_at' => current_time( 'mysql', true ) ), array( 'user_id' => $user_id ), array( '%d', '%s' ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, no caching needed for this write.
			return;
		}

		$wpdb->delete( $table, array( 'user_id' => $user_id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, no caching needed for this write.
	}

	// =========================================================================
	// WORK HOURS
	// =========================================================================

	/**
	 * Decodes a stored work_hours JSON string.
	 *
	 * @since 1.4.5
	 * @param  string $raw  Raw JSON from the DB.
	 * @return array  Empty array means "no restriction — always on duty".
	 */
	private static function decode_work_hours( $raw ) {
		if ( empty( $raw ) ) {
			return array();
		}
		$decoded = json_decode( (string) $raw, true );
		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * Gets a user's configured work hours.
	 *
	 * @since 1.4.5
	 * @param  int $user_id  WP user ID.
	 * @return array<int, array{enabled:bool, start:string, end:string}>  Keyed 0 (Sunday) – 6 (Saturday). Empty = always available.
	 */
	public static function get_work_hours( $user_id ) {
		global $wpdb;
		$table = self::table_name();
		$raw   = $wpdb->get_var( $wpdb->prepare( "SELECT work_hours FROM {$table} WHERE user_id = %d", (int) $user_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return self::decode_work_hours( $raw );
	}

	/**
	 * Saves a user's work hours. Caller is responsible for permission
	 * checks (see can_manage_target()).
	 *
	 * @since 1.4.5
	 * @param  int   $user_id  WP user ID.
	 * @param  array $hours    Keyed 0–6, each array{enabled:bool, start:'HH:MM', end:'HH:MM'}.
	 * @return void
	 */
	public static function set_work_hours( $user_id, array $hours ) {
		global $wpdb;
		$user_id = (int) $user_id;

		$clean = array();
		for ( $d = 0; $d <= 6; $d++ ) {
			$row              = isset( $hours[ $d ] ) && is_array( $hours[ $d ] ) ? $hours[ $d ] : array();
			$clean[ (string) $d ] = array(
				'enabled' => ! empty( $row['enabled'] ),
				'start'   => isset( $row['start'] ) ? self::sanitize_time( $row['start'] ) : '09:00',
				'end'     => isset( $row['end'] ) ? self::sanitize_time( $row['end'] ) : '18:00',
			);
		}

		// Make sure a row exists (covers an admin who hasn't been synced
		// into the table yet, e.g. right after this request added them).
		if ( ! self::is_explicit_agent( $user_id ) ) {
			self::add_agent( $user_id, get_current_user_id() );
		}

		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table.
			self::table_name(),
			array(
				'work_hours' => wp_json_encode( $clean ),
				'updated_at' => current_time( 'mysql', true ),
			),
			array( 'user_id' => $user_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Validates an "HH:MM" time string.
	 *
	 * @since 1.4.5
	 * @param  string $time  Raw time value.
	 * @return string  Sanitized "HH:MM", or '00:00' if invalid.
	 */
	private static function sanitize_time( $time ) {
		$time = trim( (string) $time );
		return preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', $time ) ? $time : '00:00';
	}

	/**
	 * Whether a user is currently within their configured work hours. A
	 * user with no configured hours at all is treated as always available.
	 *
	 * @since 1.4.5
	 * @param  int $user_id    WP user ID.
	 * @param  int $timestamp  Optional Unix timestamp (site-local); defaults to now.
	 * @return bool
	 */
	public static function is_on_duty( $user_id, $timestamp = null ) {
		$hours = self::get_work_hours( $user_id );

		if ( empty( $hours ) ) {
			return true;
		}

		$timestamp = $timestamp ? (int) $timestamp : current_time( 'timestamp' ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested -- site-local time needed to compare against admin-entered local hours.
		$dow       = (int) gmdate( 'w', $timestamp );
		$day       = isset( $hours[ (string) $dow ] ) ? $hours[ (string) $dow ] : ( isset( $hours[ $dow ] ) ? $hours[ $dow ] : null );

		if ( ! $day || empty( $day['enabled'] ) ) {
			return false;
		}

		$now_hm = gmdate( 'H:i', $timestamp );

		return ( $now_hm >= $day['start'] && $now_hm <= $day['end'] );
	}

	// =========================================================================
	// ASSIGNMENT
	// =========================================================================

	/**
	 * Currently assigned agent for a report.
	 *
	 * @since 1.4.5
	 * @param  int $report_id  Report log post ID.
	 * @return int  WP user ID, or 0 if unassigned.
	 */
	public static function get_assigned_agent( $report_id ) {
		return (int) get_post_meta( $report_id, self::ASSIGNED_META, true );
	}

	/**
	 * Whether a report is locked to further replies — a report marked
	 * Solved can't be messaged by the reporter or any agent until someone
	 * reopens it. Reports whose form has replies disabled are marked
	 * Solved automatically at submission (see maybe_lock_no_reply_report())
	 * and stay that way — there's no conversation to reopen.
	 *
	 * @since  1.4.6
	 * @param  int $report_id  Report log post ID.
	 * @return bool
	 */
	public static function is_report_locked( $report_id ) {
		$status = get_post_meta( $report_id, '_psbdx_report_status', true );
		return 'Solved' === $status;
	}

	/**
	 * Reopens a Solved report so replies can resume — sets it back to
	 * Processing, clears the one-time completion-rating flag (so a genuine
	 * later resolution can earn it again), and logs who did it.
	 *
	 * @since  1.4.6
	 * @param  int $report_id  Report log post ID.
	 * @param  int $actor_id   WP user ID reopening it (0 = the reporter acting without an account).
	 * @return bool
	 */
	public static function reopen_report( $report_id, $actor_id ) {
		if ( ! self::is_report_locked( $report_id ) ) {
			return false;
		}

		PSBDX_SRM_Helpers::update_report_status( $report_id, 'Processing', array( 'source' => 'reopened' ) );
		delete_post_meta( $report_id, self::RATING_AWARDED_META );
		self::log_action( $report_id, (int) $actor_id, 'reopened' );

		return true;
	}

	/**
	 * A report submitted through a form with replies disabled has no
	 * conversation loop, so it's marked Solved right away instead of
	 * sitting open forever with nobody able (or needing) to message it.
	 *
	 * @since  1.4.6
	 * @param  int $report_id  Report log post ID.
	 * @return void
	 */
	public static function maybe_lock_no_reply_report( $report_id ) {
		if ( PSBDX_SRM_Replies::replies_allowed( $report_id ) ) {
			return;
		}

		PSBDX_SRM_Helpers::update_report_status( $report_id, 'Solved', array( 'source' => 'no_replies_form' ) );
	}

	/**
	 * Number of currently open (not Solved) reports assigned to an agent —
	 * used to pick the least-busy free agent for auto-assignment.
	 *
	 * @since 1.4.5
	 * @param  int $user_id  WP user ID.
	 * @return int
	 */
	public static function get_agent_open_count( $user_id ) {
		$query = new WP_Query( array(
			'post_type'      => 'psbdx_report_log',
			'post_status'    => 'publish',
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'no_found_rows'  => false,
			'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				array(
					'key'   => self::ASSIGNED_META,
					'value' => (int) $user_id,
				),
				array(
					'key'     => '_psbdx_report_status',
					'value'   => 'Solved',
					'compare' => '!=',
				),
			),
		) );

		return (int) $query->found_posts;
	}

	/**
	 * Active (non-excluded), on-duty agents right now.
	 *
	 * @since 1.4.5
	 * @return int[]  WP user IDs.
	 */
	public static function get_free_agents() {
		$free = array();
		foreach ( self::get_all_agents() as $agent ) {
			if ( $agent['excluded'] || ! $agent['user'] ) {
				continue;
			}
			if ( self::is_on_duty( $agent['user_id'] ) ) {
				$free[] = $agent['user_id'];
			}
		}
		return $free;
	}

	/**
	 * Picks the least-busy free (on-duty) agent, optionally excluding some
	 * user IDs (e.g. the agent who just abandoned the report). Shared by
	 * maybe_auto_assign() and the abandon-triggered reassignment.
	 *
	 * @since  1.4.6
	 * @param  int[] $exclude  WP user IDs to skip.
	 * @return int  Best agent's WP user ID, or 0 if nobody free.
	 */
	private static function find_free_agent( array $exclude = array() ) {
		$free = array_values( array_diff( self::get_free_agents(), $exclude ) );

		if ( empty( $free ) ) {
			return 0;
		}

		// Pick the free agent with the fewest currently-open assignments;
		// ties broken randomly so load doesn't always land on the same person.
		shuffle( $free );
		$best       = $free[0];
		$best_count = self::get_agent_open_count( $best );

		foreach ( $free as $candidate ) {
			$count = self::get_agent_open_count( $candidate );
			if ( $count < $best_count ) {
				$best       = $candidate;
				$best_count = $count;
			}
		}

		return $best;
	}

	/**
	 * Auto-assigns a freshly submitted report to a free agent, if the
	 * report's source form has replies enabled. Only sends a notification
	 * to the one agent it lands on.
	 *
	 * @since  1.4.5
	 * @param  int $report_id  Report log post ID.
	 * @return void
	 */
	public static function maybe_auto_assign( $report_id ) {
		if ( ! PSBDX_SRM_Replies::replies_allowed( $report_id ) ) {
			return;
		}

		if ( self::get_assigned_agent( $report_id ) ) {
			return;
		}

		$best = self::find_free_agent();
		if ( $best ) {
			self::assign_report( $report_id, $best, 'auto', 0 );
		}
	}

	/**
	 * Assigns (or reassigns) a report to an agent and notifies them.
	 *
	 * @since 1.4.5
	 * @param  int    $report_id     Report log post ID.
	 * @param  int    $agent_id      WP user ID to assign to.
	 * @param  string $source        'auto' | 'manual' | 'claim' | 'handover'.
	 * @param  int    $performed_by  WP user ID performing the action (0 = system).
	 * @return void
	 */
	public static function assign_report( $report_id, $agent_id, $source = 'manual', $performed_by = 0 ) {
		$report_id = (int) $report_id;
		$agent_id  = (int) $agent_id;

		update_post_meta( $report_id, self::ASSIGNED_META, $agent_id );
		update_post_meta( $report_id, self::ASSIGNED_AT_META, current_time( 'mysql', true ) );
		update_post_meta( $report_id, self::ASSIGNED_SOURCE_META, $source );

		self::log_action( $report_id, $performed_by, 'assigned', array( 'source' => $source ), $agent_id );

		$agent = get_userdata( $agent_id );
		if ( $agent && is_email( $agent->user_email ) ) {
			$placeholders                    = PSBDX_SRM_Emails::build_report_placeholders( $report_id );
			$placeholders['{agent_name}']    = $agent->display_name;
			$placeholders['{admin_report_url}'] = get_edit_post_link( $report_id, 'raw' );

			PSBDX_SRM_Emails::send( 'agent_assigned', $agent->user_email, $placeholders );
		}

		/**
		 * Fires after a report is assigned (or reassigned) to an agent.
		 *
		 * @since 1.4.5
		 * @param int    $report_id  Report log post ID.
		 * @param int    $agent_id   Newly assigned agent's WP user ID.
		 * @param string $source     'auto' | 'manual' | 'claim' | 'handover'.
		 */
		do_action( 'psbdx_srm_agent_assigned', $report_id, $agent_id, $source );
	}

	/**
	 * An agent abandons a report they're currently assigned to — clears the
	 * assignment so it becomes available again (auto-assignment won't pick
	 * it back up automatically; it needs a claim or manual reassignment).
	 *
	 * @since 1.4.5
	 * @param  int $report_id  Report log post ID.
	 * @param  int $agent_id   Agent abandoning it (must be the current holder).
	 * @return bool
	 */
	public static function abandon_report( $report_id, $agent_id ) {
		if ( self::get_assigned_agent( $report_id ) !== (int) $agent_id ) {
			return false;
		}

		delete_post_meta( $report_id, self::ASSIGNED_META );
		delete_post_meta( $report_id, self::ASSIGNED_AT_META );
		delete_post_meta( $report_id, self::ASSIGNED_SOURCE_META );

		self::log_action( $report_id, $agent_id, 'abandoned' );
		self::adjust_rating( $agent_id, -0.15, 'abandoned' );

		// Don't leave it stranded — hand it straight to another free
		// agent, same as a fresh submission, as long as the report's form
		// actually allows replies (a no-reply report never gets assigned
		// in the first place, so there'd be nothing to reassign).
		if ( PSBDX_SRM_Replies::replies_allowed( $report_id ) ) {
			$next = self::find_free_agent( array( (int) $agent_id ) );
			if ( $next ) {
				self::assign_report( $report_id, $next, 'auto', 0 );
			}
		}

		return true;
	}

	/**
	 * An agent claims an unassigned report for themselves (used from the
	 * Search Ticket tab).
	 *
	 * @since 1.4.5
	 * @param  int $report_id  Report log post ID.
	 * @param  int $agent_id   Agent claiming it.
	 * @return bool
	 */
	public static function claim_report( $report_id, $agent_id ) {
		if ( self::get_assigned_agent( $report_id ) ) {
			return false;
		}
		self::assign_report( $report_id, $agent_id, 'claim', $agent_id );
		return true;
	}

	// =========================================================================
	// RATING
	//
	// Every agent starts at DEFAULT_RATING (2.5 stars). The score moves as
	// they work: it drops when they abandon a report or leave one for an
	// admin to notice and reassign, and rises when a report assigned to
	// them is resolved. Purely a management signal shown in Agent
	// Management — it has no effect on auto-assignment or permissions.
	// =========================================================================

	/**
	 * Clamps and formats a raw DB rating value.
	 *
	 * @since  1.4.6
	 * @param  mixed $raw  Raw value from the agents table.
	 * @return float  0.0–5.0.
	 */
	private static function normalize_rating( $raw ) {
		if ( '' === $raw || null === $raw ) {
			return self::DEFAULT_RATING;
		}
		return round( max( 0, min( 5, (float) $raw ) ), 2 );
	}

	/**
	 * An agent's current rating.
	 *
	 * @since  1.4.6
	 * @param  int $user_id  WP user ID.
	 * @return float  0.0–5.0.
	 */
	public static function get_rating( $user_id ) {
		global $wpdb;
		$table = self::table_name();
		$raw   = $wpdb->get_var( $wpdb->prepare( "SELECT rating FROM {$table} WHERE user_id = %d", (int) $user_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return null === $raw ? self::DEFAULT_RATING : self::normalize_rating( $raw );
	}

	/**
	 * Nudges an agent's rating up or down, clamped to 0–5, and logs it.
	 * Silently does nothing for a user with no agent row yet (e.g. a
	 * report auto-assigned before sync_admin_rows() has ever run for them
	 * — get_all_agents()/is_agent_or_admin() calls elsewhere always sync
	 * first, so this is only a defensive no-op).
	 *
	 * @since  1.4.6
	 * @param  int    $user_id  WP user ID.
	 * @param  float  $delta    Positive or negative amount to apply.
	 * @param  string $reason   Short reason key, stored in the action log meta.
	 * @return void
	 */
	public static function adjust_rating( $user_id, $delta, $reason = '' ) {
		global $wpdb;

		$user_id = (int) $user_id;
		if ( ! $user_id ) {
			return;
		}

		$current = self::get_rating( $user_id );
		$new     = self::normalize_rating( $current + (float) $delta );

		if ( ! self::is_explicit_agent( $user_id ) && ! self::is_admin_role( $user_id ) ) {
			return;
		}

		// Make sure a row exists (mirrors set_work_hours()'s safety net).
		if ( ! self::is_explicit_agent( $user_id ) ) {
			self::add_agent( $user_id, 0 );
		}

		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table.
			self::table_name(),
			array( 'rating' => $new, 'updated_at' => current_time( 'mysql', true ) ),
			array( 'user_id' => $user_id ),
			array( '%f', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Awards a small rating bump the first time a report assigned to an
	 * agent is marked Solved. Hooked to 'updated_postmeta' so it catches a
	 * status change no matter whether it came from the agent tools, the
	 * classic admin meta box, or anywhere else — and only pays out once
	 * per report (RATING_AWARDED_META) so re-saving Solved can't be used
	 * to farm points.
	 *
	 * @since  1.4.6
	 * @param  int    $meta_id     Meta row ID (unused).
	 * @param  int    $object_id   Post ID the meta belongs to.
	 * @param  string $meta_key    Meta key that changed.
	 * @param  mixed  $meta_value  New meta value.
	 * @return void
	 */
	public static function maybe_award_completion_rating( $meta_id, $object_id, $meta_key, $meta_value ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		if ( '_psbdx_report_status' !== $meta_key || 'Solved' !== $meta_value ) {
			return;
		}

		if ( 'psbdx_report_log' !== get_post_type( $object_id ) ) {
			return;
		}

		if ( get_post_meta( $object_id, self::RATING_AWARDED_META, true ) ) {
			return;
		}

		$agent_id = self::get_assigned_agent( $object_id );
		if ( ! $agent_id ) {
			return;
		}

		update_post_meta( $object_id, self::RATING_AWARDED_META, 1 );
		self::adjust_rating( $agent_id, 0.10, 'resolved' );
		self::log_action( $object_id, 0, 'rating_up', array( 'reason' => 'resolved' ), $agent_id );
	}

	/**
	 * Renders a star rating as HTML (full/half/empty stars + the numeric
	 * value), used by both the wp-admin Support Agents screen and the
	 * frontend Manage Agents tab.
	 *
	 * @since  1.4.6
	 * @param  float $rating  0.0–5.0.
	 * @return string
	 */
	public static function render_stars( $rating ) {
		$rating = self::normalize_rating( $rating );
		$full   = (int) floor( $rating );
		$half   = ( $rating - $full ) >= 0.5;
		$empty  = 5 - $full - ( $half ? 1 : 0 );

		$out = '<span class="psbdx-agent-stars" title="' . esc_attr( sprintf(
			/* translators: %s: numeric rating out of 5 */
			__( '%s out of 5', 'psbdx-smart-report-management' ),
			number_format_i18n( $rating, 2 )
		) ) . '">';

		$out .= str_repeat( '&#9733;', $full );
		if ( $half ) {
			$out .= '&#189;&#9733;';
		}
		$out .= str_repeat( '&#9734;', max( 0, $empty ) );
		$out .= ' <span class="psbdx-agent-stars-num">(' . esc_html( number_format_i18n( $rating, 2 ) ) . ')</span></span>';

		return $out;
	}

	// =========================================================================
	// ACTION LOG
	// =========================================================================

	/**
	 * Records an entry in a report's agent action log, visible to
	 * administrators on the report's edit screen.
	 *
	 * @since 1.4.5
	 * @param  int    $report_id       Report log post ID.
	 * @param  int    $agent_id        Agent (or admin) who performed the action; 0 = system.
	 * @param  string $action          Short action key, e.g. 'assigned', 'replied', 'status_changed'.
	 * @param  array  $meta            Optional extra details (JSON-encoded).
	 * @param  int    $target_user_id  Optional related user (e.g. who a report was reassigned to).
	 * @return void
	 */
	public static function log_action( $report_id, $agent_id, $action, array $meta = array(), $target_user_id = 0 ) {
		global $wpdb;

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- custom table.
			self::log_table_name(),
			array(
				'report_id'      => (int) $report_id,
				'agent_id'       => (int) $agent_id,
				'action'         => sanitize_key( $action ),
				'target_user_id' => (int) $target_user_id,
				'meta'           => ! empty( $meta ) ? wp_json_encode( $meta ) : '',
				'created_at'     => current_time( 'mysql', true ),
			),
			array( '%d', '%d', '%s', '%d', '%s', '%s' )
		);
	}

	/**
	 * Full action log for a report, oldest first.
	 *
	 * @since 1.4.5
	 * @param  int $report_id  Report log post ID.
	 * @return array
	 */
	public static function get_action_log( $report_id ) {
		global $wpdb;
		$table = self::log_table_name();

		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE report_id = %d ORDER BY created_at ASC, id ASC", (int) $report_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table is our own table name constant, not user input; $report_id is bound via %d.
	}

	/**
	 * Human-readable label for a logged action key.
	 *
	 * @since 1.4.5
	 * @param  string $action  Action key.
	 * @return string
	 */
	public static function action_label( $action ) {
		$labels = array(
			'assigned'           => __( 'Assigned', 'psbdx-smart-report-management' ),
			'abandoned'          => __( 'Abandoned', 'psbdx-smart-report-management' ),
			'replied'            => __( 'Replied', 'psbdx-smart-report-management' ),
			'status_changed'     => __( 'Changed status', 'psbdx-smart-report-management' ),
			'attachment_deleted' => __( 'Deleted an attachment', 'psbdx-smart-report-management' ),
			'handover_requested' => __( 'Requested handover', 'psbdx-smart-report-management' ),
			'handover_accepted'  => __( 'Accepted handover', 'psbdx-smart-report-management' ),
			'handover_declined'  => __( 'Declined handover', 'psbdx-smart-report-management' ),
			'handover_cancelled' => __( 'Cancelled handover request', 'psbdx-smart-report-management' ),
			'rating_up'          => __( 'Rating increased', 'psbdx-smart-report-management' ),
			'rating_down'        => __( 'Rating decreased', 'psbdx-smart-report-management' ),
			'ignored'            => __( 'Left unattended — reassigned by admin', 'psbdx-smart-report-management' ),
			'reopened'           => __( 'Reopened the report', 'psbdx-smart-report-management' ),
		);

		return isset( $labels[ $action ] ) ? $labels[ $action ] : ucwords( str_replace( '_', ' ', $action ) );
	}

	// =========================================================================
	// HANDOVER REQUESTS
	//
	// Two directions share one table, distinguished by `type`:
	// - 'request' : a bystander agent (not currently assigned) asks the
	//   current holder to hand the report to them — used from the Search
	//   Ticket tab. The HOLDER decides.
	// - 'offer'   : the currently assigned agent asks a specific other
	//   agent to take the report over — used from the Assigned Reports /
	//   report detail "request other agents to manage this report" action.
	//   The INVITED AGENT decides.
	// In both cases `requester_id` is whoever becomes the new assignee if
	// the request is accepted; only who is allowed to accept differs.
	// =========================================================================

	/**
	 * A bystander agent requests that the currently assigned agent hand a
	 * report over to them.
	 *
	 * @since 1.4.5
	 * @param  int $report_id     Report log post ID.
	 * @param  int $requester_id  Agent requesting the handover.
	 * @return int|false  New request row ID, or false if not applicable.
	 */
	public static function request_handover( $report_id, $requester_id ) {
		$holder_id = self::get_assigned_agent( $report_id );

		if ( ! $holder_id || $holder_id === (int) $requester_id ) {
			return false;
		}

		return self::insert_handover( $report_id, 'request', (int) $requester_id, $holder_id );
	}

	/**
	 * The currently assigned agent offers the report to a specific other
	 * agent, who must accept before it's reassigned.
	 *
	 * @since 1.4.5
	 * @param  int $report_id  Report log post ID.
	 * @param  int $holder_id  Current holder making the offer (must be the current assignee).
	 * @param  int $target_id  Agent being offered the report.
	 * @return int|false  New request row ID, or false if not applicable.
	 */
	public static function offer_handover( $report_id, $holder_id, $target_id ) {
		if ( self::get_assigned_agent( $report_id ) !== (int) $holder_id || (int) $holder_id === (int) $target_id ) {
			return false;
		}

		if ( ! PSBDX_SRM_Agents::is_agent_or_admin( $target_id ) ) {
			return false;
		}

		return self::insert_handover( $report_id, 'offer', (int) $target_id, (int) $holder_id );
	}

	/**
	 * Shared insert + notification for both handover directions.
	 *
	 * @since  1.4.5
	 * @param  int    $report_id     Report log post ID.
	 * @param  string $type          'request' | 'offer'.
	 * @param  int    $requester_id  Who becomes the new assignee if accepted.
	 * @param  int    $holder_id     Current assignee at the time of the request.
	 * @return int|false
	 */
	private static function insert_handover( $report_id, $type, $requester_id, $holder_id ) {
		global $wpdb;

		if ( self::get_pending_handover( $report_id ) ) {
			return false;
		}

		$inserted = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- custom table.
			self::handover_table_name(),
			array(
				'report_id'    => (int) $report_id,
				'type'         => $type,
				'requester_id' => $requester_id,
				'holder_id'    => $holder_id,
				'status'       => 'pending',
				'created_at'   => current_time( 'mysql', true ),
			),
			array( '%d', '%s', '%d', '%d', '%s', '%s' )
		);

		if ( ! $inserted ) {
			return false;
		}

		self::log_action( $report_id, 'offer' === $type ? $holder_id : $requester_id, 'handover_requested', array( 'type' => $type ), 'offer' === $type ? $requester_id : $holder_id );

		// Notify whichever party has to make the decision.
		$notify_id  = 'offer' === $type ? $requester_id : $holder_id;
		$other_id   = 'offer' === $type ? $holder_id : $requester_id;
		$other_user = get_userdata( $other_id );

		if ( $other_user ) {
			self::notify_agent(
				$notify_id,
				sprintf(
					/* translators: %s: ticket ID */
					__( 'Handover request — %s', 'psbdx-smart-report-management' ),
					PSBDX_SRM_Helpers::get_ticket_id( $report_id )
				),
				'offer' === $type
					? sprintf(
						/* translators: 1: offering agent's name, 2: report link */
						__( '%1$s wants to hand a report over to you. Review it here: %2$s', 'psbdx-smart-report-management' ),
						esc_html( $other_user->display_name ),
						esc_url( get_edit_post_link( $report_id, 'raw' ) )
					)
					: sprintf(
						/* translators: 1: requesting agent's name, 2: report link */
						__( '%1$s has asked to take over a report you\'re assigned to. Review it here: %2$s', 'psbdx-smart-report-management' ),
						esc_html( $other_user->display_name ),
						esc_url( get_edit_post_link( $report_id, 'raw' ) )
					)
			);
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * The current pending handover request for a report, if any.
	 *
	 * @since 1.4.5
	 * @param  int $report_id  Report log post ID.
	 * @return object|null
	 */
	public static function get_pending_handover( $report_id ) {
		global $wpdb;
		$table = self::handover_table_name();

		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE report_id = %d AND status = 'pending' ORDER BY id DESC LIMIT 1", (int) $report_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table is our own table name constant, not user input; the ID is bound via %d.
	}

	/**
	 * Pending handover requests waiting on a given agent's decision —
	 * either as the holder of a 'request', or the invitee of an 'offer'.
	 *
	 * @since 1.4.5
	 * @param  int $agent_id  WP user ID.
	 * @return array
	 */
	public static function get_incoming_handover_requests( $agent_id ) {
		global $wpdb;
		$table    = self::handover_table_name();
		$agent_id = (int) $agent_id;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table is our own class constant-derived table name, not user input; $agent_id is bound via %d.
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE status = 'pending' AND ( (type = 'request' AND holder_id = %d) OR (type = 'offer' AND requester_id = %d) ) ORDER BY created_at ASC", $agent_id, $agent_id ) );
	}

	/**
	 * The deciding party accepts or declines a pending handover request
	 * (the holder for 'request' type, the invited agent for 'offer' type).
	 *
	 * @since 1.4.5
	 * @param  int  $request_id    Handover request row ID.
	 * @param  bool $accept        True to accept (reassigns the report), false to decline.
	 * @param  int  $responder_id  WP user ID responding.
	 * @return bool
	 */
	public static function respond_handover( $request_id, $accept, $responder_id ) {
		global $wpdb;
		$table = self::handover_table_name();

		$request = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d AND status = 'pending'", (int) $request_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table is our own table name constant, not user input; the ID is bound via %d.

		if ( ! $request ) {
			return false;
		}

		$decider = 'offer' === $request->type ? (int) $request->requester_id : (int) $request->holder_id;

		if ( $decider !== (int) $responder_id ) {
			return false;
		}

		$new_status = $accept ? 'accepted' : 'declined';

		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table.
			$table,
			array( 'status' => $new_status, 'resolved_at' => current_time( 'mysql', true ) ),
			array( 'id' => (int) $request_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		self::log_action(
			(int) $request->report_id,
			$responder_id,
			$accept ? 'handover_accepted' : 'handover_declined',
			array( 'type' => $request->type )
		);

		if ( $accept ) {
			// requester_id is always "who becomes the new assignee" for
			// both directions — see the class doc block above.
			self::assign_report( (int) $request->report_id, (int) $request->requester_id, 'handover', $responder_id );
		}

		return true;
	}

	/**
	 * The initiating party cancels their own pending handover request
	 * (the requester for 'request' type, the holder for 'offer' type).
	 *
	 * @since 1.4.5
	 * @param  int $request_id  Handover request row ID.
	 * @param  int $actor_id    WP user ID — must be the request's initiator.
	 * @return bool
	 */
	public static function cancel_handover( $request_id, $actor_id ) {
		global $wpdb;
		$table = self::handover_table_name();

		$request = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d AND status = 'pending'", (int) $request_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table is our own table name constant, not user input; the ID is bound via %d.

		if ( ! $request ) {
			return false;
		}

		$initiator = 'offer' === $request->type ? (int) $request->holder_id : (int) $request->requester_id;

		if ( $initiator !== (int) $actor_id ) {
			return false;
		}

		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table.
			$table,
			array( 'status' => 'cancelled', 'resolved_at' => current_time( 'mysql', true ) ),
			array( 'id' => (int) $request_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		self::log_action( (int) $request->report_id, $actor_id, 'handover_cancelled' );

		return true;
	}

	// =========================================================================
	// CHAT ATTACHMENTS
	// =========================================================================

	/**
	 * Lets an agent delete an attachment shared in a report's reply thread
	 * (not the original submission's own attachment fields — those remain
	 * admin-only via PSBDX_SRM_Attachment_Manager).
	 *
	 * @since 1.4.5
	 * @param  int $report_id      Report log post ID.
	 * @param  int $attachment_id  Attachment post ID to delete.
	 * @param  int $agent_id       Agent performing the deletion.
	 * @return bool
	 */
	public static function delete_chat_attachment( $report_id, $attachment_id, $agent_id ) {
		global $wpdb;

		$table = PSBDX_SRM_Replies::table_name();
		$owns  = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE report_id = %d AND attachment_id = %d", (int) $report_id, (int) $attachment_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( ! $owns ) {
			return false;
		}

		if ( ! wp_delete_attachment( (int) $attachment_id, true ) ) {
			return false;
		}

		self::log_action( $report_id, $agent_id, 'attachment_deleted', array( 'attachment_id' => (int) $attachment_id ) );

		return true;
	}

	// =========================================================================
	// NOTIFICATIONS (lightweight — outside the editable-template system)
	// =========================================================================

	/**
	 * Sends a plain notification email to an agent (handover requests etc.
	 * — simpler, non-templated notices; the main "you've been assigned"
	 * email uses the full editable-template system, see 'agent_assigned' in
	 * PSBDX_SRM_Emails::get_events()).
	 *
	 * @since 1.4.5
	 * @param  int    $user_id  Recipient WP user ID.
	 * @param  string $subject  Email subject.
	 * @param  string $message  Plain-text/HTML-lite message body.
	 * @return bool
	 */
	public static function notify_agent( $user_id, $subject, $message ) {
		$user = get_userdata( (int) $user_id );
		if ( ! $user || ! is_email( $user->user_email ) ) {
			return false;
		}

		$body = '<p>' . wp_kses_post( $message ) . '</p>';

		return wp_mail( $user->user_email, wp_specialchars_decode( $subject, ENT_QUOTES ), $body, array( 'Content-Type: text/html; charset=UTF-8' ) );
	}

	// =========================================================================
	// SEARCH (used by the frontend "Search Ticket" tab)
	// =========================================================================

	/**
	 * Finds a report by ticket ID or numeric post ID, for the agent Search
	 * Ticket tab.
	 *
	 * @since 1.4.5
	 * @param  string $query  Ticket ID (e.g. "PSRM-...") or numeric post ID.
	 * @return int  Report log post ID, or 0 if not found.
	 */
	public static function search_report( $query ) {
		$query = trim( (string) $query );

		if ( '' === $query ) {
			return 0;
		}

		if ( ctype_digit( $query ) ) {
			$post = get_post( (int) $query );
			if ( $post && 'psbdx_report_log' === $post->post_type ) {
				return (int) $post->ID;
			}
		}

		return PSBDX_SRM_Helpers::get_report_by_ticket_id( $query );
	}

	// =========================================================================
	// ADMIN META BOX (Agent Activity Log + manual assignment)
	// =========================================================================

	/**
	 * Registers the "Agent Activity Log" meta box on the report edit screen,
	 * visible to administrators only.
	 *
	 * @since 1.4.5
	 * @return void
	 */
	public static function register_meta_box() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		add_meta_box(
			'psbdx-srm-agent-log',
			__( 'Assignment & Agent Activity Log', 'psbdx-smart-report-management' ),
			array( __CLASS__, 'render_meta_box' ),
			'psbdx_report_log',
			'normal',
			'low'
		);
	}

	/**
	 * Renders the meta box: current assignment (with a manual reassign
	 * control) and the full agent action log for the report.
	 *
	 * @since 1.4.5
	 * @param  WP_Post $post  Current report log post.
	 * @return void
	 */
	public static function render_meta_box( $post ) {
		$report_id = (int) $post->ID;
		$assigned  = self::get_assigned_agent( $report_id );
		$agents    = self::get_all_agents();
		$log       = self::get_action_log( $report_id );

		wp_nonce_field( 'psbdx_srm_admin_reassign_' . $report_id, 'psbdx_srm_admin_reassign_nonce' );
		?>
		<p>
			<label for="psbdx-srm-reassign-select"><strong><?php esc_html_e( 'Assigned agent:', 'psbdx-smart-report-management' ); ?></strong></label>
			<select id="psbdx-srm-reassign-select">
				<option value="0"><?php esc_html_e( '— Unassigned —', 'psbdx-smart-report-management' ); ?></option>
				<?php foreach ( $agents as $agent ) :
					if ( ! $agent['user'] || $agent['excluded'] ) {
						continue;
					}
					?>
					<option value="<?php echo (int) $agent['user_id']; ?>" <?php selected( $assigned, $agent['user_id'] ); ?>>
						<?php echo esc_html( $agent['user']->display_name . ( $agent['is_admin'] ? ' (' . __( 'Admin', 'psbdx-smart-report-management' ) . ')' : '' ) ); ?>
					</option>
				<?php endforeach; ?>
			</select>
			<button type="button" class="button" id="psbdx-srm-reassign-btn" data-report="<?php echo (int) $report_id; ?>">
				<?php esc_html_e( 'Save', 'psbdx-smart-report-management' ); ?>
			</button>
			<span id="psbdx-srm-reassign-status"></span>
		</p>
		<hr>
		<?php if ( empty( $log ) ) : ?>
			<p><em><?php esc_html_e( 'No agent activity yet.', 'psbdx-smart-report-management' ); ?></em></p>
		<?php else : ?>
			<table class="widefat striped" style="max-width:100%;">
				<thead>
					<tr>
						<th><?php esc_html_e( 'When', 'psbdx-smart-report-management' ); ?></th>
						<th><?php esc_html_e( 'Agent', 'psbdx-smart-report-management' ); ?></th>
						<th><?php esc_html_e( 'Action', 'psbdx-smart-report-management' ); ?></th>
						<th><?php esc_html_e( 'Details', 'psbdx-smart-report-management' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( $log as $row ) :
					$actor  = $row->agent_id ? get_userdata( $row->agent_id ) : false;
					$target = $row->target_user_id ? get_userdata( $row->target_user_id ) : false;
					$meta   = $row->meta ? json_decode( $row->meta, true ) : array();
					?>
					<tr>
						<td><?php echo esc_html( get_date_from_gmt( $row->created_at, 'M j, Y g:i a' ) ); ?></td>
						<td>
							<?php
							echo $actor ? esc_html( $actor->display_name . ' (#' . $actor->ID . ')' ) : esc_html__( 'System', 'psbdx-smart-report-management' );
							?>
						</td>
						<td><?php echo esc_html( self::action_label( $row->action ) ); ?></td>
						<td>
							<?php
							if ( $target ) {
								echo esc_html( sprintf(
									/* translators: %s: user display name */
									__( '→ %s', 'psbdx-smart-report-management' ),
									$target->display_name
								) );
							} elseif ( ! empty( $meta ) ) {
								echo esc_html( wp_json_encode( $meta ) );
							} else {
								echo '&mdash;';
							}
							?>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
		<script>
		( function () {
			var btn = document.getElementById( 'psbdx-srm-reassign-btn' );
			if ( ! btn ) { return; }
			btn.addEventListener( 'click', function () {
				var sel    = document.getElementById( 'psbdx-srm-reassign-select' );
				var status = document.getElementById( 'psbdx-srm-reassign-status' );
				var nonce  = document.getElementById( 'psbdx_srm_admin_reassign_nonce' ).value;
				status.textContent = '<?php echo esc_js( __( 'Saving…', 'psbdx-smart-report-management' ) ); ?>';
				var body = new URLSearchParams();
				body.set( 'action', 'psbdx_srm_admin_reassign' );
				body.set( 'report_id', '<?php echo (int) $report_id; ?>' );
				body.set( 'agent_id', sel.value );
				body.set( 'nonce', nonce );
				fetch( ajaxurl, { method: 'POST', credentials: 'same-origin', body: body } )
					.then( function ( r ) { return r.json(); } )
					.then( function ( res ) {
						status.textContent = res && res.success ? '<?php echo esc_js( __( 'Saved. Reload to see the updated log.', 'psbdx-smart-report-management' ) ); ?>' : '<?php echo esc_js( __( 'Failed to save.', 'psbdx-smart-report-management' ) ); ?>';
					} )
					.catch( function () {
						status.textContent = '<?php echo esc_js( __( 'Failed to save.', 'psbdx-smart-report-management' ) ); ?>';
					} );
			} );
		} )();
		</script>
		<?php
	}

	/**
	 * AJAX: manual reassignment from the admin meta box.
	 *
	 * @since 1.4.5
	 * @return void  Terminates with wp_send_json_success()/error().
	 */
	public static function ajax_admin_reassign() {
		$report_id = isset( $_POST['report_id'] ) ? (int) $_POST['report_id'] : 0;

		if ( ! current_user_can( 'manage_options' ) || ! check_ajax_referer( 'psbdx_srm_admin_reassign_' . $report_id, 'nonce', false ) ) {
			wp_send_json_error( __( 'Not allowed.', 'psbdx-smart-report-management' ) );
		}

		if ( 'psbdx_report_log' !== get_post_type( $report_id ) ) {
			wp_send_json_error( __( 'Invalid report.', 'psbdx-smart-report-management' ) );
		}

		$agent_id      = isset( $_POST['agent_id'] ) ? (int) $_POST['agent_id'] : 0;
		$previous_id   = self::get_assigned_agent( $report_id );

		// An admin having to step in and move a report away from whoever
		// had it (as opposed to the agent abandoning or handing it over
		// themselves) is treated as a sign it was left unattended.
		if ( $previous_id && $previous_id !== $agent_id ) {
			self::log_action( $report_id, get_current_user_id(), 'ignored', array(), $previous_id );
			self::adjust_rating( $previous_id, -0.20, 'ignored' );
		}

		if ( ! $agent_id ) {
			delete_post_meta( $report_id, self::ASSIGNED_META );
			delete_post_meta( $report_id, self::ASSIGNED_AT_META );
			delete_post_meta( $report_id, self::ASSIGNED_SOURCE_META );
			self::log_action( $report_id, get_current_user_id(), 'abandoned' );
			wp_send_json_success();
		}

		self::assign_report( $report_id, $agent_id, 'manual', get_current_user_id() );
		wp_send_json_success();
	}
}
