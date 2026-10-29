import logging
import os
import time
from pathlib import Path

import duckdb
from fastapi import FastAPI, HTTPException

from group_query import (
    CompanyNotFound,
    ReceitaDataFilesMissing,
    query_group,
)
from prospect_query import (
    DEFAULT_PRIORITY_CNAES,
    DEFAULT_PRIORITY_STATES,
    query_prospects,
)
from release_manifest import (
    ReleaseValidationError,
    validate_release,
)


DATA_DIR = Path(
    os.environ.get(
        "RECEITA_DATA_DIR",
        "/real-data/processed/active",
    )
)

logger = logging.getLogger("receita.api")

app = FastAPI(
    title="Prospector Receita Data",
    version="0.3.0",
)


def safe_dataset_error(exception: ReleaseValidationError) -> HTTPException:
    return HTTPException(
        status_code=503,
        detail={
            "code": exception.code,
            "message": "Dataset da Receita indisponível ou inválido.",
        },
    )


def dataset_info(manifest: dict) -> dict:
    tables = manifest["tables"]
    return {
        "available": True,
        "mode": manifest["mode"],
        "period": manifest["period"],
        "dataset_version": manifest["dataset_version"],
        "companies_parts": len(tables["companies"]["files"]),
        "establishments_parts": len(
            tables["establishments"]["files"]
        ),
    }


def active_dataset_snapshot() -> Path:
    """Fixa a competencia desta requisicao, mesmo que active mude depois.

    O diretorio de publicacao e imutavel; o ponteiro active pode trocar
    atomicamente durante uma leitura. Nunca retorna o caminho ao cliente.
    """
    try:
        return DATA_DIR.resolve(strict=True)
    except (OSError, RuntimeError) as exception:
        raise ReleaseValidationError("dataset_unavailable") from exception


@app.get("/health")
def health() -> dict:
    try:
        manifest = validate_release(
            active_dataset_snapshot(),
            verify_samples=True,
        )
    except ReleaseValidationError as exception:
        raise safe_dataset_error(exception) from exception

    return {
        "status": "ok",
        "duckdb": duckdb.__version__,
        **dataset_info(manifest),
    }


@app.get("/groups/{cnpj_root}")
def get_group(cnpj_root: str) -> dict:
    try:
        release_dir = active_dataset_snapshot()
        validate_release(release_dir)
        return query_group(cnpj_root, release_dir)
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
    except (ReceitaDataFilesMissing, ReleaseValidationError) as exception:
        if isinstance(exception, ReleaseValidationError):
            raise safe_dataset_error(exception) from exception
        raise HTTPException(
            status_code=503,
            detail="Dataset da Receita indisponível.",
        ) from exception


def csv_values(value: str) -> list[str]:
    return [
        item.strip()
        for item in value.split(",")
        if item.strip()
    ]


@app.get("/prospects")
def get_prospects(
    limit: int = 100,
    offset: int = 0,
    states: str = ",".join(DEFAULT_PRIORITY_STATES),
    cnaes: str = ",".join(DEFAULT_PRIORITY_CNAES),
    min_capital: float | None = None,
    size_codes: str = "",
    dataset_version: str | None = None,
) -> dict:
    started_at = time.perf_counter()
    result: dict | None = None
    try:
        result = query_prospects(
            data_dir=active_dataset_snapshot(),
            states=csv_values(states),
            cnaes=csv_values(cnaes),
            limit=limit,
            offset=offset,
            min_capital=min_capital,
            size_codes=csv_values(size_codes),
            expected_dataset_version=dataset_version,
        )
    except ValueError as exception:
        raise HTTPException(
            status_code=422,
            detail=str(exception),
        ) from exception
    except ReleaseValidationError as exception:
        if exception.code == "dataset_version_changed":
            raise HTTPException(
                status_code=409,
                detail={
                    "code": exception.code,
                    "message": "A competência ativa mudou durante a paginação.",
                },
            ) from exception
        raise safe_dataset_error(exception) from exception
    except ReceitaDataFilesMissing as exception:
        raise HTTPException(
            status_code=503,
            detail="Dataset da Receita indisponível.",
        ) from exception
    finally:
        elapsed_ms = round(
            (time.perf_counter() - started_at) * 1000,
            2,
        )
        logger.info(
            "receita_query query=prospects dataset_version=%s "
            "rows_scanned=%s rows_returned=%s cursor=%s elapsed_ms=%s",
            result["dataset_version"]
            if result is not None
            else "unavailable",
            result["rows_scanned"]
            if result is not None
            else None,
            result["count"]
            if result is not None
            else 0,
            max(0, offset),
            elapsed_ms,
        )

    return result
