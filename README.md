# Case study schema demo

Local MySQL 8.4 environment for the anonymised `unitledger` schema.

## Start

    docker compose up -d --wait

On first start the demo schema in `demo/unitledger_schema.sql` and the seed
data in `demo/unitledger_seed.sql` are imported automatically into the
`unitledger` database.

| Service  | Address                 | Credentials            |
|----------|-------------------------|------------------------|
| MySQL    | 127.0.0.1:3307          | root / root, demo / demo |
| Adminer  | http://localhost:8080   | server `db`            |
| phpMyAdmin | http://localhost:8081 | auto-login as root     |

Override ports and passwords by copying `.env.example` to `.env`.

## Import a dump

    scripts/import.sh [--fresh] <file.sql> [database]

- `scripts/import.sh --fresh demo/unitledger_schema.sql` reloads the demo schema.
- `scripts/import.sh <private dump> <other_db>` loads any other dump into its own
  database for side-by-side comparison. Keep such dumps out of git (see `.gitignore`).

## Seed data

    scripts/seed.sh [database] [-- seed.py args]

Regenerates `demo/unitledger_seed.sql` with `seed.py` (3 fictional projects,
each with stages, products and 1000 items; deterministic, so re-running yields
the same ids) and loads it. The seed deletes and re-inserts its own projects,
so it is safe to run repeatedly. `scripts/seed.sh -- --items 50` makes a
smaller set.

## Shell

    scripts/shell.sh [database]

## Regenerate the demo schema

    python3 anonymise.py <source dump> private/mapping.json demo/unitledger_schema.sql

The script strips dump metadata, renames identifiers from the private mapping,
regenerates constraint names and verifies structural counts and a denylist.

## Reset

    docker compose down -v
# case-study-optimizing-db-query-with-ml
