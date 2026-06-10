#!/usr/bin/env python3
"""Deterministic deck-compute script for WBR/MBR readouts.

Reads data/metrics_history.csv; emits a structured JSON deck with current
values, WoW/MoM deltas, vs-OP-target progress, trailing series, and
exception flags.  No network calls, no LLM.

Usage:
    python3 scripts/compute_deck.py [--property glyc|ibd|all] [--as-of YYYY-MM-DD]
    python3 scripts/compute_deck.py --test    # run unit tests against fixture

Output schema documented in docs/deck-schema.md (written alongside this file).
"""

from __future__ import annotations

import argparse
import csv
import json
import os
import sys
from collections import defaultdict
from datetime import date, timedelta
from typing import Optional

HISTORY_FILE = os.path.join(os.path.dirname(__file__), "..", "data", "metrics_history.csv")
CADENCE_PLAN_FILE = os.path.join(os.path.dirname(__file__), "..", "data", "cadence_plan.json")

# ---------------------------------------------------------------------------
# OP targets — sourced from wiki/projects/marketing-operating-plan.md
# Keys: (property, metric) → {q3, q4, unit_window, higher_is_better}
# q3 = Q3 exit Sep 2026, q4 = Q4 exit Dec 2026
# null = directional / not yet set
# ---------------------------------------------------------------------------
OP_TARGETS: dict[tuple[str, str], dict] = {
    # Glyc inputs
    ("glyc", "indexed_pages"):           {"q3": 130, "q4": 175,  "unit_window": "snapshot", "higher_is_better": True},
    ("glyc", "social_bsky_followers"):   {"q3": 150, "q4": 300,  "unit_window": "snapshot", "higher_is_better": True},
    ("glyc", "social_ig_followers"):     {"q3": 150, "q4": 300,  "unit_window": "snapshot", "higher_is_better": True},
    ("glyc", "social_masto_followers"):  {"q3": None,"q4": None, "unit_window": "snapshot", "higher_is_better": True},
    # cadence_adherence — not in CSV yet; instrumented in bm#29
    # Glyc outputs
    ("glyc", "ga4_sign_ups"):            {"q3": 5,   "q4": 10,   "unit_window": "28d",      "higher_is_better": True},
    ("glyc", "gsc_clicks"):              {"q3": 30,  "q4": 150,  "unit_window": "7d",       "higher_is_better": True},
    ("glyc", "gsc_avg_position"):        {"q3": 40,  "q4": 20,   "unit_window": "7d",       "higher_is_better": False},
    ("glyc", "ga4_debotted_sessions"):   {"q3": 250, "q4": 500,  "unit_window": "28d",      "higher_is_better": True},
    ("glyc", "utm_social_sessions"):     {"q3": None,"q4": None, "unit_window": "28d",      "higher_is_better": True},
    ("glyc", "gsc_impressions"):         {"q3": None,"q4": None, "unit_window": "7d",       "higher_is_better": True},
    # Glyc cadence inputs (planned = cadence_plan.json; adherence computed at runtime)
    ("glyc", "cadence_recipes_actual"):       {"q3": 90,  "q4": 90,   "unit_window": "weekly",   "higher_is_better": True},  # ≥90% adherence
    ("glyc", "cadence_articles_actual"):      {"q3": 90,  "q4": 90,   "unit_window": "weekly",   "higher_is_better": True},
    ("glyc", "cadence_social_bsky_actual"):   {"q3": 90,  "q4": 90,   "unit_window": "weekly",   "higher_is_better": True},
    ("glyc", "cadence_social_masto_actual"):  {"q3": 90,  "q4": 90,   "unit_window": "weekly",   "higher_is_better": True},
    ("glyc", "cadence_social_ig_actual"):     {"q3": 90,  "q4": 90,   "unit_window": "weekly",   "higher_is_better": True},
    # IBD inputs
    ("ibd",  "indexed_pages"):           {"q3": 130, "q4": 160,  "unit_window": "snapshot", "higher_is_better": True},
    ("ibd",  "social_bsky_followers"):   {"q3": 120, "q4": 200,  "unit_window": "snapshot", "higher_is_better": True},
    ("ibd",  "social_ig_followers"):     {"q3": 80,  "q4": 150,  "unit_window": "snapshot", "higher_is_better": True},
    ("ibd",  "social_masto_followers"):  {"q3": None,"q4": None, "unit_window": "snapshot", "higher_is_better": True},
    # IBD outputs
    ("ibd",  "ga4_debotted_sessions"):   {"q3": 120, "q4": 250,  "unit_window": "28d",      "higher_is_better": True},
    ("ibd",  "ga4_returning_users"):     {"q3": None,"q4": None, "unit_window": "28d",      "higher_is_better": True},
    ("ibd",  "gsc_impressions"):         {"q3": None,"q4": None, "unit_window": "7d",       "higher_is_better": True},
    ("ibd",  "gsc_clicks"):              {"q3": None,"q4": None, "unit_window": "7d",       "higher_is_better": True},
    ("ibd",  "gsc_avg_position"):        {"q3": None,"q4": None, "unit_window": "7d",       "higher_is_better": False},
    ("ibd",  "utm_social_sessions"):     {"q3": None,"q4": None, "unit_window": "28d",      "higher_is_better": True},
    # IBD cadence inputs
    ("ibd",  "cadence_articles_actual"):      {"q3": 90,  "q4": 90,   "unit_window": "weekly",   "higher_is_better": True},
    ("ibd",  "cadence_social_bsky_actual"):   {"q3": 90,  "q4": 90,   "unit_window": "weekly",   "higher_is_better": True},
    ("ibd",  "cadence_social_masto_actual"):  {"q3": 90,  "q4": 90,   "unit_window": "weekly",   "higher_is_better": True},
    ("ibd",  "cadence_social_ig_actual"):     {"q3": 90,  "q4": 90,   "unit_window": "weekly",   "higher_is_better": True},
}

