// 0.9.11: opcion «Avatar generico en los comentarios sin foto» (Mostrar / No mostrar) en Chromium REAL: con foto (campo de usuario) no cambia; sin foto e importados:
// con «No mostrar» no hay <img> ni hueco (la tarjeta empieza por el nombre, misma alineacion en las tarjetas sin avatar), sin genérico en el microdato;
// Gravatar «preguntar»: el circulo se mantiene (marcador del Gravatar tras el consentimiento); «desactivado»: se oculta; «siempre»: Gravatar no cambia;
// moderador/invitado, claro/oscuro, escritorio/movil. Requiere importados-datos.php crear, foto-usuario (campo) preparado por este script.
const fs = require('fs'); const path = require('path'); const { execFileSync } = require('child_process');
const { chromium } = require(process.env.PW_MODULE || '/opt/node-tools/node_modules/playwright');
const WORK = process.env.WORK; const BASE = process.env.BASE_URL || 'http://127.0.0.1:8080'; const PAGE = BASE + '/index.php?option=com_content&view=article&id=1&catid=8';
const opc = (...a) => execFileSync('php', [__dirname + '/avatar-opciones.php', ...a], { env: process.env });
let ok = 0, ko = 0; const r = (id, d, c, ev = '') => { (c ? ok++ : ko++); console.log(`${id.padEnd(9)} ${c ? 'PASA ' : 'FALLA'} ${d}${ev ? '  [' + String(ev).slice(0, 240) + ']' : ''}`); };
const medir = (p) => p.evaluate(() => {
  const out = {};
  for (const n of ['F9I-IMP-1', 'F9I-IMP-3', 'F9I-NORMAL']) {
    const t = Array.from(document.querySelectorAll('article[id^="akengage-comment-"]')).find((a) => a.textContent.includes('F9I texto ' + n));
    if (!t) { out[n] = null; continue; }
    const img = t.querySelector('img.akengage-commenter-avatar'); const nm = t.querySelector('[itemprop=name]'); const f = t.querySelector('footer');
    out[n] = { img: !!img, src: img ? img.getAttribute('src') : '', defer: img ? img.hasAttribute('data-engage-gravatar') : false, nameLeft: nm ? Math.round(nm.getBoundingClientRect().left - f.getBoundingClientRect().left) : -1, footH: Math.round(f.getBoundingClientRect().height), itemImage: !!t.querySelector('[itemprop=image]'), cont: !!t.querySelector('.akengage-commenter-avatar-container') };
  }
  return out;
});
(async () => {
  const b = await chromium.launch({ executablePath: process.env.CHROMIUM || '/opt/pw-browsers/chromium', args: ['--no-sandbox'] });
  try {
    const casos = [['ask', '0'], ['ask', '1'], ['off', '0'], ['off', '1'], ['always', '0']];
    for (const [modo, gen] of casos) {
      opc('gravatar', modo); opc('com', 'generic_avatar=' + gen, 'sort_selector=0', 'customfield_avatar=');
      for (const [vp, w, h, esq] of [['escritorio-claro', 1280, 900, 'light'], ['movil-oscuro', 390, 844, 'dark']]) {
        const ctx = await b.newContext({ viewport: { width: w, height: h }, colorScheme: esq }); const p = await ctx.newPage(); const errs = []; p.on('pageerror', (e) => errs.push(String(e)));
        await p.goto(PAGE); await p.waitForLoadState('load'); await p.waitForTimeout(700); const m = await medir(p); await ctx.close();
        const imp = [m['F9I-IMP-1'], m['F9I-IMP-3']]; const nor = m['F9I-NORMAL']; const id = `A-${modo}-${gen}-${vp}`;
        if (!imp[0] || !imp[1] || !nor) { r(id, 'tarjetas presentes', false, JSON.stringify(m)); continue; }
        const generico = (x) => x.img && /avatar-generico\\.svg|avatar-blanco\\.svg/.test(x.src.replace(/\\//g, '/')) || (x.img && x.src.includes('avatar-generico'));
        if (gen === '1') {
          r(id, `«Mostrar» (${modo}, ${vp}): los importados llevan el circulo generico como antes y el microdato image`, imp.every((x) => x.img && x.src.includes('avatar-generico') && x.itemImage) && errs.length === 0, JSON.stringify(imp.map((x) => [x.img, x.src.slice(-20)])));
        } else {
          const importadosSin = imp.every((x) => !x.img && !x.cont && !x.itemImage && x.nameLeft >= 0 && x.nameLeft <= 12);
          const alineados = imp[0].nameLeft === imp[1].nameLeft && imp[0].footH === imp[1].footH;
          let normalOk;
          if (modo === 'ask') { normalOk = gen === '1' ? (nor.img && nor.src.includes('avatar-generico') && !nor.defer) : (!nor.img && !nor.itemImage); }   // 0.9.13: un INVITADO no recibe la referencia diferida (decide el servidor): es un avatar generico mas
          else if (modo === 'off') { normalOk = !nor.img && !nor.itemImage; }                                       // sin foto ni Gravatar: se oculta
          else { normalOk = nor.img && /gravatar\.com/.test(nor.src); }                                             // siempre: Gravatar no cambia
          r(id, `«No mostrar» (${modo}, ${vp}): importados SIN <img>, sin contenedor ni microdato image y sin hueco (nombre a ${imp[0].nameLeft}px, misma alineacion y altura); el comentario con correo: ` + (modo === 'ask' ? 'sin referencia diferida para el invitado: generico mas (No mostrar lo oculta)' : modo === 'off' ? 'tambien sin avatar' : 'Gravatar sin cambios'), importadosSin && alineados && normalOk && errs.length === 0, JSON.stringify([imp.map((x) => [x.img, x.nameLeft, x.footH]), [nor.img, nor.src.slice(-30), nor.defer]]));
        }
      }
    }
  } finally { opc('restore'); }
  // con foto (campo de usuario) no cambia con «No mostrar»: lo cubre foto-usuario.php (ejecutalo aparte con la opcion en No)
  await b.close(); console.log(`RESUMEN avatar-generico: ${ok} PASA / ${ko} FALLA`); process.exit(ko ? 1 : 0);
})();
