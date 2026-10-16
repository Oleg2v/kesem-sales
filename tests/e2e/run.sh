#!/usr/bin/env bash
#
# End-to-end scenario for Smart Document Search on a throwaway WordPress
# (@wordpress/env + Playwright). Dev only - never touches a real site.
#
#   cd tests/e2e && npm ci && ./run.sh
#
# Scenario: activate the plugin -> upload the two real .doc fixtures through
# the admin form -> index them with the AJAX progress bar -> search with
# suggestions -> ask a question -> answer + sources -> security/REST checks ->
# uninstall cleanup.
#
# OpenAI: when OPENAI_API_KEY is set in the environment it is used for real
# (written to the throwaway wp-config.php as HDS_OPENAI_KEY). Otherwise the
# mu-plugin tests/e2e/mu-plugins/hds-e2e-mock.php answers instead of OpenAI.
#
# Set WP_ENV_PHP_VERSION=7.4 to run the same scenario on PHP 7.4.
#
set -euo pipefail
cd "$(dirname "$0")"

PLUGIN="smart-document-search"
export WP_BASE_URL="${WP_BASE_URL:-http://localhost:8888}"

wp() {
	# wp-env echoes every command on stderr; show stderr only when the command fails.
	local err status
	err="$(mktemp)"
	npx wp-env run cli --env-cwd=/var/www/html -- wp "$@" 2>"$err" && status=0 || status=$?
	if [[ $status -ne 0 ]]; then
		cat "$err" >&2
	fi
	rm -f "$err"
	return $status
}

fix_permissions() {
	# Only needed when the host user is root (CI sandboxes): Apache runs as
	# www-data there, so it must own wp-content. On a normal machine wp-env
	# already runs Apache as your user.
	# Never recurse into wp-content/plugins: the plugin directory is this
	# repository, mounted read-write.
	if [[ "$(id -u)" == "0" ]]; then
		npx wp-env run wordpress sh -c 'mkdir -p /var/www/html/wp-content/uploads && chown www-data:www-data /var/www/html/wp-content && chown -R www-data:www-data /var/www/html/wp-content/uploads' >/dev/null 2>&1 || true
	fi
}

echo "### Starting WordPress (PHP ${WP_ENV_PHP_VERSION:-8.3})"
npx wp-env start

echo "### Resetting plugin state"
if wp plugin is-active "$PLUGIN" >/dev/null 2>&1; then
	wp plugin deactivate "$PLUGIN" >/dev/null
fi
# Run the real uninstall.php (never `wp plugin uninstall`: it would delete the
# mounted plugin directory, i.e. this repository).
wp eval "define( 'WP_UNINSTALL_PLUGIN', '${PLUGIN}/${PLUGIN}.php' ); include WP_PLUGIN_DIR . '/${PLUGIN}/uninstall.php';" >/dev/null
wp option delete hds_e2e_calls hds_e2e_fail >/dev/null 2>&1 || true
wp post delete $(wp post list --post_type=page --name=hds-search,hds-block --field=ID --format=ids 2>/dev/null || true) --force >/dev/null 2>&1 || true
wp config delete HDS_OPENAI_KEY --type=constant >/dev/null 2>&1 || true
wp rewrite structure '/%postname%/' --hard >/dev/null

echo "### Activating plugin"
wp plugin activate "$PLUGIN"
fix_permissions

if [[ -n "${OPENAI_API_KEY:-}" ]]; then
	echo "### Using the REAL OpenAI API (OPENAI_API_KEY is set)"
	wp option delete hds_e2e_mock >/dev/null 2>&1 || true
	wp config set HDS_OPENAI_KEY "$OPENAI_API_KEY" --type=constant --quiet
	export HDS_E2E_MOCK=0
else
	echo "### OPENAI_API_KEY not set - using the OpenAI mock mu-plugin"
	wp option update hds_e2e_mock 1 >/dev/null
	wp config set HDS_OPENAI_KEY "sk-e2e-mock-000000000000000000000000" --type=constant --quiet
	export HDS_E2E_MOCK=1
fi

echo "### Creating search pages (shortcode + block)"
wp post create --post_type=page --post_status=publish --post_name=hds-search --post_title="חיפוש במסמכים" --post_content="[hds_search]" --porcelain >/dev/null
wp post create --post_type=page --post_status=publish --post_name=hds-block --post_title="AI Search block" --post_content='<!-- wp:hds/search {"placeholder":"שאלו על פסקי הדין"} /-->' --porcelain >/dev/null

echo "### Running Playwright"
export HDS_FIXTURES_DIR="$(cd ../fixtures && pwd)"
set +e
npx playwright test "$@"
PW_STATUS=$?
set -e

echo "### Database checks"
wp eval '
global $wpdb;
$docs   = $wpdb->get_results( "SELECT title, status, chunk_count FROM {$wpdb->prefix}hds_docs ORDER BY id", ARRAY_A );
$chunks = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}hds_chunks" );
$sizes  = $wpdb->get_col( "SELECT DISTINCT LENGTH(embedding) FROM {$wpdb->prefix}hds_chunks" );
echo "docs: " . wp_json_encode( $docs, JSON_UNESCAPED_UNICODE ) . "\nchunks: $chunks\nembedding byte sizes: " . implode( ",", $sizes ) . "\n";
echo "openai calls: " . wp_json_encode( get_option( "hds_e2e_calls" ) ) . "\n";
'

echo "### PHP notices/warnings from the plugin (debug.log)"
if npx wp-env run cli cat wp-content/debug.log 2>/dev/null | grep -E "smart-document-search|hds" ; then
	echo "!!! PHP notices found above"
	PW_STATUS=1
else
	echo "none"
fi

echo "### Uninstall check"
wp plugin deactivate "$PLUGIN" >/dev/null
wp eval "define( 'WP_UNINSTALL_PLUGIN', '${PLUGIN}/${PLUGIN}.php' ); include WP_PLUGIN_DIR . '/${PLUGIN}/uninstall.php';"
wp eval '
global $wpdb;
$tables  = $wpdb->get_col( $wpdb->prepare( "SHOW TABLES LIKE %s", $wpdb->esc_like( $wpdb->prefix . "hds_" ) . "%" ) );
$options = $wpdb->get_col( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE '"'"'hds\\_%'"'"' AND option_name NOT LIKE '"'"'hds\\_e2e%'"'"'" );
$dir     = wp_upload_dir()["basedir"] . "/hds-docs";
echo "tables left: " . count( $tables ) . ", options left: " . count( $options ) . ", folder exists: " . ( file_exists( $dir ) ? "yes" : "no" ) . "\n";
if ( $tables || $options || file_exists( $dir ) ) { echo "UNINSTALL INCOMPLETE: " . implode( ",", array_merge( $tables, $options ) ) . "\n"; exit( 1 ); }
' || PW_STATUS=1

echo "### Re-activating for manual inspection at ${WP_BASE_URL} (admin / password)"
wp plugin activate "$PLUGIN" >/dev/null
fix_permissions

exit $PW_STATUS
