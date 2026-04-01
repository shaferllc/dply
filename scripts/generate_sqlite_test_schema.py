#!/usr/bin/env python3

from __future__ import annotations

import os
import re
import sqlite3
import sys
import tempfile
from pathlib import Path


def main() -> int:
    try:
        from sqlalchemy import ForeignKeyConstraint, MetaData, create_engine, text
        from sqlalchemy.schema import DefaultClause
    except Exception as exc:  # pragma: no cover - local tooling bootstrap
        print(f"Missing dependency: {exc}", file=sys.stderr)
        return 1

    pg_user = os.environ.get("PGUSER", "postgres")
    pg_password = os.environ.get("PGPASSWORD", "")
    pg_host = os.environ.get("PGHOST", "127.0.0.1")
    pg_port = os.environ.get("PGPORT", "5432")
    pg_database = os.environ.get("PGDATABASE", "dply")

    auth = pg_user
    if pg_password:
        auth = f"{pg_user}:{pg_password}"

    source_url = f"postgresql+psycopg2://{auth}@{pg_host}:{pg_port}/{pg_database}"

    metadata = MetaData(schema="public")
    source_engine = create_engine(source_url)
    metadata.reflect(bind=source_engine)

    target_fd, target_path = tempfile.mkstemp(prefix="dply-sqlite-schema-", suffix=".sqlite")
    os.close(target_fd)

    try:
        target_engine = create_engine(f"sqlite:///{target_path}")

        for table in list(metadata.tables.values()):
            table.schema = None
            for constraint in list(table.constraints):
                if isinstance(constraint, ForeignKeyConstraint):
                    table.constraints.remove(constraint)
            for column in table.columns:
                column.server_default = normalize_default(text, DefaultClause, column.server_default)
                if getattr(column, "computed", None) is not None:
                    column.computed = None
                    if hasattr(column, "_computed"):
                        column._computed = None

        metadata.create_all(bind=target_engine)

        lines: list[str] = []
        with sqlite3.connect(target_path) as connection:
            for raw_line in connection.iterdump():
                line = raw_line.strip()
                if not line:
                    continue
                if line.startswith("BEGIN TRANSACTION") or line.startswith("COMMIT"):
                    continue
                if line.startswith("CREATE TABLE sqlite_sequence"):
                    continue
                lines.append(raw_line + (";" if not raw_line.endswith(";") else ""))

        output_path = Path(__file__).resolve().parents[1] / "database" / "schema" / "sqlite-schema.sql"
        output_path.parent.mkdir(parents=True, exist_ok=True)
        output_path.write_text("\n".join(lines) + "\n", encoding="utf-8")

        print(f"Wrote {output_path}")
        return 0
    finally:
        try:
            Path(target_path).unlink(missing_ok=True)
        except Exception:
            pass


def normalize_default(text_fn, default_clause_cls, server_default):
    if server_default is None:
        return None

    raw = getattr(getattr(server_default, "arg", None), "text", None)
    if not isinstance(raw, str):
        return None

    value = raw.strip()
    if value == "":
        return None

    lower = value.lower()

    casted_string = re.fullmatch(r"'((?:[^']|'')*)'::[a-zA-Z0-9_ ]+", value)
    if casted_string:
        literal = casted_string.group(1).replace("'", "''")
        return default_clause_cls(text_fn(f"'{literal}'"))

    casted_number = re.fullmatch(r"(-?\d+(?:\.\d+)?)::[a-zA-Z0-9_ ]+", value)
    if casted_number:
        return default_clause_cls(text_fn(casted_number.group(1)))

    if "::" in value or "generated" in lower or "(" in value or ")" in value:
        if lower in {"true", "false"}:
            return default_clause_cls(text_fn("1" if lower == "true" else "0"))
        return None

    if lower == "true":
        return default_clause_cls(text_fn("1"))

    if lower == "false":
        return default_clause_cls(text_fn("0"))

    if re.fullmatch(r"-?\d+(\.\d+)?", value):
        return default_clause_cls(text_fn(value))

    if re.fullmatch(r"'([^']|'')*'", value):
        return default_clause_cls(text_fn(value))

    return None


if __name__ == "__main__":
    raise SystemExit(main())
