#!/usr/bin/env python3

import argparse
import re
import sys
import tempfile
from pathlib import Path
from zipfile import BadZipFile, ZipFile

import duckdb


FILE_PATTERN = re.compile(
    r"^Estabelecimentos(\d+)\.zip$",
    re.IGNORECASE,
)

CHUNK_SIZE = 8 * 1024 * 1024
REPORT_STEP = 256 * 1024 * 1024


def human_size(value: int) -> str:
    units = [
        "B",
        "KiB",
        "MiB",
        "GiB",
    ]

    size = float(value)

    for unit in units:
        if (
            size < 1024
            or unit == units[-1]
        ):
            return f"{size:.2f} {unit}"

        size /= 1024

    return f"{size:.2f} GiB"


def transcode_zip_member(
    zip_path: Path,
    target: Path,
) -> None:
    try:
        archive = ZipFile(
            zip_path
        )
    except BadZipFile as exception:
        raise RuntimeError(
            f"ZIP inválido: {zip_path}"
        ) from exception

    with archive:
        members = [
            item
            for item in archive.infolist()
            if not item.is_dir()
        ]

        if len(members) != 1:
            raise RuntimeError(
                "Era esperado exatamente um "
                f"arquivo dentro de {zip_path.name}."
            )

        member = members[0]

        print(
            "[member]",
            member.filename,
        )

        print(
            "[uncompressed]",
            human_size(
                member.file_size
            ),
        )

        processed = 0
        next_report = REPORT_STEP

        with archive.open(
            member
        ) as source:
            with target.open(
                "w",
                encoding="utf-8",
                newline="",
            ) as destination:
                while True:
                    chunk = source.read(
                        CHUNK_SIZE
                    )

                    if not chunk:
                        break

                    destination.write(
                        chunk.decode(
                            "latin-1"
                        )
                    )

                    processed += len(
                        chunk
                    )

                    if processed >= next_report:
                        print(
                            "[transcode]",
                            human_size(
                                processed
                            ),
                        )

                        next_report += (
                            REPORT_STEP
                        )


