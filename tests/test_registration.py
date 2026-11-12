"""Isolated HTTP regression tests: no production config/data, SMTP or external payments.
Run from the repository root: python3 -m unittest discover -s tests -v
Requires PHP >= 8.1 with mbstring, PDO and pdo_sqlite (both storage backends).
"""
import email
import http.cookiejar
import json
import os
from pathlib import Path
import shutil
import socket
import subprocess
import tempfile
import time
import unittest
import urllib.error
import urllib.parse
import urllib.request
from html.parser import HTMLParser

ROOT = Path(__file__).resolve().parents[1]
PHP = os.environ.get('PHP_BINARY', 'php')
BILLING = dict(invoice_company='Fiktive Rechnung AG',
               invoice_address='Teststraße 99\n12345 Musterstadt',
               order_no='TEST-2026-42', invoice_email='buchhaltung@example.test')


class Fields(HTMLParser):
    def __init__(self, html):
        super().__init__()
        self.fields = {}
        self.feed(html)

    def handle_starttag(self, tag, attrs):
        attrs = dict(attrs)
        if tag in ('input', 'textarea') and 'name' in attrs:
            self.fields[attrs['name']] = attrs.get('value', '')


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *args, **kwargs):
        return None


class FlowCases:
    backend = None

    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory(prefix='x25-registration-')
        self.addCleanup(self.tmp.cleanup)
        self.work = Path(self.tmp.name)
        self.site = self.work / 'site'
        # Explicit allowlist: never copy config.php, data/ or production participant records.
        (self.site / 'anmeldung').mkdir(parents=True)
        for src in (ROOT / 'anmeldung').glob('*.php'):
            if src.name != 'config.php':
                shutil.copy2(src, self.site / 'anmeldung' / src.name)
        shutil.copytree(ROOT / 'anmeldung/lib', self.site / 'anmeldung/lib')
        shutil.copytree(ROOT / 'edition', self.site / 'edition')
        self.data = self.work / 'data'
        self.mail = self.work / 'mail'
        self.sessions = self.work / 'sessions'
        self.sessions.mkdir()
        with socket.socket() as sock:
            sock.bind(('127.0.0.1', 0))
            port = sock.getsockname()[1]
        self.base = f'http://127.0.0.1:{port}'
        config = {
            'SITE_URL': self.base + '/', 'ANMELDUNG_URL': self.base + '/anmeldung/',
            'LANDING_PATH': 'editionen/change-management/',
            'SMTP_HOST': 'unused.invalid', 'MAIL_FROM': 'host@example.test',
            'MAIL_TO': 'team@example.test', 'MAIL_TRANSPORT': 'file',
            'MAIL_DUMP_DIR': str(self.mail), 'DATA_DIR': str(self.data),
            'STORE_BACKEND': self.backend, 'APP_SECRET': 'test-secret-only-' * 4,
            'RATE_LIMIT': 0, 'CHECK_ORIGIN': True, 'LOGO_URL': '',
            'PAYPAL_ENV': 'sandbox', 'PAYPAL_CLIENT_ID': 'test-client',
            'PAYPAL_SECRET': 'test-secret', 'PAYPAL_API_BASE': 'http://127.0.0.1:9',
        }
        # JSON string literals are valid PHP literals for these values (no $ or backslashes).
        text = '<?php\n' + '\n'.join(
            f'define({json.dumps(k)}, {json.dumps(v)});' for k, v in config.items())
        (self.site / 'anmeldung/config.php').write_text(text)
        # Local-only router mirrors just the two Apache edition routes under test.
        router = self.work / 'router.php'
        router.write_text('''<?php
$p=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
if(preg_match('~^/editionen/([a-z0-9-]+)/(anmeldung|danke)$~',$p,$m)) {
    $_GET['slug']=$m[1]; require $_SERVER['DOCUMENT_ROOT'].'/edition/'.$m[2].'.php'; return true;
}
return false;
''')
        self.log = (self.work / 'php.log').open('w+')
        self.addCleanup(self.log.close)
        self.server = subprocess.Popen(
            [PHP, '-d', 'session.save_path=' + str(self.sessions), '-S',
             f'127.0.0.1:{port}', '-t', str(self.site), str(router)],
            stdout=self.log, stderr=self.log)
        self.addCleanup(self.stop_server)
        self.client = urllib.request.build_opener(
            urllib.request.ProxyHandler({}), NoRedirect(),
            urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
        for _ in range(100):
            if self.server.poll() is not None:
                self.fail('PHP server exited: ' + self.log_text())
            try:
                with socket.create_connection(('127.0.0.1', port), timeout=.1):
                    break
            except OSError:
                time.sleep(.02)
        else:
            self.fail('Local PHP server did not start')

    def stop_server(self):
        self.server.terminate()
        self.server.wait(timeout=5)

    def log_text(self):
        self.log.flush()
        return (self.work / 'php.log').read_text()

    def request(self, path, data=None, as_json=False):
        headers = {'Origin': self.base}
        if data is not None:
            headers['Content-Type'] = 'application/json' if as_json else 'application/x-www-form-urlencoded'
            if as_json:
                headers['Accept'] = 'application/json'
            data = (json.dumps(data) if as_json else urllib.parse.urlencode(data)).encode()
        req = urllib.request.Request(self.base + path, data=data, headers=headers)
        try:
            response = self.client.open(req, timeout=8)
        except urllib.error.HTTPError as error:
            response = error
        with response:
            return response.status, response.headers, response.read().decode()

    def php(self, code, data=None):
        script = ('require ' + json.dumps(str(self.site / 'anmeldung/lib/flow.php')) + ';'
                  + 'require_once ' + json.dumps(str(self.site / 'edition/lib.php')) + ';' + code)
        result = subprocess.run([PHP, '-r', script], input=json.dumps(data),
                                text=True, capture_output=True)
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertNotIn('Warning:', result.stderr)
        return json.loads(result.stdout) if result.stdout else None

    def rows(self):
        return self.php('echo json_encode(x25_store()->all());')

    def record(self, identifier):
        return next(row for row in self.rows() if row['id'] == identifier)

    def applicant(self, **changes):
        return dict(vorname='Fiktiv', nachname='Testperson', company='Beispiel Versicherung AG',
                    role='Projektverantwortung', category='versicherer',
                    question='Wie begleiten wir einen fiktiven Veränderungsprozess?',
                    email='teilnehmer@example.test', privacy='ja', binding='ja',
                    edition_slug='change-management', **changes)

    def submit(self, data=None):
        status, headers, body = self.request('/anmeldung/send.php',
                                             data or self.applicant(), as_json=True)
        return status, json.loads(body)

    def invite(self, address='teilnehmer@example.test'):
        return self.php("echo json_encode(x25_invite_create('change-management',"
                        "json_decode(file_get_contents('php://stdin'),true),'Fiktive Einladung'));", address)['token']

    def admit(self, identifier):
        return self.php("$id=json_decode(file_get_contents('php://stdin'),true);"
                        "echo json_encode(x25_admit(x25_store()->get($id),'test'));", identifier)

    def seed_admitted(self, count, company='Anderes Testunternehmen'):
        self.php("$d=json_decode(file_get_contents('php://stdin'),true);"
                 "x25_store()->transaction(function($s)use($d){for($i=0;$i<$d['count'];$i++)"
                 "{$s->insert(['token'=>bin2hex(random_bytes(16)),'status'=>'zugelassen',"
                 "'edition_slug'=>'change-management','company'=>$d['company'],"
                 "'email'=>'fixture'.$i.'@example.test']);}});", dict(count=count, company=company))

    def book(self, rec, **changes):
        status, _, html = self.request('/anmeldung/zahlung.php?t=' + rec['token'])
        self.assertEqual(status, 200, html)
        fields = Fields(html).fields
        return self.request('/anmeldung/zahlung.php', {
            't': rec['token'], 'weg': 'buchen', 'csrf': fields['csrf'],
            'terms': 'ja', **BILLING, **changes})

    def messages(self):
        return [email.message_from_bytes(path.read_bytes()) for path in self.mail.glob('*.eml')]

    def test_public_form_and_existing_texts_match_late_billing(self):
        status, _, html = self.request('/editionen/change-management/anmeldung')
        self.assertEqual(status, 200, html)
        self.assertTrue(set(BILLING).isdisjoint(Fields(html).fields))
        self.assertIn('Rechnungsangaben erfassen und speichern wir erst nach der Zusage', html)
        # Existing editions are server-owned; a stale hint must not require rewriting them.
        self.php("$e=x25ed_get('change-management');$e['texte']['anmeldung']['schritt2.hinweis']='Du kannst sie bereits hinterlegen.';x25ed_save($e);")
        _, _, html = self.request('/editionen/change-management/anmeldung')
        self.assertNotIn('bereits hinterlegen', html)
        for path in [ROOT / 'edition/texte.json', ROOT / 'edition/experience.json',
                     *sorted((ROOT / 'edition/seed').glob('*.json'))]:
            self.assertNotIn('bereits hinterlegen', path.read_text())
            json.loads(path.read_text())

    def test_regular_request_does_not_store_unsolicited_billing_or_book(self):
        data = self.applicant()
        data.update(BILLING, invoice_email='not-an-email')
        status, out = self.submit(data)
        self.assertEqual((status, out['status']), (200, 'pruefung'), out)
        self.assertNotIn('pay_url', out)
        rec = self.rows()[0]
        self.assertTrue(all(rec.get(key, '') == '' for key in BILLING))
        self.assertNotIn('booking_confirmed_at', rec)
        self.assertEqual(rec['payment_status'], 'offen')
        self.assertEqual(len(self.messages()), 2)
        self.assertTrue(all(msg['To'] != BILLING['invoice_email'] for msg in self.messages()))
        for path in [*self.mail.glob('*.eml'), *self.data.glob('*.log')]:
            self.assertNotIn(BILLING['order_no'], path.read_text())
        code, _, _ = self.request('/anmeldung/zahlung.php?t=' + rec['token'])
        self.assertEqual(code, 409)
        _, _, thanks = self.request('/editionen/change-management/danke')
        self.assertIn('zwei Werktagen', thanks)

    def test_form_post_redirect_and_duplicate(self):
        code, headers, _ = self.request('/anmeldung/send.php', self.applicant())
        self.assertEqual(code, 303)
        self.assertTrue(headers['Location'].endswith('/danke?status=pruefung'))
        code, out = self.submit()
        self.assertEqual(code, 422, out)
        self.assertEqual(len(self.rows()), 1)

    def test_required_question_and_confirmations(self):
        for field in ('question', 'privacy', 'binding'):
            with self.subTest(field=field):
                data = self.applicant()
                data[field] = ''
                code, out = self.submit(data)
                self.assertEqual(code, 422, out)
                self.assertIn(field, out['fields'])
        self.assertEqual(self.rows(), [])
        self.assertFalse(self.mail.exists())

    def test_personal_invitation_and_booking_gates(self):
        code, out = self.submit(self.applicant(invitation=self.invite()))
        self.assertEqual((code, out['status']), (200, 'zugelassen'))
        rec = self.rows()[0]
        self.assertIn(rec['token'], out['pay_url'])
        _, _, thanks = self.request('/editionen/change-management/danke')
        self.assertIn('Deine Teilnahme ist freigegeben.', thanks)
        self.assertNotIn('zwei Werktagen', thanks)
        code, _, body = self.request('/anmeldung/paypal.php',
                                    dict(t=rec['token'], action='create'), as_json=True)
        self.assertEqual(code, 409, body)  # before any outbound PayPal call
        code, _, _ = self.request('/anmeldung/zahlung.php', dict(t=rec['token'], weg='rechnung'))
        self.assertEqual(code, 200)  # displays booking form; no invoice generated
        self.assertNotIn('invoice_no', self.record(rec['id']))
        for changes, expected in [({'csrf': 'forged'}, 403), ({'terms': ''}, 403),
                                  ({'invoice_address': ''}, 422), ({'invoice_email': 'invalid'}, 422)]:
            with self.subTest(changes=changes):
                self.assertEqual(self.book(rec, **changes)[0], expected)
                saved = self.record(rec['id'])
                self.assertNotIn('booking_confirmed_at', saved)
                self.assertEqual(saved['invoice_address'], '')
        self.assertEqual(self.book(rec)[0], 303)
        saved = self.record(rec['id'])
        self.assertTrue(saved['booking_confirmed_at'])
        for key, value in BILLING.items():
            self.assertEqual(saved[key], value)
        self.assertEqual(self.book_if_replayed(saved)[0], 200)
        self.assertEqual(self.record(rec['id'])['invoice_company'], BILLING['invoice_company'])

    def book_if_replayed(self, rec):
        return self.request('/anmeldung/zahlung.php', dict(t=rec['token'], weg='buchen',
                            terms='ja', invoice_company='Nachträgliche Änderung'))

    def test_regular_review_then_booking_invoice_payment_ticket(self):
        self.assertEqual(self.submit()[0], 200)
        rec = self.admit(self.rows()[0]['id'])
        self.assertEqual(rec['status'], 'zugelassen')
        self.assertEqual(self.book(rec)[0], 303)
        code, _, _ = self.request('/anmeldung/zahlung.php', dict(t=rec['token'], weg='rechnung'))
        self.assertEqual(code, 303)
        rec = self.record(rec['id'])
        self.assertTrue(rec['invoice_no'])
        self.assertEqual(rec['payment_method'], 'rechnung')
        _, _, invoice = self.request('/anmeldung/rechnung.php?t=' + rec['token'])
        for value in (BILLING['invoice_company'], BILLING['order_no'], 'Teststraße 99'):
            self.assertIn(value, invoice)
        messages = self.messages()
        contact = [msg for msg in messages if BILLING['invoice_email'] in str(msg['To'])]
        self.assertEqual(len(contact), 1)
        payload = '\n'.join(part.get_payload(decode=True).decode('utf-8', errors='replace')
                            for part in contact[0].walk() if part.get_content_type() == 'text/plain')
        self.assertIn('Du hast Deine Teilnahme verbindlich gebucht', payload)
        self.assertNotIn('Diese Zusage allein', payload)
        paid = self.php("$id=json_decode(file_get_contents('php://stdin'),true);"
                        "echo json_encode(x25_mark_paid(x25_store()->get($id),'rechnung','test'));", rec['id'])
        self.assertEqual(paid['payment_status'], 'bezahlt')
        self.assertTrue(paid['ticket_no'])
        code, _, ticket = self.request('/anmeldung/ticket.php?t=' + rec['token'])
        self.assertEqual(code, 200, ticket)
        self.assertIn(paid['ticket_no'], ticket)

    def test_invitation_capacity_waitlist_and_status_receipt(self):
        token = self.invite()
        self.seed_admitted(25)
        code, out = self.submit(self.applicant(invitation=token))
        self.assertEqual((code, out['status']), (200, 'warteliste'))
        self.assertNotIn('pay_url', out)
        code, _, thanks = self.request('/editionen/change-management/danke?status=zugelassen')
        self.assertEqual(code, 200)
        self.assertIn('Deine Anfrage steht auf der Warteliste.', thanks)
        self.assertNotIn('zwei Werktagen', thanks)
        rec = next(r for r in self.rows() if r.get('invitation_hash'))
        self.assertEqual(self.request('/anmeldung/zahlung.php?t=' + rec['token'])[0], 409)

    def test_company_limit_and_regular_review_when_full(self):
        self.seed_admitted(2, company='Beispiel Versicherung AG')
        code, out = self.submit(self.applicant(invitation=self.invite()))
        self.assertEqual((code, out['status']), (200, 'warteliste'))
        data = self.applicant()
        data['email'] = 'zweite-person@example.test'
        self.assertEqual(self.submit(data)[1]['status'], 'pruefung')
        rec = next(r for r in self.rows() if r['email'] == data['email'])
        result = self.php("$id=json_decode(file_get_contents('php://stdin'),true);try{"
                          "x25_admit(x25_store()->get($id),'test');echo json_encode(false);"
                          "}catch(RuntimeException $e){echo json_encode(true);}", rec['id'])
        self.assertTrue(result)
        self.assertEqual(self.record(rec['id'])['status'], 'pruefung')

    def test_invalid_and_used_invitations(self):
        token = self.invite('other@example.test')
        self.assertEqual(self.submit(self.applicant(invitation=token))[0], 422)
        self.assertEqual(self.submit(self.applicant(invitation='a' * 48))[0], 422)
        token = self.invite()
        self.php("$token=json_decode(file_get_contents('php://stdin'),true);"
                 "$file=x25_curation_dir().'/.einladung-'.hash('sha256',$token).'.json';"
                 "$d=json_decode(file_get_contents($file),true);$d['expires_at']=time()-1;"
                 "x25_private_json($file,$d);", token)
        self.assertEqual(self.submit(self.applicant(invitation=token))[0], 422)
        token = self.invite()
        self.assertEqual(self.submit(self.applicant(invitation=token))[0], 200)
        rec = self.rows()[0]
        self.php("$id=json_decode(file_get_contents('php://stdin'),true);"
                 "x25_store()->update($id,['status'=>'abgesagt']);", rec['id'])
        self.assertEqual(self.submit(self.applicant(invitation=token))[0], 422)
        self.assertEqual(len(self.rows()), 1)

    def test_thanks_without_receipt_does_not_trust_status_query(self):
        _, _, body = self.request('/editionen/change-management/danke?status=zugelassen')
        self.assertIn('keine aktuelle Eingangsbestätigung', body)
        self.assertNotIn('Deine Teilnahme ist freigegeben.', body)


class JsonFlow(FlowCases, unittest.TestCase):
    backend = 'json'


class SqliteFlow(FlowCases, unittest.TestCase):
    backend = 'sqlite'


if __name__ == '__main__':
    unittest.main()
