// 0.9.11: la pantalla REAL de Joomla (Sistema > Actualizar > Extensiones) en Chromium: ofrece Engage y lo actualiza (descarga + comprobacion de la huella + instalacion).
// Uso: node actualizar-navegador.js ofrece|actualiza   Salida: una linea JSON.
const fs = require('fs');
const { chromium } = require(process.env.PW_MODULE || 'playwright');
const B = process.env.BASE_URL || 'http://127.0.0.1:8080'; const AP = 'Aa1!' + fs.readFileSync(process.env.WORK + '/adminpass.txt', 'utf8').trim();
(async () => {
  const b = await chromium.launch({ executablePath: process.env.CHROMIUM || undefined, args: ['--no-sandbox'] });
  const ctx = await b.newContext({ viewport: { width: 1300, height: 900 } }); await ctx.route(/guidedtours/, (r) => r.abort()); const p = await ctx.newPage();
  await p.goto(B + '/administrator/index.php'); await p.fill('#mod-login-username', 'admintest'); await p.fill('#mod-login-password', AP);
  await Promise.all([p.waitForNavigation(), p.click('button.login-button, button[type=submit]')]);
  await p.goto(B + '/administrator/index.php?option=com_installer&view=update'); await p.waitForLoadState('load'); await p.waitForTimeout(1000);
  const filas = await p.$$eval('table tbody tr', (t) => t.map((x) => x.textContent.replace(/\s+/g, ' ').trim()).filter((x) => /Engage/.test(x)));
  const out = { filas: filas.length, texto: filas[0] ? filas[0].slice(0, 200) : '', msgs: [] };
  if ((process.argv[2] || '') === 'actualiza' && filas.length) {
    await (await p.$('input[name="cid[]"]')).check(); const btn = await p.$('#toolbar-upload button, joomla-toolbar-button[task="update.update"] button');
    await Promise.all([p.waitForNavigation({ timeout: 180000 }).catch(() => null), btn.click()]); await p.waitForTimeout(3500);
    out.msgs = await p.$$eval('joomla-alert, .alert', (a) => a.map((x) => x.textContent.replace(/\s+/g, ' ').trim().slice(0, 220)).filter((x) => !/Help us make Joomla/.test(x)));
  }
  console.log(JSON.stringify(out)); await b.close();
})();
