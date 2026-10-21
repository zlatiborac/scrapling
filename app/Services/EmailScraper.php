<?php

namespace App\Services;

use RuntimeException;
use Symfony\Component\Process\Process;

class EmailScraper
{
    public function scrape(string $url, bool $website = false, int $maxPages = 20, int $maxDepth = 3, bool $publicOnly = true): array
    {
        if (! filter_var($url, FILTER_VALIDATE_URL) || ! in_array(strtolower(parse_url($url, PHP_URL_SCHEME) ?? ''), ['http', 'https'], true) || parse_url($url, PHP_URL_USER) !== null || parse_url($url, PHP_URL_PASS) !== null) {
            throw new RuntimeException('Enter an HTTP or HTTPS URL without embedded credentials.');
        }

        if ($maxPages < 1 || $maxPages > 50 || $maxDepth < 0 || $maxDepth > 5) {
            throw new RuntimeException('Use 1–50 pages and a link depth of 0–5.');
        }

        $arguments = [config('scrapling.python'), base_path('scripts/email_scrape.py'), $url, '--max-pages', (string) $maxPages, '--max-depth', (string) $maxDepth];
        if ($website) {
            $arguments[] = '--website';
        }
        if ($publicOnly) {
            $arguments[] = '--public-only';
        }

        $process = new Process($arguments, base_path());
        $process->setTimeout(45);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new RuntimeException(mb_substr(trim($process->getErrorOutput()) ?: 'Scrapling could not fetch e-mail addresses.', -1000));
        }

        return json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
    }
}
