#!/usr/bin/env bash
# (Re)arranca `php -S` para el sitio $SITE_DIR en $PORT con todos los avisos de PHP a un registro propio.
# Hay que reiniciarlo despues de instalar/actualizar extensiones: el servidor integrado es UN proceso con opcache y cache de
# rutas que sobrevive entre peticiones y, si arranco antes de instalar Engage, sigue devolviendo "Class ... not found".
set -euo pipefail
source "$(dirname "$0")/../config.sh"
PIDF="$WORK/php-server-${PORT}.pid"
if [ -f "$PIDF" ]; then kill "$(cat "$PIDF")" 2>/dev/null || true; sleep 1; fi
(setsid nohup php -d error_reporting=-1 -d display_errors=0 -d log_errors=1 -d error_log="$WORK/php-errors-${SITE_DIR}.log" \
	-S 127.0.0.1:${PORT} -t "$WORK/$SITE_DIR" "$AQUI/lib/router.php" >"$WORK/php-server-${PORT}.log" 2>&1 & echo $! > "$PIDF")
for i in $(seq 1 20); do curl -s -o /dev/null "${BASE_URL}/" && break; sleep 0.5; done
