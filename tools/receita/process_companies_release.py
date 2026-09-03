#!/usr/bin/env python3

import argparse
import sys
from pathlib import Path

import duckdb

from convert_companies import convert_companies
from download_release import download_file
from rfb_catalog import RfbCatalogClient


def parquet_is_valid(
    path: Path,
) -> bool:
    if not path.exists():
        return False

    connection = duckdb.connect()

    try:
        row = connection.execute(
            """
            SELECT
                COUNT(*),
                MIN(cnpj_root),
                MAX(cnpj_root)
            FROM read_parquet(?)
            """,
            [str(path)],
        ).fetchone()

        return (
            row is not None
            and row[0] > 0
            and row[1] is not None
            and row[2] is not None
        )

    except Exception:
        return False

    finally:
        connection.close()


def inspect_parquet(
    path: Path,
) -> tuple[int, str, str]:
    connection = duckdb.connect()

    try:
        row = connection.execute(
            """
            SELECT
                COUNT(*),
                MIN(cnpj_root),
                MAX(cnpj_root)
            FROM read_parquet(?)
            """,
            [str(path)],
        ).fetchone()

        if row is None:
            raise RuntimeError(
                f"Parquet vazio: {path}"
            )

        return (
            int(row[0]),
            str(row[1]),
            str(row[2]),
        )

    finally:
        connection.close()


def main() -> int:
    parser = argparse.ArgumentParser(
        description=(
            "Baixa e converte todos os "
            "EmpresasN.zip de uma publicação."
        )
    )

    parser.add_argument(
        "--period",
        required=True,
    )

    parser.add_argument(
        "--raw-root",
        default="/real-data/raw",
    )

    parser.add_argument(
        "--processed-root",
        default="/real-data/processed",
    )

    parser.add_argument(
        "--delete-zip",
        action="store_true",
        help=(
            "Remove cada ZIP após a conversão "
            "e validação do Parquet."
        ),
    )

    args = parser.parse_args()

    raw_root = Path(
        args.raw_root
    )

    processed_root = Path(
        args.processed_root
    )

    client = RfbCatalogClient()

    manifest = client.files(
        args.period
    )

    company_files = sorted(
        [
            item
            for item in manifest
            if item["name"]
            .lower()
            .startswith("empresas")
        ],
        key=lambda item:
            item["name"],
    )

    if not company_files:
        print(
            "[error] Nenhum EmpresasN.zip encontrado.",
            file=sys.stderr,
        )

        return 1

    print(
        "[period]",
        args.period,
    )

    print(
        "[files]",
        len(company_files),
    )

    print()

    summary = []

    for position, item in enumerate(
        company_files,
        start=1,
    ):
        filename = item["name"]

        number = int(
            filename
            .lower()
            .replace("empresas", "")
            .replace(".zip", "")
        )

        parquet = (
            processed_root
            / args.period
            / "companies"
            / f"part-{number:02d}.parquet"
        )

        zip_path = (
            raw_root
            / args.period
            / filename
        )

        print("=" * 70)

        print(
            f"[{position}/{len(company_files)}]",
            filename,
        )

        if parquet_is_valid(
            parquet
        ):
            print(
                "[skip-parquet]",
                parquet,
            )

        else:
            download_file(
                client,
                args.period,
                filename,
                raw_root / args.period,
            )

            convert_companies(
                args.period,
                filename,
                raw_root,
                processed_root,
            )

            if not parquet_is_valid(
                parquet
            ):
                raise RuntimeError(
                    "Parquet não passou na validação: "
                    f"{parquet}"
                )

        count, minimum, maximum = (
            inspect_parquet(
                parquet
            )
        )

        summary.append(
            (
                filename,
                count,
                minimum,
                maximum,
            )
        )

        print(
            "[validated]",
            f"{count:,}",
            "|",
            minimum,
            "→",
            maximum,
        )

        if (
            args.delete_zip
            and zip_path.exists()
        ):
            zip_path.unlink()

            print(
                "[removed]",
                zip_path,
            )

        print()

    print()
    print("=" * 70)
    print("RESUMO")
    print("=" * 70)

    total = 0

    for (
        filename,
        count,
        minimum,
        maximum,
    ) in summary:
        total += count

        print(
            f"{filename:16}",
            f"{count:>10,}",
            "|",
            minimum,
            "→",
            maximum,
        )

    print()
    print(
        "TOTAL DE EMPRESAS:",
        f"{total:,}",
    )

    return 0


if __name__ == "__main__":
    sys.exit(
        main()
    )
