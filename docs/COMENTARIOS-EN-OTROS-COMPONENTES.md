# Comentarios en el contenido de otros componentes (0.8.2)

Copyright (c)2026 fork comunitario de Engage iniciado por ChasKa; GNU General Public License v3 o posterior.

Desde la 0.8.2 Engage puede dar comentarios al contenido de **cualquier componente de Joomla**, no solo a los artículos de `com_content`. No hay opciones nuevas ni permisos nuevos: se usan los de siempre de `com_engage`. Engage **no sabe nada** de su componente: usted le dice, con dos piezas muy pequeñas, qué elemento es y quién puede verlo.

| Pieza | Quién la escribe | Qué hace |
|---|---|---|
| 1. El evento `onContentAfterDisplay` | la vista de su componente | Pide a Engage el hilo de comentarios de un elemento |
| 2. El evento `onAkeebaEngageGetAssetMeta` | un plugin de contenido suyo | Responde a Engage con la ficha del elemento: título, quién puede verlo, si está publicado, si los comentarios están abiertos |

## 1. Pedir el hilo desde su vista

Donde quiera que salgan los comentarios (normalmente al final de la vista del elemento):

```php
$item   = (object) ['asset_id' => (int) $fila->asset_id];   // lo único que Engage lee del objeto
$params = new Registry();                                   // un Registry, aunque esté vacío
PluginHelper::importPlugin('content');
$partes = $app->triggerEvent('onContentAfterDisplay', ['com_miapp.elemento', &$item, &$params, 0]);
echo implode('', (array) $partes);
```

Reglas (lo que no se cumpla no muestra **nada**):

- **Contexto**: texto de 1 a 100 caracteres con letras, cifras, `_`, `.` y `-` (por ejemplo `com_miapp.elemento`). No puede empezar por `com_content.`: esos contextos son de los artículos y sus listados. El texto del contexto **no concede ni quita nada**: manda el elemento (su `asset_id`).
- **`asset_id`**: un número entero mayor que 0 (o una cadena de cifras, sin signo, sin espacios ni ceros a la izquierda) que quepa en un `INT UNSIGNED`. `0`, negativos, `12abc`, `1e3`, `0x1A`, decimales, matrices... se descartan sin preguntar a nadie.
- **`$params`** tiene que ser un `Registry` y no puede traer la clave `moduleclass_sfx` (así Engage no sale dentro de módulos tipo «Noticias»).
- Solo en el **sitio** (no en la administración).
- Engage **no lee nada más** del objeto: ni `id`, ni `access`, ni `introtext`, ni `attribs`. Y no consulta `#__content` en ningún momento para estos elementos ni hereda opciones de categorías de `com_content`.

## 2. Responder con la ficha: `onAkeebaEngageGetAssetMeta`

Un plugin del grupo **`content`** que escucha `onAkeebaEngageGetAssetMeta`. Engage lo lanza con `[$assetId, $cargarParametros]` y espera en `result` **una matriz con la ficha, o `null` si ese `asset_id` no es de su plugin** (nunca invente una ficha para un id ajeno: Engage usa la primera matriz que reciba).

| Clave | Tipo | Significado |
|---|---|---|
| `type` | `string` | Nombre de su tipo: minúsculas, cifras y `_`, de 1 a 32 caracteres, empezando por letra. No puede ser `unknown` ni `article` (reservado a `com_content`). Un tipo no válido anula toda la ficha |
| `title` | `string` | Título (texto plano, hasta 255 caracteres). Engage lo escapa al mostrarlo |
| `category` | `string` | Nombre de la categoría (texto plano) |
| `author_name` | `string` | Nombre del autor (texto plano) |
| `author_email` | `string` | Correo del autor, para avisarle de los comentarios. Si no es un correo válido se descarta |
| `url` | `string` | Enlace en la administración. Relativo (`index.php?...`) o del propio sitio; cualquier otra cosa se descarta |
| `public_url` | `string` | Enlace público al elemento (se usa en los correos). Relativo o `http(s)` **del mismo sitio**; `javascript:`, `data:`, `//otro.host` y otros anfitriones se descartan |
| `published` | `bool` | `true` solo si el elemento está publicado **ahora** (incluya sus fechas de inicio y fin). Solo vale `true` (o `1`/`"1"`) |
| `published_on` | `DateTimeInterface` | Fecha de publicación; sirve para cerrar los comentarios pasado un tiempo |
| `access` | `int` | Nivel de acceso de Joomla del elemento (1 o más). Ausente, 0, `null` o inválido **cierra** el elemento |
| `parent_access` | `int` o `null` | Nivel de acceso de su categoría o padre; `null` si no tiene. Si no es `null` y no es un nivel válido, **cierra** el elemento |
| `category_published` | `int` o `null` | Estado de la categoría: `1` publicada, `2` archivada (se ve), `0` sin publicar y `-2` papelera (cierran). `null` si no hay categoría |
| `parameters` | `Registry` | Opciones de los comentarios de este elemento (abajo) |

