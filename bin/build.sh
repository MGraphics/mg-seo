#!/usr/bin/env bash
# Build release archives of MG SEO.
#   bin/build.sh            -> dist/mg-seo-<version>.zip        (GitLab release asset, self-updating)
#   bin/build.sh --wporg    -> dist/mg-seo-<version>-wporg.zip  (WordPress.org: no update checker, no Update URI)
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
VERSION="$(sed -n 's/^ \* Version: *//p' "$ROOT/mg-seo/mg-seo.php" | tr -d '[:space:]')"
WPORG=0
[[ "${1:-}" == "--wporg" ]] && WPORG=1
STAGE="$(mktemp -d)"
trap 'rm -rf "$STAGE"' EXIT
rsync -a --exclude-from="$ROOT/.distignore" "$ROOT/mg-seo/" "$STAGE/mg-seo/"
# Compile translations if msgfmt is available.
if command -v msgfmt >/dev/null 2>&1; then
	for po in "$STAGE"/mg-seo/languages/*.po; do [[ -e "$po" ]] && msgfmt -o "${po%.po}.mo" "$po"; done
fi
NAME="mg-seo-$VERSION"
if [[ $WPORG -eq 1 ]]; then
	rm -rf "$STAGE/mg-seo/lib/plugin-update-checker" "$STAGE/mg-seo/includes/Updater.php"
	rmdir "$STAGE/mg-seo/lib" 2>/dev/null || true
	sed -i.bak '/^ \* Update URI:/d' "$STAGE/mg-seo/mg-seo.php" && rm "$STAGE/mg-seo/mg-seo.php.bak"
	NAME="$NAME-wporg"
fi
mkdir -p "$ROOT/dist"
rm -f "$ROOT/dist/$NAME.zip"
(cd "$STAGE" && zip -qr "$ROOT/dist/$NAME.zip" mg-seo)
echo "$ROOT/dist/$NAME.zip"
