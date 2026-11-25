@php
    $statusStyles = match ($statusColor) {
        'success' => 'bg-emerald-50 text-emerald-700 ring-emerald-600/20 dark:bg-emerald-500/10 dark:text-emerald-400 dark:ring-emerald-400/20',
        'danger' => 'bg-rose-50 text-rose-700 ring-rose-600/20 dark:bg-rose-500/10 dark:text-rose-400 dark:ring-rose-400/20',
        'warning' => 'bg-amber-50 text-amber-700 ring-amber-600/20 dark:bg-amber-500/10 dark:text-amber-400 dark:ring-amber-400/20',
        'info' => 'bg-sky-50 text-sky-700 ring-sky-600/20 dark:bg-sky-500/10 dark:text-sky-400 dark:ring-sky-400/20',
        default => 'bg-gray-100 text-gray-700 ring-gray-600/15 dark:bg-white/10 dark:text-gray-300 dark:ring-white/15',
    };

    $dotColor = fn (string $color): string => match ($color) {
        'success' => 'bg-emerald-500',
        'danger' => 'bg-rose-500',
        'warning' => 'bg-amber-500',
        'info' => 'bg-sky-500',
        default => 'bg-gray-400 dark:bg-gray-500',
    };
@endphp

<div class="allocation-explanation space-y-3 text-sm">
    {{-- Ringkasan atas: jumlah kirim, status, dan sumber data --}}
    <div class="flex flex-wrap items-start justify-between gap-4 border-b border-gray-200 pb-3 dark:border-white/10">
        <div>
            <div class="flex flex-wrap items-baseline gap-2">
                <span class="text-lg font-semibold tracking-tight tabular-nums text-gray-950 dark:text-white">{{ $qtyLabel }}</span>
                @if ($suggestedDiffNote)
                    <span class="text-xs text-gray-500 dark:text-gray-400">({{ $suggestedDiffNote }})</span>
                @endif
                <span class="text-xs text-gray-500 dark:text-gray-400">·</span>
                <span class="text-xs text-gray-500 dark:text-gray-400">Target buffer {{ $targetDos }} hari</span>
            </div>
            @if (! empty($sourceInfo))
                <div class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">{{ $sourceInfo }}</div>
            @endif
        </div>

        <div class="flex items-center gap-2">
            @if ($mutationNumber)
                <span class="text-xs font-medium text-gray-500 dark:text-gray-400">{{ $mutationNumber }}</span>
            @endif
            <span class="inline-flex items-center rounded-md px-2.5 py-1 text-xs font-medium ring-1 {{ $statusStyles }}">
                {{ $statusLabel }}
            </span>
        </div>
    </div>

    @if ($from && $to)
        {{-- Kartu perbandingan terpadu: Pengirim | Penerima --}}
        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
            {{-- Pengirim (Asal) --}}
            <div class="flex min-w-0 flex-col rounded-xl border border-gray-200 p-4 dark:border-white/10">
                <div class="flex items-center justify-between gap-2">
                    <span class="text-xs font-medium text-gray-500 dark:text-gray-400">Pengirim</span>
                    <span class="rounded-md bg-emerald-50 px-2 py-0.5 text-[11px] font-medium text-emerald-700 ring-1 ring-emerald-600/20 dark:bg-emerald-500/10 dark:text-emerald-400 dark:ring-emerald-400/20">
                        {{ $from['badge'] }}
                    </span>
                </div>
                <div class="mt-1 text-base font-semibold text-gray-950 dark:text-white">{{ $from['name'] }}</div>

                <div class="mt-3 rounded-lg bg-gray-50 p-3 dark:bg-white/5">
                    <div class="text-lg font-semibold tabular-nums text-emerald-600 dark:text-emerald-400">{{ $from['surplusQty'] }}</div>
                    <div class="text-xs text-gray-500 dark:text-gray-400">{{ $from['surplusCaption'] }}</div>
                </div>

                <dl class="mt-3 flex flex-col gap-2 border-t border-gray-200 pt-3 text-xs dark:border-white/10">
                    <div class="flex items-baseline justify-between gap-3">
                        <dt class="text-gray-500 dark:text-gray-400">Stok fisik</dt>
                        <dd class="shrink-0 text-right font-medium tabular-nums text-gray-950 dark:text-white">{{ $from['stock'] }}</dd>
                    </div>
                    @if ($from['incomingNote'])
                        <div class="flex items-baseline justify-between gap-3">
                            <dt class="text-gray-500 dark:text-gray-400">Stok di jalan</dt>
                            <dd class="tabular-nums text-gray-500 dark:text-gray-400">{{ $from['incomingNote'] }}</dd>
                        </div>
                    @endif
                    <div class="flex items-baseline justify-between gap-3">
                        <dt class="text-gray-500 dark:text-gray-400">{{ $from['salesLabel'] }}</dt>
                        <dd class="shrink-0 text-right font-medium tabular-nums text-gray-950 dark:text-white">{{ $from['salesQty'] }}</dd>
                    </div>
                    <div class="flex items-baseline justify-between gap-3">
                        <dt class="text-gray-500 dark:text-gray-400">Rata-rata laku</dt>
                        <dd class="shrink-0 text-right font-medium tabular-nums text-gray-950 dark:text-white">{{ $from['dailySales'] }}</dd>
                    </div>
                    <div class="flex items-baseline justify-between gap-3">
                        <dt class="text-gray-500 dark:text-gray-400">Buffer {{ $targetDos }} hari</dt>
                        <dd class="shrink-0 text-right font-medium tabular-nums text-gray-950 dark:text-white">{{ $from['bufferQty'] }}</dd>
                    </div>
                    <div class="flex items-baseline justify-between gap-3 border-t border-gray-100 pt-2.5 dark:border-white/5">
                        <dt class="text-gray-500 dark:text-gray-400">Ketahanan stok</dt>
                        <dd class="shrink-0 text-right font-medium tabular-nums text-gray-950 dark:text-white">{{ $from['dosTransition'] }}</dd>
                    </div>
                    <div class="flex items-baseline justify-between gap-3">
                        <dt class="text-gray-500 dark:text-gray-400">Proyeksi stok akhir</dt>
                        <dd class="shrink-0 text-right font-medium tabular-nums text-gray-700 dark:text-gray-300">{{ $from['stockAfter'] }}</dd>
                    </div>
                </dl>

                @if (! empty($from['transfers']))
                    <details class="mt-4 border-t border-gray-200 pt-3 dark:border-white/10">
                        <summary class="flex cursor-pointer flex-wrap items-baseline justify-between gap-2 py-1 text-xs">
                            <span class="font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">{{ $from['transfersTitle'] }}</span>
                            <span class="tabular-nums text-gray-500 dark:text-gray-400">{{ $from['transfersSummary'] }}</span>
                        </summary>
                        <ul class="mt-2.5 flex max-h-40 flex-col gap-2 overflow-y-auto text-xs">
                            @foreach ($from['transfers'] as $item)
                                <li class="flex items-start justify-between gap-3 rounded-lg px-2 py-1.5 {{ $item['isCurrent'] ? 'bg-gray-50 dark:bg-white/5' : '' }}">
                                    <div class="min-w-0">
                                        <div class="flex items-center gap-1.5">
                                            <span class="h-1.5 w-1.5 shrink-0 rounded-full {{ $dotColor($item['color']) }}"></span>
                                            <span class="truncate {{ $item['isCurrent'] ? 'font-semibold text-gray-950 dark:text-white' : 'font-medium text-gray-700 dark:text-gray-300' }}">
                                                → {{ $item['warehouse'] }}
                                            </span>
                                        </div>
                                        <div class="mt-0.5 pl-3 text-[11px] text-gray-500 dark:text-gray-400">{{ $item['meta'] }}</div>
                                    </div>
                                    <span class="shrink-0 tabular-nums {{ $item['isCurrent'] ? 'font-semibold text-gray-950 dark:text-white' : 'text-gray-600 dark:text-gray-400' }}">
                                        {{ $item['qty'] }}
                                    </span>
                                </li>
                            @endforeach
                        </ul>
                    </details>
                @endif
            </div>

            {{-- Penerima (Tujuan) --}}
            <div class="flex min-w-0 flex-col rounded-xl border border-gray-200 p-4 dark:border-white/10">
                <div class="flex items-center justify-between gap-2">
                    <span class="text-xs font-medium text-gray-500 dark:text-gray-400">Penerima</span>
                    <span class="rounded-md bg-rose-50 px-2 py-0.5 text-[11px] font-medium text-rose-700 ring-1 ring-rose-600/20 dark:bg-rose-500/10 dark:text-rose-400 dark:ring-rose-400/20">
                        Butuh Stok
                    </span>
                </div>
                <div class="mt-1 text-base font-semibold text-gray-950 dark:text-white">{{ $to['name'] }}</div>

                <div class="mt-3 rounded-lg bg-gray-50 p-3 dark:bg-white/5">
                    <div class="text-lg font-semibold tabular-nums text-rose-600 dark:text-rose-400">{{ $to['needQty'] }}</div>
                    <div class="text-xs text-gray-500 dark:text-gray-400">kurang dari target buffer {{ $targetDos }} hari</div>
                </div>

                <dl class="mt-3 flex flex-col gap-2 border-t border-gray-200 pt-3 text-xs dark:border-white/10">
                    <div class="flex items-baseline justify-between gap-3">
                        <dt class="text-gray-500 dark:text-gray-400">Stok fisik</dt>
                        <dd class="shrink-0 text-right font-medium tabular-nums text-gray-950 dark:text-white">{{ $to['stock'] }}</dd>
                    </div>
                    @if ($to['incomingNote'])
                        <div class="flex items-baseline justify-between gap-3">
                            <dt class="text-gray-500 dark:text-gray-400">Stok di jalan</dt>
                            <dd class="shrink-0 text-right font-medium tabular-nums text-gray-950 dark:text-white">{{ $to['incomingNote'] }}</dd>
                        </div>
                    @endif
                    <div class="flex items-baseline justify-between gap-3">
                        <dt class="text-gray-500 dark:text-gray-400">{{ $to['salesLabel'] }}</dt>
                        <dd class="shrink-0 text-right font-medium tabular-nums text-gray-950 dark:text-white">{{ $to['salesQty'] }}</dd>
                    </div>
                    <div class="flex items-baseline justify-between gap-3">
                        <dt class="text-gray-500 dark:text-gray-400">Rata-rata laku</dt>
                        <dd class="shrink-0 text-right font-medium tabular-nums text-gray-950 dark:text-white">{{ $to['dailySales'] }}</dd>
                    </div>
                    <div class="flex items-baseline justify-between gap-3">
                        <dt class="text-gray-500 dark:text-gray-400">Buffer {{ $targetDos }} hari</dt>
                        <dd class="shrink-0 text-right font-medium tabular-nums text-gray-950 dark:text-white">{{ $to['bufferQty'] }}</dd>
                    </div>
                    <div class="flex items-baseline justify-between gap-3 border-t border-gray-100 pt-2.5 dark:border-white/5">
                        <dt class="text-gray-500 dark:text-gray-400">Ketahanan stok</dt>
                        <dd class="shrink-0 text-right font-medium tabular-nums text-gray-950 dark:text-white">{{ $to['dosTransition'] }}</dd>
                    </div>
                    <div class="flex items-baseline justify-between gap-3">
                        <dt class="text-gray-500 dark:text-gray-400">Proyeksi stok akhir</dt>
                        <dd class="shrink-0 text-right font-medium tabular-nums text-gray-700 dark:text-gray-300">{{ $to['stockAfter'] }}</dd>
                    </div>
                    @if ($to['remainingPo'])
                        <div class="flex items-baseline justify-between gap-3">
                            <dt class="text-gray-500 dark:text-gray-400">Sisa perlu PO</dt>
                            <dd class="font-semibold tabular-nums text-amber-600 dark:text-amber-400">{{ $to['remainingPo'] }}</dd>
                        </div>
                    @endif
                </dl>

                @if (! empty($to['transfers']))
                    <details class="mt-4 border-t border-gray-200 pt-3 dark:border-white/10">
                        <summary class="flex cursor-pointer flex-wrap items-baseline justify-between gap-2 py-1 text-xs">
                            <span class="font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">{{ $to['transfersTitle'] }}</span>
                            <span class="tabular-nums text-gray-500 dark:text-gray-400">{{ $to['transfersSummary'] }}</span>
                        </summary>
                        <ul class="mt-2.5 flex max-h-40 flex-col gap-2 overflow-y-auto text-xs">
                            @foreach ($to['transfers'] as $item)
                                <li class="flex items-start justify-between gap-3 rounded-lg px-2 py-1.5 {{ $item['isCurrent'] ? 'bg-gray-50 dark:bg-white/5' : '' }}">
                                    <div class="min-w-0">
                                        <div class="flex items-center gap-1.5">
                                            <span class="h-1.5 w-1.5 shrink-0 rounded-full {{ $dotColor($item['color']) }}"></span>
                                            <span class="truncate {{ $item['isCurrent'] ? 'font-semibold text-gray-950 dark:text-white' : 'font-medium text-gray-700 dark:text-gray-300' }}">
                                                ← {{ $item['warehouse'] }}
                                            </span>
                                        </div>
                                        <div class="mt-0.5 pl-3 text-[11px] text-gray-500 dark:text-gray-400">{{ $item['meta'] }}</div>
                                    </div>
                                    <span class="shrink-0 tabular-nums {{ $item['isCurrent'] ? 'font-semibold text-gray-950 dark:text-white' : 'text-gray-600 dark:text-gray-400' }}">
                                        {{ $item['qty'] }}
                                    </span>
                                </li>
                            @endforeach
                        </ul>
                    </details>
                @endif
            </div>
        </div>
        <p class="text-xs leading-relaxed text-gray-500 dark:text-gray-400">Proyeksi stok memperhitungkan seluruh alokasi produk ini, termasuk usulan yang belum disetujui.</p>
    @else
        <p class="text-xs text-gray-500 dark:text-gray-400">Rincian perhitungan tidak tersedia untuk transfer ini.</p>
    @endif
</div>
