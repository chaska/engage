/**
 * 0.6.26: MAQUETACION de las filas de la pantalla de Opciones, en un navegador real (Chromium + Playwright) sobre el Joomla de pruebas,
 * con el panel en es-ES (las etiquetas largas de Gravatar y de Clasificacion son las que rompian la fila en la 0.6.25).
 * Para CADA fila de CADA categoria, a 1920/1280/768/390 px x los 3 disenos (Claro/Medio/Oscuro):
 *   (a) ningun elemento de la fila (etiqueta, ayuda, "mas informacion", control, segmentos, tarjetas) se solapa con otro
 *       (se descartan solo las parejas contenedor/hijo) y la fila no se solapa con la siguiente;
 *   (b) la columna de texto mide >= 12 rem (o el ancho completo de la fila si esta apilada) y ningun texto pasa de 12 lineas por
 *       culpa de una columna estrecha (ayuda y etiqueta; el "mas informacion" abierto, con su propio tope);
 *   (c) sin desbordamiento horizontal de la pagina, de la fila, del control ni de cada segmento (scrollWidth <= clientWidth);
 *   (d) los segmentos son alcanzables con el teclado (Tab y Mayus+Tab; flechas en las filas con etiquetas largas, recorriendo TODAS las
 *       opciones y dejando el valor como estaba) y el contraste de todo el texto es >= 4,5:1 y el de los controles >= 3:1.
 * Se activa el modo "ANTES" con ANTES=1 (solo informa: sale 0 aunque haya fallos y escribe el recuento) para documentar el fallo de la 0.6.25.
 * Entorno: BASE_URL, WORK, IDS_FILE, DB_NAME, PW_MODULE, CHROMIUM, OUT, ANTES.
 */
const {execFileSync} = require('child_process');
const fs = require('fs');
const {chromium} = require(process.env.PW_MODULE || 'playwright');
const medirEnPagina = require('./contraste-pagina.js');
const BASE = process.env.BASE_URL || 'http://127.0.0.1:8080';
const WORK = process.env.WORK;
const OUT = process.env.OUT || `${WORK}/maquetacion-capturas`;
const DB = process.env.DB_NAME || 'joomla_test';
const ANTES = process.env.ANTES === '1';
const ADMIN_PASS = process.env.ADMIN_PASS || ('Aa1!' + fs.readFileSync(`${WORK}/adminpass.txt`, 'utf8').trim());
const res = [];
function r(id, desc, ok, ev = '') { res.push({id, desc, estado: ok ? 'PASA' : 'FALLA', ev}); console.log(`${id.padEnd(34)} ${ok ? 'PASA ' : 'FALLA'} ${desc}${ev ? '  [' + ev + ']' : ''}`); }
function sql(s) { return execFileSync('mysql', ['-uroot', '--raw', '--default-character-set=utf8mb4', '-N', DB, '-e', s]).toString().trim(); }
const q = s => `'${String(s).replace(/\\/g, '\\\\').replace(/'/g, "''")}'`;
const SECS = ['design', 'moderation', 'antispam', 'notifications', 'privacy', 'security', 'advanced'];
fs.mkdirSync(OUT, {recursive: true});

const prevLang = sql("SELECT params FROM jos_extensions WHERE element='com_languages'");
const prevCom = sql("SELECT IFNULL(params,'') FROM jos_extensions WHERE element='com_engage' AND type='component'");
const prevPlug = sql("SELECT extension_id, enabled, IFNULL(params,'') FROM jos_extensions WHERE type='plugin' AND folder='engage'").split('\n').filter(Boolean).map(l => l.split('\t'));
sql("UPDATE jos_extensions SET params='{\"administrator\":\"es-ES\",\"site\":\"es-ES\"}' WHERE element='com_languages'");
sql("UPDATE jos_extensions SET enabled=1 WHERE type='plugin' AND folder='engage' AND element IN ('gravatar','akismet','email')");
const restaurar = () => {
    sql(`UPDATE jos_extensions SET params=${q(prevLang)} WHERE element='com_languages'`);
    sql(`UPDATE jos_extensions SET params=${q(prevCom)} WHERE element='com_engage' AND type='component'`);
    for (const [id, en, pr] of prevPlug) sql(`UPDATE jos_extensions SET enabled=${en}, params=${q(pr || '')} WHERE extension_id=${id}`);
};

