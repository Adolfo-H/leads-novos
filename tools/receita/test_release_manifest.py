import asyncio
import json
import os
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

import duckdb

import api
from prospect_query import query_prospects
from publish_release import publish_release
from release_manifest import ReleaseValidationError


def asgi_get(path: str) -> tuple[int, dict]:
    messages = []
    received_request = False

    async def receive() -> dict:
        nonlocal received_request
        if received_request:
            return {"type": "http.disconnect"}
        received_request = True
        return {
            "type": "http.request",
            "body": b"",
            "more_body": False,
        }

    async def send(message: dict) -> None:
        messages.append(message)

    async def request() -> None:
        await api.app(
            {
                "type": "http",
                "asgi": {"version": "3.0", "spec_version": "2.3"},
                "http_version": "1.1",
                "method": "GET",
                "scheme": "http",
                "path": path,
                "raw_path": path.encode("ascii"),
                "query_string": b"",
                "root_path": "",
                "headers": [(b"host", b"testserver")],
                "client": ("127.0.0.1", 12345),
                "server": ("testserver", 80),
            },
            receive,
            send,
        )

    asyncio.run(request())
    response_start = next(
        message
        for message in messages
        if message["type"] == "http.response.start"
    )
    body = b"".join(
        message.get("body", b"")
        for message in messages
        if message["type"] == "http.response.body"
    )
    return response_start["status"], json.loads(body)


def write_fixture(staging: Path, corporate_name: str = "EMPRESA TESTE") -> None:
    staging.mkdir(parents=True, exist_ok=True)
    company_path = str(staging / "companies.parquet").replace("'", "''")
    establishment_path = str(
        staging / "establishments.parquet"
    ).replace("'", "''")
    connection = duckdb.connect()
    try:
        connection.execute(
            f"""
            COPY (
                SELECT
                    'AB12CD34' AS cnpj_root,
                    ? AS corporate_name,
                    '2143' AS legal_nature_code,
                    'COOPERATIVA' AS legal_nature_description,
                    5000000.00::DECIMAL(18, 2) AS share_capital,
                    '05' AS size_code,
                    'DEMAIS' AS size_description
            )
            TO '{company_path}'
            (FORMAT PARQUET)
            """,
            [corporate_name],
        )
        connection.execute(
            f"""
            COPY (
                SELECT
                    'AB12CD34' AS cnpj_root,
                    '0001' AS order_number,
                    '80' AS check_digits,
                    'matrix' AS type,
                    'EMPRESA TESTE' AS fantasy_name,
                    'ATIVA' AS registration_status,
                    'SP' AS state,
                    'SAO PAULO' AS municipality_name,
                    '0012345' AS primary_cnae,
                    '0012345' AS secondary_cnaes
            )
            TO '{establishment_path}'
            (FORMAT PARQUET)
            """,
        )
    finally:
        connection.close()


