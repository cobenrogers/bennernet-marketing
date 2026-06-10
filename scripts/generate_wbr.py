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
    ("indexed_pages",           "Indexed pages"),
    ("social_bsky_followers",   "Bluesky followers"),
    ("social_ig_followers",     "Instagram followers"),
    ("social_masto_followers",  "Mastodon followers"),
    ("utm_social_sessions",     "UTM social sessions (28d)"),
]

GLYC_OUTPUTS = [
    ("ga4_sign_ups",            "Sign-ups (28d) ★"),
    ("ga4_debotted_sessions",   "De-botted engaged sessions (28d)"),
    ("gsc_clicks",              "Organic clicks (7d)"),
    ("gsc_impressions",         "GSC impressions (7d)"),
    ("gsc_avg_position",        "Avg position"),
]

IBD_INPUTS = [
    ("indexed_pages",           "Indexed pages"),
    ("social_bsky_followers",   "Bluesky followers"),
    ("social_ig_followers",     "Instagram followers"),
    ("social_masto_followers",  "Mastodon followers"),
    ("utm_social_sessions",     "UTM social sessions (28d)"),
]

IBD_OUTPUTS = [
    ("ga4_debotted_sessions",   "De-botted engaged sessions (28d) ★"),
    ("ga4_returning_users",     "Returning users (28d)"),
    ("gsc_impressions",         "GSC impressions (7d)"),
    ("gsc_clicks",              "Organic clicks (7d)"),
    ("gsc_avg_position",        "Avg position"),
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
        cur = fmt_val(entry["current"]["value"], metric_key)
        wow = fmt_wow(entry)
        pace = fmt_pace(entry)
        lines.append(f"| {label} | {cur} | {wow} | {pace} |")
    return "\n".join(lines)


# ---------------------------------------------------------------------------
# WBR markdown builder
# ---------------------------------------------------------------------------

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

1. What did our audience experience last week? _(customer-facing inputs first)_
2. How did the business perform last week?
3. Are we on track to the OP targets?

---
""")

    # ── Glyc ────────────────────────────────────────────────────────────────
    glyc_ns_val = glyc.get("north_star_value")
    glyc_ns_str = str(int(glyc_ns_val)) if glyc_ns_val is not None and glyc_ns_val == int(glyc_ns_val) else str(glyc_ns_val) if glyc_ns_val is not None else "_pending_"

    sections.append(f"""\
## Glyc — getglyc.com · north star: sign-ups ({glyc_ns_str} this period)

### Inputs (controllable — where the work is)

{render_table(glyc, GLYC_INPUTS)}

_Cadence adherence (planned vs actual publish/post per channel) pending — instrumentation bm#29._

### Outputs (lagging — track, don't obsess)

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

_Cadence adherence pending — instrumentation bm#29._

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

| Channel | Planned/wk | Actual | Adherence |
|---|---|---|---|
| Glyc: recipes 5 · articles 2 · BSky 5 · Masto 5 · IG 3 | — | _pending_ | — |
| IBD: articles 2 · BSky 4 · Masto 4 · IG 3 | — | _pending_ | — |

_Blocked: cadence instrumentation (bm#29) lands June. Keystone input KPI — until live, the WBR can't answer "did we do what we said?"_

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

    md = build_wbr(deck)

    if args.out:
        with open(args.out, "w", encoding="utf-8") as f:
            f.write(md)
        print(f"Written to {args.out}", file=sys.stderr)
    else:
        print(md)


if __name__ == "__main__":
    main()
