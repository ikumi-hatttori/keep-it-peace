#!/usr/bin/env bash
# Deploys the current version to the WordPress.org SVN repository.
#
#   bin/deploy-wporg.sh              # dry run: prepares the SVN working copy and shows `svn status`
#   bin/deploy-wporg.sh --commit     # also commits trunk, tags/<version> and assets
#
# Requirements: subversion (brew install subversion), rsync.
# SVN credentials: WordPress.org username + SVN password (Profile → Account & Security).
# Directory assets for the plugin page go in .wordpress-org/ (banner-*.png, icon-*.png, screenshot-N.png).
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
SLUG="keep-it-peace"
SVN_URL="https://plugins.svn.wordpress.org/$SLUG"
SVN_DIR="${SVN_DIR:-$HOME/.cache/$SLUG-svn}"
COMMIT=0
[ "${1:-}" = "--commit" ] && COMMIT=1

VERSION="$(sed -n "s/^ \* Version: *//p" "$ROOT/$SLUG.php" | head -n 1 | tr -d '[:space:]')"
STABLE="$(sed -n "s/^Stable tag: *//p" "$ROOT/readme.txt" | tr -d '[:space:]')"
if [ "$VERSION" != "$STABLE" ]; then
	echo "Version mismatch: $SLUG.php=$VERSION readme.txt Stable tag=$STABLE" >&2
	exit 1
fi
if [ -n "$(git -C "$ROOT" status --porcelain -- . ':!composer.lock')" ]; then
	echo "Working tree has uncommitted changes. Commit first." >&2
	exit 1
fi

if [ -d "$SVN_DIR/.svn" ]; then
	svn update -q "$SVN_DIR"
else
	svn checkout -q --depth immediates "$SVN_URL" "$SVN_DIR"
	svn update -q --set-depth infinity "$SVN_DIR/trunk" "$SVN_DIR/assets"
fi
svn update -q --set-depth immediates "$SVN_DIR/tags"
if [ -d "$SVN_DIR/tags/$VERSION" ]; then
	echo "tags/$VERSION already exists on WordPress.org. Bump the version." >&2
	exit 1
fi

# trunk = distribution files (same exclusions as the ZIP).
EXCLUDES=()
while IFS= read -r line || [ -n "$line" ]; do
	line="${line%%#*}"; line="$(echo "$line" | xargs)"
	[ -z "$line" ] && continue
	EXCLUDES+=("--exclude=$line")
done < "$ROOT/.distignore"
rsync -a --delete --exclude=.svn --exclude=/.wordpress-org "${EXCLUDES[@]}" "$ROOT/" "$SVN_DIR/trunk/"

if [ -d "$ROOT/.wordpress-org" ]; then
	rsync -a --delete --exclude=.svn "$ROOT/.wordpress-org/" "$SVN_DIR/assets/"
fi

cd "$SVN_DIR"
svn status | awk '/^!/ {print $2}' | while IFS= read -r p; do svn rm -q "$p"; done
svn add -q --force trunk assets
svn copy -q trunk "tags/$VERSION"
for f in assets/*.png assets/*.jpg; do
	[ -e "$f" ] || continue
	case "$f" in *.png) t=image/png ;; *) t=image/jpeg ;; esac
	svn propset -q svn:mime-type "$t" "$f"
done

svn status
if [ "$COMMIT" -eq 1 ]; then
	svn commit -m "Release $VERSION"
	echo "Released $VERSION: https://wordpress.org/plugins/$SLUG/"
else
	echo "Dry run. Review the changes above, then run: bin/deploy-wporg.sh --commit"
fi