def convert_establishments(
    period: str,
    filename: str,
    raw_root: Path,
    processed_root: Path,
) -> Path:
    match = FILE_PATTERN.fullmatch(
        filename
    )

    if not match:
        raise ValueError(
            "O arquivo deve seguir o padrão "
            "EstabelecimentosN.zip."
        )

    part = int(
        match.group(1)
    )

    zip_path = (
        raw_root
        / period
        / filename
    )

    if not zip_path.exists():
        raise FileNotFoundError(
            f"Arquivo não encontrado: {zip_path}"
        )

    output_dir = (
        processed_root
        / period
        / "establishments"
    )

    quality_dir = (
        processed_root
        / period
        / "quality"
    )

    output_dir.mkdir(
        parents=True,
        exist_ok=True,
    )

    quality_dir.mkdir(
        parents=True,
        exist_ok=True,
    )

    final_path = (
        output_dir
        / f"part-{part:02d}.parquet"
    )

    temporary_parquet = (
        output_dir
        / f".part-{part:02d}.parquet.tmp"
    )

    duplicates_path = (
        quality_dir
        / (
            "establishment-duplicates-"
            f"part-{part:02d}.parquet"
        )
    )

    if temporary_parquet.exists():
        temporary_parquet.unlink()

    print(
        "[source]",
        zip_path,
    )

    print(
        "[target]",
        final_path,
    )

    with tempfile.TemporaryDirectory(
        prefix="receita-establishments-"
    ) as temporary_directory:
        csv_path = (
            Path(
                temporary_directory
            )
            / "establishments.csv"
        )

        transcode_zip_member(
            zip_path,
            csv_path,
        )

        connection = duckdb.connect()

        try:
            connection.execute(
                """
                CREATE TABLE raw AS
                SELECT *
                FROM read_csv(
                    ?,
                    delim = ';',
                    header = false,
                    all_varchar = true,
                    quote = '"',
                    escape = '"'
                )
                """,
                [
                    str(
                        csv_path
                    ),
                ],
            )

            columns = (
                connection.execute(
                    """
                    DESCRIBE raw
                    """
                )
                .fetchall()
            )

            if len(columns) != 30:
                raise RuntimeError(
                    "Layout inesperado em "
                    f"{filename}: "
                    f"{len(columns)} colunas."
                )

            raw_count = (
                connection.execute(
                    """
                    SELECT COUNT(*)
                    FROM raw
                    """
                )
                .fetchone()[0]
            )

            print(
                "[rows-raw]",
                raw_count,
            )

            connection.execute(
                """
                CREATE TABLE parsed_establishments AS
                SELECT
                    row_number() OVER ()
                        AS source_row,

                    upper(
                        trim(column00)
                    ) AS cnpj_root,

                    upper(
                        trim(column01)
                    ) AS order_number,

                    trim(
                        column02
                    ) AS check_digits,

                    upper(
                        trim(column00)
                        || trim(column01)
                        || trim(column02)
                    ) AS cnpj,

                    CASE
                        WHEN trim(column03) = '1'
                        THEN 'matrix'

                        WHEN trim(column03) = '2'
                        THEN 'branch'

                        ELSE NULL
                    END AS type,

                    nullif(
                        trim(column04),
                        ''
                    ) AS fantasy_name,

                    nullif(
                        trim(column05),
                        ''
                    ) AS registration_status_code,

                    try_strptime(
                        nullif(
                            trim(column06),
                            ''
                        ),
                        '%Y%m%d'
                    )::DATE
                        AS registration_status_date,

                    nullif(
                        trim(column07),
                        ''
                    ) AS registration_status_reason_code,

                    nullif(
                        trim(column08),
                        ''
                    ) AS foreign_city_name,

                    nullif(
                        trim(column09),
                        ''
                    ) AS country_code,

                    try_strptime(
                        nullif(
                            trim(column10),
                            ''
                        ),
                        '%Y%m%d'
                    )::DATE
                        AS start_date,

                    nullif(
                        trim(column11),
                        ''
                    ) AS primary_cnae,

                    nullif(
                        trim(column12),
                        ''
                    ) AS secondary_cnaes,

                    nullif(
                        trim(column13),
                        ''
                    ) AS address_type,

                    nullif(
                        trim(column14),
                        ''
                    ) AS street,

                    nullif(
                        trim(column15),
                        ''
                    ) AS number,

                    nullif(
                        trim(column16),
                        ''
                    ) AS complement,

                    nullif(
                        trim(column17),
                        ''
                    ) AS neighborhood,

                    nullif(
                        trim(column18),
                        ''
                    ) AS zip_code,

                    nullif(
                        upper(
                            trim(column19)
                        ),
                        ''
                    ) AS state,

                    nullif(
                        trim(column20),
                        ''
                    ) AS municipality_code,

                    nullif(
                        trim(column21),
                        ''
                    ) AS phone_1_ddd,

                    nullif(
                        trim(column22),
                        ''
                    ) AS phone_1_number,

                    nullif(
                        (
                            coalesce(
                                nullif(
                                    trim(column21),
                                    ''
                                ),
                                ''
                            )

                            ||

                            coalesce(
                                nullif(
                                    trim(column22),
                                    ''
                                ),
                                ''
                            )
                        ),
                        ''
                    ) AS phone_1,

                    nullif(
                        trim(column23),
                        ''
                    ) AS phone_2_ddd,

                    nullif(
                        trim(column24),
                        ''
                    ) AS phone_2_number,

                    nullif(
                        (
                            coalesce(
                                nullif(
                                    trim(column23),
                                    ''
                                ),
                                ''
                            )

                            ||

                            coalesce(
                                nullif(
                                    trim(column24),
                                    ''
                                ),
                                ''
                            )
                        ),
                        ''
                    ) AS phone_2,

                    nullif(
                        trim(column25),
                        ''
                    ) AS fax_ddd,

                    nullif(
                        trim(column26),
                        ''
                    ) AS fax_number,

                    nullif(
                        (
                            coalesce(
                                nullif(
                                    trim(column25),
                                    ''
                                ),
                                ''
                            )

                            ||

                            coalesce(
                                nullif(
                                    trim(column26),
                                    ''
                                ),
                                ''
                            )
                        ),
                        ''
                    ) AS fax,

                    nullif(
                        lower(
                            trim(column27)
                        ),
                        ''
                    ) AS email,

                    nullif(
                        trim(column28),
                        ''
                    ) AS special_situation,

                    try_strptime(
                        nullif(
                            trim(column29),
                            ''
                        ),
                        '%Y%m%d'
                    )::DATE
                        AS special_situation_date

                FROM raw
                """
            )

            invalid_identity = (
                connection.execute(
                    """
                    SELECT COUNT(*)
                    FROM parsed_establishments

                    WHERE
                        length(cnpj_root) <> 8

                        OR NOT regexp_full_match(
                            cnpj_root,
                            '[0-9A-Z]{8}'
                        )

                        OR length(order_number) <> 4

                        OR NOT regexp_full_match(
                            order_number,
                            '[0-9A-Z]{4}'
                        )

                        OR length(check_digits) <> 2

                        OR NOT regexp_full_match(
                            check_digits,
                            '[0-9]{2}'
                        )

                        OR length(cnpj) <> 14
                    """
                )
                .fetchone()[0]
            )

            if invalid_identity:
                raise RuntimeError(
                    "Foram encontrados "
                    f"{invalid_identity} "
                    "estabelecimentos com "
                    "identidade CNPJ inválida."
                )

            invalid_type = (
                connection.execute(
                    """
                    SELECT COUNT(*)
                    FROM parsed_establishments
                    WHERE type IS NULL
                    """
                )
                .fetchone()[0]
            )

            if invalid_type:
                raise RuntimeError(
                    "Foram encontrados "
                    f"{invalid_type} registros "
                    "sem tipo matriz/filial válido."
                )

            connection.execute(
                """
                CREATE TABLE scored_establishments AS
                SELECT
                    *,

                    (
                        CASE
                            WHEN type IS NOT NULL
                            THEN 100
                            ELSE 0
                        END

                        +

                        CASE
                            WHEN registration_status_code
                                IS NOT NULL
                            THEN 20
                            ELSE 0
                        END

                        +

                        CASE
                            WHEN primary_cnae IS NOT NULL
                            THEN 20
                            ELSE 0
                        END

                        +

                        CASE
                            WHEN start_date IS NOT NULL
                            THEN 10
                            ELSE 0
                        END

                        +

                        CASE
                            WHEN state IS NOT NULL
                            THEN 10
                            ELSE 0
                        END

                        +

                        CASE
                            WHEN municipality_code
                                IS NOT NULL
                            THEN 10
                            ELSE 0
                        END

                        +

                        CASE
                            WHEN street IS NOT NULL
                            THEN 5
                            ELSE 0
                        END

                        +

                        CASE
                            WHEN phone_1 IS NOT NULL
                            THEN 3
                            ELSE 0
                        END

                        +

                        CASE
                            WHEN email IS NOT NULL
                            THEN 3
                            ELSE 0
                        END

                        +

                        CASE
                            WHEN fantasy_name IS NOT NULL
                            THEN 1
                            ELSE 0
                        END
                    ) AS quality_score

                FROM parsed_establishments
                """
            )

            connection.execute(
                """
                CREATE TABLE ranked_establishments AS
                SELECT
                    *,

                    row_number() OVER (
                        PARTITION BY cnpj
                        ORDER BY
                            quality_score DESC,
                            source_row ASC
                    ) AS duplicate_rank

                FROM scored_establishments
                """
            )

            duplicate_cnpjs = (
                connection.execute(
                    """
                    SELECT COUNT(*)
                    FROM (
                        SELECT
                            cnpj

                        FROM parsed_establishments

                        GROUP BY cnpj

                        HAVING COUNT(*) > 1
                    )
                    """
                )
                .fetchone()[0]
            )

            if duplicate_cnpjs:
                if duplicates_path.exists():
                    duplicates_path.unlink()

                connection.execute(
                    """
                    COPY (
                        SELECT
                            *,
                            duplicate_rank = 1
                                AS selected

                        FROM ranked_establishments

                        WHERE cnpj IN (
                            SELECT
                                cnpj

                            FROM parsed_establishments

                            GROUP BY cnpj

                            HAVING COUNT(*) > 1
                        )

                        ORDER BY
                            cnpj,
                            duplicate_rank
                    )
                    TO ?
                    (
                        FORMAT PARQUET,
                        COMPRESSION ZSTD
                    )
                    """,
                    [
                        str(
                            duplicates_path
                        ),
                    ],
                )

                print(
                    "[duplicates]",
                    duplicate_cnpjs,
                )

                print(
                    "[quarantine]",
                    duplicates_path,
                )

            elif duplicates_path.exists():
                duplicates_path.unlink()

            connection.execute(
                """
                CREATE TABLE establishments AS
                SELECT
                    cnpj_root,
                    order_number,
                    check_digits,
                    cnpj,
                    type,
                    fantasy_name,
                    registration_status_code,
                    registration_status_date,
                    registration_status_reason_code,
                    foreign_city_name,
                    country_code,
                    start_date,
                    primary_cnae,
                    secondary_cnaes,
                    address_type,
                    street,
                    number,
                    complement,
                    neighborhood,
                    zip_code,
                    state,
                    municipality_code,
                    phone_1_ddd,
                    phone_1_number,
                    phone_1,
                    phone_2_ddd,
                    phone_2_number,
                    phone_2,
                    fax_ddd,
                    fax_number,
                    fax,
                    email,
                    special_situation,
                    special_situation_date

                FROM ranked_establishments

                WHERE duplicate_rank = 1
                """
            )

            unique_count = (
                connection.execute(
                    """
                    SELECT COUNT(
                        DISTINCT cnpj
                    )
                    FROM parsed_establishments
                    """
                )
                .fetchone()[0]
            )

            output_count = (
                connection.execute(
                    """
                    SELECT COUNT(*)
                    FROM establishments
                    """
                )
                .fetchone()[0]
            )

            if output_count != unique_count:
                raise RuntimeError(
                    "Contagem divergente após "
                    "deduplicação: "
                    f"esperado={unique_count}, "
                    f"saida={output_count}."
                )

            print(
                "[rows-output]",
                output_count,
            )

            print(
                "[rows-deduplicated]",
                raw_count
                - unique_count,
            )

            connection.execute(
                """
                COPY establishments
                TO ?
                (
                    FORMAT PARQUET,
                    COMPRESSION ZSTD,
                    ROW_GROUP_SIZE 100000
                )
                """,
                [
                    str(
                        temporary_parquet
                    ),
                ],
            )

        finally:
            connection.close()

    temporary_parquet.replace(
        final_path
    )

    print(
        "[done]",
        final_path,
    )

    print(
        "[size]",
        human_size(
            final_path.stat().st_size
        ),
    )

    return final_path


def main() -> int:
    parser = argparse.ArgumentParser(
        description=(
            "Converte EstabelecimentosN.zip "
            "da Receita para Parquet."
        )
    )

    parser.add_argument(
        "--period",
        required=True,
    )

    parser.add_argument(
        "--file",
        required=True,
        dest="filename",
    )

    parser.add_argument(
        "--raw-root",
        default="/real-data/raw",
    )

    parser.add_argument(
        "--processed-root",
        default="/real-data/processed",
    )

    args = parser.parse_args()

    try:
        convert_establishments(
            args.period,
            args.filename,
            Path(args.raw_root),
            Path(args.processed_root),
        )

    except (
        FileNotFoundError,
        RuntimeError,
        ValueError,
    ) as exception:
        print(
            "[error]",
            str(exception),
            file=sys.stderr,
        )

        return 1

    return 0


if __name__ == "__main__":
    sys.exit(
        main()
    )
