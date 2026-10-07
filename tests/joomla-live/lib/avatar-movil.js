/**
 * 0.6.28: AVATAR EN MOVIL (opcion mobile_avatar: auto / show / hide) en un navegador real (Chromium + Playwright) sobre el Joomla de pruebas,
 * como administrador (con botones de moderacion), con el plugin de Gravatar en modo «preguntar» (sin consentimiento) y en modo «off».
 *
 * FASE=antes   (con la 0.6.27 instalada) guarda la referencia de «classic sin la opcion» (HTML + estilos calculados de TODOS los elementos, por
 *              ancho) en $WORK/avatar-movil-base.json y mide cuantos avatares se ven en movil (los 4 temas): el fallo de Chas.
 * FASE=despues (con la 0.6.28 instalada) la matriz completa: 4 temas x 3 valores x anchos 320/360/390/430/575/576/768/1280:
 *   - classic + auto (y classic sin la opcion): HTML y estilos calculados IDENTICOS a la referencia de la 0.6.27 (como classic-identico de 10-temas);
 *   - avatar visible/oculto segun la tabla (classic+auto oculto < 576 px; modern/minimal/dark+auto visible; show visible; hide oculto);
 *   - tamano (36/40 px, cuadrado) y posicion dentro de la cabecera: a la izquierda del nombre, botones debajo, sin solapes ni desbordes;
 *   - desde 576 px las tres opciones dan exactamente los mismos estilos calculados (la opcion solo actua por debajo de 576 px);
 *   - contraste del texto de la cabecera, del aviso y de la cita (4,5:1; classic no baja respecto a auto);
 *   - teclado: el mismo orden de Tab con los tres valores;
 *   - red: 0 peticiones a gravatar.com sin consentimiento y el mismo numero de imagenes pedidas con los tres valores; el avatar es la silueta local.
 * Entorno: BASE_URL, WORK, IDS_FILE, DB_NAME, PW_MODULE, CHROMIUM, OUT, FASE.
 */
const {execFileSync} = require('child_process');
const fs = require('fs');
const path = require('path');
const {chromium} = require(process.env.PW_MODULE || 'playwright');
const BASE = process.env.BASE_URL || 'http://127.0.0.1:8080';
const WORK = process.env.WORK;
const OUT = process.env.OUT || `${WORK}/avatar-movil-capturas`;
const DB = process.env.DB_NAME || 'joomla_test';
const FASE = process.env.FASE || 'despues';
const ids = JSON.parse(fs.readFileSync(`${WORK}/${process.env.IDS_FILE || 'ids.json'}`, 'utf8'));
const AS = ids.art_publico_asset;
const PAGE = `${BASE}/index.php?option=com_content&view=article&id=${ids.art_publico}&catid=${ids.cat_publica}`;
const ADMIN_PASS = process.env.ADMIN_PASS || ('Aa1!' + fs.readFileSync(`${WORK}/adminpass.txt`, 'utf8').trim());
const BASEFILE = `${WORK}/avatar-movil-base.json`;
const res = [];
let MODO = 'ask';
function r(id, desc, ok, ev = '') { res.push({id, desc, estado: ok ? 'PASA' : 'FALLA', ev}); if (!ok || process.env.VERBOSO) console.log(`${id.padEnd(40)} ${ok ? 'PASA ' : 'FALLA'} ${desc}${ev ? '  [' + ev + ']' : ''}`); }
function sql(s) { return execFileSync('mysql', ['-uroot', '--raw', '--default-character-set=utf8mb4', '-N', DB, '-e', s]).toString().trim(); }
const MINID = () => +sql(`SELECT MIN(id) FROM jos_engage_comments WHERE asset_id=${AS}`);
const q = s => `'${String(s).replace(/\\/g, '\\\\').replace(/'/g, "''")}'`;
fs.mkdirSync(OUT, {recursive: true});
const TEMAS = (process.env.TEMAS_SOLO || 'classic,modern,minimal,dark').split(',');
const VALORES = ['auto', 'show', 'hide'];
const ANCHOS = (process.env.ANCHOS_SOLO || '320,360,390,430,575,576,768,1280').split(',').map(Number);
const VAR_MOVIL = {classic: 40, modern: 40, minimal: 36, dark: 40};

