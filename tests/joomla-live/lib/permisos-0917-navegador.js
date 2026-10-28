// 0.9.17: «Permisos» abre DIRECTAMENTE la matriz, en Chromium REAL (Super User): clic real desde el panel (boton de la barra y mosaico), desde Comentarios, desde
// Plantillas y desde Ajustes (barra y menu); el enlace «Permisos avanzados (Joomla)» abre la pantalla nativa y esta lleva el enlace de vuelta; con teclado (Intro);
// escritorio claro y movil oscuro; sin errores de JavaScript ni peticiones externas. Capturas solo en OUT_IMG. Variables: WORK, PW_MODULE, CHROMIUM, BASE_URL, OUT_IMG.
const fs = require('fs'); const path = require('path');
const { chromium } = require(process.env.PW_MODULE || 'playwright');
const WORK = process.env.WORK; const BASE = process.env.BASE_URL || 'http://127.0.0.1:8080';
const OUT = process.env.OUT_IMG || path.join(WORK || require('os').tmpdir(), 'capturas'); fs.mkdirSync(OUT, { recursive: true });
const AP = 'Aa1!' + fs.readFileSync(WORK + '/adminpass.txt', 'utf8').trim();
let ok = 0, ko = 0; const r = (id, d, c, ev = '') => { (c ? ok++ : ko++); console.log(`${id.padEnd(12)} ${c ? 'PASA ' : 'FALLA'} ${d}${ev ? '  [' + String(ev).slice(0, 230) + ']' : ''}`); };
const A = BASE + '/administrator/index.php?option=com_engage';
(async () => {
  const b = await chromium.launch({ executablePath: process.env.CHROMIUM || undefined, args: ['--no-sandbox'] });
  try {
    for (const [vp, w, h, esq] of [['escritorio-claro', 1280, 900, 'light'], ['movil-oscuro', 390, 844, 'dark']]) {
      const ctx = await b.newContext({ viewport: { width: w, height: h }, colorScheme: esq }); const p = await ctx.newPage();
      const errs = [], ext = [], navs = []; p.on('pageerror', (e) => errs.push(String(e)));
      p.on('request', (q) => { if (!q.url().startsWith(BASE) && !q.url().startsWith('data:')) { ext.push(q.url()); } if (q.isNavigationRequest() && q.frame() === p.mainFrame()) { navs.push(q.url()); } });
      await p.goto(BASE + '/administrator/index.php'); await p.fill('#mod-login-username', 'admintest'); await p.fill('#mod-login-password', AP);
      await Promise.all([p.waitForNavigation(), p.click('button.login-button, button[type=submit]')]); await p.waitForLoadState('networkidle'); await p.waitForTimeout(1500);
      const enMatriz = async () => (await p.locator('#eg-perm-table').count()) === 1 && /view=permissions/.test(p.url());
      // 1) cada pantalla: el enlace de la barra (en movil la barra de Joomla puede estar plegada: se busca el enlace por su destino)
      const origenes = [['panel', A + '&view=controlpanel'], ['comentarios', A + '&view=comments'], ['plantillas', A + '&view=emailtemplates'], ['ajustes', A + '&view=settings']];
      for (const [nom, url] of origenes) {
        await p.goto(url); await p.waitForLoadState('load');
        let enl = p.locator('a[href*="view=permissions"]:visible').first();
        if ((await enl.count()) === 0) { const tg = p.locator('.js-toolbar-toggle, [data-bs-target*=toolbar], [data-bs-target*=subhead], #toolbar-toggle').locator('visible=true').first(); if (await tg.count()) { await tg.click(); await p.waitForTimeout(600); enl = p.locator('a[href*="view=permissions"]:visible').first(); } }
        const hay = (await enl.count()) === 1; navs.length = 0;
        if (hay) { await Promise.all([p.waitForNavigation(), enl.click()]); await p.waitForLoadState('load'); }
        const saltos = navs.filter((u) => !/view=permissions/.test(u)).length;
        r(`PB-1-${nom}-${vp}`, `${nom}: clic real en «Permisos» y se ve la matriz directamente (sin pantallas intermedias)`, hay && (await enMatriz()) && saltos === 0, `hay=${hay} url=${p.url().slice(-40)} saltos=${saltos}`);
      }
      // 2) mosaico del panel y menu de Ajustes (visibles en el cuerpo de la pagina)
      await p.goto(A + '&view=controlpanel'); await p.waitForLoadState('load');
      await Promise.all([p.waitForNavigation(), p.locator('a.eg-tile[href*="view=permissions"]').click()]); await p.waitForLoadState('load');
      r(`PB-2-${vp}`, 'panel: el mosaico «Permisos» abre la matriz', await enMatriz());
      await p.goto(A + '&view=settings'); await p.waitForLoadState('load');
      await Promise.all([p.waitForNavigation(), p.locator('a.eg-navitem[href*="view=permissions"]').click()]); await p.waitForLoadState('load');
      r(`PB-3-${vp}`, 'Ajustes: la entrada «Permisos» del menu abre la matriz', await enMatriz());
      // 3) teclado: foco en el mosaico y Intro
      await p.goto(A + '&view=controlpanel'); await p.waitForLoadState('load');
      const tile = p.locator('a.eg-tile[href*="view=permissions"]'); await tile.focus(); await Promise.all([p.waitForNavigation(), p.keyboard.press('Enter')]); await p.waitForLoadState('load');
      r(`PB-4-${vp}`, 'con el teclado (foco + Intro) el mosaico abre la matriz', await enMatriz());
      // 4) enlace avanzado: cabecera y leyenda
      const av = p.locator('a[href*="option=com_config"][href*="component=com_engage"]');
      r(`PB-5-${vp}`, 'la matriz muestra «Permisos avanzados (Joomla)» en la cabecera y junto a «Denegado a propósito»', (await av.count()) === 2 && (await p.locator('.eg-perm-legend__item--deny a.eg-perm-advlink').count()) === 1);
      await p.screenshot({ path: path.join(OUT, `permisos-0917-${vp}.png`) });
      await Promise.all([p.waitForNavigation(), av.first().click()]); await p.waitForLoadState('load'); await p.waitForTimeout(800);
      const nat = /option=com_config/.test(p.url()) && (await p.locator('[name="jform[rules]"], #permissions, joomla-tab').count()) > 0;
      r(`PB-6-${vp}`, 'el enlace avanzado abre la pantalla nativa de Joomla del componente', nat, p.url().slice(0, 100));
      const vuelta = p.locator('a[href*="view=permissions"]:visible').first();
      const tabPerm = p.locator('joomla-tab button[aria-controls=permissions], joomla-tab [role=tab][aria-controls=permissions]'); if (await tabPerm.count()) { await tabPerm.first().click().catch(() => {}); }
      await p.screenshot({ path: path.join(OUT, `permisos-0917-nativa-${vp}.png`) });
      const nota = p.locator('#permissions a[href*="view=permissions"]');
      r(`PB-7-${vp}`, 'la pantalla nativa, en su pestana de permisos, tiene el aviso con enlace de vuelta a la matriz', (await nota.count()) >= 1 && await nota.first().isVisible(), `nota=${await nota.count()}`);
      if (await nota.count()) { await Promise.all([p.waitForNavigation(), nota.first().click()]); await p.waitForLoadState('load'); }
      r(`PB-8-${vp}`, 'el enlace de vuelta abre la matriz', await enMatriz());
      r(`PB-9-${vp}`, 'sin errores de JavaScript ni peticiones externas', errs.length === 0 && ext.length === 0, errs.concat(ext).join('|'));
      await ctx.close();
    }
  } catch (e) { r('PB-ERR', 'excepcion en el test: ' + e.message, false); }
  await b.close();
  console.log(`RESUMEN permisos-0917-navegador: ${ok} PASA / ${ko} FALLA`); process.exit(ko ? 1 : 0);
})();
