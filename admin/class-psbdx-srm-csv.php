<?php
/**
 * CSV export/import for PSBDx Smart Report Management.
 *
 * Exports reports ("responses") and report forms to CSV, and imports them
 * back — a full-fidelity round trip (every "_psbdx_*" meta value travels
 * along as one JSON column) rather than a hand-picked subset of columns,
 * so nothing about a form's configuration or a report's data is lost.
 *
 * @package PSBDx_Smart_Report_Management
 * @since   1.4.2
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class PSBDX_SRM_CSV
 *
 * @since 1.4.2
 */
class PSBDX_SRM_CSV {

	/**
	 * Constructor.
	 *
	 * @since 1.4.2
	 */
	public function __construct() {
		add_action( 'admin_post_psbdx_srm_export_reports', array( $this, 'export_reports' ) );
		add_action( 'admin_post_psbdx_srm_export_forms',   array( $this, 'export_forms' ) );
		add_action( 'admin_post_psbdx_srm_import_reports', array( $this, 'import_reports' ) );
		add_action( 'admin_post_psbdx_srm_import_forms',   array( $this, 'import_forms' ) );
	}

	// =========================================================================
	// SHARED HELPERS
	// =========================================================================

	/**
	 * Every post meta key for a post, prefixed with '_psbdx_' (i.e.
	 * everything this plugin stores), as a plain assoc array of single values.
	 *
	 * @since 1.4.2
	 * @param  int $post_id  Post ID.
	 * @return array
	 */
	private static function get_plugin_meta( $post_id ) {
		$all  = get_post_meta( $post_id );
		$data = array();

		foreach ( $all as $key => $values ) {
			if ( 0 !== strpos( $key, '_psbdx_' ) ) {
				continue;
			}
			$data[ $key ] = isset( $values[0] ) ? $values[0] : '';
		}

		return $data;
	}

	/**
	 * Streams an array of rows as a downloaded CSV and exits.
	 *
	 * @since 1.4.2
	 * @param  string $filename  Suggested download filename.
	 * @param  array  $header    Column headers.
	 * @param  array  $rows      Array of arrays, one per data row (same column order as $header).
	 * @return void  Does not return — sends headers, writes the file, and exits.
	 */
	private static function stream_csv( $filename, array $header, array $rows ) {
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=' . $filename );

		$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- streaming a generated CSV straight to the browser response; WP_Filesystem has no equivalent for php://output.
		fputcsv( $out, $header );

		foreach ( $rows as $row ) {
			fputcsv( $out, $row );
		}

		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- pairs with the php://output stream opened above.
		exit;
	}

	/**
	 * Reads an uploaded CSV file into an array of associative rows keyed by
	 * its header row.
	 *
	 * @since 1.4.2
	 * @param  string $tmp_path  Path to the uploaded (already validated) tmp file.
	 * @return array[]
	 */
	private static function read_csv( $tmp_path ) {
		$rows   = array();
		$handle = fopen( $tmp_path, 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- reading an already-validated PHP upload tmp file for row-by-row CSV parsing (fgetcsv); WP_Filesystem has no streaming-read equivalent.

		if ( ! $handle ) {
			return $rows;
		}

		$header = fgetcsv( $handle );

		if ( ! $header ) {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- pairs with the fopen() above.
			return $rows;
		}

		$header = array_map( 'trim', $header );

		while ( false !== ( $line = fgetcsv( $handle ) ) ) { // phpcs:ignore Squiz.PHP.DisallowMultipleAssignments.Found, Generic.CodeAnalysis.AssignmentInCondition.Found -- standard fgetcsv loop idiom.
			if ( 1 === count( $line ) && null === $line[0] ) {
				continue; // Blank line.
			}
			$rows[] = array_combine( $header, array_pad( $line, count( $header ), '' ) );
		}

		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- pairs with the fopen() above.

		return $rows;
	}

	/**
	 * Validates a self-submitted CSV upload and returns its tmp path.
	 *
	 * @since 1.4.2
	 * @param  string $field  $_FILES key.
	 * @return string|WP_Error
	 */
	private static function validate_upload( $field ) {
		if ( empty( $_FILES[ $field ] ) || ! isset( $_FILES[ $field ]['error'] ) || UPLOAD_ERR_OK !== $_FILES[ $field ]['error'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce already verified by every caller (check_admin_referer()) before validate_upload() is ever reached.
			return new WP_Error( 'psbdx_no_file', __( 'Please choose a CSV file to upload.', 'psbdx-smart-report-management' ) );
		}

		$file = $_FILES[ $field ]; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Missing -- file upload array, not user text input; nonce already verified by the caller, see above.
		$ext  = strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) );

		if ( 'csv' !== $ext ) {
			return new WP_Error( 'psbdx_bad_file', __( 'Please upload a .csv file.', 'psbdx-smart-report-management' ) );
		}

		if ( ! is_uploaded_file( $file['tmp_name'] ) ) {
			return new WP_Error( 'psbdx_bad_upload', __( 'File upload failed. Please try again.', 'psbdx-smart-report-management' ) );
		}

		return $file['tmp_name'];
	}

