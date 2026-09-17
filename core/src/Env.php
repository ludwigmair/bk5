<?php

declare(strict_types=1);

namespace Core;

/**
 * Minimaler .env-Reader, kein Composer-Package (siehe composer.json - bewusst
 * nur Twig als Abhängigkeit). Für Zugangsdaten, die nicht in data/config.json
 * gehören: Admin-Bootstrap-Login und der Bearer-Token für den Backup-Endpunkt
 * (Admin::handle() -> do=backup). Projekt-/Theme-Konfiguration (Farben,
 * Layout, API-Keys) bleibt bewusst in config.json - das ist keine
 * Server-Zugangsdaten-Frage, sondern normaler Projekt-Inhalt.
 *
 * Format: KEY=value pro Zeile, "#"-Zeilen und Leerzeilen ignoriert,
 * optionale An-/Ausführungszeichen ("..." oder '...') werden entfernt.
 */
final class Env
{
    /** @var array<string, string>|null */
    private static ?array $values = null;

    public static function load(string $root): void
    {
        if (self::$values !== null) {
            return;
        }
        self::$values = [];

        $path = $root . '/.env';
        if (!is_file($path)) {
            return;
        }

        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);
            if (strlen($value) >= 2 && ($value[0] === '"' || $value[0] === "'") && $value[-1] === $value[0]) {
                $value = substr($value, 1, -1);
            }
            if ($key !== '') {
                self::$values[$key] = $value;
            }
        }
    }

    public static function get(string $key, string $default = ''): string
    {
        return self::$values[$key] ?? $default;
    }

    /** Nur für Tests: erzwingt ein Neuladen beim nächsten load(). */
    public static function reset(): void
    {
        self::$values = null;
    }
}
