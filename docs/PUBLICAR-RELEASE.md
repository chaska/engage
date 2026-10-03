# Cómo publicar una versión (release) del fork

Copyright (c)2026 fork comunitario de Engage iniciado por ChasKa; GNU General Public License v3 o posterior.

Estado actual: la versión **3.4.2.1** está preparada en el repositorio, pero **el ZIP y la release todavía no existen en GitHub**. Hasta que se publiquen (pasos de abajo), el servidor de actualizaciones (`updates/pkgengage.xml`) apunta a una descarga que da error 404. Joomla no instala nada en ese caso (falla la descarga y no toca el sitio), pero conviene publicar la release justo después de sincronizar `main`.

## Aviso importante: las releases son inmutables

Una vez **publicada**, una release no debe cambiarse: ni el ZIP, ni la etiqueta (tag). GitHub puede tener activada la "inmutabilidad de releases" (Settings > General, apartado Releases) y, aunque no lo esté, Joomla comprueba el ZIP con el `<sha256>` de `updates/pkgengage.xml`: si el ZIP cambia, todas las descargas fallan. Por eso:

1. Pulsa primero **Save draft** (guardar borrador), revisa todo, y solo entonces **Publish release**.
2. Si te equivocas después de publicar, no edites ni borres: publica una versión nueva (3.4.2.2) con todo el proceso.

## Antes de empezar: obtener el ZIP exacto

En un ordenador con PHP 8 (con las extensiones `zip` y `simplexml`), en la carpeta del proyecto:

```
php build/build.php
```

Al final muestra `SHA-256 <número largo>` y deja el ZIP en `dist/pkg_engage-3.4.2.1.zip`. El SHA-256 debe ser **idéntico** al que figura en `updates/pkgengage.xml` (línea `<sha256>`). El de la versión preparada es:

```
fe3c45b159e17b93a69dcfb98ef4955ae629d2c586fa75a1c1fee03863dcc0dd
```

Si no coincide, no subas el ZIP: avisa (puede deberse a otra versión de PHP o de zlib; el hash que cuenta es el del ZIP que se sube, y `updates/pkgengage.xml` tiene que llevar ese mismo).

## Pasos en GitHub (web)

1. Asegúrate de que `main` ya contiene la versión 3.4.2.1 (el responsable sincroniza `main`; en `updates/pkgengage.xml` debe verse `<version>3.4.2.1</version>`).
2. Entra en https://github.com/chaska/engage y pulsa **Releases** (columna derecha) y luego **Draft a new release** (Crear una nueva release).
3. En **Choose a tag** (Elegir etiqueta) escribe exactamente `v3.4.2.1` y pulsa **Create new tag: v3.4.2.1 on publish**.
4. En **Target** (destino) deja seleccionada la rama `main`.
5. **Release title**: `Engage 3.4.2.1 (fork comunitario)`. En la descripción puedes copiar la entrada 0.6.0 y siguientes de `CHANGELOG.md`.
6. Arrastra el archivo `pkg_engage-3.4.2.1.zip` a la zona **Attach binaries** (adjuntar archivos). El nombre debe ser exactamente ese; no lo renombres ni subas otros ZIP con otro nombre.
7. Deja marcado **Set as the latest release**. No marques "pre-release".
8. Pulsa **Save draft**. Comprueba: etiqueta `v3.4.2.1`, destino `main`, un solo archivo adjunto con ese nombre.
9. Pulsa **Publish release**.

## Comprobar después de publicar

1. Abre en el navegador `https://github.com/chaska/engage/releases/download/v3.4.2.1/pkg_engage-3.4.2.1.zip`: debe descargarse el ZIP.
2. (Opcional, con terminal) `sha256sum pkg_engage-3.4.2.1.zip` debe dar el hash de arriba.
3. Abre `https://raw.githubusercontent.com/chaska/engage/main/updates/pkgengage.xml`: debe verse el XML con la versión 3.4.2.1. (GitHub puede tardar unos minutos en refrescar esa URL.)
4. En un Joomla de pruebas con Engage 3.4.2 instalado: Sistema > Actualizar > Extensiones > **Buscar actualizaciones**. Debe aparecer "Engage 3.4.2.1". Este paso no se ha podido probar todavía en un Joomla real.

## Sitio de actualizaciones antiguo de Akeeba (cdn.akeeba.com)

El paquete 3.4.2 registró en Joomla un "sitio de actualización" que apunta a `https://cdn.akeeba.com/updates/pkgengage.xml`.

- **No se desactiva por código en el instalador** (decisión documentada en la versión 0.6.2). Motivo: Joomla (plugin `extension/joomla`, función `addUpdateSite`) ejecuta su gestión de sitios de actualización **después** de terminar la actualización del paquete, y en ese momento, si el paquete tiene un único servidor, **reescribe la dirección (`location`) de la fila que ya existía** con la nueva. Un `enabled=0` puesto desde `postflight` quedaría aplicado a la fila ya apuntando al servidor del fork y lo dejaría **desactivado**, justo lo contrario de lo que se quiere. Además no se ha podido probar en un Joomla real.
- Con la actualización normal (3.4.2 a 3.4.2.1) Joomla reutiliza esa misma fila y la deja apuntando al servidor del fork, así que normalmente no queda ningún sitio de Akeeba. Solo el nombre mostrado seguirá siendo el antiguo ("Akeeba Engage Updates"); es solo cosmético.
- Compruébalo a mano tras actualizar: Sistema > Gestionar > **Sitios de actualización**, y busca "engage" o "akeeba". Si sigue existiendo una fila cuya dirección sea `cdn.akeeba.com/updates/pkgengage.xml`, desactívala (clic en el icono de estado) o elimínala. Si lo prefieres por base de datos (cambia el prefijo `jos_` por el de tu sitio y haz copia antes):

```
UPDATE jos_update_sites SET enabled = 0 WHERE location LIKE '%cdn.akeeba.com/updates/pkgengage%';
```

- Riesgo si queda activo: ese servidor es de un tercero; si Akeeba publicara allí una versión superior a la instalada, Joomla la ofrecería y sustituiría el código del fork.

## Para publicar una versión futura

1. Sube la versión en los manifiestos y añade la entrada al `CHANGELOG.md`.
2. Cambia `<version>` y la `downloadurl` (carpeta `v<versión>` y nombre del ZIP) en `updates/pkgengage.xml`. `php build/build.php` se niega a continuar si la versión del XML no coincide con la del paquete.
3. `php build/build.php` (escribe el `<sha256>` en el XML) y `php tests/run.php`.
4. Sincroniza `main` y publica la release siguiendo los pasos de arriba. **Primero la release con el ZIP, y después (o a la vez) `main` con el XML nuevo**, para que ninguna instalación vea una descarga inexistente.
