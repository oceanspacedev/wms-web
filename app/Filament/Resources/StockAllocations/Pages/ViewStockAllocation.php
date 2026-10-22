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
        return implode(' · ', array_filter([
            $this->record->created_at?->format('d/m/Y H:i'),
            $this->record->warehouse_count.' gudang',
            $this->record->sku_count.' SKU',
            'target DOS '.$this->record->target_dos.' hari',
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

                DeleteAction::make(),
            ])
                ->label('Aksi')
                ->icon('heroicon-m-chevron-down')
                ->iconPosition(IconPosition::After)
                ->button()
                ->color('gray'),
        ];
    }
}
