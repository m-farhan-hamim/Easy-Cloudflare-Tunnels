/**
 * PSBDx Smart Report Management — Setup Wizard step navigation.
 *
 * Purely client-side (show/hide + a progress bar); all step values are
 * submitted together in one request when the admin reaches "Finish Setup".
 *
 * @package PSBDx_Smart_Report_Management
 * @since   1.4.5
 */
( function () {
	'use strict';

	function ready( fn ) {
		if ( document.readyState === 'loading' ) {
			document.addEventListener( 'DOMContentLoaded', fn );
		} else {
			fn();
		}
	}

	ready( function () {
		var steps    = Array.prototype.slice.call( document.querySelectorAll( '.psrm-wizard-step' ) );
		var total    = steps.length;
		var current  = 1;

		var backBtn   = document.getElementById( 'psrm-wizard-back' );
		var nextBtn   = document.getElementById( 'psrm-wizard-next' );
		var finishBtn = document.getElementById( 'psrm-wizard-finish' );
		var fill      = document.getElementById( 'psrm-wizard-progress-fill' );
		var progress  = document.getElementById( 'psrm-wizard-progress' );

		if ( ! steps.length || ! backBtn || ! nextBtn || ! finishBtn ) {
			return;
		}

		function show( n ) {
			current = Math.min( Math.max( n, 1 ), total );

			steps.forEach( function ( step ) {
				var stepNum = parseInt( step.getAttribute( 'data-step' ), 10 );
				step.classList.toggle( 'psrm-wizard-step-active', stepNum === current );
			} );

			backBtn.style.visibility = ( current === 1 ) ? 'hidden' : 'visible';

			var isLast = ( current === total );
			nextBtn.style.display   = isLast ? 'none' : '';
			finishBtn.style.display = isLast ? '' : 'none';

			var pct = Math.round( ( current / total ) * 100 );
			if ( fill ) {
				fill.style.width = pct + '%';
			}
			if ( progress ) {
				progress.setAttribute( 'aria-valuenow', current );
			}

			var focusTarget = steps[ current - 1 ].querySelector( 'h1, h2' );
			if ( focusTarget ) {
				focusTarget.setAttribute( 'tabindex', '-1' );
				focusTarget.focus();
			}
		}

		nextBtn.addEventListener( 'click', function () {
			show( current + 1 );
		} );

		backBtn.addEventListener( 'click', function () {
			show( current - 1 );
		} );

		show( 1 );

		// ── Send test email (Mailing step) ──────────────────────────────
		var testBtn    = document.getElementById( 'psrm-wizard-test-email-btn' );
		var testResult = document.getElementById( 'psrm-wizard-test-email-result' );
		var cfg        = window.psbdxSrmWizard || {};

		if ( testBtn && testResult && cfg.ajaxUrl ) {
			testBtn.addEventListener( 'click', function () {
				testBtn.disabled = true;
				testResult.className = '';
				testResult.textContent = testBtn.getAttribute( 'data-sending-label' ) || '…';

				var body = new URLSearchParams();
				body.set( 'action', cfg.testAction );
				body.set( 'security', cfg.nonce );

				fetch( cfg.ajaxUrl, {
					method: 'POST',
					credentials: 'same-origin',
					headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
					body: body.toString(),
				} )
					.then( function ( r ) { return r.json(); } )
					.then( function ( data ) {
						testBtn.disabled = false;
						if ( data && data.success ) {
							testResult.className = 'psrm-test-ok';
							testResult.textContent = data.data.message;
						} else {
							testResult.className = 'psrm-test-error';
							testResult.textContent = ( data && data.data ) ? data.data : 'Something went wrong.';
						}
					} )
					.catch( function () {
						testBtn.disabled = false;
						testResult.className = 'psrm-test-error';
						testResult.textContent = 'Something went wrong.';
					} );
			} );
		}
	} );
}() );
