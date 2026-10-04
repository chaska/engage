/**
 * 0.6.19: prueba del gravatar.js REAL (se carga tal cual en un contexto de `vm`, sin tocarlo) con el origen de
 * consentimiento "jbcookies". DOM minimo simulado: cookie, imagenes, eventos. Sale con 1 si algo falla.
 * Uso: node tests/24-gravatar-jbcookies.js   (lo ejecuta tests/24-gravatar-jbcookies.php)
 */
const fs = require('fs');
const vm = require('vm');
const path = require('path');

const SRC = fs.readFileSync(process.env.GRAVATAR_JS || path.join(__dirname, '..', 'src/component/media/js/gravatar.js'), 'utf8');
const HASH = 'a'.repeat(64);
const GURL = `https://www.gravatar.com/avatar/${HASH}?s=48&r=g&d=mp`;
const LOCAL = '/media/com_engage/images/avatar-generico.svg';

let ok = 0, ko = 0;
function t(cond, msg) { if (cond) { ok++; console.log('  OK    ' + msg); } else { ko++; console.log('  FALLO ' + msg); } }

class FakeImg {
    constructor(attrs) { this.a = Object.assign({src: LOCAL, 'data-engage-gravatar': GURL}, attrs); }
    getAttribute(n) { return Object.prototype.hasOwnProperty.call(this.a, n) ? this.a[n] : null; }
    setAttribute(n, v) { this.a[n] = String(v); }
    hasAttribute(n) { return Object.prototype.hasOwnProperty.call(this.a, n); }
    removeAttribute(n) { delete this.a[n]; }
    closest() { return null; }
}

/** Crea una pagina con 2 avatares y carga gravatar.js. opts.attrs: atributos de las imagenes; opts.imgs: imagenes propias. */
function pagina(cookie, opts = {}) {
    const attrs = opts.attrs || {'data-engage-gravatar-source': 'jbcookies', 'data-engage-gravatar-group': 'terceros', 'data-engage-gravatar-notice': '0'};
    const imgs = opts.imgs || [new FakeImg(attrs), new FakeImg(attrs)];
    const docL = {}, winL = {};
    const store = Object.assign({}, opts.storage || {});
    const storageCalls = [];
    const doc = {
        readyState: 'complete', visibilityState: 'visible', cookie: cookie,
        querySelectorAll: (s) => (s.indexOf('img[') === 0 ? imgs : []),
        getElementById: () => null,
        addEventListener: (n, f) => { (docL[n] = docL[n] || []).push(f); },
        dispatchEvent: (e) => { (docL[e.type] || []).slice().forEach((f) => f(e)); return true; },
    };
    class CE { constructor(type, init) { this.type = type; this.detail = init && init.detail; } }
    const win = {
        document: doc, CustomEvent: CE,
        addEventListener: (n, f) => { (winL[n] = winL[n] || []).push(f); },
        localStorage: {
            getItem: (k) => { storageCalls.push('get'); return Object.prototype.hasOwnProperty.call(store, k) ? store[k] : null; },
            setItem: (k, v) => { storageCalls.push('set'); store[k] = String(v); },
            removeItem: (k) => { storageCalls.push('remove'); delete store[k]; },
        },
    };
    const ctx = vm.createContext({window: win, document: doc, CustomEvent: CE, setTimeout, console});
    vm.runInContext(SRC, ctx, {filename: 'gravatar.js'});
    const changes = [];
    doc.addEventListener('engage:gravatar-changed', (e) => changes.push(e.detail.granted));
    return {
        doc, win, imgs, api: win.AkeebaEngageGravatar, storageCalls, store, changes,
        srcs: () => imgs.map((i) => i.getAttribute('src')),
        todasGravatar: () => imgs.every((i) => i.getAttribute('src') === GURL),
        todasLocales: () => imgs.every((i) => i.getAttribute('src') === LOCAL),
        emitDoc: (type, detail) => doc.dispatchEvent(new CE(type, {detail})),
        emitWin: (type) => (winL[type] || []).slice().forEach((f) => f({type})),
    };
}
const enc = (o) => 'jbcookies=' + encodeURIComponent(typeof o === 'string' ? o : JSON.stringify(o));
const ck = (status, preferences) => enc({status, preferences});

