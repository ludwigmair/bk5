<?php

declare(strict_types=1);

/**
 * Wird von CMS::render() generisch aufgerufen (Konvention: components/<type>/data.php),
 * um section-spezifische Laufzeitdaten zu ergänzen, ohne dass der Core diese Section
 * beim Namen kennen muss.
 */
return static function (array $data, array $content, array $config, string $root): array {
    $data['business'] = $content['business'] ?? [];

    // Direkter externer Google-Maps-Link (kein Embed) – kein Datenschutz-Dialog nötig,
    // da nur verlinkt, nicht eingebettet. Bevorzugt die Google-Places-ID (bereits für
    // Google-Reviews erfasst, config.json → apis.google.place_id) für einen exakten
    // Treffer, sonst dieselbe Adress-Suche wie der Karten-Embed.
    $business = $data['business'];
    $placeId = trim((string) ($config['apis']['google']['place_id'] ?? ''));
    if ($placeId !== '') {
        $data['maps_url'] = 'https://www.google.com/maps/place/?q=place_id:' . rawurlencode($placeId);
    } else {
        $query = trim((string) ($business['maps_query'] ?? ''));
        if ($query === '') {
            $query = trim(($business['name'] ?? '') . ' ' . ($business['street'] ?? '') . ' ' . ($business['zip'] ?? '') . ' ' . ($business['city'] ?? ''));
        }
        $data['maps_url'] = $query !== '' ? 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode($query) : '';
    }

    return $data;
};
