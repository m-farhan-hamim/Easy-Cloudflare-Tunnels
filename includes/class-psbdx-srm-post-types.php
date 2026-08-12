<?php
/**
 * Registers custom post types for PSBDx Smart Report Management.
 *
 * @package PSBDx_Smart_Report_Management
 * @since   1.0.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class PSBDX_SRM_Post_Types
 *
 * Registers the psbdx_report_form and psbdx_report_log post types.
 *
 * @since 1.0.0
 */
class PSBDX_SRM_Post_Types {

	/**
	 * Top-level admin menu slug used to group Report Forms, Report Logs,
	 * Settings, and maintenance tools.
	 *
	 * @since 1.2.0
	 * @var string
	 */
	const ADMIN_MENU_SLUG = 'psbdx-srm';

	/**
	 * Constructor — hooks into WordPress init.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register_parent_menu' ), 9 );
		add_action( 'admin_menu', array( $this, 'remove_parent_placeholder_submenu' ), 99 );
		add_action( 'init', array( $this, 'register' ) );
	}

	/**
	 * Removes the auto-added submenu that duplicates the parent slug so only
	 * Report Forms, Report Logs, and tool pages appear under this menu.
	 *
	 * @since 1.2.0
	 * @return void
	 */
	public function remove_parent_placeholder_submenu() {
		remove_submenu_page( self::ADMIN_MENU_SLUG, self::ADMIN_MENU_SLUG );
	}

	/**
	 * Registers the parent admin menu before custom post type submenus.
	 *
	 * @since 1.2.0
	 * @return void
	 */
	public function register_parent_menu() {
		add_menu_page(
			__( 'PSBDx Reports', 'psbdx-smart-report-management' ),
			__( 'PSBDx Reports', 'psbdx-smart-report-management' ),
			'edit_posts',
			self::ADMIN_MENU_SLUG,
			array( $this, 'redirect_parent_to_forms' ),
			'dashicons-flag',
			50
		);
	}

	/**
	 * Parent menu callback — sends users to the Report Forms list.
	 *
	 * @since 1.2.0
	 * @return void
	 */
	public function redirect_parent_to_forms() {
		wp_safe_redirect( admin_url( 'edit.php?post_type=psbdx_report_form' ) );
		exit;
	}

	/**
	 * Register both custom post types.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function register() {
		$this->register_report_form();
		$this->register_report_log();
	}

	/**
	 * Register the Report Form post type.
	 *
	 * Stores configurable report button settings created by the admin.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	private function register_report_form() {
		$labels = array(
			'name'               => _x( 'Report Forms', 'post type general name', 'psbdx-smart-report-management' ),
			'singular_name'      => _x( 'Report Form',  'post type singular name', 'psbdx-smart-report-management' ),
			'add_new'            => __( 'Add New Form',        'psbdx-smart-report-management' ),
			'add_new_item'       => __( 'Add New Report Form', 'psbdx-smart-report-management' ),
			'edit_item'          => __( 'Edit Report Form',    'psbdx-smart-report-management' ),
			'new_item'           => __( 'New Report Form',     'psbdx-smart-report-management' ),
			'view_item'          => __( 'View Report Form',    'psbdx-smart-report-management' ),
			'search_items'       => __( 'Search Report Forms', 'psbdx-smart-report-management' ),
			'not_found'          => __( 'No report forms found.',           'psbdx-smart-report-management' ),
			'not_found_in_trash' => __( 'No report forms found in Trash.',  'psbdx-smart-report-management' ),
			'menu_name'          => __( 'Report Forms', 'psbdx-smart-report-management' ),
		);

		$args = array(
			'labels'        => $labels,
			'public'        => false,
			'show_ui'       => true,
			'show_in_menu'  => self::ADMIN_MENU_SLUG,
			'menu_icon'     => 'dashicons-flag',
			'menu_position' => 10,
			'supports'      => array( 'title' ),
			'rewrite'       => false,
			'query_var'     => false,
		);

		register_post_type( 'psbdx_report_form', $args );
	}

	/**
	 * Register the Report Log post type.
	 *
	 * Stores individual submitted reports. Creation is restricted to the
	 * plugin AJAX handler — admins can view and update, but not manually create.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	private function register_report_log() {
		$labels = array(
			'name'               => _x( 'Responses', 'post type general name', 'psbdx-smart-report-management' ),
			'singular_name'      => _x( 'Response',  'post type singular name', 'psbdx-smart-report-management' ),
			'edit_item'          => __( 'View Response',     'psbdx-smart-report-management' ),
			'search_items'       => __( 'Search Responses',  'psbdx-smart-report-management' ),
			'not_found'          => __( 'No responses found.',          'psbdx-smart-report-management' ),
			'not_found_in_trash' => __( 'No responses found in Trash.', 'psbdx-smart-report-management' ),
			'menu_name'          => __( 'Responses', 'psbdx-smart-report-management' ),
		);

		$args = array(
			'labels'        => $labels,
			'public'        => false,
			'show_ui'       => true,
			'show_in_menu'  => self::ADMIN_MENU_SLUG,
			'menu_icon'     => 'dashicons-clipboard',
			'menu_position' => 11,
			// 'editor' is intentionally omitted: the submitted message is
			// already shown read-only, nicely formatted, in the custom
			// "Report Details" meta box — a default WYSIWYG box under the
			// title would just duplicate it and add visual clutter.
			'supports'      => array( 'title', 'author' ),
			'capabilities'  => array( 'create_posts' => 'do_not_allow' ),
			'map_meta_cap'  => true,
			'rewrite'       => false,
			'query_var'     => false,
		);

		register_post_type( 'psbdx_report_log', $args );
	}
}