// ---------- 1. Tabla de la regla de concesion (la pagina se carga con la cookie ya presente) ----------
// [descripcion, cookie, grupo, esperado]
const T = 'terceros';
const largo = '{"status":"allow","x":"' + 'a'.repeat(5000) + '"}';
const tabla = [
    // Concede
    ['allow (JSON)', ck('allow', {necessary: 1, analytics: 1, marketing: 1}), T, true],
    ['allow sin grupo configurado', ck('allow', {}), '', true],
    ['allow con preferences basura (array)', ck('allow', [0]), T, true],
    ['valor legado de texto "allow"', 'jbcookies=allow', T, true],
    ['custom con el grupo = 1', ck('custom', {necessary: 1, terceros: 1}), T, true],
    ['custom con el grupo = true', ck('custom', {terceros: true}), T, true],
    ['cookie entre otras', 'a=1; ' + ck('custom', {terceros: 1}) + '; b=2', T, true],
    ['dos cookies jbcookies, ambas allow', ck('allow', {}) + '; ' + ck('allow', {}), T, true],
    // No concede
    ['sin cookie', '', T, false],
    ['otras cookies pero no jbcookies', 'a=1; b=2', T, false],
    ['cookie jbcookies vacia', 'jbcookies=', T, false],
    ['nombre parecido: xjbcookies', 'x' + ck('allow', {}), T, false],
    ['nombre parecido: jbcookies2', 'jbcookies2=allow', T, false],
    ['deny (JSON)', ck('deny', {terceros: 1}), T, false],
    ['valor legado "deny"', 'jbcookies=deny', T, false],
    ['valor legado "custom" (sin preferencias)', 'jbcookies=custom', T, false],
    ['custom con el grupo = 0', ck('custom', {terceros: 0}), T, false],
    ['custom con el grupo = false', ck('custom', {terceros: false}), T, false],
    ['custom con el grupo = "1" (cadena)', ck('custom', {terceros: '1'}), T, false],
    ['custom con el grupo = "true" (cadena)', ck('custom', {terceros: 'true'}), T, false],
    ['custom con el grupo = 2', ck('custom', {terceros: 2}), T, false],
    ['custom con el grupo = [1]', ck('custom', {terceros: [1]}), T, false],
    ['custom con el grupo = null', ck('custom', {terceros: null}), T, false],
    ['custom sin ese grupo en preferences', ck('custom', {marketing: 1, analytics: 1}), T, false],
    ['custom con todo a 1 pero grupo NO configurado', ck('custom', {terceros: 1, marketing: 1}), '', false],
    ['custom con preferences como array', ck('custom', [1, 1, 1]), T, false],
    ['custom con preferences = "terceros"', ck('custom', 'terceros'), T, false],
    ['custom con preferences = null', ck('custom', null), T, false],
    ['custom sin preferences', enc({status: 'custom'}), T, false],
    ['status raro', ck('Allow', {terceros: 1}), T, false],
    ['status "ALLOW"', ck('ALLOW', {}), T, false],
    ['status "allow " con espacio', ck('allow ', {}), T, false],
    ['status en array', enc({status: ['allow']}), T, false],
    ['status numerico', enc({status: 1}), T, false],
    ['status true', enc({status: true}), T, false],
    ['sin status', enc({preferences: {terceros: 1}}), T, false],
    ['status inherited via __proto__', enc('{"__proto__":{"status":"allow"}}'), T, false],
    ['JSON invalido', enc('{"status":"allow"'), T, false],
    ['JSON null', enc('null'), T, false],
    ['JSON array', enc('["allow"]'), T, false],
    ['JSON cadena "allow" (con comillas)', enc('"allow"'), T, false],
    ['JSON numero', enc('1'), T, false],
    ['texto cualquiera', 'jbcookies=hola', T, false],
    ['percent-encoding roto', 'jbcookies=%E0%A4%A', T, false],
    ['prototipo: __proto__ en el JSON no concede', enc('{"status":"custom","__proto__":{"terceros":1}}'), T, false],
    ['prototipo: preferences.__proto__ con grupo terceros', enc('{"status":"custom","preferences":{"__proto__":{"terceros":1}}}'), T, false],
    ['grupo __proto__ configurado (aunque venga a 1)', enc('{"status":"custom","preferences":{"__proto__":1}}'), '__proto__', false],
    ['grupo constructor', ck('custom', {constructor: 1}), 'constructor', false],
    ['grupo necessary (siempre es 1, no prueba nada)', ck('custom', {necessary: 1}), 'necessary', false],
    ['grupo con mayusculas', ck('custom', {Terceros: 1}), 'Terceros', false],
    ['grupo con espacio', ck('custom', {'a b': 1}), 'a b', false],
    ['grupo con comillas/HTML', ck('custom', {'a"><b': 1}), 'a"><b', false],
    ['grupo con punto', ck('custom', {'a.b': 1}), 'a.b', false],
    ['grupo de 65 caracteres', ck('custom', {['a'.repeat(65)]: 1}), 'a'.repeat(65), false],
    ['grupo vacio con custom', ck('custom', {'': 1}), '', false],
    ['valor demasiado largo (>4096)', enc(largo), T, false],
    ['dos cookies: allow + deny', ck('allow', {}) + '; ' + ck('deny', {}), T, false],
    ['dos cookies: deny + allow', ck('deny', {}) + '; ' + ck('allow', {}), T, false],
    ['dos cookies: allow + basura', ck('allow', {}) + '; jbcookies=%', T, false],
];
for (const [desc, cookie, grupo, esperado] of tabla) {
    const attrs = {'data-engage-gravatar-source': 'jbcookies', 'data-engage-gravatar-group': grupo, 'data-engage-gravatar-notice': '0'};
    const p = pagina(cookie, {attrs});
    const concedido = p.api.isGranted();
    const coherente = concedido ? p.todasGravatar() : p.todasLocales();
    t(concedido === esperado && coherente, `${esperado ? 'CONCEDE' : 'NO concede'}: ${desc}`);
}

