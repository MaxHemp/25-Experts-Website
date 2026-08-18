<?php
/**
 * 25 EXPERTS – fachlicher Ablauf der Anmeldestrecke und alle Mailtexte.
 *
 * Statusmodell je Anmeldung:
 *   status          pruefung | zugelassen | abgesagt | warteliste
 *   payment_method  '' | paypal | rechnung
 *   payment_status  offen | bezahlt
 *   invoice_no      25X-2026-0001 … (nur bei Rechnung)          ticket_no  25X-CM-001 … (nach Zahlungseingang)
 *   token           geheimer Link-Schlüssel des Anmelders (Zahlung/Rechnung/Ticket)
 *   action_nonce    Einmal-Nonce für signierte Gastgeber-Links (aktion.php); wird nach jeder Aktion erneuert
 *
 * Aktionen (aus send.php, aktion.php, admin.php, paypal.php, zahlung.php):
 *   x25_admit()  x25_reject()  x25_choose_invoice()  x25_mark_paid()  x25_send_ticket()
 * Mailtexte: Wortlaut nach 06-assets/emails (E-Mail 01–04, Sie-Form, Marke 25 EXPERTS).
 */
declare(strict_types=1);

require_once __DIR__ . '/x25.php';

// ------------------------------------------------------------------ Links
function x25_pay_url(array $rec): string { return x25_conf()['base'] . 'zahlung.php?t=' . rawurlencode((string)$rec['token']); }
function x25_invoice_url(array $rec): string { return x25_conf()['base'] . 'rechnung.php?t=' . rawurlencode((string)$rec['token']); }
function x25_ticket_url(array $rec): string { return x25_conf()['base'] . 'ticket.php?t=' . rawurlencode((string)$rec['token']); }
function x25_admin_url(): string { return x25_conf()['base'] . 'admin.php'; }

/** Zusammenfassung der Angaben (Mails, Seiten). */
function x25_rows_person(array $rec, bool $withMeta = false): array
{
    $rows = [
        ['Name', $rec['name']], ['Unternehmen', $rec['company']], ['Rolle', $rec['role']],
        ['Ebene', X25_LEVELS[$rec['level']] ?? $rec['level']], ['E-Mail', $rec['email']],
        ['LinkedIn', ($rec['linkedin'] ?? '') !== '' ? $rec['linkedin'] : '–'],
        ['Unternehmenstyp', X25_CATEGORIES[$rec['category']] ?? $rec['category']],
    ];
    if ($withMeta) {
        $rows[] = ['Datenschutzhinweis', 'gelesen (Art. 6 Abs. 1 lit. b DSGVO)'];
        $rows[] = ['Edition', $rec['edition']];
        $rows[] = ['Eingegangen', x25_date($rec['created_at']) . ' Uhr'];
        $rows[] = ['Quelle', ($rec['source'] ?? '') !== '' ? $rec['source'] : x25_conf()['landing']];
        $rows[] = ['Zulassungsprüfung', $rec['admission_note'] ?? ''];
    }
    return $rows;
}
function x25_t_rows(array $rows): string
{
    $s = '';
    foreach ($rows as [$k, $v]) { $s .= str_pad($k . ':', 20) . $v . "\n"; }
    return $s;
}

