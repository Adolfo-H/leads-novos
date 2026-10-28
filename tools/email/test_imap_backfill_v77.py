"""Testa recuperação de cabeçalhos recente sem rede e sem alterar e-mails."""
import io
import json
import unittest
from unittest.mock import patch

import imap_fetch


class FakeImap:
    def __init__(self):
        self.calls = []

    def __enter__(self):
        return self

    def __exit__(self, *args):
        return False

    def login(self, username, password):
        return 'OK', []

    def select(self, folder, readonly):
        assert folder == 'INBOX' and readonly is True
        return 'OK', []

    def status(self, folder, query):
        return 'OK', [b'INBOX (UIDNEXT 5 UIDVALIDITY 12345)']

    def uid(self, command, *args):
        self.calls.append((command, args))
        if command == 'SEARCH':
            return 'OK', [b'1 2 3 4']
        uid = int(args[0])
        if command == 'FETCH' and args[1] == '(BODY.PEEK[HEADER])':
            return 'OK', [(b'header', f'From: contato{uid}@example.test\r\nSubject: Re: Convite\r\nIn-Reply-To: <id@example.test>\r\n\r\n'.encode())]
        raise AssertionError(f'Unexpected IMAP command: {command} {args}')


def data(**kwargs):
    result = {
        'host': 'imap.example.test', 'port': 993,
        'username': 'teste@example.test', 'password': 'fake-not-real',
        'folder': 'INBOX', 'since_uid': 2, 'known_validity': '12345',
        'limit': 30, 'backfill_days': 7,
    }
    result.update(kwargs)
    return result


class TestBackfill(unittest.TestCase):
    def invoke(self, fake, **kwargs):
        with patch('imap_fetch.imaplib.IMAP4_SSL', return_value=fake):
            with patch('sys.stdin', io.StringIO(json.dumps(data(**kwargs)))):
                return imap_fetch.run()

    def test_backfill_preserves_existing_cursor_even_with_old_uids(self):
        fake = FakeImap()
        result = self.invoke(fake)
        self.assertFalse(result['initialized'])
        self.assertEqual(result['last_uid'], 2)
        self.assertEqual([m['uid'] for m in result['messages']], [1, 2, 3, 4])
        self.assertTrue(any(call[0] == 'SEARCH' and call[1][0] is None and call[1][1] == 'SINCE' for call in fake.calls))
        self.assertTrue(all(args[1] == '(BODY.PEEK[HEADER])' for cmd,args in fake.calls if cmd=='FETCH'))

    def test_backfill_before_first_sync_reads_recent_headers_and_initializes_cursor(self):
        fake = FakeImap()
        result = self.invoke(fake, since_uid=0, known_validity='')
        self.assertFalse(result['initialized'])
        self.assertEqual(result['last_uid'], 4)
        self.assertEqual(len(result['messages']), 4)

    def test_normal_first_sync_still_skips_old_mail(self):
        fake = FakeImap()
        result = self.invoke(fake, since_uid=0, known_validity='', backfill_days=0)
        self.assertTrue(result['initialized'])
        self.assertEqual(result['last_uid'], 4)
        self.assertEqual(result['messages'], [])

    def test_changed_uidvalidity_is_not_imported_as_an_old_cursor(self):
        fake = FakeImap()
        with self.assertRaisesRegex(RuntimeError, 'UIDVALIDITY mudou'):
            self.invoke(fake, known_validity='54321')


if __name__ == '__main__':
    unittest.main()
