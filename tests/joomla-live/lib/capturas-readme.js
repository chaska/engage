// Capturas del README (docs/capturas/*.jpg): Chromium REAL contra el Joomla de pruebas con DATOS INVENTADOS (lib/capturas-readme-datos.php crear), SOLO el contenido
// de la pagina (el contenedor del componente, sin barra del navegador ni menu de Joomla), claro, es-ES. Escribe PNG en OUT_IMG (nunca dentro del repositorio); el paso
// siguiente (lib/capturas-readme-jpg.py) los convierte a JPG calidad 85 sin metadatos. Variables: WORK, PW_MODULE, CHROMIUM, BASE_URL, OUT_IMG.
const fs = require('fs'); const path = require('path');
const { chromium } = require(process.env.PW_MODULE || 'playwright');
const WORK = process.env.WORK; const REAL = process.env.BASE_URL || 'http://127.0.0.1:8080';
// El sitio de pruebas se anuncia con un dominio ficticio (live_site = http://sitio-de-pruebas.example.test:8080); Chromium lo atiende en local: ninguna captura muestra la direccion real.
const BASE = 'http://sitio-de-pruebas.example.test:8080';
const enruta = (c) => c.route('http://sitio-de-pruebas.example.test:8080/**', async (r) => { const x = await r.fetch({ url: r.request().url().replace(BASE, REAL), maxRedirects: 0 }); await r.fulfill({ response: x }); });
const OUT = process.env.OUT_IMG || path.join(WORK, 'capturas-readme'); fs.mkdirSync(OUT, { recursive: true });
const AP = 'Aa1!' + fs.readFileSync(WORK + '/adminpass.txt', 'utf8').trim();
const A = BASE + '/administrator/index.php?option=com_engage';
const ids = JSON.parse(fs.readFileSync(WORK + '/ids.json', 'utf8'));
const secciones = ['design', 'moderation', 'reactions', 'antispam', 'notifications', 'privacy', 'security', 'advanced'];
const admin = [
  ['principal', A + '&view=controlpanel', '#eg-admin', 1128],
  ['comentarios', A + '&view=comments', '#eg-admin-comments', 1180],
  ['editar-comentario', A + '&task=comment.edit&id=__ID__', '#eg-admin-comment, .eg-admin', 1000],
  ['plantillas', A + '&view=emailtemplates', '#eg-admin-emailtemplates', 1000],
  ['vista-previa-correo', A + '&view=emailpreview', '.eg-mailpreview', 1000],
  ['denuncias', A + '&view=reports&status=all', '#eg-admin-reports', 1000],
  ['permisos', A + '&view=permissions', '#eg-admin-permissions', 1290],
  ...secciones.map((s) => ['ajustes-' + s, A + '&view=settings&section=' + s, '#eg-admin', 1000]),
];
(async () => {
  const b = await chromium.launch({ executablePath: process.env.CHROMIUM || undefined, args: ['--no-sandbox'] });
  const ctx = await b.newContext({ viewport: { width: 1360, height: 1000 }, locale: 'es-ES', colorScheme: 'light' }); await enruta(ctx); const p = await ctx.newPage();
  await p.goto(BASE + '/administrator/index.php'); await p.fill('#mod-login-username', 'admintest'); await p.fill('#mod-login-password', AP);
  await p.click('button[type=submit]'); await p.waitForTimeout(2500);
  await p.evaluate(() => { try { localStorage.setItem('eg-admin-theme', 'light'); } catch (e) {} });
  const quita = () => p.evaluate(() => { document.querySelectorAll('#system-message-container, .alert-message, .com-cpanel-stats, joomla-alert').forEach((e) => e.remove()); });
  const foto = async (nombre, sel, tope) => {
    await quita(); await p.waitForTimeout(500);
    const el = p.locator(sel).first(); const bb = await el.boundingBox();
    if (!bb) { console.log('SIN ELEMENTO', nombre, sel); return; }
    await p.screenshot({ path: path.join(OUT, nombre + '.png'), fullPage: true, clip: { x: bb.x, y: bb.y, width: Math.min(bb.width, 1400), height: Math.min(bb.height, tope) } });
    console.log('ok', nombre, Math.round(bb.width), Math.round(bb.height));
  };
  const cid = process.env.COMMENT_ID || '1';
  for (const [n, u, sel, tope] of admin) {
    await p.goto(u.replace('__ID__', cid), { waitUntil: 'networkidle' }); await p.waitForTimeout(600);
    if (n.startsWith('ajustes-')) { try { await p.evaluate((s) => { const t = document.querySelector('[data-eg-tab="' + s.replace('ajustes-', '') + '"]'); if (t) t.click(); }, n); } catch (e) {} await p.waitForTimeout(300); }
    await foto(n, sel, tope);
    if (n === 'editar-comentario') { await p.goto(A + '&task=comment.cancel'); }
  }
  // plugins (pantalla de Joomla)
  for (const [n, id] of [['plugin-akismet', 258], ['plugin-email', 259], ['plugin-gravatar', 260], ['plugin-actionlog', 254], ['plugin-user', 263]]) {
    await p.goto(BASE + '/administrator/index.php?option=com_plugins&task=plugin.edit&extension_id=' + id, { waitUntil: 'networkidle' }); await p.waitForTimeout(600);
    await foto(n, '#content', 1100);
    await p.goto(BASE + '/administrator/index.php?option=com_plugins&task=plugin.cancel', { waitUntil: 'networkidle' });
  }
  // sitio publico
  const pub = await b.newContext({ viewport: { width: 1100, height: 1000 }, locale: 'es-ES', colorScheme: 'light' }); await enruta(pub); const q = await pub.newPage();
  await q.goto(BASE + '/index.php?option=com_content&view=article&id=' + ids.art_publico + '&catid=' + ids.cat_publica, { waitUntil: 'networkidle' }); await q.waitForTimeout(800);
  const f2 = async (nombre, sel, tope) => { const bb = await q.locator(sel).first().boundingBox(); if (!bb) { console.log('SIN', nombre); return; } await q.screenshot({ path: path.join(OUT, nombre + '.png'), fullPage: true, clip: { x: bb.x, y: bb.y, width: bb.width, height: Math.min(bb.height, tope) } }); console.log('ok', nombre, Math.round(bb.width), Math.round(bb.height)); };
  await f2('publica-comentarios', '#akengage-comments-section', 1100);
  
  await q.evaluate(() => { document.querySelectorAll('#akengageCommentForm .tox-tinymce, #akengageCommentForm .tox-edit-area, #akengageCommentForm iframe').forEach((e) => { e.style.height = '150px'; e.style.minHeight = '0'; }); window.scrollTo(0, 0); });
  await q.waitForTimeout(300);
  const frm = q.locator('#akengageCommentForm').first(); if (await frm.count()) { const bb = await frm.boundingBox(); if (bb) { await q.screenshot({ path: path.join(OUT, 'publica-formulario.png'), fullPage: true, clip: { x: bb.x, y: bb.y, width: bb.width, height: Math.min(bb.height, 1100) } }); console.log('ok publica-formulario'); } }
  await q.evaluate(() => window.scrollTo(0, 0));
  const mod = q.locator('.sidebar-right.card:has(.engage-latest-list)').first(); if (await mod.count()) { const bb = await mod.boundingBox(); await q.screenshot({ path: path.join(OUT, 'modulo-ultimos.png'), fullPage: true, clip: { x: bb.x, y: bb.y, width: Math.min(bb.width, 500), height: Math.min(bb.height, 900) } }); console.log('ok modulo'); } else { console.log('SIN modulo'); }
  await b.close();
})();
