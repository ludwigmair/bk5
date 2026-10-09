<?php

declare(strict_types=1);

namespace Core;

/**
 * Formular-Einsendungen (Kontaktanfragen) in data/inquiries.json – generisch:
 * jede Component mit einem Formular kann hier ablegen, der Admin zeigt sie im
 * Panel "Anfragen" an. data/ ist per .htaccess gesperrt, gitignored und vom
 * Deploy ausgeschlossen (Personendaten).
 *
 * Aufbewahrung (DSGVO, Datenminimierung): Einträge älter als
 * config.json → inquiries.retention_days (Standard 180, 0 = nie löschen) werden
 * bei jedem Lesen/Schreiben entfernt.
 */
final class Inquiries
{
    public const DEFAULT_RETENTION_DAYS = 180;

    public static function retentionDays(array $config): int
    {
        $days = $config['inquiries']['retention_days'] ?? self::DEFAULT_RETENTION_DAYS;

        return is_numeric($days) ? max(0, (int) $days) : self::DEFAULT_RETENTION_DAYS;
    }

    /** Neuen Eintrag speichern; bekommt eine id, falls keine mitkommt. */
    public static function add(string $root, array $entry, int $retentionDays): void
    {
        $items = self::load($root, $retentionDays);
        $entry['id'] = (string) ($entry['id'] ?? bin2hex(random_bytes(6)));
        $entry['at'] = (string) ($entry['at'] ?? date('c'));
        $items[] = $entry;
        self::save($root, $items);
    }

    /** @return list<array<string, mixed>> neueste zuerst */
    public static function all(string $root, int $retentionDays): array
    {
        $items = self::load($root, $retentionDays);
        usort($items, static fn (array $a, array $b): int => strcmp((string) ($b['at'] ?? ''), (string) ($a['at'] ?? '')));

        return $items;
    }

    public static function delete(string $root, string $id): bool
    {
        $items = self::load($root, 0);
        $kept = array_values(array_filter($items, static fn (array $i): bool => (string) ($i['id'] ?? '') !== $id));
        if (count($kept) === count($items)) {
            return false;
        }
        self::save($root, $kept);

        return true;
    }

    public static function deleteAll(string $root): int
    {
        $count = count(self::load($root, 0));
        self::save($root, []);

        return $count;
    }

    /** @return list<array<string, mixed>> */
    private static function load(string $root, int $retentionDays): array
    {
        $file = self::path($root);
        $data = is_file($file) ? CMS::readJson($file) : [];
        $items = is_array($data['items'] ?? null) ? array_values(array_filter($data['items'], 'is_array')) : [];
        $changed = false;

        // Früher lagen die Anfragen in cache/ (vom Deploy geleert) – einmalig übernehmen.
        $legacy = $root . '/cache/inquiries.json';
        if (is_file($legacy)) {
            $old = CMS::readJson($legacy)['items'] ?? [];
            $items = array_merge(is_array($old) ? array_values(array_filter($old, 'is_array')) : [], $items);
            @unlink($legacy);
            $changed = true;
        }

        // Ältere Einträge ohne id bekommen eine stabile (aus Zeitpunkt + E-Mail).
        foreach ($items as &$item) {
            if (empty($item['id'])) {
                $item['id'] = substr(hash('sha256', ($item['at'] ?? '') . '|' . ($item['email'] ?? '') . '|' . ($item['message'] ?? '')), 0, 12);
                $changed = true;
            }
        }
        unset($item);

        if ($retentionDays > 0) {
            $cutoff = time() - $retentionDays * 86400;
            $kept = array_values(array_filter($items, static function (array $i) use ($cutoff): bool {
                $at = strtotime((string) ($i['at'] ?? ''));

                return $at === false || $at >= $cutoff;
            }));
            if (count($kept) !== count($items)) {
                $items = $kept;
                $changed = true;
            }
        }

        if ($changed) {
            self::save($root, $items);
        }

        return $items;
    }

    private static function save(string $root, array $items): void
    {
        $file = self::path($root);
        if (!is_dir(dirname($file))) {
            @mkdir(dirname($file), 0775, true);
        }
        CMS::writeJson($file, ['items' => array_values($items)]);
    }

    private static function path(string $root): string
    {
        return $root . '/data/inquiries.json';
    }
}
