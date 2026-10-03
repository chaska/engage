# Copias de clases reales de Joomla para las pruebas

Archivos de https://github.com/joomla/joomla-cms **sin modificar** (GPL v2 o posterior, compatible con la GPL v3 o posterior del fork), usados solo por `tests/12-update-server.php` para comprobar que `updates/pkgengage.xml` lo interpreta el analizador real de Joomla. No se incluyen en ningún ZIP.

| Archivo | Rama | Commit |
|---|---|---|
| `5.4-dev/Updater/Update.php`, `5.4-dev/Updater/DownloadSource.php`, `5.4-dev/Installer/InstallerHelper.php`, `5.4-dev/Object/Legacy*.php` | 5.4-dev | 45c6f2e68571ed439f8f154fbfe36ccfc43aa654 |
| `6.1-dev/Updater/Update.php` | 6.1-dev | 92d32fe6ea89af52a3c3535144aa1cf95152a782 |

Para refrescarlas: clonar la rama y copiar esos archivos con la misma ruta relativa de `libraries/src/`.
