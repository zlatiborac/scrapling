<?php

return [
    'python' => env('SCRAPLING_PYTHON', base_path(PHP_OS_FAMILY === 'Windows' ? '.venv/Scripts/python.exe' : '.venv/bin/python')),
    'email_request_timeout' => 10,
    'email_crawl_timeout' => 30,
    'email_process_timeout' => 45,
];
