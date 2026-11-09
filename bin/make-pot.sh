#!/usr/bin/env bash
# Regenerates languages/keep-it-peace.pot, merges it into the .po files and compiles .mo files.
# Requires WP-CLI with the i18n command. Set WP to use another command, e.g.
#   WP="docker run --rm -v $PWD:/app -w /app wordpress:cli wp" bin/make-pot.sh
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
WP="${WP:-wp}"
cd "$ROOT"

$WP i18n make-pot . languages/keep-it-peace.pot \
	--slug=keep-it-peace --domain=keep-it-peace \
	--exclude=vendor,tests,dev,dist,docs,node_modules \
	--headers='{"Report-Msgid-Bugs-To":"https://keep-it-peace.com/","Language-Team":"mackarel Inc."}'

for po in languages/*.po; do
	[ -e "$po" ] || continue
	$WP i18n update-po languages/keep-it-peace.pot "$po"
done
$WP i18n make-mo languages
echo "Done."
