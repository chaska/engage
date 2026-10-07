/**
 * 0.6.21: sangria y cita de las respuestas en un NAVEGADOR real (Chromium headless con Playwright).
 * 1) Simula una plantilla sin sangria para listas anidadas (como Helix Ultimate con Bootstrap estandar): se bloquea el CSS de
 *    Cassiopeia (que trae `.list-unstyled .list-unstyled {padding-left:20px}`) y se deja solo el reset de Bootstrap.
 *    Sin replies.css (comportamiento anterior) todos los comentarios quedan a la misma X; con replies.css cada nivel avanza.
 * 2) Con "Cargar CSS propio" activado no se carga replies.css y comments.css sigue indentando.
 * 3) Flujo real: clic en "Responder", el formulario recibe parent_id y muestra "En respuesta a", se envia y la respuesta nueva
 *    sale anidada bajo su padre con la cita.
 * Requisitos: 03-sembrar-y-probar.sh ya ejecutado. Entorno: BASE_URL, WORK, IDS_FILE, DB_NAME, PW_MODULE, CHROMIUM.
 */
const {execFileSync} = require('child_process');
const fs = require('fs');
const {chromium} = require(process.env.PW_MODULE || 'playwright');
const BASE = process.env.BASE_URL || 'http://127.0.0.1:8080';
const WORK = process.env.WORK;
const ids = JSON.parse(fs.readFileSync(`${WORK}/${process.env.IDS_FILE || 'ids.json'}`, 'utf8'));
const DB = process.env.DB_NAME || 'joomla_test';
const AS = ids.art_publico_asset;
const PAGE = `${BASE}/index.php?option=com_content&view=article&id=${ids.art_publico}&catid=${ids.cat_publica}`;
const res = [];
function r(id, desc, ok, ev = '') { res.push({id, desc, estado: ok ? 'PASA' : 'FALLA', ev}); console.log(`${id.padEnd(9)} ${ok ? 'PASA ' : 'FALLA'} ${desc}${ev ? '  [' + ev + ']' : ''}`); }
function sql(s) { return execFileSync('mysql', ['-uroot', '-N', DB, '-e', s]).toString().trim(); }
function setParams(p) { sql(`UPDATE jos_extensions SET params='${JSON.stringify(p)}' WHERE element='com_engage' AND type='component'`); }
const ins = (parent, body, name, created) => sql(`INSERT INTO jos_engage_comments (asset_id,parent_id,body,name,email,ip,user_agent,enabled,created,created_by) VALUES (${AS},${parent},'${body}','${name}','n@example.invalid','127.0.0.1','prueba',1,'${created}',0); SELECT LAST_INSERT_ID();`);