`parameters` (todas opcionales salvo que se indique; solo se leen estas claves y valores simples):

- `comments_enabled`: `1` abiertos, `0` cerrados (se ve el hilo pero no el formulario). **Si falta, el hilo sale cerrado**: diga usted que sí.
- `comments_close_after`: días tras `published_on` en los que se cierran solos (`0` = nunca; máximo 36500).
- `default_publish`: `1` los comentarios nuevos se publican, `0` quedan en moderación, `-1` o ausente = lo que diga la configuración de Engage.
- `comments_show`: `1` mostrar el hilo, `0` ocultarlo; ausente o `-1` = lo que diga la configuración de Engage (por omisión, mostrar).
- `comments_ordering`: `asc` o `desc`; ausente o `-1` = la configuración de Engage.
- `comments_notify_author`, `comments_notify_users`: `1`/`0` para los correos.

Engage trata **todo** lo que usted devuelve como datos no confiables: comprueba tipos y rangos, escapa todo al mostrarlo y, ante cualquier duda, **no muestra nada** (si el tipo, `published`, `access`, `parent_access` o `category_published` no cuadran, el hilo no sale y tampoco se puede comentar).

### Lo que ve cada visitante

El hilo sale solo si **todo** esto se cumple: el elemento está publicado, su categoría está publicada o archivada (o no hay categoría) y el visitante tiene el nivel `access` **y** el `parent_access`. Es la misma regla que Joomla aplica a sus propias páginas. Los permisos para comentar son los normales de `com_engage`. Lo comprueba el plugin de contenido de Engage y, otra vez, el controlador de comentarios antes de mostrar nada.

### Título del hilo (opcional)

Para que la cabecera diga «3 comentarios en “Título”» añada en el idioma de su plugin las claves `COM_ENGAGE_COMMENTS_<TIPO>_HEADER_N_COMMENTS_0`, `_1` y sin sufijo (con el `type` en mayúsculas), con `%2$s` para el título; sin ellas la cabecera es «3 comentarios» a secas. Engage entrega el título ya escapado.

## 3. Ejemplo completo y probado

Es el plugin que usa `tests/joomla-live/21-otros-componentes.sh` en un Joomla real. Los datos salen de una tabla `#__tercero_prueba` con una fila por elemento (`asset_id`, `title`, `category`, `author_name`, `author_email`, `url`, `public_url`, `published`, `publish_up`, `access`, `parent_access`, `category_published`, `comments_enabled`, `close_after`, `comments_show`). Ficheros bajo `tests/joomla-live/lib/tercero-prueba/` (**no** van en el paquete de Engage). La «vista» que lanza el evento es `tests/joomla-live/lib/tercero-vista/`.

`terceroprueba.xml`:

```xml
<?xml version="1.0" encoding="utf-8"?>
<!-- Plugin de PRUEBA que hace de «tercero»: lo instalan tests/joomla-live/21-otros-componentes.sh. NO forma parte del paquete de Engage. -->
<extension version="4.0.0" type="plugin" group="content" method="upgrade">
    <name>Tercero de prueba</name>
    <description>Ejemplo de plugin de terceros para los comentarios de Engage en otros componentes (solo pruebas)</description>
    <version>1.0.0</version>
    <creationDate>2026-10-11</creationDate>
    <author>Fork comunitario de Engage</author>
    <license>GNU/GPL v3 or any later version</license>
    <namespace path="src">Prueba\Plugin\Content\Terceroprueba</namespace>

    <files>
        <folder>services</folder>
        <folder plugin="terceroprueba">src</folder>
    </files>
    <languages folder="language">
        <language tag="en-GB">en-GB/plg_content_terceroprueba.ini</language>
    </languages>
</extension>
```

`services/provider.php`:

