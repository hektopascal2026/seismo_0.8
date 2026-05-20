#!/usr/bin/env bash
# Provision a path satellite on the VPS (run as root or a user with CREATE DATABASE).
#
# Prerequisites:
#   - Satellite row exists in Settings → Satellites (mothership registry).
#   - config.local.php points at the shared `seismo` entries database.
#   - MariaDB client and PHP CLI available.
#
# Usage:
#   sudo bin/seismo-satellite-provision.sh security
#   sudo bin/seismo-satellite-provision.sh digital

set -euo pipefail

SLUG="${1:-}"
if [[ -z "$SLUG" ]]; then
  echo "Usage: $0 <slug>   (e.g. security, digital)" >&2
  exit 1
fi

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

if [[ ! -f config.local.php ]]; then
  echo "Missing config.local.php in $ROOT" >&2
  exit 1
fi

ENTRIES_DB="${SEISMO_ENTRIES_DB:-seismo}"
SCORES_DB="seismo_${SLUG}"
MYSQL_USER="${SEISMO_MYSQL_ADMIN_USER:-root}"
MYSQL_OPTS=()
if [[ -n "${SEISMO_MYSQL_SOCKET:-}" ]]; then
  MYSQL_OPTS+=(--socket="$SEISMO_MYSQL_SOCKET")
elif [[ -n "${SEISMO_MYSQL_HOST:-}" ]]; then
  MYSQL_OPTS+=(-h"$SEISMO_MYSQL_HOST")
fi

echo "==> Provisioning satellite slug=${SLUG} scores_db=${SCORES_DB} entries_db=${ENTRIES_DB}"

php -r "
require '$ROOT/bootstrap.php';
\$slug = seismoNormaliseSatelliteSlug('$SLUG');
if (\$slug === '' || in_array(\$slug, seismoReservedSatelliteSlugs(), true)) {
    fwrite(STDERR, \"Invalid or reserved slug\\n\");
    exit(1);
}
\$found = null;
foreach (seismoSatellitesRegistry() as \$row) {
    if ((\$row['slug'] ?? '') === \$slug) { \$found = \$row; break; }
}
if (\$found === null) {
    fwrite(STDERR, \"Slug not in satellites_registry — add it in Settings → Satellites first.\\n\");
    exit(2);
}
echo json_encode(\$found, JSON_UNESCAPED_SLASHES);
" > /tmp/seismo-sat-${SLUG}.json

API_KEY="$(php -r 'echo json_decode(file_get_contents($argv[1]), true)["api_key"] ?? "";' /tmp/seismo-sat-${SLUG}.json)"
if [[ -z "$API_KEY" ]]; then
  echo "Registry row has no api_key." >&2
  exit 2
fi

echo "==> Creating database ${SCORES_DB} (if needed)"
mysql "${MYSQL_OPTS[@]}" -u"$MYSQL_USER" -e "CREATE DATABASE IF NOT EXISTS \`${SCORES_DB}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

echo "==> Grants (app user from config.local.php — adjust if your deploy uses a dedicated DB user)"
APP_DB_USER="$(php -r "require '${ROOT}/config.local.php'; echo DB_USER;")"
mysql "${MYSQL_OPTS[@]}" -u"$MYSQL_USER" -e "
  GRANT SELECT ON \`${ENTRIES_DB}\`.* TO '${APP_DB_USER}'@'localhost';
  GRANT ALL PRIVILEGES ON \`${SCORES_DB}\`.* TO '${APP_DB_USER}'@'localhost';
  FLUSH PRIVILEGES;
"

echo "==> Running scores migrations"
php migrate.php --scores-db="$SCORES_DB"

echo "==> Seeding Magnitu api_key in ${SCORES_DB}.system_config"
mysql "${MYSQL_OPTS[@]}" -u"$MYSQL_USER" "$SCORES_DB" -e "
  INSERT INTO system_config (config_key, config_value)
  VALUES ('api_key', '${API_KEY}')
  ON DUPLICATE KEY UPDATE config_value = VALUES(config_value);
"

MOUNT_DIR="${ROOT}/${SLUG}"
if [[ ! -f "${MOUNT_DIR}/index.php" ]]; then
  echo "==> Writing ${MOUNT_DIR}/index.php"
  mkdir -p "$MOUNT_DIR"
  cat > "${MOUNT_DIR}/index.php" <<PHP
<?php

declare(strict_types=1);

define('SEISMO_SATELLITE_SLUG', '${SLUG}');
require dirname(__DIR__) . '/index.php';
PHP
fi

if [[ ! -e "${MOUNT_DIR}/assets" ]]; then
  echo "==> Linking ${MOUNT_DIR}/assets -> ../assets"
  ln -sfn ../assets "${MOUNT_DIR}/assets"
fi

chown -R www-data:www-data "$MOUNT_DIR" 2>/dev/null || true

echo "==> Marking registry status=active"
php -r "
require '$ROOT/bootstrap.php';
\$slug = seismoNormaliseSatelliteSlug('$SLUG');
\$config = new Seismo\Repository\SystemConfigRepository(getDbConnection());
\$raw = \$config->get('satellites_registry');
\$rows = is_string(\$raw) && \$raw !== '' ? json_decode(\$raw, true) : [];
if (!is_array(\$rows)) { \$rows = []; }
foreach (\$rows as &\$row) {
    if ((\$row['slug'] ?? '') === \$slug) {
        \$row['status'] = 'active';
        \$row['provisioned_at'] = gmdate('Y-m-d\\\\TH:i:s\\\\Z');
        \$row['db_name'] = '$SCORES_DB';
        \$row['mount_path'] = '/$SLUG';
    }
}
unset(\$row);
\$config->set('satellites_registry', json_encode(array_values(\$rows), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
"

rm -f /tmp/seismo-sat-${SLUG}.json

echo "Done. Open: (your mothership base URL)/${SLUG}/"