(async () => {
    sql(`DELETE FROM jos_engage_comments WHERE asset_id=${AS}`);
    setParams({reactions_enabled: '0', default_publish: '1', max_level: '3', comments_ordering: 'desc'});
    const raiz = ins('NULL', 'Raiz', 'Lute', '2025-03-12 10:00:00');
    const resp = ins(raiz, 'Respuesta', 'ChasKa', '2026-10-04 10:00:00');
    ins(resp, 'Nieta', 'Lute', '2026-10-04 11:00:00');
    const b = await chromium.launch({executablePath: process.env.CHROMIUM || '/opt/pw-browsers/chromium', args: ['--no-sandbox']});
    const medir = () => document.querySelectorAll('article[id^=akengage-comment-]').length && [...document.querySelectorAll('article[id^=akengage-comment-]')].map(a => ({
        t: a.querySelector('.akengage-comment-body').textContent.trim().slice(0, 9), x: Math.round(a.getBoundingClientRect().left),
        cita: a.querySelector('.akengage-comment-replyto') ? a.querySelector('.akengage-comment-replyto').textContent.trim().replace(/\s+/g, ' ') : null}));
    const sinPlantilla = async (bloquearReplies, extra) => {
        const p = await b.newPage({viewport: {width: 1000, height: 900}});
        await p.route('**/*', rt => { const u = rt.request().url(); if (/cassiopeia\/css\/template(\.min)?/.test(u) || (bloquearReplies && /replies\.css/.test(u))) { return rt.abort(); } rt.continue(); });
        await p.goto(PAGE);
        await p.addStyleTag({content: '.list-unstyled{padding-left:0;list-style:none}ul,ol{margin:0;padding:0}'});
        const o = await p.evaluate(medir);
        const css = await p.evaluate(() => [...document.styleSheets].map(s => s.href || '').filter(h => /replies\.css|comments\.css/.test(h)).map(h => h.split('/').pop().split('?')[0]));
        await p.close();
        return {o, css};
    };
    // 1) plantilla sin sangria
    let a = await sinPlantilla(true), d = await sinPlantilla(false);
    const xs = o => o.map(i => i.x);
    r('N-antes', 'sin replies.css (comportamiento anterior) la respuesta y la nieta quedan a la MISMA X que la raiz', new Set(xs(a.o)).size === 1, 'x=' + xs(a.o).join(','));
    r('N-despues', 'con replies.css cada nivel avanza (X estrictamente creciente por nivel)', d.o.length === 3 && xs(d.o)[0] < xs(d.o)[1] && xs(d.o)[1] < xs(d.o)[2], 'x=' + xs(d.o).join(',') + ' css=' + d.css.join('+'));
    r('N-cita', 'la respuesta y la nieta llevan la cita con el nombre de su padre y la raiz no', d.o.map(i => i.cita).join('|') === '|In reply to Lute|In reply to ChasKa', d.o.map(i => i.cita).join('|'));
    // 2) CSS propio activado: no se carga replies.css y comments.css indenta
    setParams({reactions_enabled: '0', default_publish: '1', max_level: '3', comments_ordering: 'desc', loadCustomCss: '1'});
    const c = await sinPlantilla(false);
    r('N-cssPropio', 'con "Cargar CSS propio" se cargan comments.css y replies.css y la sangria NO se duplica (20px por nivel, igual que sin el CSS propio)', c.css.includes('comments.css') && c.css.includes('replies.css') && xs(c.o)[1] - xs(c.o)[0] === 20 && xs(c.o)[2] - xs(c.o)[1] === 20, 'css=' + c.css.join('+') + ' x=' + xs(c.o).join(','));
    setParams({reactions_enabled: '0', default_publish: '1', max_level: '3', comments_ordering: 'desc'});
    // 2b) opciones de diseno (plantilla simulada sin sangria propia): sangria por nivel en px a 1000 px de ancho (1rem = 16px)
    const base = {reactions_enabled: '0', default_publish: '1', max_level: '3', comments_ordering: 'desc'};
    for (const [op, px] of [['none', 0], ['small', 12], ['medium', 20], ['large', 36]]) {
        setParams({...base, reply_indent: op});
        const m = await sinPlantilla(false);
        r('N-indent-' + op, `reply_indent=${op}: cada nivel avanza ${px}px`, xs(m.o)[1] - xs(m.o)[0] === px && xs(m.o)[2] - xs(m.o)[1] === px, 'x=' + xs(m.o).join(','));
    }
    setParams({...base, reply_indent: 'small'});
    {
        const p2 = await b.newPage({viewport: {width: 1000, height: 900}});
        await p2.route('**/*', rt => /cassiopeia\/css\/template(\.min)?/.test(rt.request().url()) ? rt.abort() : rt.continue());
        await p2.goto(PAGE);
        await p2.addStyleTag({content: '.list-unstyled{padding-left:0;list-style:none}ul,ol{margin:0;padding:0} :root{--akengage-reply-indent:50px}'});
        const o = await p2.evaluate(medir);
        r('N-var', 'la variable --akengage-reply-indent del CSS del usuario manda sobre la opcion (50px)', xs(o)[1] - xs(o)[0] === 50 && xs(o)[2] - xs(o)[1] === 50, 'x=' + xs(o).join(','));
        await p2.close();
        const p3 = await b.newPage({viewport: {width: 400, height: 900}});
        await p3.route('**/*', rt => /cassiopeia\/css\/template(\.min)?/.test(rt.request().url()) ? rt.abort() : rt.continue());
        await p3.goto(PAGE);
        await p3.addStyleTag({content: '.list-unstyled{padding-left:0;list-style:none}ul,ol{margin:0;padding:0}'});
        const o3 = await p3.evaluate(medir);
        r('N-movil', 'en pantalla estrecha (400px) la sangria pequena baja a 8px por nivel', xs(o3)[1] - xs(o3)[0] === 8, 'x=' + xs(o3).join(','));
        await p3.close();
    }
    // estilos (con la plantilla real, que trae Bootstrap): borde izquierdo y fondo del comentario raiz y de la respuesta
    const estilo = async () => {
        const pe = await b.newPage({viewport: {width: 1000, height: 900}});
        await pe.goto(PAGE);
        const o = await pe.evaluate(() => [...document.querySelectorAll('article[id^=akengage-comment-]')].map(a => { const c = getComputedStyle(a); return {borde: c.borderLeftWidth, fondo: c.backgroundColor}; }));
        await pe.close();
        return o;
    };
    for (const [op, bordeResp, fondoTrans] of [['line', '4px', true], ['soft', '0px', false], ['none', '0px', true]]) {
        setParams({...base, reply_style: op});
        const o = await estilo();
        const trans = o[1].fondo === 'rgba(0, 0, 0, 0)';
        r('N-style-' + op, `reply_style=${op}: raiz conserva su linea (4px); respuesta borde=${bordeResp}, fondo ${fondoTrans ? 'transparente' : 'suave'}`,
            o[0].borde === '4px' && o[1].borde === bordeResp && trans === fondoTrans && o[0].fondo === 'rgba(0, 0, 0, 0)', JSON.stringify(o));
    }
    // cita apagada
    setParams({...base, reply_show_quote: '0'});
    {
        const pq = await b.newPage(); await pq.goto(PAGE);
        r('N-sincita', 'reply_show_quote=0: no hay ninguna cita y las respuestas siguen anidadas', (await pq.locator('.akengage-comment-replyto').count()) === 0 && (await pq.locator('ul.akengage-comment-list--level2 article').count()) === 2);
        await pq.close();
    }
    setParams(base);
    // 3) flujo real: Responder -> formulario -> enviar
    const p = await b.newPage({viewport: {width: 1000, height: 900}});
    await p.goto(PAGE);
    await p.locator(`#akengage-comment-${resp} .akengage-comment-reply-btn`).click();
    const pid = await p.evaluate(() => document.forms['akengageCommentForm']['jform[parent_id]'].value);
    const banner = await p.evaluate(() => { const w = document.getElementById('akengage-comment-inreplyto-wrapper'); return w.classList.contains('d-none') ? '' : document.getElementById('akengage-comment-inreplyto-name').textContent; });
    r('N-responder', 'el boton Responder pone parent_id del comentario y el formulario muestra "En respuesta a" con su autor', pid === String(resp) && banner === 'ChasKa', `parent_id=${pid} banner=${banner}`);
    await p.fill('[name="jform[name]"]', 'Visitante'); await p.fill('[name="jform[email]"]', 'v@example.test');
    await p.waitForFunction(() => window.tinymce && tinymce.get('jform_commentText'), null, {timeout: 20000});
    await p.evaluate(() => tinymce.get('jform_commentText').setContent('<p>Contesto desde el navegador</p>'));
    await Promise.all([p.waitForNavigation(), p.locator('.akengage-comment-submit-btn').click()]);
    const nuevo = sql(`SELECT CONCAT(id,':',IFNULL(parent_id,0)) FROM jos_engage_comments WHERE body='Contesto desde el navegador'`).split(':');
    r('N-enviar', 'la respuesta enviada desde el navegador se guarda con parent_id = el comentario respondido', nuevo[1] === String(resp), 'guardado=' + nuevo.join(' parent='));
    await p.goto(PAGE);
    const lista = await p.evaluate(medir);
    const orden = lista.map(i => i.t).join(',');
    const i = lista.findIndex(x => x.t.startsWith('Contesto'));
    r('N-posicion', 'la respuesta nueva sale debajo de su padre (Respuesta), en el nivel 3 y con la cita a ChasKa; no es la primera de la lista', i > orden.split(',').indexOf('Respuesta') && lista[i].cita === 'In reply to ChasKa' && orden.startsWith('Raiz,Respuesta,'), 'orden=' + orden);
    await b.close();
    sql(`DELETE FROM jos_engage_comments WHERE asset_id=${AS}`);
    setParams({reactions_enabled: '0', default_publish: '1'});
    const f = res.filter(z => z.estado === 'FALLA').length;
    fs.writeFileSync(`${WORK}/resultados-respuestas-navegador.json`, JSON.stringify(res, null, 1));
    console.log(`${res.length - f} PASA / ${f} FALLA`);
    process.exit(f ? 1 : 0);
})().catch(e => { console.error(e); process.exit(1); });
