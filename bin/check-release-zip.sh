#!/usr/bin/env bash
# Regression check for a built release zip: unpacks it and runs the bundled plugin-update-checker
# (requestInfo) against a mocked GitHub release with a Markdown body. Needs the dev stack
# (docker compose -p mg-seo up -d).
#   bin/check-release-zip.sh dist/mg-seo-<version>.zip
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
ZIP="$(cd "$(dirname "$1")" && pwd)/$(basename "$1")"
# Unpack under dist/ so the cli container sees it at /repo/dist/...
WORK="$(mktemp -d "$ROOT/dist/.zipcheck.XXXXXX")"
trap 'rm -rf "$WORK"' EXIT
unzip -q "$ZIP" -d "$WORK"
chmod -R a+rX "$WORK"
REL="${WORK#"$ROOT"/}"
# WP_PLUGIN_DIR points at the unpacked zip so PUC treats it as an installed plugin.
(cd "$ROOT" && docker compose run --rm --no-deps -T cli \
	--exec="define( 'WP_PLUGIN_DIR', '/repo/$REL' );" \
	--skip-plugins --skip-themes \
	eval-file /repo/bin/check-release-zip.php "/repo/$REL")