// ---------- 2. Comportamiento dinamico ----------
let p = pagina('');
t(p.todasLocales() && !p.api.isGranted(), 'carga sin cookie: avatares locales, no concedido, 0 imagenes de gravatar');
t(p.storageCalls.length === 0, 'modo jbcookies: localStorage no se lee ni se escribe al cargar');
p.doc.cookie = ck('allow', {necessary: 1, analytics: 1, marketing: 1, terceros: 1});
p.emitDoc('jbcookies:update', {status: 'allow', preferences: {}});
t(p.todasGravatar() && p.api.isGranted() && p.changes.join() === 'true', 'jbcookies:update con cookie allow: se cargan las fotos y se emite engage:gravatar-changed {granted:true}');
t(p.imgs.every((i) => i.getAttribute('referrerpolicy') === 'no-referrer'), 'las fotos llevan referrerpolicy=no-referrer');
p.doc.cookie = '';
p.emitWin('focus');
t(p.todasLocales() && !p.api.isGranted() && p.changes.join() === 'true,false', 'cookie borrada ("cambiar mi decision", sin evento) + focus: se retiran las fotos');
p.doc.cookie = ck('allow', {});
p.emitWin('focus'); p.emitWin('pageshow'); p.emitDoc('visibilitychange');
t(p.todasLocales() && !p.api.isGranted(), 'las comprobaciones pasivas (focus, pageshow, visibilitychange) NUNCA conceden');
p.emitDoc('jbcookies:update', {status: 'deny', preferences: {}});
t(p.todasGravatar(), 'jbcookies:update relee la COOKIE (no el detail del evento): cookie allow => concede aunque el detail diga deny');
p.doc.cookie = ck('deny', {terceros: 1});
p.emitDoc('jbcookies:update', {status: 'allow', preferences: {terceros: 1}});
t(p.todasLocales() && !p.api.isGranted(), 'evento jbcookies:update FALSIFICADO con detail allow pero cookie deny: no concede (y retira)');
p.doc.cookie = '';
p.emitDoc('jbcookies:update', {status: 'allow', preferences: {terceros: 1}});
t(p.todasLocales() && !p.api.isGranted(), 'evento falsificado sin cookie: no concede');
t(p.storageCalls.length === 0 && Object.keys(p.store).length === 0, 'en todo el ciclo jbcookies no se uso localStorage');

// click en "cambiar mi decision"
p = pagina(ck('allow', {}));
t(p.todasGravatar(), 'carga con cookie allow: las fotos se cargan en la carga');
p.doc.cookie = '';
await_click: {
    const target = {closest: (s) => (s === '.jb-cookie-decline' ? target : null)};
    p.doc.dispatchEvent({type: 'click', target});
}
setTimeout(() => {
    t(p.todasLocales() && !p.api.isGranted(), 'clic en .jb-cookie-decline (cookie ya borrada por el modulo): se retiran las fotos');
    seguir();
}, 30);

