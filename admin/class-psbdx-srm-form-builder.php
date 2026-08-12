<?php
/**
 * Form Builder for PSBDx Smart Report Management.
 *
 * Provides a drag-and-drop v2 form builder with tabbed UI, field library,
 * granular field settings, legacy migration logic, and post-update notices.
 *
 * @package PSBDx_Smart_Report_Management
 * @since   1.3.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class PSBDX_SRM_Form_Builder
 *
 * Replaces the flat meta-box form configuration UI with a tabbed v2 builder.
 * Tab 1: Fields / Builder (drag-and-drop canvas).
 * Tab 2: Settings     (all configuration, notifications, integrations).
 *
 * Legacy forms (those lacking `_psrm_form_version = 2`) trigger a persistent
 * admin-wide security notice until every form has been migrated and saved.
 *
 * @since 1.3.0
 */
class PSBDX_SRM_Form_Builder {

	/**
	 * Meta key that marks a form as v2 (new builder).
	 *
	 * @since 1.3.0
	 * @var string
	 */
	const VERSION_META_KEY = '_psrm_form_version';

	/**
	 * Meta key storing the serialised v2 field schema (JSON).
	 *
	 * @since 1.3.0
	 * @var string
	 */
	const FIELDS_META_KEY = '_psrm_builder_fields';

	/**
	 * Current builder schema version.
	 *
	 * @since 1.3.0
	 * @var int
	 */
	const SCHEMA_VERSION = 2;

	/**
	 * Default allowed file extensions for a new Attachment field, and the
	 * absolute ceiling any form's max_size_kb can be set to — keeps a
	 * misconfigured or malicious "max size" value from letting a single
	 * upload exhaust disk space.
	 *
	 * @since 1.4.5
	 * @var array|int
	 */
	const ATTACHMENT_DEFAULT_TYPES = array( 'jpg', 'jpeg', 'png', 'pdf' );
	const ATTACHMENT_SIZE_CEILING_KB = 51200; // 50 MB.

	/**
	 * Star-rating bounds for a Review field.
	 *
	 * @since 1.4.5
	 * @var int
	 */
	const REVIEW_MIN_STARS = 2;
	const REVIEW_MAX_STARS = 10;

	/**
	 * Nonce action for builder saves.
	 *
	 * @since 1.3.0
	 * @var string
	 */
	const NONCE_ACTION = 'psbdx_srm_builder_save';

	/**
	 * Nonce field name.
	 *
	 * @since 1.3.0
	 * @var string
	 */
	const NONCE_FIELD = 'psbdx_srm_builder_nonce';