const prev = {
    com: sql("SELECT IFNULL(params,'') FROM jos_extensions WHERE element='com_engage' AND type='component'"),
    lang: sql("SELECT params FROM jos_extensions WHERE element='com_languages'"),
    plug: sql("SELECT extension_id, enabled, IFNULL(params,'') FROM jos_extensions WHERE type='plugin' AND folder='engage'").split('\n').filter(Boolean).map(l => l.split('\t')),
};
const idAdmin = sql("SELECT id FROM jos_users WHERE username='admintest'");
const idOtro = sql("SELECT id FROM jos_users WHERE username='editor1'") || sql("SELECT id FROM jos_users WHERE username<>'admintest' LIMIT 1");
const restaurar = () => {
    sql(`UPDATE jos_extensions SET params=${q(prev.com)} WHERE element='com_engage' AND type='component'`);
    sql(`UPDATE jos_extensions SET params=${q(prev.lang)} WHERE element='com_languages'`);
    for (const [id, en, pr] of prev.plug) sql(`UPDATE jos_extensions SET enabled=${en}, params=${q(pr || '')} WHERE extension_id=${id}`);
};
const setCom = (tema, valor) => {
    const p = {default_publish: '1', max_level: '3', comments_ordering: 'asc', reactions_enabled: '0'}; // 0.7.0: sin la fila de reacciones, para seguir comparando con la referencia de la 0.6.27
    if (tema) p.theme = tema;
    if (valor) p.mobile_avatar = valor;
    sql(`UPDATE jos_extensions SET params=${q(JSON.stringify(p))} WHERE element='com_engage' AND type='component'`);
};
const setGrav = modo => sql(`UPDATE jos_extensions SET enabled=1, params=${q(JSON.stringify({mode: modo}))} WHERE type='plugin' AND folder='engage' AND element='gravatar'`);
const ins = (parent, body, name, by, enabled = 1) => sql(`INSERT INTO jos_engage_comments (asset_id,parent_id,body,name,email,ip,user_agent,enabled,created,created_by) VALUES (${AS},${parent},${q('<p>' + body + '</p>')},${q(name)},${q(name ? name.toLowerCase().replace(/\W/g, '') + '@example.invalid' : '')},'203.0.113.7','t',${enabled},'2026-10-01 10:00:00',${by}); SELECT LAST_INSERT_ID()`);

function sembrar() {
    sql(`DELETE FROM jos_engage_comments WHERE asset_id=${AS}`);
    const a = ins('NULL', 'Texto del invitado, un poco mas largo para que ocupe varias lineas en una pantalla estrecha de movil y se vea la cabecera.', 'Invitado', 0);
    const m = ins('NULL', 'Texto del administrador', '', idAdmin);
    const u = ins('NULL', 'Texto de otro usuario con un nombre de usuario largo', '', idOtro);
    const x = ins(m, 'Respuesta del invitado con nombre muy largo', 'Maria del Carmen de los Angeles Fernandez-Gutierrez de los Santos', 0);
    ins(x, 'Respuesta del administrador', '', idAdmin);
    ins(a, 'Respuesta de usuario', '', idOtro);
    ins('NULL', 'Esto es posible spam', 'Spammer', 0, -3);
    ins('NULL', 'Esto esta sin publicar', 'Pendiente', 0, 0);
    return sql(`SELECT GROUP_CONCAT(id ORDER BY id) FROM jos_engage_comments WHERE asset_id=${AS}`);
}

