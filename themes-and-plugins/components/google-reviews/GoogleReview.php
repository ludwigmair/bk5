<?php

declare(strict_types=1);

namespace Components\GoogleReviews;

use Core\CMS;

/**
 * Google Places Reviews, serverseitig und 24h lokal gecacht.
 * Besucher-IPs gehen nicht an Google (kein Places JS Widget).
 */
final class GoogleReview
{
    private const CACHE_TTL = 86400;

    public function __construct(
        private readonly string $root,
        private readonly array $config,
    ) {
    }

    /** @return array{reviews: list<array{author:string,time:string,rating:int,text:string}>, summary: array{rating: float, count: int}|null} */
    public function getData(): array
    {
        $cacheFile = $this->root . '/cache/reviews_cache.json';
        $cached = $this->readFreshCache($cacheFile);
        if ($cached !== null) {
            return $cached;
        }

        $apiKey = (string) ($this->config['apis']['google']['places_api_key'] ?? '');
        $placeId = (string) ($this->config['apis']['google']['place_id'] ?? '');
        if ($apiKey === '' || $placeId === '') {
            return ['reviews' => [], 'summary' => null];
        }

        $result = $this->fetchFromPlaces($apiKey, $placeId);
        $payload = [
            'fetched_at' => time(),
            'reviews' => $result['reviews'],
            'summary' => $result['summary'],
        ];
        CMS::writeJson($cacheFile, $payload);

        return $result;
    }

    /** @return array{reviews: list<array{author:string,time:string,rating:int,text:string}>, summary: array{rating: float, count: int}|null}|null */
    private function readFreshCache(string $cacheFile): ?array
    {
        if (!is_file($cacheFile)) {
            return null;
        }

        $data = CMS::readJson($cacheFile);
        $fetched = (int) ($data['fetched_at'] ?? 0);
        if ($fetched < 1 || (time() - $fetched) > self::CACHE_TTL) {
            return null;
        }

        $reviews = $data['reviews'] ?? [];
        return [
            'reviews' => is_array($reviews) ? $reviews : [],
            'summary' => is_array($data['summary'] ?? null) ? $data['summary'] : null,
        ];
    }

    /** @return array{reviews: list<array{author:string,time:string,rating:int,text:string}>, summary: array{rating: float, count: int}|null} */
    private function fetchFromPlaces(string $apiKey, string $placeId): array
    {
        $query = http_build_query([
            'place_id' => $placeId,
            'fields' => 'reviews,rating,user_ratings_total',
            'key' => $apiKey,
            'language' => 'de',
        ]);
        $url = 'https://maps.googleapis.com/maps/api/place/details/json?' . $query;

        $context = stream_context_create([
            'http' => [
                'timeout' => 8,
                'header' => "Accept: application/json\r\n",
            ],
        ]);
        $raw = @file_get_contents($url, false, $context);
        if ($raw === false) {
            return ['reviews' => [], 'summary' => null];
        }

        $json = json_decode($raw, true);
        $result = $json['result'] ?? [];
        $items = $result['reviews'] ?? [];
        if (!is_array($items)) {
            $items = [];
        }

        $out = [];
        foreach ($items as $item) {
            $text = trim((string) ($item['text'] ?? ''));
            if ($text === '') {
                continue;
            }
            $out[] = [
                'author' => (string) ($item['author_name'] ?? 'Gast'),
                'time' => (string) ($item['relative_time_description'] ?? ''),
                'rating' => (int) ($item['rating'] ?? 0),
                'text' => $text,
            ];
        }

        $summary = isset($result['rating'], $result['user_ratings_total'])
            ? ['rating' => (float) $result['rating'], 'count' => (int) $result['user_ratings_total']]
            : null;

        return ['reviews' => $out, 'summary' => $summary];
    }
}
