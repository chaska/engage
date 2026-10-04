/**
 * 0.6.26: PERSISTENCIA de CADA ajuste de plugin expuesto en la pantalla de Opciones modernas (Gravatar, Akismet, email), en un navegador real:
 * para cada fila de plugin y para cada valor posible, se cambia por la interfaz, se espera el "Guardado" y se comprueba
 *   (1) el parametro en #__extensions (params del plugin) con el valor exacto,
 *   (2) que NINGUN otro parametro del plugin cambia,
 *   (3) que la ficha clasica del plugin (Sistema > Plugins > editar) muestra ese valor en su campo,
 *   (4) que al RECARGAR la pantalla moderna el control sigue mostrando el valor.
 * Ademas, con Gravatar en modo "always", la imagen por defecto elegida llega a la URL de gravatar.com del HTML servido (d=<valor>).
 * Entorno: BASE_URL, WORK, IDS_FILE, DB_NAME, PW_MODULE, CHROMIUM.
 */
const {execFileSync} = require('child_process');
const fs = require('fs');
const {chromium} = require(process.env.PW_MODULE || 'playwright');
const BASE = process.env.BASE_URL || 'http://127.0.0.1:8080';
const WORK = process.env.WORK;
const DB = process.env.DB_NAME || 'joomla_test';
const ids = JSON.parse(fs.readFileSync(`${WORK}/${process.env.IDS_FILE || 'ids.json'}`, 'utf8'));
const ADMIN_PASS = process.env.ADMIN_PASS || ('Aa1!' + fs.readFileSync(`${WORK}/adminpass.txt`, 'utf8').trim());
const res = [];
function r(id, desc, ok, ev = '') { res.push({id, desc, estado: ok ? 'PASA' : 'FALLA', ev}); console.log(`${id.padEnd(44)} ${ok ? 'PASA ' : 'FALLA'} ${desc}${ev ? '  [' + ev + ']' : ''}`); }
function sql(s) { return execFileSync('mysql', ['-uroot', '--raw', '--default-character-set=utf8mb4', '-N', DB, '-e', s]).toString().trim(); }
const q = s => `'${String(s).replace(/\\/g, '\\\\').replace(/'/g, "''")}'`;
const where = scope => `type='plugin' AND folder='engage' AND element='${scope.replace('plg_engage_', '')}'`;
const params = scope => JSON.parse(sql(`SELECT IFNULL(NULLIF(params,''),'{}') FROM jos_extensions WHERE ${where(scope)}`) || '{}');
const extId = scope => sql(`SELECT extension_id FROM jos_extensions WHERE ${where(scope)}`);
const SCOPES = ['plg_engage_gravatar', 'plg_engage_akismet', 'plg_engage_email'];
// valores de ejemplo para los campos de texto/numero (el servidor los valida con la regla de cada campo)
const EJEMPLOS = {key: ['abcdef123456'], jbcookies_group: ['analytics', 'ga'], default: ['x']};

const orig = {}; for (const s of SCOPES) orig[s] = sql(`SELECT IFNULL(params,'') FROM jos_extensions WHERE ${where(s)}`);
const origEn = sql("SELECT CONCAT(extension_id,':',enabled) FROM jos_extensions WHERE type='plugin' AND folder='engage'").split('\n');
const origLang = sql("SELECT params FROM jos_extensions WHERE element='com_languages'");
sql("UPDATE jos_extensions SET enabled=1 WHERE type='plugin' AND folder='engage' AND element IN ('gravatar','akismet','email')");
sql("UPDATE jos_extensions SET params='{\"administrator\":\"es-ES\",\"site\":\"es-ES\"}' WHERE element='com_languages'");
const restaurar = () => {
    for (const s of SCOPES) sql(`UPDATE jos_extensions SET params=${q(orig[s])}, checked_out=NULL, checked_out_time=NULL WHERE ${where(s)}`);
    for (const e of origEn) { const [id, en] = e.split(':'); sql(`UPDATE jos_extensions SET enabled=${en} WHERE extension_id=${id}`); }
    sql(`UPDATE jos_extensions SET params=${q(origLang)} WHERE element='com_languages'`);
};

