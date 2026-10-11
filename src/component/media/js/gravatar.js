/**
 * @package   AkeebaEngage
 * @copyright Copyright (c)2020-2025 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

/**
 * Opt-in consent for Gravatar photos (Gravatar plugin, mode "ask").
 *
 * The server sends a generic local avatar in every img src and, in the data-engage-gravatar attribute, an OPAQUE
 * reference (`<comment id>.<size>.<token>`, 0.8.3: it contains nothing of the commenter's e-mail address; before, the
 * attribute held the Gravatar URL with the hash of the address, public to everybody). NOTHING is requested from
 * gravatar.com until the visitor accepts. The decision is always made here, in the browser (localStorage key
 * engage_gravatar_consent = "1"; no cookies), so it works with Joomla's page cache. Only AFTER the visitor accepts, this
 * script asks the component (task avatar.token, then avatar.resolve with the CSRF token) for the real Gravatar URL of the
 * comments on the page; the server answers only for comments the visitor can see.
 *
 * API: window.AkeebaEngageGravatar = {grant(), revoke(), isGranted()}. Cookie managers can also dispatch on document
 * the event "engage:gravatar-consent" with detail: {granted: true|false}. See docs/GRAVATAR-CONSENTIMIENTO.md.
 *
 * 0.6.19, consent source "jbcookies" (img attribute data-engage-gravatar-source="jbcookies"): the decision belongs to the
 * JBCookies module. It is read from its JS-readable cookie "jbcookies" on load and again when the module dispatches
 * "jbcookies:update". Consent is granted ONLY if status === "allow", or status === "custom" and the configured group
 * (data-engage-gravatar-group) is exactly 1 / true in preferences. Anything else (no cookie, deny, broken JSON, odd
 * values) is NOT consent. In this mode localStorage is neither read nor written, grant() only re-checks the cookie (it
 * can never grant against the module) and revoke() always works. Passive checks (focus, visibility, pageshow, click on
 * the module's "change my decision" link) can only REVOKE, never grant.
 */
