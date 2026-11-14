#!/usr/bin/env bash
# Open a mysql client inside the container: scripts/shell.sh [database]
DB="${1:-${MYSQL_DATABASE:-unitledger}}"
exec docker exec -it "${CONTAINER:-case-study-db}" mysql -h127.0.0.1 -uroot -p"${MYSQL_ROOT_PASSWORD:-root}" "$DB"