	/**
	 * Constructor.
	 *
	 * @since 1.3.0
	 */
	public function __construct() {
		add_action( 'add_meta_boxes',    array( $this, 'register_meta_boxes' ) );
		add_action( 'save_post',         array( $this, 'save' ) );
		add_action( 'admin_init',        array( $this, 'check_legacy_forms' ) );
		add_action( 'admin_notices',     array( $this, 'legacy_notice' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_builder_assets' ) );
		add_action( 'wp_ajax_psbdx_srm_migrate_form', array( $this, 'ajax_migrate_form' ) );
	}

	// =========================================================================
	// META BOX REGISTRATION
	// =========================================================================

	/**
	 * Register the v2 builder meta box on psbdx_report_form.
	 *
	 * The old meta box (psbdx_srm_form_config) registered by PSBDX_SRM_Meta_Boxes
	 * is removed here so only one UI is shown.
	 *
	 * @since  1.3.0
	 * @return void
	 */
	public function register_meta_boxes() {
		// Remove the v1 config meta box so it doesn't render alongside the builder.
		remove_meta_box( 'psbdx_srm_form_config', 'psbdx_report_form', 'normal' );

		add_meta_box(
			'psbdx_srm_form_builder',
			__( 'Form Builder', 'psbdx-smart-report-management' ),
			array( $this, 'render_builder' ),
			'psbdx_report_form',
			'normal',
			'high'
		);
	}

	// =========================================================================
	// ENQUEUE ASSETS
	// =========================================================================

	/**
	 * Enqueue builder JS/CSS only on the report form edit screen.
	 *
	 * @since  1.3.0
	 * @return void
	 */
	public function enqueue_builder_assets() {
		$screen = get_current_screen();

		if ( ! $screen || 'psbdx_report_form' !== $screen->post_type ) {
			return;
		}

		wp_enqueue_script(
			'psbdx-srm-builder',
			PSBDX_SRM_URL . 'assets/js/form-builder.js',
			array( 'jquery', 'jquery-ui-sortable', 'jquery-ui-draggable', 'jquery-ui-droppable' ),
			psbdx_srm_asset_ver( 'assets/js/form-builder.js' ),
			true
		);

		wp_localize_script(
			'psbdx-srm-builder',
			'psrmBuilder',
			array(
				'ajaxUrl'          => admin_url( 'admin-ajax.php' ),
				'migrateNonce'     => wp_create_nonce( 'psbdx_srm_migrate_form' ),
				'captchaActive'    => '' !== PSBDX_SRM_Captcha::active_provider(),
				'captchaSettingsUrl' => esc_url( admin_url( 'admin.php?page=psbdx-srm-settings&tab=captcha' ) ),
				'wooActive'        => class_exists( 'WooCommerce' ),
				'lpActive'         => class_exists( 'LearnPress' ),
				'i18n'             => array(
					'confirmMigrate'   => __( 'This will convert your legacy form to the new v2 builder. Continue?', 'psbdx-smart-report-management' ),
					'migrating'        => __( 'Migrating…', 'psbdx-smart-report-management' ),
					'migrationFailed'  => __( 'Migration failed. Please reload and try again.', 'psbdx-smart-report-management' ),
					'fieldSettings'    => __( 'Field Settings', 'psbdx-smart-report-management' ),
					'selectFieldHint'  => __( 'Select a field on the canvas to edit its settings.', 'psbdx-smart-report-management' ),
					'noFields'         => __( 'Drag fields here to build your form.', 'psbdx-smart-report-management' ),
					'deleteField'      => __( 'Remove field', 'psbdx-smart-report-management' ),
					'captchaDisabled'  => __( 'Please configure Captcha settings in PSRM global settings first.', 'psbdx-smart-report-management' ),
					'moveUp'           => __( 'Move field up', 'psbdx-smart-report-management' ),
					'moveDown'         => __( 'Move field down', 'psbdx-smart-report-management' ),
					/* translators: %d: number of fields currently on the form */
					'fieldCount'       => __( '%d field(s)', 'psbdx-smart-report-management' ),
				),
			)
		);

		wp_enqueue_style(
			'psbdx-srm-builder',
			PSBDX_SRM_URL . 'assets/css/form-builder.css',
			array(),
			psbdx_srm_asset_ver( 'assets/css/form-builder.css' )
		);
	}

	// =========================================================================
	// RENDER BUILDER
	// =========================================================================

	/**
	 * Render the tabbed Form Builder meta box.
	 *
	 * Shows:
	 * - Migration prompt for legacy (v1) forms.
	 * - Tab 1: drag-and-drop builder canvas + field library, with a touch-friendly
	 *   mobile mode (bottom-sheet library/settings, tap-to-add, Up/Down reorder).
	 * - Tab 2: settings (button, identity, cooldown, automatic display, captcha).
	 *
	 * @since  1.3.0
	 * @param  WP_Post $post  Current post object.
	 * @return void
	 */
	public function render_builder( $post ) {
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD );

		$form_version  = (int) get_post_meta( $post->ID, self::VERSION_META_KEY, true );
		$is_legacy     = ( $form_version < self::SCHEMA_VERSION );
		$fields_json   = get_post_meta( $post->ID, self::FIELDS_META_KEY, true ) ?: '[]';

		// Settings meta (same keys as legacy builder, backward-compat).
		$btn_text      = get_post_meta( $post->ID, '_psbdx_btn_text',        true ) ?: __( 'Report Issue', 'psbdx-smart-report-management' );
		$show_identity = get_post_meta( $post->ID, '_psbdx_show_identity',   true );
		$show_identity = ( '' === $show_identity ) ? 'yes' : $show_identity;
		$cooldown_raw  = get_post_meta( $post->ID, '_psbdx_cooldown_mins',   true );
		$cooldown_mins = PSBDX_SRM_Helpers::get_effective_cooldown_mins( $post->ID );
		$is_global_cd  = ( '' === $cooldown_raw || null === $cooldown_raw );
		$is_order_form    = ( get_option( 'psbdx_global_order_form_id' )   == $post->ID );
		$is_product_form  = ( get_option( 'psbdx_global_product_form_id' ) == $post->ID );
		$captcha_enabled  = get_post_meta( $post->ID, '_psbdx_captcha_enabled', true );
		$captcha_enabled  = ( '' === $captcha_enabled ) ? 'no' : $captcha_enabled;
		$active_provider  = PSBDX_SRM_Captcha::active_provider();
		?>

		<?php /* ── Builder wrapper (fully usable on mobile — see form-builder.css) ─ */ ?>
		<div class="psrm-builder-wrap" id="psrm-builder-wrap">

			<?php if ( $is_legacy && $post->ID > 0 ) : ?>
			<?php /* ── Legacy migration prompt ──────────────────────────────── */ ?>
			<div class="psrm-migration-gate" id="psrm-migration-gate" role="alert">
				<div class="psrm-migration-icon" aria-hidden="true">
					<svg xmlns="http://www.w3.org/2000/svg" width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
				</div>
				<h3><?php esc_html_e( 'Legacy Form Detected', 'psbdx-smart-report-management' ); ?></h3>
				<p><?php esc_html_e( 'This form was built with the old v1 builder. To edit it you must upgrade to the new secure v2 builder. Your existing configuration will be automatically migrated.', 'psbdx-smart-report-management' ); ?></p>
				<button type="button" class="button button-primary psrm-migrate-btn"
					data-form-id="<?php echo esc_attr( $post->ID ); ?>">
					<?php esc_html_e( 'Update form to the new builder', 'psbdx-smart-report-management' ); ?>
				</button>
				<span class="psrm-migrate-spinner spinner" style="float:none;vertical-align:middle;visibility:hidden;"></span>
			</div>
			<?php endif; ?>

			<?php /* ── Tabs nav ──────────────────────────────────────────────── */ ?>
			<div class="psrm-tabs-nav" role="tablist" aria-label="<?php esc_attr_e( 'Form Builder Tabs', 'psbdx-smart-report-management' ); ?>">
				<button class="psrm-tab-btn psrm-tab-active"
					id="psrm-tab-builder-btn"
					role="tab"
					aria-selected="true"
					aria-controls="psrm-tab-builder"
					type="button">
					<span class="dashicons dashicons-editor-kitchensink" aria-hidden="true"></span>
					<?php esc_html_e( 'Fields / Builder', 'psbdx-smart-report-management' ); ?>
				</button>
				<button class="psrm-tab-btn"
					id="psrm-tab-settings-btn"
					role="tab"
					aria-selected="false"
					aria-controls="psrm-tab-settings"
					type="button">
					<span class="dashicons dashicons-admin-settings" aria-hidden="true"></span>
					<?php esc_html_e( 'Settings', 'psbdx-smart-report-management' ); ?>
				</button>
			</div>

			<?php /* ── Tab 1: Builder canvas ───────────────────────────────── */ ?>
			<div class="psrm-tab-panel" id="psrm-tab-builder" role="tabpanel" aria-labelledby="psrm-tab-builder-btn">
				<?php $this->render_builder_tab( $post, $fields_json, $is_legacy ); ?>
			</div>

			<?php /* ── Tab 2: Settings ─────────────────────────────────────── */ ?>
			<div class="psrm-tab-panel psrm-tab-hidden" id="psrm-tab-settings" role="tabpanel" aria-labelledby="psrm-tab-settings-btn">
				<?php
				$this->render_settings_tab(
					$post,
					$btn_text,
					$show_identity,
					$cooldown_mins,
					$is_global_cd,
					$is_order_form,
					$is_product_form,
					$captcha_enabled,
					$active_provider
				);
				?>
			</div>

		</div><!-- .psrm-builder-wrap -->

		<?php /* ── Hidden field: serialised builder fields (updated by JS) ──── */ ?>
		<input type="hidden" id="psrm_builder_fields_json" name="psrm_builder_fields_json"
			value="<?php echo esc_attr( $fields_json ); ?>">
		<input type="hidden" id="psrm_form_version" name="psrm_form_version"
			value="<?php echo esc_attr( $is_legacy ? '1' : '2' ); ?>">

		<?php
	}

	// =========================================================================
	// BUILDER TAB
	// =========================================================================

	/**
	 * Render the drag-and-drop builder canvas and field library.
	 *
	 * @since  1.3.0
	 * @param  WP_Post $post        Post object.
	 * @param  string  $fields_json Serialised v2 field schema.
	 * @param  bool    $is_legacy   Whether this is a legacy form.
	 * @return void
	 */
	private function render_builder_tab( $post, $fields_json, $is_legacy ) {
		$captcha_active = '' !== PSBDX_SRM_Captcha::active_provider();
		?>
		<?php /* ── Mobile-only toolbar: single "+ Add Field" trigger, no drag needed ─ */ ?>
		<div class="psrm-mobile-toolbar" id="psrm-mobile-toolbar">
			<button type="button" class="button button-primary psrm-mobile-add-field-btn" id="psrm-mobile-add-field-btn">
				<span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span>
				<?php esc_html_e( 'Add Field', 'psbdx-smart-report-management' ); ?>
			</button>
			<span class="psrm-mobile-field-count" id="psrm-mobile-field-count" aria-live="polite"></span>
		</div>

		<?php /* Backdrop shown behind an open bottom sheet on mobile */ ?>
		<div class="psrm-sheet-backdrop" id="psrm-sheet-backdrop" hidden></div>

		<div class="psrm-builder-layout <?php echo $is_legacy ? 'psrm-builder-locked' : ''; ?>"
			id="psrm-builder-layout"
			aria-busy="false">

			<?php /* ── Left panel: field library (becomes a bottom sheet on mobile) ── */ ?>
			<aside class="psrm-field-library" id="psrm-field-library" aria-label="<?php esc_attr_e( 'Field Library', 'psbdx-smart-report-management' ); ?>">
				<div class="psrm-field-library-header">
					<span class="dashicons dashicons-database-add" aria-hidden="true"></span>
					<strong><?php esc_html_e( 'Add Fields', 'psbdx-smart-report-management' ); ?></strong>
					<button type="button" class="psrm-sheet-close" id="psrm-library-close" aria-label="<?php esc_attr_e( 'Close', 'psbdx-smart-report-management' ); ?>">
						<span class="dashicons dashicons-no-alt" aria-hidden="true"></span>
					</button>
				</div>

				<div class="psrm-field-library-list" id="psrm-field-library-list">
					<?php
					$library_fields = $this->get_field_library();
					foreach ( $library_fields as $type => $field ) :
						$is_captcha  = 'captcha' === $type;
						$disabled    = $is_captcha && ! $captcha_active;
						?>
					<div class="psrm-library-field <?php echo $disabled ? 'psrm-library-field-disabled' : ''; ?>"
						data-type="<?php echo esc_attr( $type ); ?>"
						draggable="<?php echo $disabled ? 'false' : 'true'; ?>"
						role="button"
						tabindex="<?php echo $disabled ? '-1' : '0'; ?>"
						aria-label="<?php /* translators: %s: field type label, e.g. "Email" */ echo esc_attr( sprintf( __( 'Add %s field', 'psbdx-smart-report-management' ), $field['label'] ) ); ?>">
						<span class="psrm-library-field-icon dashicons <?php echo esc_attr( $field['icon'] ); ?>" aria-hidden="true"></span>
						<span class="psrm-library-field-label"><?php echo esc_html( $field['label'] ); ?></span>
						<?php if ( $disabled ) : ?>
						<span class="psrm-captcha-badge" title="<?php esc_attr_e( 'Please configure Captcha settings in PSRM global settings first.', 'psbdx-smart-report-management' ); ?>">
							<span class="dashicons dashicons-warning" aria-hidden="true"></span>
							<?php esc_html_e( 'Unconfigured', 'psbdx-smart-report-management' ); ?>
						</span>
						<?php endif; ?>
					</div>
					<?php endforeach; ?>
				</div>
			</aside>

			<?php /* ── Centre: canvas ──────────────────────────────────────── */ ?>
			<div class="psrm-canvas-wrap">
				<div class="psrm-canvas-header">
					<span class="dashicons dashicons-forms" aria-hidden="true"></span>
					<strong><?php esc_html_e( 'Form Canvas', 'psbdx-smart-report-management' ); ?></strong>
					<span class="psrm-canvas-hint"><?php esc_html_e( '— drag fields from the library, or tap "Add Field"', 'psbdx-smart-report-management' ); ?></span>
				</div>

				<div class="psrm-canvas" id="psrm-canvas"
					aria-label="<?php esc_attr_e( 'Form Canvas Drop Zone', 'psbdx-smart-report-management' ); ?>">
					<div class="psrm-canvas-empty" id="psrm-canvas-empty">
						<span class="dashicons dashicons-migrate" aria-hidden="true"></span>
						<p><?php esc_html_e( 'Drag fields here, or tap "Add Field" to build your form.', 'psbdx-smart-report-management' ); ?></p>
					</div>
					<?php /* Fields rendered by JS from fields_json */ ?>
				</div>
			</div>

			<?php /* ── Field settings: a popup/drawer on every viewport, opened by
			         selecting a field. Desktop slides in from the right; mobile
			         slides up as a bottom sheet. Both close via the "X" button
			         below, the backdrop, or Esc. This used to be a permanently
			         visible third grid column on desktop, which left too little
			         room for the canvas at anything narrower than a wide monitor. ── */ ?>
			<aside class="psrm-field-settings-panel" id="psrm-field-settings-panel"
				aria-label="<?php esc_attr_e( 'Field Settings Panel', 'psbdx-smart-report-management' ); ?>"
				aria-hidden="true">
				<div class="psrm-field-settings-header">
					<span class="dashicons dashicons-admin-generic" aria-hidden="true"></span>
					<strong><?php esc_html_e( 'Field Settings', 'psbdx-smart-report-management' ); ?></strong>
					<button type="button" class="psrm-settings-close-btn" id="psrm-settings-close" aria-label="<?php esc_attr_e( 'Close', 'psbdx-smart-report-management' ); ?>">
						<span class="dashicons dashicons-no-alt" aria-hidden="true"></span>
					</button>
				</div>
				<div class="psrm-field-settings-body" id="psrm-field-settings-body">
					<p class="psrm-settings-hint"><?php esc_html_e( 'Select a field on the canvas to edit its settings.', 'psbdx-smart-report-management' ); ?></p>
				</div>
			</aside>

		</div><!-- .psrm-builder-layout -->

		<?php /* Builder initialisation data for JS */ ?>
		<script id="psrm-fields-data" type="application/json">
			<?php echo wp_json_encode( json_decode( $fields_json, true ) ?: array() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON-encoded, safe in <script type="application/json">. ?>
		</script>
		<?php
	}

	// =========================================================================
	// SETTINGS TAB
	// =========================================================================

	/**
	 * Render the Settings tab (moved from the old flat form-config meta box).
	 *
	 * @since  1.3.0
	 * @param  WP_Post $post             Post object.
	 * @param  string  $btn_text         Button label.
	 * @param  string  $show_identity    'yes'|'no'.
	 * @param  int     $cooldown_mins    Effective cooldown.
	 * @param  bool    $is_global_cd     True if using global cooldown.
	 * @param  bool    $is_order_form    True if this is the global order form.
	 * @param  bool    $is_product_form  True if this is the global product form.
	 * @param  string  $captcha_enabled  'yes'|'no'.
	 * @param  string  $active_provider  Active captcha provider slug or ''.
	 * @return void
	 */
	private function render_settings_tab(
		$post,
		$btn_text,
		$show_identity,
		$cooldown_mins,
		$is_global_cd,
		$is_order_form,
		$is_product_form,
		$captcha_enabled,
		$active_provider
	) {
		?>
		<div class="psrm-settings-sections">

			<?php /* Button label */ ?>
			<div class="psrm-settings-section">
				<div class="psrm-settings-section-header">
					<span class="dashicons dashicons-button" aria-hidden="true"></span>
					<strong><?php esc_html_e( 'Button Settings', 'psbdx-smart-report-management' ); ?></strong>
				</div>
				<div class="psrm-settings-section-body">
					<p>
						<label for="psbdx_btn_text_v2"><strong><?php esc_html_e( 'Button Label', 'psbdx-smart-report-management' ); ?></strong></label><br>
						<input type="text" name="psbdx_btn_text" id="psbdx_btn_text_v2"
							value="<?php echo esc_attr( $btn_text ); ?>"
							placeholder="<?php esc_attr_e( 'e.g. Report Issue', 'psbdx-smart-report-management' ); ?>"
							class="large-text">
					</p>
				</div>
			</div>

			<?php /* Identity display */ ?>
			<div class="psrm-settings-section">
				<div class="psrm-settings-section-header">
					<span class="dashicons dashicons-id" aria-hidden="true"></span>
					<strong><?php esc_html_e( 'User Identity Display', 'psbdx-smart-report-management' ); ?></strong>
				</div>
				<div class="psrm-settings-section-body">
					<p class="psrm-hint"><?php esc_html_e( 'Name and email are always collected server-side from the WordPress session. This controls whether the user sees a read-only identity card in the form.', 'psbdx-smart-report-management' ); ?></p>
					<p>
						<label>
							<input type="checkbox" name="psbdx_show_identity" value="yes" <?php checked( 'yes', $show_identity ); ?>>
							<?php esc_html_e( "Show reporter's name and email in the form (read-only)", 'psbdx-smart-report-management' ); ?>
						</label>
					</p>
				</div>
			</div>

			<?php /* Rate limiting */ ?>
			<div class="psrm-settings-section">
				<div class="psrm-settings-section-header">
					<span class="dashicons dashicons-clock" aria-hidden="true"></span>
					<strong><?php esc_html_e( 'Report Cooldown (Rate Limiting)', 'psbdx-smart-report-management' ); ?></strong>
				</div>
				<div class="psrm-settings-section-body">
					<p class="psrm-hint"><?php esc_html_e( 'Prevents the same logged-in user from re-submitting via this form until the cooldown expires. Set to 0 to disable.', 'psbdx-smart-report-management' ); ?></p>
					<p class="psrm-hint">
						<?php
						if ( $is_global_cd ) {
							printf(
								/* translators: %d: global minutes */
								esc_html__( 'This form is currently using the global rate limit (%d minutes). Saving a value here will override the global setting for this form.', 'psbdx-smart-report-management' ),
								(int) $cooldown_mins
							);
						} else {
							esc_html_e( 'This form has its own rate limit and overrides the global setting.', 'psbdx-smart-report-management' );
						}
						?>
					</p>
					<div class="psrm-inline-field">
						<input type="number" name="psbdx_cooldown_mins" id="psbdx_cooldown_mins_v2"
							value="<?php echo esc_attr( $cooldown_mins ); ?>"
							min="0" max="1440" class="small-text">
						<label for="psbdx_cooldown_mins_v2"><?php esc_html_e( 'minutes', 'psbdx-smart-report-management' ); ?></label>
					</div>
				</div>
			</div>

			<?php
			/*
			 * Automatic Display.
			 *
			 * NOTE (bugfix, 1.3.1): this used to be TWO separate sections —
			 * "Global Display Settings" (which actually works, via the
			 * psbdx_global_order_form_id / psbdx_global_product_form_id options
			 * read by PSBDX_SRM_Woo_Integration) and "Plugin Integrations"
			 * (which saved to _psrm_woo_integration / _psrm_lp_integration post
			 * meta that nothing in the plugin ever read). Checking those second
			 * checkboxes did nothing — confusing, and a real bug. Merged below
			 * into one honest, functional section.
			 */
			$woo_active     = class_exists( 'WooCommerce' );
			$lp_active      = defined( 'LEARNPRESS_VERSION' ) || post_type_exists( 'lp_course' );
			$product_active = $woo_active || $lp_active;
			?>
			<div class="psrm-settings-section">
				<div class="psrm-settings-section-header">
					<span class="dashicons dashicons-admin-settings" aria-hidden="true"></span>
					<strong><?php esc_html_e( 'Automatic Display', 'psbdx-smart-report-management' ); ?></strong>
				</div>
				<div class="psrm-settings-section-body">
					<p class="psrm-hint"><?php esc_html_e( 'Automatically show this form on specific page types, without needing to place a shortcode manually. Only one form can be the site-wide default for each page type.', 'psbdx-smart-report-management' ); ?></p>

					<div class="psrm-integration-row<?php echo $woo_active ? '' : ' psrm-integration-unavailable'; ?>">
						<label class="<?php echo $woo_active ? '' : 'psrm-label-muted'; ?>">
							<input type="checkbox" name="psbdx_is_order_form" value="1"
								<?php checked( $is_order_form, true ); ?>
								<?php disabled( ! $woo_active ); ?>>
							<?php esc_html_e( 'Show automatically on all e-commerce Order pages', 'psbdx-smart-report-management' ); ?>
						</label>
						<span class="psrm-integration-badge psrm-badge-woo"><?php esc_html_e( 'WooCommerce', 'psbdx-smart-report-management' ); ?></span>
						<?php if ( ! $woo_active ) : ?>
							<span class="psrm-integration-missing-badge">
								<span class="dashicons dashicons-warning" aria-hidden="true"></span>
								<?php esc_html_e( 'WooCommerce not active', 'psbdx-smart-report-management' ); ?>
							</span>
						<?php endif; ?>
					</div>

					<div class="psrm-integration-row<?php echo $product_active ? '' : ' psrm-integration-unavailable'; ?>" style="margin-top:10px;">
						<label class="<?php echo $product_active ? '' : 'psrm-label-muted'; ?>">
							<input type="checkbox" name="psbdx_is_product_form" value="1"
								<?php checked( $is_product_form, true ); ?>
								<?php disabled( ! $product_active ); ?>>
							<?php esc_html_e( 'Show automatically on all Product and Course pages', 'psbdx-smart-report-management' ); ?>
						</label>
						<?php if ( $woo_active ) : ?>
							<span class="psrm-integration-badge psrm-badge-woo"><?php esc_html_e( 'WooCommerce', 'psbdx-smart-report-management' ); ?></span>
						<?php endif; ?>
						<?php if ( $lp_active ) : ?>
							<span class="psrm-integration-badge psrm-badge-lp"><?php esc_html_e( 'LearnPress', 'psbdx-smart-report-management' ); ?></span>
						<?php endif; ?>
						<?php if ( ! $product_active ) : ?>
							<span class="psrm-integration-missing-badge">
								<span class="dashicons dashicons-warning" aria-hidden="true"></span>
								<?php esc_html_e( 'WooCommerce or LearnPress required', 'psbdx-smart-report-management' ); ?>
							</span>
						<?php endif; ?>
					</div>
				</div>
			</div>

			<?php /* Captcha */ ?>
			<div class="psrm-settings-section">
				<div class="psrm-settings-section-header">
					<span class="dashicons dashicons-shield" aria-hidden="true"></span>
					<strong><?php esc_html_e( 'Captcha Protection', 'psbdx-smart-report-management' ); ?></strong>
				</div>
				<div class="psrm-settings-section-body">
					<?php if ( '' === $active_provider ) : ?>
						<div class="psrm-notice-inline psrm-notice-warn">
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
						<p class="psrm-hint">
							<?php
							printf(
								/* translators: %1$s: provider label, %2$s: settings link */
								esc_html__( 'Active provider: %1$s. %2$s.', 'psbdx-smart-report-management' ),
								'<strong>' . esc_html( PSBDX_SRM_Captcha::label( $active_provider ) ) . '</strong>',
								'<a href="' . esc_url( admin_url( 'admin.php?page=psbdx-srm-settings&tab=captcha' ) ) . '">' . esc_html__( 'Change provider', 'psbdx-smart-report-management' ) . '</a>'
							);
							?>
						</p>
						<p>
							<label>
								<input type="checkbox" name="psbdx_captcha_enabled" value="yes" <?php checked( 'yes', $captcha_enabled ); ?>>
								<?php esc_html_e( 'Enable captcha on this form', 'psbdx-smart-report-management' ); ?>
							</label>
						</p>
					<?php endif; ?>
				</div>
			</div>

			<?php /* Replies */ ?>
			<?php
			$allow_replies    = get_post_meta( $post->ID, '_psbdx_allow_replies',   true );
			$allow_replies    = ( 'yes' === $allow_replies );
			$allow_ai_reply   = get_post_meta( $post->ID, '_psbdx_allow_ai_reply', true );
			$allow_ai_reply   = ( 'yes' === $allow_ai_reply );
			$ai_reply_master  = PSBDX_SRM_AI::is_reply_enabled();
			$ai_settings_url  = admin_url( 'admin.php?page=psbdx-srm-settings&tab=ai&subtab=manage' );
			?>
			<div class="psrm-settings-section">
				<div class="psrm-settings-section-header">
					<span class="dashicons dashicons-format-chat" aria-hidden="true"></span>
					<strong><?php esc_html_e( 'Replies', 'psbdx-smart-report-management' ); ?></strong>
				</div>
				<div class="psrm-settings-section-body">
					<p class="psrm-hint"><?php esc_html_e( 'When enabled, both the reporter and admins can exchange replies on reports submitted through this form (shown on the report edit screen, and in the [psbdx_user_reports] history shortcode). When disabled, reply attempts are rejected.', 'psbdx-smart-report-management' ); ?></p>
					<p>
						<label>
							<input type="checkbox" id="psbdx_allow_replies" name="psbdx_allow_replies" value="yes" <?php checked( $allow_replies ); ?>>
							<?php esc_html_e( 'Allow Replies', 'psbdx-smart-report-management' ); ?>
						</label>
					</p>
					<p id="psbdx_allow_ai_reply_row" style="<?php echo $allow_replies ? '' : 'display:none;'; ?>margin-left:24px;">
						<label>
							<input type="checkbox" id="psbdx_allow_ai_reply" name="psbdx_allow_ai_reply" value="yes" <?php checked( $allow_ai_reply ); ?> <?php disabled( ! $ai_reply_master ); ?>>
							<?php esc_html_e( 'Allow AI to reply', 'psbdx-smart-report-management' ); ?>
						</label>
						<?php if ( ! $ai_reply_master ) : ?>
							<p class="description">
								<?php
								printf(
									/* translators: %s: link to Settings → AI → Manage */
									esc_html__( 'This also requires the site-wide "Allow AI to reply" switch to be turned on first, under %s.', 'psbdx-smart-report-management' ),
									'<a href="' . esc_url( $ai_settings_url ) . '">' . esc_html__( 'Settings → AI → Manage', 'psbdx-smart-report-management' ) . '</a>'
								);
								?>
							</p>
						<?php else : ?>
							<p class="description"><?php esc_html_e( 'AI will automatically post a reply when a report comes in (and after the reporter follows up), using the report and page context. Admins can still reply manually at any time.', 'psbdx-smart-report-management' ); ?></p>
						<?php endif; ?>
					</p>
				</div>
			</div>
			<script>
			(function () {
				var repliesBox = document.getElementById( 'psbdx_allow_replies' );
				var aiRow      = document.getElementById( 'psbdx_allow_ai_reply_row' );
				if ( ! repliesBox || ! aiRow ) {
					return;
				}
				repliesBox.addEventListener( 'change', function () {
					aiRow.style.display = repliesBox.checked ? '' : 'none';
				} );
			})();
			</script>

			<?php
			/**
			 * Fires right after the Replies section of the form's Settings
			 * tab, so extensions (e.g. PSBDX_SRM_API) can render their own
			 * per-form settings section without editing this file.
			 *
			 * @since 1.4.3
			 * @param WP_Post $post  Current report-form post.
			 */
			do_action( 'psbdx_srm_form_builder_after_replies', $post );
			?>

		</div><!-- .psrm-settings-sections -->
		<?php
	}

	// =========================================================================
	// FIELD LIBRARY DEFINITION
	// =========================================================================

	/**
	 * Returns the full field-type library used by the builder.
	 *
	 * Keys match the `type` stored in the JSON schema.
	 *
	 * @since  1.3.0
	 * @return array[]
	 */
	private function get_field_library() {
		return array(
			'name'      => array( 'label' => __( 'Name (First & Last)', 'psbdx-smart-report-management' ), 'icon' => 'dashicons-admin-users' ),
			'email'     => array( 'label' => __( 'Email',               'psbdx-smart-report-management' ), 'icon' => 'dashicons-email-alt' ),
			'mobile'    => array( 'label' => __( 'Mobile Number',       'psbdx-smart-report-management' ), 'icon' => 'dashicons-smartphone' ),
			'text'      => array( 'label' => __( 'Text (Single Line)',  'psbdx-smart-report-management' ), 'icon' => 'dashicons-editor-insertmore' ),
			'paragraph' => array( 'label' => __( 'Paragraph',          'psbdx-smart-report-management' ), 'icon' => 'dashicons-editor-paragraph' ),
			'number'    => array( 'label' => __( 'Number',             'psbdx-smart-report-management' ), 'icon' => 'dashicons-calculator' ),
			'select'    => array( 'label' => __( 'Drop-down / Select', 'psbdx-smart-report-management' ), 'icon' => 'dashicons-arrow-down-alt2' ),
			'radio'     => array( 'label' => __( 'Radio Buttons',      'psbdx-smart-report-management' ), 'icon' => 'dashicons-marker' ),
			'checkbox'  => array( 'label' => __( 'Checkboxes',         'psbdx-smart-report-management' ), 'icon' => 'dashicons-yes-alt' ),
			'attachment' => array( 'label' => __( 'Attachment',        'psbdx-smart-report-management' ), 'icon' => 'dashicons-media-default' ),
			'review'    => array( 'label' => __( 'Review (Star Rating)', 'psbdx-smart-report-management' ), 'icon' => 'dashicons-star-filled' ),
			'captcha'   => array( 'label' => __( 'Captcha',            'psbdx-smart-report-management' ), 'icon' => 'dashicons-shield-alt' ),
		);
	}

	// =========================================================================
	// LEGACY FORM DETECTION & ADMIN NOTICE
	// =========================================================================

	/**
	 * Count how many published report forms are still on v1 (no version meta).
	 *
	 * Called on admin_init so the result is cached as a transient for 5 minutes
	 * to avoid a DB query on every single admin page load.
	 *
	 * @since  1.3.0
	 * @return int
	 */
	public static function count_legacy_forms() {
		$cached = get_transient( 'psrm_legacy_form_count' );
		if ( false !== $cached ) {
			return (int) $cached;
		}

		global $wpdb;

		// Find published report forms that have no _psrm_form_version meta = 2.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts} p
				 WHERE p.post_type   = %s
				   AND p.post_status IN ('publish','draft','private')
				   AND NOT EXISTS (
					   SELECT 1 FROM {$wpdb->postmeta} pm
					   WHERE pm.post_id   = p.ID
					     AND pm.meta_key  = %s
					     AND pm.meta_value = %s
				   )",
				'psbdx_report_form',
				self::VERSION_META_KEY,
				self::SCHEMA_VERSION
			)
		);
		// phpcs:enable

		set_transient( 'psrm_legacy_form_count', $count, 5 * MINUTE_IN_SECONDS );

		return $count;
	}

