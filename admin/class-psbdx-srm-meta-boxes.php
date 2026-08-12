<?php
/**
 * Meta boxes for PSBDx Smart Report Management.
 *
 * @package PSBDx_Smart_Report_Management
 * @since   1.0.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class PSBDX_SRM_Meta_Boxes
 *
 * Registers and renders all meta boxes for psbdx_report_form
 * and psbdx_report_log post types, and handles saving their data.
 *
 * @since 1.0.0
 */
class PSBDX_SRM_Meta_Boxes {

	/**
	 * Nonce action used for all meta box saves.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	const NONCE_ACTION = 'psbdx_srm_save_meta';

	/**
	 * Nonce field name used for all meta box saves.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	const NONCE_FIELD = 'psbdx_srm_meta_nonce';

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		add_action( 'add_meta_boxes',        array( $this, 'register' ) );
		add_action( 'save_post',             array( $this, 'save' ) );
		add_action( 'edit_form_after_title', array( $this, 'render_ticket_header' ) );
		add_action( 'wp_ajax_psbdx_srm_summarize_report',   array( $this, 'ajax_summarize_report' ) );
		add_action( 'wp_ajax_psbdx_srm_add_admin_reply',    array( $this, 'ajax_add_admin_reply' ) );
		add_action( 'wp_ajax_psbdx_srm_improve_reply',      array( $this, 'ajax_improve_reply' ) );
		add_action( 'wp_ajax_psbdx_srm_generate_ai_reply',  array( $this, 'ajax_generate_ai_reply' ) );
		add_action( 'wp_ajax_psbdx_srm_toggle_ai_reply',    array( $this, 'ajax_toggle_ai_reply' ) );
	}

	/**
	 * Register all meta boxes.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	public function register() {
		// Report Form meta boxes.
		add_meta_box(
			'psbdx_srm_form_config',
			__( 'Report Form Configuration', 'psbdx-smart-report-management' ),
			array( $this, 'render_form_config' ),
			'psbdx_report_form',
			'normal',
			'high'
		);

		add_meta_box(
			'psbdx_srm_shortcode',
			__( 'Shortcode', 'psbdx-smart-report-management' ),
			array( $this, 'render_shortcode_box' ),
			'psbdx_report_form',
			'side',
			'high'
		);

		// Report Log meta boxes.
		add_meta_box(
			'psbdx_srm_log_details',
			__( 'Report Details', 'psbdx-smart-report-management' ),
			array( $this, 'render_log_details' ),
			'psbdx_report_log',
			'normal',
			'high'
		);

		add_meta_box(
			'psbdx_srm_log_status',
			__( 'Report Status', 'psbdx-smart-report-management' ),
			array( $this, 'render_log_status' ),
			'psbdx_report_log',
			'side',
			'high'
		);

		add_meta_box(
			'psbdx_srm_log_classification',
			__( 'Category & Priority', 'psbdx-smart-report-management' ),
			array( $this, 'render_log_classification' ),
			'psbdx_report_log',
			'side',
			'default'
		);

		add_meta_box(
			'psbdx_srm_log_replies',
			__( 'Conversation', 'psbdx-smart-report-management' ),
			array( $this, 'render_log_replies' ),
			'psbdx_report_log',
			'normal',
			'default'
		);

		// LearnPress integration meta box (only when LearnPress CPTs exist).
		if ( post_type_exists( 'lp_course' ) ) {
			foreach ( array( 'lp_course', 'lp_lesson', 'lp_quiz' ) as $screen ) {
				if ( ! post_type_exists( $screen ) ) {
					continue;
				}
				add_meta_box(
					'psbdx_srm_lp_integration',
					__( 'PSBDx Report Button', 'psbdx-smart-report-management' ),
					array( $this, 'render_lp_integration' ),
					$screen,
					'side',
					'low'
				);
			}
		}
	}

	// =========================================================================
	// RENDER — REPORT FORM
	// =========================================================================

	/**
	 * Render the Shortcode helper meta box.
	 *
	 * @since  1.0.0
	 * @param  WP_Post $post  Current post object.
	 * @return void
	 */
	public function render_shortcode_box( $post ) {
		$shortcode        = sprintf( '[psbdx_report id="%d"]', $post->ID );
		$inline_shortcode = sprintf( '[psbdx_report id="%d" mode="inline"]', $post->ID );
		?>
		<p class="psbdx-meta-hint"><?php esc_html_e( 'Button that opens a popup form — copy and paste anywhere on your site:', 'psbdx-smart-report-management' ); ?></p>
		<div class="psbdx-copy-row">
			<code id="psbdx-sc-<?php echo esc_attr( $post->ID ); ?>"><?php echo esc_html( $shortcode ); ?></code>
			<button type="button" class="button button-small psbdx-copy-btn" data-target="psbdx-sc-<?php echo esc_attr( $post->ID ); ?>">
				<?php esc_html_e( 'Copy', 'psbdx-smart-report-management' ); ?>
			</button>
		</div>
		<p class="psbdx-meta-hint" style="margin-top:12px;"><?php esc_html_e( 'Or embed the form directly in the page (no button, always visible):', 'psbdx-smart-report-management' ); ?></p>
		<div class="psbdx-copy-row">
			<code id="psbdx-sc-inline-<?php echo esc_attr( $post->ID ); ?>"><?php echo esc_html( $inline_shortcode ); ?></code>
			<button type="button" class="button button-small psbdx-copy-btn" data-target="psbdx-sc-inline-<?php echo esc_attr( $post->ID ); ?>">
				<?php esc_html_e( 'Copy', 'psbdx-smart-report-management' ); ?>
			</button>
		</div>
		<p class="psbdx-meta-hint" style="margin-top:12px;">
			<?php esc_html_e( 'User reports table:', 'psbdx-smart-report-management' ); ?>
			<code>[psbdx_user_reports]</code>
		</p>
		<p class="psbdx-meta-hint" style="margin-top:12px;">
			<?php esc_html_e( 'Want this form to pop up as an overlay on any URL instead? Turn on "Enable popup link" under the Settings tab below.', 'psbdx-smart-report-management' ); ?>
		</p>
		<?php
	}

	/**
	 * Render the Report Form configuration meta box.
	 *
	 * @since  1.0.0
	 * @param  WP_Post $post  Current post object.
	 * @return void
	 */
	public function render_form_config( $post ) {
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD );

		$btn_text      = get_post_meta( $post->ID, '_psbdx_btn_text',         true ) ?: __( 'Report Issue', 'psbdx-smart-report-management' );
		$show_identity = get_post_meta( $post->ID, '_psbdx_show_identity',     true );
		$show_identity = ( '' === $show_identity ) ? 'yes' : $show_identity;
		$contact_label = get_post_meta( $post->ID, '_psbdx_contact_label',     true ) ?: __( 'WhatsApp Number', 'psbdx-smart-report-management' );
		$contact_req   = get_post_meta( $post->ID, '_psbdx_contact_required',  true );
		$reasons       = get_post_meta( $post->ID, '_psbdx_reasons',           true ) ?: __( 'Product not Working, Order not Delivered, Want to Cancel', 'psbdx-smart-report-management' );
		$custom_fields = get_post_meta( $post->ID, '_psbdx_custom_fields',     true );
		$cooldown_raw  = get_post_meta( $post->ID, '_psbdx_cooldown_mins',     true );
		$cooldown_mins = PSBDX_SRM_Helpers::get_effective_cooldown_mins( $post->ID );
		$is_global_cd  = ( '' === $cooldown_raw || null === $cooldown_raw );
		$is_order_form    = ( get_option( 'psbdx_global_order_form_id' )   == $post->ID );
		$is_product_form  = ( get_option( 'psbdx_global_product_form_id' ) == $post->ID );
		$captcha_enabled  = get_post_meta( $post->ID, '_psbdx_captcha_enabled', true );
		$captcha_enabled  = ( '' === $captcha_enabled ) ? 'no' : $captcha_enabled;
		$active_provider  = PSBDX_SRM_Captcha::active_provider();
		?>

