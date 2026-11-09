/* Nemvo Email: shared behaviour of the admin screens (theme, copy buttons, confirmations, test email). */
(function (wp) {
	'use strict';

	const cfg = window.nveAdmin || {};
	const { __, sprintf } = wp.i18n;

	/* ------------------------------------------------------------------ *
	 * Theme: auto (follows the OS), light or dark; remembered per browser.
	 * ------------------------------------------------------------------ */

	const store = {
		get(key) {
			try {
				return window.localStorage.getItem('nve-' + key);
			} catch (e) {
				return null;
			}
		},
		set(key, value) {
			try {
				window.localStorage.setItem('nve-' + key, value);
			} catch (e) {
				// Private windows may refuse; the choice just isn't remembered.
			}
		},
	};

	const THEMES = {
		auto: [__('Theme: automatic', 'nemvo-email'), '<circle cx="8" cy="8" r="5.5"/><path d="M8 2.5v11a5.5 5.5 0 0 0 0-11z" fill="currentColor" stroke="none"/>'],
		light: [__('Theme: light', 'nemvo-email'), '<circle cx="8" cy="8" r="3"/><path d="M8 1.5v1.5M8 13v1.5M1.5 8H3M13 8h1.5M3.4 3.4l1 1M11.6 11.6l1 1M3.4 12.6l1-1M11.6 4.4l1-1"/>'],
		dark: [__('Theme: dark', 'nemvo-email'), '<path d="M13.5 9.5A5.5 5.5 0 1 1 6.5 2.5a4.5 4.5 0 0 0 7 7z"/>'],
	};
	let theme = THEMES[store.get('theme')] ? store.get('theme') : 'auto';

	function applyTheme() {
		if ('auto' === theme) {
			document.body.removeAttribute('data-nve-theme');
		} else {
			document.body.setAttribute('data-nve-theme', theme);
		}
		window.dispatchEvent(new CustomEvent('nve-theme'));
	}

	applyTheme();
	document.querySelectorAll('[data-nve-theme-toggle]').forEach((button) => {
		const paint = () => {
			button.innerHTML = '<svg viewBox="0 0 16 16" aria-hidden="true">' + THEMES[theme][1] + '</svg>';
			button.setAttribute('aria-label', THEMES[theme][0]);
			button.title = THEMES[theme][0];
		};
		button.hidden = false;
		paint();
		button.addEventListener('click', () => {
			const keys = Object.keys(THEMES);
			theme = keys[(keys.indexOf(theme) + 1) % keys.length];
			store.set('theme', theme);
			applyTheme();
			paint();
		});
	});
	if (window.matchMedia) {
		window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', () => 'auto' === theme && window.dispatchEvent(new CustomEvent('nve-theme')));
	}

	/* ------------------------------------------------------------------ *
	 * REST
	 * ------------------------------------------------------------------ */

	async function api(path, options) {
		const opts = Object.assign({ method: 'GET' }, options || {});
		const init = { method: opts.method, credentials: 'same-origin', headers: { 'X-WP-Nonce': cfg.nonce } };
		if (opts.body) {
			init.headers['Content-Type'] = 'application/json';
			init.body = JSON.stringify(opts.body);
		}
		// With plain permalinks the root is ".../?rest_route=/nemvo-email/v1", so a query joins with "&".
		const [route, query] = path.split('?');
		let url = cfg.root + route;
		if (query) {
			url += (url.includes('?') ? '&' : '?') + query;
		}
		const response = await fetch(url, init);
		const data = await response.json().catch(() => ({}));
		if (!response.ok) {
			throw new Error(data && data.message ? data.message : sprintf(/* translators: %d: HTTP status */ __('The request failed (%d).', 'nemvo-email'), response.status));
		}
		return data;
	}
	window.nveApi = api;

	/* ------------------------------------------------------------------ *
	 * Copy buttons and read-only fields
	 * ------------------------------------------------------------------ */

	async function copy(text) {
		try {
			await navigator.clipboard.writeText(text);
			return true;
		} catch (e) {
			const area = document.createElement('textarea');
			area.value = text;
			area.setAttribute('readonly', '');
			area.style.position = 'fixed';
			area.style.opacity = '0';
			document.body.append(area);
			area.select();
			let ok = false;
			try {
				ok = document.execCommand('copy');
			} catch (err) {
				ok = false;
			}
			area.remove();
			return ok;
		}
	}

	document.addEventListener('click', async (event) => {
		const button = event.target.closest('[data-nve-copy]');
		if (!button) {
			return;
		}
		const label = button.dataset.label || button.textContent;
		button.dataset.label = label;
		const ok = await copy(button.getAttribute('data-nve-copy'));
		button.textContent = ok ? __('Copied', 'nemvo-email') : __('Select and copy by hand', 'nemvo-email');
		button.classList.toggle('is-done', ok);
		clearTimeout(button._nveTimer);
		button._nveTimer = setTimeout(() => {
			button.textContent = label;
			button.classList.remove('is-done');
		}, 1800);
	});

	document.querySelectorAll('.nve-copyfield input').forEach((input) => input.addEventListener('focus', () => input.select()));

	/* ------------------------------------------------------------------ *
	 * Confirmations
	 * ------------------------------------------------------------------ */

	document.querySelectorAll('form[data-nve-confirm]').forEach((form) => {
		form.addEventListener('submit', (event) => {
			// eslint-disable-next-line no-alert
			if (!window.confirm(form.getAttribute('data-nve-confirm'))) {
				event.preventDefault();
			}
		});
	});

	/* ------------------------------------------------------------------ *
	 * Settings: highlight the section in view
	 * ------------------------------------------------------------------ */

	const nav = document.querySelector('.nve-settings__nav');
	if (nav && 'IntersectionObserver' in window) {
		const links = new Map(Array.from(nav.querySelectorAll('a')).map((a) => [a.getAttribute('href').slice(1), a]));
		const observer = new IntersectionObserver(
			(entries) => {
				entries.forEach((entry) => {
					if (entry.isIntersecting && links.has(entry.target.id)) {
						links.forEach((a) => a.classList.remove('is-active'));
						links.get(entry.target.id).classList.add('is-active');
					}
				});
			},
			{ rootMargin: '-20% 0px -70% 0px' }
		);
		links.forEach((a, id) => {
			const section = document.getElementById(id);
			if (section) {
				observer.observe(section);
			}
		});
	}

	/* ------------------------------------------------------------------ *
	 * Test email
	 * ------------------------------------------------------------------ */

	function el(tag, cls, text) {
		const node = document.createElement(tag);
		if (cls) {
			node.className = cls;
		}
		if (text) {
			node.textContent = text;
		}
		return node;
	}

	function logLink(entry) {
		const a = el('a', '', __('Open in the log', 'nemvo-email'));
		a.href = cfg.logUrl + '#entry=' + entry.id;
		return a;
	}

	document.querySelectorAll('form[data-nve-test]').forEach((form) => {
		const result = form.querySelector('.nve-test__result');
		const button = form.querySelector('button[type="submit"]');
		form.addEventListener('submit', async (event) => {
			event.preventDefault();
			const to = form.querySelector('input[name="to"]').value.trim();
			button.disabled = true;
			const pending = el('div', 'nve-result is-pending', __('Sending…', 'nemvo-email'));
			result.replaceChildren(pending);
			try {
				const data = await api('/test', { method: 'POST', body: { to } });
				const entry = data.entry;
				const box = el('div', 'nve-result' + (data.ok ? '' : ' is-error'));
				if (data.ok && entry && 'paused' === entry.status) {
					box.append(el('strong', '', __('Logged, not sent.', 'nemvo-email')), el('span', '', __('Sending is paused, so the message was only written to the log.', 'nemvo-email')));
				} else if (data.ok) {
					/* translators: %s: email address */
					box.append(el('strong', '', sprintf(__('Sent to %s.', 'nemvo-email'), to)));
					if (entry && entry.message_id) {
						/* translators: 1: provider, 2: message id */
						box.append(el('span', '', sprintf(__('%1$s accepted it as message %2$s. Check the inbox, and the spam folder.', 'nemvo-email'), entry.provider_label, entry.message_id)));
					} else if (entry) {
						/* translators: %s: how it was sent, e.g. This server (PHP mail) */
						box.append(el('span', '', sprintf(__('Sent by: %s.', 'nemvo-email'), entry.provider_label)));
					}
				} else {
					box.append(el('strong', '', __('Not sent.', 'nemvo-email')), el('span', '', data.error || __('WordPress reported a failure without a reason.', 'nemvo-email')));
				}
				if (entry) {
					box.append(logLink(entry));
				}
				result.replaceChildren(box);
				if (data.ok && form.dataset.next) {
					const next = el('a', 'button button-primary', __('Continue', 'nemvo-email'));
					next.href = form.dataset.next;
					const actions = el('p', 'nve-actions');
					actions.append(next);
					result.append(actions);
				}
			} catch (e) {
				const box = el('div', 'nve-result is-error');
				box.append(el('strong', '', __('Not sent.', 'nemvo-email')), el('span', '', e.message));
				result.replaceChildren(box);
			}
			button.disabled = false;
		});
	});
})(window.wp);
