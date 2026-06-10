#!/usr/bin/env python3
"""Generate the MBR markdown skeleton from a deck JSON.

All numbers come from the deck — never recomputed here.
Exception narrative, monthly summary, and ops blockers are stubs for Haiku to fill.

Usage:
    python3 scripts/compute_deck.py | python3 scripts/generate_mbr.py
    python3 scripts/generate_mbr.py --deck /tmp/deck.json
    python3 scripts/generate_mbr.py --deck /tmp/deck.json --out /tmp/mbr.md
"""
from __future__ import annotations

import argparse
import json
import sys
from datetime import date
from typing import Optional

MBR_DOC_ID = "1cRyn0whwGDROWk4RoeI1h4EHWbgbIQUQ_8VTSvQotBQ"
MBR_CORPUS_FOLDER = "17sEegv6wsQC-w1FVzvSDyurzje2abpKx"

GLYC_INPUTS = [
    ("cadence_recipes_actual",      "Recipes cadence (latest wk)"),
    ("cadence_articles_actual",     "Articles cadence (latest wk)"),
    ("cadence_social_bsky_actual",  "Bluesky cadence (latest wk)"),
    ("cadence_social_masto_actual", "Mastodon cadence (latest wk)"),
    ("cadence_social_ig_actual",    "Instagram cadence (latest wk)"),
    ("indexed_pages",               "Indexed pages"),
    ("social_bsky_followers",       "Bluesky followers"),
    ("social_ig_followers",         "Instagram followers"),
    ("social_masto_followers",      "Mastodon followers"),
    ("utm_social_sessions",         "UTM social sessions (28d)"),
]

GLYC_OUTPUTS = [
    ("ga4_sign_ups",                "Sign-ups (28d) ★"),
    ("ga4_debotted_sessions",       "De-botted engaged sessions (28d)"),
    ("gsc_clicks",                  "Organic clicks (7d)"),
    ("gsc_impressions",             "GSC impressions (7d)"),
    ("gsc_targetset_avg_position",  "Target-query avg position"),
    ("gsc_targetset_ranking_count", "Target queries ranking (of 20)"),
    ("gsc_targetset_page1to3_count","Target queries on pg 1–3"),
    ("gsc_avg_position",            "All-query avg position (noise — ref only)"),
]

IBD_INPUTS = [
    ("cadence_articles_actual",     "Articles cadence (latest wk)"),
    ("cadence_social_bsky_actual",  "Bluesky cadence (latest wk)"),
    ("cadence_social_masto_actual", "Mastodon cadence (latest wk)"),
    ("cadence_social_ig_actual",    "Instagram cadence (latest wk)"),
    ("indexed_pages",               "Indexed pages"),
    ("social_bsky_followers",       "Bluesky followers"),
    ("social_ig_followers",         "Instagram followers"),
    ("social_masto_followers",      "Mastodon followers"),
    ("utm_social_sessions",         "UTM social sessions (28d)"),
]

IBD_OUTPUTS = [
    ("ga4_debotted_sessions",       "De-botted engaged sessions (28d) ★"),
    ("ga4_returning_users",         "Returning sessions (28d)"),
    ("gsc_impressions",             "GSC impressions (7d)"),
    ("gsc_clicks",                  "Organic clicks (7d)"),
    ("gsc_targetset_avg_position",  "Target-query avg position"),
    ("gsc_targetset_ranking_count", "Target queries ranking (of 20)"),
    ("gsc_targetset_page1to3_count","Target queries on pg 1–3"),
    ("gsc_avg_position",            "All-query avg position (noise — ref only)"),
]


# ---------------------------------------------------------------------------
# Formatting helpers
# ---------------------------------------------------------------------------

def fmt_val(v: Optional[float], metric: str) -> str:
    if v is None:
        return "_pending_"
    if "avg_position" in metric:
        return f"pg {v/10:.0f} ({v:.1f})" if v >= 10 else f"{v:.1f}"
    if isinstance(v, float) and v == int(v):
        return str(int(v))
    return str(round(v, 1))


def fmt_cadence(entry: dict) -> tuple[str, str, str]:
    """Return (latest_wk, mom, vs_pace) for a cadence metric entry."""
    ca = entry.get("cadence_adherence")
    if not ca:
        return "_pending_", "—", "_pending_"

    actual = int(ca["actual"]) if ca["actual"] == int(ca["actual"]) else ca["actual"]
    planned = ca["planned"]
    pct = ca["adherence_pct"]
    retro = ca.get("retro", False)
    retro_tag = " _(retro)_" if retro else ""

    latest_wk = f"{actual}/{planned} ({pct:.0f}%){retro_tag}"

    d = entry.get("mom_delta", {})
    mom_abs = d.get("abs")
    if mom_abs is None:
        mom = "—"
    else:
        sign = "+" if mom_abs >= 0 else ""
        mom = f"{sign}{int(mom_abs) if mom_abs == int(mom_abs) else round(mom_abs, 1)}"

    pace_icon = "✅" if pct >= 90 else "⚠️"
    vs_pace = f"{pace_icon} target ≥90%"

    return latest_wk, mom, vs_pace