# OP classification: which metrics are controllable inputs (80% of WBR attention)
OP_INPUTS: set[tuple[str, str]] = {
    ("glyc", "indexed_pages"),
    ("glyc", "social_bsky_followers"),
    ("glyc", "social_ig_followers"),
    ("glyc", "social_masto_followers"),
    ("glyc", "cadence_recipes_actual"),
    ("glyc", "cadence_articles_actual"),
    ("glyc", "cadence_social_bsky_actual"),
    ("glyc", "cadence_social_masto_actual"),
    ("glyc", "cadence_social_ig_actual"),
    ("ibd",  "indexed_pages"),
    ("ibd",  "social_bsky_followers"),
    ("ibd",  "social_ig_followers"),
    ("ibd",  "social_masto_followers"),
    ("ibd",  "cadence_articles_actual"),
    ("ibd",  "cadence_social_bsky_actual"),
    ("ibd",  "cadence_social_masto_actual"),
    ("ibd",  "cadence_social_ig_actual"),
}

# North-star metrics per property
NORTH_STARS: dict[str, str] = {
    "glyc": "ga4_sign_ups",
    "ibd":  "ga4_debotted_sessions",
}

# All OP metrics to include in the deck (ordered: inputs first, then outputs)
OP_METRICS_ORDERED: dict[str, list[str]] = {
    "glyc": [
        # inputs
        "indexed_pages",
        "social_bsky_followers",
        "social_ig_followers",
        "social_masto_followers",
        "cadence_recipes_actual",
        "cadence_articles_actual",
        "cadence_social_bsky_actual",
        "cadence_social_masto_actual",
        "cadence_social_ig_actual",
        # outputs
        "ga4_sign_ups",
        "ga4_debotted_sessions",
        "gsc_clicks",
        "gsc_impressions",
        "gsc_avg_position",
        "utm_social_sessions",
    ],
    "ibd": [
        # inputs
        "indexed_pages",
        "social_bsky_followers",
        "social_ig_followers",
        "social_masto_followers",
        "cadence_articles_actual",
        "cadence_social_bsky_actual",
        "cadence_social_masto_actual",
        "cadence_social_ig_actual",
        # outputs
        "ga4_debotted_sessions",
        "ga4_returning_users",
        "gsc_impressions",
        "gsc_clicks",
        "gsc_avg_position",
        "utm_social_sessions",
    ],
}

# Exception thresholds
WOW_EXCEPTION_PCT = 15.0   # ±15% WoW triggers an exception flag
PACE_EXCEPTION_PCT = 15.0  # >15% behind Q3 pace triggers exception

# Cadence plan — loaded once from data/cadence_plan.json
def _load_cadence_plan() -> dict:
    try:
        with open(CADENCE_PLAN_FILE, encoding="utf-8") as f:
            return json.load(f)
    except (FileNotFoundError, json.JSONDecodeError):
        return {}

CADENCE_PLAN = _load_cadence_plan()

