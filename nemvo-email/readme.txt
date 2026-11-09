=== Nemvo Email ===
Contributors: fpoller
Tags: email, gmail, google workspace, smtp, email log
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: MIT
License URI: https://opensource.org/licenses/MIT

Send all WordPress email through Google Workspace or Gmail with OAuth. Guided setup, a delivery log with statistics, and SPF, DKIM and DMARC checks.

== Description ==

WordPress sends email from the web server, and email from web servers often lands in spam or never arrives. Nemvo Email sends every message through your Google Workspace (or Gmail) account instead, with the Gmail API, so it leaves from Google's servers, signed for your domain.

It replaces nothing you use: every plugin that sends email through `wp_mail()` (WooCommerce, contact forms, membership plugins, WordPress itself) keeps working and goes through Google.

= Set up in a few minutes, with a guide =

A step-by-step guide takes you through it: create an OAuth client in Google Cloud (with direct links and the exact redirect URI to paste), sign in with Google, choose the sender, send a test email, and check your domain's DNS. Every step says what to click and what to do when Google shows an error.

Coming from WP Mail SMTP? The guide offers to import its settings, including a working Gmail connection, so you can skip the Google Cloud steps.

= Delivery log and statistics =

* Every message is logged: recipients, subject, which plugin or theme sent it, how it was sent, and Gmail's message id or the exact error.
* The overview shows sent and failed messages over time, the delivery rate, which plugins send the most, the most common types of email, recipient domains and the most frequent problems. Everything is clickable and opens the matching messages in the log.
* Search and filter the log. Optionally keep the content of messages to read them (in a sandbox) and send them again.
* Google's daily sending limit, and how much of it you used in the last 24 hours.

= SPF, DKIM and DMARC, explained =

Receivers check three DNS records to tell your email from forgeries. The domain check looks them up and tells you, for each, whether it's right for Google, and if not, exactly what to publish, with copy buttons:

* SPF: missing, without Google, two records, too many DNS lookups (it follows includes), or too permissive.
* DKIM: missing (with the steps in the Google Admin console), revoked, or a 1024-bit key.
* DMARC: missing, invalid, and, most importantly, whether your email will actually pass it: sending as an alias in another domain without DKIM fails, and with p=reject that means your email is rejected.
* MX: where replies go. It also names your DNS host (Cloudflare, GoDaddy, Route 53 and many others), so you know where to make the change.

The check runs again every day, and Site Health warns you when something changes.

= Minimal and safe =

