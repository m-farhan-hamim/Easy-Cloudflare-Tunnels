<?php
/**
 * Admin columns and asset loading for PSBDx Smart Report Management.
 *
 * @package PSBDx_Smart_Report_Management
 * @since   1.0.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class PSBDX_SRM_Admin
 *
 * Handles admin list table columns for the report_log post type,
 * and enqueues admin-side CSS/JS.
 *
 * @since 1.0.0
 */
class PSBDX_SRM_Admin {

	/**
	 * Constructor — registers all admin hooks.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		add_filter( 'manage_psbdx_report_log_posts_columns',          array( $this, 'set_columns' ) );
		add_action( 'manage_psbdx_report_log_posts_custom_column',    array( $this, 'render_column' ), 10, 2 );
		add_filter( 'manage_edit-psbdx_report_log_sortable_columns',  array( $this, 'sortable_columns' ) );
		add_action( 'pre_get_posts',                                  array( $this, 'sort_logs_by_status' ) );
		add_action( 'pre_get_posts',                                  array( $this, 'search_by_ticket_id' ) );
		add_action( 'admin_enqueue_scripts',                          array( $this, 'enqueue_assets' ) );
		add_filter( 'plugin_action_links_' . PSBDX_SRM_BASENAME,     array( $this, 'add_action_links' ) );
	}

	/**
	 * Adds Documentation, Settings, and Repair links next to Activate/Deactivate
	 * on the Plugins screen.
	 *
	 * @since  1.1.0
	 * @param  string[] $links  Existing action links.
	 * @return string[]         Modified action links.
	 */
	public function add_action_links( $links ) {
		$prepend = array();

		if ( current_user_can( 'manage_options' ) ) {
			$prepend[] = sprintf(
				'<a href="%s">%s</a>',
				esc_url( admin_url( 'admin.php?page=' . PSBDX_SRM_Admin_Tools::PAGE_SETTINGS ) ),
				esc_html__( 'Settings', 'psbdx-smart-report-management' )
			);

			$prepend[] = sprintf(
				'<a href="%s">%s</a>',
				esc_url( admin_url( 'admin.php?page=' . PSBDX_SRM_Admin_Tools::PAGE_REPAIR ) ),
				esc_html__( 'Repair & Reset', 'psbdx-smart-report-management' )
			);

			if ( class_exists( 'PSBDX_SRM_Setup_Wizard' ) ) {
				$prepend[] = sprintf(
					'<a href="%s">%s</a>',
					esc_url( PSBDX_SRM_Setup_Wizard::get_restart_url() ),
					esc_html__( 'Setup Wizard', 'psbdx-smart-report-management' )
				);
			}
		}

		$prepend[] = sprintf(
			'<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>',
			esc_url( 'https://dev.psbdx.xyz/documentations/psbdx-smart-report-managment/' ),
			esc_html__( 'Documentation', 'psbdx-smart-report-management' )
		);

		return array_merge( $prepend, $links );
	}

	/**
	 * Define columns for the Report Logs list table.
	 *
	 * @since  1.0.0
	 * @param  array $columns  Default columns.
	 * @return array           Modified columns.
	 */
	public function set_columns( $columns ) {
		return array(
			'cb'       => $columns['cb'],
			'title'    => __( 'Report Summary', 'psbdx-smart-report-management' ),
			'reporter' => __( 'Reporter',        'psbdx-smart-report-management' ),
			'order'    => __( 'Order',           'psbdx-smart-report-management' ),
			'category' => __( 'Category',        'psbdx-smart-report-management' ),
			'priority' => __( 'Priority',        'psbdx-smart-report-management' ),
			'status'   => __( 'Status',          'psbdx-smart-report-management' ),
			'source'   => __( 'Reported Item',   'psbdx-smart-report-management' ),
			'date'     => $columns['date'],
		);
	}

	/**
	 * Mark the Status column as sortable.
	 *
	 * @since  1.0.0
	 * @param  array $columns  Sortable columns array.
	 * @return array
	 */
	public function sortable_columns( $columns ) {
		$columns['status'] = 'status';
		return $columns;
	}

