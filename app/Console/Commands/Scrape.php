<?php

namespace App\Console\Commands;

use App\Services\Scraper;
use Illuminate\Console\Command;
use Throwable;

class Scrape extends Command
{
    protected $signature = 'scrape {url : HTTP or HTTPS URL to fetch}';

    protected $description = 'Fetch a page with Python Scrapling and output JSON';

    public function handle(Scraper $scraper): int
    {
        $url = $this->argument('url');
        if (! filter_var($url, FILTER_VALIDATE_URL) || ! in_array(strtolower(parse_url($url, PHP_URL_SCHEME) ?? ''), ['http', 'https'], true)) {
            $this->error('An HTTP or HTTPS URL is required.');

            return self::FAILURE;
        }

        try {
            // Trusted CLI usage also supports local development fixtures.
            $data = $scraper->scrape($url, publicOnly: false);
            $this->line(json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error('Scraping failed: '.$exception->getMessage());

            return self::FAILURE;
        }
    }
}
