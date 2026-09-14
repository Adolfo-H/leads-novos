from pathlib import Path
import re

import duckdb

from group_query import (
    ReceitaDataFilesMissing,
)


DEFAULT_PRIORITY_STATES = [
    "MA",
    "SP",
    "PA",
    "RO",
    "MT",
    "MS",
    "TO",
    "GO",
    "MG",
]


DEFAULT_PRIORITY_CNAES = [
    "4622200",
    "4632001",
    "0115600",
    "0111302",
    "1071600",
]


PRIORITY_LEGAL_NATURES = [
    "2046",
    "2054",
    "2143",
]


def normalize_states(
    values: list[str],
) -> list[str]:
    result = []

    for value in values:
        state = value.strip().upper()

        if not re.fullmatch(
            r"[A-Z]{2}",
            state,
        ):
            continue

        if state not in result:
            result.append(state)

    return result


def normalize_cnaes(
    values: list[str],
) -> list[str]:
    result = []

    for value in values:
        code = re.sub(
            r"\D",
            "",
            value,
        )

        if len(code) != 7:
            continue

        if code not in result:
            result.append(code)

    return result


def normalize_size_codes(
    values: list[str],
) -> list[str]:
    result = []

    for value in values:
        code = re.sub(
            r"\D",
            "",
            value,
        )

        if len(code) != 2:
            continue

        if code not in result:
            result.append(code)

    return result


