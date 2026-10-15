#!/usr/bin/env python3
"""Lê apenas cabeçalhos de NOVAS mensagens IMAP; credenciais chegam via STDIN.

Não escreve mensagens em disco, não imprime senhas e não modifica a caixa.
Saída JSON: metadados de UID e cabeçalhos para correlação via Message-ID.
"""
import email
import imaplib
import json
import re
import ssl
import sys
from email.header import decode_header, make_header
from email.utils import parseaddr, parsedate_to_datetime


def decode(value):
    try:
        return str(make_header(decode_header(value or ""))).replace("\r", " ").replace("\n", " ")[:255]
    except (ValueError, TypeError, UnicodeError):
        return str(value or "").replace("\r", " ").replace("\n", " ")[:255]


def metadata(imap, folder):
    kind, rows = imap.status(folder, "(UIDNEXT UIDVALIDITY)")
    if kind != "OK" or not rows:
        raise RuntimeError("Nao foi possivel ler UIDNEXT/UIDVALIDITY da caixa IMAP")
    line = rows[0].decode("ascii", "ignore") if isinstance(rows[0], bytes) else str(rows[0])
    uidnext_match = re.search(r"UIDNEXT\s+(\d+)", line)
    uidvalidity_match = re.search(r"UIDVALIDITY\s+(\d+)", line)
    if not uidnext_match or not uidvalidity_match:
        raise RuntimeError("UIDNEXT/UIDVALIDITY ausentes do servidor IMAP")
    return int(uidnext_match.group(1)), uidvalidity_match.group(1)


def run():
    cfg = json.load(sys.stdin)
    host = str(cfg["host"])
    port = int(cfg.get("port", 993))
    username = str(cfg["username"])
    password = str(cfg["password"])
    folder = str(cfg.get("folder", "INBOX"))
    since_uid = max(0, int(cfg.get("since_uid", 0)))
    known_validity = str(cfg.get("known_validity") or "")
    maximum = min(80, max(1, int(cfg.get("limit", 60))))

    if port != 993 or not host or not username or not password or folder != "INBOX":
        raise RuntimeError("Configuracao IMAP invalida ou nao suportada")

    ctx = ssl.create_default_context()
    with imaplib.IMAP4_SSL(host, port, ssl_context=ctx, timeout=25) as imap:
        status, _ = imap.login(username, password)
        if status != "OK":
            raise RuntimeError("Falha na autenticacao IMAP")
        status, _ = imap.select(folder, readonly=True)
        if status != "OK":
            raise RuntimeError("Falha ao abrir INBOX em modo somente leitura")
        uidnext, uidvalidity = metadata(imap, folder)

        # Primeira execução e UIDVALIDITY alterado: apenas inicializa.
        # NUNCA varre as mensagens pessoais antigas da caixa.
        if not known_validity or known_validity != uidvalidity:
            return {
                "uidvalidity": uidvalidity,
                "last_uid": max(0, uidnext - 1),
                "initialized": True,
                "messages": [],
            }

        status, searched = imap.uid("SEARCH", None, "UID", f"{since_uid + 1}:*")
        if status != "OK":
            raise RuntimeError("Falha na busca de UIDs IMAP")
        raw = searched[0] or b""
        ids = [int(x) for x in raw.split() if x.isdigit() and int(x) > since_uid]
        ids = sorted(set(ids))[:maximum]
        items = []
        last_uid = since_uid
        for uid in ids:
            status, fetched = imap.uid("FETCH", str(uid), "(BODY.PEEK[HEADER])")
            if status != "OK":
                raise RuntimeError("Falha de leitura de cabecalho IMAP")
            raw_header = next((part[1] for part in fetched if isinstance(part, tuple) and isinstance(part[1], bytes)), None)
            if raw_header is None:
                raise RuntimeError("Cabecalho IMAP incompleto")
            message = email.message_from_bytes(raw_header)
            date = message.get("Date") or ""
            iso_date = None
            if date:
                try:
                    iso_date = parsedate_to_datetime(date).isoformat()
                except (ValueError, TypeError, OverflowError):
                    pass
            items.append({
                "uid": uid,
                "from_email": parseaddr(message.get("From", ""))[1].lower()[:255],
                "subject": decode(message.get("Subject", "")),
                "message_id": str(message.get("Message-ID", ""))[:255],
                "in_reply_to": str(message.get("In-Reply-To", ""))[:500],
                "references": str(message.get("References", ""))[:2000],
                "auto_submitted": str(message.get("Auto-Submitted", ""))[:80],
                "received_at": iso_date,
            })
            last_uid = uid
        return {"uidvalidity": uidvalidity, "last_uid": last_uid, "initialized": False, "messages": items}


if __name__ == "__main__":
    try:
        print(json.dumps(run(), ensure_ascii=False, separators=(",", ":")))
    except Exception as exc:
        # Evitar imprimir mensagens de exceção do servidor, que podem conter dados privados.
        print("IMAP_ERROR:" + type(exc).__name__, file=sys.stderr)
        sys.exit(1)
