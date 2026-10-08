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
                    ->maxSize(102400),

                Checkbox::make('auto_sync')
                    ->label('Sinkronkan ke Google Spreadsheet')
                    ->default(false),
            ]);
    }
}
