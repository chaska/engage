/**
 * 0.6.17: prueba en un NAVEGADOR real (Chromium headless con Playwright) del consentimiento previo de Gravatar.
 * Comprueba peticiones de red de verdad: antes del clic no sale ninguna a gravatar.com, tras aceptar si, persiste
 * al recargar, revocar la detiene, el API y el evento para gestores de cookies, almacenamiento bloqueado, show_notice=0,
 * y los modos off / always. Las peticiones a gravatar.com se interceptan (se registran y se responden con un PNG de
 * 1x1, no sale nada a Internet).
 *
 * Requisitos: Joomla de pruebas con 03-sembrar-y-probar.sh y lib/gravatar-http.php ya ejecutados (deja 2 comentarios).
 * Entorno: BASE_URL, WORK, IDS_FILE, DB_NAME, PW_MODULE (ruta de playwright), CHROMIUM (ejecutable). Sale con 1 si algo FALLA.
 */
const {execFileSync} = require('child_process');
const fs = require('fs');
const {chromium} = require(process.env.PW_MODULE || 'playwright');

const BASE = process.env.BASE_URL || 'http://127.0.0.1:8080';
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
    const j = p === null ? '{}' : JSON.stringify(p);
    sql(`UPDATE jos_extensions SET enabled=1, params='${j.replace(/'/g, "\\'")}' WHERE type='plugin' AND folder='engage' AND element='gravatar'`);
}

async function nuevo(browser, {bloquearStorage = false} = {}) {
    const ctx = await browser.newContext({locale: 'en-GB'});
    const peticiones = [];
    await ctx.route(/gravatar\.com/, (route) => {
        peticiones.push({url: route.request().url(), referer: route.request().headers()['referer'] || null});
        route.fulfill({status: 200, contentType: 'image/png', body: PNG});
    });
    // Cualquier otra peticion externa (no 127.0.0.1) tambien se registra y se aborta: el HTML no debe llamar a terceros.
    const externas = [];
    await ctx.route((u) => !/^(127\.0\.0\.1|localhost)$/.test(u.hostname) && !/gravatar\.com$/.test(u.hostname), (route) => { externas.push(route.request().url()); route.abort(); });
    if (bloquearStorage) {
        await ctx.addInitScript(() => {
            Object.defineProperty(window, 'localStorage', {get() { throw new DOMException('blocked', 'SecurityError'); }});
        });
    }
    const page = await ctx.newPage();
    const errores = [];
    page.on('pageerror', (e) => errores.push(String(e) + ' @' + String(e.stack).split('\n').slice(1,3).join(';')));
    return {ctx, page, peticiones, externas, errores};
}
async function cargar(page) {
    await page.goto(PAGE, {waitUntil: 'networkidle'});
    await page.waitForTimeout(700);
}
const srcs = (page) => page.$$eval('img.akengage-commenter-avatar', (a) => a.map((i) => i.getAttribute('src')));
const stor = (page) => page.evaluate(() => { try { return window.localStorage.getItem('engage_gravatar_consent'); } catch (e) { return 'ERR'; } });
const esLocal = (s) => /\/media\/com_engage\/images\/avatar-generico\.svg$/.test(s);
const esGrav = (s) => /^https:\/\/www\.gravatar\.com\/avatar\/[0-9a-f]{64}\?/.test(s);