function seguir() {
    // API manual
    let q = pagina(ck('deny', {}));
    q.api.grant();
    t(!q.api.isGranted() && q.todasLocales(), 'grant() manual con cookie deny: NO concede (no contradice al gestor)');
    q.emitDoc('engage:gravatar-consent', {granted: true});
    t(!q.api.isGranted() && q.todasLocales(), 'evento engage:gravatar-consent {granted:true} con cookie deny: NO concede');
    q = pagina('');
    q.api.grant();
    t(!q.api.isGranted(), 'grant() manual sin cookie: NO concede');
    q = pagina(ck('allow', {}));
    t(q.api.isGranted(), 'precondicion: allow concede');
    q.api.revoke();
    t(!q.api.isGranted() && q.todasLocales() && q.changes.join() === 'false', 'revoke() manual siempre retira las fotos y emite el evento');
    q.emitWin('focus');
    t(!q.api.isGranted(), 'tras revoke() manual, focus no vuelve a conceder');
    q.api.grant();
    t(q.api.isGranted() && q.todasGravatar(), 'grant() manual con cookie allow: re-lee la cookie y concede');
    q.emitDoc('engage:gravatar-consent', {granted: false});
    t(!q.api.isGranted() && q.todasLocales(), 'evento engage:gravatar-consent {granted:false}: retira');
    q.emitDoc('engage:gravatar-consent', {granted: 'si'}); q.emitDoc('engage:gravatar-consent', null);
    t(!q.api.isGranted(), 'eventos engage:gravatar-consent con detail invalido se ignoran');
    // lista blanca
    q = pagina(ck('allow', {}), {imgs: [new FakeImg({'data-engage-gravatar-source': 'jbcookies', 'data-engage-gravatar-group': '', 'data-engage-gravatar': 'https://www.gravatar.com.evil.example/avatar/' + HASH})]});
    t(q.imgs[0].getAttribute('src') === LOCAL, 'lista blanca: una URL manipulada en data-engage-gravatar nunca se asigna a src (tambien con consentimiento)');
    // mezcla de imagenes: una sin marcador => modo engage (localStorage), la cookie de JBCookies se ignora
    q = pagina(ck('allow', {}), {imgs: [new FakeImg({'data-engage-gravatar-source': 'jbcookies', 'data-engage-gravatar-notice': '0'}), new FakeImg({'data-engage-gravatar-notice': '0'})]});
    t(q.todasLocales() && !q.api.isGranted(), 'una imagen sin marcador jbcookies deja la pagina en modo engage: la cookie allow se ignora');
    // Regresion modo engage
    q = pagina(ck('allow', {}), {attrs: {'data-engage-gravatar-notice': '0'}});
    t(q.todasLocales() && !q.api.isGranted(), 'REGRESION modo engage: la cookie jbcookies=allow se ignora');
    q = pagina('', {attrs: {'data-engage-gravatar-notice': '0'}, storage: {engage_gravatar_consent: '1'}});
    t(q.api.isGranted() && q.todasGravatar(), 'REGRESION modo engage: engage_gravatar_consent=1 en localStorage sigue concediendo');
    q.emitDoc('jbcookies:update', {status: 'deny', preferences: {}});
    t(q.api.isGranted(), 'REGRESION modo engage: jbcookies:update se ignora');
    q.api.revoke();
    t(!q.api.isGranted() && q.todasLocales() && !('engage_gravatar_consent' in q.store), 'REGRESION modo engage: revoke() borra la clave de localStorage como antes');
    q.api.grant();
    t(q.api.isGranted() && q.store.engage_gravatar_consent === '1', 'REGRESION modo engage: grant() guarda la clave como antes');
    // atributo con valor distinto de "jbcookies"
    q = pagina(ck('allow', {}), {attrs: {'data-engage-gravatar-source': 'JBCookies', 'data-engage-gravatar-notice': '0'}});
    t(q.todasLocales(), 'data-engage-gravatar-source distinto de "jbcookies" exacto => modo engage');
    // sin imagenes
    q = pagina(ck('allow', {}), {imgs: []});
    t(!q.api.isGranted(), 'sin avatares: nada que hacer y no concedido');

    console.log(`  -> ${ok} correctas, ${ko} fallidas`);
    process.exit(ko ? 1 : 0);
}
