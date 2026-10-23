// 0.9.7: la tarjeta «Acerca de Engage» del panel de control en Joomla real: muestra «Fork iniciado y dirigido por ChasKa» detras del autor original, sin desbordar en movil, en claro y oscuro; captura en docs/img.
const fs = require('fs'); const path = require('path');
const { chromium } = require(process.env.PW_MODULE || '/opt/node-tools/node_modules/playwright');
const WORK = process.env.WORK; const BASE = process.env.BASE_URL || 'http://127.0.0.1:8080';
const AP = 'Aa1!' + fs.readFileSync(WORK + '/adminpass.txt', 'utf8').trim(); const OUT = path.join(__dirname, '../../../docs/img');
let ok = 0, ko = 0; const r = (id, d, c, ev = '') => { (c ? ok++ : ko++); console.log(`${id.padEnd(6)} ${c ? 'PASA ' : 'FALLA'} ${d}${ev ? '  [' + String(ev).slice(0, 200) + ']' : ''}`); };
(async () => {
  const b = await chromium.launch({ executablePath: process.env.CHROMIUM || '/opt/pw-browsers/chromium', args: ['--no-sandbox'] });
  for (const [vp, w, h, esq] of [['escritorio', 1280, 900, 'light'], ['movil-oscuro', 390, 844, 'dark']]) {
    const ctx = await b.newContext({ viewport: { width: w, height: h }, colorScheme: esq }); await ctx.route(/guidedtours/, (rt) => rt.abort());
    const p = await ctx.newPage(); const errs = []; p.on('pageerror', (e) => errs.push(String(e)));
    await p.goto(BASE + '/administrator/index.php'); await p.fill('#mod-login-username', 'admintest'); await p.fill('#mod-login-password', AP);
    await Promise.all([p.waitForNavigation(), p.click('button.login-button, button[type=submit]')]);
    await p.goto(BASE + '/administrator/index.php?option=com_engage'); await p.waitForLoadState('load'); await p.waitForTimeout(800);
    const d = await p.evaluate(() => { const c = document.querySelector('#eg-about'); if (!c) { return null; } const f = Array.from(c.querySelectorAll('.eg-about__facts > div')).map((x) => [x.querySelector('dt').textContent.trim(), x.querySelector('dd').textContent.trim(), Math.round(x.getBoundingClientRect().right)]); return { f, note: c.querySelector('.eg-about__note').textContent, sw: document.documentElement.scrollWidth, cw: document.documentElement.clientWidth, cardR: Math.round(c.getBoundingClientRect().right), links: c.querySelectorAll('.eg-about__facts a').length }; });
    const auth = d && d.f.findIndex((x) => /Original author|Autor original/.test(x[0])); const fork = d && d.f.findIndex((x) => /Fork started and led by|Fork iniciado y dirigido por/.test(x[0]));
    r('P-' + vp, `${vp} ${esq}: «Fork started and led by» = ChasKa tras el autor original; sin enlaces; el aviso lo dice; sin desbordamiento`, !!d && fork === auth + 1 && d.f[fork][1] === 'ChasKa' && d.f[auth][1].includes('Akeeba') && d.links === 0 && /Ideas, testing and direction of the project: ChasKa\./.test(d.note) && d.f.every((x) => x[2] <= d.cardR + 1) && d.sw <= d.cw + 1 && errs.length === 0, JSON.stringify(d && [d.links, d.note.slice(-60), d.cardR, d.f.map((x) => x[2]), errs]));
    await p.evaluate(() => { document.querySelectorAll('joomla-alert, #system-message-container').forEach((x) => x.remove()); document.querySelector('#eg-about').scrollIntoView({ block: 'center' }); }); await p.waitForTimeout(400);
    await (await p.$('#eg-about')).screenshot({ path: path.join(OUT, vp === 'escritorio' ? 'panel-acerca-de.png' : 'panel-acerca-de-movil-oscuro.png') });
    await ctx.close();
  }
  await b.close(); console.log(`RESUMEN panel-acerca: ${ok} PASA / ${ko} FALLA`); process.exit(ko ? 1 : 0);
})();
