from pathlib import Path
import re

import duckdb


REGISTRATION_STATUS = {
    "01": "NULA",
    "02": "ATIVA",
    "03": "SUSPENSA",
    "04": "INAPTA",
    "08": "BAIXADA",
}

SIZE_DESCRIPTIONS = {
    "00": "NÃO INFORMADO",
    "01": "MICRO EMPRESA",
    "03": "EMPRESA DE PEQUENO PORTE",
    "05": "DEMAIS",
}


class ReceitaDataError(Exception):
    pass


class ReceitaDataFilesMissing(
    ReceitaDataError
):
    pass


class CompanyNotFound(
    ReceitaDataError
):
    pass


def normalize_root(
    value: str,
) -> str:
    normalized = re.sub(
        r"[^A-Z0-9]",
        "",
        value.upper(),
    )

    if len(normalized) == 14:
        normalized = normalized[:8]

    if len(normalized) != 8:
        raise ValueError(
            "A raiz do CNPJ deve possuir "
            "8 caracteres."
        )

    if not re.fullmatch(
        r"[A-Z0-9]{8}",
        normalized,
    ):
        raise ValueError(
            "A raiz do CNPJ contém "
            "caracteres inválidos."
        )

    return normalized


def query_group(
    value: str,
    data_dir: Path,
) -> dict:
    if (
        data_dir
        / "companies.parquet"
    ).exists():
        return query_fixture_group(
            value,
            data_dir,
        )

    return query_real_group(
        value,
        data_dir,
    )


