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

## Matrix endpoint emulation

    php -S localhost:8089 demo/test/matrix-endpoint.php
    curl 'localhost:8089/ajax/project/harbourline-residences/matrix-advance?isMatrix=true'
    php demo/test/matrix-endpoint.php 'filters=1&statusName=available&_as=agent&_agency=AG1' elm-street-village

`demo/test/matrix-endpoint.php` emulates `GET /ajax/project/{projectUrl}/matrix-advance`
from the original codebase (controller, building provider, filter trait and the
`findUnits` / `searchUnits` / `arrangeFilter` repository queries, quirks included)
against the demo database. The viewer is passed as `_as=admin|agent`,
`_agency=<id>`, `_email=<email>`. `_ml=1` applies the decision tree in
`demo/test/model.json` to every returned unit (the baseline SQL+ML path);
`_predicates=atomic|composite` also adds the tree-derived SQL predicates to the
units query (the proposed configuration). Agency names, project access, status
colours and custom labels are not emulated because their tables were pruned.

## Benchmark: baseline vs ML-derived predicates

    php demo/test/query_items.php --project=harbourline-residences [--request='filters=1&statusName=available'] [--explain] [--json]

Runs the emulated endpoint under the two configurations from the case study:
the baseline scores every returned unit with the decision tree in the element
loop, and the proposed configuration adds SQL predicates derived from that tree
so MySQL filters first. Each run builds and encodes the full response and
reports query execution time (Performance Schema), total processing time, rows
examined / returned / scored, result correctness (hash of the element ids) and
a Wilcoxon signed-rank test over 5 warm-up + 30 measured runs with alternating
order. `--request` takes the endpoint's own query string; `--as`, `--agency` and
`--email` set the viewer. Other options: `--predicates=atomic|composite`,
`--runs=N`, `--warmup=N`, `--config=baseline|proposed`. Needs local PHP 8 with
pdo_mysql.

## Shell

    scripts/shell.sh [database]

## Regenerate the demo schema

    python3 anonymise.py <source dump> private/mapping.json demo/unitledger_schema.sql

The script strips dump metadata, renames identifiers from the private mapping,
regenerates constraint names and verifies structural counts and a denylist.

## Reset

    docker compose down -v
# case-study-optimizing-db-query-with-ml
