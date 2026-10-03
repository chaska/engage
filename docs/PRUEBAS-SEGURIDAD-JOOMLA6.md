# Pruebas de seguridad y compatibilidad para el fork de Engage (Joomla 5/6) a partir de lo reportado desde Joomla 6.0

Fecha del informe: 2026-10-03. Alcance: investigación web + análisis de `src/` y `tests/` (solo lectura, no se ha tocado nada más que este archivo). Base previa leída: `docs/INFORME-FASE2-JOOMLA6.md`, `CHANGELOG.md` (hasta 0.6.2) y `tests/01..12`.

## 0. Cómo se ha obtenido la información (y su fiabilidad)

El proxy del entorno bloquea (403 / EGRESS_BLOCKED) `developer.joomla.org`, `www.joomla.org`, `nvd.nist.gov`, `tenable`, `strix.ai`, `cvedetails`, `mysites.guru`, `thedroptimes`, `joomla.center`, `api.github.com` y `cdn.akeeba.com`. No se ha desactivado TLS ni se ha reintentado. Lo que sí respondió:

- **[GH]** Páginas de `github.com/joomla/joomla-cms/releases` y `/releases/tag/<x>` leídas con WebFetch (resumidas por un modelo pequeño; **ojo**: en varias páginas el resumen dio un año erróneo, p. ej. "6.0.0 = 14-oct-2024", "6.0.4 = 31-mar-2025", "6.1.0 = 14-abr-2025", "5.4.8 = 18-ago-2024"; la secuencia de tags y las fechas día/mes son coherentes con 2025-2026 y las pongo con el año que corresponde por secuencia).
- **[BQ]** Resultados de WebSearch: se ve el título y la URL de la página oficial (p. ej. `developer.joomla.org/security-centre/1060-20260706-core-xss-in-com-installer.html`) y el buscador resume su contenido. **No he podido abrir esas páginas oficiales**, así que CVE, versiones afectadas y severidades de [BQ] son "visto en resumen de buscador, no contrastado en la fuente primaria". Hay inconsistencias detectadas en los resúmenes (se señalan con NV).
- **[GH-adv]** `github.com/advisories/GHSA-c3f5-4g7f-qjqj` (JCE) leída con WebFetch.

Convención: **VERIFICADO** = leído en la página indicada ([GH]/[GH-adv]); **BQ** = resultado de buscador (URL oficial identificada pero no abierta); **NV** = no verificado / contradictorio / no encontrado.

Fecha de lanzamiento de Joomla 6.0 (oficial): **14 de octubre de 2025** (BQ: `endoflife.date/joomla`, roadmap de developer.joomla.org en resultados; [GH] 6.0.0 sin año fiable; 6.0.1 el 25-nov-2025 coherente). Joomla 5.4 salió el mismo día (anuncio "Joomla 6.0 and Joomla 5.4 are here", `joomla.org/announcements/release-news/joomla-6-0-and-joomla-5-4-are-here.html`, BQ).

## 1. Cronología resumida

Versiones y fechas de tags según [GH] salvo indicación. "Sec." = marcada por Joomla como "security release" en GitHub.

