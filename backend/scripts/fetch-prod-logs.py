#!/usr/bin/env python3
"""
Fetch production logs and state files for debugging.

Downloads all relevant files from the production server via FTP+SSL
and stores them locally for analysis.

Usage:
    python scripts/fetch-prod-logs.py                        # Full download
    python scripts/fetch-prod-logs.py --since "2 hours ago"  # Recent files only
    python scripts/fetch-prod-logs.py --since yesterday      # Since yesterday
    python scripts/fetch-prod-logs.py --since "Jan 25 2pm"   # Since specific time
    python scripts/fetch-prod-logs.py --list                 # List without downloading
"""

import argparse
import ftplib
import gzip
import ssl
import sys
from datetime import datetime, timezone
from pathlib import Path

import dateparser


# Remote paths to download (relative to public_html/tub/backend/)
REMOTE_PATHS = {
    'logs': 'storage/logs',
    'state': 'storage/state',
    'crontab-backups': 'storage/crontab-backups',
    'scheduled-jobs': 'storage/scheduled-jobs',
}


def parse_env_file(env_path: str) -> dict:
    """Parse the env.production file to extract FTP credentials."""
    creds = {}
    with open(env_path, 'r') as f:
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


def parse_ftp_list(line: str) -> tuple[str, datetime | None]:
    """
    Parse a line from FTP LIST command to extract filename and modification time.

    Example line: "-rw-r--r--    1 misuse     misuse         327697 Jan 25 11:42 api.log"
    """
    parts = line.split()
    if len(parts) < 9:
        return None, None

    filename = ' '.join(parts[8:])  # Handle filenames with spaces

    # Parse date - format is "Mon DD HH:MM" or "Mon DD  YYYY"
    month_str = parts[5]
    day = int(parts[6])
    time_or_year = parts[7]

    months = {'Jan': 1, 'Feb': 2, 'Mar': 3, 'Apr': 4, 'May': 5, 'Jun': 6,
              'Jul': 7, 'Aug': 8, 'Sep': 9, 'Oct': 10, 'Nov': 11, 'Dec': 12}
    month = months.get(month_str, 1)

    now = datetime.now()

    if ':' in time_or_year:
        # Recent file: "HH:MM" format, assume current year
        hour, minute = map(int, time_or_year.split(':'))
        year = now.year
        # If the date is in the future, it's probably last year
        mod_time = datetime(year, month, day, hour, minute)
        if mod_time > now:
            mod_time = datetime(year - 1, month, day, hour, minute)
    else:
        # Older file: year format
        year = int(time_or_year)
        mod_time = datetime(year, month, day)

    return filename, mod_time


def list_remote_files_with_times(ftp: ftplib.FTP_TLS, remote_dir: str) -> list[tuple[str, datetime | None]]:
    """List files in a remote directory with modification times."""
    try:
        ftp.cwd(remote_dir)
        lines = []
        ftp.retrlines('LIST', lines.append)

        files = []
        for line in lines:
            if line.startswith('d'):  # Skip directories
                continue
            filename, mod_time = parse_ftp_list(line)
            if filename and not filename.startswith('.'):
                files.append((filename, mod_time))
        return files
    except ftplib.error_perm as e:
        print(f"  Warning: Cannot access {remote_dir}: {e}")
        return []


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


