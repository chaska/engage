// 0.9.8: el panel moderno de Opciones en Chromium: «Campo de usuario con la foto» es una lista cerrada con «Ninguno» y los campos de usuario con foto.
const fs = require('fs');
const { chromium } = require(process.env.PW_MODULE || '/opt/node-tools/node_modules/playwright');
const WORK = process.env.WORK; const BASE = process.env.BASE_URL || 'http://127.0.0.1:8080';
const AP = 'Aa1!' + fs.readFileSync(WORK + '/adminpass.txt', 'utf8').trim();
(async () => {
  const b = await chromium.launch({ executablePath: process.env.CHROMIUM || '/opt/pw-browsers/chromium', args: ['--no-sandbox'] });
  const ctx = await b.newContext({ viewport: { width: 1300, height: 900 } }); await ctx.route(/guidedtours/, (rt) => rt.abort());
  const p = await ctx.newPage(); const errs = []; p.on('pageerror', (e) => errs.push(String(e)));
  await p.goto(BASE + '/administrator/index.php'); await p.fill('#mod-login-username', 'admintest'); await p.fill('#mod-login-password', AP);
  await Promise.all([p.waitForNavigation(), p.click('button.login-button, button[type=submit]')]);
  await p.goto(BASE + '/administrator/index.php?option=com_engage&view=settings'); await p.waitForLoadState('load'); await p.waitForTimeout(800);
  const d = await p.evaluate(() => { const row = document.querySelector('[data-key="customfield_avatar"]'); if (!row) { return null; } const s = row.querySelector('select'); return { opts: s ? Array.from(s.options).map((o) => o.value + '=' + o.textContent.trim()) : [], sel: s ? s.value : null, label: (row.querySelector('.eg-row__label') || {}).textContent }; });
  const ok = !!d && d.opts.length >= 2 && d.opts[0].startsWith('=') && d.opts.some((o) => /Foto de prueba/.test(o)) && errs.length === 0;
  console.log((ok ? 'OK ' : 'FALLA ') + JSON.stringify(d) + ' ' + errs.join('|'));
  await b.close(); process.exit(ok ? 0 : 1);
})();
