# Colaborar con Engage

Gracias por querer ayudar. Esto es lo básico.

## Traducciones

Se necesitan idiomas nuevos. La guía paso a paso está en [`docs/TRADUCIR.md`](docs/TRADUCIR.md). Resumen: `php build/idiomas.php plantilla xx-XX`, traducir, `php build/idiomas.php comprobar xx-XX` y enviar un *pull request* o un [issue de traducción](https://github.com/chaska/engage/issues/new?template=traduccion.yml).

## Errores y mejoras

Abra un [issue](https://github.com/chaska/engage/issues) y cuente qué esperaba, qué ha pasado y con qué versión de Joomla, PHP y Engage. **No ponga datos personales** (correos, nombres de su web, claves, IP): si hace falta una captura o un fragmento, quite antes todo lo que le identifique.

Si envía código: no cambie la versión ni los hashes (los gestiona quien publica), añada una prueba en `tests/` y ejecute `php tests/run.php` y `php build/verificar.php` antes de abrir el *pull request*.

## Seguridad

**No publique un fallo de seguridad en un issue ni en un pull request.** Use la pestaña **Security › Report a vulnerability** de este repositorio en GitHub: es un aviso privado que solo ven los responsables. Cuente cómo reproducirlo y qué impacto tiene. Se responderá por ahí y se publicará el arreglo antes que los detalles.

## Licencia

Al enviar algo, usted acepta que se publique con la licencia del proyecto: GNU GPL v3 o posterior ([`LICENSE`](LICENSE)).
