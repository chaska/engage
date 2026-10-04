/*!
 * @package   AkeebaEngage
 * @copyright Copyright (c)2020-2025 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 *
 * Fork comunitario (0.6.24): comportamiento del panel de control. Sin librerias ni peticiones de red: selector de diseno
 * (radiogroup con flechas), ventana de confirmacion accesible (<dialog>; nunca confirm()) para las acciones rapidas y
 * tooltip del grafico. Las acciones rapidas envian el formulario #eg-quick a las tareas existentes de comentarios.
 */
(function (d, w) {
	'use strict';

	var opts = (w.Joomla && w.Joomla.getOptions && w.Joomla.getOptions('com_engage.panel')) || {};

	function ready(fn) {
		if (d.readyState === 'loading') {
			d.addEventListener('DOMContentLoaded', fn);
		} else {
			fn();
		}
	}

	ready(function () {
		var root = d.getElementById('eg-admin');

		if (!root) {
			return;
		}

		var live = d.getElementById('eg-live');

		function say(text) {
			if (!live) {
				return;
			}
			live.textContent = '';
			w.setTimeout(function () {
				live.textContent = text;
			}, 30);
		}

		/* ---------------------------------------------------------- Selector de diseno */
		var T = w.EngagePanelTheme;
		var btns = [].slice.call(root.querySelectorAll('[data-eg-theme-set]'));

		function paint(pref) {
			btns.forEach(function (b) {
				var on = b.getAttribute('data-eg-theme-set') === pref;
				b.setAttribute('aria-checked', on ? 'true' : 'false');
				b.tabIndex = on ? 0 : -1;
			});
		}

		if (T && btns.length) {
			paint(T.get());

			btns.forEach(function (b, i) {
				b.addEventListener('click', function () {
					var pref = b.getAttribute('data-eg-theme-set');
					T.set(pref);
					paint(pref);
					var names = opts.themes || {};
					say((opts.themeSaved || '%s').replace('{THEME}', names[pref] || pref));
				});

				b.addEventListener('keydown', function (ev) {
					var k = ev.key;
					var to = -1;

					if (k === 'ArrowRight' || k === 'ArrowDown') {
						to = (i + 1) % btns.length;
					} else if (k === 'ArrowLeft' || k === 'ArrowUp') {
						to = (i - 1 + btns.length) % btns.length;
					} else if (k === 'Home') {
						to = 0;
					} else if (k === 'End') {
						to = btns.length - 1;
					}

					if (to >= 0) {
						ev.preventDefault();
						btns[to].focus();
						btns[to].click();
					}
				});
			});

			// "Automatico" sigue al dispositivo tambien mientras la pagina esta abierta
			try {
				var mq = w.matchMedia('(prefers-color-scheme: dark)');
				var onChange = function () {
					if (T.get() === 'auto') {
						T.apply('auto');
					}
				};
				if (mq.addEventListener) {
					mq.addEventListener('change', onChange);
				}
			} catch (e) { /* sin matchMedia */ }
		}

		/* ---------------------------------------------------------- Ventana de confirmacion */
		var dlg = d.getElementById('eg-dialog');
		var form = d.getElementById('eg-quick');
		var pending = null;
		var opener = null;

		function iconFor(tone) {
			var name = tone === 'ok' ? 'check' : (tone === 'warn' ? 'flag' : 'trash');
			var ns = 'http://www.w3.org/2000/svg';
			var svg = d.createElementNS(ns, 'svg');
			svg.setAttribute('class', 'eg-ic');
			svg.setAttribute('aria-hidden', 'true');
			svg.setAttribute('focusable', 'false');
			var use = d.createElementNS(ns, 'use');
			use.setAttribute('href', '#eg-i-' + name);
			svg.appendChild(use);
			return svg;
		}

		if (dlg && form) {
			var title = d.getElementById('eg-dialog-title');
			var text = d.getElementById('eg-dialog-text');
			var quote = d.getElementById('eg-dialog-quote');
			var ico = d.getElementById('eg-dialog-icon');
			var ok = d.getElementById('eg-dialog-ok');

			root.addEventListener('click', function (ev) {
				var b = ev.target.closest ? ev.target.closest('[data-eg-act]') : null;

				if (!b || !root.contains(b)) {
					return;
				}

				var def = (opts.dialog || {})[b.getAttribute('data-eg-act')];

				if (!def) {
					return;
				}

				pending = {task: def.task, id: b.getAttribute('data-eg-id')};
				opener = b;
				title.textContent = def.title;
				text.textContent = def.text;
				quote.textContent = (b.getAttribute('data-eg-who') || '') + ': ' + (b.getAttribute('data-eg-excerpt') || '');
				ok.textContent = def.ok;
				ok.className = 'eg-btn eg-btn--' + (def.tone === 'ok' ? 'ok' : (def.tone === 'warn' ? 'warn' : 'bad'));
				dlg.className = 'eg-dialog eg-dialog--' + def.tone;
				ico.textContent = '';
				ico.appendChild(iconFor(def.tone));

				if (typeof dlg.showModal === 'function') {
					dlg.showModal();
				} else {
					dlg.setAttribute('open', '');
				}

				// Foco en "Cancelar": la accion destructiva nunca queda como respuesta por defecto
				var cancel = d.getElementById('eg-dialog-cancel');
				if (cancel) {
					cancel.focus();
				}
			});

			dlg.addEventListener('close', function () {
				var accepted = dlg.returnValue === 'ok' && pending;
				var p = pending;
				pending = null;
				dlg.returnValue = '';

				if (accepted) {
					form.elements.task.value = p.task;
					form.elements['cid[]'].value = p.id;
					form.submit();
					return;
				}

				if (opener && opener.focus) {
					opener.focus();
				}
			});

			// Clic en el fondo = cancelar
			dlg.addEventListener('click', function (ev) {
				if (ev.target === dlg) {
					dlg.close('cancel');
				}
			});
		}

		/* ---------------------------------------------------------- Tooltip del grafico */
		var plot = root.querySelector('.eg-chart__plot');
		var tip = d.getElementById('eg-chart-tip');

		if (plot && tip) {
			var cols = [].slice.call(plot.querySelectorAll('.eg-chart__col'));
			var active = null;

			var show = function (col) {
				var n = parseInt(col.getAttribute('data-n'), 10) || 0;
				var tpl = n === 1 ? opts.tipOne : opts.tipMany;
				tip.textContent = (tpl || '{DATE}: {N}').replace('{DATE}', col.getAttribute('data-date') || '').replace('{N}', String(n));
				tip.hidden = false;

				var pr = plot.getBoundingClientRect();
				var hr = col.querySelector('.eg-chart__hit').getBoundingClientRect();
				var x = hr.left - pr.left + hr.width / 2 - tip.offsetWidth / 2;
				x = Math.max(0, Math.min(pr.width - tip.offsetWidth, x));
				tip.style.transform = 'translate(' + Math.round(x) + 'px, -' + (tip.offsetHeight + 6) + 'px)';

				if (active) {
					active.classList.remove('is-active');
				}
				active = col;
				col.classList.add('is-active');
			};

			var hide = function () {
				tip.hidden = true;
				if (active) {
					active.classList.remove('is-active');
					active = null;
				}
			};

			cols.forEach(function (col) {
				col.addEventListener('pointerenter', function () {
					show(col);
				});
			});
			plot.addEventListener('pointerleave', hide);
			plot.addEventListener('pointerdown', function (ev) {
				var c = ev.target.closest ? ev.target.closest('.eg-chart__col') : null;
				if (c) {
					show(c);
				}
			});
		}
	});
})(document, window);
