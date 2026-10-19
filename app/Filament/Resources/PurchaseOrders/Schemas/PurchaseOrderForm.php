<?php

namespace App\Filament\Resources\PurchaseOrders\Schemas;

use App\Models\PurchaseOrder;
use App\Support\MediaFileName;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

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
                                    ->helperText('Gudang umumnya tidak tahu nomor PO; diisi purchasing/finance saat mencocokkan.')
                                    ->unique(ignoreRecord: true)
                                    ->maxLength(100)
                                    ->placeholder('Contoh: PO-202609-0001'),

                                TextInput::make('no_do')
                                    ->label('No. DO / SJ Supplier')
                                    ->required()
                                    ->unique(ignoreRecord: true)
                                    ->maxLength(100)
                                    ->placeholder('Contoh: 20240506_63'),
                            ]),

                        Grid::make(2)
                            ->schema([
                                TextInput::make('nama_supplier')
                                    ->label('Nama Supplier')
                                    ->required()
                                    ->maxLength(255)
                                    ->placeholder('Contoh: PT Sumber Makmur'),

                                DatePicker::make('tanggal_do')
                                    ->label('Tanggal DO')
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
                                    ->helperText('Diisi finance, bukan oleh gudang.')
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

                        Select::make('status_penerimaan')
                            ->label('Status Penerimaan')
                            ->options(array_combine(PurchaseOrder::RECEIPT_STATUSES, PurchaseOrder::RECEIPT_STATUSES))
                            ->default(PurchaseOrder::RECEIPT_COMPLETE)
                            ->required()
                            ->live(),

                        Textarea::make('catatan_gudang')
                            ->label('Catatan Gudang')
                            ->rows(2)
                            ->columnSpanFull()
                            ->required(fn (Get $get): bool => $get('status_penerimaan') !== PurchaseOrder::RECEIPT_COMPLETE)
                            ->placeholder('Kondisi kemasan, segel, atau catatan pemeriksaan koli'),

                        Textarea::make('address')
                            ->label('Lokasi Penerimaan')
                            ->rows(2)
                            ->columnSpanFull(),

                        Grid::make(2)
                            ->schema([
                                TextInput::make('latitude')
                                    ->label('Latitude')
                                    ->numeric(),

                                TextInput::make('longitude')
                                    ->label('Longitude')
                                    ->numeric(),
                            ]),

                        Grid::make(3)
                            ->schema([
                                FileUpload::make('foto_do')
                                    ->label('Foto DO')
                                    ->image()
                                    ->directory('purchase-orders/do')
                                    ->getUploadedFileNameForStorageUsing(fn (TemporaryUploadedFile $file, Get $get): string => MediaFileName::make($file, $get('no_do'), 'do'))
                                    ->openable()
                                    ->downloadable()
                                    ->required(),

                                FileUpload::make('foto_resi')
                                    ->label('Foto Resi (opsional)')
                                    ->image()
                                    ->directory('purchase-orders/resi')
                                    ->getUploadedFileNameForStorageUsing(fn (TemporaryUploadedFile $file, Get $get): string => MediaFileName::make($file, $get('no_do'), 'resi'))
                                    ->openable()
                                    ->downloadable(),

                                FileUpload::make('foto_penerimaan')
                                    ->label('Foto Penerimaan')
                                    ->image()
                                    ->directory('purchase-orders/penerimaan')
                                    ->getUploadedFileNameForStorageUsing(fn (TemporaryUploadedFile $file, Get $get): string => MediaFileName::make($file, $get('no_do'), 'penerimaan'))
                                    ->openable()
                                    ->downloadable()
                                    ->required(),
                            ]),
                    ]),
            ]);
    }
}
