/*!
 * @package   AkeebaEngage
 * @copyright Copyright (c)2020-2025 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 *
 * Fork comunitario (0.6.25): pantalla de opciones modernas. Cada cambio se guarda AL INSTANTE con fetch (POST, token CSRF de
 * Joomla en cada peticion) y, si el servidor lo rechaza o no responde, el control vuelve al valor anterior y se avisa.
 * Controles: interruptor (role=switch), segmentado y tarjetas de tema (role=radiogroup, flechas), selector, numero y texto.
 * Sin librerias ni otras peticiones de red que el propio guardado.
 */
(function (d, w) {
	'use strict';

	var opts = (w.Joomla && w.Joomla.getOptions && w.Joomla.getOptions('com_engage.settings')) || {};
	var msg = opts.msg || {};

	function ready(fn) {
		if (d.readyState === 'loading') {
			d.addEventListener('DOMContentLoaded', fn);
		} else {
			fn();
		}
	}

	ready(function () {
		var root = d.getElementById('eg-admin');

		if (!root || root.getAttribute('data-eg-view') !== 'settings') {
			return;
		}

		var live = d.getElementById('eg-live');
		var toast = d.getElementById('eg-toast');
		var toastTimer = 0;
		var rows = [].slice.call(root.querySelectorAll('[data-eg-row]'));

		function say(text) {
			if (live) {
				live.textContent = '';
				w.setTimeout(function () { live.textContent = text; }, 30);
			}
		}

		function showToast(text, isError) {
			if (!toast) {
				return;
			}
			toast.textContent = text;
			toast.className = 'eg-toast' + (isError ? ' eg-toast--error' : '');
			toast.hidden = false;
			w.clearTimeout(toastTimer);
			toastTimer = w.setTimeout(function () { toast.hidden = true; }, isError ? 6000 : 1800);
			say(text);
		}

		/* ---------------------------------------------------------- Pestanas (categorias) */
		var tabs = [].slice.call(root.querySelectorAll('[data-eg-tab]'));

		function openTab(id, focus) {
			tabs.forEach(function (t) {
				var on = t.getAttribute('data-eg-tab') === id;
				t.setAttribute('aria-selected', on ? 'true' : 'false');
				t.tabIndex = on ? 0 : -1;
				var p = d.getElementById(t.getAttribute('aria-controls'));
				if (p) {
					p.hidden = !on;
				}
				if (on && focus) {
					t.focus();
				}
			});
		}

		tabs.forEach(function (t, i) {
			t.addEventListener('click', function () {
				openTab(t.getAttribute('data-eg-tab'), false);
				try { w.history.replaceState(null, '', '#' + t.getAttribute('data-eg-tab')); } catch (e) { /* sin historial */ }
			});
			t.addEventListener('keydown', function (ev) {
				var k = ev.key, to = -1;
				if (k === 'ArrowDown' || k === 'ArrowRight') { to = (i + 1) % tabs.length; }
				else if (k === 'ArrowUp' || k === 'ArrowLeft') { to = (i - 1 + tabs.length) % tabs.length; }
				else if (k === 'Home') { to = 0; }
				else if (k === 'End') { to = tabs.length - 1; }
				if (to >= 0) {
					ev.preventDefault();
					openTab(tabs[to].getAttribute('data-eg-tab'), true);
				}
			});
		});

		if (w.location.hash) {
			var h = w.location.hash.slice(1);
			if (tabs.some(function (t) { return t.getAttribute('data-eg-tab') === h; })) {
				openTab(h, false);
			}
		}

		/* ---------------------------------------------------------- Valores y "showon" */
		function rowOf(scope, key) {
			for (var i = 0; i < rows.length; i++) {
				if (rows[i].getAttribute('data-scope') === scope && rows[i].getAttribute('data-key') === key) {
					return rows[i];
				}
			}
			return null;
		}

		function valueOf(row) {
			return row.getAttribute('data-value') || '';
		}

		// Sintaxis de Joomla: campo:valor / campo!:valor, unidos con [AND] / [OR], evaluados de izquierda a derecha
		function evalShowon(row) {
			var s = row.getAttribute('data-eg-showon');
			if (!s) {
				return true;
			}
			var scope = row.getAttribute('data-scope');
			var parts = s.split(/(\[AND\]|\[OR\])/);
			var result = null, op = null;
			for (var i = 0; i < parts.length; i++) {
				var p = parts[i];
				if (p === '[AND]' || p === '[OR]') { op = p; continue; }
				var m = p.match(/^([\w.\-]+)(!?):(.*)$/);
				if (!m) { continue; }
				var other = rowOf(scope, m[1]);
				var cur = other ? valueOf(other) : '';
				var vals = m[3].split(',');
				var hit = vals.indexOf(cur) !== -1;
				var ok = m[2] === '!' ? !hit : hit;
				result = result === null ? ok : (op === '[OR]' ? (result || ok) : (result && ok));
			}
			return result === null ? true : result;
		}

		function refreshShowon() {
			rows.forEach(function (r) { r.hidden = !evalShowon(r); });
		}

		/* ---------------------------------------------------------- Vista previa en vivo */
		var preview = root.querySelector('[data-eg-preview]');
		var previewMap = {theme: 'data-theme', reply_indent: 'data-indent', reply_show_quote: 'data-quote', reply_style: 'data-style'};

		function refreshPreview(row) {
			if (!preview || row.getAttribute('data-scope') !== 'com_engage') {
				return;
			}
			var attr = previewMap[row.getAttribute('data-key')];
			if (attr) {
				preview.setAttribute(attr, valueOf(row));
			}
		}

		/* ---------------------------------------------------------- Pintar un valor en su control */
		function paint(row, value) {
			row.setAttribute('data-value', value);
			var c = row.getAttribute('data-control');
			if (c === 'switch') {
				row.querySelector('.eg-switch').setAttribute('aria-checked', value === '1' ? 'true' : 'false');
			} else if (c === 'segmented' || c === 'cards') {
				[].forEach.call(row.querySelectorAll('[role="radio"]'), function (b) {
					var on = b.getAttribute('data-value') === value;
					b.setAttribute('aria-checked', on ? 'true' : 'false');
					b.tabIndex = on ? 0 : -1;
				});
			} else {
				var inp = row.querySelector('input, select, textarea');
				if (inp && inp.value !== value) {
					inp.value = value;
				}
			}
			refreshPreview(row);
			refreshShowon();
		}

		function setState(row, state, text) {
			row.classList.remove('is-saving', 'is-saved', 'is-error');
			var lab = row.querySelector('.eg-row__label');
			if (state) {
				row.classList.add('is-' + state);
				if (lab) { lab.setAttribute('data-st', text || ''); }
			} else if (lab) {
				lab.removeAttribute('data-st');
			}
			row.setAttribute('aria-busy', state === 'saving' ? 'true' : 'false');
		}

		function showError(row, text) {
			var er = row.querySelector('.eg-row__err');
			if (er) {
				er.textContent = text || '';
				er.hidden = !text;
			}
			var inp = row.querySelector('input, select, textarea');
			if (inp) {
				if (text) { inp.setAttribute('aria-invalid', 'true'); } else { inp.removeAttribute('aria-invalid'); }
			}
		}

		/* ---------------------------------------------------------- Guardado instantaneo */
		var chains = new WeakMap();
		var stateTimers = new WeakMap();

		function save(row, value) {
			var previous = valueOf(row);

			if (value === previous) {
				return;
			}

			paint(row, value);          // efecto inmediato en pantalla (y en la vista previa)
			showError(row, '');
			setState(row, 'saving', msg.saving);

			var body = new w.URLSearchParams();
			body.append('scope', row.getAttribute('data-scope'));
			body.append('key', row.getAttribute('data-key'));
			body.append('value', value);
			body.append(opts.token, '1');

			var run = function () {
				return w.fetch(opts.url, {method: 'POST', body: body, credentials: 'same-origin', headers: {'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest'}})
					.then(function (rs) {
						return rs.text().then(function (t) {
							var j = null;
							try { j = JSON.parse(t); } catch (e) { j = null; }
							return {status: rs.status, json: j};
						});
					});
			};

			var prev = chains.get(row) || Promise.resolve();
			var p = prev.then(run, run).then(function (res) {
				if (res.status === 200 && res.json && res.json.ok === true) {
					if (typeof res.json.value === 'string' && res.json.value !== valueOf(row)) {
						paint(row, res.json.value);
					}
					setState(row, 'saved', msg.saved);
					showToast(msg.saved, false);
					w.clearTimeout(stateTimers.get(row));
					stateTimers.set(row, w.setTimeout(function () { setState(row, ''); }, 2200));
					return;
				}
				var kind = res.status === 403 ? 'denied' : (res.status === 422 ? 'invalid' : 'failed');
				fail(row, previous, msg[kind] || msg.failed);
			}, function () {
				fail(row, previous, msg.network);
			});
			chains.set(row, p);
		}

		function fail(row, previous, text) {
			paint(row, previous);       // reversion visual
			setState(row, 'error', msg.reverted);
			showError(row, text);
			showToast(text + ' ' + (msg.reverted || ''), true);
		}

		/* ---------------------------------------------------------- Controles */
		rows.forEach(function (row) {
			var c = row.getAttribute('data-control');

			if (c === 'switch') {
				var sw = row.querySelector('.eg-switch');
				sw.addEventListener('click', function () {
					save(row, valueOf(row) === '1' ? '0' : '1');
				});
			} else if (c === 'segmented' || c === 'cards') {
				var radios = [].slice.call(row.querySelectorAll('[role="radio"]'));
				radios.forEach(function (b, i) {
					b.addEventListener('click', function () { save(row, b.getAttribute('data-value')); });
					b.addEventListener('keydown', function (ev) {
						var k = ev.key, to = -1;
						if (k === 'ArrowRight' || k === 'ArrowDown') { to = (i + 1) % radios.length; }
						else if (k === 'ArrowLeft' || k === 'ArrowUp') { to = (i - 1 + radios.length) % radios.length; }
						else if (k === 'Home') { to = 0; }
						else if (k === 'End') { to = radios.length - 1; }
						if (to >= 0) {
							ev.preventDefault();
							radios[to].focus();
							radios[to].click();
						}
					});
				});
			} else if (c === 'select') {
				row.querySelector('select').addEventListener('change', function (ev) { save(row, ev.target.value); });
			} else {
				var inp = row.querySelector('input, textarea');
				var timer = 0;
				var commit = function () {
					w.clearTimeout(timer);
					var v = inp.value;
					if (c === 'number') {
						if (!inp.checkValidity() || v === '') {
							showError(row, msg.range);
							return;
						}
					}
					save(row, v);
				};
				inp.addEventListener('input', function () {
					if (c === 'number') {
						showError(row, inp.checkValidity() && inp.value !== '' ? '' : msg.range);
					}
					w.clearTimeout(timer);
					if (c !== 'textarea') {
						timer = w.setTimeout(commit, 900);
					}
				});
				inp.addEventListener('change', commit);
				inp.addEventListener('keydown', function (ev) {
					if (ev.key === 'Enter' && c !== 'textarea') {
						ev.preventDefault();
						commit();
					}
				});
				inp.addEventListener('blur', function () {
					// un valor no valido que se deja a medias vuelve al ultimo guardado
					if (c === 'number' && (!inp.checkValidity() || inp.value === '')) {
						inp.value = valueOf(row);
						showError(row, '');
					}
				});
			}
		});

		refreshShowon();
		rows.forEach(refreshPreview);
	});
})(document, window);
