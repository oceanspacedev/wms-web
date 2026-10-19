<?php

namespace App\Filament\Resources\PurchaseOrders\Schemas;

use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class PurchaseOrderInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Informasi Purchase Order & Supplier')
                    ->description('Detail nomor PO, supplier, gudang tujuan, dan nilai pemesanan')
                    ->schema([
                        Grid::make(2)
                            ->schema([
                                TextEntry::make('no_po')
                                    ->label('Nomor PO')
                                    ->weight('bold')
                                    ->copyable()
                                    ->placeholder('Belum dicocokkan purchasing'),

                                TextEntry::make('no_do')
                                    ->label('No. DO')
                                    ->copyable()
                                    ->placeholder('-'),
                            ]),

                        Grid::make(2)
                            ->schema([
                                TextEntry::make('nama_supplier')
                                    ->label('Nama Supplier')
                                    ->placeholder('-'),

                                TextEntry::make('tanggal_do')
                                    ->label('Tanggal DO')
                                    ->date('d/m/Y')
                                    ->placeholder('-'),
                            ]),

                        Grid::make(2)
                            ->schema([
                                TextEntry::make('nama_gudang')
                                    ->label('Gudang Tujuan')
                                    ->badge()
                                    ->color('info')
                                    ->placeholder('-'),

                                TextEntry::make('total_nominal')
                                    ->label('Total Nilai PO')
                                    ->money('IDR', locale: 'id')
                                    ->placeholder('-'),
                            ]),

                        TextEntry::make('alamat_gudang')
                            ->label('Alamat Gudang')
                            ->placeholder('-')
                            ->columnSpanFull(),

                        TextEntry::make('keterangan_barang')
                            ->label('Keterangan Barang')
                            ->placeholder('-')
                            ->columnSpanFull(),
                    ]),

                Section::make('Penerimaan Fisik & Logistik Gudang')
                    ->description('Pemeriksaan fisik barang datang, ekspedisi pengantar, dan dokumen serah terima')
                    ->schema([
                        Grid::make(2)
                            ->schema([
                                TextEntry::make('tanggal_datang')
                                    ->label('Tanggal Datang')
                                    ->date('d/m/Y')
                                    ->placeholder('-'),

                                TextEntry::make('penerima_gudang')
                                    ->label('Penerima Gudang')
                                    ->placeholder('-'),
                            ]),

                        TextEntry::make('status_penerimaan')
                            ->label('Status Penerimaan')
                            ->badge()
                            ->color(fn (?string $state): string => match ($state) {
                                'Lengkap' => 'success',
                                'Kurang' => 'warning',
                                'Rusak' => 'danger',
                                default => 'gray',
                            }),

                        Grid::make(2)
                            ->schema([
                                TextEntry::make('nama_kurir_ekspedisi')
                                    ->label('Kurir / Ekspedisi')
                                    ->placeholder('-'),

                                TextEntry::make('no_resi')
                                    ->label('No. Resi / AWB')
                                    ->placeholder('-'),
                            ]),

                        Grid::make(2)
                            ->schema([
                                TextEntry::make('qty_koli')
                                    ->label('Jumlah Koli')
                                    ->numeric(),

                                TextEntry::make('qty_unit')
                                    ->label('Jumlah Unit')
                                    ->numeric(),
                            ]),

                        TextEntry::make('catatan_gudang')
                            ->label('Catatan Gudang')
                            ->placeholder('-')
                            ->columnSpanFull(),

                        TextEntry::make('address')
                            ->label('Lokasi Penerimaan')
                            ->placeholder('-')
                            ->columnSpanFull(),

                        Grid::make(3)
                            ->schema([
                                ImageEntry::make('foto_do')
                                    ->label('Foto DO')
                                    ->placeholder('Tidak ada lampiran'),

                                ImageEntry::make('foto_resi')
                                    ->label('Foto Resi')
                                    ->placeholder('Tidak ada resi'),

                                ImageEntry::make('foto_penerimaan')
                                    ->label('Foto Penerimaan')
                                    ->placeholder('Tidak ada lampiran'),
                            ]),
                    ]),
            ]);
    }
}
