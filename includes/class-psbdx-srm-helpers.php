<?php
/**
 * Shared helper functions for PSBDx Smart Report Management.
 *
 * @package PSBDx_Smart_Report_Management
 * @since   1.0.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class PSBDX_SRM_Helpers
 *
 * Static utility methods used across admin and public components.
 *
 * @since 1.0.0
 */
class PSBDX_SRM_Helpers {

	/**
	 * Option key for admin-defined custom report statuses.
	 *
	 * @since 1.2.0
	 * @var string
	 */
	const CUSTOM_STATUSES_OPTION = 'psbdx_srm_custom_statuses';
	const GLOBAL_RATE_LIMIT_OPTION = 'psbdx_srm_global_rate_limit_mins';

	/**
	 * Option key for admin-defined report categories.
	 *
	 * @since 1.4.1
	 * @var string
	 */
	const CATEGORIES_OPTION = 'psbdx_srm_report_categories';

	/**
	 * Post meta key storing each report's unique, human-readable ticket ID.
	 *
	 * @since 1.4.1
	 * @var string
	 */
	const TICKET_ID_META = '_psbdx_report_ticket_id';

	/**
	 * Option key for the admin-managed FAQ list ([psbdx_faq] shortcode).
	 *
	 * @since 1.4.1
	 * @var string
	 */
	const FAQ_OPTION = 'psbdx_srm_faq_items';

	/**
	 * Built-in status keys (not removable via settings UI).
	 *
	 * @since 1.2.0
	 * @return string[]
	 */
	public static function get_builtin_status_keys() {
		return array(
			'Processing',
			'Contacting user',
			'Waiting for user response',
			'Solved',
			'Failed to Contact user',
		);
	}

	/**
	 * Default report statuses (bundled with the plugin).
	 *
	 * @since 1.2.0
	 * @return array<string, array{label: string, bg: string, color: string}>
	 */
	public static function get_default_statuses() {
		return array(
			'Processing'                => array(
				'label' => __( 'Processing', 'psbdx-smart-report-management' ),
				'bg'    => '#e2e8f0',
				'color' => '#475569',
			),
			'Contacting user'           => array(
				'label' => __( 'Contacting User', 'psbdx-smart-report-management' ),
				'bg'    => '#f3e8ff',
				'color' => '#6b21a8',
			),
			'Waiting for user response' => array(
				'label' => __( 'Waiting Response', 'psbdx-smart-report-management' ),
				'bg'    => '#ffedd5',
				'color' => '#9a3412',
			),
			'Solved'                    => array(
				'label' => __( 'Solved', 'psbdx-smart-report-management' ),
				'bg'    => '#dcfce7',
				'color' => '#166534',
			),
			'Failed to Contact user'    => array(
				'label' => __( 'Failed to Contact', 'psbdx-smart-report-management' ),
				'bg'    => '#fee2e2',
				'color' => '#991b1b',
			),
		);
	}

	/**
	 * Raw custom statuses from the options table.
	 *
	 * @since 1.2.0
	 * @return array<string, array{label: string, bg: string, color: string}>
	 */
	public static function get_custom_statuses_stored() {
		$raw = get_option( self::CUSTOM_STATUSES_OPTION, array() );
		return is_array( $raw ) ? $raw : array();
	}

	/**
	 * Normalizes and validates stored custom statuses (keys, colours, labels).
	 *
	 * @since 1.2.0
	 * @param  array $map  Raw map status_key => data.
	 * @return array<string, array{label: string, bg: string, color: string}>
	 */
	public static function sanitize_custom_status_map( array $map ) {
		$defaults = self::get_default_statuses();
		$out      = array();

		foreach ( $map as $key => $row ) {
			$key = sanitize_key( (string) $key );

			if ( '' === $key || isset( $defaults[ $key ] ) ) {
				continue;
			}

			if ( 0 !== strpos( $key, 'psbdx_c_', 0 ) ) {
				continue;
			}

			if ( ! is_array( $row ) ) {
				continue;
			}

			$label = isset( $row['label'] ) ? sanitize_text_field( (string) $row['label'] ) : '';

			if ( '' === $label ) {
				continue;
			}

			$bg    = isset( $row['bg'] ) ? sanitize_hex_color( (string) $row['bg'] ) : '';
			$color = isset( $row['color'] ) ? sanitize_hex_color( (string) $row['color'] ) : '';

			if ( ! $bg ) {
				$bg = '#e2e8f0';
			}

			if ( ! $color ) {
				$color = '#475569';
			}

			$out[ $key ] = array(
				'label' => $label,
				'bg'    => $bg,
				'color' => $color,
			);
		}

		return $out;
	}