def format_time_ago(dt: datetime) -> str:
    """Format a datetime as a human-readable 'time ago' string."""
    now = datetime.now()
    diff = now - dt

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
        env_path: Path to env.production file
        output_dir: Local directory to save files
        since: Only download files modified after this time (None = all files)
        list_only: If True, just list files without downloading
        decompress: If True, decompress .gz files after download

    Returns dict with download statistics.
    """
    # Parse credentials
    creds = parse_env_file(env_path)
    host = creds.get('FTP_HOST', '')
    user = creds.get('FTP_USERNAME', '')
    password = creds.get('FTP_PASSWORD', '')

    if not all([host, user, password]):
        print("Error: Missing FTP credentials in env.production")
        print(f"  FTP_HOST: {'set' if host else 'MISSING'}")
        print(f"  FTP_USERNAME: {'set' if user else 'MISSING'}")
        print(f"  FTP_PASSWORD: {'set' if password else 'MISSING'}")
        sys.exit(1)

    print(f"Connecting to {host} as {user}...")
    if since:
        print(f"Filtering: files modified since {since.strftime('%Y-%m-%d %H:%M:%S')}")
    ftp = connect_ftp(host, user, password)

    base_path = '/public_html/tub/backend'
    stats = {'downloaded': 0, 'skipped': 0, 'failed': 0, 'bytes': 0}

    for category, remote_subdir in REMOTE_PATHS.items():
        print(f"\n{'Listing' if list_only else 'Downloading'} {category}/")

        remote_dir = f'{base_path}/{remote_subdir}'
        files = list_remote_files_with_times(ftp, remote_dir)

        if not files:
            print("  (no files)")
            continue

        for filename, mod_time in sorted(files, key=lambda x: x[0]):
            # Filter by modification time if --since was specified
            if since and mod_time and mod_time < since:
                stats['skipped'] += 1
                continue

            local_file = output_dir / category / filename
            time_str = format_time_ago(mod_time) if mod_time else "?"

            if list_only:
                try:
                    size = ftp.size(filename)
                    print(f"  {filename} ({size:,} bytes, {time_str})")
                except:
                    print(f"  {filename} ({time_str})")
                continue

            # Download the file
            if download_file(ftp, filename, local_file):
                size = local_file.stat().st_size
                stats['downloaded'] += 1
                stats['bytes'] += size
                print(f"  {filename} ({size:,} bytes, {time_str})")

                # Decompress .gz files
                if decompress and filename.endswith('.gz'):
                    decompressed = decompress_gzip(local_file)
                    if decompressed != local_file:
                        print(f"    -> decompressed to {decompressed.name}")
            else:
                stats['failed'] += 1

    ftp.quit()
    return stats


def main():
    parser = argparse.ArgumentParser(
        description='Fetch production logs for debugging',
        formatter_class=argparse.RawDescriptionHelpFormatter,
        epilog="""
Examples:
  python scripts/fetch-prod-logs.py                        # Full download
  python scripts/fetch-prod-logs.py --since "2 hours ago"  # Last 2 hours
  python scripts/fetch-prod-logs.py --since yesterday      # Since yesterday
  python scripts/fetch-prod-logs.py --since "Jan 25 2pm"   # Since specific time
  python scripts/fetch-prod-logs.py --list                 # List files only
  python scripts/fetch-prod-logs.py --list --since "1 day ago"  # List recent
        """
    )
    parser.add_argument('--since', type=str,
                        help='Only files modified after this time (e.g., "2 hours ago", "yesterday")')
    parser.add_argument('--list', action='store_true',
                        help='List remote files without downloading')
    parser.add_argument('--no-decompress', action='store_true',
                        help='Keep .gz files compressed')
    parser.add_argument('-o', '--output', type=str,
                        help='Output directory (default: /tmp/prod-debug-TIMESTAMP)')

    args = parser.parse_args()

    # Parse --since argument using dateparser
    since = None
    if args.since:
        since = dateparser.parse(args.since, settings={
            'PREFER_DATES_FROM': 'past',
            'RETURN_AS_TIMEZONE_AWARE': False,
        })
        if since is None:
            print(f"Error: Could not parse time expression: '{args.since}'")
            print("Examples: '2 hours ago', 'yesterday', '3 days ago', 'Jan 25 2pm'")
            sys.exit(1)

    # Determine paths
    script_dir = Path(__file__).parent
    backend_dir = script_dir.parent
    env_path = backend_dir / 'config' / 'env.production'

    if not env_path.exists():
        print(f"Error: {env_path} not found")
        sys.exit(1)

    # Create output directory
    if args.output:
        output_dir = Path(args.output)
    else:
        timestamp = datetime.now().strftime('%Y%m%d-%H%M%S')
        output_dir = Path(f'/tmp/prod-debug-{timestamp}')

    if not args.list:
        output_dir.mkdir(parents=True, exist_ok=True)
        print(f"Output directory: {output_dir}")

    # Fetch logs
    stats = fetch_logs(
        env_path=str(env_path),
        output_dir=output_dir,
        since=since,
        list_only=args.list,
        decompress=not args.no_decompress
    )

    # Print summary
    if not args.list:
        print(f"\n{'='*50}")
        print(f"Download complete!")
        print(f"  Files downloaded: {stats['downloaded']}")
        if stats['skipped']:
            print(f"  Files skipped (older than --since): {stats['skipped']}")
        print(f"  Total size: {stats['bytes']:,} bytes ({stats['bytes']/1024/1024:.1f} MB)")
        if stats['failed']:
            print(f"  Failed: {stats['failed']}")
        print(f"\nFiles saved to: {output_dir}")
        print(f"\nQuick commands:")
        print(f"  grep -r 'heat-target' {output_dir}/logs/")
        print(f"  cat {output_dir}/state/target-temperature.json")
        print(f"  ls -la {output_dir}/crontab-backups/")


if __name__ == '__main__':
    main()
