<?php
/**
 * Plugin Name:       PSBDx Smart Report Management
 * Plugin URI:        https://dev.psbdx.xyz/documentations/psbdx-smart-report-managment/
 * Description:       AJAX-powered smart report management system for e-commerce orders, products, and online courses. HPOS compatible. Includes rate limiting, order auto-linking, and an admin dashboard widget.
 * Version:           1.4.6
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            PSBDx
 * Author URI:        https://dev.psbdx.xyz/author/mf-hamim/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       psbdx-smart-report-management
 * Domain Path:       /languages
 *
 * @package PSBDx_Smart_Report_Management
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Plugin constants.
define( 'PSBDX_SRM_VERSION',     '1.4.6' );
define( 'PSBDX_SRM_FILE',        __FILE__ );
define( 'PSBDX_SRM_DIR',         plugin_dir_path( __FILE__ ) );
define( 'PSBDX_SRM_URL',         plugin_dir_url( __FILE__ ) );
define( 'PSBDX_SRM_BASENAME',    plugin_basename( __FILE__ ) );

/**
 * Cache-busting version string for one specific asset file, based on its
 * own last-modified time rather than the plugin's displayed version number.
 *
 * The plugin version (PSBDX_SRM_VERSION) is set deliberately by the site
 * owner and doesn't change on every small fix, but every enqueued CSS/JS
 * file was using it as the `?ver=` cache-buster — so a JS fix could ship
 * and browsers/host-level caches (aggressive on some free hosts) would go
 * on serving the previous cached copy indefinitely, since the URL never
 * changed. Using filemtime() instead means any edit to the file itself
 * changes its `?ver=`, independent of the plugin version number.
 *
 * @since  1.4.5
 * @param  string $relative_path  Path relative to the plugin root, e.g. 'assets/js/public.js'.
 * @return string                 Unix timestamp of the file's last edit, or PSBDX_SRM_VERSION as a fallback if the file can't be found.
 */
function psbdx_srm_asset_ver( $relative_path ) {
	$absolute = PSBDX_SRM_DIR . ltrim( $relative_path, '/' );
	$mtime    = file_exists( $absolute ) ? filemtime( $absolute ) : false;
	return $mtime ? (string) $mtime : PSBDX_SRM_VERSION;
}

// ─── Includes ────────────────────────────────────────────────────────────────

require_once PSBDX_SRM_DIR . 'includes/class-psbdx-srm-post-types.php';
require_once PSBDX_SRM_DIR . 'includes/class-psbdx-srm-helpers.php';
require_once PSBDX_SRM_DIR . 'includes/class-psbdx-srm-captcha.php';
require_once PSBDX_SRM_DIR . 'includes/class-psbdx-srm-conflict-guard.php';
require_once PSBDX_SRM_DIR . 'includes/class-psbdx-srm-ai.php';
require_once PSBDX_SRM_DIR . 'includes/class-psbdx-srm-replies.php';
require_once PSBDX_SRM_DIR . 'includes/class-psbdx-srm-emails.php';
require_once PSBDX_SRM_DIR . 'includes/class-psbdx-srm-hosting-guard.php';
require_once PSBDX_SRM_DIR . 'includes/class-psbdx-srm-api.php';
require_once PSBDX_SRM_DIR . 'includes/class-psbdx-srm-popup-link.php';
require_once PSBDX_SRM_DIR . 'includes/class-psbdx-srm-agents.php';
require_once PSBDX_SRM_DIR . 'admin/class-psbdx-srm-agents-admin.php';
require_once PSBDX_SRM_DIR . 'admin/class-psbdx-srm-ai-log.php';
require_once PSBDX_SRM_DIR . 'admin/class-psbdx-srm-csv.php';
require_once PSBDX_SRM_DIR . 'admin/class-psbdx-srm-setup-wizard.php';
require_once PSBDX_SRM_DIR . 'admin/class-psbdx-srm-admin.php';
require_once PSBDX_SRM_DIR . 'admin/class-psbdx-srm-admin-tools.php';
require_once PSBDX_SRM_DIR . 'admin/class-psbdx-srm-meta-boxes.php';
require_once PSBDX_SRM_DIR . 'admin/class-psbdx-srm-attachment-manager.php';
require_once PSBDX_SRM_DIR . 'admin/class-psbdx-srm-form-builder.php';
require_once PSBDX_SRM_DIR . 'admin/class-psbdx-srm-dashboard-widget.php';
require_once PSBDX_SRM_DIR . 'admin/class-psbdx-srm-woo-integration.php';
require_once PSBDX_SRM_DIR . 'admin/class-psbdx-srm-review-notice.php';
require_once PSBDX_SRM_DIR . 'admin/class-psbdx-srm-support.php';
require_once PSBDX_SRM_DIR . 'admin/class-psbdx-srm-faq.php';
require_once PSBDX_SRM_DIR . 'public/class-psbdx-srm-form-renderer.php';
require_once PSBDX_SRM_DIR . 'public/class-psbdx-srm-shortcodes.php';
require_once PSBDX_SRM_DIR . 'public/class-psbdx-srm-report-page.php';
require_once PSBDX_SRM_DIR . 'public/class-psbdx-srm-ajax.php';
require_once PSBDX_SRM_DIR . 'public/class-psbdx-srm-assets.php';

// ─── Activation ──────────────────────────────────────────────────────────────

/**
 * Runs on single-site activation, or per-site when $network_wide is false.
 *
 * Also called iteratively for every site during a network-wide activation so
 * each blog gets its own activation timestamp for the review notice.
 *
 * @since 1.0.0
 * @return void
 */