// ------------------------------------------------------------------ Aktionen
/** Zusage: Status zugelassen, Zusage + Zahlungsaufforderung an den Anmelder. $by = 'automatisch' | 'gastgeber' | 'admin'. */
function x25_admit(array $rec, string $by): array
{
    $store = x25_store();
    $store->update((int)$rec['id'], ['status' => 'zugelassen', 'decided_at' => gmdate('c'), 'decided_by' => $by, 'action_nonce' => x25_token(12)]);
    $rec = $store->get((int)$rec['id']);
    x25_mail_zusage($rec);
    return $rec;
}
/** Absage: Status abgesagt, Absage-Mail (Wortlaut E-Mail 03). $reason: zielgruppe | ebene | voll. */
function x25_reject(array $rec, string $by, string $reason = 'zielgruppe'): array
{
    $store = x25_store();
    $store->update((int)$rec['id'], ['status' => 'abgesagt', 'decided_at' => gmdate('c'), 'decided_by' => $by, 'reject_reason' => $reason, 'action_nonce' => x25_token(12)]);
    $rec = $store->get((int)$rec['id']);
    x25_mail_absage($rec, $reason);
    return $rec;
}
/** Warteliste (bei automatischer Zulassung, wenn alle Plätze belegt sind). */
function x25_waitlist(array $rec, string $by): array
{
    $store = x25_store();
    $store->update((int)$rec['id'], ['status' => 'warteliste', 'decided_at' => gmdate('c'), 'decided_by' => $by, 'action_nonce' => x25_token(12)]);
    $rec = $store->get((int)$rec['id']);
    x25_mail_warteliste($rec);
    return $rec;
}
/** Zahlungsweg Rechnung: Rechnungsnummer vergeben (einmal), Rechnung mailen, Gastgeber mit „Zahlung eingegangen"-Link informieren. */
function x25_choose_invoice(array $rec): array
{
    $store = x25_store();
    $rec = $store->transaction(function (X25Store $s) use ($rec) {
        $cur = $s->get((int)$rec['id']);
        if (($cur['invoice_no'] ?? '') === '') {
            $n = $s->nextNumber('rechnung');
            $year = (string)x25_cfg('INVOICE_YEAR', date('Y'));
            $no = (string)x25_cfg('INVOICE_PREFIX', '25X-') . $year . '-' . str_pad((string)$n, 4, '0', STR_PAD_LEFT);
            $date = new DateTimeImmutable('today');
            $s->update((int)$rec['id'], [
                'payment_method' => 'rechnung', 'invoice_no' => $no, 'invoice_date' => $date->format('Y-m-d'),
                'invoice_due' => $date->modify('+' . x25_conf()['payment_days'] . ' days')->format('Y-m-d'),
                'action_nonce' => x25_token(12),
            ]);
        }
        return $s->get((int)$rec['id']);
    });
    x25_mail_rechnung($rec);
    x25_mail_hosts_rechnung($rec);
    return $rec;
}
/** Zahlungseingang: Status bezahlt, Ticketnummer vergeben, Ticket-Mail. $method = paypal | rechnung | manuell. */
function x25_mark_paid(array $rec, string $method, string $by, array $details = []): array
{
    $store = x25_store();
    $rec = $store->transaction(function (X25Store $s) use ($rec, $method, $by, $details) {
        $cur = $s->get((int)$rec['id']);
        $f = ['payment_status' => 'bezahlt', 'paid_at' => $cur['paid_at'] ?? gmdate('c'), 'paid_by' => $by, 'action_nonce' => x25_token(12)];
        if (($cur['payment_method'] ?? '') === '') { $f['payment_method'] = $method; }
        foreach ($details as $k => $v) { $f[$k] = $v; }
        if (($cur['ticket_no'] ?? '') === '') {
            $n = $s->nextNumber('ticket');
            $f['ticket_no'] = (string)x25_cfg('TICKET_PREFIX', '25X-CM-') . str_pad((string)$n, 3, '0', STR_PAD_LEFT);
        }
        $s->update((int)$rec['id'], $f);
        return $s->get((int)$rec['id']);
    });
    x25_send_ticket($rec);
    x25_mail_hosts_bezahlt($rec);
    return $rec;
}
/** Ticket-Mail (mit QR-Code als eingebettetem PNG) senden; auch für „Ticket erneut senden". */
function x25_send_ticket(array $rec): void
{
    x25_mail_ticket($rec);
    x25_store()->update((int)$rec['id'], ['ticket_sent_at' => gmdate('c')]);
}

// ------------------------------------------------------------------ QR-Code (lib/barcode.php, MIT, Kreative Software)
function x25_qr_svg(string $data): string
{
    require_once __DIR__ . '/barcode.php';
    $g = new barcode_generator();
    return $g->render_svg('qr', $data, ['sf' => 4, 'p' => 8, 'ec' => 'm', 'ms' => 's', 'cm' => X25_INK]);
}
/** PNG-Bytes (GD); ohne GD leer. */
function x25_qr_png(string $data): string
{
    if (!function_exists('imagepng')) { return ''; }
    require_once __DIR__ . '/barcode.php';
    $g = new barcode_generator();
    $img = $g->render_image('qr', $data, ['sf' => 6, 'p' => 12, 'ec' => 'm']);
    ob_start(); imagepng($img); $png = (string)ob_get_clean();
    imagedestroy($img);
    return $png;
}

