/**
 * PSBDx Smart Report Management — Public Scripts
 *
 * Handles modal open/close, "Other" reason toggling,
 * and AJAX form submission with loading/success/error states.
 *
 * Relies on `psbdxSrm` global localised by wp_localize_script().
 *
 * @package PSBDx_Smart_Report_Management
 * @since   1.0.0
 */

/* global psbdxSrm, fetch */
( function () {
	'use strict';

	// Guards against this file being loaded twice on the same page (which
	// can legitimately happen: see the fallback loading in
	// PSBDX_SRM_Report_Page::render() for themes that don't print enqueued
	// scripts reliably) — without this, every click/submit handler below
	// would fire twice per interaction.
	if ( window.psbdxSrmPublicLoaded ) {
		return;
	}
	window.psbdxSrmPublicLoaded = true;

	if ( window.console && window.console.info ) {
		window.console.info( '[PSBDx] public.js loaded, psbdxSrm present:', !! window.psbdxSrm );
	}

	// ── Captcha ────────────────────────────────────────────────────────────

	/**
	 * Map of widgetId => provider for rendered captcha widgets.
	 * Keyed by the container element's id attribute.
	 */
	var captchaWidgets = {};

	/**
	 * Render captcha widgets inside all .psbdx-captcha-widget elements.
	 * Called by the captcha provider's onload callback (explicit render mode).
	 */
	window.psbdxInitCaptcha = function () {
		var cfg = ( window.psbdxSrm && psbdxSrm.captcha ) ? psbdxSrm.captcha : {};
		var provider = cfg.provider || '';
		var siteKey  = cfg.siteKey  || '';

		if ( ! provider || ! siteKey ) {
			return;
		}

		document.querySelectorAll( '.psbdx-captcha-widget' ).forEach( function ( el ) {
			if ( el.getAttribute( 'data-rendered' ) ) {
				return;
			}

			var widgetId;

			try {
				if ( 'recaptcha' === provider && window.grecaptcha && grecaptcha.render ) {
					widgetId = grecaptcha.render( el, { sitekey: siteKey } );
				} else if ( 'hcaptcha' === provider && window.hcaptcha && hcaptcha.render ) {
					widgetId = hcaptcha.render( el, { sitekey: siteKey } );
				} else if ( 'turnstile' === provider && window.turnstile && turnstile.render ) {
					widgetId = turnstile.render( el, { sitekey: siteKey } );
				}
			} catch ( e ) {
				// Widget render failed — non-fatal; server will reject empty tokens.
			}

			if ( widgetId !== undefined ) {
				captchaWidgets[ el.id ] = { provider: provider, widgetId: widgetId };
				el.setAttribute( 'data-rendered', '1' );
			}
		} );
	};

	/**
	 * Get the captcha token for a widget container, or empty string.
	 *
	 * @param  {HTMLElement} widgetEl  The .psbdx-captcha-widget element.
	 * @return {string}
	 */
	function getCaptchaToken( widgetEl ) {
		if ( ! widgetEl ) {
			return '';
		}

		var info = captchaWidgets[ widgetEl.id ];
		if ( ! info ) {
			return '';
		}

		try {
			if ( 'recaptcha' === info.provider && window.grecaptcha ) {
				return grecaptcha.getResponse( info.widgetId ) || '';
			}
			if ( 'hcaptcha' === info.provider && window.hcaptcha ) {
				return hcaptcha.getResponse( info.widgetId ) || '';
			}
			if ( 'turnstile' === info.provider && window.turnstile ) {
				return turnstile.getResponse( info.widgetId ) || '';
			}
		} catch ( e ) {}

		return '';
	}

	/**
	 * Reset a captcha widget after a failed submission.
	 *
	 * @param {HTMLElement} widgetEl
	 */
	function resetCaptcha( widgetEl ) {
		if ( ! widgetEl ) {
			return;
		}

		var info = captchaWidgets[ widgetEl.id ];
		if ( ! info ) {
			return;
		}

		try {
			if ( 'recaptcha' === info.provider && window.grecaptcha ) {
				grecaptcha.reset( info.widgetId );
			} else if ( 'hcaptcha' === info.provider && window.hcaptcha ) {
				hcaptcha.reset( info.widgetId );
			} else if ( 'turnstile' === info.provider && window.turnstile ) {
				turnstile.reset( info.widgetId );
			}
		} catch ( e ) {}
	}

	/**
	 * Re-render captcha widgets inside a freshly opened modal
	 * (widgets in display:none containers may not render in some browsers).
	 *
	 * @param {HTMLElement} modal
	 */
	function maybeRenderCaptchaInModal( modal ) {
		var cfg = ( window.psbdxSrm && psbdxSrm.captcha ) ? psbdxSrm.captcha : {};
		if ( ! cfg.provider ) {
			return;
		}

		modal.querySelectorAll( '.psbdx-captcha-widget:not([data-rendered])' ).forEach( function ( el ) {
			window.psbdxInitCaptcha();
		} );
	}

	/**
	 * Retrieve a modal element by its trigger's data-target attribute.
	 *
	 * @param  {HTMLElement} trigger  The trigger button.
	 * @return {HTMLElement|null}
	 */
	function getModal( trigger ) {
		var id = trigger.getAttribute( 'data-target' );
		return id ? document.getElementById( id ) : null;
	}

	/**
	 * Open a modal — adds class and locks body scroll.
	 *
	 * @param {HTMLElement} modal
	 */
	function openModal( modal ) {
		modal.classList.add( 'psbdx-is-open' );
		document.body.style.overflow = 'hidden';

		// Move focus into the modal for accessibility.
		var firstFocusable = modal.querySelector( 'button, input, select, textarea, [tabindex]:not([tabindex="-1"])' );
		if ( firstFocusable ) {
			firstFocusable.focus();
		}

		// Render captcha widgets that may have been skipped (hidden container).
		maybeRenderCaptchaInModal( modal );
	}

	/**
	 * Close a modal — removes class and restores body scroll.
	 *
	 * @param {HTMLElement} modal
	 */
	function closeModal( modal ) {
		modal.classList.remove( 'psbdx-is-open' );
		document.body.style.overflow = '';
	}

	/**
	 * Close all open modals.
	 */
	function closeAllModals() {
		document.querySelectorAll( '.psbdx-modal.psbdx-is-open' ).forEach( closeModal );
	}

	// Exposed for the URL-popup feature (a separate top-level IIFE further
	// down this file, out of reach of this one's local openModal()) so it
	// can open a modal it injects at runtime the exact same way a normal
	// button click does.
	window.psbdxSrmOpenModal = openModal;

	// ── Modal open/close ───────────────────────────────────────────────────

	/**
	 * Handle all click events via event delegation.
	 *
	 * @param {MouseEvent} e
	 */
	document.body.addEventListener( 'click', function ( e ) {
		// Open modal.
		var trigger = e.target.closest( '.psbdx-trigger-btn' );
		if ( trigger ) {
			e.preventDefault();
			e.stopPropagation();
			var modal = getModal( trigger );
			if ( modal ) {
				openModal( modal );
			}
			return;
		}

		// Close via X button.
		var closeBtn = e.target.closest( '.psbdx-modal-close' );
		if ( closeBtn ) {
			var parentModal = closeBtn.closest( '.psbdx-modal' );
			if ( parentModal ) {
				closeModal( parentModal );
			}
			return;
		}

		// Close by clicking the backdrop (outside the panel).
		if ( e.target.classList.contains( 'psbdx-modal' ) ) {
			closeModal( e.target );
		}
	} );

	// Close on Escape key.
	document.addEventListener( 'keydown', function ( e ) {
		if ( e.key === 'Escape' ) {
			closeAllModals();
		}
	} );

	// ── "Other" reason field toggle ────────────────────────────────────────

	document.body.addEventListener( 'change', function ( e ) {
		if ( ! e.target.classList.contains( 'psbdx-reason-select' ) ) {
			return;
		}

		var form        = e.target.closest( 'form' );
		var otherField  = form ? form.querySelector( '.psbdx-other-reason' ) : null;
		var otherInput  = otherField ? otherField.querySelector( 'input' ) : null;

		if ( ! otherField || ! otherInput ) {
			return;
		}

		var i18nOther = ( window.psbdxSrm && psbdxSrm.i18n && psbdxSrm.i18n.otherReason ) ? psbdxSrm.i18n.otherReason : 'Other';

		if ( e.target.value === i18nOther ) {
			otherField.style.display = 'block';
			otherInput.setAttribute( 'required', 'required' );
			otherInput.focus();
		} else {
			otherField.style.display = 'none';
			otherInput.removeAttribute( 'required' );
			otherInput.value = '';
		}
	} );

	// ── Form submission ────────────────────────────────────────────────────

	document.body.addEventListener( 'submit', function ( e ) {
		if ( ! e.target.classList.contains( 'psbdx-report-form' ) ) {
			return;
		}

		e.preventDefault();
		handleSubmit( e.target );
	} );

	/**
	 * Handle AJAX form submission.
	 *
	 * @param {HTMLFormElement} form
	 */
	function handleSubmit( form ) {
		var modal     = form.closest( '.psbdx-modal' );
		var response  = modal ? modal.querySelector( '.psbdx-form-response' ) : null;
		var submitBtn = form.querySelector( '.psbdx-submit-btn' );
		var btnLabel  = submitBtn ? submitBtn.querySelector( '.psbdx-btn-label' )  : null;
		var btnSpin   = submitBtn ? submitBtn.querySelector( '.psbdx-btn-spinner' ) : null;

		var i18n = ( window.psbdxSrm && psbdxSrm.i18n ) ? psbdxSrm.i18n : {};

		// ── Captcha token validation ──────────────────────────────────────
		var captchaEl = form.querySelector( '.psbdx-captcha-widget[data-rendered]' );
		if ( captchaEl ) {
			var token = getCaptchaToken( captchaEl );
			if ( ! token ) {
				if ( response ) {
					response.innerHTML =
						'<div class="psbdx-notice psbdx-notice-error">' +
						escapeHtml( i18n.captchaFail || 'Please complete the captcha before submitting.' ) +
						'</div>';
				}
				return;
			}
		}

		// Show loading state.
		if ( submitBtn ) { submitBtn.disabled = true; }
		if ( btnLabel )  { btnLabel.style.display  = 'none'; }
		if ( btnSpin )   { btnSpin.style.display    = 'flex'; }
		if ( response )  { response.innerHTML = ''; }

		var formData = new FormData( form );
		var ajaxUrl  = ( window.psbdxSrm && psbdxSrm.ajaxUrl ) ? psbdxSrm.ajaxUrl : '/wp-admin/admin-ajax.php';

		fetch( ajaxUrl, {
			method:      'POST',
			body:        formData,
			credentials: 'same-origin',
		} )
		.then( function ( res ) {
			if ( ! res.ok ) {
				throw new Error( 'HTTP ' + res.status );
			}
			return res.json();
		} )
		.then( function ( data ) {
			if ( data.success ) {
				var ticketId = ( data.data && data.data.ticket_id ) ? data.data.ticket_id : '';
				handleSuccess( form, response, modal, ticketId );
			} else {
				var msg = ( data.data && typeof data.data === 'string' )
					? data.data
					: ( i18n.error || 'Submission failed. Please try again.' );
				handleError( response, msg, submitBtn, btnLabel, btnSpin );
				// Reset captcha widget on failure so user can re-verify.
				if ( captchaEl ) {
					resetCaptcha( captchaEl );
				}
			}
		} )
		.catch( function () {
			handleError(
				response,
				i18n.networkError || 'Network error. Please check your connection.',
				submitBtn,
				btnLabel,
				btnSpin
			);
			if ( captchaEl ) {
				resetCaptcha( captchaEl );
			}
		} );
	}

	/**
	 * Show the success state and auto-close the modal after 3 seconds.
	 *
	 * @param {HTMLFormElement} form
	 * @param {HTMLElement}     response
	 * @param {HTMLElement}     modal
	 * @param {string}          [ticketId]  Unique ticket ID returned by the server, if any.
	 */
	function handleSuccess( form, response, modal, ticketId ) {
		var i18n = ( window.psbdxSrm && psbdxSrm.i18n ) ? psbdxSrm.i18n : {};

		form.style.display = 'none';

		if ( response ) {
			response.innerHTML =
				'<div class="psbdx-success">' +
					'<div class="psbdx-success-icon">&#x2705;</div>' +
					'<h3>' + escapeHtml( i18n.submitted  || 'Report Submitted!'                        ) + '</h3>' +
					'<p>'  + escapeHtml( i18n.thankyou   || 'Thank you. We will get back to you soon.' ) + '</p>' +
					( ticketId
						? '<p class="psbdx-ticket-id">' + escapeHtml( i18n.ticketLabel || 'Your ticket ID:' ) + ' <code class="psbdx-ticket-code">' + escapeHtml( ticketId ) + '</code></p>'
						: ''
					) +
				'</div>';
		}

		// Auto-close and reset after 3 seconds.
		setTimeout( function () {
			if ( modal ) {
				closeModal( modal );
			}

			// Reset form state.
			form.reset();
			form.style.display = '';

			var submitBtn = form.querySelector( '.psbdx-submit-btn' );
			var btnLabel  = submitBtn ? submitBtn.querySelector( '.psbdx-btn-label' )  : null;
			var btnSpin   = submitBtn ? submitBtn.querySelector( '.psbdx-btn-spinner' ) : null;
			if ( submitBtn ) { submitBtn.disabled = false; }
			if ( btnLabel )  { btnLabel.style.display  = ''; }
			if ( btnSpin )   { btnSpin.style.display    = 'none'; }

			if ( response ) { response.innerHTML = ''; }

			// Reload the page if a user report table is present so it updates.
			if ( document.querySelector( '.psbdx-history-wrap' ) ) {
				window.location.reload();
			}
		}, 3000 );
	}

	/**
	 * Show an error notice and restore the submit button.
	 *
	 * @param {HTMLElement}     response
	 * @param {string}          message
	 * @param {HTMLElement}     submitBtn
	 * @param {HTMLElement}     btnLabel
	 * @param {HTMLElement}     btnSpin
	 */
	function handleError( response, message, submitBtn, btnLabel, btnSpin ) {
		if ( response ) {
			response.innerHTML =
				'<div class="psbdx-notice psbdx-notice-error">' + escapeHtml( message ) + '</div>';
		}

		if ( submitBtn ) { submitBtn.disabled = false; }
		if ( btnLabel )  { btnLabel.style.display  = ''; }
		if ( btnSpin )   { btnSpin.style.display    = 'none'; }
	}

	/**
	 * Escape HTML special characters in a string.
	 *
	 * @param  {string} str
	 * @return {string}
	 */
	function escapeHtml( str ) {
		return String( str )
			.replace( /&/g,  '&amp;'  )
			.replace( /</g,  '&lt;'   )
			.replace( />/g,  '&gt;'   )
			.replace( /"/g,  '&quot;' )
			.replace( /'/g,  '&#039;' );
	}

}() );

/* ─── V2 Builder: "Other" option dynamic reveal (1.3.0) ──────────────────── */
( function () {
	'use strict';

	/**
	 * Show/hide an "Other" text input when the matching option is chosen.
	 * Delegates from document so it works for dynamically-injected modals.
	 */

	// Select / Drop-down fields.
	document.addEventListener( 'change', function ( e ) {
		var el = e.target;

		if ( el.classList.contains( 'psrm-select-field' ) ) {
			var othersInput = document.querySelector(
				'.psrm-other-input[data-for="' + el.id + '"]'
			);
			if ( ! othersInput ) { return; }

			var show = el.value === '__other__';
			othersInput.style.display = show ? '' : 'none';

			var textInput = othersInput.querySelector( 'input[type="text"]' );
			if ( textInput ) {
				textInput.required = show;
				if ( ! show ) { textInput.value = ''; }
			}
		}

		// Radio fields.
		if ( el.classList.contains( 'psrm-radio-other-trigger' ) ) {
			var targetId = el.getAttribute( 'data-target' );
			if ( ! targetId ) { return; }
			var otherDiv = document.getElementById( targetId );
			if ( ! otherDiv ) { return; }

			otherDiv.style.display = el.checked ? '' : 'none';
			var rInput = otherDiv.querySelector( 'input[type="text"]' );
			if ( rInput ) { rInput.required = el.checked; }
		}

		// Make sure non-other radio selections hide the other box.
		if ( el.type === 'radio' && ! el.classList.contains( 'psrm-radio-other-trigger' ) ) {
			// Find sibling other-trigger in same group.
			var form   = el.closest( 'form' );
			if ( ! form ) { return; }
			var others = form.querySelectorAll(
				'input.psrm-radio-other-trigger[name="' + CSS.escape( el.name ) + '"]'
			);
			others.forEach( function ( otherRadio ) {
				var tid = otherRadio.getAttribute( 'data-target' );
				if ( ! tid ) { return; }
				var box = document.getElementById( tid );
				if ( box ) {
					box.style.display = 'none';
					var inp = box.querySelector( 'input[type="text"]' );
					if ( inp ) { inp.required = false; }
				}
			} );
		}

		// Checkbox fields.
		if ( el.classList.contains( 'psrm-checkbox-other-trigger' ) ) {
			var cbTargetId = el.getAttribute( 'data-target' );
			if ( ! cbTargetId ) { return; }
			var cbDiv = document.getElementById( cbTargetId );
			if ( ! cbDiv ) { return; }

			cbDiv.style.display = el.checked ? '' : 'none';
			var cbInput = cbDiv.querySelector( 'input[type="text"]' );
			if ( cbInput ) {
				cbInput.required = el.checked;
				if ( ! el.checked ) { cbInput.value = ''; }
			}
		}
	} );

	// ── FAQ search ([psbdx_faq]) ──────────────────────────────────────────────

	( function () {
		var input = document.getElementById( 'psbdx-faq-search-input' );
		var list  = document.getElementById( 'psbdx-faq-list' );
		if ( ! input || ! list ) {
			return;
		}

		var noResults = document.getElementById( 'psbdx-faq-no-results' );
		var items     = list.querySelectorAll( '.psbdx-faq-item' );

		input.addEventListener( 'input', function () {
			var term = input.value.trim().toLowerCase();
			var visibleCount = 0;

			items.forEach( function ( item ) {
				var text    = item.textContent.toLowerCase();
				var matches = '' === term || text.indexOf( term ) !== -1;
				item.hidden = ! matches;
				if ( matches ) { visibleCount++; }
			} );

			if ( noResults ) {
				noResults.hidden = visibleCount > 0;
			}
		} );
	}() );

	// ── Report detail page ([psbdx_ticket=...]) & reply threads ─────────────

	/**
	 * Resolves no sooner than `ms` milliseconds after being called, no
	 * matter how fast the wrapped promise settles — used so quick AJAX
	 * round-trips still show a deliberate, branded loading state instead
	 * of flashing instantly.
	 *
	 * @param {Promise} promise
	 * @param {number}  ms
	 * @return {Promise}
	 */
	function withMinDelay( promise, ms ) {
		var start = Date.now();
		return promise.then( function ( result ) {
			var remaining = ms - ( Date.now() - start );
			if ( remaining <= 0 ) {
				return result;
			}
			return new Promise( function ( resolve ) {
				setTimeout( function () { resolve( result ); }, remaining );
			} );
		} );
	}

	/**
	 * Submit a reply on the report detail page (owner, admin, or a
	 * guest verified by the email embedded in the form). Capture phase
	 * in case a theme or plugin script attached to the page stops the
	 * event before it bubbles up to this delegated listener.
	 */
	document.addEventListener( 'submit', function ( event ) {
		var form = event.target;
		if ( ! form.classList || ! form.classList.contains( 'psbdx-thread-reply-form' ) ) {
			return;
		}

		event.preventDefault();

		var cfg  = window.psbdxSrm || {};
		var i18n = cfg.i18n || {};

		var reportId  = form.getAttribute( 'data-report-id' );
		var email     = form.getAttribute( 'data-email' ) || '';
		var textarea  = form.querySelector( '.psbdx-thread-reply-input' );
		var fileInput = form.querySelector( '.psbdx-thread-reply-file' );
		var fileNameEl = form.querySelector( '.psbdx-thread-reply-file-name' );
		var sendBtn   = form.querySelector( '.psbdx-thread-reply-send' );
		var statusEl  = form.querySelector( '.psbdx-thread-reply-status' );
		var threadEl  = document.getElementById( 'psbdx-thread-' + reportId );

		var message = textarea ? textarea.value.trim() : '';
		var file    = ( fileInput && fileInput.files && fileInput.files[ 0 ] ) ? fileInput.files[ 0 ] : null;

		if ( '' === message && ! file ) {
			return;
		}

		if ( sendBtn ) { sendBtn.disabled = true; sendBtn.classList.add( 'is-loading' ); }
		if ( statusEl ) {
			statusEl.classList.remove( 'is-error' );
			statusEl.textContent = i18n.replySending || 'Submitting with PSBDx\u2026';
		}

		var body = new FormData();
		body.append( 'action', cfg.replyAction || 'psbdx_srm_submit_reply' );
		body.append( 'security', cfg.replyNonce || '' );
		body.append( 'report_id', reportId || '' );
		body.append( 'message', message );
		body.append( 'email', email );
		if ( file ) {
			body.append( 'reply_attachment', file );
		}

		withMinDelay(
			fetch( cfg.ajaxUrl, { method: 'POST', body: body, credentials: 'same-origin' } ).then( function ( r ) { return r.json(); } ),
			2000
		)
			.then( function ( data ) {
				if ( sendBtn ) { sendBtn.disabled = false; sendBtn.classList.remove( 'is-loading' ); }

				if ( data && data.success ) {
					if ( threadEl && data.data && data.data.thread_html ) {
						threadEl.outerHTML = data.data.thread_html;
					}
					if ( textarea ) { textarea.value = ''; }
					if ( fileInput ) { fileInput.value = ''; }
					if ( fileNameEl ) { fileNameEl.textContent = ''; }
					if ( statusEl ) { statusEl.textContent = ''; }
				} else {
					if ( statusEl ) {
						statusEl.classList.add( 'is-error' );
						statusEl.textContent = ( data && data.data && 'string' === typeof data.data )
							? data.data
							: ( i18n.replyError || 'Could not send your reply. Please try again.' );
					}
				}
			} )
			.catch( function () {
				if ( sendBtn ) { sendBtn.disabled = false; sendBtn.classList.remove( 'is-loading' ); }
				if ( statusEl ) {
					statusEl.classList.add( 'is-error' );
					statusEl.textContent = i18n.networkError || 'Network error. Please try again.';
				}
			} );
	}, true );

	/**
	 * Show the selected filename next to the reply form's attach button.
	 */
	document.addEventListener( 'change', function ( e ) {
		if ( ! e.target.classList || ! e.target.classList.contains( 'psbdx-thread-reply-file' ) ) {
			return;
		}
		var form = e.target.closest( '.psbdx-thread-reply-form' );
		if ( ! form ) { return; }
		var nameEl = form.querySelector( '.psbdx-thread-reply-file-name' );
		if ( ! nameEl ) { return; }
		var file = e.target.files && e.target.files[ 0 ];
		nameEl.textContent = file ? file.name : '';
	} );

	/**
	 * Poll the report detail page's thread for new messages while the page
	 * is open, so a reply from the other side (admin or reporter) shows up
	 * on its own instead of needing a manual reload. Only runs on pages
	 * that actually have a thread, and pauses while the tab is hidden.
	 */
	( function () {
		var threadEl = document.querySelector( '.psbdx-thread[id^="psbdx-thread-"]' );
		if ( ! threadEl ) {
			return;
		}

		var reportId  = threadEl.id.replace( 'psbdx-thread-', '' );
		var replyForm = document.querySelector( '.psbdx-thread-reply-form[data-report-id="' + reportId + '"]' );
		var email     = replyForm ? ( replyForm.getAttribute( 'data-email' ) || '' ) : '';
		var cfg       = window.psbdxSrm || {};

		function poll() {
			if ( document.hidden ) {
				return;
			}

			var current   = document.getElementById( 'psbdx-thread-' + reportId );
			var lastCount = current ? parseInt( current.getAttribute( 'data-count' ) || '0', 10 ) : 0;

			var body = new URLSearchParams();
			body.append( 'action', cfg.pollAction || 'psbdx_srm_poll_thread' );
			body.append( 'security', cfg.pollNonce || '' );
			body.append( 'report_id', reportId );
			body.append( 'email', email );

			fetch( cfg.ajaxUrl, { method: 'POST', body: body, credentials: 'same-origin' } )
				.then( function ( r ) { return r.json(); } )
				.then( function ( data ) {
					if ( ! data || ! data.success || ! data.data ) {
						return;
					}
					var el = document.getElementById( 'psbdx-thread-' + reportId );
					if ( el && data.data.count !== lastCount && data.data.thread_html ) {
						el.outerHTML = data.data.thread_html;
					}
				} )
				.catch( function () {
					// Silent — a missed poll just tries again next interval.
				} );
		}

		setInterval( poll, 7000 );
	}() );

	// ── URL popup overlay ─────────────────────────────────────────────────
	// Turns any URL on the site into a trigger for a form's report modal:
	// appending "?" followed by a form's ID to the very end of a URL
	// (e.g. https://example.com/any-page/?123) opens that form as an
	// overlay on top of whatever page is already loaded — the page itself
	// never navigates or reloads, this just fetches the modal markup and
	// shows it. See PSBDX_SRM_Popup_Link for the per-form opt-in and
	// PSBDX_SRM_Ajax::handle_get_popup_form() for the endpoint.
	//
	// The match is deliberately lenient about *where* the bare number sits:
	// some free hosts (InfinityFree-family ones like *.rf.gd in particular)
	// inject their own tracking query params into every page, which would
	// break a strict "?123 and nothing else" match. Instead this looks for
	// any standalone "key" with no "=" in the query string that's purely
	// digits, wherever it falls among other params.
	( function () {
		var query  = window.location.search.replace( /^\?/, '' );
		var formId = null;

		if ( query ) {
			query.split( '&' ).forEach( function ( part ) {
				if ( /^\d+$/.test( part ) ) {
					formId = part;
				}
			} );
		}

		if ( ! formId ) {
			return;
		}

		var cfg = window.psbdxSrm || {};
		if ( ! cfg.ajaxUrl || ! cfg.popupAction ) {
			return;
		}

		var url = cfg.ajaxUrl + '?action=' + encodeURIComponent( cfg.popupAction ) + '&form_id=' + encodeURIComponent( formId );

		fetch( url, { credentials: 'same-origin' } )
			.then( function ( r ) { return r.json(); } )
			.then( function ( data ) {
				if ( ! data || ! data.success || ! data.data || ! data.data.html ) {
					if ( window.console && window.console.warn ) {
						window.console.warn(
							'PSBDx popup link: form ' + formId + ' did not open — ' +
							( ( data && data.data ) || 'either the form doesn\'t exist/isn\'t published, or "Enable popup link" isn\'t checked for it under the form\'s Settings tab.' )
						);
					}
					return;
				}

				var holder = document.createElement( 'div' );
				holder.innerHTML = data.data.html;

				var modal = holder.querySelector( '.psbdx-modal' );
				if ( ! modal ) {
					return;
				}

				document.body.appendChild( modal );

				if ( typeof window.psbdxSrmOpenModal === 'function' ) {
					window.psbdxSrmOpenModal( modal );
				}

				// Tidy the address bar now that the overlay is showing —
				// this does not navigate or reload the page, it only
				// removes the "?123" suffix so refreshing/sharing the
				// URL afterward doesn't keep re-triggering the popup.
				if ( window.history && window.history.replaceState ) {
					window.history.replaceState( null, '', window.location.pathname + window.location.hash );
				}
			} )
			.catch( function ( err ) {
				if ( window.console && window.console.warn ) {
					window.console.warn( 'PSBDx popup link: request failed', err );
				}
			} );
	}() );

}() );
