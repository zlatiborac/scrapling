<?php

namespace App\Services;

use App\Models\EmailContact;
use App\Models\EmailScan;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class EmailArchive
{
    public function store(array $result, string $url, ?string $name, User $user): EmailScan
    {
        return DB::transaction(function () use ($result, $url, $name, $user): EmailScan {
            $new = 0;
            $existing = 0;
            $skipped = 0;
            $groups = [];
            foreach ($result['emails'] as $entry) {
                $email = strtolower(trim($entry['email']));
                if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $skipped++;

                    continue;
                }
                $groups[$email] = array_merge($groups[$email] ?? [], $entry['sources']);
            }

            $ids = [];
            foreach ($groups as $email => $sources) {
                $contact = EmailContact::firstOrCreate(['email' => $email], [
                    'name' => Str::limit(Str::after($email, '@'), 255, ''),
                    'last_seen_at' => now(),
                ]);
                $contact->wasRecentlyCreated ? $new++ : $existing++;
                // Do not overwrite a name the administrator has changed.
                $contact->update(['last_seen_at' => now()]);
                $ids[] = $contact->id;
                foreach (array_unique(array_filter(array_map($this->safeUrl(...), $sources))) as $source) {
                    $savedSource = $contact->sources()->firstOrCreate(['url' => $source]);
                    $savedSource->touch();
                }
            }

            $scan = EmailScan::create([
                'name' => Str::limit(trim($name ?? '') ?: (parse_url($url, PHP_URL_HOST) ?: 'E-mail scan'), 255, ''),
                'url' => $this->safeUrl($url),
                'mode' => $result['mode'],
                'user_id' => $user->id,
                'new_count' => $new,
                'existing_count' => $existing,
                'skipped_count' => $skipped,
            ]);
            $scan->contacts()->attach($ids);

            return $scan;
        });
    }

    private function safeUrl(string $url): ?string
    {
        $parts = parse_url($url);
        if (! is_array($parts) || ! in_array($parts['scheme'] ?? '', ['http', 'https'], true) || empty($parts['host'])) {
            return null;
        }

        // Persist source pages without credentials, query-string secrets or fragments.
        return $parts['scheme'].'://'.$parts['host']
            .(isset($parts['port']) ? ':'.$parts['port'] : '')
            .($parts['path'] ?? '/');
    }
}
