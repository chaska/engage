/**
 * 0.6.27: CONTROLES SEGMENTADOS de la pantalla de Opciones, en un navegador real (Chromium + Playwright) sobre el Joomla de pruebas, panel en es-ES.
 * Fallo real de la 0.6.26 (Joomla 6.1.4, escritorio): «Nivel maximo de anidacion» (1 a 6) se apilaba a todo el ancho en dos filas desiguales (4+2)
 * porque la regla miraba el NUMERO de opciones y no el ancho real del texto. Para CADA segmentado de CADA categoria, a 1920/1280/768/390 px x los
 * 3 disenos (Claro/Medio/Oscuro):
 *   (a) etiquetas CORTAS (suma <= 32 caracteres y ninguna > 12): todos los segmentos en UNA sola fila, de igual anchura (+-1 px), sin
 *       desbordes y, si el control va en la misma linea que el texto, pegado al borde derecho de la fila como los demas controles;
 *   (b) etiquetas LARGAS (alguna > 24 o suma > 40): sin solapes entre segmentos, texto y control, y con todos los segmentos dentro del control;
 *   (c) ninguna ayuda recortada sin forma de verla entera: si el texto breve termina en «...» (recorte del servidor) o la ayuda tiene line-clamp o
 *       desborde oculto, existe un control (<details>/<summary> o boton con aria-expanded) alcanzable con el TECLADO que muestra el texto completo;
 *   (d) 0 solapes de cajas y de texto real, 0 desbordes de la fila/control/segmento y contraste >= 4,5:1 (texto) y 3:1 (controles).
 * ANTES=1 solo informa (sale 0 aunque haya fallos) para documentar la 0.6.26. Entorno: BASE_URL, WORK, DB_NAME, PW_MODULE, CHROMIUM, OUT, ANTES.
 */
const {execFileSync} = require('child_process');
const fs = require('fs');
const {chromium} = require(process.env.PW_MODULE || 'playwright');
const medirEnPagina = require('./contraste-pagina.js');
const BASE = process.env.BASE_URL || 'http://127.0.0.1:8080';
const WORK = process.env.WORK;
const OUT = process.env.OUT || `${WORK}/segmentados-capturas`;
const DB = process.env.DB_NAME || 'joomla_test';
const ANTES = process.env.ANTES === '1';
const ADMIN_PASS = process.env.ADMIN_PASS || ('Aa1!' + fs.readFileSync(`${WORK}/adminpass.txt`, 'utf8').trim());
const res = [];
function r(id, desc, ok, ev = '') { res.push({id, desc, estado: ok ? 'PASA' : 'FALLA', ev}); console.log(`${id.padEnd(30)} ${ok ? 'PASA ' : 'FALLA'} ${desc}${ev ? '  [' + ev + ']' : ''}`); }
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

function mostrarFilas() {
    const panel = [...document.querySelectorAll('[role=tabpanel]')].find(x => !x.hidden);
    panel.querySelectorAll('[data-eg-row]').forEach(rw => { rw.hidden = false; });
    return new Promise(ok => requestAnimationFrame(() => requestAnimationFrame(() => ok())));
}

