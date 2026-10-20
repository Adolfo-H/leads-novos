import io
import json
import unittest
from unittest.mock import patch

import imap_fetch


class FakeImap:
    def __init__(self, headers, failed_fetch_uid=None):
        self.headers = headers
        self.failed_fetch_uid = failed_fetch_uid

    def __enter__(self):
        return self

    def __exit__(self, exc_type, exc_value, traceback):
        return False

    def login(self, username, password):
        return "OK", []

    def select(self, folder, readonly):
        return "OK", []

    def status(self, folder, query):
        return "OK", [b"INBOX (UIDNEXT 4 UIDVALIDITY 12345)"]

    def uid(self, command, *args):
        if command == "SEARCH":
            return "OK", [b"1 2"]
        uid = int(args[0])
        if uid == self.failed_fetch_uid:
            return "NO", []
        return "OK", [(b"header", self.headers[uid])]


def config():
    return {
        "host": "imap.example.invalid",
        "port": 993,
        "username": "user@example.invalid",
        "password": "not-a-real-password",
        "folder": "INBOX",
        "since_uid": 0,
        "known_validity": "12345",
        "limit": 60,
    }


class ImapFetchTest(unittest.TestCase):
    def run_with_fake(self, fake):
        with patch("imap_fetch.imaplib.IMAP4_SSL", return_value=fake):
            with patch("sys.stdin", io.StringIO(json.dumps(config()))):
                return imap_fetch.run()

    def test_unknown_header_charset_falls_back_without_dropping_message(self):
        fake = FakeImap({
            1: b"From: sender@example.invalid\r\nSubject: =?x-unknown?Q?broken?=\r\n\r\n",
            2: b"From: valid@example.invalid\r\nSubject: Follow-up\r\n\r\n",
        })

        result = self.run_with_fake(fake)

        self.assertEqual(result["last_uid"], 2)
        self.assertEqual(len(result["messages"]), 2)
        self.assertIn("x-unknown", result["messages"][0]["subject"])
        self.assertEqual(result["messages"][1]["subject"], "Follow-up")
        self.assertEqual(result["message_errors"], [])

    def test_parse_failure_is_reported_and_does_not_block_next_uid(self):
        fake = FakeImap({
            1: b"From: broken@example.invalid\r\n\r\n",
            2: b"From: valid@example.invalid\r\nSubject: Re: Test\r\n\r\n",
        })
        original_parser = imap_fetch.email.message_from_bytes

        def parser(raw):
            if raw == fake.headers[1]:
                raise ValueError("private parser detail")
            return original_parser(raw)

        with patch("imap_fetch.email.message_from_bytes", side_effect=parser):
            result = self.run_with_fake(fake)

        self.assertEqual(result["last_uid"], 2)
        self.assertEqual(result["message_errors"], [{"uid": 1, "error_type": "ValueError"}])
        self.assertEqual([message["uid"] for message in result["messages"]], [2])

    def test_fetch_failure_aborts_batch_instead_of_advancing_cursor(self):
        fake = FakeImap({
            1: b"From: valid@example.invalid\r\n\r\n",
            2: b"From: unavailable@example.invalid\r\n\r\n",
        }, failed_fetch_uid=1)

        with self.assertRaisesRegex(RuntimeError, "Falha de leitura"):
            self.run_with_fake(fake)


if __name__ == "__main__":
    unittest.main()
