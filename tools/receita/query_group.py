#!/usr/bin/env python3

import argparse
import json
import sys
from pathlib import Path

from group_query import (
    CompanyNotFound,
    ReceitaDataFilesMissing,
    query_group,
)


ROOT = Path(__file__).resolve().parent


def main() -> int:
    parser = argparse.ArgumentParser()

    parser.add_argument(
        "cnpj_root",
        help="Raiz ou CNPJ completo",
    )

    parser.add_argument(
        "--data-dir",
        default=str(
            ROOT / "fixtures"
        ),
    )

    args = parser.parse_args()

    try:
        result = query_group(
            args.cnpj_root,
            Path(args.data_dir),
        )
    except ValueError as exception:
        print(
            json.dumps(
                {
                    "error":
                        str(exception),
                },
                ensure_ascii=False,
            )
        )

        return 2

    except ReceitaDataFilesMissing as exception:
        print(
            json.dumps(
                {
                    "error":
                        str(exception),
                },
                ensure_ascii=False,
            )
        )

        return 3

    except CompanyNotFound as exception:
        print(
            json.dumps(
                {
                    "error":
                        str(exception),

                    "cnpj_root":
                        args.cnpj_root,
                },
                ensure_ascii=False,
            )
        )

        return 4

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
