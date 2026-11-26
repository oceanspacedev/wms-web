<?php

namespace App\Filament\Resources\CsaShipments\Schemas;

use App\Models\CsaShipment;
use App\Support\ShipmentOptions;
use Carbon\CarbonInterface;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

/**
 * Form satu baris Data Pengiriman (pengganti baris di spreadsheet "Update Pengiriman").
 * Data Surat Jalan dari laporan CSA dikunci; admin cabang melengkapi bagian Data Pengiriman.
 * Kolom berpilihan menyarankan dropdown tab cabangnya, tapi tetap bisa diketik bebas seperti di spreadsheet.
 */
class DataPengirimanForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Hidden::make('_initial_snapshot')
                    ->dehydrated(true)
                    ->afterStateHydrated(function (Hidden $component, ?CsaShipment $record): void {
                        if (! $record) {
                            return;
                        }

                        $component->state(json_encode(static::snapshotAttributes($record)));
                    }),

                Section::make('Data Surat Jalan')
                    ->description(fn (?CsaShipment $record): string => static::isFromReport($record)
                        ? 'Dari laporan '.($record->source === CsaShipment::SOURCE_TO ? 'realisasi TO' : 'CSA').'. Diperbarui otomatis setiap upload, tidak bisa diubah di sini.'
                        : 'Untuk pengiriman di luar laporan CSA (retur, transfer internal, dll.).')
                    ->columns(3)
                    ->disabled(fn (?CsaShipment $record): bool => static::isFromReport($record))
                    ->schema([
                        DatePicker::make('tanggal_order')
                            ->label('Tanggal Order'),
                        DatePicker::make('tanggal_kirim')
                            ->label('Tanggal Kirim')
                            ->required(),
                        TextInput::make('badan_usaha')
                            ->label('Badan Usaha')
                            ->datalist(fn (Get $get): array => array_values(ShipmentOptions::badanUsaha(static::cabang($get))))
                            ->maxLength(255),
                        Select::make('target_sheet')
                            ->label('Depo [Warehouse]')
                            ->options(fn (): array => ShipmentOptions::cabang())
                            ->default(fn (): ?string => auth()->user()?->cabangScope())
                            ->disabled(fn (): bool => filled(auth()->user()?->cabangScope()))
                            ->searchable()
                            ->live()
                            ->required(),
                        TextInput::make('tujuan_dealer')
                            ->label('Tujuan / Dealer')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('nama_kota')
                            ->label('Nama Kota')
                            ->datalist(fn (Get $get): array => array_values(ShipmentOptions::namaKota(static::cabang($get))))
                            ->maxLength(255),
                        TextInput::make('brand')
                            ->label('Brand')
                            ->datalist(fn (Get $get): array => array_values(ShipmentOptions::brand(static::cabang($get))))
                            ->maxLength(100),
                        TextInput::make('no_sj')
                            ->label('Nomor SJ')
                            ->required()
                            ->maxLength(100),
                        TextInput::make('total_nominal_sj')
                            ->label('Total Nominal SJ')
                            ->numeric()
                            ->prefix('Rp'),
                        TextInput::make('reff_note')
                            ->label('Reffnote')
                            ->maxLength(255),
                        TextInput::make('qty_unit')
                            ->label('Qty Unit')
                            ->numeric(),
                        Textarea::make('ket_isi_unit')
                            ->label('Ket. Isi Unit')
                            ->rows(1),
                        Textarea::make('alamat_kirim')
                            ->label('Alamat Kirim')
                            ->rows(2)
                            ->columnSpanFull(),
                    ]),

                Section::make('Data Pengiriman')
                    ->description('Diisi admin cabang. Upload CSA berikutnya tidak menimpa isian ini.')
                    ->columns(3)
                    ->schema([
                        TextInput::make('qty_koli')
                            ->label('Qty Koli')
                            ->numeric()
                            ->minValue(0),
                        TextInput::make('berat')
                            ->label('Berat')
                            ->numeric()
                            ->minValue(0),
                        TextInput::make('ketentuan_biaya_kirim')
                            ->label('Ketentuan Biaya Kirim')
                            ->datalist(fn (Get $get): array => array_values(ShipmentOptions::ketentuanBiayaKirim(static::cabang($get))))
                            ->maxLength(255),
                        TextInput::make('nama_ekspedisi')
                            ->label('Nama Ekspedisi')
                            ->datalist(fn (Get $get): array => array_values(ShipmentOptions::ekspedisi(static::cabang($get))))
                            ->maxLength(255),
                        TextInput::make('no_resi_awb')
                            ->label('No Resi AWB')
                            ->maxLength(100),
                        TextInput::make('biaya_kirim')
                            ->label('Biaya Kirim')
                            ->numeric()
                            ->prefix('Rp'),
                        TextInput::make('status_pembayaran')
                            ->label('Status Pembayaran')
                            ->datalist(fn (Get $get): array => array_values(ShipmentOptions::statusPembayaran(static::cabang($get))))
                            ->maxLength(255),
                        TextInput::make('status_pengiriman')
                            ->label('Status Pengiriman')
                            ->maxLength(255),
                        DatePicker::make('tanggal_diterima')
                            ->label('Tanggal Diterima'),
                    ]),
            ]);
    }

    public static function isFromReport(?CsaShipment $record): bool
    {
        return $record?->isFromReport() ?? false;
    }

    /**
     * Data CSA tidak boleh diubah lewat form, apa pun yang dikirim browser.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function protectCsaFields(array $data, CsaShipment $record): array
    {
        $rawSnapshot = $data['_initial_snapshot'] ?? null;
        unset($data['_initial_snapshot']);

        if (static::isFromReport($record)) {
            $data = collect($data)
                ->except([...CsaShipment::CSA_FIELDS, 'no_sj', 'badan_usaha', 'csa_import_id', 'source'])
                ->all();
        }

        if (is_string($rawSnapshot)) {
            /** @var array<string, mixed>|null $snapshot */
            $snapshot = json_decode($rawSnapshot, true);
            if (is_array($snapshot)) {
                $data = array_filter(
                    $data,
                    fn (mixed $value, string $field): bool => ! array_key_exists($field, $snapshot)
                        || static::normalizedCompare($value) !== static::normalizedCompare($snapshot[$field]),
                    ARRAY_FILTER_USE_BOTH,
                );
            }
        }

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    private static function snapshotAttributes(CsaShipment $record): array
    {
        $fields = [
            'tanggal_order', 'tanggal_kirim', 'badan_usaha', 'target_sheet', 'tujuan_dealer',
            'alamat_kirim', 'nama_kota', 'brand', 'no_sj', 'total_nominal_sj', 'reff_note',
            'qty_unit', 'ket_isi_unit', ...CsaShipment::FILL_IF_EMPTY_FIELDS,
        ];

        $snapshot = [];
        foreach ($fields as $field) {
            $val = $record->getAttribute($field);
            $snapshot[$field] = $val instanceof CarbonInterface ? $val->format('Y-m-d') : $val;
        }

        return $snapshot;
    }

    private static function normalizedCompare(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        if ($value instanceof CarbonInterface) {
            return $value->format('Y-m-d');
        }

        if (is_numeric($value)) {
            return rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.');
        }

        return (string) $value;
    }

    private static function cabang(Get $get): ?string
    {
        return $get('target_sheet') ?: auth()->user()?->cabangScope();
    }
}