/** DENTRO de la pagina: firma de TODO el contenedor (HTML normalizado + hash de los estilos calculados y la geometria de cada elemento). */
function firmaPagina(arg) {
    const minId = (typeof arg === 'object') ? arg.id : arg; const rawL = [];
    const secTodo = document.querySelector('section.akengage-outer-container');
    const sec = secTodo.querySelector('.akengage-list-container'); // la lista de comentarios (el formulario lleva TinyMCE, con identificadores al azar)
    const h = s => { let a = 5381, b = 52711; for (let i = 0; i < s.length; i++) { const c = s.charCodeAt(i); a = ((a << 5) + a + c) | 0; b = ((b << 5) ^ b) + c | 0; } return (a >>> 0).toString(36) + (b >>> 0).toString(36); };
    const est = [];
    for (const e of [secTodo, sec, ...sec.querySelectorAll('*')]) {
        const s = getComputedStyle(e); const rc = e.getBoundingClientRect();
        let t = e.tagName + '|' + Math.round(rc.x * 10) + ',' + Math.round(rc.y * 10) + ',' + Math.round(rc.width * 10) + ',' + Math.round(rc.height * 10);
        for (const k of [...s].sort()) t += '|' + k + ':' + s.getPropertyValue(k); // ordenadas: Blink devuelve las variables CSS en orden distinto entre cargas
        est.push(h(t)); if (arg.raw) rawL.push(t);
    }
    // El contenedor lleva la clase del tema/opcion: en la firma de estilos se excluye su "class" (el HTML se compara aparte)
    const html = (secTodo.outerHTML.slice(0, secTodo.outerHTML.indexOf('>') + 1) + sec.outerHTML).replace(/\b[0-9a-f]{32}\b/g, 'TOKEN').replace(/(?<![\d.])(\d{1,6})(?![\d.])/g, (m, n) => (+n >= minId && +n < minId + 8) ? 'ID' + (+n - minId) : m).replace(/\s+/g, ' ');
    return {html, htmlHash: h(html), n: est.length, est, rawL};
}

/** DENTRO de la pagina: medidas del avatar y de la cabecera de cada comentario. */
function medirAvatar() {
    const vw = document.documentElement.clientWidth;
    const sec = document.querySelector('section.akengage-outer-container');
    const R = e => { const c = e.getBoundingClientRect(); return {l: c.left, t: c.top, r: c.right, b: c.bottom, w: c.width, h: c.height}; };
    const solapa = (a, b) => Math.min(a.r, b.r) - Math.max(a.l, b.l) > 1 && Math.min(a.b, b.b) - Math.max(a.t, b.t) > 1;
    const out = {footers: 0, visibles: 0, ocultos: 0, tam: [], fuera: [], solapes: [], desbordes: [], noIzq: 0, botonesAbajo: 0, botones: 0, srcExterno: 0, srcLocal: 0, claseSec: sec.className};
    for (const f of sec.querySelectorAll('footer.akengage-comment-properties')) {
        out.footers++;
        const fr = R(f);
        const cont = f.querySelector('.akengage-commenter-avatar-container');
        const head = f.querySelector('.akengange-comment-head');
        const cs = cont ? getComputedStyle(cont) : null;
        const vis = !!cont && cs.display !== 'none';
        if (f.scrollWidth > f.clientWidth + 1) out.desbordes.push(`cabecera desborda ${f.scrollWidth - f.clientWidth}px`);
        for (const e of f.querySelectorAll('*')) { const c = R(e); if (c.w > 0 && (c.r > vw + 1 || c.l < -1)) { out.desbordes.push(`${e.tagName.toLowerCase()}.${String(e.className).split(' ')[0]} sale del viewport`); break; } }
        const hr = R(head);
        const piezas = [...head.querySelectorAll('.akengange-commenter-name > *, .akengage-comment-permalink a, .akengage-comment-actions button, .akengage-comment-ip')].map(R).filter(c => c.w > 0);
        if (cont) { const im0 = cont.querySelector('img'); const src0 = im0 ? (im0.currentSrc || im0.src) : ''; try { (new URL(src0, location.href).host === location.host || src0.startsWith('data:')) ? out.srcLocal++ : out.srcExterno++; } catch (e) { out.srcExterno++; } }
        if (!cont || !vis) { out.ocultos++; continue; }
        out.visibles++;
        const cr = R(cont); const img = cont.querySelector('img'); const ir = R(img);
        out.tam.push(Math.round(cr.w) + 'x' + Math.round(ir.h));
        if (Math.abs(ir.w - ir.h) > 1.5) out.fuera.push(`avatar no cuadrado ${Math.round(ir.w)}x${Math.round(ir.h)}`);
        if (cr.l < fr.l - 1 || cr.r > fr.r + 1 || cr.t < fr.t - 1 || cr.b > fr.b + 1) out.fuera.push('avatar fuera de la cabecera');
        if (cr.r > hr.l + 1) out.noIzq++;
        for (const p of piezas) if (solapa(cr, p)) { out.solapes.push('avatar solapa con el texto'); break; }
        const fecha = f.querySelector('.akengage-comment-permalink a'); const acc = f.querySelector('.akengage-comment-actions');
        if (acc && acc.querySelector('button')) { out.botones++; if (R(acc).t >= R(fecha).b - 1) out.botonesAbajo++; }
    }
    out.docDesborde = Math.max(0, document.documentElement.scrollWidth - innerWidth);
    out.secDesborde = [...sec.querySelectorAll('*')].filter(e => { const c = e.getBoundingClientRect(); return c.width > 0 && c.right > vw + 1; }).length;
    return out;
}

