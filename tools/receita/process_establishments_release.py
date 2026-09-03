#!/usr/bin/env python3

import argparse
import sys
from pathlib import Path

import duckdb

from convert_establishments import (
    convert_establishments,
)
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
                COUNT(DISTINCT cnpj)
            FROM read_parquet(?)
            """,
            [str(path)],
        ).fetchone()

        return (
            row is not None
            and row[0] > 0
            and row[0] == row[1]
        )

    except Exception:
        return False

    finally:
        connection.close()


def inspect_parquet(
    path: Path,
) -> tuple[int, int, int]:
    connection = duckdb.connect()

    try:
        row = connection.execute(
            """
            SELECT
                COUNT(*),

                SUM(
                    CASE
                        WHEN type = 'matrix'
                        THEN 1
                        ELSE 0
                    END
                ),

                SUM(
                    CASE
                        WHEN type = 'branch'
                        THEN 1
                        ELSE 0
                    END
                )

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
            int(row[1] or 0),
            int(row[2] or 0),
        )

    finally:
        connection.close()


def main() -> int:
    parser = argparse.ArgumentParser(
        description=(
            "Baixa e converte todos os "
            "EstabelecimentosN.zip "
            "de uma publicação."
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
    )

    args = parser.parse_args()

    raw_root = Path(
        args.raw_root
    )

    processed_root = Path(
        args.processed_root
    )

    client = RfbCatalogClient()

    files = sorted(
        [
            item
            for item in client.files(
                args.period
            )
            if item["name"]
            .lower()
            .startswith(
                "estabelecimentos"
            )
        ],
        key=lambda item:
            item["name"],
    )

    if not files:
        print(
            "[error] Nenhum arquivo "
            "de estabelecimentos encontrado.",
            file=sys.stderr,
        )

        return 1

    print(
        "[period]",
        args.period,
    )

    print(
        "[files]",
        len(files),
    )

    summary = []

    for position, item in enumerate(
        files,
        start=1,
    ):
        filename = item["name"]

        part = int(
            filename
            .lower()
            .replace(
                "estabelecimentos",
                ""
            )
            .replace(
                ".zip",
                ""
            )
        )

        parquet = (
            processed_root
            / args.period
            / "establishments"
            / f"part-{part:02d}.parquet"
        )

        zip_path = (
            raw_root
            / args.period
            / filename
        )

        print()
        print("=" * 75)

        print(
            f"[{position}/{len(files)}]",
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

            convert_establishments(
                args.period,
                filename,
                raw_root,
                processed_root,
            )

            if not parquet_is_valid(
                parquet
            ):
                raise RuntimeError(
                    "Parquet não passou "
                    "na validação: "
                    f"{parquet}"
                )

        (
            count,
            matrices,
            branches,
        ) = inspect_parquet(
            parquet
        )

        summary.append(
            (
                filename,
                count,
                matrices,
                branches,
            )
        )

        print(
            "[validated]",
            f"{count:,}",
            "| matrices:",
            f"{matrices:,}",
            "| branches:",
            f"{branches:,}",
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
    print("=" * 75)
    print("RESUMO")
    print("=" * 75)

    total = 0
    total_matrices = 0
    total_branches = 0

    for (
        filename,
        count,
        matrices,
        branches,
    ) in summary:
        total += count
        total_matrices += matrices
        total_branches += branches

        print(
            f"{filename:25}"
            f"{count:>12,}"
            f" | M {matrices:>10,}"
            f" | F {branches:>10,}"
        )

    print()
    print(
        "TOTAL:",
        f"{total:,}",
    )

    print(
        "MATRIZES:",
        f"{total_matrices:,}",
    )

    print(
        "FILIAIS:",
        f"{total_branches:,}",
    )

    return 0


if __name__ == "__main__":
    sys.exit(
        main()
    )
