# Panel de control de Engage (0.6.24)

Desde la 0.6.24, al pulsar **Akeeba Engage** en el menú de administración de Joomla se abre un **panel de control** en lugar de caer directamente en la lista de comentarios. Los comentarios, las plantillas de email y las opciones siguen donde estaban y funcionan igual.

![Panel en el diseño Claro](img/panel-claro.png)

## Qué hay

- **Accesos rápidos**, con icono en cuadrado de color: Comentarios (con insignias de comentarios pendientes y de spam), Plantillas de email, Opciones y Permisos (solo si usted puede administrar el componente) y Acerca de.
- **Actividad**: total, publicados, por moderar, spam y comentarios recibidos en los últimos 7 y 30 días, con un mini-gráfico de barras de los últimos 30 días. El gráfico es un SVG dibujado en la propia página (sin librerías); tiene descripción para lectores de pantalla, ventana de detalle al pasar el ratón o tocar, y una tabla con los mismos datos («Ver como tabla»). Los días se cuentan en UTC.
- **Últimos comentarios** con acciones rápidas: publicar, marcar como spam y eliminar. Cada acción abre una ventana de confirmación propia (accesible, con el foco en «Cancelar»; nunca el `confirm()` del navegador) y, al aceptar, usa las tareas que ya existían (`comments.publish`, `comments.possiblespam`, `comments.delete`) con el token de seguridad de Joomla y vuelve al panel. Los botones solo aparecen si usted tiene el permiso correspondiente (cambiar estado, eliminar), y las tareas lo vuelven a comprobar en el servidor. «Marcar como spam» no borra: deja el comentario en el estado de spam, donde se puede revisar.
- **Más comentados**: los cinco contenidos con más comentarios publicados.
- **Estado y recomendaciones** (semáforo verde, ámbar y rojo, con icono y texto, nunca solo color) y un botón para arreglar cada punto:

| Punto | Rojo | Ámbar |
|---|---|---|
| Plugin de contenido (comentarios en los artículos) | desactivado o no instalado | |
| Plugin de correo (avisos) | | desactivado o no instalado |
| Caché de página | | caché de Joomla activa y plugin de caché de Engage apagado |
| Protección contra spam | | sin CAPTCHA válido ni Akismet con clave |
| Moderación | publicación inmediata y sin ninguna protección contra spam | |
| Gravatar | | modo «siempre» (contacta con gravatar.com sin consentimiento) o JBCookies sin grupo |
| Filtro de HTML | etiqueta peligrosa en la lista (`script`, `iframe`, `object`, `embed`, `style`, `form`) | modo «joomla», o `img[src]` permitido (el visitante contacta con el servidor de la imagen) |
| Carpeta de caché de HTML Purifier | | no escribible o no comprobable |
| Versión de PHP | menor que 8.1 | menor que 8.2 |
| Versión de la base de datos | menor que 8.0.13 (MySQL) o 10.4.0 (MariaDB) | no se pudo leer |
| Servidor de actualizaciones | | no hay, está desactivado o no apunta al fork |

- **Acerca de**: versión del paquete instalado (la guarda Joomla), versión del fork, licencia (GPL v3 o posterior), autor original (Nicholas K. Dionysopoulos / Akeeba Ltd) y el aviso de que es un fork comunitario no oficial, sin afiliación con Akeeba Ltd, con enlaces al repositorio, al registro de cambios, a esta documentación y al sitio del autor original.

## Tres diseños y «Automático»

El selector de la cabecera tiene **Claro**, **Medio** (pizarra de tono cálido), **Oscuro** y **Automático** (sigue el modo claro/oscuro del dispositivo). La preferencia se guarda en el navegador (`localStorage`, clave `eg-admin-theme`, dentro de `try/catch`) y se aplica **antes de pintar** la página (un script pequeño sin `defer` en la cabecera), así que no hay parpadeo. Sin JavaScript, o sin preferencia guardada, manda el dispositivo por CSS puro.

![Panel en el diseño Medio](img/panel-medio.png)
![Panel en el diseño Oscuro](img/panel-oscuro.png)

El diseño del panel es independiente del tema de administración de Joomla (Atum claro u oscuro): todo el CSS cuelga del contenedor `.eg-admin` y de variables `--eg-admin-*`, y no toca nada fuera de él. El selector se maneja con teclado (flechas, Inicio y Fin) y anuncia el cambio a los lectores de pantalla.

### Accesibilidad

Teclado completo y foco visible en todos los controles, `role="radiogroup"` en el selector, `role="status"` para los avisos, ventana modal con `<dialog>`, `prefers-reduced-motion`, modo de contraste forzado, adaptable desde 360 px, sin estilos ni scripts en línea (compatible con una CSP estricta). El contraste se **mide**: `tests/28-panel.php` calcula 250+ pares sobre las variables del CSS y `tests/joomla-live/11-panel.sh` mide en Chromium cada texto visible del panel contra su fondo real (mínimo 4,5:1; 3:1 para iconos, gráfico y anillo de foco) en los tres diseños y en escritorio y móvil.

## Permisos

- Ver el panel: `core.manage` del componente (Joomla lo exige antes de llegar a nuestro código).
- Accesos a Opciones y Permisos y botón «Opciones» de la barra: `core.admin` o `core.options`.
- Publicar y marcar como spam: `core.edit.state`. Eliminar: `core.delete`. Los comprueban las tareas existentes.
- Los botones para abrir un plugin solo se ofrecen si usted puede gestionar y editar plugins.

## Privacidad: cero conexiones externas

El panel **no se conecta a ningún servidor**: no comprueba versiones, no descarga noticias, no carga tipografías, iconos ni scripts de ningún CDN. Los iconos son SVG propios dentro de la página y la tipografía es la del sistema. Los únicos enlaces a otros sitios (repositorio, registro de cambios, documentación y autor original) los abre usted y llevan `rel="noopener noreferrer"`. `tests/joomla-live/11-panel.sh` registra todas las peticiones del navegador y exige que ninguna sea a otro host.

## Actualizar desde la 3.4.2: el menú de administración

Comprobado en un Joomla 6.1.4 real, actualizando desde el paquete 3.4.2 original (`tests/joomla-live/04-actualizacion.sh`): el elemento «Akeeba Engage» del menú sigue apuntando a `index.php?option=com_engage` y ahora abre el panel. Joomla vuelve a crear los elementos del menú al actualizar (el identificador del elemento cambia, el título y el enlace no) y, como el manifiesto declara ahora un submenú, aparecen debajo **Panel de control**, **Comentarios** y **Plantillas de email**. No hay cambios en la base de datos. Cualquier enlace antiguo con `view=comments` o `view=emailtemplates` sigue funcionando.

## Archivos

`backend/src/Controller/ControlpanelController.php`, `backend/src/View/Controlpanel/HtmlView.php`, `backend/tmpl/controlpanel/default.php`, `backend/src/Helper/Panel{Data,Health,Chart,Icons}.php`, `media/css/panel.css`, `media/js/panel.js`, `media/js/panel-theme.js`, cadenas `COM_ENGAGE_PANEL_*` en `com_engage.ini` y `COM_ENGAGE_MENU_*` en `com_engage.sys.ini` (en-GB y es-ES). `PanelHealth` y `PanelChart` son funciones puras (probadas con datos simulados); `PanelData` solo lee de la base de datos, con el constructor de consultas de Joomla y parámetros enlazados.
