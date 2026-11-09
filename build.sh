#!/usr/bin/env bash
# Builds an installable plugin zip: dist/nemvo-email-<version>.zip
set -euo pipefail
cd "$(dirname "$0")"

plugin=nemvo-email
version=$(sed -n 's/^ \* Version: *//p' $plugin/$plugin.php)
constant=$(sed -n "s/.*NEMVO_EMAIL_VERSION', '\([^']*\)'.*/\1/p" $plugin/$plugin.php)
stable=$(sed -n 's/^Stable tag: *//p' $plugin/readme.txt)
if [ "$version" != "$constant" ] || [ "$version" != "$stable" ]; then
	echo "Version mismatch: header $version, constant $constant, readme.txt Stable tag $stable" >&2
	exit 1
fi
if ! grep -q "^= $version =" $plugin/readme.txt; then
	echo "readme.txt has no changelog entry for $version" >&2
	exit 1
fi

mkdir -p dist
zip_file="dist/$plugin-$version.zip"
rm -f "$zip_file"
zip -qr "$zip_file" $plugin -x '*.DS_Store' -x '*/.git*' -x '*/node_modules/*'
echo "$zip_file"