# OP effective date — cadence adherence only meaningful from this date forward
CADENCE_OP_EFFECTIVE = date(2026, 6, 9)


# ---------------------------------------------------------------------------
# CSV loader
# ---------------------------------------------------------------------------

def load_history(path: str = HISTORY_FILE) -> dict[tuple[str, str], list[tuple[date, float, str]]]:
    """Return {(property, metric): [(date, value, unit_window), ...]} sorted asc by date."""
    store: dict[tuple[str, str], list] = defaultdict(list)
    with open(path, newline="", encoding="utf-8") as fh:
        for row in csv.DictReader(fh):
            try:
                d = date.fromisoformat(row["date"])
                v = float(row["value"])
            except (ValueError, KeyError):
                continue
            store[(row["property"], row["metric"])].append((d, v, row.get("unit_window", "")))
    for series in store.values():
        series.sort(key=lambda x: x[0])
    return dict(store)


# ---------------------------------------------------------------------------
# Series helpers
# ---------------------------------------------------------------------------

def latest(series: list) -> Optional[tuple]:
    return series[-1] if series else None


def closest_before(series: list, as_of: date, min_days: int, max_days: int) -> Optional[tuple]:
    """Return the newest point that is min_days–max_days before as_of."""
    lo = as_of - timedelta(days=max_days)
    hi = as_of - timedelta(days=min_days)
    candidates = [p for p in series if lo <= p[0] <= hi]
    return candidates[-1] if candidates else None


def trailing_weeks(series: list, as_of: date, n: int = 6) -> list[dict]:
    """Return up to n weekly anchor points (Mon–Sun buckets) ending at as_of."""
    # Build Mon-anchored week boundaries
    weeks = []
    # Walk back n complete weeks
    week_end = as_of
    for _ in range(n):
        week_start = week_end - timedelta(days=6)
        # Latest point in [week_start, week_end]
        pts = [p for p in series if week_start <= p[0] <= week_end]
        if pts:
            p = pts[-1]
            weeks.append({"week_start": week_start.isoformat(),
                          "week_end": week_end.isoformat(),
                          "value": p[1], "date": p[0].isoformat()})
        else:
            weeks.append({"week_start": week_start.isoformat(),
                          "week_end": week_end.isoformat(),
                          "value": None, "date": None})
        week_end = week_start - timedelta(days=1)
    weeks.reverse()
    return weeks


def monthly_series(series: list, as_of: date) -> list[dict]:
    """Return one point per calendar month (latest in month), oldest to newest."""
    by_month: dict[tuple[int, int], tuple] = {}
    for p in series:
        key = (p[0].year, p[0].month)
        if key not in by_month or p[0] > by_month[key][0]:
            by_month[key] = p
    result = []
    for (yr, mo), p in sorted(by_month.items()):
        if date(yr, mo, 1) <= as_of:
            result.append({"month": f"{yr:04d}-{mo:02d}", "value": p[1], "date": p[0].isoformat()})
    return result


# ---------------------------------------------------------------------------
# Pace helper
# ---------------------------------------------------------------------------
Q3_END = date(2026, 9, 30)
OP_BASELINE = date(2026, 6, 9)


def compute_pace(current: float, target: float, as_of: date,
                 higher_is_better: bool) -> dict:
    """Is current value on-pace to hit target by Q3?"""
    total_days = (Q3_END - OP_BASELINE).days
    elapsed_days = max(0, (as_of - OP_BASELINE).days)
    if total_days <= 0:
        return {"on_pace": None, "expected_by_now": None, "gap": None}

    fraction_elapsed = elapsed_days / total_days

    # Linear pace: what fraction of target should we be at by now?
    # For "lower is better" (avg_position), we start at baseline and need to
    # reach target — but we don't have the baseline stored here, so we use
    # a simpler gap-to-target ratio.
    pct_of_target = (current / target) * 100 if target else None
    if pct_of_target is None:
        return {"on_pace": None, "pct_of_target": None, "gap": None}

    if higher_is_better:
        # Need pct_of_target >= fraction_elapsed * 100 to be on pace
        expected_pct = fraction_elapsed * 100
        on_pace = pct_of_target >= (expected_pct - PACE_EXCEPTION_PCT)
    else:
        # Lower is better (avg_position): current should be ≤ target to be on pace
        # Use gap: negative gap = below target (good), positive = above (bad)
        on_pace = current <= target * (1 + PACE_EXCEPTION_PCT / 100)

    return {
        "on_pace": bool(on_pace),
        "pct_of_target": round(pct_of_target, 1) if pct_of_target is not None else None,
        "gap": round(current - target, 2),
    }


