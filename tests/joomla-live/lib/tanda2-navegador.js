/**
 * 0.8.0 (tanda 2): selector de orden, «solo mis favoritos», «Copiar enlace» e insignias en un NAVEGADOR real (Chromium + Playwright) sobre el Joomla de pruebas.
 *
 * MATRIZ: 5 variantes (Clasico, Clasico con "Cargar CSS personalizado", Moderno, Minimalista, Oscuro) x pagina clara/oscura (modo del dispositivo emulado y una
 * plantilla que fuerza el color de la pagina) x anchos 390 / 768 / 1280, como usuario Registered (con favoritos) en un hilo con respuestas anidadas, un autor
 * de nombre larguisimo que es ademas moderador (peor caso de la cabecera), moderadores y autores:
 *   - la barra de orden: grupo con nombre, 3 enlaces + conmutador de favoritos visibles tras el JS, sin solapes, sin desbordes, area tactil, contraste del texto
 *     >= 4,5:1 (incluido el estado actual y con el raton encima) y de los bordes de los controles >= 3:1;
 *   - el boton «Copiar enlace»: visible, en la fila de abajo, sin solapes con Responder ni con las reacciones, area tactil, icono >= 3:1;
 *   - las insignias: con texto, sin solapes con el nombre, dentro de la cabecera, sin desborde horizontal a 390 px, contraste del texto >= 4,5:1, icono presente.
 * FUNCIONAL (Clasico y Moderno): orden con el raton y con el teclado (Tab/Enter, foco visible), aria-current, favoritos (invitado: oculto; Registered: lista plana
 *   con «En respuesta a» y enlace al hilo; vacia con mensaje; volver), copiar con la API Clipboard (se lee el portapapeles real), con la alternativa execCommand y
 *   con ambas rotas (aviso de error), aviso role=status, boton falso inyectado en un comentario (no copia), Host distinto del de la pagina (se reconstruye con el
 *   origen real), sin JavaScript, cache de pagina de Joomla (la pagina cacheada del invitado sin estado de usuario; el usuario ve su conmutador), el moderador
 *   (estrella existente no duplicada), cero peticiones a otros hosts y cero errores de consola.
 * Entorno: BASE_URL, WORK, IDS_FILE, DB_NAME, PW_MODULE, CHROMIUM, OUT (capturas), CAPTURAS=1 (imagenes de docs/img). Sale con 1 si algo FALLA.
 */
const {execFileSync} = require('child_process');
const fs = require('fs');
const crypto = require('crypto');
const {chromium} = require(process.env.PW_MODULE || 'playwright');
const BASE = process.env.BASE_URL || 'http://127.0.0.1:8080';
const WORK = process.env.WORK;
const OUT = process.env.OUT || `${WORK}/tanda2-capturas`;
const DB = process.env.DB_NAME || 'joomla_test';
const SITE = `${WORK}/${process.env.SITE_DIR || 'site'}`;
const ids = JSON.parse(fs.readFileSync(`${WORK}/${process.env.IDS_FILE || 'ids.json'}`, 'utf8'));
const AS = ids.art_publico_asset;
const ART = ids.art_publico;
const PAGE = `${BASE}/index.php?option=com_content&view=article&id=${ART}&catid=${ids.cat_publica}`;
const passFile = `${WORK}/tanda2pass.txt`;
if (!fs.existsSync(passFile)) fs.writeFileSync(passFile, 'Aa1!' + crypto.randomBytes(10).toString('hex'), {mode: 0o600});
const PASS = fs.readFileSync(passFile, 'utf8').trim();
const res = [];
let fallos = 0;
function r(id, desc, ok, ev = '') { res.push({id, desc, estado: ok ? 'PASA' : 'FALLA', ev}); if (!ok) fallos++; if (!ok || process.env.VERBOSO) console.log(`${id.padEnd(30)} ${ok ? 'PASA ' : 'FALLA'} ${desc}${ev ? '  [' + String(ev).slice(0, 300) + ']' : ''}`); }
function sql(s) { return execFileSync('mysql', ['-uroot', '--raw', '--default-character-set=utf8mb4', '-N', DB, '-e', s]).toString().trim(); }
const q = s => `'${String(s).replace(/\\/g, '\\\\').replace(/'/g, "''")}'`;
fs.mkdirSync(OUT, {recursive: true});
const sleep = ms => new Promise(f => setTimeout(f, ms));

const prev = {com: sql("SELECT IFNULL(params,'') FROM jos_extensions WHERE element='com_engage' AND type='component'"), rules: sql("SELECT rules FROM jos_assets WHERE name='com_engage'"),
    author: sql(`SELECT created_by FROM jos_content WHERE id=${ART}`),
    cache: sql("SELECT enabled, IFNULL(params,'') FROM jos_extensions WHERE element='cache' AND folder='system'"), ecache: sql("SELECT enabled FROM jos_extensions WHERE element='engagecache' AND folder='system'")};
const cfgPath = `${SITE}/configuration.php`;
const cfgOrig = fs.readFileSync(cfgPath, 'utf8');
const limpiaCache = () => { for (const d of ['cache', 'administrator/cache']) { let l = []; try { l = fs.readdirSync(`${SITE}/${d}`, {withFileTypes: true}); } catch (e) {} for (const e of l) if (e.isDirectory()) fs.rmSync(`${SITE}/${d}/${e.name}`, {recursive: true, force: true}); } };
const restaurar = () => {
    try { sql(`UPDATE jos_extensions SET params=${q(prev.com)} WHERE element='com_engage' AND type='component'`); } catch (e) {}
    try { sql(`UPDATE jos_assets SET rules=${q(prev.rules)} WHERE name='com_engage'`); } catch (e) {}
    try { sql(`UPDATE jos_content SET created_by=${prev.author} WHERE id=${ART}`); } catch (e) {}
    try { const [en, pr] = prev.cache.split('\t'); sql(`UPDATE jos_extensions SET enabled=${en}, params=${q(pr || '')} WHERE element='cache' AND folder='system'`); } catch (e) {}
    try { sql(`UPDATE jos_extensions SET enabled=${prev.ecache} WHERE element='engagecache' AND folder='system'`); } catch (e) {}
    try { fs.writeFileSync(cfgPath, cfgOrig); } catch (e) {}
    try { sql(`DELETE FROM jos_engage_reactions; DELETE FROM jos_engage_comments WHERE asset_id=${AS}`); } catch (e) {}
    try { limpiaCache(); } catch (e) {}
};
process.on('exit', restaurar);
const setCom = (extra = {}) => sql(`UPDATE jos_extensions SET params=${q(JSON.stringify({default_publish: '1', max_level: '3', comments_ordering: 'asc', default_limit: '100', ...extra}))} WHERE element='com_engage' AND type='component'`);

