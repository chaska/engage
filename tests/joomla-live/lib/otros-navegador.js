/**
 * 0.8.2: comentarios en el contenido de otros componentes en CHROMIUM, con y sin cache de pagina:
 *   - texto hostil del tercero (HTML y JavaScript en titulo, categoria, autor, correo y URLs): ningun dialogo, ningun script ejecutado (canario window.__xss),
 *     ningun nodo <script>/<img onerror> inyectado, ningun enlace javascript:/data:/de otro sitio, el texto aparece COMO TEXTO;
 *   - el hilo de un elemento publico sale con su formulario y se puede comentar de verdad (editor, envio, el comentario queda guardado con su asset_id);
 *   - un elemento restringido no sale para el invitado ni al visitar primero con otro usuario (cache de pagina);
 *   - cero peticiones a otros hosts y cero errores de consola de Engage.
 * Uso: PW_MODULE=... CHROMIUM=... WORK=... SITE_DIR=... node otros-navegador.js   (requiere lib/otros-datos.php crear)
 */
'use strict';
const {execFileSync} = require('child_process');
const path = require('path');
const {chromium} = require(process.env.PW_MODULE || 'playwright');
const BASE = process.env.BASE_URL || 'http://127.0.0.1:8080';
const WORK = process.env.WORK;
const CFG = path.join(__dirname, 'xss-config.php');
const cfg = (...a) => execFileSync('php', [CFG, ...a], {env: process.env}).toString();
const fs = require('fs');
const PASS = fs.readFileSync(`${WORK}/otrospass.txt`, 'utf8').trim();
const mysql = (s) => execFileSync('mysql', [process.env.DB_NAME || 'joomla_test', '-N', '-e', s]).toString().trim();
let ok = 0, ko = 0;
const r = (id, desc, cond, ev) => { (cond ? ok++ : ko++); console.log(`${id.padEnd(10)} ${cond ? 'PASA ' : 'FALLA'} ${desc}${ev ? '  [' + String(ev).slice(0, 300) + ']' : ''}`); };

async function visita(b, url, usuario) {
  const ctx = await b.newContext({viewport: {width: 1100, height: 900}});
  const p = await ctx.newPage();
  const info = {dialogos: 0, externas: [], errores: []};
  p.on('dialog', async d => { info.dialogos++; await d.dismiss(); });
  p.on('request', q => { const u = new URL(q.url()); if (u.hostname !== '127.0.0.1') info.externas.push(q.url()); });
  p.on('pageerror', e => info.errores.push(String(e)));
  if (usuario) {
    await p.goto(BASE + '/');
    const tok = await p.evaluate(() => { const i = [...document.querySelectorAll('form input[type=hidden]')].find(x => /^[0-9a-f]{32}$/.test(x.name) && x.value === '1'); return i ? i.name : ''; });
    await p.request.post(BASE + '/index.php?option=com_users&task=user.login', {form: {username: usuario, password: PASS, return: Buffer.from(BASE + '/').toString('base64'), [tok]: '1'}});
  }
  const resp = await p.goto(BASE + url);
  await p.waitForTimeout(400);
  return {ctx, p, info, resp};
}

