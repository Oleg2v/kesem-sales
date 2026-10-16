// @ts-check
/**
 * Smart Document Search - end-to-end scenario (run via tests/e2e/run.sh).
 */
const { test, expect, request: pwRequest } = require( '@playwright/test' );
const { execFileSync } = require( 'child_process' );
const path = require( 'path' );

const FIXTURES = process.env.HDS_FIXTURES_DIR || path.resolve( __dirname, '../../fixtures' );
const MOCK = process.env.HDS_E2E_MOCK !== '0';
const DOCS = [ 'SH-22-06-57879-590', 'ARAR-25-09-8059' ];
const QUESTION = 'מה הוחלט לגבי החזר האגרה?';

/** Run WP-CLI in the wp-env cli container and return stdout. */
function wp( ...args ) {
	return execFileSync( 'npx', [ 'wp-env', 'run', 'cli', '--env-cwd=/var/www/html', '--', 'wp', ...args ], {
		cwd: path.resolve( __dirname, '..' ),
		encoding: 'utf8',
		stdio: [ 'ignore', 'pipe', 'ignore' ],
	} ).trim();
}

function openAiCalls() {
	try {
		return JSON.parse( wp( 'option', 'get', 'hds_e2e_calls', '--format=json' ) ) || {};
	} catch ( e ) {
		return {};
	}
}

async function login( page ) {
	await page.goto( '/wp-login.php' );
	await page.fill( '#user_login', 'admin' );
	await page.fill( '#user_pass', 'password' );
	await page.click( '#wp-submit' );
	await page.waitForURL( /wp-admin/ );
}