def query_prospects(
    data_dir: Path,
    states: list[str] | None = None,
    cnaes: list[str] | None = None,
    limit: int = 100,
    offset: int = 0,
    min_capital: float | None = None,
    size_codes: list[str] | None = None,
) -> dict:
    states = normalize_states(
        states
        or DEFAULT_PRIORITY_STATES
    )

    cnaes = normalize_cnaes(
        cnaes
        or DEFAULT_PRIORITY_CNAES
    )

    size_codes = normalize_size_codes(
        size_codes
        or []
    )

    limit = max(
        1,
        min(
            1000,
            int(limit),
        ),
    )

    offset = max(
        0,
        int(offset),
    )

    companies_dir = (
        data_dir
        / "companies"
    )

    establishments_dir = (
        data_dir
        / "establishments"
    )

    if not list(
        companies_dir.glob(
            "*.parquet"
        )
    ):
        raise ReceitaDataFilesMissing(
            "Parquets de empresas não encontrados."
        )

    if not list(
        establishments_dir.glob(
            "*.parquet"
        )
    ):
        raise ReceitaDataFilesMissing(
            "Parquets de estabelecimentos não encontrados."
        )

    if not states:
        raise ValueError(
            "Informe ao menos um estado."
        )

    if not cnaes:
        raise ValueError(
            "Informe ao menos um CNAE."
        )

    companies_glob = str(
        companies_dir
        / "*.parquet"
    )

    establishments_glob = str(
        establishments_dir
        / "*.parquet"
    )

    state_placeholders = ", ".join(
        ["?"] * len(states)
    )

    cnae_placeholders = ", ".join(
        ["?"] * len(cnaes)
    )

    secondary_conditions = []

    secondary_case = []

    for code in cnaes:
        condition = """
        (
            ','
            || REPLACE(
                COALESCE(
                    e.secondary_cnaes,
                    ''
                ),
                ' ',
                ''
            )
            || ','
        )
        LIKE
        '%,' || ? || ',%'
        """

        secondary_conditions.append(
            condition
        )

        secondary_case.append(
            f"""
            WHEN (
                ','
                || REPLACE(
                    COALESCE(
                        e.secondary_cnaes,
                        ''
                    ),
                    ' ',
                    ''
                )
                || ','
            )
            LIKE '%,{code},%'
            THEN '{code}'
            """
        )

    optional_conditions = []

    parameters: list[object] = [
        establishments_glob,
        companies_glob,
        *states,
        *cnaes,
        *cnaes,
    ]

    if (
        min_capital is not None
        and min_capital > 0
    ):
        optional_conditions.append(
            """
            COALESCE(
                c.share_capital,
                0
            ) >= ?
            """
        )

        parameters.append(
            float(
                min_capital
            )
        )

    if size_codes:
        placeholders = ", ".join(
            ["?"] * len(
                size_codes
            )
        )

        optional_conditions.append(
            f"""
            c.size_code IN (
                {placeholders}
            )
            """
        )

        parameters.extend(
            size_codes
        )

    optional_sql = ""

    if optional_conditions:
        optional_sql = (
            " AND "
            + " AND ".join(
                optional_conditions
            )
        )

    sql = f"""
        WITH active_group_stats AS (
            SELECT
                cnpj_root,

                COUNT(*) AS
                    active_establishments,

                COUNT(
                    DISTINCT state
                ) AS
                    active_states

            FROM read_parquet(?)

            WHERE
                registration_status_code
                    = '02'

            GROUP BY
                cnpj_root
        ),

        candidates AS (
            SELECT
                e.cnpj_root,
                e.cnpj,
                e.order_number,
                e.type,
                e.state,
                e.primary_cnae,
                e.secondary_cnaes,

                c.corporate_name,
                c.share_capital,
                c.size_code,
                c.legal_nature_code,

                CASE
                    WHEN e.primary_cnae IN (
                        {cnae_placeholders}
                    )
                    THEN e.primary_cnae

                    {" ".join(
                        secondary_case
                    )}

                    ELSE NULL
                END AS matched_cnae,

                CASE
                    WHEN e.primary_cnae IN (
                        {cnae_placeholders}
                    )
                    THEN 'primary'
                    ELSE 'secondary'
                END AS cnae_match_type,

                ROW_NUMBER() OVER (
                    PARTITION BY
                        e.cnpj_root

                    ORDER BY
                        CASE
                            WHEN e.primary_cnae
                                IN (
                                    {cnae_placeholders}
                                )
                            THEN 0
                            ELSE 1
                        END,

                        CASE
                            WHEN e.type
                                = 'matrix'
                            THEN 0
                            ELSE 1
                        END,

                        e.order_number
                ) AS root_rank

            FROM read_parquet(?) e

            INNER JOIN
                read_parquet(?) c
                ON c.cnpj_root
                    = e.cnpj_root

            WHERE
                e.registration_status_code
                    = '02'

                AND e.state IN (
                    {state_placeholders}
                )

                AND (
                    e.primary_cnae IN (
                        {cnae_placeholders}
                    )

                    OR (
                        {" OR ".join(
                            secondary_conditions
                        )}
                    )
                )

                {optional_sql}
        ),

        roots AS (
            SELECT
                c.*,

                COALESCE(
                    g.active_establishments,
                    1
                ) AS active_establishments,

                COALESCE(
                    g.active_states,
                    1
                ) AS active_states,

                (
                    CASE
                        WHEN c.cnae_match_type
                            = 'primary'
                        THEN 30
                        ELSE 20
                    END

                    +

                    15

                    +

                    CASE
                        WHEN c.size_code
                            = '05'
                        THEN 15
                        ELSE 0
                    END

                    +

                    CASE
                        WHEN COALESCE(
                            c.share_capital,
                            0
                        ) > 1000000
                        THEN 15
                        ELSE 0
                    END

                    +

                    CASE
                        WHEN c.legal_nature_code
                            IN (
                                '2046',
                                '2054',
                                '2143'
                            )
                        THEN 15
                        ELSE 0
                    END

                    +

                    CASE
                        WHEN COALESCE(
                            g.active_establishments,
                            1
                        ) > 1
                        THEN 10
                        ELSE 0
                    END

                ) AS discovery_score

            FROM candidates c

            LEFT JOIN
                active_group_stats g
                ON g.cnpj_root
                    = c.cnpj_root

            WHERE
                c.root_rank = 1
        ),

        diversified AS (
            SELECT
                *,

                ROW_NUMBER() OVER (
                    PARTITION BY
                        state,
                        matched_cnae

                    ORDER BY
                        discovery_score DESC,

                        share_capital DESC
                            NULLS LAST,

                        corporate_name
                ) AS bucket_rank

            FROM roots
        )

        SELECT
            cnpj_root,
            cnpj,
            corporate_name,
            state,
            matched_cnae,
            cnae_match_type,
            primary_cnae,
            share_capital,
            size_code,
            legal_nature_code,
            active_establishments,
            active_states,
            discovery_score

        FROM diversified

        ORDER BY
            bucket_rank,

            discovery_score DESC,

            active_establishments DESC,

            share_capital DESC
                NULLS LAST,

            state,

            matched_cnae,

            corporate_name

        LIMIT ?
        OFFSET ?
    """

    #
    # A query usa o parquet de estabelecimentos
    # duas vezes:
    #
    # 1. estatística do grupo
    # 2. seleção dos candidatos
    #
    parameters = [
        establishments_glob,

        # Primeiro IN usado pelo CASE
        *cnaes,

        # Segundo IN do tipo primary/secondary
        *cnaes,

        # Terceiro IN do root_rank
        *cnaes,

        establishments_glob,
        companies_glob,

        *states,

        # IN do WHERE
        *cnaes,

        # condições CNAEs secundários
        *cnaes,
    ]

    if (
        min_capital is not None
        and min_capital > 0
    ):
        parameters.append(
            float(
                min_capital
            )
        )

    if size_codes:
        parameters.extend(
            size_codes
        )

    parameters.extend(
        [
            limit,
            offset,
        ]
    )

    connection = duckdb.connect()

    try:
        rows = (
            connection.execute(
                sql,
                parameters,
            )
            .fetchall()
        )
    finally:
        connection.close()

    items = []

    for row in rows:
        items.append(
            {
                "cnpj_root":
                    row[0],

                "cnpj":
                    row[1],

                "corporate_name":
                    row[2],

                "state":
                    row[3],

                "matched_cnae":
                    row[4],

                "cnae_match_type":
                    row[5],

                "primary_cnae":
                    row[6],

                "share_capital":
                    (
                        float(row[7])
                        if row[7]
                        is not None
                        else None
                    ),

                "size_code":
                    row[8],

                "legal_nature_code":
                    row[9],

                "active_establishments":
                    int(row[10]),

                "active_states":
                    int(row[11]),

                "discovery_score":
                    int(row[12]),
            }
        )

    return {
        "items":
            items,

        "count":
            len(items),

        "limit":
            limit,

        "offset":
            offset,

        "ranking":
            "balanced_icp_v1",

        "filters": {
            "states":
                states,

            "cnaes":
                cnaes,

            "min_capital":
                min_capital,

            "size_codes":
                size_codes,

            "active_only":
                True,

            "diversified_by":
                [
                    "state",
                    "matched_cnae",
                ],
        },
    }