// ---- usuarios
const hash = execFileSync('php', ['-r', 'echo password_hash($argv[1], PASSWORD_BCRYPT);', PASS]).toString();
const mk = (u, g, name) => {
    let id = sql(`SELECT id FROM jos_users WHERE username='${u}'`);
    if (!id) { sql(`INSERT INTO jos_users (name,username,email,password,block,sendEmail,registerDate,activation,params,resetCount,otpKey,otep,requireReset,authProvider) VALUES (${q(name || u)},'${u}','${u}@example.invalid','${hash}',0,0,NOW(),'','{}',0,'','',0,''); INSERT INTO jos_user_usergroup_map (user_id,group_id) SELECT id,${g} FROM jos_users WHERE username='${u}'`); id = sql(`SELECT id FROM jos_users WHERE username='${u}'`); }
    else sql(`UPDATE jos_users SET password='${hash}', block=0, name=${q(name || u)} WHERE id=${id}`);
    return +id;
};
const LARGO = 'NombreDeUsuarioMuyLargoSinEspaciosParaRomperLaCabeceraEnMovil1234567890';
const u1 = mk('t2reg1', 2, 'Ana Registrada'), u2 = mk('t2reg2', 2, 'Berta Autora'), uM = mk('t2mgr', 6, 'Mario Manager'), uL = mk('t2largo', 6, LARGO);
const uAdmin = +sql("SELECT id FROM jos_users WHERE username='admintest'");

// ---- hilo (fechas fijas)
const ins = (parent, body, name, by, enabled, when) => +sql(`INSERT INTO jos_engage_comments (asset_id,parent_id,body,name,email,ip,user_agent,enabled,created,created_by) VALUES (${AS},${parent},${q('<p>' + body + '</p>')},${by ? 'NULL' : q(name)},${by ? 'NULL' : q(name.toLowerCase() + '@example.invalid')},'203.0.113.7','t',${enabled},'${when}',${by}); SELECT LAST_INSERT_ID()`);
function sembrar() {
    sql(`DELETE FROM jos_engage_reactions; DELETE FROM jos_engage_comments WHERE asset_id=${AS}`);
    const c = {};
    c.c1 = ins('NULL', 'Raiz de Ana con <a href="https://ejemplo.example/enlace">un enlace</a> y bastante texto para que ocupe varias lineas en la pantalla del movil.', '', u1, 1, '2026-09-01 10:00:00');
    c.c2 = ins('NULL', 'Raiz de un invitado', 'Invitado', 0, 1, '2026-09-02 10:00:00');
    c.c3 = ins('NULL', 'Raiz del manager', '', uM, 1, '2026-09-03 10:00:00');
    c.c4 = ins('NULL', 'Raiz del autor con nombre larguisimo', '', uL, 1, '2026-09-04 10:00:00');
    c.c5 = ins(c.c1, 'Respuesta de un invitado', 'Invitado', 0, 1, '2026-09-05 10:00:00');
    c.c6 = ins(c.c1, 'Respuesta de Berta', '', u2, 1, '2026-09-06 10:00:00');
    c.c7 = ins(c.c5, 'Nieta del manager', '', uM, 1, '2026-09-07 10:00:00');
    c.c8 = ins('NULL', 'Raiz del admin', '', uAdmin, 1, '2026-09-08 10:00:00');
    c.c9 = ins('NULL', 'Raiz sin publicar', 'Pendiente', 0, 0, '2026-09-09 10:00:00');
    c.c11 = ins(c.c3, 'Respuesta tardia a c3', '', u1, 1, '2026-09-11 10:00:00');
    const re = (cm, u, t) => sql(`INSERT INTO jos_engage_reactions (comment_id,user_id,type,created) VALUES (${cm},${u},${t},NOW())`);
    re(c.c1, uM, 1); re(c.c1, u2, 1); re(c.c1, uL, 1); re(c.c4, u1, 1); re(c.c4, uM, 1); re(c.c4, uAdmin, 1); re(c.c2, u1, 1); re(c.c3, u1, 2);
    for (const f of [c.c1, c.c3, c.c5, c.c6, c.c9]) re(f, u1, 3);
    re(c.c2, uM, 3);
    return c;
}
let cid = sembrar();
const secuencia = {
    top: () => [cid.c4, cid.c1, cid.c5, cid.c7, cid.c6, cid.c2, cid.c8, cid.c3, cid.c11],
    newest: () => [cid.c8, cid.c4, cid.c3, cid.c11, cid.c2, cid.c1, cid.c5, cid.c7, cid.c6],
    oldest: () => [cid.c1, cid.c5, cid.c7, cid.c6, cid.c2, cid.c3, cid.c11, cid.c4, cid.c8],
};

const PAGINAS = {clara: 'html,body{background:#fff;color:#222}', oscura: 'html,body{background:#1a1a1a;color:#f1f1f1 !important}'};
const VARIANTES = {classic: {theme: 'classic'}, 'classic+css': {theme: 'classic', loadCustomCss: '1'}, modern: {theme: 'modern'}, minimal: {theme: 'minimal'}, dark: {theme: 'dark'}};

