<?php
/**
 * WooCommerce integration for PSBDx Smart Report Management.
 *
 * @package PSBDx_Smart_Report_Management
 * @since   1.0.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class PSBDX_SRM_Woo_Integration
 *
 * Hooks the report button into WooCommerce product pages and order
 * detail pages, and adds a report form selector to the product
 * data meta box in WooCommerce.
 *
 * All hooks are only added when WooCommerce is active.
 *
 * @since 1.0.0
 */
class PSBDX_SRM_Woo_Integration {

	/**
	 * Constructor — conditionally registers WooCommerce hooks.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		if ( $this->woocommerce_active() ) {
			add_action( 'woocommerce_product_options_general_product_data', array( $this, 'render_product_selector' ) );
			add_action( 'woocommerce_process_product_meta',                 array( $this, 'save_product_meta' ) );
			add_action( 'woocommerce_single_product_summary',               array( $this, 'auto_render_on_product' ), 35 );
			add_action( 'woocommerce_order_details_after_order_table',      array( $this, 'render_on_order_page' ), 10, 1 );
		}

		// LearnPress is optional and independent of WooCommerce — register only when LP is loaded.
		if ( $this->learnpress_active() ) {
			add_action( 'learn-press/content-landing-summary',              array( $this, 'auto_render_on_product' ), 45 );
			add_action( 'learn-press/after-content-item-summary/lp_lesson', array( $this, 'auto_render_on_product' ), 20 );
			add_action( 'learn-press/after-content-item-summary/lp_quiz',   array( $this, 'auto_render_on_product' ), 20 );
		}
	}

	/**
	 * Check whether WooCommerce is active.
	 *
	 * @since  1.0.0
	 * @return bool
	 */
	private function woocommerce_active() {
		return class_exists( 'WooCommerce' );
	}

	/**
	 * Check whether LearnPress is active (course CPT registered).
	 *
	 * @since 1.1.0
	 * @return bool
	 */
	private function learnpress_active() {
		return defined( 'LEARNPRESS_VERSION' ) || post_type_exists( 'lp_course' );
	}

	/**
	 * Render the report form selector inside the WooCommerce
	 * "General" product data tab.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	public function render_product_selector() {
		$forms = PSBDX_SRM_Helpers::get_published_report_forms();
		$options = array( '' => __( 'None', 'psbdx-smart-report-management' ) );

		foreach ( $forms as $form ) {
			$options[ $form->ID ] = $form->post_title;
		}

		echo '<div class="options_group">';

		woocommerce_wp_select( array(
			'id'      => '_psbdx_product_report_btn',
			'label'   => __( 'PSBDx Report Button', 'psbdx-smart-report-management' ),
			'options' => $options,
			'desc_tip' => true,
			'description' => __( 'Display a report button on this product page. Choose "None" to use the global default (if set).', 'psbdx-smart-report-management' ),
		) );

		echo '</div>';
	}

	/**
	 * Save the report form selection when a WooCommerce product is saved.
	 *
	 * Note: WooCommerce verifies its own nonce before firing
	 * `woocommerce_process_product_meta`, so no additional nonce check is
	 * required here.
	 *
	 * @since  1.0.0
	 * @param  int $post_id  Post ID of the product being saved.
	 * @return void
	 */
	public function save_product_meta( $post_id ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verifies nonce before this hook fires.
		$value = isset( $_POST['_psbdx_product_report_btn'] )
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- see above.
			? sanitize_text_field( wp_unslash( $_POST['_psbdx_product_report_btn'] ) )
			: '';

		update_post_meta( $post_id, '_psbdx_product_report_btn', $value );
	}

	/**
	 * Automatically render the report button on product and course pages.
	 *
	 * Falls back to the global product form if no per-item form is assigned.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	public function auto_render_on_product() {
		global $post;

		if ( ! $post ) {
			return;
		}

		$form_id = get_post_meta( $post->ID, '_psbdx_product_report_btn', true );

		if ( empty( $form_id ) ) {
			$form_id = get_option( 'psbdx_global_product_form_id' );
		}

		if ( $form_id ) {
			echo do_shortcode( '[psbdx_report id="' . absint( $form_id ) . '"]' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
	}

	/**
	 * Render the global order report button on the WooCommerce order detail page.
	 *
	 * @since  1.0.0
	 * @param  WC_Order $order  The current WooCommerce order object.
	 * @return void
	 */
	public function render_on_order_page( $order ) {
		$form_id = get_option( 'psbdx_global_order_form_id' );

		if ( $form_id ) {
			echo do_shortcode( '[psbdx_report id="' . absint( $form_id ) . '"]' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
	}
}
