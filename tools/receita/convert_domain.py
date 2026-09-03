#!/usr/bin/env python3

import argparse
import csv
import io
import sys
import tempfile
from pathlib import Path
from zipfile import BadZipFile, ZipFile

import duckdb


DOMAIN_FILES = {
    "cnaes": "Cnaes.zip",
    "municipios": "Municipios.zip",
    "naturezas": "Naturezas.zip",
    "qualificacoes": "Qualificacoes.zip",
    "motivos": "Motivos.zip",
    "paises": "Paises.zip",
}


def convert_latin1_csv_to_utf8(
    zip_path: Path,
    output_path: Path,
) -> int:
    if not zip_path.exists():
        raise FileNotFoundError(
            f"Arquivo não encontrado: {zip_path}"
        )

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
                "Era esperado exatamente um arquivo "
                f"dentro de {zip_path.name}, "
                f"mas foram encontrados {len(members)}."
            )

        member = members[0]

        output_path.parent.mkdir(
            parents=True,
            exist_ok=True,
        )

        rows = 0

        with archive.open(
            member
        ) as source:
            text_source = io.TextIOWrapper(
                source,
                encoding="latin-1",
                newline="",
            )

            reader = csv.reader(
                text_source,
                delimiter=";",
                quotechar='"',
            )

            with output_path.open(
                "w",
                encoding="utf-8",
                newline="",
            ) as destination:
                writer = csv.writer(
                    destination,
                    delimiter=";",
                    quotechar='"',
                    quoting=csv.QUOTE_ALL,
                )

                for row in reader:
                    if not row:
                        continue

                    if len(row) != 2:
                        raise RuntimeError(
                            "Linha inválida no domínio "
                            f"{zip_path.name}: "
                            f"esperadas 2 colunas, "
                            f"recebidas {len(row)}."
                        )

                    writer.writerow(
                        row
                    )

                    rows += 1

        return rows


def convert_domain(
    domain: str,
    period: str,
    raw_root: Path,
    processed_root: Path,
) -> Path:
    if domain not in DOMAIN_FILES:
        raise ValueError(
            f"Domínio não suportado: {domain}"
        )

    zip_name = DOMAIN_FILES[
        domain
    ]

    zip_path = (
        raw_root
        / period
        / zip_name
    )

    output_dir = (
        processed_root
        / period
        / "domains"
    )

    output_dir.mkdir(
        parents=True,
        exist_ok=True,
    )

    final_path = (
        output_dir
        / f"{domain}.parquet"
    )

    temporary_parquet = (
        output_dir
        / f".{domain}.parquet.tmp"
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
        prefix="receita-domain-"
    ) as temp_dir:
        utf8_csv = (
            Path(temp_dir)
            / f"{domain}.csv"
        )

        rows = (
            convert_latin1_csv_to_utf8(
                zip_path,
                utf8_csv,
            )
        )

        print(
            "[rows-csv]",
            rows,
        )

        connection = (
            duckdb.connect()
        )

        try:
            connection.execute(
                """
                CREATE TABLE domain AS
                SELECT
                    column0::VARCHAR
                        AS code,
                    column1::VARCHAR
                        AS description
                FROM read_csv(
                    ?,
                    delim = ';',
                    header = false,
                    all_varchar = true,
                    quote = '"'
                )
                """,
                [
                    str(
                        utf8_csv
                    ),
                ],
            )

            count = (
                connection.execute(
                    """
                    SELECT COUNT(*)
                    FROM domain
                    """
                )
                .fetchone()[0]
            )

            if count != rows:
                raise RuntimeError(
                    "Quantidade de registros divergente: "
                    f"CSV={rows}, DuckDB={count}."
                )

            if count == 0:
                raise RuntimeError(
                    "Nenhum registro foi encontrado."
                )

            connection.execute(
                """
                COPY domain
                TO ?
                (
                    FORMAT PARQUET,
                    COMPRESSION ZSTD
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
        domain,
    )

    print(
        "[rows]",
        rows,
    )

    print(
        "[path]",
        final_path,
    )

    return final_path


def main() -> int:
    parser = argparse.ArgumentParser(
        description=(
            "Converte domínios dos Dados "
            "Abertos do CNPJ para Parquet."
        )
    )

    parser.add_argument(
        "domain",
        choices=sorted(
            DOMAIN_FILES.keys()
        ),
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

    args = parser.parse_args()

    try:
        convert_domain(
            args.domain,
            args.period,
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
