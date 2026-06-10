#!/usr/bin/env python3
"""Capture weekly cadence actuals and append to metrics_history.csv.

Counts published content per channel per ISO week (Mon–Sun; row keyed by
week-ending Sunday).  Run weekly before WBR (Tuesdays) or use --backfill
to populate the trailing N weeks.

Usage:
    python3 scripts/capture_cadence.py                     # current week
    python3 scripts/capture_cadence.py --backfill 6        # trailing 6 complete weeks
    python3 scripts/capture_cadence.py --week 2026-06-08   # specific week-ending date
    python3 scripts/capture_cadence.py --dry-run           # print rows without writing

Sources:
    Glyc recipes       — https://getglyc.com/api/recipes (created_at)
    Glyc articles      — https://getglyc.com/api/articles (published_at)
    IBD articles       — https://ibdmovement.com/wp-json/wp/v2/posts (date, status=publish)
    Social (all)       — Postiz DB (publishDate, state=PUBLISHED, by integration ID)
    Community          — manual only; skipped by this script (use append_metrics.py)
"""
from __future__ import annotations

import argparse
import json
import os
import subprocess
import sys
import urllib.request
from collections import defaultdict
from datetime import date, timedelta
from typing import Optional

REPO_ROOT   = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
PLAN_FILE   = os.path.join(REPO_ROOT, "data", "cadence_plan.json")
HISTORY_FILE = os.path.join(REPO_ROOT, "data", "metrics_history.csv")

USER_AGENT = "pop-mark-cadence-collector/1.0"

# Postiz integration IDs → (property, channel)
POSTIZ_INTEGRATIONS: dict[str, tuple[str, str]] = {
    "cmouj99190001pi8h1f0upfga": ("glyc", "bsky"),
    "cmouqqkw70001o08gts5rpnyb": ("glyc", "masto"),
    "cmpbr9le70003mo8mzzg84o2d": ("glyc", "x"),   # not in OP cadence plan; X posts contribute to engagement but plan tracks bsky/masto/ig
    "cmq2rp6l1001ol98ugo3dz6oh": ("glyc", "ig"),
    "cmpbj9osm0008poec8q68tlgo": ("ibd",  "bsky"),
    "cmouqudgd0003o08gq5w1q3jj": ("ibd",  "masto"),
    "cmpbr6c0n0001mo8mj5m2d3hx": ("ibd",  "x"),   # ditto
    "cmq142urk0017l98u8phwixop": ("ibd",  "ig"),
}

# Only these channels feed into cadence_plan actuals (X is tracked but not in plan)
PLAN_CHANNELS = {"bsky", "masto", "ig"}


# ---------------------------------------------------------------------------
# Week helpers
# ---------------------------------------------------------------------------

def week_ending(d: date) -> date:
    """Return the Sunday of the ISO week containing d."""
    return d - timedelta(days=d.weekday()) + timedelta(days=6)


def week_start(we: date) -> date:
    return we - timedelta(days=6)


def complete_weeks_before(as_of: date, n: int) -> list[date]:
    """Return the last n complete week-ending Sundays strictly before as_of."""
    last_complete_we = week_ending(as_of - timedelta(days=as_of.weekday() + 1))
    return [last_complete_we - timedelta(weeks=i) for i in range(n)]


# ---------------------------------------------------------------------------
# Fetchers
# ---------------------------------------------------------------------------

def _get_json(url: str) -> object:
    req = urllib.request.Request(url, headers={"User-Agent": USER_AGENT})
    with urllib.request.urlopen(req, timeout=15) as r:
        return json.load(r)


def fetch_glyc_recipes(since: date) -> dict[date, int]:
    """Count Glyc recipes published per week-ending since date."""
    by_we: dict[date, int] = defaultdict(int)
    page = 1
    while True:
        d = _get_json(f"https://getglyc.com/api/recipes?per_page=50&order_by=created_at&order=desc&page={page}")
        for r in d["recipes"]:
            dt = date.fromisoformat(r["created_at"][:10])
            if dt < since:
                return by_we
            by_we[week_ending(dt)] += 1
        if page >= d["pagination"]["totalPages"]:
            break
        page += 1
    return by_we


def fetch_glyc_articles(since: date) -> dict[date, int]:
    """Count Glyc articles published per week-ending since date."""
    by_we: dict[date, int] = defaultdict(int)
    d = _get_json("https://getglyc.com/api/articles")
    for a in d.get("articles", []):
        pub = a.get("published_at", "")[:10]
        if not pub:
            continue
        dt = date.fromisoformat(pub)
        if dt < since:
            continue
        by_we[week_ending(dt)] += 1
    return by_we


def fetch_ibd_articles(since: date) -> dict[date, int]:
    """Count IBD WP posts published per week-ending since date."""
    by_we: dict[date, int] = defaultdict(int)
    url = (f"https://ibdmovement.com/wp-json/wp/v2/posts"
           f"?per_page=100&after={since.isoformat()}T00:00:00"
           f"&_fields=id,date,status&orderby=date&order=desc")
    # WP REST may require a specific UA; use curl subprocess to bypass 406
    result = subprocess.run(
        ["curl", "-s", "-A", USER_AGENT, url],
        capture_output=True, text=True, timeout=20
    )
    posts = json.loads(result.stdout)
    for p in posts:
        if p.get("status") != "publish":
            continue
        dt = date.fromisoformat(p["date"][:10])
        by_we[week_ending(dt)] += 1
    return by_we


