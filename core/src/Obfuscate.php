<?php

declare(strict_types=1);

namespace Core;

/**
 * Schutz von E-Mail-Adressen und Telefonnummern vor Adress-Sammlern: läuft
 * einmal über das fertige Seiten-HTML (CMS::renderShell(), also vor dem
 * Seiten-Cache) statt in jeder Vorlage – gilt damit automatisch für alle
 * Header/Footer/Topbars/Components/Rich-Text und künftige Themes.
 *
 * Ziel: Die echte Adresse steht nirgends als Text in der Seite – weder im
 * Quelltext noch nach dem Laden im DOM (Element-Inspektor).
 *
 * - E-Mail-Adressen und Telefonnummern im Text (Text von tel:-Links und die
 *   übergebenen Nummern aus den Stammdaten): leeres <span>, die Anzeige kommt
 *   per CSS-Pseudo-Element aus RÜCKWÄRTS geschriebenen Attributen (E-Mail ohne
 *   „@“ in zwei Teilen), per bidi-override richtig herum dargestellt. Funktioniert
 *   ohne JavaScript; Kehrseite: der Text lässt sich nicht markieren/kopieren.
 * - mailto:/tel:/WhatsApp-Links: href="#" + data-obf-href (kodiert). Das Skript setzt
 *   den echten Link erst im Moment des Klicks und gleich danach wieder zurück;
 *   ebenso data-mail-address für die Mail-Vorbelegung (layout.twig).
 * - E-Mails in sonstigen Attributen (meta, title, …): durch „[E-Mail]“ ersetzt.
 * <script>/<style>/<textarea> bleiben unangetastet (JSON-LD baut
 * CMS::buildStructuredData() selbst ohne E-Mail).
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
                // Adresse für die Mail-Vorbelegung (layout.twig, data-mail-template):
                // kodiert, das Skript setzt sie nur im Moment des Klicks.
                $new = preg_replace_callback('/\sdata-mail-address\s*=\s*(["\'])([^"\']*)\1/i', static fn (array $m): string => $m[2] === '' ? $m[0] : ' data-obf-mail="' . self::encode(html_entity_decode($m[2], ENT_QUOTES | ENT_HTML5, 'UTF-8')) . '"', $new) ?? $new;
                // E-Mails in übrigen Attributen (alt, title, content, …)
                $new = preg_replace('/' . self::EMAIL . '/', '[E-Mail]', $new) ?? $new;
                if ($new !== $part) {
                    $parts[$i] = $new;
                    $changed = true;
                }
                continue;
            }

            // Textknoten
            $new = preg_replace_callback('/' . self::EMAIL . '/', static fn (array $m): string => self::textSpan(html_entity_decode($m[0], ENT_QUOTES | ENT_HTML5, 'UTF-8')), $part) ?? $part;
            if ($inTel && trim($new) !== '' && preg_match('/\d{4,}/', preg_replace('/\D/', '', $new) ?? '') === 1 && !str_contains($new, 'data-obf')) {
                // Leerraum um die Nummer bleibt Text, nur die Nummer wird ersetzt
                preg_match('/^(\s*)(.*?)(\s*)$/s', $new, $ws);
                $new = $ws[1] . self::textSpan(html_entity_decode($ws[2], ENT_QUOTES | ENT_HTML5, 'UTF-8')) . $ws[3];
            } else {
                foreach ($phones as $phone) {
                    $needle = htmlspecialchars($phone, ENT_QUOTES, 'UTF-8');
                    if (str_contains($new, $needle)) {
                        $new = str_replace($needle, self::textSpan($phone), $new);
                    }
                }
            }
            if ($new !== $part) {
                $parts[$i] = $new;
                $changed = true;
            }
        }

        $out = implode('', $parts);
        $assets = '';
        if ($changed && str_contains($out, 'data-obf-t')) {
            // Anzeige ohne Text im DOM: Pseudo-Element aus den rückwärts geschriebenen
            // Attributen, bidi-override dreht es für die Anzeige richtig herum.
            $assets .= '<style>[data-obf-t]::before{unicode-bidi:bidi-override;direction:rtl;content:attr(data-oa)}'
                . '[data-obf-t="m"]::before{content:attr(data-oa) "@" attr(data-ob)}</style>';
        }
        if ($changed && (str_contains($out, 'data-obf-href') || str_contains($out, 'data-obf-mail'))) {
            // Echter Link nur im Moment des Klicks (Capture-Phase, vor dem Handler der
            // Mail-Vorbelegung und vor der Navigation), danach sofort zurück auf "#".
            $assets .= '<script>(function(){function d(s){try{return decodeURIComponent(escape(atob(s))).split("").reverse().join("")}catch(e){return ""}}'
                . 'function go(e){var a=e.target&&e.target.closest?e.target.closest("[data-obf-href],[data-obf-mail]"):null;if(!a)return;'
                . 'var h=a.getAttribute("data-obf-href"),m=a.getAttribute("data-obf-mail");'
                . 'if(h!==null)a.setAttribute("href",d(h));if(m!==null)a.setAttribute("data-mail-address",d(m));'
                . 'setTimeout(function(){if(h!==null)a.setAttribute("href","#");if(m!==null)a.removeAttribute("data-mail-address")},0)}'
                . 'document.addEventListener("click",go,true);document.addEventListener("auxclick",go,true)})();</script>';
        }
        if ($assets === '') {
            return $out;
        }
        $pos = strripos($out, '</body>');

        return $pos === false ? $out . $assets : substr($out, 0, $pos) . $assets . substr($out, $pos);
    }

    /** Umgekehrt + Base64 (UTF-8-sicher) – das Skript dreht es beim Klick zurück. */
    public static function encode(string $s): string
    {
        return base64_encode(self::reverse($s));
    }

    private static function reverse(string $s): string
    {
        return implode('', array_reverse(mb_str_split($s, 1, 'UTF-8')));
    }

    /**
     * Leeres <span>, Anzeige per CSS aus rückwärts geschriebenen Attributen. Bei
     * E-Mails ohne „@“: data-oa = Domain rückwärts, data-ob = Name rückwärts, das
     * „@“ setzt das CSS dazwischen. So steht weder im Quelltext noch im DOM eine
     * gültige Adresse oder Nummer.
     */
    private static function textSpan(string $plain): string
    {
        $esc = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
        $at = strrpos($plain, '@');
        if ($at !== false) {
            return '<span data-obf-t="m" data-oa="' . $esc(self::reverse(substr($plain, $at + 1)))
                . '" data-ob="' . $esc(self::reverse(substr($plain, 0, $at))) . '"></span>';
        }

        return '<span data-obf-t="t" data-oa="' . $esc(self::reverse($plain)) . '"></span>';
    }
}
