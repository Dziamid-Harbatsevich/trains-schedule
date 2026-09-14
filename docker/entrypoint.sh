#!/usr/bin/env bash
# ---------------------------------------------------------------------------
#  Container entry point.
#
#   1. Waits until the MySQL server accepts connections.
#   2. On first start (or when FORCE_DB_INIT=1) loads db/schema.sql and
#      db/seed.sql into the database.
#   3. Starts the main command (Apache by default).
#
#  Everything can be configured with environment variables:
#     DB_HOST, DB_PORT, DB_USER, DB_PASSWORD, DB_NAME
#     DB_INIT=1        load schema + seed (default 1)
#     FORCE_DB_INIT=1  load schema + seed even if data already exists
# ---------------------------------------------------------------------------
set -euo pipefail
DB_HOST="${DB_HOST:-db}"
DB_PORT="${DB_PORT:-3306}"
DB_USER="${DB_USER:-belta}"
DB_PASSWORD="${DB_PASSWORD:-belta}"
DB_NAME="${DB_NAME:-belta_trains}"
DB_INIT="${DB_INIT:-1}"
FORCE_DB_INIT="${FORCE_DB_INIT:-0}"
DB_WAIT_SECONDS="${DB_WAIT_SECONDS:-120}"

# The project is copied into /var/www/html by the Dockerfile.
ROOT_DIR="${APP_ROOT:-/var/www/html}"

log() { echo "[entrypoint] $*"; }

# ---------------------------------------------------------------------------
# Pick a working MySQL client. Debian/Ubuntu images may ship either the MySQL
# or the MariaDB client; both provide one of these binary names.
# ---------------------------------------------------------------------------
MYSQL_BIN="$(command -v mysql || true)"
MYSQLADMIN_BIN="$(command -v mysqladmin || command -v mariadb-admin || true)"

if [ -z "${MYSQL_BIN}" ]; then
    log "ERROR: no 'mysql' client found in the image."
    exit 1
fi
log "Using client: ${MYSQL_BIN}"

mysql_args=(
    --host="${DB_HOST}"
    --port="${DB_PORT}"
    --user="${DB_USER}"
    --password="${DB_PASSWORD}"
    --protocol=TCP
    --connect-timeout=5
)

# MySQL 8 serves TLS with a self-signed certificate and the client refuses it
# by default ("TLS/SSL error: self-signed certificate in certificate chain").
# The connection stays inside the private compose network, so skipping the
# certificate check here is safe.
#
# The flag differs between clients:
#   Oracle mysql client  -> --ssl-mode=DISABLED
#   MariaDB client       -> --ssl=0
# so it is applied only if the chosen client actually accepts it.
DB_SSL_MODE="${DB_SSL_MODE:-disabled}"

client_supports() {
    "${1}" --help 2>&1 | grep -q -- "$2"
}

if [ "${DB_SSL_MODE}" = "disabled" ]; then
    if client_supports "${MYSQL_BIN}" --ssl-mode; then
        mysql_args+=(--ssl-mode=DISABLED)
    elif client_supports "${MYSQL_BIN}" --ssl; then
        mysql_args+=(--ssl=0)
    fi
elif [ "${DB_SSL_MODE}" = "required-no-verify" ]; then
    if client_supports "${MYSQL_BIN}" --ssl-mode; then
        mysql_args+=(--ssl-mode=REQUIRED)
        mysql_args+=(--ssl-verify-server-cert=OFF)
    fi
fi
log "TLS mode: ${DB_SSL_MODE}"

# ---------------------------------------------------------------------------
# 1. Wait until the server answers. The plain client is the fallback probe,
#    because `mysqladmin` may be missing in a slim image.
# ---------------------------------------------------------------------------
log "Waiting for MySQL at ${DB_HOST}:${DB_PORT} (up to ${DB_WAIT_SECONDS}s) …"
waited=0
while [ "${waited}" -lt "${DB_WAIT_SECONDS}" ]; do
    if [ -n "${MYSQLADMIN_BIN}" ] \
        && "${MYSQLADMIN_BIN}" "${mysql_args[@]}" ping >/dev/null 2>&1; then
        break
    fi
    if "${MYSQL_BIN}" "${mysql_args[@]}" -e "SELECT 1" >/dev/null 2>&1; then
        break
    fi
    sleep 2
    waited=$((waited + 2))
    if [ $((waited % 20)) -eq 0 ]; then
        log "  … still waiting (${waited}s)"
    fi
done
if [ "${waited}" -ge "${DB_WAIT_SECONDS}" ]; then
    log "ERROR: MySQL at ${DB_HOST}:${DB_PORT} did not answer within ${DB_WAIT_SECONDS}s."
    log "Last client error:"
    "${MYSQL_BIN}" "${mysql_args[@]}" -e "SELECT 1" || true
    exit 1
fi
log "MySQL is up."

# ---------------------------------------------------------------------------
# Load schema + demo data
# ---------------------------------------------------------------------------
if [ "${DB_INIT}" = "1" ]; then
        already_loaded=0
        if "${MYSQL_BIN}" "${mysql_args[@]}" --skip-column-names -e \
            "SELECT COUNT(*) FROM information_schema.tables
              WHERE table_schema='${DB_NAME}' AND table_name='trains';" 2>/dev/null | grep -q '^1$'; then
            already_loaded=1
        fi
        if [ "${already_loaded}" = "1" ] && [ "${FORCE_DB_INIT}" != "1" ]; then
            log "Database '${DB_NAME}' already initialised — skipping import."
            log "  (set FORCE_DB_INIT=1 to reload schema.sql + seed.sql)"
        else
            log "Loading db/schema.sql …"
            "${MYSQL_BIN}" "${mysql_args[@]}" --database="${DB_NAME}" < "${ROOT_DIR}/db/schema.sql"

            log "Loading db/seed.sql …"
            "${MYSQL_BIN}" "${mysql_args[@]}" --database="${DB_NAME}" < "${ROOT_DIR}/db/seed.sql"

            log "Database '${DB_NAME}' initialised."
        fi
    fi
    log "Starting: $*"
    exec "$@"
