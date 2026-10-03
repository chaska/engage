# Registro de cambios del fork

## 0.1.0 — 2026-10-03 (Fase 1)
- Fuente reorganizada en `src/` (componente, módulo, 10 plugins y paquete), con los `.ini` en `language/<tag>/` según los manifiestos. Archivos copiados de `upstream/3.4.2-instalado/` sin modificar su contenido.
- Nuevos `pkg_engage.sys.ini` (en-GB y es-ES) con `PKG_ENGAGE_XML_DESCRIPTION`.
- `build/build.php`: genera los ZIP de cada extensión y `pkg_engage-<versión>.zip` en `dist/`.
- `build/verificar.php`: comprobaciones de manifiestos, sintaxis PHP, identidad con upstream, XML y reproducibilidad.
- Añadido `.gitignore` (`dist/`).
- Sin cambios de código ni de manifiestos. Pendiente: URL de actualizaciones (Fase 4) e idiomas declarados que no existen (ver README).

## 0.0.1 — 2026-10-03 (Fase 0)
- Incorporada la referencia original Engage 3.4.2 en `upstream/3.4.2-instalado/`, sin modificar.
- Añadidos `LICENSE` (GPL v3), `README.md`, `NOTICE.md` y este `CHANGELOG.md`.
- Sin cambios de código.

## 0.0.2 — 2026-10-03
- `NOTICE.md`: enlaces a los perfiles GitHub de nikosdion y akeeba, licencias tal como constan en 3.4.2, nota sobre la fuente Akeeba-Products.woff.
- Sin cambios de código.
