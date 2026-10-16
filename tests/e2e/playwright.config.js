// @ts-check
const { defineConfig, devices } = require( '@playwright/test' );

module.exports = defineConfig( {
	testDir: './specs',
	timeout: 180000,
	expect: { timeout: 15000 },
	fullyParallel: false,
	workers: 1,
	retries: 0,
	reporter: [ [ 'list' ] ],
	outputDir: './artifacts/test-results',
	use: {
		baseURL: process.env.WP_BASE_URL || 'http://localhost:8888',
		locale: 'he-IL',
		screenshot: 'only-on-failure',
		trace: 'retain-on-failure',
	},
	projects: [ { name: 'chromium', use: { ...devices[ 'Desktop Chrome' ] } } ],
} );
