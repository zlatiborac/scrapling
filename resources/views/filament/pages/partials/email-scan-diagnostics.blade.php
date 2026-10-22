@php($diagnostics = $this->diagnostics)

<x-filament::section heading="Scan activity" description="Persistent activity from this project, newest first. Times include their UTC offset. Logs are independent of the contact database.">
    <div class="grid gap-4 text-sm">
        @if (! $diagnostics['log_directory_writable'])
            <p role="alert" class="text-danger-600 dark:text-danger-400">The log directory is not writable. Allow your Herd web process and terminal worker to write to storage/logs.</p>
        @endif
        @forelse (array_reverse(array_slice($diagnostics['recent_activity'], -15)) as $entry)
            <div class="min-w-0 border-b border-gray-200 pb-3 last:border-0 dark:border-gray-700">
                <p class="flex flex-wrap gap-x-3 gap-y-1 text-gray-600 dark:text-gray-400">
                    <time datetime="{{ $entry['at'] }}">{{ $entry['at'] }}</time>
                    <span>{{ $entry['scan_id'] ? 'Scan #'.$entry['scan_id'] : 'Submission' }}</span>
                    @if (isset($entry['counts']['elapsed_seconds']))
                        <span>{{ $entry['counts']['elapsed_seconds'] }} seconds</span>
                    @endif
                </p>
                <p class="mt-1 font-medium">{{ $entry['message'] }}</p>
                @if (isset($entry['error']))
                    <p class="mt-1 break-words text-danger-600 dark:text-danger-400">{{ $entry['error']['message'] }}</p>
                    <p class="mt-1 break-all text-gray-600 dark:text-gray-400">
                        {{ $entry['error']['reason'] }}
                        @if (isset($entry['error']['curl_code'])) · cURL {{ $entry['error']['curl_code'] }} @endif
                        @if (isset($entry['error']['http_status'])) · HTTP {{ $entry['error']['http_status'] }} @endif
                        @if (isset($entry['error']['python_exception'])) · {{ $entry['error']['python_exception'] }} @endif
                    </p>
                @endif
            </div>
        @empty
            <p>No activity has been recorded by this version yet. Start a new scan after restarting the worker.</p>
        @endforelse
        <div>
            <x-filament::button color="gray" size="sm" wire:click="refreshDiagnostics" wire:loading.attr="disabled">Refresh diagnostics</x-filament::button>
        </div>
    </div>
</x-filament::section>

<x-filament::section heading="Diagnostic report" description="Inspect the actual database and compare the web runtime with the worker. Select and copy the report when reporting a failure." collapsible collapsed>
    <div class="grid min-w-0 gap-4 text-sm">
        <p class="break-all">Database: {{ $diagnostics['web_runtime']['database']['driver'] ?? 'unavailable' }} — {{ $diagnostics['web_runtime']['database']['database'] ?? 'unavailable' }}</p>
        <dl class="grid gap-2">
            @foreach ($diagnostics['table_counts'] as $table => $count)
                <div class="flex flex-wrap justify-between gap-2">
                    <dt>{{ $table }}</dt>
                    <dd>{{ is_int($count) ? $count.' row(s)' : 'Unavailable — '.$count['message'] }}</dd>
                </div>
            @endforeach
        </dl>
        <p class="text-gray-600 dark:text-gray-400">The report includes local paths and scan names. Request query strings, credentials, page content, and raw exception text are excluded. Activity is retained in storage/logs/email-scraper-YYYY-MM-DD.log for 14 days; this report shows recent entries from today and yesterday.</p>
        <label for="email-diagnostic-report" class="font-medium">Report to copy</label>
        <textarea id="email-diagnostic-report" readonly rows="14" spellcheck="false" class="w-full min-w-0 rounded-lg border border-gray-300 bg-white p-3 font-mono text-xs text-gray-950 dark:border-gray-700 dark:bg-gray-950 dark:text-gray-100">{{ json_encode($diagnostics, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) }}</textarea>
    </div>
</x-filament::section>

<x-filament::section heading="Recent scans" description="Every queued attempt is saved before fetching starts, including attempts that fail. Open one to see its status and results." collapsible>
    <div class="grid gap-3 text-sm">
        @forelse ($this->recentScans as $recent)
            <div class="flex min-w-0 flex-wrap items-center justify-between gap-3 border-b border-gray-200 pb-3 last:border-0 dark:border-gray-700">
                <div class="min-w-0">
                    <p class="break-all font-medium">#{{ $recent->id }} · {{ $recent->name }}</p>
                    <p class="text-gray-600 dark:text-gray-400">{{ ucfirst($recent->status) }} · {{ $recent->created_at->toIso8601String() }}</p>
                </div>
                <x-filament::button tag="a" color="gray" size="sm" :href="\App\Filament\Pages\EmailScraper::getUrl(['scan' => $recent->id])">Open scan</x-filament::button>
            </div>
        @empty
            <p>No saved scan records in the database shown above. Contacts and sources can be empty after a failed fetch; a queued scan should still have its own record.</p>
        @endforelse
    </div>
</x-filament::section>
