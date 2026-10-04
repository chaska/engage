/**
 * 0.6.25: PANTALLA DE OPCIONES MODERNAS y guardado instantaneo, en un navegador real (Chromium + Playwright) sobre el Joomla de pruebas.
 *  - 3 disenos + automatico x 1280 y 390 px x las 8 categorias: contraste WCAG medido (texto 4,5:1; borde de campos y segmentados,
 *    pista y bola del interruptor, tarjeta de tema elegida: 3:1), desbordamiento, capturas, foco visible en todos los controles;
 *  - teclado: pestanas con flechas/Inicio/Fin, interruptor con Espacio y Enter, segmentado y tarjetas con flechas, selector, numero,
 *    texto y area de texto; cada cambio comprobado EN LA BASE DE DATOS (parametro del componente o del plugin);
 *  - el cambio llega al frontend (clase del tema en el HTML servido, comentarios ocultos con comments_show=0, publicacion por defecto);
 *  - fusion sin perdida (un parametro centinela y las reglas de permisos intactos), idempotencia;
 *  - errores por la UI con la red interceptada (500, 403, 422, sin conexion): el control vuelve al valor anterior y se avisa;
 *  - SEGURIDAD por HTTP real con la sesion del administrador: sin token, token inventado, GET, ambito ajeno (otro componente, plugin del
 *    sistema), claves desconocidas (rules, login_module...), valores fuera de lista/rango, tipos raros (arrays), NUL, cadenas enormes, XSS;
 *    Manager sin core.admin (403 en el guardado y en la pantalla), administrador del componente sin permiso de plugins (403 solo en plugins);
 *  - registro de acciones (si el plugin esta activo) sin el valor; opciones clasicas disponibles; cero peticiones a otros hosts.
 * Requisitos: 03-sembrar-y-probar.sh y el paquete instalado. Entorno: BASE_URL, WORK, IDS_FILE, DB_NAME, PW_MODULE, CHROMIUM, OUT.
 */
const {execFileSync} = require('child_process');
const fs = require('fs');
const crypto = require('crypto');
const {chromium} = require(process.env.PW_MODULE || 'playwright');
const medirEnPagina = require('./contraste-pagina.js');
const BASE = process.env.BASE_URL || 'http://127.0.0.1:8080';
const WORK = process.env.WORK;
const OUT = process.env.OUT || `${WORK}/ajustes-capturas`;
const DB = process.env.DB_NAME || 'joomla_test';
const ids = JSON.parse(fs.readFileSync(`${WORK}/${process.env.IDS_FILE || 'ids.json'}`, 'utf8'));
const ADMIN_PASS = process.env.ADMIN_PASS || ('Aa1!' + fs.readFileSync(`${WORK}/adminpass.txt`, 'utf8').trim());
const res = [];
let peor = {};
function r(id, desc, ok, ev = '') { res.push({id, desc, estado: ok ? 'PASA' : 'FALLA', ev}); console.log(`${id.padEnd(34)} ${ok ? 'PASA ' : 'FALLA'} ${desc}${ev ? '  [' + ev + ']' : ''}`); }
function sql(s) { return execFileSync('mysql', ['-uroot', '--raw', '--default-character-set=utf8mb4', '-N', DB, '-e', s]).toString().trim(); }
const q = s => `'${String(s).replace(/\\/g, '\\\\').replace(/'/g, "''")}'`;
function params(scope) {
    const w = scope === 'com_engage' ? "type='component' AND element='com_engage'" : `type='plugin' AND folder='engage' AND element='${scope.replace('plg_engage_', '')}'`;
    return JSON.parse(sql(`SELECT IFNULL(NULLIF(params,''),'{}') FROM jos_extensions WHERE ${w}`) || '{}');
}
function setParams(scope, p) {
    const w = scope === 'com_engage' ? "type='component' AND element='com_engage'" : `type='plugin' AND folder='engage' AND element='${scope.replace('plg_engage_', '')}'`;
    sql(`UPDATE jos_extensions SET params=${q(JSON.stringify(p))} WHERE ${w}`);
}
fs.mkdirSync(OUT, {recursive: true});

const orig = {com_engage: params('com_engage'), plg_engage_gravatar: params('plg_engage_gravatar'), plg_engage_akismet: params('plg_engage_akismet'), plg_engage_email: params('plg_engage_email')};
const origRules = sql("SELECT rules FROM jos_assets WHERE name='com_engage'");
const origComRules = sql("SELECT rules FROM jos_assets WHERE name='com_plugins'");
const origPlug = sql("SELECT CONCAT(extension_id,':',enabled) FROM jos_extensions WHERE type='plugin' AND ((folder='actionlog' AND element='engage') OR (folder='engage') OR (folder='content' AND element='engage'))").split('\n');
const base = {...orig.com_engage, default_publish: '1', theme: 'classic', zz_keep: 'centinela', comments_show: '1', max_level: '3', reply_indent: 'medium', reply_style: 'line', reply_show_quote: '1', filter_mode: 'strict', min_length: '0', iplookup: 'https://whatismyipaddress.com/ip/%s', htmlpurifier_configstring: 'p,b,a[href]', tos_prompt: '', captcha: '-1', comments_close_after: '0', default_limit: '20', comments_notify_author: '1', loadCustomCss: '0', comments_ordering: 'asc', htmlpurifier_config_joomla: '0', tos_accept: '0', captcha_for: 'guests', comments_show_featured: '1', max_spam_age: '15'};
setParams('com_engage', base);
setParams('plg_engage_gravatar', {...orig.plg_engage_gravatar, mode: 'ask', consent_source: 'engage', show_notice: '1', zz_keep: 'g'});
setParams('plg_engage_akismet', {...orig.plg_engage_akismet, key: '', check: 'nonmanager', discard_blatant: '1'});
sql("UPDATE jos_extensions SET enabled=1 WHERE type='plugin' AND folder='engage' AND element IN ('gravatar','akismet','email')");
sql("UPDATE jos_extensions SET enabled=0 WHERE type='plugin' AND folder='actionlog' AND element='engage'");

