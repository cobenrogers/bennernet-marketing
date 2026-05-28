#!/usr/bin/env python3
"""Append metrics to data/metrics_history.csv (append-only, idempotent).

Idempotency key: (date, property, metric). Rows with a key already present
in the file are skipped — the file is never overwritten or rewritten.
"""

import argparse
import csv
import json
import os
import sys

HISTORY_FILE = os.path.join(os.path.dirname(__file__), "..", "data", "metrics_history.csv")
HEADER = ["date", "property", "metric", "value", "unit_window", "source"]
VALID_PROPERTIES = {"glyc", "ibd"}


def _load_existing_keys(path: str) -> set:
    """Return the set of (date, property, metric) tuples already in the file."""
    keys = set()
    if not os.path.exists(path):
        return keys
    with open(path, newline="", encoding="utf-8") as fh:
        reader = csv.DictReader(fh)
        for row in reader:
            keys.add((row["date"], row["property"], row["metric"]))
    return keys


def _validate_row(row: dict) -> None:
    for field in HEADER:
        if field not in row or str(row[field]).strip() == "":
            raise ValueError(f"Missing or empty field: {field}")
    if row["property"] not in VALID_PROPERTIES:
        raise ValueError(f"property must be one of {VALID_PROPERTIES}, got: {row['property']!r}")
    try:
        float(row["value"])
    except (ValueError, TypeError):
        raise ValueError(f"value must be numeric, got: {row['value']!r}")


def append_rows(rows: list[dict], history_file: str = HISTORY_FILE) -> dict:
    """Append rows to history_file, skipping duplicates.

    Returns {"appended": int, "skipped": int}.
    """
    path = os.path.realpath(history_file)
    existing_keys = _load_existing_keys(path)

    need_header = not os.path.exists(path) or os.path.getsize(path) == 0
    appended = 0
    skipped = 0

    with open(path, "a", newline="", encoding="utf-8") as fh:
        writer = csv.DictWriter(fh, fieldnames=HEADER, lineterminator="\n")
        if need_header:
            writer.writeheader()

        for row in rows:
            _validate_row(row)
            key = (str(row["date"]), str(row["property"]), str(row["metric"]))
            if key in existing_keys:
                skipped += 1
                continue
            writer.writerow({f: row[f] for f in HEADER})
            existing_keys.add(key)
            appended += 1

    return {"appended": appended, "skipped": skipped}


def _parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description=__doc__)
    src = parser.add_mutually_exclusive_group(required=True)
    src.add_argument("--from-json", metavar="FILE",
                     help="JSON file containing a list of row dicts")
    src.add_argument("--date", help="Snapshot date (YYYY-MM-DD)")

    parser.add_argument("--property", choices=list(VALID_PROPERTIES))
    parser.add_argument("--metric")
    parser.add_argument("--value")
    parser.add_argument("--window", dest="unit_window")
    parser.add_argument("--source")
    parser.add_argument("--file", default=HISTORY_FILE,
                        help="Path to history CSV (default: data/metrics_history.csv)")
    return parser.parse_args()


def main() -> None:
    args = _parse_args()

    if args.from_json:
        with open(args.from_json, encoding="utf-8") as fh:
            rows = json.load(fh)
        if not isinstance(rows, list):
            sys.exit("--from-json: expected a JSON array at the top level")
    else:
        for flag in ("property", "metric", "value", "unit_window", "source"):
            if getattr(args, flag) is None:
                sys.exit(f"--{flag.replace('_', '-')} is required when not using --from-json")
        rows = [{"date": args.date, "property": args.property, "metric": args.metric,
                 "value": args.value, "unit_window": args.unit_window, "source": args.source}]

    result = append_rows(rows, history_file=args.file)
    print(f"appended={result['appended']} skipped={result['skipped']}")


if __name__ == "__main__":
    main()
