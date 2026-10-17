<?php

namespace App\Filament\Resources\CsaImports\Schemas;

use Filament\Forms\Components\FileUpload;
use Filament\Schemas\Schema;

class CsaImportForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                FileUpload::make('excel_file')
                    ->label('File Excel')
                    ->disk('local')
                    ->directory('csa_imports')
                    ->acceptedFileTypes([
                        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                        'application/octet-stream',
                        'application/zip',
                    ])
                    ->required()
                    ->maxSize(102400)
                    ->helperText('File disimpan dulu, lalu diekstrak di belakang layar ke menu Data Pengiriman. Status di tabel diperbarui otomatis.'),
            ]);
    }
}
