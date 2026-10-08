<?php

declare(strict_types=1);

use Core\CMS;
use Core\Mail;

/**
 * Wird von index.php generisch aufgerufen (Konvention: components/<type>/submit.php,
 * aufgerufen über POST ?action=<type>). CSRF ist bereits geprüft, bevor dieser Hook
 * läuft. Core kennt "contact-form" nicht beim Namen.
 *
 * @param array<string, mixed> $post
 * @return array{ok: bool, message: string, errors?: array<string, string>}
 */
return static function (array $post, CMS $cms): array {
    if (trim((string) ($post['website'] ?? '')) !== '') {
        return ['ok' => true, 'message' => 'Danke, wir haben Ihre Nachricht erhalten.'];
    }

    $name = trim((string) ($post['name'] ?? ''));
    $email = trim((string) ($post['email'] ?? ''));
    $phone = trim((string) ($post['phone'] ?? ''));
    $subject = trim((string) ($post['subject'] ?? ''));
    $message = trim((string) ($post['message'] ?? ''));

    $errors = [];
    if ($name === '') {
        $errors['name'] = 'Bitte geben Sie Ihren Namen ein.';
    } elseif (preg_match('/\d/', $name) === 1) {
        $errors['name'] = 'Bitte geben Sie einen gültigen Namen ohne Zahlen ein.';
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = 'Bitte geben Sie eine gültige E-Mail-Adresse ein.';
    }
    if ($phone !== '' && preg_match('/^[0-9+\-\/() .]+$/', $phone) !== 1) {
        $errors['phone'] = 'Bitte geben Sie eine gültige Telefonnummer ein (nur Ziffern und + - / ( ) erlaubt).';
    }
    if ($message === '') {
        $errors['message'] = 'Bitte beschreiben Sie Ihr Vorhaben kurz.';
    }
    if ($errors !== []) {
        return ['ok' => false, 'message' => 'Bitte prüfen Sie Ihre Angaben.', 'errors' => $errors];
    }

    $entry = [
        'at' => date('c'),
        'name' => $name,
        'email' => $email,
        'phone' => $phone,
        'subject' => $subject,
        'message' => $message,
    ];

    // Anfragen in data/ (per .htaccess gesperrt, vom Deploy nie übertragen oder
    // gelöscht) – früher lagen sie in cache/, das der Deploy-Workflow bei jedem
    // Lauf leert: schlug die Mail fehl, war die Anfrage damit endgültig weg.
    $root = $cms->root();
    $file = $root . '/data/inquiries.json';
    $list = is_file($file) ? CMS::readJson($file) : [];
    if (!isset($list['items']) || !is_array($list['items'])) {
        $list = ['items' => []];
    }
    $legacy = $root . '/cache/inquiries.json';
    if (is_file($legacy)) {
        $old = CMS::readJson($legacy)['items'] ?? [];
        $list['items'] = array_merge(is_array($old) ? $old : [], $list['items']);
        @unlink($legacy);
    }

    $content = $cms->content();
    $to = class_exists(Mail::class) ? Mail::businessEmail($content) : trim((string) ($content['business']['email'] ?? ''));
    $siteTitle = $content['site']['title'] ?? 'Website';
    // Eigene Variable für den Mail-Betreff – früher überschrieb er $subject, im
    // Text stand dann statt des gewählten Anliegens nochmal "Anfrage: …".
    $mailSubject = 'Anfrage: ' . (is_array($siteTitle) ? (string) reset($siteTitle) : (string) $siteTitle)
        . ($subject !== '' ? ' – ' . $subject : '');
    $body = "Name: {$name}\nE-Mail: {$email}\n" . ($phone !== '' ? "Telefon: {$phone}\n" : '')
        . ($subject !== '' ? "Anliegen: {$subject}\n" : '') . "\n{$message}\n";

    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        $entry['mail'] = 'kein-empfaenger';
    } elseif (class_exists(Mail::class)) {
        $entry['mail'] = Mail::send($cms->config(), $content, $to, $mailSubject, $body, $email) ? 'gesendet' : 'fehlgeschlagen';
    } else {
        // Core älter als 2.6.0 (ohne Core\Mail): bisheriger Weg, aber mit UTF-8-Headern.
        $sent = @mail($to, '=?UTF-8?B?' . base64_encode($mailSubject) . '?=', $body,
            "From: {$to}\r\nReply-To: {$email}\r\nMIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8");
        $entry['mail'] = $sent ? 'gesendet' : 'fehlgeschlagen';
    }
    if ($entry['mail'] === 'fehlgeschlagen') {
        error_log('contact-form: mail() an ' . $to . ' fehlgeschlagen – Anfrage liegt in data/inquiries.json');
    }

    $list['items'][] = $entry;
    CMS::writeJson($file, $list);

    return ['ok' => true, 'message' => 'Danke, wir haben Ihre Nachricht erhalten.'];
};
