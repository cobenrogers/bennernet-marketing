# Deck JSON Schema

Output of `scripts/compute_deck.py`. Consumed by WBR generator (bm#30), MBR generator (bm#31), and Port scoreboard view (bm#32).

## Top level

```json
{
  "as_of": "YYYY-MM-DD",
  "properties": {
    "<prop>": { ... }
  }
}
```

## Property block

```json
{
  "north_star_metric": "ga4_sign_ups",
  "north_star_value": 2.0,
  "metrics": [ ... ],
  "exceptions": [ ... ]
}
```

`exceptions` is a pre-filtered list of metric entries where `exception: true` — for fast exception-section rendering.

## Metric entry

```json
{
  "property": "glyc",
  "metric": "ga4_debotted_sessions",
  "category": "input | output",
  "north_star": false,
  "current": {
    "value": 101.0,
    "unit_window": "28d",
    "date": "2026-06-09"
  },
  "wow_delta": {
    "abs": -5.0,
    "pct": -4.7,
    "baseline_date": "2026-06-02"
  },
  "mom_delta": {
    "abs": -19.0,
    "pct": -15.8,
    "baseline_date": "2026-05-10"
  },
  "target": {
    "q3": 250,
    "q4": 500,
    "unit_window": "28d",
    "higher_is_better": true
  },
  "vs_target": {
    "on_pace": false,
    "pct_of_target": 40.4,
    "gap": -149.0
  },
  "trailing_6w": [
    { "week_start": "2026-04-28", "week_end": "2026-05-04", "value": 113.0, "date": "2026-05-04" },
    ...
  ],
  "monthly_series": [
    { "month": "2026-05", "value": 130.0, "date": "2026-05-27" },
    ...
  ],
  "exception": true,
  "exception_reasons": ["Off Q3 pace (40% of 250 target)"]
}
```

### Notes

- `wow_delta`: compares current vs nearest point 5–10 days prior. `null` if no prior point in window.
- `mom_delta`: compares current vs nearest point 25–36 days prior. `null` if no prior point.
- `vs_target`: `{"note": "_pending_"}` when no Q3 target is set (instrumentation gap).
- `trailing_6w`: 6 weekly buckets (Mon–Sun), oldest→newest. `value: null` if no data in that bucket.
- `monthly_series`: one point per calendar month (latest row in month), oldest→newest.
- `exception` triggers on: WoW drop/rise > ±15% (direction-aware), or off Q3 pace by > 15%.
- `pct_of_target` for lower-is-better metrics (e.g. `gsc_avg_position`): current/target × 100 — so 194% means current is nearly 2× the target, i.e., far off.

## Running

```bash
# Both properties, today
python3 scripts/compute_deck.py

# Single property
python3 scripts/compute_deck.py --property glyc

# Historical as-of
python3 scripts/compute_deck.py --as-of 2026-06-02

# Unit tests
python3 scripts/compute_deck.py --test
```
