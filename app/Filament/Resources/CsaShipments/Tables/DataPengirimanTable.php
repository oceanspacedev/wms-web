<?php

namespace App\Filament\Resources\CsaShipments\Tables;

use App\Filament\Resources\CsaShipments\Schemas\DataPengirimanForm;
use App\Models\CsaImport;
use App\Models\CsaShipment;
use App\Support\ShipmentOptions;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\Column;
use Filament\Tables\Columns\SelectColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\TextInputColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Tabel Data Pengiriman: pengganti tab cabang di spreadsheet "Update Pengiriman".
 */
class DataPengirimanTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns(static::columns())
            ->defaultSort('tanggal_kirim', 'desc')
            ->striped()
            ->paginated([25, 50, 100])
            ->defaultPaginationPageOption(50)
            ->persistFiltersInSession()
            // Dua kolom di popup filter supaya tidak memanjang ke bawah
            ->filtersFormColumns(2)
            ->filtersFormWidth(Width::TwoExtraLarge)
            ->filters([
                // Pengganti tab cabang di spreadsheet: satu dropdown, urutan sama dengan tab spreadsheet
                SelectFilter::make('target_sheet')
                    ->label('Cabang')
                    ->placeholder('Semua cabang')
                    ->options(fn (): array => static::cabangOptions())
                    ->searchable()
                    ->visible(fn (): bool => blank(auth()->user()?->cabangScope())),

                SelectFilter::make('kelengkapan')
                    ->label('Kelengkapan Data')
                    ->options([
                        'tanpa_ekspedisi' => 'Belum ada ekspedisi',
                        'tanpa_resi' => 'Belum ada no resi',
                        'belum_diterima' => 'Belum diterima',
                    ])
                    ->query(fn (Builder $query, array $data): Builder => match ($data['value'] ?? null) {
                        'tanpa_ekspedisi' => $query->where(fn (Builder $q) => $q->whereNull('nama_ekspedisi')->orWhere('nama_ekspedisi', '')),
                        'tanpa_resi' => $query->where(fn (Builder $q) => $q->whereNull('no_resi_awb')->orWhere('no_resi_awb', '')),
                        'belum_diterima' => $query->whereNull('tanggal_diterima'),
                        default => $query,
                    }),

                Filter::make('periode')
                    ->label('Periode Tanggal Kirim')
                    ->schema([
                        DatePicker::make('dari')->label('Tanggal kirim dari'),
                        DatePicker::make('sampai')->label('Sampai'),
                    ])
                    ->columns(2)
                    ->columnSpanFull()
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['dari'] ?? null, fn (Builder $q, $date): Builder => $q->whereDate('tanggal_kirim', '>=', $date))
                        ->when($data['sampai'] ?? null, fn (Builder $q, $date): Builder => $q->whereDate('tanggal_kirim', '<=', $date)))
                    ->indicateUsing(function (array $data): ?string {
                        if (blank($data['dari'] ?? null) && blank($data['sampai'] ?? null)) {
                            return null;
                        }

                        return 'Kirim: '.($data['dari'] ?? '…').' s/d '.($data['sampai'] ?? '…');
                    }),

                SelectFilter::make('nama_ekspedisi')
                    ->label('Ekspedisi')
                    ->options(fn (): array => ShipmentOptions::ekspedisi())
                    ->searchable(),

                SelectFilter::make('brand')
                    ->label('Brand')
                    ->options(fn (): array => CsaShipment::query()->whereNotNull('brand')->where('brand', '!=', '')->distinct()->orderBy('brand')->pluck('brand', 'brand')->all())
                    ->searchable(),

                SelectFilter::make('csa_import_id')
                    ->label('Upload CSA')
                    ->options(fn (): array => CsaImport::query()->latest('id')->limit(30)->get()
                        ->mapWithKeys(fn (CsaImport $import): array => [$import->id => '#'.$import->id.' · '.$import->created_at?->format('d/m/Y H:i').' · '.$import->file_name])
                        ->all()),

                SelectFilter::make('source')
                    ->label('Sumber Data')
                    ->options(CsaShipment::SOURCE_LABELS),

                Filter::make('terbaru')
                    ->label('Hanya data terbaru (upload terakhir)')
                    ->toggle()
                    ->query(function (Builder $query): Builder {
                        $latest = static::latestUploadWithNewRows();

                        return $latest
                            ? $query->where('csa_import_id', $latest->id)->where('created_at', '>=', $latest->created_at)
                            : $query->whereRaw('1 = 0');
                    }),
            ])
            ->recordActions([
                EditAction::make()
                    ->label('Lengkapi')
                    ->modalHeading(fn (CsaShipment $record): string => 'Data Pengiriman SJ '.$record->no_sj)
                    ->modalWidth('5xl')
                    ->mutateDataUsing(fn (array $data, CsaShipment $record): array => DataPengirimanForm::protectCsaFields($data, $record)),
                // Baris CSA akan muncul lagi di upload berikutnya, jadi hanya input manual & hasil import yang bisa dihapus
                DeleteAction::make()
                    ->visible(fn (CsaShipment $record): bool => $record->source !== CsaShipment::SOURCE_CSA),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    static::bulkFillAction(),
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    /**
     * Kolom sama persis (urutan & judul) dengan spreadsheet "Update Pengiriman". Kolom dari laporan CSA hanya
     * dibaca; kolom isian admin bisa langsung diketik/dipilih di tabel seperti di spreadsheet.
     *
     * @return array<int, Column>
     */
    public static function columns(): array
    {
        $readOnly = fn (CsaShipment $record): bool => ! static::canEdit($record);

        return [
            TextColumn::make('tanggal_order')
                ->label('TANGGAL ORDER')
                ->date('d/m/Y')
                ->sortable()
                ->toggleable(),
            TextColumn::make('tanggal_kirim')
                ->label('TANGGAL KIRIM')
                ->date('d/m/Y')
                ->sortable()
                ->toggleable(),
            TextColumn::make('badan_usaha')
                ->label('BADAN USAHA')
                ->color(fn (CsaShipment $record): ?string => static::isNewest($record) ? 'danger' : null)
                ->toggleable(),
            TextColumn::make('target_sheet')
                ->label('DEPO [WAREHOUSE]')
                ->sortable()
                ->toggleable(),
            TextColumn::make('tujuan_dealer')
                ->label('TUJUAN/DEALER')
                ->searchable()
                ->limit(30)
                ->tooltip(fn (CsaShipment $record): ?string => $record->tujuan_dealer)
                ->toggleable(),
            TextColumn::make('alamat_kirim')
                ->label('ALAMAT KIRIM')
                ->limit(35)
                ->tooltip(fn (CsaShipment $record): ?string => $record->alamat_kirim)
                ->toggleable(),
            TextColumn::make('nama_kota')
                ->label('NAMA KOTA')
                ->searchable()
                ->toggleable(),
            TextColumn::make('brand')
                ->label('BRAND')
                ->toggleable(),
            TextColumn::make('no_sj')
                ->label('NOMOR SJ')
                ->searchable()
                ->sortable()
                ->copyable()
                ->weight('bold')
                // Merah = baru masuk di upload CSA terakhir (pengganti warna biru di spreadsheet)
                ->color(fn (CsaShipment $record): ?string => static::isNewest($record) ? 'danger' : null)
                ->tooltip(fn (CsaShipment $record): ?string => static::isNewest($record) ? 'Data baru dari upload CSA terakhir' : null),
            TextColumn::make('total_nominal_sj')
                ->label('TOTAL NOMINAL SJ')
                ->money('IDR', locale: 'id', decimalPlaces: 0)
                ->sortable()
                ->toggleable(),
            TextColumn::make('reff_note')
                ->label('REFFNOTE')
                ->searchable()
                ->toggleable(),
            TextColumn::make('qty_unit')
                ->label('QTY UNIT')
                ->numeric()
                ->toggleable(),
            TextInputColumn::make('qty_koli')
                ->label('QTY KOLI')
                ->type('number')
                ->state(fn (CsaShipment $record): ?int => $record->qty_koli ?: null)
                ->rules(['nullable', 'integer', 'min:0'])
                ->disabled($readOnly)
                ->toggleable(),
            TextInputColumn::make('berat')
                ->label('BERAT')
                ->state(fn (CsaShipment $record): ?float => (float) $record->berat ?: null)
                ->rules(['nullable', 'numeric', 'min:0'])
                ->disabled($readOnly)
                ->toggleable(),
            SelectColumn::make('ketentuan_biaya_kirim')
                ->label('KETENTUAN BIAYA KIRIM')
                ->options(fn (CsaShipment $record): array => ShipmentOptions::ketentuanBiayaKirim($record->target_sheet, $record->ketentuan_biaya_kirim))
                ->disabled($readOnly)
                ->toggleable(),
            SelectColumn::make('nama_ekspedisi')
                ->label('NAMA EKSPEDISI')
                ->options(fn (CsaShipment $record): array => ShipmentOptions::ekspedisi($record->target_sheet, $record->nama_ekspedisi))
                ->disabled($readOnly)
                ->toggleable(),
            TextInputColumn::make('no_resi_awb')
                ->label('NO RESI AWB')
                ->searchable()
                ->rules(['nullable', 'string', 'max:100'])
                ->disabled($readOnly)
                ->toggleable(),
            TextInputColumn::make('biaya_kirim')
                ->label('BIAYA KIRIM')
                ->state(fn (CsaShipment $record): ?float => $record->biaya_kirim !== null ? (float) $record->biaya_kirim : null)
                ->rules(['nullable', 'numeric', 'min:0'])
                ->disabled($readOnly)
                ->toggleable(),
            SelectColumn::make('status_pembayaran')
                ->label('STATUS PEMBAYARAN')
                ->options(fn (CsaShipment $record): array => ShipmentOptions::statusPembayaran($record->target_sheet, $record->status_pembayaran))
                ->disabled($readOnly)
                ->toggleable(),
            TextInputColumn::make('status_pengiriman')
                ->label('STATUS PENGIRIMAN')
                ->rules(['nullable', 'string', 'max:255'])
                ->disabled($readOnly)
                ->toggleable(),
            TextInputColumn::make('tanggal_diterima')
                ->label('TANGGAL DITERIMA')
                ->type('date')
                ->state(fn (CsaShipment $record): ?string => $record->tanggal_diterima?->format('Y-m-d'))
                ->rules(['nullable', 'date'])
                ->sortable()
                ->disabled($readOnly)
                ->toggleable(),
            TextColumn::make('lead_time_proses')
                ->label('LEAD TIME PROSES')
                ->placeholder('-')
                ->toggleable(),
            TextColumn::make('lead_time_kirim')
                ->label('LEAD TIME KIRIM')
                ->placeholder('-')
                ->toggleable(),
            TextColumn::make('lead_time_keseluruhan')
                ->label('LEAD TIME KESELURUHAN')
                ->placeholder('-')
                ->toggleable(),
            TextColumn::make('ket_isi_unit')
                ->label('KET. ISI UNIT')
                ->limit(30)
                ->tooltip(fn (CsaShipment $record): ?string => $record->ket_isi_unit)
                ->toggleable(),

            // Tambahan web yang tidak ada di spreadsheet: tersembunyi, bisa dimunculkan lewat tombol kolom
            TextColumn::make('source')
                ->label('SUMBER')
                ->formatStateUsing(fn (?string $state): string => CsaShipment::SOURCE_LABELS[$state] ?? CsaShipment::SOURCE_LABELS[CsaShipment::SOURCE_CSA])
                ->toggleable(isToggledHiddenByDefault: true),
        ];
    }

    /**
     * Isi kolom Data Pengiriman untuk banyak SJ sekaligus; hanya kolom yang diisi di form yang diubah.
     */
    public static function bulkFillAction(): BulkAction
    {
        return BulkAction::make('isiDataPengiriman')
            ->label('Isi Data Pengiriman')
            ->icon(Heroicon::OutlinedPencilSquare)
            ->color('primary')
            ->modalHeading('Isi Data Pengiriman untuk SJ terpilih')
            ->modalDescription('Kolom yang dikosongkan tidak diubah.')
            ->modalSubmitActionLabel('Simpan')
            ->schema([
                TextInput::make('ketentuan_biaya_kirim')
                    ->label('Ketentuan Biaya Kirim')
                    ->datalist(fn (): array => array_values(ShipmentOptions::ketentuanBiayaKirim(auth()->user()?->cabangScope())))
                    ->maxLength(255),
                TextInput::make('nama_ekspedisi')
                    ->label('Nama Ekspedisi')
                    ->datalist(fn (): array => array_values(ShipmentOptions::ekspedisi(auth()->user()?->cabangScope())))
                    ->maxLength(255),
                TextInput::make('status_pembayaran')
                    ->label('Status Pembayaran')
                    ->datalist(fn (): array => array_values(ShipmentOptions::statusPembayaran(auth()->user()?->cabangScope())))
                    ->maxLength(255),
                TextInput::make('status_pengiriman')
                    ->label('Status Pengiriman')
                    ->maxLength(255),
                DatePicker::make('tanggal_diterima')
                    ->label('Tanggal Diterima'),
            ])
            ->action(function (Collection $records, array $data): void {
                $changes = collect($data)->filter(fn ($value): bool => filled($value))->all();

                if ($changes === []) {
                    Notification::make()->title('Tidak ada kolom yang diisi')->warning()->send();

                    return;
                }

                $updated = 0;
                foreach ($records as $record) {
                    if (static::canEdit($record)) {
                        $record->update($changes);
                        $updated++;
                    }
                }

                Notification::make()
                    ->title("{$updated} SJ diperbarui")
                    ->success()
                    ->send();
            })
            ->deselectRecordsAfterCompletion();
    }

    /**
     * Pilihan cabang berurutan seperti tab spreadsheet, dengan jumlah SJ, misal "CIREBON (281)".
     *
     * @return array<string, string>
     */
    private static function cabangOptions(): array
    {
        // Diminta beberapa kali per render (form filter & indikator), cukup dihitung sekali per request
        return once(function (): array {
            $counts = CsaShipment::query()
                ->toBase()
                ->selectRaw('target_sheet, COUNT(*) as total')
                ->groupBy('target_sheet')
                ->pluck('total', 'target_sheet')
                ->mapWithKeys(fn ($total, ?string $cabang): array => [mb_strtoupper(trim((string) $cabang)) => (int) $total]);

            return collect(ShipmentOptions::tabs())
                ->mapWithKeys(fn (string $cabang): array => [
                    $cabang => $cabang.' ('.number_format($counts[mb_strtoupper($cabang)] ?? 0, 0, ',', '.').')',
                ])
                ->all();
        });
    }

    /**
     * SJ yang baru masuk (bukan sekadar diperbarui) di upload CSA terakhir yang menambah data.
     */
    public static function isNewest(CsaShipment $record): bool
    {
        $latest = static::latestUploadWithNewRows();

        return $latest
            && $record->csa_import_id === $latest->id
            && $record->created_at?->greaterThanOrEqualTo($latest->created_at);
    }

    /**
     * Upload CSA terakhir yang benar-benar menambah SJ baru. Upload yang isinya hanya memperbarui SJ lama
     * tidak menggeser tanda "terbaru", sama seperti warna biru di spreadsheet. Upload yang mengisi tabel
     * pertama kali dianggap data awal (belum ada data lama sebagai pembanding), jadi tidak ditandai.
     */
    private static function latestUploadWithNewRows(): ?CsaImport
    {
        return once(function (): ?CsaImport {
            $latest = CsaImport::query()
                ->where('status', 'completed')
                ->whereHas('shipments', fn (Builder $query): Builder => $query->whereColumn('csa_shipments.created_at', '>=', 'csa_imports.created_at'))
                ->latest('id')
                ->first();

            if (! $latest || CsaShipment::query()->where('created_at', '<', $latest->created_at)->doesntExist()) {
                return null;
            }

            return $latest;
        });
    }

    private static function canEdit(CsaShipment $record): bool
    {
        return (bool) auth()->user()?->can('update', $record);
    }
}
