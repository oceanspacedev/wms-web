<?php

namespace App\Filament\Exports;

use App\Models\User;
use Carbon\CarbonInterface;
use Filament\Actions\Exports\Enums\ExportFormat;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\Models\Export;
use Illuminate\Support\Number;

/**
 * Ekspor daftar pengguna dari tabel Users. Password sengaja tidak ada di daftar kolom.
 */
class UserExporter extends Exporter
{
    protected static ?string $model = User::class;

    public static function getColumns(): array
    {
        return [
            ExportColumn::make('name')
                ->label('Nama'),
            ExportColumn::make('username')
                ->label('Username'),
            ExportColumn::make('email')
                ->label('Email'),
            ExportColumn::make('whatsapp_number')
                ->label('WhatsApp'),
            ExportColumn::make('roles.name')
                ->label('Role'),
            ExportColumn::make('kode_gudang_arsip')
                ->label('Kode Gudang Arsip'),
            ExportColumn::make('cabang')
                ->label('Cabang'),
            ExportColumn::make('created_at')
                ->label('Tanggal Dibuat')
                ->formatStateUsing(fn (?CarbonInterface $state): ?string => $state?->format('d/m/Y H:i')),
        ];
    }

    public function getFormats(): array
    {
        return [ExportFormat::Xlsx];
    }

    public function getFileName(Export $export): string
    {
        return 'daftar-pengguna-'.$export->created_at?->format('Y-m-d').'-'.$export->getKey();
    }

    public static function getCompletedNotificationTitle(Export $export): string
    {
        return 'Export pengguna selesai';
    }

    public static function getCompletedNotificationBody(Export $export): string
    {
        $body = Number::format($export->successful_rows).' pengguna berhasil diekspor.';

        if ($failedRowsCount = $export->getFailedRowsCount()) {
            $body .= ' '.Number::format($failedRowsCount).' baris gagal diekspor.';
        }

        return $body;
    }
}
