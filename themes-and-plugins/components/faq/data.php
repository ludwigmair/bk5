<?php

declare(strict_types=1);

/**
 * Wird von CMS::render() generisch aufgerufen (Konvention: components/<type>/data.php).
 * FAQ steuert hier strukturierte Daten (FAQPage-JSON-LD) bei: der generische
 * Sammler in CMS::render() liest den Key "structured_data" aus dem Rückgabewert
 * und rendert ihn als eigenes <script type="application/ld+json"> in den <head>.
 * Gebaut wird das Schema nur aus den tatsächlich angezeigten Einträgen (aktiv,
 * Frage und Antwort nicht leer) – leer lässt die FAQ-Section das Schema weg.
 */
return static function (array $data, array $content, array $config, string $root): array {
    $questions = [];
    foreach (($data['items'] ?? []) as $item) {
        $question = trim((string) ($item['question'] ?? ''));
        $answer = trim((string) ($item['answer'] ?? ''));
        if (($item['active'] ?? true) === false || $question === '' || $answer === '') {
            continue;
        }
        $questions[] = [
            '@type' => 'Question',
            'name' => $question,
            'acceptedAnswer' => ['@type' => 'Answer', 'text' => $answer],
        ];
    }

    if ($questions !== []) {
        $data['structured_data'] = [
            '@context' => 'https://schema.org',
            '@type' => 'FAQPage',
            'mainEntity' => $questions,
        ];
    }

    return $data;
};