/*!
 * @package   AkeebaEngage
 * @copyright Copyright (c)2020-2025 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 *
 * Fork comunitario (0.6.24): aplica el diseno elegido del panel (claro / medio / oscuro / automatico) ANTES de pintar la
 * pagina, para evitar el parpadeo. Se carga sin "defer" en la cabecera. Solo lee/escribe localStorage (con try/catch).
 * Clave: eg-admin-theme. Pone data-eg-theme (diseno efectivo) y data-eg-pref (preferencia) en <html>.
 */
(function (d, w) {
	'use strict';

	var KEY = 'eg-admin-theme';
	var VALID = {light: 1, mid: 1, dark: 1, auto: 1};

	function stored() {
		try {
			var v = w.localStorage.getItem(KEY);
			return VALID[v] === 1 ? v : 'auto';
		} catch (e) {
			return 'auto';
		}
	}

	function effective(pref) {
		if (pref !== 'auto') {
			return pref;
		}
		try {
			return w.matchMedia && w.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
		} catch (e) {
			return 'light';
		}
	}

	function apply(pref) {
		var el = d.documentElement;
		el.setAttribute('data-eg-pref', pref);
		el.setAttribute('data-eg-theme', effective(pref));
	}

	w.EngagePanelTheme = {
		get: stored,
		set: function (pref) {
			if (VALID[pref] !== 1) {
				return;
			}
			try {
				w.localStorage.setItem(KEY, pref);
			} catch (e) {
				/* sin almacenamiento: vale para esta pagina */
			}
			apply(pref);
		},
		apply: apply
	};

	apply(stored());
})(document, window);