/** DENTRO de la pagina: contraste del texto de la cabecera, el aviso de estado y la cita. */
function medirContraste() {
    const parse = c => { const m = c.match(/rgba?\(([^)]+)\)/); if (m) { const q = m[1].split(/[ ,\/]+/).filter(Boolean).map(Number); return {r: q[0], g: q[1], b: q[2], a: q.length > 3 ? q[3] : 1}; } const n = c.match(/color\(srgb ([^)]+)\)/); if (n) { const q = n[1].split(/[ \/]+/).filter(Boolean).map(Number); return {r: q[0] * 255, g: q[1] * 255, b: q[2] * 255, a: q.length > 3 ? q[3] : 1}; } return null; };
    const over = (f, b) => ({r: f.r * f.a + b.r * (1 - f.a), g: f.g * f.a + b.g * (1 - f.a), b: f.b * f.a + b.b * (1 - f.a), a: 1});
    const lum = c => { const f = v => { v /= 255; return v <= .03928 ? v / 12.92 : Math.pow((v + .055) / 1.055, 2.4); }; return .2126 * f(c.r) + .7152 * f(c.g) + .0722 * f(c.b); };
    const ratio = (a, b) => { const x = lum(a), y = lum(b); return (Math.max(x, y) + .05) / (Math.min(x, y) + .05); };
    const fondo = el => { const capas = []; for (let e = el; e; e = e.parentElement) { const c = parse(getComputedStyle(e).backgroundColor); if (c && c.a > 0) { capas.push(c); if (c.a >= 1) break; } } let b = {r: 255, g: 255, b: 255, a: 1}; for (let i = capas.length - 1; i >= 0; i--) b = over(capas[i], b); return b; };
    const sec = document.querySelector('section.akengage-outer-container');
    let min = 99, n = 0, peor = '';
    const w = document.createTreeWalker(sec, NodeFilter.SHOW_TEXT);
    for (let t; (t = w.nextNode());) {
        const txt = t.textContent.trim(); if (!txt) continue;
        const el = t.parentElement;
        if (!el.closest('footer.akengage-comment-properties, .akengage-comment-publish-type, .akengage-comment-replyto')) continue;
        const s = getComputedStyle(el); if (s.display === 'none' || s.visibility === 'hidden' || !el.getClientRects().length) continue;
        const bg = fondo(el); const fg = over(parse(s.color), bg); const c = ratio(fg, bg); n++;
        if (c < min) { min = c; peor = txt.slice(0, 24); }
    }
    return {min: Math.round(min * 100) / 100, n, peor};
}