	/**
	 * Sort Report Logs list table by status meta when the Status column is clicked.
	 *
	 * @since 1.1.0
	 * @param WP_Query $query Main query instance.
	 * @return void
	 */
	public function sort_logs_by_status( $query ) {
		if ( ! is_admin() || ! $query->is_main_query() ) {
			return;
		}

		if ( 'psbdx_report_log' !== $query->get( 'post_type' ) ) {
			return;
		}

		if ( 'status' !== $query->get( 'orderby' ) ) {
			return;
		}

		$query->set( 'meta_key', '_psbdx_report_status' );
		$query->set( 'orderby', 'meta_value' );
	}

	/**
	 * Lets admins paste a ticket ID (e.g. "PSRM-20260714-8K3F2A") straight
	 * into the Report Logs search box and find the exact report, instead of
	 * it falling through to the default (unhelpful) title/content search.
	 *
	 * @since  1.4.1
	 * @param  WP_Query $query  Current query.
	 * @return void
	 */
	public function search_by_ticket_id( $query ) {
		if ( ! is_admin() || ! $query->is_main_query() ) {
			return;
		}

		if ( 'psbdx_report_log' !== $query->get( 'post_type' ) ) {
			return;
		}

		$search = trim( (string) $query->get( 's' ) );

		if ( '' === $search || 0 !== stripos( $search, 'PSRM-' ) ) {
			return;
		}

		$query->set( 's', '' );
		$query->set(
			'meta_query', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			array(
				array(
					'key'     => PSBDX_SRM_Helpers::TICKET_ID_META,
					'value'   => sanitize_text_field( $search ),
					'compare' => '=',
				),
			)
		);
	}

	/**
	 * Output content for each custom column row.
	 *
	 * @since  1.0.0
	 * @param  string $column   Column slug.
	 * @param  int    $post_id  Current post ID.
	 * @return void
	 */
	public function render_column( $column, $post_id ) {
		switch ( $column ) {
			case 'reporter':
				$this->render_reporter_column( $post_id );
				break;

			case 'order':
				$this->render_order_column( $post_id );
				break;

			case 'status':
				$this->render_status_column( $post_id );
				break;

			case 'source':
				$this->render_source_column( $post_id );
				break;

			case 'category':
				$this->render_category_column( $post_id );
				break;

			case 'priority':
				$this->render_priority_column( $post_id );
				break;
		}
	}

	/**
	 * Render the Reporter column — shows avatar + display name.
	 *
	 * @since  1.0.0
	 * @param  int $post_id  Post ID.
	 * @return void
	 */
	private function render_reporter_column( $post_id ) {
		$post = get_post( $post_id );

		if ( $post && $post->post_author ) {
			$user = get_userdata( (int) $post->post_author );

			if ( $user ) {
				echo '<div class="psbdx-admin-reporter">';
				echo get_avatar( $user->ID, 24, '', '', array( 'class' => 'psbdx-admin-avatar' ) );
				echo '<span>' . esc_html( $user->display_name ) . '</span>';
				echo '</div>';
				return;
			}
		}

		echo '<span class="psbdx-admin-muted">' . esc_html__( 'Guest', 'psbdx-smart-report-management' ) . '</span>';
	}

	/**
	 * Render the Order column — linked badge if an order is attached.
	 *
	 * @since  1.0.0
	 * @param  int $post_id  Post ID.
	 * @return void
	 */
	private function render_order_column( $post_id ) {
		$order_id = get_post_meta( $post_id, '_psbdx_woo_order_id', true );

		if ( $order_id ) {
			$url = PSBDX_SRM_Helpers::get_order_edit_url( (int) $order_id );
			printf(
				'<a href="%s" target="_blank"><span class="psbdx-badge psbdx-badge-purple">#%s</span></a>',
				esc_url( $url ),
				esc_html( $order_id )
			);
		} else {
			echo '<span class="psbdx-admin-muted">&mdash;</span>';
		}
	}

