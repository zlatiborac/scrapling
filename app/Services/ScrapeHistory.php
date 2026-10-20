<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Str;

class ScrapeHistory
{
    public function record(User $user, string $url, ?array $result, int $durationMs): void
    {
        $parts = parse_url($url);
        // Store the page address, not credentials, fragments or query-string tokens.
        $safeUrl = ($parts['scheme'] ?? 'https').'://'.($parts['host'] ?? '')
            .(isset($parts['port']) ? ':'.$parts['port'] : '')
            .($parts['path'] ?? '');

        activity('scraper')
            ->causedBy($user)
            ->event($result === null ? 'failed' : 'succeeded')
            ->withProperties([
                'url' => $safeUrl,
                'http_status' => $result['status'] ?? null,
                'title' => isset($result['title']) ? Str::limit($result['title'], 500) : null,
                'heading_count' => isset($result['headings']) ? count($result['headings']) : null,
                'duration_ms' => $durationMs,
            ])
            ->log($result === null ? 'Page fetch failed' : 'Page fetched successfully');
    }
}
