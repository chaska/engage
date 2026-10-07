/**
 * 0.7.0: REACCIONES (me gusta, no me gusta, favorito) en un NAVEGADOR real (Chromium + Playwright) sobre el Joomla de pruebas.
 *
 * MATRIZ: 5 variantes (Clasico, Clasico con "Cargar CSS personalizado", Moderno, Minimalista, Oscuro) x pagina clara/oscura (con el modo claro/oscuro
 * del dispositivo emulado y una plantilla que fuerza el color de la pagina) x anchos 390 / 768 / 1280, como usuario Registered, en un hilo con
 * respuestas anidadas, un favorito propio y reacciones de otras personas:
 *   - la botonera esta en la fila de «Responder» (a su derecha o, si no cabe, debajo), sin solapes entre botones, contadores y Responder, sin desbordes
 *     horizontales de la pagina, de la fila ni del comentario;
 *   - area tactil >= 40 px por debajo de 576 px (>= 34 px en escritorio);
 *   - contorno si aria-pressed="false" y relleno si "true" (fill calculado del SVG); estrella pulsada amarilla;
 *   - contadores correctos; comentario favorito: clase akengage-is-favorite y cuerpo amarillo suave con contraste >= 4,5:1 MEDIDO en todo su texto;
 *   - contraste >= 3:1 de los iconos contra su fondo; ningun estilo en linea en la botonera.
 * FUNCIONAL (Clasico y Moderno): teclado (Tab, Espacio, Enter, foco visible), lector (rol, aria-label, aria-pressed, aria-live, pista accesible de los
 *   deshabilitados), persistencia en la BD y tras recargar, comentario propio, invitado (botones deshabilitados con pista, contadores visibles, el clic no
 *   escribe), sin JavaScript (botonera oculta), movimiento reducido, cache de pagina de Joomla activada (el HTML en cache es generico; el estado llega por
 *   AJAX), opciones (desactivar todo, sin no me gusta, sin favoritos, solo quien puede comentar), cero peticiones a otros hosts y cero errores de consola.
 * Entorno: BASE_URL, WORK, IDS_FILE, DB_NAME, PW_MODULE, CHROMIUM, OUT (capturas). Contrasenas en $WORK (no en el repositorio).
 */
const {execFileSync} = require('child_process');
const fs = require('fs');
const crypto = require('crypto');
const {chromium} = require(process.env.PW_MODULE || 'playwright');
const BASE = process.env.BASE_URL || 'http://127.0.0.1:8080';
const WORK = process.env.WORK;
const OUT = process.env.OUT || `${WORK}/reacciones-capturas`;
const DB = process.env.DB_NAME || 'joomla_test';
const SITE = `${WORK}/${process.env.SITE_DIR || 'site'}`;
const ids = JSON.parse(fs.readFileSync(`${WORK}/${process.env.IDS_FILE || 'ids.json'}`, 'utf8'));
const AS = ids.art_publico_asset;
const PAGE = `${BASE}/index.php?option=com_content&view=article&id=${ids.art_publico}&catid=${ids.cat_publica}`;
const passFile = `${WORK}/reaccionespass.txt`;
if (!fs.existsSync(passFile)) fs.writeFileSync(passFile, 'Aa1!' + crypto.randomBytes(10).toString('hex'), {mode: 0o600});
const PASS = fs.readFileSync(passFile, 'utf8').trim();
const res = [];
let fallos = 0;
function r(id, desc, ok, ev = '') { res.push({id, desc, estado: ok ? 'PASA' : 'FALLA', ev}); if (!ok) fallos++; if (!ok || process.env.VERBOSO) console.log(`${id.padEnd(34)} ${ok ? 'PASA ' : 'FALLA'} ${desc}${ev ? '  [' + ev + ']' : ''}`); }
function sql(s) { return execFileSync('mysql', ['-uroot', '--raw', '--default-character-set=utf8mb4', '-N', DB, '-e', s]).toString().trim(); }
const q = s => `'${String(s).replace(/\\/g, '\\\\').replace(/'/g, "''")}'`;
fs.mkdirSync(OUT, {recursive: true});
const sleep = ms => new Promise(f => setTimeout(f, ms));

const prev = {com: sql("SELECT IFNULL(params,'') FROM jos_extensions WHERE element='com_engage' AND type='component'"), rules: sql("SELECT rules FROM jos_assets WHERE name='com_engage'"),
    cache: sql("SELECT enabled, IFNULL(params,'') FROM jos_extensions WHERE element='cache' AND folder='system'")};
const cfgPath = `${SITE}/configuration.php`;
const cfgOrig = fs.readFileSync(cfgPath, 'utf8');
const restaurar = () => {
    try { sql(`UPDATE jos_extensions SET params=${q(prev.com)} WHERE element='com_engage' AND type='component'`); } catch (e) {}
    try { sql(`UPDATE jos_assets SET rules=${q(prev.rules)} WHERE name='com_engage'`); } catch (e) {}
    try { const [en, pr] = prev.cache.split('\t'); sql(`UPDATE jos_extensions SET enabled=${en}, params=${q(pr || '')} WHERE element='cache' AND folder='system'`); } catch (e) {}
    try { fs.writeFileSync(cfgPath, cfgOrig); } catch (e) {}
    try { sql(`DELETE FROM jos_engage_reactions; DELETE FROM jos_engage_comments WHERE asset_id=${AS}`); } catch (e) {}
};
process.on('exit', restaurar);
const setCom = (extra = {}) => sql(`UPDATE jos_extensions SET params=${q(JSON.stringify({default_publish: '1', max_level: '3', comments_ordering: 'asc', ...extra}))} WHERE element='com_engage' AND type='component'`);

