<?php
/**
 * "Popup link" embedding for PSBDx Smart Report Management.
 *
 * Lets an admin turn any URL on the site into a trigger for a given form's
 * report modal, just by appending "?" followed by the form's ID to the end
 * of it — e.g. https://example.com/any-page/?123 opens form 123 as an
 * overlay on top of that page without navigating anywhere. The base page
 * loads completely normally; PSBDx public.js is what notices the pattern
 * in the URL and fetches + opens the modal.
 *
 * @package PSBDx_Smart_Report_Management
 * @since   1.4.5
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class PSBDX_SRM_Popup_Link
 *
 * @since 1.4.5
 */
class PSBDX_SRM_Popup_Link {

	/**
	 * Post meta key — 'yes'/'no', whether this form may be opened as a
	 * URL-triggered popup overlay. Off by default so a form isn't
	 * unexpectedly poppable just because someone appends "?123" to a link.
	 *
	 * @since 1.4.5
	 * @var string
	 */
	const POPUP_ENABLED_META = '_psbdx_popup_enabled';

	/**
	 * Constructor.
	 *
	 * @since 1.4.5
	 */
	public function __construct() {
		add_action( 'psbdx_srm_form_builder_after_replies', array( $this, 'render_form_builder_checkbox' ) );
		add_action( 'save_post', array( $this, 'save_form_builder_checkbox' ), 20 );
	}

	/**
	 * Whether URL-popup access is enabled for a given form.
	 *
	 * @since  1.4.5
	 * @param  int $form_id  Report form post ID.
	 * @return bool
	 */
	public static function is_enabled( $form_id ) {
		return ( 'yes' === get_post_meta( $form_id, self::POPUP_ENABLED_META, true ) );
	}

	/**
	 * Renders the "Enable popup link" checkbox in the form's Settings tab,
	 * plus the copyable link snippet once it's turned on.
	 *
	 * Hooked via `psbdx_srm_form_builder_after_replies` (same extensibility
	 * point PSBDX_SRM_API uses for its own "Allow API" checkbox) so this
	 * whole feature stays in its own file.
	 *
	 * @since 1.4.5
	 * @param  WP_Post $post  Current report-form post.
	 * @return void
	 */
	public function render_form_builder_checkbox( $post ) {
		$enabled  = self::is_enabled( $post->ID );
		$example  = trailingslashit( home_url( '/some-page/' ) ) . '?' . $post->ID;
		?>
		<div class="psrm-settings-section">
			<div class="psrm-settings-section-header">
				<span class="dashicons dashicons-external" aria-hidden="true"></span>
				<strong><?php esc_html_e( 'Popup Link (overlay on any URL)', 'psbdx-smart-report-management' ); ?></strong>
			</div>
			<div class="psrm-settings-section-body">
				<p class="psrm-hint">
					<?php esc_html_e( 'When enabled, appending "?" followed by this form\'s ID to the end of any URL on your site opens this form as a popup overlay on that page — the page itself never changes, nothing navigates away.', 'psbdx-smart-report-management' ); ?>
				</p>
				<p>
					<label>
						<input type="checkbox" name="psbdx_popup_enabled" value="yes" <?php checked( $enabled ); ?>>
						<?php esc_html_e( 'Allow this form to be opened as a URL popup overlay', 'psbdx-smart-report-management' ); ?>
					</label>
				</p>
				<?php if ( $enabled ) : ?>
				<div class="psrm-popup-link-example">
					<label for="psrm-popup-link-<?php echo esc_attr( $post->ID ); ?>">
						<?php esc_html_e( 'Example — add this suffix to any page URL:', 'psbdx-smart-report-management' ); ?>
					</label>
					<div class="psrm-popup-link-row">
						<input type="text" readonly
							id="psrm-popup-link-<?php echo esc_attr( $post->ID ); ?>"
							class="psrm-popup-link-input"
							value="<?php echo esc_attr( $example ); ?>">
						<button type="button" class="button psbdx-copy-btn" data-target="psrm-popup-link-<?php echo esc_attr( $post->ID ); ?>">
							<?php esc_html_e( 'Copy', 'psbdx-smart-report-management' ); ?>
						</button>
					</div>
					<p class="description">
						<?php
						printf(
							/* translators: %s: form ID e.g. 123 */
							esc_html__( 'The important part is just "?%s" at the end — swap /some-page/ for any URL on your site.', 'psbdx-smart-report-management' ),
							esc_html( $post->ID )
						);
						?>
					</p>
				</div>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Saves the per-form popup checkbox. Hooked directly to save_post
	 * (rather than depending on the builder's own save() method) so this
	 * stays self-contained; it re-checks the same nonce the builder uses.
	 *
	 * @since 1.4.5
	 * @param  int $post_id  Post ID being saved.
	 * @return void
	 */
	public function save_form_builder_checkbox( $post_id ) {
		if ( ! isset( $_POST[ PSBDX_SRM_Form_Builder::NONCE_FIELD ] )
			|| ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST[ PSBDX_SRM_Form_Builder::NONCE_FIELD ] ) ), PSBDX_SRM_Form_Builder::NONCE_ACTION )
		) {
			return;
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) || 'psbdx_report_form' !== get_post_type( $post_id ) ) {
			return;
		}

		update_post_meta( $post_id, self::POPUP_ENABLED_META, isset( $_POST['psbdx_popup_enabled'] ) ? 'yes' : 'no' );
	}
}
