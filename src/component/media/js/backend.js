/**
 * @package   AkeebaEngage
 * @copyright Copyright (c)2020-2025 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 *
 * 0.9.2: no depende de akeeba.System (que Joomla no carga: daba «Cannot read properties of undefined (reading 'documentReady')» en la lista) y envia el
 * formulario con la tarea vacia, la que acepta la lista cerrada de tareas.
 */

"use strict";

if (typeof akeeba == "undefined")
{
    var akeeba = {};
}

if (typeof akeeba.Engage == "undefined")
{
    akeeba.Engage = {};
}

if (typeof akeeba.Engage.AdminComments == "undefined")
{
    akeeba.Engage.AdminComments = {};
}

akeeba.Engage.AdminComments.onReset = function (event)
{
    event.preventDefault();

    ["filter_search", "filter_enabled", "filter_itemtype", "filter_from", "filter_to"].forEach(function (id) {
        var el = document.getElementById(id);

        if (el)
        {
            el.value = "";
        }
    });

    var form = document.getElementById("adminForm");

    if (form)
    {
        form.submit();
    }

    return false;
};

(function ()
{
    var init = function ()
    {
        var button = document.getElementById("comEngageResetFilters");

        if (button)
        {
            button.addEventListener("click", akeeba.Engage.AdminComments.onReset);
        }
    };

    if (document.readyState === "loading")
    {
        document.addEventListener("DOMContentLoaded", init);
    }
    else
    {
        init();
    }
})();
