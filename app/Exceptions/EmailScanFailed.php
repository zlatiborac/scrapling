<?php

namespace App\Exceptions;

use RuntimeException;

// Only safe, already-redacted messages belong in Laravel's failed-job record.
class EmailScanFailed extends RuntimeException {}
