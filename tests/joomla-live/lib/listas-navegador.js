// 0.9.2: flujo REAL en Chromium de CADA formulario de lista del backend (tarea vacia / «browse» de Joomla): filtros (estado, tipo, fechas), busqueda,
// ordenacion por columna, paginacion, numero de filas, limpiar y acciones por lote, en Comentarios; y que Plantillas de correo, Opciones y Panel de control
// cargan y sus formularios responden. Ningun paso puede acabar en «403 No esta autorizado a poder ver este recurso».
// Requiere lib/filtro-datos.php crear (200+ comentarios). Variables: WORK, PW_MODULE, CHROMIUM, BASE_URL.
const fs = require('fs');
const { chromium } = require(process.env.PW_MODULE || 'playwright');
const WORK = process.env.WORK; const BASE = process.env.BASE_URL || 'http://127.0.0.1:8080';
const AP = 'Aa1!' + fs.readFileSync(WORK + '/adminpass.txt', 'utf8').trim();
let ok = 0, ko = 0;
const r = (id, d, c, ev = '') => { (c ? ok++ : ko++); console.log(`${id.padEnd(7)} ${c ? 'PASA ' : 'FALLA'} ${d}${ev ? '  [' + String(ev).slice(0, 220) + ']' : ''}`); };
(async () => {
  const b = await chromium.launch({ executablePath: process.env.CHROMIUM || undefined, args: ['--no-sandbox'] });
  const ctx = await b.newContext({ viewport: { width: 1400, height: 1000 } }); await ctx.route(/guidedtours/, (rt) => rt.abort()); /* el recorrido guiado de Joomla (primera vez) navega solo al escritorio */ const p = await ctx.newPage();
  const errs = []; p.on('pageerror', (e) => errs.push(String(e)));
  await p.goto(BASE + '/administrator/index.php'); await p.fill('#mod-login-username', 'admintest'); await p.fill('#mod-login-password', AP);
  await Promise.all([p.waitForNavigation(), p.click('button.login-button, button[type=submit]')]);
  const L = BASE + '/administrator/index.php?option=com_engage&view=comments';
  const prohibido = async () => { await p.waitForTimeout(1200); try { const t = (await p.textContent('body')).slice(0, 6000); return /No est[aá] autorizado|not authori[sz]ed to view this resource|\b403\b/i.test(t) || /Home Dashboard|Panel de control de Joomla|Inicio/i.test(await p.title()); } catch (e) { return true; } };
  const filas = async () => { for (let i = 0; i < 3; i++) { try { return (await p.$$('input[name="cid[]"]')).length; } catch (e) { await p.waitForTimeout(800); } } return -1; };
  const espera = async (fn) => { await Promise.all([p.waitForNavigation({ waitUntil: 'load', timeout: 30000 }).catch(() => null), fn()]); await p.waitForLoadState('load'); };
  const abre = async () => { await p.goto(L + '&filter[search]=&filter[enabled]=&filter[itemtype]=&filter[itemid]=&list[limit]=20&limitstart=0'); await p.waitForLoadState('load'); await p.waitForTimeout(1500); };
  // el boton «Opciones de filtro» muestra los filtros si estan ocultos
  const filtros = async () => { await p.waitForTimeout(500); const f = await p.$('.js-stools-container-filters').catch(() => null); if (f && !(await f.isVisible())) { const bt = await p.$('.js-stools-btn-filter'); if (bt) { await bt.click(); } } };

  await abre();
  r('L-00', 'La lista de comentarios carga (20 filas por pagina) sin 403', !(await prohibido()) && (await filas()) === 20, 'filas=' + (await filas()));
  await filtros();
  // estado
  const selE = await p.$('select[name="filter[enabled]"]');
  r('L-01', 'Existe el filtro de estado', !!selE);
  if (selE) { await espera(() => p.selectOption('select[name="filter[enabled]"]', '-2')); r('L-02', 'Filtro de estado (spam): sin 403 y con el estado elegido', !(await prohibido()) && (await p.$eval('select[name="filter[enabled]"]', (s) => s.value)) === '-2', 'filas=' + (await filas()) + ' url=' + p.url().slice(-60)); }
  await abre(); await filtros();
  // tipo de elemento
  const selT = await p.$('select[name="filter[itemtype]"]');
  r('L-03', 'Existe el filtro «Tipo de elemento»', !!selT);
  if (selT) {
    const opts = await p.$$eval('select[name="filter[itemtype]"] option', (o) => o.map((x) => x.value));
    r('L-04', 'Sus opciones incluyen los tipos reales (articulos, ranking del juego y mapas)', ['com_content.article', 'com_juego.ranking', 'com_mapaviajes.trip'].every((t) => opts.includes(t)), opts.join(','));
    await espera(() => p.selectOption('select[name="filter[itemtype]"]', 'com_mapaviajes.trip'));
    r('L-05', 'Filtro por tipo (mapas): sin 403 y solo los 5 de mapas', !(await prohibido()) && (await filas()) === 5, 'filas=' + (await filas()));
    await filtros();
    await espera(() => p.selectOption('select[name="filter[itemtype]"]', 'com_juego.ranking'));
    r('L-06', 'Filtro por tipo (ranking): solo los 4 del ranking', !(await prohibido()) && (await filas()) === 4, 'filas=' + (await filas()));
    // combinado con estado
    await filtros(); await espera(() => p.selectOption('select[name="filter[itemtype]"]', 'com_mapaviajes.trip')); await filtros();
    await espera(() => p.selectOption('select[name="filter[enabled]"]', '0'));
    r('L-07', 'Tipo (mapas) + estado (sin publicar): sin 403 y solo el comentario sin publicar de mapas', !(await prohibido()) && (await filas()) === 1, 'filas=' + (await filas()));
  }
  // 0.9.3: recuentos y etiquetas amigables en «Tipo de elemento»; filtro «Elemento»
  await abre(); await filtros();
  const textos = await p.$$eval('select[name="filter[itemtype]"] option', (o) => o.map((x) => x.textContent.trim()));
  r('L-07b', 'Las opciones de tipo llevan el recuento entre parentesis y etiquetas amigables (Mapa de viajes / Travel map; articulos)', textos.some((t) => /^(Mapa de viajes|Travel map) \(5\)$/.test(t)) && textos.some((t) => /^(Art[ií]culos|Articles) \(\d+\)$/.test(t)) && textos.some((t) => /^com_juego\.ranking.* \(4\)$/.test(t)), textos.join(' | '));
  const selI = await p.$('select[name="filter[itemid]"]');
  r('L-07c', 'Existe el filtro «Elemento»', !!selI);
  if (selI) {
    const optI = await p.$$eval('select[name="filter[itemid]"] option', (o) => o.map((x) => [x.value, x.textContent.trim()]));
    const libro = optI.find((o) => /\(2\d\d\)$/.test(o[1]));
    r('L-07d', 'Sus opciones: elementos con su numero de comentarios, el mas comentado (el «libro de visitas», ~200) primero', !!libro && optI[1] && optI[1][0] === libro[0], optI.slice(0, 4).map((o) => o.join('=')).join(' | '));
    if (libro) {
      await espera(() => p.selectOption('select[name="filter[itemid]"]', libro[0]));
      const n1 = await filas();
      r('L-07e', 'Filtro por elemento (libro de visitas): sin 403, 20 filas por pagina', !(await prohibido()) && n1 === 20, 'filas=' + n1);
      const pg = await p.$('.pagination .page-item:nth-child(4) a, .pagination a.page-link[aria-label*="2"]');
      if (pg) { await espera(() => pg.click()); }
      r('L-07f', 'La paginacion conserva el filtro por elemento (pagina 2: filas y el elemento sigue elegido)', !(await prohibido()) && (await filas()) > 0 && (await p.$eval('select[name="filter[itemid]"]', (e) => e.value)) === libro[0], 'filas=' + (await filas()));
      await filtros();
      await espera(() => p.selectOption('select[name="filter[itemtype]"]', 'com_mapaviajes.trip'));
      r('L-07g', 'Al cambiar de tipo, el elemento que no es de ese tipo se ignora (solo los 5 de mapas)', !(await prohibido()) && (await filas()) === 5, 'filas=' + (await filas()));
    }
    await abre(); await filtros();
    await espera(() => p.selectOption('select[name="filter[itemtype]"]', 'com_content.article'));
    const optI2 = await p.$$eval('select[name="filter[itemid]"] option', (o) => o.map((x) => x.textContent.trim()));
    r('L-07h', 'Con el tipo «Articulos», el desplegable de elementos solo ofrece articulos', !(await prohibido()) && !optI2.some((t) => /Activo|ranking|trip/i.test(t)) && optI2.length >= 2, optI2.join(' | '));
  }
  // mas de 100 elementos de un tipo: «Otros…»
  await abre(); await filtros();
  await espera(() => p.selectOption('select[name="filter[itemtype]"]', 'com_juego.partida'));
  const optP = await p.$$eval('select[name="filter[itemid]"] option', (o) => o.map((x) => [x.value, x.textContent.trim()]));
  const otros = optP.find((o) => o[0] === 'other');
  r('L-07j', 'Mas de 100 elementos del tipo: 100 en la lista mas «Otros… (10)»', optP.length === 102 && !!otros && /\(10\)$/.test(otros[1]), 'opciones=' + optP.length + ' ' + (otros || []).join('='));
  if (otros) { await filtros(); await espera(() => p.selectOption('select[name="filter[itemid]"]', 'other')); r('L-07k', '«Otros…» muestra los 10 comentarios de los elementos que no caben en la lista', !(await prohibido()) && (await filas()) === 10, 'filas=' + (await filas())); }
  await abre();
  await p.fill('#filter_search', 'Articulo publico');
  await espera(() => p.click('button.filter-search-bar__button, .js-stools-btn-search'));
  r('L-07i', 'La busqueda de texto tambien busca en el titulo del elemento (articulo «Articulo publico»: 20 filas por pagina)', !(await prohibido()) && (await filas()) === 20, 'filas=' + (await filas()));
  // busqueda de texto
  await abre();
  await p.fill('#filter_search', 'F9N-RANK-2');
  await espera(() => p.click('button.filter-search-bar__button, .js-stools-btn-search'));
  r('L-08', 'Busqueda de texto: sin 403 y 1 resultado', !(await prohibido()) && (await filas()) === 1, 'filas=' + (await filas()));
  // limpiar
  const clr = await p.$('button.js-stools-btn-clear, .filter-search-actions__button.js-stools-btn-clear');
  if (clr) { await espera(() => clr.click()); }
  r('L-09', '«Limpiar»: sin 403 y vuelve a la lista completa de 20 filas', !!clr && !(await prohibido()) && (await filas()) === 20, 'filas=' + (await filas()));
  // paginacion
  const pag = await p.$$('.pagination a.page-link');
  r('L-10', 'Hay paginacion', pag.length > 0);
  const sig = await p.$('.pagination a.page-link[aria-label*="2"], .pagination li:not(.disabled) a.page-link');
  const primero = await p.$eval('input[name="cid[]"]', (e) => e.value);
  if (sig) { await espera(() => p.click('.pagination .page-item:nth-child(4) a, .pagination a.page-link[aria-label*="2"]').catch(() => sig.click())); }
  r('L-11', 'Paginacion (pagina 2): sin 403 y otras filas', !(await prohibido()) && (await filas()) > 0 && (await p.$eval('input[name="cid[]"]', (e) => e.value)) !== primero, 'filas=' + (await filas()));
  // numero de filas
  const selL = await p.$('select[name="list[limit]"]');
  if (selL) { await espera(() => p.selectOption('select[name="list[limit]"]', '50')); }
  r('L-12', 'Selector del numero de filas (50): sin 403 y 50 filas', !!selL && !(await prohibido()) && (await filas()) === 50, 'filas=' + (await filas()));
  // ordenacion por columna
  const ord = await p.$('th a.js-stools-column-order');
  if (ord) { const antes = await p.$eval('input[name="cid[]"]', (e) => e.value); await espera(() => ord.click()); r('L-13', 'Ordenar por columna: sin 403', !(await prohibido()) && (await filas()) > 0, 'primero antes=' + antes + ' despues=' + (await p.$eval('input[name="cid[]"]', (e) => e.value))); }
  else { r('L-13', 'Hay cabeceras ordenables', false); }
  // seleccion multiple + accion por lote (estado): despublicar 2 y comprobar que no hay 403
  await abre();
  const cbs = await p.$$('input[name="cid[]"]');
  await cbs[0].check(); await cbs[1].check();
  const antesEn = await p.$$eval('input[name="cid[]"]:checked', (e) => e.map((x) => x.value));
  const dd = await p.$('#toolbar-status-group button.button-status-group');
  if (dd) { await dd.click(); }
  const un = await p.$('#status-group-children-unpublish button');
  if (un) { await espera(() => un.click()); }
  const nDesp = await p.evaluate(() => 0); r('L-14', 'Accion por lote (despublicar 2 seleccionados) sin 403 y con mensaje de resultado', !!un && !(await prohibido()) && (await p.$('joomla-alert, .alert-message, .alert')) !== null, 'sel=' + antesEn.join(',') + ' un=' + !!un);
  // otras pantallas
  for (const [id, url, esperado] of [['L-15', '/administrator/index.php?option=com_engage&view=emailtemplates', 'Email'], ['L-16', '/administrator/index.php?option=com_engage&view=settings', 'Opciones|Settings|Options'], ['L-17', '/administrator/index.php?option=com_engage&view=controlpanel', 'Engage'], ['L-18', '/administrator/index.php?option=com_engage', 'Engage']]) {
    const resp = await p.goto(BASE + url); r(id, 'Carga ' + url.split('view=')[1] + ' (HTTP 200, sin 403)', resp.status() === 200 && !(await prohibido()) && new RegExp(esperado, 'i').test(await p.title() + (await p.textContent('body')).slice(0, 2000)));
  }
  // formulario de edicion de un comentario
  await p.goto(BASE + '/administrator/index.php?option=com_engage&view=comments&filter[search]=F9N-RANK-1'); const idc = await p.$eval('input[name="cid[]"]', (e) => e.value).catch(() => '');
  if (idc) { const resp = await p.goto(BASE + '/administrator/index.php?option=com_engage&view=comment&task=comment.edit&id=' + idc); r('L-19', 'Editar un comentario: abre el formulario sin 403', resp.status() === 200 && (await p.$('form#autoreply-form')) !== null && !(await prohibido()), 'HTTP ' + resp.status()); const cancel = await p.$('joomla-toolbar-button.button-cancel button, #toolbar-cancel button'); if (cancel) { await espera(() => cancel.click()); } r('L-20', 'Cancelar la edicion vuelve a la lista sin 403', !(await prohibido())); }
  r('L-21', 'Sin errores de JavaScript en ninguna de las pantallas (backend.js ya no usa akeeba.System)', errs.length === 0, errs.join(' | '));
  await b.close();
  console.log(`RESUMEN listas-navegador: ${ok} PASA / ${ko} FALLA`);
  process.exit(ko ? 1 : 0);
})().catch((e) => { console.error(e); process.exit(2); });
