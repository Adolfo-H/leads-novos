import hashlib
import json
import re
from pathlib import Path
from typing import Any

import duckdb


MANIFEST_NAME = "manifest.json"
COMMIT_NAME = "_SUCCESS"
SCHEMA_VERSION = 1

TABLE_SPECS = {
    "fixture": {
        "companies": (
            "companies.parquet",
            "cnpj_root",
            {
                "cnpj_root",
                "corporate_name",
                "legal_nature_code",
                "legal_nature_description",
                "share_capital",
                "size_code",
                "size_description",
            },
        ),
        "establishments": (
            "establishments.parquet",
            "cnpj_root",
            {
                "cnpj_root",
                "order_number",
                "check_digits",
                "type",
                "fantasy_name",
                "registration_status",
                "state",
                "municipality_name",
                "primary_cnae",
                "secondary_cnaes",
            },
        ),
    },
    "real": {
        "companies": (
            "companies/*.parquet",
            "cnpj_root",
            {
                "cnpj_root",
                "corporate_name",
                "legal_nature_code",
                "responsible_qualification_code",
                "share_capital",
                "size_code",
                "federative_entity",
            },
        ),
        "establishments": (
            "establishments/*.parquet",
            "cnpj_root",
            {
                "cnpj_root",
                "order_number",
                "check_digits",
                "cnpj",
                "type",
                "fantasy_name",
                "registration_status_code",
                "registration_status_date",
                "registration_status_reason_code",
                "foreign_city_name",
                "country_code",
                "start_date",
                "primary_cnae",
                "secondary_cnaes",
                "state",
                "municipality_code",
            },
        ),
        "cnaes": (
            "domains/cnaes.parquet",
            "code",
            {"code", "description"},
        ),
        "municipios": (
            "domains/municipios.parquet",
            "code",
            {"code", "description"},
        ),
        "naturezas": (
            "domains/naturezas.parquet",
            "code",
            {"code", "description"},
        ),
        "active_group_stats": (
            "active_group_stats.parquet",
            "cnpj_root",
            {
                "cnpj_root",
                "active_establishments",
                "active_states",
            },
        ),
    },
}


class ReleaseValidationError(RuntimeError):
    def __init__(self, code: str):
        super().__init__(code)
        self.code = code


def canonical_json(value: dict[str, Any]) -> bytes:
    return json.dumps(
        value,
        ensure_ascii=False,
        sort_keys=True,
        separators=(",", ":"),
    ).encode("utf-8")


def dataset_version(manifest: dict[str, Any]) -> str:
    version_data = {
        key: manifest[key]
        for key in ("schema_version", "period", "mode", "tables")
    }
    return hashlib.sha256(canonical_json(version_data)).hexdigest()


def detect_mode(data_dir: Path) -> str:
    if (
        (data_dir / "companies").is_dir()
        and (data_dir / "establishments").is_dir()
        and (data_dir / "domains").is_dir()
    ):
        return "real"
    if (
        (data_dir / "companies.parquet").is_file()
        and (data_dir / "establishments.parquet").is_file()
    ):
        return "fixture"
    raise ReleaseValidationError("required_tables_missing")


def parquet_files(data_dir: Path, pattern: str) -> list[Path]:
    if "*" in pattern:
        return sorted(data_dir.glob(pattern))
    path = data_dir / pattern
    return [path] if path.is_file() else []


