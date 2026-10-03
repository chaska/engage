#!/usr/bin/env bash
# Prepara MariaDB (contenedor Debian/Ubuntu como root) y el servidor SMTP de captura. Idempotente.
set -euo pipefail
source "$(dirname "$0")/config.sh"

if ! command -v mariadbd >/dev/null 2>&1; then
	apt-get update -qq && DEBIAN_FRONTEND=noninteractive apt-get install -y -qq mariadb-server
fi
if ! mysqladmin ping >/dev/null 2>&1; then
	mkdir -p /run/mysqld && chown mysql:mysql /run/mysqld
	(setsid nohup mysqld_safe --bind-address=127.0.0.1 >"$WORK/mysqld.log" 2>&1 &)
	for i in $(seq 1 30); do mysqladmin ping >/dev/null 2>&1 && break; sleep 1; done
fi
mysqladmin ping

# Usuario y BD SOLO de pruebas; la contrasena aleatoria queda en $WORK/dbpass.txt
if [ ! -s "$WORK/dbpass.txt" ]; then
	openssl rand -hex 16 > "$WORK/dbpass.txt"; chmod 600 "$WORK/dbpass.txt"
fi
PW="$(cat "$WORK/dbpass.txt")"
for h in 127.0.0.1 localhost; do
	mysql -e "CREATE USER IF NOT EXISTS '${DB_USER}'@'${h}' IDENTIFIED BY '${PW}'; ALTER USER '${DB_USER}'@'${h}' IDENTIFIED BY '${PW}'; GRANT ALL ON \`joomla\\_%\`.* TO '${DB_USER}'@'${h}';"
done

# Servidor SMTP de captura (cada mensaje -> $WORK/mails/NNNNN.eml)
if ! (exec 3<>/dev/tcp/127.0.0.1/${SMTP_PORT}) 2>/dev/null; then
	(setsid nohup php "$AQUI/lib/smtp-sink.php" "$WORK/mails" "$SMTP_PORT" >"$WORK/sink.log" 2>&1 &)
	sleep 1
fi
echo "entorno listo"