	/**
	 * Returns the full list of available report statuses with display labels
	 * and badge colours.
	 *
	 * @since 1.0.0
	 * @return array<string, array{label: string, bg: string, color: string}>
	 */
	public static function get_statuses() {
		return array_merge(
			self::get_default_statuses(),
			self::sanitize_custom_status_map( self::get_custom_statuses_stored() )
		);
	}

	/**
	 * Whether a status key exists (built-in or custom).
	 *
	 * @since 1.2.0
	 * @param  string $status  Status key.
	 * @return bool
	 */
	public static function is_valid_status_key( $status ) {
		$status = (string) $status;

		return '' !== $status && isset( self::get_statuses()[ $status ] );
	}

	/**
	 * Human-readable label for a stored status value.
	 *
	 * @since 1.2.0
	 * @param  string $status  Status key or legacy free-text value.
	 * @return string
	 */
	public static function get_status_label( $status ) {
		$all = self::get_statuses();

		if ( isset( $all[ $status ] ) ) {
			return $all[ $status ]['label'];
		}

		return $status ? (string) $status : __( 'Unknown', 'psbdx-smart-report-management' );
	}

	/**
	 * Returns the CSS class name for a given status key.
	 *
	 * @since  1.0.0
	 * @param  string $status  Status key (e.g. 'Solved').
	 * @return string          CSS class name.
	 */
	public static function get_status_class( $status ) {
		$map = array(
			'Processing'                => 'psbdx-status-processing',
			'Contacting user'           => 'psbdx-status-contacting',
			'Waiting for user response' => 'psbdx-status-waiting',
			'Solved'                    => 'psbdx-status-solved',
			'Failed to Contact user'    => 'psbdx-status-failed',
		);

		if ( isset( $map[ $status ] ) ) {
			return $map[ $status ];
		}

		return 0 === strpos( (string) $status, 'psbdx_c_', 0 )
			? 'psbdx-status-custom'
			: 'psbdx-status-unknown';
	}

	/**
	 * Inline styles for a status chip (used when custom colours must apply).
	 *
	 * @since 1.2.0
	 * @param  string $status  Status key.
	 * @return string          Safe style attribute value (no "style=" wrapper).
	 */
	public static function get_status_inline_style( $status ) {
		$all = self::get_statuses();

		if ( isset( $all[ $status ] ) ) {
			return sprintf(
				'background:%1$s;color:%2$s;',
				esc_attr( $all[ $status ]['bg'] ),
				esc_attr( $all[ $status ]['color'] )
			);
		}

		return 'background:#e2e8f0;color:#475569;';
	}

