# Cómo publicar una versión (release) del fork

Copyright (c)2026 fork comunitario de Engage iniciado por ChasKa; GNU General Public License v3 o posterior.

Estado actual: la release **v3.4.2.1 ya está publicada y es inmutable** (ZIP `pkg_engage-3.4.2.1.zip`, SHA-256 `a1891a7cd729ba3ac6585c0a9fea8ba70a0e8695dcd1a246b561aac1869b2958`): no se toca, no se vuelve a subir y su etiqueta no se mueve. La versión **3.4.3** (fork 0.8.1: reacciones a los comentarios, ordenar, favoritos, copiar enlace e insignias, con las correcciones de las dos revisiones de seguridad independientes; **la 0.8.1 corrige un XSS reflejado heredado de Akeeba que afecta también a la 3.4.2 y a la 3.4.2.1 ya publicada**) está preparada en el repositorio, pero **su ZIP y su release `v3.4.3` todavía no existen en GitHub**. Hasta que se publiquen (pasos de abajo), el servidor de actualizaciones de esta rama (`updates/pkgengage.xml`, versión 3.4.3) apunta a una descarga que daría error 404. Por eso **esta rama no debe fusionarse en `main` hasta que la release `v3.4.3` exista**: mientras `main` conserve el XML de la 3.4.2.1, ningún sitio ve una descarga inexistente. Joomla trata 3.4.3 como mayor que 3.4.2.1 (`version_compare`) y la ofrece como actualización sobre la publicada.

## Aviso importante: las releases son inmutables

Una vez **publicada**, una release no debe cambiarse: ni el ZIP, ni la etiqueta (tag). GitHub puede tener activada la "inmutabilidad de releases" (Settings > General, apartado Releases) y, aunque no lo esté, Joomla comprueba el ZIP con el `<sha256>` de `updates/pkgengage.xml`: si el ZIP cambia, todas las descargas fallan. Por eso:

1. Pulsa primero **Save draft** (guardar borrador), revisa todo, y solo entonces **Publish release**.
2. Si te equivocas después de publicar, no edites ni borres: publica una versión nueva (3.4.4) con todo el proceso. La v3.4.2.1 ya publicada tampoco se corrige: su arreglo, si hiciera falta, iría en una versión posterior.

## Antes de empezar: obtener el ZIP exacto

En un ordenador con PHP 8 (con las extensiones `zip` y `simplexml`), en la carpeta del proyecto:

```
php build/build.php
```

Al final muestra `SHA-256 <número largo>` y deja el ZIP en `dist/pkg_engage-3.4.3.zip`. El SHA-256 debe ser **idéntico** al que figura en `updates/pkgengage.xml` (línea `<sha256>`). El de la versión preparada (3.4.3) es:

```
31ca682c6d29f3ad009e78e528c1285dbb43d81a2f9dde7f94c3776339b99776
```

Si no coincide, no subas el ZIP: avisa (puede deberse a otra versión de PHP o de zlib; el hash que cuenta es el del ZIP que se sube, y `updates/pkgengage.xml` tiene que llevar ese mismo).

## Pasos en GitHub (web)

1. Asegúrate de que `main` ya contiene la versión 3.4.3 (el responsable sincroniza `main` justo DESPUÉS de crear la release; en `updates/pkgengage.xml` debe verse `<version>3.4.3</version>`).
2. Entra en https://github.com/chaska/engage y pulsa **Releases** (columna derecha) y luego **Draft a new release** (Crear una nueva release).
3. En **Choose a tag** (Elegir etiqueta) escribe exactamente `v3.4.3` y pulsa **Create new tag: v3.4.3 on publish**.
4. En **Target** (destino) deja seleccionada la rama `main`.
5. **Release title**: `Engage 3.4.3 (fork comunitario)`. En la descripción puedes copiar las entradas 0.7.0, 0.7.1, 0.8.0 y 0.8.1 de `CHANGELOG.md` (destaque la corrección de seguridad de la 0.8.1).
6. Arrastra el archivo `pkg_engage-3.4.3.zip` a la zona **Attach binaries** (adjuntar archivos). El nombre debe ser exactamente ese; no lo renombres ni subas otros ZIP con otro nombre.
7. Deja marcado **Set as the latest release**. No marques "pre-release".
8. Pulsa **Save draft**. Comprueba: etiqueta `v3.4.3`, destino `main`, un solo archivo adjunto con ese nombre.
9. Pulsa **Publish release**.

