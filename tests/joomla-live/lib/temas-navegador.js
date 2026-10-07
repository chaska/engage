/**
 * 0.6.23: temas visuales en un NAVEGADOR real (Chromium headless con Playwright) sobre el Joomla de pruebas.
 * Para cada tema (modern, minimal, dark) x pagina (clara: body #fff/#222; oscura: body #1a1a1a / color #f1f1f1 !important, como Helix en
 * modo oscuro; y dos "plantillas hostiles" que fuerzan el color de TODOS los elementos con !important DESPUES del CSS del tema) x pantalla
 * (escritorio 1100 px, movil 400 px), con un hilo de 3 niveles + comentario sin publicar + spam, visto como administrador (se ven los botones
 * de moderacion, IP, usuario y correo):
 *   - guarda una captura PNG de cada combinacion en la carpeta de salida;
 *   - MIDE el contraste WCAG 2.x de TODO el texto visible del contenedor (cada nodo de texto contra su fondo efectivo: se recorren los
 *     ancestros componiendo capas translucidas hasta un fondo opaco) y de los bordes de botones y campos (3:1);
 *   - mide cada boton con el raton encima (:hover);
 *   - umbral 4,5:1 para el texto (tambien botones: mas estricto que el 3:1 pedido) y 3:1 para el texto grande;
 *   - tambien con reply_style = soft / none, con "Cargar CSS personalizado" (comments.css) y con el SO en modo oscuro;
 *   - comprueba que classic NO carga ninguna hoja de tema y que los otros cargan exactamente la suya;
 *   - comprueba que reply_indent no cambia con el tema (misma sangria que classic) y que no hay desbordamiento horizontal.
 * NO se mide el editor TinyMCE de Joomla (.tox: barra, estado, "Toggle Editor"): es de Joomla y no de Engage.
 * Requisitos: 03-sembrar-y-probar.sh ya ejecutado. Entorno: BASE_URL, WORK, IDS_FILE, DB_NAME, PW_MODULE, CHROMIUM, OUT (capturas),
 * ADMIN_PASS (opcional; por defecto Aa1!<contenido de $WORK/adminpass.txt>). NO se escribe ninguna contrasena en el repositorio.
 */
const {execFileSync} = require('child_process');
const fs = require('fs');
const {chromium} = require(process.env.PW_MODULE || 'playwright');
const BASE = process.env.BASE_URL || 'http://127.0.0.1:8080';
const WORK = process.env.WORK;
const OUT = process.env.OUT || `${WORK}/temas-capturas`;
const ids = JSON.parse(fs.readFileSync(`${WORK}/${process.env.IDS_FILE || 'ids.json'}`, 'utf8'));
const DB = process.env.DB_NAME || 'joomla_test';
const AS = ids.art_publico_asset;
const PAGE = `${BASE}/index.php?option=com_content&view=article&id=${ids.art_publico}&catid=${ids.cat_publica}`;
const ADMIN_PASS = process.env.ADMIN_PASS || ('Aa1!' + fs.readFileSync(`${WORK}/adminpass.txt`, 'utf8').trim());
const res = [];
function r(id, desc, ok, ev = '') { res.push({id, desc, estado: ok ? 'PASA' : 'FALLA', ev}); console.log(`${id.padEnd(36)} ${ok ? 'PASA ' : 'FALLA'} ${desc}${ev ? '  [' + ev + ']' : ''}`); }
function sql(s) { return execFileSync('mysql', ['-uroot', '-N', DB, '-e', s]).toString().trim(); }
function setParams(p) { sql(`UPDATE jos_extensions SET params='${JSON.stringify(p)}' WHERE element='com_engage' AND type='component'`); }
const ins = (parent, body, name, enabled, created) => sql(`INSERT INTO jos_engage_comments (asset_id,parent_id,body,name,email,ip,user_agent,enabled,created,created_by) VALUES (${AS},${parent},'${body}','${name}','${name.toLowerCase()}@example.invalid','203.0.113.7','prueba',${enabled},'${created}',0); SELECT LAST_INSERT_ID();`);
fs.mkdirSync(OUT, {recursive: true});

