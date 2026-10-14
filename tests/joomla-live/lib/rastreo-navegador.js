// 0.9.4: rastreo de robots en Chromium REAL: la URL base queda indexable, las variantes (fecha del comentario, paginacion, orden, favoritos) llevan
// noindex/canonical/nofollow, y las PERSONAS siguen pudiendo usarlo todo (clic en la fecha, en la paginacion y en el orden; el boton Copiar enlace);
// el panel de control muestra el semaforo «Rastreo de robots». Requiere lib/filtro-datos.php crear. Variables: WORK, PW_MODULE, CHROMIUM, BASE_URL.
const fs = require('fs');
const { chromium } = require(process.env.PW_MODULE || '/opt/node-tools/node_modules/playwright');
const WORK = process.env.WORK; const BASE = process.env.BASE_URL || 'http://127.0.0.1:8080';
const AP = 'Aa1!' + fs.readFileSync(WORK + '/adminpass.txt', 'utf8').trim();
const { execFileSync } = require('child_process');
const opc = (m) => execFileSync('php', [__dirname + '/rastreo-opciones.php', m], { env: process.env });
let ok = 0, ko = 0;
const r = (id, d, c, ev = '') => { (c ? ok++ : ko++); console.log(`${id.padEnd(7)} ${c ? 'PASA ' : 'FALLA'} ${d}${ev ? '  [' + String(ev).slice(0, 240) + ']' : ''}`); };
const PAGE = BASE + '/index.php?option=com_content&view=article&id=1&catid=8';
(async () => {
  opc('on');
  const b = await chromium.launch({ executablePath: process.env.CHROMIUM || '/opt/pw-browsers/chromium', args: ['--no-sandbox'] });
  const ctx = await b.newContext({ viewport: { width: 1280, height: 900 } }); await ctx.grantPermissions(['clipboard-read', 'clipboard-write'], { origin: BASE }).catch(() => null);
  await ctx.route(/guidedtours/, (rt) => rt.abort()); const p = await ctx.newPage(); const errs = []; p.on('pageerror', (e) => errs.push(String(e)));
  const head = () => p.evaluate(() => ({
    robots: Array.from(document.querySelectorAll('meta[name=robots]')).map((m) => m.content),
    canon: Array.from(document.querySelectorAll('link[rel=canonical]')).map((l) => l.href),
    links: Array.from(document.querySelectorAll('a[href*="akengage_"]')).map((a) => ({ h: a.getAttribute('href'), nf: a.relList.contains('nofollow') })),
    comments: document.querySelectorAll('[id^="akengage-comment-"]').length, url: location.href,
  }));
  const abre = async (u) => { await p.goto(u); await p.waitForLoadState('load'); await p.waitForTimeout(600); };

  await abre(PAGE); const base = await head();
  r('B-01', 'URL base en Chromium: sin meta robots noindex ni canonical de Engage (indexable) y con comentarios', !base.robots.some((x) => /noindex/i.test(x)) && base.canon.length === 0 && base.comments > 0, JSON.stringify([base.robots, base.canon, base.comments]));
  r('B-02', 'Todos los enlaces con akengage_ de la pagina (fecha, paginacion, orden, favoritos) tienen rel nofollow en el DOM', base.links.length >= 25 && base.links.every((l) => l.nf), base.links.length + ' enlaces; sin nofollow: ' + base.links.filter((l) => !l.nf).map((l) => l.h).slice(0, 2).join(' '));

  // Una persona hace clic en la fecha de un comentario: llega al comentario (el ancla), la variante lleva noindex y canonical a la base
  const fecha = await p.$('.akengage-comment-permalink a');
  const href = await fecha.getAttribute('href');
  await Promise.all([p.waitForNavigation({ waitUntil: 'load' }), fecha.click()]); await p.waitForTimeout(600);
  const v1 = await head();
  r('B-03', 'Clic en la fecha de un comentario: abre la variante con noindex, follow y canonical a la URL base (la persona ve el articulo y sus comentarios)', /akengage_cid=\d+/.test(v1.url) && v1.robots.includes('noindex, follow') && v1.canon.length === 1 && v1.canon[0].startsWith(BASE + '/') && !/akengage_/.test(v1.canon[0]) && v1.comments > 0, JSON.stringify([href, v1.robots, v1.canon]));
  r('B-04', 'La variante del comentario conserva el ancla (el comentario enlazado esta en la pagina)', (await p.$(`${new URL(v1.url).hash || '#x'}`).catch(() => null)) !== null, new URL(v1.url).hash);

  // Paginacion: clic en «Go to page 2»
  await abre(PAGE); const ids1 = await p.$$eval('[id^="akengage-comment-"]', (c) => c.map((x) => x.id).filter((i) => /^akengage-comment-[0-9]+$/.test(i))); const pg = await p.$('.akengage-pagination a[aria-label="Go to page 2"]');
  r('B-05', 'La paginacion de Joomla existe y su enlace a la pagina 2 lleva nofollow', !!pg && await pg.evaluate((a) => a.relList.contains('nofollow')));
  if (pg) {
    await Promise.all([p.waitForNavigation({ waitUntil: 'load' }), pg.click()]); await p.waitForTimeout(600);
    const v2 = await head(); const ids2 = await p.$$eval('[id^="akengage-comment-"]', (c) => c.map((x) => x.id).filter((i) => /^akengage-comment-[0-9]+$/.test(i)));
    r('B-06', 'Clic en la pagina 2: otros comentarios, noindex, follow y canonical a la base (la paginacion sigue funcionando para las personas)', /akengage_limitstart=20/.test(v2.url) && ids2.length > 0 && !ids2.some((i) => ids1.includes(i)) && v2.robots.includes('noindex, follow') && v2.canon.length === 1 && !/akengage_/.test(v2.canon[0]) && v2.canon[0].startsWith(BASE + '/') && /id=1/.test(v2.canon[0]), JSON.stringify([v2.url, v2.robots, v2.canon, ids1.length, ids2.length, ids1.filter((i) => ids2.includes(i)).length]));
  }

  // Orden y favoritos: con el selector activo hay enlaces de orden; clic en «Oldest»
  await abre(PAGE); const so = await p.$('.akengage-sort-link[href*="akengage_sort=oldest"]');
  if (so) { await Promise.all([p.waitForNavigation({ waitUntil: 'load' }), so.click()]); await p.waitForTimeout(600); const v3 = await head(); r('B-07', 'Clic en «Oldest»: la variante lleva noindex, follow y canonical; sus enlaces siguen con nofollow', /akengage_sort=oldest/.test(v3.url) && v3.robots.includes('noindex, follow') && v3.canon.length === 1 && v3.links.every((l) => l.nf), JSON.stringify([v3.robots, v3.canon])); }
  else { r('B-07', 'Existen los enlaces de orden (selector activado)', false, 'sin enlace de orden'); }

  // Boton Copiar enlace (tools.js) sigue funcionando para las personas
  await abre(PAGE); const cp = await p.$('.akengage-copy-btn');
  if (cp && await cp.isVisible()) { await cp.click(); await p.waitForTimeout(500); const txt = await p.evaluate(() => navigator.clipboard.readText().catch(() => '')); r('B-08', 'El boton «Copiar enlace» sigue funcionando: copia la direccion permanente con akengage_cid y ancla', /akengage_cid=\d+#akengage-comment-\d+/.test(txt), txt); }
  else { r('B-08', 'El boton «Copiar enlace» esta visible', false, 'no visible'); }

  // Panel de control: semaforo «Rastreo de robots»
  await abre(BASE + '/administrator/index.php'); await p.fill('#mod-login-username', 'admintest'); await p.fill('#mod-login-password', AP);
  await Promise.all([p.waitForNavigation(), p.click('button.login-button, button[type=submit]')]);
  await abre(BASE + '/administrator/index.php?option=com_engage');
  const txt = (await p.textContent('body')) || '';
  r('B-09', 'El panel de control muestra el punto «Robot crawling» del semaforo con el numero de direcciones del articulo con mas comentarios', /Robot crawling/.test(txt) && /\d+ different addresses|different addresses to robots|alternative addresses/.test(txt), (txt.match(/Robot crawling[\s\S]{0,260}/) || [''])[0].replace(/\s+/g, ' '));
  r('B-10', 'Sin errores de JavaScript en todo el recorrido', errs.length === 0, errs.join(' | '));
  await b.close(); opc('restore'); console.log(`RESUMEN rastreo-navegador: ${ok} PASA / ${ko} FALLA`); process.exit(ko ? 1 : 0);
})();