/** Muestra las filas ocultas por showon (los "Mas informacion" se miden cerrados: abrirlos por script deja el maquetado de <details> sin actualizar). */
function mostrarFilas() {
    const panel = [...document.querySelectorAll('[role=tabpanel]')].find(x => !x.hidden);
    panel.querySelectorAll('[data-eg-row]').forEach(rw => { rw.hidden = false; });
    return new Promise(ok => requestAnimationFrame(() => requestAnimationFrame(() => ok())));
}

/** Se ejecuta DENTRO de la pagina: mide todas las filas visibles de la categoria abierta (y las que showon oculta, mostradas a la fuerza). */
function medirFilas(rem) {
    const rect = e => e.getBoundingClientRect();
    const cruza = (a, b) => { const x = Math.min(a.right, b.right) - Math.max(a.left, b.left), y = Math.min(a.bottom, b.bottom) - Math.max(a.top, b.top); return x > 1 && y > 1 ? Math.round(x * y) : 0; };
    const lineas = e => { const s = getComputedStyle(e); const lh = s.lineHeight === 'normal' ? parseFloat(s.fontSize) * 1.25 : parseFloat(s.lineHeight); return Math.round(rect(e).height / lh); };
    const panel = [...document.querySelectorAll('[role=tabpanel]')].find(x => !x.hidden);
    // docOvf: solo lo que sale de #eg-admin (la cabecera de Joomla a 768 px se pasa 2 px por si sola)
    const out = {filas: 0, solapes: [], estrecha: [], lineas: [], desborde: [], docOvf: (() => { const W = document.documentElement.clientWidth, rt = document.getElementById('eg-admin'); const fuera = [...rt.querySelectorAll('*')].filter(e => { const x = e.getBoundingClientRect(); return x.width > 0 && x.right > W + 1 && !e.closest('.eg-set__nav'); }); return fuera.length ? 2 : 0; })(), stats: {apiladas: 0, enLinea: 0}, minTxt: 1e9};
    const rows = [...panel.querySelectorAll('[data-eg-row]')];
    // (las filas que oculta showon y los "Mas informacion" ya se han mostrado en prepararFilas, un fotograma antes)
    let prev = null;
    for (const rw of rows) {
        out.filas++;
        const key = `${rw.dataset.scope}/${rw.dataset.key}`;
        const R = rect(rw), T = rw.querySelector('.eg-row__text'), C = rw.querySelector('.eg-row__ctl');
        const L = rw.querySelector('.eg-row__label'), H = rw.querySelector('.eg-row__help'), M = rw.querySelector('.eg-more p');
        const tr = rect(T), cr = rect(C);
        const apilada = cr.top >= tr.bottom - 1 || tr.top >= cr.bottom - 1;
        out.stats[apilada ? 'apiladas' : 'enLinea']++;
        // (a) solapes entre elementos hermanos de la fila
        const piezas = [['texto', T], ['control', C]];
        [['etiqueta', L], ['ayuda', H], ['mas', rw.querySelector('.eg-more')]].forEach(([n, e]) => { if (e && rect(e).height > 0) piezas.push([n, e]); });
        const err = rw.querySelector('.eg-row__err'); if (err && !err.hidden) piezas.push(['error', err]);
        rw.querySelectorAll('.eg-seg__btn, .eg-themecard, .eg-switch, .eg-input').forEach((e, i) => piezas.push([`${e.className.split(' ')[0]}#${i}`, e]));
        // el TEXTO real (glifos) de etiqueta, ayuda y mas informacion tambien cuenta: una columna de 0 px no recorta el texto, lo deja desbordar sobre el control
        const glifos = e => { const g = document.createRange(); g.selectNodeContents(e); const rs = [...g.getClientRects()].filter(x => x.width > 0 && x.height > 0); if (!rs.length) return null; return {left: Math.min(...rs.map(x => x.left)), right: Math.max(...rs.map(x => x.right)), top: Math.min(...rs.map(x => x.top)), bottom: Math.max(...rs.map(x => x.bottom))}; };
        const cajas = piezas.map(([n, e]) => ({n, e, r: rect(e)}));
        [['etiqueta', L], ['ayuda', H], ['mas informacion', M && M.closest('details').open ? M : null]].forEach(([n, e]) => { const g = e && glifos(e); if (g) cajas.push({n: n + ' (texto real)', e: null, r: g, de: e}); });
        for (let i = 0; i < cajas.length; i++) for (let j = i + 1; j < cajas.length; j++) {
            const A = cajas[i], B = cajas[j];
            if (A.e && B.e && (A.e.contains(B.e) || B.e.contains(A.e))) continue;
            if ((A.de && B.e && (A.de === B.e || A.de.contains(B.e) || B.e.contains(A.de))) || (B.de && A.e && (B.de === A.e || B.de.contains(A.e) || A.e.contains(B.de)))) continue;
            if (A.de && B.de) continue;
            const s = cruza(A.r, B.r); if (s) out.solapes.push({fila: key, a: A.n, b: B.n, px2: s});
        }
        // el control entero debe quedar dentro de la fila y el texto dentro de su columna
        if (cr.right > R.right + 1 || cr.left < R.left - 1) out.desborde.push({fila: key, que: 'control fuera de la fila', px: Math.round(Math.max(cr.right - R.right, R.left - cr.left))});
        if (prev && cruza(R, rect(prev))) out.solapes.push({fila: key, a: 'fila', b: 'fila anterior', px2: cruza(R, rect(prev))});
        prev = rw;
        // (b) columna de texto
        const okAncho = tr.width >= 12 * rem - 1 || tr.width >= R.width * .9;
        out.minTxt = Math.min(out.minTxt, Math.round(tr.width));
        if (!okAncho) out.estrecha.push({fila: key, ancho: Math.round(tr.width), fila_ancho: Math.round(R.width)});
        [['etiqueta', L], ['ayuda', H]].forEach(([n, e]) => { if (e) { const l = lineas(e); if (l > 12) out.lineas.push({fila: key, que: n, lineas: l}); } });
        if (M) { const l = lineas(M); const esperadas = Math.ceil(M.textContent.length * parseFloat(getComputedStyle(M).fontSize) * .52 / Math.max(rect(M).width, 1)) + 3; if (l > Math.max(12, esperadas)) out.lineas.push({fila: key, que: 'mas informacion', lineas: l, esperadas}); }
        // (c) desbordamiento propio
        [['fila', rw], ['texto', T], ['control', C], ...[...rw.querySelectorAll('.eg-seg, .eg-seg__btn, .eg-input, .eg-cards')].map((e, i) => [`${e.className.split(' ')[0]}#${i}`, e])].forEach(([n, e]) => {
            if (e.scrollWidth > e.clientWidth + 1 && getComputedStyle(e).overflowX !== 'auto' && e.tagName !== 'INPUT' && e.tagName !== 'SELECT' && e.tagName !== 'TEXTAREA') out.desborde.push({fila: key, que: n, px: e.scrollWidth - e.clientWidth});
        });
        // segmentos dentro de su control
        const sg = rw.querySelector('.eg-seg');
        if (sg) { const sr = rect(sg); sg.querySelectorAll('.eg-seg__btn').forEach(bt => { const br = rect(bt); if (br.right > sr.right + 1 || br.left < sr.left - 1 || br.bottom > sr.bottom + 1 || br.top < sr.top - 1) out.desborde.push({fila: key, que: 'segmento fuera del control', px: 1}); }); }
    }
    if (out.docOvf > 1) out.desborde.push({fila: 'pagina', que: 'Engage fuera de la pantalla', px: out.docOvf});
    return out;
}

