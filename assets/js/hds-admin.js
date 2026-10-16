/**
 * Smart Document Search – admin UI (vanilla ES2017, no dependencies).
 *
 * Reads window.hdsAdmin (printed by HDS_Admin::enqueue_assets()):
 * { ajaxUrl, nonce, page, maxUploadBytes, maxUploadLabel, postMaxBytes,
 *   postMaxLabel, maxRetries, demoLimit, i18n: { ... } }.
 *
 * - "Update index": hds_index_start, then hds_index_step until done, with a
 *   progress bar, a log and live table rows; failed HTTP requests are
 *   retried with backoff.
 * - Delete confirmation, inline rename toggle, upload size pre-check.
 * - "Test connection" on the Settings page.
 *
 * Server text is only ever inserted with textContent.
 *
 * @package SmartDocumentSearch
 */
( function () {
	'use strict';

	const config = window.hdsAdmin || {};
	const i18n = config.i18n || {};

	const RETRY_DELAYS = [ 2000, 5000, 10000 ];
	const MAX_LOG_ITEMS = 300;
	const STALL_LIMIT = 150; // Identical step results in a row before giving up.
	const WAIT_MS = 15000; // Wait for a step that may still run (or hold the lock) after a failure.
	const MAX_WAITS = 9;

	/**
	 * printf-style formatting: %s, %d, %1$s, %2$d, %%.
	 *
	 * @param {string} template Template.
	 * @param {Array}  args     Values.
	 * @return {string} Text.
	 */
	function format( template, args ) {
		let next = 0;
		return String( template ).replace( /%(?:(\d+)\$)?([sd%])/g, function ( match, position, type ) {
			if ( '%' === type ) {
				return '%';
			}
			const index = position ? parseInt( position, 10 ) - 1 : next++;
			const value = args[ index ];
			if ( 'd' === type ) {
				const number = parseInt( value, 10 );
				return String( isNaN( number ) ? 0 : number );
			}
			return undefined === value || null === value ? '' : String( value );
		} );
	}

	/**
	 * Translated string from window.hdsAdmin.i18n.
	 *
	 * @param {string} key     Key.
	 * @param {...*}   args    Values for the placeholders.
	 * @return {string} Text.
	 */
	function t( key, ...args ) {
		const template = Object.prototype.hasOwnProperty.call( i18n, key ) ? i18n[ key ] : key;
		return format( template, args );
	}

	function toInt( value ) {
		const number = parseInt( value, 10 );
		return isNaN( number ) ? 0 : number;
	}

	function sleep( ms ) {
		return new Promise( function ( resolve ) {
			window.setTimeout( resolve, ms );
		} );
	}

	/**
	 * Error of an AJAX request.
	 */
	class RequestError extends Error {
		/**
		 * @param {string}  message   Message for the admin.
		 * @param {boolean} retryable Whether repeating the request may help.
		 * @param {number}  status    HTTP status (0 = none).
		 */
		constructor( message, retryable, status ) {
			super( message );
			this.name = 'RequestError';
			this.retryable = retryable;
			this.status = status || 0;
		}
	}

	/**
	 * POST an admin-ajax action.
	 *
	 * @param {string} action Action name.
	 * @return {Promise<Object>} The `data` of a wp_send_json_success() response.
	 */
	async function ajax( action ) {
		const body = new FormData();
		body.append( 'action', action );
		body.append( 'nonce', config.nonce || '' );

		let response;
		try {
			response = await window.fetch( config.ajaxUrl, {
				method: 'POST',
				body: body,
				credentials: 'same-origin',
				cache: 'no-store',
				headers: { 'X-Requested-With': 'XMLHttpRequest' },
			} );
		} catch ( error ) {
			throw new RequestError( t( 'networkError' ), true, 0 );
		}

		let text = '';
		try {
			text = await response.text();
		} catch ( error ) {
			throw new RequestError( t( 'networkError' ), true, response.status );
		}

		let json = null;
		try {
			json = JSON.parse( text );
		} catch ( error ) {
			json = null;
		}

		if ( json && 'object' === typeof json && 'boolean' === typeof json.success ) {
			if ( json.success ) {
				return json.data && 'object' === typeof json.data ? json.data : {};
			}
			const message = json.data && 'string' === typeof json.data.message && json.data.message ? json.data.message : t( 'genericError' );
			throw new RequestError( message, false, response.status );
		}

		const trimmed = text.trim();
		if ( 403 === response.status || '-1' === trimmed || '0' === trimmed ) {
			// Expired nonce (-1) or logged out (0: no wp_ajax_nopriv_ handler).
			throw new RequestError( t( 'sessionExpired' ), false, response.status );
		}
		// PHP fatal error / time limit, proxy timeout, HTML error page...
		throw new RequestError( t( 'genericError' ), true, response.status );
	}

	/**
	 * ajax() with up to config.maxRetries retries of retryable failures.
	 *
	 * @param {string}   action  Action name.
	 * @param {Function} onRetry Called with ( delayMs, attempt, maxAttempts ) before each retry.
	 * @return {Promise<Object>} Response data.
	 */
	async function ajaxWithRetry( action, onRetry ) {
		const maxRetries = Math.max( 0, undefined === config.maxRetries ? 3 : toInt( config.maxRetries ) );
		for ( let attempt = 0; ; attempt++ ) {
			try {
				return await ajax( action );
			} catch ( error ) {
				if ( ! error.retryable || attempt >= maxRetries ) {
					throw error;
				}
				const delay = RETRY_DELAYS[ Math.min( attempt, RETRY_DELAYS.length - 1 ) ];
				if ( onRetry ) {
					onRetry( delay, attempt + 1, maxRetries );
				}
				await sleep( delay );
			}
		}
	}

	/* ------------------------------------------------------------------
	 * Indexing
	 * ---------------------------------------------------------------- */

	function initIndex() {
		const button = document.getElementById( 'hds-index-button' );
		const progress = document.getElementById( 'hds-progress' );
		const log = document.getElementById( 'hds-index-log' );
		if ( ! button || ! progress || ! log ) {
			return;
		}
		const bar = progress.querySelector( '.hds-progress__bar' );
		const percentText = progress.querySelector( '.hds-progress__text' );
		const current = document.getElementById( 'hds-progress-current' );
		const table = document.getElementById( 'hds-docs-table' );
		let running = false;

		function setProgress( value ) {
			const percent = Math.max( 0, Math.min( 100, Math.round( Number( value ) || 0 ) ) );
			const label = t( 'percent', percent );
			progress.hidden = false;
			progress.setAttribute( 'aria-valuenow', String( percent ) );
			progress.setAttribute( 'aria-valuetext', label );
			if ( bar ) {
				bar.style.width = percent + '%';
			}
			if ( percentText ) {
				percentText.textContent = label;
			}
		}

		function setCurrent( text ) {
			if ( current ) {
				current.textContent = text || '';
			}
		}

		function addLog( message, type ) {
			if ( ! message ) {
				return;
			}
			// The server may repeat a line (e.g. an error in both `messages` and `error`).
			const last = log.lastElementChild;
			if ( last && last.textContent === String( message ) ) {
				return;
			}
			const item = document.createElement( 'li' );
			item.className = 'hds-log__item hds-log__item--' + ( type || 'info' );
			item.dir = 'ltr' === config.textDir ? 'ltr' : 'auto';
			item.textContent = String( message );
			log.appendChild( item );
			while ( log.children.length > MAX_LOG_ITEMS ) {
				log.removeChild( log.firstElementChild );
			}
			log.scrollTop = log.scrollHeight;
		}

		function onRetry( delay, attempt, max ) {
			addLog( t( 'retrying', Math.round( delay / 1000 ), attempt, max ), 'warning' );
		}

		function onBeforeUnload( event ) {
			if ( ! running ) {
				return undefined;
			}
			event.preventDefault();
			event.returnValue = t( 'confirmLeave' );
			return event.returnValue;
		}

		function updateRow( item ) {
			if ( ! table || ! item || 'object' !== typeof item ) {
				return;
			}
			const id = toInt( item.id );
			if ( id <= 0 ) {
				return;
			}
			const row = table.querySelector( 'tr[data-doc-id="' + id + '"]' );
			if ( ! row ) {
				return;
			}
			const status = String( item.status || '' ).toLowerCase().replace( /[^a-z0-9_]/g, '' );
			row.setAttribute( 'data-status', status );

			const pill = row.querySelector( '[data-hds-status]' );
			const label = String( item.status_label || status );
			if ( pill ) {
				pill.className = 'hds-status hds-status--' + ( status || 'unknown' );
				pill.textContent = label;
			}
			const message = row.querySelector( '[data-hds-status-message]' );
			if ( message ) {
				const text = item.status_message ? String( item.status_message ) : '';
				message.textContent = text && -1 === label.indexOf( text ) ? text : '';
				message.hidden = '' === message.textContent;
			}
			const chunks = row.querySelector( '[data-hds-chunks]' );
			if ( chunks && undefined !== item.chunk_count && null !== item.chunk_count ) {
				chunks.textContent = toInt( item.chunk_count ).toLocaleString( document.documentElement.lang || undefined );
			}
		}

		/**
		 * Log the result of hds_index_start and update the rows it touched.
		 *
		 * @param {Object} data Response data.
		 * @return {string} 'continue' (documents queued), 'done' (nothing to index) or 'stop'.
		 */
		function reportStart( data ) {
			const messages = Array.isArray( data.messages ) ? data.messages : [];
			const updated = Array.isArray( data.updated ) ? data.updated : [];
			updated.forEach( updateRow );
			messages.forEach( function ( message ) {
				addLog( message );
			} );

			if ( data.error ) {
				addLog( data.error, 'error' );
				addLog( t( data.fatal ? 'fatal' : 'resumable' ) );
				return 'stop';
			}

			const total = toInt( data.total );
			if ( ! messages.length ) {
				const skipped = toInt( data.skipped );
				const locked = toInt( data.locked );
				if ( total > 0 ) {
					addLog( t( 'queued', total ) );
				}
				if ( skipped > 0 ) {
					addLog( t( 'skipped', skipped ) );
				}
				if ( locked > 0 ) {
					addLog( t( 'locked', locked, toInt( config.demoLimit ) ), 'warning' );
				}
				if ( 0 === total ) {
					addLog( t( 'nothingToDo' ), 'success' );
				}
			}
			return total > 0 ? 'continue' : 'done';
		}

		/**
		 * Run steps until the queue is done or a step reports an error.
		 *
		 * @return {Promise<boolean>} True when indexing finished.
		 */
		async function loop() {
			let recovering = false;
			let waits = 0;
			let stalls = 0;
			let lastKey = '';

			for ( ;; ) {
				const data = await ajaxWithRetry( 'hds_index_step', function ( delay, attempt, max ) {
					recovering = true;
					onRetry( delay, attempt, max );
				} );

				const messages = Array.isArray( data.messages ) ? data.messages : [];
				const updated = Array.isArray( data.updated ) ? data.updated : [];
				messages.forEach( function ( message ) {
					addLog( message );
				} );
				updated.forEach( updateRow );

				if ( data.error ) {
					// After a failed request the previous step may still be running (or its lock
					// still held): wait for it instead of giving up at once.
					if ( ! data.fatal && recovering && waits < MAX_WAITS ) {
						if ( 0 === waits ) {
							addLog( t( 'waiting' ) );
						}
						waits++;
						await sleep( WAIT_MS );
						continue;
					}
					addLog( data.error, 'error' );
					addLog( t( data.fatal ? 'fatal' : 'resumable' ) );
					return false;
				}
				recovering = false;
				waits = 0;

				if ( data.done ) {
					return true;
				}

				let percent = Number( data.percent );
				if ( ! isFinite( percent ) ) {
					const total = toInt( data.total );
					percent = total > 0 ? ( toInt( data.processed ) / total ) * 100 : 0;
				}
				// 100 % is shown only once the run is really finished.
				setProgress( Math.min( percent, 99 ) );
				setCurrent( data.current ? t( 'current', data.current ) : '' );

				const key = [ data.processed, data.percent, data.current ].join( '|' );
				stalls = key === lastKey && ! messages.length && ! updated.length ? stalls + 1 : 0;
				lastKey = key;
				if ( stalls >= STALL_LIMIT ) {
					addLog( t( 'stalled' ), 'error' );
					return false;
				}
			}
		}

		function setRunning( state ) {
			running = state;
			button.disabled = state;
			if ( state ) {
				button.setAttribute( 'aria-busy', 'true' );
				window.addEventListener( 'beforeunload', onBeforeUnload );
			} else {
				button.removeAttribute( 'aria-busy' );
				window.removeEventListener( 'beforeunload', onBeforeUnload );
			}
		}

		async function run() {
			if ( running ) {
				return;
			}
			setRunning( true );
			log.textContent = '';
			progress.classList.remove( 'is-error', 'is-done' );
			setProgress( 0 );
			setCurrent( '' );

			let finished = false;
			let indexed = false;
			try {
				addLog( t( 'starting' ) );
				const state = reportStart( await ajaxWithRetry( 'hds_index_start', onRetry ) );
				if ( 'continue' === state ) {
					finished = await loop();
					indexed = finished;
				} else {
					finished = 'done' === state;
				}
			} catch ( error ) {
				addLog( ( error && error.message ) || t( 'genericError' ), 'error' );
			}

			setRunning( false );
			setCurrent( '' );
			if ( finished ) {
				setProgress( 100 );
				progress.classList.add( 'is-done' );
				if ( indexed ) {
					addLog( t( 'done' ), 'success' );
				}
				const notice = document.querySelector( '.hds-notice-reindex' );
				if ( notice ) {
					notice.hidden = true;
				}
			} else {
				progress.classList.add( 'is-error' );
			}
		}

		button.addEventListener( 'click', function () {
			run();
		} );
	}

	/* ------------------------------------------------------------------
	 * Documents table: delete confirmation, inline rename
	 * ---------------------------------------------------------------- */

	function setRenameOpen( toggle, form, open ) {
		form.classList.toggle( 'is-open', open );
		if ( toggle ) {
			toggle.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
		}
		if ( open ) {
			const input = form.querySelector( '.hds-rename-input' );
			if ( input ) {
				input.focus();
				input.select();
			}
		}
	}

	function closeRename( form ) {
		const toggle = document.querySelector( '.hds-rename-toggle[aria-controls="' + form.id + '"]' );
		const input = form.querySelector( '.hds-rename-input' );
		if ( input ) {
			input.value = input.defaultValue;
		}
		setRenameOpen( toggle, form, false );
		if ( toggle ) {
			toggle.focus();
		}
	}

	function initTable() {
		document.addEventListener( 'submit', function ( event ) {
			const form = event.target;
			if ( ! ( form instanceof HTMLFormElement ) ) {
				return;
			}
			if ( form.classList.contains( 'hds-delete-form' ) ) {
				const message = form.getAttribute( 'data-hds-confirm' );
				if ( message && ! window.confirm( message ) ) {
					event.preventDefault();
				}
				return;
			}
			if ( form.classList.contains( 'hds-rename-form' ) ) {
				const input = form.querySelector( '.hds-rename-input' );
				if ( input && '' === input.value.trim() ) {
					event.preventDefault();
					input.focus();
				}
			}
		} );

		document.addEventListener( 'click', function ( event ) {
			const target = event.target instanceof Element ? event.target : null;
			if ( ! target ) {
				return;
			}
			const toggle = target.closest( '.hds-rename-toggle' );
			if ( toggle ) {
				const form = document.getElementById( toggle.getAttribute( 'aria-controls' ) || '' );
				if ( form ) {
					if ( form.classList.contains( 'is-open' ) ) {
						closeRename( form );
					} else {
						setRenameOpen( toggle, form, true );
					}
				}
				return;
			}
			const cancel = target.closest( '.hds-rename-cancel' );
			if ( cancel ) {
				const form = cancel.closest( '.hds-rename-form' );
				if ( form ) {
					closeRename( form );
				}
			}
		} );

		document.addEventListener( 'keydown', function ( event ) {
			if ( 'Escape' !== event.key || ! ( event.target instanceof Element ) ) {
				return;
			}
			const form = event.target.closest( '.hds-rename-form' );
			if ( form && form.classList.contains( 'is-open' ) ) {
				event.preventDefault();
				closeRename( form );
			}
		} );
	}

	/* ------------------------------------------------------------------
	 * Upload form: size pre-check, busy state
	 * ---------------------------------------------------------------- */

	function initUpload() {
		const form = document.getElementById( 'hds-upload-form' );
		const input = document.getElementById( 'hds-files' );
		if ( ! form || ! input ) {
			return;
		}
		const errorBox = document.getElementById( 'hds-upload-error' );
		const submit = form.querySelector( '[type="submit"]' );
		const submitLabel = submit ? submit.textContent : '';

		function showError( messages ) {
			if ( ! errorBox ) {
				return;
			}
			errorBox.textContent = messages.join( ' ' );
			errorBox.hidden = 0 === messages.length;
		}

		function reset() {
			if ( submit ) {
				submit.disabled = false;
				submit.textContent = submitLabel;
			}
			form.removeAttribute( 'aria-busy' );
		}

		input.addEventListener( 'change', function () {
			showError( [] );
		} );

		form.addEventListener( 'submit', function ( event ) {
			const files = input.files ? Array.prototype.slice.call( input.files ) : [];
			const maxBytes = toInt( config.maxUploadBytes );
			const postMax = toInt( config.postMaxBytes );
			const problems = [];
			let total = 0;

			if ( ! files.length ) {
				problems.push( t( 'noFiles' ) );
			}
			files.forEach( function ( file ) {
				total += file.size;
				if ( maxBytes > 0 && file.size > maxBytes ) {
					problems.push( t( 'fileTooLarge', file.name, config.maxUploadLabel || '' ) );
				}
			} );
			if ( postMax > 0 && total > postMax ) {
				problems.push( t( 'totalTooLarge', config.postMaxLabel || '' ) );
			}

			if ( problems.length ) {
				event.preventDefault();
				showError( problems );
				input.focus();
				return;
			}
			showError( [] );
			form.setAttribute( 'aria-busy', 'true' );
			if ( submit ) {
				// Disabled after the submit event started: the form is still sent.
				window.setTimeout( function () {
					submit.disabled = true;
					submit.textContent = t( 'uploading' );
				}, 0 );
			}
		} );

		// Back/forward cache: do not come back with a disabled button.
		window.addEventListener( 'pageshow', reset );
	}

	/* ------------------------------------------------------------------
	 * Settings: Test connection
	 * ---------------------------------------------------------------- */

	function initTestApi() {
		const button = document.getElementById( 'hds-test-api' );
		const result = document.getElementById( 'hds-test-api-result' );
		if ( ! button || ! result ) {
			return;
		}

		function show( state, message ) {
			result.className = 'hds-test-result' + ( state ? ' hds-test-result--' + state : '' );
			result.textContent = message || '';
		}

		button.addEventListener( 'click', async function () {
			if ( button.disabled ) {
				return;
			}
			button.disabled = true;
			button.setAttribute( 'aria-busy', 'true' );
			show( 'busy', t( 'testing' ) );
			try {
				const data = await ajax( 'hds_test_api' );
				show( 'ok', data.message || '' );
			} catch ( error ) {
				show( 'error', ( error && error.message ) || t( 'genericError' ) );
			} finally {
				button.disabled = false;
				button.removeAttribute( 'aria-busy' );
			}
		} );
	}

	function init() {
		initIndex();
		initTable();
		initUpload();
		initTestApi();
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}() );
