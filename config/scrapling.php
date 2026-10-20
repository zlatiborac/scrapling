<?php

return [
    'python' => env('SCRAPLING_PYTHON', base_path(PHP_OS_FAMILY === 'Windows' ? '.venv/Scripts/python.exe' : '.venv/bin/python')),
];
