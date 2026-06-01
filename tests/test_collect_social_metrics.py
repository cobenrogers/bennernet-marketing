"""Tests for scripts/collect_social_metrics.py — row structure and error handling."""

import json
import os
import sys
import unittest
from unittest.mock import MagicMock, patch

sys.path.insert(0, os.path.join(os.path.dirname(__file__), "..", "scripts"))
from collect_social_metrics import collect_bluesky, collect_mastodon

TODAY = "2026-06-01"


def _bsky_profile_resp(followers: int) -> dict:
    return {"followersCount": followers, "handle": "test.bsky.social"}


def _bsky_feed_resp(posts: list) -> dict:
    return {"feed": [{"post": p} for p in posts]}


def _masto_acct_resp(acct_id: str, followers: int) -> dict:
    return {"id": acct_id, "followers_count": followers}


def _masto_statuses_resp(posts: list) -> list:
    return posts


class TestCollectBluesky(unittest.TestCase):
    def test_follower_count_row(self):
        profile = _bsky_profile_resp(47)
        feed = _bsky_feed_resp([
            {"likeCount": 5, "repostCount": 1},
            {"likeCount": 3, "repostCount": 0},
        ])
        with patch("collect_social_metrics._fetch", side_effect=[profile, feed]):
            rows, errors = collect_bluesky("glyc", "bennernet.bsky.social", TODAY)

        self.assertEqual(errors, [])
        by_metric = {r["metric"]: r for r in rows}
        self.assertIn("social_bsky_followers", by_metric)
        self.assertEqual(by_metric["social_bsky_followers"]["value"], "47")
        self.assertEqual(by_metric["social_bsky_followers"]["unit_window"], "snapshot")
        self.assertEqual(by_metric["social_bsky_followers"]["source"], "bluesky_api")
        self.assertEqual(by_metric["social_bsky_followers"]["property"], "glyc")
        self.assertEqual(by_metric["social_bsky_followers"]["date"], TODAY)

    def test_engagement_totals_across_last10(self):
        profile = _bsky_profile_resp(50)
        posts = [{"likeCount": 10, "repostCount": 2}, {"likeCount": 5, "repostCount": 0}]
        feed = _bsky_feed_resp(posts)
        with patch("collect_social_metrics._fetch", side_effect=[profile, feed]):
            rows, errors = collect_bluesky("ibd", "ibdmovement.bsky.social", TODAY)

        by_metric = {r["metric"]: r for r in rows}
        self.assertEqual(by_metric["social_bsky_likes_last10"]["value"], "15")
        self.assertEqual(by_metric["social_bsky_reposts_last10"]["value"], "2")
        self.assertEqual(by_metric["social_bsky_likes_last10"]["unit_window"], "last10")

    def test_empty_feed_yields_zero_engagement(self):
        profile = _bsky_profile_resp(10)
        feed = _bsky_feed_resp([])
        with patch("collect_social_metrics._fetch", side_effect=[profile, feed]):
            rows, errors = collect_bluesky("glyc", "bennernet.bsky.social", TODAY)

        by_metric = {r["metric"]: r for r in rows}
        self.assertEqual(by_metric["social_bsky_likes_last10"]["value"], "0")
        self.assertEqual(by_metric["social_bsky_reposts_last10"]["value"], "0")
        self.assertEqual(errors, [])

    def test_profile_api_failure_surfaces_error(self):
        with patch("collect_social_metrics._fetch", side_effect=Exception("timeout")):
            rows, errors = collect_bluesky("glyc", "bennernet.bsky.social", TODAY)

        self.assertGreater(len(errors), 0)
        self.assertIn("glyc bsky profile", errors[0])

    def test_feed_api_failure_still_returns_follower_row(self):
        profile = _bsky_profile_resp(30)
        with patch("collect_social_metrics._fetch", side_effect=[profile, Exception("500")]):
            rows, errors = collect_bluesky("glyc", "bennernet.bsky.social", TODAY)

        metrics = [r["metric"] for r in rows]
        self.assertIn("social_bsky_followers", metrics)
        self.assertEqual(len(errors), 1)

    def test_missing_likeCount_defaults_to_zero(self):
        profile = _bsky_profile_resp(5)
        feed = _bsky_feed_resp([{"repostCount": 1}])  # no likeCount key
        with patch("collect_social_metrics._fetch", side_effect=[profile, feed]):
            rows, errors = collect_bluesky("glyc", "bennernet.bsky.social", TODAY)

        by_metric = {r["metric"]: r for r in rows}
        self.assertEqual(by_metric["social_bsky_likes_last10"]["value"], "0")
        self.assertEqual(by_metric["social_bsky_reposts_last10"]["value"], "1")


class TestCollectMastodon(unittest.TestCase):
    def test_follower_and_engagement_rows(self):
        acct = _masto_acct_resp("12345", 3)
        statuses = [
            {"favourites_count": 4, "reblogs_count": 2},
            {"favourites_count": 1, "reblogs_count": 0},
        ]
        with patch("collect_social_metrics._fetch", side_effect=[acct, statuses]):
            rows, errors = collect_mastodon("ibd", "theibdmovement", "mastodon.social", TODAY)

        self.assertEqual(errors, [])
        by_metric = {r["metric"]: r for r in rows}
        self.assertEqual(by_metric["social_masto_followers"]["value"], "3")
        self.assertEqual(by_metric["social_masto_followers"]["unit_window"], "snapshot")
        self.assertEqual(by_metric["social_masto_favs_last10"]["value"], "5")
        self.assertEqual(by_metric["social_masto_boosts_last10"]["value"], "2")

    def test_api_failure_surfaces_error(self):
        with patch("collect_social_metrics._fetch", side_effect=Exception("connection refused")):
            rows, errors = collect_mastodon("glyc", "glyc", "mastodon.social", TODAY)

        self.assertEqual(rows, [])
        self.assertEqual(len(errors), 1)
        self.assertIn("glyc mastodon", errors[0])

    def test_row_source_field(self):
        acct = _masto_acct_resp("99", 1)
        statuses = [{"favourites_count": 0, "reblogs_count": 0}]
        with patch("collect_social_metrics._fetch", side_effect=[acct, statuses]):
            rows, _ = collect_mastodon("glyc", "glyc", "mastodon.social", TODAY)

        for row in rows:
            self.assertEqual(row["source"], "mastodon_api")
            self.assertEqual(row["property"], "glyc")
            self.assertEqual(row["date"], TODAY)

    def test_empty_statuses_yields_zero_engagement(self):
        acct = _masto_acct_resp("77", 10)
        with patch("collect_social_metrics._fetch", side_effect=[acct, []]):
            rows, errors = collect_mastodon("ibd", "theibdmovement", "mastodon.social", TODAY)

        by_metric = {r["metric"]: r for r in rows}
        self.assertEqual(by_metric["social_masto_favs_last10"]["value"], "0")
        self.assertEqual(by_metric["social_masto_boosts_last10"]["value"], "0")
        self.assertEqual(errors, [])


if __name__ == "__main__":
    unittest.main()
