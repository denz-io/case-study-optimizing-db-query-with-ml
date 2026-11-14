#!/usr/bin/env bash
# Import any SQL dump into the running case-study database container.
#
#   scripts/import.sh [--fresh] <file.sql> [database]
#
# --fresh drops and recreates the database first, so a dump without DROP TABLE
# statements (like the demo schema) can be re-imported cleanly.
#
# The database is created if it does not exist. The default database is the
# one from MYSQL_DATABASE (unitledger). To load a different dump into its own
# database, pass a separate database name, for example:
#
#   scripts/import.sh other_dump.sql other_db
set -euo pipefail

FRESH=0
if [[ "${1:-}" == "--fresh" ]]; then FRESH=1; shift; fi
FILE="${1:?usage: scripts/import.sh [--fresh] <file.sql> [database]}"
DB="${2:-${MYSQL_DATABASE:-unitledger}}"
ROOT_PW="${MYSQL_ROOT_PASSWORD:-root}"
CONTAINER="${CONTAINER:-case-study-db}"

[[ -f "$FILE" ]] || { echo "no such file: $FILE" >&2; exit 1; }

mysql_exec() { docker exec -i "$CONTAINER" mysql -h127.0.0.1 -uroot -p"$ROOT_PW" "$@" 2> >(grep -v 'Using a password' >&2); }

if [[ $FRESH -eq 1 ]]; then mysql_exec -e "DROP DATABASE IF EXISTS \`$DB\`;"; fi
mysql_exec -e "CREATE DATABASE IF NOT EXISTS \`$DB\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql_exec "$DB" < "$FILE"

mysql_exec -N -e "
  SELECT CONCAT('database: $DB');
  SELECT CONCAT('tables:   ', COUNT(*)) FROM information_schema.tables WHERE table_schema='$DB';
  SELECT CONCAT('fks:      ', COUNT(*)) FROM information_schema.referential_constraints WHERE constraint_schema='$DB';"
