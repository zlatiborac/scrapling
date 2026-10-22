<?php

namespace App\Services;

use App\Jobs\ProcessEmailScan;
use App\Models\EmailScan;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class EmailScanQueue
{
    public function submit(array $state, User $user): EmailScan
    {
        $url = $state['url'];
        if (! filter_var($url, FILTER_VALIDATE_URL) || ! in_array(strtolower(parse_url($url, PHP_URL_SCHEME) ?? ''), ['http', 'https'], true) || parse_url($url, PHP_URL_USER) !== null || parse_url($url, PHP_URL_PASS) !== null) {
            throw new RuntimeException('Enter an HTTP or HTTPS URL without embedded credentials.');
        }

        try {
            $scan = DB::transaction(function () use ($state, $url, $user): EmailScan {
                $scan = EmailScan::create([
                    'name' => Str::limit(trim($state['name'] ?? '') ?: (parse_url($url, PHP_URL_HOST) ?: 'E-mail scan'), 255, ''),
                    'url' => EmailArchive::safeUrl($url), 'mode' => $state['mode'], 'user_id' => $user->id,
                    'new_count' => 0, 'existing_count' => 0, 'skipped_count' => 0, 'status' => 'queued',
                    // The worker needs the original URL; keep its query secrets encrypted until finished.
                    'request' => ['url' => $url, 'max_pages' => (int) ($state['max_pages'] ?? 20), 'max_depth' => (int) ($state['max_depth'] ?? 3)],
                ]);
                // Queue insertion and scan creation commit together in the same SQLite database.
                ProcessEmailScan::dispatch($scan->id);

                return $scan;
            });
        } catch (Throwable $exception) {
            app(EmailScanDiagnostics::class)->record('enqueue_failed', exception: $exception);
            throw $exception;
        }

        app(EmailScanDiagnostics::class)->record('queued', $scan->id);

        return $scan;
    }
}
