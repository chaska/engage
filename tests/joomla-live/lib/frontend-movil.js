/**
 * 0.6.27: FRONTEND en movil y escritorio, en un navegador real (Chromium + Playwright) sobre el Joomla de pruebas (plantilla Cassiopeia), como administrador:
 *  (A) el AVISO de Gravatar (modo "preguntar": texto + boton «Mostrar fotos de Gravatar...», y tras aceptar «Se muestran las fotos...» + enlace
 *      «Dejar de mostrar...») a 390 y 1280 px en los 4 temas: el texto y los botones quedan DENTRO de la caja redondeada (cada linea de texto
 *      real se comprueba contra la curva de las esquinas, no solo contra el rectangulo), sin desborde horizontal y con un radio moderado (<= 16 px;
 *      en la 0.6.26 los temas usaban el de la etiqueta de cita, 999 px = pildora, en una caja de varias lineas);
 *  (B) los ICONOS del nombre del comentarista (moderador `.akengage-commenter-ismoderator`, usuario `-isuser`, invitado `-isguest`) y el nombre de
 *      usuario `-username` a 360/390/430 px y escritorio, 4 temas, con y sin respuestas, con Font Awesome (la de Joomla) y SIN ella (bloqueada, como
 *      en una plantilla que no la carga o la carga tarde): cada icono existe, tiene tamano, esta dentro de la cabecera y del viewport, no lo recorta
 *      ningun ancestro, se pinta el glifo (::before de Font Awesome o la mascara SVG propia de los temas) y la fila del nombre no desborda.
 * ANTES=1 solo informa. Entorno: BASE_URL, WORK, IDS_FILE, DB_NAME, PW_MODULE, CHROMIUM, OUT, ANTES.
 */
const {execFileSync} = require('child_process');
const fs = require('fs');
const {chromium} = require(process.env.PW_MODULE || 'playwright');
const BASE = process.env.BASE_URL || 'http://127.0.0.1:8080';
const WORK = process.env.WORK;
const OUT = process.env.OUT || `${WORK}/frontend-movil-capturas`;
const DB = process.env.DB_NAME || 'joomla_test';
const ANTES = process.env.ANTES === '1';
const ids = JSON.parse(fs.readFileSync(`${WORK}/${process.env.IDS_FILE || 'ids.json'}`, 'utf8'));
const AS = ids.art_publico_asset;
const PAGE = `${BASE}/index.php?option=com_content&view=article&id=${ids.art_publico}&catid=${ids.cat_publica}`;
const ADMIN_PASS = process.env.ADMIN_PASS || ('Aa1!' + fs.readFileSync(`${WORK}/adminpass.txt`, 'utf8').trim());
const res = [];
function r(id, desc, ok, ev = '') { res.push({id, desc, estado: ok ? 'PASA' : 'FALLA', ev}); console.log(`${id.padEnd(34)} ${ok ? 'PASA ' : 'FALLA'} ${desc}${ev ? '  [' + ev + ']' : ''}`); }
function sql(s) { return execFileSync('mysql', ['-uroot', '--raw', '--default-character-set=utf8mb4', '-N', DB, '-e', s]).toString().trim(); }
const q = s => `'${String(s).replace(/\\/g, '\\\\').replace(/'/g, "''")}'`;
fs.mkdirSync(OUT, {recursive: true});
const TEMAS = ['classic', 'modern', 'minimal', 'dark'];

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
const setCom = t => sql(`UPDATE jos_extensions SET params=${q(JSON.stringify({theme: t, default_publish: '1', max_level: '3', comments_ordering: 'asc', reactions_enabled: '0', sort_selector: '0', copy_link: '0', show_badges: '0'}))} WHERE element='com_engage' AND type='component'`);
const ins = (parent, body, name, by) => sql(`INSERT INTO jos_engage_comments (asset_id,parent_id,body,name,email,ip,user_agent,enabled,created,created_by) VALUES (${AS},${parent},${q('<p>' + body + '</p>')},${q(name)},${q(name ? name.toLowerCase().replace(/\W/g, '') + '@example.invalid' : '')},'203.0.113.7','t',1,NOW(),${by}); SELECT LAST_INSERT_ID()`);

