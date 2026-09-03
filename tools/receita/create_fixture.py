from pathlib import Path

import duckdb

ROOT = Path(__file__).resolve().parent
FIXTURES = ROOT / "fixtures"

FIXTURES.mkdir(parents=True, exist_ok=True)


def calculate_check_digits(base: str) -> str:
    weights_first = [
        5, 4, 3, 2,
        9, 8, 7, 6,
        5, 4, 3, 2,
    ]

    weights_second = [
        6, 5, 4, 3, 2,
        9, 8, 7, 6,
        5, 4, 3, 2,
    ]

    def calculate_digit(
        value: str,
        weights: list[int],
    ) -> str:
        total = sum(
            int(character) * weight
            for character, weight in zip(
                value,
                weights,
            )
        )

        remainder = total % 11

        if remainder < 2:
            return "0"

        return str(11 - remainder)

    first = calculate_digit(
        base,
        weights_first,
    )

    second = calculate_digit(
        base + first,
        weights_second,
    )

    return first + second


connection = duckdb.connect()

connection.execute(
    """
    CREATE TABLE companies (
        cnpj_root VARCHAR,
        corporate_name VARCHAR,
        legal_nature_code VARCHAR,
        legal_nature_description VARCHAR,
        share_capital DECIMAL(18, 2),
        size_code VARCHAR,
        size_description VARCHAR
    )
    """
)

connection.execute(
    """
    INSERT INTO companies VALUES (
        '11222333',
        'COOPERATIVA AGRO TESTE',
        '2143',
        'COOPERATIVA',
        5000000.00,
        '05',
        'DEMAIS'
    )
    """
)

connection.execute(
    """
    CREATE TABLE establishments (
        cnpj_root VARCHAR,
        order_number VARCHAR,
        check_digits VARCHAR,
        type VARCHAR,
        fantasy_name VARCHAR,
        registration_status VARCHAR,
        state VARCHAR,
        municipality_name VARCHAR,
        primary_cnae VARCHAR,
        secondary_cnaes VARCHAR
    )
    """
)

rows = []

for order, establishment_type, city, state in [
    (
        "0001",
        "matrix",
        "SORRISO",
        "MT",
    ),
    (
        "0002",
        "branch",
        "RIO VERDE",
        "GO",
    ),
    (
        "0003",
        "branch",
        "DOURADOS",
        "MS",
    ),
]:
    base = f"11222333{order}"

    rows.append(
        (
            "11222333",
            order,
            calculate_check_digits(base),
            establishment_type,
            (
                "AGRO TESTE"
                if establishment_type == "matrix"
                else None
            ),
            "ATIVA",
            state,
            city,
            "4622200",
            "0115600,0111302",
        )
    )

connection.executemany(
    """
    INSERT INTO establishments
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    """,
    rows,
)

companies_file = (
    FIXTURES / "companies.parquet"
)

establishments_file = (
    FIXTURES / "establishments.parquet"
)

connection.execute(
    f"""
    COPY companies
    TO '{companies_file}'
    (FORMAT PARQUET)
    """
)

connection.execute(
    f"""
    COPY establishments
    TO '{establishments_file}'
    (FORMAT PARQUET)
    """
)

print(
    "Fixture criada:",
    FIXTURES,
)

print(
    "Empresas:",
    connection
        .execute(
            "SELECT COUNT(*) FROM companies"
        )
        .fetchone()[0],
)

print(
    "Estabelecimentos:",
    connection
        .execute(
            "SELECT COUNT(*) FROM establishments"
        )
        .fetchone()[0],
)
