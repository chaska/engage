/**
 * 0.6.19: prueba en un NAVEGADOR real (Chromium headless con Playwright) del consentimiento de Gravatar gobernado por el
 * modulo JBCookies (plugin engage/gravatar con consent_source=jbcookies). Se pulsan los botones REALES del modulo
 * (Aceptar, Ajustes, Rechazar todas, Guardar seleccion, icono "cambiar mi decision") y se cuentan las peticiones de red
 * a gravatar.com (interceptadas: se registran y se responden con un PNG de 1x1; no sale nada a Internet).
 *
 * Requisitos: Joomla de pruebas con 03-sembrar-y-probar.sh y lib/gravatar-http.php ya ejecutados (dejan 2 comentarios) y
 * el modulo mod_jbcookies instalado y publicado (lo hace 07-jbcookies.sh). El modulo NO forma parte del repositorio.
 * Entorno: BASE_URL, WORK, IDS_FILE, DB_NAME, PW_MODULE, CHROMIUM, CACHE_PAGINA=1 (solo la parte con cache de pagina).
 * Sale con 1 si algo FALLA.
 */
const {execFileSync} = require('child_process');
const fs = require('fs');
const {chromium} = require(process.env.PW_MODULE || 'playwright');

const BASE = process.env.BASE_URL || 'http://127.0.0.1:8080';
const HOST = new URL(BASE).hostname;
const WORK = process.env.WORK;
const ids = JSON.parse(fs.readFileSync(`${WORK}/${process.env.IDS_FILE || 'ids.json'}`, 'utf8'));
const DB = process.env.DB_NAME || 'joomla_test';
const PAGE = `${BASE}/index.php?option=com_content&view=article&id=${ids.art_publico}&catid=${ids.cat_publica}`;
const PNG = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==', 'base64');
const ANTIGUOS = {profile_link: '1', rating: 'G', default_image: 'identicon', custom_default: '', force_default: '0'};

const res = [];
function r(id, desc, ok, ev = '') {
    res.push({id, desc, estado: ok ? 'PASA' : 'FALLA', ev});
    console.log(`${id.padEnd(8)} ${ok ? 'PASA ' : 'FALLA'} ${desc}${ev ? '  [' + ev + ']' : ''}`);
}
function sql(s) { execFileSync('mysql', ['-uroot', DB, '-e', s]); }
function setParams(p) {
    const j = JSON.stringify(p);
    sql(`UPDATE jos_extensions SET enabled=1, params='${j.replace(/\\/g, '\\\\').replace(/'/g, "\\'")}' WHERE type='plugin' AND folder='engage' AND element='gravatar'`);
}
const jb = (extra = {}) => setParams({mode: 'ask', consent_source: 'jbcookies', ...ANTIGUOS, ...extra});

async function nuevo(browser, {cookies = []} = {}) {
    const ctx = await browser.newContext({locale: 'es-ES'});
    const peticiones = [];
    await ctx.route(/gravatar\.com/, (route) => {
        peticiones.push({url: route.request().url(), referer: route.request().headers()['referer'] || null});
        route.fulfill({status: 200, contentType: 'image/png', body: PNG});
    });
    const externas = [];
    await ctx.route((u) => !/^(127\.0\.0\.1|localhost)$/.test(u.hostname) && !/gravatar\.com$/.test(u.hostname), (route) => { externas.push(route.request().url()); route.abort(); });
    if (cookies.length) { await ctx.addCookies(cookies.map((c) => ({name: c.name, value: c.value, domain: HOST, path: '/'}))); }
    const page = await ctx.newPage();
    const errores = [];
    page.on('pageerror', (e) => errores.push(String(e)));
    return {ctx, page, peticiones, externas, errores};
}
async function cargar(page) {
    await page.goto(PAGE, {waitUntil: 'networkidle'});
    await page.waitForTimeout(1300);   // el modulo muestra el aviso a los 500 ms
}
const srcs = (page) => page.$$eval('img.akengage-commenter-avatar', (a) => a.map((i) => i.getAttribute('src')));
const stor = (page) => page.evaluate(() => { try { return window.localStorage.getItem('engage_gravatar_consent'); } catch (e) { return 'ERR'; } });
const esLocal = (s) => /\/media\/com_engage\/images\/avatar-generico\.svg$/.test(s);
const esGrav = (s) => /^https:\/\/www\.gravatar\.com\/avatar\/[0-9a-f]{64}\?/.test(s);
const todasLocales = async (p) => { const s = await srcs(p); return s.length >= 2 && s.every(esLocal); };
const todasGrav = async (p) => { const s = await srcs(p); return s.length >= 2 && s.every(esGrav); };
async function cookieJb(ctx) {
    const c = (await ctx.cookies()).find((x) => x.name === 'jbcookies');
    if (!c) { return null; }
    try { return JSON.parse(decodeURIComponent(c.value)); } catch (e) { return 'ILEGIBLE'; }
}
const bannerVisible = (page) => page.locator('.jb-cookie').first().isVisible();
async function abrirAjustes(page) {
    await page.locator('.jb-settings').click();
    await page.locator('#jbcookies-preferences').waitFor({state: 'visible'});
    await page.waitForTimeout(500);
}
const ENC = (o) => encodeURIComponent(typeof o === 'string' ? o : JSON.stringify(o));

