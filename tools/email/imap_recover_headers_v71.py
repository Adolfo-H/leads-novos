#!/usr/bin/env python3
"""Releitura limitada de cabeçalhos recentes, em modo IMAP readonly.

Lê somente campos de correlação, nunca corpo/anexo e não altera flags.
Credenciais por STDIN e nunca na linha de comando/saída de erro.
Usado apenas quando o operador confirma --apply no Artisan.
"""
from __future__ import annotations

import email
import imaplib
import json
import re
import ssl
import sys
from datetime import datetime, timedelta, timezone
from email.header import decode_header, make_header
from email.utils import parseaddr, parsedate_to_datetime


def _decoded(value: str | None) -> str:
    try:
        return str(make_header(decode_header(value or ""))).replace("\r", " ").replace("\n", " ")[:255]
    except (LookupError, ValueError, TypeError, UnicodeError):
        return str(value or "").replace("\r", " ").replace("\n", " ")[:255]


def _metadata(imap: object, folder: str) -> tuple[int, str]:
    status, rows = imap.status(folder, "(UIDNEXT UIDVALIDITY)")
    if status != "OK" or not rows:
        raise RuntimeError("IMAP status failed")
    line = rows[0].decode("ascii", "ignore") if isinstance(rows[0], bytes) else str(rows[0])
    next_uid = re.search(r"\bUIDNEXT\s+(\d+)\b", line)
    validity = re.search(r"\bUIDVALIDITY\s+(\d+)\b", line)
    if not next_uid or not validity:
        raise RuntimeError("IMAP UID metadata unavailable")
    return int(next_uid.group(1)), validity.group(1)


def _headers(raw: bytes, uid: int) -> dict[str, object]:
    parsed = email.message_from_bytes(raw)
    date_value = parsed.get("Date") or ""
    received_at = None
    if date_value:
        try:
            received_at = parsedate_to_datetime(date_value).isoformat()
        except (TypeError, ValueError, OverflowError):
            pass
    return {
        "uid": uid,
        "from_email": parseaddr(parsed.get("From", ""))[1].lower()[:255],
        "subject": _decoded(parsed.get("Subject", "")),
        "message_id": str(parsed.get("Message-ID", ""))[:255],
        "in_reply_to": str(parsed.get("In-Reply-To", ""))[:500],
        "references": str(parsed.get("References", ""))[:2000],
        "auto_submitted": str(parsed.get("Auto-Submitted", ""))[:80],
        "received_at": received_at,
    }


def scan(config: dict[str, object]) -> dict[str, object]:
    host = str(config.get("host") or "").strip()
    mailbox = str(config.get("username") or "").strip()
    password = str(config.get("password") or "")
    folder = str(config.get("folder") or "INBOX")
    port = int(config.get("port") or 993)
    days = int(config.get("days") or 7)
    limit = int(config.get("limit") or 250)
    if not host or not mailbox or not password or folder != "INBOX" or port != 993:
        raise ValueError("Invalid IMAP configuration")
    if not 1 <= days <= 30 or not 1 <= limit <= 500:
        raise ValueError("Invalid recovery window")
    since = (datetime.now(timezone.utc) - timedelta(days=days)).strftime("%d-%b-%Y")

    with imaplib.IMAP4_SSL(host, port, ssl_context=ssl.create_default_context(), timeout=25) as imap:
        status, _ = imap.login(mailbox, password)
        if status != "OK":
            raise RuntimeError("IMAP authentication failed")
        status, _ = imap.select(folder, readonly=True)
        if status != "OK":
            raise RuntimeError("IMAP readonly selection failed")
        next_uid, validity = _metadata(imap, folder)
        status, rows = imap.uid("SEARCH", None, "SINCE", since)
        if status != "OK" or not rows:
            raise RuntimeError("IMAP search failed")
        raw = rows[0] or b""
        ids = sorted(set(int(uid) for uid in raw.split() if uid.isdigit()))
        ids = [uid for uid in ids if 0 < uid < next_uid]
        available = len(ids)
        selected = ids[-limit:]
        messages = []
        for uid in selected:
            status, parts = imap.uid("FETCH", str(uid), "(BODY.PEEK[HEADER])")
            if status != "OK" or not parts:
                raise RuntimeError("IMAP header fetch failed")
            raw_header = next(
                (part[1] for part in parts if isinstance(part, tuple) and isinstance(part[1], bytes)),
                None,
            )
            if raw_header is None:
                raise RuntimeError("IMAP header missing")
            messages.append(_headers(raw_header, uid))
        return {
            "uidvalidity": validity,
            "last_uid": selected[-1] if selected else 0,
            "initialized": False,
            "messages": messages,
            "scanned": len(selected),
            "available": available,
            "limited": available > len(selected),
        }


if __name__ == "__main__":
    try:
        print(json.dumps(scan(json.load(sys.stdin)), ensure_ascii=False, separators=(",", ":")))
    except Exception as exc:
        # NUNCA mostrar detalhes do servidor que possam conter dados privados.
        print("IMAP_RECOVER_ERROR:" + type(exc).__name__, file=sys.stderr)
        sys.exit(1)
