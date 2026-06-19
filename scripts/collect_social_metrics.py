#!/usr/bin/env python3
"""Collect social engagement metrics for Glyc and IBD Movement.

Fetches from Bluesky (AT Protocol public API), Mastodon (public API),
and Instagram (Graph API via Postiz DB token).
X/Twitter engagement is paywalled — captures follower count only via manual entry.

Run daily; idempotent via append_metrics.py deduplication on (date, property, metric).

Metrics written:
  social_bsky_followers         snapshot  (Bluesky follower count)
  social_bsky_likes_last10      last10    (sum of likeCount on last 10 posts)
  social_bsky_reposts_last10    last10    (sum of repostCount on last 10 posts)
  social_masto_followers        snapshot  (Mastodon follower count)
  social_masto_favs_last10      last10    (sum of favourites_count on last 10 posts)
  social_masto_boosts_last10    last10    (sum of reblogs_count on last 10 posts)
  social_ig_followers           snapshot  (Instagram follower count)
  social_ig_likes_last10        last10    (sum of like_count on last 10 IG posts)
  social_ig_comments_last10     last10    (sum of comments_count on last 10 IG posts)
  social_ig_reach_last10        last10    (sum of reach on last 10 IG posts; requires instagram_manage_insights)
"""

import json
import os
import shlex
import subprocess
import sys
import urllib.error
import urllib.request
from datetime import date, datetime, timedelta

REPO_ROOT = os.path.join(os.path.dirname(os.path.abspath(__file__)), "..")
APPEND_SCRIPT = os.path.join(REPO_ROOT, "scripts", "append_metrics.py")

ACCOUNTS = {
    "glyc": {
        "bluesky": "did:plc:nhydio4s5mvrgtukzop2nugw",  # handle.invalid — custom domain broke; DID is stable
        "mastodon_user": "glyc",
        "mastodon_server": "mastodon.social",
    },
    "ibd": {
        "bluesky": "ibdmovement.bsky.social",
        "mastodon_user": "theibdmovement",
        "mastodon_server": "mastodon.social",
    },
}

INSTAGRAM_INTEGRATIONS = {
    "glyc": "cmq2rp6l1001ol98ugo3dz6oh",
    "ibd": "cmq142urk0017l98u8phwixop",
}

INSTAGRAM_GRAPH_BASE = "https://graph.facebook.com/v21.0"
INSTAGRAM_REFRESH_URL = "https://graph.instagram.com/refresh_access_token"
POSTIZ_COMPOSE = "~/postiz/docker-compose.yaml"


def _fetch(url: str, timeout: int = 15) -> dict:
    with urllib.request.urlopen(url, timeout=timeout) as resp:
        return json.loads(resp.read())


def _query_postiz_db_row(integration_id: str) -> dict | None:
    """Get token, internalId, and tokenExpiration from Postiz DB via docker exec."""
    sql = (
        'SELECT token, "internalId", "tokenExpiration" '
        f'FROM "Integration" WHERE id = \'{integration_id}\''
    )
    inner = (
        f"docker compose -f {POSTIZ_COMPOSE} exec -T "
        f"postiz-postgres psql -U postiz-user -d postiz-db-local -t -A -F'|' "
        f"-c {shlex.quote(sql)}"
    )
    cmd = f"sg docker -c {shlex.quote(inner)}"
    try:
        res = subprocess.run(cmd, shell=True, capture_output=True, text=True, timeout=20)
        if res.returncode != 0 or not res.stdout.strip():
            return None
        parts = res.stdout.strip().split("|")
        if len(parts) < 3:
            return None
        return {"token": parts[0], "internalId": parts[1], "tokenExpiration": parts[2]}
    except Exception:
        return None