(async () => {
    const browser = await chromium.launch({executablePath: process.env.CHROMIUM || '/opt/pw-browsers/chromium', args: ['--no-sandbox']});
    let t, s;

    if (process.env.CACHE_PAGINA === '1') {
        // ============ jbcookies con la cache de pagina de Joomla (plugin cache + engagecache) ============
        jb({jbcookies_group: ''});
        const cal = await nuevo(browser); await cargar(cal.page); await cal.ctx.close();   // calienta la cache
        t = await nuevo(browser);
        const resp = await t.page.goto(PAGE, {waitUntil: 'networkidle'});
        await t.page.waitForTimeout(1300);
        const html = await resp.text();
        r('JC-01', 'cache de pagina: el HTML cacheado conserva data-engage-gravatar-source="jbcookies", sin aviso propio y 0 peticiones antes de decidir', /data-engage-gravatar-source="jbcookies"/.test(html) && t.peticiones.length === 0 && (await t.page.locator('.akengage-gravatar-notice').count()) === 0 && await todasLocales(t.page), `peticiones=${t.peticiones.length}`);
        await t.page.locator('.jb-accept').click(); await t.page.waitForTimeout(700);
        r('JC-02', 'cache de pagina: Aceptar en JBCookies carga las fotos', t.peticiones.length >= 2 && await todasGrav(t.page), `peticiones=${t.peticiones.length}`);
        await t.ctx.close();
        t = await nuevo(browser); await cargar(t.page);
        r('JC-03', 'cache de pagina: otro visitante no hereda la decision (la cookie es del navegador, no de la pagina cacheada)', t.peticiones.length === 0 && await todasLocales(t.page));
        await t.ctx.close();
        await browser.close();
        const fallos = res.filter((x) => x.estado !== 'PASA').length;
        fs.writeFileSync(`${WORK}/resultados-jbcookies-cache.json`, JSON.stringify(res, null, 1));
        console.log(`\n${res.length - fallos}/${res.length} pasan`);
        process.exit(fallos ? 1 : 0);
    }

    // ============ 1. Antes de decidir ============
    jb({jbcookies_group: ''});
    t = await nuevo(browser);
    await cargar(t.page);
    r('J-01', 'jbcookies: antes de decidir NO sale ninguna peticion a gravatar.com (ni a terceros) y el aviso de JBCookies se muestra', t.peticiones.length === 0 && t.externas.length === 0 && await bannerVisible(t.page), `gravatar=${t.peticiones.length}, externas=${t.externas.length}, aviso JBCookies visible=${await bannerVisible(t.page)}`);
    s = await srcs(t.page);
    r('J-02', 'jbcookies: avatares con el SVG local, atributo data-engage-gravatar-source=jbcookies y SIN aviso propio de Engage', s.length === 2 && s.every(esLocal) && (await t.page.$$('img[data-engage-gravatar-source="jbcookies"]')).length === 2 && (await t.page.locator('.akengage-gravatar-notice').count()) === 0, JSON.stringify(s.map((x) => x.slice(-24))));
    r('J-03', 'jbcookies: sin cookie jbcookies, sin clave engage_gravatar_consent y window.AkeebaEngageGravatar.isGranted()=false', (await cookieJb(t.ctx)) === null && (await stor(t.page)) === null && (await t.page.evaluate(() => window.AkeebaEngageGravatar.isGranted())) === false);

    // ============ 2. Aceptar ============
    await t.page.locator('.jb-accept').click();
    await t.page.waitForTimeout(800);
    let c = await cookieJb(t.ctx);
    r('J-04', 'Aceptar: JBCookies guarda status=allow y se piden las 2 fotos (https://www.gravatar.com/avatar/<sha256>, sin Referer)', c && c.status === 'allow' && t.peticiones.length >= 2 && t.peticiones.every((p) => esGrav(p.url) && p.referer === null) && await todasGrav(t.page), `cookie=${JSON.stringify(c)}, peticiones=${t.peticiones.length}`);
    r('J-05', 'Aceptar: gravatar.js NO usa localStorage como fuente de verdad (la clave engage_gravatar_consent sigue vacia)', (await stor(t.page)) === null);
    r('J-06', 'Aceptar: las imagenes se cargaron de verdad (naturalWidth>0)', await t.page.$$eval('img.akengage-commenter-avatar', (a) => a.every((i) => i.complete && i.naturalWidth > 0)));
    // persistencia
    t.peticiones.length = 0;
    await cargar(t.page);
    r('J-07', 'Recargar: la decision persiste (cookie): las fotos se cargan sin volver a preguntar y el aviso de JBCookies no se muestra', t.peticiones.length >= 2 && await todasGrav(t.page) && !(await bannerVisible(t.page)), `peticiones=${t.peticiones.length}`);
    // cambiar mi decision (sin evento en el modulo)
    await t.page.locator('.jb-cookie-decline button').click();
    await t.page.waitForTimeout(1200);
    r('J-08', '"Cambiar mi decision" (JBCookies borra la cookie SIN evento): se retiran las fotos al instante y vuelve el aviso', (await cookieJb(t.ctx)) === null && await todasLocales(t.page) && (await t.page.evaluate(() => window.AkeebaEngageGravatar.isGranted())) === false && await bannerVisible(t.page));
    t.peticiones.length = 0;
    await cargar(t.page);
    r('J-09', 'Tras "cambiar mi decision" y recargar: 0 peticiones a gravatar.com', t.peticiones.length === 0, `peticiones=${t.peticiones.length}`);

    // ============ 3. Rechazar ============
    // 3a. rechazar sin haber aceptado nunca
    await t.ctx.close();
    t = await nuevo(browser);
    await cargar(t.page);
    await abrirAjustes(t.page);
    await t.page.locator('.jb-reject-all').click();
    await t.page.waitForTimeout(800);
    c = await cookieJb(t.ctx);
    r('J-10', 'Rechazar todas (sin aceptar antes): status=deny, 0 peticiones a gravatar.com, avatares locales', c && c.status === 'deny' && t.peticiones.length === 0 && await todasLocales(t.page), `cookie=${JSON.stringify(c)}, peticiones=${t.peticiones.length}`);
    t.peticiones.length = 0;
    await cargar(t.page);
    r('J-11', 'Rechazar: tras recargar sigue sin cargarse ninguna foto', t.peticiones.length === 0 && await todasLocales(t.page));
    // 3b. aceptar y luego rechazar: las fotos cargadas se retiran
    await t.ctx.close();
    t = await nuevo(browser);
    await cargar(t.page);
    await t.page.locator('.jb-accept').click(); await t.page.waitForTimeout(800);
    const antes = t.peticiones.length;
    await t.page.locator('.jb-cookie-decline button').click(); await t.page.waitForTimeout(1200);
    await abrirAjustes(t.page);
    await t.page.locator('.jb-reject-all').click(); await t.page.waitForTimeout(800);
    t.peticiones.length = 0;
    c = await cookieJb(t.ctx);
    r('J-12', 'Aceptar y despues Rechazar todas: status=deny y los avatares vuelven al SVG local (se "borran" las fotos)', antes >= 2 && c && c.status === 'deny' && await todasLocales(t.page) && (await t.page.evaluate(() => window.AkeebaEngageGravatar.isGranted())) === false);
    await t.ctx.close();

    // ============ 4. Guardar seleccion ============
    // grupo configurado = marketing
    jb({jbcookies_group: 'marketing'});
    t = await nuevo(browser);
    await cargar(t.page);
    r('J-13', 'grupo=marketing: el HTML lleva data-engage-gravatar-group="marketing"', (await t.page.$$('img[data-engage-gravatar-group="marketing"]')).length === 2);
    await abrirAjustes(t.page);
    await t.page.locator('#jb-toggle-marketing').setChecked(true);
    await t.page.locator('.jb-save-selection').click(); await t.page.waitForTimeout(800);
    c = await cookieJb(t.ctx);
    r('J-14', 'Guardar seleccion con marketing ACTIVADO (grupo=marketing): status=custom, se cargan las fotos', c && c.status === 'custom' && c.preferences.marketing === 1 && t.peticiones.length >= 2 && await todasGrav(t.page), `cookie=${JSON.stringify(c)}, peticiones=${t.peticiones.length}`);
    await t.ctx.close();
    t = await nuevo(browser);
    await cargar(t.page);
    await abrirAjustes(t.page);
    await t.page.locator('#jb-toggle-marketing').setChecked(false);
    await t.page.locator('.jb-save-selection').click(); await t.page.waitForTimeout(800);
    c = await cookieJb(t.ctx);
    r('J-15', 'Guardar seleccion con marketing DESACTIVADO (grupo=marketing): status=custom, 0 peticiones, avatares locales', c && c.status === 'custom' && c.preferences.marketing === 0 && t.peticiones.length === 0 && await todasLocales(t.page), `cookie=${JSON.stringify(c)}, peticiones=${t.peticiones.length}`);
    t.peticiones.length = 0;
    await cargar(t.page);
    r('J-16', 'Seleccion sin marketing: tras recargar sigue sin pedirse ninguna foto', t.peticiones.length === 0 && await todasLocales(t.page));
    // cambiar la seleccion sin recargar: reabrir y activar marketing
    await t.page.locator('.jb-cookie-decline button').click(); await t.page.waitForTimeout(1200);
    await abrirAjustes(t.page);
    await t.page.locator('#jb-toggle-marketing').setChecked(true);
    await t.page.locator('.jb-save-selection').click(); await t.page.waitForTimeout(800);
    r('J-17', 'Cambiar de desactivado a activado y guardar (misma pagina): las fotos se cargan al recibir jbcookies:update', t.peticiones.length >= 2 && await todasGrav(t.page));
    await t.ctx.close();
    // grupo configurado = marketing, pero se activa solo analytics
    t = await nuevo(browser);
    await cargar(t.page);
    await abrirAjustes(t.page);
    await t.page.locator('#jb-toggle-marketing').setChecked(false);
    await t.page.locator('#jb-toggle-analytics').setChecked(true);
    await t.page.locator('.jb-save-selection').click(); await t.page.waitForTimeout(800);
    r('J-18', 'grupo=marketing y solo analytics activado: NO se cargan fotos', t.peticiones.length === 0 && await todasLocales(t.page));
    await t.ctx.close();
    // grupo vacio: Guardar seleccion con TODO activado no concede (solo "Aceptar todas" concede)
    jb({jbcookies_group: ''});
    t = await nuevo(browser);
    await cargar(t.page);
    await abrirAjustes(t.page);
    await t.page.locator('.jb-save-selection').click(); await t.page.waitForTimeout(800);
    c = await cookieJb(t.ctx);
    r('J-19', 'grupo VACIO: "Guardar seleccion" (con todos los interruptores activados) NO concede; solo "Aceptar todas" concede', c && c.status === 'custom' && t.peticiones.length === 0 && await todasLocales(t.page), `cookie=${JSON.stringify(c)}`);
    // en la misma sesion, Aceptar todas desde el modal de ajustes
    await t.page.locator('.jb-cookie-decline button').click(); await t.page.waitForTimeout(1200);
    await abrirAjustes(t.page);
    await t.page.locator('.jb-accept-all').click(); await t.page.waitForTimeout(800);
    r('J-20', 'grupo vacio: "Aceptar todas" del modal de ajustes concede', t.peticiones.length >= 2 && await todasGrav(t.page));
    await t.ctx.close();
    // grupo necessary: no se acepta (siempre esta a 1) => grupo vacio en el HTML
    jb({jbcookies_group: 'necessary'});
    t = await nuevo(browser);
    await cargar(t.page);
    const g = await t.page.$$eval('img.akengage-commenter-avatar', (a) => a.map((i) => i.getAttribute('data-engage-gravatar-group')));
    await abrirAjustes(t.page);
    await t.page.locator('.jb-save-selection').click(); await t.page.waitForTimeout(800);
    r('J-21', 'grupo=necessary (siempre activo): el plugin lo descarta (grupo vacio) y "Guardar seleccion" no concede', g.every((x) => x === '') && t.peticiones.length === 0 && await todasLocales(t.page), `grupos=${JSON.stringify(g)}`);
    await t.ctx.close();
    // grupo con caracteres raros: filtrado en el servidor y escapado
    jb({jbcookies_group: 'a"><img src=x onerror=1>'});
    t = await nuevo(browser);
    const resp = await t.page.goto(PAGE, {waitUntil: 'networkidle'});
    const htmlRaro = await resp.text();
    r('J-22', 'grupo con comillas/HTML: el HTML sirve data-engage-gravatar-group="" y no contiene el texto inyectado', /data-engage-gravatar-group=""/.test(htmlRaro) && htmlRaro.indexOf('onerror=1') === -1);
    await t.ctx.close();

    // ============ 5. Cookies hostiles puestas a mano ============
    jb({jbcookies_group: 'marketing'});
    const hostiles = [
        ['JSON roto', ENC('{"status":"allow"')],
        ['percent-encoding roto', '%E0%A4%A'],
        ['status raro', ENC({status: 'ALLOW', preferences: {marketing: 1}})],
        ['custom con "1" cadena', ENC({status: 'custom', preferences: {marketing: '1'}})],
        ['custom con preferences array', ENC({status: 'custom', preferences: [1, 1]})],
        ['custom con __proto__', ENC('{"status":"custom","__proto__":{"marketing":1},"preferences":{"__proto__":{"marketing":1}}}')],
        ['deny', ENC({status: 'deny', preferences: {marketing: 1}})],
        ['vacia', ''],
    ];
    let malas = 0, det = [];
    for (const [n, v] of hostiles) {
        t = await nuevo(browser, {cookies: [{name: 'jbcookies', value: v}]});
        await t.page.goto(PAGE, {waitUntil: 'networkidle'}); await t.page.waitForTimeout(600);
        const bien = t.peticiones.length === 0 && await todasLocales(t.page);
        if (!bien) { malas++; det.push(n); }
        await t.ctx.close();
    }
    r('J-23', `cookie jbcookies hostil puesta a mano (${hostiles.length} variantes: JSON roto, encoding roto, status raro, "1", array, __proto__, deny, vacia): 0 peticiones en todas`, malas === 0, det.join(', '));
    // valores validos puestos a mano (precondicion de que la prueba anterior distingue)
    for (const [n, v, esp] of [['legado "allow"', 'allow', true], ['allow JSON', ENC({status: 'allow', preferences: {}}), true], ['custom marketing=1', ENC({status: 'custom', preferences: {marketing: 1}}), true], ['legado "deny"', 'deny', false], ['legado "custom"', 'custom', false]]) {
        t = await nuevo(browser, {cookies: [{name: 'jbcookies', value: v}]});
        await t.page.goto(PAGE, {waitUntil: 'networkidle'}); await t.page.waitForTimeout(600);
        r('J-24', `cookie valida puesta a mano, ${n}: ${esp ? 'carga fotos' : 'no carga fotos'}`, esp ? (t.peticiones.length >= 2 && await todasGrav(t.page)) : (t.peticiones.length === 0 && await todasLocales(t.page)));
        await t.ctx.close();
    }
    // retirada pasiva: cookie borrada sin evento + focus / visibilitychange
    t = await nuevo(browser, {cookies: [{name: 'jbcookies', value: ENC({status: 'allow', preferences: {}})}]});
    await t.page.goto(PAGE, {waitUntil: 'networkidle'}); await t.page.waitForTimeout(600);
    const conFotos = await todasGrav(t.page);
    await t.ctx.clearCookies();
    await t.page.evaluate(() => window.dispatchEvent(new Event('focus')));
    r('J-25', 'cookie borrada fuera de la pagina (otra pestana): al volver el foco se retiran las fotos', conFotos && await todasLocales(t.page));
    await t.ctx.addCookies([{name: 'jbcookies', value: ENC({status: 'allow', preferences: {}}), domain: HOST, path: '/'}]);
    await t.page.evaluate(() => { window.dispatchEvent(new Event('focus')); document.dispatchEvent(new Event('visibilitychange')); });
    r('J-26', 'una comprobacion pasiva (focus/visibilitychange) NUNCA concede, aunque la cookie diga allow', await todasLocales(t.page));
    t.peticiones.length = 0;
    await t.page.evaluate(() => document.dispatchEvent(new CustomEvent('jbcookies:update', {detail: {status: 'allow', preferences: {}}})));
    await t.page.waitForTimeout(500);
    r('J-27', 'el evento jbcookies:update relee la cookie y entonces SI concede', await todasGrav(t.page));
    await t.page.evaluate(() => { window.AkeebaEngageGravatar.revoke(); });
    const trasRevoke = await todasLocales(t.page);
    t.peticiones.length = 0;
    await t.ctx.clearCookies();
    await t.page.evaluate(() => { window.AkeebaEngageGravatar.grant(); document.dispatchEvent(new CustomEvent('engage:gravatar-consent', {detail: {granted: true}})); });
    await t.page.waitForTimeout(400);
    r('J-28', 'API manual: revoke() retira siempre; grant() / engage:gravatar-consent {granted:true} sin cookie allow NO conceden (no contradicen al gestor)', trasRevoke && t.peticiones.length === 0 && await todasLocales(t.page));
    r('J-29', 'sin errores de JavaScript propios de gravatar.js en toda la sesion de la ultima pagina', t.errores.filter((e) => /gravatar/i.test(e)).length === 0, t.errores.join(' | '));
    await t.ctx.close();

    // ============ 6. Modo engage (por defecto) no cambia, con el modulo JBCookies publicado ============
    setParams({mode: 'ask', ...ANTIGUOS});   // sin consent_source (ajustes de 0.6.18)
    t = await nuevo(browser, {cookies: [{name: 'jbcookies', value: ENC({status: 'allow', preferences: {marketing: 1}})}]});
    await cargar(t.page);
    const aviso = t.page.locator('.akengage-gravatar-notice');
    r('J-30', 'REGRESION engage (consent_source ausente) con cookie jbcookies=allow: la cookie se IGNORA: 0 peticiones, aviso propio de Engage presente, sin atributos source/group', t.peticiones.length === 0 && (await aviso.count()) === 1 && await todasLocales(t.page) && (await t.page.$$('img[data-engage-gravatar-source]')).length === 0, `peticiones=${t.peticiones.length}`);
    await aviso.locator('button').click(); await t.page.waitForTimeout(700);
    r('J-31', 'REGRESION engage: el boton propio sigue funcionando (carga fotos y guarda engage_gravatar_consent=1)', t.peticiones.length >= 2 && (await stor(t.page)) === '1' && await todasGrav(t.page));
    await t.ctx.close();
    setParams({mode: 'ask', consent_source: 'engage', jbcookies_group: 'marketing', ...ANTIGUOS});
    t = await nuevo(browser);
    await cargar(t.page);
    r('J-32', 'consent_source=engage explicito (aunque haya jbcookies_group guardado): aviso propio de Engage y 0 peticiones', t.peticiones.length === 0 && (await t.page.locator('.akengage-gravatar-notice').count()) === 1 && (await t.page.$$('img[data-engage-gravatar-source]')).length === 0);
    await t.ctx.close();
    // consent_source=jbcookies con mode=off / always: no tiene efecto
    setParams({mode: 'off', consent_source: 'jbcookies', ...ANTIGUOS});
    t = await nuevo(browser);
    await cargar(t.page);
    r('J-33', 'mode=off + consent_source=jbcookies: sin efecto: 0 peticiones, sin gravatar.js, sin atributos', t.peticiones.length === 0 && (await t.page.evaluate(() => typeof window.AkeebaEngageGravatar)) === 'undefined' && (await t.page.$$('img[data-engage-gravatar-source]')).length === 0);
    await t.ctx.close();
    setParams({mode: 'always', consent_source: 'jbcookies', ...ANTIGUOS});
    t = await nuevo(browser);
    await cargar(t.page);
    r('J-34', 'mode=always + consent_source=jbcookies: sin efecto: comportamiento de always (fotos al cargar)', t.peticiones.length >= 2 && await todasGrav(t.page));
    await t.ctx.close();

    // ============ 7. Sin el modulo JBCookies (despublicado): nunca se cargan fotos ============
    sql(`UPDATE jos_modules SET published=0 WHERE module='mod_jbcookies'`);
    jb({jbcookies_group: ''});
    t = await nuevo(browser);
    await cargar(t.page);
    r('J-35', 'modulo JBCookies despublicado y consent_source=jbcookies: no hay aviso, 0 peticiones, nunca se cargan fotos (seguro por defecto)', t.peticiones.length === 0 && (await t.page.locator('.jb-cookie').count()) === 0 && await todasLocales(t.page) && (await t.page.locator('.akengage-gravatar-notice').count()) === 0);
    await t.ctx.close();
    sql(`UPDATE jos_modules SET published=1 WHERE module='mod_jbcookies'`);

    await browser.close();
    const fallos = res.filter((x) => x.estado !== 'PASA').length;
    fs.writeFileSync(`${WORK}/resultados-jbcookies.json`, JSON.stringify(res, null, 1));
    console.log(`\n${res.length - fallos}/${res.length} pasan`);
    process.exit(fallos ? 1 : 0);
})().catch((e) => { console.error(e); process.exit(1); });
