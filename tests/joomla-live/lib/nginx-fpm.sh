#!/usr/bin/env bash
# 0.9.5: segundo servidor de pruebas REAL (nginx + php-fpm, sin live_site y con SEF + reescritura como en un hosting): `php -S` hace que Joomla crea estar
# en CLI (Uri::base() devuelve la ruta de la peticion) y sin live_site no resuelve las rutas amigables. Escucha en 127.0.0.1:8090 sobre el MISMO sitio y la misma BD.
# Uso: nginx-fpm.sh [arrancar|parar]. Requiere los paquetes nginx y php8.3-fpm (+ php8.3-mysql xml mbstring zip curl gd intl).
set -euo pipefail
source "$(dirname "$0")/../config.sh"
D="$WORK/nginx"; mkdir -p "$D/log" "$D/tmp"
PORT_N="${NGINX_PORT:-8090}"
if [ "${1:-arrancar}" = "parar" ]; then
	[ -f "$D/nginx.pid" ] && kill "$(cat "$D/nginx.pid")" 2>/dev/null || true
	[ -f "$D/fpm.pid" ] && kill "$(cat "$D/fpm.pid")" 2>/dev/null || true
	exit 0
fi
command -v nginx >/dev/null && command -v php-fpm8.3 >/dev/null || { echo "NO PROBADO: faltan nginx / php-fpm8.3"; exit 2; }
cat > "$D/fpm.conf" <<EOF
[global]
pid = $D/fpm.pid
error_log = $D/log/fpm.log
daemonize = yes
[www]
listen = 127.0.0.1:9083
pm = static
pm.max_children = 4
catch_workers_output = yes
php_admin_value[error_log] = $WORK/php-errors-nginx.log
php_admin_flag[log_errors] = on
php_admin_value[error_reporting] = -1
php_admin_flag[display_errors] = off
EOF
cat > "$D/nginx.conf" <<EOF
user root;
pid $D/nginx.pid;
error_log $D/log/error.log;
events { worker_connections 64; }
http {
	access_log $D/log/access.log;
	client_body_temp_path $D/tmp/body; fastcgi_temp_path $D/tmp/fcgi; proxy_temp_path $D/tmp/proxy; uwsgi_temp_path $D/tmp/uwsgi; scgi_temp_path $D/tmp/scgi;
	include /etc/nginx/mime.types;
	server {
		listen 127.0.0.1:$PORT_N;
		root $WORK/$SITE_DIR;
		index index.php;
		location / { try_files \$uri \$uri/ /index.php?\$args; }
		location ~ ^(.+\.php)(/.*)?\$ {
			fastcgi_split_path_info ^(.+\.php)(/.*)\$;
			include /etc/nginx/fastcgi_params;
			fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;
			fastcgi_param PATH_INFO \$fastcgi_path_info;
			fastcgi_pass 127.0.0.1:9083;
			fastcgi_read_timeout 120s;
		}
	}
}
EOF
[ -f "$D/fpm.pid" ] && kill "$(cat "$D/fpm.pid")" 2>/dev/null || true
[ -f "$D/nginx.pid" ] && kill "$(cat "$D/nginx.pid")" 2>/dev/null || true
sleep 1
php-fpm8.3 -R -y "$D/fpm.conf"
nginx -c "$D/nginx.conf"
for i in $(seq 1 20); do curl -s -o /dev/null "http://127.0.0.1:$PORT_N/" && break; sleep 0.5; done
echo "nginx + php-fpm en http://127.0.0.1:$PORT_N"