/** DENTRO de la pagina: geometria del aviso de Gravatar. */
function medirAviso() {
    const n = document.querySelector('.akengage-gravatar-notice');
    if (!n) return {error: 'sin aviso'};
    const rect = e => e.getBoundingClientRect();
    const px = v => parseFloat(v) || 0;
    const dentroRedondeada = (pt, b, rad) => {
        rad = Math.min(rad, b.width / 2, b.height / 2);
        const cx = Math.min(Math.max(pt.x, b.left + rad), b.right - rad), cy = Math.min(Math.max(pt.y, b.top + rad), b.bottom - rad);
        return Math.hypot(pt.x - cx, pt.y - cy) <= rad + 0.75 && pt.x >= b.left - .75 && pt.x <= b.right + .75 && pt.y >= b.top - .75 && pt.y <= b.bottom + .75;
    };
    const lineas = e => { const g = document.createRange(); g.selectNodeContents(e); return [...g.getClientRects()].filter(x => x.width > 0 && x.height > 0); };
    const radio = e => { const s = getComputedStyle(e); return Math.max(px(s.borderTopLeftRadius), px(s.borderTopRightRadius), px(s.borderBottomLeftRadius), px(s.borderBottomRightRadius)); };
    const fuera = [];
    const caja = rect(n), rn = radio(n);
    const comprobar = (e, nombre, contenedor, rc) => {
        for (const l of lineas(e)) for (const pt of [{x: l.left, y: l.top}, {x: l.right, y: l.top}, {x: l.left, y: l.bottom}, {x: l.right, y: l.bottom}]) {
            if (!dentroRedondeada(pt, contenedor, rc)) { fuera.push(`${nombre} (${Math.round(pt.x)},${Math.round(pt.y)}) fuera de la curva de ${Math.round(rc)}px`); return; }
        }
    };
    const piezas = [...n.querySelectorAll('p, button, a')].filter(e => rect(e).height > 0);
    for (const e of piezas) {
        comprobar(e, e.tagName.toLowerCase(), caja, rn);
        if (e.tagName === 'BUTTON') { comprobar(e, 'boton en su propia curva', rect(e), radio(e)); if (rect(e).right > caja.right + 1 || rect(e).left < caja.left - 1) fuera.push('boton fuera de la caja'); }
        if (e.scrollWidth > e.clientWidth + 1 && getComputedStyle(e).display !== 'inline') fuera.push(`${e.tagName.toLowerCase()} desborda ${e.scrollWidth - e.clientWidth}px`);
    }
    return {
        radio: Math.round(rn), ancho: Math.round(caja.width), derecha: Math.round(caja.right), vw: document.documentElement.clientWidth,
        desborde: n.scrollWidth > n.clientWidth + 1 ? n.scrollWidth - n.clientWidth : 0, fuera, piezas: piezas.length,
        relleno: Math.round(Math.min(px(getComputedStyle(n).paddingLeft), px(getComputedStyle(n).paddingTop))), texto: n.textContent.trim().slice(0, 60),
        botones: [...n.querySelectorAll('button')].map(b => ({r: Math.round(radio(b)), h: Math.round(rect(b).height)})),
    };
}

