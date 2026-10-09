<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

// GIPHY, for Chat's GIF picker: search, trending, and looking one up by id
// when it's sent -- so what's saved is GIPHY's own URL, never one a
// browser handed us. The key stays on the server (config/services.php).
class Giphy
{
    const PAGE_SIZE = 24;

    public static function enabled(): bool
    {
        return filled(config('services.giphy.key'));
    }

    // Trending when there's no query.
    public function search(?string $query, int $offset = 0): array
    {
        $endpoint = filled($query) ? 'search' : 'trending';
        $response = $this->get("gifs/{$endpoint}", array_filter([
            'q' => $query,
            'limit' => self::PAGE_SIZE,
            'offset' => $offset,
            'lang' => 'en',
        ], fn ($v) => $v !== null));

        return [
            'results' => collect($response['data'] ?? [])->map(fn (array $gif) => $this->present($gif))->filter()->values()->all(),
            'next_offset' => ($response['pagination']['offset'] ?? 0) + ($response['pagination']['count'] ?? 0),
            'has_more' => (($response['pagination']['offset'] ?? 0) + ($response['pagination']['count'] ?? 0)) < ($response['pagination']['total_count'] ?? 0),
        ];
    }

    // One GIF, as it's stored on a message; null when GIPHY doesn't have it
    // (or it's above the allowed rating).
    public function find(string $id): ?array
    {
        $gif = $this->get("gifs/{$id}")['data'] ?? null;

        if (! $gif || ! $this->allowedRating($gif['rating'] ?? null)) {
            return null;
        }

        $presented = $this->present($gif);

        return $presented ? [
            'id' => $presented['id'],
            'title' => $presented['title'],
            'url' => $presented['url'],
            'width' => $presented['width'],
            'height' => $presented['height'],
        ] : null;
    }

    protected function get(string $path, array $query = []): array
    {
        return Http::baseUrl('https://api.giphy.com/v1/')
            ->timeout(5)
            ->get($path, [...$query, 'api_key' => config('services.giphy.key'), 'rating' => config('services.giphy.rating')])
            ->throw()
            ->json() ?? [];
    }

    // A small still-moving preview for the picker, and a medium copy (under
    // 2 MB) to show in the conversation.
    protected function present(array $gif): ?array
    {
        $preview = $gif['images']['fixed_width'] ?? null;
        $full = $gif['images']['downsized_medium'] ?? $gif['images']['original'] ?? null;

        if (! isset($gif['id'], $preview['url'], $full['url'])) {
            return null;
        }

        return [
            'id' => $gif['id'],
            'title' => $gif['title'] ?? '',
            'preview_url' => $preview['url'],
            'preview_width' => (int) ($preview['width'] ?? 0),
            'preview_height' => (int) ($preview['height'] ?? 0),
            'url' => $full['url'],
            'width' => (int) ($full['width'] ?? 0),
            'height' => (int) ($full['height'] ?? 0),
        ];
    }

    protected function allowedRating(?string $rating): bool
    {
        $order = ['g', 'pg', 'pg-13', 'r'];
        $max = array_search(config('services.giphy.rating'), $order, true);
        $index = array_search($rating ?? 'r', $order, true);

        return $max !== false && $index !== false && $index <= $max;
    }
}
