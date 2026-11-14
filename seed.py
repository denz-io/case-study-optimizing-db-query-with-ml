#!/usr/bin/env python3
"""Generate deterministic seed data for the unitledger demo schema.

Creates 3 fictional projects, each with a handful of stages and products and
exactly 1000 items. Output is plain SQL on stdout (or a file), so it can be
piped into mysql or dropped into docker-entrypoint-initdb.d.

    python3 seed.py > demo/unitledger_seed.sql
    python3 seed.py --projects 3 --items 1000 --out demo/unitledger_seed.sql

Re-running against a database that already holds the seed is safe: the
generated SQL deletes the seeded projects (and their stages, products and
items) before inserting, keyed by the stable project ids.
"""
import argparse
import json
import random
import sys
import uuid
from datetime import datetime, timedelta

NS = uuid.UUID("7a3c1c3e-2b1e-4e3a-9f2a-0000000000aa")  # stable namespace for seeded ids
EPOCH = datetime(2025, 1, 6, 9, 0, 0)

PROJECTS = [
    dict(title="Harbourline Residences", developer="Meridian Property Group",
         architect="Bates Ferris Architects", builder="Northgate Constructions",
         levels="24", completion="Q4 2027", type="apartment", prefix="H",
         introduction="Waterfront apartments across two towers with a shared podium garden."),
    dict(title="Elm Street Village", developer="Greenway Developments",
         architect="Studio Alder", builder="Castlereagh Build Co",
         levels="4", completion="Q2 2027", type="townhouse", prefix="E",
         introduction="Low-rise townhouses and terraces arranged around a central laneway."),
    dict(title="Riverbend Estate", developer="Brookfield Land Partners",
         architect="Fieldhouse Design", builder="Hartwell Homes",
         levels="1", completion="Q1 2028", type="land", prefix="R",
         introduction="Masterplanned house-and-land community beside the river reserve."),
]

STAGES = ["Stage 1", "Stage 2", "Stage 3", "Stage 4", "Stage 5"]
ASPECTS = ["N", "NE", "E", "SE", "S", "SW", "W", "NW"]
STATUSES = [("available", 1, 0.55), ("held", 2, 0.10), ("reserved", 3, 0.10),
            ("sold", 4, 0.20), ("withdrawn", 5, 0.05)]

PRODUCTS = {
    "apartment": [("1 Bed 1 Bath", 50, 520000), ("1 Bed + Study", 58, 590000),
                  ("2 Bed 1 Bath", 70, 740000), ("2 Bed 2 Bath", 82, 860000),
                  ("3 Bed 2 Bath", 110, 1250000), ("Penthouse", 160, 2400000)],
    "townhouse": [("2 Bed Terrace", 95, 780000), ("3 Bed Townhouse", 140, 980000),
                  ("3 Bed + Study", 155, 1080000), ("4 Bed Townhouse", 185, 1290000)],
    "land": [("Lot 300-399 m2", 300, 320000), ("Lot 400-499 m2", 400, 390000),
             ("Lot 500-599 m2", 500, 450000), ("Lot 600+ m2", 600, 540000)],
}


def uid(*parts):
    return str(uuid.uuid5(NS, "/".join(str(p) for p in parts)))


def q(v):
    if v is None:
        return "NULL"
    if isinstance(v, bool):
        return "1" if v else "0"
    if isinstance(v, (int, float)):
        return repr(v)
    if isinstance(v, datetime):
        return "'" + v.strftime("%Y-%m-%d %H:%M:%S") + "'"
    if isinstance(v, (dict, list)):
        v = json.dumps(v, separators=(",", ":"))
    return "'" + str(v).replace("\\", "\\\\").replace("'", "\\'") + "'"


def insert(out, table, rows, batch=250):
    if not rows:
        return
    cols = list(rows[0].keys())
    head = f"INSERT INTO `{table}` ({', '.join('`'+c+'`' for c in cols)}) VALUES\n"
    for i in range(0, len(rows), batch):
        chunk = rows[i:i + batch]
        out.write(head)
        out.write(",\n".join("(" + ", ".join(q(r[c]) for c in cols) + ")" for r in chunk))
        out.write(";\n")


def canonical(title):
    return "".join(ch if ch.isalnum() else "-" for ch in title.lower()).strip("-")


def pick_status(rng):
    r = rng.random()
    acc = 0.0
    for name, order, weight in STATUSES:
        acc += weight
        if r <= acc:
            return name, order
    return STATUSES[0][0], STATUSES[0][1]


