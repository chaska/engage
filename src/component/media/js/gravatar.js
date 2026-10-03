/**
 * @package   AkeebaEngage
 * @copyright Copyright (c)2020-2025 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

/**
 * Opt-in consent for Gravatar photos (Gravatar plugin, mode "ask").
 *
 * The server sends a generic local avatar in every img src and, in the data-engage-gravatar attribute, the Gravatar
 * URL. NOTHING is requested from gravatar.com until the visitor accepts. The decision is always made here, in the
 * browser (localStorage key engage_gravatar_consent = "1"; no cookies, nothing is sent to the server), so it works with
 * Joomla's page cache.
 *
 * API: window.AkeebaEngageGravatar = {grant(), revoke(), isGranted()}. Cookie managers can also dispatch on document
 * the event "engage:gravatar-consent" with detail: {granted: true|false}. See docs/GRAVATAR-CONSENTIMIENTO.md.
 */
(function (window, document) {
    "use strict";

    var STORAGE_KEY = "engage_gravatar_consent";
    var ATTR_URL = "data-engage-gravatar";
    var ATTR_NO_NOTICE = "data-engage-gravatar-notice";
    var ATTR_LOCAL = "data-engage-gravatar-local";

    // Strict whitelist: the only thing which may ever be assigned to an img src by this script.
    var ALLOWED_URL = /^https:\/\/www\.gravatar\.com\/avatar\/[0-9a-f]{32,64}(\?[A-Za-z0-9_=&%.+\-]*)?$/;

    var granted = false;
    var noticeEl = null;

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

    function getImages()
    {
        return document.querySelectorAll("img[" + ATTR_URL + "]");
    }

    function applyToImages()
    {
        var images = getImages();

        for (var i = 0; i < images.length; i++)
        {
            var img = images[i];

            if (granted)
            {
                var url = img.getAttribute(ATTR_URL) || "";

                if (!ALLOWED_URL.test(url))
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
        granted = true;
        writeStored(true);
        applyToImages();
        renderNotice(true);
        announce(true);
    }

    function revoke()
    {
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

    function init()
    {
        granted = readStored();

        if (getImages().length === 0)
        {
            return;
        }

        ensureNotice();

        if (granted)
        {
            applyToImages();
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
