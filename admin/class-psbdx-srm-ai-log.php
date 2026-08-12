<?php
/**
 * AI Response Log for PSBDx Smart Report Management.
 *
 * Records every AI Client request/response (report classification and
 * Settings → AI test requests) in a small custom table so admins on busy
 * sites can audit what the AI actually said, without that log growing
 * forever — entries older than 3 hours are purged automatically.
 *
 * @package PSBDx_Smart_Report_Management
 * @since   1.4.1
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class PSBDX_SRM_AI_Log
 *
 * @since 1.4.1
 */
class PSBDX_SRM_AI_Log {

	/**
	 * Option storing the installed table schema version.
	 *
	 * @since 1.4.1
	 * @var string
	 */
	const DB_VERSION_OPTION = 'psbdx_srm_ai_log_db_version';

	/**
	 * Current table schema version — bump and adjust install_table() if the
	 * columns ever change.
	 *
	 * @since 1.4.1
	 * @var string
	 */
	const DB_VERSION = '1.0';

	/**
	 * How long entries are kept before being purged.
	 *
	 * @since 1.4.1
	 * @var int
	 */
	const RETENTION_SECONDS = 3 * HOUR_IN_SECONDS; // phpcs:ignore WordPress.WP.CapitalPDangit

	/**
	 * Admin submenu slug.
	 *
	 * @since 1.4.1
	 * @var string
	 */
	const PAGE_SLUG = 'psbdx-srm-ai-log';

	/**
	 * Cron hook used for the hourly cleanup safety net (cleanup also runs
	 * opportunistically on every insert).
	 *
	 * @since 1.4.1
	 * @var string
	 */
	const CRON_HOOK = 'psbdx_srm_ai_log_cleanup';