	/**
	 * Render the Status column — coloured badge.
	 *
	 * @since  1.0.0
	 * @param  int $post_id  Post ID.
	 * @return void
	 */
	private function render_status_column( $post_id ) {
		$status = get_post_meta( $post_id, '_psbdx_report_status', true );
		$status = $status ? $status : 'Processing';
		$label  = PSBDX_SRM_Helpers::get_status_label( $status );
		$style  = trim( PSBDX_SRM_Helpers::get_status_inline_style( $status ) ) . ' padding:4px 10px;border-radius:999px;';

		printf(
			'<span class="psbdx-badge" style="%1$s">%2$s</span>',
			esc_attr( $style ),
			esc_html( $label )
		);
	}

	/**
	 * Render the Source column — linked item title.
	 *
	 * @since  1.0.0
	 * @param  int $post_id  Post ID.
	 * @return void
	 */
	private function render_source_column( $post_id ) {
		$url   = get_post_meta( $post_id, '_psbdx_source_url',   true );
		$title = get_post_meta( $post_id, '_psbdx_source_title', true );

		if ( $url ) {
			printf(
				'<a href="%s" target="_blank" rel="noopener noreferrer">%s <span class="dashicons dashicons-external" aria-hidden="true"></span></a>',
				esc_url( $url ),
				esc_html( $title ? $title : $url )
			);
		} else {
			echo '<span class="psbdx-admin-muted">&mdash;</span>';
		}
	}

	/**
	 * Render the Category column.
	 *
	 * @since  1.4.1
	 * @param  int $post_id  Post ID.
	 * @return void
	 */
	private function render_category_column( $post_id ) {
		$category = get_post_meta( $post_id, '_psbdx_report_category', true );

		if ( $category ) {
			echo '<span class="psbdx-badge psbdx-badge-grey">' . esc_html( $category ) . '</span>';
		} else {
			echo '<span class="psbdx-admin-muted">&mdash;</span>';
		}
	}

	/**
	 * Render the Priority column — coloured badge.
	 *
	 * @since  1.4.1
	 * @param  int $post_id  Post ID.
	 * @return void
	 */
	private function render_priority_column( $post_id ) {
		$priority = get_post_meta( $post_id, '_psbdx_report_priority', true );
		$priority = $priority ? $priority : 'Medium';
		$label    = isset( PSBDX_SRM_Helpers::get_report_priorities()[ $priority ] ) ? PSBDX_SRM_Helpers::get_report_priorities()[ $priority ] : $priority;
		$style    = trim( PSBDX_SRM_Helpers::get_priority_badge_style( $priority ) ) . ' padding:4px 10px;border-radius:999px;';

		printf(
			'<span class="psbdx-badge" style="%1$s">%2$s</span>',
			esc_attr( $style ),
			esc_html( $label )
		);
	}

	/**
	 * Enqueue admin CSS on Report Form and Report Log screens.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	public function enqueue_assets() {
		$screen = get_current_screen();

		if ( ! $screen ) {
			return;
		}

		$is_tools_screen = $screen->id && (
			false !== strpos( $screen->id, 'psbdx-srm-settings' )
			|| false !== strpos( $screen->id, 'psbdx-srm-repair' )
			|| false !== strpos( $screen->id, 'psbdx-srm-support' )
			|| false !== strpos( $screen->id, 'psbdx-srm-ai-log' )
			|| false !== strpos( $screen->id, 'psbdx-srm-faq' )
		);

		if ( ! $is_tools_screen && ! in_array( $screen->post_type, array( 'psbdx_report_form', 'psbdx_report_log' ), true ) ) {
			return;
		}

		wp_enqueue_style(
			'psbdx-srm-admin',
			PSBDX_SRM_URL . 'assets/css/admin.css',
			array(),
			psbdx_srm_asset_ver( 'assets/css/admin.css' )
		);

		wp_enqueue_script(
			'psbdx-srm-admin',
			PSBDX_SRM_URL . 'assets/js/admin.js',
			array(),
			psbdx_srm_asset_ver( 'assets/js/admin.js' ),
			true
		);
	}
}
