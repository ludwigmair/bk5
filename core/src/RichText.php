<?php

declare(strict_types=1);

namespace Core;

/**
 * Leichte Inline-Formatierung für Fließtext-Felder (Twig-Filter `|rich`):
 * `**fett**`, `*kursiv*`, `%%Hinweiszeile%%`, `::Hervorhebung::`, `[Text](url)`
 * (nur http(s)/mailto/tel/#/relativ; fremder Host → neuer Tab). Escaped zuerst
 * alles per htmlspecialchars, danach werden nur diese Markierungen in
 * HTML übersetzt – kein Roh-HTML aus dem Admin möglich.
 */
final class RichText
{
    public static function render(?string $text): string
    {
        $text = (string) $text;
        if ($text === '') {
            return '';
        }

        $escaped = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        return self::applyMarks($escaped);
    }

    private static function applyMarks(string $t): string
    {
        $t = preg_replace_callback('/\[([^\]\n]+)\]\(([^)\s]+)\)/', static function (array $m): string {
            $url = html_entity_decode($m[2], ENT_QUOTES, 'UTF-8');
            if (!preg_match('#^(https?://|mailto:|tel:|/|\#)#i', $url)) {
                return $m[0];
            }
            $safe = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
            $external = preg_match('#^https?://#i', $url) === 1;
            $attrs = $external ? ' target="_blank" rel="noopener noreferrer"' : '';
            return '<a href="' . $safe . '"' . $attrs . '>' . $m[1] . '</a>';
        }, $t) ?? $t;

        $t = preg_replace('/%%(.+?)%%/s', '<span class="rich-note">$1</span>', $t) ?? $t;
        $t = preg_replace('/::(.+?)::/s', '<span class="rich-highlight">$1</span>', $t) ?? $t;
        $t = preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $t) ?? $t;
        $t = preg_replace('/(?<!\*)\*(?!\*)([^*\n]+?)\*(?!\*)/s', '<em>$1</em>', $t) ?? $t;

        return $t;
    }

    /**
     * Wie render(), aber mit Absatz-Struktur (Twig-Filter `|rich_block`):
     * doppelter Zeilenumbruch = neuer <p>-Absatz, einfacher = <br>. Für
     * mehrzeilige Blöcke ohne umschließendes <p> im Template (z. B. Rechtstexte).
     *
     * Ein Absatz, dessen Zeilen alle mit "- " beginnen, wird stattdessen als
     * echte Liste (<ul class="rich-list">) gerendert – Häkchen-Optik kommt
     * rein per CSS (siehe layout.twig), kein zusätzliches Markup nötig.
     */
    public static function renderBlock(?string $text): string
    {
        // Browser normalisieren Zeilenumbrüche in <textarea>-Formularwerten beim Absenden
        // zu \r\n (WHATWG-Formularregel) – ohne diese Normalisierung auf \n schlagen sowohl
        // die Absatz-Erkennung (\n{2,}) als auch die Listen-Erkennung fehl.
        $text = str_replace(["\r\n", "\r"], "\n", (string) $text);
        $text = trim($text);
        if ($text === '') {
            return '';
        }

        $out = [];
        foreach (preg_split('/\n{2,}/', $text) ?: [$text] as $para) {
            $para = trim($para);
            if ($para === '') {
                continue;
            }

            $lines = preg_split('/\n/', $para) ?: [$para];
            $isList = $lines !== [] && array_reduce(
                $lines,
                static fn (bool $carry, string $line): bool => $carry && (bool) preg_match('/^-\s+\S/', trim($line)),
                true
            );

            if ($isList) {
                $items = array_map(static function (string $line): string {
                    $line = preg_replace('/^-\s+/', '', trim($line)) ?? trim($line);
                    return '<li>' . self::render($line) . '</li>';
                }, $lines);
                $out[] = '<ul class="rich-list">' . implode('', $items) . '</ul>';
                continue;
            }

            $out[] = '<p>' . nl2br(self::render($para), false) . '</p>';
        }
        return implode('', $out);
    }
}