		<div class="psbdx-meta-sections">

			<?php $this->section_open( 'dashicons-button', __( 'Button Settings', 'psbdx-smart-report-management' ) ); ?>
				<?php $this->field_text( 'psbdx_btn_text', __( 'Button Label', 'psbdx-smart-report-management' ), $btn_text, __( 'e.g. Report Issue', 'psbdx-smart-report-management' ) ); ?>
			<?php $this->section_close(); ?>

			<?php $this->section_open( 'dashicons-id', __( 'User Identity Display', 'psbdx-smart-report-management' ) ); ?>
				<p class="psbdx-meta-hint"><?php esc_html_e( 'Name and email are always collected server-side from the WordPress session. This controls whether the user sees a read-only identity card in the form.', 'psbdx-smart-report-management' ); ?></p>
				<?php $this->field_checkbox( 'psbdx_show_identity', __( "Show reporter's name and email in the form (read-only)", 'psbdx-smart-report-management' ), 'yes', ( 'yes' === $show_identity ) ); ?>
			<?php $this->section_close(); ?>

			<?php $this->section_open( 'dashicons-phone', __( 'Contact Field', 'psbdx-smart-report-management' ) ); ?>
				<?php $this->field_text( 'psbdx_contact_label', __( 'Field Label', 'psbdx-smart-report-management' ), $contact_label, __( 'e.g. WhatsApp Number', 'psbdx-smart-report-management' ) ); ?>
				<?php $this->field_checkbox( 'psbdx_contact_required', __( 'Make this field required', 'psbdx-smart-report-management' ), 'yes', ( 'yes' === $contact_req ) ); ?>
			<?php $this->section_close(); ?>

			<?php $this->section_open( 'dashicons-list-view', __( 'Report Reasons', 'psbdx-smart-report-management' ) ); ?>
				<p class="psbdx-meta-hint"><?php esc_html_e( 'Enter reasons separated by commas. "Other" is always appended automatically.', 'psbdx-smart-report-management' ); ?></p>
				<?php $this->field_textarea( 'psbdx_reasons', '', $reasons, __( 'Product not Working, Order not Delivered', 'psbdx-smart-report-management' ), 3 ); ?>
			<?php $this->section_close(); ?>

			<?php $this->section_open( 'dashicons-plus-alt2', __( 'Extra Fields (Optional)', 'psbdx-smart-report-management' ) ); ?>
				<p class="psbdx-meta-hint"><?php esc_html_e( 'Additional text fields shown in the form, comma-separated. e.g. Transaction ID, Coupon Code', 'psbdx-smart-report-management' ); ?></p>
				<?php $this->field_textarea( 'psbdx_custom_fields', '', $custom_fields, __( 'Transaction ID, Coupon Code', 'psbdx-smart-report-management' ), 2 ); ?>
			<?php $this->section_close(); ?>

			<?php $this->section_open( 'dashicons-clock', __( 'Report Cooldown (Rate Limiting)', 'psbdx-smart-report-management' ) ); ?>
				<p class="psbdx-meta-hint"><?php esc_html_e( 'Prevents the same logged-in user from re-submitting via this form until the cooldown expires. Set to 0 to disable.', 'psbdx-smart-report-management' ); ?></p>
				<p class="psbdx-meta-hint">
					<?php
					if ( $is_global_cd ) {
						/* translators: %d: global minutes */
						printf( esc_html__( 'This form is currently using the global rate limit (%d minutes). Saving a value here will override the global setting for this form.', 'psbdx-smart-report-management' ), (int) $cooldown_mins );
					} else {
						esc_html_e( 'This form has its own rate limit and overrides the global setting.', 'psbdx-smart-report-management' );
					}
					?>
				</p>
				<div class="psbdx-inline-field">
					<input type="number" name="psbdx_cooldown_mins" id="psbdx_cooldown_mins"
						value="<?php echo esc_attr( $cooldown_mins ); ?>"
						min="0" max="1440" class="small-text">
					<label for="psbdx_cooldown_mins"><?php esc_html_e( 'minutes', 'psbdx-smart-report-management' ); ?></label>
				</div>
			<?php $this->section_close(); ?>

			<?php $this->section_open( 'dashicons-admin-settings', __( 'Global Display Settings', 'psbdx-smart-report-management' ) ); ?>
				<?php $this->field_checkbox( 'psbdx_is_order_form',   __( 'Show automatically on all e-commerce Order pages',  'psbdx-smart-report-management' ), '1', $is_order_form ); ?>
				<?php $this->field_checkbox( 'psbdx_is_product_form', __( 'Show automatically on all Product and Course pages', 'psbdx-smart-report-management' ), '1', $is_product_form ); ?>
			<?php $this->section_close(); ?>

			<?php $this->section_open( 'dashicons-shield', __( 'Captcha Protection', 'psbdx-smart-report-management' ) ); ?>
				<?php if ( '' === $active_provider ) : ?>
					<div class="psbdx-notice-inline psbdx-notice-warn">
						<span class="dashicons dashicons-warning" aria-hidden="true"></span>
						<?php
						printf(
							/* translators: %s: link to captcha settings */
							esc_html__( 'No captcha provider is configured yet. %s to set one up first.', 'psbdx-smart-report-management' ),
							'<a href="' . esc_url( admin_url( 'admin.php?page=psbdx-srm-settings&tab=captcha' ) ) . '">' . esc_html__( 'Go to Captcha Settings', 'psbdx-smart-report-management' ) . '</a>'
						);
						?>
					</div>
				<?php else : ?>
					<p class="psbdx-meta-hint">
						<?php
						printf(
							/* translators: %1$s: provider label, %2$s: settings link */
							esc_html__( 'Active provider: %1$s. %2$s.', 'psbdx-smart-report-management' ),
							'<strong>' . esc_html( PSBDX_SRM_Captcha::label( $active_provider ) ) . '</strong>',
							'<a href="' . esc_url( admin_url( 'admin.php?page=psbdx-srm-settings&tab=captcha' ) ) . '">' . esc_html__( 'Change provider', 'psbdx-smart-report-management' ) . '</a>'
						);
						?>
					</p>
					<?php $this->field_checkbox( 'psbdx_captcha_enabled', __( 'Enable captcha on this form', 'psbdx-smart-report-management' ), 'yes', ( 'yes' === $captcha_enabled ) ); ?>
				<?php endif; ?>
			<?php $this->section_close(); ?>

