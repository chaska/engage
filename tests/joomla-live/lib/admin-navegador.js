// 0.9.3: renderiza en Chromium REAL las pantallas de administracion rediseñadas (Comentarios, Plantillas de correo, Editar comentario), en claro y
// oscuro, escritorio y movil, con capturas en docs/img. Comprueba: ninguna peticion externa (ni Gravatar), sin errores de JavaScript, sin desbordamiento
// horizontal, tema aplicado, contraste >= 4.5 de los textos de la pantalla (.eg-admin), insignias con texto, tarjetas en movil.
// Requiere lib/filtro-datos.php crear. Variables: WORK, PW_MODULE, CHROMIUM, BASE_URL, OUT_IMG.
const fs = require('fs'); const path = require('path');
const { chromium } = require(process.env.PW_MODULE || '/opt/node-tools/node_modules/playwright');
const WORK = process.env.WORK; const BASE = process.env.BASE_URL || 'http://127.0.0.1:8080';
const OUT = process.env.OUT_IMG || path.join(__dirname, '../../../docs/img'); fs.mkdirSync(OUT, { recursive: true });
const AP = 'Aa1!' + fs.readFileSync(WORK + '/adminpass.txt', 'utf8').trim();
let ok = 0, ko = 0; const r = (id, d, c, ev = '') => { (c ? ok++ : ko++); console.log(`${id.padEnd(22)} ${c ? 'PASA ' : 'FALLA'} ${d}${ev ? '  [' + String(ev).slice(0, 230) + ']' : ''}`); };
const lum = (c) => { const v = c.map((x) => { x /= 255; return x <= 0.03928 ? x / 12.92 : ((x + 0.055) / 1.055) ** 2.4; }); return 0.2126 * v[0] + 0.7152 * v[1] + 0.0722 * v[2]; };
const cr = (a, b) => { const la = lum(a), lb = lum(b); return (Math.max(la, lb) + 0.05) / (Math.min(la, lb) + 0.05); };
const pantallas = [
  ['comentarios', '/administrator/index.php?option=com_engage&view=comments&list[limit]=10&filter[search]=&filter[enabled]=&filter[itemtype]=&filter[itemid]=&limitstart=0', '#eg-admin-comments'],
  ['plantillas', '/administrator/index.php?option=com_engage&view=emailtemplates', '#eg-admin-emailtemplates'],
  ['edicion', '/administrator/index.php?option=com_engage&view=comment&task=comment.edit&id=__ID__', '#eg-admin-comment'],
];
(async () => {
  const b = await chromium.launch({ executablePath: process.env.CHROMIUM || '/opt/pw-browsers/chromium', args: ['--no-sandbox'] });
  let idc = '';
  for (const esquema of ['light', 'dark']) {
    for (const [vp, dims, mobile] of [['escritorio', { width: 1366, height: 900 }, false], ['movil', { width: 390, height: 844 }, true]]) {
      const ctx = await b.newContext({ viewport: dims, colorScheme: esquema, isMobile: mobile, deviceScaleFactor: 1 });
      await ctx.route(/guidedtours/, (rt) => rt.abort());
      const p = await ctx.newPage(); const ext = []; const errs = [];
      p.on('request', (q) => { const u = q.url(); if (!u.startsWith(BASE) && !u.startsWith('data:') && !u.startsWith('about:') && !u.startsWith('blob:')) { ext.push(u); } });
      p.on('pageerror', (e) => errs.push(String(e)));
      await p.goto(BASE + '/administrator/index.php'); await p.fill('#mod-login-username', 'admintest'); await p.fill('#mod-login-password', AP);
      await Promise.all([p.waitForNavigation(), p.click('button.login-button, button[type=submit]')]);
      for (const [nombre, url0, sel] of pantallas) {
        let url = url0;
        if (nombre === 'edicion') {
          if (!idc) { await p.goto(BASE + pantallas[0][1] + '&filter[search]=F9N-RANK-1'); await p.waitForTimeout(800); idc = await p.$eval('input[name="cid[]"]', (e) => e.value).catch(() => ''); }
          url = url.replace('__ID__', idc);
        }
        await p.goto(BASE + url); await p.waitForLoadState('load'); await p.waitForTimeout(1200);
        const id = `${nombre}-${esquema}-${vp}`;
        const info = await p.evaluate((sel) => {
          const root = document.querySelector(sel); if (!root) { return { root: false }; }
          const bgOf = (el) => { while (el) { const c = getComputedStyle(el).backgroundColor; if (c && c !== 'rgba(0, 0, 0, 0)' && c !== 'transparent') { return c; } el = el.parentElement; } return 'rgb(255,255,255)'; };
          const rgb = (s) => (s.match(/[\d.]+/g) || []).slice(0, 3).map(Number);
          const items = []; let n = 0;
          root.querySelectorAll('p,span,div,li,a,td,th,strong,label,h2,h3,button').forEach((el) => {
            const own = Array.from(el.childNodes).some((c) => c.nodeType === 3 && c.textContent.trim()); if (!own || el.closest('[aria-hidden="true"]')) { return; }
            const cs = getComputedStyle(el); if (cs.visibility === 'hidden' || cs.display === 'none') { return; }
            const rc = el.getBoundingClientRect(); if (rc.width < 2 || rc.height < 2 || el.closest('.visually-hidden, [hidden], .modal, .js-stools-container-filters:not(.shown)')) { return; }
            n++; items.push({ fg: rgb(cs.color), bg: rgb(bgOf(el)), t: el.textContent.trim().slice(0, 28) });
          });
          const rows = root.querySelectorAll('.eg-table tbody tr'); const tr0 = rows[0] ? getComputedStyle(rows[0]).display : '';
          return { root: true, sw: (() => { const cw = document.documentElement.clientWidth; let w = 0; root.querySelectorAll('*').forEach((el) => { if (el.closest('.js-calendar, .modal, .visually-hidden')) { return; } const th = el.closest('thead'); if (th && th.getBoundingClientRect().width <= 1) { return; } const rc = el.getBoundingClientRect(); if (rc.width > 0) { w = Math.max(w, Math.ceil(rc.right)); if (rc.right > cw + 1) { window.__off = (window.__off || []).concat([el.tagName + '.' + String(el.className).slice(0,40)]).slice(0,4); } } }); return Math.max(w, 0); })(), cw: document.documentElement.clientWidth, rootBg: bgOf(root), items, n, tr0, badges: Array.from(root.querySelectorAll('.eg-badge')).map((x) => x.textContent.trim()), imgs: root.querySelectorAll('img').length, off: window.__off || [], sty: Array.from(root.querySelectorAll('[style]')).filter((el) => !el.closest('.js-calendar, .js-stools, .tox, joomla-editor-tinymce, joomla-field-fancy-select, .choices, joomla-field-media, .mce-tinymce, .mce_editable')).map((e)=>e.tagName+'.'+String(e.className).slice(0,30)+'|'+(e.parentElement&&e.parentElement.tagName)).slice(0,3), inlineStyles: Array.from(root.querySelectorAll('[style]')).filter((el) => !el.closest('.js-calendar, .js-stools, .tox, joomla-editor-tinymce, joomla-field-fancy-select, .choices, joomla-field-media, .mce-tinymce, .mce_editable')).length, theme: getComputedStyle(root).colorScheme };
        }, sel);
        if (!info.root) { r(id, `${nombre}: la pantalla (.eg-admin) existe`, false); continue; }
        const low = info.items.map((i) => ({ c: cr(i.fg, i.bg), t: i.t })).sort((a, b) => a.c - b.c)[0];
        r(id, `${nombre} ${esquema} ${vp}: sin peticiones externas, sin errores JS, sin desbordamiento (dentro de .eg-admin), sin imagenes ni estilos en linea propios, contraste >= 4.5`, ext.length === 0 && errs.length === 0 && info.sw <= info.cw && info.imgs === 0 && info.inlineStyles === 0 && low.c >= 4.5, `ext=${ext.length} errs=${errs.length} sw=${info.sw}/${info.cw} img=${info.imgs} style=${info.inlineStyles} off=${info.off.join(',')} sty=${info.sty.join(',')} minContraste=${low.c.toFixed(2)} (${low.t})`);
        const [r1, g1, b1] = info.rootBg.match(/[\d.]+/g).map(Number);
        r(id + '-tema', `${nombre}: tema ${esquema === 'dark' ? 'oscuro' : 'claro'} aplicado (fondo ${esquema === 'dark' ? 'oscuro' : 'claro'})`, esquema === 'dark' ? lum([r1, g1, b1]) < 0.12 : lum([r1, g1, b1]) > 0.6, info.rootBg);
        if (nombre === 'comentarios') { r(id + '-forma', `comentarios ${vp}: ${mobile ? 'cada fila es una tarjeta (display:block)' : 'tabla compacta'}; insignias de estado con texto`, (mobile ? info.tr0 === 'block' : info.tr0 === 'table-row') && info.badges.length > 0 && info.badges.every((t) => t.length > 1), `tr=${info.tr0} badges=${info.badges.slice(0, 3).join('|')}`); }
        await p.evaluate(() => { document.querySelectorAll('.js-pstats, #system-message-container, joomla-alert').forEach((x) => x.remove()); const r = document.querySelector('.eg-admin'); if (r) { window.scrollTo(0, 0); r.scrollIntoView({ block: 'start' }); } }); await p.screenshot({ path: path.join(OUT, `admin-${nombre}-${esquema === 'light' ? 'claro' : 'oscuro'}-${vp}.png`), fullPage: false });
      }
      await ctx.close();
    }
  }
  await b.close();
  console.log(`RESUMEN admin-navegador: ${ok} PASA / ${ko} FALLA`);
  process.exit(ko ? 1 : 0);
})().catch((e) => { console.error(e); process.exit(2); });
