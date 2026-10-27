// 0.9.14: denunciar comentarios en Chromium REAL. Invitado (sin boton); registrado: boton, formulario con teclado, envio y mensaje; claro/oscuro y movil 390
// sin desbordamiento; cola de administracion (Manager) con filtros y acciones por la interfaz. Requiere denuncias-datos.php crear.
const fs = require('fs'); const path = require('path');
const { chromium } = require(process.env.PW_MODULE || 'playwright');
const WORK = process.env.WORK; const BASE = process.env.BASE_URL || 'http://127.0.0.1:8080'; const PAGE = BASE + '/index.php?option=com_content&view=article&id=1&catid=8';
const PASS = fs.readFileSync(WORK + '/rev082pass.txt', 'utf8').trim(); const OUT = (process.env.OUT_IMG || path.join(process.env.WORK || require('os').tmpdir(), 'capturas')); fs.mkdirSync(OUT, { recursive: true });
let ok = 0, ko = 0; const r = (id, d, c, ev = '') => { (c ? ok++ : ko++); console.log(`${id.padEnd(8)} ${c ? 'PASA ' : 'FALLA'} ${d}${ev ? '  [' + String(ev).slice(0, 240) + ']' : ''}`); };
const loginSitio = async (p, u) => { await p.goto(BASE + '/index.php?option=com_users&view=login'); await p.fill('#username', u); await p.fill('#password', PASS); await Promise.all([p.waitForNavigation(), p.click('button[type=submit].btn-primary, form#com-users-login__form button[type=submit]')]); };
const loginAdmin = async (p, u) => { await p.goto(BASE + '/administrator/index.php'); await p.fill('#mod-login-username', u); await p.fill('#mod-login-password', PASS); await Promise.all([p.waitForNavigation(), p.click('button.login-button, button[type=submit]')]); await p.waitForLoadState('networkidle'); await p.waitForTimeout(1500); };
(async () => {
  const b = await chromium.launch({ executablePath: process.env.CHROMIUM || undefined, args: ['--no-sandbox'] });
  try {
    // invitado
    let ctx = await b.newContext({ viewport: { width: 1280, height: 900 } }); let p = await ctx.newPage();
    await p.goto(PAGE); await p.waitForLoadState('load');
    r('DNB-1', 'invitado: ningun boton ni formulario de denuncia', (await p.locator('.akengage-report').count()) === 0);
    await ctx.close();
    // registrado: escritorio claro, movil oscuro
    for (const [vp, w, h, esq] of [['escritorio-claro', 1280, 900, 'light'], ['movil-oscuro', 390, 844, 'dark']]) {
      ctx = await b.newContext({ viewport: { width: w, height: h }, colorScheme: esq }); p = await ctx.newPage(); const errs = []; p.on('pageerror', (e) => errs.push(String(e)));
      await loginSitio(p, 'r3rep'); await p.goto(PAGE); await p.waitForLoadState('load');
      const botones = await p.locator('.akengage-report > summary').count();
      r(`DNB-2-${vp}`, 'registrado: el boton «Denunciar» sale en los comentarios denunciables (los ya denunciados no)', botones >= 2, `botones=${botones}`);
      const sum = p.locator('.akengage-report > summary').first();
      await sum.focus(); await p.keyboard.press('Enter'); await p.waitForTimeout(200);
      const abierto = await p.locator('.akengage-report[open]').count();
      const caja = await p.locator('.akengage-report[open] .akengage-report-form').first().boundingBox();
      const desborda = await p.evaluate(() => document.documentElement.scrollWidth > window.innerWidth);
      r(`DNB-3-${vp}`, 'con el teclado (Enter) se abre el formulario, cabe en la pantalla y no hay desbordamiento horizontal', abierto === 1 && !!caja && caja.x >= 0 && caja.x + caja.width <= w + 1 && !desborda, JSON.stringify(caja));
      if (vp === 'escritorio-claro') {
        await p.locator('.akengage-report[open] select[name=reason]').selectOption('2');
        await p.locator('.akengage-report[open] input[name=note]').fill('texto <b>de prueba</b>');
        await Promise.all([p.waitForNavigation(), p.locator('.akengage-report[open] .akengage-report-send').click()]);
        await p.waitForLoadState('load');
        const msg = await p.locator('#system-message-container, .alert-message, joomla-alert').allTextContents();
        r('DNB-4', 'al enviar: mensaje de agradecimiento y la denuncia queda guardada', /Gracias|Thank you/.test(msg.join(' ')) && /Su denuncia|Your report/.test(msg.join(' ')), msg.join(' ').replace(/\s+/g, ' ').slice(0, 120));
        const botones2 = await p.locator('.akengage-report > summary').count();
        r('DNB-5', 'el comentario ya denunciado deja de ofrecer el boton', botones2 === botones - 1, `${botones} -> ${botones2}`);
        await p.screenshot({ path: path.join(OUT, 'denuncias-comentarios.png'), fullPage: false });
      }
      r(`DNB-6-${vp}`, 'sin errores de JavaScript', errs.length === 0, errs.join('|'));
      await ctx.close();
    }
    // cola de administracion
    for (const [vp, w, h, esq] of [['escritorio-claro', 1280, 900, 'light'], ['movil-oscuro', 390, 844, 'dark']]) {
      ctx = await b.newContext({ viewport: { width: w, height: h }, colorScheme: esq }); p = await ctx.newPage();
      await loginAdmin(p, 'r3mgr'); await p.goto(BASE + '/administrator/index.php?option=com_engage&view=reports&status=all'); await p.waitForLoadState('load');
      const filas = await p.locator('#eg-admin-reports tbody tr').count();
      const desborda = await p.evaluate(() => { const e = document.getElementById('eg-admin-reports'); const cw = document.documentElement.clientWidth; let w = 0; e.querySelectorAll('*').forEach((el) => { if (el.closest('.visually-hidden, .eg-vh, thead')) { return; } const rc = el.getBoundingClientRect(); if (rc.width > 0) { w = Math.max(w, Math.ceil(rc.right)); } }); return w > cw; });
      r(`DNB-7-${vp}`, 'cola de denuncias: filas, denunciante visible al Manager y sin desbordamiento', filas >= 1 && (await p.locator('#eg-admin-reports').innerText()).includes('r3rep') && !desborda, `filas=${filas} desborda=${desborda} sw=${await p.evaluate(() => document.documentElement.scrollWidth)} contiene=${(await p.locator('#eg-admin-reports').innerText()).includes('r3rep')}`);
      if (vp === 'escritorio-claro') {
        await p.screenshot({ path: path.join(OUT, 'denuncias-cola.png') });
        await p.selectOption('#eg-rp-reason', '2'); await Promise.all([p.waitForNavigation(), p.click('.eg-filters button[type=submit]')]);
        r('DNB-8', 'el filtro por motivo deja solo las denuncias de ese motivo', (await p.locator('#eg-admin-reports tbody tr').count()) === 1);
        await Promise.all([p.waitForNavigation(), p.locator('#eg-admin-reports form[action*="reports.dismiss"] button').first().click()]);
        await p.waitForLoadState('load');
        r('DNB-9', 'descartar desde la interfaz: mensaje y la denuncia sale de «abiertas»', (await p.locator('#eg-admin-reports tbody tr').count()) === 0);
      }
      await ctx.close();
    }
  } finally { await b.close(); }
  console.log(`RESUMEN denuncias-navegador: ${ok} PASA / ${ko} FALLA`); process.exit(ko ? 1 : 0);
})();