// ================================================================== Mails an den Anmelder
/** E-Mail 01: Eingangsbestätigung bei Status „prüfung"; $freemail = Hinweis auf geschäftliche Adresse. */
function x25_mail_pruefung(array $rec, bool $freemail): void
{
    $c = x25_conf();
    $subj = 'Ihre Anmeldung ist eingegangen · ' . $c['edition_name'];
    $pre = 'Wir prüfen die Zugehörigkeit und melden uns. Zahlung erst nach Zusage.';
    $a1 = 'vielen Dank für Ihre Anmeldung zu ' . $c['edition_name'] . ' am ' . $c['edition_datum'] . ' in ' . $c['edition_ort'] . '.';
    $a2 = 'So geht es weiter: Wir prüfen die Zugehörigkeit zu einem Versicherer, Maklerpool oder Versicherungsvertrieb und melden uns innerhalb von ' . $c['review_days'] . ' Werktagen [TBD: Frist] mit der Entscheidung. Der Raum hat genau 25 Plätze, und wir achten auf die fachliche Zuordnung und die Ebene der Teilnehmer: Der Tisch ist für die Verantwortlichen der Funktion bei Versicherern, Maklerpools und Versicherungsvertrieben gedacht: erste und zweite Führungsebene (Team-, Abteilungs- und Bereichsleitung sowie Vorstandsstab).';
    $a2b = $freemail ? 'Hinweis: Sie haben sich mit einer privaten E-Mail-Adresse angemeldet. Eine geschäftliche Adresse Ihres Unternehmens beschleunigt die Prüfung; antworten Sie dazu einfach kurz auf diese E-Mail.' : '';
    $a3 = 'Bis dahin entsteht keine Zahlungspflicht. Die Zahlungsaufforderung über ' . x25_money(x25_amounts()['net']) . ' netto zzgl. USt. erhalten Sie erst nach unserer Zusage.';
    $a4 = 'Sollten Sie Ihre Anmeldung zurückziehen wollen, genügt eine kurze Antwort auf diese E-Mail.';
    $rows = x25_rows_person($rec);
    $txt = "Guten Tag " . $rec['name'] . ",\n\n" . x25_wrap($a1) . "\n\n" . x25_wrap($a2) . "\n\n" . ($a2b !== '' ? x25_wrap($a2b) . "\n\n" : '')
        . x25_wrap($a3) . "\n\n" . x25_wrap($a4) . "\n\nZur Kontrolle Ihre Angaben:\n" . x25_t_rows($rows)
        . "Ihre eine offene Frage:\n" . x25_wrap($rec['question']) . "\n\n" . x25_t_sig();
    $html = x25_html_shell($subj,
        x25_h_kicker('Anmeldung eingegangen') . x25_h_h1('Guten Tag ' . x25_e($rec['name']) . ',')
        . x25_h_p(x25_e($a1)) . x25_h_p(x25_e($a2))
        . ($a2b !== '' ? x25_h_box(x25_h_p(x25_e($a2b), 'margin:0;'), X25_ORANGE) : '')
        . x25_h_box(x25_h_p(x25_e($a3), 'margin:0;')) . x25_h_p(x25_e($a4))
        . x25_h_sub('Zur Kontrolle Ihre Angaben') . x25_h_rows($rows)
        . x25_h_p('<span style="font-family:' . X25_MONO . ';font-size:12px;letter-spacing:1px;text-transform:uppercase;color:' . X25_PETROL . ';">Ihre eine offene Frage</span><br>' . nl2br(x25_e($rec['question'])), 'font-family:Georgia,\'Times New Roman\',serif;font-size:17px;line-height:26px;color:' . X25_INK . ';')
        . x25_h_sig(), true, $pre);
    x25_send_person($rec, $subj, $html, $txt, 'bestaetigung');
}

