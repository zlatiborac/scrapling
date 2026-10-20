<?php

namespace App\Services;

use RuntimeException;
use Symfony\Component\Process\Process;

class Scraper
{
    public function scrape(string $url, bool $publicOnly = true): array
    {
        if (! filter_var($url, FILTER_VALIDATE_URL) || ! in_array(strtolower(parse_url($url, PHP_URL_SCHEME) ?? ''), ['http', 'https'], true) || parse_url($url, PHP_URL_USER) !== null || parse_url($url, PHP_URL_PASS) !== null) {
            throw new RuntimeException('Enter an HTTP or HTTPS URL without embedded credentials.');
        }

        $arguments = [config('scrapling.python'), base_path('scripts/scrape.py'), $url];
        if ($publicOnly) {
            $arguments[] = '--public-only';
        }
        $process = new Process($arguments, base_path());
        $process->setTimeout(45);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new RuntimeException(mb_substr(trim($process->getErrorOutput()) ?: 'Scrapling could not fetch this page.', -1000));
        }

        return json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
    }
}
