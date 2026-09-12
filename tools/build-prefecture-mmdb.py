#!/usr/bin/env python3
"""Build the runtime JP-prefecture MMDB from the reviewed handoff ranges.

This runs during development/release work, never on a WordPress site. The
runtime plugin ships only the generated MMDB and its non-executable manifest.
"""

from __future__ import annotations

import argparse
import csv
import gzip
import hashlib
import ipaddress
import json
import sys
from collections import Counter
from datetime import datetime, timezone
from pathlib import Path


EXPECTED_CODES = {f"JP-{number:02d}" for number in range(1, 48)}


def sha256(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as stream:
        for chunk in iter(lambda: stream.read(1024 * 1024), b""):
            digest.update(chunk)
    return digest.hexdigest().upper()


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser()
    parser.add_argument("source", type=Path, help="japan-prefecture-ranges.csv.gz")
    parser.add_argument("output", type=Path, help="output .mmdb path")
    parser.add_argument("--edition", required=True, help="source edition, for example 2026-09")
    parser.add_argument("--expected-source-sha256", required=True)
    parser.add_argument("--dependency-path", type=Path)
    parser.add_argument("--manifest", type=Path)
    return parser.parse_args()


def main() -> None:
    args = parse_args()
    if args.dependency_path:
        sys.path.insert(0, str(args.dependency_path))

    from mmdb_writer import MMDBWriter  # type: ignore
    from netaddr import IPSet  # type: ignore
    import maxminddb  # type: ignore

    source_hash = sha256(args.source)
    if source_hash != args.expected_source_sha256.upper():
        raise SystemExit(f"source SHA-256 mismatch: {source_hash}")

    ranges: list[tuple[ipaddress._BaseAddress, ipaddress._BaseAddress, str]] = []
    samples: dict[str, list[str]] = {}
    probes: dict[str, dict[str, str]] = {}
    family_counts: Counter[int] = Counter()
    previous: dict[int, tuple[int, int, str] | None] = {4: None, 6: None}

    with gzip.open(args.source, "rt", encoding="ascii", newline="") as stream:
        reader = csv.DictReader(stream)
        if reader.fieldnames != ["ip_start", "ip_end", "region_code"]:
            raise SystemExit(f"unexpected columns: {reader.fieldnames}")
        for row_number, row in enumerate(reader, 2):
            start = ipaddress.ip_address(row["ip_start"])
            end = ipaddress.ip_address(row["ip_end"])
            code = row["region_code"]
            if start.version != end.version or int(start) > int(end):
                raise SystemExit(f"invalid range at row {row_number}")
            if code not in EXPECTED_CODES:
                raise SystemExit(f"unexpected region code at row {row_number}: {code}")
            last = previous[start.version]
            if last and int(start) <= last[1]:
                raise SystemExit(f"overlapping or unsorted range at row {row_number}")
            previous[start.version] = (int(start), int(end), code)
            ranges.append((start, end, code))
            family_counts[start.version] += 1
            samples.setdefault(code, []).append(str(start))
            probes.setdefault(code, {}).setdefault(f"ipv{start.version}", str(start))

    if set(samples) != EXPECTED_CODES:
        raise SystemExit(f"missing region codes: {sorted(EXPECTED_CODES - set(samples))}")

    class StableMMDBWriter(MMDBWriter):
        def _build_meta(self):  # type: ignore[no-untyped-def]
            metadata = super()._build_meta()
            metadata["build_epoch"] = int(datetime.strptime(args.edition + "-01", "%Y-%m-%d").replace(tzinfo=timezone.utc).timestamp())
            return metadata

    writer = StableMMDBWriter(
        ip_version=6,
        ipv4_compatible=True,
        database_type=f"Access-Analytics-Plus-JP-Prefecture-{args.edition}",
        languages=["en"],
        description={"en": f"AAP JP prefecture codes derived from DB-IP City Lite {args.edition}"},
    )
    prefix_count = 0
    for start, end, code in ranges:
        networks = list(ipaddress.summarize_address_range(start, end))
        prefix_count += len(networks)
        writer.insert_network(IPSet([str(network) for network in networks]), {"region_code": code})

    args.output.parent.mkdir(parents=True, exist_ok=True)
    temporary = args.output.with_suffix(args.output.suffix + ".tmp")
    writer.to_db_file(str(temporary))

    reader = maxminddb.open_database(str(temporary))
    try:
        for code in sorted(EXPECTED_CODES):
            for address in samples[code][: min(3, len(samples[code]))]:
                result = reader.get(address)
                if not result or result.get("region_code") != code:
                    raise SystemExit(f"lookup validation failed: {address} expected {code}, got {result}")
        if reader.get("8.8.8.8") is not None:
            raise SystemExit("non-JP lookup unexpectedly returned a region")
    finally:
        reader.close()

    temporary.replace(args.output)
    output_hash = sha256(args.output)
    manifest = {
        "schema": 1,
        "dataset": "AAP Japan Prefecture MMDB",
        "edition": args.edition,
        "database_type": f"Access-Analytics-Plus-JP-Prefecture-{args.edition}",
        "source": {
            "provider": "DB-IP",
            "product": "IP to City Lite",
            "edition": args.edition,
            "license": "CC BY 4.0",
            "url": "https://db-ip.com/db/lite.php",
            "input": args.source.name,
            "input_sha256": source_hash,
        },
        "artifact": {
            "file": args.output.name,
            "size": args.output.stat().st_size,
            "sha256": output_hash,
        },
        "stats": {
            "ranges": len(ranges),
            "ipv4_ranges": family_counts[4],
            "ipv6_ranges": family_counts[6],
            "mmdb_prefixes": prefix_count,
            "region_codes": len(samples),
        },
        "probes": {code: probes[code] for code in sorted(probes)},
        "transformations": [
            "Retained JP records only",
            "Removed city, latitude, longitude and unrelated fields",
            "Normalized prefectures to JP-01 through JP-47",
            "Merged only adjacent or overlapping ranges with the same region code",
            "Converted the reviewed ranges to MaxMind DB format",
        ],
        "generated_at": datetime.now(timezone.utc).isoformat(),
    }
    manifest_path = args.manifest or args.output.with_suffix(".manifest.json")
    manifest_path.write_text(json.dumps(manifest, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    print(json.dumps(manifest, ensure_ascii=False, indent=2))


if __name__ == "__main__":
    main()