/** DENTRO de la pagina: iconos del nombre del comentarista. */
function medirIconos() {
    const sec = document.querySelector('section.akengage-outer-container');
    const vw = document.documentElement.clientWidth;
    const fuera = [], filas = [];
    const cuenta = {ismoderator: 0, isuser: 0, isguest: 0, username: 0};
    let sinGlifo = 0, recortados = 0, minimos = [];
    for (const nombre of sec.querySelectorAll('.akengange-commenter-name')) {
        const fr = nombre.getBoundingClientRect(), cab = nombre.closest('.akengage-comment-properties').getBoundingClientRect();
        if (nombre.scrollWidth > nombre.clientWidth + 1 || fr.right > vw + 1 || fr.left < -1) filas.push(`fila del nombre desborda ${nombre.scrollWidth - nombre.clientWidth}px`);
        for (const k of Object.keys(cuenta)) for (const e of nombre.querySelectorAll('.akengage-commenter-' + k)) {
            cuenta[k]++;
            const rc = e.getBoundingClientRect(), s = getComputedStyle(e), b = getComputedStyle(e, '::before');
            const ico = k !== 'username';
            if (!(rc.width > 1 && rc.height > 1) || s.visibility === 'hidden' || s.display === 'none') { fuera.push(`${k} sin tamano (${Math.round(rc.width)}x${Math.round(rc.height)})`); continue; }
            if (rc.left < cab.left - 1 || rc.right > cab.right + 1 || rc.top < cab.top - 1 || rc.bottom > cab.bottom + 1) fuera.push(`${k} fuera de la cabecera`);
            if (rc.left < -1 || rc.right > vw + 1) fuera.push(`${k} fuera del viewport`);
            for (let a = e.parentElement; a && a !== document.body; a = a.parentElement) {
                const o = getComputedStyle(a);
                if (o.overflowX !== 'visible' || o.overflowY !== 'visible') { const ar = a.getBoundingClientRect(); if (rc.left < ar.left - 1 || rc.right > ar.right + 1 || rc.top < ar.top - 1 || rc.bottom > ar.bottom + 1) { recortados++; fuera.push(`${k} recortado por ${a.tagName.toLowerCase()}.${String(a.className).split(' ')[0]}`); } }
            }
            if (ico) {
                const mascara = (s.maskImage && s.maskImage !== 'none') || (s.webkitMaskImage && s.webkitMaskImage !== 'none');
                const fa = b.content && b.content !== 'none' && b.content !== 'normal' && /Font Awesome|FontAwesome/.test(b.fontFamily) && document.fonts.check(`900 1em "${b.fontFamily.split(',')[0].replace(/"/g, '')}"`, b.content.replace(/"/g, ''));
                if (!(mascara && b.content === 'none') && !fa) { sinGlifo++; fuera.push(`${k}: no se pinta ningun glifo`); }
                minimos.push(Math.round(rc.width) + 'x' + Math.round(rc.height));
            }
        }
    }
    return {cuenta, fuera: [...new Set(fuera)], filas: [...new Set(filas)], sinGlifo, recortados, tam: [...new Set(minimos)].join(',')};
}

(async () => {
    sql("UPDATE jos_extensions SET params='{\"administrator\":\"es-ES\",\"site\":\"es-ES\"}' WHERE element='com_languages'");
    sql("UPDATE jos_extensions SET enabled=1, params='{\"mode\":\"ask\"}' WHERE type='plugin' AND folder='engage' AND element='gravatar'");
    const b = await chromium.launch({executablePath: process.env.CHROMIUM || '/opt/pw-browsers/chromium', args: ['--no-sandbox']});
    const abrir = async (w, bloqueaFA) => {
        const c = await b.newContext({viewport: {width: w, height: 900}, locale: 'es-ES', colorScheme: 'light'});
        await c.route(/gravatar\.com/, rt => rt.abort());
        if (bloqueaFA) await c.route(/fontawesome|webfonts|fa-solid|\.woff2?(\?|$)/i, rt => rt.abort());
        const p = await c.newPage();
        await p.goto(`${BASE}/index.php?option=com_users&view=login`);
        await p.fill('#username', 'admintest'); await p.fill('#password', ADMIN_PASS);
        await Promise.all([p.waitForNavigation(), p.locator('form button[type=submit]').first().click()]);
        return {c, p};
    };
    // ------------------------------------------------------------ (A) aviso de Gravatar
    sql(`DELETE FROM jos_engage_comments WHERE asset_id=${AS}`);
    const raiz = ins('NULL', 'Primer comentario', 'Invitado', 0); ins(raiz, 'Respuesta', 'Otro invitado', 0);
    let malA = 0, totA = 0;
    for (const w of [390, 1280]) {
        for (const tema of TEMAS) {
            setCom(tema);
            const {c, p} = await abrir(w);
            await p.goto(PAGE, {waitUntil: 'load'});
            await p.evaluate(() => { try { localStorage.removeItem('engage_gravatar_consent'); } catch (e) {} });
            await p.reload({waitUntil: 'load'});
            await p.waitForSelector('.akengage-gravatar-notice button', {timeout: 8000});
            const a = await p.evaluate(medirAviso);
            await p.locator('.akengage-gravatar-notice').screenshot({path: `${OUT}/${ANTES ? 'antes' : 'despues'}-aviso-${tema}-${w}.png`});
            await p.locator('.akengage-gravatar-notice button').click();
            await p.waitForFunction(() => /«Dejar|Dejar de mostrar|Stop showing/.test((document.querySelector('.akengage-gravatar-notice') || {}).textContent || ''), null, {timeout: 8000});
            const d = await p.evaluate(medirAviso);
            await p.locator('.akengage-gravatar-notice').screenshot({path: `${OUT}/${ANTES ? 'antes' : 'despues'}-aviso-aceptado-${tema}-${w}.png`});
            for (const [est, m] of [['pregunta', a], ['aceptado', d]]) {
                const ok = !m.error && m.fuera.length === 0 && m.desborde === 0 && m.derecha <= m.vw + 1 && (tema === 'classic' || m.radio <= 16) && m.piezas >= 2;
                totA++; if (!ok) malA++;
                r(`A-${tema}-${w}-${est}`, `aviso de Gravatar (${est}), tema ${tema}, ${w}px: texto y botones dentro de la caja (curva ${m.radio}px), sin desborde`, ok,
                    m.error || `radio ${m.radio}px, relleno ${m.relleno}px, desborde ${m.desborde}px, ${m.fuera.slice(0, 2).join('; ') || 'dentro'}`);
            }
            await c.close();
        }
    }
    // ------------------------------------------------------------ (B) iconos del nombre
    sql("UPDATE jos_extensions SET params='{\"mode\":\"off\"}' WHERE type='plugin' AND folder='engage' AND element='gravatar'");
    const sembrar = conRespuestas => {
        sql(`DELETE FROM jos_engage_comments WHERE asset_id=${AS}`);
        const a = ins('NULL', 'Texto del invitado', 'Invitado', 0), m = ins('NULL', 'Texto del administrador', '', idAdmin), u = ins('NULL', 'Texto de otro usuario con un nombre de usuario largo', '', idOtro);
        if (conRespuestas) { const x = ins(m, 'Respuesta del invitado', 'Otra persona', 0); ins(x, 'Respuesta del administrador', '', idAdmin); ins(a, 'Respuesta de usuario', '', idOtro); }
    };
    let malB = 0, totB = 0;
    for (const conResp of [false, true]) {
        sembrar(conResp);
        for (const bloqueada of [false, true]) {
            for (const tema of TEMAS) {
                if (bloqueada && tema === 'classic') continue; // el tema Clasico no carga hoja propia: depende de la fuente de la plantilla (como la 3.4.2 original)
                setCom(tema);
                for (const w of [360, 390, 430, 1280]) {
                    const {c, p} = await abrir(w, bloqueada);
                    await p.goto(PAGE, {waitUntil: 'load'});
                    await p.waitForTimeout(250);
                    const m = await p.evaluate(medirIconos);
                    const faltan = !(m.cuenta.isguest >= 1 && m.cuenta.ismoderator >= 1 && m.cuenta.isuser + m.cuenta.ismoderator >= 2 && m.cuenta.username >= 1);
                    const ok = !faltan && m.fuera.length === 0 && m.filas.length === 0;
                    totB++; if (!ok) malB++;
                    r(`B-${tema}-${w}-${conResp ? 'resp' : 'sinresp'}-${bloqueada ? 'sinFA' : 'conFA'}`, `iconos del nombre, tema ${tema}, ${w}px, ${conResp ? 'con' : 'sin'} respuestas, ${bloqueada ? 'SIN' : 'con'} Font Awesome`, ok,
                        `estrella ${m.cuenta.ismoderator}, usuario ${m.cuenta.isuser}, invitado ${m.cuenta.isguest}, nombre de usuario ${m.cuenta.username}; tamano ${m.tam}; ${[...m.fuera, ...m.filas].slice(0, 2).join('; ') || 'todos visibles'}`);
                    if (w === 390 && !conResp && tema === 'modern') await p.locator('section.akengage-outer-container').first().screenshot({path: `${OUT}/${ANTES ? 'antes' : 'despues'}-iconos-${bloqueada ? 'sinFA' : 'conFA'}-modern-390.png`, timeout: 15000}).catch(() => {});
                    await c.close();
                }
            }
        }
    }
    await b.close();
    restaurar();
    console.log(`\nRESUMEN: aviso de Gravatar ${totA - malA}/${totA} correctos; iconos del nombre ${totB - malB}/${totB} correctos`);
    const f = res.filter(x => x.estado === 'FALLA').length;
    console.log(`\n${res.length - f} PASA / ${f} FALLA`);
    fs.writeFileSync(`${WORK}/resultados-frontend-movil${ANTES ? '-antes' : ''}.json`, JSON.stringify({totA, malA, totB, malB, res}, null, 1));
    process.exit(ANTES ? 0 : f ? 1 : 0);
})().catch(e => { try { restaurar(); } catch (x) {} console.error(e); process.exit(1); });
