<?php
/**
 * Attachment management for PSBDx Smart Report Management.
 *
 * Every file that ends up attached to a report — whether it came from an
 * Attachment-type form field on the original submission, or was shared in
 * a reply by either side — is linked to the report via post_parent, so
 * this one class can manage all of them in one place:
 *
 * - An "Attachments" meta box on the report edit screen listing every
 *   file attached to it, with a manual "Delete" button an admin can use
 *   at any time.
 * - An optional automatic cleanup: an Attachment field can be configured
 *   (per-field, in the Form Builder) to delete its file the moment a
 *   report is marked Solved.
 *
 * @package PSBDx_Smart_Report_Management
 * @since   1.4.5
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class PSBDX_SRM_Attachment_Manager
 *
 * @since 1.4.5
 */
class PSBDX_SRM_Attachment_Manager {

	/**
	 * Constructor.
	 *
	 * @since 1.4.5
	 */
	public function __construct() {
		add_action( 'add_meta_boxes', array( $this, 'register_meta_box' ) );
		add_action( 'wp_ajax_psbdx_srm_delete_attachment', array( $this, 'ajax_delete_attachment' ) );
		add_action( 'psbdx_srm_report_status_changed_to_solved', array( $this, 'delete_on_solved' ), 10, 1 );
	}

	// =========================================================================
	// META BOX
	// =========================================================================

	/**
	 * Registers the "Attachments" meta box on the report log edit screen.
	 *
	 * @since  1.4.5
	 * @return void
	 */
	public function register_meta_box() {
		add_meta_box(
			'psbdx-srm-attachments',
			__( 'Attachments', 'psbdx-smart-report-management' ),
			array( $this, 'render_meta_box' ),
			'psbdx_report_log',
			'side',
			'default'
		);
	}

	/**
	 * Every attachment linked to this report — from the original
	 * submission's Attachment field(s) or from any reply on either side —
	 * since all of them share post_parent = the report's post ID.
	 *
	 * @since  1.4.5
	 * @param  int $report_id  Report log post ID.
	 * @return WP_Post[]
	 */
	public static function get_attachments( $report_id ) {
		return get_posts( array(
			'post_type'      => 'attachment',
			'post_parent'    => $report_id,
			'post_status'    => 'inherit',
			'posts_per_page' => -1,
			'orderby'        => 'date',
			'order'          => 'ASC',
		) );
	}

	/**
	 * Renders the meta box: a thumbnail/file row per attachment, each with
	 * its own "Delete" button.
	 *
	 * @since  1.4.5
	 * @param  WP_Post $post  Current report log post.
	 * @return void
	 */
	public function render_meta_box( $post ) {
		$attachments = self::get_attachments( $post->ID );
		$nonce       = wp_create_nonce( 'psbdx_srm_delete_attachment' );
		?>
		<div class="psbdx-attachments-box" id="psbdx-attachments-box-<?php echo (int) $post->ID; ?>" data-nonce="<?php echo esc_attr( $nonce ); ?>">
			<?php if ( empty( $attachments ) ) : ?>
				<p class="description psbdx-attachments-empty"><?php esc_html_e( 'No attachments on this report yet.', 'psbdx-smart-report-management' ); ?></p>
			<?php else : ?>
				<ul class="psbdx-attachments-list">
					<?php foreach ( $attachments as $attachment ) : ?>
						<?php echo $this->render_attachment_row( $attachment ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped internally. ?>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</div>
		<script>
		( function () {
			var box = document.getElementById( 'psbdx-attachments-box-<?php echo (int) $post->ID; ?>' );
			if ( ! box ) { return; }

			box.addEventListener( 'click', function ( e ) {
				var btn = e.target.closest( '.psbdx-attachment-delete' );
				if ( ! btn ) { return; }

				if ( ! window.confirm( '<?php echo esc_js( __( 'Delete this attachment permanently? This cannot be undone.', 'psbdx-smart-report-management' ) ); ?>' ) ) {
					return;
				}

				var row = btn.closest( '.psbdx-attachment-row' );
				btn.disabled = true;

				var body = new URLSearchParams();
				body.append( 'action', 'psbdx_srm_delete_attachment' );
				body.append( 'security', box.getAttribute( 'data-nonce' ) );
				body.append( 'attachment_id', btn.getAttribute( 'data-attachment-id' ) );
				body.append( 'report_id', '<?php echo (int) $post->ID; ?>' );

				fetch( ajaxurl, { method: 'POST', body: body, credentials: 'same-origin' } )
					.then( function ( r ) { return r.json(); } )
					.then( function ( data ) {
						if ( data && data.success ) {
							if ( row ) { row.remove(); }
							var list = box.querySelector( '.psbdx-attachments-list' );
							if ( list && ! list.children.length ) {
								list.outerHTML = '<p class="description psbdx-attachments-empty"><?php echo esc_js( __( 'No attachments on this report yet.', 'psbdx-smart-report-management' ) ); ?></p>';
							}
						} else {
							btn.disabled = false;
							window.alert( ( data && data.data ) || '<?php echo esc_js( __( 'Could not delete this attachment.', 'psbdx-smart-report-management' ) ); ?>' );
						}
					} )
					.catch( function () {
						btn.disabled = false;
						window.alert( '<?php echo esc_js( __( 'Network error.', 'psbdx-smart-report-management' ) ); ?>' );
					} );
			} );
		}() );
		</script>
		<?php
	}

	/**
	 * Renders one attachment's row in the meta box.
	 *
	 * @since  1.4.5
	 * @param  WP_Post $attachment  Attachment post.
	 * @return string
	 */
	private function render_attachment_row( $attachment ) {
		$image_src = wp_get_attachment_image_src( $attachment->ID, 'thumbnail' );
		$url       = wp_get_attachment_url( $attachment->ID );
		$filename  = basename( get_attached_file( $attachment->ID ) );
		$size_kb   = 0;
		$path      = get_attached_file( $attachment->ID );
		if ( $path && file_exists( $path ) ) {
			$size_kb = round( filesize( $path ) / 1024 );
		}

		ob_start();
		?>
		<li class="psbdx-attachment-row" data-attachment-id="<?php echo (int) $attachment->ID; ?>">
			<a href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noopener noreferrer" class="psbdx-attachment-thumb">
				<?php if ( $image_src ) : ?>
					<img src="<?php echo esc_url( $image_src[0] ); ?>" alt="">
				<?php else : ?>
					<span class="dashicons dashicons-media-default" aria-hidden="true"></span>
				<?php endif; ?>
			</a>
			<div class="psbdx-attachment-meta">
				<a href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noopener noreferrer" class="psbdx-attachment-filename"><?php echo esc_html( $filename ); ?></a>
				<?php if ( $size_kb > 0 ) : ?>
					<span class="psbdx-attachment-size"><?php echo esc_html( size_format( $size_kb * 1024 ) ); ?></span>
				<?php endif; ?>
			</div>
			<button type="button" class="button-link psbdx-attachment-delete" data-attachment-id="<?php echo (int) $attachment->ID; ?>" title="<?php esc_attr_e( 'Delete this attachment', 'psbdx-smart-report-management' ); ?>">
				<span class="dashicons dashicons-trash" aria-hidden="true"></span>
			</button>
		</li>
		<?php
		return ob_get_clean();
	}

	// =========================================================================
	// MANUAL DELETE (AJAX)
	// =========================================================================

	/**
	 * AJAX: an admin manually deleting one attachment, from the meta box.
	 *
	 * @since  1.4.5
	 * @return void  Terminates with wp_send_json_success() or wp_send_json_error().
	 */
	public function ajax_delete_attachment() {
		check_ajax_referer( 'psbdx_srm_delete_attachment', 'security' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'psbdx-smart-report-management' ) );
		}

