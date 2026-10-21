<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EmailContact extends Model
{
    protected $fillable = ['name', 'email', 'last_seen_at'];

    protected function casts(): array
    {
        return ['last_seen_at' => 'datetime'];
    }

    public function setEmailAttribute(string $value): void
    {
        $this->attributes['email'] = strtolower(trim($value));
    }

    public function sources(): HasMany
    {
        return $this->hasMany(EmailContactSource::class);
    }

    public function scans(): BelongsToMany
    {
        return $this->belongsToMany(EmailScan::class);
    }
}
