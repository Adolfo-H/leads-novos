import sys
import unittest
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[2] / 'tools' / 'email'))
from campaign_dsn import extract_dsn


def fixture(status='5.1.1', recipient='cliente@example.org', action='failed', original='<abc123@exportcontrol.com.br>'):
    return (f'''From: MAILER-DAEMON@example.org
MIME-Version: 1.0
Content-Type: multipart/report; report-type=delivery-status; boundary="dsn-test"
Subject: Delivery Status Notification

--dsn-test
Content-Type: text/plain; charset=utf-8

Delivery failed
--dsn-test
Content-Type: message/delivery-status

Reporting-MTA: dns; mx.example.org

Final-Recipient: rfc822; {recipient}
Action: {action}
Status: {status}
Diagnostic-Code: smtp; 550 user unknown

--dsn-test
Content-Type: text/rfc822-headers

From: adolfo@exportcontrol.com.br
To: {recipient}
Message-ID: {original}
Subject: teste

--dsn-test--
''').encode()


class DsnTest(unittest.TestCase):
    def test_hard_invalid(self):
        found = extract_dsn(fixture())
        self.assertIsNotNone(found)
        self.assertEqual(found['classification'], 'invalid')
        self.assertEqual(found['original_message_id'], 'abc123@exportcontrol.com.br')
        self.assertEqual(found['final_recipient'], 'cliente@example.org')

    def test_soft(self):
        found = extract_dsn(fixture('4.2.2', action='delayed'))
        self.assertEqual(found['classification'], 'temporary')

    def test_permanent_other_no_auto_invalid(self):
        found = extract_dsn(fixture('5.7.1'))
        self.assertEqual(found['classification'], 'permanent_other')

    def test_missing_original_reference(self):
        self.assertIsNone(extract_dsn(fixture(original='broken')))

    def test_reject_fake_subject_no_structured_dsn(self):
        self.assertIsNone(extract_dsn(b'From: mailer-daemon@example.org\nSubject: Mail delivery failed\n\n550 fail'))

    def test_excludes_success_reports(self):
        self.assertIsNone(extract_dsn(fixture('2.0.0', action='delivered')))

    def test_rejects_recipient_without_rfc822(self):
        self.assertIsNone(extract_dsn(fixture(recipient='cliente@example.org\n')))

    def test_max_size(self):
        self.assertIsNone(extract_dsn(fixture() + b'X' * 132000))


if __name__ == '__main__':
    unittest.main()