// usuarios
const mk = (u, g) => {
    const hash = execFileSync('php', ['-r', 'echo password_hash($argv[1], PASSWORD_BCRYPT);', PASS]).toString();
    let id = sql(`SELECT id FROM jos_users WHERE username='${u}'`);
    if (!id) { sql(`INSERT INTO jos_users (name,username,email,password,block,sendEmail,registerDate,activation,params,resetCount,otpKey,otep,requireReset,authProvider) VALUES ('${u}','${u}','${u}@example.invalid','${hash}',0,0,NOW(),'','{}',0,'','',0,''); INSERT INTO jos_user_usergroup_map (user_id,group_id) SELECT id,${g} FROM jos_users WHERE username='${u}'`); id = sql(`SELECT id FROM jos_users WHERE username='${u}'`); }
    else sql(`UPDATE jos_users SET password='${hash}', block=0 WHERE id=${id}`);
    return +id;
};
const uA = mk('reacreg1', 2), uB = mk('reacreg2', 2), uC = mk('reacreg3', 2), uM = mk('reacmgr', 6);

// hilo
const ins = (parent, body, name, by, enabled = 1) => +sql(`INSERT INTO jos_engage_comments (asset_id,parent_id,body,name,email,ip,user_agent,enabled,created,created_by) VALUES (${AS},${parent},${q('<p>' + body + '</p>')},${by ? 'NULL' : q(name)},${by ? 'NULL' : q(name.toLowerCase() + '@example.invalid')},'203.0.113.7','t',${enabled},'2026-10-01 10:00:00',${by}); SELECT LAST_INSERT_ID()`);
function sembrar() {
    sql(`DELETE FROM jos_engage_reactions; DELETE FROM jos_engage_comments WHERE asset_id=${AS}`);
    const c = {};
    c.m = ins('NULL', 'Comentario del manager con un <a href="https://ejemplo.example/enlace">enlace</a> y <code>codigo</code>. Texto algo largo para varias lineas en movil.', '', uM);
    c.g = ins(c.m, 'Respuesta de un invitado', 'Invitado', 0);
    c.b = ins(c.g, 'Respuesta de nivel tres de reacreg2', '', uB);
    c.a = ins('NULL', 'Comentario propio de reacreg1', '', uA);
    c.c = ins('NULL', 'Comentario de reacreg3', '', uC);
    c.pen = ins('NULL', 'Comentario sin publicar', 'Pendiente', 0, 0);
    const re = (cm, u, t) => sql(`INSERT INTO jos_engage_reactions (comment_id,user_id,type,created) VALUES (${cm},${u},${t},NOW())`);
    re(c.m, uA, 1); re(c.m, uB, 1); re(c.m, uC, 1);            // 3 me gusta en el del manager (uno de A)
    re(c.b, uA, 3);                                            // favorito de A en el de nivel 3
    re(c.c, uA, 2); re(c.c, uB, 1);                            // A: no me gusta al de C; B: me gusta
    re(c.g, uB, 3);                                            // favorito de B (privado: A no lo ve)
    return c;
}

const PAGINAS = {clara: 'html,body{background:#fff;color:#222}', oscura: 'html,body{background:#1a1a1a;color:#f1f1f1 !important}'};
const VARIANTES = {classic: {theme: 'classic'}, 'classic+css': {theme: 'classic', loadCustomCss: '1'}, modern: {theme: 'modern'}, minimal: {theme: 'minimal'}, dark: {theme: 'dark'}};

