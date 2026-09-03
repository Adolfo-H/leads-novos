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
    version="0.1.0",
)


@app.get("/health")
def health() -> dict:
    companies = (
        DATA_DIR / "companies.parquet"
    )

    establishments = (
        DATA_DIR
        / "establishments.parquet"
    )

    return {
        "status": "ok",
        "duckdb": duckdb.__version__,
        "data_dir": str(DATA_DIR),
        "companies_available":
            companies.exists(),
        "establishments_available":
            establishments.exists(),
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
