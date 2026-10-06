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
                                    ->copyable(),

                                TextEntry::make('no_sj_supplier')
                                    ->label('No. SJ Supplier')
                                    ->placeholder('-'),
                            ]),

                        Grid::make(2)
                            ->schema([
                                TextEntry::make('nama_supplier')
                                    ->label('Nama Supplier')
                                    ->placeholder('-'),

                                TextEntry::make('tanggal_po')
                                    ->label('Tanggal PO')
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

                        ImageEntry::make('bukti_serah_terima')
                            ->label('Foto Bukti Serah Terima / DO Fisik')
                            ->placeholder('Tidak ada lampiran')
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