# ---------------------------------------------------------------------------
# Per-metric deck entry
# ---------------------------------------------------------------------------

def compute_metric_entry(
    prop: str,
    metric: str,
    store: dict,
    as_of: date,
) -> dict:
    series = store.get((prop, metric), [])
    tgt_cfg = OP_TARGETS.get((prop, metric), {})

    cur = latest(series)
    cur_val = cur[1] if cur else None
    cur_date = cur[0].isoformat() if cur else None
    cur_window = cur[2] if cur else None

    # WoW: 5–9 days back (flexible window to find a point)
    wow_base = closest_before(series, as_of, min_days=5, max_days=10)
    if cur and wow_base:
        wow_abs = round(cur[1] - wow_base[1], 2)
        wow_pct = round((wow_abs / wow_base[1]) * 100, 1) if wow_base[1] != 0 else None
        wow_base_date = wow_base[0].isoformat()
    else:
        wow_abs = wow_pct = wow_base_date = None

    # MoM: 25–35 days back
    mom_base = closest_before(series, as_of, min_days=25, max_days=36)
    if cur and mom_base:
        mom_abs = round(cur[1] - mom_base[1], 2)
        mom_pct = round((mom_abs / mom_base[1]) * 100, 1) if mom_base[1] != 0 else None
        mom_base_date = mom_base[0].isoformat()
    else:
        mom_abs = mom_pct = mom_base_date = None

    # vs-OP target
    q3 = tgt_cfg.get("q3")
    q4 = tgt_cfg.get("q4")
    hib = tgt_cfg.get("higher_is_better", True)
    vs_target: dict = {}
    if q3 is not None and cur_val is not None:
        vs_target = compute_pace(cur_val, q3, as_of, hib)

    # Trailing series
    t6w = trailing_weeks(series, as_of)
    monthly = monthly_series(series, as_of)

    # Exception logic
    exception = False
    exception_reasons = []

    if wow_pct is not None:
        if hib and wow_pct < -WOW_EXCEPTION_PCT:
            exception = True
            exception_reasons.append(f"WoW drop {wow_pct:+.1f}%")
        elif not hib and wow_pct > WOW_EXCEPTION_PCT:
            exception = True
            exception_reasons.append(f"WoW increase {wow_pct:+.1f}% (higher=worse)")

    if q3 is not None and cur_val is not None and not vs_target.get("on_pace", True):
        exception = True
        pct = vs_target.get("pct_of_target")
        exception_reasons.append(
            f"Off Q3 pace ({pct:.0f}% of {q3} target)" if pct is not None else "Off Q3 pace"
        )

    # Cadence adherence: for cadence_*_actual metrics, compute adherence % = actual / planned * 100
    cadence_adherence: Optional[dict] = None
    if metric.startswith("cadence_") and metric.endswith("_actual"):
        planned = CADENCE_PLAN.get(prop, {}).get(metric)
        if planned and cur_val is not None:
            adherence_pct = round((cur_val / planned) * 100, 1)
            is_retro = cur and cur[0] < CADENCE_OP_EFFECTIVE
            cadence_adherence = {
                "planned": planned,
                "actual": cur_val,
                "adherence_pct": min(adherence_pct, 100.0),  # cap at 100%
                "overshoot": max(0.0, adherence_pct - 100.0),
                "retro": is_retro,
            }

    return {
        "property": prop,
        "metric": metric,
        "category": "input" if (prop, metric) in OP_INPUTS else "output",
        "north_star": NORTH_STARS.get(prop) == metric,
        "cadence_adherence": cadence_adherence,
        "current": {
            "value": cur_val,
            "unit_window": cur_window,
            "date": cur_date,
        },
        "wow_delta": {
            "abs": wow_abs,
            "pct": wow_pct,
            "baseline_date": wow_base_date,
        },
        "mom_delta": {
            "abs": mom_abs,
            "pct": mom_pct,
            "baseline_date": mom_base_date,
        },
        "target": {
            "q3": q3,
            "q4": q4,
            "unit_window": tgt_cfg.get("unit_window"),
            "higher_is_better": hib,
        },
        "vs_target": vs_target if q3 is not None else {"note": "_pending_"},
        "trailing_6w": t6w,
        "monthly_series": monthly,
        "exception": exception,
        "exception_reasons": exception_reasons,
    }


# ---------------------------------------------------------------------------
# Full deck
# ---------------------------------------------------------------------------

