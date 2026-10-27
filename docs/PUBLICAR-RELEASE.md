# Cómo publicar una versión (release) del fork

Copyright (c)2026 fork comunitario de Engage iniciado por ChasKa; GNU General Public License v3 o posterior.

Estado actual: las releases **v3.4.2.1** (ZIP `pkg_engage-3.4.2.1.zip`, SHA-256 `a1891a7cd729ba3ac6585c0a9fea8ba70a0e8695dcd1a246b561aac1869b2958`) y **v3.4.3** (ZIP `pkg_engage-3.4.4.zip`, SHA-256 `31ca682c6d29f3ad009e78e528c1285dbb43d81a2f9dde7f94c3776339b99776`; fork 0.8.1) **ya están publicadas y son inmutables**: no se tocan, no se vuelven a subir y sus etiquetas no se mueven. **Numeración:** 3.4.5 fue un número de trabajo que NUNCA se publicó y queda saltado (se entregaron varios ZIP distintos con ese nombre); cada entrega tiene ahora un número de paquete único y el ZIP lleva ese número. La versión **3.4.19** (fork 0.9.15; resume los cambios de 0.8.2 a 0.9.15, véase `CHANGELOG.md`) está preparada en el repositorio, pero **su ZIP y su release `v3.4.19` todavía no existen en GitHub**. Hasta que se publiquen (pasos de abajo), el servidor de actualizaciones de esta rama (`updates/pkgengage.xml`, versión 3.4.19) apunta a una descarga que daría error 404. Por eso **esta rama no debe fusionarse en `main` hasta que la release `v3.4.19` exista**. Joomla trata 3.4.19 como mayor que 3.4.14, 3.4.3 y 3.4.2.1 (`version_compare`) y la ofrece como actualización sobre cualquiera de las publicadas.

## Aviso importante: las releases son inmutables

Una vez **publicada**, una release no debe cambiarse: ni el ZIP, ni la etiqueta (tag). GitHub puede tener activada la "inmutabilidad de releases" (Settings > General, apartado Releases) y, aunque no lo esté, Joomla comprueba el ZIP con el `<sha256>` de `updates/pkgengage.xml`: si el ZIP cambia, todas las descargas fallan. Por eso:

1. Pulsa primero **Save draft** (guardar borrador), revisa todo, y solo entonces **Publish release**.
2. Si te equivocas después de publicar, no edites ni borres: publica una versión nueva (con número de paquete único) con todo el proceso. Las v3.4.2.1 y v3.4.3 ya publicadas tampoco se corrigen: su arreglo, si hiciera falta, iría en una versión posterior.

## Antes de empezar: obtener el ZIP exacto

En un ordenador con PHP 8 (con las extensiones `zip` y `simplexml`), en la carpeta del proyecto:

```
php build/build.php
```

Al final muestra `SHA-256 <número largo>` y deja el ZIP en `dist/pkg_engage-3.4.19.zip`. El SHA-256 debe ser **idéntico** al que figura en `updates/pkgengage.xml` (línea `<sha256>`). El de la versión preparada (3.4.19) es:

```
bfd33bdb273db1f3df61948a968da2fe7e5c46d1fdbf24427b99c5c488408c65
```

Si no coincide, no subas el ZIP: avisa (puede deberse a otra versión de PHP o de zlib; el hash que cuenta es el del ZIP que se sube, y `updates/pkgengage.xml` tiene que llevar ese mismo).

## Pasos en GitHub (web)

1. Asegúrate de que `main` ya contiene la versión 3.4.19 (el responsable sincroniza `main` justo DESPUÉS de crear la release; en `updates/pkgengage.xml` debe verse `<version>3.4.19</version>`).
2. Entra en https://github.com/chaska/engage y pulsa **Releases** (columna derecha) y luego **Draft a new release** (Crear una nueva release).
3. En **Choose a tag** (Elegir etiqueta) escribe exactamente `v3.4.19` y pulsa **Create new tag: v3.4.19 on publish**.
4. En **Target** (destino) deja seleccionada la rama `main`.
5. **Release title**: `Engage 3.4.19 (fork comunitario)`. En la descripción puedes copiar las entradas 0.8.2 a 0.9.15 de `CHANGELOG.md` (y, si quiere, las 0.7.0 a 0.8.1 de la 3.4.3 ya publicada).
6. Arrastra el archivo `pkg_engage-3.4.19.zip` a la zona **Attach binaries** (adjuntar archivos). El nombre debe ser exactamente ese; no lo renombres ni subas otros ZIP con otro nombre.
7. Deja marcado **Set as the latest release**. No marques "pre-release".
8. Pulsa **Save draft**. Comprueba: etiqueta `v3.4.19`, destino `main`, un solo archivo adjunto con ese nombre.
9. Pulsa **Publish release**.

## Comprobar después de publicar

1. Abre en el navegador `https://github.com/chaska/engage/releases/download/v3.4.19/pkg_engage-3.4.19.zip`: debe descargarse el ZIP.
2. (Opcional, con terminal) `sha256sum pkg_engage-3.4.19.zip` debe dar el hash de arriba.
3. Abre `https://raw.githubusercontent.com/chaska/engage/main/updates/pkgengage.xml`: debe verse el XML con la versión 3.4.19. (GitHub puede tardar unos minutos en refrescar esa URL.)
4. En un Joomla de pruebas con Engage 3.4.3 (o 3.4.2.1 o 3.4.2) instalado: Sistema > Actualizar > Extensiones > **Buscar actualizaciones**. Debe aparecer "Engage 3.4.19". Este paso (la oferta de actualización desde el servidor de GitHub) no se ha podido probar todavía en un Joomla real; sí se ha probado la instalación del ZIP 3.4.19 encima de la 3.4.3 y de la 3.4.2.1 publicadas y de la 3.4.2 original.

