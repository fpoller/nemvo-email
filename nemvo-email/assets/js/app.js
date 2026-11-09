/* Nemvo Email: the overview (statistics with Chart.js) and the log (one app, two views). */
(function (Chart, wp) {
	'use strict';

	const cfg = window.nveApp;
	const app = document.getElementById('nve-app');
	const api = window.nveApi;
	if (!cfg || !app || !api) {
		return;
	}
	const { __, _n, sprintf } = wp.i18n;

	/* ------------------------------------------------------------------ *
	 * DOM helpers
	 * ------------------------------------------------------------------ */

	function h(tag, props, ...children) {
		const el = document.createElement(tag);
		for (const [k, v] of Object.entries(props || {})) {
			if (v === null || v === undefined || v === false) {
				continue;
			}
			if ('class' === k) {
				el.className = v;
			} else if ('text' === k) {
				el.textContent = v;
			} else if ('style' === k) {
				for (const [sk, sv] of Object.entries(v)) {
					el.style.setProperty(sk, sv);
				}
			} else if (k.startsWith('on')) {
				el.addEventListener(k.slice(2), v);
			} else {
				el.setAttribute(k, true === v ? '' : v);
			}
		}
		children.flat(Infinity).forEach((c) => {
			if (c !== null && c !== undefined && c !== false) {
				el.append(c instanceof Node ? c : String(c));
			}
		});
		return el;
	}

	function put(el, ...children) {
		el.replaceChildren(...children.flat(Infinity).filter((c) => c !== null && c !== undefined && c !== false));
	}

	/* ------------------------------------------------------------------ *
	 * Formatting. Dates are the site's local dates ("Y-m-d"), parsed and
	 * formatted as UTC so nothing shifts.
	 * ------------------------------------------------------------------ */

	const locale = cfg.locale || undefined;
	const nf = new Intl.NumberFormat(locale);
	const pct = new Intl.NumberFormat(locale, { style: 'percent', maximumFractionDigits: 1 });
	const dec1 = new Intl.NumberFormat(locale, { maximumFractionDigits: 1 });
	const kb = new Intl.NumberFormat(locale, { style: 'unit', unit: 'kilobyte', maximumFractionDigits: 0 });
	const mb = new Intl.NumberFormat(locale, { style: 'unit', unit: 'megabyte', maximumFractionDigits: 1 });
	const rtf = window.Intl && Intl.RelativeTimeFormat ? new Intl.RelativeTimeFormat(locale, { numeric: 'auto' }) : null;

	const DAY_MS = 86400000;
	const parseDay = (s) => new Date(s.slice(0, 10) + 'T' + (s.length > 10 ? s.slice(11, 19).padEnd(8, ':00') : '00:00:00') + 'Z');
	const isoDay = (d) => d.toISOString().slice(0, 10);
	const addDays = (s, n) => isoDay(new Date(parseDay(s).getTime() + n * DAY_MS));
	const df = (opts) => new Intl.DateTimeFormat(locale, Object.assign({ timeZone: 'UTC' }, opts));
	const fDay = df({ month: 'short', day: 'numeric' });
	const fDayYear = df({ month: 'short', day: 'numeric', year: 'numeric' });
	const fWeekday = df({ weekday: 'short', month: 'short', day: 'numeric' });
	const fTime = df({ hour: '2-digit', minute: '2-digit' });
	const fFull = df({ year: 'numeric', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit', second: '2-digit' });

	const fmt = {
		n: (v) => nf.format(v || 0),
		pct: (v) => (null === v || undefined === v ? '–' : pct.format(v)),
		bytes: (b) => (!b ? '–' : b < 1024 * 1024 ? kb.format(Math.max(1, Math.round(b / 1024))) : mb.format(b / 1024 / 1024)),
		day: (s) => (s.slice(0, 4) === cfg.today.slice(0, 4) ? fDay : fDayYear).format(parseDay(s)),
		when(entry) {
			const seconds = Math.round(Date.now() / 1000 - entry.time);
			if (rtf && seconds < 60) {
				return rtf.format(0, 'second');
			}
			if (rtf && seconds < 3600) {
				return rtf.format(-Math.floor(seconds / 60), 'minute');
			}
			if (rtf && seconds < 6 * 3600) {
				return rtf.format(-Math.floor(seconds / 3600), 'hour');
			}
			const local = parseDay(entry.local);
			return (entry.local.slice(0, 10) === cfg.today ? '' : fmt.day(entry.local) + ', ') + fTime.format(local);
		},
		full: (entry) => fFull.format(parseDay(entry.local)),
	};

	function rangeText(from, to) {
		if (from === to) {
			return fmt.day(from);
		}
		const f = from.slice(0, 4) === cfg.today.slice(0, 4) && to.slice(0, 4) === cfg.today.slice(0, 4) ? fDay : fDayYear;
		return f.formatRange ? f.formatRange(parseDay(from), parseDay(to)) : f.format(parseDay(from)) + ' – ' + f.format(parseDay(to));
	}

	const RANGES = {
		today: __('Today', 'nemvo-email'),
		'7d': __('7 days', 'nemvo-email'),
		'30d': __('30 days', 'nemvo-email'),
		'90d': __('90 days', 'nemvo-email'),
		'12mo': __('12 months', 'nemvo-email'),
	};

	function rangeDates(key, from, to) {
		const t = cfg.today;
		switch (key) {
			case 'today':
				return [t, t];
			case '7d':
				return [addDays(t, -6), t];
			case '90d':
				return [addDays(t, -89), t];
			case '12mo':
				return [addDays(t, -364), t];
			case 'custom':
				return [from || t, to || t];
			case 'all':
				return ['', ''];
			default:
				return [addDays(t, -29), t];
		}
	}

	/* ------------------------------------------------------------------ *
	 * State in the address (#range=30d&status=failed…)
	 * ------------------------------------------------------------------ */

	function readHash() {
		const params = new URLSearchParams(location.hash.slice(1));
		const out = {};
		params.forEach((v, k) => {
			out[k] = v;
		});
		return out;
	}

	function writeHash(state) {
		const params = new URLSearchParams();
		Object.entries(state).forEach(([k, v]) => {
			if (v !== '' && v !== null && v !== undefined) {
				params.set(k, v);
			}
		});
		const hash = params.toString();
		history.replaceState(null, '', hash ? '#' + hash : location.pathname + location.search);
	}

	function logLink(filters) {
		const params = new URLSearchParams();
		Object.entries(filters).forEach(([k, v]) => {
			if (v !== '' && v !== null && v !== undefined) {
				params.set(k, v);
			}
		});
		return cfg.logUrl + '#' + params.toString();
	}

	/* ------------------------------------------------------------------ *
	 * Colours from the CSS tokens, so charts follow light and dark mode
	 * ------------------------------------------------------------------ */

	function tokens() {
		const cs = getComputedStyle(document.body);
		const v = (n) => cs.getPropertyValue('--nve-' + n).trim();
		return { surface: v('surface'), ink: v('ink'), muted: v('muted'), grid: v('grid'), axis: v('axis'), sent: v('sent'), failed: v('failed'), paused: v('paused'), font: v('font') };
	}

	const reducedMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

	/* ------------------------------------------------------------------ *
	 * Tooltip
	 * ------------------------------------------------------------------ */

	const tip = h('div', { class: 'nve-tip', hidden: true, role: 'tooltip' });
	document.body.append(tip);

	function showTip(children, x, y) {
		put(tip, children);
		tip.hidden = false;
		const r = tip.getBoundingClientRect();
		let left = x + 14;
		let top = y - r.height - 10;
		if (left + r.width > window.innerWidth - 8) {
			left = x - r.width - 14;
		}
		if (top < 8) {
			top = y + 14;
		}
		tip.style.left = Math.max(8, left) + 'px';
		tip.style.top = top + 'px';
	}

	function hideTip() {
		tip.hidden = true;
	}

	/* ------------------------------------------------------------------ *
	 * Shared pieces
	 * ------------------------------------------------------------------ */

	function emptyNote(text) {
		return h('p', { class: 'nve-empty', text });
	}

	function errorBox(e) {
		return h('div', { class: 'nve-error', role: 'alert' }, h('strong', { text: __('Could not load the data.', 'nemvo-email') }), ' ', e.message || '');
	}

	function sourceLabel(row) {
		if (row.name) {
			return row.name;
		}
		if (row.key) {
			return row.key.replace(/^[a-z-]+:/, '');
		}
		return __('Unknown', 'nemvo-email');
	}

	/**
	 * A ranked list with a bar behind each label.
	 *
	 * @param {Object[]} rows  { label, sent, failed, href, title }.
	 * @param {string}   first Heading of the first column.
	 * @param {string}   empty Text when there are no rows.
	 */
	function rankList(rows, first, empty) {
		if (!rows.length) {
			return emptyNote(empty);
		}
		const top = Math.max(1, ...rows.map((r) => r.sent + r.failed));
		return h(
			'div',
			{ class: 'nve-rows' },
			h('div', { class: 'nve-rows__head' }, h('span', { text: first }), h('span', { class: 'nve-num', text: __('Sent', 'nemvo-email') }), h('span', { class: 'nve-num', text: __('Failed', 'nemvo-email') })),
			rows.map((r) =>
				h(
					'div',
					{ class: 'nve-row' },
					h('div', { class: 'nve-row__cell', style: { '--w': ((r.sent + r.failed) / top) * 100 + '%' } }, h('a', { class: 'nve-row__label', href: r.href, title: r.title || r.label, text: r.label })),
					h('span', { class: 'nve-num', text: fmt.n(r.sent) }),
					h('span', { class: 'nve-num' + (r.failed ? ' is-failed' : ''), text: r.failed ? fmt.n(r.failed) : '–' })
				)
			)
		);
	}

	function panel(title, body, extra) {
		return h('section', { class: 'nve-card nve-panel' }, h('header', { class: 'nve-panel__head' }, h('h2', { text: title }), extra || null), body);
	}

	/* ------------------------------------------------------------------ *
	 * Overview
	 * ------------------------------------------------------------------ */

	function overview() {
		const state = Object.assign({ range: '30d' }, readHash());
		if (!RANGES[state.range] && 'custom' !== state.range) {
			state.range = '30d';
		}
		const seg = h('div', { class: 'nve-seg', role: 'group', 'aria-label': __('Period', 'nemvo-email') });
		const rangeLabel = h('span', { class: 'nve-toolbar__range' });
		const tiles = h('div', { class: 'nve-tiles' });
		const chartBox = h('div', { class: 'nve-chart' });
		const legend = h('div', { class: 'nve-legend' });
		const grid = h('div', { class: 'nve-grid' });
		const foot = h('p', { class: 'nve-foot' });
		let chart = null;
		let report = null;

		Object.entries(RANGES).forEach(([key, label]) => {
			seg.append(
				h('button', {
					type: 'button',
					'data-range': key,
					'aria-pressed': String(key === state.range),
					text: label,
					onclick: () => {
						state.range = key;
						delete state.from;
						delete state.to;
						load();
					},
				})
			);
		});

		put(
			app,
			h('div', { class: 'nve-toolbar' }, seg, rangeLabel, h('a', { class: 'nve-btn nve-toolbar__end', href: cfg.logUrl, text: __('Open the log', 'nemvo-email') })),
			tiles,
			h('section', { class: 'nve-card nve-panel' }, h('header', { class: 'nve-panel__head' }, h('h2', { text: __('Emails', 'nemvo-email') }), legend), chartBox),
			grid,
			foot
		);

		async function load() {
			seg.querySelectorAll('button').forEach((b) => b.setAttribute('aria-pressed', String(b.dataset.range === state.range)));
			writeHash(state.range === '30d' && !state.from ? {} : { range: state.range, from: state.from, to: state.to });
			const [from, to] = rangeDates(state.range, state.from, state.to);
			rangeLabel.textContent = rangeText(from, to);
			app.classList.add('is-loading');
			try {
				report = await api('/stats?from=' + from + '&to=' + to);
				render();
			} catch (e) {
				put(grid, errorBox(e));
			}
			app.classList.remove('is-loading');
		}

		function delta(cur, prev, upIsGood, points) {
			if (null === cur || undefined === cur || null === prev || undefined === prev) {
				return null;
			}
			let text;
			let change;
			if (points) {
				change = (cur - prev) * 100;
				if (Math.abs(change) < 0.05) {
					return h('span', { class: 'nve-delta is-flat', text: '±0' });
				}
				/* translators: %s: change in percentage points */
				text = sprintf(__('%s pp', 'nemvo-email'), (change > 0 ? '+' : '−') + dec1.format(Math.abs(change)));
			} else {
				if (!prev) {
					return null;
				}
				change = (cur - prev) / prev;
				if (Math.abs(change) < 0.005) {
					return h('span', { class: 'nve-delta is-flat', text: '±0%' });
				}
				text = (change > 0 ? '▲ ' : '▼ ') + pct.format(Math.abs(change));
			}
			const good = change > 0 === upIsGood;
			return h('span', { class: 'nve-delta ' + (good ? 'is-good' : 'is-bad'), text });
		}

		function tile(label, value, foot) {
			return h('div', { class: 'nve-card nve-tile' }, h('span', { class: 'nve-tile__label', text: label }), h('span', { class: 'nve-tile__value', text: value }), h('span', { class: 'nve-tile__foot' }, foot));
		}

		function render() {
			const t = report.totals;
			const p = report.previous;
			/* translators: %s: date range */
			const vs = h('span', { text: sprintf(__('vs %s', 'nemvo-email'), rangeText(p.from, p.to)) });
			put(
				tiles,
				tile(__('Sent', 'nemvo-email'), fmt.n(t.sent), [delta(t.sent, p.sent, true), vs.cloneNode(true)]),
				tile(__('Failed', 'nemvo-email'), fmt.n(t.failed), [delta(t.failed, p.failed, false), t.paused ? h('span', { text: sprintf(/* translators: %s: number of messages */ _n('%s paused', '%s paused', t.paused, 'nemvo-email'), fmt.n(t.paused)) }) : vs.cloneNode(true)]),
				tile(__('Delivery rate', 'nemvo-email'), fmt.pct(t.rate), [delta(t.rate, p.rate, true, true), h('span', { text: __('accepted for delivery', 'nemvo-email') })]),
				tile(__('Recipients', 'nemvo-email'), fmt.n(t.recipients), [delta(t.recipients, p.recipients, true), h('span', { text: t.sent ? sprintf(/* translators: %s: average recipients per message */ __('%s per message', 'nemvo-email'), dec1.format(t.recipients / t.sent)) : '' })])
			);
			drawChart();

			const [from, to] = rangeDates(state.range, state.from, state.to);
			const base = { from, to };
			put(
				grid,
				panel(
					__('Sent by', 'nemvo-email'),
					rankList(
						report.sources.map((r) => ({ label: sourceLabel(r), sent: r.sent, failed: r.failed, href: logLink(Object.assign({ source: r.key || '-' }, base)) })),
						__('Plugin or theme', 'nemvo-email'),
						__('Nothing was sent in this period.', 'nemvo-email')
					)
				),
				panel(
					__('Problems', 'nemvo-email'),
					report.errors.length
						? h(
								'ul',
								{ class: 'nve-errors' },
								report.errors.map((e) =>
									h(
										'li',
										null,
										h('a', { href: logLink(Object.assign({ status: 'failed', q: e.error.slice(0, 60) }, base)), text: e.error || __('Unknown error', 'nemvo-email') }),
										h('span', { class: 'nve-num', text: fmt.n(e.count) }),
										/* translators: %s: date and time */
										h('small', { text: sprintf(__('Last: %s', 'nemvo-email'), fmt.day(e.last) + ', ' + fTime.format(parseDay(e.last))) })
									)
								)
						  )
						: emptyNote(__('No failed emails in this period.', 'nemvo-email'))
				),
				panel(
					__('Email types', 'nemvo-email'),
					rankList(
						report.subjects.map((r) => ({ label: r.key || __('(no subject)', 'nemvo-email'), sent: r.sent, failed: r.failed, href: logLink(Object.assign({ subject: r.key }, base)) })),
						__('Subject', 'nemvo-email'),
						__('Nothing was sent in this period.', 'nemvo-email')
					)
				),
				panel(
					__('Recipient domains', 'nemvo-email'),
					rankList(
						report.domains.map((r) => ({ label: r.key || __('(none)', 'nemvo-email'), sent: r.sent, failed: r.failed, href: logLink(Object.assign({ domain: r.key }, base)) })),
						__('Domain', 'nemvo-email'),
						__('Nothing was sent in this period.', 'nemvo-email')
					)
				)
			);
			put(
				foot,
				/* translators: %d: days */
				sprintf(_n('Counted from the log, which keeps %d day.', 'Counted from the log, which keeps %d days.', cfg.retention, 'nemvo-email'), cfg.retention)
			);
		}

		function bucketLabel(key, long) {
			const d = parseDay(key);
			if ('hour' === report.interval) {
				return fTime.format(d);
			}
			if ('week' === report.interval) {
				/* translators: %s: first day of a week */
				return long ? sprintf(__('Week of %s', 'nemvo-email'), fDayYear.format(d)) : fDay.format(d);
			}
			return long ? fWeekday.format(d) : fDay.format(d);
		}

		function drawChart() {
			const t = tokens();
			const series = report.series;
			const paused = series.some((s) => s.paused);
			const sets = [
				['sent', __('Sent', 'nemvo-email'), t.sent, ''],
				['failed', __('Failed', 'nemvo-email'), t.failed, 'nve-key--failed'],
			];
			if (paused) {
				sets.push(['paused', __('Paused', 'nemvo-email'), t.paused, 'nve-key--paused']);
			}
			put(
				legend,
				sets.map((s) => h('span', null, h('i', { class: 'nve-key ' + s[3], 'aria-hidden': 'true' }), s[1]))
			);
			if (chart) {
				chart.destroy();
			}
			const total = series.reduce((sum, s) => sum + s.sent + s.failed + s.paused, 0);
			/* translators: 1: number of messages, 2: date range */
			const summary = sprintf(__('%1$s emails, %2$s', 'nemvo-email'), fmt.n(total), rangeLabel.textContent);
			const canvas = h('canvas', { role: 'img', 'aria-label': summary });
			put(chartBox, canvas);
			chart = new Chart(canvas, {
				type: 'bar',
				data: {
					labels: series.map((s) => bucketLabel(s.key, false)),
					datasets: sets.map((s) => ({
						label: s[1],
						data: series.map((row) => row[s[0]]),
						backgroundColor: s[2],
						borderRadius: 3,
						borderSkipped: false,
						maxBarThickness: 28,
						categoryPercentage: 0.86,
						barPercentage: 0.92,
					})),
				},
				options: {
					maintainAspectRatio: false,
					animation: { duration: reducedMotion ? 0 : 250 },
					interaction: { mode: 'index', intersect: false },
					font: { family: t.font },
					scales: {
						x: { stacked: true, grid: { display: false }, border: { color: t.axis }, ticks: { color: t.muted, maxRotation: 0, autoSkipPadding: 18, font: { family: t.font, size: 11 } } },
						y: { stacked: true, beginAtZero: true, grid: { color: t.grid }, border: { display: false }, ticks: { color: t.muted, precision: 0, maxTicksLimit: 5, padding: 8, font: { family: t.font, size: 11 } } },
					},
					plugins: {
						legend: { display: false },
						tooltip: {
							enabled: false,
							external(context) {
								const tt = context.tooltip;
								if (!tt || 0 === tt.opacity || !tt.dataPoints || !tt.dataPoints.length) {
									hideTip();
									return;
								}
								const i = tt.dataPoints[0].dataIndex;
								const rect = context.chart.canvas.getBoundingClientRect();
								showTip(
									[
										h('div', { class: 'nve-tip__title', text: bucketLabel(series[i].key, true) }),
										sets.map((s) => h('div', { class: 'nve-tip__row' }, h('i', { class: 'nve-key ' + s[3] }), h('strong', { text: fmt.n(series[i][s[0]]) }), h('span', { text: s[1] }))),
										'hour' !== report.interval ? h('div', { class: 'nve-tip__title', style: { 'margin-top': '4px', 'margin-bottom': '0' }, text: __('Click to see them in the log', 'nemvo-email') }) : null,
									],
									rect.left + tt.caretX,
									rect.top + tt.caretY
								);
							},
						},
					},
					onClick(event, elements, c) {
						const points = c.getElementsAtEventForMode(event, 'index', { intersect: false }, false);
						if (!points.length) {
							return;
						}
						const key = series[points[0].index].key;
						let from = key.slice(0, 10);
						let to = from;
						if ('week' === report.interval) {
							to = addDays(from, 6) > cfg.today ? cfg.today : addDays(from, 6);
						}
						if ('hour' === report.interval) {
							from = to = report.from;
						}
						location.href = logLink({ from, to });
					},
					onHover(event, elements, c) {
						c.canvas.style.cursor = elements.length ? 'pointer' : 'default';
					},
				},
			});
			canvas.addEventListener('mouseleave', hideTip);
		}

		window.addEventListener('nve-theme', () => report && drawChart());
		load();
	}

	/* ------------------------------------------------------------------ *
	 * Log
	 * ------------------------------------------------------------------ */

	function logView() {
		const hash = readHash();
		const state = {
			q: hash.q || '',
			status: hash.status || '',
			source: hash.source === undefined ? '' : hash.source,
			domain: hash.domain || '',
			subject: hash.subject || '',
			from: hash.from || '',
			to: hash.to || '',
			page: parseInt(hash.page, 10) || 1,
		};
		let sources = null;

		const search = h('input', { type: 'search', class: 'nve-search', placeholder: __('Search recipient, subject or error', 'nemvo-email'), 'aria-label': __('Search the log', 'nemvo-email'), value: state.q });
		const statusSeg = h('div', { class: 'nve-seg', role: 'group', 'aria-label': __('Status', 'nemvo-email') });
		const sourceSelect = h('select', { class: 'nve-select', 'aria-label': __('Sent by', 'nemvo-email') });
		const chips = h('div', { class: 'nve-filters' });
		const body = h('tbody');
		const pager = h('div', { class: 'nve-pager' });
		const card = h(
			'section',
			{ class: 'nve-card nve-log-card' },
			h(
				'div',
				{ class: 'nve-table-wrap' },
				h(
					'table',
					{ class: 'nve-table nve-table--log' },
					h(
						'thead',
						null,
						h(
							'tr',
							null,
							h('th', { scope: 'col', text: __('When', 'nemvo-email') }),
							h('th', { scope: 'col', text: __('Status', 'nemvo-email') }),
							h('th', { scope: 'col', class: 'nve-col-to', text: __('To', 'nemvo-email') }),
							h('th', { scope: 'col', class: 'nve-col-subject', text: __('Subject', 'nemvo-email') }),
							h('th', { scope: 'col', text: __('Sent by', 'nemvo-email') }),
							h('th', { scope: 'col', class: 'nve-num', text: __('Size', 'nemvo-email') })
						)
					),
					body
				)
			),
			pager
		);

		[
			['', __('All', 'nemvo-email')],
			['sent', __('Sent', 'nemvo-email')],
			['failed', __('Failed', 'nemvo-email')],
			['paused', __('Paused', 'nemvo-email')],
		].forEach(([key, label]) =>
			statusSeg.append(
				h('button', {
					type: 'button',
					'data-status': key,
					text: label,
					onclick: () => {
						state.status = key;
						state.page = 1;
						load();
					},
				})
			)
		);

		let timer = null;
		search.addEventListener('input', () => {
			clearTimeout(timer);
			timer = setTimeout(() => {
				state.q = search.value.trim();
				state.page = 1;
				load();
			}, 300);
		});
		sourceSelect.addEventListener('change', () => {
			state.source = sourceSelect.value;
			state.page = 1;
			load();
		});

		put(app, h('div', { class: 'nve-toolbar' }, h('div', { class: 'nve-filters' }, search, statusSeg, sourceSelect), chips), card);

		function fillSources() {
			const options = [h('option', { value: '', text: __('Sent by: everyone', 'nemvo-email') })];
			(sources || []).forEach((s) => options.push(h('option', { value: s.key || '-', text: sourceLabel(s) + ' (' + fmt.n(s.count) + ')' })));
			put(sourceSelect, options);
			sourceSelect.value = state.source;
		}

		function renderChips() {
			const list = [];
			const chip = (text, clear) =>
				h(
					'button',
					{
						type: 'button',
						class: 'nve-btn',
						title: __('Remove this filter', 'nemvo-email'),
						onclick: () => {
							clear();
							state.page = 1;
							load();
						},
					},
					text,
					' ✕'
				);
			if (state.from || state.to) {
				list.push(chip(rangeText(state.from || state.to, state.to || state.from), () => (state.from = state.to = '')));
			}
			if (state.domain) {
				/* translators: %s: domain */
				list.push(chip(sprintf(__('To: %s', 'nemvo-email'), state.domain), () => (state.domain = '')));
			}
			if (state.subject) {
				/* translators: %s: subject */
				list.push(chip(sprintf(__('Subject: %s', 'nemvo-email'), state.subject), () => (state.subject = '')));
			}
			put(chips, list);
		}

		async function load() {
			statusSeg.querySelectorAll('button').forEach((b) => b.setAttribute('aria-pressed', String(b.dataset.status === state.status)));
			writeHash(Object.assign({}, state, { page: state.page > 1 ? state.page : '' }));
			renderChips();
			const params = new URLSearchParams({ page: state.page, per_page: 50 });
			[['search', state.q], ['status', state.status], ['source', state.source], ['domain', state.domain], ['subject', state.subject], ['from', state.from], ['to', state.to]].forEach(([k, v]) => v && params.set(k, v));
			card.classList.add('is-loading');
			try {
				const data = await api('/log?' + params.toString());
				sources = data.sources;
				fillSources();
				renderRows(data);
			} catch (e) {
				put(body, h('tr', null, h('td', { colspan: 6 }, errorBox(e))));
			}
			card.classList.remove('is-loading');
		}

		function renderRows(data) {
			if (!data.rows.length) {
				const filtered = state.q || state.status || state.source || state.domain || state.subject || state.from;
				put(body, h('tr', null, h('td', { colspan: 6 }, emptyNote(filtered ? __('No messages match these filters.', 'nemvo-email') : __('No messages yet. Everything WordPress sends shows up here.', 'nemvo-email')))));
			} else {
				put(
					body,
					data.rows.map((row) => {
						const others = row.recipient_count - 1;
						const tr = h(
							'tr',
							{ tabindex: '0', 'data-id': row.id, onclick: () => openEntry(row.id), onkeydown: (e) => ('Enter' === e.key || ' ' === e.key) && (e.preventDefault(), openEntry(row.id)) },
							h('td', { class: 'nve-nowrap', title: fmt.full(row), text: fmt.when(row) }),
							h('td', null, statusPill(row.status)),
							h('td', { class: 'nve-col-to' }, h('span', { class: 'nve-ellipsis', style: { display: 'block' }, title: row.to_email, text: row.to_email + (others > 0 ? ' +' + others : '') })),
							h('td', { class: 'nve-col-subject' }, h('span', { class: 'nve-ellipsis', style: { display: 'block' }, text: row.subject || __('(no subject)', 'nemvo-email') }), row.error ? h('span', { class: 'nve-table__sub', style: { color: 'var(--nve-bad)' }, text: row.error }) : null),
							h('td', { class: 'nve-ellipsis', text: row.source_name || '–' }),
							h('td', { class: 'nve-num', text: fmt.bytes(row.size) })
						);
						return tr;
					})
				);
			}
			const first = data.total ? (data.page - 1) * 50 + 1 : 0;
			const last = Math.min(data.total, data.page * 50);
			put(
				pager,
				/* translators: 1: first row, 2: last row, 3: total */
				h('span', { text: sprintf(__('%1$s–%2$s of %3$s', 'nemvo-email'), fmt.n(first), fmt.n(last), fmt.n(data.total)) }),
				h(
					'div',
					{ class: 'nve-pager__buttons' },
					h('button', {
						type: 'button',
						class: 'nve-btn',
						disabled: data.page <= 1,
						text: __('Newer', 'nemvo-email'),
						onclick: () => {
							state.page--;
							load();
						},
					}),
					h('button', {
						type: 'button',
						class: 'nve-btn',
						disabled: data.page >= data.pages,
						text: __('Older', 'nemvo-email'),
						onclick: () => {
							state.page++;
							load();
						},
					})
				)
			);
		}

		/* ---------------- One message ---------------- */

		const dialog = h('dialog', { class: 'nve-dialog', 'aria-labelledby': 'nve-dialog-title' });
		document.body.append(dialog);
		dialog.addEventListener('click', (e) => e.target === dialog && dialog.close());
		dialog.addEventListener('close', () => {
			const current = readHash();
			delete current.entry;
			writeHash(current);
		});

		function statusPill(status) {
			const labels = { sent: __('Sent', 'nemvo-email'), failed: __('Failed', 'nemvo-email'), paused: __('Paused', 'nemvo-email') };
			return h('span', { class: 'nve-status-pill is-' + status, text: labels[status] || status });
		}

		function addresses(list) {
			return list.map((pair) => (pair[1] ? pair[1] + ' <' + pair[0] + '>' : pair[0])).join(', ');
		}

		function preview(entry) {
			if (!entry.has_content) {
				return h('p', { class: 'nve-muted', text: cfg.keepContent ? __('The content of this message wasn\'t kept (it was sent before keeping was turned on).', 'nemvo-email') : __('The content isn\'t kept. Turn on "Keep the content of messages" under Settings › Sending & log to read and resend messages.', 'nemvo-email') });
			}
			const html = /html/i.test(entry.content_type) ? entry.body : '';
			const text = html ? entry.alt_body : entry.body;
			const frame = () => {
				// Shown in a sandbox: no scripts, no remote images or styles.
				const csp = '<meta http-equiv="Content-Security-Policy" content="default-src \'none\'; img-src data: cid:; style-src \'unsafe-inline\'; font-src data:">';
				return h('iframe', { sandbox: '', title: __('Message content', 'nemvo-email'), srcdoc: csp + '<base target="_blank">' + html });
			};
			const pre = () => h('pre', { text: text || '' });
			const box = h('div', { class: 'nve-preview' });
			if (html && text) {
				const seg = h('div', { class: 'nve-seg', role: 'group', 'aria-label': __('Show', 'nemvo-email'), style: { 'margin-bottom': '8px' } });
				const show = (which) => {
					seg.querySelectorAll('button').forEach((b) => b.setAttribute('aria-pressed', String(b.dataset.v === which)));
					put(box, seg, 'html' === which ? frame() : pre());
				};
				seg.append(h('button', { type: 'button', 'data-v': 'html', text: 'HTML', onclick: () => show('html') }), h('button', { type: 'button', 'data-v': 'text', text: __('Text', 'nemvo-email'), onclick: () => show('text') }));
				show('html');
			} else {
				put(box, html ? frame() : pre());
			}
			return box;
		}

		async function openEntry(id) {
			writeHash(Object.assign(readHash(), { entry: id }));
			const inner = h('div', { class: 'nve-dialog__inner' }, h('p', { class: 'nve-muted', text: __('Loading…', 'nemvo-email') }));
			put(dialog, inner);
			if (!dialog.open) {
				dialog.showModal();
			}
			let entry;
			try {
				entry = await api('/log/' + id);
			} catch (e) {
				put(inner, errorBox(e), h('div', { class: 'nve-dialog__actions' }, h('button', { type: 'button', class: 'button', text: __('Close', 'nemvo-email'), onclick: () => dialog.close() })));
				return;
			}
			const r = entry.recipients;
			const rows = [
				[__('From', 'nemvo-email'), addresses([[entry.from_email, entry.from_name]])],
				[__('To', 'nemvo-email'), addresses(r.to)],
				r.cc.length ? [__('Cc', 'nemvo-email'), addresses(r.cc)] : null,
				r.bcc.length ? [__('Bcc', 'nemvo-email'), addresses(r.bcc)] : null,
				r.reply_to.length ? [__('Reply-To', 'nemvo-email'), addresses(r.reply_to)] : null,
				[__('Sent by', 'nemvo-email'), entry.source_name || entry.source || '–'],
				[__('Via', 'nemvo-email'), (entry.provider_label || '–') + (entry.account ? ' · ' + entry.account : '')],
				entry.message_id ? [__('Message ID', 'nemvo-email'), entry.message_url ? h('span', null, entry.message_id + ' · ', h('a', { href: entry.message_url, target: '_blank', rel: 'noopener', text: __('Open in Gmail', 'nemvo-email') })) : entry.message_id] : null,
				[__('Size', 'nemvo-email'), fmt.bytes(entry.size) + (entry.duration_ms ? ' · ' + sprintf(/* translators: %s: milliseconds the provider took */ __('%s ms', 'nemvo-email'), fmt.n(entry.duration_ms)) : '')],
				entry.attachments.length ? [__('Attachments', 'nemvo-email'), entry.attachments.map((a) => a.name + (a.inline ? ' (' + __('inline', 'nemvo-email') + ')' : '')).join(', ')] : null,
				entry.headers.length ? [__('Headers', 'nemvo-email'), h('span', null, entry.headers.map((x, i) => [i ? h('br') : null, x[0] + ': ' + x[1]]))] : null,
			].filter(Boolean);

			const result = h('span', { class: 'nve-muted', role: 'status' });
			const actions = h('div', { class: 'nve-dialog__actions' }, result);
			if (entry.has_content) {
				actions.append(
					h('button', {
						type: 'button',
						class: 'button',
						text: entry.has_attachments ? __('Resend without attachments', 'nemvo-email') : __('Resend', 'nemvo-email'),
						onclick: async (e) => {
							// eslint-disable-next-line no-alert
							if (!window.confirm(sprintf(/* translators: %s: recipients */ __('Send this message again to %s?', 'nemvo-email'), addresses(r.to)))) {
								return;
							}
							e.target.disabled = true;
							try {
								const sent = await api('/log/' + entry.id + '/resend', { method: 'POST' });
								result.textContent = sent.ok ? __('Sent again; it is the newest entry in the log.', 'nemvo-email') : sprintf(/* translators: %s: error */ __('Not sent: %s', 'nemvo-email'), sent.error);
								load();
							} catch (err) {
								result.textContent = err.message;
							}
							e.target.disabled = false;
						},
					})
				);
			}
			actions.append(
				h('button', {
					type: 'button',
					class: 'button nve-button-danger',
					text: __('Delete', 'nemvo-email'),
					onclick: async () => {
						// eslint-disable-next-line no-alert
						if (!window.confirm(__('Delete this log entry?', 'nemvo-email'))) {
							return;
						}
						try {
							await api('/log/' + entry.id, { method: 'DELETE' });
							dialog.close();
							load();
						} catch (err) {
							result.textContent = err.message;
						}
					},
				}),
				h('button', { type: 'button', class: 'button button-primary', text: __('Close', 'nemvo-email'), onclick: () => dialog.close() })
			);

			put(
				inner,
				h(
					'div',
					{ class: 'nve-dialog__head' },
					h('div', null, h('h2', { id: 'nve-dialog-title', text: entry.subject || __('(no subject)', 'nemvo-email') }), h('p', { class: 'nve-muted', style: { margin: '6px 0 0' } }, statusPill(entry.status), ' · ', fmt.full(entry))),
					h('button', { type: 'button', class: 'nve-icon-btn', 'aria-label': __('Close', 'nemvo-email'), onclick: () => dialog.close() }, '✕')
				),
				h(
					'div',
					{ class: 'nve-dialog__body' },
					entry.error ? h('p', { class: 'nve-dialog__error' }, h('strong', { text: __('Error:', 'nemvo-email') + ' ' }), entry.error) : null,
					h(
						'dl',
						{ class: 'nve-meta' },
						rows.map(([dt, dd]) => [h('dt', { text: dt }), h('dd', null, dd)])
					),
					preview(entry)
				),
				actions
			);
		}

		load();
		if (hash.entry) {
			openEntry(parseInt(hash.entry, 10));
		}
	}

	if ('log' === cfg.view) {
		logView();
	} else {
		overview();
	}
	// The views keep their state in the address with replaceState; a link that changes it starts over.
	window.addEventListener('hashchange', () => location.reload());
})(window.Chart, window.wp);
