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

    return $data;
};
