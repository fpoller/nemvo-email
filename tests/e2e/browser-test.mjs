// Browser tests against the dev container (fake Google, Mailpit). An administrator goes through
// the whole setup guide from scratch, sends a test email that must arrive in Mailpit, then uses
// the overview, the log, the message dialog, resending, the settings, a broken connection, the
// WP Mail SMTP import, dark mode and a phone-sized screen. Any console error, uncaught exception,
// failed request or request to another site fails the run. The plugin's settings are restored
// afterwards. Screenshots go to tests/e2e/screenshots/.
//
//   cd tests/e2e && npm install && npm test
//
// BASE (default http://localhost:8060), MAILPIT (http://localhost:8065) and CHROME can be set.
import { execFileSync } from 'node:child_process';
import { existsSync, mkdirSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import puppeteer from 'puppeteer-core';

const here = dirname(fileURLToPath(import.meta.url));
const BASE = process.env.BASE || 'http://localhost:8060';
const MAILPIT = process.env.MAILPIT || 'http://localhost:8065';
const CHROME = process.env.CHROME || ['/Applications/Google Chrome.app/Contents/MacOS/Google Chrome', '/usr/bin/google-chrome', '/usr/bin/chromium'].find((p) => existsSync(p));
const SHOTS = join(here, 'screenshots');
mkdirSync(SHOTS, { recursive: true });

let failed = 0;
const ok = (cond, msg) => {
	console.log((cond ? 'ok   ' : 'FAIL ') + msg);
	if (!cond) {
		failed++;
	}
};
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const wp = (...args) => execFileSync('docker', ['compose', '-f', join(here, '../../.devcontainer/docker-compose.yml'), 'exec', '-T', 'wordpress', 'wp', ...args], { encoding: 'utf8' }).trim();
const php = (code) => wp('eval', code);
const admin = (page, extra = '') => `${BASE}/wp-admin/admin.php?page=${page}${extra}`;
const mailpit = async (query) => (await (await fetch(`${MAILPIT}/api/v1/search?query=${encodeURIComponent(query)}`)).json()).messages || [];

// Keep the dev site's state.
const saved = php('echo wp_json_encode( array_map( static function ( $o ) { return get_option( $o, null ); }, array( "nemvo_email_settings" => "nemvo_email_settings", "nemvo_email_google" => "nemvo_email_google", "nemvo_email_dns" => "nemvo_email_dns", "wp_mail_smtp" => "wp_mail_smtp", "active_plugins" => "active_plugins" ) ) );');
const lastId = parseInt(php('echo (int) $GLOBALS["wpdb"]->get_var( "SELECT MAX(id) FROM " . NemvoEmail\\Log::table() );'), 10) || 0;
const restore = () => {
	// Remove what the test logged, then put the settings back.
	php(`$max = (int) $GLOBALS["wpdb"]->get_var( "SELECT MAX(id) FROM " . NemvoEmail\\Log::table() ); if ( $max > ${lastId} ) { NemvoEmail\\Log::delete( range( ${lastId + 1}, $max ) ); }`);
	php(`foreach ( json_decode( base64_decode( '${Buffer.from(saved).toString('base64')}' ), true ) as $o => $v ) { null === $v ? delete_option( $o ) : update_option( $o, $v ); }`);
};

const problems = [];
function watch(page, label) {
	page.on('console', (m) => m.type() === 'error' && problems.push(`${label}: console: ${m.text()}`));
	page.on('pageerror', (e) => problems.push(`${label}: exception: ${e.message}`));
	page.on('requestfailed', (r) => !/favicon/.test(r.url()) && problems.push(`${label}: request failed: ${r.url()} ${r.failure() && r.failure().errorText}`));
	page.on('response', (r) => r.status() >= 400 && !/favicon/.test(r.url()) && problems.push(`${label}: HTTP ${r.status()} ${r.url()}`));
	page.on('request', (r) => {
		const u = new URL(r.url());
		// The admin bar avatar comes from Gravatar: that is WordPress itself, not this plugin.
		if (!/^(localhost|127\.0\.0\.1)$/.test(u.hostname) && !/gravatar\.com$/.test(u.hostname) && !/^(data|blob|about):/.test(u.protocol)) {
			problems.push(`${label}: external request: ${r.url()}`);
		}
	});
}

const text = (page, selector) => page.$eval(selector, (el) => el.textContent.replace(/\s+/g, ' ').trim()).catch(() => '');
const submit = async (page, selector) => Promise.all([page.waitForNavigation({ waitUntil: 'networkidle0' }), page.click(selector)]);
const noOverflow = (page) => page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth);

