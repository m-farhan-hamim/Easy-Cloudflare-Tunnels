/**
 * PSBDx Smart Report Management — Form Builder JS (v1.4.0)
 *
 * Handles:
 *   - Tab switching
 *   - Drag-and-drop field library → canvas (jQuery UI Sortable/Draggable) on desktop
 *   - Touch-friendly mobile mode: tap-to-add fields, bottom-sheet library/settings
 *     panels, and Up/Down buttons for reordering (no drag-and-drop dependency)
 *   - Field settings panel (label, handle, required, choices, other option)
 *   - Canvas serialisation → hidden JSON input
 *   - Legacy form migration prompt
 *
 * @package PSBDx_Smart_Report_Management
 * @since   1.3.0
 */

/* global psrmBuilder, jQuery */
( function ( $, cfg ) {
	'use strict';

	// ─── State ───────────────────────────────────────────────────────────────

	/** @type {Array<Object>} Current field schema */
	var fields       = [];
	/** @type {string|null}  ID of the currently selected field */
	var activeFieldId = null;

	// ─── DOM refs ─────────────────────────────────────────────────────────────

	var $canvas          = $( '#psrm-canvas' );
	var $canvasEmpty     = $( '#psrm-canvas-empty' );
	var $settingsBody    = $( '#psrm-field-settings-body' );
	var $settingsPanel   = $( '#psrm-field-settings-panel' );
	var $fieldLibrary    = $( '#psrm-field-library' );
	var $jsonInput       = $( '#psrm_builder_fields_json' );
	var $versionInput    = $( '#psrm_form_version' );
	var $builderWrap     = $( '#psrm-builder-wrap' );
	var $migrationGate   = $( '#psrm-migration-gate' );
	var $sheetBackdrop   = $( '#psrm-sheet-backdrop' );
	var $mobileAddBtn    = $( '#psrm-mobile-add-field-btn' );
	var $mobileCount     = $( '#psrm-mobile-field-count' );

	/** Mobile breakpoint, kept in sync with form-builder.css. */
	var MOBILE_BREAKPOINT = 768;

	// ─── Helpers ──────────────────────────────────────────────────────────────

	/**
	 * Generate a short unique field ID.
	 *
	 * @returns {string}
	 */
	function uid() {
		return 'f_' + Math.random().toString( 36 ).substr( 2, 8 );
	}

	/**
	 * Convert a label string to a safe handle/slug.
	 *
	 * @param  {string} label
	 * @returns {string}
	 */
	function toHandle( label ) {
		return label.toLowerCase().replace( /\s+/g, '_' ).replace( /[^a-z0-9_]/g, '' );
	}

	/**
	 * Default label for a given field type.
	 *
	 * @param  {string} type
	 * @returns {string}
	 */
	function defaultLabel( type ) {
		var map = {
			name      : 'Full Name',
			email     : 'Email Address',
			mobile    : 'Mobile Number',
			text      : 'Text Field',
			paragraph : 'Paragraph',
			number    : 'Number',
			select    : 'Select / Drop-down',
			radio     : 'Radio Buttons',
			checkbox  : 'Checkboxes',
			captcha   : 'Captcha'
		};
		return map[ type ] || 'Field';
	}

	/**
	 * Serialise the fields array and write it to the hidden JSON input.
	 */
	function persistFields() {
		$jsonInput.val( JSON.stringify( fields ) );
	}

	/**
	 * Find a field definition by ID.
	 *
	 * @param  {string} id
	 * @returns {Object|undefined}
	 */
	function getField( id ) {
		return fields.find( function ( f ) { return f.id === id; } );
	}

	/**
	 * Choice fields that support the "Other" option toggle.
	 *
	 * @param  {string} type
	 * @returns {boolean}
	 */
	function isChoiceField( type ) {
		return type === 'select' || type === 'radio' || type === 'checkbox';
	}

	// ─── Mobile detection ─────────────────────────────────────────────────────
	// The builder is fully usable on mobile: the field library and field
	// settings panel become bottom sheets (opened via a floating "Add Field"
	// button and by tapping a field card), and reordering uses Up/Down
	// buttons on each card instead of relying on drag-and-drop, which jQuery
	// UI does not support on touch devices without an extra touch-punch
	// dependency this plugin doesn't ship.

	function isMobile() {
		return window.innerWidth < MOBILE_BREAKPOINT;
	}

	function updateMobileFieldCount() {
		var tmpl = ( cfg.i18n && cfg.i18n.fieldCount ) || '%d field(s)';
		$mobileCount.text( tmpl.replace( '%d', fields.length ) );
	}

	/**
	 * Open a field-library or field-settings popup.
	 *
	 * The field library is a mobile-only bottom sheet — on desktop it's a
	 * normal, always-visible column, so opening it as an overlay there would
	 * make no sense. The field settings panel, on the other hand, is now a
	 * popup on every viewport (a right-side drawer on desktop, a bottom sheet
	 * on mobile) — it used to be a permanently visible third grid column on
	 * desktop, which left too little room for the canvas at anything
	 * narrower than a wide monitor.
	 *
	 * @param {jQuery} $sheet
	 */
	function openSheet( $sheet ) {
		if ( $sheet.is( $fieldLibrary ) && ! isMobile() ) { return; }
		$( '.psrm-sheet-open' ).not( $sheet ).removeClass( 'psrm-sheet-open' ).attr( 'aria-hidden', 'true' );
		$sheet.addClass( 'psrm-sheet-open' ).attr( 'aria-hidden', 'false' );
		$sheetBackdrop.prop( 'hidden', false );
	}

	/**
	 * Close all open popups/sheets and hide the backdrop.
	 */
	function closeSheets() {
		$( '.psrm-sheet-open' ).removeClass( 'psrm-sheet-open' ).attr( 'aria-hidden', 'true' );
		$sheetBackdrop.prop( 'hidden', true );
	}

	$sheetBackdrop.on( 'click', closeSheets );
	$( '#psrm-library-close' ).on( 'click', closeSheets );
	$( '#psrm-settings-close' ).on( 'click', closeSheets );

	// Esc closes whatever popup is open, on any viewport.
	$( document ).on( 'keydown', function ( e ) {
		if ( 'Escape' === e.key && $( '.psrm-sheet-open' ).length ) {
			closeSheets();
		}
	} );

	$mobileAddBtn.on( 'click', function () {
		openSheet( $fieldLibrary );
	} );

	$( window ).on( 'resize', function () {
		// Leaving mobile width: the library sheet has no meaning on desktop
		// (it's a normal column there), so drop its open state. The settings
		// popup stays open if it already was — it's valid at every width.
		if ( ! isMobile() ) {
			$fieldLibrary.removeClass( 'psrm-sheet-open' ).attr( 'aria-hidden', 'true' );
			if ( ! $settingsPanel.hasClass( 'psrm-sheet-open' ) ) {
				$sheetBackdrop.prop( 'hidden', true );
			}
		}
	} );

	// ─── Tab switching ────────────────────────────────────────────────────────

	$( '.psrm-tab-btn' ).on( 'click', function () {
		var $btn    = $( this );
		var target  = $btn.attr( 'aria-controls' );

		$( '.psrm-tab-btn' ).removeClass( 'psrm-tab-active' ).attr( 'aria-selected', 'false' );
		$btn.addClass( 'psrm-tab-active' ).attr( 'aria-selected', 'true' );

		$( '.psrm-tab-panel' ).addClass( 'psrm-tab-hidden' ).attr( 'hidden', true );
		$( '#' + target ).removeClass( 'psrm-tab-hidden' ).removeAttr( 'hidden' );
	} );

	// ─── Load existing fields from server-injected JSON ───────────────────────

	( function loadInitialFields() {
		var $dataEl = $( '#psrm-fields-data' );
		if ( ! $dataEl.length ) { return; }

		try {
			var parsed = JSON.parse( $dataEl.text() );
			if ( Array.isArray( parsed ) && parsed.length ) {
				fields = parsed;
				fields.forEach( renderFieldCard );
			}
		} catch ( e ) {
			// Malformed JSON — start with blank canvas.
		}

		toggleCanvasEmpty();
	}() );

	// ─── Field Library — drag from panel (desktop/mouse only) ─────────────────
	//
	// jQuery UI's draggable/sortable are mouse-event based and have no touch
	// support without the extra touch-punch plugin this project doesn't ship.
	// On a touch device, binding them anyway can intercept/suppress the
	// synthesized mousedown that follows a tap — silently eating the click
	// before our tap-to-add / tap-to-select handlers ever run, so taps on the
	// mobile bottom sheet looked like they "did nothing" and touches fell
	// through to whatever was underneath. Mobile relies entirely on
	// tap-to-add (below) and the Up/Down reorder buttons instead.

	if ( ! isMobile() ) {
		$( '#psrm-field-library-list .psrm-library-field:not(.psrm-library-field-disabled)' )
			.draggable( {
				helper      : 'clone',
				appendTo    : 'body',
				cursor      : 'grabbing',
				revert      : 'invalid',
				zIndex      : 9999,
				start       : function () {
					$( this ).addClass( 'psrm-dragging' );
				},
				stop        : function () {
					$( this ).removeClass( 'psrm-dragging' );
				}
			} );
	}

	// Click to add (library panel) — works on both desktop and mobile.
	$( '#psrm-field-library-list' ).on( 'click keypress', '.psrm-library-field:not(.psrm-library-field-disabled)', function ( e ) {
		if ( e.type === 'keypress' && e.which !== 13 ) { return; }
		var type = $( this ).data( 'type' );
		addField( type );
	} );

	// ─── Canvas — droppable (desktop/mouse only) ───────────────────────────────

	if ( ! isMobile() ) {
		$canvas.droppable( {
			accept   : '.psrm-library-field',
			hoverClass: 'psrm-canvas-drop-hover',
			drop     : function ( event, ui ) {
				var type = ui.draggable.data( 'type' );
				if ( type ) {
					addField( type );
				}
			}
		} );

		// Make canvas items sortable (reorder by dragging). Mobile uses the
		// per-card Up/Down buttons instead — see moveField().
		$canvas.sortable( {
			items    : '.psrm-field-card',
			handle   : '.psrm-field-card-drag',
			axis     : 'y',
			cursor   : 'ns-resize',
			update   : function () {
				// Sync sort order back to `fields` array.
				var newOrder = [];
				$canvas.find( '.psrm-field-card' ).each( function () {
					var id = $( this ).data( 'id' );
					var f  = getField( id );
					if ( f ) { newOrder.push( f ); }
				} );
				fields = newOrder;
				persistFields();
			}
		} );
	}

	// ─── Add a new field ──────────────────────────────────────────────────────

	/**
	 * Create a default field definition, push to state, and render.
	 *
	 * @param {string} type
	 */
	function addField( type ) {
		if ( type === 'captcha' && ! cfg.captchaActive ) { return; }

		var label = defaultLabel( type );
		var f = {
			id       : uid(),
			type     : type,
			label    : label,
			handle   : toHandle( label ),
			required : false
		};

		if ( isChoiceField( type ) ) {
			f.choices      = [ 'Option 1', 'Option 2' ];
			f.other_option = false;
		}

		if ( type === 'attachment' ) {
			f.allowed_types = [ 'jpg', 'jpeg', 'png', 'pdf' ];
			f.min_size_kb   = 0;
			f.max_size_kb   = 5120;
		}

		if ( type === 'review' ) {
			f.max_stars = 5;
		}

		fields.push( f );
		persistFields();
		renderFieldCard( f );
		toggleCanvasEmpty();

		// selectField() opens the settings popup on every viewport, so the
		// flow is add → configure → close, whether that's a right-side
		// drawer on desktop or a bottom sheet on mobile.
		selectField( f.id );
	}

	// ─── Render a field card on the canvas ────────────────────────────────────

	/**
	 * Build and append a field card HTML element.
	 *
	 * @param {Object} f  Field definition.
	 */
	function renderFieldCard( f ) {
		var $card = $( '<div>' )
			.addClass( 'psrm-field-card' )
			.attr( {
				'data-id'    : f.id,
				'data-type'  : f.type,
				'tabindex'   : '0',
				'role'       : 'listitem',
				'aria-label' : f.label
			} );

		var $drag = $( '<span>' )
			.addClass( 'psrm-field-card-drag dashicons dashicons-move' )
			.attr( 'aria-hidden', 'true' );

		var $info = $( '<div>' ).addClass( 'psrm-field-card-info' );
		$info.append(
			$( '<span>' ).addClass( 'psrm-field-card-type' ).text( f.type.charAt( 0 ).toUpperCase() + f.type.slice( 1 ) ),
			$( '<span>' ).addClass( 'psrm-field-card-label' ).text( f.label )
		);

		if ( f.required ) {
			$info.append( $( '<span>' ).addClass( 'psrm-field-card-required' ).text( '*' ).attr( 'title', 'Required' ) );
		}

		var $moveUp = $( '<button>' )
			.addClass( 'psrm-field-card-move psrm-field-card-move-up' )
			.attr( {
				type         : 'button',
				'aria-label' : cfg.i18n.moveUp || 'Move field up'
			} )
			.html( '<span class="dashicons dashicons-arrow-up-alt2" aria-hidden="true"></span>' );

		var $moveDown = $( '<button>' )
			.addClass( 'psrm-field-card-move psrm-field-card-move-down' )
			.attr( {
				type         : 'button',
				'aria-label' : cfg.i18n.moveDown || 'Move field down'
			} )
			.html( '<span class="dashicons dashicons-arrow-down-alt2" aria-hidden="true"></span>' );

		var $moveGroup = $( '<div>' ).addClass( 'psrm-field-card-move-group' ).append( $moveUp, $moveDown );

		var $del = $( '<button>' )
			.addClass( 'psrm-field-card-delete' )
			.attr( {
				type         : 'button',
				'aria-label' : cfg.i18n.deleteField
			} )
			.html( '<span class="dashicons dashicons-trash" aria-hidden="true"></span>' );

		$card.append( $drag, $info, $moveGroup, $del );
		$canvas.append( $card );

		// Click card → select.
		$card.on( 'click keypress', function ( e ) {
			if ( $( e.target ).closest( '.psrm-field-card-delete, .psrm-field-card-move' ).length ) { return; }
			if ( e.type === 'keypress' && e.which !== 13 ) { return; }
			selectField( f.id );
		} );

		// Delete button.
		$del.on( 'click', function ( e ) {
			e.stopPropagation();
			deleteField( f.id );
		} );

		// Move up/down — a touch-friendly alternative to drag-and-drop reordering.
		$moveUp.on( 'click', function ( e ) {
			e.stopPropagation();
			moveField( f.id, -1 );
		} );
		$moveDown.on( 'click', function ( e ) {
			e.stopPropagation();
			moveField( f.id, 1 );
		} );
	}

	/**
	 * Move a field up or down one position, in both state and the DOM.
	 *
	 * @param {string} id        Field ID.
	 * @param {number} direction -1 to move up, 1 to move down.
	 */
	function moveField( id, direction ) {
		var index = fields.findIndex( function ( f ) { return f.id === id; } );
		var target = index + direction;

		if ( index === -1 || target < 0 || target >= fields.length ) { return; }

		var tmp = fields[ index ];
		fields[ index ]  = fields[ target ];
		fields[ target ] = tmp;

		var $card = $canvas.find( '.psrm-field-card[data-id="' + id + '"]' );
		if ( direction < 0 ) {
			$card.insertBefore( $card.prev( '.psrm-field-card' ) );
		} else {
			$card.insertAfter( $card.next( '.psrm-field-card' ) );
		}

		persistFields();
	}

	// ─── Select / activate a field ───────────────────────────────────────────

	/**
	 * Highlight the selected card and populate the settings panel.
	 *
	 * @param {string} id  Field ID.
	 */
	function selectField( id ) {
		activeFieldId = id;
		$canvas.find( '.psrm-field-card' ).removeClass( 'psrm-field-card-active' );
		$canvas.find( '.psrm-field-card[data-id="' + id + '"]' ).addClass( 'psrm-field-card-active' );
		$settingsPanel.attr( 'aria-hidden', 'false' );
		renderFieldSettings( id );
		openSheet( $settingsPanel );
	}

	// ─── Delete a field ───────────────────────────────────────────────────────

	/**
	 * Remove a field from state and DOM.
	 *
	 * @param {string} id
	 */
	function deleteField( id ) {
		fields = fields.filter( function ( f ) { return f.id !== id; } );
		$canvas.find( '.psrm-field-card[data-id="' + id + '"]' ).remove();
		persistFields();
		toggleCanvasEmpty();

		if ( activeFieldId === id ) {
			activeFieldId = null;
			$settingsBody.html( '<p class="psrm-settings-hint">' + cfg.i18n.selectFieldHint + '</p>' );
			$settingsPanel.attr( 'aria-hidden', 'true' );
			closeSheets();
		}
	}

	// ─── Show/hide canvas empty state ────────────────────────────────────────

	function toggleCanvasEmpty() {
		$canvasEmpty.toggle( fields.length === 0 );
		updateMobileFieldCount();
	}

	// ─── Field Settings Panel ─────────────────────────────────────────────────

	/**
	 * Build the settings form for a field and inject into the panel.
	 *
	 * @param {string} id
	 */
	function renderFieldSettings( id ) {
		var f = getField( id );
		if ( ! f ) { return; }

		var $body = $( '<div>' ).addClass( 'psrm-field-settings-form' );

		// Label.
		$body.append( makeTextInput( 'psrm_fs_label', 'Field Name (Label)', f.label, function ( val ) {
			f.label = val;
			var $card = $canvas.find( '.psrm-field-card[data-id="' + id + '"]' );
			$card.find( '.psrm-field-card-label' ).text( val );
			$card.attr( 'aria-label', val );
			persistFields();
		} ) );

		// Handle.
		$body.append( makeTextInput( 'psrm_fs_handle', 'Field Handle (slug used in database)', f.handle, function ( val ) {
			f.handle = val.toLowerCase().replace( /[^a-z0-9_]/g, '' );
			$( '#psrm_fs_handle' ).val( f.handle );
			persistFields();
		} ) );

		// Required.
		$body.append( makeCheckbox( 'psrm_fs_required', 'Required', f.required, function ( checked ) {
			f.required = checked;
			var $card = $canvas.find( '.psrm-field-card[data-id="' + id + '"]' );
			$card.find( '.psrm-field-card-required' ).remove();
			if ( checked ) {
				$card.find( '.psrm-field-card-info' )
					.append( $( '<span>' ).addClass( 'psrm-field-card-required' ).text( '*' ).attr( 'title', 'Required' ) );
			}
			persistFields();
		} ) );

		// Choice-field extras: choice list + other option.
		if ( isChoiceField( f.type ) ) {
			$body.append( makeChoiceEditor( f, id ) );

			$body.append( makeCheckbox(
				'psrm_fs_other_option',
				'Enable "Other" Option',
				!! f.other_option,
				function ( checked ) {
					f.other_option = checked;
					persistFields();
				}
			) );
		}

		// Attachment extras: allowed extensions + min/max size.
		if ( f.type === 'attachment' ) {
			$body.append( makeTextInput(
				'psrm_fs_allowed_types',
				'Allowed file extensions (comma-separated, e.g. jpg,png,pdf)',
				( f.allowed_types || [] ).join( ', ' ),
				function ( val ) {
					f.allowed_types = val.split( ',' )
						.map( function ( s ) { return s.trim().toLowerCase().replace( /^\.+/, '' ).replace( /[^a-z0-9]/g, '' ); } )
						.filter( Boolean );
					persistFields();
				}
			) );

			$body.append( makeNumberInput(
				'psrm_fs_min_size',
				'Minimum file size in KB (0 = no minimum)',
				f.min_size_kb || 0,
				0,
				51200,
				function ( val ) {
					f.min_size_kb = Math.max( 0, val );
					persistFields();
				}
			) );

			$body.append( makeNumberInput(
				'psrm_fs_max_size',
				'Maximum file size in KB (e.g. 5120 = 5 MB)',
				f.max_size_kb || 5120,
				1,
				51200,
				function ( val ) {
					f.max_size_kb = Math.min( 51200, Math.max( 1, val || 5120 ) );
					persistFields();
				}
			) );

			$body.append( makeCheckbox(
				'psrm_fs_delete_on_solved',
				'Automatically delete this attachment when the report is marked Solved',
				!! f.delete_on_solved,
				function ( checked ) {
					f.delete_on_solved = checked;
					persistFields();
				}
			) );
		}

		// Review extras: how many stars to show.
		if ( f.type === 'review' ) {
			$body.append( makeNumberInput(
				'psrm_fs_max_stars',
				'Number of stars to show (2–10)',
				f.max_stars || 5,
				2,
				10,
				function ( val ) {
					f.max_stars = Math.min( 10, Math.max( 2, val || 5 ) );
					persistFields();
				}
			) );
		}

		$settingsBody.empty().append( $body );
	}

	// Settings field helpers.

	/**
	 * Build a labelled text input row.
	 */
	function makeTextInput( inputId, label, value, onChange ) {
		var $wrap  = $( '<div>' ).addClass( 'psrm-sf-row' );
		var $label = $( '<label>' ).attr( 'for', inputId ).text( label );
		var $input = $( '<input>' ).attr( { type: 'text', id: inputId } ).val( value ).addClass( 'large-text' );

		$input.on( 'input change', function () {
			onChange( $( this ).val() );
		} );

		return $wrap.append( $label, $input );
	}

	/**
	 * Build a labelled checkbox row.
	 */
	function makeCheckbox( inputId, label, checked, onChange ) {
		var $wrap  = $( '<div>' ).addClass( 'psrm-sf-row psrm-sf-row-check' );
		var $label = $( '<label>' ).attr( 'for', inputId );
		var $input = $( '<input>' ).attr( { type: 'checkbox', id: inputId } ).prop( 'checked', checked );

		$input.on( 'change', function () {
			onChange( $( this ).is( ':checked' ) );
		} );

		return $wrap.append( $input, $label.append( $input, document.createTextNode( ' ' + label ) ) );
	}

	/**
	 * Build a labelled number input row.
	 */
	function makeNumberInput( inputId, label, value, min, max, onChange ) {
		var $wrap  = $( '<div>' ).addClass( 'psrm-sf-row' );
		var $label = $( '<label>' ).attr( 'for', inputId ).text( label );
		var $input = $( '<input>' ).attr( { type: 'number', id: inputId, min: min, max: max } ).val( value );

		$input.on( 'input change', function () {
			var val = parseInt( $( this ).val(), 10 );
			onChange( isNaN( val ) ? min : val );
		} );

		return $wrap.append( $label, $input );
	}

	/**
	 * Build the choices editor (add/remove/edit option list).
	 *
	 * @param {Object} f   Field definition.
	 * @param {string} id  Field ID.
	 * @returns {jQuery}
	 */
	function makeChoiceEditor( f, id ) {
		var $wrap = $( '<div>' ).addClass( 'psrm-sf-row psrm-choice-editor' );
		$wrap.append( $( '<label>' ).text( 'Options (one per line)' ) );

		var $list = $( '<div>' ).addClass( 'psrm-choice-list' );

		function buildChoiceRows() {
			$list.empty();
			( f.choices || [] ).forEach( function ( choice, idx ) {
				var $row   = $( '<div>' ).addClass( 'psrm-choice-row' );
				var $input = $( '<input>' )
					.attr( { type: 'text', 'data-idx': idx } )
					.val( choice )
					.addClass( 'regular-text' );
				var $del   = $( '<button>' )
					.attr( { type: 'button', 'aria-label': 'Remove option' } )
					.addClass( 'psrm-choice-del' )
					.html( '<span class="dashicons dashicons-minus" aria-hidden="true"></span>' );

				$input.on( 'input change', function () {
					f.choices[ parseInt( $( this ).data( 'idx' ), 10 ) ] = $( this ).val();
					persistFields();
				} );

				$del.on( 'click', function () {
					f.choices.splice( idx, 1 );
					buildChoiceRows();
					persistFields();
				} );

				$row.append( $input, $del );
				$list.append( $row );
			} );
		}

		buildChoiceRows();

		var $addBtn = $( '<button>' )
			.attr( { type: 'button' } )
			.addClass( 'button psrm-choice-add' )
			.html( '<span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span> Add Option' );

		$addBtn.on( 'click', function () {
			if ( ! f.choices ) { f.choices = []; }
			f.choices.push( 'Option ' + ( f.choices.length + 1 ) );
			buildChoiceRows();
			persistFields();
		} );

		return $wrap.append( $list, $addBtn );
	}

	// ─── Legacy migration ────────────────────────────────────────────────────

	if ( $migrationGate.length ) {
		$( '.psrm-migrate-btn' ).on( 'click', function () {
			if ( ! window.confirm( cfg.i18n.confirmMigrate ) ) { return; }

			var $btn     = $( this );
			var $spinner = $migrationGate.find( '.psrm-migrate-spinner' );
			var formId   = $btn.data( 'form-id' );

			$btn.prop( 'disabled', true ).text( cfg.i18n.migrating );
			$spinner.css( 'visibility', 'visible' );

			$.post( cfg.ajaxUrl, {
				action  : 'psbdx_srm_migrate_form',
				nonce   : cfg.migrateNonce,
				form_id : formId
			}, function ( response ) {
				$spinner.css( 'visibility', 'hidden' );

				if ( ! response.success ) {
					$btn.prop( 'disabled', false ).text( cfg.i18n.migrationFailed );
					return;
				}

				// Load migrated fields.
				fields = response.data.fields || [];
				$canvas.find( '.psrm-field-card' ).remove();
				fields.forEach( renderFieldCard );
				toggleCanvasEmpty();
				persistFields();

				// Unlock the builder and mark as v2.
				$versionInput.val( '2' );
				$migrationGate.slideUp( 300 );
				$( '#psrm-builder-layout' ).removeClass( 'psrm-builder-locked' );

				// Show a success notice.
				var $notice = $( '<div>' )
					.addClass( 'psrm-migrate-success notice notice-success inline' )
					.html( '<p>' + response.data.message + '</p>' );
				$migrationGate.after( $notice );
				setTimeout( function () { $notice.fadeOut( 400, function () { $notice.remove(); } ); }, 5000 );
			} ).fail( function () {
				$spinner.css( 'visibility', 'hidden' );
				$btn.prop( 'disabled', false ).text( cfg.i18n.migrationFailed );
			} );
		} );
	}

	// ─── Canvas keyboard accessibility (Enter to select) ─────────────────────
	$canvas.on( 'keypress', '.psrm-field-card', function ( e ) {
		if ( e.which === 13 ) {
			selectField( $( this ).data( 'id' ) );
		}
	} );

}( jQuery, psrmBuilder ) );
