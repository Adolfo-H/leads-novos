#!/usr/bin/env python3

import argparse
import hashlib
import os
import re
import sys
import uuid
from pathlib import Path
from typing import Callable

import duckdb

from release_manifest import (
    COMMIT_NAME,
    MANIFEST_NAME,
    SCHEMA_VERSION,
    TABLE_SPECS,
    ReleaseValidationError,
    canonical_json,
    dataset_version,
    detect_mode,
    inspect_table,
    validate_release,
)


def _sql_literal(value: str) -> str:
    return "'" + value.replace("'", "''") + "'"


def build_active_group_stats(staging_dir: Path) -> None:
    establishments = staging_dir / "establishments" / "*.parquet"
    target = staging_dir / "active_group_stats.parquet"
    temporary = staging_dir / f".active_group_stats-{uuid.uuid4().hex}.parquet"
    connection = duckdb.connect()
    try:
        connection.execute("SET threads = 2")
        connection.execute("SET memory_limit = '2GB'")
        connection.execute(
            f"""
            COPY (
                SELECT
                    cnpj_root,
                    COUNT(*)::BIGINT AS active_establishments,
                    COUNT(DISTINCT state)::BIGINT AS active_states
                FROM read_parquet(?)
                WHERE registration_status_code = '02'
                GROUP BY cnpj_root
            )
            TO {_sql_literal(str(temporary))}
            (FORMAT PARQUET, COMPRESSION ZSTD)
            """,
            [str(establishments)],
        )
    except Exception as exception:
        raise ReleaseValidationError("preaggregation_failed") from exception
    finally:
        connection.close()

    try:
        os.replace(temporary, target)
    except OSError as exception:
        temporary.unlink(missing_ok=True)
        raise ReleaseValidationError("preaggregation_publish_failed") from exception


def _write_atomic(path: Path, content: bytes) -> None:
    temporary = path.with_name(f".{path.name}-{uuid.uuid4().hex}.tmp")
    try:
        with temporary.open("xb") as destination:
            destination.write(content)
            destination.flush()
            os.fsync(destination.fileno())
        os.replace(temporary, path)
    except OSError:
        temporary.unlink(missing_ok=True)
        raise


def write_manifest(staging_dir: Path, period: str) -> dict:
    mode = detect_mode(staging_dir)
    if mode == "real":
        build_active_group_stats(staging_dir)

    tables = {
        name: inspect_table(staging_dir, spec)
        for name, spec in TABLE_SPECS[mode].items()
    }
    manifest = {
        "schema_version": SCHEMA_VERSION,
        "period": period,
        "mode": mode,
        "tables": tables,
    }
    manifest["dataset_version"] = dataset_version(manifest)
    manifest_bytes = canonical_json(manifest)

    _write_atomic(staging_dir / MANIFEST_NAME, manifest_bytes)
    _write_atomic(
        staging_dir / COMMIT_NAME,
        hashlib.sha256(manifest_bytes).hexdigest().encode("ascii"),
    )
    return manifest


def activate_release(processed_root: Path, release_dir: Path) -> None:
    processed_root.mkdir(parents=True, exist_ok=True)
    active = processed_root / "active"
    temporary = processed_root / f".active-{uuid.uuid4().hex}"
    try:
        relative_target = os.path.relpath(release_dir, processed_root)
        os.symlink(relative_target, temporary, target_is_directory=True)
        os.replace(temporary, active)
    except OSError as exception:
        if temporary.is_symlink() or temporary.exists():
            temporary.unlink()
        raise ReleaseValidationError("activation_failed") from exception


def publish_release(
    staging_dir: Path,
    processed_root: Path,
    period: str,
    *,
    activate: bool = False,
    before_activate: Callable[[], None] | None = None,
) -> tuple[Path, str]:
    if not re.fullmatch(r"\d{4}-(0[1-9]|1[0-2])", period):
        raise ReleaseValidationError("period_invalid")

    staging_dir = staging_dir.resolve(strict=True)
    processed_root.mkdir(parents=True, exist_ok=True)
    releases_root = processed_root / "releases"
    releases_root.mkdir(parents=True, exist_ok=True)
    if staging_dir.stat().st_dev != releases_root.stat().st_dev:
        raise ReleaseValidationError("staging_filesystem_mismatch")

    manifest = write_manifest(staging_dir, period)
    validate_release(
        staging_dir,
        verify_samples=True,
        verify_checksums=True,
    )
    version = manifest["dataset_version"]
    release_dir = releases_root / period / version
    release_dir.parent.mkdir(parents=True, exist_ok=True)
    if release_dir.exists():
        raise ReleaseValidationError("release_exists")

    try:
        os.rename(staging_dir, release_dir)
    except OSError as exception:
        raise ReleaseValidationError("release_publish_failed") from exception

    validate_release(
        release_dir,
        verify_samples=True,
        verify_checksums=True,
    )

    if activate:
        if before_activate is not None:
            before_activate()
        activate_release(processed_root, release_dir)

    return release_dir, version


def main() -> int:
    parser = argparse.ArgumentParser(
        description=(
            "Valida e publica um staging Receita sem ativá-lo "
            "automaticamente."
        )
    )
    parser.add_argument("--staging-dir", required=True, type=Path)
    parser.add_argument("--processed-root", required=True, type=Path)
    parser.add_argument("--period", required=True)
    parser.add_argument(
        "--activate",
        action="store_true",
        help="Troca atomicamente o ponteiro ativo após a validação.",
    )
    args = parser.parse_args()

    try:
        release_dir, version = publish_release(
            args.staging_dir,
            args.processed_root,
            args.period,
            activate=args.activate,
        )
    except (OSError, ReleaseValidationError) as exception:
        code = (
            exception.code
            if isinstance(exception, ReleaseValidationError)
            else "publication_io_error"
        )
        print(f"[error] publication failed: {code}", file=sys.stderr)
        return 1

    print(f"[published] version={version}")
    if args.activate:
        print("[active] pointer switched")
    else:
        print("[inactive] explicit --activate required")
    return 0


if __name__ == "__main__":
    sys.exit(main())
