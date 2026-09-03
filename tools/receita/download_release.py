#!/usr/bin/env python3

import argparse
import sys
import time
from pathlib import Path
from urllib.parse import quote

import requests

from rfb_catalog import (
    CNPJ_PATH,
    WEBDAV_BASE,
    RfbCatalogClient,
    RfbCatalogError,
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
        if size < 1024 or unit == units[-1]:
            return f"{size:.2f} {unit}"

        size /= 1024

    return f"{size:.2f} GiB"


def find_file(
    client: RfbCatalogClient,
    period: str,
    filename: str,
) -> dict:
    for item in client.files(period):
        if item["name"] == filename:
            return item

    raise RuntimeError(
        f"Arquivo {filename} não encontrado no período {period}."
    )


def download_file(
    client: RfbCatalogClient,
    period: str,
    filename: str,
    output_dir: Path,
    max_retries: int = 5,
) -> Path:
    info = find_file(
        client,
        period,
        filename,
    )

    expected_size = int(
        info["size"] or 0
    )

    output_dir.mkdir(
        parents=True,
        exist_ok=True,
    )

    target = (
        output_dir / filename
    )

    partial = (
        output_dir
        / f"{filename}.part"
    )

    if (
        target.exists()
        and (
            expected_size == 0
            or target.stat().st_size
            == expected_size
        )
    ):
        print(
            "[skip]",
            filename,
            human_size(
                target.stat().st_size
            ),
        )

        return target

    if target.exists():
        target.unlink()

    if (
        partial.exists()
        and expected_size > 0
        and partial.stat().st_size
        > expected_size
    ):
        partial.unlink()

    url = (
        f"{WEBDAV_BASE}/"
        f"{CNPJ_PATH}/"
        f"{period}/"
        f"{quote(filename)}"
    )

    print(
        f"[file] {filename}"
    )

    print(
        "[size]",
        human_size(expected_size)
        if expected_size
        else "desconhecido",
    )

    for attempt in range(
        1,
        max_retries + 1,
    ):
        already = (
            partial.stat().st_size
            if partial.exists()
            else 0
        )

        headers = {}

        if already > 0:
            headers["Range"] = (
                f"bytes={already}-"
            )

            print(
                "[resume]",
                human_size(already),
            )

        try:
            with client.session.get(
                url,
                headers=headers,
                stream=True,
                timeout=(20, 300),
            ) as response:
                if response.status_code == 206:
                    mode = "ab"

                elif response.status_code == 200:
                    mode = "wb"
                    already = 0

                else:
                    response.raise_for_status()

                    raise RuntimeError(
                        "Resposta HTTP inesperada: "
                        f"{response.status_code}"
                    )

                downloaded = already

                next_report = (
                    (
                        downloaded
                        // REPORT_STEP
                    )
                    + 1
                ) * REPORT_STEP

                with partial.open(
                    mode
                ) as output:
                    for chunk in (
                        response.iter_content(
                            chunk_size=CHUNK_SIZE
                        )
                    ):
                        if not chunk:
                            continue

                        output.write(
                            chunk
                        )

                        downloaded += len(
                            chunk
                        )

                        if (
                            downloaded
                            >= next_report
                        ):
                            if expected_size:
                                percentage = (
                                    downloaded
                                    / expected_size
                                    * 100
                                )

                                print(
                                    "[progress]",
                                    human_size(
                                        downloaded
                                    ),
                                    f"({percentage:.1f}%)",
                                )

                            else:
                                print(
                                    "[progress]",
                                    human_size(
                                        downloaded
                                    ),
                                )

                            next_report += (
                                REPORT_STEP
                            )

            actual_size = (
                partial.stat().st_size
            )

            if (
                expected_size > 0
                and actual_size
                != expected_size
            ):
                raise RuntimeError(
                    "Tamanho incompleto: "
                    f"{actual_size} "
                    f"de {expected_size} bytes."
                )

            partial.replace(
                target
            )

            print(
                "[done]",
                filename,
                human_size(
                    target.stat().st_size
                ),
            )

            return target

        except (
            requests.RequestException,
            OSError,
            RuntimeError,
        ) as exception:
            print(
                f"[retry {attempt}/{max_retries}]",
                str(exception),
                file=sys.stderr,
            )

            if attempt >= max_retries:
                raise

            time.sleep(
                min(
                    2**attempt,
                    30,
                )
            )

    raise RuntimeError(
        f"Falha ao baixar {filename}."
    )


def main() -> int:
    parser = argparse.ArgumentParser(
        description=(
            "Baixa arquivos dos Dados "
            "Abertos do CNPJ com resume."
        )
    )

    parser.add_argument(
        "--period",
        default=None,
        help=(
            "Período YYYY-MM. "
            "Por padrão usa o mais recente."
        ),
    )

    parser.add_argument(
        "--file",
        required=True,
        dest="filename",
        help=(
            "Nome exato do ZIP, "
            "ex.: Cnaes.zip"
        ),
    )

    parser.add_argument(
        "--output-dir",
        default="/real-data/raw",
    )

    args = parser.parse_args()

    client = RfbCatalogClient()

    try:
        period = (
            args.period
            or client.latest_period()
        )

        output_dir = (
            Path(args.output_dir)
            / period
        )

        file_path = download_file(
            client,
            period,
            args.filename,
            output_dir,
        )

    except (
        RfbCatalogError,
        RuntimeError,
        ValueError,
        requests.RequestException,
    ) as exception:
        print(
            "[error]",
            str(exception),
            file=sys.stderr,
        )

        return 1

    print(
        "[path]",
        file_path,
    )

    return 0


if __name__ == "__main__":
    sys.exit(
        main()
    )
