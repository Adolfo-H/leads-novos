#!/usr/bin/env python3

import argparse
import json
import sys

from rfb_catalog import (
    RfbCatalogClient,
    RfbCatalogError,
)


def main() -> int:
    parser = argparse.ArgumentParser(
        description=(
            "Inspeciona as versões disponíveis "
            "dos Dados Abertos do CNPJ."
        )
    )

    parser.add_argument(
        "--period",
        default=None,
        help=(
            "Período YYYY-MM. "
            "Se omitido, usa o mais recente."
        ),
    )

    parser.add_argument(
        "--periods",
        action="store_true",
        help="Lista somente os períodos disponíveis.",
    )

    args = parser.parse_args()

    client = RfbCatalogClient()

    try:
        if args.periods:
            print(
                json.dumps(
                    {
                        "periods":
                            client.periods(),
                    },
                    ensure_ascii=False,
                )
            )

            return 0

        manifest = client.manifest(
            args.period
        )

    except (
        RfbCatalogError,
        ValueError,
    ) as exception:
        print(
            json.dumps(
                {
                    "error":
                        str(exception),
                },
                ensure_ascii=False,
            )
        )

        return 1

    print(
        json.dumps(
            manifest,
            ensure_ascii=False,
        )
    )

    return 0


if __name__ == "__main__":
    sys.exit(
        main()
    )