/** E-Mail 02: Zusage + Zahlungsaufforderung (PayPal oder Rechnung über zahlung.php). */
function x25_mail_zusage(array $rec): void
{
    $c = x25_conf(); $a = x25_amounts();
    $subj = 'Zusage: Ihr Platz bei ' . $c['edition_name'] . ' · Zahlungsaufforderung';
    $pre = 'Einer von 25. Bitte wählen Sie PayPal oder Rechnung; nach Zahlungseingang erhalten Sie Ihr Ticket.';
    $url = x25_pay_url($rec);
    $a1 = 'wir freuen uns, Ihnen einen der 25 Plätze bei ' . $c['edition_name'] . ' zuzusagen.';
    $rows = [['Termin', $c['edition_datum']], ['Ort', $c['edition_venue']], ['Beitrag', x25_money($a['net']) . ' netto zzgl. ' . (int)round($a['rate'] * 100) . ' % USt. = ' . x25_money($a['gross']) . ' brutto']];
    $a2 = 'Zahlungsaufforderung: Bitte begleichen Sie den Teilnahmebeitrag über die folgende Seite. Dort können Sie zwischen PayPal und Zahlung per Rechnung (Zahlungsziel ' . $c['payment_days'] . ' Tage) wählen. Mit dem Zahlungseingang ist Ihr Platz verbindlich; Sie erhalten dann Ihr Ticket und alle weiteren Informationen. Sollten Sie verhindert sein, sagen Sie uns bitte kurz Bescheid, damit wir den Platz weitergeben können.';
    $a3 = 'Vorbereitung ist nicht nötig. Bringen Sie Ihre eine offene Frage mit, so wie Sie sie im Formular gestellt haben. Aus den 25 Fragen entsteht am ersten Vormittag die Arbeitsagenda; das ist das Programm. Mit Ihnen am Tisch: 24 weitere Führungskräfte derselben Funktion aus anderen Versicherern, Maklerpools und Versicherungsvertrieben, von der Teamleitung bis zum Vorstandsstab, auf Augenhöhe.';
    $a4 = 'Zwei Wochen vor dem Termin senden wir Ihnen alle Details zu Ablauf, Anreise und Abend.';
    $txt = "Guten Tag " . $rec['name'] . ",\n\n" . x25_wrap($a1) . "\n\n" . x25_t_rows($rows) . "\n" . x25_wrap($a2) . "\n\nZur Zahlung (PayPal oder Rechnung):\n" . $url . "\n\n"
        . x25_wrap($a3) . "\n\n" . x25_wrap($a4) . "\n\n" . x25_t_sig();
    $html = x25_html_shell($subj,
        x25_h_kicker('Zusage') . x25_h_h1('Guten Tag ' . x25_e($rec['name']) . ',')
        . x25_h_p(x25_e($a1)) . x25_h_rows($rows)
        . x25_h_box(x25_h_p(x25_e($a2)) . x25_h_btn($url, 'Zur Zahlung: PayPal oder Rechnung') . x25_h_p('<span style="font-size:13px;color:' . X25_META . ';">Falls die Schaltfläche nicht funktioniert: ' . x25_e($url) . '</span>', 'margin:0;'))
        . x25_h_p(x25_e($a3)) . x25_h_p(x25_e($a4)) . x25_h_sig(), true, $pre);
    x25_send_person($rec, $subj, $html, $txt, 'zusage');
}

/** E-Mail 03: Absage. $reason: zielgruppe (Standard) | ebene | voll. */
function x25_mail_absage(array $rec, string $reason): void
{
    $c = x25_conf();
    $subj = 'Ihre Anmeldung zu ' . $c['edition_name'];
    $pre = 'Diesmal können wir Ihnen keinen Platz anbieten.';
    $a1 = 'vielen Dank, dass Sie sich zu ' . $c['edition_name'] . ' angemeldet haben. Wir müssen Ihnen leider absagen: Für diese Edition können wir Ihnen keinen der 25 Plätze anbieten.';
    $a2 = 'Das ist keine Aussage über Sie oder Ihre Arbeit. Der Raum ist bewusst klein, und wir stellen ihn nach fachlicher Zusammensetzung zusammen. Bei 25 Plätzen bleiben zwangsläufig Anmeldungen offen, die wir gern angenommen hätten.';
    $a3 = match ($reason) {
        'ebene' => 'Der Tisch ist für die Verantwortlichen der Funktion bei Versicherern, Maklerpools und Versicherungsvertrieben gedacht: erste und zweite Führungsebene (Team-, Abteilungs- und Bereichsleitung sowie Vorstandsstab). Wenn Sie uns die Person aus Ihrem Haus nennen, die diese Verantwortung trägt, sprechen wir sie gern selbst an.',
        'voll' => 'Alle 25 Plätze dieser Edition sind bereits vergeben.',
        default => 'Der Tisch ist ausschließlich für Mitarbeitende von Versicherern, Maklerpools und Versicherungsvertrieben gedacht. Nach unserer Prüfung können wir Ihre Anmeldung keinem dieser drei Unternehmenstypen zuordnen. Sollten wir uns irren, antworten Sie bitte kurz auf diese E-Mail, gern mit Ihrer geschäftlichen E-Mail-Adresse; wir prüfen dann erneut.',
    };
    $a4 = $reason === 'zielgruppe'
        ? 'Unabhängig davon melden wir uns gern, wenn ein Format zu Ihrer Fachfunktion angekündigt wird. Es entstehen keine Kosten.'
        : 'Wenn Sie möchten, nehmen wir Sie auf die Warteliste dieser Edition und informieren Sie, sobald ein Platz frei wird. Antworten Sie dazu einfach kurz auf diese E-Mail. Unabhängig davon melden wir uns, wenn die nächste Edition zu Ihrer Fachfunktion angekündigt wird. Es entstehen keine Kosten.';
    $txt = "Guten Tag " . $rec['name'] . ",\n\n" . x25_wrap($a1) . "\n\n" . x25_wrap($a2) . "\n\n" . x25_wrap($a3) . "\n\n" . x25_wrap($a4) . "\n\n" . x25_t_sig();
    $html = x25_html_shell($subj,
        x25_h_kicker('Ihre Anmeldung') . x25_h_h1('Guten Tag ' . x25_e($rec['name']) . ',')
        . x25_h_p(x25_e($a1)) . x25_h_p(x25_e($a2)) . x25_h_box(x25_h_p(x25_e($a3), 'margin:0;')) . x25_h_p(x25_e($a4)) . x25_h_sig(), true, $pre);
    x25_send_person($rec, $subj, $html, $txt, 'absage');
}

