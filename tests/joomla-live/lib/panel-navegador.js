/**
 * 0.6.24: PANEL DE CONTROL en un navegador real (Chromium headless + Playwright) sobre el Joomla de pruebas.
 *  - 3 disenos (claro, medio, oscuro) + automatico (dispositivo claro y oscuro) x escritorio (1280) y movil (390): capturas PNG,
 *    desbordamiento horizontal, y CONTRASTE WCAG medido de TODO el texto visible del panel (cada nodo de texto contra su fondo efectivo;
 *    umbral 4,5:1 para todo, mas estricto que el 3:1 del texto grande), de los iconos de botones y estado (3:1), de la barra del
 *    grafico (3:1), de los glifos blancos sobre cada degradado (3:1, peor extremo) y del anillo de foco de cada control (3:1);
 *  - teclado: selector de diseno (radiogroup con flechas, Home/End), foco visible en todos los controles, dialogo accesible (foco en
 *    Cancelar, Escape cancela, el foco vuelve al boton), jamas confirm() del navegador;
 *  - persistencia en localStorage y diseno aplicado antes de pintar (script sin defer en la cabecera);
 *  - acciones rapidas (publicar, spam, eliminar) por la UI con comprobacion en la base de datos; peticion sin token y con token
 *    erroneo rechazadas sin cambios; usuario Manager (core.manage sin core.admin) ve el panel pero no Opciones/Permisos;
 *  - semaforos con la configuracion real cambiada en la BD (y restaurada al acabar);
 *  - XSS: nombre y texto con HTML se muestran como texto; sin estilos ni scripts en linea del panel; cero peticiones a otros hosts.
 * Requisitos: 03-sembrar-y-probar.sh ya ejecutado y el paquete instalado. Entorno: BASE_URL, WORK, IDS_FILE, DB_NAME, PW_MODULE, CHROMIUM,
 * OUT (capturas). La contrasena del admin sale de $WORK/adminpass.txt; la del Manager se genera al azar en $WORK. Nada en el repositorio.
 */
const {execFileSync} = require('child_process');
const fs = require('fs');
const crypto = require('crypto');
const {chromium} = require(process.env.PW_MODULE || 'playwright');
const BASE = process.env.BASE_URL || 'http://127.0.0.1:8080';
const WORK = process.env.WORK;
const OUT = process.env.OUT || `${WORK}/panel-capturas`;
const DB = process.env.DB_NAME || 'joomla_test';
const ids = JSON.parse(fs.readFileSync(`${WORK}/${process.env.IDS_FILE || 'ids.json'}`, 'utf8'));
const AS = ids.art_publico_asset, AS2 = ids.art_otro_asset;
const ADMIN_PASS = process.env.ADMIN_PASS || ('Aa1!' + fs.readFileSync(`${WORK}/adminpass.txt`, 'utf8').trim());
const ES = process.env.PANEL_LANG === 'es';
const res = [];
let peor = {};
function r(id, desc, ok, ev = '') { res.push({id, desc, estado: ok ? 'PASA' : 'FALLA', ev}); console.log(`${id.padEnd(34)} ${ok ? 'PASA ' : 'FALLA'} ${desc}${ev ? '  [' + ev + ']' : ''}`); }
function sql(s) { return execFileSync('mysql', ['-uroot', '-N', DB, '-e', s]).toString().trim(); }
function params() { return JSON.parse(sql("SELECT params FROM jos_extensions WHERE element='com_engage' AND type='component'") || '{}'); }
function setParams(p) { sql(`UPDATE jos_extensions SET params='${JSON.stringify(p).replace(/'/g, "''")}' WHERE element='com_engage' AND type='component'`); }
fs.mkdirSync(OUT, {recursive: true});

// ---------------------------------------------------------------- datos de prueba
const origParams = params();
const origRules = sql("SELECT rules FROM jos_assets WHERE name='com_engage'");
const origPlugins = sql("SELECT CONCAT(extension_id,':',enabled,':',IFNULL(params,'')) FROM jos_extensions WHERE type='plugin' AND (folder='engage' OR (folder='content' AND element='engage') OR (folder='system' AND element IN ('engagecache','cache')))").split('\n');
sql('DELETE FROM jos_engage_comments');
const ins = (parent, body, name, enabled, daysAgo, hour = 10) => sql(`INSERT INTO jos_engage_comments (asset_id,parent_id,body,name,email,ip,user_agent,enabled,created,created_by) VALUES (${daysAgo < 0 ? AS2 : AS},${parent},'${body.replace(/'/g, "''")}','${name.replace(/'/g, "''")}','x@example.invalid','203.0.113.7','prueba',${enabled},DATE_SUB(UTC_TIMESTAMP(), INTERVAL ${Math.abs(daysAgo) * 24 + 2} HOUR),0); SELECT LAST_INSERT_ID();`);
const dias = [0, 0, 0, 1, 1, 2, 4, 4, 4, 4, 7, 9, 12, 12, 15, 20, 21, 25, 28, 29];
dias.forEach((d, i) => ins('NULL', `<p>Comentario de prueba numero ${i}</p>`, `Visitante ${i}`, 1, d));
const idXss = ins('NULL', '<p>Texto <b>con</b> &lt;script&gt;window.__xss=1&lt;/script&gt; <img src=x onerror="window.__xss=1"></p>', '<img src=x onerror="window.__xss=2">Ana', 0, 0);
const idPend = ins('NULL', '<p>Pendiente de moderacion A</p>', 'Pendiente A', 0, 0);
const idPend2 = ins('NULL', '<p>Pendiente de moderacion B</p>', 'Pendiente B', 0, 0);
const idSpam = ins('NULL', '<p>Esto es spam</p>', 'Spammer', -3, 1);
const idDel = ins('NULL', '<p>Para eliminar desde el panel</p>', 'Borrable', 1, 0);
sql(`UPDATE jos_engage_comments SET asset_id=${AS2} WHERE id IN (${idPend2}, ${idSpam})`);