def _update_postiz_token(integration_id: str, new_token: str, new_expiry: str) -> bool:
    """Write refreshed token and expiry back to Postiz DB."""
    safe_token = new_token.replace("'", "''")
    sql = (
        f'UPDATE "Integration" SET token = \'{safe_token}\', '
        f'"tokenExpiration" = \'{new_expiry}\' '
        f"WHERE id = '{integration_id}'"
    )
    inner = (
        f"docker compose -f {POSTIZ_COMPOSE} exec -T "
        f"postiz-postgres psql -U postiz-user -d postiz-db-local -c "
        f"{shlex.quote(sql)}"
    )
    cmd = f"sg docker -c {shlex.quote(inner)}"
    try:
        res = subprocess.run(cmd, shell=True, capture_output=True, text=True, timeout=20)
        return res.returncode == 0
    except Exception:
        return False


def _maybe_refresh_instagram_token(integration_id: str, token: str, expiry_str: str) -> str:
    """Refresh Instagram long-lived token if expiring within 10 days. Returns active token."""
    try:
        expiry = datetime.fromisoformat(expiry_str.replace(" ", "T"))
        if (expiry - datetime.now()).days > 10:
            return token
        data = _fetch(
            f"{INSTAGRAM_REFRESH_URL}?grant_type=ig_refresh_token&access_token={token}"
        )
        new_token = data.get("access_token")
        expires_in = data.get("expires_in", 5184000)
        if new_token:
            new_expiry = (datetime.now() + timedelta(seconds=expires_in)).strftime(
                "%Y-%m-%d %H:%M:%S"
            )
            _update_postiz_token(integration_id, new_token, new_expiry)
            return new_token
    except Exception:
        pass
    return token


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


def collect_instagram(prop: str, integration_id: str, today: str) -> tuple[list, list]:
    rows, errors = [], []

    def row(metric, value, window):
        return {"date": today, "property": prop, "metric": metric,
                "value": str(value), "unit_window": window, "source": "instagram_api"}

    db_row = _query_postiz_db_row(integration_id)
    if not db_row:
        errors.append(f"{prop} instagram: failed to get token from Postiz DB")
        return rows, errors

    token = _maybe_refresh_instagram_token(
        integration_id, db_row["token"], db_row["tokenExpiration"]
    )
    ig_user_id = db_row["internalId"]

    try:
        data = _fetch(
            f"{INSTAGRAM_GRAPH_BASE}/{ig_user_id}?fields=followers_count&access_token={token}"
        )
        rows.append(row("social_ig_followers", data.get("followers_count", 0), "snapshot"))
    except Exception as e:
        errors.append(f"{prop} instagram followers: {e}")

    try:
        media_resp = _fetch(
            f"{INSTAGRAM_GRAPH_BASE}/{ig_user_id}/media"
            f"?fields=like_count,comments_count,id&limit=10&access_token={token}"
        )
        media = media_resp.get("data", [])
        likes = sum(p.get("like_count", 0) for p in media)
        comments = sum(p.get("comments_count", 0) for p in media)
        rows.append(row("social_ig_likes_last10", likes, "last10"))
        rows.append(row("social_ig_comments_last10", comments, "last10"))

        total_reach = 0
        reach_ok = 0
        for post in media:
            try:
                ins = _fetch(
                    f"{INSTAGRAM_GRAPH_BASE}/{post['id']}/insights"
                    f"?metric=reach&access_token={token}"
                )
                for item in ins.get("data", []):
                    if item.get("name") == "reach":
                        if item.get("values"):
                            total_reach += item["values"][0].get("value", 0)
                        elif item.get("total_value"):
                            total_reach += item["total_value"].get("value", 0)
                        reach_ok += 1
            except Exception:
                pass
        if reach_ok > 0:
            rows.append(row("social_ig_reach_last10", total_reach, "last10"))
        else:
            errors.append(
                f"{prop} instagram reach: insights unavailable (permissions or dev mode)"
            )
    except Exception as e:
        errors.append(f"{prop} instagram media: {e}")

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

    for prop, integration_id in INSTAGRAM_INTEGRATIONS.items():
        ig_rows, ig_errors = collect_instagram(prop, integration_id, today)
        all_rows.extend(ig_rows)
        all_errors.extend(ig_errors)

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