	/**
	 * On admin_init: bust the cache after a save so the notice refreshes.
	 *
	 * @since  1.3.0
	 * @return void
	 */
	public function check_legacy_forms() {
		// Just ensure the transient is populated; the notice uses it.
		self::count_legacy_forms();
	}

	/**
	 * Display a persistent non-dismissible security notice if legacy forms exist.
	 *
	 * Shown on every admin screen until the count reaches zero.
	 *
	 * @since  1.3.0
	 * @return void
	 */
	public function legacy_notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$count = self::count_legacy_forms();

		if ( $count <= 0 ) {
			return;
		}

		$edit_url = admin_url( 'edit.php?post_type=psbdx_report_form' );
		?>
		<div class="notice notice-error psrm-legacy-notice" style="border-left-color:#b91c1c;">
			<p>
				<strong><?php esc_html_e( 'Security Alert:', 'psbdx-smart-report-management' ); ?></strong>
				<?php
				printf(
					/* translators: %s: link to forms list */
					esc_html__( 'PSRM has updated to a new, secure Form Builder. To maintain frontend security, you must migrate your legacy forms. %s.', 'psbdx-smart-report-management' ),
					'<a href="' . esc_url( $edit_url ) . '"><strong>' . esc_html__( 'Click here to edit and upgrade your forms', 'psbdx-smart-report-management' ) . '</strong></a>'
				);
				?>
				<span class="psrm-legacy-badge"><?php
				/* translators: %d: number of legacy forms remaining to migrate */
				echo esc_html( sprintf( _n( '%d legacy form', '%d legacy forms', $count, 'psbdx-smart-report-management' ), $count ) );
				?></span>
			</p>
		</div>
		<?php
	}

	// =========================================================================
	// AJAX MIGRATION
	// =========================================================================

	/**
	 * AJAX handler: parse v1 meta into a v2 field schema and return it.
	 *
	 * The actual save still happens through the normal save_post flow; this
	 * only returns the converted schema so JS can populate the canvas.
	 *
	 * @since  1.3.0
	 * @return void  Terminates with wp_send_json_success() or wp_send_json_error().
	 */
	public function ajax_migrate_form() {
		check_ajax_referer( 'psbdx_srm_migrate_form', 'nonce' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'psbdx-smart-report-management' ) );
		}

		$form_id = absint( $_POST['form_id'] ?? 0 );

		if ( ! $form_id || 'psbdx_report_form' !== get_post_type( $form_id ) ) {
			wp_send_json_error( __( 'Invalid form ID.', 'psbdx-smart-report-management' ) );
		}

		$fields = $this->migrate_v1_to_v2( $form_id );

		wp_send_json_success( array(
			'fields'  => $fields,
			'message' => __( 'Legacy form parsed. Review the fields below, then save to complete migration.', 'psbdx-smart-report-management' ),
		) );
	}

	/**
	 * Parse v1 meta keys and synthesise an equivalent v2 field schema.
	 *
	 * V1 data:
	 *   _psbdx_contact_label / _psbdx_contact_required → single text/mobile field.
	 *   _psbdx_reasons (comma-separated)               → select field.
	 *   _psbdx_custom_fields (comma-separated)         → one text field each.
	 *   _psbdx_captcha_enabled 'yes'                   → captcha field.
	 *
	 * @since  1.3.0
	 * @param  int $form_id  Post ID.
	 * @return array[]  Array of v2 field definitions.
	 */
	private function migrate_v1_to_v2( $form_id ) {
		$fields = array();

		// Contact field.
		$contact_label = get_post_meta( $form_id, '_psbdx_contact_label', true ) ?: __( 'WhatsApp Number', 'psbdx-smart-report-management' );
		$contact_req   = 'yes' === get_post_meta( $form_id, '_psbdx_contact_required', true );
		$fields[]      = array(
			'id'       => $this->generate_field_id(),
			'type'     => 'mobile',
			'label'    => $contact_label,
			'handle'   => $this->label_to_handle( $contact_label ),
			'required' => $contact_req,
		);

		// Report Reasons → select.
		$reasons_raw = get_post_meta( $form_id, '_psbdx_reasons', true );
		if ( $reasons_raw ) {
			$choices  = array_map( 'trim', explode( ',', $reasons_raw ) );
			$fields[] = array(
				'id'           => $this->generate_field_id(),
				'type'         => 'select',
				'label'        => __( 'Reason', 'psbdx-smart-report-management' ),
				'handle'       => 'report_reason',
				'required'     => true,
				'choices'      => $choices,
				'other_option' => true,
			);
		}

		// Custom text fields.
		$custom_raw = get_post_meta( $form_id, '_psbdx_custom_fields', true );
		if ( $custom_raw ) {
			foreach ( array_map( 'trim', explode( ',', $custom_raw ) ) as $label ) {
				if ( '' === $label ) {
					continue;
				}
				$fields[] = array(
					'id'       => $this->generate_field_id(),
					'type'     => 'text',
					'label'    => $label,
					'handle'   => $this->label_to_handle( $label ),
					'required' => false,
				);
			}
		}

		// Details paragraph.
		$fields[] = array(
			'id'       => $this->generate_field_id(),
			'type'     => 'paragraph',
			'label'    => __( 'Details', 'psbdx-smart-report-management' ),
			'handle'   => 'report_details',
			'required' => true,
		);

		// Captcha.
		if ( 'yes' === get_post_meta( $form_id, '_psbdx_captcha_enabled', true )
			&& '' !== PSBDX_SRM_Captcha::active_provider()
		) {
			$fields[] = array(
				'id'       => $this->generate_field_id(),
				'type'     => 'captcha',
				'label'    => __( 'Captcha', 'psbdx-smart-report-management' ),
				'handle'   => 'captcha',
				'required' => true,
			);
		}

		return $fields;
	}

	// =========================================================================
	// SAVE
	// =========================================================================

	/**
	 * Save the builder fields JSON and settings on post save.
	 *
	 * @since  1.3.0
	 * @param  int $post_id  Post ID.
	 * @return void
	 */
	public function save( $post_id ) {
		if ( ! isset( $_POST[ self::NONCE_FIELD ] )
			|| ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ), self::NONCE_ACTION )
		) {
			return;
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		if ( 'psbdx_report_form' !== get_post_type( $post_id ) ) {
			return;
		}

		// ── Builder fields JSON ───────────────────────────────────────────────
		$raw_json     = isset( $_POST['psrm_builder_fields_json'] )
			? wp_unslash( $_POST['psrm_builder_fields_json'] ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized via json_decode below.
			: '[]';
		$decoded      = json_decode( $raw_json, true );
		$clean_fields = is_array( $decoded ) ? $this->sanitize_fields_schema( $decoded ) : array();
		update_post_meta( $post_id, self::FIELDS_META_KEY, wp_json_encode( $clean_fields ) );

		// ── Mark as v2 if the admin saved from the builder (not legacy save) ──
		$submitted_version = isset( $_POST['psrm_form_version'] ) ? (int) $_POST['psrm_form_version'] : 1;
		if ( $submitted_version >= self::SCHEMA_VERSION || ! empty( $clean_fields ) ) {
			update_post_meta( $post_id, self::VERSION_META_KEY, self::SCHEMA_VERSION );
			// Bust the legacy-form count cache.
			delete_transient( 'psrm_legacy_form_count' );
		}

		// ── Settings fields (same as legacy, kept for backward compat) ────────
		$data = array(
			'psbdx_btn_text'        => isset( $_POST['psbdx_btn_text'] )        ? sanitize_text_field( wp_unslash( $_POST['psbdx_btn_text'] ) )        : '',
			'psbdx_show_identity'   => isset( $_POST['psbdx_show_identity'] )   ? 'yes' : 'no',
			'psbdx_captcha_enabled' => isset( $_POST['psbdx_captcha_enabled'] ) ? 'yes' : 'no',
			'psbdx_cooldown_mins'   => isset( $_POST['psbdx_cooldown_mins'] )   ? min( 1440, max( 0, (int) $_POST['psbdx_cooldown_mins'] ) ) : null,
			'psbdx_is_order_form'   => isset( $_POST['psbdx_is_order_form'] )   && '1' === $_POST['psbdx_is_order_form'],
			'psbdx_is_product_form' => isset( $_POST['psbdx_is_product_form'] ) && '1' === $_POST['psbdx_is_product_form'],
			'psbdx_allow_replies'   => isset( $_POST['psbdx_allow_replies'] )   ? 'yes' : 'no',
			'psbdx_allow_ai_reply'  => isset( $_POST['psbdx_allow_ai_reply'] )  ? 'yes' : 'no',
		);

		if ( '' !== $data['psbdx_btn_text'] ) {
			update_post_meta( $post_id, '_psbdx_btn_text', $data['psbdx_btn_text'] );
		}
		update_post_meta( $post_id, '_psbdx_show_identity',   $data['psbdx_show_identity'] );
		update_post_meta( $post_id, '_psbdx_captcha_enabled', $data['psbdx_captcha_enabled'] );
		update_post_meta( $post_id, '_psbdx_allow_replies',   $data['psbdx_allow_replies'] );
		// AI replies can never be allowed on a form without replies allowed at all,
		// regardless of what was submitted (e.g. stale form state).
		update_post_meta(
			$post_id,
			'_psbdx_allow_ai_reply',
			( 'yes' === $data['psbdx_allow_replies'] ) ? $data['psbdx_allow_ai_reply'] : 'no'
		);

		// Bugfix 1.3.1: _psrm_woo_integration / _psrm_lp_integration were written
		// here but never read anywhere in the plugin — dead meta from the old
		// duplicate "Plugin Integrations" checkboxes. Clean up any leftover
		// values so old installs don't carry stale, meaningless meta forever.
		delete_post_meta( $post_id, '_psrm_woo_integration' );
		delete_post_meta( $post_id, '_psrm_lp_integration' );

		if ( null !== $data['psbdx_cooldown_mins'] ) {
			update_post_meta( $post_id, '_psbdx_cooldown_mins', $data['psbdx_cooldown_mins'] );
		}

		if ( $data['psbdx_is_order_form'] ) {
			update_option( 'psbdx_global_order_form_id', $post_id );
		} elseif ( (int) get_option( 'psbdx_global_order_form_id' ) === $post_id ) {
			delete_option( 'psbdx_global_order_form_id' );
		}

		if ( $data['psbdx_is_product_form'] ) {
			update_option( 'psbdx_global_product_form_id', $post_id );
		} elseif ( (int) get_option( 'psbdx_global_product_form_id' ) === $post_id ) {
			delete_option( 'psbdx_global_product_form_id' );
		}
	}

	// =========================================================================
	// PRIVATE HELPERS
	// =========================================================================

	/**
	 * Sanitize the full fields schema array before persisting.
	 *
	 * Each field is a plain object with known keys; any extra key is dropped.
	 *
	 * @since  1.3.0
	 * @param  array $fields  Raw decoded schema.
	 * @return array[]
	 */
	private function sanitize_fields_schema( array $fields ) {
		$clean = array();

		foreach ( $fields as $field ) {
			if ( ! is_array( $field ) ) {
				continue;
			}

			$type = sanitize_key( $field['type'] ?? '' );

			if ( ! array_key_exists( $type, $this->get_field_library() ) ) {
				continue;
			}

			$entry = array(
				'id'       => sanitize_text_field( $field['id'] ?? $this->generate_field_id() ),
				'type'     => $type,
				'label'    => sanitize_text_field( $field['label'] ?? '' ),
				'handle'   => sanitize_key( $field['handle'] ?? '' ),
				'required' => ! empty( $field['required'] ),
			);

			// Choice fields: choices + other_option.
			if ( in_array( $type, array( 'select', 'radio', 'checkbox' ), true ) ) {
				$raw_choices     = is_array( $field['choices'] ?? null ) ? $field['choices'] : array();
				$entry['choices']      = array_map( 'sanitize_text_field', $raw_choices );
				$entry['other_option'] = ! empty( $field['other_option'] );
			}

			// Attachment field: allowed extensions + min/max size (KB).
			if ( 'attachment' === $type ) {
				$raw_types = is_array( $field['allowed_types'] ?? null ) ? $field['allowed_types'] : array();
				$clean_types = array();
				foreach ( $raw_types as $ext ) {
					$ext = strtolower( preg_replace( '/[^a-z0-9]/i', '', (string) $ext ) );
					if ( '' !== $ext ) {
						$clean_types[] = $ext;
					}
				}
				$entry['allowed_types'] = ! empty( $clean_types ) ? array_values( array_unique( $clean_types ) ) : self::ATTACHMENT_DEFAULT_TYPES;

				$min_kb = max( 0, (int) ( $field['min_size_kb'] ?? 0 ) );
				$max_kb = (int) ( $field['max_size_kb'] ?? 5120 );
				$max_kb = $max_kb > 0 ? min( self::ATTACHMENT_SIZE_CEILING_KB, $max_kb ) : 5120;
				if ( $min_kb > $max_kb ) {
					$min_kb = 0; // Ignore a nonsensical min > max rather than blocking every upload.
				}
				$entry['min_size_kb'] = $min_kb;
				$entry['max_size_kb'] = $max_kb;
				$entry['delete_on_solved'] = ! empty( $field['delete_on_solved'] );
			}

			// Review field: how many stars are shown.
			if ( 'review' === $type ) {
				$max_stars = (int) ( $field['max_stars'] ?? 5 );
				$entry['max_stars'] = min( self::REVIEW_MAX_STARS, max( self::REVIEW_MIN_STARS, $max_stars ?: 5 ) );
			}

			$clean[] = $entry;
		}

		return $clean;
	}

	/**
	 * Generate a short unique field ID.
	 *
	 * @since  1.3.0
	 * @return string
	 */
	private function generate_field_id() {
		return 'f_' . substr( md5( uniqid( '', true ) ), 0, 8 );
	}

	/**
	 * Convert a human label to a safe database handle/slug.
	 *
	 * @since  1.3.0
	 * @param  string $label  Human-readable label.
	 * @return string
	 */
	private function label_to_handle( $label ) {
		return sanitize_key( str_replace( ' ', '_', strtolower( trim( $label ) ) ) );
	}
}
