#!/usr/bin/env bash
#
# Build the installable plugin archive: dist/smart-document-search.zip
#
# The archive contains only what runs on the server. Everything listed in
# .distignore (tools/ with the license signing script, tests/, .git, Composer
# and npm files, build scripts, README.md...) is left out, and the build fails
# if anything sensitive (a private key, tests, tools) slips into the archive.
#
# Usage: ./build.sh [--require-license-key]
#   --require-license-key  fail when HDS_License::PUBLIC_KEY is still empty
#                          (use for real releases).
#
set -euo pipefail

cd "$(dirname "$0")"

SLUG="smart-document-search"
MAIN="${SLUG}.php"
REQUIRE_KEY=0
[[ "${1:-}" == "--require-license-key" ]] && REQUIRE_KEY=1

VERSION="$(sed -n 's/^[[:space:]]*\*[[:space:]]*Version:[[:space:]]*//p' "$MAIN" | head -n1 | tr -d '\r')"
echo "Building ${SLUG} ${VERSION}"

# --- Pre-flight checks -------------------------------------------------------
[[ -f vendor/autoload.php ]] || { echo "ERROR: vendor/autoload.php missing - run: php tools/prefix-vendor.php" >&2; exit 1; }

if grep -Eq "const PUBLIC_KEY = '';" includes/class-hds-license.php; then
	if [[ $REQUIRE_KEY -eq 1 ]]; then
		echo "ERROR: HDS_License::PUBLIC_KEY is empty. Run: php tools/keygen.php init" >&2
		exit 1
	fi
	echo "WARNING: HDS_License::PUBLIC_KEY is empty - no license key can be valid, every site stays in demo mode."
fi

# --- Stage files -------------------------------------------------------------
BUILD_DIR="$(mktemp -d)"
trap 'rm -rf "$BUILD_DIR"' EXIT
mkdir -p "$BUILD_DIR/$SLUG"

EXCLUDES=()
while IFS= read -r line || [[ -n "$line" ]]; do
	line="${line%%#*}"
	line="$(echo "$line" | tr -d '\r' | sed 's/[[:space:]]*$//')"
	[[ -z "$line" ]] && continue
	EXCLUDES+=( "--exclude=./${line#/}" )
done < .distignore

tar -C . --anchored "${EXCLUDES[@]}" -cf - . | tar -C "$BUILD_DIR/$SLUG" -xf -

# --- Safety checks on the staged tree ----------------------------------------
STAGE="$BUILD_DIR/$SLUG"
for forbidden in tools tests .git .dev node_modules dist; do
	if [[ -e "$STAGE/$forbidden" ]]; then
		echo "ERROR: '$forbidden' must not be in the plugin archive." >&2
		exit 1
	fi
done
if find "$STAGE" \( -name 'private.key' -o -name '*.key' -o -name '.env' -o -name 'composer.json' -o -name 'package.json' \) | grep -q .; then
	echo "ERROR: a key/dev file ended up in the archive:" >&2
	find "$STAGE" \( -name 'private.key' -o -name '*.key' -o -name '.env' -o -name 'composer.json' -o -name 'package.json' \) >&2
	exit 1
fi

# Every PHP file must lint.
while IFS= read -r -d '' file; do
	php -l "$file" >/dev/null || { echo "ERROR: syntax error in ${file#"$STAGE"/}" >&2; exit 1; }
done < <(find "$STAGE" -name '*.php' -print0)

# --- Archive -----------------------------------------------------------------
mkdir -p dist
rm -f "dist/${SLUG}.zip"
( cd "$BUILD_DIR" && zip -rqX9 "$OLDPWD/dist/${SLUG}.zip" "$SLUG" )

echo "Created dist/${SLUG}.zip ($(du -h "dist/${SLUG}.zip" | cut -f1)), $(unzip -Z1 "dist/${SLUG}.zip" | grep -vc '/$') files:"
unzip -Z1 "dist/${SLUG}.zip" | grep -v '/$' | sed 's#^[^/]*/##' | awk -F/ '{print ($2 == "" ? $1 : $1 "/")}' | sort | uniq -c
