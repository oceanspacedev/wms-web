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
</div>