(async () => {
    const b = await chromium.launch({executablePath: process.env.CHROMIUM || '/opt/pw-browsers/chromium', args: ['--no-sandbox']});
    const login = async vp => {
        const c = await b.newContext({viewport: vp, colorScheme: 'light', locale: 'es-ES'});
        const p = await c.newPage();
        await p.goto(`${BASE}/administrator/`);
        await p.fill('#mod-login-username', 'admintest'); await p.fill('#mod-login-password', ADMIN_PASS);
        await Promise.all([p.waitForNavigation(), p.press('#mod-login-password', 'Enter')]);
        return {c, p};
    };
    const abrir = async (p, tema) => {
        await p.goto(`${BASE}/administrator/index.php?option=com_engage&view=settings&section=design`, {waitUntil: 'load'});
        await p.evaluate(t => { try { localStorage.setItem('eg-admin-theme', t); } catch (e) {} }, tema);
        await p.reload({waitUntil: 'load'});
    };
    const espera = async (p, sel) => p.waitForFunction(s => { const e = document.querySelector(s); return e && (e.classList.contains('is-saved') || e.classList.contains('is-error')); }, sel, {timeout: 8000});
    const anchos = (process.env.ANCHOS || '1920,1280,768,390').split(',').map(Number);
    const T = [['light', 'claro'], ['mid', 'medio'], ['dark', 'oscuro']];
    let filasTot = 0, medidas = 0, solapesTot = 0, estrechasTot = 0, lineasTot = 0, desbTot = 0, contrasteMalos = 0, contrasteTot = 0, apiladas = 0, enLinea = 0, minTxt = 1e9;
    const malos = {solapes: [], estrecha: [], lineas: [], desborde: [], contraste: []};
    for (const w of anchos) {
        const {c, p} = await login({width: w, height: 1000});
        const rem = await p.evaluate(() => parseFloat(getComputedStyle(document.documentElement).fontSize));
        for (const [tema, nombre] of T) {
            await abrir(p, tema);
            let filas = 0, sol = 0, est = 0, lin = 0, desb = 0, cm = 0, ct = 0;
            for (const sec of SECS) {
                await p.click(`[data-eg-tab="${sec}"]`);
                await p.evaluate(mostrarFilas);

                const m = await p.evaluate(medirFilas, rem);
                filas += m.filas; sol += m.solapes.length; est += m.estrecha.length; lin += m.lineas.length; desb += m.desborde.length;
                apiladas += m.stats.apiladas; enLinea += m.stats.enLinea; minTxt = Math.min(minTxt, m.minTxt);
                malos.solapes.push(...m.solapes.map(x => ({w, nombre, ...x}))); malos.estrecha.push(...m.estrecha.map(x => ({w, nombre, ...x})));
                malos.lineas.push(...m.lineas.map(x => ({w, nombre, ...x}))); malos.desborde.push(...m.desborde.map(x => ({w, nombre, ...x})));
                const k = await p.evaluate(medirEnPagina);
                const mal = k.items.filter(i => i.ratio < i.min); cm += mal.length; ct += k.items.length;
                malos.contraste.push(...mal.slice(0, 3).map(x => ({w, nombre, sec, ...x})));
                if (nombre === 'claro' && sec === 'privacy') await (await p.$('#eg-admin')).screenshot({path: `${OUT}/${ANTES ? 'antes' : 'despues'}-privacidad-${w}.png`});
                if (nombre === 'oscuro' && sec === 'privacy' && w === 1920) await (await p.$('#eg-admin')).screenshot({path: `${OUT}/${ANTES ? 'antes' : 'despues'}-privacidad-oscuro-${w}.png`});
            }
            filasTot += filas; medidas++; solapesTot += sol; estrechasTot += est; lineasTot += lin; desbTot += desb; contrasteMalos += cm; contrasteTot += ct;
            r(`M-${w}-${nombre}`, `${w}px, diseno ${nombre}: ${filas} filas en ${SECS.length} categorias`, sol === 0 && est === 0 && lin === 0 && desb === 0 && cm === 0 && filas >= 30,
                `solapes ${sol}, columna estrecha ${est}, lineas>12 ${lin}, desbordes ${desb}, contraste ${cm}/${ct}`);
        }
        // (d) teclado (en claro): Tab / Mayus+Tab llegan al control activo de cada segmentado; flechas recorren TODAS las opciones de las filas largas
        await abrir(p, 'light');
        let segs = 0, malTab = [];
        for (const sec of SECS) {
            await p.click(`[data-eg-tab="${sec}"]`);
            const filasSeg = await p.$$(`#eg-panel-${sec} [data-eg-row]:not([hidden]) .eg-seg`);
            for (const sg of filasSeg) {
                const act = await sg.$('[role=radio][tabindex="0"]'); if (!act) { malTab.push('sin tabindex=0'); continue; }
                segs++;
                await act.scrollIntoViewIfNeeded(); await act.focus(); await p.keyboard.press('Shift+Tab'); await p.keyboard.press('Tab');
                const ok = await act.evaluate(e => document.activeElement === e && e.matches(':focus-visible'));
                const vis = await act.evaluate(e => { const s = getComputedStyle(e); return s.outlineStyle !== 'none' && parseFloat(s.outlineWidth) >= 2 || s.boxShadow !== 'none'; });
                const rc = await act.boundingBox();
                if (!ok || !vis || !rc || rc.width < 24 || rc.height < 24) malTab.push(`${sec}: ${ok}/${vis}/${rc && Math.round(rc.height)}`);
            }
        }
        r(`D-tab-${w}`, `${w}px: ${segs} controles segmentados alcanzables con Tab y Mayus+Tab, con foco visible y objetivo >= 24 px`, segs >= 15 && malTab.length === 0, malTab.slice(0, 3).join('; ') || `${segs} controles`);
        const largas = [['privacy', 'plg_engage_gravatar', 'mode'], ['privacy', 'plg_engage_gravatar', 'rating']];
        for (const [sec, scope, key] of (process.env.SOLO_MEDIDAS === '1' ? [] : largas)) {
            await p.click(`[data-eg-tab="${sec}"]`);
            const sel = `[data-eg-row][data-scope="${scope}"][data-key="${key}"]`;
            const n = await p.locator(`${sel} [role=radio]`).count();
            const inicial = await p.locator(`${sel} [role=radio][aria-checked=true]`).getAttribute('data-value');
            await p.locator(`${sel} [role=radio][aria-checked=true]`).focus();
            const visitados = []; let fuera = 0;
            for (let i = 0; i < n; i++) {
                await p.keyboard.press('ArrowRight'); await espera(p, sel); await p.waitForTimeout(150);
                const e = await p.evaluate(s => { const a = document.activeElement; const rc = a.getBoundingClientRect(), sr = document.querySelector(s + ' .eg-seg').getBoundingClientRect(); return {v: a.dataset.value, ok: a.closest(s) !== null && rc.left >= sr.left - 1 && rc.right <= sr.right + 1 && rc.width > 0, mark: a.getAttribute('aria-checked')}; }, sel);
                visitados.push(e.v); if (!e.ok || e.mark !== 'true') fuera++;
                await p.evaluate(s => { const rw = document.querySelector(s); rw.classList.remove('is-saved', 'is-error', 'is-saving'); }, sel);
            }
            const final = await p.locator(`${sel} [role=radio][aria-checked=true]`).getAttribute('data-value');
            r(`D-flechas-${w}-${key}`, `${w}px, "${key}": las flechas recorren las ${n} opciones (largas) sin salirse del control y el valor vuelve a "${inicial}"`, n >= 3 && new Set(visitados).size === n && fuera === 0 && final === inicial, `${visitados.join('>')}; fuera ${fuera}`);
        }
        await c.close();
    }
    // capturas ANTES/DESPUES de Clasificacion (1920 claro, fila entera) y de Privacidad (ya hechas por anchos)
    {
        const {c, p} = await login({width: 1920, height: 1000});
        await abrir(p, 'light'); await p.click('[data-eg-tab="privacy"]');
        for (const key of ['mode', 'rating']) await p.locator(`[data-eg-row][data-key="${key}"]`).screenshot({path: `${OUT}/${ANTES ? 'antes' : 'despues'}-fila-${key}-1920.png`});
        await c.close();
    }
    await b.close();
    restaurar();
    console.log(`\nRESUMEN: ${filasTot} filas medidas (suma sobre ${medidas} combinaciones ancho x diseno; ${apiladas} apiladas, ${enLinea} en linea); solapes ${solapesTot}; columna de texto estrecha ${estrechasTot} (minimo ${minTxt}px); textos > 12 lineas ${lineasTot}; desbordes ${desbTot}; contraste ${contrasteMalos} fallos de ${contrasteTot} medidas`);
    for (const k of Object.keys(malos)) if (malos[k].length) console.log(`  ${k}: ${malos[k].length}, ejemplos ${JSON.stringify(malos[k].slice(0, 4))}`);
    const f = res.filter(x => x.estado === 'FALLA').length;
    console.log(`\n${res.length - f} PASA / ${f} FALLA`);
    fs.writeFileSync(`${WORK}/resultados-maquetacion${ANTES ? '-antes' : ''}.json`, JSON.stringify({resumen: {filasTot, medidas, solapesTot, estrechasTot, lineasTot, desbTot, contrasteMalos, contrasteTot, apiladas, enLinea, minTxt}, malos, res}, null, 1));
    process.exit(ANTES ? 0 : f ? 1 : 0);
})().catch(e => { try { restaurar(); } catch (x) {} console.error(e); process.exit(1); });