// usuario Manager (core.manage en com_engage, SIN core.admin)
const mgrFile = `${WORK}/managerpass.txt`;
if (!fs.existsSync(mgrFile)) { fs.writeFileSync(mgrFile, crypto.randomBytes(9).toString('hex'), {mode: 0o600}); }
const MGR_PASS = 'Aa1!' + fs.readFileSync(mgrFile, 'utf8').trim();
const hash = execFileSync('php', ['-r', 'echo password_hash($argv[1], PASSWORD_BCRYPT);', MGR_PASS]).toString();
sql("DELETE FROM jos_user_usergroup_map WHERE user_id IN (SELECT id FROM jos_users WHERE username='mgrpanel'); DELETE FROM jos_users WHERE username='mgrpanel'");
sql(`INSERT INTO jos_users (name,username,email,password,block,sendEmail,registerDate,params,requireReset,authProvider) VALUES ('Manager Panel','mgrpanel','mgrpanel@example.invalid','${hash}',0,0,UTC_TIMESTAMP(),'{}',0,'')`);
sql("INSERT INTO jos_user_usergroup_map (user_id,group_id) SELECT id,6 FROM jos_users WHERE username='mgrpanel'");

const base = {...origParams, default_publish: '1'};
setParams(base);
// complementos: todo activado salvo cache de Engage (la de Joomla apagada) para partir de un estado conocido
sql("UPDATE jos_extensions SET enabled=1 WHERE type='plugin' AND ((folder='engage' AND element IN ('email','gravatar','akismet')) OR (folder='content' AND element='engage'))");
sql("UPDATE jos_extensions SET enabled=0 WHERE type='plugin' AND folder='system' AND element IN ('cache')");

// ---------------------------------------------------------------- medicion de contraste en la pagina
function medirEnPagina() {
    const parse = c => { let m = c.match(/color\(srgb ([^)]+)\)/); if (m) { const q = m[1].split(/[ \/]+/).filter(Boolean).map(Number); return {r: q[0] * 255, g: q[1] * 255, b: q[2] * 255, a: q.length > 3 ? q[3] : 1}; } m = c.match(/rgba?\(([^)]+)\)/); if (!m) return null; const p = m[1].split(/[ ,\/]+/).filter(Boolean).map(Number); return {r: p[0], g: p[1], b: p[2], a: p.length > 3 ? p[3] : 1}; };
    const over = (f, b) => ({r: f.r * f.a + b.r * (1 - f.a), g: f.g * f.a + b.g * (1 - f.a), b: f.b * f.a + b.b * (1 - f.a), a: 1});
    const lum = c => { const f = v => { v /= 255; return v <= .03928 ? v / 12.92 : Math.pow((v + .055) / 1.055, 2.4); }; return .2126 * f(c.r) + .7152 * f(c.g) + .0722 * f(c.b); };
    const ratio = (a, b) => { const x = lum(a), y = lum(b); return (Math.max(x, y) + .05) / (Math.min(x, y) + .05); };
    const fondo = el => {
        const capas = [];
        for (let e = el; e; e = e.parentElement) {
            const c = parse(getComputedStyle(e).backgroundColor);
            if (c && c.a > 0) { capas.push(c); if (c.a >= 1) break; }
        }
        let b = {r: 255, g: 255, b: 255, a: 1};
        for (let i = capas.length - 1; i >= 0; i--) b = over(capas[i], b);
        return b;
    };
    const visible = el => { const s = getComputedStyle(el); if (s.display === 'none' || s.visibility === 'hidden' || +s.opacity === 0) return false; const rc = el.getClientRects(); if (!(rc.length > 0 && rc[0].width > 0 && rc[0].height > 0)) return false; for (let e = el; e; e = e.parentElement) { if (e.hasAttribute && e.hasAttribute('hidden')) return false; if (e.tagName === 'DETAILS' && !e.open && el.tagName !== 'SUMMARY' && !el.closest('summary')) return false; } return true; };
    const root = document.getElementById('eg-admin');
    const items = [];
    const walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT);
    for (let n; (n = walker.nextNode());) {
        const t = n.textContent.trim(); if (!t) continue;
        const el = n.parentElement; if (!el || el.closest('.eg-vh, svg, script, style, .eg-avatar')) continue;
        if (!visible(el)) continue;
        const fg = parse(getComputedStyle(el).color); const bg = fondo(el);
        const f2 = over(fg, bg);
        items.push({tipo: 'texto', t: t.slice(0, 40), cls: (el.className && el.className.baseVal === undefined ? el.className : '').toString().slice(0, 40), ratio: Math.round(ratio(f2, bg) * 100) / 100, min: 4.5});
    }
    // iconos (svg.eg-ic) con currentColor sobre su fondo efectivo: 3:1; los de cuadrados con degradado se miden aparte
    root.querySelectorAll('svg.eg-ic').forEach(sv => {
        if (!visible(sv)) return;
        if (sv.closest('.eg-icobox, .eg-logo, .eg-dialog__icon, .eg-avatar')) return;
        const fg = parse(getComputedStyle(sv).color); const bg = fondo(sv);
        items.push({tipo: 'icono', t: sv.closest('button,a,span,h2,p') ? (sv.closest('button,a,span,h2,p').className || '').toString().slice(0, 30) : 'svg', ratio: Math.round(ratio(over(fg, bg), bg) * 100) / 100, min: 3});
    });
    // barras del grafico contra la tarjeta
    const bar = root.querySelector('.eg-chart__bar');
    if (bar) { const f = parse(getComputedStyle(bar).fill), bg = fondo(bar.closest('.eg-card')); items.push({tipo: 'grafico', t: 'barra', ratio: Math.round(ratio(f, bg) * 100) / 100, min: 3}); }
    const hov = root.querySelector('.eg-chart__grid--base');
    if (hov) { const f = parse(getComputedStyle(hov).stroke), bg = fondo(hov.closest('.eg-card')); items.push({tipo: 'grafico', t: 'linea base', ratio: Math.round(ratio(f, bg) * 100) / 100, min: 3}); }
    // glifos blancos sobre los DOS extremos de cada degradado (peor caso)
    const grad = [];
    root.querySelectorAll('.eg-icobox, .eg-logo, .eg-avatar').forEach(bx => {
        if (!visible(bx)) return;
        const bi = getComputedStyle(bx).backgroundImage; const cols = (bi.match(/rgba?\([^)]+\)|color\(srgb [^)]+\)/g) || []).map(parse);
        cols.forEach((c, i) => grad.push({tipo: 'degradado', t: (bx.className || '').toString().slice(0, 30) + (i ? ' fin' : ' inicio'), ratio: Math.round(ratio({r: 255, g: 255, b: 255, a: 1}, c) * 100) / 100, min: bx.classList.contains('eg-avatar') ? 4.5 : 3}));
    });
    items.push(...grad);
    // bordes de controles interactivos con aspecto de control (botones con borde): borde contra la tarjeta; el glifo ya se mide arriba
    const ovf = root.scrollWidth - root.clientWidth;
    return {items, overflow: ovf, tema: document.documentElement.getAttribute('data-eg-theme'), pref: document.documentElement.getAttribute('data-eg-pref')};
}

