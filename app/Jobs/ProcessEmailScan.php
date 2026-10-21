<?php

namespace App\Jobs;

use App\Models\EmailScan;
use App\Services\EmailArchive;
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

    public function __construct(public int $scanId)
    {
        $this->onConnection('scraping')->onQueue('email-scans');
    }

    public function handle(EmailScraper $scraper, EmailArchive $archive): void
    {
        // Atomic claim: duplicate deliveries and cancelled/finished jobs never crawl again.
        if (! EmailScan::whereKey($this->scanId)->where('status', 'queued')->update(['status' => 'running', 'started_at' => now()])) {
            return;
        }

        try {
            $scan = EmailScan::findOrFail($this->scanId);
            $user = $scan->user;
            if (! $user?->is_admin) {
                throw new RuntimeException('Administrator access is no longer available.');
            }
            $request = $scan->request;
            $result = $scraper->scrape($request['url'], $scan->mode === 'website', $request['max_pages'], $request['max_depth']);
            $scan->update(['result' => EmailScanResult::sanitize($result)]);

            DB::transaction(function () use ($scan, $result, $request, $user, $archive): void {
                $current = EmailScan::whereKey($scan->id)->lockForUpdate()->firstOrFail();
                if ($current->status !== 'running') {
                    return;
                }
                if (! $user->fresh()?->is_admin) {
                    throw new RuntimeException('Administrator access is no longer available.');
                }
                $archive->store($result, $request['url'], null, $user, $current);
                $current->update(['status' => 'completed', 'request' => null, 'finished_at' => now(), 'failure_message' => null]);
            });
        } catch (Throwable $exception) {
            report($exception);
            $this->failed($exception);
        }
    }

    public function failed(?Throwable $exception): void
    {
        $scan = EmailScan::find($this->scanId);
        if (! $scan || ! in_array($scan->status, ['queued', 'running'], true)) {
            return;
        }
        $scan->update([
            'status' => 'failed', 'request' => null, 'finished_at' => now(),
            'failure_message' => $scan->result !== null
                ? 'The scan finished, but its e-mails could not be saved. Try a new scan after checking the database setup.'
                : ($exception ? EmailScanResult::failureMessage($exception) : 'The background scan was interrupted. Start a new scan.'),
        ]);
    }
}