def fmt_mom(entry: dict) -> str:
    d = entry.get("mom_delta", {})
    abs_ = d.get("abs")
    pct = d.get("pct")
    hib = entry.get("target", {}).get("higher_is_better", True)

    if abs_ is None:
        return "—"

    sign = "+" if abs_ >= 0 else ""
    abs_str = f"{sign}{int(abs_) if abs_ == int(abs_) else round(abs_, 1)}"
    pct_str = f" ({sign}{pct:.0f}%)" if pct is not None else ""

    if abs_ == 0:
        arrow = "→"
    elif (abs_ > 0 and hib) or (abs_ < 0 and not hib):
        arrow = "✅"
    else:
        arrow = "⚠️"

    return f"{arrow} {abs_str}{pct_str}"


def fmt_pace(entry: dict) -> str:
    tgt = entry.get("target", {})
    vt = entry.get("vs_target", {})
    q3 = tgt.get("q3")
    if q3 is None:
        return "directional"
    if "note" in vt:
        return "_pending_"
    pct = vt.get("pct_of_target")
    on_pace = vt.get("on_pace")
    if pct is None:
        return f"→{q3}"
    pace_icon = "✅" if on_pace else "⚠️"
    return f"{pace_icon} {pct:.0f}% of {q3}"


def metric_lookup(prop_data: dict, metric: str) -> Optional[dict]:
    for e in prop_data.get("metrics", []):
        if e["metric"] == metric:
            return e
    return None


# ---------------------------------------------------------------------------
# Table renderer (MoM-focused)
# ---------------------------------------------------------------------------

def render_table(prop_data: dict, rows: list[tuple[str, str]]) -> str:
    lines = ["| Metric | Latest wk | MoM Δ | vs Q3 target |",
             "|---|---|---|---|"]
    for metric_key, label in rows:
        entry = metric_lookup(prop_data, metric_key)
        if entry is None:
            lines.append(f"| {label} | _pending_ | — | — |")
            continue
        if metric_key.startswith("cadence_"):
            cur, mom, pace = fmt_cadence(entry)
        else:
            cur = fmt_val(entry["current"]["value"], metric_key)
            mom = fmt_mom(entry)
            pace = fmt_pace(entry)
        lines.append(f"| {label} | {cur} | {mom} | {pace} |")
    return "\n".join(lines)


def render_monthly_series(prop_data: dict, metric: str, label: str) -> str:
    entry = metric_lookup(prop_data, metric)
    if not entry:
        return f"_{label}: no data_"
    series = entry.get("monthly_series", [])
    if not series:
        return f"_{label}: no monthly data yet_"
    parts = [f"{s['month']}: {fmt_val(s['value'], metric)}" for s in series if s.get("value") is not None]
    return f"**{label}**: " + " → ".join(parts) if parts else f"_{label}: no data_"


# ---------------------------------------------------------------------------
# MBR markdown builder
# ---------------------------------------------------------------------------