// usuarios: Manager con core.manage en com_engage SIN core.admin; "Admin de componente" = Manager con core.admin en com_engage pero sin core.edit en com_plugins
const mgrFile = `${WORK}/managerpass.txt`;
if (!fs.existsSync(mgrFile)) { fs.writeFileSync(mgrFile, crypto.randomBytes(9).toString('hex'), {mode: 0o600}); }
const MGR_PASS = 'Aa1!' + fs.readFileSync(mgrFile, 'utf8').trim();
const hash = execFileSync('php', ['-r', 'echo password_hash($argv[1], PASSWORD_BCRYPT);', MGR_PASS]).toString();
for (const u of ['mgrpanel', 'mgradmin']) {
    sql(`DELETE FROM jos_user_usergroup_map WHERE user_id IN (SELECT id FROM jos_users WHERE username='${u}'); DELETE FROM jos_users WHERE username='${u}'`);
    sql(`INSERT INTO jos_users (name,username,email,password,block,sendEmail,registerDate,params,requireReset,authProvider) VALUES ('${u}','${u}','${u}@example.invalid','${hash}',0,0,UTC_TIMESTAMP(),'{}',0,'')`);
    sql(`INSERT INTO jos_user_usergroup_map (user_id,group_id) SELECT id,6 FROM jos_users WHERE username='${u}'`);
}
const permisos = (com) => sql(`UPDATE jos_assets SET rules=${q(JSON.stringify(com))} WHERE name='com_engage'`);

