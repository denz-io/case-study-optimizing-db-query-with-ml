#!/usr/bin/env bash
# Regenerate demo/unitledger_seed.sql and load it into the running database.
#
#   scripts/seed.sh [database] [-- extra seed.py args]
#
# Example: scripts/seed.sh -- --projects 3 --items 1000
set -euo pipefail
cd "$(dirname "$0")/.."

DB="${MYSQL_DATABASE:-unitledger}"
if [[ $# -gt 0 && "$1" != "--" ]]; then DB="$1"; shift; fi
[[ "${1:-}" == "--" ]] && shift

python3 seed.py --out demo/unitledger_seed.sql "$@"
scripts/import.sh demo/unitledger_seed.sql "$DB"
