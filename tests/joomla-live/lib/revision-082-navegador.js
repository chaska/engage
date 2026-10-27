/**
 * 0.8.3, H3 y H9 en CHROMIUM sobre el Joomla real.
 *  H3: BBCode [url] / [img] con javascript:, entidades, tabuladores, data:, vbscript: en los tres filter_mode (joomla, htmlpurifier, strict) vistos por un
 *      invitado y por un Super User (filtro de Joomla «sin filtrado»): ningun dialogo al pulsar los enlaces, ningun href/src javascript:, data: ni vbscript:;
 *      los enlaces legitimos (https, mailto) y la negrita siguen funcionando.
 *  H9: HTML con `background:url(http://127.0.0.1:8099/pixel.gif)` (pixel de seguimiento) y estilos que recolocan el diseno, con htmlpurifier y strict:
 *      0 peticiones al «pixel», el texto no se sale de su sitio (posicion normal, sin margen negativo) y el estilo legitimo (color, negrita) se conserva.
 * Uso: PW_MODULE=... CHROMIUM=... WORK=... SITE_DIR=... IDS_FILE=... node revision-082-navegador.js (admintest: $WORK/adminpass.txt)
 */
'use strict';
const {execFileSync} = require('child_process');
const fs = require('fs');
const path = require('path');
const {chromium} = require(process.env.PW_MODULE || 'playwright');
const BASE = process.env.BASE_URL || 'http://127.0.0.1:8080';
const ART = '/index.php?option=com_content&view=article&id=1&catid=8';
const DATOS = path.join(__dirname, 'revision-082-nav-datos.php');
const datos = (...a) => execFileSync('php', [DATOS, ...a], {env: process.env}).toString();
let ok = 0, ko = 0;
const r = (id, desc, cond, ev) => { (cond ? ok++ : ko++); console.log(`${id.padEnd(9)} ${cond ? 'PASA ' : 'FALLA'} ${desc}${ev ? '  [' + String(ev).slice(0, 300) + ']' : ''}`); };
const adminPass = 'Aa1!' + fs.readFileSync(path.join(process.env.WORK, 'adminpass.txt'), 'utf8').trim();

async function login(ctx) {
  const html = await (await ctx.request.get(BASE + '/')).text();
  const token = (html.match(/name="([0-9a-f]{32})" value="1"/) || [])[1] || '';
  await ctx.request.post(BASE + '/index.php?option=com_users&task=user.login', {form: {username: 'admintest', password: adminPass, return: Buffer.from(BASE + '/').toString('base64'), [token]: '1'}, maxRedirects: 0});
}

