"""Detecta apenas DSNs estruturados RFC 3464 / MIME multipart/report.

Nunca aceita assunto ou From como prova de falha, nem imprime mensagem/anexo.
O resultado exige Message-ID original + Final-Recipient para correlação no Laravel.
"""
from __future__ import annotations

import email
import re
from email import policy
from email.message import Message
from email.utils import parseaddr

MAX_DSN_BYTES = 131_072
ID_RE = re.compile(r"^<?([A-Za-z0-9._+-]+@[A-Za-z0-9.-]+)>?$")
STATUS_RE = re.compile(r"^[245]\.\d{1,3}\.\d{1,3}$")
ALLOWED_ACTIONS = {"failed", "delayed", "delivered", "relayed", "expanded"}


def _header(headers: Message, name: str) -> str:
    return str(headers.get(name, "")).strip().replace("\r", " ").replace("\n", " ")


def _message_id(value: str) -> str:
    match = ID_RE.fullmatch(value.strip())
    return match.group(1) if match else ""


def _recipient(value: str) -> str:
    if ";" not in value:
        return ""
    kind, address = value.split(";", 1)
    if kind.strip().lower() != "rfc822":
        return ""
    candidate = parseaddr(address.strip())[1].lower()
    if len(candidate) > 254 or not re.fullmatch(r"[^\s@]+@[^\s@]+\.[^\s@]+", candidate):
        return ""
    return candidate


def _original_id(message: Message) -> str:
    """Procura o Message-ID SOMENTE no conteúdo anexado como mensagem original."""
    for part in message.walk():
        ctype = part.get_content_type().lower()
        if ctype == "message/rfc822":
            payload = part.get_payload()
            if isinstance(payload, list):
                for original in payload:
                    found = _message_id(_header(original, "Message-ID"))
                    if found:
                        return found
        elif ctype == "text/rfc822-headers":
            raw = part.get_payload(decode=True)
            if raw is None:
                raw_value = part.get_payload()
                if not isinstance(raw_value, str):
                    continue
                raw = raw_value.encode("utf-8", "replace")
            headers = email.message_from_bytes(raw[:8192], policy=policy.default)
            found = _message_id(_header(headers, "Message-ID"))
            if found:
                return found
    return ""


def extract_dsn(raw: bytes) -> dict[str, str] | None:
    if not raw or len(raw) > MAX_DSN_BYTES:
        return None
    message = email.message_from_bytes(raw, policy=policy.default)
    if message.get_content_type().lower() != "multipart/report":
        return None
    if str(message.get_param("report-type", header="content-type") or "").lower() != "delivery-status":
        return None
    orig_id = _original_id(message)
    if not orig_id:
        return None

    for part in message.walk():
        if part.get_content_type().lower() != "message/delivery-status":
            continue
        blocks = part.get_payload()
        if not isinstance(blocks, list):
            continue
        for item in blocks:
            if not isinstance(item, Message):
                continue
            final_recipient = _recipient(_header(item, "Final-Recipient"))
            status = _header(item, "Status")
            action = _header(item, "Action").lower()
            if not final_recipient or not STATUS_RE.fullmatch(status) or action not in ALLOWED_ACTIONS:
                continue
            if action == "failed" and status.startswith("5."):
                classification = "invalid" if status in {"5.1.1", "5.1.2", "5.1.10"} else "permanent_other"
            elif action in {"failed", "delayed"} and status.startswith("4."):
                classification = "temporary"
            else:
                continue  # Delivered/relayed, status inconsistente ou informativo não é bounce.
            diagnostic = re.sub(r"[\x00-\x1f\x7f]", " ", _header(item, "Diagnostic-Code"))[:200]
            # Corpo original, anexos e texto do relatório nunca são retornados.
            return {
                "original_message_id": orig_id,
                "final_recipient": final_recipient,
                "status_code": status,
                "action": action,
                "classification": classification,
                "diagnostic": diagnostic,
            }
    return None