const PAGINAS = {
    clara: 'html,body{background:#fff;color:#222}',
    oscura: 'html,body{background:#1a1a1a;color:#f1f1f1 !important}',
    // plantillas hostiles: cargadas DESPUES del CSS del tema, fuerzan el color de todos los elementos
    'hostil-clara': 'html,body,body *{color:#f5f5f5 !important} body{background:#222 !important}',
    'hostil-oscura': 'html,body,body *{color:#111 !important} body{background:#fff !important}',
};
const PANTALLAS = {escritorio: {width: 1100, height: 1000}, movil: {width: 400, height: 900}};

// ---- Se ejecuta DENTRO de la pagina: mide el contraste de todo el texto del contenedor ----
function medirEnPagina() {
    const parse = c => { const m = c.match(/rgba?\(([^)]+)\)/); if (!m) return null; const p = m[1].split(/[ ,\/]+/).filter(Boolean).map(Number); return {r: p[0], g: p[1], b: p[2], a: p.length > 3 ? p[3] : 1}; };
    const over = (f, b) => ({r: f.r * f.a + b.r * (1 - f.a), g: f.g * f.a + b.g * (1 - f.a), b: f.b * f.a + b.b * (1 - f.a), a: 1});
    const lum = c => { const f = v => { v /= 255; return v <= .03928 ? v / 12.92 : Math.pow((v + .055) / 1.055, 2.4); }; return .2126 * f(c.r) + .7152 * f(c.g) + .0722 * f(c.b); };
    const ratio = (a, b) => { const x = lum(a), y = lum(b); return (Math.max(x, y) + .05) / (Math.min(x, y) + .05); };
    const fondo = el => {
        const capas = []; let imagen = false;
        for (let e = el; e; e = e.parentElement) {
            const s = getComputedStyle(e); const c = parse(s.backgroundColor);
            if (s.backgroundImage && s.backgroundImage !== 'none') imagen = true;
            if (c && c.a > 0) { capas.push(c); if (c.a >= 1) break; }
        }
        let base = {r: 255, g: 255, b: 255, a: 1};
        for (let i = capas.length - 1; i >= 0; i--) base = over(capas[i], base);
        return {c: base, imagen};
    };
    const visible = el => { if (el.closest('.tox, .tox-tinymce, .js-editor-tinymce, .tox-tinymce-aux, .toggle-editor')) return false; const s = getComputedStyle(el); if (s.display === 'none' || s.visibility === 'hidden' || +s.opacity === 0) return false; const r = el.getClientRects(); return r.length > 0 && r[0].width > 0 && r[0].height > 0; };
    const rol = el => {
        if (el.closest('.akengage-comment-reply-btn')) return 'boton-responder';
        if (el.closest('.akengage-comment-replyto-link')) return 'cita-enlace';
        if (el.closest('.akengage-comment-replyto')) return 'cita';
        if (el.closest('.akengage-comment-actions')) return 'boton-moderacion';
        if (el.closest('.akengage-comment-permalink')) return 'fecha';
        if (el.closest('[itemprop=name]')) return 'nombre';
        if (el.closest('.akengage-comment-body a')) return 'enlace';
        if (el.closest('.akengage-comment-body')) return 'texto';
        if (el.closest('.akengage-comment-publish-type')) return 'franja-estado';
        if (el.closest('.akengage-comment-properties')) return 'cabecera-otro';
        if (el.closest('#akengageCommentForm')) return 'formulario';
        if (el.closest('h3.akengage-title')) return 'titulo';
        if (el.closest('.akengage-pagination')) return 'paginacion';
        return 'otro';
    };
    const sec = document.querySelector('section.akengage-outer-container');
    const out = [];
    if (!sec) return {error: 'sin contenedor'};
    const w = document.createTreeWalker(sec, NodeFilter.SHOW_TEXT);
    const vistos = new Set();
    for (let n; (n = w.nextNode());) {
        const t = n.nodeValue.trim(); const el = n.parentElement;
        if (!t || /^(SCRIPT|STYLE|NOSCRIPT|OPTION)$/.test(el.tagName) || !visible(el) || vistos.has(el)) continue;
        vistos.add(el);
        const s = getComputedStyle(el); const bg = fondo(el); let fg = parse(s.color); fg = over(fg, bg.c);
        const px = parseFloat(s.fontSize), bold = parseInt(s.fontWeight, 10) >= 700, grande = px >= 24 || (px >= 18.66 && bold);
        out.push({tipo: 'texto', rol: rol(el), t: t.slice(0, 40), ratio: ratio(fg, bg.c), min: grande ? 3 : 4.5, imagen: bg.imagen, sel: el.tagName.toLowerCase() + '.' + String(el.className).split(' ')[0]});
    }
    sec.querySelectorAll('input:not([type=hidden]):not([type=checkbox]):not([type=radio]), textarea, select').forEach(el => {
        if (!visible(el)) return;
        const s = getComputedStyle(el); const bg = fondo(el); const fg = over(parse(s.color), bg.c);
        out.push({tipo: 'texto', rol: 'campo', t: el.name || el.id, ratio: ratio(fg, bg.c), min: 4.5, imagen: bg.imagen, sel: el.tagName.toLowerCase()});
        const bc = parse(s.borderTopColor); if (bc && parseFloat(s.borderTopWidth) > 0) { const pb = fondo(el.parentElement); out.push({tipo: 'borde', rol: 'campo-borde', t: el.name || el.id, ratio: ratio(over(bc, pb.c), pb.c), min: 3, sel: el.tagName.toLowerCase()}); }
    });
    sec.querySelectorAll('button.btn').forEach(el => {
        if (!visible(el)) return;
        const s = getComputedStyle(el); const bc = parse(s.borderTopColor); const pb = fondo(el.parentElement);
        // limite visible del boton (WCAG 1.4.11): vale el mejor entre su borde y su relleno opaco
        const fl = parse(s.backgroundColor); let rr = 0;
        if (bc && parseFloat(s.borderTopWidth) > 0) rr = Math.max(rr, ratio(over(bc, pb.c), pb.c));
        if (fl && fl.a >= 1) rr = Math.max(rr, ratio(fl, pb.c));
        out.push({tipo: 'borde', rol: 'boton-borde', t: el.textContent.trim().slice(0, 20), ratio: rr, min: 3, sel: 'button'});
    });
    const arts = [...sec.querySelectorAll('article[id^=akengage-comment-]')].map(a => ({id: a.id, clase: a.className.match(/akengage-comment--\w+/)[0], borde: getComputedStyle(a).borderLeftColor, fondo: getComputedStyle(a).backgroundColor, x: Math.round(a.getBoundingClientRect().left)}));
    return {items: out, arts, desborda: sec.scrollWidth > sec.clientWidth + 1, hojas: [...document.styleSheets].map(h => h.href || '').filter(h => /com_engage\/css/.test(h)).map(h => h.split('/css/')[1].split('?')[0]), clase: sec.className};
}
// Contraste del texto de UN boton en el estado actual (p. ej. con el raton encima). Se ejecuta dentro de la pagina.
function medirBoton(i) {
    const e = [...document.querySelectorAll('section.akengage-outer-container button')].filter(x => !x.closest('.tox, .tox-tinymce, .toggle-editor'))[i];
    const parse = c => { const m = c.match(/rgba?\(([^)]+)\)/); const p = m[1].split(/[ ,\/]+/).filter(Boolean).map(Number); return {r: p[0], g: p[1], b: p[2], a: p.length > 3 ? p[3] : 1}; };
    const L = c => [c.r, c.g, c.b].map(v => { v /= 255; return v <= .03928 ? v / 12.92 : Math.pow((v + .055) / 1.055, 2.4); }).reduce((a, v, k) => a + v * [.2126, .7152, .0722][k], 0);
    const s = getComputedStyle(e); const fg = parse(s.color); let bg = parse(s.backgroundColor);
    if (bg.a < 1) { let p = e.parentElement, capas = [bg]; for (; p; p = p.parentElement) { const q = parse(getComputedStyle(p).backgroundColor); if (q.a > 0) { capas.push(q); if (q.a >= 1) break; } } let base = {r: 255, g: 255, b: 255, a: 1}; for (let k = capas.length - 1; k >= 0; k--) { const c = capas[k]; base = {r: c.r * c.a + base.r * (1 - c.a), g: c.g * c.a + base.g * (1 - c.a), b: c.b * c.a + base.b * (1 - c.a), a: 1}; } bg = base; }
    const x = L(fg), y = L(bg);
    return {t: e.textContent.trim().slice(0, 24), ratio: (Math.max(x, y) + .05) / (Math.min(x, y) + .05), fg: s.color, bg: s.backgroundColor};
}
// -------------------------------------------------------------------------------------------

