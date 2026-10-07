/**
 * 0.8.1: el PoC de la revision independiente (XSS reflejado y almacenado por la cache) y sus variantes en CHROMIUM sobre el Joomla real, con y sin
 * cache de pagina (caching=1 con y sin el plugin engagecache, con y sin live_site). Para cada configuracion x URL de ataque:
 *   - NO se dispara ningun dialogo (alert/confirm/prompt), no se ejecuta ningun script inyectado (canario window.__xss), no hay nodos <script> con el
 *     codigo del atacante, ni atributos on*, ni <svg onload>, ni <img onerror>, ni enlaces javascript:/data:;
 *   - el SIGUIENTE visitante (otro contexto del navegador, URL normal) recibe la pagina normal (lo que dejo la cache);
 *   - se pulsa el enlace de la fecha de cada comentario y el boton Copiar: se queda en el mismo sitio y copia un enlace con el origen real;
 *   - con una cabecera Host hostil enviada con Node (un navegador no puede) y la cache vacia, la visita normal del navegador no tiene rastro.
 * Uso: PW_MODULE=/opt/node-tools/node_modules/playwright CHROMIUM=/opt/pw-browsers/chromium WORK=... SITE_DIR=... node xss-navegador.js
 */
'use strict';
const {execFileSync} = require('child_process');
const http = require('http');
const path = require('path');
const {chromium} = require(process.env.PW_MODULE || 'playwright');
const BASE = process.env.BASE_URL || 'http://127.0.0.1:8080';
const ART = '/index.php?option=com_content&view=article&id=1&catid=8';
const CFG = path.join(__dirname, 'xss-config.php');
const cfg = (...a) => execFileSync('php', [CFG, ...a], {env: process.env}).toString();
let ok = 0, ko = 0;
const r = (id, desc, cond, ev) => { (cond ? ok++ : ko++); console.log(`${id.padEnd(9)} ${cond ? 'PASA ' : 'FALLA'} ${desc}${ev ? '  [' + String(ev).slice(0, 300) + ']' : ''}`); };

const ALERT = 'alert(document.domain)', CANARIO = '(window.__xss=true)';
const cargas = {};
for (const [n, c] of [['alert', ALERT], ['canario', CANARIO]]) {
  Object.assign(cargas, {
    [`poc-${n}`]: `x=%22%3E%3Cscript%3E${encodeURIComponent(c)}%3C/script%3E`,
    [`simples-${n}`]: `x=%27%3E%3Cscript%3E${encodeURIComponent(c)}%3C/script%3E`,
    [`svg-${n}`]: `x=%22%3E%3Csvg%20onload%3D${encodeURIComponent(c)}%3E`,
    [`img-${n}`]: `x=%22%3E%3Cimg%20src%3Dx%20onerror%3D${encodeURIComponent(c)}%3E`,
    [`attr-${n}`]: `p=%22%20autofocus%20onfocus%3D%22${encodeURIComponent(c)}`,
    [`sort-${n}`]: `akengage_sort=%22%3E%3Cscript%3E${encodeURIComponent(c)}%3C/script%3E`,
    [`fav-${n}`]: `akengage_fav=%22%3E%3Csvg%20onload%3D${encodeURIComponent(c)}%3E`,
    [`ls-${n}`]: `limitstart=%22%3E%3Cscript%3E${encodeURIComponent(c)}%3C/script%3E&akengage_limitstart=%27%3E%3Cscript%3E${encodeURIComponent(c)}%3C/script%3E`,
    [`cid-${n}`]: `cid=%22%3E%3Cscript%3E${encodeURIComponent(c)}%3C/script%3E&akengage_cid=%22%3E%3Cscript%3E${encodeURIComponent(c)}%3C/script%3E`,
    [`entidad-${n}`]: `x=%26quot%3B%26gt%3B%26lt%3Bscript%26gt%3B${encodeURIComponent(c)}%26lt%3B/script%26gt%3B`,
    [`salto-${n}`]: `x=a%0d%0a%22%3E%3Cscript%3E${encodeURIComponent(c)}%3C/script%3E`,
  });
}
cargas['js'] = 'x=javascript:alert(document.domain)';
cargas['junto'] = 'x=%22%3E%3Cscript%3Ealert(1)%3C/script%3E&akengage_sort=newest&akengage_limitstart=0&akengage_fav=1';

