import os
from pathlib import Path

import duckdb
from fastapi import FastAPI, HTTPException

from group_query import (
    CompanyNotFound,
    ReceitaDataFilesMissing,
    query_group,
)


DATA_DIR = Path(
    os.environ.get(
        "RECEITA_DATA_DIR",
        "/app/fixtures",
    )
)


app = FastAPI(
    title="Prospector Receita Data",
    version="0.2.0",
)


def dataset_info() -> dict:
    fixture = (
        (
            DATA_DIR
            / "companies.parquet"
        ).exists()

        and

        (
            DATA_DIR
            / "establishments.parquet"
        ).exists()
    )

    company_parts = list(
        (
            DATA_DIR
            / "companies"
        ).glob(
            "*.parquet"
        )
    )

    establishment_parts = list(
        (
            DATA_DIR
            / "establishments"
        ).glob(
            "*.parquet"
        )
    )

    domains = (
        DATA_DIR
        / "domains"
    )

    real = (
        bool(company_parts)
        and bool(
            establishment_parts
        )
        and (
            domains
            / "cnaes.parquet"
        ).exists()
        and (
            domains
            / "municipios.parquet"
        ).exists()
        and (
            domains
            / "naturezas.parquet"
        ).exists()
    )

    return {
        "available":
            fixture or real,

        "mode":
            (
                "real"
                if real
                else "fixture"
                if fixture
                else "unavailable"
            ),

        "period":
            (
                DATA_DIR.name
                if real
                else None
            ),

        "companies_parts":
            len(
                company_parts
            ),

        "establishments_parts":
            len(
                establishment_parts
            ),
    }


@app.get("/health")
def health() -> dict:
    dataset = dataset_info()

    if not dataset[
        "available"
    ]:
        raise HTTPException(
            status_code=503,
            detail=(
                "Dataset da Receita "
                "não está disponível."
            ),
        )

    return {
        "status":
            "ok",

        "duckdb":
            duckdb.__version__,

        "data_dir":
            str(
                DATA_DIR
            ),

        **dataset,
    }


@app.get("/groups/{cnpj_root}")
def get_group(
    cnpj_root: str,
) -> dict:
    try:
        return query_group(
            cnpj_root,
            DATA_DIR,
        )

    except ValueError as exception:
        raise HTTPException(
            status_code=422,
            detail=str(exception),
        ) from exception

    except CompanyNotFound as exception:
        raise HTTPException(
            status_code=404,
            detail=str(exception),
        ) from exception

    except ReceitaDataFilesMissing as exception:
        raise HTTPException(
            status_code=503,
            detail=str(exception),
        ) from exception