/** Warteliste (alle Plätze belegt). */
function x25_mail_warteliste(array $rec): void
{
    $c = x25_conf();
    $subj = 'Ihre Anmeldung zu ' . $c['edition_name'] . ' · Warteliste';
    $pre = 'Alle 25 Plätze sind vergeben. Wir führen Sie auf der Warteliste.';
    $a1 = 'vielen Dank für Ihre Anmeldung zu ' . $c['edition_name'] . ' am ' . $c['edition_datum'] . ' in ' . $c['edition_ort'] . '.';
    $a2 = 'Der Raum hat genau 25 Plätze, und alle sind derzeit vergeben. Wir führen Sie auf der Warteliste und melden uns, sobald ein Platz frei wird. Es entsteht keine Zahlungspflicht; die Zahlungsaufforderung erhalten Sie erst mit unserer Zusage.';
    $a3 = 'Sollten Sie nicht auf der Warteliste bleiben wollen, genügt eine kurze Antwort auf diese E-Mail.';
    $txt = "Guten Tag " . $rec['name'] . ",\n\n" . x25_wrap($a1) . "\n\n" . x25_wrap($a2) . "\n\n" . x25_wrap($a3) . "\n\n" . x25_t_sig();
    $html = x25_html_shell($subj, x25_h_kicker('Warteliste') . x25_h_h1('Guten Tag ' . x25_e($rec['name']) . ',')
        . x25_h_p(x25_e($a1)) . x25_h_box(x25_h_p(x25_e($a2), 'margin:0;'), X25_ORANGE) . x25_h_p(x25_e($a3)) . x25_h_sig(), true, $pre);
    x25_send_person($rec, $subj, $html, $txt, 'warteliste');
}

/** E-Mail 04: Rechnung (HTML-Mail mit allen Pflichtangaben + Link zur druckbaren Rechnungsseite). */
function x25_mail_rechnung(array $rec): void
{
    $c = x25_conf(); $a = x25_amounts();
    $subj = 'Rechnung ' . $rec['invoice_no'] . ' · ' . $c['edition_name'];
    $pre = 'Zahlungsziel ' . $c['payment_days'] . ' Tage. Die Rechnung finden Sie unten und als druckbare Seite.';
    $url = x25_invoice_url($rec);
    $a1 = 'anbei erhalten Sie die Rechnung für Ihre Teilnahme an ' . $c['edition_name'] . ' am ' . $c['edition_datum'] . '. Über den Link unten können Sie die Rechnung als PDF speichern oder drucken (Browser: „Drucken" → „Als PDF speichern").';
    $rows = x25_invoice_rows($rec);
    $a2 = 'Mit dem Zahlungseingang ist Ihr Platz verbindlich; Sie erhalten dann Ihr Ticket und alle weiteren Informationen. Sollte Ihre Buchhaltung eine Bestellnummer oder abweichende Rechnungsanschrift benötigen, antworten Sie einfach auf diese E-Mail; wir stellen die Rechnung neu aus.';
    $txt = "Guten Tag " . $rec['name'] . ",\n\n" . x25_wrap($a1) . "\n\n" . x25_t_rows($rows) . "\nRechnung online (druckbar): " . $url . "\n\n" . x25_wrap($a2) . "\n\n" . x25_t_sig();
    $html = x25_html_shell($subj, x25_h_kicker('Rechnung') . x25_h_h1('Guten Tag ' . x25_e($rec['name']) . ',')
        . x25_h_p(x25_e($a1)) . x25_h_box(x25_h_rows($rows) . x25_h_btn($url, 'Rechnung ansehen / als PDF speichern'))
        . x25_h_p(x25_e($a2)) . x25_h_sig(), true, $pre);
    x25_send_person($rec, $subj, $html, $txt, 'rechnung');
}

