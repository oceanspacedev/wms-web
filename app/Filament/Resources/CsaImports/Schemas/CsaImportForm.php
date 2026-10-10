<?php

namespace App\Filament\Resources\CsaImports\Schemas;

use Filament\Forms\Components\Checkbox;
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
                    ->helperText('File disimpan dulu, lalu diekstrak di belakang layar. Status di tabel diperbarui otomatis.'),

                Checkbox::make('auto_sync')
                    ->label('Otomatis Sinkronkan ke Google Spreadsheet')
                    ->helperText('Jika dicentang, setelah file selesai diekstrak data dikirim bertahap ke Google Spreadsheet cabang, juga di belakang layar.')
                    ->default(true),
            ]);
    }
}
