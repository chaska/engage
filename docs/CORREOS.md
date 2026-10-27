# Correos de aviso de Engage (0.9.0)

## Qué hace Engage con los correos

Engage envía avisos de comentarios (a moderadores, al autor del contenido y a quienes participan en el hilo) con las **plantillas de correo de Joomla** (Sistema > Plantillas de correo). Esas plantillas **no se tocan**: ni las de Engage ni las que usted haya personalizado. Lo que hace desde la 0.9.0 es decorar el mensaje **justo al enviarlo**:

1. **Diseño HTML enriquecido** (opción «Diseño HTML enriquecido en los correos», `email_rich`, Sí por defecto): cabecera con el nombre del sitio y el tipo de aviso, tarjeta con el contenido, botones, pie. Todo con CSS en línea y tablas (lo que exigen los clientes de correo), ancho máximo de 600 píxeles, adaptable a móvil y con modo oscuro (`color-scheme`, `prefers-color-scheme`). **No** carga imágenes (se quitan el avatar y las imágenes del comentario), fuentes, hojas de estilo ni nada externo; **no** lleva píxeles de seguimiento ni JavaScript; solo admite enlaces `http(s)` y `mailto`.
2. **Aviso de confidencialidad y protección de datos** (opción «Mostrar aviso de confidencialidad», `email_confidential`, Sí por defecto) al pie, también en la parte de texto plano.
3. **Color de acento** (`email_accent`): lista cerrada de seis tonos (azul, violeta, verde, rojo, naranja, pizarra).

Con las dos primeras opciones en **No** los correos salen exactamente como antes de la 0.9.0.

Con plantillas personalizadas: el HTML de su plantilla se limpia (sin `script`, `style`, formularios, imágenes…) y se coloca dentro de la tarjeta; los elementos de las plantillas por defecto de Engage (barra con el nombre del sitio, bloque legal) se recolocan en la cabecera y el pie.

Los botones solo aparecen si hay dirección: con «Incluir enlaces en los correos» en No, o sin una dirección fiable del sitio (véase `docs/PANEL.md`), los botones desaparecen. El enlace de baja de los suscriptores se mantiene siempre que haya dirección fiable.

## Siempre HTML + texto, con cualquier «Formato de correo» de Joomla (0.9.6)

**Qué pasaba:** el «Formato de correo» de Joomla (Sistema > Correos electrónicos > Opciones, `mail_style`) vale **«Solo texto» por defecto**. Con él Joomla no construye ninguna parte HTML y manda el mensaje como `text/plain`: el diseño de la 0.9.0 no tenía dónde aplicarse y llegaba un correo plano, con las direcciones largas a la vista (y, si la plantilla usaba `{COMMENT_SANITIZED}`, el comentario con sus etiquetas `<p>`).

**Qué hace ahora Engage**, sea cual sea ese formato («solo texto», «HTML» o «ambos»):

- Todos los correos salen `multipart/alternative`: texto plano **y** HTML con el diseño. Engage fuerza el formato **solo en memoria, mientras construye y envía ese mensaje**; la configuración guardada de Joomla no se toca.
- **HTML:** ninguna dirección a la vista. Botones con texto: «Ver comentarios»; en los correos de moderación «Publicar», «Despublicar», «Eliminar», «Posible spam», «Spam y eliminar» o «No es spam» según el caso; y a los suscriptores, «Darse de baja» (baja firmada). En inglés: «View comments», «Publish»… «Unsubscribe».
- **Texto:** cada dirección en su propia línea con su etiqueta (`Publicar:` y debajo la dirección), también la de baja. El comentario va como texto limpio: párrafos y saltos de línea, sin etiquetas, entidades decodificadas una sola vez.
- **Aviso de confidencialidad** en el HTML y en el texto de **todos** los correos (`manage`, `notify`, `notify_author`, `notify_managers`, `spam`), también con plantillas personalizadas o antiguas, mientras la opción esté en Sí (por defecto). En el idioma de quien lo recibe (es-ES con «usted», en-GB).
- «Incluir enlaces en los correos» = No: desaparecen «Ver comentarios» y los botones de moderación (y sus direcciones del texto); **la baja de los suscriptores se mantiene siempre**.
- Si por cualquier motivo el diseño no se pudiera aplicar, el correo se envía igualmente y el motivo (solo clase de error y mensaje) queda en el registro de errores de PHP.

