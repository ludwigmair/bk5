<?php

declare(strict_types=1);

namespace Core;

/**
 * Schutz von E-Mail-Adressen und Telefonnummern vor Adress-Sammlern: läuft
 * einmal über das fertige Seiten-HTML (CMS::renderShell(), also vor dem
 * Seiten-Cache) statt in jeder Vorlage – gilt damit automatisch für alle
 * Header/Footer/Topbars/Components/Rich-Text und künftige Themes.
 *
 * - mailto:/tel:/WhatsApp-Links: href wird zu "#" + data-obf-href (kodiert).
 * - E-Mail-Adressen im Text: <span data-obf> mit Ersatztext "name [at] domain.de".
 * - Telefonnummern im Text: der Text von tel:-Links und die übergebenen Nummern
 *   (Stammdaten) – Ersatztext mit leeren Kommentaren zwischen den Ziffern.
 * - E-Mails in sonstigen Attributen (meta, title, …): "[at]"-Form, ohne Rückweg.
 * Ein kleines Inline-Skript vor </body> stellt Links und Text im Browser wieder
 * her; ohne JavaScript bleibt der Ersatztext lesbar. <script>/<style>/<textarea>
 * bleiben unangetastet (JSON-LD baut CMS::buildStructuredData() selbst ohne E-Mail).
 *
 * Bewusst ohne Abhängigkeiten (wie RichText/Markdown), damit tests/run.php die
 * Klasse direkt requiren kann.
 */
final class Obfuscate
{
    private const EMAIL = '[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}';
    private const HREF = '/\shref\s*=\s*(["\'])((?:mailto:|tel:|https?:\/\/(?:wa\.me|api\.whatsapp\.com)\/)[^"\']*)\1/i';

    /** @param list<string> $phones Telefonnummern, wie sie auf der Seite stehen (z. B. Stammdaten) */
    public static function html(string $html, array $phones = []): string
    {
        $phones = array_values(array_unique(array_filter(array_map('trim', $phones), static fn (string $p): bool => preg_match('/\d{4,}/', preg_replace('/\D/', '', $p) ?? '') === 1)));
        usort($phones, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        $parts = preg_split('/(<script\b.*?<\/script>|<style\b.*?<\/style>|<textarea\b.*?<\/textarea>|<!--.*?-->|<[^>]+>)/is', $html, -1, PREG_SPLIT_DELIM_CAPTURE);
        if ($parts === false) {
            return $html;
        }

        $changed = false;
        $inTel = false;
        foreach ($parts as $i => $part) {
            if ($part === '') {
                continue;
            }
            if ($part[0] === '<') {
                if (preg_match('/^<(script|style|textarea|!--)/i', $part)) {
                    continue;
                }
                if (preg_match('/^<a\b/i', $part)) {
                    $inTel = false;
                    $new = preg_replace_callback(self::HREF, static function (array $m) use (&$inTel): string {
                        $inTel = stripos($m[2], 'mailto:') !== 0;
                        return ' href="#" data-obf-href="' . self::encode(html_entity_decode($m[2], ENT_QUOTES | ENT_HTML5, 'UTF-8')) . '"';
                    }, $part) ?? $part;
                } else {
                    if (preg_match('/^<\/a\b/i', $part)) {
                        $inTel = false;
                    }
                    $new = $part;
                }
                // Adresse für die Mail-Vorbelegung (layout.twig, data-mail-template) –
                // kodiert statt [at], sonst wäre der vorbelegte mailto-Link kaputt.
                $new = preg_replace_callback('/\sdata-mail-address\s*=\s*(["\'])([^"\']*)\1/i', static fn (array $m): string => $m[2] === '' ? $m[0] : ' data-obf-mail="' . self::encode(html_entity_decode($m[2], ENT_QUOTES | ENT_HTML5, 'UTF-8')) . '"', $new) ?? $new;
                // E-Mails in übrigen Attributen (alt, title, content, …)
                $new = preg_replace_callback('/' . self::EMAIL . '/', static fn (array $m): string => self::atForm($m[0]), $new) ?? $new;
                if ($new !== $part) {
                    $parts[$i] = $new;
                    $changed = true;
                }
                continue;
            }

            // Textknoten
            $new = preg_replace_callback('/' . self::EMAIL . '/', static fn (array $m): string => '<span data-obf="' . self::encode(html_entity_decode($m[0], ENT_QUOTES | ENT_HTML5, 'UTF-8')) . '">' . self::atForm($m[0]) . '</span>', $part) ?? $part;
            if ($inTel && trim($new) !== '' && preg_match('/\d{4,}/', preg_replace('/\D/', '', $new) ?? '') === 1 && !str_contains($new, 'data-obf')) {
                $new = self::phoneSpan($new);
            } else {
                foreach ($phones as $phone) {
                    $needle = htmlspecialchars($phone, ENT_QUOTES, 'UTF-8');
                    if (str_contains($new, $needle)) {
                        $new = str_replace($needle, self::phoneSpan($needle), $new);
                    }
                }
            }
            if ($new !== $part) {
                $parts[$i] = $new;
                $changed = true;
            }
        }

        $out = implode('', $parts);
        // Skript nur, wenn etwas wiederherzustellen ist (nicht bei reinen [at]-Attributen)
        if (!$changed || !str_contains($out, 'data-obf')) {
            return $out;
        }
        $script = '<script>(function(){function d(s){try{return decodeURIComponent(escape(atob(s))).split("").reverse().join("")}catch(e){return ""}}'
            . 'document.querySelectorAll("[data-obf-href],[data-obf],[data-obf-mail]").forEach(function(e){var h=e.getAttribute("data-obf-href");'
            . 'if(h!==null){e.setAttribute("href",d(h));e.removeAttribute("data-obf-href")}'
            . 'var m=e.getAttribute("data-obf-mail");if(m!==null){e.setAttribute("data-mail-address",d(m));e.removeAttribute("data-obf-mail")}'
            . 'var t=e.getAttribute("data-obf");if(t!==null){e.textContent=d(t);e.removeAttribute("data-obf")}})})();</script>';
        $pos = strripos($out, '</body>');

        return $pos === false ? $out . $script : substr($out, 0, $pos) . $script . substr($out, $pos);
    }

    /** Umgekehrt + Base64 (UTF-8-sicher) – reicht gegen Quelltext-Sammler, das Skript dreht es zurück. */
    public static function encode(string $s): string
    {
        return base64_encode(implode('', array_reverse(mb_str_split($s, 1, 'UTF-8'))));
    }

    private static function atForm(string $email): string
    {
        return str_replace('@', ' [at] ', $email);
    }

    /** $escaped ist bereits HTML-escaped (Textknoten). */
    private static function phoneSpan(string $escaped): string
    {
        $plain = html_entity_decode($escaped, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $fallback = implode('<!---->', array_map(static fn (string $c): string => htmlspecialchars($c, ENT_QUOTES, 'UTF-8'), mb_str_split($plain, 1, 'UTF-8')));

        return '<span data-obf="' . self::encode($plain) . '">' . $fallback . '</span>';
    }
}