## Comprobar después de publicar

1. Abre en el navegador `https://github.com/chaska/engage/releases/download/v3.4.3/pkg_engage-3.4.3.zip`: debe descargarse el ZIP.
2. (Opcional, con terminal) `sha256sum pkg_engage-3.4.3.zip` debe dar el hash de arriba.
3. Abre `https://raw.githubusercontent.com/chaska/engage/main/updates/pkgengage.xml`: debe verse el XML con la versión 3.4.3. (GitHub puede tardar unos minutos en refrescar esa URL.)
4. En un Joomla de pruebas con Engage 3.4.2.1 (o 3.4.2) instalado: Sistema > Actualizar > Extensiones > **Buscar actualizaciones**. Debe aparecer "Engage 3.4.3". Este paso (la oferta de actualización desde el servidor de GitHub) no se ha podido probar todavía en un Joomla real; sí se ha probado la instalación del ZIP 3.4.3 encima de la 3.4.2.1 publicada y de la 3.4.2 original.

## Sitio de actualizaciones antiguo de Akeeba (cdn.akeeba.com)

El paquete 3.4.2 registró en Joomla un "sitio de actualización" que apunta a `https://cdn.akeeba.com/updates/pkgengage.xml`.

- **No se desactiva por código en el instalador** (decisión documentada en la versión 0.6.2). Motivo: Joomla (plugin `extension/joomla`, función `addUpdateSite`) ejecuta su gestión de sitios de actualización **después** de terminar la actualización del paquete, y en ese momento, si el paquete tiene un único servidor, **reescribe la dirección (`location`) de la fila que ya existía** con la nueva. Un `enabled=0` puesto desde `postflight` quedaría aplicado a la fila ya apuntando al servidor del fork y lo dejaría **desactivado**, justo lo contrario de lo que se quiere. Además no se ha podido probar en un Joomla real.
- Con la actualización normal (3.4.2 a 3.4.3, o 3.4.2.1 a 3.4.3) Joomla reutiliza esa misma fila y la deja apuntando al servidor del fork, así que normalmente no queda ningún sitio de Akeeba. Solo el nombre mostrado seguirá siendo el antiguo ("Akeeba Engage Updates"); es solo cosmético.
- Compruébalo a mano tras actualizar: Sistema > Gestionar > **Sitios de actualización**, y busca "engage" o "akeeba". Si sigue existiendo una fila cuya dirección sea `cdn.akeeba.com/updates/pkgengage.xml`, desactívala (clic en el icono de estado) o elimínala. Si lo prefieres por base de datos (cambia el prefijo `jos_` por el de tu sitio y haz copia antes):

```
UPDATE jos_update_sites SET enabled = 0 WHERE location LIKE '%cdn.akeeba.com/updates/pkgengage%';
```

- Riesgo si queda activo: ese servidor es de un tercero; si Akeeba publicara allí una versión superior a la instalada, Joomla la ofrecería y sustituiría el código del fork.

## Qué cambia la 3.4.3 en la base de datos

La 3.4.3 añade UNA tabla (`#__engage_reactions`) con el fichero `sql/updates/mysql/3.4.3-20261007.sql` (`CREATE TABLE IF NOT EXISTS`, repetible). Joomla lo ejecuta al actualizar desde la 3.4.2 original y desde la 3.4.2.1 publicada, porque ambas dejaron el esquema en `3.0.2-20220107` y el nombre nuevo es mayor según `version_compare`. No modifica ninguna tabla existente. Al desinstalar el componente se elimina. Detalle en [`REACCIONES.md`](REACCIONES.md).

## Para publicar una versión futura

1. Sube la versión en los manifiestos y añade la entrada al `CHANGELOG.md`.
2. Cambia `<version>` y la `downloadurl` (carpeta `v<versión>` y nombre del ZIP) en `updates/pkgengage.xml`. `php build/build.php` se niega a continuar si la versión del XML no coincide con la del paquete.
3. `php build/build.php` (escribe el `<sha256>` en el XML) y `php tests/run.php`.
4. Sincroniza `main` y publica la release siguiendo los pasos de arriba. **Primero la release con el ZIP, y después (o a la vez) `main` con el XML nuevo**, para que ninguna instalación vea una descarga inexistente.
