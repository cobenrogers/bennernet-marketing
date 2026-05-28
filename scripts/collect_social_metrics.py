#!/usr/bin/env python3
"""Collect social engagement metrics for Glyc and IBD Movement.

Fetches from Bluesky (AT Protocol public API) and Mastodon (public API).
X/Twitter engagement is paywalled — captures follower count only via manual entry.

Run daily; idempotent via append_metrics.py deduplication on (date, property, metric).

Metrics written:
  social_bsky_followers         snapshot  (Bluesky follower count)
  social_bsky_likes_last10      last10    (sum of likeCount on last 10 posts)
  social_bsky_reposts_last10    last10    (sum of repostCount on last 10 posts)
  social_masto_followers        snapshot  (Mastodon follower count)
  social_masto_favs_last10      last10    (sum of favourites_count on last 10 posts)
  social_masto_boosts_last10    last10    (sum of reblogs_count on last 10 posts)
"""

import json
import os
import sys
import urllib.error
import urllib.request
from datetime import date

REPO_ROOT = os.path.join(os.path.dirname(os.path.abspath(__file__)), "..")
APPEND_SCRIPT = os.path.join(REPO_ROOT, "scripts", "append_metrics.py")

ACCOUNTS = {
    "glyc": {
        "bluesky": "bennernet.bsky.social",
        "mastodon_user": "glyc",
        "mastodon_server": "mastodon.social",
    },
    "ibd": {
        "bluesky": "ibdmovement.bsky.social",
        "mastodon_user": "theibdmovement",
        "mastodon_server": "mastodon.social",
    },
}


def _fetch(url: str, timeout: int = 15) -> dict:
    with urllib.request.urlopen(url, timeout=timeout) as resp:
        return json.loads(resp.read())


def collect_bluesky(prop: str, handle: str, today: str) -> tuple[list, list]:
    rows, errors = [], []

    def row(metric, value, window):
        return {"date": today, "property": prop, "metric": metric,
                "value": str(value), "unit_window": window, "source": "bluesky_api"}

    try:
        profile = _fetch(f"https://public.api.bsky.app/xrpc/app.bsky.actor.getProfile?actor={handle}")
        rows.append(row("social_bsky_followers", profile.get("followersCount", 0), "snapshot"))
    except Exception as e:
        errors.append(f"{prop} bsky profile: {e}")

    try:
        feed_resp = _fetch(f"https://public.api.bsky.app/xrpc/app.bsky.feed.getAuthorFeed?actor={handle}&limit=10")
        feed = feed_resp.get("feed", [])
        likes = sum(p["post"].get("likeCount", 0) for p in feed)
        reposts = sum(p["post"].get("repostCount", 0) for p in feed)
        rows.append(row("social_bsky_likes_last10", likes, "last10"))
        rows.append(row("social_bsky_reposts_last10", reposts, "last10"))
    except Exception as e:
        errors.append(f"{prop} bsky feed: {e}")

    return rows, errors


def collect_mastodon(prop: str, user: str, server: str, today: str) -> tuple[list, list]:
    rows, errors = [], []

    def row(metric, value, window):
        return {"date": today, "property": prop, "metric": metric,
                "value": str(value), "unit_window": window, "source": "mastodon_api"}

    try:
        acct = _fetch(f"https://{server}/api/v1/accounts/lookup?acct={user}")
        acct_id = acct["id"]
        rows.append(row("social_masto_followers", acct.get("followers_count", 0), "snapshot"))

        statuses = _fetch(f"https://{server}/api/v1/accounts/{acct_id}/statuses?limit=10")
        favs = sum(p.get("favourites_count", 0) for p in statuses)
        boosts = sum(p.get("reblogs_count", 0) for p in statuses)
        rows.append(row("social_masto_favs_last10", favs, "last10"))
        rows.append(row("social_masto_boosts_last10", boosts, "last10"))
    except Exception as e:
        errors.append(f"{prop} mastodon: {e}")

    return rows, errors


def main():
    today = date.today().isoformat()
    all_rows, all_errors = [], []

    for prop, cfg in ACCOUNTS.items():
        bsky_rows, bsky_errors = collect_bluesky(prop, cfg["bluesky"], today)
        all_rows.extend(bsky_rows)
        all_errors.extend(bsky_errors)

        masto_rows, masto_errors = collect_mastodon(
            prop, cfg["mastodon_user"], cfg["mastodon_server"], today)
        all_rows.extend(masto_rows)
        all_errors.extend(masto_errors)

    if all_errors:
        for e in all_errors:
            print(f"WARNING: {e}", file=sys.stderr)

    if not all_rows:
        print("No rows collected.", file=sys.stderr)
        sys.exit(1)

    # Write via append_metrics
    import importlib.util
    spec = importlib.util.spec_from_file_location("append_metrics", APPEND_SCRIPT)
    mod = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(mod)
    result = mod.append_rows(all_rows)
    print(f"Social metrics: appended={result['appended']} skipped={result['skipped']}")
    if all_errors:
        sys.exit(1)


if __name__ == "__main__":
    main()
