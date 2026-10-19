<?php

namespace App\Filament\Resources\FreightInvoices\Schemas;

use App\Models\ExpeditionRateCard;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;

class FreightInvoiceForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                Select::make('expedition_id')
                    ->label('Ekspedisi')
                    ->relationship('expedition', 'name')
                    ->preload()
                    ->searchable()
                    ->required()
                    ->live()
                    ->afterStateUpdated(fn (?string $state, Set $set) => $set('origin_city', static::defaultOrigin($state))),

                Select::make('origin_city')
                    ->label('Kota Asal Pengiriman')
                    ->options(fn (Get $get): array => static::origins($get('expedition_id')))
                    ->searchable()
                    ->required()
                    ->helperText('Untuk mencari Tarif Kontrak, karena invoice ekspedisi biasanya tidak mencantumkan kota asal.'),

                TextInput::make('invoice_number')
                    ->label('Nomor Invoice')
                    ->placeholder('Opsional, kosongkan = pakai nama file')
                    ->required(fn (string $operation): bool => $operation === 'edit')
                    ->unique(ignoreRecord: true),

                // Upload hanya saat membuat; file asli dari ekspedisi dibaca apa adanya
                FileUpload::make('invoice_file')
                    ->label('File Invoice dari Ekspedisi (.xlsx / .csv)')
                    ->disk('local')
                    ->directory('freight_invoices')
                    ->acceptedFileTypes([
                        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                        'text/csv',
                        'text/plain',
                        'application/octet-stream',
                    ])
                    ->storeFileNamesIn('invoice_file_name')
                    ->required()
                    ->maxSize(51200)
                    ->helperText('Upload file apa adanya. Tarif & berat setiap resi dicek ke Tarif Kontrak.')
                    ->visibleOn('create')
                    ->columnSpanFull(),

                DatePicker::make('invoice_date')
                    ->label('Tanggal Invoice')
                    ->required()
                    ->visibleOn('edit'),

                Textarea::make('notes')
                    ->label('Catatan')
                    ->visibleOn('edit')
                    ->columnSpanFull(),
            ]);
    }

    /**
     * Kebanyakan kiriman dari Jakarta; kalau ekspedisi hanya punya satu kota asal, langsung dipilih.
     */
    public static function defaultOrigin(mixed $expeditionId): ?string
    {
        $origins = static::origins($expeditionId);

        return count($origins) === 1 ? (string) array_key_first($origins) : (isset($origins['JAKARTA']) ? 'JAKARTA' : null);
    }

    /**
     * Kota asal yang tersedia di Tarif Kontrak ekspedisi.
     *
     * @return array<string, string>
     */
    public static function origins(mixed $expeditionId): array
    {
        if (blank($expeditionId)) {
            return [];
        }

        return ExpeditionRateCard::query()
            ->where('expedition_id', $expeditionId)
            ->where('is_active', true)
            ->distinct()
            ->orderBy('origin_depo')
            ->pluck('origin_depo', 'origin_depo')
            ->all();
    }
}
