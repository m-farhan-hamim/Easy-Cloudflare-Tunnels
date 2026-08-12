<?php
/**
 * Admin dashboard widget for PSBDx Smart Report Management.
 *
 * @package PSBDx_Smart_Report_Management
 * @since   1.0.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class PSBDX_SRM_Dashboard_Widget
 *
 * Registers and renders a dashboard widget showing:
 * - Count of unsolved (non-Solved) reports with a direct link.
 * - Per-status report counts.
 * - A list of the 5 most recent report submissions.
 *
 * @since 1.0.0
 */
class PSBDX_SRM_Dashboard_Widget {

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		add_action( 'wp_dashboard_setup',    array( $this, 'register' ) );
		add_action( 'admin_bar_menu',        array( $this, 'admin_bar_shortcut' ), 100 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_widget_styles' ) );
	}

	/**
	 * Register the dashboard widget — admins only.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	public function register() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		wp_add_dashboard_widget(
			'psbdx_srm_widget',
			__( 'PSBDx Smart Report Management — Overview', 'psbdx-smart-report-management' ),
			array( $this, 'render' )
		);
	}

	/**
	 * Enqueue inline styles for the widget and admin bar badge.
	 *
	 * @since  1.3.1
	 * @return void
	 */
	public function enqueue_widget_styles() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$unsolved = $this->get_unsolved_count();