(async () => {
  const b = await chromium.launch({executablePath: process.env.CHROMIUM || '/opt/pw-browsers/chromium', args: ['--no-sandbox']});
  try {
    for (const [nombre, c] of [['sin cache', ['0', '0', '1', '1']], ['cache de pagina', ['1', '1', '1', '1']]]) {
      cfg('set', ...c);
      // 1) texto hostil
      for (const [url, asset] of [['/index.php?tp=13', 900013], ['/index.php?tp_asset=910003', 910003], ['/index.php?tp_asset=910011', 910011]]) {
        for (let visita_n = 1; visita_n <= 2; visita_n++) {
          const {ctx, p, info} = await visita(b, url, null);
          const est = await p.evaluate(() => ({
            canario: typeof window.__xss !== 'undefined',
            scripts: [...document.querySelectorAll('script')].filter(s => /__xss|alert\(/.test(s.textContent)).length,
            imgs: document.querySelectorAll('img[src="x"], img[onerror]').length,
            on: [...document.querySelectorAll('*')].filter(e => [...e.attributes].some(a => /^on/i.test(a.name))).length,
            enlaces: [...document.querySelectorAll('#tp-comentarios a[href], #akengage-comments-section a[href]')].map(a => a.getAttribute('href')).filter(h => /^\s*(javascript|data|vbscript):|evil\.test|^\/\//i.test(h)).length,
            hilo: !!document.getElementById('akengage-comments-section'),
            titulo: (document.querySelector('#akengage-comments-section .akengage-title') || {}).textContent || '',
          }));
          r(`B-X-${asset}-${visita_n}`, `[${nombre}] texto hostil ${asset}, visita ${visita_n}: hilo, sin dialogos, sin canario, sin script/img/on* inyectados, sin enlaces peligrosos, titulo como texto`,
            est.hilo && info.dialogos === 0 && !est.canario && est.scripts === 0 && est.imgs === 0 && est.on === 0 && est.enlaces === 0 && /<script>window\.__xss/.test(est.titulo), JSON.stringify(est) + ' dialogos=' + info.dialogos);
          r(`B-R-${asset}-${visita_n}`, `[${nombre}] ${asset}: cero peticiones externas y cero errores de JavaScript`, info.externas.length === 0 && info.errores.length === 0, info.externas.join(' ') + info.errores.join(' '));
          await ctx.close();
        }
      }
      // 2) restringido y orden de visitas con cache
      for (const usuario of ['otrosreg', null]) {
        const {ctx, p} = await visita(b, '/index.php?tp=3', usuario);
        const hay = (await p.content()).includes('MARCA-900003');
        r(`B-C-${usuario || 'inv'}`, `[${nombre}] elemento solo para Registered visto por ${usuario ? 'Registered' : 'invitado'} (el invitado, despues del Registered)`, hay === !!usuario);
        await ctx.close();
      }
    }
    // 3) comentar de verdad
    cfg('set', '0', '0', '1', '1');
    {
      const {ctx, p} = await visita(b, '/index.php?tp=1', 'otrosreg');
      r('B-F1', 'Registered: el elemento publico muestra el hilo y el formulario', (await p.locator('#akengage-comments-section').count()) === 1 && (await p.locator('#akengageCommentForm').count()) === 1);
      const asset = await p.evaluate(() => document.forms['akengageCommentForm']['jform[asset_id]'].value);
      r('B-F2', 'el formulario lleva el asset_id del elemento (900001)', asset === '900001', asset);
      await p.waitForFunction(() => window.tinymce && tinymce.get('jform_commentText'), null, {timeout: 20000}).catch(() => {});
      const texto = 'Desde Chromium ' + Date.now();
      const tiene = await p.evaluate(() => !!(window.tinymce && tinymce.get('jform_commentText')));
      if (tiene) { await p.evaluate(t => tinymce.get('jform_commentText').setContent('<p>' + t + '</p>'), texto); }
      else { await p.fill('[name="jform[commentText]"]', texto).catch(() => {}); }
      await Promise.all([p.waitForNavigation(), p.locator('.akengage-comment-submit-btn').click()]);
      const fila = mysql(`SELECT CONCAT(asset_id,':',enabled) FROM jos_engage_comments WHERE body LIKE '%${texto}%'`);
      r('B-F3', 'el comentario enviado desde el navegador queda guardado en el activo 900001 y publicado', fila === '900001:1', fila);
      // El enlace de vuelta conserva solo los parametros conocidos de la URL (option, view, id, Itemid...: CommentTools::ALLOWED_PARAMS); este elemento de prueba usa ?tp=, asi que se vuelve a la portada. Con URL amigables o `id` se vuelve al elemento
      await p.goto(BASE + '/index.php?tp=1');
      r('B-F4', 'tras enviar, el comentario se ve en el elemento', (await p.content()).includes(texto), p.url());
      await ctx.close();
    }
  } finally { cfg('restore'); await b.close(); }
  console.log(`\nRESUMEN: ${ok} PASA, ${ko} FALLA`);
  process.exit(ko ? 1 : 0);
})().catch(e => { console.error(e); cfg('restore'); process.exit(1); });
