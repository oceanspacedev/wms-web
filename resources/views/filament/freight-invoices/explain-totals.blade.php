@php
    /** @var \App\Models\FreightInvoice $invoice */
    $rupiah = fn ($value): string => 'Rp '.number_format((float) $value, 0, ',', '.');

    $totals = [
        ['Ditagih', $invoice->total_billed_amount, 'Total di file invoice'],
        ['Disetujui', $invoice->total_approved_amount, 'Sesuai Tarif Kontrak'],
        ['Koreksi', $invoice->total_discrepancy_amount, 'Kelebihan tagihan'],
        ['Belum Terverifikasi', $invoice->total_unverified_amount, 'Tarif rute belum ada'],
    ];
@endphp

<div class="space-y-6 text-sm">
    {{-- Ditagih = Disetujui + Koreksi + Belum Terverifikasi --}}
    <div class="grid grid-cols-2 gap-px overflow-hidden rounded-xl bg-gray-200 ring-1 ring-gray-950/5 sm:grid-cols-4 dark:bg-white/10 dark:ring-white/10">
        @foreach ($totals as [$label, $value, $hint])
            <div class="bg-white p-4 dark:bg-gray-900">
                <div class="text-xs text-gray-500 dark:text-gray-400">{{ $label }}</div>
                <div class="mt-1 text-base font-semibold text-gray-950 dark:text-white">{{ $rupiah($value) }}</div>
                <div class="mt-0.5 text-xs text-gray-400 dark:text-gray-500">{{ $hint }}</div>
            </div>
        @endforeach
    </div>
    <p class="-mt-3 text-xs text-gray-500 dark:text-gray-400">Ditagih = Disetujui + Koreksi + Belum Terverifikasi</p>

    <div class="overflow-x-auto rounded-xl ring-1 ring-gray-950/5 dark:ring-white/10">
        <table class="w-full divide-y divide-gray-200 dark:divide-white/5">
            <thead class="bg-gray-50 dark:bg-white/5">
                <tr class="text-xs text-gray-500 dark:text-gray-400">
                    <th class="px-4 py-2.5 text-start font-medium">Hasil cek</th>
                    <th class="px-4 py-2.5 text-end font-medium">Resi</th>
                    <th class="px-4 py-2.5 text-end font-medium">Ditagih</th>
                    <th class="px-4 py-2.5 text-end font-medium">Disetujui</th>
                    <th class="px-4 py-2.5 text-end font-medium">Koreksi</th>
                    <th class="px-4 py-2.5 text-end font-medium">Belum Terverifikasi</th>
                    <th class="px-4 py-2.5 text-start font-medium">Tindak lanjut</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200 text-gray-700 dark:divide-white/5 dark:text-gray-300">
                @forelse ($rows as $row)
                    <tr>
                        <td class="whitespace-nowrap px-4 py-2.5 font-medium text-gray-950 dark:text-white">{{ $row['label'] }}</td>
                        <td class="px-4 py-2.5 text-end">{{ number_format($row['count'], 0, ',', '.') }}</td>
                        <td class="whitespace-nowrap px-4 py-2.5 text-end">{{ $rupiah($row['billed']) }}</td>
                        <td class="whitespace-nowrap px-4 py-2.5 text-end">{{ $rupiah($row['approved']) }}</td>
                        <td class="whitespace-nowrap px-4 py-2.5 text-end">{{ $row['correction'] > 0 ? $rupiah($row['correction']) : '–' }}</td>
                        <td class="whitespace-nowrap px-4 py-2.5 text-end">{{ $row['unverified'] > 0 ? $rupiah($row['unverified']) : '–' }}</td>
                        <td class="whitespace-nowrap px-4 py-2.5 text-gray-500 dark:text-gray-400">{{ $row['followUp'] }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="px-4 py-4 text-center text-gray-500">Invoice belum selesai dicek.</td>
                    </tr>
                @endforelse
            </tbody>
            @if (count($rows) > 0)
                <tfoot class="bg-gray-50 font-semibold text-gray-950 dark:bg-white/5 dark:text-white">
                    <tr>
                        <td class="px-4 py-2.5">Total</td>
                        <td class="px-4 py-2.5 text-end">{{ number_format($invoice->total_items_count, 0, ',', '.') }}</td>
                        <td class="whitespace-nowrap px-4 py-2.5 text-end">{{ $rupiah($invoice->total_billed_amount) }}</td>
                        <td class="whitespace-nowrap px-4 py-2.5 text-end">{{ $rupiah($invoice->total_approved_amount) }}</td>
                        <td class="whitespace-nowrap px-4 py-2.5 text-end">{{ $rupiah($invoice->total_discrepancy_amount) }}</td>
                        <td class="whitespace-nowrap px-4 py-2.5 text-end">{{ $rupiah($invoice->total_unverified_amount) }}</td>
                        <td class="px-4 py-2.5"></td>
                    </tr>
                </tfoot>
            @endif
        </table>
    </div>

    @if (isset($shipmentSummary))
        <div class="space-y-2">
            <h4 class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                Pencocokan Data Pengiriman (Update Pengiriman All Depo)
            </h4>
            <div class="grid grid-cols-2 gap-px overflow-hidden rounded-xl bg-gray-200 ring-1 ring-gray-950/5 sm:grid-cols-4 dark:bg-white/10 dark:ring-white/10">
                <div class="bg-white p-3 dark:bg-gray-900">
                    <div class="text-xs text-gray-500 dark:text-gray-400">Cocok</div>
                    <div class="mt-0.5 text-base font-semibold text-emerald-600 dark:text-emerald-400">{{ number_format($shipmentSummary['matched'], 0, ',', '.') }} resi</div>
                </div>
                <div class="bg-white p-3 dark:bg-gray-900">
                    <div class="text-xs text-gray-500 dark:text-gray-400">Beda Berat</div>
                    <div class="mt-0.5 text-base font-semibold text-amber-600 dark:text-amber-400">{{ number_format($shipmentSummary['weight_mismatch'], 0, ',', '.') }} resi</div>
                </div>
                <div class="bg-white p-3 dark:bg-gray-900">
                    <div class="text-xs text-gray-500 dark:text-gray-400">Beda Ekspedisi</div>
                    <div class="mt-0.5 text-base font-semibold text-rose-600 dark:text-rose-400">{{ number_format($shipmentSummary['expedition_mismatch'], 0, ',', '.') }} resi</div>
                </div>
                <div class="bg-white p-3 dark:bg-gray-900">
                    <div class="text-xs text-gray-500 dark:text-gray-400">Belum Ada di Pengiriman</div>
                    <div class="mt-0.5 text-base font-semibold text-gray-700 dark:text-gray-300">{{ number_format($shipmentSummary['unmatched'], 0, ',', '.') }} resi</div>
                </div>
            </div>
        </div>
    @endif

    @if (! empty($companyBreakdown))
        <div class="space-y-2">
            <h4 class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                Rekap Tagihan per Badan Usaha &amp; Reffnote
            </h4>
            <div class="overflow-x-auto rounded-xl ring-1 ring-gray-950/5 dark:ring-white/10">
                <table class="w-full divide-y divide-gray-200 dark:divide-white/5">
                    <thead class="bg-gray-50 dark:bg-white/5">
                        <tr class="text-xs text-gray-500 dark:text-gray-400">
                            <th class="px-4 py-2.5 text-start font-medium">Badan Usaha</th>
                            <th class="px-4 py-2.5 text-start font-medium">Reffnote</th>
                            <th class="px-4 py-2.5 text-end font-medium">Resi</th>
                            <th class="px-4 py-2.5 text-end font-medium">Total Nominal SJ</th>
                            <th class="px-4 py-2.5 text-end font-medium">Ditagih</th>
                            <th class="px-4 py-2.5 text-end font-medium">Disetujui</th>
                            <th class="px-4 py-2.5 text-end font-medium">% Ongkir</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 text-gray-700 dark:divide-white/5 dark:text-gray-300">
                        @foreach ($companyBreakdown as $cb)
                            <tr>
                                <td class="whitespace-nowrap px-4 py-2 font-medium text-gray-950 dark:text-white">{{ $cb['badanUsaha'] }}</td>
                                <td class="whitespace-nowrap px-4 py-2 text-gray-600 dark:text-gray-400">{{ $cb['reffNote'] }}</td>
                                <td class="px-4 py-2 text-end">{{ number_format($cb['count'], 0, ',', '.') }}</td>
                                <td class="whitespace-nowrap px-4 py-2 text-end">{{ $cb['nominalSj'] > 0 ? $rupiah($cb['nominalSj']) : '–' }}</td>
                                <td class="whitespace-nowrap px-4 py-2 text-end">{{ $rupiah($cb['billed']) }}</td>
                                <td class="whitespace-nowrap px-4 py-2 text-end">{{ $rupiah($cb['approved']) }}</td>
                                <td class="whitespace-nowrap px-4 py-2 text-end">{{ $cb['ratio'] !== null ? number_format($cb['ratio'], 2, ',', '.').'%' : '–' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif
</div>