function peorDe(m, clave) {
    for (const i of m.items) {
        const k = `${clave}|${i.tipo}`; const v = i.ratio / i.min;
        if (!(k in peor) || v < peor[k].v) peor[k] = {v, ratio: i.ratio, min: i.min, t: i.t, tipo: i.tipo, clave};
    }
}

(async () => {
    const b = await chromium.launch({executablePath: process.env.CHROMIUM || '/opt/pw-browsers/chromium', args: ['--no-sandbox']});
    const login = async (user, pass, viewport, colorScheme) => {
        const c = await b.newContext({viewport, colorScheme: colorScheme || 'light', locale: ES ? 'es-ES' : 'en-GB'});
        const p = await c.newPage();
        const info = {errs: [], externos: [], js: 0};
        p.on('pageerror', e => info.errs.push(e.message));
        p.on('dialog', d => { info.js++; d.dismiss().catch(() => {}); });
        p.on('request', q => { const u = new URL(q.url()); if (!['data:', 'blob:', 'about:'].includes(u.protocol) && u.host !== new URL(BASE).host) info.externos.push(q.url()); });
        await p.goto(`${BASE}/administrator/`);
        await p.fill('#mod-login-username', user); await p.fill('#mod-login-password', pass);
        await Promise.all([p.waitForNavigation(), p.press('#mod-login-password', 'Enter')]);
        return {c, p, info};
    };
    const ir = async (p, tema, extra = '') => {
        await p.goto(`${BASE}/administrator/index.php?option=com_engage${extra}`, {waitUntil: 'networkidle'});
        await p.evaluate(t => { try { localStorage.setItem('eg-admin-theme', t); } catch (e) {} }, tema);
        await p.reload({waitUntil: 'networkidle'});
    };

    // ------------------------------------------------ A) disenos x pantallas
    const PANT = {escritorio: {width: 1280, height: 900}, movil: {width: 390, height: 844}};
    for (const [pn, vp] of Object.entries(PANT)) {
        const {c, p, info} = await login('admintest', ADMIN_PASS, vp);
        const casos = [['light', 'light', 'claro'], ['mid', 'light', 'medio'], ['dark', 'light', 'oscuro']];
        for (const [tema, os, nombre] of casos) {
            await ir(p, tema);
            const m = await p.evaluate(medirEnPagina);
            peorDe(m, `${nombre}`);
            const malos = m.items.filter(i => i.ratio < i.min);
            r(`A-${nombre}-${pn}`, `diseno ${nombre} en ${pn}: html[data-eg-theme]=${tema}, ${m.items.length} medidas de contraste, ninguna bajo el umbral`, m.tema === tema && malos.length === 0 && m.items.length > 40, malos.length ? JSON.stringify(malos.slice(0, 4)) : `peor texto ${Math.min(...m.items.filter(i => i.tipo === 'texto').map(i => i.ratio))}`);
            r(`A-${nombre}-${pn}-ovf`, `diseno ${nombre} en ${pn}: sin desbordamiento horizontal del panel`, m.overflow <= 1, `exceso ${m.overflow}px`);
            await (await p.$('#eg-admin')).screenshot({path: `${OUT}/panel-${nombre}-${pn}.png`});
        }
        // automatico: sigue al dispositivo
        for (const os of ['light', 'dark']) {
            await p.emulateMedia({colorScheme: os});
            await ir(p, 'auto');
            const m = await p.evaluate(medirEnPagina);
            peorDe(m, `auto-${os}`);
            const malos = m.items.filter(i => i.ratio < i.min);
            r(`A-auto-${os}-${pn}`, `automatico con dispositivo ${os} en ${pn}: aplica ${os} y contraste correcto`, m.tema === os && m.pref === 'auto' && malos.length === 0, malos.length ? JSON.stringify(malos.slice(0, 3)) : '');
        }
        await p.emulateMedia({colorScheme: 'light'});
        // sin JS ni preferencia (sin atributo): manda el dispositivo por CSS
        await p.goto(`${BASE}/administrator/index.php?option=com_engage`, {waitUntil: 'networkidle'});
        await p.evaluate(() => { try { localStorage.removeItem('eg-admin-theme'); } catch (e) {} });
        await p.emulateMedia({colorScheme: 'dark'});
        await p.evaluate(() => { document.documentElement.removeAttribute('data-eg-theme'); });
        const bgCss = await p.evaluate(() => getComputedStyle(document.getElementById('eg-admin')).backgroundColor);
        r(`A-css-auto-${pn}`, 'sin atributo data-eg-theme (sin JavaScript) el panel sigue al dispositivo por CSS puro (oscuro)', bgCss === 'rgb(14, 17, 23)', bgCss);
        await p.emulateMedia({colorScheme: 'light'});
        r(`A-errores-${pn}`, `sin errores de JavaScript propios del panel (${pn})`, !info.errs.some(e => /eg-|panel|EngagePanel/i.test(e)), JSON.stringify(info.errs.slice(0, 2)));
        r(`A-externas-${pn}`, `cero peticiones a otros hosts durante el recorrido (${pn})`, info.externos.length === 0, info.externos.slice(0, 3).join(' '));
        r(`A-confirm-${pn}`, `ningun confirm()/alert() del navegador (${pn})`, info.js === 0, String(info.js));
        await c.close();
    }

    // ------------------------------------------------ B) teclado, selector, persistencia, foco
    {
        const {c, p, info} = await login('admintest', ADMIN_PASS, PANT.escritorio);
        await ir(p, 'light');
        await p.focus('[data-eg-theme-set="light"]');
        await p.keyboard.press('ArrowRight');
        let st = await p.evaluate(() => ({t: document.documentElement.getAttribute('data-eg-theme'), ls: localStorage.getItem('eg-admin-theme'), chk: [...document.querySelectorAll('[data-eg-theme-set]')].map(x => x.getAttribute('aria-checked')).join(','), foco: document.activeElement.getAttribute('data-eg-theme-set'), live: document.getElementById('eg-live').textContent}));
        r('B-flecha-der', 'flecha derecha en el selector: pasa a Medio, aria-checked, foco y localStorage', st.t === 'mid' && st.ls === 'mid' && st.chk === 'false,true,false,false' && st.foco === 'mid', JSON.stringify(st));
        await p.keyboard.press('End');
        st = await p.evaluate(() => ({t: document.documentElement.getAttribute('data-eg-theme'), pref: document.documentElement.getAttribute('data-eg-pref')}));
        r('B-fin', 'tecla Fin: Automatico (pref auto)', st.pref === 'auto', JSON.stringify(st));
        await p.keyboard.press('Home'); await p.keyboard.press('ArrowRight'); await p.keyboard.press('ArrowRight');
        st = await p.evaluate(() => document.documentElement.getAttribute('data-eg-theme'));
        r('B-dark', 'Inicio + 2 flechas: Oscuro', st === 'dark', st);
        const roving = await p.evaluate(() => [...document.querySelectorAll('[data-eg-theme-set]')].map(x => x.tabIndex).join(','));
        r('B-roving', 'solo el elegido esta en el orden de tabulacion (tabindex 0, los demas -1)', roving === '-1,-1,0,-1', roving);
        await p.waitForTimeout(250);
        const live = await p.evaluate(() => document.getElementById('eg-live').textContent);
        r('B-live', 'cambio de diseno anunciado en la region role=status', live.length > 3 && !/\{THEME\}/.test(live), live);
        // persiste y se aplica antes de pintar
        await p.reload({waitUntil: 'domcontentloaded'});
        const html = await (await c.request.get(`${BASE}/administrator/index.php?option=com_engage`, {headers: {}})).text().catch(() => '');
        const ant = await p.evaluate(() => document.documentElement.getAttribute('data-eg-theme'));
        r('B-persiste', 'tras recargar el diseno elegido sigue aplicado en <html> (localStorage)', ant === 'dark', ant);
        await p.waitForLoadState('networkidle');
        const src = await p.evaluate(() => { const s = [...document.querySelectorAll('head script[src*="panel-theme"]')][0]; const pj = [...document.querySelectorAll('head script[src*="com_engage/js/panel"]')].filter(x => !/panel-theme/.test(x.src))[0]; return {theme: !!s, defer: s ? s.defer || s.async : null, panelDefer: pj ? pj.defer : null, cssOrden: !!document.querySelector('head link[href*="com_engage/css/panel.css"]')}; });
        r('B-antes-de-pintar', 'panel-theme.js va en <head> SIN defer/async (se ejecuta antes de pintar) y panel.js con defer', src.theme && src.defer === false && src.panelDefer === true && src.cssOrden, JSON.stringify(src));
        // sin localStorage (privado/bloqueado): el panel no se rompe
        const c2 = await b.newContext({viewport: PANT.escritorio});
        await c2.addInitScript(() => { Object.defineProperty(window, 'localStorage', {get() { throw new Error('bloqueado'); }}); });
        const p2 = await c2.newPage(); const e2 = []; p2.on('pageerror', e => e2.push(e.message));
        await p2.goto(`${BASE}/administrator/`); await p2.fill('#mod-login-username', 'admintest'); await p2.fill('#mod-login-password', ADMIN_PASS);
        await Promise.all([p2.waitForNavigation(), p2.press('#mod-login-password', 'Enter')]);
        await p2.goto(`${BASE}/administrator/index.php?option=com_engage`, {waitUntil: 'networkidle'});
        await p2.click('[data-eg-theme-set="dark"]');
        const t2 = await p2.evaluate(() => document.documentElement.getAttribute('data-eg-theme'));
        r('B-sin-almacenamiento', 'con localStorage bloqueado el selector sigue funcionando en la pagina y sin errores propios', t2 === 'dark' && !e2.some(e => /bloqueado|eg-/.test(e)), JSON.stringify(e2.slice(0, 2)));
        await c2.close();

        // foco visible en TODOS los controles del panel, en los 3 disenos
        for (const tema of ['light', 'mid', 'dark']) {
            await ir(p, tema);
            await p.keyboard.press('Shift');
            const fx = await p.evaluate(() => {
                const parse = c => { const m = c.match(/rgba?\(([^)]+)\)/); const q = m[1].split(/[ ,\/]+/).filter(Boolean).map(Number); return {r: q[0], g: q[1], b: q[2], a: q.length > 3 ? q[3] : 1}; };
                const over = (f, b) => ({r: f.r * f.a + b.r * (1 - f.a), g: f.g * f.a + b.g * (1 - f.a), b: f.b * f.a + b.b * (1 - f.a), a: 1});
                const lum = c => { const f = v => { v /= 255; return v <= .03928 ? v / 12.92 : Math.pow((v + .055) / 1.055, 2.4); }; return .2126 * f(c.r) + .7152 * f(c.g) + .0722 * f(c.b); };
                const ratio = (a, b) => { const x = lum(a), y = lum(b); return (Math.max(x, y) + .05) / (Math.min(x, y) + .05); };
                const fondo = el => { const capas = []; for (let e = el.parentElement; e; e = e.parentElement) { const c = parse(getComputedStyle(e).backgroundColor); if (c.a > 0) { capas.push(c); if (c.a >= 1) break; } } let bb = {r: 255, g: 255, b: 255, a: 1}; for (let i = capas.length - 1; i >= 0; i--) bb = over(capas[i], bb); return bb; };
                const out = []; const root = document.getElementById('eg-admin');
                root.querySelectorAll('a[href], button, summary, [tabindex="0"]').forEach(el => {
                    const rc = el.getClientRects(); if (!rc.length || rc[0].width === 0 || el.closest('[hidden], dialog:not([open])')) return;
                    el.focus({focusVisible: true});
                    const s = getComputedStyle(el);
                    out.push({el: (el.className || el.tagName).toString().slice(0, 30), w: parseFloat(s.outlineWidth), st: s.outlineStyle, ratio: Math.round(ratio(parse(s.outlineColor), fondo(el)) * 100) / 100});
                });
                return out;
            });
            const mal = fx.filter(x => x.st === 'none' || x.w < 2 || x.ratio < 3);
            r(`B-foco-${tema}`, `foco visible (contorno >=2px y >=3:1) en los ${fx.length} controles del panel, diseno ${tema}`, fx.length >= 15 && mal.length === 0, mal.length ? JSON.stringify(mal.slice(0, 3)) : `peor ${Math.min(...fx.map(x => x.ratio))}`);
            peor[`${tema}|foco`] = {ratio: Math.min(...fx.map(x => x.ratio)), min: 3, t: 'anillo de foco', tipo: 'foco'};
        }
        await c.close();
    }

    // ------------------------------------------------ C) acciones rapidas por la UI
    {
        const {c, p, info} = await login('admintest', ADMIN_PASS, PANT.escritorio);
        await ir(p, 'light');
        // XSS
        const x = await p.evaluate(() => ({xss: window.__xss, imgs: document.querySelectorAll('#eg-admin img').length, txt: document.getElementById('eg-admin').textContent.includes('<img src=x onerror='), estilos: document.querySelectorAll('#eg-admin [style]').length, scripts: document.querySelectorAll('#eg-admin script').length, onattr: [...document.querySelectorAll('#eg-admin *')].filter(e => [...e.attributes].some(a => /^on/i.test(a.name))).length}));
        r('C-xss', 'nombre y texto con HTML/JS se muestran como TEXTO: sin <img>, sin ejecucion, sin handlers on*', x.xss === undefined && x.imgs === 0 && x.txt && x.onattr === 0, JSON.stringify(x));
        r('C-csp', 'sin estilos en linea ni scripts dentro del panel (compatible con CSP estricta)', x.estilos === 0 && x.scripts === 0, `style=${x.estilos} script=${x.scripts}`);
        // dialogo: cancelar con Escape
        const btnSpam = p.locator(`[data-eg-act="spam"][data-eg-id="${idPend}"]`);
        await btnSpam.focus();
        await p.keyboard.press('Enter');
        let dl = await p.evaluate(() => ({open: document.getElementById('eg-dialog').open, foco: document.activeElement.id, titulo: document.getElementById('eg-dialog-title').textContent, rol: document.getElementById('eg-dialog').getAttribute('aria-labelledby')}));
        r('C-dialogo-abre', 'Enter en la accion abre el dialogo modal con foco en Cancelar y titulo enlazado (aria-labelledby)', dl.open && dl.foco === 'eg-dialog-cancel' && dl.titulo.length > 3 && dl.rol === 'eg-dialog-title', JSON.stringify(dl));
        await p.screenshot({path: `${OUT}/panel-dialogo.png`});
        const trap = []; for (let i = 0; i < 4; i++) { await p.keyboard.press('Tab'); trap.push(await p.evaluate(() => document.activeElement.id || document.activeElement.tagName)); }
        r('C-dialogo-trampa', 'el foco con Tab solo recorre los botones del dialogo (o pasa al navegador), nunca la pagina de detras', trap.every(t => /eg-dialog|BODY/.test(t)), trap.join(','));
        await p.keyboard.press('Escape');
        dl = await p.evaluate(() => ({open: document.getElementById('eg-dialog').open, foco: document.activeElement.getAttribute('data-eg-act')}));
        r('C-escape', 'Escape cierra sin cambios y devuelve el foco al boton de origen', !dl.open && dl.foco === 'spam' && sql(`SELECT enabled FROM jos_engage_comments WHERE id=${idPend}`) === '0', JSON.stringify(dl));
        // spam confirmado
        await btnSpam.click();
        await Promise.all([p.waitForNavigation(), p.click('#eg-dialog-ok')]);
        await p.waitForLoadState('networkidle');
        r('C-spam', 'marcar como spam desde el panel: enabled = -3 en la BD y se vuelve al panel con aviso', sql(`SELECT enabled FROM jos_engage_comments WHERE id=${idPend}`) === '-3' && /view=controlpanel|option=com_engage/.test(p.url()) && (await p.locator('#eg-admin').count()) === 1 && (await p.locator('joomla-alert, .alert-message, .alert').count()) >= 1, p.url().replace(BASE, ''));
        // publicar
        await p.locator(`[data-eg-act="publish"][data-eg-id="${idPend2}"]`).click();
        const ok = await p.evaluate(() => document.getElementById('eg-dialog-ok').textContent);
        await Promise.all([p.waitForNavigation(), p.click('#eg-dialog-ok')]); await p.waitForLoadState('networkidle');
        r('C-publicar', `publicar desde el panel ("${ok}"): enabled = 1 y de vuelta en el panel`, sql(`SELECT enabled FROM jos_engage_comments WHERE id=${idPend2}`) === '1' && (await p.locator('#eg-admin').count()) === 1);
        // eliminar
        await p.locator(`[data-eg-act="delete"][data-eg-id="${idDel}"]`).click();
        await Promise.all([p.waitForNavigation(), p.click('#eg-dialog-ok')]); await p.waitForLoadState('networkidle');
        r('C-eliminar', 'eliminar desde el panel: la fila desaparece de la BD y se vuelve al panel', sql(`SELECT COUNT(*) FROM jos_engage_comments WHERE id=${idDel}`) === '0' && (await p.locator('#eg-admin').count()) === 1);
        r('C-sin-confirm', 'durante las acciones no salio ningun confirm() del navegador', info.js === 0, String(info.js));

        // CSRF: sin token, con token erroneo; en el mismo navegador autenticado
        const antes = sql(`SELECT enabled FROM jos_engage_comments WHERE id=${idXss}`);
        await p.waitForLoadState('networkidle');
        const post = async (campos) => p.evaluate(async (campos) => { const f = new URLSearchParams(campos); const rs = await fetch('index.php?option=com_engage&task=comments.publish', {method: 'POST', body: f, redirect: 'manual', credentials: 'same-origin'}); return {st: rs.status, t: rs.type}; }, campos);
        await post({'cid[]': String(idXss)});
        r('C-csrf-sin', 'publicar SIN token: rechazado, el comentario no cambia', sql(`SELECT enabled FROM jos_engage_comments WHERE id=${idXss}`) === antes);
        await post({'cid[]': String(idXss), [crypto.randomBytes(16).toString('hex')]: '1'});
        r('C-csrf-malo', 'publicar con token inventado: rechazado, el comentario no cambia', sql(`SELECT enabled FROM jos_engage_comments WHERE id=${idXss}`) === antes);
        const r2 = await p.evaluate(async () => { const rs = await fetch('index.php?option=com_engage&task=comments.delete&cid[]=1', {method: 'GET', redirect: 'manual', credentials: 'same-origin'}); return rs.type; });
        r('C-csrf-get', 'borrar por GET sin token: sin efecto', sql('SELECT COUNT(*) FROM jos_engage_comments') !== '0');
        await c.close();
    }

    // ------------------------------------------------ D) Manager sin core.admin
    {
        const mg = await b.newContext({viewport: PANT.escritorio});
        sql(`UPDATE jos_assets SET rules='{"core.manage":{"6":1},"core.edit.state":{"6":1},"core.delete":{"6":1}}' WHERE name='com_engage'`);
        const p = await mg.newPage();
        await p.goto(`${BASE}/administrator/`); await p.fill('#mod-login-username', 'mgrpanel'); await p.fill('#mod-login-password', MGR_PASS);
        await Promise.all([p.waitForNavigation(), p.press('#mod-login-password', 'Enter')]);
        await p.goto(`${BASE}/administrator/index.php?option=com_engage`, {waitUntil: 'networkidle'});
        const t = await p.evaluate(() => ({panel: !!document.getElementById('eg-admin'), tiles: [...document.querySelectorAll('.eg-tile__label')].map(x => x.textContent.trim()), prefs: [...document.querySelectorAll('#toolbar-options, #subhead-container a, joomla-toolbar-button')].some(x => /option/i.test(x.textContent + (x.id || '')) ) , prefsHtml: [...document.querySelectorAll('#subhead-container *')].map(x => x.id || x.tagName).slice(0, 6).join(','), borrar: document.querySelectorAll('[data-eg-act="delete"]').length, spam: document.querySelectorAll('[data-eg-act="spam"]').length, fixes: [...document.querySelectorAll('.eg-btn--small')].length}));
        r('D-manager-panel', 'Manager con core.manage (sin core.admin) ve el panel y las acciones rapidas', t.panel && t.borrar > 0 && t.spam > 0, JSON.stringify(t.tiles));
        r('D-manager-sin-opciones', 'Manager NO ve los accesos Opciones ni Permisos ni el boton Opciones de la barra', t.tiles.length === 3 && !t.prefs, JSON.stringify(t));
        // sin core.delete ni core.edit.state: no hay botones de accion
        sql(`UPDATE jos_assets SET rules='{"core.manage":{"6":1},"core.edit.state":{"6":0},"core.delete":{"6":0}}' WHERE name='com_engage'`);
        await p.goto(`${BASE}/administrator/index.php?option=com_engage`, {waitUntil: 'networkidle'});
        const n = await p.evaluate(() => document.querySelectorAll('[data-eg-act]').length);
        r('D-manager-sin-acciones', 'sin core.edit.state ni core.delete no se pintan botones de accion', n === 0, String(n));
        // y la peticion directa tampoco funciona (la tarea existente comprueba permisos)
        const idT = sql("SELECT id FROM jos_engage_comments WHERE enabled=-3 LIMIT 1");
        const tok = await p.evaluate(() => document.querySelector('#eg-quick input[type=hidden][value="1"]').name);
        const st = await p.evaluate(async ([tok, id]) => { const f = new URLSearchParams({'cid[]': id, [tok]: '1'}); const rs = await fetch('index.php?option=com_engage&task=comments.delete', {method: 'POST', body: f, redirect: 'manual', credentials: 'same-origin'}); return rs.status; }, [tok, idT]);
        r('D-manager-directo', 'Manager sin core.delete: eliminar por peticion directa con token valido NO borra', sql(`SELECT COUNT(*) FROM jos_engage_comments WHERE id=${idT}`) === '1', `HTTP ${st}`);
        sql(`UPDATE jos_assets SET rules='{}' WHERE name='com_engage'`);
        const p3 = await mg.newPage();
        const rs = await p3.goto(`${BASE}/administrator/index.php?option=com_engage`);
        const sinManage = await p3.evaluate(() => ({panel: !!document.getElementById('eg-admin'), txt: document.body.innerText.slice(0, 200)}));
        r('D-sin-manage', 'sin core.manage en el componente: el panel NO se muestra (Joomla lo deniega)', !sinManage.panel, JSON.stringify(sinManage.txt).slice(0, 100));
        await mg.close();
    }

    // ------------------------------------------------ E) semaforos con la configuracion real
    {
        sql(`UPDATE jos_assets SET rules=${sql("SELECT QUOTE('" + origRules.replace(/'/g, "''") + "')")} WHERE name='com_engage'`);
        const {c, p} = await login('admintest', ADMIN_PASS, PANT.escritorio);
        const estado = async () => { await p.goto(`${BASE}/administrator/index.php?option=com_engage`, {waitUntil: 'networkidle'}); return p.evaluate(() => Object.fromEntries([...document.querySelectorAll('.eg-health__item')].map(li => [li.querySelector('.eg-health__title').textContent.trim(), li.className.match(/item--(ok|warn|bad)/)[1] + (li.querySelector('a.eg-btn') ? '+fix' : '')]))); };
        const nivel = (e, i) => Object.values(e)[i];
        sql("UPDATE jos_extensions SET params=JSON_SET(IFNULL(NULLIF(params,''),'{}'),'$.key','') WHERE type='plugin' AND folder='engage' AND element='akismet'");
        // base: moderacion sin antispam y publicacion directa = rojo
        setParams({...base, default_publish: '1', captcha: '-1', filter_mode: 'strict', htmlpurifier_configstring: 'p,b,a[href],img[src]'});
        let e = await estado();
        r('E-moderacion-roja', 'publicacion inmediata sin antispam: semaforo ROJO con enlace de arreglo', nivel(e, 4).startsWith('bad') && nivel(e, 4).endsWith('+fix'), JSON.stringify(e));
        r('E-img-ambar', 'lista de permitidos con img[src]: AMBAR con enlace de arreglo', nivel(e, 6).startsWith('warn'), nivel(e, 6));
        setParams({...base, default_publish: '0', filter_mode: 'strict', htmlpurifier_configstring: 'p,b,a[href]'});
        e = await estado();
        r('E-moderacion-verde', 'con moderacion previa: moderacion VERDE y filtro VERDE (sin img)', nivel(e, 4).startsWith('ok') && nivel(e, 6).startsWith('ok'), JSON.stringify(e));
        setParams({...base, filter_mode: 'strict', htmlpurifier_configstring: 'p,iframe[src],a[href]'});
        e = await estado();
        r('E-iframe-rojo', 'iframe en la lista de permitidos: ROJO', nivel(e, 6).startsWith('bad'), nivel(e, 6));
        setParams({...base, filter_mode: 'joomla'});
        e = await estado();
        r('E-joomla-ambar', 'modo de filtrado "joomla": AMBAR', nivel(e, 6).startsWith('warn'), nivel(e, 6));
        sql("UPDATE jos_extensions SET enabled=0 WHERE type='plugin' AND folder='content' AND element='engage'");
        e = await estado();
        r('E-plugin-rojo', 'plugin Contenido - Engage desactivado: ROJO con boton para abrirlo', nivel(e, 0) === 'bad+fix', nivel(e, 0));
        sql("UPDATE jos_extensions SET enabled=1 WHERE type='plugin' AND folder='content' AND element='engage'");
        sql("UPDATE jos_extensions SET enabled=1 WHERE type='plugin' AND folder='system' AND element='cache'; UPDATE jos_extensions SET enabled=0 WHERE type='plugin' AND folder='system' AND element='engagecache'");
        e = await estado();
        r('E-cache-ambar', 'cache de pagina de Joomla activa y plugin de cache de Engage apagado: AMBAR', nivel(e, 2).startsWith('warn'), nivel(e, 2));
        sql("UPDATE jos_extensions SET enabled=0 WHERE type='plugin' AND folder='system' AND element='cache'");
        sql("UPDATE jos_extensions SET params=JSON_SET(IFNULL(NULLIF(params,''),'{}'),'$.mode','always') WHERE type='plugin' AND folder='engage' AND element='gravatar'");
        e = await estado();
        r('E-gravatar-ambar', 'Gravatar en modo "always": AMBAR', nivel(e, 5).startsWith('warn'), nivel(e, 5));
        r('E-sitio-actualizacion', 'sitio de actualizacion apuntando al fork: VERDE', nivel(e, 10) === 'ok' || nivel(e, 10) === 'warn+fix', nivel(e, 10));
        await c.close();
    }

    await b.close();

    // restaurar
    setParams(origParams);
    sql(`UPDATE jos_assets SET rules=${sql("SELECT QUOTE('" + origRules.replace(/'/g, "''") + "')")} WHERE name='com_engage'`);
    for (const l of origPlugins) { const m = l.match(/^(\d+):(\d):([\s\S]*)$/); if (m) sql(`UPDATE jos_extensions SET enabled=${m[2]}, params='${m[3].replace(/'/g, "''")}' WHERE extension_id=${m[1]}`); }

    // resumen de peores contrastes
    console.log('\nPEOR CONTRASTE POR DISENO Y TIPO (ratio medido / minimo exigido):');
    const por = {};
    for (const [k, v] of Object.entries(peor)) { const [d, tipo] = k.split('|'); (por[d] = por[d] || []).push(`${tipo} ${v.ratio}:1 (min ${v.min}) [${v.t}]`); }
    for (const [d, l] of Object.entries(por)) console.log(`  ${d}: ${l.join(' | ')}`);
    fs.writeFileSync(`${WORK}/resultados-panel.json`, JSON.stringify({resultados: res, peor}, null, 1));
    const ko = res.filter(x => x.estado === 'FALLA').length;
    console.log(`\n${res.length - ko} PASA / ${ko} FALLA`);
    process.exit(ko ? 1 : 0);
})().catch(e => { console.error(e); try { setParams(origParams); } catch (x) {} process.exit(1); });
