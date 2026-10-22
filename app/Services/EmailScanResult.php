<?php

namespace App\Services;

use App\Exceptions\EmailFetchFailed;
use Illuminate\Database\Eloquent\JsonEncodingException;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Queue\TimeoutExceededException;
use Throwable;

class EmailScanResult
{
    public static function sanitize(array $result): array
    {
        $safe = array_intersect_key($result, array_flip(['mode', 'fetched', 'attempted', 'pending', 'limits', 'complete']));
        $safe['url'] = EmailArchive::safeUrl($result['url']);
        $safe['emails'] = array_map(static fn (array $entry): array => [
            'email' => $entry['email'],
            'sources' => array_values(array_unique(array_filter(array_map(EmailArchive::safeUrl(...), $entry['sources'])))),
        ], $result['emails']);
        $safe['pages'] = array_map(static function (array $page): array {
            $record = array_intersect_key($page, array_flip(['depth', 'status', 'http_status', 'emails']));
            foreach (['url', 'final_url', 'discovered_from'] as $key) {
                $record[$key] = isset($page[$key]) ? EmailArchive::safeUrl($page[$key]) : null;
            }
            $record['error'] = empty($page['error']) ? null : (isset($page['error_code'])
                ? (new EmailFetchFailed($page['error_code'], $page['http_status'] ?? null))->getMessage()
                : self::safeMessage($page['error']));

            return $record;
        }, $result['pages']);

        return $safe;
    }

    public static function failureMessage(Throwable $exception): string
    {
        if ($exception instanceof EmailFetchFailed) {
            return $exception->getMessage();
        }
        if ($exception instanceof JsonEncodingException) {
            return 'The scan data could not be saved as JSON. Check Scan activity for the encoding error.';
        }
        if ($exception instanceof TimeoutExceededException || $exception instanceof MaxAttemptsExceededException) {
            return 'The background worker exceeded its execution limit or was interrupted. Start a new scan.';
        }

        return self::safeMessage($exception->getMessage());
    }

    private static function safeMessage(string $message): string
    {
        if (str_contains($message, 'local and private')) {
            return 'The dashboard only accepts public URLs; local and private addresses are blocked.';
        }
        if (preg_match('/^Target returned HTTP ([45][0-9]{2})$/', $message, $matches)) {
            return 'Target returned HTTP '.$matches[1];
        }
        if (in_array($message, ['Maximum link depth reached', 'Not an HTML or text page', 'Redirect leaves the starting website',
            'Target returned too many redirects', 'Target returned a redirect loop', 'Crawl time limit reached'], true)) {
            return $message;
        }

        return 'Could not fetch this page. Check the address and try a new scan.';
    }
}
