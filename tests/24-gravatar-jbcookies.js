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

// 0.8.3: el atributo ya no lleva la URL de Gravatar sino una REFERENCIA opaca `<id>.<tamano>.<token>`; la URL real la da el servidor tras el consentimiento
const REF = '7.48.' + 'b'.repeat(32);
class FakeImg {
    constructor(attrs) { this.a = Object.assign({src: LOCAL, 'data-engage-gravatar': REF}, attrs); }
    getAttribute(n) { return Object.prototype.hasOwnProperty.call(this.a, n) ? this.a[n] : null; }
    setAttribute(n, v) { this.a[n] = String(v); }
    hasAttribute(n) { return Object.prototype.hasOwnProperty.call(this.a, n); }
    removeAttribute(n) { delete this.a[n]; }
    closest() { return null; }
}

/** Promesa SINCRONA minima (solo then/catch): permite probar gravatar.js sin esperar a microtareas. */
class SP {
    constructor(v, err) { this.v = v; this.err = err; }
    static of(x) { return x instanceof SP ? x : new SP(x); }
    static rej(e) { const p = new SP(undefined, e); p.failed = true; return p; }
    then(f, g) {
        try {
            if (this.failed) { return g ? SP.of(g(this.err)) : this; }
            return f ? SP.of(f(this.v)) : this;
        } catch (e) { return SP.rej(e); }
    }
    catch(g) { return this.then(null, g); }
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
    // 0.8.3: servidor simulado (avatar.token y avatar.resolve). opts.resolveUrl cambia lo que devuelve; opts.failFetch simula un error de red.
    const fetchCalls = [];
    let serverConsent = !!opts.serverConsent;
    win.Joomla = {getOptions: (k) => (k === 'akeeba.Engage.Gravatar.endpoint' ? (opts.endpoint !== undefined ? opts.endpoint : '/index.php/component/engage') : null)};
    win.fetch = (url, init) => {
        fetchCalls.push({url, init});
        if (opts.failFetch) { return SP.rej(new Error('red')); }
        if (String(url).indexOf('task=avatar.token') !== -1) { return SP.of({ok: true, json: () => SP.of({ok: true, token: 'c'.repeat(32)})}); }
        // 0.9.13: el servidor decide: el consentimiento se guarda con avatar.consent y avatar.resolve lo exige (403 consent). opts.serverConsent: ya guardado.
        if (String(url).indexOf('task=avatar.consent') !== -1) {
            if (opts.failConsent) { return SP.of({ok: false, json: () => SP.of({ok: false, error: 'login'})}); }
            serverConsent = init.body.get('grant') === '1';
            return SP.of({ok: true, json: () => SP.of({ok: true, granted: serverConsent})});
        }
        if (!serverConsent) { return SP.of({ok: false, json: () => SP.of({ok: false, error: 'consent'})}); }
        const c = init && init.body ? init.body.get('c') : '';
        const urls = {};
        String(c).split(',').forEach((r) => { const q = r.split('.'); urls[q[0] + '.' + q[1]] = opts.resolveUrl || GURL; });
        return SP.of({ok: true, json: () => SP.of({ok: true, urls})});
    };
    win.URLSearchParams = URLSearchParams;
    const ctx = vm.createContext({window: win, document: doc, CustomEvent: CE, setTimeout, console, URLSearchParams, Object, Array, JSON});
    vm.runInContext(SRC, ctx, {filename: 'gravatar.js'});
    const changes = [];
    doc.addEventListener('engage:gravatar-changed', (e) => changes.push(e.detail.granted));
    return {
        doc, win, imgs, api: win.AkeebaEngageGravatar, storageCalls, store, changes, fetchCalls,
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
    q = pagina(ck('allow', {}), {resolveUrl: 'https://www.gravatar.com.evil.example/avatar/' + HASH, imgs: [new FakeImg({'data-engage-gravatar-source': 'jbcookies', 'data-engage-gravatar-group': ''})]});
    t(q.imgs[0].getAttribute('src') === LOCAL, 'lista blanca: una URL manipulada que devolviera el servidor nunca se asigna a src (tambien con consentimiento)');
    q = pagina(ck('allow', {}), {imgs: [new FakeImg({'data-engage-gravatar-source': 'jbcookies', 'data-engage-gravatar-group': '', 'data-engage-gravatar': 'https://www.gravatar.com/avatar/' + HASH})]});
    t(q.imgs[0].getAttribute('src') === LOCAL && !q.fetchCalls.some((c) => /avatar\.resolve/.test(c.url)), 'una URL (no una referencia) en data-engage-gravatar se ignora: ni se asigna ni se pide nada');
    // 0.8.3: privacidad y peticiones
    q = pagina('', {attrs: {'data-engage-gravatar-notice': '0'}});
    t(q.fetchCalls.length === 0 && q.todasLocales(), '0.8.3: sin consentimiento NO se hace ninguna peticion (ni al servidor ni a Gravatar) y no hay URL de Gravatar en la pagina');
    q.api.grant();
    t(q.fetchCalls.length === 4 && /task=avatar\.token$/.test(q.fetchCalls[0].url) && /task=avatar\.consent$/.test(q.fetchCalls[1].url) && q.fetchCalls[1].init.method === 'POST' && q.fetchCalls[1].init.body.get('grant') === '1' && /task=avatar\.token$/.test(q.fetchCalls[2].url) && /task=avatar\.resolve$/.test(q.fetchCalls[3].url) && q.fetchCalls[3].init.method === 'POST', '0.9.13: tras aceptar: token + consentimiento (POST, se guarda en el servidor) y despues token + resolucion (POST), todo al propio sitio');
    t(q.fetchCalls[3].init.body.get('c') === REF && q.fetchCalls[3].init.body.get('c'.repeat(32)) === '1' && q.fetchCalls[3].init.credentials === 'same-origin', '0.8.3: se envia la referencia (una vez, aunque haya dos imagenes iguales) y el token CSRF');
    t(q.todasGravatar(), '0.8.3: la foto se carga con la URL que da el servidor');
    q.api.revoke(); q.api.grant();
    t(q.todasGravatar() && q.fetchCalls.filter((c) => /avatar\.resolve/.test(c.url)).length === 1, '0.8.3: revocar y volver a conceder reutiliza lo ya resuelto (una sola resolucion)');
    for (const ep of ['https://evil.example/x', '//evil.example/x', '', 'javascript:alert(1)', '/a b']) {
        q = pagina('', {endpoint: ep, attrs: {'data-engage-gravatar-notice': '0'}}); q.api.grant();
        t(q.fetchCalls.length === 0 && q.todasLocales(), '0.8.3: endpoint no permitido ' + JSON.stringify(ep) + ': no se pide nada');
    }
    q = pagina('', {failFetch: true, attrs: {'data-engage-gravatar-notice': '0'}}); q.api.grant();
    t(!q.api.isGranted() && q.todasLocales(), '0.9.13: si la red falla no se concede (el servidor no guardo el consentimiento) y se queda el avatar local');
    q = pagina('', {failConsent: true, attrs: {'data-engage-gravatar-notice': '0'}}); q.api.grant();
    t(!q.api.isGranted() && q.todasLocales() && !('engage_gravatar_consent' in q.store), '0.9.13: si el servidor rechaza el consentimiento (sin sesion) no se concede ni se guarda nada en el navegador');
    q = pagina('', {imgs: [new FakeImg({'data-engage-gravatar': 'no-es-una-referencia', 'data-engage-gravatar-notice': '0'}), new FakeImg({'data-engage-gravatar': '7.48.' + 'B'.repeat(32), 'data-engage-gravatar-notice': '0'})]}); q.api.grant();
    t(!q.fetchCalls.some((c) => /avatar\.resolve/.test(c.url)) && q.todasLocales(), '0.8.3: referencias mal formadas (texto, mayusculas) no se envian');
    // mezcla de imagenes: una sin marcador => modo engage (localStorage), la cookie de JBCookies se ignora
    q = pagina(ck('allow', {}), {imgs: [new FakeImg({'data-engage-gravatar-source': 'jbcookies', 'data-engage-gravatar-notice': '0'}), new FakeImg({'data-engage-gravatar-notice': '0'})]});
    t(q.todasLocales() && !q.api.isGranted(), 'una imagen sin marcador jbcookies deja la pagina en modo engage: la cookie allow se ignora');
    // Regresion modo engage
    q = pagina(ck('allow', {}), {attrs: {'data-engage-gravatar-notice': '0'}});
    t(q.todasLocales() && !q.api.isGranted(), 'REGRESION modo engage: la cookie jbcookies=allow se ignora');
    q = pagina('', {attrs: {'data-engage-gravatar-notice': '0'}, storage: {engage_gravatar_consent: '1'}, serverConsent: true});
    t(q.api.isGranted() && q.todasGravatar(), 'REGRESION modo engage: engage_gravatar_consent=1 en localStorage concede si el servidor tiene el consentimiento');
    let q0 = pagina('', {attrs: {'data-engage-gravatar-notice': '0'}, storage: {engage_gravatar_consent: '1'}});
    t(!q0.api.isGranted() && q0.todasLocales() && !('engage_gravatar_consent' in q0.store), '0.9.13: el navegador recuerda un «si» que el servidor no tiene: vuelve a preguntar (no se muestran fotos)');
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
