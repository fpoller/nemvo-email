#!/usr/bin/env bash
# Installs a built zip on a brand-new WordPress and checks that it works out of the box: every
# screen loads without PHP errors, with nothing configured email still goes through wp_mail() and
# is logged (here it fails, as the container has no mail server, and the failure is logged),
# the REST API answers, a connection to a stand-in for Google sends through the Gmail API path,
# uninstall cleans up, and Plugin Check reports no errors or warnings. Also lints on PHP 7.4.
# Usage: ./smoke-test.sh dist/nemvo-email-x.y.z.zip
set -euo pipefail
cd "$(dirname "$0")"

zip_file=${1:?usage: $0 dist/nemvo-email-x.y.z.zip}
project=nemvo-email-smoke
port=${SMOKE_PORT:-8067}
url="http://localhost:$port"
work=$(mktemp -d)
trap 'docker compose -p $project -f "$work/compose.yml" down -v >/dev/null 2>&1; rm -rf "$work"' EXIT

fail=0
check() { # name, url, [cookie jar], [expected status]
	local body status
	body=$(curl -s -w '\n%{http_code}' ${3:+-b "$3"} "$2")
	status=${body##*$'\n'}
	if [ "$status" != "${4:-200}" ] || grep -qiE '(fatal error|warning|notice|deprecated)</b>:|critical error' <<<"$body"; then
		echo "FAIL $1 ($status)"; fail=1
	else
		echo "ok   $1"
	fi
}
expect() { # name, condition result ("1" = pass)
	if [ "$2" = 1 ]; then echo "ok   $1"; else echo "FAIL $1"; fail=1; fi
}

echo "== PHP 7.4 lint"
lint=$(docker run --rm -v "$PWD/nemvo-email":/src -w /src php:7.4-cli sh -c 'find . -name "*.php" -exec php -l {} \; | grep -v "^No syntax errors" || true')
if [ -n "$lint" ]; then echo "$lint"; echo "FAIL php 7.4 lint"; fail=1; else echo "ok   php 7.4 lint"; fi

cp "$zip_file" "$work/plugin.zip"
mkdir -p "$work/mu"
cat >"$work/compose.yml" <<YAML
services:
  db:
    image: mariadb:11.4
    environment: { MARIADB_DATABASE: wp, MARIADB_USER: wp, MARIADB_PASSWORD: wp, MARIADB_ROOT_PASSWORD: root }
    healthcheck: { test: ["CMD", "healthcheck.sh", "--connect", "--innodb_initialized"], interval: 3s, retries: 30 }
  wordpress:
    image: wordpress:php8.3-apache
    depends_on: { db: { condition: service_healthy } }
    ports: ["$port:80"]
    environment: { WORDPRESS_DB_HOST: db, WORDPRESS_DB_USER: wp, WORDPRESS_DB_PASSWORD: wp, WORDPRESS_DB_NAME: wp, WORDPRESS_DEBUG: "1" }
    volumes: ["$work/plugin.zip:/tmp/plugin.zip:ro", "$work/mu:/var/www/html/wp-content/mu-plugins"]
YAML

echo "== Starting a clean WordPress"
docker compose -p $project -f "$work/compose.yml" up -d >/dev/null 2>&1
dc() { docker compose -p $project -f "$work/compose.yml" exec -T wordpress "$@"; }
dc bash -c 'curl -fsSL -o /usr/local/bin/wp https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar && chmod +x /usr/local/bin/wp'
wp() { dc runuser -u www-data -- wp --path=/var/www/html "$@"; }
for _ in $(seq 1 60); do wp core is-installed >/dev/null 2>&1 && break; wp db check >/dev/null 2>&1 && break; sleep 1; done
wp core install --url="$url" --title=Smoke --admin_user=admin --admin_password=admin --admin_email=a@example.com --skip-email >/dev/null

echo "== Installing $zip_file"
wp plugin install /tmp/plugin.zip --activate
expect "log table created" "$(wp eval 'echo NemvoEmail\Log::exists() ? 1 : 0;')"

jar="$work/cookies"
curl -s -c "$jar" -b "$jar" -o /dev/null -d "log=admin&pwd=admin&testcookie=1" -b "wordpress_test_cookie=WP%20Cookie%20check" "$url/wp-login.php"
page=$(curl -s -b "$jar" "$url/wp-admin/plugins.php")
expect "activation notice offers the setup" "$(grep -q 'Start setup' <<<"$page" && echo 1)"
for step in client connect sender test domain done; do
	check "setup guide: $step (nothing configured)" "$url/wp-admin/admin.php?page=nemvo-email-setup&step=$step" "$jar"
done
for slug in nemvo-email nemvo-email-log nemvo-email-settings; do
	check "admin $slug (nothing configured)" "$url/wp-admin/admin.php?page=$slug" "$jar"
done
check "site health" "$url/wp-admin/site-health.php" "$jar"
check "dashboard" "$url/wp-admin/index.php" "$jar"

echo "== Email without a connection"
# Plain permalinks (a fresh install's default): the REST API is reached with ?rest_route=.
# A site at http://localhost has the invalid default sender wordpress@localhost, and this
# container has no mail server either: wp_mail() fails, and the failure must be logged.
sent=$(wp eval 'var_dump( wp_mail( "someone@example.org", "Smoke, no connection", "Hello" ) );')
row=$(wp eval '$r = NemvoEmail\Log::query( array() )["rows"][0]; echo $r["status"], "|", $r["to_email"], "|", $r["subject"];')
expect "wp_mail() without a connection is logged ($sent → $row)" "$([ "$row" = 'failed|someone@example.org|Smoke, no connection' ] && echo 1)"
nonce=$(curl -s -b "$jar" "$url/wp-admin/admin-ajax.php?action=rest-nonce")
today=$(wp eval 'echo current_datetime()->format("Y-m-d");')
stats=$(curl -s -b "$jar" -H "X-WP-Nonce: $nonce" "$url/?rest_route=/nemvo-email/v1/stats&from=$today&to=$today")
expect "REST stats: $(cut -c1-70 <<<"$stats")" "$(grep -q '"failed":1' <<<"$stats" && echo 1)"
log=$(curl -s -b "$jar" -H "X-WP-Nonce: $nonce" "$url/?rest_route=/nemvo-email/v1/log")
expect "REST log" "$(grep -q '"total":1' <<<"$log" && echo 1)"
status=$(curl -s -o /dev/null -w '%{http_code}' "$url/?rest_route=/nemvo-email/v1/log")
expect "REST refuses visitors ($status)" "$([ "$status" = 401 ] && echo 1)"

echo "== Email through a stand-in for Google"
# Answers Google's token endpoint and the Gmail API upload the way Google does.
cat >"$work/mu/fake-google.php" <<'PHP'
<?php
add_filter( 'pre_http_request', static function ( $pre, $args, $url ) {
	$json = static function ( $code, $data ) {
		return array( 'headers' => array(), 'body' => wp_json_encode( $data ), 'response' => array( 'code' => $code, 'message' => '' ), 'cookies' => array(), 'filename' => null );
	};
	if ( 0 === strpos( $url, 'https://oauth2.googleapis.com/token' ) ) {
		return $json( 200, array( 'access_token' => 'smoke-access', 'expires_in' => 3599, 'scope' => 'https://www.googleapis.com/auth/gmail.send', 'token_type' => 'Bearer' ) );
	}
	if ( 0 === strpos( $url, 'https://oauth2.googleapis.com/revoke' ) ) {
		update_option( 'smoke_revoked', 1 );
		return $json( 200, array() );
	}
	if ( 0 === strpos( $url, 'https://gmail.googleapis.com/' ) ) {
		if ( 'Bearer smoke-access' !== ( $args['headers']['Authorization'] ?? '' ) ) {
			return $json( 401, array( 'error' => array( 'code' => 401, 'message' => 'Invalid Credentials' ) ) );
		}
		if ( false !== strpos( $url, '/upload/' ) ) {
			update_option( 'smoke_mime', (string) $args['body'] );
			return $json( 200, array( 'id' => '19a0smoke', 'threadId' => '19a0smoke' ) );
		}
		return $json( 400, array( 'error' => array( 'code' => 400, 'message' => 'Recipient address required' ) ) );
	}
	return $pre;
}, 10, 3 );
PHP
dc chown -R www-data:www-data /var/www/html/wp-content/mu-plugins
wp eval '
	$g = NemvoEmail\Providers::get( "google" );
	$g->save_credentials( "123456789012-smoke.apps.googleusercontent.com", "GOCSPX-smoke-secret-01234567" );
	$r = $g->import( array( "client_id" => "123456789012-smoke.apps.googleusercontent.com", "client_secret" => "GOCSPX-smoke-secret-01234567", "refresh_token" => "smoke-refresh", "email" => "site@example.com", "scope" => "https://www.googleapis.com/auth/gmail.send", "source" => "" ) );
	echo true === $r ? "connected\n" : $r->get_error_message();'
out=$(wp nemvo-email test someone@example.org 2>&1 || true)
expect "test email through the Gmail API: $out" "$(grep -q 'Sent via Google Workspace, message id 19a0smoke' <<<"$out" && echo 1)"
mime=$(wp option get smoke_mime)
expect "the uploaded message is complete (To, From, Subject)" "$(grep -q '^To: someone@example.org' <<<"$mime" && grep -q '^From: Smoke <site@example.com>' <<<"$mime" && grep -q '^Subject: Test email from Smoke' <<<"$mime" && echo 1)"
check "overview, connected" "$url/wp-admin/admin.php?page=nemvo-email" "$jar"
check "settings, connected" "$url/wp-admin/admin.php?page=nemvo-email-settings" "$jar"
check "setup guide, connected" "$url/wp-admin/admin.php?page=nemvo-email-setup&step=domain" "$jar"
wp nemvo-email status

echo "== Plugin Check"
wp plugin install plugin-check --activate >/dev/null 2>&1
pcp=$(wp plugin check nemvo-email --format=csv 2>&1 || true)
if grep -q 'No errors found' <<<"$pcp"; then
	echo "ok   plugin check (no errors or warnings)"
else
	echo "$pcp"; echo "FAIL plugin check"; fail=1
fi

echo "== Uninstall"
wp plugin deactivate nemvo-email >/dev/null && wp plugin uninstall nemvo-email >/dev/null
left=$(wp eval 'global $wpdb; echo count( $wpdb->get_col( "SHOW TABLES LIKE \"{$wpdb->prefix}nemvo_email%\"" ) ) + count( $wpdb->get_col( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE \"nemvo_email%\"" ) ) + (int) (bool) wp_next_scheduled( "nemvo_email_daily" );')
expect "uninstall removes the table, options and cron ($left left)" "$([ "$left" = 0 ] && echo 1)"
expect "uninstall revokes the Google authorization" "$(wp option get smoke_revoked 2>/dev/null)"

[ $fail = 0 ] && echo "SMOKE TEST PASSED" || { echo "SMOKE TEST FAILED"; exit 1; }