/** Rechnungszeilen (Pflichtangaben § 14 UStG) für Mail und Rechnungsseite. */
function x25_invoice_rows(array $rec): array
{
    $c = x25_conf(); $a = x25_amounts();
    return [
        ['Rechnungsnummer', $rec['invoice_no']],
        ['Rechnungsdatum', x25_date($rec['invoice_date'], 'd.m.Y')],
        ['Leistungsdatum', $c['leistungsdatum'] . ' (Veranstaltungstage)'],
        ['Leistung', 'Teilnahme ' . $c['edition_name'] . ', ' . $c['leistungsdatum'] . ', ' . $c['edition_ort'] . ' (1 Person: ' . $rec['name'] . ')'],
        ['Netto', x25_money($a['net'])],
        ['USt. ' . (int)round($a['rate'] * 100) . ' %', x25_money($a['vat'])],
        ['Brutto', x25_money($a['gross'])],
        ['Zahlungsziel', x25_date($rec['invoice_due'], 'd.m.Y') . ' (' . $c['payment_days'] . ' Tage ab Rechnungsdatum)'],
        ['Empfänger', (string)x25_cfg('BANK_HOLDER', '25 Experts Cologne UG (haftungsbeschränkt)')],
        ['IBAN', (string)x25_cfg('BANK_IBAN', '[TBD: IBAN]')],
        ['BIC', (string)x25_cfg('BANK_BIC', '[TBD: BIC]')],
        ['Bank', (string)x25_cfg('BANK_NAME', '[TBD: Bank]')],
        ['Verwendungszweck', $rec['invoice_no'] . ' · ' . $rec['name']],
    ];
}

/** Ticket-Mail mit Ticketnummer, QR-Code (eingebettetes PNG) und allen weiteren Informationen. */
function x25_mail_ticket(array $rec): void
{
    $c = x25_conf();
    $subj = 'Ihr Ticket ' . $rec['ticket_no'] . ' · ' . $c['edition_name'];
    $pre = 'Ihr Platz ist verbindlich. Ticket, Ort, Zeiten und Kontakt.';
    $url = x25_ticket_url($rec);
    $a1 = 'Ihre Zahlung ist eingegangen, Ihr Platz bei ' . $c['edition_name'] . ' ist damit verbindlich. Anbei Ihr Ticket; bitte zeigen Sie es am Empfang vor (Ausdruck oder Smartphone).';
    $rows = [['Ticketnummer', $rec['ticket_no']], ['Name', $rec['name']], ['Unternehmen', $rec['company']], ['Termin', $c['edition_datum']], ['Zeiten', $c['edition_zeiten']], ['Ort', $c['edition_venue']], ['Hotel', $c['edition_hotel']], ['Kontakt', $c['edition_kontakt']]];
    $a2 = 'Vorbereitung ist nicht nötig. Bringen Sie Ihre eine offene Frage mit, so wie Sie sie im Formular gestellt haben. Zwei Wochen vor dem Termin senden wir Ihnen alle Details zu Ablauf, Anreise und Abend.';
    $a3 = 'Sollten Sie verhindert sein, sagen Sie uns bitte kurz Bescheid; ein Ersatzteilnehmer aus Ihrem Haus und derselben Funktion kann jederzeit benannt werden [TBD: Stornoregeln laut Teilnahmebedingungen].';
    $png = x25_qr_png($url);
    $qrHtml = $png !== ''
        ? '<img src="cid:ticketqr" width="200" height="200" alt="QR-Code zum Ticket" style="display:block;width:200px;height:200px;border:0;">'
        : '';
    $txt = "Guten Tag " . $rec['name'] . ",\n\n" . x25_wrap($a1) . "\n\n" . x25_t_rows($rows) . "\nIhr Ticket online (mit QR-Code, druckbar): " . $url . "\n\n" . x25_wrap($a2) . "\n\n" . x25_wrap($a3) . "\n\n" . x25_t_sig();
    $html = x25_html_shell($subj, x25_h_kicker('Ticket') . x25_h_h1('Guten Tag ' . x25_e($rec['name']) . ',')
        . x25_h_p(x25_e($a1))
        . x25_h_box('<table role="presentation" cellpadding="0" cellspacing="0" border="0"><tr><td valign="top" style="padding-right:20px;">' . $qrHtml . '</td><td valign="top">'
            . '<p style="margin:0 0 6px 0;font-family:' . X25_MONO . ';font-size:12px;letter-spacing:1px;text-transform:uppercase;color:' . X25_PETROL . ';">Ticketnummer</p>'
            . '<p style="margin:0 0 12px 0;font-family:' . X25_MONO . ';font-size:24px;line-height:30px;font-weight:700;color:' . X25_INK . ';">' . x25_e($rec['ticket_no']) . '</p>'
            . x25_h_btn($url, 'Ticket öffnen / drucken') . '</td></tr></table>')
        . x25_h_sub('Alle weiteren Informationen') . x25_h_rows($rows)
        . x25_h_p(x25_e($a2)) . x25_h_p(x25_e($a3)) . x25_h_sig(), true, $pre);
    x25_send_person($rec, $subj, $html, $txt, 'ticket', $png !== '' ? ['ticketqr' => [$png, 'ticket-qr.png', 'image/png']] : []);
}