def compute_deck(properties: list[str], as_of: date, history_path: str = HISTORY_FILE) -> dict:
    store = load_history(history_path)
    result = {"as_of": as_of.isoformat(), "properties": {}}
    for prop in properties:
        metrics = OP_METRICS_ORDERED.get(prop, [])
        entries = [compute_metric_entry(prop, m, store, as_of) for m in metrics]
        north_star_metric = NORTH_STARS.get(prop)
        result["properties"][prop] = {
            "north_star_metric": north_star_metric,
            "north_star_value": next(
                (e["current"]["value"] for e in entries if e["metric"] == north_star_metric),
                None,
            ),
            "metrics": entries,
            "exceptions": [e for e in entries if e["exception"]],
        }
    return result


# ---------------------------------------------------------------------------
# Tests (run with --test)
# ---------------------------------------------------------------------------

FIXTURE_CSV = """\
date,property,metric,value,unit_window,source
2026-05-26,glyc,ga4_debotted_sessions,120,28d,ga4_api
2026-06-02,glyc,ga4_debotted_sessions,100,28d,ga4_api
2026-06-09,glyc,ga4_debotted_sessions,80,28d,ga4_api
2026-05-26,glyc,indexed_pages,110,snapshot,gsc_ui
2026-06-02,glyc,indexed_pages,115,snapshot,gsc_ui
2026-06-09,glyc,indexed_pages,120,snapshot,gsc_ui
2026-05-26,glyc,ga4_sign_ups,1,28d,ga4_api
2026-06-09,glyc,ga4_sign_ups,2,28d,ga4_api
2026-06-09,glyc,gsc_clicks,0,7d,gsc_api
2026-06-09,glyc,gsc_avg_position,77.9,7d,gsc_api
"""


def run_tests() -> None:
    import io
    import tempfile

    with tempfile.NamedTemporaryFile(mode="w", suffix=".csv", delete=False) as f:
        f.write(FIXTURE_CSV)
        tmp = f.name

    as_of = date(2026, 6, 9)
    store = load_history(tmp)

    # WoW delta for debotted_sessions: 80 vs 100 = -20 abs, -20%
    s = store[("glyc", "ga4_debotted_sessions")]
    entry = compute_metric_entry("glyc", "ga4_debotted_sessions", store, as_of)
    assert entry["current"]["value"] == 80, f"current={entry['current']['value']}"
    assert entry["wow_delta"]["abs"] == -20.0, f"wow_abs={entry['wow_delta']['abs']}"
    assert entry["wow_delta"]["pct"] == -20.0, f"wow_pct={entry['wow_delta']['pct']}"
    assert entry["exception"] is True, "should flag exception on -20% WoW drop"
    assert "WoW drop" in entry["exception_reasons"][0]

    # indexed_pages: 120 vs Q3 target 130 → below target
    entry2 = compute_metric_entry("glyc", "indexed_pages", store, as_of)
    assert entry2["current"]["value"] == 120
    assert entry2["vs_target"]["pct_of_target"] == round((120 / 130) * 100, 1)
    assert entry2["category"] == "input"

    # ga4_sign_ups: north star
    entry3 = compute_metric_entry("glyc", "ga4_sign_ups", store, as_of)
    assert entry3["north_star"] is True

    # trailing_weeks: should return 6 buckets
    t6w = trailing_weeks(s, as_of)
    assert len(t6w) == 6

    # monthly_series: should have 2 months (May, Jun)
    ms = monthly_series(s, as_of)
    assert len(ms) == 2
    assert ms[0]["month"] == "2026-05"

    os.unlink(tmp)
    print("All tests passed.")


# ---------------------------------------------------------------------------
# CLI
# ---------------------------------------------------------------------------

def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("--property", choices=["glyc", "ibd", "all"], default="all")
    parser.add_argument("--as-of", metavar="YYYY-MM-DD",
                        help="Compute as of this date (default: today)")
    parser.add_argument("--test", action="store_true", help="Run unit tests and exit")
    parser.add_argument("--pretty", action="store_true", help="Pretty-print JSON")
    args = parser.parse_args()

    if args.test:
        run_tests()
        return

    as_of = date.fromisoformat(args.as_of) if args.as_of else date.today()
    props = ["glyc", "ibd"] if args.property == "all" else [args.property]
    deck = compute_deck(props, as_of)
    indent = 2 if args.pretty else None
    print(json.dumps(deck, indent=indent))


if __name__ == "__main__":
    main()
