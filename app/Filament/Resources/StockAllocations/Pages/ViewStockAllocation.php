<?php

namespace App\Filament\Resources\StockAllocations\Pages;

use App\Filament\Resources\StockAllocations\Actions\ExportAllocationAction;
use App\Filament\Resources\StockAllocations\StockAllocationResource;
use App\Models\StockAllocation;
use App\Models\StockAllocationTransfer;
use App\Services\StockAllocationService;
use App\Services\StockMutationService;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;
use Filament\Support\Enums\IconPosition;
use Filament\Support\Enums\Width;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;
use Livewire\Attributes\On;

/**
 * @property StockAllocation $record
 */
class ViewStockAllocation extends ViewRecord
{
    protected static string $resource = StockAllocationResource::class;

    #[On('stock-allocation-updated')]
    public function refreshSummary(): void
    {
        $this->record->refresh();
    }

    /**
     * Cukup tab Saran Transfer, Per Gudang & Dokumen Mutasi: total unit & PO sudah ada di daftar Alokasi Stok.
     */
    public function content(Schema $schema): Schema
    {
        return $schema->components([
            $this->getRelationManagersContentComponent(),
        ]);
    }

    public function getTitle(): string|Htmlable
    {
        return 'Alokasi Stok';
    }

    /**
     * Nama file bisa sangat panjang, jadi tidak ditampilkan di header (tetap terlihat di daftar Alokasi Stok).
     */
    public function getSubheading(): ?string
    {
        $record = $this->record;

        return implode(' · ', array_filter([
            // Dari laporan stok ERP: tanggal posisi stok & periode penjualan yang dipakai sebagai angka laku
            $record->usesStockReport() ? 'stok '.$record->stock_date->format('d/m/Y') : $record->created_at?->format('d/m/Y H:i'),
            $record->sales_start && $record->sales_end
                ? 'laku '.$record->sales_start->format('d/m').'–'.$record->sales_end->format('d/m/Y').' ('.((int) $record->sales_start->diffInDays($record->sales_end) + 1).' hari)'
                : null,
            $record->warehouse_count.' gudang',
            $record->sku_count.' SKU',
            'target DOS '.$record->target_dos.' hari',
        ]));
    }

    /**
     * @return array<string, string>
     */
    public function getBreadcrumbs(): array
    {
        return [
            StockAllocationResource::getUrl() => 'Alokasi Stok',
            'Detail',
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            ActionGroup::make([
                Action::make('howItWorks')
                    ->label('Cara Hitung')
                    ->icon('heroicon-o-information-circle')
                    ->modalHeading('Cara Hitung Alokasi Stok')
                    ->modalContent(fn (): HtmlString => $this->explanation())
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Tutup')
                    ->modalWidth(Width::Large),

                // Saran yang disetujui → dokumen mutasi per rute (tab Dokumen Mutasi)
                Action::make('createMutations')
                    ->label('Buat Dokumen Mutasi')
                    ->icon('heroicon-o-document-text')
                    ->visible(fn (): bool => (auth()->user()?->can('update', $this->record) ?? false)
                        && $this->record->transfers()->where('status', StockAllocationTransfer::STATUS_APPROVED)->whereNull('stock_mutation_id')->exists())
                    ->requiresConfirmation()
                    ->modalHeading('Buat Dokumen Mutasi')
                    ->modalDescription('Saran yang sudah disetujui dikelompokkan per rute gudang. Setelah masuk dokumen, saran tersebut tidak bisa diubah lagi.')
                    ->modalSubmitActionLabel('Buat')
                    ->action(function (): void {
                        $mutations = app(StockMutationService::class)->createFromApproved($this->record, auth()->id());

                        Notification::make()
                            ->title($mutations->count().' dokumen mutasi dibuat')
                            ->body($mutations->pluck('number')->implode(', '))
                            ->success()
                            ->send();

                        $this->redirect(StockAllocationResource::getUrl('view', ['record' => $this->record, 'activeRelationManager' => 2]));
                    }),

                ExportAllocationAction::make(),

                // Sudah ada dokumen mutasi: saran tidak dihitung ulang supaya dokumennya tetap cocok
                Action::make('recalculate')
                    ->label('Hitung Ulang')
                    ->icon('heroicon-o-arrow-path')
                    ->visible(fn (): bool => (auth()->user()?->can('update', $this->record) ?? false) && ! $this->record->mutations()->exists())
                    ->modalHeading('Hitung Ulang Alokasi')
                    ->modalDescription('Saran transfer dihitung ulang dari data yang sama. Persetujuan sebelumnya ikut direset.')
                    ->modalWidth(Width::Medium)
                    ->modalSubmitActionLabel('Hitung Ulang')
                    ->fillForm(fn (): array => [
                        'target_dos' => $this->record->target_dos,
                        'min_transfer_qty' => $this->record->min_transfer_qty,
                    ])
                    ->schema([
                        Grid::make(2)->schema([
                            TextInput::make('target_dos')
                                ->label('Target DOS')
                                ->numeric()
                                ->integer()
                                ->minValue(14)
                                ->maxValue(120)
                                ->suffix('hari')
                                ->required(),
                            TextInput::make('min_transfer_qty')
                                ->label('Transfer minimal')
                                ->numeric()
                                ->integer()
                                ->minValue(1)
                                ->suffix('unit')
                                ->required(),
                        ]),
                    ])
                    ->action(function (array $data): void {
                        $this->record->update([
                            'target_dos' => (int) $data['target_dos'],
                            'min_transfer_qty' => (int) $data['min_transfer_qty'],
                        ]);

                        app(StockAllocationService::class)->allocate($this->record);

                        Notification::make()
                            ->title('Alokasi dihitung ulang')
                            ->success()
                            ->send();

                        $this->redirect(StockAllocationResource::getUrl('view', ['record' => $this->record]));
                    }),

                // Sudah ada dokumen mutasi: tidak bisa dihapus (lihat StockAllocation::booted)
                DeleteAction::make()
                    ->hidden(fn (): bool => $this->record->mutations()->exists()),
            ])
                ->label('Aksi')
                ->icon('heroicon-m-chevron-down')
                ->iconPosition(IconPosition::After)
                ->button()
                ->color('gray'),
        ];
    }

