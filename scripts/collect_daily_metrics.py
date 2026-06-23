#!/usr/bin/env python3
"""Daily metrics collection for the bennernet scoreboard history store.

Fetches GA4 + GSC metrics for both Glyc and IBD Movement, then appends
all collected rows to data/metrics_history.csv via append_metrics.py.

Idempotent: safe to run multiple times per day — append_metrics.py deduplicates
on (date, property, metric). Per-source failures are logged to stderr and do NOT
corrupt the store; whatever was successfully collected is still appended.
"""

import json
import os
import subprocess
import sys
import urllib.error
import urllib.request
from datetime import date

REPO_ROOT = os.path.join(os.path.dirname(os.path.abspath(__file__)), "..")
APPEND_SCRIPT = os.path.join(REPO_ROOT, "scripts", "append_metrics.py")
GSC_SCRIPT = "/home/ben/.openclaw/skills/gsc/scripts/gsc.py"
SA_KEY = os.path.expanduser("~/.config/gcloud/bennernet-analytics-reader.json")

GA4_PROPERTIES = {"glyc": "518966874", "ibd": "501432462"}
GSC_PROPERTIES = {"glyc": "sc-domain:getglyc.com", "ibd": "sc-domain:ibdmovement.com"}

# Sources we actively post to; LinkedIn excluded (not an active Bennernet channel).
_OUR_SOCIAL_KEYWORDS = frozenset(["mastodon", "bluesky", "bsky.app", "go.bsky.app",
                                   "t.co", "x.com", "twitter", "instagram",
                                   "threads", "facebook", "fb.com"])


def _get_ga4_token() -> str:
    env = os.environ.copy()
    env["GOOGLE_APPLICATION_CREDENTIALS"] = SA_KEY
    result = subprocess.run(
        ["python3", "-c",
         "import google.auth, google.auth.transport.requests; "
         "c, _ = google.auth.default(scopes=['https://www.googleapis.com/auth/analytics.readonly']); "
         "r = google.auth.transport.requests.Request(); c.refresh(r); print(c.token)"],
        capture_output=True, text=True, env=env,
    )
    token = result.stdout.strip()
    if not token:
        raise RuntimeError(f"GA4 token acquisition failed: {result.stderr.strip()}")
    return token


def _ga4(token: str, property_id: str, body: dict) -> dict:
    url = f"https://analyticsdata.googleapis.com/v1beta/properties/{property_id}:runReport"
    req = urllib.request.Request(
        url, data=json.dumps(body).encode(), method="POST",
        headers={"Authorization": f"Bearer {token}", "Content-Type": "application/json"},
    )
    with urllib.request.urlopen(req) as resp:
        return json.loads(resp.read())


def _row(today, prop, metric, value, window, source="ga4_api"):
    return {"date": today, "property": prop, "metric": metric,
            "value": str(value), "unit_window": window, "source": source}


