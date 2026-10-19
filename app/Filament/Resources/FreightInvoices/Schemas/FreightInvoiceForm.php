<?php

namespace App\Filament\Resources\FreightInvoices\Schemas;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
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
                    ->required(),

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
                    ->helperText('Upload file apa adanya. Setiap resi dicek ke Data Pengiriman dan Tarif Kontrak.')
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
}