(async () => {
    sql(`DELETE FROM jos_engage_comments WHERE asset_id=${AS}`);
    const base = {default_publish: '1', max_level: '3', comments_ordering: 'asc', reactions_enabled: '0', sort_selector: '0', copy_link: '0', show_badges: '0'}; // 0.7.0: las reacciones tienen su propia prueba (17-reacciones.sh)
    const cuerpo = '<p>Texto principal con <a href="https://example.org/enlace">un enlace</a> y <strong>negrita</strong>.</p><blockquote><p>Una cita dentro del comentario</p></blockquote><pre>codigo = 1</pre>';
    const raiz = ins('NULL', cuerpo, 'Lute', 1, '2025-03-12 10:00:00');
    sql(`UPDATE jos_engage_comments SET modified_by=518, modified='2026-10-04 12:00:00' WHERE id=${raiz}`);
    const resp = ins(raiz, '<p>Primera respuesta al hilo</p>', 'ChasKa', 1, '2026-10-04 10:00:00');
    ins(resp, '<p>Respuesta de tercer nivel</p>', 'Lute', 1, '2026-10-04 11:00:00');
    ins('NULL', '<p>Comentario sin publicar, visible solo para moderacion</p>', 'Pendiente', 0, '2026-10-04 12:00:00');
    ins('NULL', '<p>Comentario marcado como posible spam</p>', 'Spammer', -3, '2026-10-04 13:00:00');
    setParams(base);

    const b = await chromium.launch({executablePath: process.env.CHROMIUM || '/opt/pw-browsers/chromium', args: ['--no-sandbox']});
    const ctxAdmin = async (viewport, colorScheme) => {
        const c = await b.newContext({viewport, colorScheme: colorScheme || 'light'});
        const p = await c.newPage();
        await p.goto(`${BASE}/index.php?option=com_users&view=login`);
        await p.fill('#username', 'admintest'); await p.fill('#password', ADMIN_PASS);
        await Promise.all([p.waitForNavigation(), p.locator('form button[type=submit]').first().click()]);
        return {c, p};
    };
    const peorGlobal = {};
    const registrar = (clave, m) => { for (const i of m.items) { const k = clave + '|' + i.rol; const v = i.ratio / i.min; if (!(k in peorGlobal) || v < peorGlobal[k].v) peorGlobal[k] = {v, ratio: i.ratio, min: i.min, t: i.t, rol: i.rol, tipo: i.tipo}; } };
    const peorHover = {};

    const correr = async (id, tema, pagina, pant, extra = {}) => {
        setParams({...base, theme: tema, ...(extra.params || {})});
        const {c, p} = await ctxAdmin(PANTALLAS[pant], extra.os);
        await p.goto(PAGE, {waitUntil: 'load'});
        await p.addStyleTag({content: PAGINAS[pagina]});
        await p.waitForTimeout(500);
        const m = await p.evaluate(medirEnPagina);
        if (m.error) { r(id, 'sin contenedor de comentarios', false, m.error); await c.close(); return null; }
        const malos = m.items.filter(i => i.ratio < i.min);
        const peor = m.items.reduce((a, i) => (i.ratio / i.min < a.ratio / a.min ? i : a), m.items[0]);
        const roles = new Set(m.items.map(i => i.rol));
        const nec = extra.necesarios || ['texto', 'nombre', 'fecha', 'enlace', 'boton-responder', 'cita', 'cita-enlace', 'boton-moderacion', 'franja-estado', 'formulario', 'titulo'];
        const faltan = nec.filter(x => !roles.has(x));
        const sinImg = m.items.every(i => !i.imagen);
        r(id, `contraste: ${m.items.length} medidos, peor ${peor.ratio.toFixed(2)}:1 (${peor.rol}, min ${peor.min})`, malos.length === 0 && faltan.length === 0 && sinImg,
            malos.slice(0, 4).map(i => `${i.rol} "${i.t}" ${i.ratio.toFixed(2)}<${i.min}`).join('; ') + (faltan.length ? ' FALTAN roles: ' + faltan.join(',') : '') + (sinImg ? '' : ' fondo con imagen'));
        registrar(tema + '|' + pant + '|' + pagina, m);
        if (!extra.sinCaptura) {
            const f = `${OUT}/${id.replace(/[^\w.-]+/g, '_')}.png`;
            const cl = await p.evaluate(() => { const s = document.querySelector('section.akengage-outer-container'); const q = s.getBoundingClientRect(); return {x: 0, y: Math.max(0, q.top + scrollY), width: innerWidth, height: Math.min(q.height, 2600)}; });
            await p.screenshot({path: f, clip: cl, fullPage: true});
        }
        if (extra.hover) {
            const loc = p.locator('section.akengage-outer-container button:not(.tox *):not(.toggle-editor *)');
            const n = await loc.count();
            const malosH = []; let peorH = {ratio: 99};
            for (let i = 0; i < n; i++) {
                const el = loc.nth(i);
                if (!(await el.isVisible())) continue;
                await el.hover(); await p.waitForTimeout(320);
                const h = await p.evaluate(medirBoton, i);
                if (h.ratio < peorH.ratio) peorH = h;
                if (h.ratio < 4.5) malosH.push(`"${h.t}" ${h.ratio.toFixed(2)} (${h.fg} sobre ${h.bg})`);
            }
            await p.mouse.move(0, 0);
            const k = tema + '|' + pagina; if (!peorHover[k] || peorH.ratio < peorHover[k]) peorHover[k] = peorH.ratio;
            r(id + '/hover', `${n} botones con el raton encima, peor ${peorH.ratio.toFixed(2)}:1 ("${peorH.t}")`, malosH.length === 0 && n > 0, malosH.slice(0, 3).join('; '));
        }
        await c.close();
        return m;
    };

    // 1) hojas cargadas por tema
    {
        const c = await b.newContext({viewport: PANTALLAS.escritorio}); const p = await c.newPage();
        for (const [tema, hoja] of [['classic', null], ['modern', 'themes/moderno.css'], ['minimal', 'themes/minimalista.css'], ['dark', 'themes/oscuro.css'], ['desconocido', null]]) {
            setParams({...base, theme: tema});
            await p.goto(PAGE);
            const m = await p.evaluate(medirEnPagina);
            const themes = m.hojas.filter(h => h.startsWith('themes/'));
            r('hojas-' + tema, hoja ? `carga exactamente ${hoja} y la clase akengage-theme--${tema}` : 'no carga ninguna hoja de tema ni pone clase de tema (aspecto original)',
                hoja ? themes.length === 1 && themes[0] === hoja && m.clase.includes('akengage-theme--' + tema) : themes.length === 0 && !/akengage-theme--/.test(m.clase),
                `hojas=${m.hojas.join('+')} clase="${m.clase}"`);
        }
        setParams({...base, theme: 'modern', loadCustomCss: '1'});
        await p.goto(PAGE);
        const orden = await p.evaluate(() => [...document.styleSheets].map(h => h.href || '').filter(h => /com_engage\/css/.test(h)).map(h => h.split('/css/')[1].split('?')[0]));
        r('hojas-orden', 'con "Cargar CSS personalizado" el tema se carga despues de replies.css y de comments.css', orden.indexOf('themes/moderno.css') > orden.indexOf('replies.css') && orden.indexOf('themes/moderno.css') > orden.indexOf('comments.css') && orden.indexOf('comments.css') >= 0, orden.join(' < '));
        await c.close();
    }
    // 2) matriz principal: tema x pagina (clara, oscura, hostiles) x pantalla
    for (const tema of ['modern', 'minimal', 'dark']) {
        for (const pagina of ['clara', 'oscura', 'hostil-clara', 'hostil-oscura']) {
            for (const pant of ['escritorio', 'movil']) {
                const m = await correr(`${tema}_${pagina}_${pant}`, tema, pagina, pant, {hover: pant === 'escritorio'});
                if (!m) continue;
                const k3 = ['primary', 'spam', 'unpublished'];
                r(`${tema}_${pagina}_${pant}/estados`, 'publicado, spam y sin publicar tienen barra de color distinta', k3.every(k => m.arts.some(a => a.clase === 'akengage-comment--' + k)) && new Set(k3.map(k => m.arts.find(a => a.clase === 'akengage-comment--' + k).borde)).size === 3,
                    m.arts.map(a => a.clase.slice(17) + ':' + a.borde).join(' '));
                if (pant === 'movil') r(`${tema}_${pagina}_${pant}/desborde`, 'sin desbordamiento horizontal del contenedor a 400 px', !m.desborda);
            }
        }
    }
    // 3) reply_style soft / none (escritorio)
    for (const tema of ['modern', 'minimal', 'dark']) {
        for (const pagina of ['clara', 'oscura', 'hostil-clara']) {
            for (const st of ['soft', 'none']) await correr(`${tema}_${pagina}_estilo-${st}`, tema, pagina, 'escritorio', {params: {reply_style: st}, sinCaptura: true});
        }
    }
    // 3b) "Cargar CSS personalizado" (comments.css, con su bloque prefers-color-scheme: dark) x modo del dispositivo (Chromium colorScheme)
    //     x pagina x pantalla. Los temas claros deben seguir claros y el oscuro seguir oscuro con el dispositivo en modo oscuro.
    for (const tema of ['modern', 'minimal', 'dark']) {
        for (const so of ['light', 'dark']) {
            for (const pagina of ['clara', 'oscura', 'hostil-clara', 'hostil-oscura']) {
                for (const pant of ['escritorio', 'movil']) {
                    const m = await correr(`${tema}_cssPropio_SO${so}_${pagina}_${pant}`, tema, pagina, pant, {params: {loadCustomCss: '1'}, os: so, hover: pant === 'escritorio', sinCaptura: pagina !== 'clara'});
                    if (m) {
                        const claro = tema !== 'dark';
                        const a = m.arts.find(z => z.clase === 'akengage-comment--primary');
                        const lum = (c) => { const q = c.match(/[\d.]+/g).map(Number); return (q[0] + q[1] + q[2]) / 3; };
                        r(`${tema}_cssPropio_SO${so}_${pagina}_${pant}/tono`, claro ? 'la tarjeta es clara aunque el dispositivo este en modo oscuro' : 'la tarjeta es oscura aunque el dispositivo este en modo claro',
                            claro ? lum(a.fondo) > 200 : lum(a.fondo) < 80, a.fondo);
                    }
                }
            }
        }
    }
    // 3c) paginacion (default_limit bajo) con comments.css y el dispositivo en los dos modos
    for (const tema of ['modern', 'minimal', 'dark']) {
        for (const so of ['light', 'dark']) {
            for (const pagina of ['clara', 'oscura', 'hostil-clara']) {
                await correr(`${tema}_paginacion_SO${so}_${pagina}`, tema, pagina, 'escritorio', {params: {loadCustomCss: '1', default_limit: '2'}, os: so, sinCaptura: true, necesarios: ['paginacion', 'texto', 'fecha', 'titulo']});
            }
        }
    }
    // 4) reply_indent: la sangria con tema es la misma que con classic (plantilla real)
    {
        const xs = async (tema, ind) => {
            setParams({...base, theme: tema, reply_indent: ind});
            const {c, p} = await ctxAdmin(PANTALLAS.escritorio); await p.goto(PAGE);
            const m = await p.evaluate(medirEnPagina); await c.close();
            return [raiz, resp, +resp + 1].map(id => (m.arts.find(z => z.id === 'akengage-comment-' + id) || {x: -1}).x);
        };
        for (const ind of ['none', 'small', 'medium', 'large']) {
            const ref = await xs('classic', ind);
            for (const tema of ['modern', 'minimal', 'dark']) { const t = await xs(tema, ind); r(`indent-${ind}-${tema}`, `reply_indent=${ind}: misma sangria por nivel que classic`, t[0] >= 0 && JSON.stringify(t.map(v => v - t[0])) === JSON.stringify(ref.map(v => v - ref[0])), `classic=${ref.join(',')} ${tema}=${t.join(',')}`); }
        }
    }
    // 5) classic: mismos estilos calculados con y sin la opcion
    {
        const est = async (params) => {
            setParams({...base, ...params});
            const {c, p} = await ctxAdmin(PANTALLAS.escritorio); await p.goto(PAGE);
            const o = await p.evaluate(() => [...document.querySelectorAll('section.akengage-outer-container *')].slice(0, 400).map(e => { const s = getComputedStyle(e); return [e.tagName, s.color, s.backgroundColor, s.borderLeftWidth, s.borderLeftColor, s.paddingLeft, s.borderRadius, s.boxShadow, s.fontSize].join('|'); }).join('\n'));
            await c.close(); return o;
        };
        const sin = await est({}), con = await est({theme: 'classic'});
        r('classic-identico', 'theme=classic produce los mismos estilos calculados que sin la opcion', sin === con && sin.length > 1000, `${sin.split('\n').length} elementos comparados`);
    }
    // 6) galeria de invitado (sin botones de moderacion), pagina clara
    for (const tema of ['classic', 'modern', 'minimal', 'dark']) {
        setParams({...base, theme: tema});
        const c = await b.newContext({viewport: PANTALLAS.escritorio}); const p = await c.newPage();
        await p.goto(PAGE); await p.addStyleTag({content: PAGINAS.clara}); await p.waitForTimeout(400);
        const bx = await p.evaluate(() => { const l = document.querySelector('section.akengage-outer-container .akengage-list-container'); const q = l.getBoundingClientRect(); const x = Math.max(0, q.left - 16); return {x, y: q.top + scrollY - 8, width: Math.min(innerWidth - x, q.width + 32), height: q.height + 16}; });
        await p.screenshot({path: `${OUT}/galeria-${tema}.png`, clip: bx, fullPage: true});
        await c.close();
    }
    await b.close();
    sql(`DELETE FROM jos_engage_comments WHERE asset_id=${AS}`);
    setParams({default_publish: '1'});

    console.log('\nPeor contraste medido (ratio : minimo exigido)');
    const peorTema = {}, porRol = {};
    for (const [k, v] of Object.entries(peorGlobal)) {
        const [tema, pant, pag, rol] = k.split('|');
        if (!peorTema[tema] || v.v < peorTema[tema].v) peorTema[tema] = {...v, k};
        const kk = tema + '|' + rol; if (!porRol[kk] || v.ratio < porRol[kk]) porRol[kk] = v.ratio;
    }
    for (const [tema, v] of Object.entries(peorTema)) console.log(`  ${tema.padEnd(8)} ${v.ratio.toFixed(2)}:1 (min ${v.min}) en ${v.k} "${v.t}"`);
    for (const tema of ['modern', 'minimal', 'dark']) console.log('   ' + tema.padEnd(8) + Object.entries(porRol).filter(([k]) => k.startsWith(tema + '|')).map(([k, v]) => k.split('|')[1] + '=' + v.toFixed(1)).join('  ') + '  hover=' + Object.entries(peorHover).filter(([k]) => k.startsWith(tema + '|')).map(([, v]) => v).reduce((a, v) => Math.min(a, v), 99).toFixed(1));
    const f = res.filter(z => z.estado === 'FALLA').length;
    fs.writeFileSync(`${WORK}/resultados-temas.json`, JSON.stringify({res, peorTema, porRol, peorHover}, null, 1));
    console.log(`${res.length - f} PASA / ${f} FALLA`);
    process.exit(f ? 1 : 0);
})().catch(e => { console.error(e); process.exit(1); });
