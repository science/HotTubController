#!/usr/bin/env python3
"""
Fetch production logs and state files for debugging (READ-ONLY).

There is no HTTP endpoint for logs. This script downloads them from the
production cPanel host over FTP+TLS and never writes to the server. Full
documentation, including what every log contains: docs/production-logs.md.

Credentials come from backend/config/env.network.conveniences
(FTP_HOST, FTP_USERNAME, FTP_PASSWORD). That file is gitignored and local-only.

Usage (from the repo root; plain python3, no packages required):
    python3 backend/scripts/fetch-prod-logs.py --since "2 days ago"
    python3 backend/scripts/fetch-prod-logs.py --since 2026-11-01 -o ~/dev/tmp/prod
    python3 backend/scripts/fetch-prod-logs.py --list --since "6 hours ago"
    python3 backend/scripts/fetch-prod-logs.py            # everything (~5,700 files)
"""

import argparse
import ftplib
import gzip
import re
import ssl
import sys
from dataclasses import dataclass
from datetime import datetime, timedelta, timezone
from pathlib import Path

UTC = timezone.utc

# Remote paths to download, relative to public_html/tub/backend/.
# Key = local subdirectory name under the output directory.
REMOTE_PATHS = {
    'logs': 'storage/logs',               # api, cron, equipment-events, esp32, temperature-*
    'device-calls': 'logs',               # events.log: every iot-api call and its HTTP result
    'state': 'storage/state',
    'crontab-backups': 'storage/crontab-backups',
    'scheduled-jobs': 'storage/scheduled-jobs',
}

BASE_PATH = '/public_html/tub/backend'


@dataclass(frozen=True)
class RemoteFile:
    name: str
    modified: datetime  # timezone-aware, UTC
    size: int | None


def parse_env_file(env_path: str) -> dict:
    """Parse a KEY=VALUE env file. Missing files yield an empty dict."""
    creds = {}
    path = Path(env_path)
    if not path.exists():
        return creds
    with path.open('r') as f:
        for line in f:
            line = line.strip().replace('\r', '')
            if '=' in line and not line.startswith('#'):
                key, val = line.split('=', 1)
                creds[key] = val
    return creds


def connect_ftp(host: str, user: str, password: str) -> ftplib.FTP_TLS:
    """Establish FTP+SSL connection."""
    context = ssl.create_default_context()
    context.check_hostname = False
    context.verify_mode = ssl.CERT_NONE

    ftp = ftplib.FTP_TLS(host, context=context)
    ftp.login(user, password)
    ftp.prot_p()

    return ftp


def parse_mlsd_modify(value: str) -> datetime:
    """
    Parse an MLSD/MDTM 'modify' fact (YYYYMMDDHHMMSS[.fff]). RFC 3659 defines it
    as UTC, unlike plain LIST output, which is in the server's local time zone.
    """
    return datetime.strptime(value.split('.', 1)[0], '%Y%m%d%H%M%S').replace(tzinfo=UTC)


_RELATIVE = re.compile(r'^\s*(\d+)\s*(second|minute|hour|day|week)s?\s+ago\s*$', re.IGNORECASE)


def parse_since(text: str, now: datetime | None = None, allow_dateparser: bool = True) -> datetime | None:
    """
    Turn a --since expression into a timezone-aware UTC datetime.

    Built in (no packages needed):
      "N seconds|minutes|hours|days|weeks ago", "yesterday" (24 h back),
      ISO 8601 such as "2026-11-01", "2026-11-01 06:30", "2026-11-07T10:00-05:00".
      An ISO value without a zone is read as this machine's local time.
    Anything else ("Jan 25 2pm") is handed to the optional `dateparser` package
    if it is installed. Returns None if nothing understood it.
    """
    now = now or datetime.now(UTC)
    stripped = text.strip()

    match = _RELATIVE.match(stripped)
    if match:
        amount, unit = int(match.group(1)), match.group(2).lower()
        return now - timedelta(**{unit + 's': amount})

    if stripped.lower() == 'yesterday':
        return now - timedelta(days=1)

    try:
        parsed = datetime.fromisoformat(stripped)
    except ValueError:
        parsed = None
    if parsed is not None:
        if parsed.tzinfo is None:
            parsed = parsed.astimezone()  # naive -> local time
        return parsed.astimezone(UTC)

    if allow_dateparser:
        try:
            import dateparser  # optional
        except ImportError:
            return None
        parsed = dateparser.parse(stripped, settings={
            'PREFER_DATES_FROM': 'past',
            'RETURN_AS_TIMEZONE_AWARE': True,
        })
        if parsed is not None:
            return parsed.astimezone(UTC)

    return None