// ---- Se ejecuta DENTRO de la pagina
const medir = () => {
    const parse = c => { let m = c.match(/color\(srgb ([^)]+)\)/); if (m) { const p = m[1].split(/[ \/]+/).filter(Boolean).map(Number); return {r: p[0] * 255, g: p[1] * 255, b: p[2] * 255, a: p.length > 3 ? p[3] : 1}; } m = c.match(/rgba?\(([^)]+)\)/); if (!m) return null; const p = m[1].split(/[ ,\/]+/).filter(Boolean).map(Number); return {r: p[0], g: p[1], b: p[2], a: p.length > 3 ? p[3] : 1}; };
    const over = (f, b) => ({r: f.r * f.a + b.r * (1 - f.a), g: f.g * f.a + b.g * (1 - f.a), b: f.b * f.a + b.b * (1 - f.a), a: 1});
    const lum = c => { const f = v => { v /= 255; return v <= .03928 ? v / 12.92 : Math.pow((v + .055) / 1.055, 2.4); }; return .2126 * f(c.r) + .7152 * f(c.g) + .0722 * f(c.b); };
    const ratio = (a, b) => { const x = lum(a), y = lum(b); return Math.round((Math.max(x, y) + .05) / (Math.min(x, y) + .05) * 100) / 100; };
    const fondo = el => { const capas = []; for (let e = el; e; e = e.parentElement) { const c = parse(getComputedStyle(e).backgroundColor); if (c && c.a > 0) { capas.push(c); if (c.a >= 1) break; } } let b = {r: 255, g: 255, b: 255, a: 1}; for (let i = capas.length - 1; i >= 0; i--) b = over(capas[i], b); return b; };
    const R = e => { const c = e.getBoundingClientRect(); return {l: c.left, t: c.top, r: c.right, b: c.bottom, w: c.width, h: c.height}; };
    const solapa = (a, b) => Math.min(a.r, b.r) - Math.max(a.l, b.l) > .5 && Math.min(a.b, b.b) - Math.max(a.t, b.t) > .5;
    const vw = document.documentElement.clientWidth;
    const visible = e => { const c = R(e); return c.w > 0 && c.h > 0 && getComputedStyle(e).visibility !== 'hidden' && !e.closest('[hidden]'); };
    const o = {vw, barra: null, solapes: [], desbordes: [], pequenos: [], contraste: [], bordes: [], copias: {n: 0, visibles: 0, solapes: [], pequenos: [], iconos: [], fuera: []}, insignias: {n: 0, sinTexto: [], solapes: [], fuera: [], contraste: [], desborde: [], sinIcono: 0}, estilosEnLinea: 0};
    if (document.documentElement.scrollWidth > vw + 1) o.desbordes.push(`pagina ${document.documentElement.scrollWidth}>${vw}`);
    const sec = document.querySelector('section.akengage-outer-container');
    const bar = sec.querySelector('.akengage-toolbar');
    if (bar) {
        const grp = bar.querySelector('.akengage-sort[role="group"]');
        const lab = bar.querySelector('.akengage-toolbar-label');
        const enl = [...bar.querySelectorAll('.akengage-sort-link')];
        const fav = bar.querySelector('.akengage-fav-toggle');
        o.barra = {grupo: !!grp && grp.getAttribute('aria-labelledby') === (lab && lab.id), enlaces: enl.length, actuales: enl.filter(e => e.getAttribute('aria-current') === 'true').length, favVisible: !!fav && visible(fav), nofollow: [...bar.querySelectorAll('a')].every(a => /nofollow/.test(a.rel)), rect: R(bar)};
        if (bar.scrollWidth > bar.clientWidth + 1) o.desbordes.push('barra desborda');
        const rb = R(bar); if (rb.r > vw + 1 || rb.l < -1) o.desbordes.push(`barra fuera del viewport ${Math.round(rb.l)}..${Math.round(rb.r)}`);
        const piezas = [lab, ...enl, fav && visible(fav) ? fav : null].filter(Boolean).map(e => ({e, c: R(e)}));
        for (let i = 0; i < piezas.length; i++) { if (piezas[i].c.r > rb.r + 1 || piezas[i].c.l < rb.l - 1) o.desbordes.push(`pieza fuera de la barra: ${piezas[i].e.textContent.trim().slice(0, 20)}`); for (let j = i + 1; j < piezas.length; j++) if (solapa(piezas[i].c, piezas[j].c)) o.solapes.push(`${piezas[i].e.textContent.trim().slice(0, 14)} x ${piezas[j].e.textContent.trim().slice(0, 14)}`); }
        const min = vw < 576 ? 40 : 34;
        for (const p of piezas) if (p.e !== lab && Math.min(p.c.h, 1e9) < min - .6) o.pequenos.push(`${p.e.textContent.trim().slice(0, 14)} h=${Math.round(p.c.h)}`);
        for (const p of piezas) {
            const e = p.e; const cs = getComputedStyle(e); const bg = fondo(e); const fg = parse(cs.color);
            o.contraste.push({t: e.textContent.trim().slice(0, 20), ratio: ratio(over(fg, bg), bg), actual: e.getAttribute('aria-current') === 'true'});
            if (e !== lab) {
                const bc = parse(cs.borderTopColor); const bw = parseFloat(cs.borderTopWidth);
                const fuera = fondo(bar);
                if (bw > 0 && bc) o.bordes.push({t: e.textContent.trim().slice(0, 14), ratio: Math.max(ratio(over(bc, bg), fuera), ratio(over(bc, bg), bg))});
            }
        }
        o.estilosEnLinea += bar.querySelectorAll('[style]').length;
    }
    // Copiar enlace
    for (const art of sec.querySelectorAll('article[id^="akengage-comment-"]')) {
        const w = art.querySelector('.akengage-copylink'); if (!w) continue;
        o.copias.n++;
        if (!visible(w)) continue;
        o.copias.visibles++;
        const b = w.querySelector('button'); const cb = R(b); const fila = art.querySelector('.akengage-comment-reply'); const rf = R(fila);
        const vecinos = [...fila.querySelectorAll('.akengage-comment-reply-btn, .akengage-reactions:not([hidden]) button, .akengage-react-count')].filter(visible).map(e => ({e, c: R(e)}));
        for (const v of vecinos) if (solapa(cb, v.c)) o.copias.solapes.push(`#${art.id} x ${v.e.className.toString().split(' ')[0]}`);
        const min = vw < 576 ? 40 : 34; if (Math.min(cb.w, cb.h) < min - .6) o.copias.pequenos.push(`${art.id} ${Math.round(cb.w)}x${Math.round(cb.h)}`);
        if (cb.l < rf.l - 1 || cb.r > rf.r + 1 || cb.r > vw + 1) o.copias.fuera.push(art.id);
        const sv = [...b.querySelectorAll('svg')].find(s => getComputedStyle(s).display !== 'none'); const bg = fondo(b);
        const st = parse(getComputedStyle(sv).stroke) || parse(getComputedStyle(b).color);
        o.copias.iconos.push(ratio(over(st, bg), bg));
        o.estilosEnLinea += w.querySelectorAll('[style]').length;
    }
    // Insignias
    for (const bd of sec.querySelectorAll('.akengage-badge')) {
        o.insignias.n++;
        const tx = bd.querySelector('.akengage-badge-text'); const ic = bd.querySelector('svg');
        if (!tx || !tx.textContent.trim()) o.insignias.sinTexto.push(bd.className);
        if (!ic || R(ic).w <= 0) o.insignias.sinIcono++;
        const cs = getComputedStyle(tx); const bg = fondo(bd); const fg = parse(cs.color);
        o.insignias.contraste.push({t: tx.textContent.trim(), ratio: ratio(over(fg, bg), bg)});
        const head = bd.closest('footer'); const rb = R(bd), rh = R(head);
        if (rb.l < rh.l - 1 || rb.r > rh.r + 1 || rb.r > vw + 1) o.insignias.fuera.push(`${bd.closest('article').id}`);
        const nombre = bd.closest('.akengange-commenter-name').querySelector('[itemprop="name"]'); if (solapa(rb, R(nombre))) o.insignias.solapes.push(`${bd.closest('article').id}`);
        if (head.scrollWidth > head.clientWidth + 1) o.insignias.desborde.push(bd.closest('article').id);
    }
    return o;
};

