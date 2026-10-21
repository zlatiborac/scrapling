<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class EmailScan extends Model
{
    protected $fillable = ['name', 'url', 'mode', 'user_id', 'new_count', 'existing_count', 'skipped_count'];

    public function contacts(): BelongsToMany
    {
        return $this->belongsToMany(EmailContact::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
