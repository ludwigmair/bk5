<?php

declare(strict_types=1);

namespace Core;

/**
 * Sehr kompakter Markdown-Renderer nur für lokale .md-Dokumentationsdateien
 * (siehe Admin::renderManual()) – deckt ab, was docs/*.md tatsächlich nutzt:
 * Überschriften (#–####), **fett**, `code`, [Links](url), Aufzählungen (- Punkt),
 * einfache Pipe-Tabellen, Absätze. Kein allgemeiner Markdown-Parser, keine externe
 * Library nötig. Escaped zuerst alles per htmlspecialchars, wie RichText.
 */
final class Markdown
{
    /**
     * Liste der Top-Level-Überschriften (`## ...`) mit Anker-Slug (identisch zu
     * den `id`-Attributen, die render() denselben Überschriften gibt) – für eine
     * Sprungnavigation über dem gerenderten Dokument, siehe Admin::renderManual().
     *
     * @return list<array{text: string, slug: string}>
     */
    public static function headings(string $text): array
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $out = [];
        foreach (explode("\n", $text) as $line) {
            if (preg_match('/^##\s+(.+)$/', trim($line), $m)) {
                $label = trim($m[1]);
                $slug = self::slugify($label);
                if ($slug !== '') {
                    $out[] = ['text' => $label, 'slug' => $slug];
                }
            }
        }

        return $out;
    }

    /**
     * Eigene, schlanke Kopie von CMS::slugify() statt einer Abhängigkeit dorthin –
     * Markdown.php ist bewusst frei von Core-Abhängigkeiten (siehe tests/run.php,
     * das RichText/Markdown genau deswegen ohne Autoloader direkt requiret).
     */
    private static function slugify(string $s): string
    {
        $s = trim($s);
        if ($s === '') {
            return '';
        }

        $translit = strtr($s, [
            'ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue',
            'Ä' => 'Ae', 'Ö' => 'Oe', 'Ü' => 'Ue', 'ß' => 'ss',
        ]);
        $lower = mb_strtolower($translit, 'UTF-8');
        $slug = preg_replace('/[^a-z0-9]+/', '-', $lower) ?? '';

        return trim($slug, '-');
    }

    public static function render(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $lines = explode("\n", $text);
        $count = count($lines);

        $html = [];
        $paragraph = [];
        $listItems = [];

        $flushParagraph = static function () use (&$paragraph, &$html): void {
            if ($paragraph === []) {
                return;
            }
            $html[] = '<p>' . self::inline(implode(' ', $paragraph)) . '</p>';
            $paragraph = [];
        };
        $flushList = static function () use (&$listItems, &$html): void {
            if ($listItems === []) {
                return;
            }
            $items = array_map(static fn (string $item): string => '<li>' . self::inline($item) . '</li>', $listItems);
            $html[] = '<ul>' . implode('', $items) . '</ul>';
            $listItems = [];
        };

        $i = 0;
        while ($i < $count) {
            $trimmed = trim($lines[$i]);

            if ($trimmed === '') {
                $flushParagraph();
                $flushList();
                $i++;
                continue;
            }

            if (preg_match('/^(#{1,4})\s+(.+)$/', $trimmed, $m)) {
                $flushParagraph();
                $flushList();
                $level = strlen($m[1]);
                $slug = self::slugify($m[2]);
                $idAttr = $slug !== '' ? ' id="' . $slug . '"' : '';
                $html[] = '<h' . $level . $idAttr . '>' . self::inline($m[2]) . '</h' . $level . '>';
                $i++;
                continue;
            }

            if (preg_match('/^-\s+(.+)$/', $trimmed, $m)) {
                $flushParagraph();
                $listItems[] = $m[1];
                $i++;
                continue;
            }

            if (str_starts_with($trimmed, '|')) {
                $flushParagraph();
                $flushList();
                $tableLines = [];
                while ($i < $count && str_starts_with(trim($lines[$i]), '|')) {
                    $tableLines[] = trim($lines[$i]);
                    $i++;
                }
                $html[] = self::table($tableLines);
                continue;
            }

            // Soft-umgebrochene Fortsetzungszeile eines Listenpunkts (kein neues "- ",
            // aber die letzte Zeile war Teil der laufenden Liste) – an letzten Punkt
            // anhängen statt als eigenen Absatz zu werten, sonst reißt der Punkt ab.
            if ($listItems !== [] && $paragraph === []) {
                $listItems[count($listItems) - 1] .= ' ' . $trimmed;
                $i++;
                continue;
            }

            $paragraph[] = $trimmed;
            $i++;
        }

        $flushParagraph();
        $flushList();

        return implode("\n", $html);
    }

    /** @param list<string> $lines */
    private static function table(array $lines): string
    {
        if (count($lines) < 2) {
            return '';
        }

        $rows = array_map(static function (string $line): array {
            $line = trim(trim($line), '|');
            return array_map('trim', explode('|', $line));
        }, $lines);

        $header = array_shift($rows) ?? [];
        array_shift($rows); // Trennzeile (---|---)

        $out = '<div class="md-table-wrap"><table><thead><tr>';
        foreach ($header as $cell) {
            $out .= '<th>' . self::inline($cell) . '</th>';
        }
        $out .= '</tr></thead><tbody>';
        foreach ($rows as $row) {
            $out .= '<tr>';
            foreach ($row as $cell) {
                $out .= '<td>' . self::inline($cell) . '</td>';
            }
            $out .= '</tr>';
        }
        $out .= '</tbody></table></div>';

        return $out;
    }

    private static function inline(string $text): string
    {
        $escaped = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $escaped = preg_replace_callback('/\[([^\]\n]+)\]\(([^)\s]+)\)/', static function (array $m): string {
            $url = html_entity_decode($m[2], ENT_QUOTES, 'UTF-8');
            $safe = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
            return '<a href="' . $safe . '">' . $m[1] . '</a>';
        }, $escaped) ?? $escaped;

        $escaped = preg_replace('/`([^`\n]+?)`/', '<code>$1</code>', $escaped) ?? $escaped;
        $escaped = preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $escaped) ?? $escaped;

        return $escaped;
    }
}
