<?php

declare(strict_types=1);

namespace Core;

/**
 * Schlanker Versand über PHPs mail() – bewusst ohne SMTP-Bibliothek (läuft auf
 * jedem Shared-Hosting, das mail() anbietet). Kümmert sich um das, was ein
 * nacktes mail() falsch macht:
 *
 * - UTF-8: Content-Type-Header + MIME-kodierter Betreff/Absendername, sonst
 *   zeigen Mailprogramme Umlaute als "Ã¼".
 * - Absender: config.json → mail.from (z. B. website@<domain-des-webhosters>),
 *   sonst die Betriebs-E-Mail. Liegt das Postfach der Firma bei einem anderen
 *   Anbieter als der Webspace, landen Mails "von info@firma.de" vom Webserver
 *   oft im Spam (SPF/DMARC) – dann mail.from auf eine Adresse der Webhoster-
 *   Domain setzen. Die Betriebs-Adresse bleibt Empfänger, die Besucher-Adresse
 *   kommt als Reply-To.
 * - Envelope-Sender (-f), damit Bounces nicht beim Server-User landen.
 * - Header-Injection: Zeilenumbrüche in Header-Werten werden entfernt.
 */
final class Mail
{
    /**
     * @param array<string, mixed> $config  config.json
     * @param array<string, mixed> $content content.json (lokalisiert oder nicht)
     */
    public static function send(array $config, array $content, string $to, string $subject, string $body, string $replyTo = ''): bool
    {
        $to = self::clean($to);
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return false;
        }
        $from = self::fromAddress($config, $content);
        $fromName = self::clean(self::text($content['site']['title'] ?? ''));

        $headers = [
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit',
            'From: ' . ($fromName !== '' ? self::encode($fromName) . ' <' . $from . '>' : $from),
        ];
        $replyTo = self::clean($replyTo);
        if ($replyTo !== '' && filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
            $headers[] = 'Reply-To: ' . $replyTo;
        }

        // Zeilenenden normalisieren (RFC 5322: CRLF; mail() wandelt \n selbst).
        $body = str_replace(["\r\n", "\r"], "\n", $body);

        return @mail($to, self::encode(self::clean($subject)), $body, implode("\r\n", $headers), '-f' . $from);
    }

    /** Absender: config.mail.from, sonst Betriebs-E-Mail, sonst website@<aufgerufene Domain>. */
    public static function fromAddress(array $config, array $content): string
    {
        $configured = self::clean((string) ($config['mail']['from'] ?? ''));
        if (filter_var($configured, FILTER_VALIDATE_EMAIL)) {
            return $configured;
        }
        $business = self::clean(self::text($content['business']['email'] ?? ''));
        if (filter_var($business, FILTER_VALIDATE_EMAIL)) {
            return $business;
        }
        $host = preg_replace('/^www\./', '', (string) ($_SERVER['HTTP_HOST'] ?? 'localhost')) ?: 'localhost';

        return 'website@' . preg_replace('/[^a-z0-9.-]/i', '', $host);
    }

    /** Betriebs-E-Mail als String, auch wenn sie im (unlokalisierten) Content eine Sprach-Map ist. */
    public static function businessEmail(array $content): string
    {
        return self::clean(self::text($content['business']['email'] ?? ''));
    }

    private static function text(mixed $value): string
    {
        if (is_array($value)) {
            $value = $value['de'] ?? reset($value);
        }

        return trim((string) $value);
    }

    private static function clean(string $value): string
    {
        return trim(str_replace(["\r", "\n", "\0"], '', $value));
    }

    private static function encode(string $value): string
    {
        return preg_match('/[^\x20-\x7E]/', $value) === 1 ? '=?UTF-8?B?' . base64_encode($value) . '?=' : $value;
    }
}