def list_remote_files(ftp, remote_dir: str) -> list[RemoteFile]:
    """
    List regular files in a remote directory with UTC modification times.

    Uses MLSD rather than LIST: LIST prints times in the server's local zone with
    no zone marker and no year for recent files, which is what made --since skip
    the live logs (see the regression test in test_fetch_prod_logs.py).
    """
    files = []
    for name, facts in ftp.mlsd(remote_dir, facts=['type', 'size', 'modify']):
        if facts.get('type') != 'file' or name.startswith('.'):
            continue
        size = int(facts['size']) if 'size' in facts else None
        files.append(RemoteFile(name, parse_mlsd_modify(facts['modify']), size))
    return sorted(files, key=lambda f: f.name)


def download_file(ftp: ftplib.FTP_TLS, remote_path: str, local_path: Path) -> bool:
    """Download a single file."""
    try:
        local_path.parent.mkdir(parents=True, exist_ok=True)
        with open(local_path, 'wb') as f:
            ftp.retrbinary(f'RETR {remote_path}', f.write)
        return True
    except ftplib.error_perm as e:
        print(f"  Warning: Cannot download {remote_path}: {e}")
        return False


def decompress_gzip(gz_path: Path) -> Path:
    """Decompress a .gz file and return the decompressed path."""
    if not gz_path.suffix == '.gz':
        return gz_path

    decompressed_path = gz_path.with_suffix('')
    try:
        with gzip.open(gz_path, 'rb') as f_in:
            with open(decompressed_path, 'wb') as f_out:
                f_out.write(f_in.read())
        return decompressed_path
    except Exception as e:
        print(f"  Warning: Cannot decompress {gz_path}: {e}")
        return gz_path


def format_time_ago(dt: datetime, now: datetime | None = None) -> str:
    """Format an aware datetime as a human-readable 'time ago' string."""
    diff = (now or datetime.now(UTC)) - dt

    if diff.days > 0:
        return f"{diff.days}d ago"
    elif diff.seconds >= 3600:
        return f"{diff.seconds // 3600}h ago"
    elif diff.seconds >= 60:
        return f"{diff.seconds // 60}m ago"
    else:
        return "just now"


def fetch_logs(env_path: str, output_dir: Path, since: datetime | None = None,
               list_only: bool = False, decompress: bool = True) -> dict:
    """
    Fetch all production logs and state files.

    Args:
        env_path: Path to the local credential file holding FTP_*
        output_dir: Local directory to save files
        since: Only download files modified after this aware UTC time (None = all)
        list_only: If True, just list files without downloading
        decompress: If True, decompress .gz files after download

    Returns dict with download statistics.
    """
    creds = parse_env_file(env_path)
    host = creds.get('FTP_HOST', '')
    user = creds.get('FTP_USERNAME', '')
    password = creds.get('FTP_PASSWORD', '')

    if not all([host, user, password]):
        print(f"Error: Missing FTP credentials in {env_path}")
        print(f"  FTP_HOST: {'set' if host else 'MISSING'}")
        print(f"  FTP_USERNAME: {'set' if user else 'MISSING'}")
        print(f"  FTP_PASSWORD: {'set' if password else 'MISSING'}")
        sys.exit(1)

    print(f"Connecting to {host} as {user}...")
    if since:
        print(f"Filtering: files modified since {since.astimezone():%Y-%m-%d %H:%M:%S %Z} "
              f"({since:%Y-%m-%d %H:%M:%S} UTC)")
    ftp = connect_ftp(host, user, password)

    stats = {'downloaded': 0, 'skipped': 0, 'failed': 0, 'bytes': 0}

    for category, remote_subdir in REMOTE_PATHS.items():
        print(f"\n{'Listing' if list_only else 'Downloading'} {category}/  ({remote_subdir}/)")

        remote_dir = f'{BASE_PATH}/{remote_subdir}'
        try:
            files = list_remote_files(ftp, remote_dir)
        except ftplib.error_perm as e:
            print(f"  Warning: Cannot list {remote_dir}: {e}")
            continue

        if not files:
            print("  (no files)")
            continue

        for remote in files:
            if since and remote.modified < since:
                stats['skipped'] += 1
                continue

            time_str = format_time_ago(remote.modified)

            if list_only:
                size_str = f"{remote.size:,} bytes, " if remote.size is not None else ""
                print(f"  {remote.name} ({size_str}{time_str})")
                continue

            local_file = output_dir / category / remote.name
            if download_file(ftp, f'{remote_dir}/{remote.name}', local_file):
                size = local_file.stat().st_size
                stats['downloaded'] += 1
                stats['bytes'] += size
                print(f"  {remote.name} ({size:,} bytes, {time_str})")

                if decompress and remote.name.endswith('.gz'):
                    decompressed = decompress_gzip(local_file)
                    if decompressed != local_file:
                        print(f"    -> decompressed to {decompressed.name}")
            else:
                stats['failed'] += 1

    ftp.quit()
    return stats