		$attachment_id = isset( $_POST['attachment_id'] ) ? (int) $_POST['attachment_id'] : 0;
		$report_id     = isset( $_POST['report_id'] ) ? (int) $_POST['report_id'] : 0;

		if ( ! $attachment_id || 'attachment' !== get_post_type( $attachment_id ) ) {
			wp_send_json_error( __( 'Attachment not found.', 'psbdx-smart-report-management' ) );
		}

		// Defense in depth: only allow deleting an attachment through this
		// box if it's actually attached to the report currently being edited.
		$attachment = get_post( $attachment_id );
		if ( ! $attachment || (int) $attachment->post_parent !== $report_id ) {
			wp_send_json_error( __( 'This attachment does not belong to that report.', 'psbdx-smart-report-management' ) );
		}

		self::delete_attachment( $attachment_id, $report_id );

		wp_send_json_success();
	}

	/**
	 * Deletes one attachment file/post, and cleans up the
	 * `_psbdx_attachment_<handle>` meta pointer on the report if this
	 * attachment was the one a v2 Attachment field pointed to (so the
	 * report doesn't keep a dangling reference to a deleted file).
	 *
	 * @since  1.4.5
	 * @param  int $attachment_id  Attachment post ID.
	 * @param  int $report_id      Report log post ID it's attached to.
	 * @return void
	 */
	public static function delete_attachment( $attachment_id, $report_id ) {
		wp_delete_attachment( $attachment_id, true );

		$meta = get_post_meta( $report_id );
		foreach ( $meta as $key => $values ) {
			if ( 0 !== strpos( $key, '_psbdx_attachment_' ) ) {
				continue;
			}
			if ( isset( $values[0] ) && (int) $values[0] === (int) $attachment_id ) {
				delete_post_meta( $report_id, $key );
			}
		}
	}

	// =========================================================================
	// AUTO-DELETE ON SOLVED
	// =========================================================================

	/**
	 * Hooked to `psbdx_srm_report_status_changed_to_solved` — deletes the
	 * attachment for any Attachment field on this report's source form
	 * that has "delete on Solved" turned on. Off by default per field;
	 * reply attachments are never touched by this, only the fields it was
	 * explicitly turned on for.
	 *
	 * @since  1.4.5
	 * @param  int $report_id  Report log post ID.
	 * @return void
	 */
	public function delete_on_solved( $report_id ) {
		$form_id = PSBDX_SRM_Replies::get_source_form_id( $report_id );
		if ( ! $form_id ) {
			return;
		}

		$fields_json = get_post_meta( $form_id, PSBDX_SRM_Form_Builder::FIELDS_META_KEY, true );
		$schema      = $fields_json ? json_decode( $fields_json, true ) : array();

		if ( ! is_array( $schema ) ) {
			return;
		}

		foreach ( $schema as $field ) {
			if ( 'attachment' !== ( $field['type'] ?? '' ) || empty( $field['delete_on_solved'] ) ) {
				continue;
			}

			$handle    = sanitize_key( $field['handle'] ?? '' );
			$meta_key  = '_psbdx_attachment_' . $handle;
			$attach_id = (int) get_post_meta( $report_id, $meta_key, true );

			if ( $attach_id && 'attachment' === get_post_type( $attach_id ) ) {
				wp_delete_attachment( $attach_id, true );
				delete_post_meta( $report_id, $meta_key );
			}
		}
	}
}
