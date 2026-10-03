#!/usr/bin/env bash
# Descarga el paquete oficial de Joomla (GitHub Releases), lo instala por linea de comandos y lo sirve con `php -S`.
# Uso: [WORK=...] [JOOMLA_VERSION=6.1.4] [SITE_DIR=site] [DB_NAME=joomla_test] [PORT=8080] ./01-montar-joomla.sh
set -euo pipefail
source "$(dirname "$0")/config.sh"
cd "$WORK"
ZIP="$WORK/Joomla_${JOOMLA_VERSION}.zip"
[ -s "$ZIP" ] || curl -fsSL -o "$ZIP" "$JOOMLA_URL"
sha256sum "$ZIP"
if [ -n "${JOOMLA_SHA256:-}" ]; then echo "${JOOMLA_SHA256}  $ZIP" | sha256sum -c -; fi

rm -rf "$WORK/$SITE_DIR"; mkdir "$WORK/$SITE_DIR"; (cd "$WORK/$SITE_DIR" && unzip -q "$ZIP")
mysql -e "DROP DATABASE IF EXISTS \`${DB_NAME}\`; CREATE DATABASE \`${DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

# Contrasenas del administrador y de los usuarios de prueba: aleatorias, solo en $WORK
[ -s "$WORK/adminpass.txt" ] || { openssl rand -hex 10 > "$WORK/adminpass.txt"; chmod 600 "$WORK/adminpass.txt"; }
[ -s "$WORK/userpass.txt" ]  || { echo "Aa1!$(openssl rand -hex 8)" > "$WORK/userpass.txt"; chmod 600 "$WORK/userpass.txt"; }
cd "$WORK/$SITE_DIR"
php installation/joomla.php install --site-name="Engage Test" --admin-user="Admin Test" --admin-username=admintest \
	--admin-password="Aa1!$(cat "$WORK/adminpass.txt")" --admin-email=admin@example.test \
	--db-type=mysqli --db-host=127.0.0.1 --db-name="$DB_NAME" --db-user="$DB_USER" --db-pass="$(cat "$WORK/dbpass.txt")" \
	--db-prefix=jos_ --db-encryption=0 --no-interaction | tail -4

# Ajustes de pruebas: URL fija (php -S), errores al maximo, correo por el SMTP de captura
for kv in "live_site=${BASE_URL}" error_reporting=maximum mailer=smtp smtphost=127.0.0.1 "smtpport=${SMTP_PORT}" smtpauth=0 smtpsecure=none \
          mailonline=1 mailfrom=noreply@example.test fromname=EngageTest; do
	php cli/joomla.php config:set "$kv" >/dev/null
done

UP="$(cat "$WORK/userpass.txt")"
for u in registrado1 registrado2 editor1; do
	php cli/joomla.php user:add --username=$u --name="Usuario $u" --email=$u@example.test --password="$UP" --usergroup=Registered >/dev/null
done
php cli/joomla.php user:addtogroup --username=editor1 --group=Editor >/dev/null
php cli/joomla.php --version

# Servidor web de pruebas (php -S con router)
bash "$AQUI/lib/servidor.sh"
for u in / /administrator/; do printf '%s -> HTTP %s\n' "$u" "$(curl -s -o /dev/null -w '%{http_code}' "${BASE_URL}${u}")"; done
