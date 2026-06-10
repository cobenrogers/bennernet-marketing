#!/usr/bin/env python3
"""Capture GSC avg position for the target-query set (bm#29 item 4).

Pulls query-level GSC performance, matches against data/target_queries.json
(case-insensitive), and writes weekly metric rows. This is the *actionable*
position signal — replaces the all-query average noise in the WBR.

Metrics written per property (unit_window=weekly, keyed by week-ending Sunday):
    gsc_targetset_avg_position    — avg position across target queries that ARE ranking
    gsc_targetset_ranking_count   — # of target queries appearing in GSC at all (of 20)
    gsc_targetset_page1to3_count  — # of target queries at position <= 30 (pages 1-3)
    gsc_targetset_page1_count     — # of target queries at position <= 10 (page 1)

Usage:
    python3 scripts/capture_target_queries.py                    # last complete week
    python3 scripts/capture_target_queries.py --week 2026-06-07  # specific week-ending
    python3 scripts/capture_target_queries.py --dry-run
"""
from __future__ import annotations

import argparse
import json
import os
import subprocess
import sys
from datetime import date, timedelta
from typing import Optional

REPO_ROOT      = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
TARGET_QUERIES = os.path.join(REPO_ROOT, "data", "target_queries.json")
HISTORY_FILE   = os.path.join(REPO_ROOT, "data", "metrics_history.csv")
GSC_SCRIPT     = "/home/ben/.openclaw/skills/gsc/scripts/gsc.py"
GSC_PROPERTIES = {"glyc": "sc-domain:getglyc.com", "ibd": "sc-domain:ibdmovement.com"}

PAGE1_POS   = 10.0   # position <= 10 = page 1
PAGE1TO3_POS = 30.0  # position <= 30 = pages 1-3


def week_ending(d: date) -> date:
    """Sunday of the ISO week containing d."""
    return d - timedelta(days=d.weekday()) + timedelta(days=6)


def last_complete_week_ending(as_of: date) -> date:
    return week_ending(as_of - timedelta(days=as_of.weekday() + 1))


def load_target_queries() -> dict:
    with open(TARGET_QUERIES, encoding="utf-8") as f:
        return json.load(f)


def fetch_gsc_queries(gsc_property: str, days: int) -> dict[str, float]:
    """Return {query_lower: position} for all queries GSC reports in the window."""
    result = subprocess.run(
        ["python3", GSC_SCRIPT, "performance", gsc_property, f"--days={days}", "--dim=query"],
        capture_output=True, text=True, timeout=60,
    )
    if result.returncode != 0:
        raise RuntimeError(result.stderr.strip() or f"gsc exit {result.returncode}")

    positions: dict[str, float] = {}
    for line in result.stdout.strip().splitlines():
        if line.startswith("#") or line.startswith("query") or not line.strip():
            continue
        parts = line.split("\t")
        if len(parts) < 5:
            continue
        # query may be wrapped in [brackets] for anonymized/low-volume terms
        q = parts[0].strip().strip("[]").lower()
        try:
            pos = float(parts[4])
            imp = int(parts[2])
        except ValueError:
            continue
        if imp <= 0:
            continue
        # keep best (lowest) position if a query appears twice
        if q not in positions or pos < positions[q]:
            positions[q] = pos
    return positions


def match_targets(targets: list[str], gsc_positions: dict[str, float]) -> dict:
    """Match target queries against GSC positions.

    Exact (case-insensitive) match only. Substring/fuzzy matching was rejected:
    a short GSC query like "low glycemic" would false-positive against every
    "low glycemic X" target, inflating the ranking count. A target "ranks" only
    when GSC reports that actual search query — the honest aspirational signal.
    GSC brackets/anonymizes very-low-volume queries, so this undercounts rather
    than overcounts, which is the safe direction for a progress metric.
    """
    ranking = {}      # target → position
    for t in targets:
        tl = t.lower()
        if tl in gsc_positions:
            ranking[t] = gsc_positions[tl]
    return ranking