(function (window, document) {
    "use strict";

    var STORAGE_KEY = "engage_gravatar_consent";
    var ATTR_URL = "data-engage-gravatar";
    var ATTR_NO_NOTICE = "data-engage-gravatar-notice";
    var ATTR_LOCAL = "data-engage-gravatar-local";
    var ATTR_SOURCE = "data-engage-gravatar-source";
    var ATTR_GROUP = "data-engage-gravatar-group";

    var JB_COOKIE = "jbcookies";
    var JB_MAX_LENGTH = 4096;
    var JB_GROUP = /^[a-z0-9_-]{1,64}$/;
    // "necessary" is always 1 (it proves nothing); the others exist on every JavaScript object.
    var JB_BAD_GROUPS = ["necessary", "__proto__", "constructor", "prototype"];

    // Strict whitelist: the only thing which may ever be assigned to an img src by this script.
    var ALLOWED_URL = /^https:\/\/www\.gravatar\.com\/avatar\/[0-9a-f]{32,64}(\?[A-Za-z0-9_=&%.+\-]*)?$/;

    // 0.8.3: the opaque reference printed in the page, and the endpoint (same site, relative) that trades it for the Gravatar URL
    var REF_FORMAT = /^[1-9][0-9]{0,17}\.[1-9][0-9]{0,3}\.[0-9a-f]{32}$/;
    var TOKEN_FORMAT = /^[0-9a-f]{32}$/;
    var MAX_REFS = 100;
    var resolved = Object.create(null);   // "id.size" -> Gravatar URL (already checked against ALLOWED_URL)
    var tried = Object.create(null);      // "id.size" -> true while asked or answered
    var pending = false;

    var granted = false;
    var noticeEl = null;
    var external = false;   // true: the decision comes from the JBCookies module
    var jbGroup = "";

    function text(key, fallback)
    {
        try
        {
            if (window.Joomla && window.Joomla.Text && typeof window.Joomla.Text._ === "function")
            {
                var value = window.Joomla.Text._(key);

                if (value)
                {
                    return value;
                }
            }
        }
        catch (e)
        {
        }

        return fallback;
    }

    function readStored()
    {
        try
        {
            return window.localStorage.getItem(STORAGE_KEY) === "1";
        }
        catch (e)
        {
            return false;
        }
    }

    function writeStored(on)
    {
        try
        {
            if (on)
            {
                window.localStorage.setItem(STORAGE_KEY, "1");
            }
            else
            {
                window.localStorage.removeItem(STORAGE_KEY);
            }
        }
        catch (e)
        {
            // Storage blocked: the choice only lasts until the page is reloaded.
        }
    }

    function isPlainObject(value)
    {
        return value !== null && typeof value === "object" && !Array.isArray(value);
    }

    function validGroup(value)
    {
        return typeof value === "string" && JB_GROUP.test(value) && JB_BAD_GROUPS.indexOf(value) === -1 ? value : "";
    }

    /**
     * Pure rule: does the (already URL-decoded) value of the jbcookies cookie grant consent for this group?
     * Only an explicit allow does; every doubt is "no".
     */
    function jbDecide(raw, group)
    {
        if (typeof raw !== "string" || raw === "" || raw.length > JB_MAX_LENGTH)
        {
            return false;
        }

        var status = "";
        var prefs = null;

        try
        {
            var parsed = JSON.parse(raw);

            if (isPlainObject(parsed) && Object.prototype.hasOwnProperty.call(parsed, "status") && typeof parsed.status === "string")
            {
                status = parsed.status;
                prefs = Object.prototype.hasOwnProperty.call(parsed, "preferences") ? parsed.preferences : null;
            }
        }
        catch (e)
        {
            status = "";
        }

        if (status === "" && (raw === "allow" || raw === "deny" || raw === "custom"))
        {
            // Legacy plain text value, accepted by the module.
            status = raw;
            prefs = null;
        }

        if (status === "allow")
        {
            return true;
        }

        group = validGroup(group);

        if (status !== "custom" || group === "" || !isPlainObject(prefs) || !Object.prototype.hasOwnProperty.call(prefs, group))
        {
            return false;
        }

        return prefs[group] === 1 || prefs[group] === true;
    }

    /** Values of every cookie called "jbcookies" (URL-decoded). A value which cannot be decoded is returned as "". */
    function jbCookieValues()
    {
        var values = [];
        var all;

        try
        {
            all = String(document.cookie || "");
        }
        catch (e)
        {
            return values;
        }

        var parts = all.split(";");

        for (var i = 0; i < parts.length; i++)
        {
            var part = parts[i];
            var eq = part.indexOf("=");

            if (eq === -1 || part.slice(0, eq).trim() !== JB_COOKIE)
            {
                continue;
            }

            var value = part.slice(eq + 1).trim();

            try
            {
                values.push(decodeURIComponent(value));
            }
            catch (e)
            {
                values.push("");
            }
        }

        return values;
    }

    /** The module's current decision. With no cookie, or two cookies of the same name where any does not grant: false. */
    function jbGranted()
    {
        var values = jbCookieValues();

        if (values.length === 0)
        {
            return false;
        }

        for (var i = 0; i < values.length; i++)
        {
            if (!jbDecide(values[i], jbGroup))
            {
                return false;
            }
        }

        return true;
    }

    function detectSource()
    {
        var images = getImages();

        external = false;
        jbGroup = "";

        // Any image without the explicit "jbcookies" marker keeps the whole page in the default (own) mode.
        if (images.length === 0)
        {
            return;
        }

        for (var i = 0; i < images.length; i++)
        {
            if (images[i].getAttribute(ATTR_SOURCE) !== "jbcookies")
            {
                return;
            }
        }

        external = true;
        jbGroup = validGroup(images[0].getAttribute(ATTR_GROUP) || "");
    }

    function getImages()
    {
        return document.querySelectorAll("img[" + ATTR_URL + "]");
    }

    function refKey(ref)
    {
        var parts = ref.split(".");

        return parts[0] + "." + parts[1];
    }

    /** The endpoint of the component: a path of this site (never another host). */
    function endpointUrl(task)
    {
        var base = "";

        try
        {
            base = window.Joomla && typeof window.Joomla.getOptions === "function" ? window.Joomla.getOptions("akeeba.Engage.Gravatar.endpoint") : "";
        }
        catch (e)
        {
            base = "";
        }

        if (typeof base !== "string" || base.charAt(0) !== "/" || base.charAt(1) === "/" || base.length > 500 || /[\s"'<>\\]/.test(base))
        {
            return "";
        }

        return base + (base.indexOf("?") === -1 ? "?" : "&") + "task=" + task;
    }

    /**
     * Asks the server for the Gravatar URL of the comments whose photo is still unknown. Only runs with consent. Every URL which comes back
     * goes through ALLOWED_URL before it is kept; anything else is ignored (the local avatar stays).
     */
    function resolvePending()
    {
        if (!granted || pending || typeof window.fetch !== "function")
        {
            return;
        }

        var images = getImages();
        var refs = [];
        var keys = [];

        for (var i = 0; i < images.length && refs.length < MAX_REFS; i++)
        {
            var ref = images[i].getAttribute(ATTR_URL) || "";

            if (!REF_FORMAT.test(ref))
            {
                continue;
            }

            var key = refKey(ref);

            if (resolved[key] !== undefined || tried[key] || keys.indexOf(key) !== -1)
            {
                continue;
            }

            refs.push(ref);
            keys.push(key);
        }

        var tokenUrl = endpointUrl("avatar.token");
        var resolveUrl = endpointUrl("avatar.resolve");

        if (refs.length === 0 || tokenUrl === "" || resolveUrl === "")
        {
            return;
        }

        pending = true;
        keys.forEach(function (key) {
            tried[key] = true;
        });

        var ok = false;

        window.fetch(tokenUrl, {credentials: "same-origin", cache: "no-store", headers: {"Accept": "application/json"}})
            .then(function (response) {
                return response.ok ? response.json() : null;
            })
            .then(function (data) {
                if (!isPlainObject(data) || typeof data.token !== "string" || !TOKEN_FORMAT.test(data.token))
                {
                    return null;
                }

                var body = new URLSearchParams();
                body.set("c", refs.join(","));
                body.set(data.token, "1");

                return window.fetch(resolveUrl, {
                    method: "POST",
                    credentials: "same-origin",
                    cache: "no-store",
                    headers: {"Accept": "application/json"},
                    body: body
                }).then(function (response) {
                    return response.ok ? response.json() : null;
                });
            })
            .then(function (data) {
                if (!isPlainObject(data) || data.ok !== true || !isPlainObject(data.urls))
                {
                    return;
                }

                ok = true;
                keys.forEach(function (key) {
                    var value = Object.prototype.hasOwnProperty.call(data.urls, key) ? data.urls[key] : null;

                    // A photo that the server does not give (null) stays the local avatar for good; anything unexpected is also "none"
                    resolved[key] = (typeof value === "string" && ALLOWED_URL.test(value)) ? value : "";
                });
            })
            .catch(function () {
                // Network or server error: the local avatars stay; a later consent change tries again
            })
            .then(function () {
                pending = false;

                if (!ok)
                {
                    keys.forEach(function (key) {
                        delete tried[key];
                    });

                    return;
                }

                if (granted)
                {
                    applyToImages();
                    resolvePending();
                }
            });
    }

    function applyToImages()
    {
        var images = getImages();

        for (var i = 0; i < images.length; i++)
        {
            var img = images[i];

            if (granted)
            {
                var ref = img.getAttribute(ATTR_URL) || "";
                var url = REF_FORMAT.test(ref) ? resolved[refKey(ref)] : "";

                if (typeof url !== "string" || !ALLOWED_URL.test(url))
                {
                    continue;
                }

                if (!img.hasAttribute(ATTR_LOCAL))
                {
                    img.setAttribute(ATTR_LOCAL, img.getAttribute("src") || "");
                }

                // Do not tell Gravatar which page the visitor is reading.
                img.setAttribute("referrerpolicy", "no-referrer");
                img.setAttribute("src", url);
            }
            else if (img.hasAttribute(ATTR_LOCAL))
            {
                img.setAttribute("src", img.getAttribute(ATTR_LOCAL));
                img.removeAttribute(ATTR_LOCAL);
            }
        }
    }

    function wantsNotice()
    {
        var images = getImages();

        for (var i = 0; i < images.length; i++)
        {
            if (images[i].getAttribute(ATTR_NO_NOTICE) !== "0")
            {
                return true;
            }
        }

        return false;
    }

    function findContainer()
    {
        var section = document.getElementById("akengage-comments-section");

        if (section)
        {
            return section;
        }

        var first = getImages()[0];

        return first ? (first.closest(".akengage-outer-container") || first.parentNode) : null;
    }

    function renderNotice(moveFocus)
    {
        if (!noticeEl)
        {
            return;
        }

        while (noticeEl.firstChild)
        {
            noticeEl.removeChild(noticeEl.firstChild);
        }

        var message = document.createElement("p");
        message.className = "mb-2";
        message.setAttribute("role", "status");

        var button = document.createElement("button");
        button.type = "button";

        if (granted)
        {
            message.textContent = text("COM_ENGAGE_GRAVATAR_STATUS_ON", "Gravatar photos are shown. Your choice is saved only in this browser.");
            button.className = "btn btn-link btn-sm p-0";
            button.textContent = text("COM_ENGAGE_GRAVATAR_BTN_REVOKE", "Stop showing Gravatar photos");
            button.addEventListener("click", function () {
                revoke();
            });
        }
        else
        {
            message.textContent = text(
                "COM_ENGAGE_GRAVATAR_NOTICE_TEXT",
                "The commenters' photos on this page can be shown from Gravatar, a third-party service. Loading them makes your browser contact gravatar.com, which receives your IP address. Until you accept, a generic avatar is shown."
            );
            button.className = "btn btn-primary btn-sm";
            button.textContent = text("COM_ENGAGE_GRAVATAR_BTN_ACCEPT", "Show Gravatar photos (this sends your IP to gravatar.com)");
            button.addEventListener("click", function () {
                grant();
            });
        }

        noticeEl.appendChild(message);
        noticeEl.appendChild(button);

        if (moveFocus)
        {
            // The button which was clicked has been replaced: keep keyboard and screen reader users where they were.
            button.focus();
        }
    }

    function ensureNotice()
    {
        if (noticeEl || !wantsNotice())
        {
            return;
        }

        var container = findContainer();

        if (!container)
        {
            return;
        }

        noticeEl = document.createElement("div");
        noticeEl.className = "akengage-gravatar-notice alert alert-info small";
        noticeEl.setAttribute("role", "region");
        noticeEl.setAttribute("aria-label", text("COM_ENGAGE_GRAVATAR_NOTICE_LABEL", "Gravatar photos"));

        container.insertBefore(noticeEl, container.firstChild);
        renderNotice(false);
    }

    function setExternalState(on)
    {
        var changed = granted !== on;

        granted = on;
        applyToImages();
        resolvePending();

        if (changed)
        {
            announce(on);
        }
    }

    // JBCookies: follow the module's decision (grants and revokes).
    function syncFromJbcookies()
    {
        setExternalState(jbGranted());
    }

    // JBCookies: passive checks (no event from the module) may only withdraw consent, never give it.
    function recheckJbcookies()
    {
        if (granted && !jbGranted())
        {
            setExternalState(false);
        }
    }

    function announce(on)
    {
        try
        {
            var evt = new CustomEvent("engage:gravatar-changed", {detail: {granted: on}});
            document.dispatchEvent(evt);
        }
        catch (e)
        {
        }
    }

    function grant()
    {
        if (external)
        {
            // Never against the module: this only re-reads its cookie.
            syncFromJbcookies();
            return;
        }

        granted = true;
        writeStored(true);
        applyToImages();
        resolvePending();
        renderNotice(true);
        announce(true);
    }

    function revoke()
    {
        if (external)
        {
            setExternalState(false);
            return;
        }

        granted = false;
        writeStored(false);
        applyToImages();
        renderNotice(true);
        announce(false);
    }

    function isGranted()
    {
        return granted === true;
    }

    window.AkeebaEngageGravatar = {
        grant: grant,
        revoke: revoke,
        isGranted: isGranted
    };

    // Hook for cookie / consent managers.
    document.addEventListener("engage:gravatar-consent", function (e) {
        var detail = e && e.detail;

        if (!detail || typeof detail.granted !== "boolean")
        {
            return;
        }

        if (detail.granted)
        {
            grant();
        }
        else
        {
            revoke();
        }
    });

    function initJbcookies()
    {
        granted = false;
        syncFromJbcookies();

        document.addEventListener("jbcookies:update", syncFromJbcookies);
        document.addEventListener("visibilitychange", function () {
            if (document.visibilityState !== "hidden")
            {
                recheckJbcookies();
            }
        });
        window.addEventListener("focus", recheckJbcookies);
        window.addEventListener("pageshow", recheckJbcookies);
        // The module's "change my decision" link erases the cookie WITHOUT dispatching any event.
        document.addEventListener("click", function (e) {
            var target = e && e.target;

            if (target && typeof target.closest === "function" && target.closest(".jb-cookie-decline"))
            {
                // The module's own handler has already erased the cookie when the click bubbles up to the document.
                setTimeout(recheckJbcookies, 0);
            }
        });
    }

    function init()
    {
        detectSource();

        if (external)
        {
            initJbcookies();
            return;
        }

        granted = readStored();

        if (getImages().length === 0)
        {
            return;
        }

        ensureNotice();

        if (granted)
        {
            applyToImages();
            resolvePending();
            renderNotice(false);
        }
    }

    if (document.readyState === "loading")
    {
        document.addEventListener("DOMContentLoaded", init);
    }
    else
    {
        init();
    }
})(window, document);
