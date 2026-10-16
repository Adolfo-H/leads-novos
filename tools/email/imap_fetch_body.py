#!/usr/bin/env python3
"""Lê APENAS um UID IMAP previamente vinculado à campanha.

Credenciais via stdin; não lê anexos, não marca como lido, não carrega imagens.
Nunca escreve mensagens ou senhas em logs.
"""
import email
import imaplib
import json
import re
import ssl
import sys
from email import policy
from html.parser import HTMLParser

MAX_BYTES = 131072
MAX_CHARS = 32000


class TextOnly(HTMLParser):
    def __init__(self):
        super().__init__(convert_charrefs=True)
        self.parts = []
        self.ignored = 0

    def handle_starttag(self, tag, attrs):
        if tag in ("script", "style", "head", "iframe", "svg", "object"):
            self.ignored += 1
        elif not self.ignored and tag in ("br", "p", "div", "li", "tr"):
            self.parts.append("\n")

    def handle_endtag(self, tag):
        if tag in ("script", "style", "head", "iframe", "svg", "object"):
            self.ignored = max(0, self.ignored - 1)
        elif not self.ignored and tag in ("p", "div", "li", "tr"):
            self.parts.append("\n")

    def handle_data(self, data):
        if not self.ignored:
            self.parts.append(data)


def plain_body(msg):
    part = msg.get_body(preferencelist=("plain", "html")) if msg.is_multipart() else msg
    if part is None or part.get_content_disposition() == "attachment":
        return ""
    if part.get_content_maintype() != "text":
        return ""
    try:
        value = part.get_content()
    except (UnicodeError, LookupError, ValueError, TypeError, AttributeError):
        payload = part.get_payload(decode=True)
        value = (payload or b"").decode("utf-8", "replace")
    value = value if isinstance(value, str) else str(value)
    if part.get_content_subtype() == "html":
        parser = TextOnly()
        parser.feed(value)
        value = "".join(parser.parts)
    value = value.replace("\r\n", "\n").replace("\r", "\n").replace("\x00", "")
    value = "\n".join(line.strip() for line in value.splitlines())
    value = re.sub(r"\n{4,}", "\n\n\n", value).strip()
    return value[:MAX_CHARS]


def main():
    cfg = json.load(sys.stdin)
    host = str(cfg.get("host", ""))
    username = str(cfg.get("username", ""))
    password = str(cfg.get("password", ""))
    folder = str(cfg.get("folder", "INBOX"))
    expected = str(cfg.get("uidvalidity", ""))
    uid = int(cfg.get("uid", 0))
    port = int(cfg.get("port", 993))
    if not host or not username or not password or not folder or not expected.isdigit():
        raise ValueError("Configuracao IMAP incompleta")
    if uid < 1 or uid > (2**32 - 1) or port != 993:
        raise ValueError("UID/porta IMAP invalidos")

    with imaplib.IMAP4_SSL(host, port, ssl_context=ssl.create_default_context(), timeout=25) as imap:
        imap.login(username, password)
        status, result = imap.select(folder, readonly=True)
        if status != "OK":
            raise RuntimeError("Caixa nao disponivel")
        status, data = imap.status(folder, "(UIDVALIDITY)")
        info = data[0].decode("ascii", "ignore") if status == "OK" and data else ""
        match = re.search(r"UIDVALIDITY\s+(\d+)", info)
        if match is None or match.group(1) != expected:
            raise RuntimeError("A identificacao da caixa IMAP mudou; resposta nao sera lida")
        status, response = imap.uid("FETCH", str(uid), f"(BODY.PEEK[]<0.{MAX_BYTES}> RFC822.SIZE)")
        if status != "OK":
            raise RuntimeError("Falha ao ler UID especifico")
        chunks = [item[1] for item in response if isinstance(item, tuple) and isinstance(item[1], bytes)]
        if not chunks:
            raise RuntimeError("Mensagem nao encontrada para UID registrado")
        raw = chunks[0][:MAX_BYTES]
        msg = email.message_from_bytes(raw, policy=policy.default)
        body = plain_body(msg)
        if not body:
            raise RuntimeError("Mensagem sem corpo textual disponivel")
        print(json.dumps({"body": body, "truncated": len(raw) >= MAX_BYTES}, ensure_ascii=False))
        imap.logout()


if __name__ == "__main__":
    try:
        main()
    except Exception as exc:
        # Nunca incluir credenciais nem dados da mensagem na resposta de erro.
        print("IMAP_BODY_ERROR:" + type(exc).__name__, file=sys.stderr)
        sys.exit(1)
