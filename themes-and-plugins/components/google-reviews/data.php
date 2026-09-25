<?php

declare(strict_types=1);

require_once __DIR__ . '/GoogleReview.php';

use Components\GoogleReviews\GoogleReview;

/**
 * Wird von CMS::render() generisch aufgerufen (Konvention: components/<type>/data.php),
 * um section-spezifische Laufzeitdaten zu ergänzen, ohne dass der Core diese Section
 * beim Namen kennen muss.
 */
return static function (array $data, array $content, array $config, string $root): array {
    $result = (new GoogleReview($root, $config))->getData();
    $fallbackReviews = $data['fallback_reviews'] ?? [];
    $data['reviews'] = $result['reviews'] !== [] ? $result['reviews'] : $fallbackReviews;

    // Kein manuelles "Durchschnitt"/"Anzahl"-Feld mehr - beides wird aus den
    // tatsächlichen Bewertungen berechnet, sonst laufen Anzeige und echte
    // Stimmen unbemerkt auseinander (Review hinzugefügt/gelöscht, Zahl oben
    // vergessen anzupassen). Bei aktiver Google-API bleibt deren Summary
    // maßgeblich (die API kennt die Gesamtzahl aller Google-Bewertungen,
    // nicht nur der hier angezeigten Auszüge).
    if ($result['summary'] !== null) {
        $data['rating_summary'] = $result['summary'];
    } else {
        $ratings = [];
        foreach ($fallbackReviews as $review) {
            if (($review['active'] ?? true) === false) {
                continue;
            }
            $ratings[] = (float) ($review['rating'] ?? 0);
        }
        $count = count($ratings);
        $data['rating_summary'] = $count > 0
            ? ['rating' => round(array_sum($ratings) / $count, 1), 'count' => $count]
            : null;
    }

    // Strukturierte Daten (AggregateRating + Review-JSON-LD) für den generischen
    // Sammler in CMS::render() – nur aus den tatsächlich angezeigten Bewertungen
    // (aktiv, Text nicht leer). Leer lässt diese Section kein Schema beisteuern.
    $sdReviews = [];
    foreach (($data['reviews'] ?? []) as $review) {
        $text = trim((string) ($review['text'] ?? ''));
        if (($review['active'] ?? true) === false || $text === '') {
            continue;
        }
        $sd = [
            '@type' => 'Review',
            'reviewBody' => $text,
            'author' => ['@type' => 'Person', 'name' => trim((string) ($review['author'] ?? '')) !== '' ? trim((string) $review['author']) : 'Kunde'],
        ];
        $rating = (float) ($review['rating'] ?? 0);
        if ($rating > 0) {
            $sd['reviewRating'] = ['@type' => 'Rating', 'ratingValue' => $rating, 'bestRating' => 5];
        }
        $sdReviews[] = $sd;
    }

    if ($sdReviews !== []) {
        $entity = [
            '@context' => 'https://schema.org',
            '@type' => 'LocalBusiness',
            'name' => trim((string) ($content['business']['name'] ?? $content['site']['title'] ?? '')) !== ''
                ? trim((string) ($content['business']['name'] ?? $content['site']['title']))
                : 'Bewertungen',
            'review' => $sdReviews,
        ];
        $summary = $data['rating_summary'] ?? null;
        if (is_array($summary) && (float) ($summary['rating'] ?? 0) > 0 && (int) ($summary['count'] ?? 0) > 0) {
            $entity['aggregateRating'] = [
                '@type' => 'AggregateRating',
                'ratingValue' => (string) round((float) $summary['rating'], 1),
                'ratingCount' => (int) $summary['count'],
            ];
        }
        $data['structured_data'] = $entity;
    }

    return $data;
};
