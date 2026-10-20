<x-filament-panels::page>
    <form wire:submit="scrape" style="display: grid; gap: 1.5rem;">
        {{ $this->form }}

        <div>
        <x-filament::button type="submit" wire:loading.attr="disabled" wire:target="scrape">
            <span wire:loading.remove wire:target="scrape">Test URL</span>
            <span wire:loading wire:target="scrape">Fetching page…</span>
        </x-filament::button>
        </div>
    </form>

    @if ($error)
        <x-filament::section heading="Could not fetch page">
            <p role="alert" class="text-sm text-danger-600 dark:text-danger-400">{{ $error }}</p>
        </x-filament::section>
    @endif

    @if ($result)
        <x-filament::section heading="Scraping result">
            <dl class="text-sm" style="display: grid; gap: 1rem;">
                <div><dt class="font-semibold">Final URL</dt><dd class="break-all">{{ $result['url'] }}</dd></div>
                <div><dt class="font-semibold">HTTP status</dt><dd>{{ $result['status'] }}</dd></div>
                <div><dt class="font-semibold">Title</dt><dd>{{ $result['title'] ?: 'No title found' }}</dd></div>
                <div>
                    <dt class="font-semibold">H1 headings</dt>
                    <dd>
                        <ul class="list-inside list-disc">
                            @forelse ($result['headings'] as $heading)
                                <li>{{ $heading }}</li>
                            @empty
                                <li>No H1 headings found</li>
                            @endforelse
                        </ul>
                    </dd>
                </div>
            </dl>
        </x-filament::section>
        <x-filament::section heading="Raw JSON" collapsible collapsed>
            <pre class="overflow-x-auto text-sm">{{ json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre>
        </x-filament::section>
    @endif
</x-filament-panels::page>
