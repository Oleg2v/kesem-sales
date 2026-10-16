/**
 * Smart Document Search – search form (shortcode [hds_search] / block hds/search).
 *
 * Vanilla ES2017, no dependencies. Enhances every [data-hds-search] element:
 * - suggestions (ARIA 1.2 combobox + listbox) filtered in the browser from a
 *   dictionary fetched once from GET {restUrl}suggest (no OpenAI calls);
 * - questions sent to POST {restUrl}ask with the X-HDS-Nonce header
 *   (credentials omitted; one nonce refresh + retry when the nonce expired);
 * - the answer, a disclaimer and the sources with highlighted query terms,
 *   built with DOM nodes only (server text is never parsed as HTML).
 *
 * Configuration: window.hdsSearch = { restUrl, nonce, demo, minChars,
 * maxLength, debounce, i18n: { ..., errors: { ... } } } (printed by PHP).
 *
 * @package SmartDocumentSearch
 */
( function () {
	'use strict';

	if ( typeof window === 'undefined' || typeof document === 'undefined' ) {
		return;
	}

	const cfg = window.hdsSearch && typeof window.hdsSearch === 'object' ? window.hdsSearch : {};

	// Hebrew fallbacks, used only when the inline configuration is missing
	// (e.g. removed by an optimization plugin).
	const DEFAULT_I18N = {
		loading: 'מחפש תשובה…',
		sources: 'מקורות',
		disclaimer: 'התשובה מבוססת על המסמכים בלבד ואינה מהווה ייעוץ משפטי.',
		document: 'מסמך',
		suggestCount: '%d הצעות זמינות. השתמשו בחיצים כדי לבחור.',
		suggestOne: 'הצעה אחת זמינה. השתמשו בחיצים כדי לבחור.',
		errors: {
			short: 'נא להקליד לפחות 2 תווים.',
			long: 'השאלה ארוכה מדי.',
			rate: 'נשלחו יותר מדי בקשות. נסו שוב בעוד דקה.',
			generic: 'אירעה שגיאה. נסו שוב מאוחר יותר.',
			not_configured: 'החיפוש אינו זמין כרגע.',
			no_index: 'עדיין אין מסמכים זמינים לחיפוש.',
			network: 'אין חיבור לשרת. בדקו את החיבור ונסו שוב.',
			nonce: 'פג תוקף הדף. רעננו את הדף ונסו שוב.',
		},
	};

	const i18n = Object.assign( {}, DEFAULT_I18N, cfg.i18n && typeof cfg.i18n === 'object' ? cfg.i18n : {} );
	i18n.errors = Object.assign( {}, DEFAULT_I18N.errors, i18n.errors && typeof i18n.errors === 'object' ? i18n.errors : {} );

	const MIN_CHARS = positiveInt( cfg.minChars, 2 );
	const MAX_LENGTH = positiveInt( cfg.maxLength, 300 );
	const DEBOUNCE = positiveInt( cfg.debounce, 200 );
	const MAX_TITLES = 3;
	const MAX_SUGGESTIONS = 8;
	const PREFIXES = 'והבכלמש';
	const WORD_SPLIT = /[\s\-_/\\.,;:!?()[\]{}"'|]+/;

	const ERROR_KEYS = {
		hds_query_short: 'short',
		hds_query_long: 'long',
		hds_rate_limited: 'rate',
		hds_not_configured: 'not_configured',
		hds_no_index: 'no_index',
		hds_bad_nonce: 'nonce',
		network: 'network',
	};

	const restUrl = resolveRestUrl();
	let nonce = typeof cfg.nonce === 'string' ? cfg.nonce : '';
	let noncePromise = null;
	let dictPromise = null;
	let dictAttempts = 0;
	let regexSupport = null;

	/* ------------------------------------------------------------------
	 * Helpers
	 * ---------------------------------------------------------------- */

	function positiveInt( value, fallback ) {
		const n = parseInt( value, 10 );
		return n > 0 ? n : fallback;
	}

	function resolveRestUrl() {
		let url = typeof cfg.restUrl === 'string' ? cfg.restUrl : '';
		if ( ! url ) {
			// WordPress prints the REST root in <head>.
			const link = document.querySelector( 'link[rel="https://api.w.org/"]' );
			url = link && link.href ? link.href + 'hds/v1/' : '';
		}
		if ( url && url.indexOf( '?' ) === -1 && url.charAt( url.length - 1 ) !== '/' ) {
			url += '/';
		}
		return url;
	}

	/**
	 * Normalize like HDS_Normalizer::normalize_query(): no nikud, unified
	 * quotes and dashes, single spaces, lowercase.
	 *
	 * @param {string} text Text.
	 * @return {string} Normalized text.
	 */
	function normalize( text ) {
		return String( text == null ? '' : text )
			.replace( /־/g, '-' )
			.replace( /[֑-ׇֽֿׁׂׅׄ]/g, '' )
			.replace( /׀/g, '|' )
			.replace( /׃/g, ':' )
			.replace( /׆/g, 'נ' )
			.replace( /[״“-‟″«»＂]/g, '"' )
			.replace( /[׳‘-‛′´`＇]/g, "'" )
			.replace( /[‐-―−]/g, '-' )
			.replace( /\s+/g, ' ' )
			.trim()
			.toLowerCase();
	}

	function words( text ) {
		return text.split( WORD_SPLIT ).filter( Boolean );
	}

	function escapeRegExp( text ) {
		// Only syntax characters: other escapes are invalid with the 'u' flag.
		return text.replace( /[.*+?^${}()|[\]\\/]/g, '\\$&' );
	}

	function format( template, n ) {
		return String( template || '' ).replace( '%d', String( n ) );
	}

	function element( tag, className, text ) {
		const node = document.createElement( tag );
		if ( className ) {
			node.className = className;
		}
		if ( text != null ) {
			node.textContent = String( text );
		}
		return node;
	}

	function apiError( code ) {
		const error = new Error( code );
		error.code = code;
		return error;
	}

	function isAbort( error ) {
		return !! error && error.name === 'AbortError';
	}

	function errorMessage( code ) {
		const key = ERROR_KEYS[ code ] || 'generic';
		return i18n.errors[ key ] || i18n.errors.generic;
	}

	/* ------------------------------------------------------------------
	 * REST
	 * ---------------------------------------------------------------- */

	async function readJson( response ) {
		try {
			return await response.json();
		} catch ( error ) {
			if ( isAbort( error ) ) {
				throw error;
			}
			return null;
		}
	}

	function errorCode( body, status ) {
		let code = body && typeof body.code === 'string' ? body.code : '';
		const details = body && body.data && body.data.details;
		if ( details && details.q && typeof details.q.code === 'string' ) {
			code = details.q.code;
		}
		if ( 'rest_missing_callback_param' === code ) {
			code = 'hds_query_short';
		} else if ( 'rest_invalid_param' === code ) {
			code = 'hds_query_long';
		} else if ( ! code && 429 === status ) {
			code = 'hds_rate_limited';
		}
		return code || 'default';
	}

	function refreshNonce() {
		if ( ! noncePromise ) {
			noncePromise = fetch( restUrl + 'nonce', {
				method: 'GET',
				credentials: 'omit',
				cache: 'no-store',
				headers: { Accept: 'application/json' },
			} )
				.then( ( response ) => ( response.ok ? response.json() : null ) )
				.then(
					( data ) => {
						noncePromise = null;
						if ( ! data || typeof data.nonce !== 'string' || ! data.nonce ) {
							throw apiError( 'hds_bad_nonce' );
						}
						nonce = data.nonce;
					},
					( error ) => {
						noncePromise = null;
						throw error && error.code ? error : apiError( 'network' );
					}
				);
		}
		return noncePromise;
	}

	async function postAsk( q, signal, retried ) {
		let response;
		try {
			response = await fetch( restUrl + 'ask', {
				method: 'POST',
				credentials: 'omit',
				cache: 'no-store',
				headers: {
					'Content-Type': 'application/json',
					Accept: 'application/json',
					'X-HDS-Nonce': nonce,
				},
				body: JSON.stringify( { q } ),
				signal,
			} );
		} catch ( error ) {
			throw isAbort( error ) ? error : apiError( 'network' );
		}
		const body = await readJson( response );
		if ( response.ok && body && typeof body.answer === 'string' ) {
			return body;
		}
		const code = errorCode( body, response.status );
		if ( 'hds_bad_nonce' === code && ! retried ) {
			await refreshNonce();
			return postAsk( q, signal, true );
		}
		throw apiError( code );
	}

	/* ------------------------------------------------------------------
	 * Suggestions dictionary (shared by all instances, fetched once)
	 * ---------------------------------------------------------------- */

	function prepareDictionary( data ) {
		const entries = [];
		const seen = Object.create( null );
		const add = ( text, isTitle ) => {
			if ( typeof text !== 'string' ) {
				return;
			}
			const label = text.replace( /\s+/g, ' ' ).trim();
			const norm = normalize( label );
			if ( norm.length < 2 || seen[ norm ] ) {
				return;
			}
			seen[ norm ] = true;
			entries.push( { label, norm, words: words( norm ), title: isTitle, order: entries.length } );
		};
		( data && Array.isArray( data.titles ) ? data.titles : [] ).forEach( ( t ) => add( t, true ) );
		( data && Array.isArray( data.phrases ) ? data.phrases : [] ).forEach( ( p ) => add( p, false ) );
		return entries;
	}

	function loadDictionary() {
		if ( ! dictPromise ) {
			dictAttempts++;
			dictPromise = fetch( restUrl + 'suggest', {
				method: 'GET',
				credentials: 'omit',
				headers: { Accept: 'application/json' },
			} )
				.then( ( response ) => {
					if ( ! response.ok ) {
						throw apiError( 'default' );
					}
					return response.json();
				} )
				.then( prepareDictionary )
				.catch( () => {
					if ( dictAttempts < 2 ) {
						dictPromise = null; // One more try on the next keystroke.
					}
					return [];
				} );
		}
		return dictPromise;
	}

	/**
	 * Rank of an entry for a query: 0 = the entry starts with the query,
	 * 1 = every query word starts a word of the entry, 2 = the same after a
	 * Hebrew one-letter prefix, -1 = no match.
	 *
	 * @param {Object}   entry  Dictionary entry.
	 * @param {string}   query  Normalized query.
	 * @param {string[]} tokens Query words.
	 * @return {number} Rank.
	 */
	function matchRank( entry, query, tokens ) {
		if ( entry.norm.indexOf( query ) === 0 ) {
			return 0;
		}
		let rank = 1;
		for ( let t = 0; t < tokens.length; t++ ) {
			const token = tokens[ t ];
			let best = -1;
			for ( let w = 0; w < entry.words.length && best !== 1; w++ ) {
				const word = entry.words[ w ];
				if ( word.indexOf( token ) === 0 ) {
					best = 1;
				} else if ( token.length >= 2 && word.length > token.length && PREFIXES.indexOf( word.charAt( 0 ) ) !== -1 && word.indexOf( token, 1 ) === 1 ) {
					best = 2;
				}
			}
			if ( best < 0 ) {
				return -1;
			}
			rank = Math.max( rank, best );
		}
		return rank;
	}

	function filterSuggestions( entries, query ) {
		const tokens = words( query );
		if ( ! tokens.length ) {
			return [];
		}
		const titles = [];
		const phrases = [];
		entries.forEach( ( entry ) => {
			if ( entry.norm === query ) {
				return;
			}
			const rank = matchRank( entry, query, tokens );
			if ( rank >= 0 ) {
				( entry.title ? titles : phrases ).push( { entry, rank } );
			}
		} );
		const byRank = ( a, b ) => a.rank - b.rank || a.entry.order - b.entry.order;
		titles.sort( byRank );
		phrases.sort( byRank );
		return titles
			.slice( 0, MAX_TITLES )
			.concat( phrases )
			.slice( 0, MAX_SUGGESTIONS )
			.map( ( item ) => item.entry );
	}

	/* ------------------------------------------------------------------
	 * Highlighting
	 * ---------------------------------------------------------------- */

	function termVariants( terms ) {
		const out = [];
		( Array.isArray( terms ) ? terms : [] ).forEach( ( raw ) => {
			const term = normalize( raw );
			if ( term.length < 2 ) {
				return;
			}
			out.push( term );
			// 'האגרה' also matches 'אגרה' (and, with the prefix group, 'לאגרה').
			if ( term.length >= 4 && PREFIXES.indexOf( term.charAt( 0 ) ) !== -1 ) {
				out.push( term.slice( 1 ) );
			}
		} );
		return out
			.filter( ( term, index ) => out.indexOf( term ) === index )
			.sort( ( a, b ) => b.length - a.length );
	}

	/**
	 * Regex matching the terms at word starts with optional Hebrew prefixes
	 * (needs lookbehind and Unicode property escapes), or null.
	 *
	 * @param {string[]} variants Terms.
	 * @return {RegExp|null} Regex.
	 */
	function termRegex( variants ) {
		if ( ! variants.length || false === regexSupport ) {
			return null;
		}
		const body = '(?:[' + PREFIXES + ']{1,2})?(?:' + variants.map( escapeRegExp ).join( '|' ) + ')';
		try {
			const regex = new RegExp( '(?<![\\p{L}\\p{N}])' + body, 'giu' );
			regexSupport = true;
			return regex;
		} catch ( error ) {
			regexSupport = false; // Older browser: simple matching below.
			return null;
		}
	}

	/**
	 * Matcher for a set of terms: { variants, regex } (regex null = fallback).
	 *
	 * @param {string[]} terms Terms from the server.
	 * @return {Object} Matcher.
	 */
	function termMatcher( terms ) {
		const variants = termVariants( terms );
		return { variants, regex: termRegex( variants ) };
	}

	function findMatches( text, matcher ) {
		const out = [];
		if ( ! matcher.variants.length || ! text ) {
			return out;
		}
		const regex = matcher.regex;
		if ( regex ) {
			let match;
			regex.lastIndex = 0;
			while ( ( match = regex.exec( text ) ) !== null ) {
				if ( ! match[ 0 ] ) {
					regex.lastIndex++;
					continue;
				}
				out.push( [ match.index, match.index + match[ 0 ].length ] );
			}
			return out;
		}
		// Fallback: case-insensitive substring search, longest term first.
		const lower = text.toLowerCase();
		if ( lower.length !== text.length ) {
			return out;
		}
		let pos = 0;
		while ( pos < lower.length ) {
			let best = -1;
			let length = 0;
			matcher.variants.forEach( ( term ) => {
				const index = lower.indexOf( term, pos );
				if ( index !== -1 && ( best === -1 || index < best ) ) {
					best = index;
					length = term.length;
				}
			} );
			if ( best === -1 ) {
				break;
			}
			out.push( [ best, best + length ] );
			pos = best + length;
		}
		return out;
	}

	function appendHighlighted( node, text, matcher ) {
		let pos = 0;
		findMatches( text, matcher ).forEach( ( range ) => {
			if ( range[ 0 ] > pos ) {
				node.appendChild( document.createTextNode( text.slice( pos, range[ 0 ] ) ) );
			}
			node.appendChild( element( 'mark', 'hds-mark', text.slice( range[ 0 ], range[ 1 ] ) ) );
			pos = range[ 1 ];
		} );
		if ( pos < text.length ) {
			node.appendChild( document.createTextNode( text.slice( pos ) ) );
		}
	}

	/* ------------------------------------------------------------------
	 * One search form
	 * ---------------------------------------------------------------- */

	function init( root ) {
		if ( root.getAttribute( 'data-hds-ready' ) ) {
			return;
		}
		const form = root.querySelector( '.hds-form' );
		const input = root.querySelector( '.hds-input' );
		const list = root.querySelector( '.hds-suggestions' );
		const status = root.querySelector( '.hds-status' );
		const result = root.querySelector( '.hds-result' );
		if ( ! form || ! input || ! list || ! result || ! restUrl ) {
			return;
		}
		root.setAttribute( 'data-hds-ready', '1' );

		const baseId = root.id || 'hds-search-' + Math.random().toString( 36 ).slice( 2, 10 );
		if ( ! list.id ) {
			list.id = baseId + '-listbox';
			input.setAttribute( 'aria-controls', list.id );
		}

		// Screen-reader-only live region for the number of suggestions.
		const hint = element( 'div', 'hds-sr-only' );
		hint.setAttribute( 'role', 'status' );
		hint.setAttribute( 'aria-live', 'polite' );
		root.appendChild( hint );

		let items = [];
		let active = -1;
		let timer = 0;
		let controller = null;
		let sequence = 0;

		function announceSuggestions( text ) {
			if ( hint.textContent !== text ) {
				hint.textContent = text;
			}
		}

		function isOpen() {
			return ! list.hidden && items.length > 0;
		}

		function resetList() {
			items = [];
			active = -1;
			list.textContent = '';
			input.removeAttribute( 'aria-activedescendant' );
		}

		function close() {
			resetList();
			list.hidden = true;
			input.setAttribute( 'aria-expanded', 'false' );
			root.classList.remove( 'is-open' );
			announceSuggestions( '' );
		}

		function setActive( index ) {
			const options = list.children;
			if ( active >= 0 && options[ active ] ) {
				options[ active ].setAttribute( 'aria-selected', 'false' );
				options[ active ].classList.remove( 'is-active' );
			}
			active = index >= 0 && index < options.length ? index : -1;
			if ( active < 0 ) {
				input.removeAttribute( 'aria-activedescendant' );
				return;
			}
			const option = options[ active ];
			option.setAttribute( 'aria-selected', 'true' );
			option.classList.add( 'is-active' );
			input.setAttribute( 'aria-activedescendant', option.id );
			if ( typeof option.scrollIntoView === 'function' ) {
				option.scrollIntoView( { block: 'nearest' } );
			}
		}

		function renderSuggestions( found, selectFirst ) {
			if ( ! found.length ) {
				close();
				return;
			}
			resetList();
			items = found;
			found.forEach( ( entry, index ) => {
				const option = element( 'li', 'hds-suggestion' + ( entry.title ? ' hds-suggestion--title' : '' ) );
				option.id = list.id + '-option-' + index;
				option.setAttribute( 'role', 'option' );
				option.setAttribute( 'aria-selected', 'false' );
				option.appendChild( element( 'span', 'hds-suggestion-text', entry.label ) );
				if ( entry.title ) {
					option.appendChild( element( 'span', 'hds-suggestion-type', i18n.document ) );
				}
				list.appendChild( option );
			} );
			list.hidden = false;
			input.setAttribute( 'aria-expanded', 'true' );
			root.classList.add( 'is-open' );
			announceSuggestions( 1 === found.length ? i18n.suggestOne : format( i18n.suggestCount, found.length ) );
			if ( selectFirst ) {
				setActive( 0 );
			}
		}

		function suggest( selectFirst ) {
			const value = input.value;
			if ( normalize( value ).length < MIN_CHARS ) {
				close();
				return;
			}
			loadDictionary().then( ( entries ) => {
				// Stale: the text changed or the field lost focus meanwhile.
				if ( input.value !== value || document.activeElement !== input ) {
					return;
				}
				renderSuggestions( filterSuggestions( entries, normalize( value ) ), selectFirst );
			} );
		}

		function scheduleSuggest() {
			clearTimeout( timer );
			if ( normalize( input.value ).length < MIN_CHARS ) {
				close();
				return;
			}
			timer = setTimeout( () => suggest( false ), DEBOUNCE );
		}

		function choose( index ) {
			const entry = items[ index ];
			if ( ! entry ) {
				return;
			}
			clearTimeout( timer );
			input.value = entry.label;
			close();
			if ( document.activeElement !== input ) {
				input.focus();
			}
		}

		function setBusy( busy ) {
			result.setAttribute( 'aria-busy', busy ? 'true' : 'false' );
			root.classList.toggle( 'is-loading', busy );
			if ( status ) {
				status.textContent = busy ? i18n.loading : '';
			}
		}

		function showError( code ) {
			result.textContent = '';
			result.appendChild( element( 'p', 'hds-error', errorMessage( code ) ) );
		}

		function renderAnswer( data ) {
			const wrap = element( 'div', 'hds-answer' );
			wrap.appendChild( element( 'p', 'hds-answer-text', String( data.answer ) ) );
			wrap.appendChild( element( 'p', 'hds-disclaimer', i18n.disclaimer ) );

			const sources = Array.isArray( data.sources ) ? data.sources : [];
			if ( sources.length ) {
				const matcher = termMatcher( data.terms );
				const headingId = baseId + '-sources-' + sequence;
				const heading = element( 'h3', 'hds-sources-heading', i18n.sources );
				heading.id = headingId;
				const ol = element( 'ol', 'hds-sources' );
				ol.setAttribute( 'aria-labelledby', headingId );
				sources.forEach( ( source ) => {
					if ( ! source || typeof source !== 'object' ) {
						return;
					}
					const li = element( 'li', 'hds-source' );
					li.appendChild( element( 'span', 'hds-source-title', source.title ? String( source.title ) : i18n.document ) );
					const snippet = String( source.snippet || '' );
					if ( snippet ) {
						const p = element( 'p', 'hds-source-snippet' );
						appendHighlighted( p, snippet, matcher );
						li.appendChild( p );
					}
					ol.appendChild( li );
				} );
				wrap.appendChild( heading );
				wrap.appendChild( ol );
			}

			result.textContent = '';
			result.appendChild( wrap );
		}

		async function ask( raw ) {
			const q = String( raw || '' ).replace( /\s+/g, ' ' ).trim();
			if ( controller ) {
				controller.abort();
				controller = null;
			}
			const current = ++sequence;
			if ( normalize( q ).length < MIN_CHARS ) {
				setBusy( false );
				showError( 'hds_query_short' );
				return;
			}
			if ( q.length > MAX_LENGTH ) {
				setBusy( false );
				showError( 'hds_query_long' );
				return;
			}

			const ownController = typeof AbortController === 'function' ? new AbortController() : null;
			controller = ownController;
			setBusy( true );
			try {
				const data = await postAsk( q, ownController ? ownController.signal : undefined, false );
				if ( current === sequence ) {
					renderAnswer( data );
				}
			} catch ( error ) {
				if ( current === sequence && ! isAbort( error ) ) {
					showError( error && error.code ? error.code : 'network' );
				}
			} finally {
				if ( current === sequence ) {
					controller = null;
					setBusy( false );
				}
			}
		}

		input.addEventListener( 'focus', () => {
			loadDictionary();
		} );
		input.addEventListener( 'input', scheduleSuggest );
		input.addEventListener( 'search', () => {
			if ( ! input.value ) {
				close();
			}
		} );
		input.addEventListener( 'blur', () => {
			clearTimeout( timer );
			close();
		} );
		input.addEventListener( 'keydown', ( event ) => {
			if ( event.isComposing || 229 === event.keyCode ) {
				return;
			}
			switch ( event.key ) {
				case 'ArrowDown':
				case 'Down':
					event.preventDefault();
					if ( isOpen() ) {
						setActive( active + 1 >= items.length ? 0 : active + 1 );
					} else {
						clearTimeout( timer );
						suggest( true );
					}
					break;
				case 'ArrowUp':
				case 'Up':
					if ( isOpen() ) {
						event.preventDefault();
						setActive( active <= 0 ? items.length - 1 : active - 1 );
					}
					break;
				case 'Enter':
					if ( isOpen() && active >= 0 ) {
						event.preventDefault();
						choose( active );
					}
					break;
				case 'Escape':
				case 'Esc':
					if ( isOpen() ) {
						event.preventDefault();
						clearTimeout( timer );
						close();
					}
					break;
				case 'Tab':
					clearTimeout( timer );
					close();
					break;
			}
		} );

		// Keep the focus in the field while an option is clicked or tapped.
		list.addEventListener( 'mousedown', ( event ) => event.preventDefault() );
		list.addEventListener( 'click', ( event ) => {
			let node = event.target;
			while ( node && node !== list && ! ( node.getAttribute && 'option' === node.getAttribute( 'role' ) ) ) {
				node = node.parentNode;
			}
			if ( node && node !== list ) {
				choose( Array.prototype.indexOf.call( list.children, node ) );
			}
		} );

		form.addEventListener( 'submit', ( event ) => {
			event.preventDefault();
			clearTimeout( timer );
			close();
			ask( input.value );
		} );
	}

	function initAll() {
		Array.prototype.forEach.call( document.querySelectorAll( '[data-hds-search]' ), init );
	}

	// For forms inserted later (e.g. by page builders).
	window.hdsSearchInit = initAll;

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', initAll );
	} else {
		initAll();
	}
}() );
