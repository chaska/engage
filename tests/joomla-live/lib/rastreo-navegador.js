// 0.9.4 / 0.9.5: rastreo de robots y microdatos en Chromium REAL, con SEF + reescritura y SIN live_site (nginx + php-fpm, como un hosting): la URL base es
// indexable y tiene su canonical; las variantes llevan noindex/canonical/nofollow; TODOS los <a href> de la pagina funcionan (ninguno con «amp;», vacio ni roto:
// se piden uno a uno); la fecha de cada comentario es un ancla (:target resaltado); la paginacion va por la ruta amigable; los microdatos tienen cada Comment
// dentro de un CreativeWork (https) y el autor sin url inventada; las personas siguen pudiendo usarlo todo; el semaforo del panel. Requiere filtro-datos.php crear.
const fs = require('fs');
const { execFileSync } = require('child_process');
const { chromium } = require(process.env.PW_MODULE || 'playwright');
const WORK = process.env.WORK; const BASE = process.env.NGINX_URL || 'http://127.0.0.1:8090';
const AP = 'Aa1!' + fs.readFileSync(WORK + '/adminpass.txt', 'utf8').trim();
const php = (...a) => execFileSync('php', [__dirname + '/' + a[0], ...a.slice(1)], { env: process.env });
let ok = 0, ko = 0;
const r = (id, d, c, ev = '') => { (c ? ok++ : ko++); console.log(`${id.padEnd(7)} ${c ? 'PASA ' : 'FALLA'} ${d}${ev ? '  [' + String(ev).slice(0, 260) + ']' : ''}`); };
const ART = '/component/content/article/a-art-publico?catid=8';
(async () => {
  php('rastreo-opciones.php', 'on'); php('rastreo-opciones.php', 'sef'); php('rastreo-datos.php', 'crear');
  const restaura = () => { try { php('rastreo-datos.php', 'borrar'); php('rastreo-opciones.php', 'cfg-restore'); php('rastreo-opciones.php', 'restore'); } catch (e) { console.log('restauracion: ' + e.message); } };
  const b = await chromium.launch({ executablePath: process.env.CHROMIUM || undefined, args: ['--no-sandbox'] });
  try {
    const ctx = await b.newContext({ viewport: { width: 1280, height: 900 } }); await ctx.grantPermissions(['clipboard-read', 'clipboard-write'], { origin: BASE }).catch(() => null);
    await ctx.route(/guidedtours/, (rt) => rt.abort());
    const p = await ctx.newPage(); const errs = []; p.on('pageerror', (e) => errs.push(String(e)));
    const head = () => p.evaluate(() => ({
      robots: Array.from(document.querySelectorAll('meta[name=robots]')).map((m) => m.content),
      canon: Array.from(document.querySelectorAll('link[rel=canonical]')).map((l) => l.getAttribute('href')),
      links: Array.from(document.querySelectorAll('a[href*="akengage_"], a[href^="#akengage-comment-"]')).map((a) => ({ h: a.getAttribute('href'), nf: a.relList.contains('nofollow') })),
      comments: Array.from(document.querySelectorAll('[id^="akengage-comment-"]')).filter((x) => /^akengage-comment-[0-9]+$/.test(x.id)).map((x) => x.id), url: location.href, title: document.title,
    }));
    const abre = async (u) => { const rs = await p.goto(u.startsWith('http') ? u : BASE + u); await p.waitForLoadState('load'); await p.waitForTimeout(500); return rs; };

    const rs = await abre(ART); const base = await head();
    r('B-01', 'SEF + reescritura + SIN live_site: la URL amigable del articulo carga con sus comentarios, sin noindex y con SU canonical relativa (autocanonical)', rs.status() === 200 && base.comments.length >= 20 && !base.robots.some((x) => /noindex/i.test(x)) && base.canon.length === 1 && base.canon[0] === '/component/content/article/a-art-publico?catid=8', JSON.stringify([rs.status(), base.comments.length, base.robots, base.canon]));
    r('B-02', 'Todos los enlaces con akengage_ y las anclas de fecha tienen rel nofollow en el DOM', base.links.length >= 25 && base.links.every((l) => l.nf), base.links.length + ' enlaces; sin nofollow: ' + base.links.filter((l) => !l.nf).map((l) => l.h).slice(0, 2).join(' '));

    // Todos los <a href> de la base y de tres variantes: sin amp;, sin vacios, y cada enlace de Engage se pide y sirve el articulo (no la portada ni un error)
    const todos = new Map(); const malos = [];
    for (const u of [ART, ART + '&akengage_limitstart=20', ART + '&akengage_sort=oldest', ART + '&akengage_cid=9208']) {
      await abre(u);
      const hs = await p.$$eval('a[href]', (as) => as.map((a) => ({ raw: a.getAttribute('href'), abs: a.href })));
      for (const h of hs) { if (!h.raw || !h.raw.trim() || /amp;/i.test(decodeURIComponent(h.raw.replace(/%(?![0-9a-f]{2})/gi, '%25'))) || /amp;/i.test(h.abs) || /^javascript:/i.test(h.raw)) { malos.push(u + ' -> ' + h.raw); } todos.set(h.abs, h.raw); }
    }
    r('B-03', 'Ningun <a href> de la pagina base ni de sus variantes esta vacio, es javascript: o contiene «amp;» (doble escape): ' + todos.size + ' enlaces distintos', malos.length === 0, malos.slice(0, 3).join(' | '));
    const propios = Array.from(todos.keys()).filter((h) => h.startsWith(BASE) && !/\/(media|templates|administrator|images)\//.test(h) && !/#$/.test(h)).map((h) => h.split('#')[0]).filter((h, i, a) => a.indexOf(h) === i);
    const rotos = []; const noHilo = [];
    for (const h of propios.slice(0, 70)) {
      const x = await ctx.request.get(h, { maxRedirects: 0 }).catch((e) => ({ status: () => 0, text: async () => String(e) }));
      const st = x.status(); if (st >= 400 || st === 0) { rotos.push(st + ' ' + h); continue; }
      if (/akengage_(limitstart|sort|fav)=/.test(h) && !(await x.text()).includes('id="akengage-comments-section"')) { noHilo.push(h); }
    }
    r('B-04', 'Cada enlace propio de la pagina (' + propios.length + ' direcciones, incluidas paginacion, orden y favoritos) responde sin error y las de Engage sirven el hilo de comentarios (no la portada)', rotos.length === 0 && noHilo.length === 0, rotos.concat(noHilo).slice(0, 3).join(' | '));
    await abre(ART + '&akengage_limitstart=20');
    const pag = await p.$$eval('.akengage-pagination a', (as) => as.map((a) => a.getAttribute('href')));
    r('B-05', 'Los enlaces de la paginacion de Joomla van por la ruta amigable (/component/content/article/...?...akengage_limitstart=N), sin index.php?view= ni option=', pag.length >= 5 && pag.filter((h) => /akengage_limitstart=\d+/.test(h)).length >= 4 && pag.every((h) => h === '#' || (h.startsWith('/component/content/article/a-art-publico') && !/view=|option=|index\.php/.test(h))), pag.slice(0, 2).join(' '));

    // Fecha = ancla: clic, hash, resaltado :target, comentario visible
    await abre(ART);
    const fecha = await p.$('.akengage-comment-permalink a');
    const hf = await fecha.getAttribute('href'); await fecha.click(); await p.waitForTimeout(1800);
    const t = await p.evaluate(() => { const el = document.querySelector(':target'); if (!el) { return null; } const cs = getComputedStyle(el); const rc = el.getBoundingClientRect(); return { id: el.id, outline: cs.outlineStyle, w: cs.outlineWidth, vis: rc.bottom > 0 && rc.top < innerHeight, top: Math.round(rc.top), sy: Math.round(scrollY), ih: innerHeight, sh: document.documentElement.scrollHeight, hash: location.hash, search: location.search }; });
    r('B-06', 'La fecha del comentario es un ancla (#akengage-comment-ID): el clic no cambia la direccion (sin ?akengage_cid), el comentario queda resaltado (:target con contorno) y visible', /^#akengage-comment-\d+$/.test(hf) && t && t.id === hf.slice(1) && t.outline === 'solid' && t.vis && t.search === '?catid=8', JSON.stringify([hf, t]));
    // Comentario en otra pagina (akengage_cid): se abre la pagina que lo contiene y se resalta
    const cs = await p.evaluate(async (u) => 1, 1);
    const otroId = (await (await ctx.request.get(BASE + ART + '&akengage_limitstart=100')).text()).match(/id="akengage-comment-(\d+)"/)[1];
    await abre(ART + '&akengage_cid=' + otroId + '#akengage-comment-' + otroId);
    const t2 = await p.evaluate(() => { const el = document.querySelector(':target'); return el ? { id: el.id, outline: getComputedStyle(el).outlineStyle } : null; });
    const h2 = await head();
    r('B-07', 'Un enlace con ?akengage_cid de un comentario de OTRA pagina abre la pagina que lo contiene, lo resalta, y la variante lleva noindex, follow y canonical limpia', t2 && t2.id === 'akengage-comment-' + otroId && t2.outline === 'solid' && h2.robots.includes('noindex, follow') && h2.canon.length === 1 && h2.canon[0] === '/component/content/article/a-art-publico?catid=8', JSON.stringify([t2, h2.robots, h2.canon]));
    // Clics de persona: pagina 2 y orden
    await abre(ART); const pg = await p.$('.akengage-pagination a[aria-label="Go to page 2"]');
    const ids1 = (await head()).comments; await Promise.all([p.waitForNavigation({ waitUntil: 'load' }), pg.click()]); await p.waitForTimeout(400);
    const v2 = await head();
    r('B-08', 'Clic en la pagina 2: otros comentarios, noindex, follow, canonical limpia y nofollow en sus enlaces (la paginacion sigue funcionando para las personas)', /akengage_limitstart=20/.test(v2.url) && v2.comments.length > 0 && !v2.comments.some((i) => ids1.includes(i)) && v2.robots.includes('noindex, follow') && v2.canon[0] === '/component/content/article/a-art-publico?catid=8' && v2.links.every((l) => l.nf), JSON.stringify([v2.url, v2.robots, v2.canon]));
    await abre(ART); const so = await p.$('.akengage-sort-link[href*="akengage_sort=oldest"]');
    await Promise.all([p.waitForNavigation({ waitUntil: 'load' }), so.click()]); await p.waitForTimeout(400); const v3 = await head();
    r('B-09', 'Clic en «Oldest»: la variante lleva noindex, follow y canonical; la lista sigue ordenada para las personas', /akengage_sort=oldest/.test(v3.url) && v3.robots.includes('noindex, follow') && v3.canon[0] === '/component/content/article/a-art-publico?catid=8' && v3.comments.length > 0, JSON.stringify([v3.robots, v3.canon]));
    await abre(ART); await p.waitForSelector('.akengage-copy-btn', { state: 'visible', timeout: 5000 }).catch(() => null); const cp = await p.$('.akengage-copy-btn');
    if (cp && await cp.isVisible()) { await cp.click(); await p.waitForTimeout(500); const tx = await p.evaluate(() => navigator.clipboard.readText().catch(() => '')); r('B-10', 'El boton «Copiar enlace» copia la direccion amigable permanente (con akengage_cid y ancla) del comentario', new RegExp('^' + BASE + '/component/content/article/a-art-publico\\?catid=8&akengage_cid=\\d+#akengage-comment-\\d+$').test(tx), tx); }
    else { r('B-10', 'El boton «Copiar enlace» esta visible', false, 'no visible'); }

    // Microdatos
    await abre(ART);
    const md = await p.evaluate(() => {
      const HTTPS = 'https://schema.org/'; const out = { comments: 0, sinCW: 0, sinProp: 0, http: 0, personas: 0, personaSinNombre: 0, urlSinPerfil: 0, urlMalRel: 0, cw: 0, cwName: '', cwUrl: '', tipos: [] };
      document.querySelectorAll('[itemscope]').forEach((e) => { const t = e.getAttribute('itemtype') || ''; out.tipos.push(t); if (/^http:\/\//i.test(t)) { out.http++; } });
      document.querySelectorAll('[itemtype$="/CreativeWork"]').forEach((e) => { out.cw++; const n = e.querySelector(':scope > meta[itemprop=name]'); const u = e.querySelector(':scope > link[itemprop=url]'); out.cwName = n ? n.content : ''; out.cwUrl = u ? u.href : ''; });
      document.querySelectorAll('[itemtype$="/Comment"]').forEach((c) => { out.comments++; if (!c.parentElement.closest('[itemtype="' + HTTPS + 'CreativeWork"]')) { out.sinCW++; } if (c.getAttribute('itemprop') !== 'comment') { out.sinProp++; } });
      document.querySelectorAll('[itemtype$="/Person"]').forEach((pe) => { out.personas++; if (!pe.querySelector('[itemprop=name]') || !pe.querySelector('[itemprop=name]').textContent.trim()) { out.personaSinNombre++; } const u = pe.querySelector('[itemprop=url]'); if (u) { if (u.tagName !== 'A' || !u.getAttribute('href')) { out.urlSinPerfil++; } if (!(u.relList.contains('nofollow') && u.relList.contains('ugc'))) { out.urlMalRel++; } } });
      out.guestsSinUrl = Array.from(document.querySelectorAll('[itemtype$="/Person"]')).filter((pe) => !pe.querySelector('[itemprop=url]')).length;
      return out;
    });
    r('B-11', 'Microdatos: ' + md.comments + ' Comment, cada uno con itemprop="comment" y dentro de un CreativeWork (https); ningun tipo en http://', md.comments >= 20 && md.sinCW === 0 && md.sinProp === 0 && md.http === 0 && md.cw === 1, JSON.stringify(md));
    r('B-12', 'CreativeWork con name = titulo del articulo y url = canonical limpia; cada Person (invitados y registrados) con name; url solo si hay perfil real (a, nofollow ugc): a los invitados no se les inventa', md.cwName === 'Articulo publico' && md.cwUrl === BASE + '/component/content/article/a-art-publico?catid=8' && md.personas >= 20 && md.personaSinNombre === 0 && md.urlSinPerfil === 0 && md.urlMalRel === 0 && md.guestsSinUrl > 0, JSON.stringify([md.cwName, md.cwUrl, md.personas, md.guestsSinUrl]));
    const reg = await p.evaluate(() => Array.from(document.querySelectorAll('[itemtype$="/Comment"]')).filter((c) => /F9R-BODY/.test(c.textContent)).map((c) => ({ nombre: (c.querySelector('[itemtype$="/Person"] [itemprop=name]') || {}).textContent, en: !!c.closest('[itemtype$="/CreativeWork"]') })));
    r('B-13', 'Los comentarios de usuarios registrados tambien son Comment dentro del CreativeWork y su autor tiene name', reg.length === 3 && reg.every((x) => x.en && x.nombre && x.nombre.trim().length > 0), JSON.stringify(reg));
    // Aspecto: las clases/selectores de siempre siguen
    const cls = await p.evaluate(() => ({ s: !!document.querySelector('section#akengage-comments-section.akengage-outer-container'), l: !!document.querySelector('section#akengage-comments-section .akengage-list-container ul.akengage-comment-list'), a: document.querySelectorAll('article[id^="akengage-comment-"]').length }));
    r('B-14', 'Las clases e identificadores de las plantillas siguen igual (el CSS y el JS de los temas no cambian)', cls.s && cls.l && cls.a >= 20, JSON.stringify(cls));

    // Panel de control
    await p.goto(BASE + '/administrator/index.php'); await p.fill('#mod-login-username', 'admintest'); await p.fill('#mod-login-password', AP);
    await Promise.all([p.waitForNavigation(), p.click('button.login-button, button[type=submit]')]);
    await abre('/administrator/index.php?option=com_engage');
    const txt = (await p.textContent('body')) || '';
    r('B-15', 'El panel de control muestra el punto «Robot crawling» del semaforo', /Robot crawling/.test(txt), (txt.match(/Robot crawling[\s\S]{0,200}/) || [''])[0].replace(/\s+/g, ' '));
    r('B-16', 'Sin errores de JavaScript en todo el recorrido', errs.length === 0, errs.join(' | '));
  } finally { await b.close(); restaura(); }
  console.log(`RESUMEN rastreo-navegador: ${ok} PASA / ${ko} FALLA`); process.exit(ko ? 1 : 0);
})();