(async () => {
    const browser = await chromium.launch({executablePath: process.env.CHROMIUM || '/opt/pw-browsers/chromium', args: ['--no-sandbox']});

    let t;
    if (process.env.CACHE_PAGINA === '1') {
    // ============ ask con la cache de pagina de Joomla (plugin cache + engagecache) ============
        setParams({mode: 'ask', ...ANTIGUOS});
        // Calienta la cache con una visita de invitado; la siguiente sale de ella.
        const cal = await nuevo(browser); await cargar(cal.page); await cal.ctx.close();
        t = await nuevo(browser);
        const resp = await t.page.goto(PAGE, {waitUntil: 'networkidle'});
        await t.page.waitForTimeout(700);
        const htmlC = await resp.text();
        const aviso2 = t.page.locator('.akengage-gravatar-notice');
        r('C-01', 'cache de pagina activa: el HTML cacheado sigue sin ninguna URL de gravatar.com fuera de data-engage-gravatar y 0 peticiones antes del clic', t.peticiones.length === 0 && !/(https?:)?\/\/[^\s"'<>]*gravatar\.com/i.test(htmlC.replace(/data-engage-gravatar="[^"]*"/g, '').replace(/\\\//g, '/')), `peticiones=${t.peticiones.length}`);
        r('C-02', 'cache de pagina: el aviso se pinta con los textos traducidos (Text::script/engagecache)', (await aviso2.count()) === 1 && (await aviso2.locator('button').textContent()) === 'Show Gravatar photos (this sends your IP to gravatar.com)');
        await aviso2.locator('button').click(); await t.page.waitForTimeout(500);
        const trasAceptar = t.peticiones.length;
        t.peticiones.length = 0;
        await cargar(t.page);
        r('C-03', 'cache de pagina: aceptar carga las fotos y la decision (cliente, localStorage) persiste en la pagina cacheada', trasAceptar >= 2 && t.peticiones.length >= 2 && (await srcs(t.page)).every(esGrav), `aceptar=${trasAceptar}, recarga=${t.peticiones.length}`);
        await t.ctx.close();
        // Otro visitante (contexto nuevo) recibe el mismo HTML cacheado y NO hereda el consentimiento del anterior
        t = await nuevo(browser); await cargar(t.page);
        r('C-04', 'cache de pagina: un visitante distinto no hereda el consentimiento (la decision no depende del servidor)', t.peticiones.length === 0 && (await srcs(t.page)).every(esLocal));
        await t.ctx.close();
        } else {
    // ============ ask (mode ausente: ajustes antiguos) ============
    setParams(ANTIGUOS);
    t = await nuevo(browser);
    await cargar(t.page);
    let s = await srcs(t.page);
    r('N-01', 'ask (mode ausente): antes del clic NO sale ninguna peticion a gravatar.com (ni a ningun tercero)', t.peticiones.length === 0 && t.externas.length === 0, `peticiones a gravatar=${t.peticiones.length}, otras externas=${t.externas.length}`);
    r('N-02', 'ask: todos los avatares muestran el SVG local y llevan data-engage-gravatar', s.length === 2 && s.every(esLocal) && (await t.page.$$('img[data-engage-gravatar]')).length === 2, JSON.stringify(s.map((x) => x.slice(-30))));
    const aviso = t.page.locator('.akengage-gravatar-notice');
    const boton = aviso.locator('button');
    r('N-03', 'ask: aviso accesible (role=region, con nombre) con el boton "Show Gravatar photos (this sends your IP to gravatar.com)"', (await aviso.count()) === 1 && (await aviso.getAttribute('role')) === 'region' && !!(await aviso.getAttribute('aria-label')) && (await boton.textContent()) === 'Show Gravatar photos (this sends your IP to gravatar.com)', await boton.textContent());
    r('N-04', 'ask: sin consentimiento previo no hay clave en localStorage; sin cookies de Engage/Gravatar', (await stor(t.page)) === null && (await t.ctx.cookies()).every((c) => !/gravatar|consent/i.test(c.name)), 'localStorage=' + (await stor(t.page)));
    r('N-05', 'ask: window.AkeebaEngageGravatar existe y isGranted()=false', await t.page.evaluate(() => typeof window.AkeebaEngageGravatar === 'object' && ['grant', 'revoke', 'isGranted'].every((k) => typeof window.AkeebaEngageGravatar[k] === 'function') && window.AkeebaEngageGravatar.isGranted() === false));
    await t.page.screenshot({path: `${WORK}/gravatar-aviso.png`, clip: await aviso.boundingBox().then((b) => ({x: 0, y: Math.max(0, b.y - 10), width: 900, height: 260}))}).catch(() => {});
    // aceptar
    await boton.click();
    await t.page.waitForTimeout(700);
    s = await srcs(t.page);
    r('N-06', 'ask: tras aceptar SI se piden las imagenes a gravatar.com (2 avatares), todas https://www.gravatar.com/avatar/<sha256>, sin Referer', t.peticiones.length >= 2 && t.peticiones.every((p) => esGrav(p.url) && p.referer === null) && s.every(esGrav), `peticiones=${t.peticiones.length}, referers=${JSON.stringify(t.peticiones.map((p) => p.referer))}`);
    r('N-07', 'ask: las imagenes se cargaron de verdad (naturalWidth>0) y la preferencia queda en localStorage engage_gravatar_consent=1', (await t.page.$$eval('img.akengage-commenter-avatar', (a) => a.every((i) => i.complete && i.naturalWidth > 0))) && (await stor(t.page)) === '1');
    r('N-08', 'ask: tras aceptar el aviso pasa a estado con boton "Stop showing Gravatar photos" y el foco se mueve a el', (await aviso.locator('button').textContent()) === 'Stop showing Gravatar photos' && (await t.page.evaluate(() => document.activeElement && document.activeElement.textContent)) === 'Stop showing Gravatar photos');
    // recargar
    t.peticiones.length = 0;
    await cargar(t.page);
    s = await srcs(t.page);
    r('N-09', 'ask: tras recargar la preferencia persiste: se cargan las fotos sin pedir de nuevo y el aviso ofrece revocar', t.peticiones.length >= 2 && s.every(esGrav) && (await aviso.locator('button').textContent()) === 'Stop showing Gravatar photos', `peticiones=${t.peticiones.length}`);
    // revocar
    t.peticiones.length = 0;
    await aviso.locator('button').click();
    await t.page.waitForTimeout(500);
    s = await srcs(t.page);
    r('N-10', 'ask: revocar borra la clave, vuelve al avatar local y el aviso vuelve a pedir consentimiento', (await stor(t.page)) === null && s.every(esLocal) && (await aviso.locator('button').textContent()) === 'Show Gravatar photos (this sends your IP to gravatar.com)');
    await cargar(t.page);
    r('N-11', 'ask: tras revocar y recargar no sale ninguna peticion a gravatar.com', t.peticiones.length === 0, `peticiones=${t.peticiones.length}`);
    // API y evento
    await t.page.evaluate(() => document.dispatchEvent(new CustomEvent('engage:gravatar-consent', {detail: {granted: true}})));
    await t.page.waitForTimeout(500);
    r('N-12', 'evento engage:gravatar-consent {granted:true}: carga las fotos y guarda la clave', t.peticiones.length >= 2 && (await srcs(t.page)).every(esGrav) && (await stor(t.page)) === '1' && (await t.page.evaluate(() => window.AkeebaEngageGravatar.isGranted())) === true);
    await t.page.evaluate(() => document.dispatchEvent(new CustomEvent('engage:gravatar-consent', {detail: {granted: false}})));
    r('N-13', 'evento engage:gravatar-consent {granted:false}: vuelve al avatar local y borra la clave', (await srcs(t.page)).every(esLocal) && (await stor(t.page)) === null && (await t.page.evaluate(() => window.AkeebaEngageGravatar.isGranted())) === false);
    t.peticiones.length = 0;
    await t.page.evaluate(() => { document.dispatchEvent(new CustomEvent('engage:gravatar-consent', {detail: {granted: 'si'}})); document.dispatchEvent(new CustomEvent('engage:gravatar-consent', {detail: null})); document.dispatchEvent(new CustomEvent('engage:gravatar-consent')); });
    await t.page.waitForTimeout(300);
    r('N-14', 'eventos con detail invalido (granted no booleano, null, ausente) se ignoran', t.peticiones.length === 0 && (await srcs(t.page)).every(esLocal));
    await t.page.evaluate(() => window.AkeebaEngageGravatar.grant());
    await t.page.waitForTimeout(400);
    const okGrant = t.peticiones.length >= 2;
    await t.page.evaluate(() => window.AkeebaEngageGravatar.revoke());
    r('N-15', 'API grant()/revoke() equivalen al boton', okGrant && (await srcs(t.page)).every(esLocal) && (await stor(t.page)) === null);
    // lista blanca en el navegador: URL manipulada en el DOM
    t.peticiones.length = 0;
    await t.page.evaluate(() => { const i = document.querySelector('img[data-engage-gravatar]'); i.setAttribute('data-engage-gravatar', 'https://www.gravatar.com.evil.example/avatar/' + 'a'.repeat(64)); window.AkeebaEngageGravatar.grant(); });
    await t.page.waitForTimeout(400);
    const primera = (await srcs(t.page))[0];
    r('N-16', 'lista blanca en el navegador: un data-engage-gravatar manipulado (otro host) NUNCA se asigna a src', esLocal(primera) && t.externas.length === 0 && t.peticiones.every((p) => esGrav(p.url)), 'src=' + primera.slice(-34));
    await t.page.evaluate(() => window.AkeebaEngageGravatar.revoke());
    r('N-17', 'ask: sin errores de JavaScript en toda la sesion', t.errores.length === 0, t.errores.join(' | '));
    await t.ctx.close();

    // ============ almacenamiento bloqueado ============
    // Referencia: con el plugin en modo off (sin gravatar.js) y localStorage bloqueado, comments.min.js (codigo original de
    // Engage, loadCommenterInfo) y TinyMCE ya lanzan un error no capturado. Lo nuestro no debe anadir ninguno.
    setParams({mode: 'off', ...ANTIGUOS});
    t = await nuevo(browser, {bloquearStorage: true});
    await cargar(t.page);
    const erroresBase = t.errores.length;
    await t.ctx.close();
    setParams({mode: 'ask', ...ANTIGUOS});
    t = await nuevo(browser, {bloquearStorage: true});
    await cargar(t.page);
    const bloqueadoInicial = t.peticiones.length;
    await t.page.locator('.akengage-gravatar-notice button').click();
    await t.page.waitForTimeout(500);
    r('N-18', 'localStorage bloqueado (lanza excepcion): antes del clic 0 peticiones; tras aceptar se cargan las fotos en esa pagina; gravatar.js no anade errores de JavaScript', bloqueadoInicial === 0 && t.peticiones.length >= 2 && t.errores.length <= erroresBase, `antes=${bloqueadoInicial}, despues=${t.peticiones.length}, errores=${t.errores.length} (referencia sin gravatar.js: ${erroresBase}, preexistentes de comments.min.js/TinyMCE)`);
    await t.ctx.close();

    // ============ show_notice=0 ============
    setParams({mode: 'ask', show_notice: '0', ...ANTIGUOS});
    t = await nuevo(browser);
    await cargar(t.page);
    const sinAviso = (await t.page.locator('.akengage-gravatar-notice').count()) === 0;
    await t.page.evaluate(() => window.AkeebaEngageGravatar.grant());
    await t.page.waitForTimeout(400);
    r('N-19', 'show_notice=0: sin aviso propio ni peticiones antes; el API sigue funcionando (grant carga las fotos)', sinAviso && t.peticiones.length >= 2 && (await srcs(t.page)).every(esGrav), `peticiones=${t.peticiones.length}`);
    await t.ctx.close();

    // ============ off ============
    setParams({mode: 'off', ...ANTIGUOS});
    t = await nuevo(browser);
    await t.ctx.addInitScript(() => { try { window.localStorage.setItem('engage_gravatar_consent', '1'); } catch (e) {} });
    await cargar(t.page);
    r('N-20', 'off (incluso con engage_gravatar_consent=1 en localStorage): 0 peticiones a gravatar.com, sin aviso y avatar local', t.peticiones.length === 0 && (await t.page.locator('.akengage-gravatar-notice').count()) === 0 && (await srcs(t.page)).every(esLocal) && (await t.page.evaluate(() => typeof window.AkeebaEngageGravatar)) === 'undefined', `peticiones=${t.peticiones.length}`);
    await t.ctx.close();

    // ============ always ============
    setParams({mode: 'always', ...ANTIGUOS});
    t = await nuevo(browser);
    await cargar(t.page);
    r('N-21', 'always: comportamiento anterior; las fotos se piden al cargar sin ninguna interaccion y no hay aviso', t.peticiones.length >= 2 && (await srcs(t.page)).every(esGrav) && (await t.page.locator('.akengage-gravatar-notice').count()) === 0, `peticiones=${t.peticiones.length}`);
    await t.ctx.close();

    // ============ ask en movil (accesibilidad basica: el aviso cabe y el boton es pulsable) ============
    setParams({mode: 'ask', ...ANTIGUOS});
    const ctxM = await browser.newContext({viewport: {width: 360, height: 740}, hasTouch: true});
    const pm = await ctxM.newPage();
    const pet = [];
    await ctxM.route(/gravatar\.com/, (route) => { pet.push(route.request().url()); route.fulfill({status: 200, contentType: 'image/png', body: PNG}); });
    await pm.goto(PAGE, {waitUntil: 'networkidle'});
    const caja = await pm.locator('.akengage-gravatar-notice button').boundingBox();
    const desborda = await pm.evaluate(() => document.documentElement.scrollWidth > window.innerWidth);
    r('N-22', 'ask en 360px: el aviso no provoca desbordamiento horizontal y el boton es visible/pulsable', !!caja && caja.width > 0 && caja.x >= 0 && caja.x + caja.width <= 360 && !desborda && pet.length === 0, `boton=${JSON.stringify(caja && {x: Math.round(caja.x), w: Math.round(caja.width), h: Math.round(caja.height)})}`);
    await ctxM.close();

    }
    setParams({mode: 'ask', ...ANTIGUOS});
    await browser.close();
    const c = {PASA: 0, FALLA: 0};
    res.forEach((x) => c[x.estado]++);
    fs.writeFileSync(`${WORK}/resultados-gravatar-navegador.json`, JSON.stringify({resumen: c, pruebas: res}, null, 2));
    console.log('\nRESUMEN: ' + JSON.stringify(c));
    process.exit(c.FALLA ? 1 : 0);
})().catch((e) => { console.error('ERROR', e); process.exit(2); });
