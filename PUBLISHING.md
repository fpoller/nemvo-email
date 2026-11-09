# Publishing to WordPress.org

How Nemvo Email gets into the WordPress.org plugin directory, and how new versions reach it afterwards.

## 1. Before submitting

- **WordPress.org account.** The account that submits becomes the plugin's owner. `Contributors:` in `nemvo-email/readme.txt` must list WordPress.org usernames (currently `fpoller`).
- **Two-factor authentication.** WordPress.org requires 2FA for accounts with commit access. Turn it on under *Edit profile → Account & Security*.
- **Checks.** Build the zip and run the smoke test. The smoke test includes Plugin Check, the same tool the review team runs first:

  ```sh
  ./build.sh
  ./smoke-test.sh dist/nemvo-email-1.0.0.zip
  ```

  Optionally, paste `readme.txt` into the [readme validator](https://wordpress.org/plugins/developers/readme-validator/).

Plugin Check reports no errors and no warnings, and the code passes the WordPress Coding Standards (`phpcs.xml.dist`).

## 2. Submit

1. Sign in at [wordpress.org/plugins/developers/add](https://wordpress.org/plugins/developers/add/).
2. Upload `dist/nemvo-email-1.0.0.zip` and accept the guidelines.
3. The slug is taken from the plugin name: `nemvo-email`. It shows on the confirmation page. You can still ask the reviewers to change it, but once the plugin is approved it is permanent.

An automated scan runs right away. Then the plugin waits in the review queue; the page shows how many plugins are ahead of it. Reviews take anywhere from a few days to several weeks.

## 3. During review

The review team emails you from `plugins@wordpress.org`. Reply to that email, and don't open a new thread. If they ask for changes:

1. Make the changes. Keep the version at 1.0.0, because nothing has been published yet.
2. Run `./build.sh` and `./smoke-test.sh` again.
3. Upload the new zip on the same [Add your plugin](https://wordpress.org/plugins/developers/add/) page, then reply to the email saying what you changed.

Reviewers often ask about the following. The answers are already in `readme.txt` or the code:

| Topic | Where it's answered |
|---|---|
| Calls to external services (Google OAuth, the Gmail API, DNS lookups) | *External services* section, with links to Google's terms and privacy policy |
| Trademarks ("Gmail", "Google Workspace") | Only in the description and tags, as what the plugin works with; the name and slug are "Nemvo Email" |
| Minified or third-party code | Chart.js 4.5.1, unmodified (*Third-party code*, `assets/vendor/README.txt`). Everything else is unminified source. |
| Licenses | MIT for the plugin and Chart.js. |
| Replacing PHPMailer / wp_mail() | It doesn't redefine `wp_mail()`: it sets the global `$phpmailer` to a subclass at `pre_wp_mail`, so every WordPress filter keeps working (`class-mailer.php`, `class-message.php`). Another plugin's mailer is only replaced once the user connects an account. |
| Storing OAuth tokens | Encrypted with libsodium, the key from the WordPress salts (`class-crypto.php`). Only the `gmail.send` scope is requested. |
| `debug_backtrace()` | Names the plugin or theme that called `wp_mail()` for the log; annotated in `Mailer::caller()`. |
| `.apps.googleusercontent` + `.com` | The format of a Google OAuth client ID, split in two because Plugin Check takes the domain for a CDN; nothing is loaded from it (`Providers\Google::CLIENT_SUFFIX`). |

## 4. After approval

You get Subversion (SVN) access to `https://plugins.svn.wordpress.org/nemvo-email/`. SVN doesn't use your normal password: set an SVN password under [Edit profile → Account & Security](https://profiles.wordpress.org/me/profile/edit/group/3/?screen=svn-password).

The plugin goes live when version 1.0.0 is committed to SVN. Use one of the two ways below, not both.

### Option A: GitHub Actions (recommended)

The release workflow (`.github/workflows/release.yml`) already contains a WordPress.org job, which only runs once you switch it on. In the repository on GitHub:

1. **Settings → Secrets and variables → Actions → Secrets:** add `SVN_USERNAME` (your WordPress.org username) and `SVN_PASSWORD` (the SVN password).
2. **Settings → Secrets and variables → Actions → Variables:** add `WPORG_DEPLOY` with the value `true`.
3. **Actions → Release:** open the run for `v1.0.0` and choose **Re-run all jobs**.

The job copies `nemvo-email/` to `trunk/`, creates `tags/1.0.0` and uploads `.wordpress-org/` (icons, banners, screenshots) to `assets/`.

### Option B: by hand with SVN

```sh
svn checkout https://plugins.svn.wordpress.org/nemvo-email wporg && cd wporg
rsync -a --delete ../nemvo-email/ trunk/
mkdir -p assets && cp ../.wordpress-org/* assets/
svn add --force trunk assets
svn cp trunk tags/1.0.0
svn propset svn:mime-type image/png assets/*.png
svn propset svn:mime-type image/svg+xml assets/*.svg
svn commit -m "Nemvo Email 1.0.0" --username <wordpress.org username>
```

The listing at `https://wordpress.org/plugins/nemvo-email/` updates within a few minutes. Search can take a few hours to pick it up.

## 5. Later releases

1. Set the new version in `nemvo-email/nemvo-email.php` (header and `NEMVO_EMAIL_VERSION`) and in `readme.txt` (`Stable tag:`). Add a changelog entry to `readme.txt` and `README.md`. `build.sh` refuses to build when these differ.
2. If the database schema changed, raise `Install::DB_VERSION` (`dbDelta` adds new columns and keys on the next page load).
3. If strings changed, regenerate `languages/nemvo-email.pot`:
   `docker compose -f .devcontainer/docker-compose.yml exec -T wordpress wp i18n make-pot /var/www/html/wp-content/plugins/nemvo-email /var/www/html/wp-content/plugins/nemvo-email/languages/nemvo-email.pot --domain=nemvo-email --exclude=assets/vendor`
4. Run the tests (README → Tests), `./build.sh` and `./smoke-test.sh dist/nemvo-email-<version>.zip`.
5. Commit, tag and push:

   ```sh
   git tag -a v1.0.1 -m "Nemvo Email 1.0.1"
   git push origin main v1.0.1
   ```

The release workflow creates the GitHub release with the zip, and, with `WPORG_DEPLOY` on, commits the version to WordPress.org. Sites that have the plugin installed are then offered the update in wp-admin.

When a new WordPress version comes out, test the plugin with it and raise `Tested up to:` in `readme.txt`. Release this as a normal patch version, or commit only `readme.txt` to SVN `trunk/` and `tags/<current version>/`.

## Assets

`.wordpress-org/` holds what the directory shows besides the readme:

| File | Size | Shown as |
|---|---|---|
| `icon.svg`, `icon-256x256.png`, `icon-128x128.png` | 256 and 128 px | Plugin icon |
| `banner-1544x500.png`, `banner-772x250.png` | | Header banner |
| `screenshot-1.png` … `screenshot-7.png` | | Screenshots, captioned by `== Screenshots ==` in `readme.txt` |

They are generated from the dev container: `cd tests/e2e && npm install && node assets.mjs`. The script makes the example messages it needs and puts the dev site back afterwards.