    /**
     * Penjelasan singkat rumus & aturan yang dipakai hitungan ini (gaya sebaris supaya tidak perlu build CSS).
     */
    private function explanation(): HtmlString
    {
        $record = $this->record;
        $target = $record->target_dos;

        $sources = $record->usesStockReport()
            ? 'Stok dari laporan stok ERP per '.$record->stock_date->format('d/m/Y').'. Laku dari laporan penjualan '
                .($record->sales_start && $record->sales_end ? $record->sales_start->format('d/m/Y').' – '.$record->sales_end->format('d/m/Y') : 'terakhir')
                .'. Barang di gudang transit (#HPM…) dihitung sebagai incoming depo tujuannya. Hanya HP &amp; tablet di gudang yang punya region di Pemetaan Gudang.'
            : 'Stok, laku 30 hari, dan incoming dari file DOS yang di-upload.';

        $points = [
            '<b>Data.</b> '.$sources,
            '<b>Laku per hari</b> = jumlah terjual ÷ jumlah hari periode penjualan.',
            '<b>DOS</b> = stok ÷ laku per hari, artinya stok cukup untuk berapa hari. ≤ 7 hari KRITIS, ≤ 14 WASPADA, ≤ '.$target.' AMAN, lebih dari itu OVERSTOCK.',
            '<b>Kebutuhan</b> gudang = '.$target.' hari × laku per hari − stok − incoming.',
            '<b>Bisa dikirim</b> = stok fisik − '.$target.' hari × laku per hari. Incoming tidak dihitung supaya gudang pengirim tidak kosong kalau barang di jalan terlambat. Barang yang tidak laku di suatu gudang bisa dikirim semuanya.',
            '<b>Urutan.</b> Gudang paling kritis dilayani lebih dulu, diambil dari gudang paling berlebih di region yang sama, lalu dari gudang PUSAT. PUSAT terhubung ke semua region: bisa mengirim ke depo mana pun dan menerima dari depo mana pun kalau stoknya sendiri kurang.',
            '<b>Minimal</b> '.$record->min_transfer_qty.' unit per saran. Kekurangan yang tidak tertutup transfer menjadi <b>Sisa PO</b>.',
        ];

        return new HtmlString('<ul style="display:grid;gap:10px;margin:0;padding-left:18px;list-style:disc;font-size:14px;line-height:1.5">'
            .implode('', array_map(fn (string $point): string => '<li>'.$point.'</li>', $points))
            .'</ul>');
    }
}
