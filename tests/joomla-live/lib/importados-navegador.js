// 0.9.10: comentarios importados (sin usuario, sin correo, ip «0»…) en Chromium REAL, como moderador y como invitado, claro/oscuro, escritorio/movil: todas las tarjetas llevan
// el avatar generico local con el mismo tamano y alineacion que una normal; ninguna muestra «IP 0»; la IP valida de la normal si (solo a quien modera); sin peticiones
// externas; microdatos con image local; administracion con el valor tal cual. Requiere lib/importados-datos.php crear.
const fs = require('fs'); const path = require('path');
const { chromium } = require(process.env.PW_MODULE || 'playwright');
const WORK = process.env.WORK; const BASE = process.env.BASE_URL || 'http://127.0.0.1:8080';
const AP = 'Aa1!' + fs.readFileSync(WORK + '/adminpass.txt', 'utf8').trim(); const OUT = (process.env.OUT_IMG || path.join(process.env.WORK || require('os').tmpdir(), 'capturas')); fs.mkdirSync(OUT, { recursive: true });
let ok = 0, ko = 0; const r = (id, d, c, ev = '') => { (c ? ok++ : ko++); console.log(`${id.padEnd(8)} ${c ? 'PASA ' : 'FALLA'} ${d}${ev ? '  [' + String(ev).slice(0, 260) + ']' : ''}`); };
const PAGE = BASE + '/index.php?option=com_content&view=article&id=1&catid=8';
const medir = (p) => p.evaluate(() => {
  const out = {};
  for (const n of ['F9I-IMP-1', 'F9I-IMP-2', 'F9I-IMP-3', 'F9I-IMP-4', 'F9I-NORMAL']) {
    const t = Array.from(document.querySelectorAll('article[id^="akengage-comment-"]')).find((a) => a.textContent.includes('F9I texto ' + n));
    if (!t) { out[n] = null; continue; }
    const img = t.querySelector('img.akengage-commenter-avatar'); const cs = img ? getComputedStyle(img) : null; const rc = img ? img.getBoundingClientRect() : null;
    const ip = t.querySelector('.akengage-comment-ip');
    out[n] = { img: !!img, src: img ? img.getAttribute('src') : '', defer: img ? img.hasAttribute('data-engage-gravatar') : false, shown: !!img && cs.display !== 'none' && rc.width > 0, w: rc ? Math.round(rc.width) : 0, h: rc ? Math.round(rc.height) : 0, left: rc ? Math.round(rc.left) : 0, loaded: !!img && (img.naturalWidth > 0 || img.complete), ip: ip ? ip.textContent.replace(/\s+/g, ' ').trim() : null, itemImage: !!t.querySelector('[itemprop=image]'), card: t.textContent.replace(/\s+/g, ' ').slice(0, 400) };
  }
  return out;
});
(async () => {
  const b = await chromium.launch({ executablePath: process.env.CHROMIUM || undefined, args: ['--no-sandbox'] });
  const ext = [];
  for (const [vp, w, h, esq] of [['escritorio-claro', 1280, 900, 'light'], ['movil-oscuro', 390, 844, 'dark'], ['escritorio-oscuro', 1280, 900, 'dark']]) {
    for (const quien of ['moderador', 'invitado']) {
      const ctx = await b.newContext({ viewport: { width: w, height: h }, colorScheme: esq }); await ctx.route(/guidedtours/, (rt) => rt.abort());
      const p = await ctx.newPage(); const errs = []; p.on('pageerror', (e) => errs.push(String(e))); p.on('request', (q) => { if (!q.url().startsWith(BASE) && !q.url().startsWith('data:')) { ext.push(q.url()); } });
      if (quien === 'moderador') { await p.goto(BASE + '/index.php?option=com_users&view=login'); await p.fill('#username', 'admintest'); await p.fill('#password', AP); await Promise.all([p.waitForNavigation(), p.click('button[type=submit].btn-primary, form#com-users-login__form button[type=submit]')]); }
      await p.goto(PAGE); await p.waitForLoadState('load'); await p.waitForTimeout(900);
      const m = await medir(p); const imp = ['F9I-IMP-1', 'F9I-IMP-2', 'F9I-IMP-3', 'F9I-IMP-4']; const nor = m['F9I-NORMAL'];
      const todos = !!nor && imp.every((k) => m[k]);
      const avatares = todos && imp.every((k) => m[k].img && /\/media\/com_engage\/images\/avatar-generico\.svg$/.test(m[k].src) && !m[k].defer && m[k].shown === nor.shown && m[k].w === nor.w && m[k].h === nor.h && m[k].left === nor.left && m[k].loaded);
      r(`I-${vp}-${quien}`.slice(0, 40), `${vp} ${quien}: las 4 tarjetas importadas llevan el avatar generico local (sin referencia a Gravatar), con el mismo tamano, posicion y visibilidad que la normal (${nor ? nor.w + 'x' + nor.h + ' en x=' + nor.left + ', ' + (nor.shown ? 'visible' : 'oculto por el tema') : '?'})`, avatares, JSON.stringify(todos ? imp.map((k) => [m[k].src.slice(-22), m[k].shown, m[k].w, m[k].left]) : m));
      const ips = todos ? imp.map((k) => m[k].ip) : ['?'];
      r(`J-${vp}-${quien}`.slice(0, 40), `${vp} ${quien}: ninguna tarjeta importada muestra la linea de la IP (ni «IP 0»)` + (quien === 'moderador' ? '; la normal sí enseña 203.0.113.9 al moderador' : '; el invitado no ve ninguna IP'), todos && ips.every((x) => x === null) && !imp.some((k) => /IP\s*0|0\.0\.0\.0|basura/.test(m[k].card)) && (quien === 'moderador' ? !!nor.ip && nor.ip.includes('203.0.113.9') : nor.ip === null), JSON.stringify([ips, nor && nor.ip]));
      r(`K-${vp}-${quien}`.slice(0, 40), `${vp} ${quien}: microdatos con image local en todas, sin errores de JavaScript`, todos && imp.every((k) => m[k].itemImage) && errs.length === 0, errs.join('|'));
      if (quien === 'moderador') { const el = await p.$('article[id^="akengage-comment-"]'); if (el) { await p.evaluate(() => { document.querySelectorAll('joomla-alert, #system-message-container').forEach((x) => x.remove()); const a = Array.from(document.querySelectorAll('article')).find((x) => x.textContent.includes('F9I texto F9I-IMP-1')); if (a) { a.scrollIntoView({ block: 'start' }); window.scrollBy(0, -20); } }); await p.waitForTimeout(300); await p.screenshot({ path: path.join(OUT, `importados-${vp}.png`) }); } }
      await ctx.close();
    }
  }
  r('I-ext', 'sin ninguna peticion a otros dominios en todo el recorrido', ext.length === 0, ext.slice(0, 3).join(' '));
  // administracion: el valor tal cual
  const ctx = await b.newContext({ viewport: { width: 1400, height: 1000 } }); await ctx.route(/guidedtours/, (rt) => rt.abort()); const p = await ctx.newPage();
  await p.goto(BASE + '/administrator/index.php'); await p.fill('#mod-login-username', 'admintest'); await p.fill('#mod-login-password', AP); await Promise.all([p.waitForNavigation(), p.click('button.login-button, button[type=submit]')]);
  await p.goto(BASE + '/administrator/index.php?option=com_engage&view=comments&filter[search]=F9I-IMP-1&filter[enabled]=&filter[itemtype]=&filter[itemid]=&list[limit]=20'); await p.waitForLoadState('load'); await p.waitForTimeout(800);
  const adm = await p.evaluate(() => { const row = Array.from(document.querySelectorAll('tr')).find((t) => t.textContent.includes('F9I-IMP-1')); return row ? Array.from(row.querySelectorAll('.font-monospace')).map((x) => x.textContent.trim()) : null; });
  r('A-admin', 'la administracion sigue mostrando el valor de la IP tal cual a quien modera («0»)', !!adm && adm.includes('0'), JSON.stringify(adm));
  await b.close(); console.log(`RESUMEN importados-navegador: ${ok} PASA / ${ko} FALLA`); process.exit(ko ? 1 : 0);
})();