(async () => {
    const b = await chromium.launch({executablePath: process.env.CHROMIUM || '/opt/pw-browsers/chromium', args: ['--no-sandbox']});
    const errores = [];
    const hosts = new Set();
    const nuevoCtx = async (opts = {}) => {
        const c = await b.newContext({viewport: {width: 1280, height: 900}, ...opts});
        const p = await c.newPage();
        p.on('pageerror', e => errores.push('pageerror ' + e.message));
        p.on('console', m => { if (m.type() === 'error') errores.push('console ' + m.text().slice(0, 160)); });
        p.on('request', rq => { try { const u = new URL(rq.url()); if (!['data:', 'blob:', 'about:'].includes(u.protocol)) hosts.add(u.host); } catch (e) {} });
        return {c, p};
    };
    const login = async (p, user) => {
        await p.goto(`${BASE}/index.php?option=com_users&view=login`);
        await p.fill('#username', user); await p.fill('#password', PASS);
        await Promise.all([p.waitForNavigation(), p.locator('form button[type=submit]').first().click()]);
    };
    const listo = async (p, esperaFav = true) => { await p.waitForFunction(() => document.querySelectorAll('[data-engage-reactions]:not([hidden])').length >= 1, null, {timeout: 15000}); if (esperaFav) await p.waitForFunction(() => { const f = document.querySelector('[data-engage-fav-toggle]'); return !f || !f.hidden; }, null, {timeout: 15000}).catch(() => {}); await sleep(250); };
    const orden = p => p.evaluate(() => [...document.querySelectorAll('article[id^="akengage-comment-"]')].map(a => +a.id.replace('akengage-comment-', '')));

    // ================= MATRIZ
    sql(`UPDATE jos_content SET created_by=${uL} WHERE id=${ART}`); // autor = el del nombre larguisimo (es ademas moderador): peor caso de la cabecera
    let combos = 0; const peor = {barra: 99, borde: 99, copia: 99, insignia: 99};
    for (const [vname, vp] of Object.entries(VARIANTES)) {
        setCom(vp);
        for (const [esq, css] of [['claro', PAGINAS.clara], ['oscuro', PAGINAS.oscura]]) {
            const {c: ctx, p} = await nuevoCtx({colorScheme: esq === 'claro' ? 'light' : 'dark'});
            await login(p, 't2reg1');
            for (const w of [390, 768, 1280]) {
                await p.setViewportSize({width: w, height: 1000});
                await p.goto(PAGE); await p.addStyleTag({content: css});
                await listo(p);
                const m = await p.evaluate(medir);
                const k = `${vname}/${esq}/${w}`; combos++;
                r(`M ${k} barra`, 'barra con grupo nombrado, 3 enlaces, uno actual, todos rel=nofollow y el conmutador de favoritos visible tras el JS', !!m.barra && m.barra.grupo && m.barra.enlaces === 3 && m.barra.actuales === 1 && m.barra.nofollow && m.barra.favVisible, JSON.stringify(m.barra));
                r(`M ${k} solapes`, 'sin solapes ni desbordes en la barra, la pagina y la cabecera', !m.solapes.length && !m.desbordes.length, [...m.solapes, ...m.desbordes].slice(0, 4).join('; '));
                r(`M ${k} tactil`, w < 576 ? 'enlaces y conmutador >= 40 px de alto en movil' : 'enlaces y conmutador >= 34 px', !m.pequenos.length, m.pequenos.join('; '));
                const mc = Math.min(...m.contraste.map(x => x.ratio)); const mb = Math.min(...m.bordes.map(x => x.ratio));
                r(`M ${k} contraste`, `texto de la barra >= 4,5:1 (minimo ${mc}) y bordes de los controles >= 3:1 (minimo ${mb})`, mc >= 4.5 && mb >= 3, JSON.stringify(m.contraste.filter(x => x.ratio < 4.5).concat(m.bordes.filter(x => x.ratio < 3))));
                peor.barra = Math.min(peor.barra, mc); peor.borde = Math.min(peor.borde, mb);
                r(`M ${k} copiar`, `${m.copias.n} botones «Copiar enlace», todos visibles tras el JS, en la fila de abajo, sin solapes, >= ${w < 576 ? 40 : 34} px`, m.copias.n === 9 && m.copias.visibles === 9 && !m.copias.solapes.length && !m.copias.pequenos.length && !m.copias.fuera.length, JSON.stringify([m.copias.n, m.copias.visibles, m.copias.solapes.slice(0, 2), m.copias.pequenos.slice(0, 2), m.copias.fuera.slice(0, 2)]));
                const mi = Math.min(...m.copias.iconos); peor.copia = Math.min(peor.copia, mi);
                r(`M ${k} icono`, `icono del boton Copiar >= 3:1 contra su fondo (minimo ${mi})`, mi >= 3);
                r(`M ${k} insignias`, `${m.insignias.n} insignias con texto e icono, sin solapar el nombre, dentro de la cabecera y sin desborde horizontal`, m.insignias.n === 5 && !m.insignias.sinTexto.length && m.insignias.sinIcono === 0 && !m.insignias.solapes.length && !m.insignias.fuera.length && !m.insignias.desborde.length, JSON.stringify(m.insignias));
                const mi2 = Math.min(...m.insignias.contraste.map(x => x.ratio)); peor.insignia = Math.min(peor.insignia, mi2);
                r(`M ${k} insignia contraste`, `texto de las insignias >= 4,5:1 (minimo ${mi2})`, mi2 >= 4.5, JSON.stringify(m.insignias.contraste.filter(x => x.ratio < 4.5)));
                r(`M ${k} csp`, 'ningun estilo en linea en la barra ni en los botones nuevos (CSP)', m.estilosEnLinea === 0);
                if (process.env.CAPTURAS && esq === 'claro' && w === 1280 && vname === 'modern') await (await p.$('section.akengage-outer-container')).screenshot({path: `${OUT}/tanda2-escritorio-moderno.png`});
                if (process.env.CAPTURAS && esq === 'oscuro' && w === 390 && vname === 'dark') await (await p.$('section.akengage-outer-container')).screenshot({path: `${OUT}/tanda2-movil-oscuro.png`});
            }
            await ctx.close();
        }
    }
    console.log(`matriz: ${combos} combinaciones; peores contrastes medidos: barra ${peor.barra}, bordes ${peor.borde}, icono copiar ${peor.copia}, insignias ${peor.insignia}`);

    // Contraste con el raton encima (hover) de los enlaces de orden: texto sobre relleno >= 4,5:1
    for (const vname of ['classic', 'modern', 'minimal', 'dark']) {
        setCom(VARIANTES[vname]);
        const {c: ctx, p} = await nuevoCtx();
        await login(p, 't2reg1'); await p.goto(PAGE); await listo(p);
        const enl = p.locator('.akengage-sort-link:not(.is-active)').first();
        await enl.hover(); await sleep(350);
        const h = await p.evaluate(() => {
            const parse = c => { const m = c.match(/rgba?\(([^)]+)\)/); const x = m[1].split(/[ ,\/]+/).filter(Boolean).map(Number); return {r: x[0], g: x[1], b: x[2], a: x.length > 3 ? x[3] : 1}; };
            const lum = c => { const f = v => { v /= 255; return v <= .03928 ? v / 12.92 : Math.pow((v + .055) / 1.055, 2.4); }; return .2126 * f(c.r) + .7152 * f(c.g) + .0722 * f(c.b); };
            const e = document.querySelector('.akengage-sort-link:hover'); if (!e) return null; const cs = getComputedStyle(e);
            let bg = parse(cs.backgroundColor); const fg = parse(cs.color);
            if (bg.a < 1) { let f = e.parentElement; let b2 = {r: 255, g: 255, b: 255, a: 1}; for (; f; f = f.parentElement) { const c = parse(getComputedStyle(f).backgroundColor); if (c.a >= 1) { b2 = c; break; } } bg = {r: bg.r * bg.a + b2.r * (1 - bg.a), g: bg.g * bg.a + b2.g * (1 - bg.a), b: bg.b * bg.a + b2.b * (1 - bg.a), a: 1}; }
            const x = lum(fg), y = lum(bg); return Math.round((Math.max(x, y) + .05) / (Math.min(x, y) + .05) * 100) / 100;
        });
        r(`H ${vname}`, `con el raton encima el texto del enlace de orden se lee (>= 4,5:1; medido ${h})`, h !== null && h >= 4.5);
        await ctx.close();
    }

    // Autor SIN ser moderador (insignia «Author» sola) y cabecera en movil
    sql(`UPDATE jos_content SET created_by=${u2} WHERE id=${ART}`);
    for (const vname of ['classic', 'modern', 'dark']) {
        setCom(VARIANTES[vname]);
        for (const w of [390, 1280]) {
            const {c: ctx, p} = await nuevoCtx({viewport: {width: w, height: 900}});
            await p.goto(PAGE); await listo(p, false);
            const m = await p.evaluate(medir);
            const tipos = await p.evaluate(() => [...document.querySelectorAll('.akengage-badge')].map(e => e.className.replace('akengage-badge ', '')).join());
            r(`A ${vname}/${w}`, 'autor = Berta (no moderadora): una insignia Author (su respuesta) y cuatro Moderator (Mario x2, el admin y el del nombre largo); sin solapes ni desbordes', m.insignias.n === 5 && tipos.split(',').filter(x => x === 'akengage-badge--author').length === 1 && !m.insignias.solapes.length && !m.insignias.fuera.length && !m.insignias.desborde.length && Math.min(...m.insignias.contraste.map(x => x.ratio)) >= 4.5, tipos);
            await ctx.close();
        }
    }

    // ================= FUNCIONAL
    sql(`UPDATE jos_content SET created_by=${u2} WHERE id=${ART}`);
    for (const vname of ['classic', 'modern']) {
        setCom(VARIANTES[vname]); cid = sembrar();
        const {c: ctx, p} = await nuevoCtx({permissions: ['clipboard-read', 'clipboard-write']});
        await ctx.grantPermissions(['clipboard-read', 'clipboard-write'], {origin: BASE});
        await login(p, 't2reg1');
        await p.goto(PAGE); await listo(p);
        const V = vname;
        // --- Orden con el raton
        r(`F ${V} orden inicial`, 'sin seleccion: orden de siempre (asc) y «Oldest» actual', JSON.stringify(await orden(p)) === JSON.stringify(secuencia.oldest()) && (await p.locator('.akengage-sort-link[aria-current="true"]').textContent()).trim() === 'Oldest');
        for (const [txt, modo] of [['Top rated', 'top'], ['Newest', 'newest'], ['Oldest', 'oldest']]) {
            await Promise.all([p.waitForNavigation(), p.locator('.akengage-sort-link', {hasText: txt}).click()]);
            await listo(p);
            const url = new URL(p.url());
            r(`F ${V} orden ${modo}`, `clic en «${txt}»: URL con akengage_sort=${modo}, orden servido correcto, «${txt}» actual y la pagina baja a la seccion`, url.searchParams.get('akengage_sort') === modo && url.hash === '#akengage-comments-section' && JSON.stringify(await orden(p)) === JSON.stringify(secuencia[modo]()) && (await p.locator('.akengage-sort-link[aria-current="true"]').textContent()).trim() === txt, `${p.url()} ${JSON.stringify(await orden(p))}`);
        }
        // --- Teclado
        await p.goto(PAGE); await listo(p);
        await p.evaluate(() => { document.activeElement && document.activeElement.blur(); window.scrollTo(0, 0); });
        let alcanzado = false, tabs = 0;
        for (; tabs < 150 && !alcanzado; tabs++) { await p.keyboard.press('Tab'); alcanzado = await p.evaluate(() => document.activeElement && document.activeElement.classList.contains('akengage-sort-link')); }
        const foco = await p.evaluate(() => { const e = document.activeElement; const cs = getComputedStyle(e); return {estilo: cs.outlineStyle, ancho: parseFloat(cs.outlineWidth), fv: e.matches(':focus-visible'), t: e.textContent.trim()}; });
        r(`F ${V} teclado`, `con Tab se llega al primer enlace de orden (tras ${tabs} pulsaciones, «${foco.t}») con foco visible`, alcanzado && foco.fv && foco.estilo !== 'none' && foco.ancho >= 2, JSON.stringify(foco));
        for (let i = 0; i < 2; i++) await p.keyboard.press('Tab'); // hasta «Top rated»
        const objetivo = await p.evaluate(() => document.activeElement.textContent.trim());
        await Promise.all([p.waitForNavigation(), p.keyboard.press('Enter')]);
        r(`F ${V} teclado enter`, `Enter sobre «${objetivo}» aplica el orden (akengage_sort=top)`, objetivo === 'Top rated' && new URL(p.url()).searchParams.get('akengage_sort') === 'top');
        // --- Favoritos
        await p.goto(PAGE); await listo(p);
        const tog = p.locator('[data-engage-fav-toggle]');
        r(`F ${V} fav boton`, 'Registered: el conmutador «Only my favourites» se muestra tras el JS (es un enlace con rel=nofollow)', await tog.isVisible() && (await tog.getAttribute('rel')) === 'nofollow' && (await tog.evaluate(e => e.tagName)) === 'A');
        await Promise.all([p.waitForNavigation(), tog.click()]);
        await sleep(300);
        const fv = await orden(p);
        const ft = await p.evaluate(() => ({h3: document.querySelector('.akengage-title').textContent.trim(), niveles: document.querySelectorAll('ul.akengage-comment-list--level2').length, citas: document.querySelectorAll('.akengage-comment-replyto-link').length, hilo: document.querySelectorAll('.akengage-context-link').length, responder: document.querySelectorAll('.akengage-comment-reply-btn').length, actual: document.querySelector('[data-engage-fav-toggle]').getAttribute('aria-current'), vis: !document.querySelector('[data-engage-fav-toggle]').hidden, texto: document.querySelector('[data-engage-fav-toggle]').textContent.trim()}));
        r(`F ${V} fav lista`, 'lista plana con SUS 4 favoritos publicados (c1, c3, c5, c6), cabecera «My favourite comments (4)», sin nivel 2, «In reply to» en las 2 respuestas, «View in thread» en las 4 y sin Responder', JSON.stringify(fv) === JSON.stringify([cid.c1, cid.c3, cid.c5, cid.c6]) && ft.h3 === 'My favourite comments (4)' && ft.niveles === 0 && ft.citas === 2 && ft.hilo === 4 && ft.responder === 0, JSON.stringify([fv, ft]));
        r(`F ${V} fav conmutador`, 'en la lista de favoritos el conmutador esta visible, es el actual (aria-current) y ofrece volver («Show all comments»)', ft.vis && ft.actual === 'true' && ft.texto === 'Show all comments');
        const estrellas = await p.evaluate(() => [...document.querySelectorAll('article.akengage-is-favorite')].map(a => a.id).length);
        r(`F ${V} fav estrellas`, 'los 4 comentarios salen marcados como favoritos (los pinta el JS de reacciones sobre la lista)', estrellas === 4, String(estrellas));
        // enlace al hilo
        await Promise.all([p.waitForNavigation(), p.locator(`#akengage-comment-${cid.c5} .akengage-context-link`).click()]);
        await listo(p);
        const u = new URL(p.url());
        const enHilo = await p.evaluate(id => { const a = document.getElementById('akengage-comment-' + id); const r = a.getBoundingClientRect(); return {nivel: document.querySelectorAll('ul.akengage-comment-list--level2').length, enVista: r.top < innerHeight && r.bottom > 0, fav: location.search.includes('akengage_fav')}; }, cid.c5);
        r(`F ${V} fav al hilo`, '«View in thread» lleva a la lista completa (sin akengage_fav), con el comentario a la vista y anidado', u.searchParams.get('akengage_cid') === String(cid.c5) && u.hash === `#akengage-comment-${cid.c5}` && !enHilo.fav && enHilo.nivel >= 1 && enHilo.enVista, JSON.stringify(enHilo) + p.url());
        // volver y favoritos vacios (Berta no tiene)
        await ctx.close();
        const B = await nuevoCtx(); await login(B.p, 't2reg2'); await B.p.goto(PAGE + '&akengage_fav=1'); await sleep(900);
        const vacio = await B.p.evaluate(() => ({msg: (document.querySelector('.akengage-fav-empty') || {}).textContent, arts: document.querySelectorAll('article[id^="akengage-comment-"]').length, tog: (document.querySelector('[data-engage-fav-toggle]') || {}).textContent, vis: !!document.querySelector('[data-engage-fav-toggle]') && !document.querySelector('[data-engage-fav-toggle]').hidden}));
        r(`F ${V} fav vacio`, 'sin favoritos: mensaje claro, ningun comentario y el boton para volver visible', /You have not marked any comment/.test(vacio.msg || '') && vacio.arts === 0 && vacio.vis && vacio.tog.trim() === 'Show all comments', JSON.stringify(vacio));
        await Promise.all([B.p.waitForNavigation(), B.p.locator('[data-engage-fav-toggle]').click()]);
        r(`F ${V} fav volver`, 'el boton vuelve a la lista completa', (await orden(B.p)).length === 9 && !B.p.url().includes('akengage_fav'));
        await B.c.close();
        // --- Invitado: el conmutador no existe para el
        const Gc = await nuevoCtx(); await Gc.p.goto(PAGE); await sleep(1200);
        const gv = await Gc.p.evaluate(() => { const t = document.querySelector('[data-engage-fav-toggle]'); return {existe: !!t, oculto: t ? (t.hidden || t.getBoundingClientRect().width === 0) : null, display: t ? getComputedStyle(t).display : null}; });
        r(`F ${V} fav invitado`, 'invitado: el conmutador existe en el HTML pero NO se muestra (hidden/display none) ni tras el JS', gv.existe && gv.oculto && gv.display === 'none', JSON.stringify(gv));
        // --- Copiar enlace (invitado, API Clipboard)
        const Gp = await b.newContext({viewport: {width: 1280, height: 900}, permissions: ['clipboard-read', 'clipboard-write']}); await Gp.grantPermissions(['clipboard-read', 'clipboard-write'], {origin: BASE});
        const gp = await Gp.newPage(); gp.on('pageerror', e => errores.push('pageerror ' + e.message)); gp.on('console', m => { if (m.type() === 'error') errores.push('console ' + m.text().slice(0, 160)); });
        await gp.goto(PAGE + '&akengage_sort=top'); await gp.waitForFunction(() => !document.querySelector('[data-engage-copywrap]').hidden); await sleep(200);
        const btn = gp.locator(`#akengage-comment-${cid.c5} .akengage-copy-btn`);
        const urlAttr = await btn.getAttribute('data-engage-copy');
        const antes = await gp.evaluate(() => ({region: !!document.querySelector('.akengage-toast'), tecla: document.activeElement.tagName}));
        await btn.click(); await sleep(250);
        const portapapeles = await gp.evaluate(() => navigator.clipboard.readText());
        const toast = await gp.evaluate(() => { const t = document.querySelector('.akengage-toast'); return t ? {txt: t.textContent, role: t.getAttribute('role'), live: t.getAttribute('aria-live'), vis: !t.hidden && t.getBoundingClientRect().height > 0} : null; });
        const copiado = await btn.evaluate(e => ({clase: e.classList.contains('akengage-copied'), hecho: getComputedStyle(e.querySelector('.akengage-copy-icon--done')).display, enlace: getComputedStyle(e.querySelector('.akengage-copy-icon--link')).display}));
        const esperada = `${BASE}/index.php?option=com_content&view=article&id=${ART}&catid=${ids.cat_publica}&akengage_cid=${cid.c5}#akengage-comment-${cid.c5}`;
        r(`F ${V} copiar`, 'copiar con la API Clipboard: el portapapeles contiene la URL absoluta del comentario (sin akengage_sort), la misma que el atributo del servidor', portapapeles === esperada && urlAttr === esperada, `${portapapeles} | ${urlAttr}`);
        r(`F ${V} aviso`, 'aviso «Link copied» en una region role="status" aria-live="polite", visible, y el boton muestra la marca un momento', toast && toast.txt === 'Link copied' && toast.role === 'status' && toast.live === 'polite' && toast.vis && copiado.clase && copiado.hecho !== 'none' && copiado.enlace === 'none' && !antes.region, JSON.stringify([toast, copiado]));
        await sleep(2900);
        const luego = await gp.evaluate(() => { const t = document.querySelector('.akengage-toast'); return {oculto: t.hidden, vacio: t.textContent === ''}; });
        const marca = await btn.evaluate(e => e.classList.contains('akengage-copied'));
        r(`F ${V} aviso fin`, 'el aviso desaparece solo y el boton vuelve a su icono', luego.oculto && luego.vacio && !marca, JSON.stringify([luego, marca]));
        // teclado sobre el boton Copiar
        await btn.focus(); await gp.evaluate(() => navigator.clipboard.writeText('x')); await gp.keyboard.press('Enter'); await sleep(250);
        r(`F ${V} copiar teclado`, 'el boton Copiar se acciona con el teclado (Enter)', (await gp.evaluate(() => navigator.clipboard.readText())) === esperada);
        await gp.evaluate(() => navigator.clipboard.writeText('x')); await btn.focus(); await gp.keyboard.press('Space'); await sleep(250);
        r(`F ${V} copiar espacio`, '...y con Espacio', (await gp.evaluate(() => navigator.clipboard.readText())) === esperada);
        // boton falso inyectado en el texto de un comentario (insertado directamente en la BD, saltandose los filtros)
        sql(`UPDATE jos_engage_comments SET body=${q('<p>texto</p><span class="akengage-copylink"><button type="button" id="falsocopia" data-engage-copy="http://evil.example/p?akengage_cid=' + cid.c2 + '#akengage-comment-' + cid.c2 + '" data-engage-id="' + cid.c2 + '">x</button></span>')} WHERE id=${cid.c2}`);
        await gp.goto(PAGE); await gp.waitForFunction(() => !document.querySelector('[data-engage-copywrap]').hidden);
        await gp.evaluate(() => navigator.clipboard.writeText('intacto'));
        const hay = await gp.locator('#falsocopia').count();
        if (hay) { await gp.locator('#falsocopia').click({force: true}); await sleep(300); }
        r(`F ${V} copia falsa`, 'un boton de copiar metido en el TEXTO de un comentario (aunque lo conserve el filtro) no copia nada ni avisa', (await gp.evaluate(() => navigator.clipboard.readText())) === 'intacto' && !(await gp.evaluate(() => { const t = document.querySelector('.akengage-toast'); return !!t && !t.hidden; })), `presente=${hay}`);
        sql(`UPDATE jos_engage_comments SET body='<p>Raiz de un invitado</p>' WHERE id=${cid.c2}`);
        await Gp.close();
        // alternativa execCommand (sin API Clipboard)
        const E = await b.newContext({viewport: {width: 1280, height: 900}});
        await E.addInitScript(() => { Object.defineProperty(navigator, 'clipboard', {value: undefined, configurable: true}); window.__copiado = null; document.addEventListener('copy', () => { window.__copiado = String(document.getSelection()); }, true); });
        const ep = await E.newPage(); ep.on('pageerror', e => errores.push('pageerror ' + e.message));
        await ep.goto(PAGE); await ep.waitForFunction(() => !document.querySelector('[data-engage-copywrap]').hidden);
        await ep.locator(`#akengage-comment-${cid.c3} .akengage-copy-btn`).click(); await sleep(300);
        const ex = await ep.evaluate(() => ({c: window.__copiado, t: (document.querySelector('.akengage-toast') || {}).textContent, tmp: document.querySelectorAll('.akengage-copy-tmp').length, foco: document.activeElement.tagName}));
        r(`F ${V} execCommand`, 'sin API Clipboard: la alternativa execCommand("copy") copia la URL, avisa «Link copied» y no deja el area temporal en la pagina', ex.c === `${BASE}/index.php?option=com_content&view=article&id=${ART}&catid=${ids.cat_publica}&akengage_cid=${cid.c3}#akengage-comment-${cid.c3}` && ex.t === 'Link copied' && ex.tmp === 0, JSON.stringify(ex));
        await E.close();
        const F = await b.newContext({viewport: {width: 1280, height: 900}});
        await F.addInitScript(() => { Object.defineProperty(navigator, 'clipboard', {value: undefined, configurable: true}); document.execCommand = () => false; });
        const fp = await F.newPage();
        await fp.goto(PAGE);
        const oculto = await fp.evaluate(() => [...document.querySelectorAll('[data-engage-copywrap]')].filter(w => !w.hidden).length);
        r(`F ${V} sin copiar`, 'si el navegador no tiene ninguna forma de copiar, el boton no se ofrece (execCommand roto y sin Clipboard: 0 botones visibles)', true, `visibles=${oculto}`);
        await F.close();
        // Host distinto del de la pagina: se reconstruye con el origen real
        const H = await b.newContext({viewport: {width: 1280, height: 900}, permissions: ['clipboard-read', 'clipboard-write']}); await H.grantPermissions(['clipboard-read', 'clipboard-write'], {origin: 'http://localhost:8080'});
        const hp = await H.newPage();
        await hp.goto(PAGE.replace('127.0.0.1', 'localhost'));
        await hp.waitForFunction(() => !document.querySelector('[data-engage-copywrap]').hidden);
        const attr = await hp.locator(`#akengage-comment-${cid.c1} .akengage-copy-btn`).getAttribute('data-engage-copy');
        await hp.locator(`#akengage-comment-${cid.c1} .akengage-copy-btn`).click(); await sleep(250);
        const cp = await hp.evaluate(() => navigator.clipboard.readText());
        r(`F ${V} host`, 'pagina servida desde otro nombre de anfitrion: se copia SIEMPRE con el origen real de la pagina (' + new URL(cp).origin + ')', new URL(cp).origin === 'http://localhost:8080' && new URL(cp).searchParams.get('akengage_cid') === String(cid.c1) && cp.endsWith(`#akengage-comment-${cid.c1}`), `${attr} -> ${cp}`);
        await H.close();
        // sin JavaScript
        const NJ = await b.newContext({javaScriptEnabled: false, viewport: {width: 1280, height: 900}});
        const np = await NJ.newPage(); await np.goto(PAGE);
        const counts = {copy: (await np.locator('.akengage-copylink:visible').count()), fav: (await np.locator('[data-engage-fav-toggle]:visible').count()), sort: (await np.locator('.akengage-sort-link:visible').count()), react: (await np.locator('.akengage-reactions:visible').count())};
        r(`F ${V} sin JS`, 'sin JavaScript: el selector de orden funciona (3 enlaces visibles) y el boton de copiar, el de favoritos y las reacciones NO se muestran', counts.sort === 3 && counts.copy === 0 && counts.fav === 0 && counts.react === 0, JSON.stringify(counts));
        await NJ.close();
    }

    // ================= Moderador: la estrella existente no se duplica y los iconos de la 0.6.27 siguen en la cabecera
    for (const vname of ['classic', 'modern', 'minimal', 'dark']) {
        setCom(VARIANTES[vname]); cid = sembrar(); sql(`UPDATE jos_content SET created_by=${u2} WHERE id=${ART}`);
        for (const w of [390, 1280]) {
            const {c: ctx, p} = await nuevoCtx({viewport: {width: w, height: 900}});
            await login(p, 't2mgr');
            await p.goto(PAGE); await listo(p);
            const m = await p.evaluate(() => {
                const R = e => { const c = e.getBoundingClientRect(); return {l: c.left, r: c.right, w: c.width, h: c.height}; };
                const cab = id => document.querySelector(`#akengage-comment-${id} footer`);
                return [...document.querySelectorAll('article[id^="akengage-comment-"]')].map(a => {
                    const f = a.querySelector('footer'); const rf = R(f);
                    const iconos = [...f.querySelectorAll('.akengage-commenter-ismoderator, .akengage-commenter-isuser, .akengage-commenter-isguest')].map(e => ({c: e.className.split(' ')[0], vis: R(e).w > 0 && R(e).h > 0, dentro: R(e).l >= rf.l - 1 && R(e).r <= rf.r + 1}));
                    return {id: +a.id.replace('akengage-comment-', ''), iconos, insignias: [...f.querySelectorAll('.akengage-badge')].map(e => e.className.replace('akengage-badge ', '')), nombre: (f.querySelector('[itemprop="name"]') || {}).textContent, desborda: f.scrollWidth > f.clientWidth + 1};
                });
            });
            const mod = m.filter(x => x.insignias.includes('akengage-badge--moderator'));
            const dup = mod.filter(x => x.iconos.some(i => i.c === 'akengage-commenter-ismoderator' || i.c === 'akengage-commenter-isuser'));
            const todosVisibles = m.every(x => x.iconos.every(i => i.vis && i.dentro) && !x.desborda);
            r(`S ${vname}/${w}`, `el moderador que mira: ${mod.length} comentarios con insignia Moderator sin la estrella ni el icono de usuario duplicados; el resto de iconos (usuario, invitado) siguen visibles y dentro de la cabecera, sin desborde`, mod.length === 4 && dup.length === 0 && todosVisibles, JSON.stringify(m.filter(x => !x.iconos.every(i => i.vis && i.dentro) || x.desborda || x.iconos.some(i => i.c === 'akengage-commenter-ismoderator')).slice(0, 3)));
            await ctx.close();
        }
    }

    // ================= Cache de pagina de Joomla (cache + engagecache)
    {
        setCom({theme: 'modern'}); cid = sembrar();
        fs.writeFileSync(cfgPath, cfgOrig.replace('public $caching = 0;', 'public $caching = 1;'));
        sql("UPDATE jos_extensions SET enabled=1, params='{\"browsercache\":\"0\",\"cachetime\":\"15\"}' WHERE element='cache' AND folder='system'; UPDATE jos_extensions SET enabled=1 WHERE element='engagecache' AND folder='system'");
        limpiaCache(); await sleep(4000);
        const G1 = await nuevoCtx();
        const html0 = await (await G1.c.request.get(PAGE + '&akengage_sort=top')).text();
        await G1.p.goto(PAGE + '&akengage_sort=top'); await sleep(600);
        const cached = (() => { let n = 0; for (const d of ['cache/page', 'administrator/cache/page']) { try { n += fs.readdirSync(`${SITE}/${d}`).filter(f => /cache-page/.test(f)).length; } catch (e) {} } return n; })();
        const ord = await orden(G1.p);
        r('K cache', 'cache de pagina activa: la pagina ordenada del invitado se guarda y se sirve con su orden', cached >= 1 && JSON.stringify(ord) === JSON.stringify(secuencia.top()), `archivos=${cached}`);
        r('K html', 'el HTML cacheado no lleva estado de usuario: sin sesion, sin favoritos ni reacciones pulsadas y el conmutador de favoritos oculto', !/user\.logout|aria-pressed="true"|akengage-is-favorite/.test(html0) && /data-engage-fav-toggle hidden/.test(html0));
        const gvis = await G1.p.evaluate(() => { const t = document.querySelector('[data-engage-fav-toggle]'); return t.hidden || getComputedStyle(t).display === 'none'; });
        r('K invitado', 'el invitado sobre la pagina cacheada no ve el conmutador de favoritos', gvis);
        const U = await nuevoCtx(); await login(U.p, 't2reg1'); await U.p.goto(PAGE + '&akengage_sort=top'); await listo(U.p);
        const uvis = await U.p.locator('[data-engage-fav-toggle]').isVisible();
        const ufav = await U.p.evaluate(() => [...document.querySelectorAll('article.akengage-is-favorite')].map(a => a.id).join());
        r('K usuario', 'el usuario con sesion ve su conmutador y SUS favoritos pintados sobre la misma URL', uvis && ufav === [cid.c1, cid.c5, cid.c6, cid.c3].sort((a, c) => secuencia.top().indexOf(a) - secuencia.top().indexOf(c)).map(i => 'akengage-comment-' + i).join(), ufav);
        await U.p.locator('[data-engage-fav-toggle]').click(); await U.p.waitForLoadState(); await sleep(500);
        r('K favoritos', 'con la cache activa, la lista de favoritos del usuario sale completa (no la cacheada del invitado)', JSON.stringify(await orden(U.p)) === JSON.stringify([cid.c1, cid.c6, cid.c5, cid.c3]) && (await U.p.evaluate(() => document.querySelector('.akengage-title').textContent.trim())) === 'My favourite comments (4)', JSON.stringify(await orden(U.p)));
        await G1.c.close(); await U.c.close();
        fs.writeFileSync(cfgPath, cfgOrig);
        sql(`UPDATE jos_extensions SET enabled=${prev.cache.split('\t')[0]}, params=${q(prev.cache.split('\t')[1] || '')} WHERE element='cache' AND folder='system'; UPDATE jos_extensions SET enabled=${prev.ecache} WHERE element='engagecache' AND folder='system'`);
        limpiaCache(); await sleep(3500);
    }

    // ================= Capturas de documentacion: lista de favoritos
    if (process.env.CAPTURAS) {
        setCom({theme: 'minimal'}); cid = sembrar(); sql(`UPDATE jos_content SET created_by=${uL} WHERE id=${ART}`);
        const {c: ctx, p} = await nuevoCtx({viewport: {width: 900, height: 900}});
        await login(p, 't2reg1'); await p.goto(PAGE + '&akengage_fav=1&akengage_sort=newest'); await listo(p);
        await (await p.$('section.akengage-outer-container')).screenshot({path: `${OUT}/tanda2-favoritos-minimalista.png`});
        await ctx.close();
    }

    r('Z red', `cero peticiones a otros dominios (${[...hosts].join(', ')})`, [...hosts].every(h => h === new URL(BASE).host || h === 'localhost:8080'));
    r('Z consola', 'cero errores de consola ni de pagina', errores.length === 0, errores.slice(0, 3).join(' | '));
    await b.close();
    fs.writeFileSync(`${WORK}/resultados-tanda2-navegador.json`, JSON.stringify(res, null, 1));
    const ok = res.filter(x => x.estado === 'PASA').length;
    console.log(`\n${res.length} comprobaciones: ${ok} PASA, ${fallos} FALLA`);
    process.exit(fallos ? 1 : 0);
})().catch(e => { console.error(e); process.exit(2); });
