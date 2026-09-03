from pathlib import Path
import re

import duckdb


class ReceitaDataError(Exception):
    pass


class ReceitaDataFilesMissing(ReceitaDataError):
    pass


class CompanyNotFound(ReceitaDataError):
    pass


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


def query_group(
    value: str,
    data_dir: Path,
) -> dict:
    cnpj_root = normalize_root(
        value
    )

    companies_file = (
        data_dir / "companies.parquet"
    )

    establishments_file = (
        data_dir
        / "establishments.parquet"
    )

    if not companies_file.exists():
        raise ReceitaDataFilesMissing(
            "companies.parquet não encontrado."
        )

    if not establishments_file.exists():
        raise ReceitaDataFilesMissing(
            "establishments.parquet não encontrado."
        )

    connection = duckdb.connect()

    try:
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
            raise CompanyNotFound(
                "Empresa não encontrada na base local."
            )

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

        return result
    finally:
        connection.close()
