/**
 * Capturas para docs/img (panel-claro.png, panel-medio.png, panel-oscuro.png y, desde 0.6.25, config-*.png) con datos de ejemplo
 * en espanol y el panel en es-ES. Cada PNG se reduce a paleta de 128 colores (GD) para quedar por debajo de 200 KB.
 * Entorno: BASE_URL, WORK, IDS_FILE, DB_NAME, PW_MODULE, CHROMIUM, DOCS_OUT (por defecto <repo>/docs/img).
 */
const {execFileSync} = require('child_process');
const fs = require('fs');
const path = require('path');
const {chromium} = require(process.env.PW_MODULE || 'playwright');
const BASE = process.env.BASE_URL || 'http://127.0.0.1:8080';
const WORK = process.env.WORK;
const DB = process.env.DB_NAME || 'joomla_test';
const OUT = process.env.DOCS_OUT || path.resolve(__dirname, '../../../docs/img');
const ids = JSON.parse(fs.readFileSync(`${WORK}/${process.env.IDS_FILE || 'ids.json'}`, 'utf8'));
const PASS = 'Aa1!' + fs.readFileSync(`${WORK}/adminpass.txt`, 'utf8').trim();
const sql = s => execFileSync('mysql', ['-uroot', '--default-character-set=utf8mb4', '-N', DB, '-e', s]).toString().trim();
const prevLang = sql("SELECT params FROM jos_extensions WHERE element='com_languages'");
const prevParams = sql("SELECT params FROM jos_extensions WHERE element='com_engage' AND type='component'");
const prevPlug = sql("SELECT extension_id, enabled, IFNULL(params,'') FROM jos_extensions WHERE type='plugin' AND ((folder='engage') OR (folder='content' AND element='engage'))").split('\n').map(l => l.split('\t'));
const S = process.env.SITE_PATH || `${WORK}/${process.env.SITE_DIR || 'site'}`;
const demo = [
    ['Marta Soler', 'Muy buen artículo, me ha servido para configurar el sitio de mi asociación. Gracias.', 0, 1],
    ['Javier Ortega', '¿Podrían explicar cómo se cambia el aviso de cookies? No lo encuentro.', 0, 0],
    ['Lucía Prieto', 'Excelente explicación, claro y directo. Lo recomendaré en el foro.', 1, 1],
    ['Ricardo Gil', 'Gracias por compartirlo, justo lo que buscaba.', 2, 1],
    ['Compra ya!!!', 'Oferta increíble en relojes, pulse aquí', 3, -3],
    ['Elena Cano', 'Una duda sobre los permisos de los editores: ¿se puede limitar a una categoría?', 4, 0],
    ['Pablo Vera', 'Funciona perfectamente en Joomla 6.', 6, 1],
    ['Nuria Camps', 'Me encanta el nuevo diseño del panel.', 8, 1],
    ['Sergio Mora', 'Gracias, ya está resuelto.', 11, 1],
    ['Ana Beltrán', 'Pendiente de revisar mi comentario anterior, por favor.', 13, 1],
    ['Hugo Nieto', 'Muy útil.', 16, 1], ['Iris Roca', 'Genial.', 18, 1], ['Óscar Gil', 'Interesante enfoque.', 22, 1], ['Eva Luna', 'Lo probaré este fin de semana.', 25, 1],
    ['Raúl Pons', 'Gracias por el trabajo.', 27, 1], ['Carla Díaz', 'Todo claro.', 29, 1], ['Mario Sanz', 'Me ha sido de gran ayuda.', 0, 1], ['Rosa Vidal', 'Perfecto.', 1, 1], ['Tomás Ibáñez', 'Gracias.', 2, 1],
    ['Alba Reyes', 'Buen resumen.', 4, 1], ['Dani Ferrer', 'Opino lo mismo.', 4, 1], ['Gloria Peña', 'Muy bien explicado.', 7, 1],
];
function sqlq(s) { return `'${String(s).replace(/\\/g, '\\\\').replace(/'/g, "''")}'`; }
(async () => {
    sql('DELETE FROM jos_engage_comments');
    demo.forEach(([n, t, d, e], i) => sql(`INSERT INTO jos_engage_comments (asset_id,parent_id,body,name,email,ip,user_agent,enabled,created,created_by) VALUES (${i % 4 === 3 ? ids.art_otro_asset : ids.art_publico_asset},NULL,${sqlq('<p>' + t + '</p>')},${sqlq(n)},'demo@example.invalid','203.0.113.7','demo',${e},DATE_SUB(UTC_TIMESTAMP(), INTERVAL ${d * 24 + 1 + (i % 7)} HOUR),0)`));
    sql("UPDATE jos_extensions SET params='{\"default_publish\":\"0\",\"filter_mode\":\"strict\",\"htmlpurifier_configstring\":\"p,b,a[href],i,u,strong,em,ul,ol,li,br,blockquote\",\"captcha\":\"-1\",\"theme\":\"modern\"}' WHERE element='com_engage' AND type='component'");
    sql("UPDATE jos_extensions SET enabled=1 WHERE type='plugin' AND ((folder='engage' AND element IN ('email','gravatar','akismet')) OR (folder='content' AND element='engage'))");
    sql("UPDATE jos_extensions SET params=JSON_SET(IFNULL(NULLIF(params,''),'{}'),'$.key','demo-key') WHERE type='plugin' AND folder='engage' AND element='akismet'");
    sql("UPDATE jos_extensions SET params='{\"administrator\":\"es-ES\",\"site\":\"es-ES\"}' WHERE element='com_languages'");
    execFileSync('sh', ['-c', `rm -rf ${S}/cache/* ${S}/administrator/cache/* 2>/dev/null; true`]);
    const b = await chromium.launch({executablePath: process.env.CHROMIUM || '/opt/pw-browsers/chromium', args: ['--no-sandbox']});
    const ctx = await b.newContext({viewport: {width: 1280, height: 1000}, locale: 'es-ES'});
    const p = await ctx.newPage();
    await p.goto(`${BASE}/administrator/`);
    await p.fill('#mod-login-username', 'admintest'); await p.fill('#mod-login-password', PASS);
    await Promise.all([p.waitForNavigation(), p.press('#mod-login-password', 'Enter')]);
    const tmp = fs.mkdtempSync('/tmp/capdocs-');
    const grab = async (name, url, tema, alto) => {
        await p.goto(`${BASE}/administrator/${url}`, {waitUntil: 'networkidle'});
        await p.evaluate(t => { try { localStorage.setItem('eg-admin-theme', t); } catch (e) {} }, tema);
        await p.reload({waitUntil: 'networkidle'});
        // quita el aviso de estadisticas de Joomla (no es de Engage) para que la captura muestre solo el componente
        await p.evaluate(() => { document.querySelectorAll('.alert-message, joomla-alert, #system-message-container, .com-cpanel-stats, [data-bs-theme] .sticky-top ~ * .alert').forEach(() => {}); const m = document.getElementById('system-message-container'); if (m) m.remove(); });
        const bb = await (await p.$('#eg-admin')).boundingBox();
        const raw = `${tmp}/${name}`;
        await p.screenshot({path: raw, fullPage: true, clip: {x: bb.x, y: bb.y, width: bb.width, height: Math.min(alto, bb.height)}});
        execFileSync('php', [path.join(__dirname, 'optimizar-png.php'), raw, path.join(OUT, name)], {stdio: 'inherit'});
    };
    fs.mkdirSync(OUT, {recursive: true});
    const panel = process.env.DOCS_ONLY !== 'config';
    if (panel) {
        await grab('panel-claro.png', 'index.php?option=com_engage', 'light', 1000);
        await grab('panel-medio.png', 'index.php?option=com_engage', 'mid', 1000);
        await grab('panel-oscuro.png', 'index.php?option=com_engage', 'dark', 1000);
    }
    if (process.env.DOCS_CONFIG === '1') {
        await grab('config-claro.png', 'index.php?option=com_engage&view=settings', 'light', 1000);
        // 0.6.26: "Privacidad y Gravatar" con las etiquetas largas de Modo y Clasificacion (antes se rompia la fila)
        await grab('config-gravatar.png', 'index.php?option=com_engage&view=settings&section=privacy', 'light', 1400);
    }
    await b.close();
    sql(`UPDATE jos_extensions SET params=${sqlq(prevLang)} WHERE element='com_languages'`);
    sql(`UPDATE jos_extensions SET params=${sqlq(prevParams)} WHERE element='com_engage' AND type='component'`);
    for (const [id, en, pr] of prevPlug) { if (id) sql(`UPDATE jos_extensions SET enabled=${en}, params=${sqlq(pr || '')} WHERE extension_id=${id}`); }
    execFileSync('sh', ['-c', `rm -rf ${S}/cache/* ${S}/administrator/cache/* 2>/dev/null; true`]);
})().catch(e => { console.error(e); process.exit(1); });