		if ( $unsolved > 0 ) {
			wp_add_inline_style(
				'wp-admin',
				'#wp-admin-bar-psrm-unsolved .psrm-bar-badge{
					display:inline-block;
					background:#b91c1c;
					color:#fff;
					border-radius:10px;
					font-size:10px;
					line-height:1;
					padding:2px 6px;
					margin-left:4px;
					vertical-align:middle;
				}'
			);
		}
	}

	/**
	 * Add a shortcut in the WP admin bar showing the unsolved report count.
	 *
	 * @since  1.3.1
	 * @param  WP_Admin_Bar $wp_admin_bar  Admin bar object.
	 * @return void
	 */
	public function admin_bar_shortcut( $wp_admin_bar ) {
		if ( ! is_admin() ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$unsolved = $this->get_unsolved_count();
		$url      = admin_url( 'edit.php?post_type=psbdx_report_log' );

		$title = sprintf(
			/* translators: %s: badge HTML or empty string */
			__( 'Reports%s', 'psbdx-smart-report-management' ),
			$unsolved > 0
				? ' <span class="psrm-bar-badge" aria-label="' . esc_attr( sprintf(
					/* translators: %d: number of unsolved reports */
					_n( '%d unsolved', '%d unsolved', $unsolved, 'psbdx-smart-report-management' ),
					$unsolved
				) ) . '">' . esc_html( (string) $unsolved ) . '</span>'
				: ''
		);

		$wp_admin_bar->add_node( array(
			'id'    => 'psrm-unsolved',
			'title' => $title,
			'href'  => esc_url( $url ),
			'meta'  => array(
				'title' => $unsolved > 0
					? sprintf(
						/* translators: %d: unsolved count */
						_n( '%d unsolved report', '%d unsolved reports', $unsolved, 'psbdx-smart-report-management' ),
						$unsolved
					)
					: __( 'View Reports', 'psbdx-smart-report-management' ),
			),
		) );
	}

	/**
	 * Render the dashboard widget content.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	public function render() {
		$statuses  = PSBDX_SRM_Helpers::get_statuses();
		$total     = (int) wp_count_posts( 'psbdx_report_log' )->publish;
		$counts    = PSBDX_SRM_Helpers::get_report_status_counts();
		$recent    = $this->get_recent_reports( 5 );
		$unsolved  = $this->get_unsolved_count();
		$logs_url  = admin_url( 'edit.php?post_type=psbdx_report_log' );
		?>

		<div class="psbdx-dw">

			<?php /* Unsolved banner — shown only when there are open reports */ ?>
			<?php if ( $unsolved > 0 ) : ?>
			<div class="psbdx-dw-unsolved-banner" style="background:#fef2f2;border:1px solid #fca5a5;border-radius:6px;padding:10px 14px;margin-bottom:14px;display:flex;align-items:center;gap:10px;">
				<span class="dashicons dashicons-warning" aria-hidden="true" style="color:#b91c1c;font-size:22px;width:22px;height:22px;flex-shrink:0;"></span>
				<div style="flex:1;">
					<strong style="color:#b91c1c;">
						<?php echo esc_html( sprintf(
							/* translators: %d: number of unsolved reports */
							_n( '%d report is not marked as Solved', '%d reports are not marked as Solved', $unsolved, 'psbdx-smart-report-management' ),
							$unsolved
						) ); ?>
					</strong>
					<br>
					<a href="<?php echo esc_url( $logs_url ); ?>" style="font-size:12px;">
						<?php esc_html_e( 'View unsolved reports &rarr;', 'psbdx-smart-report-management' ); ?>
					</a>
				</div>
			</div>
			<?php else : ?>
			<div style="background:#f0fdf4;border:1px solid #86efac;border-radius:6px;padding:8px 14px;margin-bottom:14px;display:flex;align-items:center;gap:8px;">
				<span class="dashicons dashicons-yes-alt" aria-hidden="true" style="color:#16a34a;"></span>
				<strong style="color:#15803d;"><?php esc_html_e( 'All reports marked as Solved!', 'psbdx-smart-report-management' ); ?></strong>
			</div>
			<?php endif; ?>

			<!-- Total -->
			<div class="psbdx-dw-total-row">
				<span class="psbdx-dw-total"><?php echo esc_html( $total ); ?></span>
				<span class="psbdx-dw-total-label"><?php esc_html_e( 'total reports', 'psbdx-smart-report-management' ); ?></span>
			</div>

			<!-- Status cards -->
			<div class="psbdx-dw-grid">
				<?php foreach ( $statuses as $key => $s ) : ?>
				<div class="psbdx-dw-card" style="background:<?php echo esc_attr( $s['bg'] ); ?>;color:<?php echo esc_attr( $s['color'] ); ?>;">
					<div class="psbdx-dw-card-count"><?php echo esc_html( (string) ( $counts[ $key ] ?? 0 ) ); ?></div>
					<div class="psbdx-dw-card-label"><?php echo esc_html( $s['label'] ); ?></div>
				</div>
				<?php endforeach; ?>
			</div>

			<!-- Recent reports -->
			<?php if ( ! empty( $recent ) ) : ?>
			<p class="psbdx-dw-section-heading"><?php esc_html_e( 'Recent Reports', 'psbdx-smart-report-management' ); ?></p>
			<ul class="psbdx-dw-list">
				<?php foreach ( $recent as $report ) :
					$rs    = get_post_meta( $report->ID, '_psbdx_report_status', true ) ?: 'Processing';
					$sd    = isset( $statuses[ $rs ] ) ? $statuses[ $rs ] : array(
						'label' => PSBDX_SRM_Helpers::get_status_label( $rs ),
						'bg'    => '#e2e8f0',
						'color' => '#475569',
					);
					$elink = get_edit_post_link( $report->ID );
				?>
				<li class="psbdx-dw-list-item">
					<?php if ( $elink ) : ?>
					<a href="<?php echo esc_url( $elink ); ?>" class="psbdx-dw-list-title">
						<?php echo esc_html( $report->post_title ); ?>
					</a>
					<?php else : ?>
					<span class="psbdx-dw-list-title"><?php echo esc_html( $report->post_title ); ?></span>
					<?php endif; ?>
					<span class="psbdx-dw-badge" style="background:<?php echo esc_attr( $sd['bg'] ); ?>;color:<?php echo esc_attr( $sd['color'] ); ?>;">
						<?php echo esc_html( $sd['label'] ); ?>
					</span>
					<span class="psbdx-dw-date"><?php echo esc_html( get_the_date( 'd M', $report->ID ) ); ?></span>
				</li>
				<?php endforeach; ?>
			</ul>
			<?php endif; ?>

			<div class="psbdx-dw-footer">
				<a href="<?php echo esc_url( $logs_url ); ?>" class="button button-primary button-small">
					<?php esc_html_e( 'View All Reports', 'psbdx-smart-report-management' ); ?> &rarr;
				</a>
			</div>

		</div>
		<?php
	}

	/**
	 * Count reports that are not in Solved status.
	 *
	 * Cached as a 2-minute transient to avoid repeated DB hits from the admin bar.
	 *
	 * @since  1.3.1
	 * @return int
	 */
	public static function get_unsolved_count() {
		$cached = get_transient( 'psrm_unsolved_count' );

		if ( false !== $cached ) {
			return (int) $cached;
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- cached in transient above.
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts} p
				WHERE p.post_type   = %s
				  AND p.post_status = 'publish'
				  AND NOT EXISTS (
					  SELECT 1 FROM {$wpdb->postmeta} pm
					  WHERE pm.post_id   = p.ID
					    AND pm.meta_key  = %s
					    AND pm.meta_value = %s
				  )",
				'psbdx_report_log',
				'_psbdx_report_status',
				'Solved'
			)
		);

		set_transient( 'psrm_unsolved_count', $count, 2 * MINUTE_IN_SECONDS );

		return $count;
	}

	/**
	 * Retrieve the most recent report log posts.
	 *
	 * @since  1.0.0
	 * @param  int $limit  Number of posts to retrieve.
	 * @return WP_Post[]   Array of post objects.
	 */
	private function get_recent_reports( $limit ) {
		return get_posts( array(
			'post_type'      => 'psbdx_report_log',
			'post_status'    => 'publish',
			'numberposts'    => absint( $limit ),
			'orderby'        => 'date',
			'order'          => 'DESC',
		) );
	}
}
