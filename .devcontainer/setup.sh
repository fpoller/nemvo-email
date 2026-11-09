#!/usr/bin/env bash
# Prepares the development site: WP-CLI, WordPress, the plugin, and (with the fake Google,
# the default) a connected account with 90 days of made-up log history.
# Safe to run again.
#
# Runs automatically when the dev container starts. Without VS Code:
#   docker compose -f .devcontainer/docker-compose.yml up -d
#   docker compose -f .devcontainer/docker-compose.yml exec wordpress bash /workspace/.devcontainer/setup.sh
set -euo pipefail

WP_ROOT=/var/www/html
SITE_URL="http://localhost:${WP_PORT:-8060}"

if [ ! -x /usr/local/bin/wp ]; then
	echo "Installing WP-CLI..."
	curl -fsSL -o /usr/local/bin/wp-cli.phar https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar
	# Run WP-CLI as www-data so the files it creates stay writable for Apache.
	printf '#!/bin/sh\nexec runuser -u www-data -- php /usr/local/bin/wp-cli.phar --path=%s "$@"\n' "$WP_ROOT" >/usr/local/bin/wp
	chmod +x /usr/local/bin/wp
fi

echo "Waiting for the WordPress files and the database..."
for _ in $(seq 1 60); do
	[ -f "$WP_ROOT/wp-config.php" ] && [ -f "$WP_ROOT/wp-includes/version.php" ] && wp db check >/dev/null 2>&1 && break
	sleep 1
done

if ! wp core is-installed 2>/dev/null; then
	wp core install --url="$SITE_URL" --title="Northwind Studio" --admin_user=admin --admin_password=admin --admin_email=admin@example.com --skip-email
	wp option update timezone_string Europe/Stockholm
	wp option update blogdescription "Linen and ceramics, made to last"
	wp rewrite structure '/%postname%/'
fi

wp plugin activate nemvo-email

if [ "${NEMVO_EMAIL_GOOGLE_MOCK:-1}" = "1" ]; then
	# A connected account through the fake Google, as if the guide had been followed.
	wp eval '
		$google = NemvoEmail\Providers::get( "google" );
		if ( ! $google->is_connected() ) {
			$google->save_credentials( "123456789012-devcontainer0mock.apps.googleusercontent.com", "GOCSPX-devcontainer-mock-secret" );
			$google->import( array( "client_id" => "123456789012-devcontainer0mock.apps.googleusercontent.com", "client_secret" => "GOCSPX-devcontainer-mock-secret", "refresh_token" => "mock-refresh:hello@example.com", "email" => "hello@example.com", "scope" => NemvoEmail\Providers\Google::SCOPE_SEND, "source" => "" ) );
			NemvoEmail\Settings::update( array( "sender_saved" => 1, "test_sent" => time(), "setup_done" => 1 ) );
			NemvoEmail\Dns::check( "example.com", $google );
			echo "Connected the fake Google account hello@example.com.\n";
		}
	'
	if [ "$(wp eval 'echo (int) $GLOBALS["wpdb"]->get_var( "SELECT COUNT(*) FROM " . NemvoEmail\Log::table() );')" = "0" ]; then
		wp nemvo-email seed --days=90
	fi
fi

cat <<EOF

Ready.
  Site       $SITE_URL
  Overview   $SITE_URL/wp-admin/admin.php?page=nemvo-email   (admin / admin)
  Setup      $SITE_URL/wp-admin/admin.php?page=nemvo-email-setup
  Mailpit    http://localhost:${MAILPIT_PORT:-8065}

Start over without a connection:  wp eval 'NemvoEmail\Providers::current()->disconnect(); NemvoEmail\Settings::update( array( "setup_done" => 0, "sender_saved" => 0, "test_sent" => 0 ) );'
EOF
wp nemvo-email status
