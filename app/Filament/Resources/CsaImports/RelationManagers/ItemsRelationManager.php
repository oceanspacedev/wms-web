<?php

namespace App\Filament\Resources\CsaImports\RelationManagers;

use App\Models\CsaImport;
use App\Models\CsaImportItem;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Enums\Alignment;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Berapa yang terjual per gudang, dipisah per barang (model + warna), dari laporan penjualan yang di-upload.
 * Gudang tampil terlipat beserta totalnya; dibuka untuk melihat rincian per HP & warna.
 */
class ItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'items';

    protected static ?string $title = 'Penjualan per Barang';

    protected static ?string $modelLabel = 'Barang';

    protected static ?string $pluralModelLabel = 'barang';

    /**
     * Laporan realisasi TO tidak berisi penjualan.
     */
    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return $ownerRecord instanceof CsaImport
            && $ownerRecord->report_type !== CsaImport::REPORT_TRANSFER
            && parent::canViewForRecord($ownerRecord, $pageClass);
    }

    public function table(Table $table): Table
    {
        $byWarehouse = Group::make('kode_gudang')
            ->label('Gudang')
            ->titlePrefixedWithLabel(false)
            ->getTitleFromRecordUsing(fn (CsaImportItem $record): string => $record->warehouseLabel())
            ->getDescriptionFromRecordUsing(fn (CsaImportItem $record): string => $record->kode_gudang)
            // Gudang yang paling banyak terjual di atas
            ->orderQueryUsing(fn (Builder $query): Builder => $query
                ->orderByRaw('(select sum(i.qty) from csa_import_items i where i.csa_import_id = csa_import_items.csa_import_id and i.kode_gudang = csa_import_items.kode_gudang) desc')
                ->orderBy('kode_gudang'))
            ->collapsible();

        return $table
            ->recordTitleAttribute('nama_barang')
            ->columns([
                TextColumn::make('model')
                    ->label('Barang')
                    ->formatStateUsing(fn (CsaImportItem $record): string => $record->shortModel())
                    ->description(fn (CsaImportItem $record): ?string => $record->brand)
                    ->tooltip(fn (CsaImportItem $record): string => $record->kode_barang.' · '.$record->nama_barang)
                    ->searchable(['nama_barang', 'kode_barang'])
                    ->weight('medium')
                    ->wrap(),
                TextColumn::make('warna')
                    ->label('Warna')
                    ->placeholder('-'),
                TextColumn::make('qty')
                    ->label('Terjual')
                    ->numeric(locale: 'id')
                    ->alignment(Alignment::End)
                    ->sortable()
                    ->summarize(Sum::make()->label('')->numeric(locale: 'id')),
                TextColumn::make('nominal')
                    ->label('Nominal')
                    ->money('IDR', locale: 'id', decimalPlaces: 0)
                    ->alignment(Alignment::End)
                    ->color('gray')
                    ->sortable()
                    ->summarize(Sum::make()->label('')->money('IDR', locale: 'id', decimalPlaces: 0)),
            ])
            ->defaultGroup($byWarehouse)
            ->groups([
                $byWarehouse,
                Group::make('model')
                    ->label('Barang')
                    ->titlePrefixedWithLabel(false)
                    ->getTitleFromRecordUsing(fn (CsaImportItem $record): string => $record->shortModel())
                    ->collapsible(),
            ])
            // Daftar gudang + total dulu; satu gudang tidak terpotong ke halaman lain
            ->collapsedGroupsByDefault()
            ->groupingDirectionSettingHidden()
            ->paginated(false)
            ->defaultSort('qty', 'desc')
            ->filters([
                TernaryFilter::make('is_unit')
                    ->label('Jenis')
                    ->trueLabel('HP & tablet')
                    ->falseLabel('Aksesoris & lainnya')
                    ->placeholder('Semua')
                    ->default(true),
                SelectFilter::make('kode_gudang')
                    ->label('Gudang')
                    ->options(fn (): array => $this->getOwnerRecord()->items()
                        ->distinct()
                        ->get(['kode_gudang', 'nama_gudang'])
                        ->sortBy(fn (CsaImportItem $item): string => $item->warehouseLabel())
                        ->mapWithKeys(fn (CsaImportItem $item): array => [$item->kode_gudang => $item->warehouseLabel().' ('.$item->kode_gudang.')'])
                        ->all())
                    ->searchable(),
                SelectFilter::make('brand')
                    ->label('Brand')
                    ->options(fn (): array => $this->getOwnerRecord()->items()
                        ->whereNotNull('brand')
                        ->distinct()
                        ->orderBy('brand')
                        ->pluck('brand', 'brand')
                        ->all()),
            ]);
    }
}