def build_mbr(deck: dict) -> str:
    as_of = deck.get("as_of", date.today().isoformat())
    month_label = as_of[:7]  # YYYY-MM
    props = deck.get("properties", {})
    glyc = props.get("glyc", {})
    ibd = props.get("ibd", {})

    mom_baseline = None
    for e in glyc.get("metrics", []):
        bd = e.get("mom_delta", {}).get("baseline_date")
        if bd:
            mom_baseline = bd
            break

    exception_count = len(glyc.get("exceptions", [])) + len(ibd.get("exceptions", []))

    sections = []

    # ── Header ──────────────────────────────────────────────────────────────
    sections.append(f"""\
# MBR — Bennernet Marketing

**Review:** First Tuesday of month · **Prep:** Pop-Mark · **Reviewer:** Ben · **Reviews against:** [Marketing OP FY2026](https://github.com/cobenrogers/mission-control-wiki/blob/main/wiki/projects/marketing-operating-plan.md)

_Living document — refreshed monthly. Monthly pulse; MoM trajectory on every metric, vs Q3 OP targets, pre-loaded exceptions with root cause. Cadence rows show latest weekly adherence (MBR narrative covers full-month picture)._

**Data through:** {as_of}{f' · MoM vs {mom_baseline}' if mom_baseline else ''}

---

## The three questions

1. Did we invest consistently in the inputs this month? _(cadence + content)_
2. What did the outputs do month-over-month?
3. Are we on track to Q3 OP targets?

---
""")

    # ── Monthly series spotlight ─────────────────────────────────────────────
    glyc_ns_entry = metric_lookup(glyc, "ga4_sign_ups") or metric_lookup(glyc, "ga4_debotted_sessions")
    ibd_ns_entry = metric_lookup(ibd, "ga4_debotted_sessions")

    glyc_series = render_monthly_series(glyc, "ga4_sign_ups", "Glyc sign-ups")
    ibd_series = render_monthly_series(ibd, "ga4_debotted_sessions", "IBD debotted sessions")

    sections.append(f"""\
## North-star monthly trajectory

{glyc_series}

{ibd_series}

---
""")

    # ── Glyc ────────────────────────────────────────────────────────────────
    glyc_ns_val = glyc.get("north_star_value")
    glyc_ns_str = str(int(glyc_ns_val)) if glyc_ns_val is not None and glyc_ns_val == int(glyc_ns_val) else str(glyc_ns_val) if glyc_ns_val is not None else "_pending_"

    sections.append(f"""\
## Glyc — getglyc.com · north star: sign-ups ({glyc_ns_str} this period)

### Inputs (controllable — where the work is)

{render_table(glyc, GLYC_INPUTS)}

_Cadence shows latest weekly actuals/adherence. Pre-OP weeks (before 2026-06-09) are informational only._

### Outputs (lagging — MoM trajectory)

{render_table(glyc, GLYC_OUTPUTS)}

---
""")

    # ── IBD ─────────────────────────────────────────────────────────────────
    ibd_ns_val = ibd.get("north_star_value")
    ibd_ns_str = str(int(ibd_ns_val)) if ibd_ns_val is not None and ibd_ns_val == int(ibd_ns_val) else str(ibd_ns_val) if ibd_ns_val is not None else "_pending_"

    sections.append(f"""\
## IBD Movement — ibdmovement.com · north star: de-botted engaged sessions ({ibd_ns_str} this period)

### Inputs (controllable)

{render_table(ibd, IBD_INPUTS)}

_Cadence shows latest weekly actuals/adherence. Pre-OP weeks (before 2026-06-09) are informational only._

### Outputs (lagging)

{render_table(ibd, IBD_OUTPUTS)}

---
""")

    # ── Exception facts for Haiku ────────────────────────────────────────────
    exception_list_lines = []
    for prop_key, prop_data in [("glyc", glyc), ("ibd", ibd)]:
        prop_label = "Glyc" if prop_key == "glyc" else "IBD Movement"
        for exc in prop_data.get("exceptions", []):
            metric = exc["metric"]
            cur = exc["current"]["value"]
            reasons = "; ".join(exc.get("exception_reasons", []))
            mom_pct = exc.get("mom_delta", {}).get("pct")
            q3_tgt = exc.get("target", {}).get("q3")
            hib = exc.get("target", {}).get("higher_is_better", True)
            exception_list_lines.append(
                f"- **{prop_label} / {metric}**: current={cur}, reasons=[{reasons}]"
                + (f", mom_pct={mom_pct}%" if mom_pct is not None else "")
                + (f", q3_target={q3_tgt}, higher_is_better={hib}" if q3_tgt is not None else "")
            )

    exception_facts = "\n".join(exception_list_lines) if exception_list_lines else "_(none this month)_"

    sections.append(f"""\
## Exceptions this month — pre-loaded by Pop-Mark

_Format: what happened · root cause hypothesis · recommendation. {exception_count} exception(s) this month._

{{{{EXCEPTION_NARRATIVE}}}}

<!-- DECK EXCEPTION FACTS (for Haiku reference — do not render):
{exception_facts}
-->

---
""")

    # ── Monthly narrative ────────────────────────────────────────────────────
    sections.append("""\
## Monthly summary

_2-3 bullets: what worked this month, what didn't, one recommended focus for next month. Written by Pop-Mark from deck data only._

{{MONTHLY_SUMMARY}}

---
""")

    # ── Ops & blockers ──────────────────────────────────────────────────────
    sections.append("""\
## Ops & blockers

{{OPS_BLOCKERS}}
""")

    return "\n".join(sections)


# ---------------------------------------------------------------------------
# CLI
# ---------------------------------------------------------------------------

def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__,
                                     formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("--deck", metavar="FILE",
                        help="Path to deck JSON (default: read from stdin)")
    parser.add_argument("--out", metavar="FILE",
                        help="Write markdown to file instead of stdout")
    args = parser.parse_args()

    if args.deck:
        with open(args.deck, encoding="utf-8") as f:
            deck = json.load(f)
    else:
        deck = json.load(sys.stdin)

    md = build_mbr(deck)

    if args.out:
        with open(args.out, "w", encoding="utf-8") as f:
            f.write(md)
        print(f"Written to {args.out}", file=sys.stderr)
    else:
        print(md)


if __name__ == "__main__":
    main()
