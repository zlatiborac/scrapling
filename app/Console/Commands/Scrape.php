<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

class Scrape extends Command
{
    protected $signature = 'scrape {url : HTTP or HTTPS URL to fetch}';

    protected $description = 'Fetch a page with Python Scrapling and output JSON';

    public function handle(): int
    {
        $url = $this->argument('url');
        if (! filter_var($url, FILTER_VALIDATE_URL) || ! in_array(strtolower(parse_url($url, PHP_URL_SCHEME) ?? ''), ['http', 'https'], true)) {
            $this->error('An HTTP or HTTPS URL is required.');
            return self::FAILURE;
        }

        $process = new Process([config('scrapling.python'), base_path('scripts/scrape.py'), $url], base_path());
        $process->setTimeout(45);

        try {
            $process->mustRun();
            $data = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
            $this->line(json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
            return self::SUCCESS;
        } catch (\Throwable $exception) {
            $this->error('Scraping failed: '.$exception->getMessage());
            return self::FAILURE;
        }
    }
}
