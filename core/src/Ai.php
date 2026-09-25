<?php

declare(strict_types=1);

namespace Core;

/**
 * Schlanker OpenAI-kompatibler Chat-Completions-Client für die "KI"-Buttons im
 * Admin (siehe web/core/MANUAL.md, Abschnitt "KI-Buttons"). Bewusst
 * dependency-frei (curl) und serverseitig: Der API-Key (config.json →
 * apis.openai.api_key) landet nie im gerenderten HTML, der Browser fragt nur
 * den Admin-Endpoint. Provider-agnostisch über config.json (apis.openai.* –
 * base_url/model optional, Default ist OpenAI), damit auch andere
 * OpenAI-kompatible Anbieter (Azure, lokale Gateways) anschließbar sind.
 */
final class Ai
{
    public const DEFAULT_MODEL = 'gpt-4o-mini';

    public static function configured(array $config): bool
    {
        return trim((string) ($config['apis']['openai']['api_key'] ?? '')) !== '';
    }

    public static function baseUrl(array $config): string
    {
        return rtrim((string) ($config['apis']['openai']['base_url'] ?? 'https://api.openai.com/v1'), '/');
    }

    public static function model(array $config): string
    {
        $model = trim((string) ($config['apis']['openai']['model'] ?? ''));

        return $model !== '' ? $model : self::DEFAULT_MODEL;
    }

    /**
     * @return array{ok: bool, message: string, alternatives: list<string>}
     */
    public static function alternatives(array $config, string $system, string $user, int $count = 3): array
    {
        if (!self::configured($config)) {
            return [
                'ok' => false,
                'message' => 'Kein API-Key konfiguriert (config.json → apis.openai.api_key).',
                'alternatives' => [],
            ];
        }

        $system .= "\nGib exakt {$count} unterschiedliche Fassungen aus.";
        $user .= "\n\nAntwortformat: eine reine JSON-Liste von Strings, zum Beispiel "
            . '["Fassung 1", "Fassung 2", "Fassung 3"]. Kein Text davor oder danach, keine Markdown-Codeblöcke.';

        $result = self::complete($config, $system, $user, [
            'temperature' => 0.85,
            'max_tokens' => 1200,
        ]);
        if (!$result['ok']) {
            return ['ok' => false, 'message' => $result['message'], 'alternatives' => []];
        }

        return ['ok' => true, 'message' => '', 'alternatives' => self::parseAlternatives($result['text'])];
    }

    /**
     * SEO-Titel/-Description-Paare generieren (siehe Admin::aiSeo()).
     *
     * @return array{ok: bool, message: string, suggestions: list<array{title: string, description: string}>}
     */
    public static function seoSuggestions(array $config, string $system, string $user, int $count = 3): array
    {
        if (!self::configured($config)) {
            return [
                'ok' => false,
                'message' => 'Kein API-Key konfiguriert (config.json → apis.openai.api_key).',
                'suggestions' => [],
            ];
        }

        $user .= "\n\nAntwortformat: ein reines JSON-Objekt ohne Markdown-Codeblöcke, zum Beispiel "
            . '{ "alternatives": [{"title": "…", "description": "…"}, {"title": "…", "description": "…"}] } — '
            . "exakt {$count} Einträge.";

        $result = self::complete($config, $system, $user, [
            'temperature' => 0.7,
            'max_tokens' => 1400,
        ]);
        if (!$result['ok']) {
            return ['ok' => false, 'message' => $result['message'], 'suggestions' => []];
        }

        return ['ok' => true, 'message' => '', 'suggestions' => self::parseSeoSuggestions($result['text'])];
    }

    /**
     * @return array{ok: bool, message: string, text: string}
     */
    private static function complete(array $config, string $system, string $user, array $overrides): array
    {
        $payload = array_replace([
            'model' => self::model($config),
            'messages' => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => $user],
            ],
        ], $overrides);

        $http = self::postJson(self::baseUrl($config) . '/chat/completions', $config, $payload);
        if (!$http['ok']) {
            return ['ok' => false, 'message' => $http['message'], 'text' => ''];
        }

        $content = $http['content']['choices'][0]['message']['content'] ?? '';

        return ['ok' => true, 'message' => '', 'text' => is_string($content) ? $content : ''];
    }

    /**
     * Wertet die Modell-Antwort robust zu einer Liste von Fassungen aus:
     * erst echter JSON (Liste von Strings), sonst zeilenweise als Fallback.
     *
     * @return list<string>
     */
    public static function parseAlternatives(string $content): array
    {
        $content = trim($content);
        $bare = self::stripCodeFence($content);

        $decoded = json_decode($bare, true);
        if (is_array($decoded) && array_is_list($decoded)) {
            $out = [];
            foreach ($decoded as $item) {
                if (is_string($item) && trim($item) !== '') {
                    $out[] = trim($item);
                }
            }
            if ($out !== []) {
                return $out;
            }
        }

        $lines = array_values(array_filter(array_map('trim', explode("\n", $bare)), static fn (string $l): bool => $l !== ''));
        if ($lines !== []) {
            return $lines;
        }

        return $content !== '' ? [$content] : [];
    }

    /** @return list<array{title: string, description: string}> */
    public static function parseSeoSuggestions(string $content): array
    {
        $bare = self::stripCodeFence(trim($content));

        $decoded = json_decode($bare, true);
        $items = [];
        if (is_array($decoded)) {
            $items = $decoded['alternatives'] ?? (array_is_list($decoded) ? $decoded : []);
        }
        if (!is_array($items)) {
            $items = [];
        }

        $out = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $title = trim((string) ($item['title'] ?? ''));
            $description = trim((string) ($item['description'] ?? ''));
            if ($title === '' && $description === '') {
                continue;
            }
            $out[] = ['title' => $title, 'description' => $description];
        }

        return $out;
    }

    private static function stripCodeFence(string $content): string
    {
        $content = preg_replace('/^```(?:json)?\s*/i', '', $content);
        $content = preg_replace('/\s*```$/i', '', $content);

        return $content !== null ? trim($content) : '';
    }

    /** @return array{ok: bool, message: string, content: mixed} */
    private static function postJson(string $url, array $config, array $payload): array
    {
        if (!function_exists('curl_init')) {
            return ['ok' => false, 'message' => 'cURL ist auf dem Server nicht verfügbar.', 'content' => null];
        }

        $key = trim((string) ($config['apis']['openai']['api_key'] ?? ''));
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $key,
            ],
            CURLOPT_TIMEOUT => 60,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);

        $raw = curl_exec($ch);
        $errno = curl_errno($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($raw === false || $errno !== 0) {
            // bewusst keine Details/curl-Fehlermeldung – könnte serverseitige
            // Infos leaken; Nutzer sieht nur die neutrale Meldung.
            return ['ok' => false, 'message' => 'Verbindung zum KI-Dienst fehlgeschlagen.', 'content' => null];
        }
        if ($status < 200 || $status >= 300) {
            return ['ok' => false, 'message' => 'KI-Dienst antwortete mit HTTP ' . $status . '.', 'content' => null];
        }

        $decoded = json_decode((string) $raw, true);

        return ['ok' => true, 'message' => '', 'content' => is_array($decoded) ? $decoded : null];
    }
}