(async () => {
  datos('sembrar');
  const browser = await chromium.launch({executablePath: process.env.CHROMIUM || undefined, args: ['--no-sandbox']});
  try {
    for (const modo of ['joomla', 'htmlpurifier', 'strict']) {
      datos('modo', modo);
      for (const quien of ['invitado', 'superusuario']) {
        const ctx = await browser.newContext({viewport: {width: 1100, height: 900}});
        const pixel = [];
        await ctx.route(/127\.0\.0\.1:8099/, (route) => { pixel.push(route.request().url()); route.abort(); });
        if (quien === 'superusuario') { await login(ctx); }
        const page = await ctx.newPage();
        const dialogos = [];
        page.on('dialog', async (d) => { dialogos.push(d.message()); await d.dismiss(); });
        await page.goto(BASE + ART, {waitUntil: 'networkidle'});
        const id = `${modo}/${quien}`;
        const login_ok = quien === 'invitado' || (await page.content()).includes('user.logout');
        if (!login_ok) { r(`NAV-${id}`, 'sesion de Super User abierta', false); await ctx.close(); continue; }
        // Todos los enlaces e imagenes del area de comentarios
        const enlaces = await page.$$eval('#akengage-comments-section a[href]', (as) => as.map((a) => ({href: a.getAttribute('href'), texto: a.textContent.trim()})));
        const imgs = await page.$$eval('#akengage-comments-section img[src]', (is) => is.map((i) => i.getAttribute('src')));
        const malo = (v) => /^[\s\x00-\x20]*(javascript|vbscript|data):/i.test(String(v).replace(/[\t\r\n]/g, ''));
        const hrefsMalos = enlaces.filter((e) => malo(e.href)).map((e) => e.href);
        const srcsMalos = imgs.filter(malo);
        r(`H3-${id}-1`, `[${modo}/${quien}] ningun enlace ni imagen del hilo apunta a javascript:, vbscript: ni data:`, hrefsMalos.length === 0 && srcsMalos.length === 0, JSON.stringify(hrefsMalos.concat(srcsMalos)));
        // Pulsar todos los enlaces que el BBCode hostil ha dejado como texto o enlace
        for (const t of ['clic-b1', 'clic-b2', 'clic-b4', 'clic-b5', 'clic-b6', 'clic-b7', 'clic-b7b']) {
          const el = await page.$(`#akengage-comments-section :text("${t}")`);
          if (el) { await el.click({timeout: 3000, noWaitAfter: true}).catch(() => {}); await page.waitForTimeout(100); }
        }
        const canario = await page.evaluate(() => window.__xss === true).catch(() => false);
        r(`H3-${id}-2`, `[${modo}/${quien}] al pulsar cada enlace hostil no se abre ningun dialogo ni se ejecuta codigo`, dialogos.length === 0 && !canario, dialogos.join('|'));
        if (!page.url().startsWith(BASE + '/index.php?option=com_content')) { await page.goto(BASE + ART, {waitUntil: 'networkidle'}); }
        // Con filter_mode joomla / htmlpurifier un invitado (filtro «Sin HTML» de Joomla por omision) ve texto plano: lo legitimo solo se comprueba con HTML permitido
        const htmlPermitido = modo === 'strict' || quien === 'superusuario';
        const legit = enlaces.filter((e) => e.href === 'https://example.com/ok?a=1&b=2' || e.href === 'mailto:ana@example.com');
        const neg = await page.$$eval('#akengage-comments-section strong', (s) => s.map((x) => x.textContent));
        const relOk = await page.$$eval('#akengage-comments-section a[href^="https://example.com/ok"]', (as) => as.every((a) => /nofollow/.test(a.getAttribute('rel') || '') && /ugc/.test(a.getAttribute('rel') || '')));
        if (htmlPermitido) {
          r(`H3-${id}-3`, `[${modo}/${quien}] los enlaces legitimos (https y mailto) y la negrita siguen funcionando, con rel="nofollow ugc noreferrer"`, legit.length === 2 && neg.includes('negrita-legitima') && relOk, `enlaces=${legit.length}`);
        }
        if (modo !== 'joomla') {
          // H9
          r(`H9-${id}-1`, `[${modo}/${quien}] el pixel de seguimiento (background:url(http://127.0.0.1:8099/...)) no genera ninguna peticion`, pixel.length === 0 && !(await page.content()).includes('8099'), pixel.join(','));
          const geo = await page.evaluate(() => {
            const out = {};
            for (const [k, t] of [['h2', 'layout-h2'], ['ok', 'estilo-legitimo-h2']]) {
              const el = [...document.querySelectorAll('#akengage-comments-section span')].find((s) => s.textContent.trim() === t);
              if (!el) { out[k] = null; continue; }
              const cs = getComputedStyle(el); const b = el.getBoundingClientRect();
              out[k] = {pos: cs.position, ml: cs.marginLeft, fs: parseFloat(cs.fontSize), w: Math.round(b.width), color: cs.color, fw: cs.fontWeight, style: el.getAttribute('style') || ''};
            }
            return out;
          });
          if (!htmlPermitido) { await ctx.close(); continue; }
          r(`H9-${id}-2`, `[${modo}/${quien}] el texto con position:fixed, margen negativo, 9999px y 900px queda en su sitio (position static, sin margen, tamano normal)`, !!geo.h2 && geo.h2.pos === 'static' && geo.h2.ml === '0px' && geo.h2.fs < 40 && geo.h2.w < 600 && !/position|margin|width|height|z-index|900px/.test(geo.h2.style), JSON.stringify(geo.h2));
          r(`H9-${id}-3`, `[${modo}/${quien}] el estilo legitimo (color rojo, negrita) se conserva`, !!geo.ok && geo.ok.color === 'rgb(255, 0, 0)' && Number(geo.ok.fw) >= 700, JSON.stringify(geo.ok));
        }
        await ctx.close();
      }
    }
  } finally {
    await browser.close();
    datos('borrar');
  }
  console.log(`RESUMEN navegador: ${ok} PASA / ${ko} FALLA`);
  process.exit(ko ? 1 : 0);
})().catch((e) => { console.error(e); try { datos('borrar'); } catch (x) {} process.exit(1); });