// ================================================================== Mails an die Gastgeber (MAIL_TO)
/** Neue Anmeldung: Info (zugelassen/warteliste) bzw. Prüfauftrag mit den zwei signierten Links (prüfung). */
function x25_mail_hosts_neu(array $rec): void
{
    $c = x25_conf();
    $status = $rec['status'];
    $label = X25_STATUS[$status] ?? $status;
    $subj = 'Neue Anmeldung (' . $label . '): ' . $rec['name'] . ', ' . $rec['company'] . ' · ' . $c['edition_name'];
    $rows = x25_rows_person($rec, true);
    $rows[] = ['Status', $label];
    $intro = match ($status) {
        'zugelassen' => 'Die E-Mail-Domain steht auf der Allowlist; die Anmeldung wurde automatisch zugelassen. Zusage und Zahlungsaufforderung sind an die Person unterwegs. Nichts weiter zu tun, bis die Zahlung eingeht (PayPal: automatisch; Rechnung: Sie erhalten eine Mail mit Link „Zahlung eingegangen").',
        'warteliste' => 'Alle ' . $c['max_seats'] . ' Plätze sind belegt; die Anmeldung steht auf der Warteliste (Person ist informiert). Zulassen können Sie sie im Admin, sobald ein Platz frei wird.',
        default => 'Bitte prüfen: Die E-Mail-Domain ist nicht auf der Allowlist (' . ($rec['admission_note'] ?? '') . '). Gehört die Person zu einem Versicherer, Maklerpool oder Versicherungsvertrieb? Entscheiden Sie mit einem der beiden Links; die Person erhält dann automatisch die Zusage mit Zahlungsaufforderung bzw. die Absage. Die Links sind einmalig gültig.',
    };
    $btns = ''; $txtLinks = '';
    if ($status === 'pruefung') {
        $ok = x25_action_url($rec, 'zulassen'); $no = x25_action_url($rec, 'absagen');
        $btns = x25_h_btn($ok, 'Zulassen') . x25_h_btn($no, 'Absagen', X25_ORANGE);
        $txtLinks = "ZULASSEN:  " . $ok . "\nABSAGEN:   " . $no . "\n\n";
    }
    $txt = x25_wrap($intro) . "\n\n" . $txtLinks . x25_t_rows($rows) . "\nIhre eine offene Frage:\n" . x25_wrap($rec['question']) . "\n\nAdmin: " . x25_admin_url() . "\nAntworten Sie direkt auf diese E-Mail, um die Person zu erreichen (Reply-To ist gesetzt).\n";
    $html = x25_html_shell($subj,
        x25_h_kicker('Neue Anmeldung · ' . x25_e($label)) . x25_h_h1(x25_e($rec['name']) . '<br><span style="font-weight:400;color:' . X25_BODY . ';">' . x25_e($rec['company']) . '</span>')
        . x25_h_box(x25_h_p(x25_e($intro)) . $btns, $status === 'pruefung' ? X25_ORANGE : X25_PETROL)
        . x25_h_rows($rows)
        . x25_h_sub('Ihre eine offene Frage')
        . x25_h_box(x25_h_p(nl2br(x25_e($rec['question'])), 'margin:0;font-family:Georgia,\'Times New Roman\',serif;font-size:17px;line-height:26px;color:' . X25_INK . ';'), X25_ORANGE)
        . x25_h_p('<a href="' . x25_e(x25_admin_url()) . '" style="color:' . X25_PETROL . ';">Admin-Übersicht</a> · Antworten Sie direkt auf diese E-Mail, um die Person zu erreichen (Reply-To ist gesetzt).', 'font-size:14px;line-height:20px;color:' . X25_META . ';'),
        false);
    x25_send_hosts($subj, $html, $txt, 'benachrichtigung', [$rec['email'], $rec['name']]);
}
/** Rechnung gewählt: Link „Zahlung eingegangen" (signiert, einmalig). */
function x25_mail_hosts_rechnung(array $rec): void
{
    $c = x25_conf();
    $subj = 'Rechnung ' . $rec['invoice_no'] . ' erstellt: ' . $rec['name'] . ', ' . $rec['company'];
    $url = x25_action_url($rec, 'bezahlt');
    $intro = 'Die Person hat „Zahlung per Rechnung" gewählt. Rechnung ' . $rec['invoice_no'] . ' über ' . x25_money(x25_amounts()['gross']) . ' brutto ist versandt, Zahlungsziel ' . x25_date($rec['invoice_due'], 'd.m.Y') . '. Sobald der Betrag auf dem Konto ist, bitte hier markieren; die Person erhält dann automatisch das Ticket.';
    $rows = [['Name', $rec['name']], ['Unternehmen', $rec['company']], ['E-Mail', $rec['email']], ['Rechnung', $rec['invoice_no']], ['Rechnung online', x25_invoice_url($rec)]];
    $txt = x25_wrap($intro) . "\n\nZAHLUNG EINGEGANGEN: " . $url . "\n\n" . x25_t_rows($rows) . "\nAdmin: " . x25_admin_url() . "\n";
    $html = x25_html_shell($subj, x25_h_kicker('Rechnung') . x25_h_h1(x25_e($rec['name']) . '<br><span style="font-weight:400;color:' . X25_BODY . ';">' . x25_e($rec['company']) . '</span>')
        . x25_h_box(x25_h_p(x25_e($intro)) . x25_h_btn($url, 'Zahlung eingegangen → Ticket senden')) . x25_h_rows($rows)
        . x25_h_p('<a href="' . x25_e(x25_admin_url()) . '" style="color:' . X25_PETROL . ';">Admin-Übersicht</a>', 'font-size:14px;color:' . X25_META . ';'), false);
    x25_send_hosts($subj, $html, $txt, 'benachrichtigung-rechnung', [$rec['email'], $rec['name']]);
}
/** Zahlung eingegangen (PayPal oder manuell): Info mit Ticketnummer. */
function x25_mail_hosts_bezahlt(array $rec): void
{
    $subj = 'Zahlung eingegangen (' . ($rec['payment_method'] ?? '') . '): ' . $rec['name'] . ', ' . $rec['company'] . ' · Ticket ' . $rec['ticket_no'];
    $rows = [['Name', $rec['name']], ['Unternehmen', $rec['company']], ['E-Mail', $rec['email']], ['Zahlungsweg', $rec['payment_method'] ?? ''], ['Ticket', $rec['ticket_no']], ['Bezahlt am', x25_date($rec['paid_at'] ?? null)]];
    if (!empty($rec['paypal_capture_id'])) { $rows[] = ['PayPal Capture', $rec['paypal_capture_id']]; }
    if (!empty($rec['invoice_no'])) { $rows[] = ['Rechnung', $rec['invoice_no']]; }
    $intro = 'Die Zahlung ist verbucht, das Ticket ist an die Person unterwegs. Nichts weiter zu tun.';
    $txt = x25_wrap($intro) . "\n\n" . x25_t_rows($rows) . "\nAdmin: " . x25_admin_url() . "\n";
    $html = x25_html_shell($subj, x25_h_kicker('Zahlung eingegangen') . x25_h_h1(x25_e($rec['name'])) . x25_h_p(x25_e($intro)) . x25_h_rows($rows), false);
    x25_send_hosts($subj, $html, $txt, 'benachrichtigung-bezahlt');
}