const browser = await puppeteer.launch({ executablePath: CHROME, headless: true, args: ['--window-size=1440,1000', '--no-first-run'] });
try {
	const context = browser.defaultBrowserContext();
	await context.overridePermissions(BASE, ['clipboard-read', 'clipboard-write', 'clipboard-sanitized-write']);
	const page = await browser.newPage();
	watch(page, 'admin');
	await page.setViewport({ width: 1440, height: 1000 });

	/* ---------------- From scratch ---------------- */
	php('NemvoEmail\\Providers::current()->disconnect( false ); delete_option( "nemvo_email_google" ); delete_option( "nemvo_email_dns" ); delete_option( "nemvo_email_settings" ); delete_option( "wp_mail_smtp" ); deactivate_plugins( "wp-mail-smtp/wp_mail_smtp.php" );');
	await fetch(`${MAILPIT}/api/v1/messages`, { method: 'DELETE' });

	await page.goto(`${BASE}/wp-login.php`, { waitUntil: 'networkidle0' });
	await page.type('#user_login', 'admin');
	await page.type('#user_pass', 'admin');
	await submit(page, '#wp-submit');

	const menuHref = await page.$eval('#toplevel_page_nemvo-email > a', (a) => a.getAttribute('href'));
	ok(/page=nemvo-email-setup/.test(menuHref), `before setup, the Email menu opens the guide (${menuHref})`);
	const submenu = await page.$$eval('#toplevel_page_nemvo-email .wp-submenu a', (as) => as.map((a) => a.textContent.trim()).join(','));
	ok('Set up,Overview,Log,Settings' === submenu, `submenu: ${submenu}`);

	await page.goto(admin('nemvo-email'), { waitUntil: 'networkidle0' });
	ok(/web server/.test(await text(page, '.nve-status')), 'overview before setup: "sent by the web server" with a setup button');

	/* Step 1: OAuth client */
	await page.goto(admin('nemvo-email-setup'), { waitUntil: 'networkidle0' });
	ok((await text(page, '.nve-stepper .is-current')).startsWith('1') || /Google Cloud/.test(await text(page, '.nve-stepper .is-current')), 'the guide starts at Google Cloud');
	const redirect = await page.$eval('.nve-copyfield input', (i) => i.value);
	ok(redirect === `${BASE}/wp-admin/admin-post.php?action=nemvo_email_oauth`, 'the redirect URI to paste is shown');
	await page.click('.nve-copyfield button');
	await sleep(200);
	ok((await page.evaluate(() => navigator.clipboard.readText())) === redirect && /Copied/.test(await text(page, '.nve-copyfield button')), 'Copy puts it on the clipboard');
	ok((await page.$$eval('.nve-guide a[target="_blank"]', (as) => as.map((a) => a.href))).some((h) => h.includes('gmail.googleapis.com')), 'links to enable the Gmail API');
	await page.screenshot({ path: join(SHOTS, 'setup-client.png'), fullPage: true });

	await page.type('input[name="client_id"]', 'not-a-client-id');
	await page.type('input[name="client_secret"]', 'GOCSPX-abcdefghijklmnopqrstuv');
	await submit(page, '.nve-step button[type="submit"]');
	ok(/doesn't look like a client ID/.test(await text(page, '.nve-flash')), 'a wrong client ID is explained');
	await page.$eval('input[name="client_id"]', (i) => (i.value = ''));
	await page.type('input[name="client_id"]', '123456789012-e2etest.apps.googleusercontent.com');
	await page.type('input[name="client_secret"]', 'GOCSPX-abcdefghijklmnopqrstuv');
	await submit(page, '.nve-step button[type="submit"]');
	ok(/step=connect/.test(page.url()) && /saved/i.test(await text(page, '.nve-flash')), 'client saved, on to Connect');

	/* Step 2: connect (the fake Google skips the consent screen) */
	await page.screenshot({ path: join(SHOTS, 'setup-connect.png'), fullPage: true });
	await submit(page, 'button.nve-google');
	ok(/step=sender/.test(page.url()) && /hello@example\.com/.test(await text(page, '.nve-flash')), 'signed in with Google: connected as hello@example.com');

	/* Step 3: sender */
	ok((await page.$eval('input[name="nve[from_email]"]', (i) => i.placeholder)) === 'hello@example.com', 'sender defaults to the account');
	await page.type('input[name="nve[from_name]"]', 'Northwind Studio');
	await submit(page, '.nve-step button[type="submit"]');
	ok(/step=test/.test(page.url()), 'sender saved, on to Test');

	/* Step 4: test email, which must arrive in Mailpit */
	await page.$eval('input[name="to"]', (i) => (i.value = 'tester@example.org'));
	await page.click('form[data-nve-test] button[type="submit"]');
	await page.waitForSelector('.nve-result:not(.is-pending)', { timeout: 20000 });
	const result = await text(page, '.nve-result');
	ok(/Sent to tester@example.org/.test(result) && /Google Workspace accepted it as message/.test(result), `test email sent: ${result.slice(0, 90)}`);
	await sleep(500);
	const arrived = await mailpit('to:tester@example.org');
	ok(arrived.length === 1 && arrived[0].From.Address === 'hello@example.com' && arrived[0].From.Name === 'Northwind Studio', 'the test email arrived in Mailpit, from "Northwind Studio <hello@example.com>"');
	await page.screenshot({ path: join(SHOTS, 'setup-test.png'), fullPage: true });
	await submit(page, '.nve-test__result a.button-primary');

	/* Step 5: domain */
	ok(/step=domain/.test(page.url()), 'on to Domain');
	const states = await page.$$eval('.nve-dns__list > li', (lis) => lis.map((li) => li.className));
	ok(states.join(',') === 'is-good,is-good,is-warning,is-good', `SPF, DKIM good, DMARC missing, MX good (${states.join(',')})`);
	ok(/Cloudflare/.test(await text(page, '.nve-dns__meta')), 'names where the DNS is hosted');
	const dmarc = await page.$eval('#nve-dns-dmarc .nve-record__value code', (c) => c.textContent);
	ok(dmarc === 'v=DMARC1; p=none; rua=mailto:hello@example.com', `the record to add: ${dmarc}`);
	await page.click('#nve-dns-dmarc .nve-record__value .nve-copy');
	await sleep(200);
	ok((await page.evaluate(() => navigator.clipboard.readText())) === dmarc, 'copied');
	await page.screenshot({ path: join(SHOTS, 'setup-domain.png'), fullPage: true });
	await submit(page, '.nve-step .nve-actions a.button-primary');

	/* Done */
	ok(/step=done/.test(page.url()) && /set up/i.test(await text(page, '#nve-step-title')), 'done step');
	await submit(page, '.nve-step form button.button-primary');
	ok(page.url().endsWith('page=nemvo-email'), 'Finish opens the overview');
	ok(!(await page.$('#toplevel_page_nemvo-email a[href*="nemvo-email-setup"]')), 'the guide left the menu');
	const guide = await page.goto(admin('nemvo-email-setup', '&step=domain'), { waitUntil: 'networkidle0' });
	ok(guide.status() === 200 && (await page.$('#toplevel_page_nemvo-email.wp-has-current-submenu')), 'and is still reachable from Settings, with the Email menu open');

	/* ---------------- Overview ---------------- */
	php('NemvoEmail\\Seeder::run( 60 );');
	await page.goto(admin('nemvo-email'), { waitUntil: 'networkidle0' });
	await page.waitForSelector('#nve-app canvas');
	await sleep(500);
	ok(/Sending through Google Workspace/.test(await text(page, '.nve-status')) && (await page.$$('.nve-chips .nve-badge')).length === 3, 'status: sending through Google, SPF/DKIM/DMARC chips');
	const tiles = await page.$$eval('.nve-tile__value', (els) => els.map((e) => e.textContent));
	ok(tiles.length === 4 && parseInt(tiles[0].replace(/\D/g, ''), 10) > 100, `tiles: ${tiles.join(' | ')}`);
	ok((await page.$$('.nve-grid .nve-row')).length >= 10 && (await page.$$('.nve-errors li')).length >= 1, 'breakdowns and problems are listed');
	const box = await page.$eval('#nve-app canvas', (c) => {
		const r = c.getBoundingClientRect();
		return { x: r.x + r.width * 0.75, y: r.y + r.height * 0.6 };
	});
	await page.mouse.move(box.x, box.y);
	await sleep(300);
	ok(!(await page.$eval('.nve-tip', (t) => t.hidden)) && /Sent/.test(await text(page, '.nve-tip')), 'chart tooltip');
	await page.mouse.move(5, 5);
	await page.screenshot({ path: join(SHOTS, 'overview.png'), fullPage: true });
	await page.click('.nve-seg button[data-range="7d"]');
	await sleep(600);
	ok(/range=7d/.test(page.url()) && (await page.$$eval('.nve-seg button[aria-pressed="true"]', (b) => b.map((x) => x.dataset.range))).includes('7d'), '7 days: the period is in the address');
	await page.click('.nve-seg button[data-range="today"]');
	await sleep(600);
	ok((await page.$$eval('#nve-app canvas', (c) => c.length)) === 1, 'today: hourly chart');

	await page.click('.nve-seg button[data-range="30d"]');
	await sleep(600);
	const sourceLink = await page.$eval('.nve-grid .nve-panel:first-child .nve-row__label', (a) => ({ href: a.href, label: a.textContent }));
	await page.goto(sourceLink.href, { waitUntil: 'networkidle0' });
	await page.waitForSelector('.nve-table--log tbody tr td');
	await sleep(300);
	const sentBy = await page.$$eval('.nve-table--log tbody tr td:nth-child(5)', (tds) => [...new Set(tds.map((td) => td.textContent))]);
	ok(sentBy.length === 1 && sentBy[0] === sourceLink.label, `clicking "${sourceLink.label}" opens the log filtered to it`);

	/* ---------------- Log ---------------- */
	await page.goto(admin('nemvo-email-log'), { waitUntil: 'networkidle0' });
	await page.waitForSelector('.nve-table--log tbody tr td');
	const total = await text(page, '.nve-pager span');
	ok(/of [\d,]+/.test(total) && (await page.$$('.nve-table--log tbody tr')).length === 50, `log: ${total}`);
	await page.click('.nve-seg button[data-status="failed"]');
	await sleep(700);
	const statuses = await page.$$eval('.nve-table--log tbody .nve-status-pill', (p) => [...new Set(p.map((x) => x.textContent))]);
	ok(statuses.length === 1 && statuses[0] === 'Failed', 'filter: failed only');
	await page.click('.nve-seg button[data-status=""]');
	await page.type('.nve-search', 'tester@example.org');
	await sleep(900);
	ok((await page.$$('.nve-table--log tbody tr')).length === 1, 'search by recipient');
	await page.click('.nve-table--log tbody tr');
	await page.waitForSelector('.nve-dialog[open] .nve-meta');
	ok(/Test email from/.test(await text(page, '#nve-dialog-title')) && /hello@example\.com/.test(await text(page, '.nve-meta')), 'the dialog shows the message');
	ok(/isn't kept/.test(await text(page, '.nve-dialog__body')), 'content not kept: says how to keep it');
	ok(/entry=\d+/.test(page.url()), 'the open entry is in the address');
	await page.screenshot({ path: join(SHOTS, 'log-dialog.png') });
	await page.keyboard.press('Escape');
	await sleep(200);
	ok(!(await page.$('.nve-dialog[open]')), 'Escape closes it');

	/* ---------------- Plain permalinks: the REST root has "?rest_route=" ---------------- */
	const permalinks = wp('option', 'get', 'permalink_structure');
	wp('rewrite', 'structure', '');
	try {
		await page.goto(admin('nemvo-email'), { waitUntil: 'networkidle0' });
		await page.waitForSelector('#nve-app canvas');
		ok((await page.$$('.nve-tile')).length === 4, 'plain permalinks: overview loads');
		await page.goto(admin('nemvo-email-log', '#status=failed'), { waitUntil: 'networkidle0' });
		await page.waitForSelector('.nve-table--log tbody tr td');
		await sleep(300);
		const plain = await page.$$eval('.nve-table--log tbody .nve-status-pill', (p) => [...new Set(p.map((x) => x.textContent))]);
		ok(plain.length === 1 && plain[0] === 'Failed', 'plain permalinks: filtered log loads');
	} finally {
		wp('rewrite', 'structure', permalinks);
	}

	/* ---------------- Settings: keep content, send, resend ---------------- */
	await page.goto(admin('nemvo-email-settings'), { waitUntil: 'networkidle0' });
	await page.click('input[name="nve[log_content]"]');
	await submit(page, '#sending button[type="submit"]');
	ok(/Settings saved/.test(await text(page, '.nve-flash')), 'content kept from now on');
	await page.$eval('#test input[name="to"]', (i) => (i.value = 'keep@example.org'));
	await page.click('#test button[type="submit"]');
	await page.waitForSelector('#test .nve-result:not(.is-pending)');
	await submit(page, '#test .nve-result a');
	await page.waitForSelector('.nve-dialog[open] iframe');
	// The sandbox gives the frame an opaque origin: the page itself can't reach into it.
	ok(await page.$eval('.nve-dialog iframe', (f) => '' === f.getAttribute('sandbox') && null === f.contentDocument), 'kept content is shown in a fully sandboxed frame');
	const frameText = await (await (await page.$('.nve-dialog iframe')).contentFrame()).evaluate(() => document.body.textContent);
	ok(/If it arrived in your inbox/.test(frameText), 'the message renders in it');
	page.once('dialog', (d) => d.accept());
	await page.evaluate(() => [...document.querySelectorAll('.nve-dialog__actions button')].find((b) => /Resend/.test(b.textContent)).click());
	const resent = await page.waitForFunction(() => /Sent again/.test(document.querySelector('.nve-dialog__actions [role="status"]').textContent), { timeout: 15000 }).then(
		() => true,
		() => false
	);
	ok(resent, 'Resend (after a confirmation) sends it again');
	await sleep(500);
	ok((await mailpit('to:keep@example.org')).length === 2, 'both copies arrived in Mailpit');
	await page.keyboard.press('Escape');

	/* ---------------- A broken connection ---------------- */
	php('$o = get_option( "nemvo_email_google" ); $o["refresh_token"] = NemvoEmail\\Crypto::encrypt( "mock-refresh:hello@example.com|revoked" ); $o["expires"] = 0; update_option( "nemvo_email_google", $o );');
	await page.goto(admin('nemvo-email-settings'), { waitUntil: 'networkidle0' });
	await submit(page, '#connection form input[value="nemvo_email_check"] ~ button');
	ok(/no longer accepts/.test(await text(page, '.nve-flash')), 'Check connection explains a revoked authorization');
	await page.goto(`${BASE}/wp-admin/index.php`, { waitUntil: 'networkidle0' });
	ok(/not being delivered/.test(await text(page, '.notice-error')), 'a notice on every admin screen says email isn\'t delivered');
	await page.goto(admin('nemvo-email'), { waitUntil: 'networkidle0' });
	ok(/refuses the connection/.test(await text(page, '.nve-status.is-critical')), 'overview: critical status');
	await page.goto(admin('nemvo-email-settings'), { waitUntil: 'networkidle0' });
	await submit(page, '#connection form input[value="nemvo_email_connect"] ~ button');
	ok(/Connected/.test(await text(page, '.nve-flash')) && /Connected/.test(await text(page, '#connection .nve-state')), 'Connect again fixes it');

	/* ---------------- Import from WP Mail SMTP ---------------- */
	if (/wp-mail-smtp/.test(wp('plugin', 'list', '--field=name'))) {
		wp('plugin', 'activate', 'wp-mail-smtp');
		php('update_option( "wp_mail_smtp", array( "mail" => array( "mailer" => "gmail", "from_email" => "website@example.com", "from_name" => "Website", "from_email_force" => true, "from_name_force" => false ), "gmail" => array( "client_id" => "123456789012-wpms.apps.googleusercontent.com", "client_secret" => "GOCSPX-wpms-secret-0123456789", "refresh_token" => "1//wpms-refresh", "user_details" => array( "email" => "website@example.com" ) ) ) ); NemvoEmail\\Providers::current()->disconnect( false ); delete_option( "nemvo_email_settings" );');
		await page.goto(admin('nemvo-email-setup'), { waitUntil: 'networkidle0' });
		ok(/sends through Gmail as website@example.com/.test(await text(page, '.nve-callout')), 'the guide offers the WP Mail SMTP import');
		await page.screenshot({ path: join(SHOTS, 'setup-import.png'), fullPage: true });
		await submit(page, '.nve-callout button');
		ok(/step=sender/.test(page.url()) && /now sends email as website@example.com/.test(await text(page, '.nve-flash')), 'imported and connected, straight on to Sender');
		await page.goto(admin('nemvo-email-setup', '&step=done'), { waitUntil: 'networkidle0' });
		ok(/still active/.test(await text(page, '.nve-callout')), 'done step suggests deactivating WP Mail SMTP');
		await submit(page, '.nve-callout button');
		ok(/deactivated/.test(await text(page, '.nve-flash')) && !/wp-mail-smtp/.test(wp('plugin', 'list', '--status=active', '--field=name')), 'WP Mail SMTP deactivated');
	} else {
		console.log('skip WP Mail SMTP import (plugin not installed in the dev site)');
	}

	/* ---------------- Dark mode, phone ---------------- */
	await page.goto(admin('nemvo-email'), { waitUntil: 'networkidle0' });
	await page.waitForSelector('#nve-app canvas');
	const themes = [];
	for (let i = 0; i < 3; i++) {
		await page.click('[data-nve-theme-toggle]');
		await sleep(200);
		themes.push(await page.evaluate(() => document.body.getAttribute('data-nve-theme') || 'auto'));
	}
	ok(themes.join(',') === 'light,dark,auto', `theme switch cycles: ${themes.join(',')}`);
	await page.click('[data-nve-theme-toggle]');
	await page.click('[data-nve-theme-toggle]');
	await sleep(400);
	ok((await page.evaluate(() => getComputedStyle(document.body).getPropertyValue('--nve-surface').trim())) === '#1a1a19' && (await page.$('#nve-app canvas')), 'dark mode, chart redrawn');
	await page.screenshot({ path: join(SHOTS, 'overview-dark.png'), fullPage: true });
	await page.click('[data-nve-theme-toggle]');

	await page.setViewport({ width: 390, height: 844 });
	for (const slug of ['nemvo-email', 'nemvo-email-log', 'nemvo-email-settings', 'nemvo-email-setup']) {
		await page.goto(admin(slug), { waitUntil: 'networkidle0' });
		await sleep(400);
		ok(await noOverflow(page), `${slug}: no sideways scrolling at 390 px`);
	}
	await page.goto(admin('nemvo-email'), { waitUntil: 'networkidle0' });
	await sleep(400);
	await page.screenshot({ path: join(SHOTS, 'overview-phone.png'), fullPage: true });

	problems.forEach((p) => console.log('     ' + p));
	ok(!problems.length, 'no console errors, failed requests or requests to other sites');
} finally {
	await browser.close();
	restore();
	php('delete_transient( NemvoEmail\\Log::FAILED_TRANSIENT );');
}

console.log(failed ? `\n${failed} failed` : '\nall passed');
process.exit(failed ? 1 : 0);
