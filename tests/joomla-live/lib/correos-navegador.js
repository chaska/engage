// 0.9.0: renderiza en Chromium el HTML de los correos CAPTURADOS (lib/correos-html.php los deja en $WORK/correos-html) en claro y oscuro, escritorio y movil.
// Comprueba: ninguna peticion de red (el correo no carga nada), sin desbordamiento horizontal en movil, ancho de la tarjeta <= 600 px, modo oscuro aplicado
// (fondo oscuro y texto claro), contraste minimo 4.5:1 en los textos visibles, sin errores de JavaScript (no hay). Guarda capturas en $WORK/capturas (OUT_IMG), nunca dentro del repositorio.
const fs = require('fs'); const path = require('path');
const { chromium } = require(process.env.PW_MODULE || 'playwright');
const WORK = process.env.WORK; const OUT = process.env.OUT_IMG || path.join(process.env.WORK || require('os').tmpdir(), 'capturas');
fs.mkdirSync(OUT, { recursive: true });
let ok = 0, ko = 0;
const r = (id, desc, cond, ev = '') => { (cond ? ok++ : ko++); console.log(`${id.padEnd(8)} ${cond ? 'PASA ' : 'FALLA'} ${desc}${ev ? '  [' + String(ev).slice(0, 240) + ']' : ''}`); };
const lum = (rgb) => { const c = rgb.map((v) => { v /= 255; return v <= 0.03928 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4; }); return 0.2126 * c[0] + 0.7152 * c[1] + 0.0722 * c[2]; };
const cr = (a, b) => { const la = lum(a), lb = lum(b); return (Math.max(la, lb) + 0.05) / (Math.min(la, lb) + 0.05); };
(async () => {
  const browser = await chromium.launch({ executablePath: process.env.CHROMIUM || undefined, args: ['--no-sandbox'] });
  const dir = path.join(WORK, 'correos-html');
  const files = fs.readdirSync(dir).filter((f) => f.endsWith('.html'));
  r('N-00', 'Hay correos capturados para renderizar', files.length >= 4, files.join(','));
  for (const f of files) {
    const nombre = f.replace('.html', '');
    for (const esquema of ['light', 'dark']) {
      for (const [vp, dims] of [['escritorio', { width: 1000, height: 900 }], ['movil', { width: 390, height: 844 }]]) {
        const ctx = await browser.newContext({ viewport: dims, colorScheme: esquema, deviceScaleFactor: 1, isMobile: vp === 'movil' });
        const page = await ctx.newPage(); const reqs = []; const errs = [];
        page.on('request', (q) => { if (!q.url().startsWith('data:') && !q.url().startsWith('about:')) reqs.push(q.url()); });
        page.on('pageerror', (e) => errs.push(String(e)));
        await page.setContent(fs.readFileSync(path.join(dir, f), 'utf8'), { waitUntil: 'load' });
        const info = await page.evaluate(() => {
          const card = document.querySelector('.eg-card'); const wrap = document.querySelector('.eg-wrap');
          const bg = (el) => { while (el) { const c = getComputedStyle(el).backgroundColor; if (c && c !== 'rgba(0, 0, 0, 0)' && c !== 'transparent') return c; el = el.parentElement; } return 'rgb(255,255,255)'; };
          const rgb = (s) => (s.match(/[\d.]+/g) || []).slice(0, 3).map(Number);
          const bad = []; let n = 0;
          document.querySelectorAll('p,span,div,li,a,td,strong,b').forEach((el) => {
            const own = Array.from(el.childNodes).some((c) => c.nodeType === 3 && c.textContent.trim());
            if (!own) return; n++;
            const fg = rgb(getComputedStyle(el).color); const b = rgb(bg(el));
            bad.push({ fg, b, t: el.textContent.trim().slice(0, 30) });
          });
          return { sw: document.documentElement.scrollWidth, cw: document.documentElement.clientWidth, wrapW: wrap ? wrap.getBoundingClientRect().width : 0, cardBg: card ? bg(card) : '', cardFg: card ? getComputedStyle(card).color : '', pageBg: bg(document.body), items: bad, n };
        });
        const minCr = Math.min(...info.items.map((i) => cr(i.fg, i.b)));
        const peor = info.items.map((i) => ({ c: cr(i.fg, i.b), t: i.t })).sort((a, b) => a.c - b.c)[0];
        const id = `${nombre}-${esquema}-${vp}`;
        r(id, `${nombre} ${esquema} ${vp}: sin peticiones de red, sin errores JS, sin desbordamiento horizontal, tarjeta <= 600 px, contraste >= 4.5`,
          reqs.length === 0 && errs.length === 0 && info.sw <= info.cw && info.wrapW <= 600.5 && minCr >= 4.5, `reqs=${reqs.length} errs=${errs.length} sw=${info.sw}/${info.cw} wrap=${Math.round(info.wrapW)} minContraste=${minCr.toFixed(2)} (${peor && peor.t})`);
        if (esquema === 'dark') { const [r1, g1, b1] = (info.pageBg.match(/[\d.]+/g) || [255, 255, 255]).map(Number); r(id + '-oscuro', `${nombre} ${vp}: el modo oscuro se aplica (fondo de la pagina oscuro)`, lum([r1, g1, b1]) < 0.1, info.pageBg + ' / tarjeta ' + info.cardBg); }
        else { const [r1, g1, b1] = (info.cardBg.match(/[\d.]+/g) || [0, 0, 0]).map(Number); r(id + '-claro', `${nombre} ${vp}: modo claro con tarjeta clara`, lum([r1, g1, b1]) > 0.8, info.cardBg); }
        if (nombre.startsWith('es-ES') || nombre.startsWith('en-GB')) { await page.screenshot({ path: path.join(OUT, `correo-${nombre}-${esquema === 'light' ? 'claro' : 'oscuro'}-${vp}.png`), fullPage: true }); }
        await ctx.close();
      }
    }
  }
  await browser.close();
  console.log(`RESUMEN navegador-correos: ${ok} PASA / ${ko} FALLA`);
  process.exit(ko ? 1 : 0);
})().catch((e) => { console.error(e); process.exit(2); });
