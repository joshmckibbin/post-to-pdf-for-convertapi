/**
 * "Download as PDF" link: generate the PDF in the background with a status message,
 * then start the download. Without JavaScript the link downloads directly.
 */
( function () {
	var config = window.ptpdfFrontend || {};
	var i18n = config.i18n || {};
	var SLOW_AFTER_MS = 8000;

	function setStatus( wrap, text, state ) {
		var status = wrap.querySelector( '.ptpdf-status' );
		wrap.classList.remove( 'is-busy', 'is-error' );
		if ( state ) {
			wrap.classList.add( 'is-' + state );
		}
		if ( status ) {
			status.textContent = text || '';
		}
	}

	function finish( link, wrap ) {
		link.removeAttribute( 'aria-disabled' );
		wrap.classList.remove( 'is-busy' );
	}

	document.addEventListener( 'click', function ( event ) {
		var link = event.target.closest && event.target.closest( 'a[data-ptpdf-post]' );
		// Let modified clicks (new tab, etc.) and pages without config behave like a normal link.
		if ( ! link || ! config.ajaxUrl || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey ) {
			return;
		}
		event.preventDefault();

		var wrap = link.closest( '.ptpdf-download' ) || link.parentNode;
		if ( link.getAttribute( 'aria-disabled' ) === 'true' ) {
			return;
		}
		link.setAttribute( 'aria-disabled', 'true' );
		setStatus( wrap, i18n.preparing, 'busy' );

		var slowTimer = setTimeout( function () {
			setStatus( wrap, i18n.slow, 'busy' );
		}, SLOW_AFTER_MS );

		var body = new URLSearchParams();
		body.append( 'action', config.action || 'ptpdf_prepare' );
		body.append( 'post_id', link.getAttribute( 'data-ptpdf-post' ) );

		fetch( config.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { Accept: 'application/json' },
			body: body,
		} )
			.then( function ( response ) {
				return response.json().then(
					function ( json ) {
						// admin-ajax answers a bare 0 / -1 when the action is unknown or blocked.
						if ( ! json || typeof json !== 'object' ) {
							throw new Error( 'fallback' );
						}
						return { ok: response.ok && json.success === true, body: json.data };
					},
					function () {
						// Not JSON: admin-ajax is blocked or broken. Fall back to the plain link.
						throw new Error( 'fallback' );
					}
				);
			} )
			.then( function ( result ) {
				clearTimeout( slowTimer );
				finish( link, wrap );

				if ( result.ok && result.body && result.body.url ) {
					setStatus( wrap, i18n.starting );
					// The file is sent as an attachment, so the page stays put.
					window.location.assign( result.body.url );
					setTimeout( function () {
						setStatus( wrap, '' );
					}, 5000 );
					return;
				}

				var message = ( result.body && result.body.message ) || i18n.error;
				var detail = result.body && result.body.detail;
				setStatus( wrap, detail ? message + ' (' + detail + ')' : message, 'error' );
			} )
			.catch( function () {
				clearTimeout( slowTimer );
				finish( link, wrap );
				setStatus( wrap, '' );
				window.location.assign( link.href );
			} );
	} );
} )();
