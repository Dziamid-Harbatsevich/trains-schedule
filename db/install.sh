#!/usr/bin/env bash
# ---------------------------------------------------------------------------
#  Creates the database and loads schema + demo data.
#  Usage:  ./db/install.sh
#
#  Connection settings can be overridden with the usual MySQL environment
#  variables, e.g.:
#      MYSQL_PORT=13306 ./db/install.sh
# ---------------------------------------------------------------------------
set -euo pipefail

HOST="${DB_HOST:-127.0.0.1}"
PORT="${DB_PORT:-3306}"
USER="${DB_USER:-root}"
PASS="${DB_PASSWORD:-}"
NAME="${DB_NAME:-belta_trains}"

DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

mysql_args=(-h "$HOST" -P "$PORT" -u "$USER")
if [[ -n "$PASS" ]]; then
    mysql_args+=("-p${PASS}")
fi
# 1. Make sure the database exists (schema.sql no longer creates it).
echo "→ Ensuring database '${NAME}' exists on ${HOST}:${PORT}…"
mysql "${mysql_args[@]}" -e \
    "CREATE DATABASE IF NOT EXISTS \`${NAME}\`
        CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

# 2. Tables, functions and demo data.
echo "→ Creating schema…"
mysql "${mysql_args[@]}" --database="${NAME}" < "${DIR}/schema.sql"

echo "→ Loading demo data…"
mysql "${mysql_args[@]}" --database="${NAME}" < "${DIR}/seed.sql"

echo "✔ Done. Database '${NAME}' is ready."
