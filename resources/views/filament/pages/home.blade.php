<x-filament-panels::page>

    <div class="uta-home">
        <div class="uta-home-roster">
            @livewire(\App\Filament\Widgets\DutyRosterPublicUrlTable::class)
        </div>

        <div class="uta-home-news">
            @livewire(\App\Filament\Widgets\NewsPublicUrlTable::class)
        </div>

        <div class="uta-home-docs">
            @livewire(\App\Filament\Widgets\DocumentPublicUrlTable::class)
        </div>
    </div>

</x-filament-panels::page>