def write_real_dataset(staging: Path) -> None:
    (staging / "companies").mkdir(parents=True)
    (staging / "establishments").mkdir()
    (staging / "domains").mkdir()
    connection = duckdb.connect()
    try:
        connection.execute(
            """
            CREATE TABLE companies (
                cnpj_root VARCHAR,
                corporate_name VARCHAR,
                legal_nature_code VARCHAR,
                responsible_qualification_code VARCHAR,
                share_capital DECIMAL(20, 2),
                size_code VARCHAR,
                federative_entity VARCHAR
            )
            """
        )
        connection.executemany(
            "INSERT INTO companies VALUES (?, ?, '2143', '05', 5000000, '05', NULL)",
            [
                (root, f"Empresa {root}")
                for root in (
                    "00112233",
                    "AB12CD34",
                    "EF56GH78",
                    "JK90LM12",
                )
            ],
        )
        connection.execute(
            """
            CREATE TABLE establishments (
                cnpj_root VARCHAR,
                order_number VARCHAR,
                check_digits VARCHAR,
                cnpj VARCHAR,
                type VARCHAR,
                fantasy_name VARCHAR,
                registration_status_code VARCHAR,
                registration_status_date DATE,
                registration_status_reason_code VARCHAR,
                foreign_city_name VARCHAR,
                country_code VARCHAR,
                start_date DATE,
                primary_cnae VARCHAR,
                secondary_cnaes VARCHAR,
                state VARCHAR,
                municipality_code VARCHAR
            )
            """
        )
        establishment_rows = []
        for root in ("00112233", "AB12CD34", "EF56GH78", "JK90LM12"):
            establishment_rows.append(
                (
                    root,
                    "0001",
                    "80",
                    f"{root}000180",
                    "matrix",
                    f"Empresa {root}",
                    "02",
                    None,
                    None,
                    None,
                    None,
                    None,
                    "0012345",
                    None,
                    "SP",
                    "0001",
                )
            )
        establishment_rows.insert(
            1,
            (
                "00112233",
                "0002",
                "60",
                "00112233000260",
                "branch",
                None,
                "02",
                None,
                None,
                None,
                None,
                None,
                "0012345",
                None,
                "SP",
                "0001",
            ),
        )
        connection.executemany(
            "INSERT INTO establishments VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
            establishment_rows,
        )
        for table, columns, rows in (
            (
                "cnaes",
                "code VARCHAR, description VARCHAR",
                [("0012345", "CNAE com zero inicial")],
            ),
            (
                "municipios",
                "code VARCHAR, description VARCHAR",
                [("0001", "Município teste")],
            ),
            (
                "naturezas",
                "code VARCHAR, description VARCHAR",
                [("2143", "Cooperativa")],
            ),
        ):
            connection.execute(
                f"CREATE TABLE {table} ({columns})"
            )
            connection.executemany(
                f"INSERT INTO {table} VALUES (?, ?)",
                rows,
            )
        connection.execute(
            f"""
            COPY companies
            TO '{staging / "companies" / "part-01.parquet"}'
            (FORMAT PARQUET)
            """
        )
        connection.execute(
            f"""
            COPY establishments
            TO '{staging / "establishments" / "part-01.parquet"}'
            (FORMAT PARQUET)
            """
        )
        for table in ("cnaes", "municipios", "naturezas"):
            connection.execute(
                f"""
                COPY {table}
                TO '{staging / "domains" / f"{table}.parquet"}'
                (FORMAT PARQUET)
                """
            )
    finally:
        connection.close()


def measure_rows_scanned(query: str, parquet_path: Path) -> int:
    descriptor, filename = tempfile.mkstemp(
        prefix="receita-test-profile-",
        suffix=".json",
    )
    os.close(descriptor)
    profile_path = Path(filename)
    connection = duckdb.connect()
    try:
        connection.execute("PRAGMA enable_profiling='json'")
        escaped_path = str(profile_path).replace("'", "''")
        connection.execute(
            f"SET profiling_output='{escaped_path}'"
        )
        connection.execute(
            query,
            [str(parquet_path)],
        ).fetchall()
        profile = json.loads(
            connection.get_profiling_information()
        )
        return int(profile["cumulative_rows_scanned"])
    finally:
        connection.close()
        profile_path.unlink(missing_ok=True)


