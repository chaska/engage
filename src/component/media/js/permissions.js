/**
 * @package   AkeebaEngage
 * @copyright Copyright (c)2020-2025 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

/**
 * Permissions matrix (0.9.16). Minimal: it only toggles the state of a cell between «allowed» and «not assigned» (an inherited cell becomes an explicit allow;
 * a denied cell and Super Users are never touched), remembers what changed and sends ONE POST with the CSRF token. The server validates and recalculates
 * everything; after saving, every cell is redrawn with the states that the server (that is, Joomla) answers. Only textContent and attributes are used to write to the page.
 */
(function (window, document) {
    "use strict";

    var table = document.getElementById("eg-perm-table");

    if (!table) { return; }

    var saveBtn = document.getElementById("eg-perm-save");
    var discardBtn = document.getElementById("eg-perm-discard");
    var statusEl = document.getElementById("eg-perm-status");
    var dialog = document.getElementById("eg-perm-confirm");
    var broad = (table.getAttribute("data-broad") || "").split(",").filter(Boolean);
    var sensitive = (table.getAttribute("data-sensitive") || "").split(",").filter(Boolean);
    var tpl = table.getAttribute("data-aria") || "{g}: {a}: {s}";
    var busy = false;

    function cells() { return Array.prototype.slice.call(table.querySelectorAll("button.eg-perm-cell")); }

    function label(btn) {
        var s = table.getAttribute("data-s-" + btn.getAttribute("data-state")) || btn.getAttribute("data-state");

        return tpl.replace("{g}", btn.getAttribute("data-gt")).replace("{a}", btn.getAttribute("data-an")).replace("{s}", s);
    }

    function paint(btn, state) {
        btn.setAttribute("data-state", state);
        btn.setAttribute("aria-pressed", state === "allow" || state === "always" ? "true" : (state === "inherit" ? "mixed" : "false"));

        if (state === "allow" || state === "inherit" || state === "unset") { btn.removeAttribute("aria-disabled"); }
        else { btn.setAttribute("aria-disabled", "true"); }

        btn.setAttribute("aria-label", label(btn));
        btn.toggleAttribute("data-pending", pendingOf(btn) !== null);
    }

    /** The change that this cell asks for, or null. allow wanted when it is now «allow» and was not; not assigned when it was an explicit allow and now is not. */
    function pendingOf(btn) {
        var now = btn.getAttribute("data-state");
        var was = btn.getAttribute("data-orig");

        if (now === "allow" && was !== "allow") { return 1; }
        if (now === "unset" && was === "allow") { return 0; }

        return null;
    }

    function changes() {
        var out = [];

        cells().forEach(function (b) {
            var v = pendingOf(b);

            if (v !== null) { out.push({g: parseInt(b.getAttribute("data-g"), 10), a: b.getAttribute("data-a"), v: v, gt: b.getAttribute("data-gt"), an: b.getAttribute("data-an")}); }
        });

        return out;
    }

    function refresh() {
        var n = changes().length;

        saveBtn.disabled = busy || n === 0;
        discardBtn.disabled = busy || n === 0;

        if (n > 0) { statusEl.textContent = (table.getAttribute("data-t-pending") || "").replace("{n}", String(n)); }
    }

    function toggle(btn) {
        var st = btn.getAttribute("data-state");

        if (busy || st === "always" || st === "deny") { return; }

        paint(btn, st === "allow" ? "unset" : "allow");
        refresh();
    }

    table.addEventListener("click", function (e) {
        var btn = e.target && e.target.closest ? e.target.closest("button.eg-perm-cell") : null;

        if (btn && table.contains(btn)) { toggle(btn); }
    });

    discardBtn.addEventListener("click", function () {
        cells().forEach(function (b) { paint(b, b.getAttribute("data-orig")); });
        statusEl.textContent = "";
        refresh();
    });

    function needsConfirm(list) {
        return list.filter(function (c) { return c.v === 1 && broad.indexOf(String(c.g)) !== -1 && sensitive.indexOf(c.a) !== -1; });
    }

    function ask(list, done) {
        if (!dialog || typeof dialog.showModal !== "function") { done(window.confirm(table.getAttribute("data-t-confirm-text") || "")); return; }

        document.getElementById("eg-perm-confirm-title").textContent = table.getAttribute("data-t-confirm-title") || "";
        document.getElementById("eg-perm-confirm-text").textContent = table.getAttribute("data-t-confirm-text") || "";

        var ul = document.getElementById("eg-perm-confirm-list");

        while (ul.firstChild) { ul.removeChild(ul.firstChild); }

        list.forEach(function (c) {
            var li = document.createElement("li");

            li.textContent = c.gt + ": " + c.an;
            ul.appendChild(li);
        });

        var yes = document.getElementById("eg-perm-confirm-yes");
        var no = document.getElementById("eg-perm-confirm-no");

        function close(v) {
            yes.removeEventListener("click", onYes);
            no.removeEventListener("click", onNo);
            dialog.removeEventListener("cancel", onNo);
            dialog.close();
            done(v);
        }

        function onYes() { close(true); }
        function onNo() { close(false); }

        yes.addEventListener("click", onYes);
        no.addEventListener("click", onNo);
        dialog.addEventListener("cancel", onNo);
        dialog.showModal();
        no.focus();
    }

    function post(list, confirm) {
        busy = true;
        refresh();

        var body = new URLSearchParams();

        body.set(table.getAttribute("data-token"), "1");
        body.set("changes", JSON.stringify(list.map(function (c) { return {g: c.g, a: c.a, v: c.v}; })));

        if (confirm) { body.set("confirm", "1"); }

        window.fetch(table.getAttribute("data-save-url"), {
            method: "POST",
            credentials: "same-origin",
            cache: "no-store",
            headers: {"Accept": "application/json", "X-Requested-With": "XMLHttpRequest"},
            body: body
        }).then(function (r) {
            return r.json().then(function (j) { return {status: r.status, json: j}; }, function () { return {status: r.status, json: null}; });
        }).then(function (res) {
            busy = false;

            if (res.status === 200 && res.json && res.json.ok === true && res.json.cells) {
                cells().forEach(function (b) {
                    var row = res.json.cells[b.getAttribute("data-g")];
                    var st = row ? row[b.getAttribute("data-a")] : null;

                    if (st) { b.setAttribute("data-orig", st); paint(b, st); }
                });
                statusEl.textContent = res.json.changed > 0 ? table.getAttribute("data-t-saved") : table.getAttribute("data-t-nochange");
            } else {
                statusEl.textContent = table.getAttribute("data-t-failed");
            }

            refresh();
        }).catch(function () {
            busy = false;
            statusEl.textContent = table.getAttribute("data-t-failed");
            refresh();
        });
    }

    saveBtn.addEventListener("click", function () {
        var list = changes();

        if (list.length === 0 || busy) { return; }

        var risky = needsConfirm(list);

        if (risky.length > 0) { ask(risky, function (ok) { if (ok) { post(list, true); } }); }
        else { post(list, false); }
    });

    refresh();
}(window, document));
