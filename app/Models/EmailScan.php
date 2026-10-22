<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class EmailScan extends Model
{
    protected $fillable = ['name', 'url', 'mode', 'user_id', 'new_count', 'existing_count', 'skipped_count',
        'status', 'request', 'result', 'failure_message', 'started_at', 'finished_at'];

    protected $hidden = ['request'];

    protected function casts(): array
    {
        return ['request' => 'encrypted:array', 'result' => 'array', 'started_at' => 'datetime', 'finished_at' => 'datetime'];
    }

    protected function getJsonCastFlags($key): int
    {
        // A bad byte in optional crawl text must not discard every valid contact.
        // Keep encrypted request data and other attributes strict.
        return parent::getJsonCastFlags($key) | ($key === 'result' ? JSON_INVALID_UTF8_SUBSTITUTE : 0);
    }

    public function contacts(): BelongsToMany
    {
        return $this->belongsToMany(EmailContact::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
