<x-filament-panels::page>
    <div class="space-y-6">
        {{-- Form Section --}}
        <div class="fi-section rounded-xl bg-white shadow-xs ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 overflow-hidden">
            <form wire:submit="checkTariff" class="p-6 space-y-5">
                {{ $this->form }}

                {{-- Action Buttons --}}
                <div class="pt-4 border-t border-gray-100 dark:border-white/10 flex items-center justify-between flex-wrap gap-2.5">
                    <div class="flex items-center gap-2.5 flex-wrap">
                        <x-filament::button 
                            type="submit" 
                            size="md" 
                            icon="heroicon-m-magnifying-glass"
                            color="primary"
                            wire:loading.attr="disabled"
                            wire:target="checkTariff"
                        >
                            <span wire:loading.remove wire:target="checkTariff">Cek Tarif</span>
                            <span wire:loading wire:target="checkTariff">Mencari...</span>
                        </x-filament::button>

                        <x-filament::button 
                            type="button" 
                            color="gray" 
                            size="md" 
                            wire:click="resetSearch"
                        >
                            Reset Form
                        </x-filament::button>

                        <x-filament::button 
                            type="button" 
                            :color="$showHistory ? 'primary' : 'gray'" 
                            size="md" 
                            wire:click="toggleHistory"
                        >
                            <span>History</span>
                            @php
                                $historyCount = $this->getSearchHistories()->count();
                            @endphp
                            @if($historyCount > 0)
                                <span class="ms-1.5 inline-flex items-center justify-center px-1.5 py-0.5 text-xs font-bold rounded-full {{ $showHistory ? 'bg-white/20 text-white' : 'bg-primary-100 text-primary-700 dark:bg-primary-950 dark:text-primary-300' }}">
                                    {{ $historyCount }}
                                </span>
                            @endif
                        </x-filament::button>
                    </div>
                </div>
            </form>
        </div>

        {{-- History Table Section (Standard Filament Table Layout) --}}
        @if($showHistory)
            @php
                $histories = $this->getSearchHistories();
            @endphp
            <div 
                x-data="{ search: '' }"
                class="fi-ta-ctn divide-y divide-gray-200 overflow-hidden rounded-xl bg-white shadow-xs ring-1 ring-gray-950/5 dark:divide-white/10 dark:bg-gray-900 dark:ring-white/10"
            >
                {{-- Table Toolbar --}}
                <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 p-4 sm:px-6">
                    <div class="flex items-center gap-2 flex-wrap">
                        <h3 class="text-base font-semibold text-gray-950 dark:text-white">
                            Riwayat Pencarian Tarif
                        </h3>
                        <span class="text-gray-300 dark:text-gray-600">&bull;</span>
                        <span class="text-xs text-gray-500 dark:text-gray-400">
                            {{ $histories->count() }} rute tersimpan
                        </span>
                    </div>

                    <div class="flex items-center gap-x-2.5 ms-auto flex-wrap">
                        @if($histories->isNotEmpty())
                            <div class="relative rounded-lg shadow-2xs">
                                <div class="pointer-events-none absolute inset-y-0 start-0 flex items-center ps-3 text-gray-400 dark:text-gray-500">
                                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z" />
                                    </svg>
                                </div>
                                <input 
                                    type="search" 
                                    placeholder="Search riwayat..." 
                                    x-model="search"
                                    class="block w-40 sm:w-52 rounded-lg border-0 py-1.5 ps-9 pe-3 text-sm text-gray-950 ring-1 ring-inset ring-gray-950/10 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-primary-600 dark:bg-white/5 dark:text-white dark:ring-white/20 dark:placeholder:text-gray-500 dark:focus:ring-primary-500"
                                />
                            </div>
                        @endif
                    </div>
                </div>

                {{-- Table Content --}}
                @if($histories->isNotEmpty())
                    <div class="overflow-x-auto">
                        <table class="w-full table-auto divide-y divide-gray-200 text-start dark:divide-white/5">
                            <thead class="bg-gray-50/50 dark:bg-white/5">
                                <tr>
                                    <th scope="col" class="px-4 py-3.5 ps-4 sm:ps-6 text-start text-sm font-semibold text-gray-950 dark:text-white">
                                        Waktu
                                    </th>
                                    <th scope="col" class="px-4 py-3.5 text-start text-sm font-semibold text-gray-950 dark:text-white">
                                        Rute Pengiriman
                                    </th>
                                    <th scope="col" class="px-4 py-3.5 text-start text-sm font-semibold text-gray-950 dark:text-white">
                                        Berat & Nilai Barang
                                    </th>
                                    <th scope="col" class="px-4 py-3.5 text-start text-sm font-semibold text-gray-950 dark:text-white">
                                        Service
                                    </th>
                                    <th scope="col" class="px-4 py-3.5 text-start text-sm font-semibold text-gray-950 dark:text-white">
                                        Hasil Tarif Seluruh Ekspedisi
                                    </th>
                                    <th scope="col" class="px-4 py-3.5 pe-4 sm:pe-6 text-end text-sm font-semibold text-gray-950 dark:text-white">
                                        Aksi
                                    </th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200 dark:divide-white/5">
                                @foreach($histories as $item)
                                    <tr 
                                        x-show="!search || '{{ strtolower($item->origin . ' ' . $item->destination . ' ' . $item->district . ' ' . $item->cheapest_expedition . ' ' . $item->service . ' ' . $item->store_name) }}'.includes(search.toLowerCase())"
                                        class="transition duration-75 hover:bg-gray-50/50 dark:hover:bg-white/5"
                                    >
                                        {{-- Waktu --}}
                                        <td class="px-4 py-4 ps-4 sm:ps-6 text-sm text-gray-950 dark:text-white align-top whitespace-nowrap">
                                            <div>{{ $item->created_at->setTimezone('Asia/Jakarta')->format('d M Y, H:i') }} WIB</div>
                                            <div class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">
                                                {{ $item->created_at->setTimezone('Asia/Jakarta')->diffForHumans() }}
                                            </div>
                                        </td>

                                        {{-- Rute --}}
                                        <td class="px-4 py-4 text-sm text-gray-950 dark:text-white align-top">
                                            <div class="font-medium text-gray-950 dark:text-white">
                                                {{ $item->origin }} &rarr; {{ $item->destination }}
                                            </div>
                                            @if(!empty($item->district))
                                                <div class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">
                                                    Kec. {{ $item->district }}
                                                </div>
                                            @endif
                                        </td>

                                        {{-- Berat & Nilai --}}
                                        <td class="px-4 py-4 text-sm text-gray-950 dark:text-white align-top whitespace-nowrap">
                                            <div class="font-medium">
                                                {{ $item->weight }} KG
                                                @if(!empty($item->qty))
                                                    <span class="text-xs font-normal text-gray-500 dark:text-gray-400">&bull; {{ $item->qty }} Qty</span>
                                                @endif
                                            </div>
                                            @if(!empty($item->total_amount))
                                                <div class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">
                                                    Rp {{ number_format($item->total_amount, 0, ',', '.') }}
                                                </div>
                                            @endif
                                        </td>

                                        {{-- Service --}}
                                        <td class="px-4 py-4 text-sm text-gray-950 dark:text-white align-top whitespace-nowrap">
                                            @if(!empty($item->service))
                                                <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-sky-50 text-sky-700 dark:bg-sky-950 dark:text-sky-300">
                                                    {{ $item->service }}
                                                </span>
                                            @else
                                                <span class="text-xs text-gray-500 dark:text-gray-400">
                                                    Semua Service
                                                </span>
                                            @endif
                                        </td>

                                        {{-- Hasil Tarif Seluruh Ekspedisi --}}
                                        <td class="px-4 py-4 text-sm align-top">
                                            @php
                                                $ratesList = $item->getRatesList();
                                                $historyMultiOrigin = str_contains((string) $item->origin, ',');
                                                $historyMultiDestination = str_contains((string) $item->destination, ',');
                                            @endphp
                                            @if(!empty($ratesList))
                                                <div class="space-y-1">
                                                    @foreach($ratesList as $idx => $rate)
                                                        <div class="flex items-center justify-between gap-3 text-xs">
                                                            <div class="flex items-center gap-1.5 flex-wrap">
                                                                @if($historyMultiOrigin && $historyMultiDestination && !empty($rate['origin']) && !empty($rate['destination']))
                                                                    <span class="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-medium bg-primary-50 text-primary-700 dark:bg-primary-950 dark:text-primary-300">
                                                                        {{ $rate['origin'] }} &rarr; {{ $rate['destination'] }}
                                                                    </span>
                                                                @elseif($historyMultiOrigin && !empty($rate['origin']))
                                                                    <span class="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-medium bg-primary-50 text-primary-700 dark:bg-primary-950 dark:text-primary-300">
                                                                        {{ $rate['origin'] }}
                                                                    </span>
                                                                @elseif($historyMultiDestination && !empty($rate['destination']))
                                                                    <span class="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-medium bg-sky-50 text-sky-700 dark:bg-sky-950 dark:text-sky-300">
                                                                        &rarr; {{ $rate['destination'] }}
                                                                    </span>
                                                                @endif
                                                                <span class="font-medium text-gray-950 dark:text-white">{{ $rate['expedition'] }}</span>
                                                                <span class="text-gray-400">({{ $rate['service'] }})</span>
                                                                @if($idx === 0)
                                                                    <span class="inline-flex items-center px-1.5 py-0.2 rounded text-[10px] font-semibold bg-emerald-50 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300">
                                                                        Termurah
                                                                    </span>
                                                                @endif
                                                            </div>
                                                            <div class="font-semibold text-gray-900 dark:text-white whitespace-nowrap">
                                                                Rp {{ number_format($rate['total_cost'], 0, ',', '.') }}
                                                            </div>
                                                        </div>
                                                    @endforeach
                                                </div>
                                            @else
                                                <span class="text-xs text-amber-600 dark:text-amber-400">
                                                    Belum ada tarif
                                                </span>
                                            @endif
                                        </td>

                                        {{-- Aksi --}}
                                        <td class="px-4 py-4 pe-4 sm:pe-6 align-top text-end whitespace-nowrap">
                                            <div class="flex items-center justify-end gap-1.5">
                                                <x-filament::button 
                                                    type="button" 
                                                    size="xs" 
                                                    color="primary" 
                                                    variant="text"
                                                    wire:click="applyHistory({{ $item->id }})"
                                                >
                                                    Buka
                                                </x-filament::button>
                                                <x-filament::button 
                                                    type="button" 
                                                    size="xs" 
                                                    color="gray" 
                                                    variant="text"
                                                    wire:click="deleteHistoryItem({{ $item->id }})"
                                                >
                                                    Hapus
                                                </x-filament::button>
                                            </div>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    {{-- Footer --}}
                    <div class="flex items-center justify-between border-t border-gray-200 px-4 py-3 sm:px-6 dark:border-white/10">
                        <div class="text-sm text-gray-700 dark:text-gray-200">
                            Showing 1 to {{ $histories->count() }} of {{ $histories->count() }} results
                        </div>
                    </div>
                @else
                    <div class="p-8 text-center space-y-2">
                        <h4 class="text-sm font-semibold text-gray-900 dark:text-white">
                            Belum Ada Riwayat Pencarian
                        </h4>
                        <p class="text-xs text-gray-500 dark:text-gray-400 max-w-md mx-auto">
                            Rute pengiriman dan ekspedisi yang pernah Anda cari akan otomatis dicatat di sini.
                        </p>
                    </div>
                @endif
            </div>
        @endif

        {{-- Results Section (Consistent Filament Table Layout Sesuai Foto Pertama) --}}
        @if($hasSearched && !$showHistory)
            @php
                $showOriginColumn = count($searchedOrigins) > 1;
                $showDestinationColumn = count($searchedDestinations) > 1;
                $searchPlaceholder = match (true) {
                    $showOriginColumn && $showDestinationColumn => 'Cari asal / tujuan / ekspedisi...',
                    $showOriginColumn => 'Cari asal / ekspedisi...',
                    $showDestinationColumn => 'Cari tujuan / ekspedisi...',
                    default => 'Search...',
                };
            @endphp
            @if(count($rates) > 0)
                <div 
                    x-data="{ search: '' }"
                    class="fi-ta-ctn divide-y divide-gray-200 overflow-hidden rounded-xl bg-white shadow-xs ring-1 ring-gray-950/5 dark:divide-white/10 dark:bg-gray-900 dark:ring-white/10"
                >
                    {{-- Table Toolbar (Header & Rute Info) --}}
                    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 p-4 sm:px-6">
                        <div class="flex items-center gap-2 flex-wrap">
                            <h3 class="text-base font-semibold text-gray-950 dark:text-white">
                                Hasil Cek Tarif
                            </h3>
                            <span class="text-gray-300 dark:text-gray-600">&bull;</span>
                            <div class="flex items-center gap-1.5 text-sm text-gray-700 dark:text-gray-300 flex-wrap">
                                @if($showOriginColumn)
                                    <span class="inline-flex items-center gap-1 flex-wrap">
                                        @foreach($searchedOrigins as $origBadge)
                                            <span class="inline-flex items-center px-2 py-0.5 rounded-md text-xs font-medium bg-primary-50 text-primary-700 dark:bg-primary-950 dark:text-primary-300">
                                                {{ $origBadge }}
                                            </span>
                                        @endforeach
                                    </span>
                                @else
                                    <span class="font-medium text-gray-950 dark:text-white">{{ $searchedOrigin }}</span>
                                @endif
                                <span class="text-gray-400 font-semibold">&rarr;</span>
                                @if($showDestinationColumn)
                                    <span class="inline-flex items-center gap-1 flex-wrap">
                                        @foreach($searchedDestinations as $destBadge)
                                            <span class="inline-flex items-center px-2 py-0.5 rounded-md text-xs font-medium bg-sky-50 text-sky-700 dark:bg-sky-950 dark:text-sky-300">
                                                {{ $destBadge }}
                                            </span>
                                        @endforeach
                                    </span>
                                    @if(!empty($searchedDistrict))
                                        <span class="font-medium text-gray-950 dark:text-white">Kec. {{ $searchedDistrict }}</span>
                                    @endif
                                @else
                                    <span class="font-medium text-gray-950 dark:text-white">{{ $searchedDestination }}@if(!empty($searchedDistrict)), Kec. {{ $searchedDistrict }}@endif</span>
                                @endif
                                <span class="text-gray-500 dark:text-gray-400">({{ $searchedWeight }} KG{{ !empty($searchedQty) ? ' • ' . $searchedQty . ' Qty' : '' }})</span>
                                @if(!empty($searchedTotalAmount))
                                    <span class="text-gray-500 dark:text-gray-400">&bull; Nilai: Rp {{ number_format($searchedTotalAmount, 0, ',', '.') }}</span>
                                @endif
                            </div>
                        </div>

                        {{-- Search Filter --}}
                        <div class="flex items-center gap-x-3 ms-auto">
                            <div class="relative rounded-lg shadow-2xs">
                                <div class="pointer-events-none absolute inset-y-0 start-0 flex items-center ps-3 text-gray-400 dark:text-gray-500">
                                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z" />
                                    </svg>
                                </div>
                                <input 
                                    type="search" 
                                    placeholder="{{ $searchPlaceholder }}" 
                                    x-model="search"
                                    class="block w-40 sm:w-56 rounded-lg border-0 py-1.5 ps-9 pe-3 text-sm text-gray-950 ring-1 ring-inset ring-gray-950/10 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-primary-600 dark:bg-white/5 dark:text-white dark:ring-white/20 dark:placeholder:text-gray-500 dark:focus:ring-primary-500"
                                />
                            </div>
                        </div>
                    </div>

                    {{-- Table Body (Header dan Kolom Persis Sesuai Foto Pertama) --}}
                    <div class="overflow-x-auto">
                        <table class="w-full table-auto divide-y divide-gray-200 text-start dark:divide-white/5">
                            <thead class="bg-gray-50/50 dark:bg-white/5">
                                <tr>
                                    @if($showOriginColumn)
                                        <th scope="col" class="px-4 py-3.5 ps-4 sm:ps-6 text-start text-sm font-semibold text-gray-950 dark:text-white">
                                            Kota Asal
                                        </th>
                                    @endif
                                    @if($showDestinationColumn)
                                        <th scope="col" class="px-4 py-3.5 {{ $showOriginColumn ? '' : 'ps-4 sm:ps-6' }} text-start text-sm font-semibold text-gray-950 dark:text-white">
                                            Kota Tujuan
                                        </th>
                                    @endif
                                    <th scope="col" class="px-4 py-3.5 {{ ($showOriginColumn || $showDestinationColumn) ? '' : 'ps-4 sm:ps-6' }} text-start text-sm font-semibold text-gray-950 dark:text-white">
                                        Ekspedisi
                                    </th>
                                    <th scope="col" class="px-4 py-3.5 text-start text-sm font-semibold text-gray-950 dark:text-white">
                                        Layanan
                                    </th>
                                    <th scope="col" class="px-4 py-3.5 text-end text-sm font-semibold text-gray-950 dark:text-white">
                                        Tarif
                                    </th>
                                    <th scope="col" class="px-4 py-3.5 text-center text-sm font-semibold text-gray-950 dark:text-white">
                                        Min KG
                                    </th>
                                    <th scope="col" class="px-4 py-3.5 text-center text-sm font-semibold text-gray-950 dark:text-white">
                                        Qty
                                    </th>
                                    <th scope="col" class="px-4 py-3.5 text-center text-sm font-semibold text-gray-950 dark:text-white">
                                        Premi Asuransi
                                    </th>
                                    <th scope="col" class="px-4 py-3.5 text-end text-sm font-semibold text-gray-950 dark:text-white">
                                        Biaya Asuransi
                                    </th>
                                    <th scope="col" class="px-4 py-3.5 text-end text-sm font-semibold text-gray-950 dark:text-white">
                                        Biaya Ongkir
                                    </th>
                                    <th scope="col" class="px-4 py-3.5 text-end text-sm font-semibold text-gray-950 dark:text-white">
                                        Total Biaya
                                    </th>
                                    <th scope="col" class="px-4 py-3.5 text-center text-sm font-semibold text-gray-950 dark:text-white">
                                        % dari Nilai
                                    </th>
                                    <th scope="col" class="px-4 py-3.5 pe-4 sm:pe-6 text-center text-sm font-semibold text-gray-950 dark:text-white">
                                        Estimasi SLA
                                    </th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200 dark:divide-white/5">
                                @foreach($rates as $index => $rate)
                                    <tr 
                                        x-show="!search || {{ \Illuminate\Support\Js::from(strtolower(($rate['origin'] ?? '') . ' ' . ($rate['destination'] ?? '') . ' ' . $rate['expedition'] . ' ' . $rate['service'])) }}.includes(search.toLowerCase())"
                                        class="transition duration-75 hover:bg-gray-50/50 dark:hover:bg-white/5"
                                    >
                                        @if($showOriginColumn)
                                            <td class="px-4 py-4 ps-4 sm:ps-6 text-sm text-gray-950 dark:text-white align-middle whitespace-nowrap">
                                                <span class="inline-flex items-center px-2 py-0.5 rounded-md text-xs font-medium bg-gray-100 text-gray-700 dark:bg-white/10 dark:text-gray-200">
                                                    {{ $rate['origin'] ?? '-' }}
                                                </span>
                                            </td>
                                        @endif

                                        @if($showDestinationColumn)
                                            <td class="px-4 py-4 {{ $showOriginColumn ? '' : 'ps-4 sm:ps-6' }} text-sm text-gray-950 dark:text-white align-middle whitespace-nowrap">
                                                <span class="inline-flex items-center px-2 py-0.5 rounded-md text-xs font-medium bg-sky-50 text-sky-700 dark:bg-sky-950 dark:text-sky-300">
                                                    {{ $rate['destination'] ?? '-' }}
                                                </span>
                                            </td>
                                        @endif

                                        {{-- Ekspedisi --}}
                                        <td class="px-4 py-4 {{ ($showOriginColumn || $showDestinationColumn) ? '' : 'ps-4 sm:ps-6' }} text-sm text-gray-950 dark:text-white align-middle whitespace-nowrap">
                                            <div class="flex items-center gap-1.5 flex-wrap">
                                                <span class="font-medium text-gray-950 dark:text-white">{{ $rate['expedition'] }}</span>
                                                @if($index === 0)
                                                    <span class="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-semibold bg-emerald-50 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300">
                                                        Termurah
                                                    </span>
                                                @endif
                                                @if(!empty($rate['is_flat']))
                                                    <span class="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-medium bg-amber-50 text-amber-700 ring-1 ring-amber-600/20 dark:bg-amber-500/10 dark:text-amber-400 dark:ring-amber-400/20">
                                                        {{ $rate['pricing_badge'] ?? 'Flat' }}
                                                    </span>
                                                @endif
                                                @if(!empty($searchedDistrict))
                                                    @if(($rate['match_scope'] ?? 'city') === 'district')
                                                        <span class="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-medium bg-sky-50 text-sky-700 dark:bg-sky-950 dark:text-sky-300">
                                                            Kec. {{ $rate['matched_district'] ?? $searchedDistrict }}
                                                        </span>
                                                    @else
                                                        <span class="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-medium bg-gray-100 text-gray-600 dark:bg-white/10 dark:text-gray-300">
                                                            Tarif Kota
                                                        </span>
                                                    @endif
                                                @endif
                                            </div>
                                        </td>

                                        {{-- Layanan --}}
                                        <td class="px-4 py-4 text-sm text-gray-950 dark:text-white align-middle whitespace-nowrap">
                                            {{ $rate['service'] }}
                                        </td>

                                        {{-- Tarif --}}
                                        <td class="px-4 py-4 text-sm tabular-nums text-gray-950 dark:text-white align-middle text-end whitespace-nowrap">
                                            {{ isset($rate['rate_per_kg']) ? number_format($rate['rate_per_kg'], 0, ',', '.') : '-' }}
                                            @if(!empty($rate['is_flat']))
                                                <span class="text-xs text-gray-400 dark:text-gray-500">{{ $rate['pricing_badge'] ?? 'Flat' }}</span>
                                            @else
                                                <span class="text-xs text-gray-400 dark:text-gray-500">/kg</span>
                                            @endif
                                        </td>

                                        {{-- Min kg --}}
                                        <td class="px-4 py-4 text-sm text-gray-950 dark:text-white align-middle text-center whitespace-nowrap">
                                            {{ $rate['min_kg'] ?? '-' }}
                                        </td>

                                        {{-- Qty --}}
                                        <td class="px-4 py-4 text-sm tabular-nums text-gray-950 dark:text-white align-middle text-center whitespace-nowrap">
                                            {{ $searchedQty ?? '-' }}
                                        </td>

                                        {{-- Insurance rate percent --}}
                                        <td class="px-4 py-4 text-sm text-gray-950 dark:text-white align-middle text-center whitespace-nowrap">
                                            {{ isset($rate['insurance_percent']) ? number_format($rate['insurance_percent'], 1, ',', '.') . '%' : '0%' }}
                                        </td>

                                        {{-- Biaya asuransi --}}
                                        <td class="px-4 py-4 text-sm text-gray-950 dark:text-white align-middle text-end whitespace-nowrap">
                                            {{ isset($rate['biaya_asuransi']) ? number_format($rate['biaya_asuransi'], 0, ',', '.') : '0' }}
                                        </td>

                                        {{-- Biaya ongkir --}}
                                        <td class="px-4 py-4 text-sm text-gray-950 dark:text-white align-middle text-end whitespace-nowrap">
                                            <div>{{ isset($rate['biaya_ongkir']) ? number_format($rate['biaya_ongkir'], 0, ',', '.') : '-' }}</div>
                                            @if(!empty($searchedQty) && $searchedQty > 1 && isset($rate['biaya_ongkir']))
                                                @php
                                                    $baseOngkir = (float) ($rate['base_ongkir'] ?? ($rate['biaya_ongkir'] / max(1, $searchedQty)));
                                                @endphp
                                                <div class="text-[11px] text-gray-400 dark:text-gray-500">
                                                    {{ number_format($baseOngkir, 0, ',', '.') }} &times; {{ $searchedQty }} Qty
                                                </div>
                                            @endif
                                        </td>

                                        {{-- Total biaya --}}
                                        <td class="px-4 py-4 text-sm font-semibold text-gray-950 dark:text-white align-middle text-end whitespace-nowrap {{ $index === 0 ? 'text-emerald-600 dark:text-emerald-400' : '' }}">
                                            {{ isset($rate['total_cost']) ? number_format($rate['total_cost'], 0, ',', '.') : '-' }}
                                        </td>

                                        {{-- % Dari amount --}}
                                        <td class="px-4 py-4 text-sm text-gray-950 dark:text-white align-middle text-center whitespace-nowrap">
                                            {{ !empty($searchedTotalAmount) && isset($rate['percent_dari_amount']) ? number_format($rate['percent_dari_amount'], 1, ',', '.') . '%' : '-' }}
                                        </td>

                                        {{-- Sla days --}}
                                        <td class="px-4 py-4 pe-4 sm:pe-6 text-sm text-gray-950 dark:text-white align-middle text-center whitespace-nowrap">
                                            {{ $rate['lead_time'] ?? '-' }}
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    {{-- Table Pagination Footer --}}
                    <div class="flex items-center justify-between border-t border-gray-200 px-4 py-3 sm:px-6 dark:border-white/10">
                        <div class="text-sm text-gray-700 dark:text-gray-200">
                            Showing 1 to {{ count($rates) }} of {{ count($rates) }} results
                        </div>
                    </div>
                </div>
            @else
                {{-- Empty State (Clean & Integrated) --}}
                <div class="fi-section rounded-xl bg-white shadow-xs ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 overflow-hidden p-8 text-center space-y-3">
                    <div class="space-y-1">
                        <h4 class="text-sm font-semibold text-gray-900 dark:text-white">
                            Tarif Rute {{ $searchedOrigin }} &rarr; {{ $searchedDestination }}@if(!empty($searchedDistrict)), Kec. {{ $searchedDistrict }}@endif Belum Tersedia
                        </h4>
                        <p class="text-xs text-gray-500 dark:text-gray-400 max-w-md mx-auto">
                            Belum ada data tarif pada Google Spreadsheet untuk rute pengiriman ini.
                        </p>
                    </div>

                    {{-- Available destinations suggestions --}}
                    @php
                        $suggested = $this->getSuggestedDestinations();
                    @endphp
                    @if(!empty($suggested))
                        <div class="pt-3 border-t border-gray-100 dark:border-white/10 max-w-lg mx-auto">
                            <span class="text-xs text-gray-400 block mb-2">
                                Pilihan kota tujuan dari {{ $searchedOrigin }} yang ada di spreadsheet:
                            </span>
                            <div class="flex flex-wrap justify-center gap-1.5">
                                @foreach($suggested as $dest)
                                    <button 
                                        type="button" 
                                        wire:click="setRoute({{ \Illuminate\Support\Js::from($searchedOrigins !== [] ? $searchedOrigins : [$searchedOrigin]) }}, {{ \Illuminate\Support\Js::from($dest) }}, {{ $searchedWeight }}, '', null, {{ \Illuminate\Support\Js::from($searchedQty) }})"
                                        class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-gray-100 hover:bg-gray-200 text-gray-700 dark:bg-white/10 dark:hover:bg-white/15 dark:text-gray-200 transition cursor-pointer"
                                    >
                                        {{ $dest }} &rarr;
                                    </button>
                                @endforeach
                            </div>
                        </div>
                    @endif
                </div>
            @endif
        @endif
    </div>
</x-filament-panels::page>
