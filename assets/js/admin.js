/**
 * PSBDx Smart Report Management — Admin Scripts
 *
 * @package PSBDx_Smart_Report_Management
 * @since   1.0.0
 */

/* global navigator */
( function () {
	'use strict';

	/**
	 * Initialise shortcode copy buttons.
	 *
	 * @since 1.0.0
	 */
	function initCopyButtons() {
		var buttons = document.querySelectorAll( '.psbdx-copy-btn' );

		buttons.forEach( function ( btn ) {
			btn.addEventListener( 'click', function () {
				var targetId = btn.getAttribute( 'data-target' );
				var codeEl   = document.getElementById( targetId );

				if ( ! codeEl ) {
					return;
				}

				var text = ( 'value' in codeEl && codeEl.value ) ? codeEl.value : ( codeEl.innerText || codeEl.textContent );

				if ( navigator.clipboard && navigator.clipboard.writeText ) {
					navigator.clipboard.writeText( text ).then( function () {
						flashCopied( btn );
					} ).catch( function () {
						fallbackCopy( text, btn );
					} );
				} else {
					fallbackCopy( text, btn );
				}
			} );
		} );
	}

	/**
	 * Fallback copy using execCommand (older browsers).
	 *
	 * @since  1.0.0
	 * @param  {string}      text  Text to copy.
	 * @param  {HTMLElement} btn   The button element.
	 */
	function fallbackCopy( text, btn ) {
		var textarea       = document.createElement( 'textarea' );
		textarea.value     = text;
		textarea.style.top = '0';
		textarea.style.left = '0';
		textarea.style.position = 'fixed';
		document.body.appendChild( textarea );
		textarea.focus();
		textarea.select();

		try {
			document.execCommand( 'copy' );
			flashCopied( btn );
		} catch ( err ) {
			// Silent fail — browser does not support copy.
		}

		document.body.removeChild( textarea );
	}

	/**
	 * Briefly show a "Copied!" label on the button.
	 *
	 * @since  1.0.0
	 * @param  {HTMLElement} btn  The button element.
	 */
	function flashCopied( btn ) {
		var original = btn.textContent;
		btn.textContent = '✓ Copied!';
		setTimeout( function () {
			btn.textContent = original;
		}, 2000 );
	}

	// Bootstrap on DOM ready.
	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', initCopyButtons );
	} else {
		initCopyButtons();
	}
}() );