def build(n_projects, n_items, seed):
    rng = random.Random(seed)
    projects, stages, products, items = [], [], [], []

    for pi in range(n_projects):
        spec = PROJECTS[pi % len(PROJECTS)]
        title = spec["title"] if pi < len(PROJECTS) else f"{spec['title']} {pi + 1}"
        pid = uid("project", pi)
        created = EPOCH + timedelta(days=pi * 11)
        projects.append(dict(
            id=pid, address_id=None, title=title, title_canonical=canonical(title),
            developer=spec["developer"], architect=spec["architect"], builder=spec["builder"],
            completion_period=spec["completion"], levels=spec["levels"],
            aspects=json.dumps(ASPECTS), introduction=spec["introduction"],
            features=None, websites=None, show_held=1, status="active", show_publicly=1,
            mobile_number=None, legacy_doc_id=None, created_at=created, updated_at=created,
            enquiry_emails=None, enquiry_only=0, is_integrated=0, project_crm_id=None,
            published_at=created + timedelta(days=3), type=spec["type"], publish_showroom=1,
            portal_listing_id=None, portal_listing_url=None, is_portal_listing=0,
            services=None, special_conditions=None, workflow_link={}, metrics="m2",
            expected_settlement_date=created + timedelta(days=900),
            project_marketer=spec["developer"],
        ))

        n_stages = rng.randint(3, 5)
        stage_ids = []
        for si in range(n_stages):
            sid = uid("stage", pi, si)
            stage_ids.append(sid)
            stages.append(dict(id=sid, project_id=pid, title=STAGES[si], active=1 if si < n_stages - 1 else 0))

        prod_specs = PRODUCTS[spec["type"]]
        product_ids = []
        for ki, (name, size_from, price_from) in enumerate(prod_specs):
            prid = uid("product", pi, ki)
            product_ids.append((prid, size_from, price_from))
            products.append(dict(id=prid, project_id=pid, name=name, size_from=size_from,
                                 price_from=price_from, configurations=None))

        levels = max(1, int(spec["levels"]))
        per_level = max(1, -(-n_items // levels))
        for ii in range(n_items):
            prid, size_from, price_from = rng.choice(product_ids)
            level_no = ii // per_level + 1
            unit_no = ii % per_level + 1
            level = "G" if (levels > 1 and level_no == 1) else str(level_no)
            stock_id = f"{spec['prefix']}{level_no:02d}{unit_no:02d}" if levels > 1 else f"LOT{ii + 1:04d}"
            status, status_order = pick_status(rng)
            internal = round(size_from * rng.uniform(1.0, 1.25), 1)
            external = round(rng.uniform(6, 40), 1) if spec["type"] != "land" else 0.0
            total = round(internal + external, 1)
            price = round(price_from * rng.uniform(0.98, 1.22) / 1000) * 1000
            aspect_i = rng.randrange(len(ASPECTS))
            created = EPOCH + timedelta(days=pi * 11 + 5, minutes=ii)
            updated = created + timedelta(days=rng.randint(0, 120))
            lot_area = round(rng.uniform(size_from, size_from + 99), 0) if spec["type"] == "land" else None
            items.append(dict(
                id=uid("item", pi, ii), project_id=pid, product_id=prid,
                classification=spec["type"], status=status, status_order=status_order,
                is_public=0 if status == "withdrawn" else 1,
                level=level, width=0, level_order=float(level_no),
                stock_id=stock_id, stock_id_order=float(ii + 1), price=price,
                total_area=total if spec["type"] != "land" else lot_area,
                notes=None, meta=None, legacy_doc_id=None,
                created_at=created, updated_at=updated, discr="unit",
                aspect=ASPECTS[aspect_i], aspect_order=float(aspect_i + 1),
                internal_area=internal if spec["type"] != "land" else None,
                external_area=external if spec["type"] != "land" else None,
                price_m2=round(price / total, 2) if spec["type"] != "land" else round(price / lot_area, 2),
                property_crmid=None, stage_id=rng.choice(stage_ids),
                is_leased=0, hide_price=1 if status == "sold" else 0,
                external_id=None, apartment_no=str(unit_no) if spec["type"] == "apartment" else None,
                lot_no=str(ii + 1) if spec["type"] != "apartment" else None,
                frontage=round(rng.uniform(10, 18), 1) if spec["type"] == "land" else None,
                depth=round(rng.uniform(25, 36), 1) if spec["type"] == "land" else None,
                total_lot_area=lot_area,
                street="Riverbend Parade" if spec["type"] == "land" else None,
            ))
    return projects, stages, products, items


def main():
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("--projects", type=int, default=3)
    ap.add_argument("--items", type=int, default=1000, help="items per project")
    ap.add_argument("--seed", type=int, default=20261101)
    ap.add_argument("--out", default="-")
    args = ap.parse_args()

    projects, stages, products, items = build(args.projects, args.items, args.seed)
    pids = ", ".join(q(p["id"]) for p in projects)

    out = sys.stdout if args.out == "-" else open(args.out, "w")
    with out:
        out.write(f"-- Seed: {args.projects} projects x {args.items} items (seed {args.seed}), generated by seed.py\n")
        out.write("SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS = 0;\n")
        for t in ("items", "stages", "products"):
            out.write(f"DELETE FROM `{t}` WHERE `project_id` IN ({pids});\n")
        out.write(f"DELETE FROM `projects` WHERE `id` IN ({pids});\n")
        insert(out, "projects", projects)
        insert(out, "stages", stages)
        insert(out, "products", products)
        insert(out, "items", items)
        out.write("SET FOREIGN_KEY_CHECKS = 1;\n")
    print(f"projects={len(projects)} stages={len(stages)} products={len(products)} items={len(items)}", file=sys.stderr)


if __name__ == "__main__":
    main()