def fetch_postiz_social(since: date) -> dict[tuple[str, str, date], int]:
    """Count Postiz published posts per (property, channel, week_ending) since date."""
    result = subprocess.run([
        "docker", "exec", "postiz-postgres", "psql",
        "-U", "postiz-user", "postiz-db-local", "-t", "-c",
        f"""
        SELECT
            p."integrationId",
            DATE_TRUNC('week', p."publishDate")::date AS week_mon,
            COUNT(*) AS cnt
        FROM "Post" p
        WHERE p.state = 'PUBLISHED'
          AND p."deletedAt" IS NULL
          AND p."publishDate" >= '{since.isoformat()}'
        GROUP BY p."integrationId", week_mon
        ORDER BY week_mon, p."integrationId";
        """
    ], capture_output=True, text=True, timeout=30)

    counts: dict[tuple[str, str, date], int] = defaultdict(int)
    for line in result.stdout.splitlines():
        parts = [p.strip() for p in line.split("|")]
        if len(parts) < 3 or not parts[2].strip():
            continue
        int_id = parts[0].strip()
        week_mon_str = parts[1].strip()[:10]
        cnt = int(parts[2].strip())
        mapping = POSTIZ_INTEGRATIONS.get(int_id)
        if not mapping or not week_mon_str:
            continue
        prop, channel = mapping
        if channel not in PLAN_CHANNELS:
            continue
        we = date.fromisoformat(week_mon_str) + timedelta(days=6)
        counts[(prop, channel, we)] += cnt
    return counts


# ---------------------------------------------------------------------------
# Row builder
# ---------------------------------------------------------------------------

def build_rows(weeks: list[date], as_of: date) -> list[dict]:
    """Fetch all sources and build metric rows for the given week-ending dates."""
    since = min(weeks) - timedelta(days=1)

    print(f"Fetching Glyc recipes...", file=sys.stderr)
    glyc_recipes = fetch_glyc_recipes(since)

    print(f"Fetching Glyc articles...", file=sys.stderr)
    glyc_articles = fetch_glyc_articles(since)

    print(f"Fetching IBD articles...", file=sys.stderr)
    ibd_articles = fetch_ibd_articles(since)

    print(f"Fetching Postiz social...", file=sys.stderr)
    social = fetch_postiz_social(since)

    op_effective = date(2026, 6, 9)
    rows = []

    for we in sorted(weeks):
        ws = week_start(we)
        retro = we < op_effective  # pre-OP weeks are informational
        source_tag = "api+retro" if retro else "api"

        # Glyc recipes
        val = glyc_recipes.get(we, 0)
        rows.append({"date": we.isoformat(), "property": "glyc",
                     "metric": "cadence_recipes_actual", "value": str(val),
                     "unit_window": "weekly", "source": source_tag})

        # Glyc articles
        val = glyc_articles.get(we, 0)
        rows.append({"date": we.isoformat(), "property": "glyc",
                     "metric": "cadence_articles_actual", "value": str(val),
                     "unit_window": "weekly", "source": source_tag})

        # IBD articles
        val = ibd_articles.get(we, 0)
        rows.append({"date": we.isoformat(), "property": "ibd",
                     "metric": "cadence_articles_actual", "value": str(val),
                     "unit_window": "weekly", "source": source_tag})

        # Social per property+channel
        for prop in ("glyc", "ibd"):
            for channel in ("bsky", "masto", "ig"):
                val = social.get((prop, channel, we), 0)
                rows.append({"date": we.isoformat(), "property": prop,
                             "metric": f"cadence_social_{channel}_actual",
                             "value": str(val), "unit_window": "weekly",
                             "source": source_tag})

    return rows


# ---------------------------------------------------------------------------
# CLI
# ---------------------------------------------------------------------------

def main() -> None:
    import sys
    sys.path.insert(0, os.path.dirname(__file__))
    from append_metrics import append_rows

    parser = argparse.ArgumentParser(description=__doc__,
                                     formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("--backfill", type=int, metavar="N", default=0,
                        help="Backfill the last N complete weeks (default: 1)")
    parser.add_argument("--week", metavar="YYYY-MM-DD",
                        help="Specific week-ending Sunday date to capture")
    parser.add_argument("--as-of", metavar="YYYY-MM-DD",
                        help="Reference date for 'current week' (default: today)")
    parser.add_argument("--dry-run", action="store_true",
                        help="Print rows without writing to CSV")
    args = parser.parse_args()

    as_of = date.fromisoformat(args.as_of) if args.as_of else date.today()

    if args.week:
        weeks = [date.fromisoformat(args.week)]
    elif args.backfill:
        weeks = complete_weeks_before(as_of, args.backfill)
    else:
        # Default: last complete week
        weeks = complete_weeks_before(as_of, 1)

    print(f"Capturing cadence for {len(weeks)} week(s): "
          f"{min(weeks).isoformat()} → {max(weeks).isoformat()}", file=sys.stderr)

    rows = build_rows(weeks, as_of)

    if args.dry_run:
        for r in rows:
            print(json.dumps(r))
        print(f"\n{len(rows)} rows (dry-run, not written)", file=sys.stderr)
        return

    result = append_rows(rows, history_file=HISTORY_FILE)
    print(f"appended={result['appended']} skipped={result['skipped']}", flush=True)


if __name__ == "__main__":
    main()