	/**
	 * Finds a psbdx_report_form post by its exact title — used by
	 * import_forms() to decide whether a CSV row should update an
	 * existing form or create a new one.
	 *
	 * @since 1.4.3
	 * @param  string $title  Exact post title to match.
	 * @return WP_Post|null
	 */
	private static function find_form_by_title( $title ) {
		$matches = get_posts(
			array(
				'post_type'              => 'psbdx_report_form',
				'post_status'            => array( 'publish', 'draft', 'private' ),
				'title'                  => $title,
				'posts_per_page'         => 1,
				'orderby'                => 'ID',
				'order'                  => 'ASC',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);

		return ! empty( $matches ) ? $matches[0] : null;
	}

	/**
	 * Redirects back to the settings page with a one-time status message.
	 *
	 * @since 1.4.2
	 * @param  string $code     Message code (becomes the query var value).
	 * @param  string $message  Human-readable message.
	 * @param  string $type     'success' or 'error'.
	 * @return void
	 */
	private static function redirect_with_message( $code, $message, $type = 'success' ) {
		add_settings_error( 'psbdx_srm_repair', $code, $message, $type );
		set_transient( 'settings_errors', get_settings_errors(), 30 );

		wp_safe_redirect( admin_url( 'admin.php?page=' . PSBDX_SRM_Admin_Tools::PAGE_REPAIR . '&settings-updated=1' ) );
		exit;
	}

	// =========================================================================
	// REPORTS (RESPONSES)
	// =========================================================================

	/**
	 * Exports every report log as a CSV download.
	 *
	 * @since  1.4.2
	 * @return void  Does not return.
	 */
	public function export_reports() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'psbdx-smart-report-management' ) );
		}

		check_admin_referer( 'psbdx_srm_export_reports' );

		$posts = get_posts( array(
			'post_type'      => 'psbdx_report_log',
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'orderby'        => 'ID',
			'order'          => 'ASC',
		) );

		$rows = array();

		foreach ( $posts as $post ) {
			$rows[] = array(
				$post->ID,
				PSBDX_SRM_Helpers::get_ticket_id( $post->ID ),
				$post->post_title,
				$post->post_content,
				$post->post_status,
				get_post_meta( $post->ID, '_psbdx_report_status',   true ),
				get_post_meta( $post->ID, '_psbdx_report_category', true ),
				get_post_meta( $post->ID, '_psbdx_report_priority', true ),
				get_post_meta( $post->ID, '_psbdx_reporter_email', true ),
				$post->post_author,
				$post->post_date_gmt,
				wp_json_encode( self::get_plugin_meta( $post->ID ) ),
			);
		}

		self::stream_csv(
			'psbdx-reports-' . gmdate( 'Y-m-d' ) . '.csv',
			array( 'id', 'ticket_id', 'title', 'content', 'post_status', 'status', 'category', 'priority', 'reporter_email', 'author_id', 'created_gmt', 'meta_json' ),
			$rows
		);
	}

	/**
	 * Imports report logs from an uploaded CSV. Rows whose ticket_id
	 * matches an existing report update it in place; everything else is
	 * inserted as a new report.
	 *
	 * @since  1.4.2
	 * @return void  Does not return (redirects back with a result message).
	 */
	public function import_reports() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'psbdx-smart-report-management' ) );
		}

		check_admin_referer( 'psbdx_srm_import_reports' );

		$tmp = self::validate_upload( 'psbdx_csv_file' );

		if ( is_wp_error( $tmp ) ) {
			self::redirect_with_message( 'import_reports_error', $tmp->get_error_message(), 'error' );
		}

		$rows      = self::read_csv( $tmp );
		$created   = 0;
		$updated   = 0;

		foreach ( $rows as $row ) {
			$ticket_id  = isset( $row['ticket_id'] ) ? sanitize_text_field( $row['ticket_id'] ) : '';
			$existing   = 0;

			if ( $ticket_id ) {
				$existing_posts = get_posts( array(
					'post_type'      => 'psbdx_report_log',
					'post_status'    => 'any',
					'posts_per_page' => 1,
					'meta_key'       => PSBDX_SRM_Helpers::TICKET_ID_META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
					'meta_value'     => $ticket_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
					'fields'         => 'ids',
				) );
				$existing = ! empty( $existing_posts ) ? (int) $existing_posts[0] : 0;
			}

			$post_args = array(
				'post_type'    => 'psbdx_report_log',
				'post_title'   => isset( $row['title'] ) ? sanitize_text_field( $row['title'] ) : '',
				'post_content' => isset( $row['content'] ) ? wp_kses_post( $row['content'] ) : '',
				'post_status'  => isset( $row['post_status'] ) && in_array( $row['post_status'], array( 'publish', 'draft', 'private' ), true ) ? $row['post_status'] : 'publish',
				'post_author'  => isset( $row['author_id'] ) ? (int) $row['author_id'] : 0,
			);

			if ( $existing ) {
				$post_args['ID'] = $existing;
				wp_update_post( $post_args );
				$post_id = $existing;
				$updated++;
			} else {
				$post_id = wp_insert_post( $post_args );
				$created++;
			}

			if ( ! $post_id || is_wp_error( $post_id ) ) {
				continue;
			}

			// Restore full meta from the JSON column when present (full
			// fidelity round-trip); otherwise fall back to the readable columns.
			$meta = array();
			if ( ! empty( $row['meta_json'] ) ) {
				$decoded = json_decode( $row['meta_json'], true );
				if ( is_array( $decoded ) ) {
					$meta = $decoded;
				}
			}

			if ( empty( $meta ) ) {
				$meta = array(
					PSBDX_SRM_Helpers::TICKET_ID_META => $ticket_id,
					'_psbdx_report_status'            => isset( $row['status'] )          ? $row['status']          : 'Processing',
					'_psbdx_report_category'          => isset( $row['category'] )        ? $row['category']        : '',
					'_psbdx_report_priority'          => isset( $row['priority'] )         ? $row['priority']        : '',
					'_psbdx_reporter_email'           => isset( $row['reporter_email'] )   ? $row['reporter_email']  : '',
				);
			} elseif ( '' === ( $meta[ PSBDX_SRM_Helpers::TICKET_ID_META ] ?? '' ) && $ticket_id ) {
				$meta[ PSBDX_SRM_Helpers::TICKET_ID_META ] = $ticket_id;
			}

			foreach ( $meta as $meta_key => $meta_value ) {
				if ( 0 !== strpos( (string) $meta_key, '_psbdx_' ) ) {
					continue;
				}
				update_post_meta( $post_id, $meta_key, sanitize_text_field( (string) $meta_value ) );
			}

			// A row with no ticket ID at all (very old export, or a
			// hand-built CSV) still needs one so replies/AI features work.
			if ( '' === get_post_meta( $post_id, PSBDX_SRM_Helpers::TICKET_ID_META, true ) ) {
				update_post_meta( $post_id, PSBDX_SRM_Helpers::TICKET_ID_META, PSBDX_SRM_Helpers::generate_ticket_id() );
			}
		}

		self::redirect_with_message(
			'import_reports_done',
			sprintf(
				/* translators: 1: number created, 2: number updated */
				__( 'Import complete: %1$d report(s) created, %2$d updated.', 'psbdx-smart-report-management' ),
				$created,
				$updated
			),
			'success'
		);
	}

	// =========================================================================
	// FORMS
	// =========================================================================

	/**
	 * Exports every report form as a CSV download.
	 *
	 * @since  1.4.2
	 * @return void  Does not return.
	 */
	public function export_forms() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'psbdx-smart-report-management' ) );
		}

		check_admin_referer( 'psbdx_srm_export_forms' );

		$posts = get_posts( array(
			'post_type'      => 'psbdx_report_form',
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'orderby'        => 'ID',
			'order'          => 'ASC',
		) );

		$rows = array();

		foreach ( $posts as $post ) {
			$rows[] = array(
				$post->ID,
				$post->post_title,
				$post->post_status,
				wp_json_encode( self::get_plugin_meta( $post->ID ) ),
			);
		}

		self::stream_csv(
			'psbdx-forms-' . gmdate( 'Y-m-d' ) . '.csv',
			array( 'id', 'title', 'post_status', 'meta_json' ),
			$rows
		);
	}

	/**
	 * Imports report forms from an uploaded CSV. A row whose title exactly
	 * matches an existing form updates it in place; everything else is
	 * created as a new form.
	 *
	 * @since  1.4.2
	 * @return void  Does not return (redirects back with a result message).
	 */
	public function import_forms() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'psbdx-smart-report-management' ) );
		}

		check_admin_referer( 'psbdx_srm_import_forms' );

		$tmp = self::validate_upload( 'psbdx_csv_file' );

		if ( is_wp_error( $tmp ) ) {
			self::redirect_with_message( 'import_forms_error', $tmp->get_error_message(), 'error' );
		}

		$rows    = self::read_csv( $tmp );
		$created = 0;
		$updated = 0;

		foreach ( $rows as $row ) {
			$title    = isset( $row['title'] ) ? sanitize_text_field( $row['title'] ) : __( 'Imported Form', 'psbdx-smart-report-management' );
			$existing = self::find_form_by_title( $title );

			$post_args = array(
				'post_type'   => 'psbdx_report_form',
				'post_title'  => $title,
				'post_status' => isset( $row['post_status'] ) && in_array( $row['post_status'], array( 'publish', 'draft', 'private' ), true ) ? $row['post_status'] : 'publish',
			);

			if ( $existing ) {
				$post_args['ID'] = $existing->ID;
				wp_update_post( $post_args );
				$post_id = $existing->ID;
				$updated++;
			} else {
				$post_id = wp_insert_post( $post_args );
				$created++;
			}

			if ( ! $post_id || is_wp_error( $post_id ) || empty( $row['meta_json'] ) ) {
				continue;
			}

			$meta = json_decode( $row['meta_json'], true );

			if ( ! is_array( $meta ) ) {
				continue;
			}

			foreach ( $meta as $meta_key => $meta_value ) {
				if ( 0 !== strpos( (string) $meta_key, '_psbdx_' ) ) {
					continue;
				}
				// Field/category/allowed-email definitions are stored as
				// JSON-encoded strings; everything else is a plain scalar.
				// Either way this is our own previously-exported value, so
				// store it back as-is rather than re-encoding it.
				update_post_meta( $post_id, $meta_key, (string) $meta_value );
			}
		}

		self::redirect_with_message(
			'import_forms_done',
			sprintf(
				/* translators: 1: number created, 2: number updated */
				__( 'Import complete: %1$d form(s) created, %2$d updated.', 'psbdx-smart-report-management' ),
				$created,
				$updated
			),
			'success'
		);
	}
}