def build_rows(week_end: date, as_of: date, dry_run: bool = False) -> list[dict]:
    cfg = load_target_queries()
    # Window: the 7 days of the target week. GSC has ~2-3 day lag, so for the
    # most recent complete week we query a slightly wider window and the
    # week-ending date labels the row.
    days_back = (as_of - (week_end - timedelta(days=6))).days
    days_back = max(7, min(days_back, 90))

    rows = []
    for prop, gsc_prop in GSC_PROPERTIES.items():
        targets = cfg.get(prop, [])
        if not targets:
            continue
        print(f"Fetching GSC queries for {prop} ({days_back}d window)...", file=sys.stderr)
        try:
            gsc_positions = fetch_gsc_queries(gsc_prop, days_back)
        except Exception as e:
            print(f"  ERROR {prop}: {e}", file=sys.stderr)
            continue

        ranking = match_targets(targets, gsc_positions)

        if ranking:
            avg_pos = round(sum(ranking.values()) / len(ranking), 1)
        else:
            avg_pos = None  # nothing ranking yet — render as gap, not zero

        ranking_count   = len(ranking)
        page1_count     = sum(1 for p in ranking.values() if p <= PAGE1_POS)
        page1to3_count  = sum(1 for p in ranking.values() if p <= PAGE1TO3_POS)

        print(f"  {prop}: {ranking_count}/{len(targets)} ranking, "
              f"avg_pos={avg_pos}, page1-3={page1to3_count}, page1={page1_count}",
              file=sys.stderr)
        if ranking:
            for t, p in sorted(ranking.items(), key=lambda x: x[1]):
                print(f"    {p:5.1f}  {t}", file=sys.stderr)

        we = week_end.isoformat()
        # avg_position only written when something ranks (skip null rows — deck reads latest present)
        if avg_pos is not None:
            rows.append({"date": we, "property": prop, "metric": "gsc_targetset_avg_position",
                         "value": str(avg_pos), "unit_window": "weekly", "source": "gsc_api"})
        rows.append({"date": we, "property": prop, "metric": "gsc_targetset_ranking_count",
                     "value": str(ranking_count), "unit_window": "weekly", "source": "gsc_api"})
        rows.append({"date": we, "property": prop, "metric": "gsc_targetset_page1to3_count",
                     "value": str(page1to3_count), "unit_window": "weekly", "source": "gsc_api"})
        rows.append({"date": we, "property": prop, "metric": "gsc_targetset_page1_count",
                     "value": str(page1_count), "unit_window": "weekly", "source": "gsc_api"})

    return rows


def main() -> None:
    sys.path.insert(0, os.path.dirname(__file__))
    from append_metrics import append_rows

    parser = argparse.ArgumentParser(description=__doc__,
                                     formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("--week", metavar="YYYY-MM-DD", help="Week-ending Sunday to label the row")
    parser.add_argument("--as-of", metavar="YYYY-MM-DD", help="Reference date (default: today)")
    parser.add_argument("--dry-run", action="store_true", help="Print rows without writing")
    args = parser.parse_args()

    as_of = date.fromisoformat(args.as_of) if args.as_of else date.today()
    week_end = date.fromisoformat(args.week) if args.week else last_complete_week_ending(as_of)

    print(f"Capturing target-query positions for week ending {week_end.isoformat()}", file=sys.stderr)
    rows = build_rows(week_end, as_of, dry_run=args.dry_run)

    if args.dry_run:
        for r in rows:
            print(json.dumps(r))
        print(f"\n{len(rows)} rows (dry-run, not written)", file=sys.stderr)
        return

    result = append_rows(rows, history_file=HISTORY_FILE)
    print(f"appended={result['appended']} skipped={result['skipped']}", flush=True)


if __name__ == "__main__":
    main()
