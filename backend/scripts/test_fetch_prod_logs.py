"""
Unit tests for fetch-prod-logs.py (stdlib unittest only, no network).

Run: python3 -I -m unittest discover -s backend/scripts -p 'test_*.py'
(./scripts/test.sh backend runs this.)
"""

import importlib.util
import unittest
from datetime import datetime, timedelta, timezone
from pathlib import Path

_SCRIPT = Path(__file__).with_name('fetch-prod-logs.py')
_spec = importlib.util.spec_from_file_location('fetch_prod_logs', _SCRIPT)
fpl = importlib.util.module_from_spec(_spec)
_spec.loader.exec_module(fpl)

UTC = timezone.utc
NOW = datetime(2026, 11, 7, 18, 0, 0, tzinfo=UTC)


class ParseMlsdModifyTest(unittest.TestCase):
    """MLSD 'modify' facts are UTC by definition (RFC 3659)."""

    def test_parses_as_utc(self):
        self.assertEqual(fpl.parse_mlsd_modify('20261107154924'),
                         datetime(2026, 11, 7, 15, 49, 24, tzinfo=UTC))

    def test_ignores_fractional_seconds(self):
        self.assertEqual(fpl.parse_mlsd_modify('20261107154924.517'),
                         datetime(2026, 11, 7, 15, 49, 24, tzinfo=UTC))


class ParseSinceTest(unittest.TestCase):
    def test_relative_hours(self):
        self.assertEqual(fpl.parse_since('2 hours ago', now=NOW), NOW - timedelta(hours=2))

    def test_relative_days_singular_and_plural(self):
        self.assertEqual(fpl.parse_since('1 day ago', now=NOW), NOW - timedelta(days=1))
        self.assertEqual(fpl.parse_since('21 days ago', now=NOW), NOW - timedelta(days=21))

    def test_relative_minutes_and_weeks(self):
        self.assertEqual(fpl.parse_since('90 minutes ago', now=NOW), NOW - timedelta(minutes=90))
        self.assertEqual(fpl.parse_since('2 weeks ago', now=NOW), NOW - timedelta(weeks=2))

    def test_yesterday_is_24_hours_back(self):
        self.assertEqual(fpl.parse_since('yesterday', now=NOW), NOW - timedelta(days=1))

    def test_iso_with_zone_is_respected(self):
        self.assertEqual(fpl.parse_since('2026-11-07T10:00:00-05:00', now=NOW),
                         datetime(2026, 11, 7, 15, 0, 0, tzinfo=UTC))

    def test_iso_without_zone_is_local_time(self):
        expected = datetime(2026, 11, 1, 6, 30).astimezone().astimezone(UTC)
        self.assertEqual(fpl.parse_since('2026-11-01 06:30', now=NOW), expected)

    def test_result_is_always_utc_aware(self):
        for text in ('3 hours ago', 'yesterday', '2026-11-01', '2026-11-01T00:00:00Z'):
            with self.subTest(text=text):
                self.assertEqual(fpl.parse_since(text, now=NOW).tzinfo, UTC)

    def test_garbage_returns_none(self):
        self.assertIsNone(fpl.parse_since('the other day-ish', now=NOW, allow_dateparser=False))


class FakeFtp:
    """Just enough of ftplib.FTP_TLS for listing."""

    def __init__(self, entries):
        self._entries = entries

    def mlsd(self, path='', facts=()):
        return iter(self._entries.get(path, []))


class ListRemoteFilesTest(unittest.TestCase):
    def test_returns_files_with_utc_times_and_sizes_skipping_dirs_and_dotfiles(self):
        ftp = FakeFtp({'/x/logs': [
            ('.', {'type': 'cdir', 'modify': '20251210063437'}),
            ('..', {'type': 'pdir', 'modify': '20251217232011'}),
            ('.htaccess', {'type': 'file', 'size': '10', 'modify': '20260101000000'}),
            ('old', {'type': 'dir', 'modify': '20260101000000'}),
            ('events.log', {'type': 'file', 'size': '308449', 'modify': '20261107154924'}),
        ]})

        files = fpl.list_remote_files(ftp, '/x/logs')

        self.assertEqual(files, [
            fpl.RemoteFile('events.log', datetime(2026, 11, 7, 15, 49, 24, tzinfo=UTC), 308449),
        ])

    def test_a_file_written_minutes_ago_on_an_eastern_server_counts_as_recent(self):
        # The 2026-11-07 regression: the server's LIST output is in its local zone
        # (US Eastern), which the old parser compared with the client's local
        # clock (US Pacific). Files touched "13:51" Eastern looked like they were
        # from the future and were pushed back a year, so --since skipped them.
        ftp = FakeFtp({'/x/logs': [
            ('api.log', {'type': 'file', 'size': '1', 'modify': '20261107175100'}),
        ]})

        [f] = fpl.list_remote_files(ftp, '/x/logs')

        self.assertGreater(f.modified, fpl.parse_since('1 hour ago', now=NOW))


class RemotePathsTest(unittest.TestCase):
    def test_device_call_log_is_fetched(self):
        # backend/logs/events.log (iot_api_live outcomes) lives outside storage/.
        self.assertIn('logs', fpl.REMOTE_PATHS.values())

    def test_storage_logs_still_fetched(self):
        self.assertIn('storage/logs', fpl.REMOTE_PATHS.values())


if __name__ == '__main__':
    unittest.main()