		</div>
		<?php
	}

	// =========================================================================
	// RENDER — TICKET HEADER (at-a-glance strip under the title)
	// =========================================================================

	/**
	 * Renders a compact "at a glance" strip right under the title on the
	 * report edit screen: ticket ID, status, category, priority, reporter,
	 * and submission date — so an admin doesn't have to piece that together
	 * from several sidebar meta boxes.
	 *
	 * @since  1.4.1
	 * @param  WP_Post $post  Current post object (passed by edit_form_after_title).
	 * @return void
	 */
	public function render_ticket_header( $post ) {
		if ( ! $post || 'psbdx_report_log' !== $post->post_type ) {
			return;
		}

		$status     = get_post_meta( $post->ID, '_psbdx_report_status', true );
		$status     = $status ? $status : 'Processing';
		$statuses   = PSBDX_SRM_Helpers::get_statuses();
		$s_label    = isset( $statuses[ $status ] ) ? $statuses[ $status ]['label'] : $status;
		$s_style    = PSBDX_SRM_Helpers::get_status_inline_style( $status );
		$category   = get_post_meta( $post->ID, '_psbdx_report_category', true );
		$priority   = get_post_meta( $post->ID, '_psbdx_report_priority', true );
		$priority   = $priority ? $priority : 'Medium';
		$priorities = PSBDX_SRM_Helpers::get_report_priorities();
		$p_label    = isset( $priorities[ $priority ] ) ? $priorities[ $priority ] : $priority;
		$p_style    = trim( PSBDX_SRM_Helpers::get_priority_badge_style( $priority ) ) . 'padding:4px 10px;border-radius:999px;';
		$email      = get_post_meta( $post->ID, '_psbdx_reporter_email', true );
		$author     = $post->post_author
			? get_the_author_meta( 'display_name', $post->post_author )
			: __( 'Guest', 'psbdx-smart-report-management' );
		$ticket_id  = PSBDX_SRM_Helpers::get_ticket_id( $post->ID );
		?>
		<div class="psbdx-ticket-header">
			<?php if ( $ticket_id ) : ?>
				<span class="psbdx-badge psbdx-badge-ticket">
					<span class="dashicons dashicons-tag" aria-hidden="true"></span>
					<?php echo esc_html( $ticket_id ); ?>
				</span>
			<?php endif; ?>

			<span class="psbdx-badge" style="<?php echo esc_attr( trim( $s_style ) ); ?>"><?php echo esc_html( $s_label ); ?></span>

			<?php if ( $category ) : ?>
				<span class="psbdx-badge psbdx-badge-grey"><?php echo esc_html( $category ); ?></span>
			<?php endif; ?>

			<span class="psbdx-badge" style="<?php echo esc_attr( $p_style ); ?>"><?php echo esc_html( $p_label ); ?></span>

			<span class="psbdx-ticket-header-meta">
				<span class="dashicons dashicons-admin-users" aria-hidden="true"></span>
				<?php echo esc_html( $author ); ?>
				<?php if ( $email ) : ?>
					&middot; <?php echo esc_html( $email ); ?>
				<?php endif; ?>
				&middot; <?php echo esc_html( get_the_date( '', $post ) ); ?>
			</span>
		</div>
		<?php
	}

	// =========================================================================
	// RENDER — REPORT LOG
	// =========================================================================

	/**
	 * Render the Report Details meta box (read-only display).
	 *
	 * Redesigned in 1.4.1 into a "response card": reported item / linked
	 * order shown as compact chips, an optional AI summary panel with a
	 * Summarize button, and the submitted message in its own readable card.
	 *
	 * @since  1.0.0
	 * @param  WP_Post $post  Current post object.
	 * @return void
	 */
	public function render_log_details( $post ) {
		$source_url   = get_post_meta( $post->ID, '_psbdx_source_url',   true );
		$source_title = get_post_meta( $post->ID, '_psbdx_source_title', true );
		$order_id     = get_post_meta( $post->ID, '_psbdx_woo_order_id', true );
		$ai_summary   = get_post_meta( $post->ID, '_psbdx_report_ai_summary', true );
		$ai_ready     = PSBDX_SRM_AI::is_available();
		?>
		<div class="psbdx-response-card">

			<?php if ( $source_url || $order_id ) : ?>
			<div class="psbdx-response-chips">
				<?php if ( $source_url ) : ?>
				<span class="psbdx-response-chip">
					<span class="dashicons dashicons-admin-links" aria-hidden="true"></span>
					<a href="<?php echo esc_url( $source_url ); ?>" target="_blank" rel="noopener noreferrer">
						<?php echo esc_html( $source_title ? $source_title : $source_url ); ?>
					</a>
				</span>
				<?php endif; ?>

				<?php if ( $order_id ) : ?>
				<span class="psbdx-response-chip">
					<span class="dashicons dashicons-cart" aria-hidden="true"></span>
					<a href="<?php echo esc_url( PSBDX_SRM_Helpers::get_order_edit_url( (int) $order_id ) ); ?>" target="_blank" rel="noopener noreferrer">
						<?php
						printf(
							/* translators: %s: order ID */
							esc_html__( 'Order #%s', 'psbdx-smart-report-management' ),
							esc_html( $order_id )
						);
						?>
					</a>
				</span>
				<?php endif; ?>
			</div>
			<?php endif; ?>

			<?php if ( $ai_ready || $ai_summary ) : ?>
			<div class="psbdx-ai-summary-panel">
				<div class="psbdx-ai-summary-head">
					<span class="psbdx-ai-summary-title">
						<span class="dashicons dashicons-lightbulb" aria-hidden="true"></span>
						<?php esc_html_e( 'What is this actually about?', 'psbdx-smart-report-management' ); ?>
					</span>
					<?php if ( $ai_ready ) : ?>
					<button type="button" class="button button-small" id="psbdx-summarize-btn"
						data-post-id="<?php echo esc_attr( $post->ID ); ?>"
						data-nonce="<?php echo esc_attr( wp_create_nonce( 'psbdx_srm_summarize_report' ) ); ?>">
						<span class="dashicons dashicons-superhero" aria-hidden="true"></span>
						<?php echo $ai_summary ? esc_html__( 'Re-summarize', 'psbdx-smart-report-management' ) : esc_html__( 'Summarize with AI', 'psbdx-smart-report-management' ); ?>
					</button>
					<?php endif; ?>
				</div>
				<div id="psbdx-summarize-result" class="psbdx-ai-summary-body <?php echo $ai_summary ? '' : 'psbdx-is-empty'; ?>">
					<?php echo $ai_summary ? esc_html( $ai_summary ) : esc_html__( 'No summary yet.', 'psbdx-smart-report-management' ); ?>
				</div>
			</div>

			<script>
			(function () {
				var btn = document.getElementById( 'psbdx-summarize-btn' );
				if ( ! btn ) {
					return;
				}

				btn.addEventListener( 'click', function () {
					var result = document.getElementById( 'psbdx-summarize-result' );
					if ( ! result ) {
						return;
					}

					btn.disabled = true;
					result.classList.remove( 'psbdx-is-empty', 'psbdx-is-error' );
					result.textContent = '<?php echo esc_js( __( 'Summarizing…', 'psbdx-smart-report-management' ) ); ?>';

					var body = new URLSearchParams();
					body.append( 'action', 'psbdx_srm_summarize_report' );
					body.append( 'security', btn.getAttribute( 'data-nonce' ) );
					body.append( 'post_id', btn.getAttribute( 'data-post-id' ) );

					fetch( ajaxurl, { method: 'POST', body: body, credentials: 'same-origin' } )
						.then( function ( r ) { return r.json(); } )
						.then( function ( data ) {
							btn.disabled = false;
							if ( data.success ) {
								result.textContent = data.data.summary;
								btn.innerHTML = '<span class="dashicons dashicons-superhero" aria-hidden="true"></span> <?php echo esc_js( __( 'Re-summarize', 'psbdx-smart-report-management' ) ); ?>';
							} else {
								result.classList.add( 'psbdx-is-error' );
								result.textContent = data.data || '<?php echo esc_js( __( 'Could not summarize this report.', 'psbdx-smart-report-management' ) ); ?>';
							}
						} )
						.catch( function () {
							btn.disabled = false;
							result.classList.add( 'psbdx-is-error' );
							result.textContent = '<?php echo esc_js( __( 'Network error.', 'psbdx-smart-report-management' ) ); ?>';
						} );
				} );
			})();
			</script>
			<?php endif; ?>

			<div class="psbdx-response-message">
				<?php echo wp_kses_post( $post->post_content ); ?>
			</div>

		</div>
		<?php
	}

	/**
	 * AJAX: summarize a report's content with AI for the admin viewing it.
	 *
	 * @since  1.4.1
	 * @return void
	 */
	public function ajax_summarize_report() {
		check_ajax_referer( 'psbdx_srm_summarize_report', 'security' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'psbdx-smart-report-management' ) );
		}

		$post_id = isset( $_POST['post_id'] ) ? (int) $_POST['post_id'] : 0;

		if ( ! $post_id || 'psbdx_report_log' !== get_post_type( $post_id ) ) {
			wp_send_json_error( __( 'Invalid report.', 'psbdx-smart-report-management' ) );
		}

		$summary = PSBDX_SRM_AI::summarize_report( $post_id );

		if ( is_wp_error( $summary ) ) {
			wp_send_json_error( $summary->get_error_message() );
		}

		wp_send_json_success( array( 'summary' => $summary ) );
	}

	/**
	 * Render the Conversation (replies) meta box.
	 *
	 * Shows the existing thread, and — when replies are allowed for this
	 * report's source form — a reply box with "Improve with AI" (always
	 * available when general AI features are on) and "Generate AI Reply"
	 * (only when this report's AI-reply gate is fully open).
	 *
	 * @since  1.4.2
	 * @param  WP_Post $post  Current post object.
	 * @return void
	 */
	public function render_log_replies( $post ) {
		$can_reply      = PSBDX_SRM_Replies::replies_allowed( $post->ID );
		$ai_configured  = PSBDX_SRM_Replies::ai_reply_configured( $post->ID );
		$ai_off         = PSBDX_SRM_Replies::is_ai_reply_off( $post->ID );
		$ai_can_improve = PSBDX_SRM_AI::is_available();
		$form_id        = PSBDX_SRM_Replies::get_source_form_id( $post->ID );
		?>
		<div class="psbdx-thread-admin-wrap" id="psbdx-admin-thread-<?php echo (int) $post->ID; ?>" data-poll-nonce="<?php echo esc_attr( wp_create_nonce( 'psbdx_srm_poll_thread_nonce' ) ); ?>">
			<?php if ( ! $form_id ) : ?>
				<p class="description"><?php esc_html_e( 'This report was submitted before reply threads existed, so its source form is unknown and replies can\'t be enabled for it.', 'psbdx-smart-report-management' ); ?></p>
			<?php elseif ( ! $can_reply ) : ?>
				<p class="description">
					<?php
					printf(
						/* translators: %s: link to the report form's edit screen */
						esc_html__( 'Replies are turned off for this report\'s form. %s to enable "Allow Replies" under its Settings tab.', 'psbdx-smart-report-management' ),
						'<a href="' . esc_url( get_edit_post_link( $form_id ) ) . '">' . esc_html__( 'Edit the form', 'psbdx-smart-report-management' ) . '</a>'
					);
					?>
				</p>
			<?php endif; ?>

			<?php if ( $ai_configured ) : ?>
			<div class="psbdx-ai-reply-toggle-bar<?php echo $ai_off ? ' is-off' : ''; ?>" id="psbdx-ai-reply-toggle-bar-<?php echo (int) $post->ID; ?>">
				<span class="dashicons <?php echo $ai_off ? 'dashicons-hidden' : 'dashicons-admin-site-alt3'; ?>" aria-hidden="true"></span>
				<span class="psbdx-ai-reply-toggle-text">
					<?php
					echo $ai_off
						? esc_html__( 'AI auto-replies are turned OFF for this report.', 'psbdx-smart-report-management' )
						: esc_html__( 'AI will auto-reply to this report.', 'psbdx-smart-report-management' );
					?>
				</span>
				<button type="button" class="button psbdx-ai-reply-toggle-btn"
					data-post-id="<?php echo esc_attr( $post->ID ); ?>"
					data-off="<?php echo $ai_off ? '1' : '0'; ?>"
					data-nonce="<?php echo esc_attr( wp_create_nonce( 'psbdx_srm_toggle_ai_reply' ) ); ?>">
					<?php echo $ai_off ? esc_html__( 'Turn AI replies back on', 'psbdx-smart-report-management' ) : esc_html__( 'Turn off AI replies', 'psbdx-smart-report-management' ); ?>
				</button>
			</div>
			<?php endif; ?>

			<div class="psbdx-admin-thread-list" id="psbdx-admin-thread-list-<?php echo (int) $post->ID; ?>" data-count="<?php echo (int) count( PSBDX_SRM_Replies::get_thread( $post->ID ) ); ?>">
				<?php echo PSBDX_SRM_Shortcodes::render_thread_html( $post->ID, false, true ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped internally per-field. ?>
			</div>

			<?php if ( $can_reply ) : ?>
			<div class="psbdx-admin-reply-box">
				<textarea id="psbdx-admin-reply-text-<?php echo (int) $post->ID; ?>" class="large-text" rows="4"
					placeholder="<?php esc_attr_e( 'Write a reply to the reporter…', 'psbdx-smart-report-management' ); ?>"></textarea>
				<p class="psbdx-admin-reply-attach-row">
					<label class="psbdx-admin-reply-attach-btn">
						<span class="dashicons dashicons-paperclip" aria-hidden="true"></span>
						<?php esc_html_e( 'Attach a file', 'psbdx-smart-report-management' ); ?>
						<input type="file" id="psbdx-admin-reply-file-<?php echo (int) $post->ID; ?>" accept=".jpg,.jpeg,.png,.gif,.webp,.pdf" hidden>
					</label>
					<span class="psbdx-admin-reply-file-name" id="psbdx-admin-reply-file-name-<?php echo (int) $post->ID; ?>"></span>
				</p>
				<p class="psbdx-admin-reply-actions">
					<button type="button" class="button button-primary psbdx-admin-reply-send"
						data-post-id="<?php echo esc_attr( $post->ID ); ?>"
						data-nonce="<?php echo esc_attr( wp_create_nonce( 'psbdx_srm_add_admin_reply' ) ); ?>">
						<?php esc_html_e( 'Send Reply', 'psbdx-smart-report-management' ); ?>
					</button>
					<?php if ( $ai_can_improve ) : ?>
					<button type="button" class="button psbdx-admin-reply-improve"
						data-post-id="<?php echo esc_attr( $post->ID ); ?>"
						data-nonce="<?php echo esc_attr( wp_create_nonce( 'psbdx_srm_improve_reply' ) ); ?>">
						<span class="dashicons dashicons-superhero" aria-hidden="true"></span>
						<?php esc_html_e( 'Improve with AI', 'psbdx-smart-report-management' ); ?>
					</button>
					<?php endif; ?>
					<?php if ( $ai_configured ) : ?>
					<button type="button" class="button psbdx-admin-reply-generate"
						data-post-id="<?php echo esc_attr( $post->ID ); ?>"
						data-nonce="<?php echo esc_attr( wp_create_nonce( 'psbdx_srm_generate_ai_reply' ) ); ?>">
						<span class="dashicons dashicons-lightbulb" aria-hidden="true"></span>
						<?php esc_html_e( 'Generate AI Reply', 'psbdx-smart-report-management' ); ?>
					</button>
					<?php endif; ?>
					<span class="psbdx-admin-reply-status" id="psbdx-admin-reply-status-<?php echo (int) $post->ID; ?>" role="status" aria-live="polite"></span>
				</p>
			</div>
			<?php endif; ?>
		</div>

		<script>
		(function () {
			var postId = <?php echo (int) $post->ID; ?>;
			var wrap   = document.getElementById( 'psbdx-admin-thread-' + postId );
			if ( ! wrap ) { return; }

			var textarea = document.getElementById( 'psbdx-admin-reply-text-' + postId );
			var fileInput = document.getElementById( 'psbdx-admin-reply-file-' + postId );
			var fileNameEl = document.getElementById( 'psbdx-admin-reply-file-name-' + postId );
			var listEl   = document.getElementById( 'psbdx-admin-thread-list-' + postId );
			var statusEl = document.getElementById( 'psbdx-admin-reply-status-' + postId );

			if ( fileInput ) {
				fileInput.addEventListener( 'change', function () {
					var file = fileInput.files && fileInput.files[ 0 ];
					if ( fileNameEl ) { fileNameEl.textContent = file ? file.name : ''; }
				} );
			}

			function setStatus( text, isError ) {
				if ( ! statusEl ) { return; }
				statusEl.textContent = text || '';
				statusEl.classList.toggle( 'psbdx-is-error', !! isError );
			}

			function withMinDelay( promise, ms ) {
				var start = Date.now();
				return promise.then( function ( result ) {
					var remaining = ms - ( Date.now() - start );
					if ( remaining <= 0 ) { return result; }
					return new Promise( function ( resolve ) {
						setTimeout( function () { resolve( result ); }, remaining );
					} );
				} );
			}

			function post( action, extra, btn ) {
				var body = new URLSearchParams();
				body.append( 'action', action );
				body.append( 'security', extra.nonce );
				body.append( 'post_id', postId );
				if ( extra.message !== undefined ) { body.append( 'message', extra.message ); }
				if ( extra.off !== undefined ) { body.append( 'off', extra.off ? '1' : '' ); }

				if ( btn ) { btn.disabled = true; }

				return fetch( ajaxurl, { method: 'POST', body: body, credentials: 'same-origin' } )
					.then( function ( r ) { return r.json(); } )
					.finally( function () { if ( btn ) { btn.disabled = false; } } );
			}

			var sendBtn = wrap.querySelector( '.psbdx-admin-reply-send' );
			if ( sendBtn ) {
				sendBtn.addEventListener( 'click', function () {
					var message = textarea ? textarea.value.trim() : '';
					var file    = ( fileInput && fileInput.files && fileInput.files[ 0 ] ) ? fileInput.files[ 0 ] : null;
					if ( '' === message && ! file ) { return; }

					setStatus( '<?php echo esc_js( __( 'Submitting with PSBDx…', 'psbdx-smart-report-management' ) ); ?>' );

					var body = new FormData();
					body.append( 'action', 'psbdx_srm_add_admin_reply' );
					body.append( 'security', sendBtn.getAttribute( 'data-nonce' ) );
					body.append( 'post_id', postId );
					body.append( 'message', message );
					if ( file ) { body.append( 'reply_attachment', file ); }

					sendBtn.disabled = true;

					withMinDelay(
						fetch( ajaxurl, { method: 'POST', body: body, credentials: 'same-origin' } )
							.then( function ( r ) { return r.json(); } )
							.finally( function () { sendBtn.disabled = false; } ),
						2000
					)
						.then( function ( data ) {
							if ( data.success ) {
								if ( listEl ) {
									listEl.innerHTML = data.data.thread_html;
									listEl.setAttribute( 'data-count', String( data.data.count || 0 ) );
								}
								if ( textarea ) { textarea.value = ''; }
								if ( fileInput ) { fileInput.value = ''; }
								if ( fileNameEl ) { fileNameEl.textContent = ''; }
								setStatus( '' );
							} else {
								setStatus( data.data || '<?php echo esc_js( __( 'Could not send reply.', 'psbdx-smart-report-management' ) ); ?>', true );
							}
						} )
						.catch( function () {
							setStatus( '<?php echo esc_js( __( 'Network error.', 'psbdx-smart-report-management' ) ); ?>', true );
						} );
				} );
			}

			var improveBtn = wrap.querySelector( '.psbdx-admin-reply-improve' );
			if ( improveBtn ) {
				improveBtn.addEventListener( 'click', function () {
					var message = textarea ? textarea.value.trim() : '';
					if ( '' === message ) {
						setStatus( '<?php echo esc_js( __( 'Write a draft reply first.', 'psbdx-smart-report-management' ) ); ?>', true );
						return;
					}

					setStatus( '<?php echo esc_js( __( 'Improving…', 'psbdx-smart-report-management' ) ); ?>' );

					post( 'psbdx_srm_improve_reply', { nonce: improveBtn.getAttribute( 'data-nonce' ), message: message }, improveBtn )
						.then( function ( data ) {
							if ( data.success ) {
								if ( textarea ) { textarea.value = data.data.text; }
								setStatus( '' );
							} else {
								setStatus( data.data || '<?php echo esc_js( __( 'Could not improve reply.', 'psbdx-smart-report-management' ) ); ?>', true );
							}
						} )
						.catch( function () {
							setStatus( '<?php echo esc_js( __( 'Network error.', 'psbdx-smart-report-management' ) ); ?>', true );
						} );
				} );
			}

			var generateBtn = wrap.querySelector( '.psbdx-admin-reply-generate' );
			if ( generateBtn ) {
				generateBtn.addEventListener( 'click', function () {
					setStatus( '<?php echo esc_js( __( 'Generating…', 'psbdx-smart-report-management' ) ); ?>' );

					post( 'psbdx_srm_generate_ai_reply', { nonce: generateBtn.getAttribute( 'data-nonce' ) }, generateBtn )
						.then( function ( data ) {
							if ( data.success ) {
								if ( textarea ) { textarea.value = data.data.text; }
								setStatus( '<?php echo esc_js( __( 'Draft generated below — review before sending.', 'psbdx-smart-report-management' ) ); ?>' );
							} else {
								setStatus( data.data || '<?php echo esc_js( __( 'Could not generate a reply.', 'psbdx-smart-report-management' ) ); ?>', true );
							}
						} )
						.catch( function () {
							setStatus( '<?php echo esc_js( __( 'Network error.', 'psbdx-smart-report-management' ) ); ?>', true );
						} );
				} );
			}

			var aiToggleBtn = wrap.querySelector( '.psbdx-ai-reply-toggle-btn' );
			if ( aiToggleBtn ) {
				aiToggleBtn.addEventListener( 'click', function () {
					var turningOff = '0' === aiToggleBtn.getAttribute( 'data-off' );
					var bar        = document.getElementById( 'psbdx-ai-reply-toggle-bar-' + postId );
					var icon       = bar ? bar.querySelector( '.dashicons' ) : null;
					var text       = bar ? bar.querySelector( '.psbdx-ai-reply-toggle-text' ) : null;

					post( 'psbdx_srm_toggle_ai_reply', { nonce: aiToggleBtn.getAttribute( 'data-nonce' ), off: turningOff }, aiToggleBtn )
						.then( function ( data ) {
							if ( ! data.success ) {
								setStatus( data.data || '<?php echo esc_js( __( 'Could not update AI reply setting.', 'psbdx-smart-report-management' ) ); ?>', true );
								return;
							}

							aiToggleBtn.setAttribute( 'data-off', data.data.off ? '1' : '0' );
							aiToggleBtn.textContent = data.data.label;
							if ( bar ) { bar.classList.toggle( 'is-off', !! data.data.off ); }
							if ( text ) { text.textContent = data.data.text; }
							if ( icon ) {
								icon.classList.toggle( 'dashicons-hidden', !! data.data.off );
								icon.classList.toggle( 'dashicons-admin-site-alt3', ! data.data.off );
							}
						} )
						.catch( function () {
							setStatus( '<?php echo esc_js( __( 'Network error.', 'psbdx-smart-report-management' ) ); ?>', true );
						} );
				} );
			}

			// Poll for a new reply from the reporter (or AI) while this
			// screen is open, so it shows up without a manual reload —
			// same idea as the frontend report detail page.
			if ( listEl ) {
				var pollNonce = wrap.getAttribute( 'data-poll-nonce' );

				setInterval( function () {
					if ( document.hidden || ! pollNonce ) { return; }

					var lastCount = parseInt( listEl.getAttribute( 'data-count' ) || '0', 10 );

					var body = new URLSearchParams();
					body.append( 'action', 'psbdx_srm_poll_thread' );
					body.append( 'security', pollNonce );
					body.append( 'report_id', postId );

					fetch( ajaxurl, { method: 'POST', body: body, credentials: 'same-origin' } )
						.then( function ( r ) { return r.json(); } )
						.then( function ( data ) {
							if ( data && data.success && data.data && data.data.count !== lastCount && data.data.thread_html_inner ) {
								listEl.innerHTML = data.data.thread_html_inner;
								listEl.setAttribute( 'data-count', String( data.data.count ) );
							}
						} )
						.catch( function () {
							// Silent — a missed poll just tries again next interval.
						} );
				}, 7000 );
			}
		})();
		</script>
		<?php
	}

	/**
	 * AJAX: admin posts a reply into a report's thread.
	 *
	 * @since  1.4.2
	 * @return void
	 */
	public function ajax_add_admin_reply() {
		check_ajax_referer( 'psbdx_srm_add_admin_reply', 'security' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'psbdx-smart-report-management' ) );
		}

		$post_id = isset( $_POST['post_id'] ) ? (int) $_POST['post_id'] : 0;
		$message = isset( $_POST['message'] ) ? sanitize_textarea_field( wp_unslash( $_POST['message'] ) ) : '';

		if ( ! $post_id || 'psbdx_report_log' !== get_post_type( $post_id ) ) {
			wp_send_json_error( __( 'Invalid report.', 'psbdx-smart-report-management' ) );
		}

		if ( ! PSBDX_SRM_Replies::replies_allowed( $post_id ) ) {
			wp_send_json_error( __( 'Replies are not enabled for this report.', 'psbdx-smart-report-management' ) );
		}

		// Optional attachment, shared alongside the message — same
		// validate/upload path as everywhere else a file gets attached to
		// a report (see PSBDX_SRM_Ajax::validate_and_upload_file()).
		$attachment_id = 0;

		if ( isset( $_FILES['reply_attachment']['name'] ) && '' !== $_FILES['reply_attachment']['name'] ) {
			$file = array(
				'name'     => sanitize_file_name( wp_unslash( $_FILES['reply_attachment']['name'] ) ),
				'type'     => sanitize_text_field( wp_unslash( $_FILES['reply_attachment']['type'] ) ), // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated -- index existence is checked by the isset() this block starts with; nonce already verified above via check_ajax_referer().
				'tmp_name' => $_FILES['reply_attachment']['tmp_name'], // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- server-generated path, not user input; see above.
				'error'    => (int) $_FILES['reply_attachment']['error'], // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated -- see above; cast to int.
				'size'     => (int) $_FILES['reply_attachment']['size'], // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated -- see above; cast to int.
			);

			if ( UPLOAD_ERR_NO_FILE !== $file['error'] ) {
				$attachment_id = PSBDX_SRM_Ajax::validate_and_upload_file(
					$file,
					__( 'Attachment', 'psbdx-smart-report-management' ),
					PSBDX_SRM_Ajax::REPLY_ATTACHMENT_TYPES,
					0,
					PSBDX_SRM_Ajax::REPLY_ATTACHMENT_MAX_KB
				);
			}
		}

		if ( '' === trim( $message ) && ! $attachment_id ) {
			wp_send_json_error( __( 'Please write a message or attach a file first.', 'psbdx-smart-report-management' ) );
		}

		$user = wp_get_current_user();
		$ok   = PSBDX_SRM_Replies::add_reply( $post_id, 'admin', $user->ID, $user->display_name, $message, false, $attachment_id );

		if ( ! $ok ) {
			wp_send_json_error( __( 'Failed to save the reply.', 'psbdx-smart-report-management' ) );
		}

		if ( $attachment_id ) {
			wp_update_post( array( 'ID' => $attachment_id, 'post_parent' => $post_id ) );
		}

		wp_send_json_success( array(
			'thread_html' => PSBDX_SRM_Shortcodes::render_thread_html( $post_id, false, true ),
			'count'       => count( PSBDX_SRM_Replies::get_thread( $post_id ) ),
		) );
	}

	/**
	 * AJAX: improve an admin's drafted reply with AI (does not post it).
	 *
	 * @since  1.4.2
	 * @return void
	 */
	public function ajax_improve_reply() {
		check_ajax_referer( 'psbdx_srm_improve_reply', 'security' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'psbdx-smart-report-management' ) );
		}

		$post_id = isset( $_POST['post_id'] ) ? (int) $_POST['post_id'] : 0;
		$message = isset( $_POST['message'] ) ? sanitize_textarea_field( wp_unslash( $_POST['message'] ) ) : '';

		$result = PSBDX_SRM_AI::improve_reply( $post_id, $message );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}

		wp_send_json_success( array( 'text' => $result ) );
	}

	/**
	 * AJAX: generate a full AI reply draft into the admin's reply box
	 * (does not post it — the admin still reviews and clicks Send).
	 *
	 * @since  1.4.2
	 * @return void
	 */
	public function ajax_generate_ai_reply() {
		check_ajax_referer( 'psbdx_srm_generate_ai_reply', 'security' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'psbdx-smart-report-management' ) );
		}

		$post_id = isset( $_POST['post_id'] ) ? (int) $_POST['post_id'] : 0;

		if ( ! $post_id || 'psbdx_report_log' !== get_post_type( $post_id ) ) {
			wp_send_json_error( __( 'Invalid report.', 'psbdx-smart-report-management' ) );
		}

		if ( ! PSBDX_SRM_Replies::ai_reply_configured( $post_id ) ) {
			wp_send_json_error( __( 'AI replies are not enabled for this report.', 'psbdx-smart-report-management' ) );
		}

		// Draft only: generate the text but don't post it into the thread —
		// unlike the automatic reply, an admin-triggered draft is meant to
		// be reviewed (and possibly edited or improved) before sending.
		$result = PSBDX_SRM_AI::generate_reply( $post_id, false );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}

		wp_send_json_success( array( 'text' => $result ) );
	}

	/**
	 * AJAX: admin turns AI auto-replies on/off for one specific report —
	 * e.g. once they've personally taken over the conversation and don't
	 * want the AI to keep chiming in on it.
	 *
	 * @since  1.4.2
	 * @return void
	 */
	public function ajax_toggle_ai_reply() {
		check_ajax_referer( 'psbdx_srm_toggle_ai_reply', 'security' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'psbdx-smart-report-management' ) );
		}

		$post_id = isset( $_POST['post_id'] ) ? (int) $_POST['post_id'] : 0;
		$off     = ! empty( $_POST['off'] );

		if ( ! $post_id || 'psbdx_report_log' !== get_post_type( $post_id ) ) {
			wp_send_json_error( __( 'Invalid report.', 'psbdx-smart-report-management' ) );
		}

		PSBDX_SRM_Replies::set_ai_reply_off( $post_id, $off );

		wp_send_json_success( array(
			'off'   => $off,
			'label' => $off
				? __( 'Turn AI replies back on', 'psbdx-smart-report-management' )
				: __( 'Turn off AI replies', 'psbdx-smart-report-management' ),
			'text'  => $off
				? __( 'AI auto-replies are turned OFF for this report.', 'psbdx-smart-report-management' )
				: __( 'AI will auto-reply to this report.', 'psbdx-smart-report-management' ),
		) );
	}

	/**
	 * Render the Report Status meta box.
	 *
	 * @since  1.0.0
	 * @param  WP_Post $post  Current post object.
	 * @return void
	 */
	public function render_log_status( $post ) {
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD );

		$current  = get_post_meta( $post->ID, '_psbdx_report_status', true );
		$current  = $current ? $current : 'Processing';
		$statuses = PSBDX_SRM_Helpers::get_statuses();
		$s        = isset( $statuses[ $current ] ) ? $statuses[ $current ] : array(
			'label' => PSBDX_SRM_Helpers::get_status_label( $current ),
			'bg'    => '#e2e8f0',
			'color' => '#475569',
		);
		?>
		<div class="psbdx-status-wrap">
			<span class="psbdx-current-status-badge"
				style="background:<?php echo esc_attr( $s['bg'] ); ?>;color:<?php echo esc_attr( $s['color'] ); ?>;">
				<?php echo esc_html( $s['label'] ); ?>
			</span>

			<label for="psbdx_report_status" class="psbdx-status-update-label">
				<?php esc_html_e( 'Update status:', 'psbdx-smart-report-management' ); ?>
			</label>
			<select name="psbdx_report_status" id="psbdx_report_status" class="widefat">
				<?php foreach ( $statuses as $key => $data ) : ?>
					<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $current, $key ); ?>>
						<?php echo esc_html( $data['label'] ); ?>
					</option>
				<?php endforeach; ?>
			</select>
		</div>
		<?php
	}

	/**
	 * Render the Category & Priority meta box.
	 *
	 * Always available, regardless of WordPress version or whether AI
	 * features are enabled — this is the manual fallback (and override) for
	 * AI-assisted classification.
	 *
	 * @since  1.4.1
	 * @param  WP_Post $post  Current post object.
	 * @return void
	 */
	public function render_log_classification( $post ) {
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD );

		$categories    = PSBDX_SRM_Helpers::get_report_categories();
		$current_cat   = get_post_meta( $post->ID, '_psbdx_report_category', true );
		$current_pri   = get_post_meta( $post->ID, '_psbdx_report_priority', true );
		$current_pri   = $current_pri ? $current_pri : 'Medium';
		$classified_by = get_post_meta( $post->ID, '_psbdx_report_classified_by', true );
		$priorities    = PSBDX_SRM_Helpers::get_report_priorities();
		?>
		<?php if ( $classified_by ) : ?>
			<p class="psbdx-meta-hint">
				<span class="dashicons <?php echo 'ai' === $classified_by ? 'dashicons-superhero' : 'dashicons-admin-users'; ?>" aria-hidden="true"></span>
				<?php
				echo 'ai' === $classified_by
					? esc_html__( 'Suggested automatically by AI.', 'psbdx-smart-report-management' )
					: esc_html__( 'Set manually by an admin.', 'psbdx-smart-report-management' );
				?>
			</p>
		<?php endif; ?>

		<p>
			<label for="psbdx_report_category"><strong><?php esc_html_e( 'Category', 'psbdx-smart-report-management' ); ?></strong></label><br>
			<?php if ( empty( $categories ) ) : ?>
				<?php if ( $current_cat ) : ?>
					<p class="psbdx-meta-hint">
						<?php
						printf(
							/* translators: %s: current category value */
							esc_html__( 'Current: %s', 'psbdx-smart-report-management' ),
							'<strong>' . esc_html( $current_cat ) . '</strong>'
						);
						?>
					</p>
				<?php endif; ?>
				<select id="psbdx_report_category" class="widefat" disabled>
					<option><?php esc_html_e( 'No categories configured yet', 'psbdx-smart-report-management' ); ?></option>
				</select>
				<span class="psbdx-meta-hint">
					<?php
					printf(
						/* translators: %s: link to the Categories settings tab */
						esc_html__( '%s to enable manual categorization.', 'psbdx-smart-report-management' ),
						'<a href="' . esc_url( admin_url( 'admin.php?page=psbdx-srm-settings&tab=categories' ) ) . '">' . esc_html__( 'Add categories', 'psbdx-smart-report-management' ) . '</a>'
					);
					?>
				</span>
			<?php else : ?>
				<select id="psbdx_report_category" name="psbdx_report_category" class="widefat">
					<option value=""><?php esc_html_e( '— None —', 'psbdx-smart-report-management' ); ?></option>
					<?php foreach ( $categories as $cat ) : ?>
						<option value="<?php echo esc_attr( $cat ); ?>" <?php selected( $current_cat, $cat ); ?>><?php echo esc_html( $cat ); ?></option>
					<?php endforeach; ?>
				</select>
			<?php endif; ?>
		</p>

		<p>
			<label for="psbdx_report_priority"><strong><?php esc_html_e( 'Priority', 'psbdx-smart-report-management' ); ?></strong></label><br>
			<select id="psbdx_report_priority" name="psbdx_report_priority" class="widefat">
				<?php foreach ( $priorities as $key => $label ) : ?>
					<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $current_pri, $key ); ?>><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?>
			</select>
		</p>
		<?php
	}

	// =========================================================================
	// RENDER — LEARNPRESS INTEGRATION
	// =========================================================================

	/**
	 * Render LearnPress course/lesson/quiz report form selector.
	 *
	 * @since  1.0.0
	 * @param  WP_Post $post  Current post object.
	 * @return void
	 */
	public function render_lp_integration( $post ) {
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD );

		$selected = get_post_meta( $post->ID, '_psbdx_product_report_btn', true );
		$forms    = PSBDX_SRM_Helpers::get_published_report_forms();
		?>
		<p>
			<label for="psbdx_lp_report_btn">
				<strong><?php esc_html_e( 'Select Report Form:', 'psbdx-smart-report-management' ); ?></strong>
			</label>
		</p>
		<select name="_psbdx_product_report_btn" id="psbdx_lp_report_btn" class="widefat">
			<option value=""><?php esc_html_e( '— None (use global default) —', 'psbdx-smart-report-management' ); ?></option>
			<?php foreach ( $forms as $form ) : ?>
				<option value="<?php echo esc_attr( $form->ID ); ?>" <?php selected( $selected, $form->ID ); ?>>
					<?php echo esc_html( $form->post_title ); ?>
				</option>
			<?php endforeach; ?>
		</select>
		<?php
	}

	// =========================================================================
	// SAVE
	// =========================================================================

	/**
	 * Save all meta box data on post save.
	 *
	 * All $_POST reads happen here, after nonce verification, so the full
	 * verification chain is visible to static analysis tools.
	 *
	 * @since  1.0.0
	 * @param  int $post_id  Post ID being saved.
	 * @return void
	 */
	public function save( $post_id ) {
		// Verify nonce.
		if ( ! isset( $_POST[ self::NONCE_FIELD ] )
			|| ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ), self::NONCE_ACTION )
		) {
			return;
		}

		// Bail on autosave.
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		// Verify capability.
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		// Collect and sanitize all expected POST fields here, after nonce
		// verification, so PHPCS can confirm the full security chain.
		$data = array(
			'psbdx_btn_text'            => isset( $_POST['psbdx_btn_text'] )            ? sanitize_text_field( wp_unslash( $_POST['psbdx_btn_text'] ) )            : '',
			'psbdx_reasons'             => isset( $_POST['psbdx_reasons'] )             ? sanitize_text_field( wp_unslash( $_POST['psbdx_reasons'] ) )             : '',
			'psbdx_custom_fields'       => isset( $_POST['psbdx_custom_fields'] )       ? sanitize_text_field( wp_unslash( $_POST['psbdx_custom_fields'] ) )       : '',
			'psbdx_contact_label'       => isset( $_POST['psbdx_contact_label'] )       ? sanitize_text_field( wp_unslash( $_POST['psbdx_contact_label'] ) )       : '',
			'psbdx_contact_required'    => isset( $_POST['psbdx_contact_required'] )    ? 'yes' : 'no',
			'psbdx_show_identity'       => isset( $_POST['psbdx_show_identity'] )       ? 'yes' : 'no',
			'psbdx_captcha_enabled'     => isset( $_POST['psbdx_captcha_enabled'] )     ? 'yes' : 'no',
			'psbdx_cooldown_mins'       => isset( $_POST['psbdx_cooldown_mins'] )       ? min( 1440, max( 0, (int) $_POST['psbdx_cooldown_mins'] ) ) : null,
			'psbdx_is_order_form'       => isset( $_POST['psbdx_is_order_form'] )       && '1' === $_POST['psbdx_is_order_form'],
			'psbdx_is_product_form'     => isset( $_POST['psbdx_is_product_form'] )     && '1' === $_POST['psbdx_is_product_form'],
			'psbdx_report_status'       => isset( $_POST['psbdx_report_status'] )       ? sanitize_text_field( wp_unslash( $_POST['psbdx_report_status'] ) )       : '',
			'psbdx_report_category'     => isset( $_POST['psbdx_report_category'] )     ? sanitize_text_field( wp_unslash( $_POST['psbdx_report_category'] ) )     : null,
			'psbdx_report_priority'     => isset( $_POST['psbdx_report_priority'] )     ? sanitize_text_field( wp_unslash( $_POST['psbdx_report_priority'] ) )     : null,
			'_psbdx_product_report_btn' => isset( $_POST['_psbdx_product_report_btn'] ) ? sanitize_text_field( wp_unslash( $_POST['_psbdx_product_report_btn'] ) ) : null,
		);

		$post_type = get_post_type( $post_id );

		if ( 'psbdx_report_form' === $post_type ) {
			$this->save_form_meta( $post_id, $data );
		}

		if ( 'psbdx_report_log' === $post_type ) {
			$this->save_log_meta( $post_id, $data );
		}

		// LearnPress integration — save per-item form assignment.
		if ( null !== $data['_psbdx_product_report_btn'] ) {
			update_post_meta( $post_id, '_psbdx_product_report_btn', $data['_psbdx_product_report_btn'] );
		}
	}

	/**
	 * Save Report Form meta fields.
	 *
	 * @since  1.0.0
	 * @param  int   $post_id  Post ID.
	 * @param  array $data     Pre-sanitized POST values collected in save().
	 * @return void
	 */
	private function save_form_meta( $post_id, array $data ) {
		// Simple text fields.
		$text_fields = array(
			'psbdx_btn_text'      => '_psbdx_btn_text',
			'psbdx_reasons'       => '_psbdx_reasons',
			'psbdx_custom_fields' => '_psbdx_custom_fields',
			'psbdx_contact_label' => '_psbdx_contact_label',
		);

		foreach ( $text_fields as $key => $meta_key ) {
			if ( '' !== $data[ $key ] ) {
				update_post_meta( $post_id, $meta_key, $data[ $key ] );
			}
		}

		// Checkboxes.
		update_post_meta( $post_id, '_psbdx_contact_required', $data['psbdx_contact_required'] );
		update_post_meta( $post_id, '_psbdx_show_identity',    $data['psbdx_show_identity'] );
		update_post_meta( $post_id, '_psbdx_captcha_enabled',  $data['psbdx_captcha_enabled'] );

		// Cooldown — integer clamped 0–1440.
		if ( null !== $data['psbdx_cooldown_mins'] ) {
			update_post_meta( $post_id, '_psbdx_cooldown_mins', $data['psbdx_cooldown_mins'] );
		}

		// Global order form option.
		if ( $data['psbdx_is_order_form'] ) {
			update_option( 'psbdx_global_order_form_id', $post_id );
		} elseif ( (int) get_option( 'psbdx_global_order_form_id' ) === $post_id ) {
			delete_option( 'psbdx_global_order_form_id' );
		}

		// Global product form option.
		if ( $data['psbdx_is_product_form'] ) {
			update_option( 'psbdx_global_product_form_id', $post_id );
		} elseif ( (int) get_option( 'psbdx_global_product_form_id' ) === $post_id ) {
			delete_option( 'psbdx_global_product_form_id' );
		}
	}

	/**
	 * Save Report Log status field (the only editable log field).
	 *
	 * @since  1.0.0
	 * @param  int   $post_id  Post ID.
	 * @param  array $data     Pre-sanitized POST values collected in save().
	 * @return void
	 */
	private function save_log_meta( $post_id, array $data ) {
		if ( ! empty( $data['psbdx_report_status'] ) && PSBDX_SRM_Helpers::is_valid_status_key( $data['psbdx_report_status'] ) ) {
			PSBDX_SRM_Helpers::update_report_status( $post_id, $data['psbdx_report_status'], array( 'source' => 'admin' ) );
		}

		// Category and priority — this form only ever submits an admin's
		// explicit choice, so only re-stamp "classified by admin" when a
		// value actually changed (avoids clobbering an AI attribution just
		// because the status field was updated in the same save).
		$changed = false;

		if ( null !== $data['psbdx_report_category'] ) {
			$prev_cat = (string) get_post_meta( $post_id, '_psbdx_report_category', true );

			if ( $data['psbdx_report_category'] !== $prev_cat ) {
				$changed = true;

				if ( '' === $data['psbdx_report_category'] ) {
					delete_post_meta( $post_id, '_psbdx_report_category' );
				} elseif ( PSBDX_SRM_Helpers::is_valid_report_category( $data['psbdx_report_category'] ) ) {
					update_post_meta( $post_id, '_psbdx_report_category', $data['psbdx_report_category'] );
				}
			}
		}

		if ( null !== $data['psbdx_report_priority'] && PSBDX_SRM_Helpers::is_valid_report_priority( $data['psbdx_report_priority'] ) ) {
			$prev_pri = (string) get_post_meta( $post_id, '_psbdx_report_priority', true );

			if ( $data['psbdx_report_priority'] !== $prev_pri ) {
				$changed = true;
				update_post_meta( $post_id, '_psbdx_report_priority', $data['psbdx_report_priority'] );
			}
		}

		if ( $changed ) {
			update_post_meta( $post_id, '_psbdx_report_classified_by', 'admin' );
		}
	}

	// =========================================================================
	// PRIVATE RENDERING HELPERS
	// =========================================================================

	/**
	 * Open a styled meta box section.
	 *
	 * @since  1.0.0
	 * @param  string $icon   Dashicon class (e.g. 'dashicons-button').
	 * @param  string $title  Section heading text.
	 * @return void
	 */
	private function section_open( $icon, $title ) {
		?>
		<div class="psbdx-meta-section">
			<div class="psbdx-meta-section-header">
				<span class="dashicons <?php echo esc_attr( $icon ); ?>" aria-hidden="true"></span>
				<strong><?php echo esc_html( $title ); ?></strong>
			</div>
			<div class="psbdx-meta-section-body">
		<?php
	}

	/**
	 * Close a styled meta box section.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	private function section_close() {
		echo '</div></div>';
	}

	/**
	 * Render a labelled text input field.
	 *
	 * @since  1.0.0
	 * @param  string $name         Input name attribute.
	 * @param  string $label        Visible label text.
	 * @param  string $value        Current value.
	 * @param  string $placeholder  Placeholder text.
	 * @return void
	 */
	private function field_text( $name, $label, $value, $placeholder = '' ) {
		$id = 'psbdx_' . $name;
		?>
		<p>
			<label for="<?php echo esc_attr( $id ); ?>"><strong><?php echo esc_html( $label ); ?></strong></label><br>
			<input type="text" name="<?php echo esc_attr( $name ); ?>" id="<?php echo esc_attr( $id ); ?>"
				value="<?php echo esc_attr( $value ); ?>"
				placeholder="<?php echo esc_attr( $placeholder ); ?>"
				class="large-text">
		</p>
		<?php
	}

	/**
	 * Render a labelled textarea field.
	 *
	 * @since  1.0.0
	 * @param  string $name         Input name attribute.
	 * @param  string $label        Visible label text (empty string = no label).
	 * @param  string $value        Current value.
	 * @param  string $placeholder  Placeholder text.
	 * @param  int    $rows         Number of rows.
	 * @return void
	 */
	private function field_textarea( $name, $label, $value, $placeholder = '', $rows = 3 ) {
		$id = 'psbdx_' . $name;
		?>
		<p>
			<?php if ( $label ) : ?>
				<label for="<?php echo esc_attr( $id ); ?>"><strong><?php echo esc_html( $label ); ?></strong></label><br>
			<?php endif; ?>
			<textarea name="<?php echo esc_attr( $name ); ?>" id="<?php echo esc_attr( $id ); ?>"
				rows="<?php echo esc_attr( $rows ); ?>"
				placeholder="<?php echo esc_attr( $placeholder ); ?>"
				class="large-text"><?php echo esc_textarea( $value ); ?></textarea>
		</p>
		<?php
	}

	/**
	 * Render a checkbox field.
	 *
	 * @since  1.0.0
	 * @param  string $name    Input name attribute.
	 * @param  string $label   Visible label text.
	 * @param  string $value   Value when checked.
	 * @param  bool   $checked Whether the checkbox is currently checked.
	 * @return void
	 */
	private function field_checkbox( $name, $label, $value, $checked ) {
		?>
		<p>
			<label>
				<input type="checkbox" name="<?php echo esc_attr( $name ); ?>"
					value="<?php echo esc_attr( $value ); ?>"
					<?php checked( $checked, true ); ?>>
				<?php echo esc_html( $label ); ?>
			</label>
		</p>
		<?php
	}
}
