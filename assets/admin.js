/* global keepitpeaceAdmin */
( function () {
	'use strict';

	var config = window.keepitpeaceAdmin;
	var button = document.getElementById( 'kip-scan-now' );
	var box = document.getElementById( 'kip-scan-progress' );
	if ( ! config || ! box ) {
		return;
	}
	var bar = box.querySelector( '.kip-progress-bar span' );
	var text = box.querySelector( '.kip-progress-text' );

	function format( template, value ) {
		return template.replace( '%d', value ).replace( '%s', value ).replace( '%%', '%' );
	}

	function show( progress, message ) {
		box.hidden = false;
		bar.style.width = Math.max( 0, Math.min( 100, progress ) ) + '%';
		text.textContent = message;
	}

	function request( path, method ) {
		return window.fetch( config.root + path, {
			method: method,
			credentials: 'same-origin',
			headers: { 'X-WP-Nonce': config.nonce, 'Content-Type': 'application/json' }
		} ).then( function ( response ) {
			if ( ! response.ok ) {
				throw new Error( 'HTTP ' + response.status );
			}
			return response.json();
		} );
	}

	function step() {
		request( 'scans/step', 'POST' ).then( function ( data ) {
			if ( 'completed' === data.status ) {
				show( 100, config.i18n.done );
				window.setTimeout( function () { window.location.reload(); }, 800 );
				return;
			}
			if ( 'failed' === data.status ) {
				show( 0, config.i18n.failed );
				if ( button ) {
					button.disabled = false;
				}
				return;
			}
			if ( 'busy' === data.status ) {
				show( data.progress || 0, config.i18n.busy );
				window.setTimeout( step, 3000 );
				return;
			}
			if ( 'idle' === data.status ) {
				window.location.reload();
				return;
			}
			show( data.progress, format( config.i18n.running, data.progress ) );
			step();
		} ).catch( function ( error ) {
			show( 0, format( config.i18n.error, error.message ) );
			if ( button ) {
				button.disabled = false;
			}
		} );
	}

	function start() {
		if ( button ) {
			button.disabled = true;
		}
		show( 0, config.i18n.starting );
		request( 'scans', 'POST' ).then( function ( data ) {
			show( data.progress || 0, format( config.i18n.running, data.progress || 0 ) );
			step();
		} ).catch( function ( error ) {
			show( 0, format( config.i18n.error, error.message ) );
			if ( button ) {
				button.disabled = false;
			}
		} );
	}

	if ( button ) {
		button.addEventListener( 'click', start );
	}
	// Resume progress display when a scan is already running.
	if ( '1' === box.getAttribute( 'data-running' ) ) {
		if ( button ) {
			button.disabled = true;
		}
		step();
	}
}() );