test.describe.serial( 'Smart Document Search', () => {
	/** @type {import('@playwright/test').Page} */
	let admin;

	test.beforeAll( async ( { browser } ) => {
		admin = await browser.newPage();
		await login( admin );
	} );

	test.afterAll( async () => {
		await admin.close();
	} );

	test( 'admin: menu, demo notice and upload of the two .doc fixtures', async () => {
		await admin.goto( '/wp-admin/admin.php?page=hds-documents' );
		await expect( admin.locator( '#adminmenu' ) ).toContainText( 'AI Search' );
		await expect( admin.locator( '.notice' ).filter( { hasText: /Demo version/i } ) ).toBeVisible();

		await admin.setInputFiles( '#hds-files', DOCS.map( ( d ) => path.join( FIXTURES, `${ d }.doc` ) ) );
		await Promise.all( [ admin.waitForNavigation(), admin.locator( '#hds-upload-form [type=submit]' ).click() ] );

		const table = admin.locator( '#hds-docs-table' );
		for ( const d of DOCS ) {
			await expect( table ).toContainText( d );
		}
		await expect( admin.locator( '.notice-success' ) ).toBeVisible();
	} );

	test( 'admin: duplicate upload is rejected', async () => {
		await admin.setInputFiles( '#hds-files', path.join( FIXTURES, `${ DOCS[ 0 ] }.doc` ) );
		await Promise.all( [ admin.waitForNavigation(), admin.locator( '#hds-upload-form [type=submit]' ).click() ] );
		await expect( admin.locator( '.notice' ).filter( { hasText: /already/i } ) ).toBeVisible();
		await expect( admin.locator( '#hds-docs-table tbody tr[data-doc-id]' ) ).toHaveCount( 2 );
	} );

	test( 'admin: indexing via AJAX batches with progress bar', async () => {
		await admin.goto( '/wp-admin/admin.php?page=hds-documents' );
		await admin.click( '#hds-index-button' );
		const bar = admin.locator( '#hds-progress' );
		await expect( bar ).toHaveAttribute( 'aria-valuenow', '100', { timeout: 150000 } );

		await admin.reload();
		const rows = admin.locator( '#hds-docs-table tbody tr[data-doc-id]' );
		await expect( rows ).toHaveCount( 2 );
		for ( let i = 0; i < 2; i++ ) {
			await expect( rows.nth( i ) ).toContainText( 'Indexed' );
		}
		const chunks = Number( wp( 'db', 'query', 'SELECT COUNT(*) FROM wp_hds_chunks WHERE embedding IS NOT NULL', '--skip-column-names' ) );
		expect( chunks ).toBeGreaterThan( 10 );
	} );

	test( 'admin: re-indexing skips unchanged files (no OpenAI calls)', async () => {
		test.skip( ! MOCK, 'call counting needs the mock' );
		const before = openAiCalls().embeddings || 0;
		await admin.goto( '/wp-admin/admin.php?page=hds-documents' );
		await admin.click( '#hds-index-button' );
		await expect( admin.locator( '#hds-index-log' ) ).toContainText( /up to date|skipped|unchanged/i, { timeout: 60000 } );
		expect( openAiCalls().embeddings || 0 ).toBe( before );
	} );

	test( 'security: stored files are not publicly reachable', async ( { request } ) => {
		const file = wp( 'db', 'query', 'SELECT file_name FROM wp_hds_docs LIMIT 1', '--skip-column-names' );
		expect( file ).toMatch( /\.doc$/ );
		const res = await request.get( `/wp-content/uploads/hds-docs/${ file }` );
		expect( res.status() ).toBe( 403 );
		const listing = await request.get( '/wp-content/uploads/hds-docs/' );
		expect( [ 200, 403 ] ).toContain( listing.status() );
		expect( await listing.text() ).not.toContain( file );
	} );

	test( 'settings: API key is never printed, license domain shown', async () => {
		await admin.goto( '/wp-admin/admin.php?page=hds-settings' );
		const html = await admin.content();
		expect( html ).not.toContain( 'sk-e2e-mock-000000000000000000000000' );
		expect( html ).not.toMatch( /sk-[A-Za-z0-9_-]{20,}/ );
		await expect( admin.locator( 'body' ) ).toContainText( /wp-config\.php/ );
		await expect( admin.locator( 'body' ) ).toContainText( 'localhost' );
		await expect( admin.locator( 'body' ) ).toContainText( /Demo mode/i );
	} );

	test( 'frontend: form, demo badge, RTL, accessibility attributes', async ( { page } ) => {
		await page.goto( '/hds-search/' );
		const root = page.locator( '.hds-search' ).first();
		await expect( root ).toHaveAttribute( 'dir', 'rtl' );
		await expect( root.locator( '.hds-demo-badge' ) ).toHaveText( 'Demo' );
		const input = root.locator( 'input.hds-input' );
		await expect( input ).toHaveAttribute( 'role', 'combobox' );
		const id = await input.getAttribute( 'id' );
		await expect( root.locator( `label[for="${ id }"]` ) ).toHaveCount( 1 );
		await expect( root.locator( '.hds-result' ) ).toHaveAttribute( 'aria-live', 'polite' );
		// The front end never sees the API key.
		expect( await page.content() ).not.toContain( 'sk-e2e-mock' );
	} );

	test( 'frontend: suggestions without OpenAI, keyboard navigation', async ( { page } ) => {
		await page.goto( '/hds-search/' );
		const before = JSON.stringify( openAiCalls() );
		const input = page.locator( 'input.hds-input' ).first();
		await input.click();
		await input.type( 'פס', { delay: 50 } );
		const list = page.locator( '.hds-suggestions' ).first();
		await expect( list ).toBeVisible();
		await expect( list.locator( '[role=option]' ).first() ).toBeVisible();
		await expect( input ).toHaveAttribute( 'aria-expanded', 'true' );

		await input.press( 'ArrowDown' );
		const active = await input.getAttribute( 'aria-activedescendant' );
		expect( active ).toBeTruthy();
		await expect( page.locator( `#${ active }` ) ).toHaveAttribute( 'aria-selected', 'true' );

		await input.press( 'Escape' );
		await expect( list ).toBeHidden();
		await expect( input ).toHaveAttribute( 'aria-expanded', 'false' );
		expect( JSON.stringify( openAiCalls() ) ).toBe( before );
	} );

	test( 'frontend: ask -> answer + highlighted sources, then cached', async ( { page } ) => {
		await page.goto( '/hds-search/' );
		const input = page.locator( 'input.hds-input' ).first();
		await input.fill( QUESTION );
		const [ response ] = await Promise.all( [
			page.waitForResponse( ( r ) => r.url().includes( 'hds/v1/ask' ) && r.request().method() === 'POST' ),
			input.press( 'Enter' ),
		] );
		expect( response.status() ).toBe( 200 );
		const json = await response.json();
		expect( json.answer.length ).toBeGreaterThan( 10 );
		expect( json.sources.length ).toBeGreaterThan( 0 );

		const result = page.locator( '.hds-result' ).first();
		await expect( result ).toContainText( json.answer.slice( 0, 20 ) );
		await expect( result.locator( '.hds-sources li' ).first() ).toBeVisible();
		await expect( result.locator( '.hds-source-title' ).first() ).toContainText( /SH-22-06-57879-590|ARAR-25-09-8059/ );
		await expect( result.locator( 'mark.hds-mark' ).first() ).toBeVisible();
		await expect( result ).toContainText( 'ייעוץ משפטי' );

		const chatCalls = openAiCalls().chat || 0;
		await page.reload();
		await page.locator( 'input.hds-input' ).first().fill( QUESTION );
		const [ again ] = await Promise.all( [
			page.waitForResponse( ( r ) => r.url().includes( 'hds/v1/ask' ) && r.request().method() === 'POST' ),
			page.locator( 'input.hds-input' ).first().press( 'Enter' ),
		] );
		expect( ( await again.json() ).cached ).toBe( true );
		if ( MOCK ) {
			expect( openAiCalls().chat || 0 ).toBe( chatCalls );
		}
	} );

	test( 'frontend: Gutenberg block renders the same search form', async ( { page } ) => {
		await page.goto( '/hds-block/' );
		await expect( page.locator( '.hds-search input.hds-input' ) ).toHaveAttribute( 'placeholder', 'שאלו על פסקי הדין' );
	} );

	test( 'rest: nonce required, rate limit returns 429 in Hebrew', async ( { baseURL } ) => {
		const api = await pwRequest.newContext( { baseURL } );
		const noNonce = await api.post( '/wp-json/hds/v1/ask', { data: { q: QUESTION } } );
		expect( noNonce.status() ).toBe( 403 );

		const nonce = ( await ( await api.get( '/wp-json/hds/v1/nonce' ) ).json() ).nonce;
		expect( nonce ).toMatch( /^[a-f0-9]{10}$/ );

		wp( 'eval', '$s = get_option( "hds_settings" ); $s["rate_limit"] = 2; update_option( "hds_settings", $s );' );
		try {
			const statuses = [];
			let last;
			for ( let i = 0; i < 4; i++ ) {
				last = await api.post( '/wp-json/hds/v1/ask', { data: { q: `${ QUESTION } ${ i }` }, headers: { 'X-HDS-Nonce': nonce } } );
				statuses.push( last.status() );
			}
			expect( statuses ).toContain( 429 );
			const body = await last.json();
			expect( body.message ).toMatch( /[֐-׿]/ );
		} finally {
			wp( 'eval', '$s = get_option( "hds_settings" ); $s["rate_limit"] = 10; update_option( "hds_settings", $s );' );
		}
		await api.dispose();
	} );

	test( 'frontend: mobile layout has no horizontal overflow', async ( { browser } ) => {
		const ctx = await browser.newContext( { viewport: { width: 360, height: 740 }, isMobile: true } );
		const page = await ctx.newPage();
		await page.goto( '/hds-search/' );
		const overflow = await page.evaluate( () => {
			const el = document.querySelector( '.hds-search' );
			return el ? el.scrollWidth - el.clientWidth : 999;
		} );
		expect( overflow ).toBeLessThanOrEqual( 1 );
		await page.screenshot( { path: path.join( __dirname, '../artifacts/mobile-search.png' ), fullPage: true } );
		await ctx.close();
	} );

	test( 'admin: delete a document removes it and its fragments', async () => {
		await admin.goto( '/wp-admin/admin.php?page=hds-documents' );
		await admin.screenshot( { path: path.join( __dirname, '../artifacts/admin-documents.png' ), fullPage: true } );
		const row = admin.locator( '#hds-docs-table tbody tr[data-doc-id]' ).filter( { hasText: DOCS[ 0 ] } );
		const id = await row.getAttribute( 'data-doc-id' );
		admin.once( 'dialog', ( d ) => d.accept() );
		await Promise.all( [ admin.waitForNavigation(), row.locator( '.hds-delete-button, button:has-text("Delete")' ).first().click() ] );
		await expect( admin.locator( `#hds-docs-table tr[data-doc-id="${ id }"]` ) ).toHaveCount( 0 );
		expect( Number( wp( 'db', 'query', `SELECT COUNT(*) FROM wp_hds_chunks WHERE doc_id = ${ Number( id ) }`, '--skip-column-names' ) ) ).toBe( 0 );
	} );
} );
