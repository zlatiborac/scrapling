<x-filament-panels::page>
    <p class="text-sm text-gray-600 dark:text-gray-400">
        Find e-mail addresses in page text and mailto links. Website scans follow links on the starting website,
        avoid repeat pages, and use a 30-second crawl budget plus your page and depth limits. JavaScript is not executed.
    </p>

    <form wire:submit="scrape" class="grid gap-6">
        {{ $this->form }}
        <div>
            <x-filament::button type="submit" wire:loading.attr="disabled" wire:target="scrape">
                <span wire:loading.remove wire:target="scrape">Find e-mails</span>
                <span wire:loading wire:target="scrape">Scanning pages…</span>
            </x-filament::button>
        </div>
    </form>

    <p wire:loading wire:target="scrape" role="status" class="text-sm text-gray-600 dark:text-gray-400">
        Following links and collecting source pages. Results will appear when the scan finishes.
    </p>

    <div wire:loading.remove wire:target="scrape" class="grid gap-6">
    @if ($error)
        <x-filament::section heading="Could not scan website">
            <p role="alert" class="text-sm text-danger-600 dark:text-danger-400">{{ $error }}</p>
        </x-filament::section>
    @endif

    @if ($result)
        @if ($saveError)
            <x-filament::section heading="Results were not saved">
                <p role="alert" class="text-sm text-danger-600 dark:text-danger-400">{{ $saveError }}</p>
            </x-filament::section>
        @elseif ($saveSummary)
            <x-filament::section heading="Saved to database">
                <div class="grid gap-3 text-sm">
                    <p>Saved scan: <strong>{{ $saveSummary['name'] }}</strong>.</p>
                    <p>{{ $saveSummary['new'] }} new contact(s), {{ $saveSummary['existing'] }} already saved. Existing names were preserved.</p>
                    @if ($saveSummary['skipped'])
                        <p>{{ $saveSummary['skipped'] }} invalid e-mail entry/entries were skipped.</p>
                    @endif
                    <div class="flex flex-wrap gap-3">
                        <x-filament::button tag="a" :href="\App\Filament\Resources\EmailScanResource::contactsUrl($saveSummary['id'])" size="sm">View saved e-mails</x-filament::button>
                        <x-filament::button tag="a" :href="\App\Filament\Resources\EmailScanResource::getUrl('index')" color="gray" size="sm">Saved scans</x-filament::button>
                    </div>
                </div>
            </x-filament::section>
        @endif
        <x-filament::section heading="E-mail addresses">
            <div class="grid gap-4 text-sm">
                <p>{{ count($result['emails']) }} unique e-mail(s) found on {{ $result['fetched'] }} fetched page(s).
                    {{ $result['pending'] }} queued page(s) not visited.</p>
                @if ($result['limits'])
                    <p role="status" class="text-warning-600 dark:text-warning-400">
                        Scan limited by: {{ implode(', ', array_map(fn ($limit) => ['pages' => 'page count', 'depth' => 'link depth', 'time' => '30-second time budget', 'discovery' => '500 discovered pages'][$limit] ?? $limit, $result['limits'])) }}.
                        More pages may contain e-mails. You can scan a specific section or contact page next.
                    </p>
                @endif
                @forelse ($result['emails'] as $entry)
                    <div class="min-w-0 border-t border-gray-200 pt-4 dark:border-gray-700">
                        <p class="break-all font-semibold">{{ $entry['email'] }}</p>
                        <p class="mt-2 text-gray-600 dark:text-gray-400">Found on:</p>
                        <ul class="list-inside list-disc">
                            @foreach ($entry['sources'] as $source)
                                <li class="break-all">{{ $source }}</li>
                            @endforeach
                        </ul>
                    </div>
                @empty
                    <p>No e-mail addresses found in the scanned pages. Addresses loaded by JavaScript or hidden in images may not be detected.</p>
                @endforelse
            </div>
        </x-filament::section>

        <x-filament::section heading="Crawl log" description="Each discovered page is tracked with its first referring page and link depth. Failed pages do not stop the rest of a website scan." collapsible>
            <div class="grid gap-4 text-sm">
                @foreach ($result['pages'] as $page)
                    <div class="min-w-0 border-b border-gray-200 pb-4 last:border-0 dark:border-gray-700">
                        <div class="flex flex-wrap items-center gap-2">
                            <x-filament::badge :color="match ($page['status']) { 'fetched' => 'success', 'failed' => 'danger', 'pending' => 'warning', default => 'gray' }">
                                {{ ucfirst($page['status']) }}
                            </x-filament::badge>
                            <span>Depth {{ $page['depth'] }}</span>
                            @if ($page['http_status']) <span>HTTP {{ $page['http_status'] }}</span> @endif
                            <span>{{ count($page['emails']) }} e-mail(s)</span>
                        </div>
                        <p class="mt-2 break-all font-medium">{{ $page['url'] }}</p>
                        @if ($page['final_url'] && $page['final_url'] !== $page['url'])
                            <p class="mt-1 break-all">Final URL: {{ $page['final_url'] }}</p>
                        @endif
                        <p class="mt-1 break-all text-gray-600 dark:text-gray-400">Found from: {{ $page['discovered_from'] ?? 'Starting page' }}</p>
                        @if ($page['error'])
                            <p class="mt-1 break-all text-danger-600 dark:text-danger-400">{{ $page['error'] }}</p>
                        @endif
                    </div>
                @endforeach
            </div>
        </x-filament::section>
        <p class="text-sm text-gray-600 dark:text-gray-400">E-mails, source pages, and named scans are saved automatically when storage succeeds. The detailed crawl log is only kept on this screen. Use Saved e-mails or Saved e-mail scans to review results and rename records.</p>
    @elseif (! $error)
        <x-filament::section heading="Start with a page or website">
            <p class="text-sm text-gray-600 dark:text-gray-400">Enter a public page address above. Choose Single page for a quick check, or Website to discover linked pages from that address.</p>
        </x-filament::section>
    @endif
    </div>
</x-filament-panels::page>
