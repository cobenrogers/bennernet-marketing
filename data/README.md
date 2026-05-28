# Metrics History Store

Append-only time-series of marketing metrics for Glyc (`getglyc.com`) and IBD Movement (`ibdmovement.com`).

## File

`data/metrics_history.csv`

## Schema

| Column | Type | Description |
|---|---|---|
| `date` | `YYYY-MM-DD` | Date the snapshot was recorded |
| `property` | `glyc` \| `ibd` | Which site |
| `metric` | string | Metric name (see below) |
| `value` | numeric | The measured value |
| `unit_window` | string | Measurement window (`28d`, `7d`, `snapshot`) |
| `source` | string | Where the value came from (`ga4_api`, `gsc_api`, `manual …`) |

**Idempotency key:** `(date, property, metric)` — the combination is unique. Duplicate rows for the same key are rejected by the append script.

## Metrics

| Metric | Window | Source | Notes |
|---|---|---|---|
| `ga4_debotted_sessions` | `28d` | `ga4_api` | Non-Direct channel sessions — de-botting proxy (bot traffic dominates Direct) |
| `ga4_engaged_sessions` | `28d` | `ga4_api` | GA4 engagedSessions metric |
| `ga4_total_users` | `28d` | `ga4_api` | GA4 totalUsers |
| `ga4_sign_ups` | `28d` | `ga4_api` | GA4 `sign_up` event count |
| `gsc_clicks` | `7d` | `gsc_api` | Organic search clicks |
| `gsc_impressions` | `7d` | `gsc_api` | Organic search impressions |
| `gsc_avg_position` | `7d` | `gsc_api` | Impression-weighted average search position |
| `indexed_pages` | `snapshot` | `manual` | Pages confirmed indexed in GSC (no API; manual capture) |
| `social_bsky_followers` | `snapshot` | `manual` | Bluesky followers |
| `social_masto_followers` | `snapshot` | `manual` | Mastodon followers |
| `social_x_followers` | `snapshot` | `manual` | X/Twitter followers (engagement N/A — paywall) |

## Append rule

**Never rewrite the file.** The `scripts/append_metrics.py` script opens the file in append-only mode. Before writing any row it checks that `(date, property, metric)` is not already present; if it is, the row is skipped silently. This makes all appends idempotent and safe to run on a cron schedule.

## Usage

```bash
# Append a single metric
python3 scripts/append_metrics.py \
  --date 2026-06-01 --property glyc --metric ga4_debotted_sessions \
  --value 82 --window 28d --source ga4_api

# Append from a JSON file (for cron batch appends)
python3 scripts/append_metrics.py --from-json /tmp/metrics_batch.json
```

JSON batch format:
```json
[
  {"date": "2026-06-01", "property": "glyc", "metric": "ga4_debotted_sessions", "value": 82, "unit_window": "28d", "source": "ga4_api"}
]
```