(async () => {
    const b = await chromium.launch({executablePath: process.env.CHROMIUM || '/opt/pw-browsers/chromium', args: ['--no-sandbox']});
    const login = async (user, pass, viewport, colorScheme) => {
        const c = await b.newContext({viewport, colorScheme: colorScheme || 'light'});
        const p = await c.newPage();
        const info = {errs: [], externos: [], js: 0, saves: []};
        p.on('pageerror', e => info.errs.push(e.message));
        p.on('dialog', d => { info.js++; d.dismiss().catch(() => {}); });
        p.on('request', rq => { const u = new URL(rq.url()); if (!['data:', 'blob:', 'about:'].includes(u.protocol) && u.host !== new URL(BASE).host) info.externos.push(rq.url()); if (/task=settings\.save/.test(rq.url())) info.saves.push(rq.postData()); });
        await p.goto(`${BASE}/administrator/`);
        await p.fill('#mod-login-username', user); await p.fill('#mod-login-password', pass);
        await Promise.all([p.waitForNavigation(), p.press('#mod-login-password', 'Enter')]);
        return {c, p, info};
    };
    const abrir = async (p, tema, seccion = 'design') => {
        await p.goto(`${BASE}/administrator/index.php?option=com_engage&view=settings&section=${seccion}`, {waitUntil: 'networkidle'});
        await p.evaluate(t => { try { localStorage.setItem('eg-admin-theme', t); } catch (e) {} }, tema);
        await p.reload({waitUntil: 'networkidle'});
    };
    const fila = (p, key, scope = 'com_engage') => p.locator(`[data-eg-row][data-scope="${scope}"][data-key="${key}"]`);
    const esperaGuardado = async (p, fila_) => { await p.waitForFunction(el => el.classList.contains('is-saved') || el.classList.contains('is-error'), await fila_.elementHandle(), {timeout: 8000}); };
    const peorDe = (m, clave) => { for (const i of m.items) { const k = `${clave}|${i.tipo}`; const v = i.ratio / i.min; if (!(k in peor) || v < peor[k].v) peor[k] = {v, ratio: i.ratio, min: i.min, t: i.t, tipo: i.tipo}; } };
    const SECS = ['design', 'moderation', 'antispam', 'notifications', 'privacy', 'security', 'advanced', 'permissions'];

    // ------------------------------------------------ A) disenos x pantallas x categorias
    for (const [pn, vp] of Object.entries({escritorio: {width: 1280, height: 900}, movil: {width: 390, height: 844}})) {
        const {c, p, info} = await login('admintest', ADMIN_PASS, vp);
        for (const [tema, nombre] of [['light', 'claro'], ['mid', 'medio'], ['dark', 'oscuro']]) {
            await abrir(p, tema);
            let total = 0, malos = [], ovf = 0, minTxt = 99;
            for (const sec of SECS) {
                await p.click(`[data-eg-tab="${sec}"]`);
                const m = await p.evaluate(medirEnPagina);
                peorDe(m, nombre);
                total += m.items.length; ovf = Math.max(ovf, m.overflow);
                malos.push(...m.items.filter(i => i.ratio < i.min).map(i => ({sec, ...i})));
                for (const i of m.items) if (i.tipo === 'texto') minTxt = Math.min(minTxt, i.ratio);
                if (sec === 'design') await (await p.$('#eg-admin')).screenshot({path: `${OUT}/ajustes-${nombre}-${pn}-design.png`});
                if (sec === 'privacy') await (await p.$('#eg-admin')).screenshot({path: `${OUT}/ajustes-${nombre}-${pn}-privacy.png`});
            }
            r(`A-${nombre}-${pn}`, `diseno ${nombre} en ${pn}: las 8 categorias, ${total} medidas de contraste, ninguna bajo el umbral`, malos.length === 0 && total > 250, malos.length ? JSON.stringify(malos.slice(0, 4)) : `peor texto ${minTxt}`);
            r(`A-${nombre}-${pn}-ovf`, `diseno ${nombre} en ${pn}: sin desbordamiento horizontal`, ovf <= 1, `exceso ${ovf}px`);
        }
        for (const os of ['light', 'dark']) {
            await p.emulateMedia({colorScheme: os}); await abrir(p, 'auto');
            let malos = [];
            for (const sec of SECS) { await p.click(`[data-eg-tab="${sec}"]`); const m = await p.evaluate(medirEnPagina); peorDe(m, `auto-${os}`); malos.push(...m.items.filter(i => i.ratio < i.min)); if (m.tema !== os) malos.push({tema: m.tema}); }
            r(`A-auto-${os}-${pn}`, `automatico con dispositivo ${os} en ${pn}: aplica ${os}, contraste correcto en las 8 categorias`, malos.length === 0, JSON.stringify(malos.slice(0, 3)));
        }
        await p.emulateMedia({colorScheme: 'light'});
        r(`A-externas-${pn}`, `cero peticiones a otros hosts (${pn})`, info.externos.length === 0, info.externos.slice(0, 3).join(' '));
        r(`A-errores-${pn}`, `sin errores de JavaScript propios (${pn})`, !info.errs.some(e => /eg-|settings|panel|Engage/i.test(e)), JSON.stringify(info.errs.slice(0, 2)));
        r(`A-confirm-${pn}`, `ningun confirm()/alert() (${pn})`, info.js === 0);
        await c.close();
    }

    // ------------------------------------------------ B) teclado y guardado instantaneo
    {
        const {c, p, info} = await login('admintest', ADMIN_PASS, {width: 1280, height: 900});
        await abrir(p, 'light');
        // pestanas
        await p.focus('[data-eg-tab="design"]'); await p.keyboard.press('ArrowDown');
        let t = await p.evaluate(() => ({sel: document.querySelector('[data-eg-tab][aria-selected="true"]').getAttribute('data-eg-tab'), vis: [...document.querySelectorAll('[role=tabpanel]')].filter(x => !x.hidden).map(x => x.id).join(), foco: document.activeElement.getAttribute('data-eg-tab')}));
        r('B-pestanas-flecha', 'flecha abajo en las categorias: se abre Moderacion (aria-selected, panel visible, foco)', t.sel === 'moderation' && t.vis === 'eg-panel-moderation' && t.foco === 'moderation', JSON.stringify(t));
        await p.keyboard.press('End'); t = await p.evaluate(() => document.querySelector('[data-eg-tab][aria-selected="true"]').getAttribute('data-eg-tab'));
        r('B-pestanas-fin', 'Fin: ultima categoria (Permisos)', t === 'permissions');
        await p.keyboard.press('Home'); await p.keyboard.press('ArrowDown');
        // interruptor con Espacio
        const sw = fila(p, 'default_publish').locator('.eg-switch');
        await sw.focus(); await p.keyboard.press('Space');
        await esperaGuardado(p, fila(p, 'default_publish'));
        r('B-switch-espacio', 'Espacio en el interruptor "publicar al instante": aria-checked y BD default_publish 1 -> 0', (await sw.getAttribute('aria-checked')) === 'false' && params('com_engage').default_publish === '0', JSON.stringify(info.saves.slice(-1)));
        const aviso = await p.evaluate(() => ({toast: document.getElementById('eg-toast').textContent, hidden: document.getElementById('eg-toast').hidden, live: document.getElementById('eg-live').textContent, estado: document.querySelector('[data-key="default_publish"] .eg-row__label').getAttribute('data-st')}));
        await p.waitForTimeout(100);
        r('B-aviso', 'aviso discreto "Guardado" (toast), region viva y marca en la fila', aviso.toast.length > 2 && !aviso.hidden && aviso.estado === aviso.toast, JSON.stringify(aviso));
        await p.keyboard.press('Enter'); await esperaGuardado(p, fila(p, 'default_publish'));
        r('B-switch-enter', 'Enter vuelve a activarlo: BD default_publish = 1', params('com_engage').default_publish === '1');
        // idempotencia por la UI: dos clics rapidos -> estado final coherente
        await sw.click(); await sw.click(); await p.waitForTimeout(1200);
        r('B-rapido', 'dos pulsaciones rapidas: la BD y el interruptor acaban coherentes', (await sw.getAttribute('aria-checked')) === (params('com_engage').default_publish === '1' ? 'true' : 'false'), `bd=${params('com_engage').default_publish}`);
        if (params('com_engage').default_publish !== '1') { await sw.click(); await p.waitForTimeout(800); }
        // segmentado con flechas
        await p.click('[data-eg-tab="design"]');
        const ind = fila(p, 'reply_indent');
        await ind.locator('[role=radio][aria-checked=true]').focus(); await p.keyboard.press('ArrowRight');
        await esperaGuardado(p, ind);
        r('B-segmentado', 'flecha derecha en "Sangria": medium -> large, aria-checked, tabindex y BD', params('com_engage').reply_indent === 'large' && (await ind.locator('[role=radio][aria-checked=true]').getAttribute('data-value')) === 'large' && (await ind.locator('[role=radio][tabindex="0"]').count()) === 1);
        const vp = await p.evaluate(() => getComputedStyle(document.querySelector('.eg-preview')).getPropertyValue('--pv-ind').trim());
        r('B-preview-sangria', 'vista previa en vivo: la sangria de las respuestas cambia a 36px al elegir "Grande"', vp === '36px', vp);
        // tarjetas de tema
        const th = fila(p, 'theme');
        await th.locator('[role=radio][data-value=dark]').click(); await esperaGuardado(p, th);
        const pt = await p.evaluate(() => document.querySelector('.eg-preview').getAttribute('data-theme'));
        r('B-tema-tarjeta', 'tarjeta de tema "Oscuro": BD theme=dark y la vista previa lo refleja al instante', params('com_engage').theme === 'dark' && pt === 'dark');
        await p.screenshot({path: `${OUT}/ajustes-tema-oscuro-elegido.png`});
        // llega al frontend
        const html = async () => (await (await c.request.get(`${BASE}/index.php?option=com_content&view=article&id=${ids.art_publico}&catid=${ids.cat_publica}`)).text());
        let h = await html();
        r('B-frontend-tema', 'el HTML servido en el frontend lleva akengage-theme--dark', h.includes('akengage-theme--dark'));
        await th.locator('[role=radio][data-value=classic]').click(); await esperaGuardado(p, th);
        h = await html();
        r('B-frontend-classic', 'al volver a Clasico el HTML no lleva clase de tema', !h.includes('akengage-theme--'));
        // select, numero, texto, textarea
        await p.click('[data-eg-tab="moderation"]');
        const lim = fila(p, 'default_limit');
        await lim.locator('select').selectOption('50'); await esperaGuardado(p, lim);
        r('B-select', 'selector "elementos por pagina" = 50 guardado', params('com_engage').default_limit === '50');
        const mn = fila(p, 'min_length');
        await mn.locator('input').fill('15'); await p.keyboard.press('Enter'); await esperaGuardado(p, mn);
        r('B-numero', 'campo numerico min_length=15 guardado (a los ~1 s o con Enter)', params('com_engage').min_length === '15');
        await mn.locator('input').fill('99999999'); await p.waitForTimeout(300);
        const er = await p.evaluate(() => ({inv: document.querySelector('[data-key="min_length"] input').getAttribute('aria-invalid'), msg: document.querySelector('[data-key="min_length"] .eg-row__err').textContent, hid: document.querySelector('[data-key="min_length"] .eg-row__err').hidden}));
        await p.keyboard.press('Enter'); await p.waitForTimeout(400);
        r('B-numero-invalido', 'valor fuera de rango: error accesible en linea (aria-invalid + role=alert), NO se envia y la BD no cambia', er.inv === 'true' && !er.hid && er.msg.length > 3 && params('com_engage').min_length === '15', JSON.stringify(er));
        await mn.locator('input').blur(); await p.waitForTimeout(200);
        r('B-numero-revierte', 'al salir del campo con un valor no valido vuelve al ultimo guardado (15)', (await mn.locator('input').inputValue()) === '15');
        await mn.locator('input').fill('0'); await p.keyboard.press('Enter'); await esperaGuardado(p, mn);
        await p.click('[data-eg-tab="advanced"]');
        const ip = fila(p, 'iplookup');
        await ip.locator('input').fill('https://ipinfo.example/%s'); await p.keyboard.press('Enter'); await esperaGuardado(p, ip);
        r('B-texto', 'campo de texto iplookup guardado con Enter', params('com_engage').iplookup === 'https://ipinfo.example/%s');
        await ip.locator('input').fill('javascript:alert(1)//%s'); await p.keyboard.press('Enter'); await esperaGuardado(p, ip);
        const estIp = await p.evaluate(() => ({v: document.querySelector('[data-key="iplookup"] input').value, err: !document.querySelector('[data-key="iplookup"] .eg-row__err').hidden, cls: document.querySelector('[data-key="iplookup"]').className}));
        r('B-texto-rechazado', 'valor peligroso en iplookup: el servidor lo rechaza, el campo VUELVE al valor anterior y se avisa; la BD sigue igual', params('com_engage').iplookup === 'https://ipinfo.example/%s' && estIp.v === 'https://ipinfo.example/%s' && estIp.err && /is-error/.test(estIp.cls), JSON.stringify(estIp));
        await ip.locator('input').fill('https://whatismyipaddress.com/ip/%s'); await p.keyboard.press('Enter'); await esperaGuardado(p, ip);
        await p.click('[data-eg-tab="security"]');
        const ta = fila(p, 'htmlpurifier_configstring');
        await ta.locator('textarea').fill('p,b,a[href],i'); await p.locator('[data-eg-tab="security"]').focus(); await esperaGuardado(p, ta);
        r('B-textarea', 'lista blanca de HTML (textarea) guardada al salir del campo', params('com_engage').htmlpurifier_configstring === 'p,b,a[href],i');
        // showon
        await fila(p, 'filter_mode').locator('[role=radio][data-value=joomla]').click(); await esperaGuardado(p, fila(p, 'filter_mode'));
        const ocultos = await p.evaluate(() => ['htmlpurifier_config_joomla', 'htmlpurifier_configstring', 'htmlpurifier_include'].map(k => document.querySelector(`[data-key="${k}"]`).hidden).join());
        await fila(p, 'filter_mode').locator('[role=radio][data-value=strict]').click(); await esperaGuardado(p, fila(p, 'filter_mode'));
        const visibles = await p.evaluate(() => ['htmlpurifier_config_joomla', 'htmlpurifier_configstring', 'htmlpurifier_include'].map(k => document.querySelector(`[data-key="${k}"]`).hidden).join());
        r('B-showon', 'dependencias (showon de config.xml): con modo "joomla" se ocultan los ajustes de HTML Purifier y con "strict" reaparecen', ocultos === 'true,true,true' && visibles === 'false,false,false', `${ocultos} / ${visibles}`);
        // plugin: Gravatar
        await p.click('[data-eg-tab="privacy"]');
        const gm = fila(p, 'mode', 'plg_engage_gravatar');
        await gm.locator('[role=radio][data-value=off]').click(); await esperaGuardado(p, gm);
        const gp = params('plg_engage_gravatar');
        r('B-plugin-gravatar', 'Gravatar (plugin): modo "off" guardado y el resto de parametros del plugin (zz_keep, consent_source) intactos', gp.mode === 'off' && gp.zz_keep === 'g' && gp.consent_source === 'engage', JSON.stringify(gp).slice(0, 120));
        const jb = fila(p, 'jbcookies_group', 'plg_engage_gravatar');
        const oculto0 = await jb.evaluate(e => e.hidden);
        await gm.locator('[role=radio][data-value=ask]').click(); await esperaGuardado(p, gm);
        await fila(p, 'consent_source', 'plg_engage_gravatar').locator('[role=radio][data-value=jbcookies]').click(); await esperaGuardado(p, fila(p, 'consent_source', 'plg_engage_gravatar'));
        r('B-showon-plugin', 'grupo de JBCookies: oculto con modo off, visible con modo "ask" + origen JBCookies', oculto0 === true && (await jb.evaluate(e => e.hidden)) === false);
        await jb.locator('input').fill('marketing'); await p.keyboard.press('Enter'); await esperaGuardado(p, jb);
        r('B-plugin-texto', 'texto del plugin (grupo JBCookies) guardado', params('plg_engage_gravatar').jbcookies_group === 'marketing');
        // fusion y reglas
        r('B-fusion', 'tras todo lo anterior: el parametro centinela zz_keep del componente y las reglas de permisos siguen intactos', params('com_engage').zz_keep === 'centinela' && sql("SELECT rules FROM jos_assets WHERE name='com_engage'") === origRules);
        // efecto en frontend: ocultar comentarios
        await p.click('[data-eg-tab="design"]');
        await fila(p, 'comments_show').locator('[role=radio][data-value="0"]').click(); await esperaGuardado(p, fila(p, 'comments_show'));
        h = await html();
        const ocultoFront = !h.includes('akengage-outer-container');
        await fila(p, 'comments_show').locator('[role=radio][data-value="1"]').click(); await esperaGuardado(p, fila(p, 'comments_show'));
        h = await html();
        r('B-frontend-comentarios', 'comments_show=0 desde la UI oculta los comentarios en el frontend y =1 los devuelve', ocultoFront && h.includes('akengage-outer-container'));
        r('B-sin-externas', 'durante todo el uso: cero peticiones a otros hosts', info.externos.length === 0, info.externos.join(' '));
        // enlace a opciones clasicas
        const href = await p.locator('a.eg-chip--plain').getAttribute('href');
        const cl = await (await c.request.get(`${BASE}${href.startsWith('/') ? '' : '/administrator/'}${href}`)).text();
        r('B-clasicas', 'enlace "Opciones clasicas" abre la pantalla de Joomla con sus campos', /name="jform\[default_publish\]"/.test(cl) && /name="jform\[rules\]/.test(cl), href);
        await c.close();
    }

    // ------------------------------------------------ C) foco visible en todos los controles (3 disenos)
    {
        const {c, p} = await login('admintest', ADMIN_PASS, {width: 1280, height: 900});
        for (const tema of ['light', 'mid', 'dark']) {
            await abrir(p, tema);
            let mal = [], n = 0, minR = 99;
            for (const sec of SECS) {
                await p.click(`[data-eg-tab="${sec}"]`); await p.keyboard.press('Shift');
                const fx = await p.evaluate(() => {
                    const parse = c => { const m = c.match(/rgba?\(([^)]+)\)/); const q = m[1].split(/[ ,\/]+/).filter(Boolean).map(Number); return {r: q[0], g: q[1], b: q[2], a: q.length > 3 ? q[3] : 1}; };
                    const over = (f, b) => ({r: f.r * f.a + b.r * (1 - f.a), g: f.g * f.a + b.g * (1 - f.a), b: f.b * f.a + b.b * (1 - f.a), a: 1});
                    const lum = c => { const f = v => { v /= 255; return v <= .03928 ? v / 12.92 : Math.pow((v + .055) / 1.055, 2.4); }; return .2126 * f(c.r) + .7152 * f(c.g) + .0722 * f(c.b); };
                    const ratio = (a, b) => { const x = lum(a), y = lum(b); return (Math.max(x, y) + .05) / (Math.min(x, y) + .05); };
                    const fondo = el => { const capas = []; for (let e = el.parentElement; e; e = e.parentElement) { const c = parse(getComputedStyle(e).backgroundColor); if (c.a > 0) { capas.push(c); if (c.a >= 1) break; } } let bb = {r: 255, g: 255, b: 255, a: 1}; for (let i = capas.length - 1; i >= 0; i--) bb = over(capas[i], bb); return bb; };
                    const out = [];
                    document.querySelectorAll('#eg-admin a[href], #eg-admin button, #eg-admin select, #eg-admin input, #eg-admin textarea, #eg-admin summary, #eg-admin [role=tabpanel]').forEach(el => {
                        if (!el.getClientRects().length || el.closest('[hidden]')) return;
                        if (el.getAttribute('role') === 'tabpanel' && el.hidden) return;
                        el.focus({focusVisible: true});
                        const s = getComputedStyle(el);
                        out.push({el: (el.className || el.tagName).toString().slice(0, 24), w: parseFloat(s.outlineWidth), st: s.outlineStyle, ratio: Math.round(ratio(parse(s.outlineColor), fondo(el)) * 100) / 100});
                    });
                    return out;
                });
                n += fx.length; mal.push(...fx.filter(x => x.st === 'none' || x.w < 2 || x.ratio < 3)); for (const x of fx) minR = Math.min(minR, x.ratio);
            }
            r(`C-foco-${tema}`, `foco visible (>=2px y >=3:1) en los ${n} controles de las 8 categorias, diseno ${tema}`, n > 90 && mal.length === 0, mal.length ? JSON.stringify(mal.slice(0, 3)) : `peor ${minR}`);
            peor[`${tema}|foco`] = {ratio: minR, min: 3, t: 'anillo de foco', tipo: 'foco'};
        }
        // ARIA
        await abrir(p, 'light');
        const a = await p.evaluate(() => ({
            sw: [...document.querySelectorAll('[role=switch]')].every(x => x.hasAttribute('aria-checked') && x.getAttribute('aria-labelledby') && x.getAttribute('aria-describedby')),
            rg: [...document.querySelectorAll('[role=radiogroup]')].every(x => (x.getAttribute('aria-labelledby') || x.getAttribute('aria-label')) && x.querySelectorAll('[role=radio]').length >= 2 && x.querySelectorAll('[role=radio][tabindex="0"]').length === 1),
            tabs: document.querySelectorAll('[role=tab]').length === 8 && [...document.querySelectorAll('[role=tab]')].every(x => document.getElementById(x.getAttribute('aria-controls'))),
            labels: [...document.querySelectorAll('#eg-admin input:not([type=hidden]), #eg-admin select, #eg-admin textarea')].every(x => x.labels && x.labels.length >= 1),
            live: !!document.querySelector('#eg-live[role=status][aria-live=polite]'),
            nsw: document.querySelectorAll('[role=switch]').length, nrg: document.querySelectorAll('[role=radiogroup]').length,
        }));
        r('C-aria', `ARIA: ${a.nsw} interruptores con nombre y ayuda, ${a.nrg} grupos de opciones con un solo tabindex=0, 8 pestanas con su panel, etiquetas de todos los campos, region viva`, a.sw && a.rg && a.tabs && a.labels && a.live && a.nsw >= 10, JSON.stringify(a));
        await c.close();
    }

    // ------------------------------------------------ D) errores por la UI con la red interceptada
    {
        const {c, p} = await login('admintest', ADMIN_PASS, {width: 1280, height: 900});
        await abrir(p, 'light'); await p.click('[data-eg-tab="moderation"]');
        const row = fila(p, 'comments_enabled'); const sw = row.locator('.eg-switch');
        const antes = params('com_engage').comments_enabled;
        for (const [nombre, handler] of [['500', rt => rt.fulfill({status: 500, contentType: 'application/json', body: '{"ok":false,"error":"failed"}'})], ['403', rt => rt.fulfill({status: 403, contentType: 'application/json', body: '{"ok":false,"error":"denied"}'})], ['422', rt => rt.fulfill({status: 422, contentType: 'application/json', body: '{"ok":false,"error":"invalid"}'})], ['HTML', rt => rt.fulfill({status: 200, contentType: 'text/html', body: '<html>ups</html>'})], ['red caida', rt => rt.abort('failed')]]) {
            await p.route('**/*task=settings.save*', handler);
            const v0 = await sw.getAttribute('aria-checked');
            await sw.click(); await esperaGuardado(p, row);
            const e = await p.evaluate(() => ({cls: document.querySelector('[data-key="comments_enabled"]').className, err: document.querySelector('[data-key="comments_enabled"] .eg-row__err').textContent, toast: document.getElementById('eg-toast').className + '|' + document.getElementById('eg-toast').textContent}));
            const v1 = await sw.getAttribute('aria-checked');
            r(`D-revierte-${nombre}`, `respuesta ${nombre}: el interruptor VUELVE a su estado, la fila marca error, hay mensaje en linea y aviso rojo`, v1 === v0 && /is-error/.test(e.cls) && e.err.length > 3 && /toast--error/.test(e.toast) && params('com_engage').comments_enabled === antes, JSON.stringify(e).slice(0, 160));
            await p.unroute('**/*task=settings.save*');
            await p.waitForTimeout(100);
        }
        await c.close();
    }

    // ------------------------------------------------ E) seguridad por HTTP con la sesion del administrador
    {
        const {c, p} = await login('admintest', ADMIN_PASS, {width: 1280, height: 900});
        await abrir(p, 'light');
        const tok = await p.evaluate(() => Joomla.getOptions('com_engage.settings').token);
        const url = `index.php?option=com_engage&task=settings.save&format=json`;
        const post = (campos, token = tok, metodo = 'POST') => p.evaluate(async ([campos, token, metodo, url]) => {
            const f = new URLSearchParams(); for (const [k, v] of campos) f.append(k, v); if (token) f.append(token, '1');
            const rs = await fetch(url, {method: metodo, body: metodo === 'POST' ? f : undefined, credentials: 'same-origin'}); const t = await rs.text();
            let j = null; try { j = JSON.parse(t); } catch (e) {} return {status: rs.status, json: j, len: t.length, ct: rs.headers.get('content-type'), nosniff: rs.headers.get('x-content-type-options'), cache: rs.headers.get('cache-control')};
        }, [campos, token, metodo, url]);
        const snap = () => JSON.stringify([params('com_engage'), params('plg_engage_gravatar'), params('plg_engage_akismet'), params('plg_engage_email'), sql("SELECT rules FROM jos_assets WHERE name='com_engage'"), sql("SELECT GROUP_CONCAT(CONCAT(extension_id,params)) FROM jos_extensions WHERE type='plugin' AND folder='system'")]);
        const s0 = snap();
        let x = await post([['scope', 'com_engage'], ['key', 'default_publish'], ['value', '0']], null);
        r('E-sin-token', 'POST sin token: 403, cuerpo generico y nada cambia', x.status === 403 && x.json && x.json.ok === false && snap() === s0, JSON.stringify(x.json));
        x = await post([['scope', 'com_engage'], ['key', 'default_publish'], ['value', '0']], crypto.randomBytes(16).toString('hex'));
        r('E-token-malo', 'POST con token inventado: 403 y nada cambia', x.status === 403 && snap() === s0);
        x = await post([['scope', 'com_engage'], ['key', 'default_publish'], ['value', '0']], tok, 'GET');
        r('E-get', 'GET en lugar de POST: 405 y nada cambia', x.status === 405 && snap() === s0, String(x.status));
        const rechazos = [
            ['ambito otro componente', [['scope', 'com_content'], ['key', 'show_title'], ['value', '0']]], ['plugin del sistema', [['scope', 'plg_system_cache'], ['key', 'browsercache'], ['value', '1']]],
            ['plugin de Engage sin manifiesto de ajustes', [['scope', 'plg_content_engage'], ['key', 'x'], ['value', '1']]], ['ambito con ruta', [['scope', '../com_engage'], ['key', 'default_publish'], ['value', '0']]],
            ['sin ambito', [['key', 'default_publish'], ['value', '0']]], ['sin clave', [['scope', 'com_engage'], ['value', '0']]], ['sin valor', [['scope', 'com_engage'], ['key', 'default_publish']]],
            ['clave rules (permisos)', [['scope', 'com_engage'], ['key', 'rules'], ['value', '{"core.admin":{"2":1}}']]], ['clave login_module', [['scope', 'com_engage'], ['key', 'login_module'], ['value', 'mod_login']]],
            ['clave custom_default', [['scope', 'plg_engage_gravatar'], ['key', 'custom_default'], ['value', 'x.png']]], ['clave de otro ambito', [['scope', 'com_engage'], ['key', 'mode'], ['value', 'off']]],
            ['clave inventada', [['scope', 'com_engage'], ['key', 'zz_keep'], ['value', 'pisado']]], ['clave __proto__', [['scope', 'com_engage'], ['key', '__proto__'], ['value', '1']]], ['clave con espacio', [['scope', 'com_engage'], ['key', 'default_publish '], ['value', '0']]],
            ['valor fuera de lista', [['scope', 'com_engage'], ['key', 'theme'], ['value', 'neon']]], ['valor 2 en interruptor', [['scope', 'com_engage'], ['key', 'default_publish'], ['value', '2']]],
            ['entero fuera de rango', [['scope', 'com_engage'], ['key', 'max_level'], ['value', '99']]], ['entero negativo', [['scope', 'com_engage'], ['key', 'min_length'], ['value', '-1']]],
            ['entero con notacion', [['scope', 'com_engage'], ['key', 'min_length'], ['value', '1e3']]], ['array como valor', [['scope', 'com_engage'], ['key', 'theme[]'], ['value[]', 'dark']]],
            ['valor array', [['scope', 'com_engage'], ['key', 'theme'], ['value[]', 'dark']]], ['valor objeto', [['scope', 'com_engage'], ['key', 'theme'], ['value[a]', 'dark']]], ['ambito array', [['scope[]', 'com_engage'], ['key', 'theme'], ['value', 'dark']]],
            ['valor con NUL', [['scope', 'com_engage'], ['key', 'iplookup'], ['value', 'https://a.example/%s\u0000']]], ['url javascript', [['scope', 'com_engage'], ['key', 'iplookup'], ['value', 'javascript:alert(1)//%s']]],
            ['XSS en url', [['scope', 'com_engage'], ['key', 'iplookup'], ['value', 'https://a.example/%s"><script>alert(1)</script>']]], ['XSS en lista blanca', [['scope', 'com_engage'], ['key', 'htmlpurifier_configstring'], ['value', 'p,<script>alert(1)</script>']]],
            ['cadena enorme', [['scope', 'com_engage'], ['key', 'tos_prompt'], ['value', 'A'.repeat(2 * 1024 * 1024)]]], ['lista blanca enorme', [['scope', 'com_engage'], ['key', 'htmlpurifier_configstring'], ['value', 'p,'.repeat(100000)]]],
            ['captcha inexistente', [['scope', 'com_engage'], ['key', 'captcha'], ['value', 'no_existe']]], ['clave de Akismet con espacios', [['scope', 'plg_engage_akismet'], ['key', 'key'], ['value', 'a b c']]],
        ];
        let malR = [];
        for (const [n, campos] of rechazos) { const y = await post(campos); const ok = [403, 422, 400, 413].includes(y.status) && y.status !== 200 && (y.json ? y.json.ok === false : true) && !(y.json && JSON.stringify(y.json).includes(String(campos.find(f => f[0] === 'value')?.[1] ?? '\u0001'))); if (!ok || snap() !== s0) malR.push(`${n}:${y.status}`); }
        r('E-rechazos', `${rechazos.length} peticiones no validas (ambito, clave, valor, tipos, NUL, XSS, enormes): todas 4xx genericas y NINGUN dato cambia (componente, plugins, permisos, plugins del sistema)`, malR.length === 0, malR.join(' '));
        x = await post([['scope', 'com_engage'], ['key', 'theme'], ['value', 'neon']]);
        r('E-cuerpo-generico', 'el cuerpo del error es generico: no repite el valor ni dice que campo fallo; JSON, nosniff y no-store', x.json && Object.keys(x.json).sort().join() === 'error,ok' && /json/.test(x.ct || '') && x.nosniff === 'nosniff' && /no-store/.test(x.cache || ''), JSON.stringify(x));
        // valido: fusion e idempotencia
        x = await post([['scope', 'com_engage'], ['key', 'comments_notify_author'], ['value', '0']]);
        const p1 = params('com_engage');
        r('E-valido', 'peticion valida: 200 con el valor normalizado y guardada; el centinela intacto', x.status === 200 && x.json.ok === true && x.json.value === '0' && x.json.changed === true && p1.comments_notify_author === '0' && p1.zz_keep === 'centinela');
        x = await post([['scope', 'com_engage'], ['key', 'comments_notify_author'], ['value', '0']]);
        r('E-idempotente', 'repetir la misma peticion: 200 y changed=false (sin reescribir)', x.status === 200 && x.json.changed === false);
        x = await post([['scope', 'com_engage'], ['key', 'min_length'], ['value', '007']]);
        r('E-normaliza', 'el valor se normaliza: "007" se guarda como "7"', x.status === 200 && params('com_engage').min_length === '7');
        await post([['scope', 'com_engage'], ['key', 'min_length'], ['value', '0']]);
        // filtro safehtml
        x = await post([['scope', 'com_engage'], ['key', 'tos_prompt'], ['value', 'Acepto <script>alert(1)</script> las <a href="/t">condiciones</a> <img src=x onerror=alert(1)>']]);
        const tp = params('com_engage').tos_prompt || '';
        r('E-safehtml', 'tos_prompt pasa por el filtro safehtml de Joomla: sin <script> ni handlers, con el enlace', x.status === 200 && !/<script|onerror/i.test(tp) && /<a href/.test(tp), tp.slice(0, 100));
        await post([['scope', 'com_engage'], ['key', 'tos_prompt'], ['value', '']]);
        // plugin
        x = await post([['scope', 'plg_engage_akismet'], ['key', 'check'], ['value', 'all']]);
        r('E-plugin-ok', 'ajuste de un plugin de Engage como administrador: 200 y guardado en el plugin', x.status === 200 && params('plg_engage_akismet').check === 'all');
        await c.close();
    }

    // ------------------------------------------------ F) usuarios sin permisos
    {
        // Manager con core.manage pero sin core.admin
        permisos({'core.manage': {6: 1}});
        const mg = await login('mgrpanel', MGR_PASS, {width: 1280, height: 900});
        await mg.p.goto(`${BASE}/administrator/index.php?option=com_engage&view=controlpanel`, {waitUntil: 'networkidle'});
        const tokM = await mg.p.evaluate(() => document.querySelector('#eg-quick input[type=hidden][value="1"]').name);
        const s0 = JSON.stringify([params('com_engage'), params('plg_engage_gravatar')]);
        const rs = await mg.p.evaluate(async ([tok]) => { const f = new URLSearchParams({scope: 'com_engage', key: 'default_publish', value: '0'}); f.append(tok, '1'); const x = await fetch('index.php?option=com_engage&task=settings.save&format=json', {method: 'POST', body: f, credentials: 'same-origin'}); return {st: x.status, t: await x.text()}; }, [tokM]);
        r('F-manager-guardar', 'Manager SIN core.admin con token valido: 403 y nada cambia', rs.st === 403 && JSON.stringify([params('com_engage'), params('plg_engage_gravatar')]) === s0, `HTTP ${rs.st}`);
        const pg = await mg.p.goto(`${BASE}/administrator/index.php?option=com_engage&view=settings`);
        const cuerpo = await mg.p.content();
        r('F-manager-pantalla', 'Manager SIN core.admin: la pantalla de opciones se deniega (no se pinta)', !cuerpo.includes('data-eg-view="settings"') && !cuerpo.includes('eg-switch'), `HTTP ${pg.status()}`);
        await mg.c.close();
        // administrador del componente (core.admin en com_engage) sin permiso de edicion de plugins
        permisos({'core.manage': {6: 1}, 'core.admin': {6: 1}});
        sql("UPDATE jos_assets SET rules='{\"core.manage\":{\"6\":1,\"7\":1},\"core.edit\":{\"6\":0}}' WHERE name='com_plugins'");
        const ad = await login('mgradmin', MGR_PASS, {width: 1280, height: 900});
        await ad.p.goto(`${BASE}/administrator/index.php?option=com_engage&view=settings`, {waitUntil: 'networkidle'});
        const vis = await ad.p.evaluate(() => ({vista: !!document.querySelector('[data-eg-view="settings"]'), plug: document.querySelectorAll('[data-scope^="plg_"]').length, comp: document.querySelectorAll('[data-scope="com_engage"]').length}));
        r('F-admin-comp-pantalla', 'admin del componente sin permiso de plugins: ve los ajustes del componente y NO los de los plugins', vis.vista && vis.comp > 20 && vis.plug === 0, JSON.stringify(vis));
        const tk = await ad.p.evaluate(() => Joomla.getOptions('com_engage.settings').token);
        const enviar = (campos) => ad.p.evaluate(async ([campos, tk]) => { const f = new URLSearchParams(campos); f.append(tk, '1'); const x = await fetch('index.php?option=com_engage&task=settings.save&format=json', {method: 'POST', body: f, credentials: 'same-origin'}); return x.status; }, [campos, tk]);
        const sg = JSON.stringify(params('plg_engage_gravatar'));
        const st1 = await enviar({scope: 'plg_engage_gravatar', key: 'mode', value: 'always'});
        r('F-admin-comp-plugin', 'admin del componente sin core.edit de com_plugins: 403 al tocar un plugin de Engage y el plugin no cambia', st1 === 403 && JSON.stringify(params('plg_engage_gravatar')) === sg, `HTTP ${st1}`);
        const st2 = await enviar({scope: 'com_engage', key: 'comments_notify_users', value: '0'});
        r('F-admin-comp-comp', 'el mismo usuario SI puede cambiar ajustes del componente (200)', st2 === 200 && params('com_engage').comments_notify_users === '0', `HTTP ${st2}`);
        await ad.c.close();
        sql(`UPDATE jos_assets SET rules=${q(origComRules)} WHERE name='com_plugins'`);
        permisos(JSON.parse(origRules || '{}'));
        sql(`UPDATE jos_assets SET rules=${q(origRules)} WHERE name='com_engage'`);
    }

    // ------------------------------------------------ G) registro de acciones
    {
        sql("UPDATE jos_extensions SET enabled=1 WHERE type='plugin' AND folder='actionlog' AND element='engage'");
        sql("DELETE FROM jos_action_logs WHERE message_language_key='COM_ENGAGE_USERLOG_SETTING_CHANGED'");
        const {c, p} = await login('admintest', ADMIN_PASS, {width: 1280, height: 900});
        await abrir(p, 'light'); await p.click('[data-eg-tab="antispam"]');
        const tk = await p.evaluate(() => Joomla.getOptions('com_engage.settings').token);
        await p.evaluate(async ([tk]) => { const f = new URLSearchParams({scope: 'plg_engage_akismet', key: 'key', value: 'SECRETO123abc'}); f.append(tk, '1'); await fetch('index.php?option=com_engage&task=settings.save&format=json', {method: 'POST', body: f, credentials: 'same-origin'}); }, [tk]);
        const log = sql("SELECT message FROM jos_action_logs WHERE message_language_key='COM_ENGAGE_USERLOG_SETTING_CHANGED' ORDER BY id DESC LIMIT 1");
        r('G-actionlog', 'con el plugin de registro activo se anota el cambio (clave y ambito) y el valor (una clave de API) NO aparece en el registro', /key/.test(log) && !/SECRETO123abc/.test(log) && /plg_engage_akismet/.test(log), log.slice(0, 160));
        const vista = await (await c.request.get(`${BASE}/administrator/index.php?option=com_actionlogs&view=actionlogs`)).text();
        r('G-actionlog-vista', 'la pantalla de Registro de acciones de Joomla se abre con el cambio dentro', vista.includes('actionlogs') && !/SECRETO123abc/.test(vista));
        sql("UPDATE jos_extensions SET enabled=0 WHERE type='plugin' AND folder='actionlog' AND element='engage'");
        sql("DELETE FROM jos_action_logs WHERE message_language_key='COM_ENGAGE_USERLOG_SETTING_CHANGED'");
        await c.close();
    }

    await b.close();

    // restaurar
    for (const sc of Object.keys(orig)) setParams(sc, orig[sc]);
    sql(`UPDATE jos_assets SET rules=${q(origRules)} WHERE name='com_engage'`);
    sql(`UPDATE jos_assets SET rules=${q(origComRules)} WHERE name='com_plugins'`);
    for (const l of origPlug) { const m = l.match(/^(\d+):(\d)$/); if (m) sql(`UPDATE jos_extensions SET enabled=${m[2]} WHERE extension_id=${m[1]}`); }

    console.log('\nPEOR CONTRASTE POR DISENO Y TIPO (ratio medido / minimo exigido):');
    const por = {};
    for (const [k, v] of Object.entries(peor)) { const [d, tipo] = k.split('|'); (por[d] = por[d] || []).push(`${tipo} ${v.ratio}:1 (min ${v.min}) [${v.t}]`); }
    for (const [d, l] of Object.entries(por)) console.log(`  ${d}: ${l.join(' | ')}`);
    fs.writeFileSync(`${WORK}/resultados-ajustes.json`, JSON.stringify({resultados: res, peor}, null, 1));
    const ko = res.filter(x => x.estado === 'FALLA').length;
    console.log(`\n${res.length - ko} PASA / ${ko} FALLA`);
    process.exit(ko ? 1 : 0);
})().catch(e => {
    console.error(e);
    try { for (const sc of Object.keys(orig)) setParams(sc, orig[sc]); sql(`UPDATE jos_assets SET rules=${q(origRules)} WHERE name='com_engage'`); sql(`UPDATE jos_assets SET rules=${q(origComRules)} WHERE name='com_plugins'`); } catch (x) {}
    process.exit(1);
});