```php
<?php

defined('_JEXEC') || die;

use Joomla\CMS\Extension\PluginInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\Database\DatabaseInterface;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;
use Joomla\Event\DispatcherInterface;
use Prueba\Plugin\Content\Terceroprueba\Extension\Terceroprueba;

return new class implements ServiceProviderInterface {
	public function register(Container $container)
	{
		$container->set(
			PluginInterface::class,
			function (Container $container) {
				$plugin = new Terceroprueba(
					$container->get(DispatcherInterface::class),
					(array) PluginHelper::getPlugin('content', 'terceroprueba')
				);
				$plugin->setApplication(Factory::getApplication());
				$plugin->setDatabase($container->get(DatabaseInterface::class));

				return $plugin;
			}
		);
	}
};
```

`src/Extension/Terceroprueba.php`:

```php
<?php

namespace Prueba\Plugin\Content\Terceroprueba\Extension;

defined('_JEXEC') or die;

use Joomla\CMS\Date\Date;
use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\Database\DatabaseAwareTrait;
use Joomla\Database\ParameterType;
use Joomla\Event\Event;
use Joomla\Event\SubscriberInterface;
use Joomla\Registry\Registry;

/**
 * Ejemplo COMPLETO de plugin de terceros para los comentarios de Engage en el contenido de otro componente.
 * Lo documenta docs/COMENTARIOS-EN-OTROS-COMPONENTES.md y lo prueba tests/joomla-live/21-otros-componentes.sh.
 * Los datos salen de la tabla `#__tercero_prueba` (una fila por elemento, con su asset_id).
 */
class Terceroprueba extends CMSPlugin implements SubscriberInterface
{
	use DatabaseAwareTrait;

	protected $allowLegacyListeners = false;

	protected $autoloadLanguage = true;

	public static function getSubscribedEvents(): array
	{
		return [
			// Lo que Engage necesita saber del elemento (lo pregunta con el asset_id)
			'onAkeebaEngageGetAssetMeta' => 'onAkeebaEngageGetAssetMeta',
		];
	}

	/**
	 * Engage pregunta por un asset_id. Si es de este plugin se devuelve la ficha; si no, NULL (nunca una ficha inventada).
	 */
	public function onAkeebaEngageGetAssetMeta(Event $event): void
	{
		[$assetId] = array_values($event->getArguments());
		$result    = $event->getArgument('result', []);

		$event->setArgument('result', array_merge($result, [$this->cargarFicha((int) $assetId)]));
	}

	private function cargarFicha(int $assetId): ?array
	{
		if ($assetId <= 0)
		{
			return null;
		}

		$db    = $this->getDatabase();
		$query = $db->getQuery(true)
			->select('*')
			->from($db->quoteName('#__tercero_prueba'))
			->where($db->quoteName('asset_id') . ' = :asset')
			->bind(':asset', $assetId, ParameterType::INTEGER);
		$fila  = $db->setQuery($query)->loadObject();

		if (!is_object($fila))
		{
			return null;
		}

		$ahora      = Factory::getDate();
		$publicadoEn = new Date($fila->publish_up ?: 'now');
		$publicado  = ((int) $fila->published === 1) && ($publicadoEn <= $ahora);

		return [
			'type'               => 'tercero',                 // minusculas, cifras y «_», hasta 32; nunca «unknown» ni «article»
			'title'              => (string) $fila->title,     // texto plano: Engage lo escapa al mostrarlo
			'category'           => (string) $fila->category,
			'author_name'        => (string) $fila->author_name,
			'author_email'       => (string) $fila->author_email,
			'url'                => (string) $fila->url,       // relativa o del propio sitio
			'public_url'         => (string) $fila->public_url,
			'published'          => $publicado,                // bool estricto
			'published_on'       => $publicadoEn,
			'access'             => (int) $fila->access,       // nivel de acceso de Joomla (>= 1)
			'parent_access'      => ($fila->parent_access === null) ? null : (int) $fila->parent_access,
			'category_published' => ($fila->category_published === null) ? null : (int) $fila->category_published,
			'parameters'         => new Registry([
				'comments_enabled'     => (int) $fila->comments_enabled,
				'comments_close_after' => (int) $fila->close_after,
				'default_publish'      => 1,
				'comments_show'        => ($fila->comments_show === null) ? -1 : (int) $fila->comments_show,
			]),
		];
	}
}
```

## 4. Cosas que debe saber

- **Caché.** Compatible con la caché de página y con la caché conservadora de Joomla. Mientras despacha el hilo, Engage añade el `asset_id` a la petición (y lo devuelve a su valor al terminar) para que la caché de vistas de Joomla no mezcle dos elementos de la misma URL.
- **Vista `comments` propia.** Joomla guarda las vistas en una caché estática por nombre compartida por todos los componentes de la petición. Engage **no** la usa: crea su vista con su propia fábrica y rechaza cualquier vista que no sea una clase suya. Puede tener usted una vista llamada `comments` sin que se pisen.
- **`layout` y `view` de la petición** (CVE-2026-40383, inclusión de ficheros locales): solo se aceptan letras y cifras; `../../`, `%00`, `....//` y similares se convierten en `default`.
- **Volver al elemento tras comentar.** Por seguridad (caché de página) el enlace de vuelta del formulario conserva de la URL solo los parámetros conocidos: `option`, `view`, `layout`, `tmpl`, `id`, `catid`, `Itemid`, `lang`, `print`, `showall`, `start`, `limitstart` y `limit`. Con URL amigables (SEF) o con esos parámetros se vuelve al elemento; si su URL depende de otro parámetro, el visitante vuelve a la portada y el comentario queda guardado igual.
- **La vista de comentarios no se alcanza por URL.** Solo se muestra cuando la llama el plugin de contenido de Engage; una URL directa responde 403/404.
- **Quien responde manda para sus ids.** Engage fía en el plugin que reconoce el `asset_id`. Devuelva `null` para ids que no sean suyos.