def collect_ga4(prop: str, property_id: str, today: str) -> tuple[list, list]:
    rows, errors = [], []
    dr = [{"startDate": "28daysAgo", "endDate": "yesterday"}]

    # Glyc: scope all queries to production hostname — excludes CI/localhost hits.
    # IBD has no equivalent CI pollution so no filter needed there.
    _hostname_filter = None
    if prop == "glyc":
        _hostname_filter = {"filter": {"fieldName": "hostName",
                                       "stringFilter": {"matchType": "EXACT",
                                                        "value": "getglyc.com"}}}

    def _q(body: dict) -> dict:
        """Merge hostname filter (if any) with the query body."""
        if _hostname_filter is None:
            return body
        existing = body.get("dimensionFilter")
        if existing:
            body["dimensionFilter"] = {"andGroup": {"expressions": [existing, _hostname_filter]}}
        else:
            body["dimensionFilter"] = _hostname_filter
        return body

    try:
        token = _get_ga4_token()
    except Exception as e:
        return [], [f"token: {e}"]

    # --- summary: totalUsers, engagedSessions ---
    try:
        r = _ga4(token, property_id, _q(
                 {"metrics": [{"name": "totalUsers"}, {"name": "engagedSessions"}],
                  "dateRanges": dr}))
        vals = r.get("rows", [{}])[0].get("metricValues", [])
        if vals:
            rows.append(_row(today, prop, "ga4_total_users", vals[0]["value"], "28d"))
            rows.append(_row(today, prop, "ga4_engaged_sessions", vals[1]["value"], "28d"))
    except Exception as e:
        errors.append(f"summary: {e}")

    # --- channel breakdown: debotted sessions (non-Direct) ---
    try:
        r = _ga4(token, property_id, _q(
                 {"dimensions": [{"name": "sessionDefaultChannelGroup"}],
                  "metrics": [{"name": "sessions"}],
                  "dateRanges": dr}))
        debotted = sum(int(row["metricValues"][0]["value"])
                       for row in r.get("rows", [])
                       if row["dimensionValues"][0]["value"].lower() != "direct")
        rows.append(_row(today, prop, "ga4_debotted_sessions", debotted, "28d"))
    except Exception as e:
        errors.append(f"channel_breakdown: {e}")

    # --- sign_up events ---
    try:
        r = _ga4(token, property_id, _q(
                 {"dimensions": [{"name": "eventName"}],
                  "metrics": [{"name": "eventCount"}],
                  "dateRanges": dr,
                  "dimensionFilter": {"filter": {"fieldName": "eventName",
                                                  "stringFilter": {"value": "sign_up"}}}}))
        signups = sum(int(row["metricValues"][0]["value"]) for row in r.get("rows", []))
        rows.append(_row(today, prop, "ga4_sign_ups", signups, "28d"))
    except Exception as e:
        errors.append(f"sign_ups: {e}")

    # --- returning users (community depth, esp. IBD) ---
    try:
        r = _ga4(token, property_id, _q(
                 {"dimensions": [{"name": "newVsReturning"}],
                  "metrics": [{"name": "sessions"}],
                  "dateRanges": dr}))
        returning = 0
        for row in r.get("rows", []):
            if row["dimensionValues"][0]["value"].lower() == "returning":
                returning = int(row["metricValues"][0]["value"])
        rows.append(_row(today, prop, "ga4_returning_users", returning, "28d"))
    except Exception as e:
        errors.append(f"returning_users: {e}")

    # --- UTM social sessions (Bluesky / Mastodon / X only) ---
    try:
        r = _ga4(token, property_id, _q(
                 {"dimensions": [{"name": "sessionMedium"}, {"name": "sessionSource"}],
                  "metrics": [{"name": "sessions"}],
                  "dateRanges": dr}))
        utm_social = 0
        for row in r.get("rows", []):
            src = row["dimensionValues"][1]["value"].lower()
            sess = int(row["metricValues"][0]["value"])
            if any(kw in src for kw in _OUR_SOCIAL_KEYWORDS):
                utm_social += sess
        rows.append(_row(today, prop, "utm_social_sessions", utm_social, "28d"))
    except Exception as e:
        errors.append(f"utm_social: {e}")

    # --- device-category split (mobile vs desktop vs tablet) ---
    # Capture BOTH raw users and engaged sessions per device: raw users are
    # bot-inflated (esp. Glyc mobile), so engaged sessions are the real-user
    # signal for the mobile-vs-desktop audience question.
    try:
        r = _ga4(token, property_id, _q(
                 {"dimensions": [{"name": "deviceCategory"}],
                  "metrics": [{"name": "totalUsers"}, {"name": "engagedSessions"}],
                  "dateRanges": dr}))
        for row in r.get("rows", []):
            dev = row["dimensionValues"][0]["value"].lower()
            if dev not in ("mobile", "desktop", "tablet"):
                continue
            users = int(row["metricValues"][0]["value"])
            engaged = int(row["metricValues"][1]["value"])
            rows.append(_row(today, prop, f"ga4_device_{dev}_users", users, "28d"))
            rows.append(_row(today, prop, f"ga4_device_{dev}_engaged_sessions", engaged, "28d"))
    except Exception as e:
        errors.append(f"device_split: {e}")

    # --- sign-up funnel events (glyc only — per-surface CTR: impression→click→sign_up) ---
    # Events: sign_in_prompt_impression/click (save-prompt modal), guest_limit_impression/click
    # (cap modal), post_result_cta_impression/click (aha-moment inline strip), login_page_view.
    # Only collected for glyc; IBD has a different funnel shape.
    if prop == "glyc":
        _FUNNEL_EVENTS = [
            "sign_in_prompt_impression", "sign_in_prompt_click",
            "guest_limit_impression",    "guest_limit_click",
            "post_result_cta_impression","post_result_cta_click",
            "login_page_view",
        ]
        try:
            r = _ga4(token, property_id, _q(
                     {"dimensions": [{"name": "eventName"}],
                      "metrics": [{"name": "eventCount"}],
                      "dateRanges": dr,
                      "dimensionFilter": {"filter": {
                          "fieldName": "eventName",
                          "inListFilter": {"values": _FUNNEL_EVENTS}}}}))
            event_counts = {
                row["dimensionValues"][0]["value"]: int(row["metricValues"][0]["value"])
                for row in r.get("rows", [])
            }
            for event in _FUNNEL_EVENTS:
                rows.append(_row(today, prop, event, event_counts.get(event, 0), "28d"))
        except Exception as e:
            errors.append(f"signup_funnel_events: {e}")

    return rows, errors


