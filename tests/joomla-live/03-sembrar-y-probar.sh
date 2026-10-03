#!/usr/bin/env bash
# Crea categorias/articulos de prueba con los modelos reales de Joomla y ejecuta pruebas.php. Sale con 1 si algo FALLA.
set -euo pipefail
source "$(dirname "$0")/config.sh"
JOOMLA_SITE="$WORK/$SITE_DIR" php "$AQUI/lib/seed.php" > "$WORK/$IDS_FILE"
cat "$WORK/$IDS_FILE" | tr -d '\n ' ; echo
WORK="$WORK" SITE_DIR="$SITE_DIR" IDS_FILE="$IDS_FILE" DB_NAME="$DB_NAME" BASE_URL="$BASE_URL" ERROR_LOG="$WORK/php-errors-${SITE_DIR}.log" \
	php "$AQUI/pruebas.php"