| Fecha | Versión(es) | Contenido relevante | Fuente |
|---|---|---|---|
| 2025-09-30 (previo a 6.0, contexto) | 5.3.4 / 4.4.14 | CVE-2025-54476 (XSS por filtrado insuficiente en `checkAttribute`), CVE-2025-54477 (enumeración de usuarios en passkey) | BQ `joomla.org/announcements/release-news/5936-joomla-5-3-4-security-bugfix-release.html` |
| 2025-10-14 | **6.0.0 y 5.4.0** | Lanzamiento. 6.0 elimina clases obsoletas (BaseApplication, clases CLI, CMSObject, `Joomla\CMS\Input` pasa a la clase del framework, se quita Chosen y el polyfill de web components, etc.). Obligatorio pasar por 5.4 antes de 6.x. PHP mínimo 8.3 | [GH] tag 6.0.0 (año NV en el resumen); BQ endoflife.date |
| 2025-11-25 | 6.0.1 / 5.4.1 | Bugfix. DELETE de la API devuelve 404 correcto; asset de compat; sin CVE conocidos (BQ) | [GH] 6.0.1 |
| 2026-01 (GH: 6 ene; un resultado BQ dice 31 ene: discrepancia, NV) | 6.0.2 / 5.4.2 (Sec.) | CVE-2025-63082 (XSS: filtrado insuficiente de URLs `data:` en etiquetas `img`, afectaba 4.0.0-5.4.1 / 6.0.0-6.0.1) y CVE-2025-63083 (XSS en plugin pagebreak). Soporte oficial de PHP 8.5. Bug conocido posterior: `setSender()` del mailer (16-ene-2026) | [GH] 6.0.2; BQ `developer.joomla.org/security-centre/1016-20260101-core-inadequate-content-filtering-for-data-urls.html`, `joomla.org/.../5942-...` |
| 2026-02-17 | 6.0.3 / 5.4.3 | Bugfix + dependencias (NPM/composer). Error de actualización 5.4.3→6.0.3 "Call to a member function load() on null" reportado en foro | [GH] 6.0.3; BQ `forum.joomla.org/viewtopic.php?t=1022581` |
| 2026-03-31 | 6.0.4 / 5.4.4 (Sec.) | SQLi en endpoint web service de artículos (CVE-2026-21630, CVSS 8.8, afecta hasta 5.4.3/6.0.3); borrado arbitrario de ficheros en com_joomlaupdate (CVE-2026-23898); XSS en comparación de com_associations (posible CVE-2026-25901, NV el mapeo); XSS en títulos de artículo; refuerzo ACL en com_ajax; comprobación de acceso incorrecta en endpoints web service | [GH] 6.0.4 (año NV); BQ `joomla.org/.../5944-joomla-6-0-4-5-4-4-security-bugfix-release.html`, `cyberstrike.io/cve/CVE-2026-21630` |
| 2026-04-07 | (extensión, no core) | Compromiso de cadena de suministro de Smart Slider 3 Pro 3.5.1.35 (Nextend) vía su canal oficial de actualización; CVE-2026-34424 | BQ `mysites.guru/blog/smart-slider-3-pro-supply-chain-compromise/`, `exploit-intel.com/vuln/CVE-2026-34424` |
| 2026-04-14 | 5.4.5 (bugfix) y 6.1.0 | 6.1.0: editor gráfico de workflow, captcha proof-of-work, MFA configurable para superusuarios, versionado de módulos, schema.org en web services | [GH] (página 3-4 de releases; fecha exacta de 6.1.0 no mostrada; 5.4.5 = 14 abr) |
| 2026-05-26 | **6.1.1 / 5.4.6** (BQ: 10 a 20 correcciones; el número NO es consistente entre fuentes) | MFA bypass x2 (CVE-2026-48896/48897), escalada en com_users batch (CVE-2026-48898, Alta), escalada en web service de grupos (CVE-2026-48904), ACL en datos de ejemplo (48899), XSS en joomla/filter `checkAttribute`/`cleanAttributes` (48902/48903/48905, "desde 3.0.0"), clave de caché de InputFilter incorrecta (48900), degradación de transporte en enlaces de reseteo de contraseña (48901), SQLi ciega en com_finder y com_tags (CVE-2026-35221 y otro de ID dudoso), CSRF en endpoint de activación de usuario, LFI en parámetro `layout` de HtmlView (CVE-2026-40383, 3.2.1-5.4.5/6.0.0-6.1.0), path traversal en web service de com_media, ACL en com_config y com_scheduler, XSS en módulos de feed, contenthistory, associations y readmore; phpseclib 3.0.52, joomla/oauth2 4.0.2 | [GH] 6.1.1 (phpseclib/oauth2); BQ `developer.joomla.org/security-centre/1041-20260509-core-lfi-in-htmlview-layout-parameter.html`, `.../1051-20260519-framework-inadequate-content-filtering-within-the-checkattribute-filter-code.html`, `.../1048-20260516-core-incorrect-access-control-in-com-scheduler.html`, `.../1038-20260506-core-authenticated-blind-sqli-in-com-finder.html`, `.../1039-20260507-...-com-tags.html`, `mysites.guru/blog/joomla-5-4-6-and-6-1-1-patch-ten-security-issues/` |
| 2026-06-03 a 2026-06-16 | (extensión) | **JCE CVE-2026-48907** (CVSS 10, RCE sin autenticación por importación de perfil de editor): parche 2.9.99.5 (3-jun), 2.9.99.6 (6-jun); exploit público 9-jun; CISA KEV 16-jun; BQ: caída de extensions/community/certification.joomla.org tras ataques | [GH-adv]; BQ `thehackernews.com/2026/06/cisa-warns-of-actively-exploited-joomla.html`, `mysites.guru/jce-hack/`, `scworld.com` |
| 2026-06 / 2026-07 | (extensiones) | SP Page Builder CVE-2026-48908 y Page Builder CK CVE-2026-56290 (subida sin auth, explotación activa; alerta CSA Singapur AL-2026-085 del 10-jul). iCagenda CVE-2026-48939 y Balbooa Forms CVE-2026-56291 (subida sin auth, CISA KEV 10-jul). Helix3 CVE-2026-49049 (campaña de defacement "AntonKill" desde ~5-jul, sin parche en la fecha de publicación) | BQ `csa.gov.sg/alerts-and-advisories/alerts/al-2026-085/`, `thehackernews.com/2026/07/icagenda-and-balbooa-forms-joomla-flaws.html`, `mysites.guru/blog/helix3-antonkill-defacement-wave/` |
| 2026-07-07 | **6.1.2 / 5.4.7** (Sec., 12 avisos 20260701-20260712, CVE ~48947-48957) | ACL: web services de com_media, com_privacy (CVE-2026-48957), com_fields, descarga vcf de com_contact, com_workflow, com_modules. XSS: gestión de métodos MFA, com_templates, layouts modalreturn, com_installer (CVE-2026-48952), layout genérico de imagen, language overrides. Regresión posterior: "las opciones del artículo no se respetan" (hotfix oficial) | [GH] 6.1.2; BQ `developer.joomla.org/security-centre/1060-...`, `.../1065-20260711-...com-privacy-webservice-endpoints.html`, `manual.joomla.org/updates/60-61/known-issues/6.1.2/` |
| 2026-08-18 | **6.1.3 / 5.4.8** (Sec.) | Inyección de cabeceras de respuesta en vistas de descarga (CVE-2026-71572), validación CORS incorrecta (CVE-2026-71573, CVSS 8.3 según BQ), ACL en web services mutadores (CVE-2026-71574), campos personalizados, categorías, copia por lotes, datos schema.org de contacto; XSS por salidas schema.org (CVE-2026-73336); MFA bypass (CVE-2026-73337); **subida de ficheros SHTML sin restricción** (CVE-2026-73373); comprobaciones de path traversal en com_templates (#48171) | [GH] 5.4.8 (año NV); BQ `joomla.org/.../joomla-6-1-3-5-4-8-security-bugfix-release.html`, `developer.joomla.org/security-centre/1070-20260803-core-inconsistent-acl-checks-for-mutating-webservice-endpoints.html`, `app.opencve.io/cve/CVE-2026-71572` |
| 2026-09-15 | HTMLPurifier 4.19.1 | Corrige `null` en Lexer con `Core.NormalizeNewlines=false`, chmod tras mkdir, compat. PHP 8.5. Sin CVE | BQ `github.com/ezyang/htmlpurifier/releases/tag/v4.19.1` (el fork ya la incluye) |
| 2026-09-29 | **6.1.4 / 5.4.9** (Sec., 16 correcciones: 2 Altas, 13 Moderadas, 1 Baja según BQ) | Altas: borrado arbitrario de directorios por purgado de caché (CVE-2026-90915, path traversal en nombre de grupo de caché) y SSRF en URLs de feeds/solicitudes (CVE-2026-92222). Moderadas: XSS en `HTMLHelper::link` (90906), creación de cuenta con registro desactivado (90907), ACL en API de niveles de acceso (90913), XSS en layouts audio/vídeo (90914), ACL en comparación de content history (90916), divulgación de artículos de categorías restringidas (90917), XSS en plantillas de correo HTML (90918), ACL en cambios de workflow (92223), XSS en link toolbar (92224) y lista de módulos (92225), **bypass de InputFilter por entidades HTML5** (92231) y **por espacios en URIs `data:`** (92232, CVSS 7.1), MFA bypass por cookie "recordarme" emitida demasiado pronto (92227, afecta 4.0.0-5.4.8/6.0.0-6.1.3), ACL en tareas de edición de web services (20260913). Endurecimientos: TOTP con `hash_equals`, escape de fórmulas en CSV de banners, quitar la comprobación CSRF de POWcaptcha (#48304) | [GH] 6.1.4 y 5.4.9; BQ `developer.joomla.org/security-centre/1085-20260905-core-arbitrary-directory-deletion-via-cache-purge-action.html`, `.../1095-20260915-core-xss-filter-bypass-in-inputfilter-via-html5-entity-decode-mismatch.html`, `.../1096-20260916-...-whitespace-characters-in-html-data-uris.html`, `.../1092-20260912-core-xss-in-module-list.html`, `.../1084-20260904-core-xss-in-the-generic-media-output-layouts.html` |
| 2026-09-29 | **5.4.9: regresión** | PATCH/PUT de la API web services falla con error fatal (`Doctrine\Inflector\InflectorFactory` no importado al portar los parches; 6.1.4 no afectada). Se corrige en 5.4.10 (NV su fecha) | BQ `manual.joomla.org/updates/53-54/known-issues/5.4.9/`, `github.com/joomla/joomla-cms/issues/48556`; [GH] 5.4.9 menciona el problema |
| 2026-09-29 | 6.2.0 RC1 | GA de 6.2.0 prevista el 2026-10-13 (BQ roadmap) | [GH] 6.2.0 RC1 |
| Fechas de soporte | | 5.x: bugfix hasta 2026-10-13, solo seguridad hasta 2027-10-12. 6.x: bugfix hasta 2028-10-17, seguridad hasta 2029-10-16. 4.4 sin parches desde 2025-10-14 | BQ `endoflife.date/joomla`, `developer.joomla.org/roadmap.html` |

### 1.1 Incidentes en la naturaleza / campañas
- JCE (CVE-2026-48907): explotación masiva automatizada desde el exploit público del 9-jun-2026; indicador típico = perfiles de editor falsos que reactivan subida de `php`/`txt` y luego webshell. CISA KEV 16-jun. (BQ, [GH-adv] confirma CVSS 10, CWE-284, presencia en KEV).
- Subidas sin autenticación en SP Page Builder, Page Builder CK, iCagenda, Balbooa Forms (CISA KEV / CSA). (BQ.)
- Helix3 (CVE-2026-49049): defacement masivo por botnet. (BQ.) Una de las fuentes lo describe también como "RCE AJAX" en un escáner de GitHub; la descripción que da el CVE es de control de acceso indebido (escritura de JSON/borrado de ficheros/parámetros de plantilla). NV el alcance real.
- Alerta de la ACSC (Australia) sobre campaña global contra WordPress/Joomla con webshells por subida, RCE, SSRF o deserialización (BQ `cybernews.com/news/global-wordpress-joomla-hacking-campaign/`, fecha NV).
- Cadena de suministro: Smart Slider 3 Pro 3.5.1.35 (7-abr-2026, ~6 h de ventana, puerta trasera RCE y administradores ocultos; BQ). Infraestructura de Joomla: BQ afirma que 3 sitios oficiales fueron retirados tras ataques coincidentes con el exploit de JCE (9-jun); **NV**: no he visto un comunicado oficial.
- El resumen de ChronoEngine sobre 6.1.2 dice que un invitado podía subir a Super User por la API; contradice el aviso de 5.4.6 (CVE-2026-48904 requiere cuenta con acceso a la API de grupos). **NV, no tomar como hecho.**
- No se ha encontrado ninguna explotación en la naturaleza de vulnerabilidades del **núcleo** de Joomla en 2026 (el Security Centre no la reporta, según BQ); las explotaciones son de extensiones.

### 1.2 Extensiones de comentarios, foros y componentes afines
- Easy Discuss (foro): CVE-2026-21624, XSS persistente por texto de avatar (BQ, `sentinelone.com/vulnerability-database/cve-2026-21624/`).
- RSBlog CVE-2025-50126 (XSS almacenado, blog), J2Store CVE-2026-74252 (XSS almacenado en checkout de invitado) y fin de vida de J2Store 3 (BQ).
- AcyMailing CVE-2026-3614 (falta de autorización, 8.8; parche 10.8.2 del 13-mar-2026; BQ lo cataloga como bug de WordPress con código compartido con Joomla).
- Kunena: solo CVE históricos (2009-2019), ninguno de 2025-2026 encontrado. JComments, Komento, Akeeba Engage, Akeeba Backup/Admin Tools: **no se encontró ningún CVE de 2025-2026** (la ausencia en el buscador no prueba que no existan).
- Patrón común de estos incidentes: falta de autorización, XSS por campos de usuario sin escapar, subida de ficheros.

### 1.3 HTMLPurifier
- CVE catalogados de `ezyang/htmlpurifier`: solo 2007-2011 (BQ cvedetails/OpenCVE). Ninguno de 2025-2026 encontrado.
- Relacionado y relevante como lección: Grocy CVE-2026-71236 (BQ), XSS almacenado porque la aplicación **deshacía la codificación de entidades justo después de purificar**; y `node-xhtml-purifier` CVE-2026-61784 (otra librería, sin relación de código). Prueba PS-04 cubre "no post-procesar la salida purificada".
- 4.19.0 (2025-10-17, según NEWS ya leído en la Fase 2) y 4.19.1 (2026-09-15) ya están en el fork (CHANGELOG 0.5.1).

## 2. Resumen por categoría (core de Joomla, desde 6.0.0 hasta 6.1.4/5.4.9; cifras aproximadas, contadas a partir de resúmenes BQ)

| Categoría | Nº aprox. | Ejemplos |
|---|---|---|
| XSS | 25 | filtros `data:`/entidades/espacios, pagebreak, feeds, associations, templates, language overrides, HTMLHelper::link, plantillas de correo |
| Control de acceso (ACL) incorrecto / divulgación | 23 | web services (media, privacy, fields, categories, config, access levels, workflow), com_ajax, com_users batch, content history, categorías restringidas |
| SQL injection | 3 | orden en web service de artículos, com_finder, com_tags |
| Path traversal / borrado / LFI | 5 | com_joomlaupdate, com_media WS, purga de caché, com_templates, LFI en `layout` |
| Auth / MFA / cuentas | 7 | MFA bypass x4 (mayo x2, agosto, remember-me), creación de cuenta con registro desactivado, degradación de transporte en reseteo, enumeración (pre-6.0 passkey) |
| CSRF | 1 (+ eliminación de token en POWcaptcha) | activación de usuario |
| SSRF | 1 | feeds/solicitudes/URL de perfil |
| Subida de ficheros | 1 | SHTML |
| Inyección de cabeceras / CORS | 2 | descargas, CORS |
| Deserialización | 0 en el core (en la campaña ACSC genérica, NV) | |
| ReDoS | 0 en core; HTMLPurifier 4.19.0 corrigió uno | |
| Cadena de suministro | 1 incidente de extensión (Smart Slider 3 Pro) + 1 de infraestructura de Joomla (NV) | |
| Extensiones explotadas | 7 (JCE, SP Page Builder, Page Builder CK, iCagenda, Balbooa Forms, Helix3, Smart Slider) | |

## 3. Clases de vulnerabilidad frente a Engage

| Clase | Relevancia | Por qué (código de Engage) |
|---|---|---|
| XSS (filtros InputFilter, salidas) | **Crítica** | Engage recibe HTML de anónimos; guarda con `ComponentHelper::filterText` (`frontend/forms/comment_new.xml:50`, `backend/forms/comment.xml:114`) y purifica al mostrar (`HtmlFilter.php`). Los bypass 2026 de InputFilter afectan la primera capa; HTMLPurifier es la segunda y ya se aplica siempre desde 0.4.1 |
| ACL / divulgación | **Alta** | Moderación, edición, borrado, publicación; listado según acceso del artículo; módulo "últimos comentarios" |
| Inyección SQL (orden, filtros) | **Alta** | `akengage_order` ya con lista blanca (0.4.0); quedan filtros del backend, `limit`, `cid[]` |
| CSRF / enlaces firmados | Alta | Acciones por GET con firma (0.4.3); formularios con token |
| Redirección abierta | Media | `returnurl` base64 (`ControllerReturnURLTrait.php:81`, `default_form.php:45`) |
| Inyección de cabeceras de correo | Alta | Nombre y correo de anónimos entran en correos (`TemplateEmails.php`, plugin `email`) |
| Path traversal / LFI | Media | Copia de `loadTemplate` con `Path::find` (`ViewLoadAnyTemplateTrait.php`), vistas con `layout`; `UpgradeModel` borra ficheros |
| SSRF | Baja-media | Engage no tiene campos URL (`grep type="url"` vacío); sale a Akismet y Gravatar con host fijo |
| Auth / MFA / sesiones | Baja | Engage no autentica; plugin `user` usa `$options['remember']` (`user/engage/.../Engage.php:210`) |
| Subida de ficheros | Baja | Sin subidas; editor del invitado con `buttons="false"` (`comment_new.xml:51`) |
| Deserialización | Baja | Caché de definiciones de HTMLPurifier (`cache/com_engage/htmlpurifier`); Engage no usa `unserialize` propio (NV: no he hecho grep exhaustivo) |
| ReDoS / DoS | Media | BBCode.php, Html2Text.php, límite de comentarios por página (`akengage_limit`, `CommentsController.php:362`) |
| Cadena de suministro | **Alta** | Update site propio en `raw.githubusercontent.com/.../main` (mutable), sha256, vendor HTMLPurifier, sitio antiguo de Akeeba |
| Info. en errores | Baja | `errorhandler.php:193` imprime `$_COOKIE` con htmlentities (solo modo depuración) |
| Cabeceras/CORS | Baja | Engage no tiene vistas de descarga; formato JSON no verificado (NV) |

## 4. Pruebas concretas que debe pasar el fork

Leyenda de columna "Cubierta": **SÍ** = ya hay test (nombre), **PARCIAL**, **NUEVA**. "Automatizable": **A** = automatizable con los stubs/librerías de `tests/` (sin Joomla), **A+** = automatizable pero necesita clon de joomla-cms (como `tests/joomla-real/`) o base de datos, **M** = requiere Joomla real/manual. Los archivos son rutas bajo `src/`.

### 4.1 CRÍTICO

**PS-01. Bypass de InputFilter 2026 + HTMLPurifier (primera y segunda capa).**
- Qué: que ninguna carga útil de los avisos CVE-2026-92231/92232/48902/48903/48905 y CVE-2025-63082 ejecute script en la salida final.
- Cómo: payloads `<a href="&#106;avascript:alert(1)">x</a>`, `<a href="jav&Tab;ascript:alert(1)">`, `<a href="javascript&colon;alert(1)">`, `<a href="&#x6A;avascript&#58;alert(1)">`, `<img src="da&#9;ta:text/html;base64,PHNjcmlwdD5hbGVydCgxKTwvc2NyaXB0Pg==">`, `<img src=" data:image/svg+xml;base64,...">` (espacio inicial/tabulador/salto de línea dentro del esquema), `<a href="data:text/html,...">`, `<svg><a xlink:href="javascript:..">`, `<math><mtext><table><mglyph><style><img src onerror=...>`. Pasar por (a) `joomla/filter` real (InputFilter con `filterText` de lista negra por defecto y con "sin filtrar") y (b) HTMLPurifier con la configuración por defecto y con "usar filtros de Joomla".
- Esperado: (b) siempre sin `javascript:`/`data:`/`on*`/`<script>`/`<svg>`; (a) se registra qué pasa: si el InputFilter de la versión instalada deja pasar algo, la segunda capa lo para (la prueba falla solo si ambas dejan pasar).
- Código: `HtmlFilter.php` (`purify`, `filterTextForDisplay`), `Service/Html/Engage.php` (`processCommentTextForDisplay`).
- Cubierta: PARCIAL (`tests/03` y `tests/07`: 18 vectores, sin entidades HTML5 con nombre ni `data:` con espacios). Ampliar con este conjunto. **A** (HTMLPurifier real) y **A+** para joomla/filter 4.x real (añadir `joomla/filter` al `composer.json` de `tests/`; hoy solo hay `filesystem` y `uri` en `tests/vendor/joomla`).

**PS-02. Almacenado "ya contaminado": el valor en BD se muestra seguro en todos los contextos.**
- Qué: que aunque el filtro de guardado se eludiera (fila insertada por SQL, importación, bug futuro), lo que se pinta no ejecute script, para cada tipo de espectador (Sin filtrar, lista negra, lista blanca, sin etiquetas, lista negra personalizada).
- Cómo: insertar en `#__engage_comments.body` los payloads de PS-01 y renderizar frontend (`default_list.php`), módulo (`engage_latest/tmpl/default.php`), lista del panel (`backend/tmpl/comments/default.php:100`), correo (`Email.php:355-370`), export de privacidad.
- Esperado: ninguna salida contiene sintaxis ejecutable; texto legítimo conservado.
- Cubierta: PARCIAL (`tests/03` cubre `processCommentTextForDisplay` y la plantilla del módulo de forma estática). Falta: correo, export de privacidad y panel. **A**.

**PS-03. Escape de nombres y de otros campos de usuario en cadenas de idioma que contienen HTML.**
- Qué: que ningún campo controlado por el visitante (`name` del comentario, nombre del autor, IP, `user_agent`, título del artículo) entre sin escapar en HTML. Patrón de CVE-2026-90918 (XSS en plantillas de correo) y del aviso "XSS through language overrides".
- Cómo: análisis estático + dinámico. Estático: para cada llamada `Text::sprintf` cuya cadena `.ini` contenga `<`, comprobar que los argumentos pasan por `htmlspecialchars`/`$this->escape`. Hallazgo concreto de esta lectura (**NV de explotabilidad**, no se ha ejecutado): `backend/tmpl/comments/default.php:209` pasa `$parentAuthor` (que sale de `$parent->name`, texto del invitado) y `frontend/tmpl/comments/default_list.php:253` pasa `$modifiedBy` (nombre de usuario) a `Text::sprintf` sin escapar; la cadena `COM_ENGAGE_COMMENTS_LBL_INREPLYTO` contiene HTML. Dinámico: nombre `<img src=x onerror=alert(1)>` y `"><script>` en comentario de invitado y en nombre de usuario registrado.
- Esperado: cualquier `<`/`"` aparece como entidad. (El campo `name` no declara `filter` en `comment_new.xml:27-34`, así que depende del filtro por defecto de Joomla, que quita etiquetas pero no es una garantía de escape.)
- Cubierta: NUEVA (el CHANGELOG 0.4.1 solo corrigió el módulo). **A** (análisis estático con tokenizador, como `tests/06`) y **A** (render con stubs).

**PS-04. HTML de correo: cuerpo, nombre, título y plantilla; sin post-procesado de entidades tras purificar.**
- Qué: correos de notificación (`plugins/engage/email/src/Extension/Email.php`, `TemplateEmails.php`) sin XSS ni inyección de HTML en el cliente de correo del moderador; y que ninguna función deshaga la codificación de entidades después de HTMLPurifier (lección de Grocy CVE-2026-71236).
- Cómo: comentario con nombre `<b onmouseover=alert(1)>x`, título de artículo `</title><script>`, cuerpo con `&lt;script&gt;` (entidades legítimas) y con payloads de PS-01; revisar el HTML y el texto plano generados. Buscar `html_entity_decode`/`htmlspecialchars_decode`/`strip_tags` aplicados a salida purificada.
- Esperado: texto escapado, sin doble decodificación; `Html2Text.php` no resucita etiquetas.
- Cubierta: NUEVA. **A** (stubs de `Mail`/`MailTemplate`), envío real **M**.

**PS-05. Cabeceras de correo (CRLF) y destinatarios.**
- Qué: que `name`/`email` del invitado no puedan inyectar cabeceras (`Bcc:`) ni cambiar el destinatario o el Reply-To.
- Cómo: nombre `Juan\r\nBcc: victima@example.com`, email `a@b.com\r\nCc: x@y.com`, email con comas o `<>`, nombre con 10 000 caracteres; examinar `addReplyTo`/`addRecipient` en `TemplateEmails.php` y el plugin `email`.
- Esperado: rechazo en validación (regla email) o saneado; ninguna cabecera extra en el mensaje final.
- Cubierta: NUEVA. **A** (stub de PHPMailer o la clase `Joomla\CMS\Mail\Mail` del clon). Real **M**.

**PS-06. Matriz de ACL de moderación/edición/borrado (frontend y backend).**
- Qué: cada acción comprueba permiso en el servidor, no solo oculta el botón (ACL fue la clase más frecuente en 2026).
- Cómo: matriz usuario (invitado, registrado, autor del comentario, editor, manager, superusuario, usuario con `core.edit.own` revocado) × acción (editar, editar ajeno, publicar, despublicar, borrar, marcar spam, reportar ham, listar sin publicar, ver IP/user agent) × origen (GET con firma, POST con token, sin token). Intentar cambiar `id`/`cid[]` a comentarios ajenos (IDOR).
- Esperado: 403 / redirección a login; datos ajenos no modificados.
- Código: `Controller/CommentsController.php` (frontend y backend), `CommentController.php`, `Model/CommentModel.php` (`canEditState`, `canDelete`), `ControllerFrontendCommentsTrait.php`.
- Cubierta: PARCIAL (`tests/05` cubre el token firmado; no hay prueba de ACL de modelo). **A** con stubs de `User::authorise`; confirmación real **M**.

**PS-07. Acceso del artículo/categoría: comentarios de contenido restringido.**
- Qué: caso del aviso CVE-2026-90917 (divulgación de artículos de categorías restringidas) trasladado a Engage: los comentarios de un artículo cuyo `access` o categoría no son visibles para el espectador no aparecen en listados, módulo `mod_engage_latest`, feeds, ni cambiando `asset_id` en la petición.
- Cómo: artículo en categoría con nivel de acceso "Registered"; como invitado pedir `index.php?option=com_engage&view=comments&asset_id=<id>` (vía plugin y directo), `akengage_*`, el módulo en una página pública, la URL JSON si existe; repetir con artículo despublicado, archivado, caducado (`publish_down`) y en otro idioma.
- Esperado: sin comentarios ni metadatos (título, autor, extracto) para quien no tiene acceso.
- Código: `Helper/Meta.php` (`getAssetAccessMeta`), `plugins/content/engage/.../Engage.php` (`onContentAfterDisplay`), `CommentsModel::getListQuery`, `modules/site/engage_latest`.
- Cubierta: NUEVA. **M** (Joomla real con niveles de acceso); parte **A+** con base de datos.

**PS-08. Asignación masiva en el envío de comentarios (mass assignment) y relaciones.**
- Qué: un invitado no puede fijar `enabled`, `created_by`, `created`, `ip`, `user_agent`, `modified*`, `id` (sobrescribir comentario existente), ni usar `parent_id` de otro artículo o de un comentario despublicado; `asset_id` debe coincidir con el contenido realmente mostrado.
- Cómo: POST con `jform[enabled]=1`, `jform[created_by]=1`, `jform[id]=<comentario ajeno>`, `jform[parent_id]=<id de otro artículo>`, `jform[asset_id]=<id restringido>`, y campos nuevos desconocidos.
- Esperado: se ignoran/rechazan; el comentario se crea con el estado definido por la configuración (moderación/Akismet) y bajo el asset correcto.
- Código: `frontend/src/Model/CommentModel.php` (`removeFields` ~línea 162, `save`), `backend/src/Table/CommentTable.php`; formulario `frontend/forms/comment_new.xml` (declara `id`, `parent_id`, `asset_id` como campos enviables por el usuario).
- Cubierta: NUEVA. **A** (modelo con stubs) y **A+** con BD.

**PS-09. Cadena de suministro del paquete y del servidor de actualizaciones.**
- Qué: integridad extremo a extremo: lo que Joomla descarga es exactamente lo construido; nadie puede alterar `updates/pkgengage.xml` sin que se note (incidente Smart Slider 3 Pro, 7-abr-2026).
- Cómo: (1) `updates/pkgengage.xml` solo HTTPS, `<sha256>` presente y igual al ZIP; (2) ZIP reproducible y contenido == `src/` (ya hace `build/verificar.php`); (3) lista de ficheros del ZIP sin extras (sin `.git`, `tests/`, `upstream/`, `.env`, claves); (4) la release en GitHub no se reescribe (tags protegidos, 2FA y protección de la rama `main` porque el update site es `raw.githubusercontent.com/.../main`: quien escriba en `main` publica código a todos los sitios con solo cambiar el XML y el sha256); (5) Joomla rechaza un ZIP con sha256 distinto.
- Esperado: todas las comprobaciones verdes; el procedimiento de release documenta los controles de (4).
- Cubierta: PARCIAL (`tests/12-update-server.php`: coherencia, sha256, rechazo de ZIP con otro hash; `build/verificar.php`). Falta lista blanca de contenido del ZIP y controles del repositorio. **A** para 1-3 y 5; 4 **M** (ajustes de GitHub).

**PS-10. Sin dependencias vulnerables en `vendor/` y en el árbol de pruebas.**
- Qué: `composer audit` limpio para `src/component/backend/vendor` (solo HTMLPurifier 4.19.1) y `tests/` (joomla/filesystem, uri, etc.); el vendor coincide con `composer.lock`.
- Cómo: `composer audit --locked` en ambos directorios; comparar hash del árbol `ezyang/htmlpurifier` con la etiqueta (ya lo hace `tests/07` por commit en `installed.php`).
- Esperado: 0 avisos; si salen, issue con plazo.
- Cubierta: PARCIAL (`tests/07`). **A** (necesita red a Packagist; en el sandbox puede fallar por proxy).

**PS-11. Sin clases eliminadas en Joomla 6 (con `compat6` apagado).**
- Qué: no queda ninguna referencia a clases/alias que existen solo en el plugin `behaviour/compat6` o que se eliminaron en 6.0 (CMSObject, BaseApplication, `Joomla\CMS\Filesystem\*`, `JFactory`, `Joomla\CMS\Input\Input`, clases CLI antiguas, etc.).
- Cómo: script que extrae todos los `use`/nombres de clase de `src/` (como ya se hizo manualmente en Fase 2) y comprueba `class_exists`/ficheros contra clones de `6.1-dev` y `5.4-dev` (y 6.2 cuando salga) y contra `plugins/behaviour/compat6/src/classmap`.
- Esperado: 0 clases que dependan de compat6.
- Cubierta: PARCIAL (`tests/01` solo `Filesystem`). **A+** (clon de joomla-cms en CI).

### 4.2 ALTO

**PS-12. Inyección SQL en filtros y listados del backend y del frontend.**
- Qué: ningún parámetro de petición llega sin enlazar a SQL (órdenes de web service de artículos, com_finder y com_tags fueron SQLi 2026).
- Cómo: fuzzing de `filter[search]`, `filter[enabled]`, `filter[since]`, `filter[to]`, `filter[...][]` (array donde se espera escalar), `list[fullordering]`, `list[limit]`, `list[direction]`, `limitstart`, `akengage_limit`, `akengage_limitstart`, `cid[]` con strings, `asset_id` con `1 OR 1=1`, `1;SELECT SLEEP(5)`; medir tiempo y comparar resultados.
- Esperado: números forzados con `(int)`, sin error SQL, sin retardo.
- Código: `Model/CommentsModel.php` (`getListQuery`, `populateState`), `Mixin/ModelPopulateStateTrait.php`, `frontend/src/Controller/CommentsController.php:361-362`.
- Cubierta: PARCIAL (`tests/02` solo orden/dirección; `tests/10` fechas). **A** (patrón de `tests/02`) y **A+** con MariaDB/MySQL/PostgreSQL reales.

**PS-13. Límite de resultados y DoS por paginación.**
- Qué: `akengage_limit=100000` no devuelve el hilo completo ni consume memoria sin tope; límite máximo razonable.
- Cómo: pedir `akengage_limit=0`, `-1`, `999999999`, `abc`, arrays; medir memoria/tiempo con 5 000 comentarios.
- Esperado: se acota a un máximo configurable (o al valor por defecto).
- Código: `frontend/src/Controller/CommentsController.php:361-388`.
- Cubierta: NUEVA. **A** (stub) + carga **A+**.

**PS-14. Redirección abierta en `returnurl`/`return`.**
- Qué: solo se redirige a URLs internas; casos que han roto filtros de URL antes.
- Cómo: base64 de `//evil.com`, `/\evil.com`, `https://evil.com`, `https:\\evil.com`, `javascript:alert(1)`, `\/\/evil.com`, `https://site.example@evil.com`, `/%0d%0aLocation:...`, URL con tab/salto de línea (`ht\ttp://evil`), `data:`; también `returnurl` no base64, vacío, enorme.
- Esperado: redirección a la URL por defecto (artículo o inicio).
- Código: `backend/src/Mixin/ControllerReturnURLTrait.php:81` (`Uri::isInternal`), `ControllerFrontendCommentsTrait.php:121-153`, `default_form.php:45`.
- Cubierta: NUEVA. **A** (joomla/uri real ya está en `tests/vendor`; probar contra la versión de `joomla/uri` que lleva J5.4 y J6.1, ver `composer.lock` de cada una).

**PS-15. CSRF: formularios, acciones por GET y bypass por `debug_backtrace`.**
- Qué: toda acción que cambia estado exige token de formulario o enlace firmado válido de UN comentario; `task=main` no es invocable desde la URL (S9 del informe).
- Cómo: GET/POST sin token, con token de otra sesión, con token caducado, `task=main`/`view=comments&task=...` directo con `option=com_engage`, `cid[]` extra (ya), enlace firmado reutilizado en otra acción (cambiar `task` en la URL), método HTTP cruzado (POST con token en query).
- Esperado: fallo de token (403/redirección con aviso) y estado sin cambios.
- Código: `Mixin/ControllerFrontendCommentsTrait.php:37-107` (`checkToken`), `CommentsController.php:180,330-352`, backend `CommentsController.php:103,172`, `EmailtemplatesController.php:25,41` (usa `checkToken('get')`).
- Cubierta: PARCIAL (`tests/05`). Falta `task=main` directo y reutilización entre tareas. **A** (stubs) + **M** (confirmar con Joomla real J5/J6, porque `Session::checkToken` y el nombre del token dependen de la versión).

**PS-16. Enlaces firmados: caducidad, clave, algoritmo y comparación en tiempo constante.**
- Qué: tras 0.4.3 el token ata `task+email+asset_id+expires+cid`, usa HMAC-SHA-256 y `Crypt::timingSafeCompare`.
- Cómo: ya probados en `tests/05`; añadir: cambio de `secret` de Joomla invalida enlaces; `expires` en pasado/futuro lejano/negativo/float/array; email en mayúsculas/espacios; verificar que se usa comparación en tiempo constante (análisis estático de `verifyToken`).
- Esperado: rechazo en todos los casos anómalos.
- Código: `frontend/src/Helper/SignedURL.php`.
- Cubierta: SÍ en su mayor parte (`tests/05-enlaces-firmados.php`); extras **A**.

**PS-17. Aislamiento por asset: respuesta a comentario ajeno y comentarios en artículos distintos.**
- Qué: un `parent_id` debe pertenecer al mismo `asset_id` y estar publicado/visible para el autor del envío; no se puede "colgar" un comentario del hilo de otro artículo restringido.
- Cómo: ver PS-08 (variante `parent_id`) + comprobación en tabla.
- Esperado: rechazo.
- Código: `CommentModel.php` (frontend), `CommentTable.php`.
- Cubierta: NUEVA. **A+**.

**PS-18. LFI/path traversal por `layout`/`tmpl`/`task` en vistas de Engage.**
- Qué: homólogo de CVE-2026-40383 (LFI en `layout` de HtmlView). Engage tiene su propia copia de `loadTemplate` (`Mixin/ViewLoadAnyTemplateTrait.php`, usa `Path::find` y `_createFileName`).
- Cómo: `index.php?option=com_engage&view=comments&layout=../../../../configuration`, `layout=..%2f..%2fconfiguration`, `layout=default_form%00`, `layout=\..\..`, `tmpl=../..`, `?format=../../x`; con J5.4.8 y J6.1.4 (donde el core ya corrige la validación del layout) y con J5 anterior.
- Esperado: layout inexistente → error 404 o layout por defecto; nunca inclusión de ficheros fuera de `tmpl/`.
- Cubierta: NUEVA. **A+** (probar el trait contra `HtmlView` real del clon) y **M**.

**PS-19. Plantilla/errores: no filtrar información interna (S8) y `JDEBUG`.**
- Qué: ni traza, ni `$_COOKIE`, ni versión de PHP/SO a visitantes; `$title` escapado.
- Cómo: provocar excepción en el plugin con `JDEBUG=0`, `JDEBUG=1` como invitado y como superusuario; `$title` con HTML.
- Esperado: invitado ve mensaje genérico; solo superusuario ve detalles y siempre escapados.
- Código: `backend/tmpl/common/errorhandler.php:29,53,94,147,193`, `plugins/content/engage/.../Engage.php:1208-1224`.
- Cubierta: NUEVA (S8 pendiente del informe). **A** (render con stubs) y **M**.

**PS-20. Salto de la purificación por filtro "none" en TODOS los puntos (regresión de S2/S3).**
- Qué: mantener `tests/03` y añadir los puntos nuevos de lectura (feeds, export de privacidad, JSON, correo, actionlog).
- Cómo: ampliar `tests/03` con espectador "Sin filtrar" y todos los canales de salida.
- Esperado: purificado siempre en modo `strict`/`htmlpurifier`.
- Cubierta: SÍ (`tests/03-htmlfilter-none.php`) para frontend/módulo; PARCIAL para el resto. **A**.

**PS-21. Mapeo de filtros de Joomla 6 a HTMLPurifier (`htmlpurifier_config_joomla`).**
- Qué: con la opción "usar la configuración de filtros de texto de Joomla" el resultado coincide con el comportamiento de `ComponentHelper::filterText` en J5.4.9 y J6.1.4 (la clave de caché de InputFilter se corrigió en 48900).
- Cómo: ejecutar los 5 tipos de filtro contra los clones de `ComponentHelper` y `InputFilter`.
- Esperado: sin TypeError, listas correctas.
- Cubierta: SÍ (`tests/04-htmlfilter-mapeo.php` con stubs con la forma de joomla/filter 4.x). Falta contra la clase real de J6. **A+**.

**PS-22. `MailTemplateHotFix` en Joomla 6.**
- Qué: `backend/src/Helper/MailTemplateHotFix.php` reescribe en tiempo de ejecución el código fuente de `libraries/src/Mail/MailTemplate.php` del núcleo (`str_replace` de `class MailTemplate` y de `convertRelativeToAbsoluteUrls`) y lo incluye mediante un wrapper de flujo `akmtwa://`. La condición es `version_compare(JVERSION, '5.2.0', 'ge')` sin límite superior: también se aplica en J6. Riesgos: (a) si J6 cambia esa línea o la firma, el parche no hace nada o rompe el correo; (b) ejecuta código derivado del núcleo (fallo de seguridad si el archivo fuera alterado, y bypass de opcache/open_basedir); (c) un parche del core posterior (p. ej. el XSS de plantillas HTML 6.1.4, CVE-2026-90918) se hereda, pero el patrón de inclusión dinámica es mala práctica en un plugin que corre en sitios ajenos.
- Cómo: con el clon 6.1-dev y 5.4-dev comprobar que las dos cadenas existen literalmente; `php -l` del resultado; enviar un correo real con J5.2, J5.4.9 y J6.1.4/6.0.2 (hay un bug conocido del `setSender()` en 6.0.2 [GH]); desactivar `stream_wrapper_register` (Suhosin) y verificar el respaldo a `MailTemplate` normal.
- Esperado: o bien el parche se aplica y el correo sale bien, o se detecta que no hace falta/ya no encaja y se usa `MailTemplate` estándar sin error; decisión recomendada: limitar a `<6.0` o eliminar si el bug de 5.2 está corregido en 5.4.
- Cubierta: NUEVA. **A+** (comprobación de cadenas) y **M** (envío).

**PS-23. Plugin `user` y sesión: MFA, "recordarme" y creación de cuentas.**
- Qué: que Engage no emita cookies/estado de autenticación ni cree usuarios (CVE-2026-92227, 90907). Verificar `plugins/user/engage/src/Extension/Engage.php:210` (`onUserAfterLogin` y `$options['remember']`) y la gestión de `onUserAfterDelete/BeforeDelete`.
- Cómo: login frontend con MFA activo y "recordarme"; flujo de comentario antes/después del login; borrado de un usuario con comentarios (¿se anonimizan?); registro desactivado + comentario con email nuevo → no se crea cuenta.
- Esperado: Engage no altera la autenticación; borrado deja comentarios sin PII o según la política de privacidad.
- Cubierta: NUEVA. **M**.

### 4.3 MEDIO

**PS-24. ReDoS y entradas patológicas en BBCode/Html2Text/filtros propios.**
- Qué: ningún regex propio sufre retroceso catastrófico; HTMLPurifier 4.19.x ya corrige `Core.AggressivelyFixLt`.
- Cómo: entradas de 100 KB: `<` repetido 50 000 veces, `[quote]` anidados 5 000, `[url=` sin cerrar, `<a href="` sin comillas, 1 MB de `&`, UTF-8 inválido; cronometrar (< 2 s) y memoria (< 64 MB).
- Esperado: tiempo acotado; sin `preg_last_error` ignorado.
- Código: `Helper/BBCode.php`, `Helper/Html2Text.php`, `HtmlFilter.php`.
- Cubierta: PARCIAL (`tests/07`: solo `AggressivelyFixLt`). **A**.

**PS-25. Longitud, codificación y límites de datos.**
- Qué: el límite de longitud (`CommentTable.php:195-210`, hoy `mb_strlen(..., '8bit')` = bytes) no truncatea mal; emoji de 4 bytes y NUL se manejan; tablas `utf8mb4` en MySQL/MariaDB con modo estricto.
- Cómo: comentario de justo el máximo, +1, solo emoji, con `\0`, con UTF-8 inválido, con direccional RTL; insertar con `sql_mode=STRICT_ALL_TABLES`.
- Esperado: error de validación claro o guardado íntegro; nunca truncado silencioso ni 500.
- Cubierta: NUEVA. **A+** (BD real).

**PS-26. Akismet/Gravatar/IP lookup: salidas a terceros, valores falsificables y comportamiento ante fallos.**
- Qué: los destinos son fijos; el `permalink`/`user_ip` enviados no los controla el invitado de forma peligrosa (IP vía `IpHelper::getIp()`, S6 del informe: depende de "ip overrides"); si Akismet falla, el comentario se retiene (o según configuración) y no se publica en abierto; la clave API no aparece en logs/errores; Gravatar solo usa hash MD5 del email en minúsculas con recorte.
- Cómo: cabeceras `X-Forwarded-For: 1.2.3.4`, `Client-IP`; Akismet simulado con HTTP 500, timeout, respuesta no válida; revisar `Akismet.php:276` y el log.
- Esperado: IP = `REMOTE_ADDR` salvo proxy de confianza configurado; fallo cerrado (moderación).
- Código: `frontend/src/Model/CommentModel.php:321`, `plugins/engage/akismet/src/Extension/Akismet.php`, `plugins/engage/gravatar`.
- Cubierta: NUEVA. **A** (stub de HttpFactory) y **A+** para `IpHelper` (joomla/utilities 4.0).

**PS-27. Caché de página y plugin `engagecache`: nada personal ni sensible queda en HTML en caché.**
- Qué: con caché de Joomla (conservadora/progresiva) activa, la página cacheada para invitados no contiene token CSRF reutilizable ajeno, enlaces firmados, comentarios sin publicar de otro visitante ni datos de moderación (el plugin `system/engagecache` existe por esto).
- Cómo: con `caching=1` y `=2`, visitar como invitado, luego como moderador y comparar el HTML; buscar `token`, `signed`, `unpublished` en la respuesta cacheada; enviar comentario y ver que no se sirve versión obsoleta con datos de otro.
- Esperado: HTML cacheado idéntico para todos los invitados y sin secretos; formulario obtiene el token vía JS/AJAX o el plugin lo regenera.
- Código: `plugins/system/engagecache/src/Extension/Engagecache.php`, `Service/CacheCleaner.php`.
- Cubierta: NUEVA. **M**. Y relacionado con CVE-2026-90915 (purga de caché con nombre de grupo no validado): comprobar que Engage solo pasa nombres de grupo constantes a `CacheCleaner::cleanCache` (**A**, estático).

**PS-28. Privacidad: exportación y borrado (plugins `privacy` y `datacompliance`).**
- Qué: un usuario solo exporta/borra lo suyo; la salida está escapada; el borrado elimina IP, user agent, nombre y email (S10).
- Cómo: solicitud de exportación de usuario A cuando B también comenta con el mismo email/nombre; borrado y comprobación en BD; ACL de com_privacy (CVE-2026-48957 es del core, pero verificar que Engage no abre otra vía).
- Esperado: solo datos del sujeto; sin XSS en el informe.
- Código: `plugins/privacy/engage`, `plugins/datacompliance/engage`.
- Cubierta: NUEVA. **A** (stubs) y **M**.

**PS-29. Registro de actividad (actionlog): contenido escapado y sin datos sensibles.**
- Qué: nombres, títulos y mensajes en el actionlog del backend (lista de administradores) no permiten XSS; no se guardan emails/IP completos sin necesidad.
- Cómo: acciones de moderación con nombre/título maliciosos; revisar la vista de registro de acciones.
- Esperado: texto escapado.
- Código: `plugins/actionlog/engage`.
- Cubierta: NUEVA. **A** (render de cadena) / **M**.

**PS-30. Instalación/actualización: ficheros que se borran y se escriben (patrón CVE-2026-23898).**
- Qué: `UpgradeModel` (`File::delete`, `Folder::delete/move`, `File::write` en `postflight`) solo opera sobre rutas constantes dentro de `JPATH_ROOT`, nunca sobre datos del XML de actualización ni de la petición; `UpdatesModel::File::write :584` escribe solo bajo la caché/tmp.
- Cómo: análisis estático de todas las rutas; ejecutar la actualización 3.4.2 → 3.4.2.1 en J5.4 y J6.1 con `compat6` apagado y con `open_basedir`; que falle limpio si no hay permisos (`FilesystemException`).
- Esperado: sin borrado fuera de las carpetas de Engage; sin Fatal.
- Cubierta: PARCIAL (`tests/01-filesystem.php` ejecuta las operaciones contra la librería real). Falta la actualización completa. **A** (estático) y **M** (instalación).

**PS-31. Sitio de actualizaciones antiguo de Akeeba.**
- Qué: tras actualizar, no queda ninguna fila en `#__update_sites` que apunte a `cdn.akeeba.com/updates/pkgengage` (si Akeeba publicara una versión mayor, Joomla la ofrecería y pisaría el código del fork).
- Cómo: instalar 3.4.2 original, actualizar con el paquete del fork, ver `#__update_sites` y `#__update_sites_extensions`; repetir con sitios que ya tenían la URL del fork añadida a mano (dos filas).
- Esperado: una sola fila activa, la del fork; o el administrador avisado.
- Cubierta: NUEVA (el CHANGELOG 0.6.2 dice que no se pudo verificar el orden de eventos). **M**.

**PS-32. Fuga de datos de `#__users` y de tablas unidas vía columnas.**
- Qué: ninguna consulta de lista devuelve `password`, `params`, `otpKey`, `activation` de `#__users` a vistas, JSON o correos (S1 mitigó el orden; falta verificar SELECT).
- Cómo: revisar el `SELECT` de `getListQuery` (join con `#__users u`), `Avatar.php`, `UserFetcher.php`; volcar los objetos que llegan a plantillas y a `json_encode`.
- Esperado: solo columnas necesarias.
- Cubierta: PARCIAL (`tests/02` inspecciona el ORDER BY, no el SELECT). **A**.

**PS-33. Enumeración de usuarios/correos.**
- Qué: respuesta idéntica al usar un email de un usuario registrado frente a uno inexistente (formulario de comentario de invitado, `unsubscribe`, mensajes de error), análogo a CVE-2025-54477.
- Cómo: comparar mensajes, códigos HTTP y tiempos con ambos emails y con ID de comentario inexistente.
- Esperado: sin diferencias observables, o decisión documentada.
- Código: `CommentsController::unsubscribe` (`frontend/.../CommentsController.php:172-200`).
- Cubierta: NUEVA. **A** (stub) / **M** (tiempos).

**PS-34. Enlaces absolutos de correo y esquema HTTPS (patrón CVE-2026-48901).**
- Qué: los enlaces que Engage pone en correos usan https cuando el sitio está en https (también tras proxy inverso y con `force_ssl`).
- Cómo: sitio con `force_ssl=2` y con `X-Forwarded-Proto: https`; enviar correo; revisar `href`.
- Esperado: todos https; sin enlaces `http://` con tokens firmados.
- Código: `Email.php` (enlaces firmados), `TemplateEmails.php`.
- Cubierta: NUEVA. **M**.

**PS-35. Editor del invitado sin subida de ficheros ni botones peligrosos.**
- Qué: homólogo de las subidas sin autenticar de JCE/SP Page Builder/iCagenda/Balbooa y de CVE-2026-73373 (SHTML). Engage declara `buttons="false"` en `comment_new.xml:51` pero el formulario del backend (`comment.xml:108`) y el editor configurado por el sitio (JCE, TinyMCE) pueden aportar medios.
- Cómo: como invitado y registrado sin `core.create` en com_media, abrir el comentario con cada editor (none, TinyMCE, JCE) y comprobar que no hay botón de imagen/medios/código fuente; POST directo a endpoints de subida del editor con la sesión de invitado.
- Esperado: sin botones ni subidas; si JCE está presente, su perfil público no tiene "upload".
- Cubierta: NUEVA. **M**.

**PS-36. Event handling de plugins en J6 (eventos concretos e inmutables).**
- Qué: los 10 plugins siguen funcionando cuando Joomla 6 despacha eventos como clases concretas (`ReshapeArgumentsAware`, `ResultAware`, eventos inmutables; el soporte legado se retirará en 7.0): `onContentAfterDisplay`, `onContentPrepareData`, `onUserAfterDelete/BeforeDelete`, `onContentBeforeSave`, `onAjax*`, eventos propios `onAkEngage*` lanzados con `RunPluginsTrait`.
- Cómo: ejecutar la tabla de eventos con las clases reales de J6.1 (`Joomla\CMS\Event\...`), comprobando `getArguments()` posicional y `setArgument('result', ...)`; test con J5.4 (clase genérica `Event`). Activar el registro de obsolescencias (`log-deprecated`) y contar avisos.
- Esperado: mismos resultados en J5.4 y J6.1; cero `E_USER_DEPRECATED` propios.
- Código: `plugins/*/src/Extension/*.php`, `component/backend/src/Mixin/RunPluginsTrait.php` (bug latente de `triggerPluginEvent` con `$dispatcher` null anotado en Fase 2), `TriggerEventTrait.php`.
- Cubierta: NUEVA. **A+** (clon de J6 y clases de eventos reales) y **M**.

**PS-37. Contenido, módulos y opciones de artículo en J6 (regresiones conocidas).**
- Qué: Engage se muestra una sola vez por artículo en vistas artículo, categoría blog, destacados, módulos de artículos y bajo `onContentAfterDisplay`; funciona con la regresión "opciones del artículo ignoradas" de 6.1.2/5.4.7 (hay hotfix) y con los cambios en `featured` de 6.0.1.
- Cómo: artículo con parámetros propios `show_*`; categoría blog; módulo `mod_articles`; modo `tmpl=component`; artículo multilingüe.
- Esperado: sin duplicados ni ausencias.
- Cubierta: NUEVA. **M**.

### 4.4 BAJO

**PS-38. Sin web services (API) de Engage; no filtrar datos por la API del core.**
- Qué: el componente no tiene carpeta `api/` ni `plugins/webservices` (comprobado con `ls src/component` y `src/plugins`: no hay). Que ninguna ruta `/api/index.php/v1/engage/...` responde; que `com_users`/`com_content` API (con las ACL corregidas en 6.1.x) no expone filas de `#__engage_comments`.
- Cómo: petición con token de API de superusuario y sin token.
- Esperado: 404 para rutas de Engage.
- Cubierta: NUEVA. **M** (rápida).

**PS-39. Respuesta HTTP: cabeceras y CORS.**
- Qué: respuestas de Engage (HTML/JSON/redirect) sin cabeceras inyectables (`Content-Disposition`/`Location` con datos del usuario; CVE-2026-71572) y sin `Access-Control-Allow-Origin: *` con credenciales (CVE-2026-71573).
- Cómo: `Origin: https://evil.example` en cada endpoint; nombres con CRLF en redirecciones.
- Esperado: sin eco de Origin; sin CRLF.
- Cubierta: NUEVA. **M**.

**PS-40. Permisos y caché de HTMLPurifier (`cache/com_engage/htmlpurifier`).**
- Qué: carpeta creada con permisos mínimos (4.19.1 corrige `chmod` tras `mkdir`), no ejecutable ni servida por el web (`.htaccess`/`web.config`), no se `unserialize` nada que controle un visitante.
- Cómo: instalación limpia en Linux con `umask 022` y `077`; `stat`; petición directa a un `.ser` de la caché.
- Esperado: 0755 o menos, acceso denegado vía web.
- Cubierta: PARCIAL (`tests/07` ejecuta HTMLPurifier pero no revisa permisos). **A** (stat) + **M** (acceso web).

**PS-41. Consola: comando `CleanSpam` y plugin `console/engage` en J6.**
- Qué: se registra con `addCommand` en Joomla 6 (symfony/console 7.x) sin avisos y no es invocable por web.
- Cómo: `php cli/joomla.php list`, ejecutar el comando con `--help`, con base de datos vacía.
- Esperado: aparece y termina con código 0.
- Cubierta: NUEVA (el informe lo marca NV por falta de `vendor`). **M**/**A+**.

**PS-42. Exportaciones con fórmulas (CSV).**
- Qué: si Engage tiene o añade exportación CSV, escapar valores que empiecen por `=`, `+`, `-`, `@` (6.1.4 lo hizo para banners). Hoy no he encontrado exportación CSV en Engage (NV).
- Cómo: grep de `fputcsv`/`text/csv` en `src/`.
- Esperado: no existe o está escapada.
- Cubierta: NUEVA. **A**.

## 5. Pruebas de compatibilidad (J5 / J6 / PHP 8.1-8.5 / bases de datos)

Matriz mínima en CI (la existente solo corre PHP 8.3.6 con stubs, sin Joomla):

| Eje | Valores a cubrir | Notas verificadas / fuente |
|---|---|---|
| Joomla | 5.0.0 (mínimo del fork), 5.4.9 (o 5.4.10), 6.0.4, 6.1.4, 6.2.0 RC1/GA | 5.4.9 tiene regresión de API PATCH (no usa Engage, pero afecta a pruebas con API); 6.0.2 mailer `setSender`; 6.1.2 "opciones de artículo" (BQ/GH) |
| PHP | 8.1, 8.2 (solo J5), 8.3, 8.4, 8.5 | J6 exige 8.3.0 y recomienda 8.4 (BQ). 5.4.2/6.0.2 soportan 8.5 según notas [GH]. HTMLPurifier 4.19.1 declara compat. 8.5 |
| Base de datos | MySQL 8.0.13, 8.4; MariaDB 10.4, 10.11, 11.4; PostgreSQL 12-16 | Mínimos de J6: MySQL 8.0.13, MariaDB 10.4, PostgreSQL 12 (Fase 2 §4). Detalle de collation por defecto de MariaDB 11.x: NV |
| Instalación | limpia / actualización desde 3.4.2 / desinstalación / reinstalación | |
| Plugins de compat. | `behaviour/compat6` ON y OFF en J6; en J5.4 con el plugin presente | |
| Caché | off / conservadora / progresiva; caché de página del navegador y proxy | |

Pruebas de compatibilidad concretas (numeradas CP):
- **CP-01 (alta).** Instalar el paquete en J5.0, J5.4.9, J6.0.4, J6.1.4 con PHP mínimo de cada una; `preflight` rechaza J4 y PHP<8.1 (cubierto por `tests/09` con stub). **M**.
- **CP-02 (alta).** Actualización 3.4.2 → 3.4.2.1 en J5.4 y J6.1 con `compat6` apagado: sin `Class not found` (cubre 0.3.0). **M**.
- **CP-03 (alta).** Actualizar Joomla 5.4.x → 6.x con Engage instalado: la comprobación previa de com_joomlaupdate lo marca compatible (analizador real de `Update.php` ya probado en `tests/12`; falta la prueba con el servidor real publicado) y la actualización de Joomla no deja a Engage roto. **M**; la URL de GitHub y la release v3.4.2.1 estaban pendientes de publicar (CHANGELOG 0.6.1), NV.
- **CP-04 (alta).** Base de datos: ejecutar `install.mysql.utf8.sql` y las actualizaciones en MySQL 8.0/8.4 y MariaDB 10.4/10.11/11.4; con `sql_mode` estricto y `ONLY_FULL_GROUP_BY`; comprobar `utf8mb4` e índices; ejecutar toda la lista del comentarios, árbol de respuestas (`commentTreeSlice`) y borrado. **A+**.
- **CP-05 (alta).** PostgreSQL: el esquema es solo MySQL/MariaDB (Fase 2). Comprobar qué hace el instalador de Joomla 5/6 en un sitio PostgreSQL (¿instala el componente sin tablas y luego falla en tiempo de ejecución?) y que `preflight` aborte con mensaje claro. No hay hoy ninguna comprobación de driver en `script.engage.php` (NV: no lo he releído para esta tarea; el informe de Fase 2 no la menciona). **M** + posible cambio de código.
- **CP-06 (media).** PHP 8.4: sin `Deprecated` implícito-nullable en `src/` ni `vendor/` (cubierto estáticamente por `tests/06`); ejecución real con `error_reporting=E_ALL` y `display_errors=1` del flujo completo (comentar, moderar, correo, módulo). **A+/M**.
- **CP-07 (media).** PHP 8.5: ejecutar `tests/run.php` y el flujo completo; buscar `Deprecated` por `ReflectionProperty::setAccessible` (ya eliminado), literales de comillas invertidas, `__sleep`/`__wakeup` (obsolescencias anunciadas de 8.5; NV el detalle de cada una, comprobar con `php -l` en 8.5). **A+**.
- **CP-08 (media).** PHP 8.1: `tests/run.php` y el flujo completo en Joomla 5.0-5.4 (el fork declara 8.1 como mínimo, `platform_check` de HTMLPurifier 4.19.1 exige 8.1). Cuidar sintaxis solo 8.2+ (enum readonly, `true` como tipo, constantes en traits). **A** (con PHP 8.1) .
- **CP-09 (media).** Clases de entrada: `Joomla\Input\Input` (framework) en J5 y J6 en módulo, controlador del backend y plugin de contenido (hoy se importa la clase del framework en los tres puntos, bien; confirmar `getArray()` y `get->getString` con J6). **A+**.
- **CP-10 (media).** Constructores y eventos de plugins en J6 (`__construct(&$subject, $config)` obsoleto, retirada en 7.0) sin `E_USER_DEPRECATED` bloqueante; con `error_reporting` máximo en J6 y registro de obsolescencias. **M**.
- **CP-11 (media).** Idiomas: instalar con de-DE, fr-FR, nl-NL, el-GR presentes en el sitio (ya no se declaran, `tests/08`); verificar que la instalación no falla. **M**.
- **CP-12 (media).** `ComponentParameters` con reflexión sobre `ComponentHelper::$components` y `PluginHelper::$plugins`: en J6.0-6.2 las propiedades siguen `protected static`; si Joomla las cambia a otra estructura fallará. Prueba por clon. **A+**.
- **CP-13 (baja).** Joomla 6.2: lanzamiento previsto 2026-10-13; repetir toda la matriz con 6.2.0 RC1 y GA; revisar notas B/C. **M**. NV: no se han leído las notas B/C de 6.2.
- **CP-14 (baja).** Caché: instalar con caché de página y `engagecache` en J5.4 y J6.1 (ver PS-27).
- **CP-15 (baja).** Multi-idioma y asociaciones (los avisos XSS de com_associations afectan al core; comprobar que Engage no depende de ellos). **M**.

## 6. Lo no verificado

1. **Fuentes primarias bloqueadas**: no pude abrir ninguna página de `developer.joomla.org/security-centre`, ni NVD, ni el sitio de noticias de Joomla. Todos los CVE de las secciones 1 y 2 marcados [BQ] vienen de resúmenes de buscador. Hay incoherencias entre fuentes (p. ej. número de correcciones de 5.4.6/6.1.1: "10", "16" y "20"; fecha de 6.0.2: 6 o 31 de enero; un identificador CVE-2026-352212 que parece erróneo). Antes de citar un CVE en documentación pública hay que contrastarlo en developer.joomla.org o en NVD.
2. **Años**: los resúmenes de las páginas de GitHub fallaron con el año en varias versiones; las fechas día/mes son coherentes con 2025-2026.
3. Versiones **afectadas exactas** por CVE (rangos "4.0.0-5.4.8", etc.) provienen de [BQ].
4. **Joomla infrastructure compromise** (sitios oficiales fuera de línea en junio) y **ChronoEngine "invitado a Super User"**: no verificados.
5. Alcance real de **Helix3 CVE-2026-49049** (defacement vs RCE) y número de sitios de **Smart Slider 3 Pro**.
6. **Explotación en la naturaleza de fallos del núcleo**: no encontré ninguna; no es prueba de ausencia.
7. **Deserialización** en el ecosistema Joomla 2026: solo mención genérica de la ACSC; no encontré CVE concreto.
8. **CVE de otras extensiones de comentarios** (JComments, Komento, Kunena, JomComment): ninguno de 2025-2026 hallado; **no** es prueba de ausencia.
9. **Ninguna de las pruebas de este documento se ha ejecutado**: es una lista de trabajo. En particular, no he ejecutado nada contra un Joomla real ni con PHP 8.4/8.5, ni contra MySQL/MariaDB/PostgreSQL reales.
10. Hallazgos nuevos sobre el código (PS-03 `default.php:209`/`default_list.php:253` sin escapar, PS-22 `MailTemplateHotFix` sin límite superior de versión, PS-08 formulario con `id`/`parent_id`/`asset_id` enviables por el usuario, PS-13 `akengage_limit`) provienen de lectura rápida de ficheros concretos para esta tarea; su explotabilidad **no** está confirmada.
11. Comportamiento de `Uri::isInternal`, `IpHelper::getIp()` y `InputFilter` en las versiones concretas que llevan J5.4.9 y J6.1.4: no se ha leído su código (el informe de Fase 2 ya marcaba S6 como NV).
12. Notas de compatibilidad hacia atrás de Joomla 6.2 y collation por defecto de MariaDB 11.x.
13. Fechas de publicación de 5.4.10 (corrige la regresión de API) y de 6.2.0 GA (prevista 13-oct-2026 según roadmap en resultados de búsqueda).
