<?php

namespace App\Filament\Resources\CsaShipments\Schemas;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class CsaShipmentForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('csa_import_id')
                    ->required()
                    ->numeric(),
                TextInput::make('no_sj')
                    ->required(),
                TextInput::make('no_trans'),
                TextInput::make('no_so'),
                DatePicker::make('tanggal_order'),
                DatePicker::make('tanggal_kirim'),
                TextInput::make('badan_usaha'),
                TextInput::make('kode_gudang')
                    ->required(),
                TextInput::make('nama_gudang'),
                TextInput::make('target_sheet')
                    ->label('Cabang')
                    ->required(),
                TextInput::make('tujuan_dealer'),
                Textarea::make('alamat_kirim')
                    ->columnSpanFull(),
                TextInput::make('nama_kota'),
                TextInput::make('brand'),
                TextInput::make('reff_note'),
                TextInput::make('total_nominal_sj')
                    ->required()
                    ->numeric()
                    ->default(0),
                TextInput::make('qty_unit')
                    ->required()
                    ->numeric()
                    ->default(0),
                TextInput::make('qty_koli')
                    ->required()
                    ->numeric()
                    ->default(1),
                TextInput::make('berat')
                    ->required()
                    ->numeric()
                    ->default(1),
                TextInput::make('ketentuan_biaya_kirim')
                    ->default('INVOICE'),
                TextInput::make('nama_ekspedisi'),
                TextInput::make('no_resi_awb'),
                TextInput::make('biaya_kirim')
                    ->numeric(),
                TextInput::make('status_pembayaran')
                    ->default('TAGIHAN BULANAN'),
                TextInput::make('status_pengiriman'),
                DatePicker::make('tanggal_diterima'),
                Textarea::make('ket_isi_unit')
                    ->columnSpanFull(),
            ]);
    }
}
