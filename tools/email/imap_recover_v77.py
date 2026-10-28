#!/usr/bin/env python3
"""Leitura excepcional e limitada de cabeçalhos IMAP recentes.

- Apenas INBOX, TLS e modo READONLY; nunca envia, move ou marca mensagens.
- Nunca lê corpo nem anexos; nunca exibe endereços/assuntos no stdout/stderr.
- A aplicação PHP decide a correlação EXATA com Message-ID e remetente.
- last_uid devolvido é o do cursor original: uma recuperação não deve saltar UIDs.
"""

import email
import imaplib
import json
import re
import ssl
import sys
from datetime import datetime, timedelta, timezone
from email.utils import parseaddr, parsedate_to_datetime
from email.header import decode_header, make_header


def decode(value):
    try:
        return str(make_header(decode_header(value or ""))).replace("\r", " ").replace("\n", " ")[:255]
    except (LookupError, ValueError, TypeError, UnicodeError):
        return str(value or "").replace("\r", " ").replace("\n", " ")[:255]


def metadata(imap, folder):
    kind, rows = imap.status(folder, "(UIDNEXT UIDVALIDITY)")
    if kind != "OK" or not rows:
        raise RecoveryError("MailboxMetadata")
    line = rows[0].decode("ascii", "ignore") if isinstance(rows[0], bytes) else str(rows[0])
    uidnext_match = re.search(r"UIDNEXT\s+(\d+)", line)
    validity_match = re.search(r"UIDVALIDITY\s+(\d+)", line)
    if not uidnext_match or not validity_match:
        raise RecoveryError("MailboxMetadata")
    return int(uidnext_match.group(1)), validity_match.group(1)


class RecoveryError(RuntimeError):
    pass


def run():
    cfg = json.load(sys.stdin)
    host = str(cfg.get("host") or "").strip()
    username = str(cfg.get("username") or "").strip()
    password = str(cfg.get("password") or "")
    port = int(cfg.get("port", 993))
    folder = str(cfg.get("folder", "INBOX"))
    known_validity = str(cfg.get("known_validity") or "")
    since_uid = max(0, int(cfg.get("since_uid", 0)))
    hours = int(cfg.get("hours", 72))
    limit = int(cfg.get("limit", 600))
    sender = str(cfg.get("sender") or "").strip().lower()

    if not (1 <= hours <= 168 and 1 <= limit <= 1200):
        raise RecoveryError("InvalidBound")
    if not (host and username and password and port == 993 and folder.upper() == "INBOX"):
        raise RecoveryError("InvalidConfig")
    if not known_validity.isdigit():
        raise RecoveryError("NoCursor")
    if sender and not re.fullmatch(r"[A-Za-z0-9.!#$%&'*+/=?^_`{|}~-]+@[A-Za-z0-9.-]+", sender):
        raise RecoveryError("InvalidSender")

    cutoff = datetime.now(timezone.utc) - timedelta(hours=hours)
    search_date = cutoff.strftime("%d-%b-%Y")
    ctx = ssl.create_default_context()
    with imaplib.IMAP4_SSL(host, port, ssl_context=ctx, timeout=25) as imap:
        status, _ = imap.login(username, password)
        if status != "OK":
            raise RecoveryError("Authentication")
        status, _ = imap.select("INBOX", readonly=True)
        if status != "OK":
            raise RecoveryError("Mailbox")
        _uidnext, validity = metadata(imap, "INBOX")
        if known_validity != validity:
            raise RecoveryError("UidValidityChanged")

        if sender:
            status, searched = imap.uid("SEARCH", None, "SINCE", search_date, "FROM", '"' + sender + '"')
        else:
            status, searched = imap.uid("SEARCH", None, "SINCE", search_date)
        if status != "OK" or not searched:
            raise RecoveryError("Search")

        raw = searched[0] or b""
        found = sorted({int(uid) for uid in raw.split() if uid.isdigit() and int(uid) > 0})
        selected = sorted(found[-limit:])
        results = []
        errors = 0
        for uid in selected:
            status, fetched = imap.uid("FETCH", str(uid), "(BODY.PEEK[HEADER])")
            if status != "OK" or not fetched:
                raise RecoveryError("Fetch")
            raw_header = next(
                (part[1] for part in fetched if isinstance(part, tuple) and isinstance(part[1], bytes)),
                None,
            )
            if raw_header is None:
                raise RecoveryError("HeaderMissing")
            try:
                message = email.message_from_bytes(raw_header)
                date = message.get("Date", "")
                received = None
                if date:
                    try:
                        received = parsedate_to_datetime(date).isoformat()
                    except (ValueError, TypeError, OverflowError):
                        pass
                results.append({
                    "uid": uid,
                    "from_email": parseaddr(message.get("From", ""))[1].lower()[:255],
                    "subject": decode(message.get("Subject", "")),
                    "message_id": str(message.get("Message-ID", ""))[:255],
                    "in_reply_to": str(message.get("In-Reply-To", ""))[:500],
                    "references": str(message.get("References", ""))[:2000],
                    "auto_submitted": str(message.get("Auto-Submitted", ""))[:80],
                    "received_at": received,
                })
            except (ValueError, TypeError, UnicodeError, LookupError):
                errors += 1

        return {
            "uidvalidity": validity,
            "last_uid": since_uid,
            "initialized": False,
            "messages": results,
            "found_in_window": len(found),
            "truncated": len(found) > len(selected),
            "parse_errors": errors,
            "lookback_hours": hours,
        }


if __name__ == "__main__":
    try:
        print(json.dumps(run(), ensure_ascii=False, separators=(",", ":")))
    except Exception as exc:
        # Os erros podem conter strings privadas do servidor: exibir apenas o tipo.
        category = exc.args[0] if isinstance(exc, RecoveryError) and exc.args else type(exc).__name__
        print("RECOVERY_ERROR:" + str(category), file=sys.stderr)
        sys.exit(1)
