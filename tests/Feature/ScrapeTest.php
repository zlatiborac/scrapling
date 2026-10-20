<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class ScrapeTest extends TestCase
{
    public function test_scrapling_fetches_a_real_page_through_artisan(): void
    {
        $this->withFixture(function (string $url) {
            $this->assertSame(0, Artisan::call('scrape', ['url' => $url]));
            $data = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
            $this->assertSame(200, $data['status']);
            $this->assertSame('Scrapling fixture', $data['title']);
            $this->assertSame(['Laravel + Scrapling'], $data['headings']);
        });
    }

    public function test_invalid_urls_are_rejected(): void
    {
        $this->artisan('scrape', ['url' => 'file:///etc/passwd'])
            ->expectsOutputToContain('An HTTP or HTTPS URL is required.')
            ->assertExitCode(1);
    }

    public function test_target_http_errors_fail_the_command(): void
    {
        $this->withFixture(function (string $url) {
            $this->artisan('scrape', ['url' => $url.'/missing'])
                ->expectsOutputToContain('Target returned HTTP 404')
                ->assertExitCode(1);
        });
    }

    private function withFixture(callable $test): void
    {
        $server = new Process([config('scrapling.python'), base_path('tests/Fixtures/server.py')]);
        $server->start();
        try {
            $this->assertTrue($server->waitUntil(fn ($type, $output) => str_contains($output, 'http://')));
            $test(trim($server->getOutput()));
        } finally {
            $server->stop();
        }
    }
}