const medir = (arg) => {
    const parse = c => { let m = c.match(/color\(srgb ([^)]+)\)/); if (m) { const p = m[1].split(/[ \/]+/).filter(Boolean).map(Number); return {r: p[0] * 255, g: p[1] * 255, b: p[2] * 255, a: p.length > 3 ? p[3] : 1}; } m = c.match(/rgba?\(([^)]+)\)/); if (!m) return null; const p = m[1].split(/[ ,\/]+/).filter(Boolean).map(Number); return {r: p[0], g: p[1], b: p[2], a: p.length > 3 ? p[3] : 1}; };
    const over = (f, b) => ({r: f.r * f.a + b.r * (1 - f.a), g: f.g * f.a + b.g * (1 - f.a), b: f.b * f.a + b.b * (1 - f.a), a: 1});
    const lum = c => { const f = v => { v /= 255; return v <= .03928 ? v / 12.92 : Math.pow((v + .055) / 1.055, 2.4); }; return .2126 * f(c.r) + .7152 * f(c.g) + .0722 * f(c.b); };
    const ratio = (a, b) => { const x = lum(a), y = lum(b); return (Math.max(x, y) + .05) / (Math.min(x, y) + .05); };
    const fondo = el => { const capas = []; for (let e = el; e; e = e.parentElement) { const c = parse(getComputedStyle(e).backgroundColor); if (c && c.a > 0) { capas.push(c); if (c.a >= 1) break; } } let b = {r: 255, g: 255, b: 255, a: 1}; for (let i = capas.length - 1; i >= 0; i--) b = over(capas[i], b); return b; };
    const R = e => { const c = e.getBoundingClientRect(); return {l: c.left, t: c.top, r: c.right, b: c.bottom, w: c.width, h: c.height}; };
    const solapa = (a, b) => Math.min(a.r, b.r) - Math.max(a.l, b.l) > .5 && Math.min(a.b, b.b) - Math.max(a.t, b.t) > .5;
    const vw = document.documentElement.clientWidth;
    const out = {articulos: 0, botoneras: 0, solapes: [], desbordes: [], fuera: [], pequenos: [], posicion: [], relleno: [], contraste: [], iconos: [], favoritos: [], conteo: {}, estilosEnLinea: 0, mitad: [], hiddenVisibles: 0};
    const sec = document.querySelector('section.akengage-outer-container');
    if (document.documentElement.scrollWidth > vw + 1) out.desbordes.push(`pagina ${document.documentElement.scrollWidth}>${vw}`);
    for (const art of sec.querySelectorAll('article[id^="akengage-comment-"]')) {
        out.articulos++;
        const id = art.id.replace('akengage-comment-', '');
        const row = art.querySelector('.akengage-comment-reply');
        const grp = art.querySelector('.akengage-reactions');
        if (!grp) continue;
        if (grp.hidden || getComputedStyle(grp).display === 'none') { out.hiddenVisibles++; continue; }
        out.botoneras++;
        const rr = R(row), gr = R(grp), reply = row.querySelector('.akengage-comment-reply-btn');
        if (row.scrollWidth > row.clientWidth + 1) out.desbordes.push(`#${id} fila desborda`);
        if (gr.r > vw + 1 || gr.l < -1) out.desbordes.push(`#${id} botonera fuera del viewport`);
        if (gr.l < rr.l - 1 || gr.r > rr.r + 1) out.fuera.push(`#${id} botonera fuera de su fila`);
        const ar = R(art); if (gr.r > ar.r + 1) out.fuera.push(`#${id} botonera fuera del comentario`);
        const piezas = [...grp.querySelectorAll('button, .akengage-react-count')].map(e => ({e, c: R(e)})).filter(p => p.c.w > 0);
        if (reply) piezas.push({e: reply, c: R(reply)});
        for (let i = 0; i < piezas.length; i++) for (let j = i + 1; j < piezas.length; j++) if (solapa(piezas[i].c, piezas[j].c)) out.solapes.push(`#${id} ${piezas[i].e.className.toString().split(' ').slice(0, 2).join('.')} x ${piezas[j].e.className.toString().split(' ').slice(0, 2).join('.')}`);
        if (reply) { const rp = R(reply); const mismaFila = Math.abs((rp.t + rp.b) / 2 - (gr.t + gr.b) / 2) < Math.max(rp.h, gr.h); out.posicion.push({id, mismaFila, derecha: gr.l >= rp.r - 1, debajo: gr.t >= rp.b - 1, margenDcha: Math.round(rr.r - gr.r)}); }
        else out.posicion.push({id, mismaFila: true, derecha: true, debajo: false, margenDcha: Math.round(rr.r - gr.r)});
        out.estilosEnLinea += grp.querySelectorAll('[style]').length + (grp.hasAttribute('style') ? 1 : 0);
        for (const b of grp.querySelectorAll('button')) {
            const c = R(b); const min = vw < 576 ? 40 : 34;
            if (Math.min(c.w, c.h) < min - .6) out.pequenos.push(`#${id} ${b.dataset.engageReact} ${Math.round(c.w)}x${Math.round(c.h)}`);
            const sv = b.querySelector('svg'); const fill = getComputedStyle(sv).fill; const pressed = b.getAttribute('aria-pressed') === 'true';
            const vacio = fill === 'none' || /rgba\(0, 0, 0, 0\)/.test(fill);
            out.relleno.push({id, tipo: b.dataset.engageReact, pressed, vacio, fill, stroke: getComputedStyle(sv).stroke});
            // contraste del icono (trazo) contra el fondo
            const bg = fondo(b); const st = parse(getComputedStyle(sv).stroke) || parse(getComputedStyle(b).color);
            const fl = parse(getComputedStyle(sv).fill);
            let rt = st ? ratio(over(st, bg), bg) : 0;
            if (pressed && fl && fl.a > 0) rt = Math.max(rt, ratio(over(fl, bg), bg)); // pulsado: se distingue por el relleno o por el contorno
            out.iconos.push({id, tipo: b.dataset.engageReact, pressed, ratio: Math.round(rt * 100) / 100});
        }
        for (const cnt of grp.querySelectorAll('.akengage-react-count')) { (out.conteo[id] = out.conteo[id] || {})[cnt.dataset.engageCount] = cnt.textContent; }
        // contador visible junto a su boton
        const fav = art.classList.contains('akengage-is-favorite');
        if (fav) {
            const body = art.querySelector('.akengage-comment-body'); const bb = parse(getComputedStyle(body).backgroundColor); const eff = fondo(body);
            const rec = {id, bg: getComputedStyle(body).backgroundColor, eff: [Math.round(eff.r), Math.round(eff.g), Math.round(eff.b)], textos: []};
            // el amarillo debe ser amarillo: R y G altos, B mas bajo
            rec.amarillo = eff.r > 200 && eff.g > 150 && eff.b < eff.r - 15 || (eff.r > 55 && eff.g > 40 && eff.b < eff.g - 10 && eff.r > eff.b + 15);
            const w = document.createTreeWalker(body, NodeFilter.SHOW_TEXT);
            for (let n; (n = w.nextNode());) { const t = n.textContent.trim(); if (!t) continue; const el = n.parentElement; const fg = parse(getComputedStyle(el).color); const bg2 = fondo(el); rec.textos.push({t: t.slice(0, 24), ratio: Math.round(ratio(over(fg, bg2), bg2) * 100) / 100}); }
            out.favoritos.push(rec);
        }
        out.mitad.push({id, fav, claseArt: art.className.split(' ').filter(c => c.startsWith('akengage-is')).join(' ')});
    }
    return out;
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
        p.on('request', rq => { try { hosts.add(new URL(rq.url()).host); } catch (e) {} });
        return {c, p};
    };
    const login = async (p, user) => {
        await p.goto(`${BASE}/index.php?option=com_users&view=login`);
        await p.fill('#username', user); await p.fill('#password', PASS);
        await Promise.all([p.waitForNavigation(), p.locator('form button[type=submit]').first().click()]);
    };
    const listo = async (p, n) => { await p.waitForFunction(k => document.querySelectorAll('[data-engage-reactions]:not([hidden])').length >= k, n, {timeout: 15000}); await sleep(250); };
    const cid = {};

    // ================= MATRIZ
    const c = sembrar(); Object.assign(cid, c);
    const PUBLICADOS = 5;
    let combos = 0, peor = {};
    for (const [vname, vp] of Object.entries(VARIANTES)) {
        setCom(vp);
        for (const [esq, css] of [['claro', PAGINAS.clara], ['oscuro', PAGINAS.oscura]]) {
            const {c: ctx, p} = await nuevoCtx({colorScheme: esq === 'claro' ? 'light' : 'dark'});
            await login(p, 'reacreg1');
            for (const w of [390, 768, 1280]) {
                await p.setViewportSize({width: w, height: 1000});
                await p.goto(PAGE); await p.addStyleTag({content: css});
                await listo(p, PUBLICADOS);
                const m = await p.evaluate(medir);
                const k = `${vname}/${esq}/${w}`; combos++;
                r(`M ${k} botoneras`, 'una botonera por comentario publicado, ninguna oculta (el sin publicar no se ve a un Registered)', m.botoneras === PUBLICADOS && m.hiddenVisibles === 0 && m.articulos === 5, `botoneras=${m.botoneras} articulos=${m.articulos}`);
                r(`M ${k} solapes`, 'sin solapes entre botones, contadores y Responder; sin desbordes ni elementos fuera de su fila', !m.solapes.length && !m.desbordes.length && !m.fuera.length, [...m.solapes, ...m.desbordes, ...m.fuera].slice(0, 3).join('; '));
                r(`M ${k} posicion`, 'en la fila de Responder: a su derecha o, si no cabe, debajo; pegada al margen derecho', m.posicion.every(x => (x.mismaFila && x.derecha) || x.debajo) && m.posicion.every(x => x.margenDcha >= -1 && x.margenDcha <= 40), JSON.stringify(m.posicion.filter(x => !((x.mismaFila && x.derecha) || x.debajo) || x.margenDcha > 40).slice(0, 2)));
                if (w >= 768) r(`M ${k} misma fila`, 'con anchura suficiente la botonera comparte linea con Responder', m.posicion.filter(x => x.id != cid.a).every(x => x.mismaFila && x.derecha), JSON.stringify(m.posicion.filter(x => !x.mismaFila).slice(0, 2)));
                r(`M ${k} tactil`, w < 576 ? 'area tactil >= 40 px en movil' : 'botones >= 34 px', !m.pequenos.length, m.pequenos.slice(0, 3).join('; '));
                r(`M ${k} relleno`, 'contorno (fill none) si no esta pulsado y relleno si lo esta; estrella pulsada amarilla', m.relleno.every(x => x.pressed ? !x.vacio : x.vacio), JSON.stringify(m.relleno.filter(x => x.pressed ? x.vacio : !x.vacio).slice(0, 2)));
                const est = m.relleno.find(x => x.tipo === 'favorite' && x.pressed); const rgb = est && (est.fill.match(/\d+/g) || []).map(Number);
                r(`M ${k} estrella`, 'la estrella pulsada tiene relleno amarillo', !!rgb && rgb[0] > 200 && rgb[1] > 150 && rgb[2] < 120, est ? est.fill : 'sin estrella pulsada');
                r(`M ${k} contadores`, `contadores: ${JSON.stringify(m.conteo[cid.m])} y ${JSON.stringify(m.conteo[cid.c])}`, m.conteo[cid.m]?.like === '3' && m.conteo[cid.m]?.dislike === '0' && m.conteo[cid.c]?.like === '1' && m.conteo[cid.c]?.dislike === '1' && m.conteo[cid.b]?.like === '0');
                r(`M ${k} favorito`, 'solo el favorito de ESTE usuario (el de nivel 3) lleva akengage-is-favorite; el de B no se ve', m.mitad.filter(x => x.fav).map(x => x.id).join() === String(cid.b) && m.favoritos.length === 1);
                const f = m.favoritos[0]; const minT = f ? Math.min(...f.textos.map(t => t.ratio)) : 0;
                r(`M ${k} amarillo`, `cuerpo del favorito en amarillo suave (${f ? f.bg : '?'}) y contraste del texto >= 4,5:1 (minimo medido ${minT} sobre ${f ? f.textos.length : 0} textos)`, !!f && f.amarillo && minT >= 4.5, f ? JSON.stringify(f.textos.filter(t => t.ratio < 4.5)) : '');
                if (f) { const key = `${vname}|${esq}`; if (!(key in peor) || minT < peor[key]) peor[key] = minT; }
                const minI = Math.min(...m.iconos.map(i => i.ratio));
                r(`M ${k} iconos`, `contraste de los iconos contra su fondo >= 3:1 (minimo ${minI})`, minI >= 3, JSON.stringify(m.iconos.filter(i => i.ratio < 3).slice(0, 3)));
                r(`M ${k} csp`, 'ningun estilo en linea en la botonera (CSP)', m.estilosEnLinea === 0);
                if (process.env.CAPTURAS && w === 1280 && esq === 'claro' && vname === 'modern') await (await p.$('section.akengage-outer-container')).screenshot({path: `${OUT}/matriz-${vname}-${esq}-${w}.png`});
            }
            await ctx.close();
        }
    }
    console.log(`matriz: ${combos} combinaciones; peor contraste del texto del favorito por variante/modo: ${JSON.stringify(peor)}`);

    // ================= FUNCIONAL (teclado, lector, persistencia)
    setCom({theme: 'classic'});
    Object.assign(cid, sembrar());
    {
        const {c: ctx, p} = await nuevoCtx();
        await login(p, 'reacreg1');
        await p.goto(PAGE); await listo(p, PUBLICADOS);
        const art = id => p.locator(`#akengage-comment-${id}`);
        const btn = (id, t) => p.locator(`#akengage-comment-${id} button[data-engage-react="${t}"]`);
        r('F roles', 'cada botonera es role="group" con aria-label; cada boton <button type="button"> con aria-label y aria-pressed', await p.evaluate(() => [...document.querySelectorAll('[data-engage-reactions]')].every(g => g.getAttribute('role') === 'group' && g.getAttribute('aria-label') && [...g.querySelectorAll('button')].every(b => b.type === 'button' && b.getAttribute('aria-label') && ['true', 'false'].includes(b.getAttribute('aria-pressed'))))));
        r('F etiquetas', 'aria-label en ingles del sitio: Like / Dislike / Favorite', (await btn(cid.m, 'like').getAttribute('aria-label')) === 'Like' && (await btn(cid.m, 'dislike').getAttribute('aria-label')) === 'Dislike' && (await btn(cid.m, 'favorite').getAttribute('aria-label')) === 'Favorite');
        r('F aria-live', 'los contadores son aria-live="polite"', await p.evaluate(() => [...document.querySelectorAll('.akengage-react-count')].every(c => c.getAttribute('aria-live') === 'polite')));
        r('F propio', 'comentario propio: me gusta y no me gusta deshabilitados con pista accesible (aria-describedby); favorito habilitado', await btn(cid.a, 'like').isDisabled() && await btn(cid.a, 'dislike').isDisabled() && !(await btn(cid.a, 'favorite').isDisabled()) && await p.evaluate(id => { const b = document.querySelector(`#akengage-comment-${id} button[data-engage-react="like"]`); const h = document.getElementById(b.getAttribute('aria-describedby') || 'x'); return !!h && h.textContent.length > 10; }, cid.a));
        // teclado sobre el comentario de C: A tiene no me gusta; Tab hasta su boton "me gusta"
        const objetivo = `#akengage-comment-${cid.c} button[data-engage-react="like"]`;
        await p.evaluate(() => { document.activeElement && document.activeElement.blur(); window.scrollTo(0, 0); });
        let alcanzado = false, tabs = 0;
        for (; tabs < 120 && !alcanzado; tabs++) { await p.keyboard.press('Tab'); alcanzado = await p.evaluate(s => document.activeElement === document.querySelector(s), objetivo); }
        r('F tab', `con Tab se llega al boton «me gusta» del comentario (tras ${tabs} pulsaciones) y el orden pasa por me gusta, no me gusta y favorito`, alcanzado);
        const foco = await p.evaluate(s => { const e = document.querySelector(s); const cs = getComputedStyle(e); return {estilo: cs.outlineStyle, ancho: parseFloat(cs.outlineWidth), visible: e.matches(':focus-visible')}; }, objetivo);
        r('F foco', 'el foco por teclado es visible (outline)', foco.visible && foco.estilo !== 'none' && foco.ancho >= 2, JSON.stringify(foco));
        await p.keyboard.press('Space'); await sleep(500);
        let s = await p.evaluate(id => [...document.querySelectorAll(`#akengage-comment-${id} button[data-engage-react]`)].map(b => b.dataset.engageReact + '=' + b.getAttribute('aria-pressed')).join(), cid.c);
        r('F espacio', 'Espacio pulsa me gusta: aria-pressed true y el no me gusta pasa a false (excluyentes)', s === 'like=true,dislike=false,favorite=false', s);
        r('F bd1', 'persistido en la BD (me gusta de A en el comentario de C, sin su no me gusta anterior)', sql(`SELECT GROUP_CONCAT(type) FROM jos_engage_reactions WHERE comment_id=${cid.c} AND user_id=${uA}`) === '1');
        const cont = await p.evaluate(id => document.querySelector(`#akengage-comment-${id} .akengage-react-count[data-engage-count="like"]`).textContent + '/' + document.querySelector(`#akengage-comment-${id} .akengage-react-count[data-engage-count="dislike"]`).textContent, cid.c);
        r('F contador', 'los contadores se actualizan (2 me gusta / 0 no me gusta)', cont === '2/0', cont);
        await p.keyboard.press('Enter'); await sleep(500);
        s = await p.evaluate(id => [...document.querySelectorAll(`#akengage-comment-${id} button[data-engage-react]`)].map(b => b.dataset.engageReact + '=' + b.getAttribute('aria-pressed')).join(), cid.c);
        r('F enter', 'Enter vuelve a pulsar: lo quita (todo a false)', s === 'like=false,dislike=false,favorite=false' && sql(`SELECT COUNT(*) FROM jos_engage_reactions WHERE comment_id=${cid.c} AND user_id=${uA}`) === '0', s);
        // favorito con el raton + recarga
        await btn(cid.m, 'favorite').click(); await sleep(500);
        r('F favorito', 'marcar favorito: aria-pressed true, el articulo toma akengage-is-favorite y se guarda', (await btn(cid.m, 'favorite').getAttribute('aria-pressed')) === 'true' && (await art(cid.m).getAttribute('class')).includes('akengage-is-favorite') && sql(`SELECT COUNT(*) FROM jos_engage_reactions WHERE comment_id=${cid.m} AND user_id=${uA} AND type=3`) === '1');
        await p.reload(); await listo(p, PUBLICADOS);
        r('F recarga', 'tras recargar siguen los dos favoritos de A (el de nivel 3 y el del manager) y ningun otro', (await p.evaluate(() => [...document.querySelectorAll('article.akengage-is-favorite')].map(a => a.id).sort().join())) === [`akengage-comment-${cid.m}`, `akengage-comment-${cid.b}`].sort().join());
        await btn(cid.m, 'favorite').click(); await sleep(500);
        r('F desmarcar', 'desmarcar el favorito quita la clase y la fila', !(await art(cid.m).getAttribute('class')).includes('akengage-is-favorite') && sql(`SELECT COUNT(*) FROM jos_engage_reactions WHERE comment_id=${cid.m} AND user_id=${uA} AND type=3`) === '0');
        // sin doble envio: clic rapido doble
        await btn(cid.c, 'like').dblclick(); await sleep(700);
        r('F doble clic', 'un doble clic rapido no deja filas duplicadas (0 o 1)', ['0', '1'].includes(sql(`SELECT COUNT(*) FROM jos_engage_reactions WHERE comment_id=${cid.c} AND user_id=${uA} AND type=1`)));
        sql(`DELETE FROM jos_engage_reactions WHERE comment_id=${cid.c} AND user_id=${uA}`);
        // HTML inyectado en un comentario: un boton falso dentro del TEXTO no reacciona (ni en nombre de otra persona)
        const peticiones = [];
        p.on('request', rq => { if (/reactions\.toggle/.test(rq.url())) peticiones.push(rq.url()); });
        await p.evaluate(id => {
            const cuerpo = document.querySelector(`#akengage-comment-${id} .akengage-comment-body`);
            const f = document.createElement('div'); f.setAttribute('data-engage-reactions', ''); f.className = 'akengage-reactions';
            f.innerHTML = '<button type="button" id="falso" class="akengage-react-btn" data-engage-react="like" data-engage-id="' + id + '" aria-pressed="false">x</button>';
            cuerpo.appendChild(f);
        }, cid.c);
        await p.locator('#falso').click(); await sleep(500);
        r('F falso', 'un boton con data-engage-* dentro del texto de un comentario (HTML inyectado) no envia ninguna reaccion', peticiones.length === 0 && sql(`SELECT COUNT(*) FROM jos_engage_reactions WHERE comment_id=${cid.c} AND user_id=${uA}`) === '0');
        await p.evaluate(([art, otro]) => { const f = document.createElement('div'); f.setAttribute('data-engage-reactions', ''); f.className = 'akengage-reactions'; f.innerHTML = '<button type="button" id="falso2" class="akengage-react-btn" data-engage-react="like" data-engage-id="' + otro + '">y</button>'; document.querySelector(`#akengage-comment-${art}`).appendChild(f); }, [cid.a, cid.c]);
        await p.locator('#falso2').click(); await sleep(500);
        r('F falso2', 'ni siquiera dentro del articulo si el id no es el de ese comentario', peticiones.length === 0);
        // peticion en red: POST con token; ninguna a otro host
        await ctx.close();
    }

    // ---- Invitado
    {
        const {c: ctx, p} = await nuevoCtx();
        await p.goto(PAGE); await listo(p, PUBLICADOS);
        const n0 = sql('SELECT COUNT(*) FROM jos_engage_reactions');
        const m = await p.evaluate(medir);
        const dis = await p.evaluate(() => [...document.querySelectorAll('[data-engage-reactions] button')].map(b => b.disabled));
        r('G botones', 'invitado: TODOS los botones deshabilitados, contadores visibles', dis.length === 15 && dis.every(Boolean) && m.conteo[cid.m]?.like === '3', `botones=${dis.length}`);
        r('G pista', 'invitado: pista accesible (aria-describedby -> texto "Log in to react")', await p.evaluate(id => { const b = document.querySelector(`#akengage-comment-${id} button[data-engage-react="like"]`); const h = document.getElementById(b.getAttribute('aria-describedby') || 'x'); return !!h && /log in/i.test(h.textContent); }, cid.m));
        await p.locator(`#akengage-comment-${cid.m} button[data-engage-react="like"]`).click({force: true}).catch(() => {}); await sleep(400);
        r('G clic', 'invitado: el clic no escribe nada', sql('SELECT COUNT(*) FROM jos_engage_reactions') === n0);
        r('G sin favorito', 'invitado: ningun favorito ni aria-pressed true (ni el de otros)', !(await p.evaluate(() => document.querySelector('article.akengage-is-favorite') || document.querySelector('[aria-pressed="true"]'))));
        await ctx.close();
    }

    // ---- Sin JavaScript
    {
        const {c: ctx, p} = await nuevoCtx({javaScriptEnabled: false});
        await p.goto(PAGE);
        const o = await p.evaluate(() => ({n: document.querySelectorAll('[data-engage-reactions]').length, vis: [...document.querySelectorAll('[data-engage-reactions]')].filter(g => getComputedStyle(g).display !== 'none').length, reply: document.querySelectorAll('.akengage-comment-reply-btn').length})).catch(() => null);
        if (o === null) { const h = await p.content(); const n = (h.match(/data-engage-reactions hidden/g) || []).length; r('J sin JS', 'sin JavaScript los botones no se ven (atributo hidden en el HTML) y Responder sigue', n === 5 && (await p.locator('[data-engage-reactions]').first().isHidden())); }
        else r('J sin JS', 'sin JavaScript los botones no se ven y Responder sigue', o.n === 5 && o.vis === 0 && o.reply > 0);
        await ctx.close();
    }

    // ---- Movimiento reducido y estilos en Moderno
    for (const tema of ['classic', 'modern']) {
        setCom({theme: tema});
        const {c: ctx, p} = await nuevoCtx({reducedMotion: 'reduce'});
        await login(p, 'reacreg1'); await p.goto(PAGE); await listo(p, PUBLICADOS);
        const d = await p.evaluate(() => { const b = document.querySelector('.akengage-react-btn'); const i = b.querySelector('svg'); return {t: getComputedStyle(b).transitionDuration, a: getComputedStyle(i).transitionDuration}; });
        r(`R ${tema}`, 'con prefers-reduced-motion no hay transiciones en los botones ni en los iconos', /^0s(, 0s)*$/.test(d.t) && /^0s(, 0s)*$/.test(d.a), JSON.stringify(d));
        await ctx.close();
    }
    {
        setCom({theme: 'classic'});
        const {c: ctx, p} = await nuevoCtx();
        await login(p, 'reacreg1'); await p.goto(PAGE); await listo(p, PUBLICADOS);
        const d = await p.evaluate(() => { const b = document.querySelector('.akengage-react-btn'); return {t: getComputedStyle(b).transitionDuration, a: getComputedStyle(b.querySelector('svg')).transitionDuration}; });
        r('R normal', 'sin esa preferencia hay una transicion suave', !/^0s(, 0s)*$/.test(d.t), JSON.stringify(d));
        await ctx.close();
    }

    // ---- Cache de pagina de Joomla
    {
        Object.assign(cid, sembrar()); setCom({theme: 'modern'});
        let cfg = cfgOrig.replace('public $caching = 0;', 'public $caching = 1;');
        fs.writeFileSync(cfgPath, cfg);
        sql("UPDATE jos_extensions SET enabled=1, params='{\"browsercache\":\"0\",\"cachetime\":\"15\"}' WHERE element='cache' AND folder='system'");
        execFileSync('rm', ['-rf', `${SITE}/cache/page`, `${SITE}/administrator/cache/page`]);
        const G1 = await nuevoCtx();
        await G1.p.goto(PAGE); await listo(G1.p, PUBLICADOS);
        const html1 = await (await G1.c.request.get(PAGE)).text(); // HTML tal como se sirve (sin ejecutar JavaScript)
        const resp = await G1.p.goto(PAGE); await listo(G1.p, PUBLICADOS);
        const hayCache = (() => { try { return execFileSync('sh', ['-c', `ls ${SITE}/cache/page ${SITE}/administrator/cache/page 2>/dev/null | wc -l`]).toString().trim() !== '0'; } catch (e) { return false; } })();
        r('K cache', 'la cache de pagina de Joomla esta activa y ha guardado la pagina del invitado', hayCache);
        const cont0 = await G1.p.evaluate(id => document.querySelector(`#akengage-comment-${id} .akengage-react-count[data-engage-count="dislike"]`).textContent, cid.m);
        // otra persona reacciona; el invitado recarga la pagina CACHEADA y ve el contador nuevo
        sql(`INSERT INTO jos_engage_reactions (comment_id,user_id,type,created) VALUES (${cid.m},${uC},2,NOW())`);
        await G1.p.reload(); await listo(G1.p, PUBLICADOS);
        const cont1 = await G1.p.evaluate(id => document.querySelector(`#akengage-comment-${id} .akengage-react-count[data-engage-count="dislike"]`).textContent, cid.m);
        r('K contador', 'el HTML cacheado no lleva contadores: el contador nuevo llega por AJAX (0 -> 1)', cont0 === '0' && cont1 === '1', `${cont0} -> ${cont1}`);
        r('K html', 'el HTML cacheado no tiene aria-pressed="true", contadores ni favoritos', !/aria-pressed="true"|akengage-is-favorite/.test(html1) && !/akengage-react-count"[^>]*>\s*[0-9]/.test(html1));
        // dos usuarios distintos ven SU estado sobre la misma pagina
        const UA = await nuevoCtx(); await login(UA.p, 'reacreg1'); await UA.p.goto(PAGE); await listo(UA.p, PUBLICADOS);
        const UB = await nuevoCtx(); await login(UB.p, 'reacreg2'); await UB.p.goto(PAGE); await listo(UB.p, PUBLICADOS);
        const fav = p => p.evaluate(() => [...document.querySelectorAll('article.akengage-is-favorite')].map(a => a.id).join());
        r('K usuarios', 'con la cache activa cada usuario ve SU favorito (A: el de nivel 3; B: el del invitado) sobre el mismo HTML', (await fav(UA.p)) === `akengage-comment-${cid.b}` && (await fav(UB.p)) === `akengage-comment-${cid.g}`, `${await fav(UA.p)} | ${await fav(UB.p)}`);
        const crudo = async c => { const h = await (await c.request.get(PAGE)).text(); return (h.match(/<div class="akengage-reactions"[\s\S]*?<\/div>/g) || []).join('|'); };
        const eA = await crudo(UA.c), eB = await crudo(UB.c), eG = await crudo(G1.c);
        r('K esqueleto', 'el esqueleto de la botonera servido es IDENTICO para el invitado y los dos usuarios (el estado lo pinta el JS)', eA.length > 500 && eA === eB && eB === eG, `${eA.length}/${eB.length}/${eG.length}`);
        await UA.c.close(); await UB.c.close(); await G1.c.close();
        fs.writeFileSync(cfgPath, cfgOrig);
        sql(`UPDATE jos_extensions SET enabled=${prev.cache.split('\t')[0]}, params=${q(prev.cache.split('\t')[1] || '')} WHERE element='cache' AND folder='system'`);
        await sleep(3500); // el servidor de pruebas (php -S con opcache) tarda ~2 s en releer configuration.php
        execFileSync('rm', ['-rf', `${SITE}/cache/page`, `${SITE}/administrator/cache/page`, `${SITE}/cache/_system`, `${SITE}/administrator/cache/_system`]);
    }

    // ---- Opciones
    {
        Object.assign(cid, sembrar());
        const ver = async (extra, usuario) => { setCom({theme: 'classic', ...extra}); const {c: ctx, p} = await nuevoCtx(); if (usuario) await login(p, usuario); await p.goto(PAGE); await sleep(1500); const o = await p.evaluate(() => ({g: document.querySelectorAll('.akengage-reactions').length, v: document.querySelectorAll('.akengage-reactions:not([hidden])').length, like: document.querySelectorAll('.akengage-react-like').length, dis: document.querySelectorAll('.akengage-react-dislike').length, fav: document.querySelectorAll('.akengage-react-favorite').length, js: !!document.querySelector('script[src*="reactions.js"]'), rep: document.querySelectorAll('.akengage-comment-reply--react').length, favs: document.querySelectorAll('.akengage-is-favorite').length, dis_: document.querySelectorAll('.akengage-reactions button:disabled').length})); await ctx.close(); return o; };
        let o = await ver({reactions_enabled: '0'}, 'reacreg1');
        r('P desactivadas', 'reactions_enabled=0: ni botoneras ni script ni fila nueva; no se pinta ningun favorito', o.g === 0 && !o.js && o.rep === 0 && o.favs === 0, JSON.stringify(o));
        o = await ver({reactions_dislike: '0'}, 'reacreg1');
        r('P sin dislike', 'reactions_dislike=0: solo me gusta y favorito', o.dis === 0 && o.like === 5 && o.fav === 5 && o.v === 5);
        o = await ver({reactions_favorites: '0'}, 'reacreg1');
        r('P sin favoritos', 'reactions_favorites=0: sin estrella y sin fondo amarillo aunque haya favoritos guardados', o.fav === 0 && o.dis === 5 && o.favs === 0, JSON.stringify(o));
        sql(`UPDATE jos_assets SET rules=${q('{"core.create":{"6":1},"core.edit.own":{"2":1},"core.edit.state":{"4":1},"core.delete":{"6":1}}')} WHERE name='com_engage'`);
        o = await ver({reactions_who: 'commenters'}, 'reacreg1');
        r('P commenters', 'reactions_who=commenters: un Registered sin permiso de comentar ve la botonera deshabilitada', o.v === 5 && o.dis_ === 15, JSON.stringify(o));
        o = await ver({reactions_who: 'commenters'}, 'reacmgr');
        r('P commenters-mgr', 'reactions_who=commenters: el Manager (puede comentar) tiene botones habilitados (excepto en lo propio)', o.v === 5 && o.dis_ <= 2, JSON.stringify(o));
        sql(`UPDATE jos_assets SET rules=${q(prev.rules)} WHERE name='com_engage'`);
        o = await ver({}, 'reacreg1');
        r('P defecto', 'con las opciones por defecto (sin las claves) todo esta activado', o.v === 5 && o.dis === 5 && o.fav === 5 && o.js);
    }

    // ================= CAPTURAS de la documentacion (CAPTURAS=1): docs/img/reacciones-*.png
    if (process.env.CAPTURAS) {
        Object.assign(cid, sembrar());
        const lista = async (nombre, vp, vp2, esq, css) => {
            setCom(vp2);
            const {c: ctx, p} = await nuevoCtx({colorScheme: esq, deviceScaleFactor: 1});
            await login(p, 'reacreg1'); await p.setViewportSize(vp); await p.goto(PAGE); await p.addStyleTag({content: css}); await listo(p, PUBLICADOS);
            await p.evaluate(() => { document.querySelector('.akengage-comment-reply-btn') && null; });
            await (await p.$('.akengage-list-container')).screenshot({path: `${OUT}/${nombre}.png`});
            await ctx.close();
        };
        await lista('reacciones-escritorio', {width: 980, height: 900}, {theme: 'modern'}, 'light', PAGINAS.clara);
        await lista('reacciones-movil-oscuro', {width: 390, height: 900}, {theme: 'dark'}, 'dark', PAGINAS.oscura);
        await lista('reacciones-clasico', {width: 640, height: 900}, {theme: 'classic'}, 'light', PAGINAS.clara);
    }

    r('Z hosts', `ninguna peticion a otros hosts (${[...hosts].join(', ')})`, [...hosts].every(h => h === new URL(BASE).host));
    r('Z consola', 'cero errores de JavaScript ni de consola', errores.length === 0, errores.slice(0, 3).join(' | '));
    await b.close();
    fs.writeFileSync(`${WORK}/resultados-reacciones-navegador.json`, JSON.stringify(res, null, 1));
    const n = res.length;
    console.log(`\n${n} comprobaciones, ${fallos} FALLAN`);
    process.exit(fallos ? 1 : 0);
})().catch(e => { console.error(e); process.exit(2); });