Si aun así un correo llega plano, comprobar: que el paquete instalado sea la 3.4.10 o posterior (Sistema > Información > el panel de Engage dice la versión), que no haya un plugin de correo de terceros que reescriba el mensaje al enviarlo y el registro de errores de PHP (busque «Engage: the email was sent without the HTML design»).

## Cómo se ha comprobado

Renderizados en Chromium a partir de los correos realmente enviados por un Joomla de pruebas (`tests/joomla-live/lib/correos-navegador.js`): escritorio y móvil, claro y oscuro, es-ES y en-GB. Las capturas no se incluyen en el repositorio; la prueba las genera en su directorio de trabajo.

## Aviso de confidencialidad: texto estándar

> **Es un texto estándar, redactado con carácter general. Conviene que lo revise su asesoría legal** (responsable del tratamiento, base jurídica, plazos de conservación, derechos y vías de contacto reales de su sitio) antes de darlo por bueno.

El texto está en cadenas de idioma, de modo que puede cambiarlo sin tocar el código con los **overrides de idioma de Joomla** (Sistema > Idioma > Sobrescrituras): `COM_ENGAGE_MAILWRAP_CONF_TITLE`, `COM_ENGAGE_MAILWRAP_CONF_BODY`, `COM_ENGAGE_MAILWRAP_CONF_PRIVACY` (sin correo de contacto), `COM_ENGAGE_MAILWRAP_CONF_PRIVACY_CONTACT` (con el correo de contacto de Joomla, `mailfrom`), `COM_ENGAGE_MAILWRAP_UNSUB`, `COM_ENGAGE_MAILWRAP_UNSUB_LABEL`, `COM_ENGAGE_MAILWRAP_UNSUB_NOLINK`, `COM_ENGAGE_MAILWRAP_SENT_BY` y los títulos `COM_ENGAGE_MAILWRAP_HEAD_*`. El idioma del correo es el del destinatario (o el del sitio) como hasta ahora; si falta la traducción se usa en-GB.

Qué dice, en esencia: que el mensaje y sus adjuntos son confidenciales y van dirigidos exclusivamente a su destinatario; que si lo ha recibido por error debe avisar al remitente y eliminarlo, sin copiarlo, usarlo ni divulgarlo; que no se garantiza la seguridad ni la integridad de las comunicaciones por correo electrónico; que sus datos se tratan conforme al RGPD para gestionar los comentarios y las notificaciones y cómo ejercer sus derechos contactando con el responsable del sitio (el nombre del sitio y, si está configurado, el correo de contacto de Joomla); y, en los correos a suscriptores, cómo darse de baja.

## Vista previa y correo de prueba

Panel de control > «Vista previa de los correos y correo de prueba» (solo con permiso `core.admin` de Engage):

- La **vista previa** enseña el correo de moderador o de suscriptor, en español o en inglés, con datos de ejemplo y las opciones actuales. Se pinta en un `iframe` con `sandbox` (sin scripts) y no hace ninguna petición externa ni envía nada. Usa las plantillas por defecto de Engage; el correo de prueba, en cambio, usa la plantilla guardada en Joomla.
- **Enviar correo de prueba**: POST con token, solo para `core.admin`, **solo a la dirección de su cuenta**, y como máximo uno por minuto y sesión.

## Compatibilidad

Probado en Joomla 6.1.4 con SMTP de captura y Chromium (claro y oscuro, escritorio y móvil; las capturas las genera la prueba, no se incluyen). No se ha probado en Gmail, Outlook ni Apple Mail reales: el diseño sigue las prácticas habituales (tablas, CSS en línea, sin dependencias) para funcionar en ellos.
