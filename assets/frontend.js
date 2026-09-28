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
		if ( ! link || ! config.restUrl || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey ) {
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

		var url = config.restUrl + link.getAttribute( 'data-ptpdf-post' );

		function prepare( nonce ) {
			var headers = { Accept: 'application/json' };
			if ( nonce ) {
				headers[ 'X-WP-Nonce' ] = nonce;
			}

			return fetch( url, {
				method: 'POST',
				credentials: 'same-origin',
				headers: headers,
			} ).then( function ( response ) {
				return response.json().then(
					function ( body ) {
						return { ok: response.ok, body: body };
					},
					function () {
						// Not JSON: the REST API is blocked or broken. Fall back to the plain link.
						throw new Error( 'fallback' );
					}
				);
			} );
		}

		prepare( config.nonce )
			.then( function ( result ) {
				// A stale nonce (cached page, long-open tab, re-login) makes core reject the request
				// with "Cookie check failed". The route is public, so retry anonymously.
				if ( config.nonce && ! result.ok && result.body && result.body.code === 'rest_cookie_invalid_nonce' ) {
					return prepare( null );
				}
				return result;
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
				var detail = result.body && result.body.data && result.body.data.detail;
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
