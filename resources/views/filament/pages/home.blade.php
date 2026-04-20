<x-filament-panels::page>

    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">

        <div>
            @livewire(\App\Filament\Widgets\DutyRosterPublicUrlTable::class)
        </div>

        <div class="aspect-video relative overflow-hidden rounded-xl bg-gray-900">
            <div x-data="{
                active: 0,
                images: [
                    '{{ asset('assets/home/IMG_A3D5B332BB77-1.jpeg') }}',
                    '{{ asset('assets/home/IMG_0E0CBBC7CE7B-2.jpeg') }}'
                ]
            }" x-init="setInterval(() => active = (active + 1) % images.length, 3000)" class="w-full h-full">
                <template x-for="(img, index) in images" :key="index">
                    <img x-show="active === index" x-transition.opacity.duration.700ms :src="img"
                        class="absolute inset-0 w-full h-full object-contain">
                </template>
            </div>
        </div>

        <div>
            @livewire(\App\Filament\Widgets\DocumentPublicUrlTable::class)
        </div>

        <div>
            @livewire(\App\Filament\Widgets\NewsPublicUrlTable::class)
        </div>

    </div>

</x-filament-panels::page>