### Límites conocidos

- **Contador de comentarios** (el «3 comentarios» en listados y bloques): **no soportado** fuera de `com_content`; solo sale el hilo completo.
- **Listados** (categoría, destacados) de otros componentes: sin hilo; el contexto `com_content.*` queda para los artículos.
- **Borrado.** Engage solo limpia los comentarios de los **artículos** que se borran (`onContentAfterDelete` con `com_content.article`). Cuando su componente borre un elemento **debe** limpiar lo suyo:

```php
use Akeeba\Component\Engage\Site\Helper\Meta;

// 1) comentarios, reacciones de esos comentarios y bajas de aviso del elemento (SQL ligado, en una transacción)
$borrados = Meta::deleteAssetData((int) $fila->asset_id);   // -1 si el id no es válido o hubo un error (no se cambió nada)
// 2) y usted borra la fila de #__assets del elemento y la suya
```

  `deleteAssetData()` es un método estático de PHP: no hay ninguna ruta HTTP, tarea ni evento que llegue a él. No toca `#__assets`.
- Si el plugin de Engage no está activo o `com_engage` no está habilitado, el evento no hace nada.

## 5. Lista de comprobación de seguridad para el desarrollador

- [ ] Devuelvo `null` para cualquier `asset_id` que no es mío; mi consulta usa parámetros ligados.
- [ ] `published` es un `bool` calculado **ahora** con mis fechas; `access` y `parent_access` son los niveles reales (enteros ≥ 1); nunca devuelvo un nivel «por omisión».
- [ ] `title`, `category` y `author_name` son texto plano; no los he escapado ya (Engage lo hace) y no he metido HTML en ellos.
- [ ] `url` y `public_url` son relativas o de mi propio sitio y las genero yo con `Route`, no con datos de la petición.
- [ ] `type` es fijo, propio y no es `article` ni `unknown`.
- [ ] Mi vista calcula el `asset_id` desde **mi** base de datos, nunca desde un parámetro de la URL ni de un formulario, y no lanza el evento si el visitante no puede ver el elemento (Engage lo vuelve a comprobar, pero no debe ser mi única defensa).
- [ ] `$params` es un `Registry` y no lleva `moduleclass_sfx`.
- [ ] Borro con `Meta::deleteAssetData()` y borro mi fila de `#__assets` cuando borro el elemento.
- [ ] Mi plugin no abre accesos nuevos (rutas, tareas, `exit`) para mostrar el hilo: Engage no los necesita.
- [ ] Lo he probado como invitado, Registered y Manager, con la caché de página activada y con un elemento sin publicar, en categoría sin publicar y con un nivel de acceso que el visitante no tiene.

## 6. Cómo se prueba

- Sin Joomla: `php tests/run.php` (`35-comentarios-otros-componentes.php`: 300+ comprobaciones del código real de Engage con datos hostiles; `36-borrado-por-activo.php`; `37-doc-otros-componentes.php`: este ejemplo es el fichero probado).
- En un Joomla real: `bash tests/joomla-live/21-otros-componentes.sh` (necesita `00` a `03`): instala tres plugins de prueba (este ejemplo, uno hostil con tipos raros y XSS, y una «vista»), y comprueba por HTTP y en Chromium, con y sin caché de página, la matriz de visibilidad, el texto hostil, los `asset_id` inválidos, el contexto falsificado, la vista `comments` ajena, el `layout` hostil, la escritura, el borrado y que el HTML de los artículos es idéntico al de la 0.8.1.