class ReceitaReleaseTest(unittest.TestCase):
    def test_named_but_corrupt_parquets_make_health_unavailable(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            data_dir = Path(directory)
            staging = data_dir / "staging"
            processed = data_dir / "processed"
            write_fixture(staging)
            release, _ = publish_release(
                staging,
                processed,
                "2026-08",
            )
            company_file = release / "companies.parquet"
            corrupted = bytearray(company_file.read_bytes())
            corrupted[:4] = b"FAIL"
            company_file.write_bytes(corrupted)

            with patch.object(api, "DATA_DIR", release):
                status, response = asgi_get("/health")

        self.assertEqual(status, 503)
        self.assertEqual(
            response["detail"]["message"],
            "Dataset da Receita indisponível ou inválido.",
        )
        self.assertEqual(
            response["detail"]["code"],
            "parquet_magic_invalid",
        )

    def test_valid_manifest_health_and_alphanumeric_group_query(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            staging = root / "staging"
            processed = root / "processed"
            write_fixture(staging)
            release, _ = publish_release(
                staging,
                processed,
                "2026-08",
            )

            with patch.object(api, "DATA_DIR", release):
                health_status, health_body = asgi_get("/health")
                group_status, group_body = asgi_get("/groups/AB12CD34")

        self.assertEqual(health_status, 200)
        self.assertEqual(health_body["status"], "ok")
        self.assertNotIn("data_dir", health_body)
        self.assertEqual(group_status, 200)
        self.assertEqual(
            group_body["company"]["corporate_name"],
            "EMPRESA TESTE",
        )

    def test_real_release_preaggregates_and_paginates_without_duplicates(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            staging = root / "staging"
            processed = root / "processed"
            write_real_dataset(staging)
            release, _ = publish_release(
                staging,
                processed,
                "2026-08",
            )

            first = query_prospects(
                release,
                states=["SP"],
                cnaes=["0012345"],
                limit=2,
            )
            second = query_prospects(
                release,
                states=["SP"],
                cnaes=["0012345"],
                limit=2,
                offset=2,
                expected_dataset_version=first["dataset_version"],
            )
            baseline_stats_rows = measure_rows_scanned(
                """
                SELECT cnpj_root, COUNT(*)
                FROM read_parquet(?)
                WHERE registration_status_code = '02'
                GROUP BY cnpj_root
                """,
                release / "establishments" / "part-01.parquet",
            )
            preaggregated_stats_rows = measure_rows_scanned(
                """
                SELECT cnpj_root, active_establishments
                FROM read_parquet(?)
                """,
                release / "active_group_stats.parquet",
            )

            with self.assertRaises(ReleaseValidationError) as error:
                query_prospects(
                    release,
                    states=["SP"],
                    cnaes=["0012345"],
                    expected_dataset_version="stale-version",
                )
            self.assertEqual(error.exception.code, "dataset_version_changed")

            all_roots = [
                item["cnpj_root"]
                for item in first["items"] + second["items"]
            ]

        self.assertIsInstance(first["rows_scanned"], int)
        self.assertGreater(first["rows_scanned"], 0)
        self.assertLess(
            preaggregated_stats_rows,
            baseline_stats_rows,
        )
        self.assertEqual(baseline_stats_rows, 5)
        self.assertEqual(preaggregated_stats_rows, 4)
        self.assertEqual(len(all_roots), 4)
        self.assertEqual(len(set(all_roots)), 4)
        self.assertIn("00112233", all_roots)
        self.assertIn("AB12CD34", all_roots)
        self.assertEqual(
            next(
                item["cnpj"]
                for item in first["items"] + second["items"]
                if item["cnpj_root"] == "00112233"
            ),
            "00112233000180",
        )
        self.assertTrue(
            all(
                item["matched_cnae"] == "0012345"
                for item in first["items"] + second["items"]
            )
        )

    def test_failed_activation_keeps_previous_active_release(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            processed = root / "processed"
            first_staging = root / "first"
            write_fixture(first_staging, "EMPRESA ORIGINAL")
            first_release, _ = publish_release(
                first_staging,
                processed,
                "2026-08",
                activate=True,
            )
            original_target = (processed / "active").resolve()

            second_staging = root / "second"
            write_fixture(second_staging, "EMPRESA NOVA")
            with self.assertRaises(RuntimeError):
                publish_release(
                    second_staging,
                    processed,
                    "2026-09",
                    activate=True,
                    before_activate=lambda: (_ for _ in ()).throw(
                        RuntimeError("simulated failure")
                    ),
                )

            self.assertEqual((processed / "active").resolve(), original_target)
            self.assertTrue(first_release.is_dir())


if __name__ == "__main__":
    unittest.main()