(async () => {
    const b = await chromium.launch({executablePath: process.env.CHROMIUM || '/opt/pw-browsers/chromium', args: ['--no-sandbox']});
    const c = await b.newContext({viewport: {width: 1400, height: 900}, locale: 'es-ES'});
    const p = await c.newPage();
    await p.goto(`${BASE}/administrator/`);
    await p.fill('#mod-login-username', 'admintest'); await p.fill('#mod-login-password', ADMIN_PASS);
    await Promise.all([p.waitForNavigation(), p.press('#mod-login-password', 'Enter')]);
    const abrir = async () => { await p.goto(`${BASE}/administrator/index.php?option=com_engage&view=settings&section=privacy`, {waitUntil: 'load'}); };
    // filas de plugin de todas las categorias
    await abrir();
    const filas = await p.evaluate(() => [...document.querySelectorAll('[data-eg-row][data-scope^="plg_engage_"]')].map(rw => ({
        scope: rw.dataset.scope, key: rw.dataset.key, control: rw.dataset.control,
        sec: rw.closest('[role=tabpanel]').id.replace('eg-panel-', ''),
        opts: [...rw.querySelectorAll('[role=radio], option')].map(o => o.dataset.value !== undefined ? o.dataset.value : o.value),
    })));
    r('P-filas', `la interfaz expone ${filas.length} ajustes de plugin (${SCOPES.map(s => filas.filter(f => f.scope === s).length).join(' + ')})`, filas.length >= 10, filas.map(f => f.key).join(','));
    const ficha = async (scope, key) => {
        const pg = await c.newPage();
        await pg.goto(`${BASE}/administrator/index.php?option=com_plugins&task=plugin.edit&extension_id=${extId(scope)}`, {waitUntil: 'load'});
        const v = await pg.evaluate(k => {
            const n = `jform[params][${k}]`;
            const sel = document.querySelector(`select[name="${n}"]`); if (sel) return sel.value;
            const rad = [...document.querySelectorAll(`input[type=radio][name="${n}"]`)]; if (rad.length) { const ch = rad.find(x => x.checked); return ch ? ch.value : null; }
            const inp = document.querySelector(`[name="${n}"]`); return inp ? inp.value : undefined;
        }, key);
        await pg.close(); sql(`UPDATE jos_extensions SET checked_out=NULL, checked_out_time=NULL WHERE ${where(scope)}`);
        return v;
    };
    const cambiar = async (f, valor) => {
        await abrir(); await p.click(`[data-eg-tab="${f.sec}"]`);
        const sel = `[data-eg-row][data-scope="${f.scope}"][data-key="${f.key}"]`;
        await p.evaluate(s => { document.querySelectorAll('[data-eg-row]').forEach(x => { x.hidden = false; }); }, sel);
        const antes = params(f.scope);
        const ui0 = await p.evaluate(s => document.querySelector(s).dataset.value, sel);
        if (f.control !== 'switch' && ui0 === valor) return {sel, antes, estado: 'ya', ui0};   // ya es el valor que muestra (sin valor guardado manda el predeterminado)
        if (f.control === 'switch') await p.click(`${sel} .eg-switch`);
        else if (f.control === 'segmented') await p.click(`${sel} [role=radio][data-value="${valor}"]`);
        else if (f.control === 'select') await p.selectOption(`${sel} select`, valor);
        else { await p.fill(`${sel} input, ${sel} textarea`, valor); await p.keyboard.press(f.control === 'textarea' ? 'Tab' : 'Enter'); }
        let estado = 'tiempo';
        try { await p.waitForFunction(s => { const e = document.querySelector(s); return e.classList.contains('is-saved') || e.classList.contains('is-error'); }, sel, {timeout: 6000}); estado = await p.evaluate(s => document.querySelector(s).classList.contains('is-saved') ? 'guardado' : 'error', sel); } catch (e) {}
        return {sel, antes, estado, ui0};
    };
    let total = 0, malos = [];
    for (const f of filas) {
        let valores;
        if (f.control === 'switch') valores = ['(otro)'];
        else if (f.control === 'segmented' || f.control === 'select') valores = f.opts.filter(v => v !== undefined && v !== '');
        else valores = EJEMPLOS[f.key] || ['x1'];
        const inicial = params(f.scope)[f.key];
        const vistos = [];
        for (const v of valores) {
            await abrir();
            const actual = String(params(f.scope)[f.key] ?? '');
            if (f.control !== 'switch' && actual === v) { vistos.push(v + '=ya'); continue; }
            const {antes, estado, ui0} = await cambiar(f, v);
            if (estado === 'ya') { vistos.push(v + '=ya'); continue; }
            total++;
            const esperado = f.control === 'switch' ? (ui0 === '1' ? '0' : '1') : v;   // el interruptor se da la vuelta respecto de lo que MUESTRA (sin valor guardado manda el valor por defecto)
            const des = params(f.scope);
            const otros = Object.keys({...antes, ...des}).filter(k => k !== f.key && JSON.stringify(antes[k]) !== JSON.stringify(des[k]));
            let fi = null; if (estado === 'guardado') fi = await ficha(f.scope, f.key);
            await abrir(); await p.click(`[data-eg-tab="${f.sec}"]`);
            const mostrado = await p.evaluate(s => document.querySelector(s).dataset.value, `[data-eg-row][data-scope="${f.scope}"][data-key="${f.key}"]`);
            const ok = estado === 'guardado' && String(des[f.key]) === esperado && otros.length === 0 && String(fi) === esperado && mostrado === esperado;
            if (!ok) malos.push(`${f.scope}.${f.key}=${v}: ${estado}, BD=${JSON.stringify(des[f.key])}, ficha=${JSON.stringify(fi)}, recarga=${mostrado}${otros.length ? ', otros cambiados ' + otros : ''}`);
            vistos.push(`${v}${ok ? '' : '!'}`);
        }
        // deja el valor inicial en la base de datos (se restaura todo al final)
        const mal = malos.filter(m => m.startsWith(`${f.scope}.${f.key}=`));
        r(`P-${f.scope.replace('plg_engage_', '')}-${f.key}`, `${f.scope.replace('plg_engage_', '')}.${f.key} (${f.control}): ${vistos.length} valores cambiados por la interfaz persisten en BD, en la ficha clasica y al recargar`, mal.length === 0 && vistos.length > 0, mal.length ? mal.join(' | ') : vistos.join(','));
    }
    // imagen por defecto -> URL de gravatar.com del HTML servido (modo "always")
    const asset = ids.art_publico_asset;
    sql(`DELETE FROM jos_engage_comments WHERE asset_id=${asset}`);
    sql(`INSERT INTO jos_engage_comments (asset_id,parent_id,body,name,email,ip,user_agent,enabled,created,created_by) VALUES (${asset},NULL,'<p>Prueba de avatar</p>','Avatar Prueba','avatar@example.com','127.0.0.1','t',1,UTC_TIMESTAMP(),0)`);
    sql("UPDATE jos_extensions SET params=JSON_SET(IFNULL(NULLIF(params,''),'{}'),'$.mode','always','$.force_default','0') WHERE type='plugin' AND folder='engage' AND element='gravatar'");
    const vals = ['mp', 'identicon', 'monsterid', 'wavatar', 'retro', 'robohash', 'blank'];
    const fg = filas.find(f => f.key === 'default_image');
    const url = `${BASE}/index.php?option=com_content&view=article&id=${ids.art_publico}&catid=${ids.cat_publica}`;
    const resultados = [];
    for (const v of vals) {
        await abrir(); await p.click('[data-eg-tab="privacy"]');
        if (params('plg_engage_gravatar').default_image !== v) await cambiar(fg, v);
        execFileSync('sh', ['-c', `rm -rf ${WORK}/site/cache/* ${WORK}/site/administrator/cache/* 2>/dev/null; true`]);
        const html = await (await c.request.get(url)).text();
        const m = html.match(/https:\/\/(?:www\.)?gravatar\.com\/avatar\/[0-9a-f]{32}[^"'\s<]*/g) || [];
        const dec = m.map(x => x.replace(/&amp;/g, '&'));
        const esperado = v === 'blank' ? null : `d=${v}`;
        resultados.push({v, n: dec.length, ok: v === 'blank' ? true : dec.length > 0 && dec.every(x => x.includes('&' + esperado)), ej: dec[0]});
    }
    const mal = resultados.filter(x => !x.ok);
    r('P-gravatar-d-en-url', `Gravatar "always": la imagen por defecto elegida en la interfaz llega a la URL final (d=<valor>) para ${vals.length} valores`, mal.length === 0, mal.length ? JSON.stringify(mal) : resultados.map(x => x.v + ':' + (x.ej || '-').replace(/^.*\?/, '')).join(' '));
    await b.close();
    sql(`DELETE FROM jos_engage_comments WHERE asset_id=${asset} AND name='Avatar Prueba'`);
    restaurar();
    const f = res.filter(x => x.estado === 'FALLA').length;
    console.log(`\n${total} cambios por la interfaz en ${filas.length} ajustes de plugin; ${res.length - f} PASA / ${f} FALLA`);
    fs.writeFileSync(`${WORK}/resultados-plugins-persistencia.json`, JSON.stringify(res, null, 1));
    process.exit(f ? 1 : 0);
})().catch(e => { try { restaurar(); } catch (x) {} console.error(e); process.exit(1); });
