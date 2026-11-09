// Regenerates .wordpress-org/: icons and banners from icon.svg, and the seven screenshots listed
// in readme.txt, taken from the dev container (http://localhost:8060) with its made-up history.
// Settings, the log and plugins are put back afterwards.
//
//   cd tests/e2e && npm install && node assets.mjs
import { execFileSync } from 'node:child_process';
import { existsSync, readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import puppeteer from 'puppeteer-core';

const here = dirname(fileURLToPath(import.meta.url));
const OUT = join(here, '../../.wordpress-org');
const BASE = process.env.BASE || 'http://localhost:8060';
const CHROME = process.env.CHROME || ['/Applications/Google Chrome.app/Contents/MacOS/Google Chrome', '/usr/bin/google-chrome', '/usr/bin/chromium'].find((p) => existsSync(p));
const wp = (...args) => execFileSync('docker', ['compose', '-f', join(here, '../../.devcontainer/docker-compose.yml'), 'exec', '-T', 'wordpress', 'wp', ...args], { encoding: 'utf8' }).trim();
const php = (code) => wp('eval', code);
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const admin = (page, extra = '') => `${BASE}/wp-admin/admin.php?page=${page}${extra}`;

const options = ['nemvo_email_settings', 'nemvo_email_google', 'nemvo_email_dns', 'wp_mail_smtp', 'active_plugins', 'blogname'];
const saved = php(`echo base64_encode( wp_json_encode( array_combine( ${JSON.stringify(options).replace(/^\[/, 'array(').replace(/\]$/, ')')}, array_map( static function ( $o ) { return get_option( $o, null ); }, ${JSON.stringify(options).replace(/^\[/, 'array(').replace(/\]$/, ')')} ) ) ) );`);
const lastId = parseInt(php('echo (int) $GLOBALS["wpdb"]->get_var( "SELECT MAX(id) FROM " . NemvoEmail\\Log::table() );'), 10) || 0;

const icon = readFileSync(join(OUT, 'icon.svg'), 'utf8');
const browser = await puppeteer.launch({ executablePath: CHROME, headless: true });
try {
	php('update_option( "blogname", "Northwind Studio" ); deactivate_plugins( array( "plugin-check/plugin.php", "wp-mail-smtp/wp_mail_smtp.php" ) );');
	const page = await browser.newPage();
	await page.emulateMediaFeatures([{ name: 'prefers-color-scheme', value: 'light' }]);

	/* Icons and banners */
	for (const size of [128, 256]) {
		await page.setViewport({ width: size, height: size });
		await page.setContent(`<style>body{margin:0}svg{width:${size}px;height:${size}px;display:block}</style>${icon}`);
		await page.screenshot({ path: join(OUT, `icon-${size}x${size}.png`) });
	}
	for (const [w, h] of [
		[1544, 500],
		[772, 250],
	]) {
		const s = w / 1544;
		await page.setViewport({ width: w, height: h });
		await page.setContent(`<style>
			body{margin:0;width:${w}px;height:${h}px;display:flex;align-items:center;gap:${56 * s}px;padding:0 ${110 * s}px;box-sizing:border-box;
			background:linear-gradient(120deg,#b8430b 0%,#e8590c 55%,#f47a2b 100%);font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;color:#fff}
			svg{width:${210 * s}px;height:${210 * s}px;flex:none;filter:drop-shadow(0 ${12 * s}px ${30 * s}px rgba(0,0,0,.25))}
			h1{font-size:${92 * s}px;margin:0 0 ${14 * s}px;letter-spacing:-.02em;font-weight:700}
			p{font-size:${34 * s}px;margin:0;opacity:.94;line-height:1.35;max-width:${930 * s}px}
		</style>${icon.replace('rx="56" fill="#e8590c"', 'rx="56" fill="#e8590c" stroke="rgba(255,255,255,.4)" stroke-width="6"')}<div><h1>Nemvo Email</h1><p>All WordPress email through Google Workspace. A guided setup, a delivery log, and SPF, DKIM and DMARC checks.</p></div>`);
		await page.screenshot({ path: join(OUT, `banner-${w}x${h}.png`) });
	}

	/* Screenshots */
	await page.setViewport({ width: 1440, height: 1000 });
	await page.goto(`${BASE}/wp-login.php`);
	await page.type('#user_login', 'admin');
	await page.type('#user_pass', 'admin');
	await Promise.all([page.waitForNavigation(), page.click('#wp-submit')]);
	await page.evaluate(() => localStorage.setItem('nve-theme', 'auto'));

	// 1. Overview.
	await page.goto(admin('nemvo-email'), { waitUntil: 'networkidle0' });
	await page.waitForSelector('#nve-app canvas');
	await sleep(800);
	await page.screenshot({ path: join(OUT, 'screenshot-1.png') });

	// 2. The log, with an order confirmation opened.
	php(`
		NemvoEmail\\Settings::update( array( 'log_content' => 1 ) );
		NemvoEmail\\Mailer::set_source( array( 'key' => 'plugin:woocommerce', 'name' => 'WooCommerce' ) );
		$rows = '';
		foreach ( array( array( 'Linen tea towel, oat', 2, '18.00' ), array( 'Stoneware mug, sea green', 4, '96.00' ), array( 'Table runner, natural', 1, '42.00' ) ) as $i ) {
			$rows .= '<tr><td style="padding:8px 0;border-bottom:1px solid #eee">' . $i[0] . ' × ' . $i[1] . '</td><td style="padding:8px 0;border-bottom:1px solid #eee;text-align:right">€' . $i[2] . '</td></tr>';
		}
		$html = '<div style="background:#f6f4ef;padding:32px;font-family:-apple-system,Segoe UI,Roboto,sans-serif;color:#222"><div style="max-width:520px;margin:0 auto;background:#fff;border-radius:10px;overflow:hidden"><div style="background:#2f4f4f;color:#fff;padding:24px 28px;font-size:22px;font-weight:600">Thank you for your order</div><div style="padding:24px 28px"><p>Hi Maria,</p><p>We have received your order #1054 and are getting it ready. Here is what you ordered:</p><table style="width:100%;border-collapse:collapse;font-size:14px">' . $rows . '<tr><td style="padding:10px 0;font-weight:600">Total</td><td style="padding:10px 0;text-align:right;font-weight:600">€156.00</td></tr></table><p style="margin-top:20px">Northwind Studio</p></div></div></div>';
		wp_mail( 'Maria Lind <maria.lind@outlook.com>', 'Your Northwind Studio order has been received!', $html, array( 'Content-Type: text/html; charset=UTF-8', 'From: Northwind Studio <hello@example.com>' ) );
	`);
	await page.goto(admin('nemvo-email-log'), { waitUntil: 'networkidle0' });
	await page.waitForSelector('.nve-table--log tbody tr td');
	await page.click('.nve-table--log tbody tr');
	await page.waitForSelector('.nve-dialog[open] iframe');
	await sleep(800);
	await page.screenshot({ path: join(OUT, 'screenshot-2.png') });
	await page.keyboard.press('Escape');

	// 4. The domain check (taken while connected).
	await page.setViewport({ width: 1440, height: 1040 });
	await page.goto(admin('nemvo-email-setup', '&step=domain'), { waitUntil: 'networkidle0' });
	await page.screenshot({ path: join(OUT, 'screenshot-4.png') });

	// 6. Settings.
	await page.setViewport({ width: 1440, height: 1000 });
	await page.goto(admin('nemvo-email-settings'), { waitUntil: 'networkidle0' });
	await sleep(300);
	await page.screenshot({ path: join(OUT, 'screenshot-6.png') });

	// 3. The guide on a site that isn't set up yet.
	php('delete_option( "nemvo_email_google" ); delete_option( "nemvo_email_dns" ); NemvoEmail\\Settings::update( array( "setup_done" => 0, "sender_saved" => 0, "test_sent" => 0 ) );');
	await page.setViewport({ width: 1440, height: 1050 });
	await page.goto(admin('nemvo-email-setup'), { waitUntil: 'networkidle0' });
	await page.screenshot({ path: join(OUT, 'screenshot-3.png') });

	// 5. Importing from WP Mail SMTP.
	php('activate_plugin( "wp-mail-smtp/wp_mail_smtp.php" ); delete_transient( "wp_mail_smtp_just_activated" ); update_option( "wp_mail_smtp", array( "mail" => array( "mailer" => "gmail", "from_email" => "hello@example.com", "from_name" => "Northwind Studio", "from_email_force" => true ), "gmail" => array( "client_id" => "123456789012-wpms.apps.googleusercontent.com", "client_secret" => "GOCSPX-wpms-secret-0123456789", "refresh_token" => "1//wpms-refresh", "user_details" => array( "email" => "hello@example.com" ) ) ) );');
	await page.setViewport({ width: 1440, height: 760 });
	await page.goto(admin('nemvo-email-setup'), { waitUntil: 'networkidle0' });
	await page.screenshot({ path: join(OUT, 'screenshot-5.png') });

	// 7. Dark mode.
	php(`foreach ( json_decode( base64_decode( '${saved}' ), true ) as $o => $v ) { if ( 0 === strpos( $o, 'nemvo_email' ) ) { update_option( $o, $v ); } }`);
	await page.emulateMediaFeatures([{ name: 'prefers-color-scheme', value: 'dark' }]);
	await page.setViewport({ width: 1440, height: 1000 });
	await page.goto(admin('nemvo-email'), { waitUntil: 'networkidle0' });
	await page.waitForSelector('#nve-app canvas');
	await sleep(800);
	await page.screenshot({ path: join(OUT, 'screenshot-7.png') });
	console.log('Assets written to .wordpress-org/');
} finally {
	await browser.close();
	php(`foreach ( json_decode( base64_decode( '${saved}' ), true ) as $o => $v ) { null === $v ? delete_option( $o ) : update_option( $o, $v ); }`);
	php(`$max = (int) $GLOBALS["wpdb"]->get_var( "SELECT MAX(id) FROM " . NemvoEmail\\Log::table() ); if ( $max > ${lastId} ) { NemvoEmail\\Log::delete( range( ${lastId + 1}, $max ) ); }`);
}
