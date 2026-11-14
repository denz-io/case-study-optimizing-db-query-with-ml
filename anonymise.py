#!/usr/bin/env python3
"""Anonymise a mysqldump schema for public use while preserving its structure.

Usage: python3 anonymise.py <input.sql> <mapping.json> <output.sql>

What changes: dump metadata, Doctrine comments, AUTO_INCREMENT counters,
identifying table/column/literal names, and index/constraint names.
What is preserved byte-for-byte: types, widths, nullability, defaults,
column order, charsets, collations, FK actions.
"""
import json
import re
import sys
from collections import Counter

IDENT = re.compile(r"`([^`]+)`")
DC2 = re.compile(r" COMMENT '\(DC2Type:[^']*\)'")
AUTOINC_VAL = re.compile(r" AUTO_INCREMENT=\d+")
CREATE = re.compile(r"^CREATE TABLE `([^`]+)` \($")
KEY_LINE = re.compile(r"^(\s+)(UNIQUE KEY|KEY) `[^`]+` \(([^)]+)\)(,?)$")
FK_LINE = re.compile(r"^(\s+)CONSTRAINT `[^`]+` FOREIGN KEY \(([^)]+)\)(.*)$")


def rename_ident(name, m):
    if name in m["exact"]:
        return m["exact"][name]
    for old, new in m["substring"]:
        if old in name:
            name = name.replace(old, new)
    return name


def cols_of(group):
    return [c.strip("` ") for c in group.split(",")]


def transform(src, m):
    out = []
    table = None
    names_seen = set()
    for line in src.splitlines():
        # 1. drop dump metadata and directives
        if line.startswith("/*!") or line.startswith("DROP TABLE IF EXISTS"):
            continue
        if line.startswith("-- ") and not line.startswith("-- Table structure"):
            continue
        if line.strip() in ("--", ""):
            continue
        # 2. strip ORM comments and live counters
        line = DC2.sub("", line)
        line = AUTOINC_VAL.sub("", line)
        # 3. rename identifiers and literals
        line = IDENT.sub(lambda mo: "`" + rename_ident(mo.group(1), m) + "`", line)
        for old, new in m["literals"]:
            line = line.replace(old, new)
        # 4. regenerate index / constraint names from the new names
        mo = CREATE.match(line)
        if mo:
            table = mo.group(1)
        mo = KEY_LINE.match(line)
        if mo:
            indent, kind, group, comma = mo.groups()
            prefix = "uq" if kind == "UNIQUE KEY" else "idx"
            name = f"{prefix}_{table}_{'_'.join(cols_of(group))}"
            line = f"{indent}{kind} `{name}` ({group}){comma}"
            names_seen.add(name)
        mo = FK_LINE.match(line)
        if mo:
            indent, group, rest = mo.groups()
            name = f"fk_{table}_{'_'.join(cols_of(group))}"
            line = f"{indent}CONSTRAINT `{name}` FOREIGN KEY ({group}){rest}"
            assert name not in names_seen, f"duplicate constraint name {name}"
            names_seen.add(name)
        out.append(line)
    too_long = [n for n in names_seen if len(n) > 64]
    assert not too_long, f"identifier(s) over 64 chars: {too_long}"
    header = [
        f"-- Schema: {m['database']['to']} (anonymised demo schema for a case study)",
        "-- Structure, types, nullability, defaults and referential actions are",
        "-- preserved from the source system. Names and metadata are fictional.",
        "SET NAMES utf8mb4;",
        "SET FOREIGN_KEY_CHECKS = 0;",
        "",
    ]
    footer = ["", "SET FOREIGN_KEY_CHECKS = 1;", ""]
    return "\n".join(header + out + footer)


COUNTERS = {
    "tables": r"^CREATE TABLE",
    "json columns": r"^\s+`[a-z_0-9]+` json",
    "longtext columns": r"^\s+`[a-z_0-9]+` longtext",
    "varchar(255) columns": r"varchar\(255\)",
    "double columns": r"^\s+`[a-z_0-9]+` double",
    "money-as-varchar columns": r"`[a-z_]*(price|cost|amount|commission|deposit|balance|yield|growth|income|payed|paid|rebate)[a-z_]*` varchar",
    "status varchar columns": r"`[a-z_]*status[a-z_]*` varchar",
    "discr columns": r"`discr`",
    "enum columns": r" enum\(",
    "foreign keys": r"FOREIGN KEY",
    "FK on delete restrict": r"ON DELETE RESTRICT",
    "FK on delete cascade": r"ON DELETE CASCADE",
    "FK on delete set null": r"ON DELETE SET NULL",
    "int auto-increment PKs": r" int NOT NULL AUTO_INCREMENT",
    "latin1 tables": r"CHARSET=latin1",
    "utf8mb3 tables": r"CHARSET=utf8mb3",
    "char(36) uuid columns": r"char\(36\)",
    "nullable columns": r"DEFAULT NULL",
    "deleted_at columns": r"`deleted_at`",
    "archived columns": r"`archived` tinyint",
}


def count(text):
    return {k: len(re.findall(p, text, re.M)) for k, p in COUNTERS.items()}


def main(src_path, map_path, out_path):
    src = open(src_path, encoding="utf-8").read()
    m = json.load(open(map_path, encoding="utf-8"))
    result = transform(src, m)
    open(out_path, "w", encoding="utf-8").write(result)

    before, after = count(src), count(result)
    print(f"{'metric':<28}{'before':>8}{'after':>8}")
    ok = True
    for k in COUNTERS:
        flag = "" if before[k] == after[k] else "  <-- MISMATCH"
        ok &= before[k] == after[k]
        print(f"{k:<28}{before[k]:>8}{after[k]:>8}{flag}")

    hits = Counter()
    for term in m["denylist"]:
        flags = 0 if any(c.isupper() for c in term) else re.I
        n = len(re.findall(re.escape(term), result, flags))
        if n:
            hits[term] = n
    print()
    if hits:
        ok = False
        print("DENYLIST HITS:", dict(hits))
    else:
        print(f"denylist: 0 hits across {len(m['denylist'])} terms")
    print("RESULT:", "PASS" if ok else "FAIL")
    return 0 if ok else 1


if __name__ == "__main__":
    sys.exit(main(*sys.argv[1:4]))