## Sitio de actualizaciones antiguo de Akeeba (cdn.akeeba.com)

El paquete 3.4.2 registró en Joomla un "sitio de actualización" que apunta a `https://cdn.akeeba.com/updates/pkgengage.xml`.

- **No se desactiva por código en el instalador** (decisión documentada en la versión 0.6.2). Motivo: Joomla (plugin `extension/joomla`, función `addUpdateSite`) ejecuta su gestión de sitios de actualización **después** de terminar la actualización del paquete, y en ese momento, si el paquete tiene un único servidor, **reescribe la dirección (`location`) de la fila que ya existía** con la nueva. Un `enabled=0` puesto desde `postflight` quedaría aplicado a la fila ya apuntando al servidor del fork y lo dejaría **desactivado**, justo lo contrario de lo que se quiere. Además no se ha podido probar en un Joomla real.
- Con la actualización normal (3.4.2, 3.4.2.1 o 3.4.3 a 3.4.19) Joomla reutiliza esa misma fila y la deja apuntando al servidor del fork, así que normalmente no queda ningún sitio de Akeeba. Solo el nombre mostrado seguirá siendo el antiguo ("Akeeba Engage Updates"); es solo cosmético.
- Compruébalo a mano tras actualizar: Sistema > Gestionar > **Sitios de actualización**, y busca "engage" o "akeeba". Si sigue existiendo una fila cuya dirección sea `cdn.akeeba.com/updates/pkgengage.xml`, desactívala (clic en el icono de estado) o elimínala. Si lo prefieres por base de datos (cambia el prefijo `jos_` por el de tu sitio y haz copia antes):

```
UPDATE jos_update_sites SET enabled = 0 WHERE location LIKE '%cdn.akeeba.com/updates/pkgengage%';
```

- Riesgo si queda activo: ese servidor es de un tercero; si Akeeba publicara allí una versión superior a la instalada, Joomla la ofrecería y sustituiría el código del fork.

## Qué cambia en la base de datos

**La 3.4.19 añade UNA tabla** (`#__engage_reports`, denuncias de comentarios) con el fichero `sql/updates/mysql/3.4.18-20261027.sql` (`CREATE TABLE IF NOT EXISTS`, repetible; nombre mayor que el de la 3.4.3, así que Joomla lo ejecuta al actualizar desde cualquier versión anterior; desde la 3.4.2.1 y la 3.4.2 ejecuta antes el de la 3.4.3, que es el siguiente apartado). El submenú «Denuncias» no se añade a instalaciones existentes: se llega desde el panel de control.

### La 3.4.3

La 3.4.3 añade UNA tabla (`#__engage_reactions`) con el fichero `sql/updates/mysql/3.4.3-20261007.sql` (`CREATE TABLE IF NOT EXISTS`, repetible). Joomla lo ejecuta al actualizar desde la 3.4.2 original y desde la 3.4.2.1 publicada, porque ambas dejaron el esquema en `3.0.2-20220107` y el nombre nuevo es mayor según `version_compare`. No modifica ninguna tabla existente. Al desinstalar el componente se elimina. Detalle en [`REACCIONES.md`](REACCIONES.md).

## Para publicar una versión futura

1. Sube la versión en los manifiestos y añade la entrada al `CHANGELOG.md`.
2. Cambia `<version>` y la `downloadurl` (carpeta `v<versión>` y nombre del ZIP) en `updates/pkgengage.xml`. `php build/build.php` se niega a continuar si la versión del XML no coincide con la del paquete.
3. `php build/build.php` (escribe el `<sha256>` en el XML) y `php tests/run.php`.
4. Sincroniza `main` y publica la release siguiendo los pasos de arriba. **Primero la release con el ZIP, y después (o a la vez) `main` con el XML nuevo**, para que ninguna instalación vea una descarga inexistente.


## Comprobar que Joomla OFRECE la actualización (0.9.11)

Después de publicar la release y de que `main` sirva el `updates/pkgengage.xml` nuevo, en un Joomla con una versión anterior de Engage instalada:

1. **Sistema > Instalar > Extensiones > Gestionar sitios de actualización**: debe haber una fila «Engage (fork comunitario) Updates» activada con la dirección `https://raw.githubusercontent.com/chaska/engage/main/updates/pkgengage.xml`.
2. **Sistema > Actualizar > Extensiones**, botón **Borrar caché** y luego **Buscar actualizaciones**: debe aparecer la fila «Engage» con la versión nueva (tipo Paquete, sitio «Site»). Si dice «No hay actualizaciones disponibles», mire `<client>site</client>` en el XML: sin esa etiqueta Joomla guarda la oferta como del administrador y no la muestra (así pasó con la 3.4.19).
3. Marque la fila y pulse **Actualizar**: Joomla descarga el ZIP, comprueba su `sha256` con el del XML (si no coincide, rechaza la actualización) e instala. La versión en **Sistema > Información del sistema > Extensiones** pasa a la nueva.

Automático: `PKG_ANTES=<zip anterior> bash tests/joomla-live/23-oferta-actualizacion.sh` instala la versión anterior, sirve en local una copia del XML con la descarga local y recorre esos pasos en Chromium (también con una huella mala: se rechaza).