	/**
	 * Constructor.
	 *
	 * @since 1.4.1
	 */
	public function __construct() {
		add_action( 'admin_init', array( __CLASS__, 'maybe_install_table' ) );
		add_action( 'admin_menu', array( $this, 'register_page' ), 105 );
		add_action( self::CRON_HOOK, array( __CLASS__, 'cleanup' ) );
		add_action( 'admin_post_psbdx_srm_clear_ai_log', array( $this, 'handle_clear_log' ) );

		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time(), 'hourly', self::CRON_HOOK );
		}
	}

	// =========================================================================
	// TABLE MANAGEMENT
	// =========================================================================

	/**
	 * The fully-prefixed table name for the current site.
	 *
	 * @since 1.4.1
	 * @return string
	 */
	public static function table_name() {
		global $wpdb;

		return $wpdb->prefix . 'psbdx_srm_ai_log';
	}

	/**
	 * Creates the table if it doesn't exist yet, or the schema has changed.
	 * Safe to call on every admin request — it's a no-op once the stored
	 * version option already matches.
	 *
	 * @since 1.4.1
	 * @return void
	 */
	public static function maybe_install_table() {
		if ( get_option( self::DB_VERSION_OPTION ) === self::DB_VERSION ) {
			return;
		}

		self::install_table();
	}

	/**
	 * Creates (or updates) the psbdx_srm_ai_log table via dbDelta().
	 *
	 * @since 1.4.1
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
			ticket_id VARCHAR(64) NOT NULL DEFAULT '',
			report_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			log_type VARCHAR(20) NOT NULL DEFAULT 'classification',
			status VARCHAR(20) NOT NULL DEFAULT 'success',
			model VARCHAR(100) NOT NULL DEFAULT '',
			request_excerpt TEXT NULL,
			response_excerpt TEXT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY created_at (created_at),
			KEY ticket_id (ticket_id)
		) {$charset_collate};";
		// phpcs:enable

		dbDelta( $sql );

		update_option( self::DB_VERSION_OPTION, self::DB_VERSION, false );
	}

	// =========================================================================
	// RECORDING & RETENTION
	// =========================================================================

	/**
	 * Records one AI interaction.
	 *
	 * @since 1.4.1
	 * @param  array $entry {
	 *     @type string $ticket_id  Ticket ID, if this relates to a report.
	 *     @type int    $report_id  Report log post ID, if applicable.
	 *     @type string $log_type   'classification' or 'test'.
	 *     @type string $status     'success' or 'error'.
	 *     @type string $model      Model identifier, if known.
	 *     @type string $request    Prompt text sent to the AI.
	 *     @type string $response   Raw text/JSON returned (or the error message).
	 * }
	 * @return void
	 */
	public static function record( array $entry ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- writing to our own custom log table; no WP API exists for this and it isn't a cacheable read.
		$wpdb->insert(
			self::table_name(),
			array(
				'ticket_id'        => isset( $entry['ticket_id'] ) ? substr( (string) $entry['ticket_id'], 0, 64 ) : '',
				'report_id'        => isset( $entry['report_id'] ) ? (int) $entry['report_id'] : 0,
				'log_type'         => isset( $entry['log_type'] ) ? substr( (string) $entry['log_type'], 0, 20 ) : 'classification',
				'status'           => isset( $entry['status'] ) ? substr( (string) $entry['status'], 0, 20 ) : 'success',
				'model'            => isset( $entry['model'] ) ? substr( (string) $entry['model'], 0, 100 ) : '',
				'request_excerpt'  => isset( $entry['request'] ) ? mb_substr( (string) $entry['request'], 0, 2000 ) : '',
				'response_excerpt' => isset( $entry['response'] ) ? mb_substr( (string) $entry['response'], 0, 2000 ) : '',
				'created_at'       => current_time( 'mysql', true ),
			),
			array( '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		// Opportunistic cleanup so the visible window stays accurate to
		// "last 3 hours" even between hourly cron runs, on busy sites where
		// entries are being written constantly.
		self::cleanup();
	}

	/**
	 * Deletes entries older than the retention window.
	 *
	 * @since 1.4.1
	 * @return void
	 */
	public static function cleanup() {
		global $wpdb;

		$table  = self::table_name();
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - self::RETENTION_SECONDS );

		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE created_at < %s", $cutoff ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table name is safe (built from a constant, not user input); a DELETE has nothing to cache.
	}

	/**
	 * Fetches entries from the last 3 hours, newest first.
	 *
	 * @since 1.4.1
	 * @param  int $limit  Max rows to return.
	 * @return array
	 */
	public static function get_recent( $limit = 200 ) {
		global $wpdb;

		$table  = self::table_name();
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - self::RETENTION_SECONDS );

		return $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE created_at >= %s ORDER BY created_at DESC LIMIT %d", $cutoff, $limit ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table name is safe (built from a constant, not user input); this log is intentionally short-lived (3-hour retention) and read infrequently, so a persistent cache layer adds no value.
		);
	}

	// =========================================================================
	// ADMIN PAGE
	// =========================================================================

	/**
	 * Registers the "AI Response Log" submenu page.
	 *
	 * @since  1.4.1
	 * @return void
	 */
	public function register_page() {
		add_submenu_page(
			PSBDX_SRM_Post_Types::ADMIN_MENU_SLUG,
			__( 'AI Response Log', 'psbdx-smart-report-management' ),
			__( 'AI Response Log', 'psbdx-smart-report-management' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Handles the "Clear log" button.
	 *
	 * @since  1.4.1
	 * @return void
	 */
	public function handle_clear_log() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'psbdx-smart-report-management' ) );
		}

		check_admin_referer( 'psbdx_srm_clear_ai_log' );

		global $wpdb;
		$wpdb->query( 'TRUNCATE TABLE ' . self::table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&cleared=1' ) );
		exit;
	}

	/**
	 * Renders the AI Response Log page.
	 *
	 * @since  1.4.1
	 * @return void
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		self::cleanup();
		$entries = self::get_recent();

		if ( isset( $_GET['cleared'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only success flag, no state change.
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'The AI Response Log has been cleared.', 'psbdx-smart-report-management' ) . '</p></div>';
		}
		?>
		<div class="wrap psbdx-srm-tools">
			<h1>
				<span class="dashicons dashicons-media-text" aria-hidden="true" style="vertical-align:middle;margin-right:6px;"></span>
				<?php esc_html_e( 'AI Response Log', 'psbdx-smart-report-management' ); ?>
			</h1>
			<p class="description">
				<?php esc_html_e( 'A rolling record of every request sent to the AI Client and what it replied — report classifications and Settings → AI test requests alike. Only the last 3 hours are kept; older entries are removed automatically.', 'psbdx-smart-report-management' ); ?>
			</p>

			<p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;" onsubmit="return confirm('<?php echo esc_js( __( 'Clear the entire AI Response Log now?', 'psbdx-smart-report-management' ) ); ?>');">
					<?php wp_nonce_field( 'psbdx_srm_clear_ai_log' ); ?>
					<input type="hidden" name="action" value="psbdx_srm_clear_ai_log">
					<button type="submit" class="button">
						<?php esc_html_e( 'Clear log now', 'psbdx-smart-report-management' ); ?>
					</button>
				</form>
			</p>

			<?php if ( empty( $entries ) ) : ?>
				<div class="psbdx-empty-state">
					<p><?php esc_html_e( 'No AI activity in the last 3 hours.', 'psbdx-smart-report-management' ); ?></p>
				</div>
			<?php else : ?>
				<div class="psbdx-table-responsive">
					<table class="widefat striped psbdx-ai-log-table">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Time', 'psbdx-smart-report-management' ); ?></th>
								<th><?php esc_html_e( 'Ticket', 'psbdx-smart-report-management' ); ?></th>
								<th><?php esc_html_e( 'Report', 'psbdx-smart-report-management' ); ?></th>
								<th><?php esc_html_e( 'Type', 'psbdx-smart-report-management' ); ?></th>
								<th><?php esc_html_e( 'Status', 'psbdx-smart-report-management' ); ?></th>
								<th><?php esc_html_e( 'Model', 'psbdx-smart-report-management' ); ?></th>
								<th><?php esc_html_e( 'Request / Response', 'psbdx-smart-report-management' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $entries as $entry ) : ?>
								<?php
								$created_gmt = strtotime( $entry->created_at . ' UTC' );
								$local_time  = $created_gmt ? get_date_from_gmt( $entry->created_at, 'M j, g:i:s a' ) : $entry->created_at;
								$is_error    = 'error' === $entry->status;
								?>
								<tr>
									<td data-label="<?php esc_attr_e( 'Time', 'psbdx-smart-report-management' ); ?>">
										<?php echo esc_html( $local_time ); ?>
									</td>
									<td data-label="<?php esc_attr_e( 'Ticket', 'psbdx-smart-report-management' ); ?>">
										<?php if ( $entry->ticket_id ) : ?>
											<code><?php echo esc_html( $entry->ticket_id ); ?></code>
										<?php else : ?>
											<span class="psbdx-admin-muted">&mdash;</span>
										<?php endif; ?>
									</td>
									<td data-label="<?php esc_attr_e( 'Report', 'psbdx-smart-report-management' ); ?>">
										<?php if ( $entry->report_id && get_post( (int) $entry->report_id ) ) : ?>
											<a href="<?php echo esc_url( get_edit_post_link( (int) $entry->report_id ) ); ?>">
												<?php echo esc_html( get_the_title( (int) $entry->report_id ) ); ?>
											</a>
										<?php else : ?>
											<span class="psbdx-admin-muted">&mdash;</span>
										<?php endif; ?>
									</td>
									<td data-label="<?php esc_attr_e( 'Type', 'psbdx-smart-report-management' ); ?>">
										<span class="psbdx-badge psbdx-badge-grey"><?php echo esc_html( ucfirst( $entry->log_type ) ); ?></span>
									</td>
									<td data-label="<?php esc_attr_e( 'Status', 'psbdx-smart-report-management' ); ?>">
										<span class="psbdx-badge <?php echo $is_error ? 'psbdx-badge-red' : 'psbdx-badge-green'; ?>">
											<?php echo esc_html( ucfirst( $entry->status ) ); ?>
										</span>
									</td>
									<td data-label="<?php esc_attr_e( 'Model', 'psbdx-smart-report-management' ); ?>">
										<?php echo $entry->model ? esc_html( $entry->model ) : '<span class="psbdx-admin-muted">&mdash;</span>'; ?>
									</td>
									<td data-label="<?php esc_attr_e( 'Request / Response', 'psbdx-smart-report-management' ); ?>">
										<details class="psbdx-ai-log-details">
											<summary><?php esc_html_e( 'View', 'psbdx-smart-report-management' ); ?></summary>
											<p><strong><?php esc_html_e( 'Request:', 'psbdx-smart-report-management' ); ?></strong></p>
											<pre><?php echo esc_html( $entry->request_excerpt ); ?></pre>
											<p><strong><?php esc_html_e( 'Response:', 'psbdx-smart-report-management' ); ?></strong></p>
											<pre><?php echo esc_html( $entry->response_excerpt ); ?></pre>
										</details>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}
}