def collect_gsc(prop: str, gsc_property: str, today: str) -> tuple[list, list]:
    rows, errors = [], []

    try:
        result = subprocess.run(
            ["python3", GSC_SCRIPT, "performance", gsc_property],
            capture_output=True, text=True,
        )
        if result.returncode != 0:
            raise RuntimeError(result.stderr.strip() or f"exit {result.returncode}")

        clicks, impressions, pos_weighted = 0, 0, 0.0
        for line in result.stdout.strip().splitlines():
            if line.startswith("#") or line.startswith("query") or not line.strip():
                continue
            parts = line.split("\t")
            if len(parts) < 5:
                continue
            clicks += int(parts[1])
            imp = int(parts[2])
            impressions += imp
            pos_weighted += imp * float(parts[4])

        avg_pos = round(pos_weighted / impressions, 1) if impressions else 0.0
        rows += [
            _row(today, prop, "gsc_clicks", clicks, "7d", "gsc_api"),
            _row(today, prop, "gsc_impressions", impressions, "7d", "gsc_api"),
            _row(today, prop, "gsc_avg_position", avg_pos, "7d", "gsc_api"),
        ]
    except Exception as e:
        errors.append(f"gsc {gsc_property}: {e}")

    return rows, errors


def run_collection(today: str | None = None) -> dict:
    """Collect all metrics and append to the history store.

    Returns {"appended": int, "skipped": int, "errors": list[str]}.
    """
    today = today or date.today().isoformat()
    all_rows, all_errors = [], []

    for prop, ga4_id in GA4_PROPERTIES.items():
        rows, errors = collect_ga4(prop, ga4_id, today)
        all_rows.extend(rows)
        all_errors.extend([f"[{prop}/ga4] {e}" for e in errors])

    for prop, gsc_prop in GSC_PROPERTIES.items():
        rows, errors = collect_gsc(prop, gsc_prop, today)
        all_rows.extend(rows)
        all_errors.extend([f"[{prop}/gsc] {e}" for e in errors])

    if not all_rows:
        return {"appended": 0, "skipped": 0, "errors": all_errors}

    batch_file = f"/tmp/metrics_batch_{today}.json"
    with open(batch_file, "w") as fh:
        json.dump(all_rows, fh)

    result = subprocess.run(
        [sys.executable, APPEND_SCRIPT, "--from-json", batch_file],
        capture_output=True, text=True,
    )
    if result.returncode != 0:
        all_errors.append(f"append_metrics: {result.stderr.strip()}")
        return {"appended": 0, "skipped": 0, "errors": all_errors}

    # Parse "appended=N skipped=M" from append_metrics stdout
    appended = skipped = 0
    for token in result.stdout.strip().split():
        if token.startswith("appended="):
            appended = int(token.split("=")[1])
        elif token.startswith("skipped="):
            skipped = int(token.split("=")[1])

    return {"appended": appended, "skipped": skipped, "errors": all_errors}


def main() -> None:
    result = run_collection()
    for err in result["errors"]:
        print(f"WARNING: {err}", file=sys.stderr)
    print(f"appended={result['appended']} skipped={result['skipped']} "
          f"warnings={len(result['errors'])}")
    if result["appended"] == 0 and result["skipped"] == 0 and result["errors"]:
        sys.exit(1)


if __name__ == "__main__":
    main()
