<?php

namespace App\Services;

use App\Exceptions\EmailFetchFailed;
use JsonException;
use RuntimeException;
use Symfony\Component\Process\Exception\ProcessStartFailedException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

class EmailScraper
{
    public function scrape(string $url, bool $website = false, int $maxPages = 20, int $maxDepth = 3, bool $publicOnly = true): array
    {
        if (! filter_var($url, FILTER_VALIDATE_URL) || ! in_array(strtolower(parse_url($url, PHP_URL_SCHEME) ?? ''), ['http', 'https'], true) || parse_url($url, PHP_URL_USER) !== null || parse_url($url, PHP_URL_PASS) !== null) {
            throw new EmailFetchFailed('invalid_url');
        }

        if ($maxPages < 1 || $maxPages > 50 || $maxDepth < 0 || $maxDepth > 5) {
            throw new RuntimeException('Use 1–50 pages and a link depth of 0–5.');
        }

        $arguments = [config('scrapling.python'), base_path('scripts/email_scrape.py'), $url, '--max-pages', (string) $maxPages, '--max-depth', (string) $maxDepth,
            '--time-limit', (string) config('scrapling.email_crawl_timeout', 30), '--request-timeout', (string) config('scrapling.email_request_timeout', 10)];
        if ($website) {
            $arguments[] = '--website';
        }
        if ($publicOnly) {
            $arguments[] = '--public-only';
        }

        $process = new Process($arguments, base_path());
        $process->setTimeout((float) config('scrapling.email_process_timeout', 45));
        try {
            $process->run();
        } catch (ProcessTimedOutException) {
            throw new EmailFetchFailed('process_timeout');
        } catch (ProcessStartFailedException) {
            throw new EmailFetchFailed('python_unavailable');
        }

        if (! $process->isSuccessful()) {
            $lines = preg_split('/\R/', trim($process->getErrorOutput()));
            $error = json_decode(end($lines) ?: '{}', true);
            $reason = $error['error']['code'] ?? null;
            $status = $error['error']['http_status'] ?? null;
            throw new EmailFetchFailed(is_string($reason) ? $reason : ($process->getExitCode() === 127 ? 'python_unavailable' : 'python_error'), is_int($status) ? $status : null);
        }

        try {
            $result = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new EmailFetchFailed('invalid_response');
        }
        if (! is_array($result) || ! isset($result['emails'], $result['pages'], $result['url'], $result['mode']) || ! is_array($result['emails']) || ! is_array($result['pages'])) {
            throw new EmailFetchFailed('invalid_response');
        }

        return $result;
    }
}
