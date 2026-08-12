<?php
/**
 * FAQ admin page for PSBDx Smart Report Management.
 *
 * Lets the site admin manage a list of question/answer pairs for their own
 * site's visitors, shown on the front end via the [psbdx_faq] shortcode.
 *
 * This is intentionally a separate page from Support: Support is the admin
 * contacting the PSBDx development team about the plugin itself, while the
 * FAQ here is content the admin is building for their own site's users.
 *
 * @package PSBDx_Smart_Report_Management
 * @since   1.4.1
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class PSBDX_SRM_FAQ
 *
 * @since 1.4.1
 */
class PSBDX_SRM_FAQ {

	/**
	 * Submenu slug.
	 *
	 * @since 1.4.1
	 * @var string
	 */
	const PAGE_FAQ = 'psbdx-srm-faq';

	/**
	 * Constructor.
	 *
	 * @since 1.4.1
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register_page' ), 102 );
		add_action( 'admin_init', array( $this, 'handle_save' ) );
	}

	/**
	 * Register the FAQ submenu page.
	 *
	 * @since  1.4.1
	 * @return void
	 */
	public function register_page() {
		add_submenu_page(
			PSBDX_SRM_Post_Types::ADMIN_MENU_SLUG,
			__( 'FAQ', 'psbdx-smart-report-management' ),
			__( 'FAQ', 'psbdx-smart-report-management' ),
			'manage_options',
			self::PAGE_FAQ,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Saves the admin-managed FAQ list.
	 *
	 * @since  1.4.1
	 * @return void
	 */
	public function handle_save() {
		if ( ! isset( $_POST['psbdx_srm_save_faq'] ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		check_admin_referer( 'psbdx_srm_faq_settings' );

		$questions = isset( $_POST['psbdx_srm_faq_question'] ) ? array_map( 'sanitize_text_field', wp_unslash( (array) $_POST['psbdx_srm_faq_question'] ) ) : array();
		$answers   = isset( $_POST['psbdx_srm_faq_answer'] )   ? array_map( 'sanitize_textarea_field', wp_unslash( (array) $_POST['psbdx_srm_faq_answer'] ) )   : array();

		$raw = array();
		foreach ( $questions as $i => $question ) {
			$raw[] = array(
				'question' => $question,
				'answer'   => isset( $answers[ $i ] ) ? $answers[ $i ] : '',
			);
		}

		update_option( PSBDX_SRM_Helpers::FAQ_OPTION, PSBDX_SRM_Helpers::sanitize_faq_items( $raw ), false );

		add_settings_error(
			'psbdx_srm_faq',
			'faq_saved',
			__( 'FAQ saved.', 'psbdx-smart-report-management' ),
			'success'
		);
	}

	/**
	 * Render the FAQ admin page.
	 *
	 * @since  1.4.1
	 * @return void
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		settings_errors( 'psbdx_srm_faq' );

		$items = PSBDX_SRM_Helpers::get_faq_items();
		?>
		<div class="wrap psbdx-srm-tools">
			<h1>
				<span class="dashicons dashicons-editor-help" aria-hidden="true" style="vertical-align:middle;margin-right:6px;"></span>
				<?php esc_html_e( 'FAQ', 'psbdx-smart-report-management' ); ?>
			</h1>
			<p class="description">
				<?php esc_html_e( 'Add the questions your site\'s visitors ask most often, then drop the shortcode below anywhere on your site to display them as a FAQ accordion for your users.', 'psbdx-smart-report-management' ); ?>
			</p>

			<p class="psbdx-meta-hint" style="margin:0 0 16px;">
				<?php esc_html_e( 'Shortcode:', 'psbdx-smart-report-management' ); ?>
				<span class="psbdx-copy-row" style="display:inline-flex;">
					<code id="psbdx-faq-shortcode">[psbdx_faq]</code>
					<button type="button" class="button button-small psbdx-copy-btn" data-target="psbdx-faq-shortcode">
						<?php esc_html_e( 'Copy', 'psbdx-smart-report-management' ); ?>
					</button>
				</span>
			</p>

			<form method="post" action="" id="psbdx-srm-faq-form" style="max-width:820px;">
				<?php wp_nonce_field( 'psbdx_srm_faq_settings' ); ?>
				<div id="psbdx-faq-rows">
					<?php foreach ( $items as $i => $item ) : ?>
					<div class="psbdx-faq-row">
						<p>
							<label>
								<strong><?php esc_html_e( 'Question', 'psbdx-smart-report-management' ); ?></strong>
								<input type="text" class="large-text" name="psbdx_srm_faq_question[]" value="<?php echo esc_attr( $item['question'] ); ?>" required>
							</label>
						</p>
						<p>
							<label>
								<strong><?php esc_html_e( 'Answer', 'psbdx-smart-report-management' ); ?></strong>
								<textarea class="large-text" rows="3" name="psbdx_srm_faq_answer[]" required><?php echo esc_textarea( $item['answer'] ); ?></textarea>
							</label>
						</p>
						<p><button type="button" class="button-link-delete psbdx-faq-remove-row"><?php esc_html_e( 'Remove this question', 'psbdx-smart-report-management' ); ?></button></p>
						<hr>
					</div>
					<?php endforeach; ?>
				</div>

				<?php if ( empty( $items ) ) : ?>
				<p class="psbdx-empty-state" id="psbdx-faq-empty-note">
					<?php esc_html_e( 'No questions yet — click "Add another question" below to get started.', 'psbdx-smart-report-management' ); ?>
				</p>
				<?php endif; ?>

				<p>
					<button type="button" id="psbdx-faq-add-row" class="button">
						<?php esc_html_e( 'Add another question', 'psbdx-smart-report-management' ); ?>
					</button>
				</p>
				<p>
					<button type="submit" name="psbdx_srm_save_faq" class="button button-primary" value="1">
						<?php esc_html_e( 'Save FAQ', 'psbdx-smart-report-management' ); ?>
					</button>
				</p>
			</form>

			<script>
			(function () {
				const wrap   = document.getElementById( 'psbdx-faq-rows' );
				const addBtn = document.getElementById( 'psbdx-faq-add-row' );
				const empty  = document.getElementById( 'psbdx-faq-empty-note' );
				if ( ! wrap || ! addBtn ) {
					return;
				}

				addBtn.addEventListener( 'click', function () {
					if ( empty ) {
						empty.remove();
					}

					const row = document.createElement( 'div' );
					row.className = 'psbdx-faq-row';
					row.innerHTML =
						'<p><label><strong><?php echo esc_js( __( 'Question', 'psbdx-smart-report-management' ) ); ?></strong> ' +
						'<input type="text" class="large-text" name="psbdx_srm_faq_question[]" value="" required></label></p>' +
						'<p><label><strong><?php echo esc_js( __( 'Answer', 'psbdx-smart-report-management' ) ); ?></strong> ' +
						'<textarea class="large-text" rows="3" name="psbdx_srm_faq_answer[]" required></textarea></label></p>' +
						'<p><button type="button" class="button-link-delete psbdx-faq-remove-row"><?php echo esc_js( __( 'Remove this question', 'psbdx-smart-report-management' ) ); ?></button></p>' +
						'<hr>';
					wrap.appendChild( row );
				} );

				wrap.addEventListener( 'click', function ( event ) {
					if ( ! event.target.classList.contains( 'psbdx-faq-remove-row' ) ) {
						return;
					}
					const row = event.target.closest( '.psbdx-faq-row' );
					if ( row ) {
						row.remove();
					}
				} );
			})();
			</script>
		</div>
		<?php
	}
}
