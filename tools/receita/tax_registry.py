"""Consulta local, somente leitura, da foto nacional Simples/MEI da RFB.

O registro contém APENAS empresas que devem ser bloqueadas (optantes ou
indicadores indeterminados). A ausência de uma raiz significa não optante
SOMENTE quando o arquivo completo da mesma competência foi processado.
"""
from __future__ import annotations

import os
from pathlib import Path
import re
import sqlite3

from release_manifest import ReleaseValidationError


def registry_path(period: str) -> Path:
    if not re.fullmatch(r"\d{4}-(0[1-9]|1[0-2])", period):
        raise ReleaseValidationError("tax_period_invalid")
    return Path(os.environ.get("RECEITA_TAX_REGISTRY_DIR", "/real-data/tax_registry")) / (period + ".sqlite3")


def connect(period: str) -> sqlite3.Connection:
    path = registry_path(period)
    if not path.is_file():
        raise ReleaseValidationError("tax_registry_missing")
    db = sqlite3.connect(path.as_uri() + "?mode=ro", uri=True, timeout=10)
    try:
        meta = dict(db.execute("SELECT key, value FROM metadata"))
        if meta.get("status") != "ready" or meta.get("period") != period:
            raise ReleaseValidationError("tax_registry_invalid")
        if int(meta.get("rows_read", "0")) < 30000000:
            raise ReleaseValidationError("tax_registry_incomplete")
    except (ValueError, sqlite3.DatabaseError):
        db.close()
        raise ReleaseValidationError("tax_registry_invalid") from None
    except Exception:
        db.close()
        raise
    return db


def statuses(db: sqlite3.Connection, roots: list[str]) -> dict[str, dict]:
    """Retorna todos os CNPJs solicitados; bloqueia S e desconhecidos."""
    roots = list(dict.fromkeys(r.upper().strip() for r in roots))
    if len(roots) > 500 or any(not re.fullmatch(r"[0-9A-Z]{8}", r) for r in roots):
        raise ValueError("Envie até 500 raízes de CNPJ válidas.")
    blocked: dict[str, tuple[str, str]] = {}
    for start in range(0, len(roots), 400):
        group = roots[start:start + 400]
        placeholders = ",".join("?" for _ in group)
        for root, simples, mei in db.execute(
            f"SELECT root, simples, mei FROM blocked_roots WHERE root IN ({placeholders})", group
        ):
            blocked[root] = (simples, mei)
    result = {}
    for root in roots:
        if root not in blocked:
            result[root] = {
                "regime": "nao_optante", "simples_option": False, "mei_option": False,
            }
            continue
        simples, mei = blocked[root]
        if mei == "S":
            regime = "mei"
        elif simples == "S":
            regime = "simples"
        else:
            regime = "desconhecido"
        result[root] = {
            "regime": regime,
            "simples_option": (simples == "S" if simples in ("S", "N") else None),
            "mei_option": (mei == "S" if mei in ("S", "N") else None),
        }
    return result