def query_real_group(
    value: str,
    data_dir: Path,
) -> dict:
    cnpj_root = normalize_root(
        value
    )

    companies_dir = (
        data_dir / "companies"
    )

    establishments_dir = (
        data_dir / "establishments"
    )

    domains_dir = (
        data_dir / "domains"
    )

    company_files = list(
        companies_dir.glob(
            "*.parquet"
        )
    )

    establishment_files = list(
        establishments_dir.glob(
            "*.parquet"
        )
    )

    cnaes_file = (
        domains_dir
        / "cnaes.parquet"
    )

    municipios_file = (
        domains_dir
        / "municipios.parquet"
    )

    naturezas_file = (
        domains_dir
        / "naturezas.parquet"
    )

    if not company_files:
        raise ReceitaDataFilesMissing(
            "Parquets de empresas "
            "não encontrados."
        )

    if not establishment_files:
        raise ReceitaDataFilesMissing(
            "Parquets de estabelecimentos "
            "não encontrados."
        )

    for required in [
        cnaes_file,
        municipios_file,
        naturezas_file,
    ]:
        if not required.exists():
            raise ReceitaDataFilesMissing(
                "Domínio obrigatório "
                f"não encontrado: {required.name}"
            )

    companies_glob = str(
        companies_dir
        / "*.parquet"
    )

    establishments_glob = str(
        establishments_dir
        / "*.parquet"
    )

    connection = duckdb.connect()

    try:
        company = (
            connection.execute(
                """
                SELECT
                    c.cnpj_root,
                    c.corporate_name,
                    c.legal_nature_code,

                    n.description
                        AS legal_nature_description,

                    c.responsible_qualification_code,
                    c.share_capital,
                    c.size_code,
                    c.federative_entity

                FROM read_parquet(?) c

                LEFT JOIN read_parquet(?) n
                    ON n.code =
                        c.legal_nature_code

                WHERE c.cnpj_root = ?

                LIMIT 1
                """,
                [
                    companies_glob,
                    str(
                        naturezas_file
                    ),
                    cnpj_root,
                ],
            )
            .fetchone()
        )

        if company is None:
            raise CompanyNotFound(
                "Empresa não encontrada "
                "na base local da Receita."
            )

        cnae_rows = (
            connection.execute(
                """
                SELECT
                    code,
                    description
                FROM read_parquet(?)
                """,
                [
                    str(
                        cnaes_file
                    ),
                ],
            )
            .fetchall()
        )

        cnae_descriptions = {
            row[0]: row[1]
            for row in cnae_rows
        }

        establishments = (
            connection.execute(
                """
                SELECT
                    e.cnpj,
                    e.order_number,
                    e.check_digits,
                    e.type,
                    e.fantasy_name,

                    e.registration_status_code,

                    CAST(
                        e.registration_status_date
                        AS VARCHAR
                    ),

                    e.registration_status_reason_code,

                    e.foreign_city_name,
                    e.country_code,

                    CAST(
                        e.start_date
                        AS VARCHAR
                    ),

                    e.primary_cnae,

                    c.description
                        AS primary_cnae_description,

                    e.secondary_cnaes,

                    e.address_type,
                    e.street,
                    e.number,
                    e.complement,
                    e.neighborhood,
                    e.zip_code,
                    e.state,

                    e.municipality_code,

                    m.description
                        AS municipality_name,

                    e.phone_1,
                    e.phone_2,
                    e.fax,
                    e.email,

                    e.special_situation,

                    CAST(
                        e.special_situation_date
                        AS VARCHAR
                    )

                FROM read_parquet(?) e

                LEFT JOIN read_parquet(?) m
                    ON m.code =
                        e.municipality_code

                LEFT JOIN read_parquet(?) c
                    ON c.code =
                        e.primary_cnae

                WHERE e.cnpj_root = ?

                ORDER BY
                    CASE
                        WHEN e.type = 'matrix'
                        THEN 0
                        ELSE 1
                    END,

                    e.order_number
                """,
                [
                    establishments_glob,
                    str(
                        municipios_file
                    ),
                    str(
                        cnaes_file
                    ),
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

                "responsible_qualification_code":
                    company[4],

                "share_capital":
                    (
                        float(
                            company[5]
                        )
                        if company[5]
                        is not None
                        else None
                    ),

                "size_code":
                    company[6],

                "size_description":
                    SIZE_DESCRIPTIONS.get(
                        company[6]
                    ),

                "federative_entity":
                    company[7],

                "source":
                    "receita-local",

                "metadata": {
                    "dataset_period":
                        data_dir.name,
                },
            },

            "establishments": [],
        }

        for establishment in establishments:
            cnaes = []

            primary_cnae = (
                establishment[11]
            )

            if primary_cnae:
                cnaes.append(
                    {
                        "code":
                            primary_cnae,

                        "description":
                            establishment[12],

                        "is_primary":
                            True,
                    }
                )

            secondary_cnaes = (
                establishment[13]
            )

            if secondary_cnaes:
                for code in (
                    secondary_cnaes
                    .split(",")
                ):
                    code = code.strip()

                    if (
                        not code
                        or code
                        == primary_cnae
                    ):
                        continue

                    cnaes.append(
                        {
                            "code":
                                code,

                            "description":
                                cnae_descriptions
                                .get(code),

                            "is_primary":
                                False,
                        }
                    )

            status_code = (
                establishment[5]
            )

            result[
                "establishments"
            ].append(
                {
                    "establishment": {
                        "cnpj":
                            establishment[0],

                        "type":
                            establishment[3],

                        "fantasy_name":
                            establishment[4],

                        "registration_status_code":
                            status_code,

                        "registration_status":
                            REGISTRATION_STATUS
                            .get(
                                status_code,
                                status_code,
                            ),

                        "registration_status_date":
                            establishment[6],

                        "registration_status_reason_code":
                            establishment[7],

                        "foreign_city_name":
                            establishment[8],

                        "country_code":
                            establishment[9],

                        "start_date":
                            establishment[10],

                        "address_type":
                            establishment[14],

                        "street":
                            establishment[15],

                        "number":
                            establishment[16],

                        "complement":
                            establishment[17],

                        "neighborhood":
                            establishment[18],

                        "zip_code":
                            establishment[19],

                        "state":
                            establishment[20],

                        "municipality_code":
                            establishment[21],

                        "municipality_name":
                            establishment[22],

                        "phone_1":
                            establishment[23],

                        "phone_2":
                            establishment[24],

                        "fax":
                            establishment[25],

                        "email":
                            establishment[26],

                        "special_situation":
                            establishment[27],

                        "special_situation_date":
                            establishment[28],

                        "source":
                            "receita-local",

                        "metadata": {
                            "dataset_period":
                                data_dir.name,
                        },
                    },

                    "cnaes":
                        cnaes,
                }
            )

        return result

    finally:
        connection.close()


def query_fixture_group(
    value: str,
    data_dir: Path,
) -> dict:
    cnpj_root = normalize_root(
        value
    )

    companies_file = (
        data_dir
        / "companies.parquet"
    )

    establishments_file = (
        data_dir
        / "establishments.parquet"
    )

    if not companies_file.exists():
        raise ReceitaDataFilesMissing(
            "companies.parquet "
            "não encontrado."
        )

    if not establishments_file.exists():
        raise ReceitaDataFilesMissing(
            "establishments.parquet "
            "não encontrado."
        )

    connection = duckdb.connect()

    try:
        company = (
            connection.execute(
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
                    str(
                        companies_file
                    ),
                    cnpj_root,
                ],
            )
            .fetchone()
        )

        if company is None:
            raise CompanyNotFound(
                "Empresa não encontrada "
                "na base local."
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
                    str(
                        establishments_file
                    ),
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
                        float(
                            company[4]
                        )
                        if company[4]
                        is not None
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

            primary_cnae = (
                establishment[8]
            )

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
