// 0.9.16: matriz de permisos en Chromium REAL (Super User): escritorio claro y movil oscuro. Teclado (foco, Enter/Espacio), boton de guardar, marca de pendiente,
// aria-label / aria-pressed, confirmacion al conceder a un grupo amplio (Escape/Cancelar no guarda; Confirmar guarda), mensaje de estado, desplazamiento horizontal
// de la tabla sin desbordar la pagina, un solo icono visible por estado, sin errores de JavaScript ni peticiones externas. Capturas solo en OUT_IMG.
// Requiere lib/permisos-datos.php crear (y borrar al acabar). Variables: WORK, PW_MODULE, CHROMIUM, BASE_URL, OUT_IMG.
const fs = require('fs'); const path = require('path');
const { chromium } = require(process.env.PW_MODULE || 'playwright');
const WORK = process.env.WORK; const BASE = process.env.BASE_URL || 'http://127.0.0.1:8080';
const OUT = process.env.OUT_IMG || path.join(WORK || require('os').tmpdir(), 'capturas'); fs.mkdirSync(OUT, { recursive: true });
const AP = 'Aa1!' + fs.readFileSync(WORK + '/adminpass.txt', 'utf8').trim();
let ok = 0, ko = 0; const r = (id, d, c, ev = '') => { (c ? ok++ : ko++); console.log(`${id.padEnd(10)} ${c ? 'PASA ' : 'FALLA'} ${d}${ev ? '  [' + String(ev).slice(0, 230) + ']' : ''}`); };
const VIEW = BASE + '/administrator/index.php?option=com_engage&view=permissions';
(async () => {
  const b = await chromium.launch({ executablePath: process.env.CHROMIUM || undefined, args: ['--no-sandbox'] });
  try {
    for (const [vp, w, h, esq] of [['escritorio-claro', 1280, 900, 'light'], ['movil-oscuro', 390, 844, 'dark']]) {
      const ctx = await b.newContext({ viewport: { width: w, height: h }, colorScheme: esq }); const p = await ctx.newPage();
      const errs = [], ext = []; p.on('pageerror', (e) => errs.push(String(e)));
      p.on('request', (q) => { if (!q.url().startsWith(BASE) && !q.url().startsWith('data:')) { ext.push(q.url()); } });
      await p.goto(BASE + '/administrator/index.php'); await p.fill('#mod-login-username', 'admintest'); await p.fill('#mod-login-password', AP);
      await Promise.all([p.waitForNavigation(), p.click('button.login-button, button[type=submit]')]); await p.waitForLoadState('networkidle'); await p.waitForTimeout(1500);
      await p.goto(VIEW); await p.waitForLoadState('networkidle');
      const T = (s) => p.locator(s);
      r(`PN-1-${vp}`, 'la matriz sale con filas por grupo y seis columnas de acciones', (await T('#eg-perm-table tbody tr').count()) >= 9 && (await T('#eg-perm-table thead th[scope=col]').count()) >= 6);
      const cel = T('button.eg-perm-cell[data-g="2"][data-a="core.edit.own"]'); const guardar = T('#eg-perm-save');
      r(`PN-2-${vp}`, 'guardar empieza desactivado y la celda parte de «sin asignar» con aria-pressed=false', (await guardar.isDisabled()) && (await cel.getAttribute('data-state')) === 'unset' && (await cel.getAttribute('aria-pressed')) === 'false');
      const visibles = await cel.evaluate((el) => Array.from(el.querySelectorAll('svg')).filter((s) => getComputedStyle(s).display !== 'none').length);
      r(`PN-3-${vp}`, 'cada celda muestra UN solo icono', visibles === 1, `visibles=${visibles}`);
      await cel.focus(); const foco = await cel.evaluate((el) => el === document.activeElement && getComputedStyle(el).outlineStyle !== 'none');
      await p.keyboard.press('Enter');
      r(`PN-4-${vp}`, 'foco visible y Enter la marca: allow, aria-pressed=true, pendiente, guardar activo', foco && (await cel.getAttribute('data-state')) === 'allow' && (await cel.getAttribute('aria-pressed')) === 'true' && (await cel.getAttribute('data-pending')) !== null && !(await guardar.isDisabled()), `foco=${foco}`);
      const lab = (await cel.getAttribute('aria-label')) || '';
      r(`PN-5-${vp}`, 'aria-label con grupo, accion y estado', /Registrad|Registered/.test(lab) && lab.split(':').length >= 3, lab);
      await p.keyboard.press('Space');
      r(`PN-6-${vp}`, 'Espacio la desmarca y guardar vuelve a desactivarse', (await cel.getAttribute('data-state')) === 'unset' && (await guardar.isDisabled()));
      await p.keyboard.press('Enter');
      const desb = await p.evaluate(() => { const x = document.querySelector('.eg-perm-wrap'); return { pag: document.documentElement.scrollWidth > window.innerWidth + 1, tab: x ? x.scrollWidth > x.clientWidth : null }; });
      r(`PN-7-${vp}`, w < 600 ? 'en movil la tabla se desplaza dentro de su contenedor y la pagina no desborda' : 'en escritorio la pagina no desborda', !desb.pag && (w < 600 ? desb.tab === true : true), JSON.stringify(desb));
      await p.screenshot({ path: path.join(OUT, `permisos-${vp}.png`), fullPage: false });
      await Promise.all([p.waitForResponse((x) => x.url().includes('permissions.save')), guardar.click()]); await p.waitForTimeout(300);
      r(`PN-8-${vp}`, 'guardar: mensaje de estado y la celda queda como «permitido» sin pendiente', (await T('#eg-perm-status').innerText()).trim().length > 0 && (await cel.getAttribute('data-state')) === 'allow' && (await cel.getAttribute('data-pending')) === null && (await guardar.isDisabled()));
      // recarga: persiste
      await p.reload(); await p.waitForLoadState('networkidle');
      r(`PN-9-${vp}`, 'tras recargar la celda sigue permitida (lo guardo Joomla)', (await T('button.eg-perm-cell[data-g="2"][data-a="core.edit.own"]').getAttribute('data-state')) === 'allow');
      // grupo amplio + accion sensible: confirmacion
      const del = T('button.eg-perm-cell[data-g="2"][data-a="core.delete"]'); await del.focus(); await p.keyboard.press('Enter'); await guardar.click();
      const dlg = T('#eg-perm-confirm'); const abierto = await dlg.evaluate((d) => d.open);
      r(`PN-10-${vp}`, 'conceder «Borrar» a Registrado pide confirmacion en un dialogo', abierto && (await T('#eg-perm-confirm-list li').count()) === 1);
      await p.screenshot({ path: path.join(OUT, `permisos-confirmar-${vp}.png`), fullPage: false });
      await p.keyboard.press('Escape'); await p.waitForTimeout(200);
      r(`PN-11-${vp}`, 'Escape cancela: nada se guarda y el cambio sigue pendiente', !(await dlg.evaluate((d) => d.open)) && (await del.getAttribute('data-pending')) !== null);
      await guardar.click(); await Promise.all([p.waitForResponse((x) => x.url().includes('permissions.save')), T('#eg-perm-confirm-yes').click()]); await p.waitForTimeout(300);
      r(`PN-12-${vp}`, 'Confirmar guarda y la celda queda permitida', (await del.getAttribute('data-state')) === 'allow' && (await del.getAttribute('data-pending')) === null);
      // revertir lo concedido (sin asignar) para el siguiente viewport
      for (const c of [cel, del]) { await c.focus(); await p.keyboard.press('Enter'); }
      await Promise.all([p.waitForResponse((x) => x.url().includes('permissions.save')), guardar.click()]); await p.waitForTimeout(300);
      r(`PN-13-${vp}`, 'quitar es sin confirmacion y deja las celdas sin asignar', (await cel.getAttribute('data-state')) === 'unset' && (await del.getAttribute('data-state')) === 'unset');
      // Super Users: atenuado y no editable
      const sup = T('button.eg-perm-cell[data-state=always]').first();
      const antes = await sup.getAttribute('data-state'); await sup.click({ force: true });
      r(`PN-14-${vp}`, 'la celda de Super Users (siempre) no cambia', (await sup.getAttribute('data-state')) === antes && (await sup.getAttribute('aria-disabled')) === 'true');
      r(`PN-15-${vp}`, 'sin errores de JavaScript ni peticiones externas', errs.length === 0 && ext.length === 0, errs.concat(ext).join('|'));
      await ctx.close();
    }
  } catch (e) { r('PN-ERR', 'excepcion en el test: ' + e.message, false); }
  await b.close();
  console.log(`RESUMEN permisos-0916-navegador: ${ok} PASA / ${ko} FALLA`); process.exit(ko ? 1 : 0);
})();
