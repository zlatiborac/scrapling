<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    private string $diagnosticLog;

    protected function setUp(): void
    {
        parent::setUp();
        $directory = base_path('work');
        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }
        $this->diagnosticLog = $directory.'/test-email-diagnostics-'.bin2hex(random_bytes(6));
        config(['scrapling.email_diagnostic_log' => $this->diagnosticLog.'.log']);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->diagnosticLog.'-*.log') ?: [] as $file) {
            unlink($file);
        }
        parent::tearDown();
    }
}