def main():
    parser = argparse.ArgumentParser(
        description='Fetch production logs and state for debugging (read-only FTP). '
                    'See docs/production-logs.md.',
        formatter_class=argparse.RawDescriptionHelpFormatter,
        epilog="""
Examples:
  python3 backend/scripts/fetch-prod-logs.py --since "2 hours ago"
  python3 backend/scripts/fetch-prod-logs.py --since "21 days ago" -o ~/dev/tmp/prod
  python3 backend/scripts/fetch-prod-logs.py --since 2026-11-01        # local midnight
  python3 backend/scripts/fetch-prod-logs.py --list --since yesterday
  python3 backend/scripts/fetch-prod-logs.py                           # everything

--since filters on each file's modification time, so a daily log that was
appended to recently is fetched whole. Free-form dates ("Jan 25 2pm") need
the optional `dateparser` package; the forms above do not.
        """
    )
    parser.add_argument('--since', type=str,
                        help='Only files modified after this time ("2 hours ago", "yesterday", "2026-11-01 06:30")')
    parser.add_argument('--list', action='store_true',
                        help='List remote files without downloading')
    parser.add_argument('--no-decompress', action='store_true',
                        help='Keep .gz files compressed')
    parser.add_argument('-o', '--output', type=str,
                        help='Output directory (default: /tmp/prod-debug-TIMESTAMP)')

    args = parser.parse_args()

    since = None
    if args.since:
        since = parse_since(args.since)
        if since is None:
            print(f"Error: Could not parse time expression: '{args.since}'")
            print("Examples: '2 hours ago', '3 days ago', 'yesterday', '2026-11-01', '2026-11-01 06:30'")
            print("(Free-form dates like 'Jan 25 2pm' need: pip install dateparser)")
            sys.exit(1)

    # FTP credentials moved out of env.production on 2026-08-11
    # (config/env.network.conveniences) so that env.production holds only keys the
    # deployed backend actually reads. Fall back to env.production for checkouts
    # that predate the split.
    script_dir = Path(__file__).parent
    backend_dir = script_dir.parent
    config_dir = backend_dir / 'config'

    env_path = config_dir / 'env.network.conveniences'
    if not env_path.exists():
        env_path = config_dir / 'env.production'

    if not env_path.exists():
        print(f"Error: no credential file found in {config_dir}")
        print("  Expected env.network.conveniences (holds FTP_HOST/USERNAME/PASSWORD)")
        sys.exit(1)

    if args.output:
        output_dir = Path(args.output).expanduser()
    else:
        timestamp = datetime.now().strftime('%Y%m%d-%H%M%S')
        output_dir = Path(f'/tmp/prod-debug-{timestamp}')

    if not args.list:
        output_dir.mkdir(parents=True, exist_ok=True)
        print(f"Output directory: {output_dir}")

    stats = fetch_logs(
        env_path=str(env_path),
        output_dir=output_dir,
        since=since,
        list_only=args.list,
        decompress=not args.no_decompress
    )

    if not args.list:
        print(f"\n{'='*50}")
        print("Download complete!")
        print(f"  Files downloaded: {stats['downloaded']}")
        if stats['skipped']:
            print(f"  Files skipped (older than --since): {stats['skipped']}")
        print(f"  Total size: {stats['bytes']:,} bytes ({stats['bytes']/1024/1024:.1f} MB)")
        if stats['failed']:
            print(f"  Failed: {stats['failed']}")
        print(f"\nFiles saved to: {output_dir}")
        print("\nWhat each file means: docs/production-logs.md")
        print("Quick looks:")
        print(f"  tail {output_dir}/logs/equipment-events.log        # heater on/off, stalls, dynamic targets")
        print(f"  tail {output_dir}/device-calls/events.log          # each iot-api call + HTTP result")
        print(f"  grep heat-target {output_dir}/logs/cron.log | tail  # the check chain")


if __name__ == '__main__':
    main()