(async () => {
    sql("UPDATE jos_extensions SET params='{\"administrator\":\"es-ES\",\"site\":\"es-ES\"}' WHERE element='com_languages'");
    const b = await chromium.launch({executablePath: process.env.CHROMIUM || '/opt/pw-browsers/chromium', args: ['--no-sandbox']});
    const reqs = {gravatar: 0, imagenes: 0};
    const abrir = async w => {
        const c = await b.newContext({viewport: {width: w, height: 900}, locale: 'es-ES', colorScheme: 'light'});
        await c.route(/gravatar\.com/, rt => { reqs.gravatar++; rt.abort(); });
        const p = await c.newPage();
        p.on('request', rq => { if (rq.resourceType() === 'image' && !/gravatar\.com/.test(rq.url())) reqs.imagenes++; });
        await p.goto(`${BASE}/index.php?option=com_users&view=login`);
        await p.fill('#username', 'admintest'); await p.fill('#password', ADMIN_PASS);
        await Promise.all([p.waitForNavigation(), p.locator('form button[type=submit]').first().click()]);
        return {c, p};
    };
    const cargar = async p => { reqs.gravatar = 0; reqs.imagenes = 0; await p.goto(PAGE, {waitUntil: 'load'}); if (MODO === 'ask') await p.waitForSelector('.akengage-gravatar-notice', {timeout: 5000}).catch(() => {}); await p.waitForLoadState('networkidle').catch(() => {}); await p.waitForTimeout(250); return {gr: reqs.gravatar, im: reqs.imagenes}; };

    if (FASE === 'antes') {
        // ---- referencia de la 0.6.27 + el fallo (avatares ocultos en movil con los 4 temas)
        MODO = 'ask'; setGrav('ask');
        const idsSemilla = sembrar();
        const base = {ids: idsSemilla, anchos: {}};
        const visibles = {};
        for (const w of ANCHOS) {
            const {c, p} = await abrir(w);
            setCom('classic', null); await cargar(p);
            base.anchos[w] = await p.evaluate(firmaPagina, process.env.RAW ? {id: MINID(), raw: 1} : MINID());
            for (const tema of TEMAS) {
                setCom(tema, null); await cargar(p);
                const m = await p.evaluate(medirAvatar);
                visibles[`${tema}@${w}`] = `${m.visibles}/${m.footers}`;
            }
            await c.close();
        }
        fs.writeFileSync(BASEFILE, JSON.stringify(base));
        console.log('REFERENCIA 0.6.27 guardada (' + Object.keys(base.anchos).length + ' anchos, comentarios ' + idsSemilla + ')');
        console.log('AVATARES VISIBLES / CABECERAS (0.6.27):');
        for (const tema of TEMAS) console.log('  ' + tema.padEnd(8) + ANCHOS.map(w => `${w}px:${visibles[`${tema}@${w}`]}`).join('  '));
        fs.writeFileSync(`${WORK}/avatar-movil-antes-visibles.json`, JSON.stringify(visibles, null, 1));
        await b.close(); restaurar();
        process.exit(0);
    }

    // ---------------------------------------------------------------- FASE despues
    let base = null; try { base = JSON.parse(fs.readFileSync(BASEFILE, 'utf8')); } catch (e) {}
    if (+sql(`SELECT COUNT(*) FROM jos_engage_comments WHERE asset_id=${AS}`) !== 8) { sembrar(); }
    if (!base) console.log('AVISO: sin referencia de la 0.6.27: se omite la comparacion con ella (ejecute antes FASE=antes con la 0.6.27 instalada)');
    let resumen = {paginas: 0, grav: 0, solapes: 0, desbordes: 0, visiblesOk: 0, visiblesMal: 0, identicos: 0, difieren: 0};
    const firmas = {}; // (modo,tema,ancho,valor) -> firma (para 576+ y teclado)
    const teclado = {};
    const imagenes = {};
    const contrasteMin = {};
    for (const modo of ['ask', 'off']) {
        MODO = modo; setGrav(modo);
        const anchos = modo === 'ask' ? ANCHOS : [390, 576, 1280];
        for (const w of anchos) {
            const {c, p} = await abrir(w);
            const movil = w < 576;
            for (const tema of TEMAS) {
                for (const valor of VALORES) {
                    setCom(tema, valor);
                    const red = await cargar(p);
                    const id = `${modo}-${tema}-${valor}-${w}`;
                    const fiPre = modo === 'ask' ? await p.evaluate(firmaPagina, process.env.RAW ? {id: MINID(), raw: 1} : MINID()) : null; // antes de pulsar Tab (el foco cambia estilos)
                    const m = await p.evaluate(medirAvatar);
                    const esperado = !movil ? true : valor === 'show' ? true : valor === 'hide' ? false : tema !== 'classic';
                    const nVis = m.visibles;
                    const okVis = m.footers >= 8 && (esperado ? nVis === m.footers : nVis === 0);
                    resumen.paginas++; okVis ? resumen.visiblesOk++ : resumen.visiblesMal++;
                    r(`${id}/visible`, `${tema}, mobile_avatar=${valor}, ${w}px: avatar ${esperado ? 'visible' : 'oculto'} (${nVis}/${m.footers})`, okVis, `clase ${m.claseSec.replace('akengage-outer-container', '').trim() || '(ninguna)'}`);
                    // red
                    resumen.grav += red.gr;
                    r(`${id}/red`, `0 peticiones a gravatar.com sin consentimiento; avatar = silueta local`, red.gr === 0 && m.srcExterno === 0 && m.srcLocal === m.footers, `gravatar ${red.gr}, imagenes ${red.im}, locales ${m.srcLocal}/${m.footers}`);
                    (imagenes[`${modo}-${tema}-${w}`] = imagenes[`${modo}-${tema}-${w}`] || {})[valor] = red.im;
                    // desbordes
                    const des = m.desbordes.length + m.docDesborde + m.secDesborde; if (movil && (valor !== 'auto' || tema !== 'classic')) resumen.desbordes += des;
                    const controlado = movil && (valor !== 'auto' || tema !== 'classic');
                    r(`${id}/desborde`, `sin desbordes (cabecera, viewport, pagina)${controlado ? '' : ' [solo informa: comportamiento original de la 0.6.27]'}`, !controlado || des === 0, `${m.desbordes.slice(0, 2).join('; ')} doc ${m.docDesborde} sec ${m.secDesborde}`);
                    if (esperado && movil) {
                        const esp = valor === 'show' || tema !== 'classic' ? VAR_MOVIL[tema] : 0;
                        const tamOk = m.tam.every(t => Math.abs(parseInt(t) - esp) <= 1 && Math.abs(parseInt(t.split('x')[1]) - esp) <= 1.5);
                        resumen.solapes += m.solapes.length;
                        r(`${id}/geometria`, `avatar de ${esp} px, a la izquierda del texto, dentro de la cabecera, sin solapes`, tamOk && m.noIzq === 0 && m.solapes.length === 0 && m.fuera.length === 0, `tam ${[...new Set(m.tam)].join(',')}; noIzq ${m.noIzq}; ${[...m.solapes, ...m.fuera].slice(0, 2).join('; ')}`);
                        r(`${id}/botones-debajo`, `botones de moderacion debajo de la fecha (${m.botonesAbajo}/${m.botones})`, m.botones >= 3 && m.botonesAbajo === m.botones, '');
                    }
                    // contraste
                    const ct = await p.evaluate(medirContraste); contrasteMin[`${modo}-${tema}-${valor}-${w}`] = ct.min;
                    if (tema !== 'classic') r(`${id}/contraste`, `contraste del texto de la cabecera, aviso y cita >= 4,5:1`, ct.n > 20 && ct.min >= 4.5, `minimo ${ct.min} ("${ct.peor}", ${ct.n} textos)`);
                    // teclado: orden de Tab (sin contar el enlace del perfil, que no existe en el HTML de los invitados)
                    const seq = await p.evaluate(async () => {
                        const sec = document.querySelector('section.akengage-outer-container');
                        const f = [...sec.querySelectorAll('button, a[href], input, select, textarea, [tabindex]')].filter(e => !e.classList.contains('akengage-commenter-profile') && e.tabIndex >= 0 && e.getClientRects().length && getComputedStyle(e).visibility !== 'hidden');
                        return f.map(e => e.tagName + '.' + String(e.className).split(' ')[0] + ':' + (e.textContent || '').trim().slice(0, 12)).join('|');
                    });
                    (teclado[`${modo}-${tema}-${w}`] = teclado[`${modo}-${tema}-${w}`] || {})[valor] = seq;
                    if (modo === 'ask') {
                        // Tab real: 8 pulsaciones desde el primer boton de la seccion
                        await p.evaluate(() => { const b0 = document.querySelector('section.akengage-outer-container button'); b0 && b0.focus(); });
                        const tab = []; for (let i = 0; i < 8; i++) { await p.keyboard.press('Tab'); tab.push(await p.evaluate(() => { const e = document.activeElement; return e.tagName + '.' + String(e.className).split(' ')[0] + ':' + (e.textContent || '').trim().slice(0, 10); })); }
                        (teclado[`tab-${tema}-${w}`] = teclado[`tab-${tema}-${w}`] || {})[valor] = tab.join('|');
                    }
                    // firmas (comparacion con la 0.6.27 y a partir de 576 px)
                    if (modo === 'ask') {
                        const fi = fiPre;
                        firmas[`${tema}-${valor}-${w}`] = fi;
                        if (tema === 'classic' && valor === 'auto' && base && base.anchos[w]) {
                            const bf = base.anchos[w];
                            const igualHtml = fi.html === bf.html;
                            if (!igualHtml) { fs.writeFileSync(`${OUT}/dif-${w}-base.html`, bf.html.replace(/></g, '>\n<')); fs.writeFileSync(`${OUT}/dif-${w}-act.html`, fi.html.replace(/></g, '>\n<')); }
                            const dif = []; for (let i = 0; i < Math.max(fi.est.length, bf.est.length); i++) if (fi.est[i] !== bf.est[i]) dif.push(i);
                            if (process.env.RAW && dif.length && fi.rawL.length) { const A = bf.rawL[dif[0]].split('|'), B = fi.rawL[dif[0]].split('|'); const k = A.findIndex((x, i) => x !== B[i]); console.log('DIF', w, 'elem', dif[0], A.length, B.length, k, A[k], '=>', B[k], '| total', dif.length); }
                            resumen[igualHtml && dif.length === 0 ? 'identicos' : 'difieren']++;
                            r(`${id}/identico-0.6.27`, `classic + auto: HTML y estilos calculados IDENTICOS a la 0.6.27 (${fi.n} elementos)`, igualHtml && dif.length === 0 && fi.n === bf.n, `HTML ${igualHtml ? 'igual' : 'DISTINTO'}, estilos distintos en ${dif.length}`);
                        }
                        if (tema === 'classic' && valor === 'auto') {
                            // tambien sin la opcion guardada en absoluto (instalacion que actualiza sin tocar nada)
                            setCom('classic', null); await cargar(p);
                            const f0 = await p.evaluate(firmaPagina, MINID());
                            r(`${id}/identico-sin-opcion`, `classic + auto es igual a classic sin la opcion en los parametros (HTML y estilos)`, f0.html === fi.html && JSON.stringify(f0.est) === JSON.stringify(fi.est), '');
                            if (base && base.anchos[w]) r(`${id}/sin-opcion-0.6.27`, `classic sin la opcion: HTML y estilos identicos a la 0.6.27`, f0.html === base.anchos[w].html && JSON.stringify(f0.est) === JSON.stringify(base.anchos[w].est), `${f0.n} elementos`);
                        }
                    }
                }
            }
            await c.close();
        }
    }
    // comparaciones entre valores
    for (const k of Object.keys(teclado)) {
        const t = teclado[k]; r(`teclado-${k}`, 'el orden del teclado es el mismo con auto, show y hide', t.auto === t.show && t.auto === t.hide, '');
    }
    for (const k of Object.keys(imagenes)) { const t = imagenes[k]; r(`imagenes-${k}`, 'el mismo numero de imagenes pedidas con los tres valores (no se piden mas)', t.auto === t.show && t.auto === t.hide, JSON.stringify(t)); }
    for (const tema of TEMAS) for (const w of [576, 768, 1280]) {
        // a partir de 576 la opcion no cambia nada: mismos estilos con los tres valores (sin contar la clase del contenedor)
        const a = firmas[`${tema}-auto-${w}`], s = firmas[`${tema}-show-${w}`], h = firmas[`${tema}-hide-${w}`];
        const quita = x => x.html.replace(/ akengage-mobile-avatar--(show|hide)/, '');
        r(`desde576-${tema}-${w}`, `${tema} a ${w}px: auto, show y hide dan los mismos estilos y el mismo HTML (salvo la clase de la opcion)`, JSON.stringify(a.est) === JSON.stringify(s.est) && JSON.stringify(a.est) === JSON.stringify(h.est) && quita(a) === quita(s) && quita(a) === quita(h), `estilos auto/show ${a.est.filter((x, i) => x !== s.est[i]).length}, auto/hide ${a.est.filter((x, i) => x !== h.est[i]).length}, HTML ${quita(a) === quita(s) && quita(a) === quita(h) ? 'igual' : 'distinto'}, n ${a.n}/${s.n}/${h.n}`);
    }
    for (const w of ANCHOS) { // classic+show no empeora el contraste respecto de classic+auto
        const a = contrasteMin[`ask-classic-auto-${w}`], s = contrasteMin[`ask-classic-show-${w}`];
        r(`contraste-classic-${w}`, 'classic: el contraste con show no es peor que con auto', s >= a - 0.01, `auto ${a}, show ${s}`);
    }
    // nombre y correo SIN espacios (muy largos): no desbordan con show/hide en movil
    const idExtremo = ins('NULL', 'Nombre sin espacios', 'MariaDelCarmenDeLosAngelesFernandezGutierrezDeLosSantosYMartinez', 0);
    MODO = 'ask'; setGrav('ask');
    for (const w of [320, 390]) {
        const {c, p} = await abrir(w);
        for (const tema of TEMAS) for (const valor of ['show', 'hide']) {
            setCom(tema, valor); await cargar(p);
            const m = await p.evaluate(medirAvatar); const des = m.desbordes.length + m.docDesborde + m.secDesborde;
            r(`extremo-${tema}-${valor}-${w}`, `nombre de 62 letras sin espacios, ${tema}, ${valor}, ${w}px: sin desbordes`, des === 0, `${m.desbordes.slice(0, 2).join('; ')} doc ${m.docDesborde} sec ${m.secDesborde}`);
        }
        await c.close();
    }
    sql(`DELETE FROM jos_engage_comments WHERE id=${idExtremo}`);
    // ---------------------------------------------------------------- captura final (390 px, administrador)
    MODO = 'ask'; setGrav('ask');
    const fotos = [];
    const {c: cc, p: pp} = await abrir(390);
    for (const [tema, valor, rotulo] of [['classic', 'auto', 'Clasico (auto): sin avatar'], ['classic', 'show', 'Clasico + Mostrar'], ['modern', 'auto', 'Moderno (auto)'], ['minimal', 'auto', 'Minimalista (auto)'], ['dark', 'auto', 'Oscuro (auto)']]) {
        setCom(tema, valor); await cargar(pp);
        const box = await pp.evaluate(() => { const l = document.querySelector('section.akengage-outer-container .akengage-list-container'); const f = [...l.querySelectorAll('article')].slice(0, 1); const a = l.getBoundingClientRect(), z = f[f.length - 1].getBoundingClientRect(); return {x: Math.max(0, a.left - 6), y: a.top + scrollY - 6, width: Math.min(innerWidth - Math.max(0, a.left - 6), a.width + 12), height: z.bottom - a.top + 12}; });
        const buf = await pp.screenshot({clip: box, fullPage: true});
        fotos.push({rotulo, b64: buf.toString('base64'), w: box.width, h: box.height});
    }
    await cc.close();
    const alto = Math.max(...fotos.map(f => f.h));
    const html = `<body style="margin:0;background:#e5e7eb;font:600 13px system-ui,sans-serif;color:#111"><div style="display:flex;gap:10px;padding:10px;align-items:flex-start">${fotos.map(f => `<div><div style="padding:0 0 6px">${f.rotulo}</div><img src="data:image/png;base64,${f.b64}" style="display:block;width:${Math.round(f.w)}px"></div>`).join('')}</div>`;
    const pc = await (await b.newContext({viewport: {width: Math.round(fotos.reduce((s, f) => s + f.w + 10, 10)), height: Math.round(alto + 50)}})).newPage();
    await pc.setContent(html); await pc.waitForTimeout(300);
    const raw = `${OUT}/avatar-movil-raw.png`; await pc.screenshot({path: raw, fullPage: true});
    execFileSync('php', [path.join(__dirname, 'optimizar-png.php'), raw, `${OUT}/avatar-movil.png`, '140'], {stdio: 'inherit'});

    await b.close();
    restaurar();
    console.log(`\nRESUMEN: ${resumen.paginas} paginas medidas; visibles bien ${resumen.visiblesOk}, mal ${resumen.visiblesMal}; peticiones a gravatar.com ${resumen.grav}; solapes ${resumen.solapes}; desbordes ${resumen.desbordes}; classic+auto identico a la 0.6.27 en ${resumen.identicos} anchos (distinto en ${resumen.difieren}${base ? '' : ', SIN REFERENCIA'})`);
    const f = res.filter(x => x.estado === 'FALLA').length;
    console.log(`${res.length - f} PASA / ${f} FALLA`);
    fs.writeFileSync(`${WORK}/resultados-avatar-movil.json`, JSON.stringify({resumen, res}, null, 1));
    process.exit(f ? 1 : 0);
})().catch(e => { try { restaurar(); } catch (x) {} console.error(e); process.exit(1); });