/** DENTRO de la pagina: mide los segmentados y las ayudas de la categoria abierta. */
function medir(rem) {
    const rect = e => e.getBoundingClientRect();
    const cruza = (a, b) => { const x = Math.min(a.right, b.right) - Math.max(a.left, b.left), y = Math.min(a.bottom, b.bottom) - Math.max(a.top, b.top); return x > 1 && y > 1; };
    const panel = [...document.querySelectorAll('[role=tabpanel]')].find(x => !x.hidden);
    const o = {segs: [], cortas: 0, largas: 0, medias: 0, unaFila: [], igual: [], desborde: [], alineado: [], solapes: [], largasMal: [], ayudas: 0, recortadas: [], resumen: []};
    for (const rw of panel.querySelectorAll('[data-eg-row]')) {
        const key = `${rw.dataset.scope}/${rw.dataset.key}`;
        const sg = rw.querySelector('.eg-seg');
        const T = rw.querySelector('.eg-row__text'), C = rw.querySelector('.eg-row__ctl'), H = rw.querySelector('.eg-row__help');
        const R = rect(rw), tr = rect(T), cr = rect(C);
        // (c) ayuda: ¿recortada? (line-clamp / overflow oculto) o acortada por el servidor (termina en "…")
        if (H) {
            o.ayudas++;
            const s = getComputedStyle(H), clamp = (s.webkitLineClamp && s.webkitLineClamp !== 'none') || (s.maxHeight !== 'none' && s.overflowY !== 'visible');
            const oculto = H.scrollHeight > H.clientHeight + 1 && s.overflowY !== 'visible';
            const acortada = /…$|\.\.\.$/.test(H.textContent.trim());
            const det = rw.querySelector('details.eg-more'), btn = rw.querySelector('button[aria-expanded]');
            if (clamp || oculto || acortada) {
                const completo = det ? det.querySelector('p') : null;
                const base = H.textContent.trim().replace(/(…|\.\.\.)$/, '').trim();
                const ok = !!((det && det.querySelector('summary')) || btn) && !!completo && completo.textContent.trim().length > H.textContent.trim().length - 1 && completo.textContent.trim().startsWith(base.slice(0, 40));
                o.recortadas.push({fila: key, ok, tipo: clamp ? 'line-clamp' : oculto ? 'overflow' : 'servidor', control: det ? 'details' : btn ? 'boton' : 'ninguno'});
            }
        }
        if (!sg) continue;
        const bts = [...sg.querySelectorAll('.eg-seg__btn')];
        const labels = bts.map(b => b.textContent.trim());
        const total = labels.join('').length, max = Math.max(...labels.map(l => l.length));
        const tipo = (total <= 32 && max <= 12) ? 'corta' : (max > 24 || total > 40) ? 'larga' : 'media';
        const rs = bts.map(rect), sr = rect(sg);
        o.segs.push({fila: key, tipo, n: bts.length, total, max, filas: new Set(rs.map(x => Math.round(x.top))).size, anchos: rs.map(x => Math.round(x.width)).join('/'), ancho: Math.round(sr.width)});
        o[tipo === 'corta' ? 'cortas' : tipo === 'larga' ? 'largas' : 'medias']++;
        const desb = [];
        if (sg.scrollWidth > sg.clientWidth + 1) desb.push(`scroll del control ${sg.scrollWidth - sg.clientWidth}px`);
        if (sr.right > R.right + 1 || sr.left < R.left - 1) desb.push('control fuera de la fila');
        for (const b of bts) { const x = rect(b); if (x.right > sr.right + 1 || x.left < sr.left - 1 || x.bottom > sr.bottom + 1 || x.top < sr.top - 1) { desb.push('segmento fuera del control'); break; } if (b.scrollWidth > b.clientWidth + 1) { desb.push('texto de segmento desbordado'); break; } }
        if (desb.length) o.desborde.push({fila: key, que: desb.join(', ')});
        // solapes entre segmentos, y de texto/ayuda/etiqueta contra el control
        const ps = [...bts.map((b, i) => [`seg#${i}`, rect(b)]), ['control', cr]];
        const g = e => { const q = document.createRange(); q.selectNodeContents(e); const z = [...q.getClientRects()].filter(x => x.width > 0 && x.height > 0); return z.length ? {left: Math.min(...z.map(x => x.left)), right: Math.max(...z.map(x => x.right)), top: Math.min(...z.map(x => x.top)), bottom: Math.max(...z.map(x => x.bottom))} : null; };
        for (const [n, e] of [['etiqueta', rw.querySelector('.eg-row__label')], ['ayuda', H]]) { const x = e && g(e); if (x) ps.push([n + ' (texto real)', x]); }
        const mal = [];
        for (let i = 0; i < ps.length; i++) for (let j = i + 1; j < ps.length; j++) {
            if (ps[i][0] === 'control' && ps[j][0].startsWith('seg#')) continue;
            if (ps[j][0] === 'control' && ps[i][0].startsWith('seg#')) continue;
            if (cruza(ps[i][1], ps[j][1])) mal.push(`${ps[i][0]}~${ps[j][0]}`);
        }
        if (mal.length) o.solapes.push({fila: key, que: mal.slice(0, 3).join(', ')});
        const anchoT = tr.width >= 12 * rem - 1 || tr.width >= R.width * .9;
        if (!anchoT) o.solapes.push({fila: key, que: `columna de texto estrecha ${Math.round(tr.width)}px`});
        if (tipo !== 'larga') {
            const tops = rs.map(x => x.top), ws = rs.map(x => x.width);
            if (tipo === 'corta' && Math.max(...tops) - Math.min(...tops) > 1) o.unaFila.push({fila: key, filas: new Set(tops.map(Math.round)).size});
            if (Math.max(...ws) - Math.min(...ws) > 1) o.igual.push({fila: key, anchos: ws.map(Math.round).join('/')});
            if (tipo === 'corta') {
            const enLinea = cr.top < tr.bottom - 1 && tr.top < cr.bottom - 1;
            const pie = parseFloat(getComputedStyle(rw).paddingRight) || 0;
            if (enLinea && R.right - pie - sr.right > 8) o.alineado.push({fila: key, hueco: Math.round(R.right - pie - sr.right)});
            }
        } else if (tipo === 'larga') {
            // sin solapes ya comprobado arriba; ademas, cada segmento con su texto entero a la vista y todos los segmentos con el mismo ancho en una fila
            const filasTop = [...new Set(rs.map(x => Math.round(x.top)))];
            for (const t of filasTop) { const w = rs.filter(x => Math.round(x.top) === t).map(x => x.width); if (Math.max(...w) - Math.min(...w) > 1) { o.largasMal.push({fila: key, que: 'anchos desiguales en una fila ' + w.map(Math.round).join('/')}); break; } }
        }
    }
    return o;
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
    const anchos = (process.env.ANCHOS || '1920,1280,768,390').split(',').map(Number);
    const T = [['light', 'claro'], ['mid', 'medio'], ['dark', 'oscuro']];
    const tot = {segs: 0, cortas: 0, largas: 0, medias: 0, unaFila: 0, igual: 0, alineado: 0, desborde: 0, solapes: 0, largasMal: 0, ayudas: 0, recortadas: 0, recortadasMal: 0, contrasteMalos: 0, contrasteTot: 0};
    const malos = {unaFila: [], igual: [], alineado: [], desborde: [], solapes: [], largasMal: [], recortadasMal: [], contraste: []};
    let inventario = null;
    for (const w of anchos) {
        const {c, p} = await login({width: w, height: 1000});
        const rem = await p.evaluate(() => parseFloat(getComputedStyle(document.documentElement).fontSize));
        for (const [tema, nombre] of T) {
            await abrir(p, tema);
            const a = {segs: 0, cortas: 0, largas: 0, medias: 0, unaFila: 0, igual: 0, alineado: 0, desborde: 0, solapes: 0, largasMal: 0, recortadas: 0, recortadasMal: 0, cm: 0, ct: 0};
            const inv = [];
            for (const sec of SECS) {
                await p.click(`[data-eg-tab="${sec}"]`);
                await p.evaluate(mostrarFilas);
                const m = await p.evaluate(medir, rem);
                a.segs += m.segs.length; a.cortas += m.cortas; a.largas += m.largas; a.medias += m.medias; a.unaFila += m.unaFila.length; a.igual += m.igual.length; a.alineado += m.alineado.length;
                a.desborde += m.desborde.length; a.solapes += m.solapes.length; a.largasMal += m.largasMal.length; tot.ayudas += m.ayudas;
                a.recortadas += m.recortadas.length; a.recortadasMal += m.recortadas.filter(x => !x.ok).length;
                for (const k of ['unaFila', 'igual', 'alineado', 'desborde', 'solapes', 'largasMal']) malos[k].push(...m[k].map(x => ({w, nombre, ...x})));
                malos.recortadasMal.push(...m.recortadas.filter(x => !x.ok).map(x => ({w, nombre, ...x})));
                inv.push(...m.segs);
                const k = await p.evaluate(medirEnPagina);
                const mal = k.items.filter(i => i.ratio < i.min); a.cm += mal.length; a.ct += k.items.length;
                malos.contraste.push(...mal.slice(0, 3).map(x => ({w, nombre, sec, ...x})));
                if (nombre === 'claro' && (sec === 'design' || sec === 'privacy')) {
                    const clave = sec === 'design' ? 'max_level' : 'mode';
                    await p.locator(`[data-eg-row][data-key="${clave}"]`).screenshot({path: `${OUT}/${ANTES ? 'antes' : 'despues'}-${clave}-${w}.png`});
                }
            }
            if (w === anchos[0] && nombre === 'claro') inventario = inv;
            for (const k of Object.keys(a)) if (k !== 'cm' && k !== 'ct' && k !== 'recortadas') tot[k] += a[k];
            tot.recortadas += a.recortadas; tot.contrasteMalos += a.cm; tot.contrasteTot += a.ct;
            r(`S-${w}-${nombre}`, `${w}px, diseno ${nombre}: ${a.segs} segmentados (${a.cortas} cortos, ${a.medias} medios, ${a.largas} largos)`,
                a.segs >= 15 && a.cortas >= 4 && a.largas >= 8 && a.unaFila === 0 && a.igual === 0 && a.alineado === 0 && a.desborde === 0 && a.solapes === 0 && a.largasMal === 0 && a.recortadasMal === 0 && a.cm === 0,
                `cortos en 2+ filas ${a.unaFila}, anchos desiguales ${a.igual}, sin alinear a la derecha ${a.alineado}, desbordes ${a.desborde}, solapes ${a.solapes}, largos mal ${a.largasMal}, ayudas acortadas ${a.recortadas} (sin forma de verlas ${a.recortadasMal}), contraste ${a.cm}/${a.ct}`);
        }
        // (c) teclado: cada ayuda acortada se puede abrir con Tab + Intro / Espacio y muestra el texto completo (en claro)
        await abrir(p, 'light');
        let probadas = 0; const malTeclado = [];
        for (const sec of SECS) {
            await p.click(`[data-eg-tab="${sec}"]`);
            await p.evaluate(mostrarFilas);
            const filas = await p.$$(`#eg-panel-${sec} [data-eg-row]`);
            for (const rw of filas) {
                const H = await rw.$('.eg-row__help'); if (!H) continue;
                const txt = ((await H.textContent()) || '').trim(); if (!/…$|\.\.\.$/.test(txt)) continue;
                const sm = await rw.$('details.eg-more > summary, button[aria-expanded]'); const key = await rw.getAttribute('data-key');
                if (!sm) { malTeclado.push(`${key}: sin control`); continue; }
                probadas++;
                await sm.scrollIntoViewIfNeeded(); await sm.focus(); await p.keyboard.press('Shift+Tab'); await p.keyboard.press('Tab');
                const foco = await sm.evaluate(e => document.activeElement === e && e.matches(':focus-visible'));
                const estado = () => sm.evaluate(e => e.tagName === 'SUMMARY' ? e.parentElement.open : e.getAttribute('aria-expanded') === 'true');
                const antes = await estado();
                await p.keyboard.press('Enter'); const tras = await estado();
                const visto = await rw.evaluate(x => { const pp = x.querySelector('details.eg-more p'); if (!pp) return false; const b = pp.getBoundingClientRect(); return b.height > 0 && pp.scrollHeight <= pp.clientHeight + 1; });
                await p.keyboard.press(' '); const cerrado = await estado();
                if (!(foco && antes === false && tras === true && visto && cerrado === false)) malTeclado.push(`${key}: foco ${foco}, abre ${tras}, visible ${visto}, cierra ${!cerrado}`);
            }
        }
        r(`C-teclado-${w}`, `${w}px: cada ayuda acortada por el servidor se abre y se cierra con el teclado (Intro/Espacio), con foco visible y texto completo a la vista`, probadas >= 2 && malTeclado.length === 0, malTeclado.slice(0, 3).join('; ') || `${probadas} ayudas`);
        await c.close();
    }
    await b.close();
    restaurar();
    console.log('\nINVENTARIO (1920 claro): fila | tipo | n | suma | max | filas de segmentos | anchos');
    for (const s of inventario || []) console.log(`  ${s.fila} | ${s.tipo} | ${s.n} | ${s.total} | ${s.max} | ${s.filas} | ${s.anchos}`);
    console.log(`\nRESUMEN: ${tot.segs} medidas de segmentados (${tot.cortas} cortos, ${tot.medias} medios, ${tot.largas} largos; 12 combinaciones ancho x diseno); cortos en 2+ filas ${tot.unaFila}; anchos desiguales ${tot.igual}; sin alinear ${tot.alineado}; desbordes ${tot.desborde}; solapes ${tot.solapes}; largos mal ${tot.largasMal}; ayudas medidas ${tot.ayudas}, acortadas ${tot.recortadas}, sin forma de verlas ${tot.recortadasMal}; contraste ${tot.contrasteMalos} fallos de ${tot.contrasteTot}`);
    for (const k of Object.keys(malos)) if (malos[k].length) console.log(`  ${k}: ${malos[k].length}, ejemplos ${JSON.stringify(malos[k].slice(0, 4))}`);
    const f = res.filter(x => x.estado === 'FALLA').length;
    console.log(`\n${res.length - f} PASA / ${f} FALLA`);
    fs.writeFileSync(`${WORK}/resultados-segmentados${ANTES ? '-antes' : ''}.json`, JSON.stringify({resumen: tot, malos, res}, null, 1));
    process.exit(ANTES ? 0 : f ? 1 : 0);
})().catch(e => { try { restaurar(); } catch (x) {} console.error(e); process.exit(1); });
