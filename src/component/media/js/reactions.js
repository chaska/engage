/**
 * @package   AkeebaEngage
 * @copyright Copyright (c)2020-2025 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 *
 * Reacciones a los comentarios (fork 0.7.0): me gusta, no me gusta y favorito. Sin dependencias.
 *
 * El HTML de la pagina es un esqueleto igual para todos (puede estar en cache): este script pide los contadores y el estado de
 * la persona (una sola consulta GET con los ids de la pagina) y pinta aria-pressed, contadores y el fondo del favorito. Para
 * reaccionar envia un POST con el token CSRF que le dio esa misma consulta. Sin JavaScript los botones no se muestran.
 *
 * SEGURIDAD: nada de lo que llega del servidor se inserta como HTML (solo textContent y atributos fijos); todo va en try/catch;
 * los ids se leen de atributos data-* y se validan como enteros antes de usarlos.
 */
(function (window, document) {
    "use strict";

    var TYPES = ["like", "dislike", "favorite"];
    var CHUNK = 100;
    var cfg = null;
    var token = "";
    var busy = {};
    var items = {};
    var liveRegion = null;

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

    /**
     * Un boton de reaccion solo es de fiar si lo ha pintado el servidor: dentro de la botonera de SU comentario, fuera del texto del
     * comentario (que escribe un visitante) y con el mismo id que el comentario. Asi un texto con HTML inyectado no puede hacer que
     * otra persona reaccione sin querer.
     */
    function trusted(btn) {
        try {
            var group = btn.closest(".akengage-reactions[data-engage-reactions]");
            var art = btn.closest('article[id^="akengage-comment-"]');

            if (!group || !art || btn.closest(".akengage-comment-body")) { return false; }

            return art.id === "akengage-comment-" + btn.getAttribute("data-engage-id");
        } catch (e) {
            return false;
        }
    }

    function btnsOf(id) {
        var all = document.querySelectorAll('button[data-engage-react][data-engage-id="' + id + '"]');
        var out = [];

        for (var i = 0; i < all.length; i++) { if (trusted(all[i])) { out.push(all[i]); } }

        return out;
    }

    function say(msg) {
        try {
            if (!liveRegion) {
                liveRegion = document.createElement("div");
                liveRegion.className = "akengage-react-live";
                liveRegion.setAttribute("role", "status");
                liveRegion.setAttribute("aria-live", "polite");
                document.body.appendChild(liveRegion);
            }
            liveRegion.textContent = "";
            window.setTimeout(function () { liveRegion.textContent = msg; }, 30);
        } catch (e) { /* sin aviso hablado */ }
    }

    function setHint(btn, msg) {
        var group = btn.closest(".akengage-reactions");

        if (!group) { return; }

        var id = "akengage-react-hint-" + btn.getAttribute("data-engage-id");
        var hint = document.getElementById(id);

        if (!msg) {
            btn.removeAttribute("aria-describedby");
            return;
        }

        if (!hint) {
            hint = document.createElement("span");
            hint.id = id;
            hint.className = "akengage-react-hint";
            group.appendChild(hint);
        }

        hint.textContent = msg;
        btn.setAttribute("aria-describedby", id);
        btn.setAttribute("title", msg);
    }

    function paint(id, item, st) {
        var article = document.getElementById("akengage-comment-" + id);
        var mine = (item && item.mine) || {like: false, dislike: false, favorite: false};
        var buttons = btnsOf(id);
        var msg = "";

        for (var i = 0; i < buttons.length; i++) {
            var b = buttons[i];
            var type = b.getAttribute("data-engage-react");
            var off = false;
            var why = "";

            if (!st.auth) {
                off = true;
                why = text("COM_ENGAGE_REACTIONS_HINT_LOGIN", "Log in to react.");
            } else if (!st.can) {
                off = true;
                why = text("COM_ENGAGE_REACTIONS_HINT_COMMENTERS", "You cannot react.");
            } else if (item && item.own && type !== "favorite") {
                off = true;
                why = text("COM_ENGAGE_REACTIONS_HINT_OWN", "You cannot rate your own comment.");
            }

            b.setAttribute("aria-pressed", mine[type] ? "true" : "false");
            b.disabled = off; // NO se deshabilita mientras se envia: un boton deshabilitado pierde el foco del teclado
            b.setAttribute("aria-busy", busy[id] ? "true" : "false");

            if (off) {
                setHint(b, why);
                msg = why;
            } else {
                b.removeAttribute("aria-describedby");
                b.removeAttribute("title");
            }
        }

        var group = buttons.length ? buttons[0].closest(".akengage-reactions") : null;
        var counts = group ? group.querySelectorAll(".akengage-react-count[data-engage-count]") : [];

        for (var j = 0; j < counts.length; j++) {
            var kind = counts[j].getAttribute("data-engage-count");
            counts[j].textContent = String(item && typeof item[kind] === "number" ? item[kind] : 0);
        }

        if (article) {
            article.classList.toggle("akengage-is-favorite", !!mine.favorite);
        }
    }

    var state = {auth: false, can: false};

    function apply(data) {
        state.auth = !!data.auth;
        state.can = !!data.can;
        token = (typeof data.token === "string") ? data.token : "";

        var groups = document.querySelectorAll("[data-engage-reactions]");

        for (var i = 0; i < groups.length; i++) {
            var first = groups[i].querySelector("button[data-engage-id]");
            var id = first ? first.getAttribute("data-engage-id") : "";

            if (!first || !trusted(first) || !isId(id) || !data.items || !Object.prototype.hasOwnProperty.call(data.items, id)) {
                continue;
            }

            items[id] = data.items[id];
            paint(id, items[id], state);
            groups[i].hidden = false;
        }

        showFavToggle();
    }

    /**
     * 0.8.0: el conmutador «Solo mis favoritos» de la barra de la lista viene oculto en el HTML (que puede estar en cache y es igual para
     * todos); solo se muestra a quien tiene sesion y puede reaccionar. Es un enlace normal a la misma pagina con akengage_fav=1: el
     * servidor vuelve a comprobar la sesion al servir esa lista. Solo se hace caso al conmutador de la barra, fuera del texto de los comentarios.
     */
    function showFavToggle() {
        var toggles = document.querySelectorAll("[data-engage-fav-toggle]");

        for (var i = 0; i < toggles.length; i++) {
            var t = toggles[i];

            if (!t.closest("[data-engage-toolbar]") || t.closest(".akengage-comment-body") || t.getAttribute("aria-current") === "true") { continue; }

            t.hidden = !(state.auth && state.can);
        }
    }

    function collectIds() {
        var seen = {};
        var out = [];
        var all = document.querySelectorAll("button[data-engage-react][data-engage-id]");

        for (var i = 0; i < all.length; i++) {
            var id = all[i].getAttribute("data-engage-id");

            if (isId(id) && !seen[id] && trusted(all[i])) {
                seen[id] = true;
                out.push(id);
            }
        }

        return out;
    }

    function load() {
        var ids = collectIds();

        if (!cfg || !cfg.stateUrl || !ids.length || !window.fetch) { return Promise.resolve(); }

        var jobs = [];

        for (var i = 0; i < ids.length; i += CHUNK) {
            var url = cfg.stateUrl + (cfg.stateUrl.indexOf("?") === -1 ? "?" : "&") + "ids=" + ids.slice(i, i + CHUNK).join(",");

            jobs.push(window.fetch(url, {method: "GET", credentials: "same-origin", cache: "no-store", headers: {"Accept": "application/json"}})
                .then(function (r) { return r.ok ? r.json() : null; })
                .then(function (data) {
                    if (data && data.ok && data.enabled !== false) { apply(data); }
                })
                .catch(function () { /* los botones siguen ocultos */ }));
        }

        return Promise.all(jobs);
    }

    function release(id) {
        delete busy[id];
        paint(id, items[id], state);
    }

    function react(btn) {
        var id = btn.getAttribute("data-engage-id");
        var type = btn.getAttribute("data-engage-react");

        if (!trusted(btn) || !isId(id) || TYPES.indexOf(type) === -1 || busy[id] || btn.disabled || !token || !cfg || !cfg.toggleUrl) { return; }

        busy[id] = true;
        paint(id, items[id], state);

        var body = new URLSearchParams();
        body.set("comment_id", id);
        body.set("type", type);
        body.set(token, "1");

        window.fetch(cfg.toggleUrl, {
            method: "POST",
            credentials: "same-origin",
            cache: "no-store",
            headers: {"Accept": "application/json", "Content-Type": "application/x-www-form-urlencoded"},
            body: body.toString()
        }).then(function (r) {
            return r.json().then(function (data) { return {status: r.status, data: data}; }, function () { return {status: r.status, data: null}; });
        }).then(function (res) {
            var d = res.data;

            if (res.status === 200 && d && d.ok && d.counts && d.mine) {
                items[id] = {like: d.counts.like | 0, dislike: d.counts.dislike | 0, mine: d.mine, own: !!(items[id] && items[id].own)};
                btn.classList.remove("akengage-react-pop");
                void btn.offsetWidth;
                btn.classList.add("akengage-react-pop");
                release(id);
                return;
            }

            release(id);
            say(res.status === 429 ? text("COM_ENGAGE_REACTIONS_ERR_RATE", "Too many reactions.")
                : (res.status === 403 || res.status === 404 ? text("COM_ENGAGE_REACTIONS_ERR_DENIED", "Not allowed.") : text("COM_ENGAGE_REACTIONS_ERR_FAILED", "Failed.")));

            if (res.status === 403) { load(); } // token caducado o permisos cambiados: se vuelve a pedir el estado
        }).catch(function () {
            release(id);
            say(text("COM_ENGAGE_REACTIONS_ERR_FAILED", "Failed."));
        });
    }

    function onClick(e) {
        try {
            var t = e.target;
            var btn = t && t.closest ? t.closest("button[data-engage-react]") : null;

            if (btn) {
                e.preventDefault();
                react(btn);
            }
        } catch (err) { /* nunca romper la pagina */ }
    }

    function init() {
        try {
            cfg = (window.Joomla && window.Joomla.getOptions) ? window.Joomla.getOptions("akeeba.Engage.Reactions") : null;

            if (!cfg || !document.querySelector("[data-engage-reactions]")) { return; }

            document.addEventListener("click", onClick);
            window.addEventListener("pageshow", function (e) { if (e.persisted) { load(); } });
            load();
        } catch (e) { /* sin reacciones */ }
    }

    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", init);
    } else {
        init();
    }
})(window, document);
