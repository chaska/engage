// 0.9.11: aviso del panel de control «Plugins de fotos visibles para invitados» en Chromium REAL: verde con todo Publico; ambar al poner el plugin gravatar (grupo engage)
// en Registered, con su nombre, el nivel y el arreglo; email/akismet fuera de Publico no avisan; vuelve a verde al restaurar. Restaura el acceso al acabar.
const fs = require('fs'); const { execFileSync } = require('child_process');
const { chromium } = require(process.env.PW_MODULE || 'playwright');
const WORK = process.env.WORK; const BASE = process.env.BASE_URL || 'http://127.0.0.1:8080'; const AP = 'Aa1!' + fs.readFileSync(WORK + '/adminpass.txt', 'utf8').trim();
const opc = (...a) => execFileSync('php', [__dirname + '/avatar-opciones.php', ...a], { env: process.env });
let ok = 0, ko = 0; const r = (id, d, c, ev = '') => { (c ? ok++ : ko++); console.log(`${id.padEnd(8)} ${c ? 'PASA ' : 'FALLA'} ${d}${ev ? '  [' + String(ev).slice(0, 300) + ']' : ''}`); };
(async () => {
  const b = await chromium.launch({ executablePath: process.env.CHROMIUM || undefined, args: ['--no-sandbox'] });
  try {
    const ctx = await b.newContext({ viewport: { width: 1280, height: 900 } }); const p = await ctx.newPage(); const ext = [];
    p.on('request', (q) => { if (!q.url().startsWith(BASE)) ext.push(q.url()); });
    await p.goto(BASE + '/administrator/index.php'); await p.fill('#mod-login-username', 'admintest'); await p.fill('#mod-login-password', AP); await Promise.all([p.waitForNavigation(), p.click('button.login-button, button[type=submit]')]); await p.waitForLoadState('networkidle'); await p.waitForTimeout(1500);
    const leer = async () => { for (let i = 0; i < 3; i++) { try { await p.goto(BASE + '/administrator/index.php?option=com_engage&view=controlpanel'); break; } catch (e) { await p.waitForTimeout(800); } } await p.waitForLoadState('load'); return p.evaluate(() => { const t = document.querySelector('#eg-health'); const li = Array.from(t.querySelectorAll('li, .eg-health-item, article')).find((x) => /foto|photo/i.test(x.textContent) && /acceso|access/i.test(x.textContent)); return { txt: li ? li.textContent.replace(/\s+/g, ' ').trim() : '', cls: li ? li.className : '', all: t.textContent.replace(/\s+/g, ' ').length, href: li ? Array.from(li.querySelectorAll('a')).map((a) => a.getAttribute('href')) : [] }; }); };
    let m = await leer(); r('PA-1', 'todo Publico: elemento presente y en verde (sin enlace de arreglo)', m.txt && /ok|success/.test(m.cls + m.txt.toLowerCase()) && !m.href.length, JSON.stringify(m));
    opc('access', 'email', 'engage', '3'); opc('access', 'akismet', 'engage', '2'); m = await leer(); r('PA-2', 'email y akismet fuera de Publico: sigue sin avisar', /ok/.test(m.cls) && !/Special|Registered/.test(m.txt), JSON.stringify(m));
    opc('access', 'gravatar', 'engage', '2'); m = await leer(); r('PA-3', 'gravatar en Registered: aviso ambar con el plugin, el nivel y el arreglo', /warn/.test(m.cls) && /Registered|Registrado/.test(m.txt) && /Gravatar/i.test(m.txt) && /Public|Pública|Publico|Público/.test(m.txt), JSON.stringify(m));
    r('PA-4', 'enlace de arreglo al plugin (com_plugins)', m.href.some((h) => /com_plugins/.test(h)), JSON.stringify(m.href));
    opc('access', 'gravatar', 'engage', '1'); opc('access', 'email', 'engage', '1'); opc('access', 'akismet', 'engage', '1'); m = await leer(); r('PA-5', 'al restaurar vuelve a verde', !/warn/.test(m.cls) && !/Registered/.test(m.txt), JSON.stringify(m));
    r('PA-6', 'cero peticiones a otros hosts', ext.length === 0, ext.join(','));
    await ctx.close();
  } finally { opc('restore'); await b.close(); }
  console.log(`RESUMEN panel-acceso: ${ok} PASA / ${ko} FALLA`); process.exit(ko ? 1 : 0);
})();
