<?php

namespace App\Filament\Resources\FreightInvoices\Schemas;

use App\Models\FreightInvoice;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class FreightInvoiceInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make('Informasi Invoice')
                    ->columns(['default' => 2, 'lg' => 6])
                    ->schema([
                        TextEntry::make('invoice_number')
                            ->label('Nomor Invoice')
                            ->weight('bold')
                            ->columnSpan(2),

                        TextEntry::make('expedition.name')
                            ->label('Ekspedisi')
                            ->badge()
                            ->color('info'),

                        TextEntry::make('origin_city')
                            ->label('Kota Asal')
                            ->placeholder('Belum dipilih'),

                        TextEntry::make('invoice_date')
                            ->label('Tanggal Invoice')
                            ->date('d M Y'),

                        TextEntry::make('status')
                            ->label('Status')
                            ->badge()
                            ->color(fn (string $state): string => match ($state) {
                                'approved' => 'success',
                                'audited' => 'primary',
                                'processing' => 'info',
                                'disputed' => 'danger',
                                'paid' => 'gray',
                                default => 'warning',
                            })
                            ->formatStateUsing(fn (string $state): string => match ($state) {
                                'approved' => 'Disetujui',
                                'audited' => 'Selesai Diaudit',
                                'processing' => 'Sedang Diaudit',
                                'disputed' => 'Ada Selisih / Sanggahan',
                                'paid' => 'Sudah Dibayar',
                                default => 'Draft',
                            }),
                    ]),

                // Total Ditagih = Disetujui + Koreksi + Belum Terverifikasi
                Section::make('Ringkasan Tagihan')
                    ->columns(['default' => 1, 'sm' => 2, 'lg' => 4])
                    ->schema([
                        TextEntry::make('total_billed_amount')
                            ->label('Total Ditagih')
                            ->money('IDR', locale: 'id', decimalPlaces: 0)
                            ->size('lg')
                            ->weight('bold')
                            ->belowContent(fn (FreightInvoice $record): string => self::number($record->total_items_count).' resi di invoice'),

                        TextEntry::make('total_approved_amount')
                            ->label('Disetujui')
                            ->money('IDR', locale: 'id', decimalPlaces: 0)
                            ->size('lg')
                            ->weight('bold')
                            ->color('success')
                            ->belowContent(fn (FreightInvoice $record): string => self::number($record->matched_count).' resi sesuai Tarif Kontrak'),

                        TextEntry::make('total_discrepancy_amount')
                            ->label('Koreksi / Selisih')
                            ->money('IDR', locale: 'id', decimalPlaces: 0)
                            ->size('lg')
                            ->weight('bold')
                            ->color(fn ($state): string => (float) $state > 0 ? 'danger' : 'gray')
                            ->belowContent(fn (FreightInvoice $record): string => self::number($record->discrepancy_count).' resi perlu dicek'
                                .($record->duplicate_count > 0 ? ' · '.self::number($record->duplicate_count).' dobel' : '')),

                        TextEntry::make('total_unverified_amount')
                            ->label('Belum Terverifikasi')
                            ->money('IDR', locale: 'id', decimalPlaces: 0)
                            ->size('lg')
                            ->weight('bold')
                            ->color(fn ($state): string => (float) $state > 0 ? 'warning' : 'gray')
                            ->belowContent(fn (FreightInvoice $record): string => self::number($record->unrecognized_count).' resi, tarif belum ada'),
                    ]),
            ]);
    }

    private static function number(mixed $value): string
    {
        return number_format((int) $value, 0, ',', '.');
    }
}