def sha256_file(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as source:
        for chunk in iter(lambda: source.read(8 * 1024 * 1024), b""):
            digest.update(chunk)
    return digest.hexdigest()


def parquet_magic_is_valid(path: Path) -> bool:
    if path.stat().st_size < 8:
        return False
    with path.open("rb") as parquet_file:
        parquet_file.seek(-4, 2)
        footer = parquet_file.read(4)
        parquet_file.seek(0)
        header = parquet_file.read(4)
    return header == b"PAR1" and footer == b"PAR1"


def inspect_table(
    data_dir: Path,
    spec: tuple[str, str, set[str]],
) -> dict[str, Any]:
    pattern, sample_column, required_columns = spec
    files = parquet_files(data_dir, pattern)
    if not files:
        raise ReleaseValidationError("required_tables_missing")

    connection = duckdb.connect()
    try:
        connection.execute("SET threads = 2")
        connection.execute("SET memory_limit = '2GB'")
        parquet_source = (
            str(data_dir / pattern)
            if "*" in pattern
            else str(files[0])
        )
        schema_rows = connection.execute(
            "DESCRIBE SELECT * FROM read_parquet(?)",
            [parquet_source],
        ).fetchall()
        schema = [
            {"name": str(row[0]), "type": str(row[1])}
            for row in schema_rows
        ]
        columns = {column["name"] for column in schema}
        if not required_columns.issubset(columns):
            raise ReleaseValidationError("schema_invalid")

        row_count = int(
            connection.execute(
                "SELECT COUNT(*) FROM read_parquet(?)",
                [parquet_source],
            ).fetchone()[0]
        )
        if row_count < 1:
            raise ReleaseValidationError("table_empty")

        sample_value = connection.execute(
            f'SELECT "{sample_column}" FROM read_parquet(?) LIMIT 1',
            [str(files[0])],
        ).fetchone()[0]
        if sample_value is None or str(sample_value).strip() == "":
            raise ReleaseValidationError("sample_invalid")
    except ReleaseValidationError:
        raise
    except Exception as exception:
        raise ReleaseValidationError("parquet_invalid") from exception
    finally:
        connection.close()

    file_metadata = []
    for path in files:
        if path.is_symlink() or not path.is_file():
            raise ReleaseValidationError("file_invalid")
        file_metadata.append(
            {
                "path": path.relative_to(data_dir).as_posix(),
                "size_bytes": path.stat().st_size,
                "sha256": sha256_file(path),
            }
        )

    return {
        "files": file_metadata,
        "row_count": row_count,
        "schema": schema,
        "sample": {
            "path": file_metadata[0]["path"],
            "column": sample_column,
            "value": str(sample_value),
        },
    }


def validate_release(
    data_dir: Path,
    *,
    verify_samples: bool = False,
    verify_checksums: bool = False,
) -> dict[str, Any]:
    try:
        root = data_dir.resolve(strict=True)
        if not root.is_dir():
            raise ReleaseValidationError("dataset_unavailable")
        manifest_path = root / MANIFEST_NAME
        if manifest_path.stat().st_size > 10 * 1024 * 1024:
            raise ReleaseValidationError("manifest_too_large")
        manifest_bytes = manifest_path.read_bytes()
        commit = (root / COMMIT_NAME).read_text(encoding="ascii").strip()
    except ReleaseValidationError:
        raise
    except OSError as exception:
        raise ReleaseValidationError("manifest_missing") from exception

    if not re.fullmatch(r"[a-f0-9]{64}", commit):
        raise ReleaseValidationError("commit_invalid")
    if not hashlib.sha256(manifest_bytes).hexdigest() == commit:
        raise ReleaseValidationError("commit_mismatch")

    try:
        manifest = json.loads(manifest_bytes)
    except (json.JSONDecodeError, UnicodeDecodeError) as exception:
        raise ReleaseValidationError("manifest_invalid") from exception
    if not isinstance(manifest, dict):
        raise ReleaseValidationError("manifest_invalid")

    mode = manifest.get("mode")
    if not isinstance(mode, str) or mode not in TABLE_SPECS:
        raise ReleaseValidationError("manifest_invalid")
    if manifest.get("schema_version") != SCHEMA_VERSION:
        raise ReleaseValidationError("schema_version_unsupported")
    period = manifest.get("period")
    if not isinstance(period, str) or not re.fullmatch(r"\d{4}-\d{2}", period):
        raise ReleaseValidationError("manifest_invalid")
    tables = manifest.get("tables")
    if not isinstance(tables, dict):
        raise ReleaseValidationError("manifest_invalid")
    if not isinstance(manifest.get("dataset_version"), str):
        raise ReleaseValidationError("manifest_invalid")
    if manifest.get("dataset_version") != dataset_version(manifest):
        raise ReleaseValidationError("dataset_version_mismatch")

    required_specs = TABLE_SPECS[mode]
    if set(tables) != set(required_specs):
        raise ReleaseValidationError("manifest_invalid")

    for name, spec in required_specs.items():
        table = tables.get(name)
        if not isinstance(table, dict):
            raise ReleaseValidationError("manifest_invalid")
        if not isinstance(table.get("row_count"), int) or table["row_count"] < 1:
            raise ReleaseValidationError("table_empty")
        schema = table.get("schema")
        if not isinstance(schema, list):
            raise ReleaseValidationError("schema_invalid")
        columns = {
            item.get("name")
            for item in schema
            if isinstance(item, dict)
        }
        if not spec[2].issubset(columns):
            raise ReleaseValidationError("schema_invalid")

        files = table.get("files")
        if not isinstance(files, list) or not files:
            raise ReleaseValidationError("required_tables_missing")
        for file_info in files:
            if not isinstance(file_info, dict):
                raise ReleaseValidationError("manifest_invalid")
            relative_path = file_info.get("path")
            if not isinstance(relative_path, str):
                raise ReleaseValidationError("manifest_invalid")
            candidate = Path(relative_path)
            if candidate.is_absolute() or ".." in candidate.parts:
                raise ReleaseValidationError("manifest_invalid")
            file_path = root / candidate
            if any(
                part.is_symlink()
                for part in [root, *file_path.parents]
                if part != root.parent
            ):
                raise ReleaseValidationError("file_invalid")
            if file_path.is_symlink() or not file_path.is_file():
                raise ReleaseValidationError("file_missing")
            try:
                actual_size = file_path.stat().st_size
                magic_valid = parquet_magic_is_valid(file_path)
            except OSError as exception:
                raise ReleaseValidationError("file_unreadable") from exception
            if file_info.get("size_bytes") != actual_size:
                raise ReleaseValidationError("file_size_mismatch")
            if not magic_valid:
                raise ReleaseValidationError("parquet_magic_invalid")
            if not re.fullmatch(
                r"[a-f0-9]{64}",
                str(file_info.get("sha256", "")),
            ):
                raise ReleaseValidationError("manifest_invalid")
            if (
                verify_checksums
                and sha256_file(file_path) != file_info["sha256"]
            ):
                raise ReleaseValidationError("file_checksum_mismatch")

        sample = table.get("sample")
        if (
            not isinstance(sample, dict)
            or sample.get("column") != spec[1]
            or sample.get("path") != files[0].get("path")
            or not isinstance(sample.get("value"), str)
            or sample["value"] == ""
        ):
            raise ReleaseValidationError("sample_invalid")

    if verify_samples:
        connection = duckdb.connect()
        try:
            for name, table in tables.items():
                sample = table["sample"]
                sample_path = root / sample["path"]
                actual_schema = connection.execute(
                    "DESCRIBE SELECT * FROM read_parquet(?)",
                    [str(sample_path)],
                ).fetchall()
                expected_schema = [
                    (item["name"], item["type"])
                    for item in table["schema"]
                ]
                if [
                    (str(row[0]), str(row[1]))
                    for row in actual_schema
                ] != expected_schema:
                    raise ReleaseValidationError("schema_mismatch")
                actual = connection.execute(
                    f'SELECT "{sample["column"]}" FROM read_parquet(?) LIMIT 1',
                    [str(sample_path)],
                ).fetchone()
                if actual is None or str(actual[0]) != sample["value"]:
                    raise ReleaseValidationError("sample_mismatch")
        except ReleaseValidationError:
            raise
        except Exception as exception:
            raise ReleaseValidationError("sample_check_failed") from exception
        finally:
            connection.close()

    return manifest