const configs = [
  ['sin cache, con live_site', [0, 0, 1, 1]],
  ['cache de paginas + engagecache, con live_site', [1, 1, 1, 1]],
  ['cache de paginas + engagecache, sin live_site', [1, 1, 1, 0]],
  ['cache de paginas SIN engagecache, sin live_site', [1, 1, 0, 0]],
  ['conservadora + engagecache, sin live_site', [1, 0, 1, 0]],
  ['conservadora SIN engagecache, con live_site', [1, 0, 0, 1]],
];

/** Mide el DOM tras cargar: lo que habria inyectado el atacante. */
async function mide(page) {
  return page.evaluate(() => {
    const aj = (n) => Array.from(document.querySelectorAll(n));
    const conOn = aj('*').filter(e => Array.from(e.attributes).some(a => /^on/i.test(a.name))).length;
    const scripts = aj('script:not([src])').filter(s => !/json/i.test(s.type) && /alert\(|__xss/.test(s.textContent)).length;
    const mal = aj('a[href],link[href],iframe[src],form[action]').filter(e => /^\s*(javascript|data|vbscript):/i.test(e.getAttribute('href') || e.getAttribute('src') || e.getAttribute('action') || '')).length;
    return {conOn, scripts, svg: aj('svg[onload]').length, img: aj('img[onerror]').length, mal, xss: !!window.__xss, comentarios: aj('.akengage-comment-item').length};
  });
}

(async () => {
  const b = await chromium.launch({executablePath: process.env.CHROMIUM || '/opt/pw-browsers/chromium', args: ['--no-sandbox']});
  cfg('seed');
  try {
    let total = 0, malos = [];
    for (const [nombre, c] of configs) {
      cfg('set', ...c.map(String));
      let mal = [];
      for (const [k, qs] of Object.entries(cargas)) {
        cfg('set', ...c.map(String)); // vacia la cache: el atacante es el primero en llegar
        const ctxA = await b.newContext(); const pA = await ctxA.newPage();
        let dialogos = 0; pA.on('dialog', async d => { dialogos++; await d.dismiss(); });
        await pA.goto(`${BASE}${ART}&${qs}`, {waitUntil: 'load'}); await pA.waitForTimeout(250);
        const a = await mide(pA);
        const ctxV = await b.newContext(); const pV = await ctxV.newPage();
        let dialogosV = 0; pV.on('dialog', async d => { dialogosV++; await d.dismiss(); });
        await pV.goto(`${BASE}${ART}`, {waitUntil: 'load'}); await pV.waitForTimeout(250);
        const v = await mide(pV);
        total += 2;
        for (const [quien, m, d] of [['ataque', a, dialogos], ['visitante', v, dialogosV]]) {
          if (d || m.conOn || m.scripts || m.svg || m.img || m.mal || m.xss || m.comentarios < 3) mal.push(`${k}/${quien}: dialogos=${d} on=${m.conOn} scripts=${m.scripts} svg=${m.svg} img=${m.img} mal=${m.mal} xss=${m.xss} comentarios=${m.comentarios}`);
        }
        await ctxA.close(); await ctxV.close();
      }
      r('N-' + nombre.slice(0, 5), `${nombre}: ${Object.keys(cargas).length} URLs de ataque x (ataque + siguiente visitante): ningun dialogo, ningun script inyectado, ningun on*, svg, img ni enlace javascript:`, mal.length === 0, mal.slice(0, 3).join(' | '));
      malos = malos.concat(mal);
    }
    r('N-TOT', `${total} paginas cargadas en Chromium en ${configs.length} configuraciones: ${malos.length} con ejecucion o nodos inyectados`, malos.length === 0);

    // Enlace de la fecha y boton Copiar tras un ataque, con la pagina servida desde la cache
    cfg('set', '1', '1', '1', '1'); // con live_site: sin el, el servidor integrado de PHP da mal la ruta base de los scripts y tools.js no carga
    const ctx = await b.newContext({permissions: ['clipboard-read', 'clipboard-write']}); const p = await ctx.newPage();
    let dlg = 0; p.on('dialog', async d => { dlg++; await d.dismiss(); });
    await p.goto(`${BASE}${ART}&x=%22%3E%3Cscript%3Ealert(1)%3C/script%3E&p=ATACANTE`, {waitUntil: 'load'}); await p.waitForTimeout(300);
    const href = await p.$eval('.akengage-comment-permalink a', a => a.getAttribute('href'));
    r('N-LNK', 'el enlace de la fecha es relativo y sin parametros del atacante', /^\/[^"<>]*akengage_cid=\d+#akengage-comment-\d+$/.test(href) && !/ATACANTE|alert|script/.test(href), href);
    await p.click('.akengage-comment-permalink a'); await p.waitForTimeout(400);
    r('N-NAV', 'pulsarlo deja al visitante en el mismo sitio, con el comentario en el ancla, sin dialogos', p.url().startsWith(BASE + '/') && /#akengage-comment-\d+$/.test(p.url()) && dlg === 0, p.url());
    const visible = await p.$('.akengage-copy-btn:visible');
    if (visible) {
      await visible.click(); await p.waitForTimeout(300);
      const copiado = await p.evaluate(() => navigator.clipboard.readText());
      r('N-COPY', 'el boton Copiar copia un enlace absoluto con el origen REAL de la pagina (no el de quien la pidio primero)', copiado.startsWith(BASE + '/') && /akengage_cid=\d+#akengage-comment-\d+$/.test(copiado) && !/ATACANTE|atacante/.test(copiado), copiado);
    } else { r('N-COPY', 'boton Copiar visible', false, 'no hay boton visible'); }
    await ctx.close();

    // Cabecera Host hostil (Node) con la cache vacia y luego visita normal en Chromium
    for (const [nombre, c] of [['cache de paginas + engagecache, sin live_site', [1, 1, 1, 0]], ['conservadora SIN engagecache, sin live_site', [1, 0, 0, 0]], ['conservadora + engagecache, sin live_site', [1, 0, 1, 0]]]) {
      cfg('set', ...c.map(String));
      for (const host of ['atacante.test', 'atacante.test:8080']) {
        await new Promise((res) => { const q = http.request({host: '127.0.0.1', port: 8080, path: `${ART}&p=ATACANTE&akengage_sort=top&x=EXTRA`, headers: {Host: host}}, (rs) => { rs.resume(); rs.on('end', res); }); q.on('error', res); q.end(); });
        const cx = await b.newContext(); const pg = await cx.newPage();
        await pg.goto(`${BASE}${ART}`, {waitUntil: 'load'}); await pg.waitForTimeout(200);
        const html = await pg.evaluate(() => { const c = document.documentElement.cloneNode(true); c.querySelectorAll('script[type*=json]').forEach(s => s.remove()); return c.outerHTML; });
        const copias = await pg.$$eval('[data-engage-copy]', e => e.map(x => x.getAttribute('data-engage-copy')));
        const canon = await pg.$$eval('link[rel=canonical]', e => e.map(x => x.href));
        r('N-H' + c.join(''), `${nombre}, Host ${host}: la visita normal posterior no tiene rastro del anfitrion ni de la consulta del atacante (copias=${copias.length}, canonical=${canon.length})`,
          !/atacante\.test|ATACANTE|EXTRA/.test(html) && copias.every(x => x.startsWith('/')) && canon.every(x => !/atacante/.test(x)), copias[0]);
        await cx.close();
      }
    }
  } finally {
    cfg('restore'); await b.close();
  }
  console.log(`\n-> ${ok} PASAN, ${ko} FALLAN`);
  process.exit(ko ? 1 : 0);
})().catch((e) => { console.error(e); try { cfg('restore'); } catch (x) {} process.exit(2); });
