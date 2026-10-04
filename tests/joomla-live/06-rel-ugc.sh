#!/usr/bin/env bash
# 0.6.18: rel="nofollow ugc noreferrer" en los enlaces de los comentarios (HTML servido). Requiere 03-sembrar-y-probar.sh ya ejecutado.
set -uo pipefail
source "$(dirname "$0")/config.sh"
export WORK SITE_DIR IDS_FILE DB_NAME BASE_URL
php "$AQUI/lib/rel-ugc-http.php"
