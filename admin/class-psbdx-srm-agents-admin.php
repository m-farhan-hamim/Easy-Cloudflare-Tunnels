<?php
/**
 * "Support Agents" admin submenu for PSBDx Smart Report Management.
 *
 * @package PSBDx_Smart_Report_Management
 * @since   1.4.5
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class PSBDX_SRM_Agents_Admin
 *
 * @since 1.4.5
 */
class PSBDX_SRM_Agents_Admin {

	/**
	 * Submenu slug.
	 *
	 * @since 1.4.5
	 * @var string
	 */
	const PAGE = 'psbdx-srm-agents';

	/**
	 * Constructor.
	 *
	 * @since 1.4.5
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register_page' ), 100 );
		add_action( 'admin_init', array( $this, 'handle_actions' ) );
	}

	/**
	 * Registers the "Support Agents" submenu page. Menu-level access is
	 * capped at manage_options (a WordPress Administrator) — plain agents
	 * manage their own work hours from the [psbdx_user_reports] frontend
	 * tab instead, they don't get a wp-admin screen.
	 *
	 * @since 1.4.5
	 * @return void
	 */
	public function register_page() {
		add_submenu_page(
			PSBDX_SRM_Post_Types::ADMIN_MENU_SLUG,
			__( 'Support Agents', 'psbdx-smart-report-management' ),
			__( 'Support Agents', 'psbdx-smart-report-management' ),
			'manage_options',
			self::PAGE,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Handles all form submissions from the Support Agents page.
	 *
	 * @since 1.4.5
	 * @return void
	 */
	public function handle_actions() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( isset( $_POST['psbdx_srm_agent_add'] ) ) {
			$this->handle_add();
		} elseif ( isset( $_POST['psbdx_srm_agent_remove'] ) ) {
			$this->handle_remove();
		} elseif ( isset( $_POST['psbdx_srm_agent_save_hours'] ) ) {
			$this->handle_save_hours();
		} elseif ( isset( $_POST['psbdx_srm_agent_toggle_super'] ) ) {
			$this->handle_toggle_super();
		}
	}

	/**
	 * Adds a user (found by email or username) as a support agent.
	 *
	 * @since 1.4.5
	 * @return void
	 */
	private function handle_add() {
		check_admin_referer( 'psbdx_srm_agents_settings' );

		$identifier = isset( $_POST['psbdx_srm_agent_identifier'] ) ? sanitize_text_field( wp_unslash( $_POST['psbdx_srm_agent_identifier'] ) ) : '';

		$user = is_email( $identifier ) ? get_user_by( 'email', $identifier ) : get_user_by( 'login', $identifier );

		if ( ! $user ) {
			add_settings_error( 'psbdx_srm_agents', 'not_found', __( 'No user found with that username or email.', 'psbdx-smart-report-management' ), 'error' );
			return;
		}

		PSBDX_SRM_Agents::add_agent( $user->ID, get_current_user_id() );

		add_settings_error( 'psbdx_srm_agents', 'added', sprintf(
			/* translators: %s: user display name */
			__( '%s added as a support agent.', 'psbdx-smart-report-management' ),
			$user->display_name
		), 'success' );
	}

	/**
	 * Removes an agent, subject to can_manage_target() permission rules.
	 *
	 * @since 1.4.5
	 * @return void
	 */
	private function handle_remove() {
		check_admin_referer( 'psbdx_srm_agents_settings' );

		$target_id = isset( $_POST['psbdx_srm_agent_user_id'] ) ? (int) $_POST['psbdx_srm_agent_user_id'] : 0;

		if ( ! PSBDX_SRM_Agents::can_manage_target( get_current_user_id(), $target_id ) ) {
			add_settings_error( 'psbdx_srm_agents', 'not_allowed', __( 'Only a Super Administrator can manage another administrator.', 'psbdx-smart-report-management' ), 'error' );
			return;
		}

		PSBDX_SRM_Agents::remove_agent( $target_id );
		add_settings_error( 'psbdx_srm_agents', 'removed', __( 'Agent removed.', 'psbdx-smart-report-management' ), 'success' );
	}

	/**
	 * Saves a user's work hours, subject to can_manage_target().
	 *
	 * @since 1.4.5
	 * @return void
	 */
	private function handle_save_hours() {
		check_admin_referer( 'psbdx_srm_agents_settings' );

		$target_id = isset( $_POST['psbdx_srm_agent_user_id'] ) ? (int) $_POST['psbdx_srm_agent_user_id'] : 0;

		if ( ! PSBDX_SRM_Agents::can_manage_target( get_current_user_id(), $target_id ) ) {
			add_settings_error( 'psbdx_srm_agents', 'not_allowed', __( 'You are not allowed to edit this agent\'s hours.', 'psbdx-smart-report-management' ), 'error' );
			return;
		}

		$hours = array();
		for ( $d = 0; $d <= 6; $d++ ) {
			$hours[ $d ] = array(
				'enabled' => ! empty( $_POST[ 'psbdx_srm_hours_enabled_' . $d ] ),
				'start'   => isset( $_POST[ 'psbdx_srm_hours_start_' . $d ] ) ? sanitize_text_field( wp_unslash( $_POST[ 'psbdx_srm_hours_start_' . $d ] ) ) : '09:00',
				'end'     => isset( $_POST[ 'psbdx_srm_hours_end_' . $d ] ) ? sanitize_text_field( wp_unslash( $_POST[ 'psbdx_srm_hours_end_' . $d ] ) ) : '18:00',
			);
		}

		PSBDX_SRM_Agents::set_work_hours( $target_id, $hours );
		add_settings_error( 'psbdx_srm_agents', 'hours_saved', __( 'Work hours saved.', 'psbdx-smart-report-management' ), 'success' );
	}

	/**
	 * Promotes/demotes plugin-level Super Administrator status. Only an
	 * existing super admin can do this.
	 *
	 * @since 1.4.5
	 * @return void
	 */
	private function handle_toggle_super() {
		check_admin_referer( 'psbdx_srm_agents_settings' );

		if ( ! PSBDX_SRM_Agents::is_super_admin( get_current_user_id() ) ) {
			add_settings_error( 'psbdx_srm_agents', 'not_allowed', __( 'Only a Super Administrator can promote or demote other administrators.', 'psbdx-smart-report-management' ), 'error' );
			return;
		}

		$target_id = isset( $_POST['psbdx_srm_agent_user_id'] ) ? (int) $_POST['psbdx_srm_agent_user_id'] : 0;

		if ( PSBDX_SRM_Agents::is_super_admin( $target_id ) ) {
			PSBDX_SRM_Agents::remove_super_admin( $target_id );
			add_settings_error( 'psbdx_srm_agents', 'super_removed', __( 'Super Administrator status removed.', 'psbdx-smart-report-management' ), 'success' );
		} else {
			PSBDX_SRM_Agents::add_super_admin( $target_id );
			add_settings_error( 'psbdx_srm_agents', 'super_added', __( 'Super Administrator status granted.', 'psbdx-smart-report-management' ), 'success' );
		}
	}

	/**
	 * Renders the day-of-week work hours editor rows, shared by the
	 * wp-admin page and — via PSBDX_SRM_Shortcodes — the frontend Manage
	 * Agents tab.
	 *
	 * @since  1.4.5
	 * @param  int $user_id  WP user ID the editor is for.
	 * @return void
	 */
	public static function render_hours_fields( $user_id ) {
		$hours = PSBDX_SRM_Agents::get_work_hours( $user_id );
		$days  = array(
			0 => __( 'Sunday', 'psbdx-smart-report-management' ),
			1 => __( 'Monday', 'psbdx-smart-report-management' ),
			2 => __( 'Tuesday', 'psbdx-smart-report-management' ),
			3 => __( 'Wednesday', 'psbdx-smart-report-management' ),
			4 => __( 'Thursday', 'psbdx-smart-report-management' ),
			5 => __( 'Friday', 'psbdx-smart-report-management' ),
			6 => __( 'Saturday', 'psbdx-smart-report-management' ),
		);

		foreach ( $days as $d => $label ) {
			$day = isset( $hours[ (string) $d ] ) ? $hours[ (string) $d ] : ( isset( $hours[ $d ] ) ? $hours[ $d ] : array() );
			$on  = ! empty( $day['enabled'] );
			$start = isset( $day['start'] ) ? $day['start'] : '09:00';
			$end   = isset( $day['end'] ) ? $day['end'] : '18:00';
			?>
			<div class="psbdx-hours-row">
				<label>
					<input type="checkbox" name="psbdx_srm_hours_enabled_<?php echo (int) $d; ?>" value="1" <?php checked( $on ); ?>>
					<?php echo esc_html( $label ); ?>
				</label>
				<input type="time" name="psbdx_srm_hours_start_<?php echo (int) $d; ?>" value="<?php echo esc_attr( $start ); ?>">
				<span>&ndash;</span>
				<input type="time" name="psbdx_srm_hours_end_<?php echo (int) $d; ?>" value="<?php echo esc_attr( $end ); ?>">
			</div>
			<?php
		}
	}

	/**
	 * Renders the Support Agents page.
	 *
	 * @since 1.4.5
	 * @return void
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$agents   = PSBDX_SRM_Agents::get_all_agents();
		$actor_id = get_current_user_id();
		$is_super = PSBDX_SRM_Agents::is_super_admin( $actor_id );
		?>
		<div class="wrap psbdx-srm-wrap">
			<style>
				.psbdx-agent-stars { color: #d9a300; letter-spacing: 1px; white-space: nowrap; }
				.psbdx-agent-stars-num { color: #666; font-size: 11px; letter-spacing: 0; }
			</style>
			<h1><?php esc_html_e( 'Support Agents', 'psbdx-smart-report-management' ); ?></h1>
			<p class="description">
				<?php esc_html_e( 'Manage who can be automatically assigned reports and reply on their behalf. Administrators are added here automatically.', 'psbdx-smart-report-management' ); ?>
				<?php if ( $is_super ) : ?>
					<strong><?php esc_html_e( 'You are a Super Administrator.', 'psbdx-smart-report-management' ); ?></strong>
				<?php endif; ?>
			</p>

			<?php settings_errors( 'psbdx_srm_agents' ); ?>

			<h2><?php esc_html_e( 'Add a Support Agent', 'psbdx-smart-report-management' ); ?></h2>
			<form method="post" class="psbdx-srm-inline-form">
				<?php wp_nonce_field( 'psbdx_srm_agents_settings' ); ?>
				<input type="text" name="psbdx_srm_agent_identifier" placeholder="<?php esc_attr_e( 'Username or email', 'psbdx-smart-report-management' ); ?>" required>
				<button type="submit" name="psbdx_srm_agent_add" value="1" class="button button-primary"><?php esc_html_e( 'Add Agent', 'psbdx-smart-report-management' ); ?></button>
			</form>

			<h2><?php esc_html_e( 'Current Agents', 'psbdx-smart-report-management' ); ?></h2>
			<table class="widefat striped">
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
					$has_hours  = ! empty( $agent['work_hours'] );
					?>
					<tr<?php echo $agent['excluded'] ? ' style="opacity:.5;"' : ''; ?>>
						<td>
							<?php echo get_avatar( $agent['user_id'], 24 ); ?>
							<?php echo esc_html( $agent['user']->display_name ); ?>
							<?php if ( $agent['excluded'] ) : ?>
								<em>(<?php esc_html_e( 'removed', 'psbdx-smart-report-management' ); ?>)</em>
							<?php endif; ?>
						</td>
						<td><?php echo esc_html( $role_label ); ?></td>
						<td><?php echo PSBDX_SRM_Agents::render_stars( $agent['rating'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped internally. ?></td>
						<td><?php echo $has_hours ? esc_html__( 'Custom hours set', 'psbdx-smart-report-management' ) : esc_html__( 'Always available', 'psbdx-smart-report-management' ); ?></td>
						<td>
							<?php if ( $can_manage ) : ?>
								<details>
									<summary><?php esc_html_e( 'Edit Hours', 'psbdx-smart-report-management' ); ?></summary>
									<form method="post">
										<?php wp_nonce_field( 'psbdx_srm_agents_settings' ); ?>
										<input type="hidden" name="psbdx_srm_agent_user_id" value="<?php echo (int) $agent['user_id']; ?>">
										<?php self::render_hours_fields( $agent['user_id'] ); ?>
										<button type="submit" name="psbdx_srm_agent_save_hours" value="1" class="button"><?php esc_html_e( 'Save Hours', 'psbdx-smart-report-management' ); ?></button>
									</form>
								</details>
								<form method="post" style="display:inline;" onsubmit="return confirm('<?php echo esc_js( __( 'Remove this agent?', 'psbdx-smart-report-management' ) ); ?>');">
									<?php wp_nonce_field( 'psbdx_srm_agents_settings' ); ?>
									<input type="hidden" name="psbdx_srm_agent_user_id" value="<?php echo (int) $agent['user_id']; ?>">
									<button type="submit" name="psbdx_srm_agent_remove" value="1" class="button-link-delete"><?php esc_html_e( 'Remove', 'psbdx-smart-report-management' ); ?></button>
								</form>
								<?php if ( $is_super && $agent['is_admin'] ) : ?>
									<form method="post" style="display:inline;">
										<?php wp_nonce_field( 'psbdx_srm_agents_settings' ); ?>
										<input type="hidden" name="psbdx_srm_agent_user_id" value="<?php echo (int) $agent['user_id']; ?>">
										<button type="submit" name="psbdx_srm_agent_toggle_super" value="1" class="button">
											<?php echo $agent['is_super'] ? esc_html__( 'Revoke Super Admin', 'psbdx-smart-report-management' ) : esc_html__( 'Make Super Admin', 'psbdx-smart-report-management' ); ?>
										</button>
									</form>
								<?php endif; ?>
							<?php else : ?>
								<em><?php esc_html_e( 'Only a Super Administrator can manage this account.', 'psbdx-smart-report-management' ); ?></em>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}
}
