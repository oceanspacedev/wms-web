<?php

namespace App\Filament\Resources\PurchaseOrders\Schemas;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class PurchaseOrderForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Informasi Purchase Order & Supplier')
                    ->description('Detail nomor PO, supplier, gudang tujuan, dan nilai pemesanan')
                    ->extraAttributes(['class' => 'h-full'])
                    ->schema([
                        Grid::make(2)
                            ->schema([
                                TextInput::make('no_po')
                                    ->label('Nomor PO')
                                    ->required()
                                    ->maxLength(100)
                                    ->placeholder('Contoh: PO-202609-0001'),

                                TextInput::make('no_sj_supplier')
                                    ->label('No. SJ Supplier')
                                    ->maxLength(100)
                                    ->placeholder('Contoh: SJ-SUP-8891'),
                            ]),

                        Grid::make(2)
                            ->schema([
                                TextInput::make('nama_supplier')
                                    ->label('Nama Supplier')
                                    ->maxLength(255)
                                    ->placeholder('Contoh: PT Sumber Makmur'),

                                DatePicker::make('tanggal_po')
                                    ->label('Tanggal PO')
                                    ->native(false),
                            ]),

                        Grid::make(2)
                            ->schema([
                                TextInput::make('nama_gudang')
                                    ->label('Gudang Tujuan')
                                    ->maxLength(255)
                                    ->placeholder('Contoh: GUDANG MSIS BANDUNG'),

                                TextInput::make('total_nominal')
                                    ->label('Total Nilai PO')
                                    ->numeric()
                                    ->prefix('Rp')
                                    ->placeholder('0'),
                            ]),

                        Textarea::make('alamat_gudang')
                            ->label('Alamat Gudang')
                            ->rows(2)
                            ->columnSpanFull()
                            ->placeholder('Alamat lengkap penerimaan barang'),

                        Textarea::make('keterangan_barang')
                            ->label('Keterangan Barang')
                            ->rows(2)
                            ->columnSpanFull()
                            ->placeholder('Rincian produk, tipe, atau spesifikasi barang'),
                    ]),

                Section::make('Penerimaan Fisik & Logistik Gudang')
                    ->description('Pemeriksaan fisik barang datang, ekspedisi pengantar, dan dokumen serah terima')
                    ->extraAttributes(['class' => 'h-full'])
                    ->schema([
                        Grid::make(2)
                            ->schema([
                                DatePicker::make('tanggal_datang')
                                    ->label('Tanggal Datang')
                                    ->native(false),

                                TextInput::make('penerima_gudang')
                                    ->label('Penerima Gudang')
                                    ->maxLength(255)
                                    ->placeholder('Nama staf penerima'),
                            ]),

                        Grid::make(2)
                            ->schema([
                                TextInput::make('nama_kurir_ekspedisi')
                                    ->label('Kurir / Ekspedisi')
                                    ->maxLength(255)
                                    ->placeholder('Contoh: J&T Cargo / Armada Supplier'),

                                TextInput::make('no_resi')
                                    ->label('No. Resi / AWB')
                                    ->maxLength(100)
                                    ->placeholder('Contoh: 100240387353'),
                            ]),

                        Grid::make(2)
                            ->schema([
                                TextInput::make('qty_koli')
                                    ->label('Jumlah Koli')
                                    ->numeric()
                                    ->default(1)
                                    ->required(),

                                TextInput::make('qty_unit')
                                    ->label('Jumlah Unit')
                                    ->numeric()
                                    ->default(0)
                                    ->required(),
                            ]),

                        Textarea::make('catatan_gudang')
                            ->label('Catatan Gudang')
                            ->rows(2)
                            ->columnSpanFull()
                            ->placeholder('Kondisi kemasan, segel, atau catatan pemeriksaan koli'),

                        FileUpload::make('bukti_serah_terima')
                            ->label('Foto Bukti Serah Terima / DO Fisik')
                            ->image()
                            ->directory('purchase-orders/bukti')
                            ->visibility('public')
                            ->openable()
                            ->downloadable()
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
