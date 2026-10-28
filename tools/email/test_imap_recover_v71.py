import io
import json
import unittest
from unittest.mock import patch

import imap_recover_headers_v71 as recovery


class FakeImap:
    def __init__(self, messages):
        self.messages = messages
        self.commands = []
        self.selected_readonly = None

    def __enter__(self):
        return self

    def __exit__(self, exc_type, exc_val, exc_tb):
        return False

    def login(self, username, password):
        assert username == "test@example.invalid" and password == "fake-password"
        return "OK", []

    def select(self, folder, readonly):
        self.selected_readonly = readonly
        return "OK", []

    def status(self, folder, query):
        return "OK", [b"INBOX (UIDNEXT 16 UIDVALIDITY 556677)"]

    def uid(self, command, *args):
        self.commands.append((command, args))
        if command == "SEARCH":
            assert args[0] is None and args[1] == "SINCE"
            return "OK", [b"11 12 13 14 15"]
        if command == "FETCH":
            assert args[1] == "(BODY.PEEK[HEADER])"
            return "OK", [(b"body-header", self.messages[int(args[0])])]
        return "BAD", []


class RecoveryTest(unittest.TestCase):
    def test_recent_header_scan_is_readonly_and_bounded(self):
        fake = FakeImap({i: (
            b"From: Giovanna <giovanna@example.invalid>\r\n"
            b"Subject: Re: Convite\r\n"
            b"In-Reply-To: <sent-123@example.invalid>\r\n"
            b"Date: Wed, 28 Oct 2026 10:00:00 -0300\r\n\r\n"
        ) for i in range(11, 16)})
        cfg = {
            "host": "imap.example.invalid", "username": "test@example.invalid",
            "password": "fake-password", "port": 993, "folder": "INBOX",
            "days": 7, "limit": 2,
        }
        with patch("imap_recover_headers_v71.imaplib.IMAP4_SSL", return_value=fake):
            batch = recovery.scan(cfg)
        self.assertTrue(fake.selected_readonly)
        self.assertEqual([m["uid"] for m in batch["messages"]], [14, 15])
        self.assertEqual(batch["available"], 5)
        self.assertEqual(batch["scanned"], 2)
        self.assertEqual(batch["last_uid"], 15)
        self.assertEqual(batch["uidvalidity"], "556677")
        self.assertTrue(batch["limited"])
        self.assertEqual(batch["messages"][0]["in_reply_to"], "<sent-123@example.invalid>")
        self.assertEqual(len([c for c in fake.commands if c[0] == "FETCH"]), 2)

    def test_invalid_configuration_fails_before_connecting(self):
        with self.assertRaisesRegex(ValueError, "Invalid recovery window"):
            recovery.scan({
                "host": "h", "username": "test@example.invalid", "password": "fake-password",
                "port": 993, "folder": "INBOX", "days": 50, "limit": 5,
            })
        with self.assertRaisesRegex(ValueError, "Invalid IMAP configuration"):
            recovery.scan({
                "host": "h", "username": "test@example.invalid", "password": "fake-password",
                "port": 143, "folder": "INBOX", "days": 7, "limit": 5,
            })


if __name__ == "__main__":
    unittest.main()
