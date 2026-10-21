<?php

namespace Tests\Concerns;

use App\Jobs\ProcessEmailScan;
use Livewire\Features\SupportTesting\Testable;

trait RunsEmailScans
{
    private function finishEmailScan(Testable $component): Testable
    {
        app()->call([new ProcessEmailScan((int) $component->get('scanId')), 'handle']);

        return $component->call('refreshScan');
    }
}
