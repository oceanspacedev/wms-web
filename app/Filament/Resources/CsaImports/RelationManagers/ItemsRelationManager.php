<?php

namespace App\Filament\Resources\CsaImports\RelationManagers;

use App\Models\CsaImport;
use App\Models\CsaImportItem;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Enums\Alignment;
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
     * @var array<string, string>|null
     */
    private ?array $warehouseSummaries = null;

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
            // Total gudang langsung di judulnya, jadi tidak perlu baris ringkasan terpisah
            ->getDescriptionFromRecordUsing(fn (CsaImportItem $record): string => $this->warehouseSummary($record->kode_gudang))
            // Gudang yang paling banyak terjual di atas
            // Dihitung sekali per gudang lewat join (subquery per baris lebih lambat)
            ->orderQueryUsing(fn (Builder $query): Builder => $query
                ->select('csa_import_items.*')
                ->joinSub(
                    $this->getOwnerRecord()->items()->getQuery()
                        ->select('kode_gudang as warehouse_code')
                        ->selectRaw('sum(qty) as warehouse_qty')
                        ->groupBy('kode_gudang'),
                    'warehouses',
                    'warehouses.warehouse_code',
                    '=',
                    'csa_import_items.kode_gudang',
                )
                ->orderByDesc('warehouses.warehouse_qty')
                ->orderBy('csa_import_items.kode_gudang'))
            ->collapsible();

        return $table
            ->recordTitleAttribute('nama_barang')
            // Judul sudah ada di tab, jadi tidak diulang di atas tabel
            ->heading(null)
            ->columns([
                // Satu baris saja: brand + model, kode & nama lengkap muncul saat kursor diarahkan
                TextColumn::make('model')
                    ->label('Barang')
                    ->formatStateUsing(fn (CsaImportItem $record): string => $record->productLabel())
                    ->tooltip(fn (CsaImportItem $record): string => $record->kode_barang.' · '.$record->nama_barang)
                    ->searchable(['nama_barang', 'kode_barang'])
                    ->wrap(),
                TextColumn::make('warna')
                    ->label('Warna')
                    ->color('gray')
                    ->placeholder('-'),
                TextColumn::make('qty')
                    ->label('Terjual')
                    ->numeric(locale: 'id')
                    ->weight('medium')
                    ->alignment(Alignment::End)
                    ->sortable(),
                TextColumn::make('nominal')
                    ->label('Nominal')
                    ->money('IDR', locale: 'id', decimalPlaces: 0)
                    ->alignment(Alignment::End)
                    ->color('gray')
                    ->sortable(),
            ])
            ->defaultGroup($byWarehouse)
            ->groupingSettingsHidden()
            // Daftar gudang + total dulu; satu gudang tidak terpotong ke halaman lain
            ->collapsedGroupsByDefault()
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

    /**
     * "GMJKT · 18.509 unit · Rp 29,6 M"; ikut filter & pencarian yang aktif, dihitung sekali per tampilan.
     */
    private function warehouseSummary(string $code): string
    {
        $this->warehouseSummaries ??= $this->getFilteredTableQuery()
            ->reorder()
            ->select('kode_gudang')
            ->selectRaw('sum(qty) as qty, sum(nominal) as nominal')
            ->groupBy('kode_gudang')
            ->get()
            ->mapWithKeys(fn (CsaImportItem $item): array => [
                $item->kode_gudang => $item->kode_gudang.' · '.number_format($item->qty, 0, ',', '.').' unit · '.$this->rupiah((float) $item->nominal),
            ])
            ->all();

        return $this->warehouseSummaries[$code] ?? $code;
    }

    /**
     * Nominal diringkas supaya judul gudang tetap pendek: Rp 29,6 M / Rp 750 jt.
     */
    private function rupiah(float $value): string
    {
        return match (true) {
            $value >= 1_000_000_000 => 'Rp '.number_format($value / 1_000_000_000, 1, ',', '.').' M',
            $value >= 1_000_000 => 'Rp '.number_format($value / 1_000_000, 0, ',', '.').' jt',
            default => 'Rp '.number_format($value, 0, ',', '.'),
        };
    }
}
