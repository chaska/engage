# Configuracion comun de los scripts (se "source"a). Todo se puede sobreescribir con variables de entorno.
# Las contrasenas se generan al vuelo y se guardan SOLO en $WORK (fuera del repo, permisos 600).
AQUI="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO="$(cd "$AQUI/../.." && pwd)"
WORK="${WORK:-${TMPDIR:-/tmp}/engage-joomla-live}"       # directorio de trabajo (Joomla, ZIP, contrasenas, correos, registros)
JOOMLA_VERSION="${JOOMLA_VERSION:-6.1.4}"
JOOMLA_URL="${JOOMLA_URL:-https://github.com/joomla/joomla-cms/releases/download/${JOOMLA_VERSION}/Joomla_${JOOMLA_VERSION}-Stable-Full_Package.zip}"
SITE_DIR="${SITE_DIR:-site}"                              # subdirectorio de $WORK con el Joomla
DB_NAME="${DB_NAME:-joomla_test}"
DB_USER="${DB_USER:-joomla_test}"
PORT="${PORT:-8080}"
BASE_URL="http://127.0.0.1:${PORT}"
SMTP_PORT="${SMTP_PORT:-2525}"
IDS_FILE="${IDS_FILE:-ids.json}"
mkdir -p "$WORK"; chmod 700 "$WORK"
