#!/usr/bin/env bash
# Builds dist/keep-it-peace-<version>.zip from the working tree, excluding paths in .distignore.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
SLUG="keep-it-peace"
VERSION="$(sed -n "s/^ \* Version: *//p" "$ROOT/$SLUG.php" | head -n 1 | tr -d '[:space:]')"
STABLE="$(sed -n "s/^Stable tag: *//p" "$ROOT/readme.txt" | tr -d '[:space:]')"

if [ "$VERSION" != "$STABLE" ]; then
	echo "Version mismatch: $SLUG.php says $VERSION, readme.txt Stable tag says $STABLE" >&2
	exit 1
fi
if ! grep -q "define( 'KEEPITPEACE_VERSION', '$VERSION' );" "$ROOT/$SLUG.php"; then
	echo "KEEPITPEACE_VERSION does not match $VERSION" >&2
	exit 1
fi

BUILD="$(mktemp -d)"
trap 'rm -rf "$BUILD"' EXIT

EXCLUDES=()
while IFS= read -r line || [ -n "$line" ]; do
	line="${line%%#*}"
	line="$(echo "$line" | xargs)"
	[ -z "$line" ] && continue
	EXCLUDES+=("--exclude=$line")
done < "$ROOT/.distignore"

mkdir -p "$BUILD/$SLUG"
rsync -a "${EXCLUDES[@]}" "$ROOT/" "$BUILD/$SLUG/"

mkdir -p "$ROOT/dist"
OUT="$ROOT/dist/$SLUG-$VERSION.zip"
rm -f "$OUT"
( cd "$BUILD" && zip -qr "$OUT" "$SLUG" )
echo "Built $OUT"
unzip -l "$OUT" | tail -n 1
