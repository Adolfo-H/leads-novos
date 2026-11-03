#!/usr/bin/env python3
"""Importa Simples.zip oficial para SQLite indexado, fora da release imutável.

Executar explicitamente 1x por competência. Nenhum download/ativação automática.
Não carrega o CSV completo em memória e não guarda senhas.
"""
from __future__ import annotations

import argparse
import csv
from datetime import datetime, timezone
import io
import os
from pathlib import Path
import re
import sqlite3
import sys
import uuid
from zipfile import ZipFile

from tax_registry import registry_path, connect


def build(source_zip: Path, period: str, min_rows: int = 30000000, replace: bool = False) -> tuple[int, int, Path]:
    target = registry_path(period)
    if not source_zip.is_file():
        raise ValueError("Simples.zip não encontrado. Baixe o arquivo da mesma competência da Receita.")
    if target.exists() and not replace:
        raise ValueError("Já existe base fiscal dessa competência. Use --replace somente após conferência.")
    target.parent.mkdir(parents=True, exist_ok=True)
    temp = target.with_name(f".{period}.{uuid.uuid4().hex}.tmp.sqlite3")
    rows, blocked = 0, 0
    connection = None
    try:
        connection = sqlite3.connect(temp)
        connection.execute("PRAGMA journal_mode=DELETE")
        connection.execute("PRAGMA synchronous=NORMAL")
        connection.execute("CREATE TABLE metadata(key TEXT PRIMARY KEY, value TEXT NOT NULL)")
        connection.execute("CREATE TABLE blocked_roots(root TEXT PRIMARY KEY, simples TEXT NOT NULL, mei TEXT NOT NULL) WITHOUT ROWID")
        with ZipFile(source_zip) as archive:
            entries = [n for n in archive.namelist() if not n.endswith("/")]
            if len(entries) != 1:
                raise ValueError("O ZIP Simples deve possuir exatamente um arquivo CSV.")
            with archive.open(entries[0]) as binary:
                with io.TextIOWrapper(binary, encoding="latin-1", newline="") as stream:
                    reader = csv.reader(stream, delimiter=";", quotechar='"')
                    batch = []
                    for fields in reader:
                        rows += 1
                        if len(fields) != 7:
                            raise ValueError(f"Linha {rows}: esperado layout Simples de 7 colunas.")
                        root = fields[0].strip().upper()
                        simples = fields[1].strip().upper()
                        mei = fields[4].strip().upper()
                        if not re.fullmatch(r"[A-Z0-9]{8}", root) or simples not in ("S", "N", "") or mei not in ("S", "N", ""):
                            raise ValueError(f"Linha {rows}: dados fiscais inválidos, processamento cancelado.")
                        # S => optante; vazio => situação indeterminada (não liberar).
                        # N/N => elegível se outras regras comerciais permitirem.
                        if simples != "N" or mei != "N":
                            batch.append((root, simples, mei))
                            blocked += 1
                        if len(batch) >= 20000:
                            connection.executemany(
                                "INSERT OR REPLACE INTO blocked_roots(root,simples,mei) VALUES (?,?,?)", batch
                            )
                            connection.commit()
                            batch.clear()
                        if rows % 5000000 == 0:
                            print(f"[Simples] {rows:,} registros processados; bloqueios {blocked:,}", flush=True)
                    if batch:
                        connection.executemany(
                            "INSERT OR REPLACE INTO blocked_roots(root,simples,mei) VALUES (?,?,?)", batch
                        )
                        connection.commit()
        if rows < min_rows:
            raise ValueError(f"Carga incompleta: {rows} linhas; mínimo exigido {min_rows}.")
        meta = {
            "period": period, "status": "ready", "rows_read": str(rows),
            "blocked_read": str(blocked),
            "created_at": datetime.now(timezone.utc).isoformat(),
        }
        connection.executemany("INSERT INTO metadata(key,value) VALUES (?,?)", meta.items())
        connection.commit()
        if connection.execute("PRAGMA integrity_check").fetchone()[0] != "ok":
            raise ValueError("Banco fiscal SQLite inconsistente.")
        connection.close()
        connection = None
        os.replace(temp, target)
        return rows, blocked, target
    finally:
        if connection is not None:
            connection.close()
        temp.unlink(missing_ok=True)


if __name__ == "__main__":
    p = argparse.ArgumentParser(description="Importa Simples.zip (RFB) sem alterar a release de CNPJ.")
    p.add_argument("--period", required=True, help="Mesma competência ativa, formato AAAA-MM")
    p.add_argument("--zip", required=True, type=Path, dest="source_zip")
    p.add_argument("--replace", action="store_true")
    args = p.parse_args()
    try:
        rows, blocked, target = build(args.source_zip, args.period, replace=args.replace)
        print(f"BASE FISCAL PRONTA: {rows:,} linhas; {blocked:,} bloqueios/indefinidos; {target}")
    except (OSError, ValueError, sqlite3.Error) as e:
        print(f"ERRO: {e}", file=sys.stderr)
        sys.exit(1)
