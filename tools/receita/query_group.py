#!/usr/bin/env python3

import argparse
import json
import re
import sys
from pathlib import Path

import duckdb

ROOT = Path(__file__).resolve().parent


def normalize_root(value: str) -> str:
    normalized = re.sub(
        r"\D",
        "",
        value,
    )

    if len(normalized) == 14:
        normalized = normalized[:8]

    if len(normalized) != 8:
        raise ValueError(
            "A raiz do CNPJ deve possuir 8 dígitos."
        )

    return normalized


def main() -> int:
    parser = argparse.ArgumentParser()

    parser.add_argument(
        "cnpj_root",
        help="Raiz do CNPJ",
    )

    parser.add_argument(
        "--data-dir",
        default=str(
            ROOT / "fixtures"
        ),
    )

    args = parser.parse_args()

    try:
        cnpj_root = normalize_root(
            args.cnpj_root
        )
    except ValueError as exception:
        print(
            json.dumps(
                {
                    "error": str(exception),
                },
                ensure_ascii=False,
            )
        )

        return 2

    data_dir = Path(
        args.data_dir
    )

    companies_file = (
        data_dir / "companies.parquet"
    )

    establishments_file = (
        data_dir
        / "establishments.parquet"
    )

    if not companies_file.exists():
        print(
            json.dumps(
                {
                    "error":
                        "companies.parquet não encontrado.",
                },
                ensure_ascii=False,
            )
        )

        return 3

    if not establishments_file.exists():
        print(
            json.dumps(
                {
                    "error":
                        "establishments.parquet não encontrado.",
                },
                ensure_ascii=False,
            )
        )

        return 3

    connection = duckdb.connect()

    company = connection.execute(
        """
        SELECT
            cnpj_root,
            corporate_name,
            legal_nature_code,
            legal_nature_description,
            share_capital,
            size_code,
            size_description
        FROM read_parquet(?)
        WHERE cnpj_root = ?
        LIMIT 1
        """,
        [
            str(companies_file),
            cnpj_root,
        ],
    ).fetchone()

    if company is None:
        print(
            json.dumps(
                {
                    "error":
                        "Empresa não encontrada na base local.",

                    "cnpj_root":
                        cnpj_root,
                },
                ensure_ascii=False,
            )
        )

        return 4

    establishments = (
        connection.execute(
            """
            SELECT
                cnpj_root,
                order_number,
                check_digits,
                type,
                fantasy_name,
                registration_status,
                state,
                municipality_name,
                primary_cnae,
                secondary_cnaes
            FROM read_parquet(?)
            WHERE cnpj_root = ?
            ORDER BY order_number
            """,
            [
                str(establishments_file),
                cnpj_root,
            ],
        )
        .fetchall()
    )

    result = {
        "company": {
            "corporate_name":
                company[1],

            "legal_nature_code":
                company[2],

            "legal_nature_description":
                company[3],

            "share_capital":
                (
                    float(company[4])
                    if company[4] is not None
                    else None
                ),

            "size_code":
                company[5],

            "size_description":
                company[6],

            "source":
                "receita-local",
        },

        "establishments": [],
    }

    for establishment in establishments:
        full_cnpj = (
            establishment[0]
            + establishment[1]
            + establishment[2]
        )

        cnaes = []

        primary_cnae = establishment[8]

        if primary_cnae:
            cnaes.append(
                {
                    "code":
                        primary_cnae,

                    "description":
                        None,

                    "is_primary":
                        True,
                }
            )

        secondary_cnaes = (
            establishment[9]
        )

        if secondary_cnaes:
            for code in (
                secondary_cnaes
                .split(",")
            ):
                code = code.strip()

                if not code:
                    continue

                cnaes.append(
                    {
                        "code":
                            code,

                        "description":
                            None,

                        "is_primary":
                            False,
                    }
                )

        result[
            "establishments"
        ].append(
            {
                "establishment": {
                    "cnpj":
                        full_cnpj,

                    "type":
                        establishment[3],

                    "fantasy_name":
                        establishment[4],

                    "registration_status":
                        establishment[5],

                    "state":
                        establishment[6],

                    "municipality_name":
                        establishment[7],

                    "source":
                        "receita-local",
                },

                "cnaes":
                    cnaes,
            }
        )

    print(
        json.dumps(
            result,
            ensure_ascii=False,
            separators=(",", ":"),
        )
    )

    return 0


if __name__ == "__main__":
    sys.exit(
        main()
    )
