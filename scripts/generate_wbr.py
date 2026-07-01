#!/usr/bin/env python3
"""Generate the WBR markdown skeleton from a deck JSON.

All numbers come from the deck — never recomputed here.
Exception narrative slots are left as {{EXCEPTION_NARRATIVE}} for Haiku to fill.
Ops & blockers slot is left as {{OPS_BLOCKERS}} for Haiku to fill.

Usage:
    python3 scripts/compute_deck.py | python3 scripts/generate_wbr.py
    python3 scripts/generate_wbr.py --deck /tmp/deck.json
    python3 scripts/generate_wbr.py --deck /tmp/deck.json --out /tmp/wbr.md
"""
from __future__ import annotations

import argparse
import json
import sys
from datetime import date
from typing import Optional

WBR_DOC_ID = "11wtSWFnbSMnn-A8SkTfDuHwGgjKFejIsGPzG_upMM_A"

# Metric display names + row ordering for WBR tables
GLYC_INPUTS = [
    ("cadence_recipes_actual",      "Recipes cadence (actual/planned)"),
    ("cadence_articles_actual",     "Articles cadence (actual/planned)"),
    ("cadence_social_bsky_actual",  "Bluesky cadence (actual/planned)"),
    ("cadence_social_masto_actual", "Mastodon cadence (actual/planned)"),
    ("cadence_social_ig_actual",      "Instagram cadence (actual/planned)"),
    ("cadence_social_x_actual",       "X.com cadence (actual/planned)"),
    ("cadence_social_fb_actual",      "Facebook cadence (actual/planned)"),
    ("indexed_pages",                 "Indexed pages"),
    ("social_bsky_followers",         "Bluesky followers"),
    ("social_ig_followers",           "Instagram followers"),
    ("social_fb_followers",           "Facebook followers"),
    ("social_masto_followers",        "Mastodon followers"),
    ("utm_social_sessions",           "UTM social sessions (28d)"),
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
    ("cadence_articles_actual",     "Articles cadence (actual/planned)"),
    ("cadence_social_bsky_actual",  "Bluesky cadence (actual/planned)"),
    ("cadence_social_masto_actual", "Mastodon cadence (actual/planned)"),
    ("cadence_social_ig_actual",      "Instagram cadence (actual/planned)"),
    ("cadence_social_x_actual",       "X.com cadence (actual/planned)"),
    ("cadence_social_fb_actual",      "Facebook cadence (actual/planned)"),
    ("indexed_pages",                 "Indexed pages"),
    ("social_bsky_followers",         "Bluesky followers"),
    ("social_ig_followers",           "Instagram followers"),
    ("social_fb_followers",           "Facebook followers"),
    ("social_masto_followers",        "Mastodon followers"),
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
    """Return (this_wk, wow, vs_pace) for a cadence metric entry."""
    ca = entry.get("cadence_adherence")
    if not ca:
        return "_pending_", "—", "_pending_"

    actual = int(ca["actual"]) if ca["actual"] == int(ca["actual"]) else ca["actual"]
    planned = ca["planned"]
    pct = ca["adherence_pct"]
    retro = ca.get("retro", False)
    retro_tag = " _(retro)_" if retro else ""

    this_wk = f"{actual}/{planned} ({pct:.0f}%){retro_tag}"

    # WoW for cadence: show raw wow_delta on the actual count
    d = entry.get("wow_delta", {})
    wow_abs = d.get("abs")
    if wow_abs is None:
        wow = "—"
    else:
        sign = "+" if wow_abs >= 0 else ""
        wow = f"{sign}{int(wow_abs) if wow_abs == int(wow_abs) else round(wow_abs, 1)}"

    # vs pace: target is ≥90% adherence
    pace_icon = "✅" if pct >= 90 else "⚠️"
    vs_pace = f"{pace_icon} target ≥90%"

    return this_wk, wow, vs_pace


def fmt_wow(entry: dict) -> str:
    d = entry.get("wow_delta", {})
    abs_ = d.get("abs")
    pct = d.get("pct")
    metric = entry["metric"]
    hib = entry.get("target", {}).get("higher_is_better", True)

    if abs_ is None:
        return "—"

    sign = "+" if abs_ >= 0 else ""
    abs_str = f"{sign}{int(abs_) if abs_ == int(abs_) else round(abs_, 1)}"
    pct_str = f" ({sign}{pct:.0f}%)" if pct is not None else ""

    # Arrow direction indicator (good/bad)
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
# Table renderer
# ---------------------------------------------------------------------------

def render_table(prop_data: dict, rows: list[tuple[str, str]]) -> str:
    lines = ["| Metric | This wk | WoW Δ | vs OP pace |",
             "|---|---|---|---|"]
    for metric_key, label in rows:
        entry = metric_lookup(prop_data, metric_key)
        if entry is None:
            lines.append(f"| {label} | _pending_ | — | — |")
            continue
        if metric_key.startswith("cadence_"):
            cur, wow, pace = fmt_cadence(entry)
        else:
            cur = fmt_val(entry["current"]["value"], metric_key)
            wow = fmt_wow(entry)
            pace = fmt_pace(entry)
        lines.append(f"| {label} | {cur} | {wow} | {pace} |")
    return "\n".join(lines)


# ---------------------------------------------------------------------------
# WBR markdown builder
# ---------------------------------------------------------------------------

def fmt_budget_row(label: str, entry: dict, unit: str = "$") -> str:
    value = entry.get("value")
    error = entry.get("error")
    if error is not None:
        return f"| {label} | _unavailable ({error})_ |"
    if unit == "$":
        return f"| {label} | ${value:,.2f} |"
    return f"| {label} | {value:,.2f} credits |"


def render_budget_section(deck: dict) -> str:
    budget = deck.get("budget", {})
    if not budget:
        return "_(balance cache not available this run)_"
    rows = [
        fmt_budget_row("Anthropic", budget.get("anthropic", {})),
        fmt_budget_row("OpenAI", budget.get("openai", {})),
        fmt_budget_row("Stability AI", budget.get("stability_ai", {}), unit="credits"),
    ]
    return "| Platform | Balance |\n| --- | --- |\n" + "\n".join(rows)


def build_wbr(deck: dict) -> str:
    as_of = deck.get("as_of", date.today().isoformat())
    props = deck.get("properties", {})
    glyc = props.get("glyc", {})
    ibd = props.get("ibd", {})

    # Compute WoW baseline date from first metric with a baseline_date
    wow_baseline = None
    for e in glyc.get("metrics", []):
        bd = e.get("wow_delta", {}).get("baseline_date")
        if bd:
            wow_baseline = bd
            break

    exception_count = len(glyc.get("exceptions", [])) + len(ibd.get("exceptions", []))

    sections = []

    # ── Header ──────────────────────────────────────────────────────────────
    sections.append(f"""\
# WBR — Bennernet Marketing

**Review:** Tuesdays · **Prep:** Pop-Mark · **Reviewer:** Ben · **Reviews against:** [Marketing OP FY2026](https://github.com/cobenrogers/mission-control-wiki/blob/main/wiki/projects/marketing-operating-plan.md)

_Living document — refreshed every Tuesday before review. Operational pulse; ~80% input-metric focus. Every exception carries a pre-loaded root cause + recommendation (or "investigating")._

**Data through:** {as_of}{f' · WoW vs {wow_baseline}' if wow_baseline else ''}

---

## The three questions

**1. What did our audience experience last week?** _(customer-facing inputs first)_

{{{{Q1_NARRATIVE}}}}

**2. How did the business perform last week?**

{{{{Q2_NARRATIVE}}}}

**3. Are we on track to the OP targets?**

{{{{Q3_NARRATIVE}}}}

---
""")

    # ── Glyc ────────────────────────────────────────────────────────────────
    glyc_ns_val = glyc.get("north_star_value")
    glyc_ns_str = str(int(glyc_ns_val)) if glyc_ns_val is not None and glyc_ns_val == int(glyc_ns_val) else str(glyc_ns_val) if glyc_ns_val is not None else "_pending_"

    sections.append(f"""\
## Glyc — getglyc.com · north star: sign-ups ({glyc_ns_str} this period)

### Business Update

{{{{GLYC_BUSINESS_UPDATE}}}}

### Highlights & Lowlights

{{{{GLYC_HIGHLIGHTS_LOWLIGHTS}}}}

### Inputs (controllable — where the work is)

{render_table(glyc, GLYC_INPUTS)}

_Cadence rows marked (retro) are pre-OP (before 2026-06-09) — informational only; adherence targets apply from week of Jun 9 forward._

### Outputs (lagging — track, don't obsess)

{render_table(glyc, GLYC_OUTPUTS)}

---
""")

    # ── IBD ─────────────────────────────────────────────────────────────────
    ibd_ns_val = ibd.get("north_star_value")
    ibd_ns_str = str(int(ibd_ns_val)) if ibd_ns_val is not None and ibd_ns_val == int(ibd_ns_val) else str(ibd_ns_val) if ibd_ns_val is not None else "_pending_"

    sections.append(f"""\
## IBD Movement — ibdmovement.com · north star: de-botted engaged sessions ({ibd_ns_str} this period)

### Business Update

{{{{IBD_BUSINESS_UPDATE}}}}

### Highlights & Lowlights

{{{{IBD_HIGHLIGHTS_LOWLIGHTS}}}}

### Inputs (controllable)

{render_table(ibd, IBD_INPUTS)}

_Cadence rows marked (retro) are pre-OP (before 2026-06-09) — informational only; adherence targets apply from week of Jun 9 forward._

### Outputs

{render_table(ibd, IBD_OUTPUTS)}

---
""")

    # ── Exception summary for Haiku ─────────────────────────────────────────
    exception_list_lines = []
    for prop_key, prop_data in [("glyc", glyc), ("ibd", ibd)]:
        prop_label = "Glyc" if prop_key == "glyc" else "IBD Movement"
        for exc in prop_data.get("exceptions", []):
            metric = exc["metric"]
            cur = exc["current"]["value"]
            reasons = "; ".join(exc.get("exception_reasons", []))
            wow_pct = exc.get("wow_delta", {}).get("pct")
            q3_tgt = exc.get("target", {}).get("q3")
            hib = exc.get("target", {}).get("higher_is_better", True)
            exception_list_lines.append(
                f"- **{prop_label} / {metric}**: current={cur}, reasons=[{reasons}]"
                + (f", wow_pct={wow_pct}%" if wow_pct is not None else "")
                + (f", q3_target={q3_tgt}, higher_is_better={hib}" if q3_tgt is not None else "")
            )

    exception_facts = "\n".join(exception_list_lines) if exception_list_lines else "_(none this week)_"

    sections.append(f"""\
## Exceptions this week — pre-loaded by Pop-Mark

_Format: what happened · root cause hypothesis · recommendation. {exception_count} exception(s) this week._

{{{{EXCEPTION_NARRATIVE}}}}

<!-- DECK EXCEPTION FACTS (for Haiku reference — do not render):
{exception_facts}
-->

---
""")

    # ── Cadence adherence ───────────────────────────────────────────────────
    sections.append("""\
## Cadence adherence — did we pull the levers?

{{CADENCE_ADHERENCE}}

_Community cadence tracked manually. Pre-OP weeks (before 2026-06-09) are informational only._

---
""")

    # ── Risks & Challenges ──────────────────────────────────────────────────
    sections.append("""\
## Risks & Challenges

{{RISKS_CHALLENGES}}

---
""")

    # ── Action Items & Follow-ups ───────────────────────────────────────────
    sections.append("""\
## Action Items & Follow-ups

{{ACTION_ITEMS}}

---
""")

    # ── Ops & blockers ──────────────────────────────────────────────────────
    sections.append("""\
## Ops & blockers

{{OPS_BLOCKERS}}

---
""")

    # ── Budget platform balances ────────────────────────────────────────────
    sections.append(f"""\
## Budget — platform balances

{render_budget_section(deck)}
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

    md = build_wbr(deck)

    if args.out:
        with open(args.out, "w", encoding="utf-8") as f:
            f.write(md)
        print(f"Written to {args.out}", file=sys.stderr)
    else:
        print(md)


if __name__ == "__main__":
    main()
