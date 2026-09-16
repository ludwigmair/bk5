<?php

declare(strict_types=1);

use Core\CMS;

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

    $root = $cms->root();
    $file = $root . '/cache/inquiries.json';
    $list = is_file($file) ? CMS::readJson($file) : [];
    if (!isset($list['items']) || !is_array($list['items'])) {
        $list = ['items' => []];
    }
    $list['items'][] = $entry;
    CMS::writeJson($file, $list);

    $to = (string) ($cms->content()['business']['email'] ?? '');
    if ($to !== '' && filter_var($to, FILTER_VALIDATE_EMAIL)) {
        $subject = 'Anfrage: ' . ($cms->content()['site']['title'] ?? 'Website');
        $body = "Name: {$name}\nE-Mail: {$email}\n" . ($phone !== '' ? "Telefon: {$phone}\n" : '')
            . ($subject !== '' ? "Anliegen: {$subject}\n" : '') . "\n{$message}\n";
        @mail($to, $subject, $body, 'From: ' . $to . "\r\nReply-To: " . $email);
    }

    return ['ok' => true, 'message' => 'Danke, wir haben Ihre Nachricht erhalten.'];
};