function psbdx_srm_activate() {
	PSBDX_SRM_Review_Notice::on_activation();
	PSBDX_SRM_AI_Log::install_table();
	PSBDX_SRM_Replies::install_table();
	PSBDX_SRM_API::install_tables();
	PSBDX_SRM_Agents::install_table();
	PSBDX_SRM_Agents::seed_super_admins_if_needed();
	PSBDX_SRM_Setup_Wizard::on_activation();
}

/**
 * Activation hook callback — handles single-site and multisite gracefully.
 *
 * Without `Network: true` in the plugin header WordPress will call this once
 * for the current site even on a multisite install.  We additionally iterate
 * all sites when the super-admin chooses "Network Activate" from the
 * Network Admin › Plugins screen, which sets $network_wide = true.
 *
 * @since 1.0.0
 * @param  bool $network_wide  True when activated network-wide by a super-admin.
 * @return void
 */
function psbdx_srm_activate_multisite( $network_wide ) {
	if ( $network_wide && is_multisite() ) {
		// Network-wide activation: stamp every existing site.
		$site_ids = get_sites( array( 'fields' => 'ids', 'number' => 0 ) );

		foreach ( $site_ids as $site_id ) {
			switch_to_blog( (int) $site_id );
			psbdx_srm_activate();
			restore_current_blog();
		}
	} else {
		// Per-site activation (single-site install, or a specific sub-site
		// on a multisite network choosing to activate this plugin individually).
		psbdx_srm_activate();
	}
}
register_activation_hook( PSBDX_SRM_FILE, 'psbdx_srm_activate_multisite' );

/**
 * When a new site is added to a network where this plugin is already
 * network-active, stamp its activation time immediately.
 *
 * This hook only fires on multisite.  On a per-site activation there is no
 * network-wide active_sitewide_plugins entry, so the check below is safe.
 *
 * @since 1.1.0
 * @param  WP_Site $new_site  The newly created site object.
 * @return void
 */
function psbdx_srm_new_blog( $new_site ) {
	if ( ! is_multisite() ) {
		return;
	}

	$active_sitewide = (array) get_site_option( 'active_sitewide_plugins', array() );

	if ( ! isset( $active_sitewide[ PSBDX_SRM_BASENAME ] ) ) {
		return;
	}

	switch_to_blog( (int) $new_site->blog_id );
	psbdx_srm_activate();
	restore_current_blog();
}
add_action( 'wp_insert_site', 'psbdx_srm_new_blog' );

/**
 * Runs on deactivation — clears the scheduled AI log cleanup event so it
 * doesn't keep firing (harmlessly, but pointlessly) while the plugin is off.
 * The log table and its data are left in place, matching how deactivation
 * behaves for every other setting this plugin stores.
 *
 * @since 1.4.1
 * @return void
 */
function psbdx_srm_deactivate() {
	wp_clear_scheduled_hook( PSBDX_SRM_AI_Log::CRON_HOOK );
}
register_deactivation_hook( PSBDX_SRM_FILE, 'psbdx_srm_deactivate' );

// ─── WooCommerce HPOS compatibility ──────────────────────────────────────────

/**
 * Declare WooCommerce HPOS compatibility.
 *
 * @since 1.0.0
 */
function psbdx_srm_declare_hpos_compat() {
	if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
			'custom_order_tables',
			PSBDX_SRM_FILE,
			true
		);
	}
}
add_action( 'before_woocommerce_init', 'psbdx_srm_declare_hpos_compat' );

// ─── Initialisation ───────────────────────────────────────────────────────────

/**
 * Initialise all plugin components.
 *
 * plugins_loaded fires once per site request on both single-site and multisite
 * (WordPress handles the per-site context automatically). We skip the Network
 * Admin screen here because this plugin has no network-admin UI — all
 * configuration is per-site.
 *
 * @since 1.0.0
 */
function psbdx_srm_init() {
	// No UI or post-type registration needed in the network admin dashboard.
	// Admin classes and menu hooks would try to register menus that don't
	// belong there and could confuse super-admins.
	if ( is_multisite() && is_network_admin() ) {
		return;
	}

	// Text domain is loaded automatically by WordPress since WP 4.6 when the plugin
	// is hosted on WordPress.org. Manual load_plugin_textdomain() is no longer needed.

	// Lazily stamp activation for sites that existed before v1.1.0.
	PSBDX_SRM_Review_Notice::maybe_set_activated_time();

	new PSBDX_SRM_Post_Types();
	new PSBDX_SRM_Conflict_Guard();
	new PSBDX_SRM_AI();
	new PSBDX_SRM_AI_Log();
	new PSBDX_SRM_CSV();
	new PSBDX_SRM_Setup_Wizard();
	new PSBDX_SRM_Replies();
	new PSBDX_SRM_Emails();
	new PSBDX_SRM_Hosting_Guard();
	new PSBDX_SRM_API();
	new PSBDX_SRM_Popup_Link();
	new PSBDX_SRM_Agents();
	new PSBDX_SRM_Agents_Admin();
	new PSBDX_SRM_Admin();
	new PSBDX_SRM_Admin_Tools();
	new PSBDX_SRM_Meta_Boxes();
	new PSBDX_SRM_Attachment_Manager();
	new PSBDX_SRM_Form_Builder();
	new PSBDX_SRM_Dashboard_Widget();
	new PSBDX_SRM_Woo_Integration();
	new PSBDX_SRM_Review_Notice();
	new PSBDX_SRM_Support();
	new PSBDX_SRM_FAQ();
	new PSBDX_SRM_Shortcodes();
	new PSBDX_SRM_Report_Page();
	new PSBDX_SRM_Ajax();
	new PSBDX_SRM_Assets();
}
add_action( 'plugins_loaded', 'psbdx_srm_init', 20 );
