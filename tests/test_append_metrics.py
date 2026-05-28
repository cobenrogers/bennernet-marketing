"""Tests for scripts/append_metrics.py — idempotency and non-destructive guarantees."""

import csv
import os
import sys
import tempfile
import unittest

sys.path.insert(0, os.path.join(os.path.dirname(__file__), "..", "scripts"))
from append_metrics import append_rows

SAMPLE_ROW = {
    "date": "2026-05-27",
    "property": "glyc",
    "metric": "ga4_debotted_sessions",
    "value": "75",
    "unit_window": "28d",
    "source": "ga4_api",
}

SECOND_ROW = {
    "date": "2026-05-27",
    "property": "glyc",
    "metric": "ga4_sign_ups",
    "value": "2",
    "unit_window": "28d",
    "source": "ga4_api",
}


def _read_csv(path):
    with open(path, newline="", encoding="utf-8") as fh:
        return list(csv.DictReader(fh))


class TestAppendIdempotency(unittest.TestCase):
    def _tmp_csv(self, seed_rows=None):
        fd, path = tempfile.mkstemp(suffix=".csv")
        os.close(fd)
        if seed_rows:
            append_rows(seed_rows, history_file=path)
        return path

    def test_first_append_writes_row(self):
        path = self._tmp_csv()
        result = append_rows([SAMPLE_ROW], history_file=path)
        self.assertEqual(result["appended"], 1)
        self.assertEqual(result["skipped"], 0)
        rows = _read_csv(path)
        self.assertEqual(len(rows), 1)
        self.assertEqual(rows[0]["metric"], "ga4_debotted_sessions")

    def test_duplicate_key_is_skipped(self):
        """Appending the same (date, property, metric) twice must not create a duplicate."""
        path = self._tmp_csv(seed_rows=[SAMPLE_ROW])
        before = _read_csv(path)
        result = append_rows([SAMPLE_ROW], history_file=path)
        after = _read_csv(path)
        self.assertEqual(result["appended"], 0)
        self.assertEqual(result["skipped"], 1)
        self.assertEqual(len(before), len(after), "row count must not change on duplicate append")

    def test_idempotent_across_three_runs(self):
        """Running the same append three times leaves the file unchanged after the first run."""
        path = self._tmp_csv()
        append_rows([SAMPLE_ROW], history_file=path)
        snapshot_after_first = _read_csv(path)
        append_rows([SAMPLE_ROW], history_file=path)
        append_rows([SAMPLE_ROW], history_file=path)
        self.assertEqual(snapshot_after_first, _read_csv(path))

    def test_new_metric_is_appended_to_existing_file(self):
        """A new (date, property, metric) key must be added without touching prior rows."""
        path = self._tmp_csv(seed_rows=[SAMPLE_ROW])
        original_rows = _read_csv(path)
        result = append_rows([SECOND_ROW], history_file=path)
        final_rows = _read_csv(path)
        self.assertEqual(result["appended"], 1)
        self.assertEqual(len(final_rows), len(original_rows) + 1)
        # First row must be byte-for-byte unchanged
        self.assertEqual(final_rows[0], original_rows[0])

    def test_existing_rows_never_modified(self):
        """Existing row values must survive any number of append operations."""
        path = self._tmp_csv(seed_rows=[SAMPLE_ROW])
        original = _read_csv(path)[0]
        for _ in range(5):
            append_rows([SAMPLE_ROW, SECOND_ROW], history_file=path)
        rows_by_key = {(r["date"], r["property"], r["metric"]): r for r in _read_csv(path)}
        preserved = rows_by_key[("2026-05-27", "glyc", "ga4_debotted_sessions")]
        self.assertEqual(preserved, original, "original row must be bit-identical after repeated appends")

    def test_batch_append_partial_duplicate(self):
        """In a mixed batch, duplicates are skipped and new rows are written."""
        path = self._tmp_csv(seed_rows=[SAMPLE_ROW])
        result = append_rows([SAMPLE_ROW, SECOND_ROW], history_file=path)
        self.assertEqual(result["appended"], 1)
        self.assertEqual(result["skipped"], 1)
        self.assertEqual(len(_read_csv(path)), 2)

    def test_invalid_property_raises(self):
        path = self._tmp_csv()
        bad_row = {**SAMPLE_ROW, "property": "unknown_site"}
        with self.assertRaises(ValueError):
            append_rows([bad_row], history_file=path)

    def test_invalid_value_raises(self):
        path = self._tmp_csv()
        bad_row = {**SAMPLE_ROW, "value": "not_a_number"}
        with self.assertRaises(ValueError):
            append_rows([bad_row], history_file=path)


if __name__ == "__main__":
    unittest.main()
