<?php

namespace App\Exceptions;

use RuntimeException;

class EmailFetchFailed extends RuntimeException
{
    public readonly string $reason;

    public readonly ?int $httpStatus;

    public function __construct(string $reason, ?int $httpStatus = null)
    {
        $messages = [
            'invalid_url' => 'Enter an HTTP or HTTPS URL without embedded credentials.',
            'dns_error' => 'DNS lookup failed. The worker could not resolve the website hostname.',
            'connection_error' => 'Could not connect to the website. Check its availability and your network.',
            'tls_error' => 'TLS certificate verification or negotiation failed. Check the website certificate and local CA setup.',
            'request_timeout' => 'The website request timed out before a page could be fetched.',
            'crawl_timeout' => 'The crawl time budget expired before a page could be fetched.',
            'process_timeout' => 'The Python scraper exceeded its subprocess time limit.',
            'python_unavailable' => 'Python could not be started. Check SCRAPLING_PYTHON and the project virtual environment.',
            'python_error' => 'The Python scraper could not run. Check the virtual environment and its installed dependencies.',
            'invalid_response' => 'The Python scraper returned an invalid response.',
            'blocked_url' => 'The dashboard only accepts public URLs; local and private addresses are blocked.',
            'redirect_error' => 'The website returned an invalid redirect or a redirect loop.',
            'http_error' => 'The website returned an HTTP error.',
            'fetch_error' => 'Could not fetch this page. Check the address and try a new scan.',
        ];
        $this->reason = array_key_exists($reason, $messages) ? $reason : 'fetch_error';
        $this->httpStatus = $httpStatus >= 400 && $httpStatus <= 599 ? $httpStatus : null;
        parent::__construct($this->reason === 'http_error' && $this->httpStatus !== null
            ? 'Target returned HTTP '.$this->httpStatus
            : $messages[$this->reason]);
    }
}
