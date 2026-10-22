<?php

namespace Tests\Concerns;

use App\Exceptions\EmailScanFailed;
use App\Jobs\ProcessEmailScan;
use Livewire\Features\SupportTesting\Testable;

trait RunsEmailScans
{
    private function finishEmailScan(Testable $component): Testable
    {
        $this->runEmailScanJob((int) $component->get('scanId'));

        return $component->call('refreshScan');
    }

    private function runEmailScanJob(int $id): void
    {
        try {
            app()->call([new ProcessEmailScan($id), 'handle']);
        } catch (EmailScanFailed) {
            // The real worker catches this and emits JobFailed; unit tests then poll the saved state.
        }
    }
}
