/** Mide el contraste WCAG de todo el contenido de #eg-admin DENTRO de la pagina (se pasa a page.evaluate). Compartido por panel-navegador.js y ajustes-navegador.js. */
module.exports = function medirEnPagina() {
    const parse = c => { let m = c.match(/color\(srgb ([^)]+)\)/); if (m) { const q = m[1].split(/[ \/]+/).filter(Boolean).map(Number); return {r: q[0] * 255, g: q[1] * 255, b: q[2] * 255, a: q.length > 3 ? q[3] : 1}; } m = c.match(/rgba?\(([^)]+)\)/); if (!m) return null; const p = m[1].split(/[ ,\/]+/).filter(Boolean).map(Number); return {r: p[0], g: p[1], b: p[2], a: p.length > 3 ? p[3] : 1}; };
    const over = (f, b) => ({r: f.r * f.a + b.r * (1 - f.a), g: f.g * f.a + b.g * (1 - f.a), b: f.b * f.a + b.b * (1 - f.a), a: 1});
    const lum = c => { const f = v => { v /= 255; return v <= .03928 ? v / 12.92 : Math.pow((v + .055) / 1.055, 2.4); }; return .2126 * f(c.r) + .7152 * f(c.g) + .0722 * f(c.b); };
    const ratio = (a, b) => { const x = lum(a), y = lum(b); return (Math.max(x, y) + .05) / (Math.min(x, y) + .05); };
    const fondo = el => {
        const capas = [];
        for (let e = el; e; e = e.parentElement) {
            const c = parse(getComputedStyle(e).backgroundColor);
            if (c && c.a > 0) { capas.push(c); if (c.a >= 1) break; }
        }
        let b = {r: 255, g: 255, b: 255, a: 1};
        for (let i = capas.length - 1; i >= 0; i--) b = over(capas[i], b);
        return b;
    };
    const visible = el => { const s = getComputedStyle(el); if (s.display === 'none' || s.visibility === 'hidden' || +s.opacity === 0) return false; const rc = el.getClientRects(); if (!(rc.length > 0 && rc[0].width > 0 && rc[0].height > 0)) return false; for (let e = el; e; e = e.parentElement) { if (e.hasAttribute && e.hasAttribute('hidden')) return false; if (e.tagName === 'DETAILS' && !e.open && el.tagName !== 'SUMMARY' && !el.closest('summary')) return false; } return true; };
    const root = document.getElementById('eg-admin');
    const items = [];
    const walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT);
    for (let n; (n = walker.nextNode());) {
        const t = n.textContent.trim(); if (!t) continue;
        const el = n.parentElement; if (!el || el.closest('.eg-vh, svg, script, style, .eg-avatar')) continue;
        if (!visible(el)) continue;
        const fg = parse(getComputedStyle(el).color); const bg = fondo(el);
        const f2 = over(fg, bg);
        items.push({tipo: 'texto', t: t.slice(0, 40), cls: (el.className && el.className.baseVal === undefined ? el.className : '').toString().slice(0, 40), ratio: Math.round(ratio(f2, bg) * 100) / 100, min: 4.5});
    }
    // iconos (svg.eg-ic) con currentColor sobre su fondo efectivo: 3:1; los de cuadrados con degradado se miden aparte
    root.querySelectorAll('svg.eg-ic').forEach(sv => {
        if (!visible(sv)) return;
        if (sv.closest('.eg-icobox, .eg-logo, .eg-dialog__icon, .eg-avatar')) return;
        const fg = parse(getComputedStyle(sv).color); const bg = fondo(sv);
        items.push({tipo: 'icono', t: sv.closest('button,a,span,h2,p') ? (sv.closest('button,a,span,h2,p').className || '').toString().slice(0, 30) : 'svg', ratio: Math.round(ratio(over(fg, bg), bg) * 100) / 100, min: 3});
    });
    // barras del grafico contra la tarjeta
    const bar = root.querySelector('.eg-chart__bar');
    if (bar) { const f = parse(getComputedStyle(bar).fill), bg = fondo(bar.closest('.eg-card')); items.push({tipo: 'grafico', t: 'barra', ratio: Math.round(ratio(f, bg) * 100) / 100, min: 3}); }
    const hov = root.querySelector('.eg-chart__grid--base');
    if (hov) { const f = parse(getComputedStyle(hov).stroke), bg = fondo(hov.closest('.eg-card')); items.push({tipo: 'grafico', t: 'linea base', ratio: Math.round(ratio(f, bg) * 100) / 100, min: 3}); }
    // glifos blancos sobre los DOS extremos de cada degradado (peor caso)
    const grad = [];
    root.querySelectorAll('.eg-icobox, .eg-logo, .eg-avatar').forEach(bx => {
        if (!visible(bx)) return;
        const bi = getComputedStyle(bx).backgroundImage; const cols = (bi.match(/rgba?\([^)]+\)|color\(srgb [^)]+\)/g) || []).map(parse);
        cols.forEach((c, i) => grad.push({tipo: 'degradado', t: (bx.className || '').toString().slice(0, 30) + (i ? ' fin' : ' inicio'), ratio: Math.round(ratio({r: 255, g: 255, b: 255, a: 1}, c) * 100) / 100, min: bx.classList.contains('eg-avatar') ? 4.5 : 3}));
    });
    items.push(...grad);
    // bordes de controles interactivos con aspecto de control (botones con borde): borde contra la tarjeta; el glifo ya se mide arriba

    // 0.6.25: controles de la pantalla de opciones (no texto): borde de campos y segmentados, pista del interruptor, tarjetas de tema
    root.querySelectorAll('.eg-input, .eg-seg, .eg-themecard').forEach(el => {
        if (!visible(el)) return;
        const bw = parseFloat(getComputedStyle(el).borderTopWidth); if (!bw) return;
        const bc = parse(getComputedStyle(el).borderTopColor); const card = el.closest('.eg-card') || root;
        // tarjeta de tema seleccionada: borde de acento; no seleccionada: borde decorativo (el nombre y la miniatura la identifican), se mide solo la seleccionada
        if (el.classList.contains('eg-themecard') && el.getAttribute('aria-checked') !== 'true') return;
        items.push({tipo: 'borde-control', t: (el.className || '').toString().slice(0, 24), ratio: Math.round(ratio(over(bc, fondo(card)), fondo(card)) * 100) / 100, min: 3});
    });
    root.querySelectorAll('.eg-switch').forEach(sw => {
        if (!visible(sw)) return;
        const card = sw.closest('.eg-card');
        const track = parse(getComputedStyle(sw).backgroundColor); const knob = parse(getComputedStyle(sw.querySelector('.eg-switch__knob')).backgroundColor);
        items.push({tipo: 'interruptor-pista', t: sw.getAttribute('aria-checked'), ratio: Math.round(ratio(track, fondo(card)) * 100) / 100, min: 3});
        items.push({tipo: 'interruptor-bola', t: sw.getAttribute('aria-checked'), ratio: Math.round(ratio(knob, track) * 100) / 100, min: 3});
    });
    const ovf = root.scrollWidth - root.clientWidth;
    return {items, overflow: ovf, tema: document.documentElement.getAttribute('data-eg-theme'), pref: document.documentElement.getAttribute('data-eg-pref')};
};
