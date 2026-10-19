<?php

namespace App\Filament\Resources\StockAllocations\Pages;

use App\Filament\Resources\StockAllocations\Actions\ExportAllocationAction;
use App\Filament\Resources\StockAllocations\StockAllocationResource;
use App\Models\StockAllocation;
use App\Services\StockAllocationService;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Grid;
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

    public function getTitle(): string|Htmlable
    {
        return 'Alokasi Stok';
    }

    public function getSubheading(): ?string
    {
        return implode(' · ', array_filter([
            $this->record->name,
            $this->record->created_at?->format('d/m/Y H:i'),
        ]));
    }

    protected function getHeaderActions(): array
    {
        return [
            ActionGroup::make([
                ExportAllocationAction::make(),

                Action::make('recalculate')
                    ->label('Hitung Ulang')
                    ->icon('heroicon-o-arrow-path')
                    ->visible(fn (): bool => auth()->user()?->can('update', $this->record) ?? false)
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