* Asks Google only for permission to send email ("gmail.send"), never to read it.
* Uses your own OAuth client: nothing passes through a third party's server.
* Tokens and the client secret are encrypted with keys from wp-config.php, so a copy of the database alone can't be used.
* When Google stops accepting the connection (removed access, changed password), every admin screen says so, with a button to fix it. A daily check finds it before an important email fails.
* Sender rules for Gmail: other senders set by plugins (a contact form using the visitor's address) are replaced by yours, and the visitor becomes the Reply-To.
* Pause sending for staging copies of a live site, with a setting or the NEMVO_EMAIL_PAUSE constant.
* No tracking pixels, no link tracking, no templates: messages go out exactly as WordPress built them.

= For developers =

* Every `wp_mail()` filter and action keeps working, including `phpmailer_init`, `wp_mail_succeeded` and `wp_mail_failed`.
* Actions `nemvo_email_sent`, `nemvo_email_failed` and `nemvo_email_paused`; filter `nemvo_email_log_entry`.
* WP-CLI: `wp nemvo-email status | test | dns | log | import | purge`.
* Configuration through wp-config.php constants or environment variables: `NEMVO_EMAIL_GOOGLE_CLIENT_ID`, `NEMVO_EMAIL_GOOGLE_CLIENT_SECRET`, `NEMVO_EMAIL_KEY`, `NEMVO_EMAIL_PAUSE` (with `_FILE` variants for Docker secrets).

Source code and issue tracker: [github.com/fpoller/nemvo-email](https://github.com/fpoller/nemvo-email).

== Installation ==

1. Install and activate the plugin. Until you connect an account, WordPress keeps sending email the way it did, and the log already shows what it sends.
2. Open **Email › Set up** and follow the guide. You need a Google Workspace account (or a Gmail account) and access to Google Cloud. If WP Mail SMTP's Gmail mailer is set up, import it instead.
3. Publish the DNS records the domain check suggests, if any.
4. Deactivate any other email plugin (SMTP plugins included).

== Frequently Asked Questions ==

= Why do I need my own OAuth client in Google Cloud? =

So that your email goes from your site straight to Google, and nobody else holds a key to your mailbox. It is free and done once; the guide shows every click. A shared client run by a plugin vendor would mean a third party's server in the middle.

= Can it read my email? =

No. It asks Google only for the "gmail.send" permission ("Send email on your behalf"), plus your address. It can't read, change or delete messages.

= Does it work with a personal Gmail account? =

Yes. Create the consent screen with the audience "External" and publish it (in "Testing" the connection stops after 7 days). Google shows an "unverified app" warning when you connect; it's your own app, so click Advanced and continue. Gmail accounts may send 500 messages a day, Google Workspace accounts 2,000.

= Which From address can I use? =

The connected account, or any of its "Send mail as" addresses, aliases or groups it may send as. Gmail replaces any other address with the account's own. By default, senders set by plugins are replaced by yours and become the Reply-To, so a contact form reply still goes to the visitor.

= What if Google stops accepting the connection? =

When access is removed from the Google account, the password changes, or the app is still in "Testing", Google refuses the connection. Messages fail and are logged with the reason, every admin screen shows a notice, and Site Health reports it. Click "Connect again" under Email › Settings. A daily check usually notices it before a message fails.

= I use WP Mail SMTP. How do I switch? =

Install Nemvo Email and open the setup guide: it offers to import WP Mail SMTP's sender settings and, if you use its Gmail mailer with your own OAuth client, the connection itself. Then deactivate WP Mail SMTP (the guide has a button). WP Mail SMTP's "One-Click Setup" uses its own app, which can't be shared, so in that case you create an OAuth client in the guide.

= Will a staging copy of my site send email to my customers? =

Not if you add `define( 'NEMVO_EMAIL_PAUSE', true );` to the staging site's wp-config.php: messages are logged but not sent. Copies with different WordPress security keys can't decrypt the Google connection either.

= Where are the tokens stored, and how? =

In the WordPress options table, encrypted with XSalsa20-Poly1305 (libsodium). The key comes from the WordPress salts in wp-config.php, or from `NEMVO_EMAIL_KEY` if you define it. Changing the salts means connecting again.

= What does the log store? =

Recipients, subject, sender, the plugin that sent the message, size, status and Gmail's message id or error, for 90 days by default (1 to 3,650). The content is only kept if you turn that on. Tools › Export and Erase Personal Data cover the log.

= Does it track opens or clicks? =

No. Messages go out unchanged.

= Can I use Microsoft 365 or another service? =

Not yet: this version supports Google Workspace and Gmail. The plugin is built so other services can be added.

== External services ==

This plugin connects to Google to send the site's email, once you connect a Google account:

* **Google OAuth 2.0** (accounts.google.com, oauth2.googleapis.com): when an administrator clicks "Sign in with Google", the browser goes to Google's consent screen. Afterwards, and about once an hour while email is being sent, the site exchanges the authorization for access tokens, sending your OAuth client ID and secret. Disconnecting sends the token to Google to revoke it.
* **Gmail API** (gmail.googleapis.com): every email WordPress sends, with its recipients, content and attachments, is uploaded to the Gmail API, which delivers it from the connected account. The daily connection check makes one empty request that sends nothing.

Google's [Terms of Service](https://policies.google.com/terms), [Privacy Policy](https://policies.google.com/privacy) and [API Services User Data Policy](https://developers.google.com/terms/api-services-user-data-policy) apply.

The domain check looks up DNS records (TXT, MX and NS) of the sender domain through the server's own resolver, when an administrator asks for it and once a day.

Nothing is sent anywhere else.

== Third-party code ==

* Chart.js 4.5.1, MIT, https://github.com/chartjs/Chart.js, bundled unmodified in `assets/vendor/chart/` (see `assets/vendor/README.txt`), so browsers load nothing from a CDN.

== Screenshots ==

1. The overview: sent and failed email over time, delivery rate, which plugins send email, the most common types of email and problems.
2. The log, with one message opened.
3. The setup guide: creating an OAuth client in Google Cloud, with the redirect URI to paste.
4. The domain check: SPF, DKIM and DMARC, with the exact record to publish.
5. Importing from WP Mail SMTP.
6. Settings: the connection, the sender and the checks.
7. Dark mode.

== Changelog ==

= 1.0.0 =
* First release: sends all WordPress email through Google Workspace or Gmail with the Gmail API and OAuth, with a setup guide, a delivery log with statistics, SPF, DKIM and DMARC checks, import from WP Mail SMTP, Site Health checks and WP-CLI commands.

== Upgrade Notice ==

= 1.0.0 =
First release.
