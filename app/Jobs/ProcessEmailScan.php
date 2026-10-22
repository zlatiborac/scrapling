<?php

namespace App\Jobs;

use App\Exceptions\EmailScanFailed;
use App\Models\EmailScan;
use App\Services\EmailArchive;
use App\Services\EmailScanDiagnostics;
use App\Services\EmailScanResult;
use App\Services\EmailScraper;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class ProcessEmailScan implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    // Python has a 45-second limit; allow time to archive, below the 120s reservation.
    public int $timeout = 75;

    public bool $failOnTimeout = true;

    private bool $hasFetchedResult = false;

    public function __construct(public int $scanId)
    {
        $this->onConnection('scraping')->onQueue('email-scans');
    }

    public function handle(EmailScraper $scraper, EmailArchive $archive): void
    {
        $diagnostics = app(EmailScanDiagnostics::class);
        $scan = null;
        try {
            // Atomic claim: duplicate deliveries and cancelled/finished jobs never crawl again.
            if (! EmailScan::whereKey($this->scanId)->where('status', 'queued')->update(['status' => 'running', 'started_at' => now()])) {
                if (! EmailScan::whereKey($this->scanId)->exists()) {
                    $diagnostics->record('scan_missing', $this->scanId);
                    throw new EmailScanFailed('No saved scan exists for this job. Check Scan activity and the database shown in the diagnostic report.');
                }

                return;
            }
            $diagnostics->record('worker_started', $this->scanId);
            $scan = EmailScan::findOrFail($this->scanId);
            $user = $scan->user;
            if (! $user?->is_admin) {
                throw new RuntimeException('Administrator access is no longer available.');
            }
            $request = $scan->request;
            $diagnostics->record('fetching', $scan->id);
            $result = $scraper->scrape($request['url'], $scan->mode === 'website', $request['max_pages'], $request['max_depth']);
            $this->hasFetchedResult = true;
            $diagnostics->record('fetched', $scan->id, counts: ['fetched_pages' => $result['fetched'], 'unique_emails' => count($result['emails'])]);
            $scan->update(['result' => EmailScanResult::sanitize($result)]);
            $diagnostics->record('archiving', $scan->id);

            $completed = DB::transaction(function () use ($scan, $result, $request, $user, $archive): bool {
                $current = EmailScan::whereKey($scan->id)->lockForUpdate()->firstOrFail();
                if ($current->status !== 'running') {
                    return false;
                }
                if (! $user->fresh()?->is_admin) {
                    throw new RuntimeException('Administrator access is no longer available.');
                }
                $archive->store($result, $request['url'], null, $user, $current);
                $current->update(['status' => 'completed', 'request' => null, 'finished_at' => now(), 'failure_message' => null]);

                return true;
            });
            if ($completed) {
                $diagnostics->record('completed', $scan->id);
            }
        } catch (Throwable $exception) {
            if ($exception instanceof EmailScanFailed) {
                throw $exception;
            }
            if (! $scan) {
                $diagnostics->record('claim_failed', $this->scanId, $exception);
            }
            $this->failed($exception);
            // Returning normally makes Laravel print DONE even though the scan failed.
            // Throw a safe exception so the queue records FAIL without leaking the target URL.
            throw new EmailScanFailed($this->failureMessage($exception, $scan));
        }
    }

    public function failed(?Throwable $exception): void
    {
        $diagnostics = app(EmailScanDiagnostics::class);
        try {
            $scan = EmailScan::find($this->scanId);
        } catch (Throwable $databaseException) {
            $diagnostics->record('claim_failed', $this->scanId, $exception ?? $databaseException);

            return;
        }
        if (! $scan || ! in_array($scan->status, ['queued', 'running'], true)) {
            return;
        }
        $diagnostics->record('failed', $scan->id, $exception, counts: $scan->started_at
            ? ['elapsed_seconds' => round($scan->started_at->diffInSeconds(now()), 3)] : []);
        try {
            $scan->update([
                'status' => 'failed', 'request' => null, 'finished_at' => now(),
                'failure_message' => $this->failureMessage($exception, $scan),
            ]);
        } catch (Throwable $databaseException) {
            $diagnostics->record('failed', $scan->id, $databaseException);
        }
    }

    private function failureMessage(?Throwable $exception, ?EmailScan $scan): string
    {
        if ($this->hasFetchedResult || $scan?->result !== null) {
            return 'The website was fetched, but its results could not be saved. Check Scan activity and start a new scan.';
        }

        return $exception ? app(EmailScanDiagnostics::class)->error($exception)['message'] : 'The background scan was interrupted. Start a new scan.';
    }
}
