/**
 * @package   AkeebaEngage
 * @copyright Copyright (c)2020-2025 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 *
 * Herramientas de la lista de comentarios (fork 0.8.0): boton «Copiar enlace». Sin dependencias.
 *
 * El servidor pinta cada boton con el enlace permanente YA validado (desde la 0.8.1 una RUTA relativa con su consulta validada y el ancla, en un atributo data-engage-copy: el HTML puede estar en la cache y no debe llevar el anfitrion de nadie). Este
 * script solo (1) muestra los botones si el navegador puede copiar, (2) copia ese enlace con la API Clipboard o, si no esta
 * disponible, con un area de texto temporal y execCommand("copy"), y (3) avisa con una region role="status" (aria-live), sin
 * alert() ni confirm().
 *
 * SEGURIDAD: el enlace nunca se construye con datos del usuario; aqui se vuelve a comprobar (esquema http/https, mismo origen que la
 * pagina o, si el anfitrion de la copia en cache no coincide, se reconstruye con el origen real; el id de la URL debe ser el del
 * comentario). Un boton solo es de fiar si lo pinto el servidor: dentro de la fila de SU comentario y fuera del texto del
 * comentario, que escribe un visitante. Nada se inserta como HTML (solo textContent y atributos fijos).
 */
(function (window, document) {
    "use strict";

    var liveRegion = null;
    var hideTimer = 0;

    function text(key, fallback) {
        try {
            var s = window.Joomla && window.Joomla.Text && window.Joomla.Text._(key);
            return s ? s : fallback;
        } catch (e) {
            return fallback;
        }
    }

    function isId(v) {
        return /^[1-9][0-9]{0,17}$/.test(String(v));
    }

    function trusted(btn) {
        try {
            var art = btn.closest('article[id^="akengage-comment-"]');

            if (!art || btn.closest(".akengage-comment-body") || !btn.closest(".akengage-copylink")) { return false; }

            return art.id === "akengage-comment-" + btn.getAttribute("data-engage-id");
        } catch (e) {
            return false;
        }
    }

    /**
     * Devuelve la URL a copiar o "" si no es valida. Debe ser http(s), llevar akengage_cid = id del comentario y el ancla
     * #akengage-comment-<id>; si su origen no es el de la pagina (pagina en cache servida desde otro nombre de anfitrion), se
     * reconstruye con el origen real.
     */
    function safeUrl(raw, id) {
        try {
            var u = new URL(String(raw), window.location.href);

            if ((u.protocol !== "http:" && u.protocol !== "https:") || u.username !== "" || u.password !== "") { return ""; }

            if (u.origin !== window.location.origin) {
                u = new URL(u.pathname + u.search + u.hash, window.location.origin);
            }

            if (u.searchParams.get("akengage_cid") !== String(id) || u.hash !== "#akengage-comment-" + id) { return ""; }

            return u.href;
        } catch (e) {
            return "";
        }
    }

    function region() {
        if (!liveRegion) {
            liveRegion = document.createElement("div");
            liveRegion.className = "akengage-toast";
            liveRegion.setAttribute("role", "status");
            liveRegion.setAttribute("aria-live", "polite");
            liveRegion.setAttribute("aria-atomic", "true");
            liveRegion.hidden = true;
            document.body.appendChild(liveRegion);
        }

        return liveRegion;
    }

    function say(msg, btn) {
        try {
            var r = region();

            window.clearTimeout(hideTimer);
            r.textContent = "";
            r.hidden = false;
            window.setTimeout(function () { r.textContent = msg; }, 30);
            hideTimer = window.setTimeout(function () {
                r.hidden = true;
                r.textContent = "";
            }, 2600);

            if (btn) {
                btn.classList.add("akengage-copied");
                window.setTimeout(function () { btn.classList.remove("akengage-copied"); }, 1600);
            }
        } catch (e) { /* sin aviso */ }
    }

    /** Alternativa sin la API Clipboard: area de texto temporal (clase CSS, sin estilos en linea) + execCommand. */
    function legacyCopy(value) {
        var ta = null;

        try {
            var active = document.activeElement;

            ta = document.createElement("textarea");
            ta.className = "akengage-copy-tmp";
            ta.value = value;
            ta.setAttribute("readonly", "readonly");
            ta.setAttribute("aria-hidden", "true");
            ta.tabIndex = -1;
            document.body.appendChild(ta);
            ta.select();
            ta.setSelectionRange(0, value.length);

            var ok = !!document.execCommand && document.execCommand("copy");

            document.body.removeChild(ta);
            ta = null;

            if (active && active.focus) { active.focus(); }

            return ok;
        } catch (e) {
            try { if (ta && ta.parentNode) { ta.parentNode.removeChild(ta); } } catch (e2) { /* nada */ }

            return false;
        }
    }

    function copy(btn) {
        var id = btn.getAttribute("data-engage-id");
        var url = isId(id) ? safeUrl(btn.getAttribute("data-engage-copy"), id) : "";

        if (!url || !trusted(btn)) { return; }

        var okMsg = text("COM_ENGAGE_TOOLS_COPY_OK", "Link copied");
        var failMsg = text("COM_ENGAGE_TOOLS_COPY_FAIL", "The link could not be copied.");

        function done(ok) { say(ok ? okMsg : failMsg, ok ? btn : null); }

        if (window.navigator && window.navigator.clipboard && window.navigator.clipboard.writeText && window.isSecureContext) {
            window.navigator.clipboard.writeText(url).then(function () { done(true); }, function () { done(legacyCopy(url)); });
        } else {
            done(legacyCopy(url));
        }
    }

    function onClick(e) {
        try {
            var t = e.target;
            var btn = t && t.closest ? t.closest("button[data-engage-copy]") : null;

            if (btn) {
                e.preventDefault();
                copy(btn);
            }
        } catch (err) { /* nunca romper la pagina */ }
    }

    function init() {
        try {
            var cfg = (window.Joomla && window.Joomla.getOptions) ? window.Joomla.getOptions("akeeba.Engage.Tools") : null;
            var wraps = document.querySelectorAll("[data-engage-copywrap]");

            if (!cfg || !cfg.copy || !wraps.length) { return; }

            // Solo se ofrece el boton si el navegador tiene alguna forma de copiar
            var can = !!(window.navigator && window.navigator.clipboard && window.navigator.clipboard.writeText) || !!(document.queryCommandSupported && document.queryCommandSupported("copy"));

            if (!can) { return; }

            for (var i = 0; i < wraps.length; i++) {
                var btn = wraps[i].querySelector("button[data-engage-copy]");

                if (btn && trusted(btn)) { wraps[i].hidden = false; }
            }

            document.addEventListener("click", onClick);
        } catch (e) { /* sin boton */ }
    }

    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", init);
    } else {
        init();
    }
})(window, document);