	/**
	 * Aggregated report counts per status in one database round-trip.
	 *
	 * Posts without a status meta row are counted as "Processing".
	 *
	 * @since 1.2.0
	 * @return array<string, int>
	 */
	public static function get_report_status_counts() {
		global $wpdb;

		$sql = $wpdb->prepare(
			"SELECT pm.meta_value AS st, COUNT(DISTINCT p.ID) AS cnt
			FROM {$wpdb->posts} p
			INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = %s
			WHERE p.post_type = %s AND p.post_status = 'publish'
			GROUP BY pm.meta_value",
			'_psbdx_report_status',
			'psbdx_report_log'
		);

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- $sql is built with prepare(); result is aggregated per-request and must not be cached (stale counts break the dashboard widget).
		$rows = $wpdb->get_results( $sql, ARRAY_A );

		$counts = array();

		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				$key = isset( $row['st'] ) ? (string) $row['st'] : '';

				if ( '' === $key ) {
					$key = 'Processing';
				}

				$counts[ $key ] = isset( $counts[ $key ] ) ? $counts[ $key ] + (int) $row['cnt'] : (int) $row['cnt'];
			}
		}

		$counts_obj = wp_count_posts( 'psbdx_report_log' );
		$published  = isset( $counts_obj->publish ) ? (int) $counts_obj->publish : 0;

		$assigned = array_sum( $counts );

		if ( $published > $assigned ) {
			$counts['Processing'] = isset( $counts['Processing'] )
				? $counts['Processing'] + ( $published - $assigned )
				: ( $published - $assigned );
		}

		return $counts;
	}

	/**
	 * Global report cooldown (minutes) used when a form-level cooldown
	 * is not configured.
	 *
	 * @since 1.2.0
	 * @return int
	 */
	public static function get_global_rate_limit_mins() {
		$value = get_option( self::GLOBAL_RATE_LIMIT_OPTION, 30 );
		return min( 1440, max( 0, (int) $value ) );
	}

	/**
	 * Best-effort visitor IP address, for lightweight throttling only
	 * (not a security control — REMOTE_ADDR is the one value here that
	 * can't be spoofed by a request header, so it's what we key on).
	 *
	 * @since 1.4.5
	 * @return string
	 */
	public static function get_client_ip() {
		return isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
	}

	/**
	 * Effective cooldown for a specific form.
	 *
	 * Form meta value overrides global settings. If form meta is missing/empty,
	 * the global rate limit is used.
	 *
	 * @since 1.2.0
	 * @param  int $form_id Report form post ID.
	 * @return int
	 */
	public static function get_effective_cooldown_mins( $form_id ) {
		$raw = get_post_meta( (int) $form_id, '_psbdx_cooldown_mins', true );

		if ( '' === $raw || null === $raw ) {
			return self::get_global_rate_limit_mins();
		}

		return min( 1440, max( 0, (int) $raw ) );
	}

	/**
	 * Retrieves the parsed list of report reasons for a given form.
	 *
	 * Always appends "Other" as the last option.
	 *
	 * @since  1.0.0
	 * @param  int $form_id  Post ID of the report form.
	 * @return string[]      Array of reason strings.
	 */
	public static function get_form_reasons( $form_id ) {
		$raw = get_post_meta( $form_id, '_psbdx_reasons', true );

		if ( ! empty( $raw ) ) {
			$reasons = array_map( 'trim', explode( ',', $raw ) );
			$reasons = array_filter( $reasons );
			$reasons = array_values( array_diff( $reasons, array( 'Other' ) ) );
		} else {
			$reasons = array(
				__( 'Product not Working',   'psbdx-smart-report-management' ),
				__( 'Order not Delivered',   'psbdx-smart-report-management' ),
				__( 'Want to Cancel',        'psbdx-smart-report-management' ),
			);
		}

		$reasons[] = __( 'Other', 'psbdx-smart-report-management' );

		return $reasons;
	}

	/**
	 * Retrieves the parsed list of extra custom fields for a given form.
	 *
	 * @since  1.0.0
	 * @param  int $form_id  Post ID of the report form.
	 * @return string[]      Array of field label strings.
	 */
	public static function get_custom_fields( $form_id ) {
		$raw = get_post_meta( $form_id, '_psbdx_custom_fields', true );

		if ( empty( $raw ) ) {
			return array();
		}

		return array_values(
			array_filter(
				array_map( 'trim', explode( ',', $raw ) )
			)
		);
	}

	/**
	 * Validates and resolves a WooCommerce order ID from a submitted report,
	 * ensuring the order belongs to the currently logged-in user.
	 *
	 * @since  1.0.0
	 * @param  int    $order_id     Order ID passed from the form (may be 0).
	 * @param  string $source_url   Source URL, used as a fallback to detect order ID.
	 * @param  int    $user_id      ID of the currently logged-in user (0 = guest).
	 * @return int                  Validated order ID, or 0 if invalid/unverifiable.
	 */
	public static function resolve_woo_order_id( $order_id, $source_url, $user_id ) {
		// Guests cannot link orders.
		if ( ! $user_id ) {
			return 0;
		}

		// Attempt to detect order ID from URL if not passed explicitly.
		if ( ! $order_id && ! empty( $source_url ) ) {
			if ( preg_match( '#/view-order/(\d+)#', $source_url, $matches ) ) {
				$order_id = (int) $matches[1];
			}
		}

		if ( ! $order_id ) {
			return 0;
		}

		// Never trust a submitted order ID unless WooCommerce can verify ownership.
		if ( ! function_exists( 'wc_get_order' ) ) {
			return 0;
		}

		$order = wc_get_order( $order_id );

		if ( ! $order || (int) $order->get_customer_id() !== $user_id ) {
			return 0;
		}

		return $order_id;
	}

	/**
	 * Returns the WooCommerce order edit URL, with HPOS support.
	 *
	 * @since  1.0.0
	 * @param  int $order_id  WooCommerce order ID.
	 * @return string         Admin URL to edit/view the order.
	 */
	public static function get_order_edit_url( $order_id ) {
		if ( function_exists( 'wc_get_order' ) ) {
			$order = wc_get_order( $order_id );
			if ( $order ) {
				return $order->get_edit_order_url();
			}
		}

		return admin_url( 'post.php?post=' . absint( $order_id ) . '&action=edit' );
	}

	/**
	 * Published report forms (cached per request).
	 *
	 * @since 1.2.0
	 * @return WP_Post[]
	 */
	public static function get_published_report_forms() {
		static $cache = null;

		if ( null !== $cache ) {
			return $cache;
		}

		$cache = get_posts(
			array(
				'post_type'      => 'psbdx_report_form',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'orderby'        => 'title',
				'order'          => 'ASC',
				'no_found_rows'  => true,
			)
		);

		return $cache;
	}

	// =========================================================================
	// REPORT CATEGORIES & PRIORITY
	// =========================================================================

	/**
	 * Admin-defined report categories, used for both manual classification
	 * and (when configured) to constrain the AI category suggestion.
	 *
	 * @since 1.4.1
	 * @return string[]
	 */
	public static function get_report_categories() {
		$raw = get_option( self::CATEGORIES_OPTION, array() );

		if ( ! is_array( $raw ) ) {
			return array();
		}

		return self::sanitize_report_categories( $raw );
	}

	/**
	 * Normalizes a raw list of category labels: trims, drops empties, and
	 * removes duplicates while preserving admin-chosen order.
	 *
	 * @since 1.4.1
	 * @param  array $raw  Raw category labels.
	 * @return string[]
	 */
	public static function sanitize_report_categories( array $raw ) {
		$out = array();

		foreach ( $raw as $label ) {
			$label = sanitize_text_field( (string) $label );

			if ( '' === $label || in_array( $label, $out, true ) ) {
				continue;
			}

			$out[] = $label;
		}

		return $out;
	}

	/**
	 * Whether a category label is part of the admin-configured list.
	 *
	 * @since 1.4.1
	 * @param  string $category  Category label.
	 * @return bool
	 */
	public static function is_valid_report_category( $category ) {
		$category = (string) $category;

		return '' !== $category && in_array( $category, self::get_report_categories(), true );
	}

	/**
	 * Fixed set of report priority levels.
	 *
	 * @since 1.4.1
	 * @return array<string, string>  Priority key => display label.
	 */
	public static function get_report_priorities() {
		return array(
			'Low'    => __( 'Low', 'psbdx-smart-report-management' ),
			'Medium' => __( 'Medium', 'psbdx-smart-report-management' ),
			'High'   => __( 'High', 'psbdx-smart-report-management' ),
		);
	}

	/**
	 * Whether a priority key is one of the fixed Low/Medium/High values.
	 *
	 * @since 1.4.1
	 * @param  string $priority  Priority key.
	 * @return bool
	 */
	public static function is_valid_report_priority( $priority ) {
		return isset( self::get_report_priorities()[ (string) $priority ] );
	}

	/**
	 * Inline badge style for a priority value.
	 *
	 * @since 1.4.1
	 * @param  string $priority  Priority key.
	 * @return string             Safe style attribute value (no "style=" wrapper).
	 */
	public static function get_priority_badge_style( $priority ) {
		$map = array(
			'Low'    => array(
				'bg'    => '#dcfce7',
				'color' => '#166534',
			),
			'Medium' => array(
				'bg'    => '#ffedd5',
				'color' => '#9a3412',
			),
			'High'   => array(
				'bg'    => '#fee2e2',
				'color' => '#991b1b',
			),
		);

		$s = isset( $map[ $priority ] ) ? $map[ $priority ] : array(
			'bg'    => '#e2e8f0',
			'color' => '#475569',
		);

		return sprintf( 'background:%1$s;color:%2$s;', esc_attr( $s['bg'] ), esc_attr( $s['color'] ) );
	}

	// =========================================================================
	// TICKET IDs
	// =========================================================================

	/**
	 * Generates a unique, human-readable ticket ID for a new report, e.g.
	 * "PSRM-20260714-8K3F2A". Both the reporting user and admins reference
	 * this ID, and it's echoed back by the AI classification response so
	 * the plugin can confirm which report a reply belongs to.
	 *
	 * @since 1.4.1
	 * @return string
	 */
	public static function generate_ticket_id() {
		$attempts = 0;

		do {
			$ticket_id = sprintf(
				'PSRM-%s-%s',
				current_time( 'Ymd' ),
				strtoupper( wp_generate_password( 6, false, false ) )
			);

			$existing = get_posts(
				array(
					'post_type'      => 'psbdx_report_log',
					'post_status'    => 'any',
					'meta_key'       => self::TICKET_ID_META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
					'meta_value'     => $ticket_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
					'fields'         => 'ids',
					'posts_per_page' => 1,
					'no_found_rows'  => true,
				)
			);

			$attempts++;
		} while ( ! empty( $existing ) && $attempts < 5 );

		return $ticket_id;
	}

	/**
	 * Gets a report's stored ticket ID.
	 *
	 * @since 1.4.1
	 * @param  int $post_id  Report log post ID.
	 * @return string
	 */
	public static function get_ticket_id( $post_id ) {
		return (string) get_post_meta( $post_id, self::TICKET_ID_META, true );
	}

	/**
	 * The single place a report's status should ever be written from.
	 *
	 * Every other part of this plugin that changes a report's status goes
	 * through here instead of calling update_post_meta() directly, so that
	 * exactly one reliable event fires per real change — this is the hook
	 * external integrations (including third-party/paid add-ons) should use
	 * instead of polling the database for status changes.
	 *
	 * Fires two action hooks on every actual change (no-op, no hook, if the
	 * new status is the same as the old one):
	 *
	 * - `psbdx_srm_report_status_changed( $report_id, $old_status, $new_status, $context )`
	 *   Fires for every change, whatever the new status is.
	 *
	 * - `psbdx_srm_report_status_changed_to_{$new_status}( $report_id, $old_status, $context )`
	 *   ($new_status run through sanitize_key(), e.g. `psbdx_srm_report_status_changed_to_solved`)
	 *   Fires only for that specific new status, so an integration that only
	 *   cares about one transition doesn't need to filter inside a generic callback.
	 *
	 * $context is an associative array with:
	 * - report_id        (int)          Same as the first hook argument, included for convenience.
	 * - ticket_id         (string)       The report's human-readable ticket ID.
	 * - submitter_id      (int)          Numeric WP user ID of whoever filed the report, or 0 for a guest.
	 * - submitter_email   (string)       The reporter's email address, if collected.
	 * - old_status        (string|null)  Same as the second hook argument; null means this report had no prior status (i.e. this is the first status it's ever had).
	 * - new_status        (string)       Same as the third/second hook argument.
	 * - changed_by        (int)          Numeric WP user ID of whoever triggered the change (the logged-in admin, typically), or 0 if there wasn't one (e.g. a guest's own submission, or an automated process).
	 * - updated_at        (string)       MySQL datetime in GMT/UTC.
	 * - updated_at_local  (string)       MySQL datetime in the site's local timezone.
	 * - source            (string)       Where the change came from: 'submission' (a brand-new report), 'admin' (the report edit screen), or whatever the caller passes.
	 *
	 * @since 1.4.2
	 * @param  int    $report_id   Report log post ID.
	 * @param  string $new_status  New status key (e.g. 'Processing', 'Solved').
	 * @param  array  $args        Optional overrides for the $context array above (e.g. array( 'source' => 'submission' )).
	 * @return bool  True if the status actually changed (and the hooks fired), false if it was already that status.
	 */
	public static function update_report_status( $report_id, $new_status, $args = array() ) {
		$report_id  = (int) $report_id;
		$old_status = get_post_meta( $report_id, '_psbdx_report_status', true );
		$old_status = ( '' === $old_status ) ? null : $old_status;

		if ( $old_status === $new_status ) {
			return false;
		}

		update_post_meta( $report_id, '_psbdx_report_status', $new_status );

		$post = get_post( $report_id );

		$context = wp_parse_args(
			$args,
			array(
				'report_id'        => $report_id,
				'ticket_id'        => self::get_ticket_id( $report_id ),
				'submitter_id'     => $post ? (int) $post->post_author : 0,
				'submitter_email'  => get_post_meta( $report_id, '_psbdx_reporter_email', true ),
				'old_status'       => $old_status,
				'new_status'       => $new_status,
				'changed_by'       => get_current_user_id(),
				'updated_at'       => current_time( 'mysql', true ),
				'updated_at_local' => current_time( 'mysql' ),
				'source'           => 'unknown',
			)
		);

		/** This action is documented above, in update_report_status()'s docblock. */
		do_action( 'psbdx_srm_report_status_changed', $report_id, $old_status, $new_status, $context );

		/** This action is documented above, in update_report_status()'s docblock. */
		do_action( 'psbdx_srm_report_status_changed_to_' . sanitize_key( $new_status ), $report_id, $old_status, $context );

		return true;
	}

	/**
	 * Looks up a report log post by its ticket ID.
	 *
	 * @since 1.4.2
	 * @param  string $ticket_id  Ticket ID (e.g. "PSRM-20260714-8K3F2A").
	 * @return int  Report log post ID, or 0 if not found.
	 */
	public static function get_report_by_ticket_id( $ticket_id ) {
		$ticket_id = sanitize_text_field( (string) $ticket_id );

		if ( '' === $ticket_id ) {
			return 0;
		}

		$posts = get_posts(
			array(
				'post_type'      => 'psbdx_report_log',
				'post_status'    => 'any',
				'meta_key'       => self::TICKET_ID_META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'     => $ticket_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'fields'         => 'ids',
				'posts_per_page' => 1,
				'no_found_rows'  => true,
			)
		);

		return ! empty( $posts ) ? (int) $posts[0] : 0;
	}

	// =========================================================================
	// FAQ
	// =========================================================================

	/**
	 * Admin-managed list of FAQ question/answer pairs, shown by [psbdx_faq].
	 *
	 * @since 1.4.1
	 * @return array<int, array{question: string, answer: string}>
	 */
	public static function get_faq_items() {
		$raw = get_option( self::FAQ_OPTION, array() );

		return is_array( $raw ) ? self::sanitize_faq_items( $raw ) : array();
	}

	/**
	 * Normalizes raw FAQ rows: trims, drops incomplete pairs.
	 *
	 * @since 1.4.1
	 * @param  array $raw  Raw FAQ rows (each with 'question'/'answer').
	 * @return array<int, array{question: string, answer: string}>
	 */
	public static function sanitize_faq_items( array $raw ) {
		$out = array();

		foreach ( $raw as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$question = isset( $item['question'] ) ? sanitize_text_field( (string) $item['question'] ) : '';
			$answer   = isset( $item['answer'] ) ? sanitize_textarea_field( (string) $item['answer'] ) : '';

			if ( '' === $question || '' === $answer ) {
				continue;
			}

			$out[] = array(
				'question' => $question,
				'answer'   => $answer,
			);
		}

		return $out;
	}
}
