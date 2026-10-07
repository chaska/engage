#!/usr/bin/env bash
# 0.7.0: desinstalacion limpia con el instalador real de Joomla (segundo sitio, el de 04-actualizacion.sh). Antes crea unas reacciones para
# comprobar que la tabla nueva desaparece con los datos. Despues del paquete hay que reinstalar (bash 02-instalar-engage.sh) para seguir probando ahi.
# Uso: bash 18-desinstalacion.sh   (SITE_DIR=site2 DB_NAME=joomla_upg PORT=8081 por defecto)
set -uo pipefail
export SITE_DIR="${SITE_DIR:-site2}" DB_NAME="${DB_NAME:-joomla_upg}" PORT="${PORT:-8081}" IDS_FILE="${IDS_FILE:-ids2.json}"
source "$(dirname "$0")/config.sh"
export WORK SITE_DIR DB_NAME
mysql "$DB_NAME" -e "INSERT IGNORE INTO jos_engage_reactions (comment_id,user_id,type,created) SELECT id, 1, 1, NOW() FROM jos_engage_comments LIMIT 3" 2>&1 | head -2
echo "reacciones guardadas antes: $(mysql -N "$DB_NAME" -e 'SELECT COUNT(*) FROM jos_engage_reactions')"
php "$AQUI/lib/desinstalar.php"
