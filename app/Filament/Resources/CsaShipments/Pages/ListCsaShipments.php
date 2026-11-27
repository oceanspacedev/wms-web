<?php

namespace App\Filament\Resources\CsaShipments\Pages;

use App\Filament\Resources\CsaShipments\CsaShipmentResource;
use App\Jobs\ImportShipmentSpreadsheetJob;
use App\Models\AppSetting;
use App\Models\CsaShipment;
use App\Services\DataPengirimanExporter;
use App\Services\TrackingOrderShipmentSyncService;
use BezhanSalleh\FilamentShield\Support\Utils;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRecords;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Enums\IconPosition;
use Filament\Support\Enums\Width;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ListCsaShipments extends ManageRecords
{
    protected static string $resource = CsaShipmentResource::class;

    private const MONTH_NAMES = [
        1 => 'Januari',
        2 => 'Februari',
        3 => 'Maret',
        4 => 'April',
        5 => 'Mei',
        6 => 'Juni',
        7 => 'Juli',
        8 => 'Agustus',
        9 => 'September',
        10 => 'Oktober',
        11 => 'November',
        12 => 'Desember',
    ];

    protected function getHeaderActions(): array
    {
        return [
            ActionGroup::make([
                Action::make('export')
                    ->label('Export Excel')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('gray')
                    ->modalHeading('Export Data Pengiriman')
                    ->modalDescription('Pilih filter data yang ingin di-export. Kosongkan pilihan untuk mengekspor seluruh data sesuai tampilan tabel.')
                    ->modalWidth(Width::Medium)
                    ->modalSubmitActionLabel('Export Excel')
                    ->fillForm(fn (): array => [
                        'jenis' => $this->tableFilters['jenis']['value'] ?? null,
                        'badan_usaha' => $this->tableFilters['badan_usaha']['value'] ?? null,
                        'bulan' => null,
                    ])
                    ->schema([
                        Select::make('jenis')
                            ->label('Jenis Data')
                            ->placeholder('Semua (SJ & Realisasi TO)')
                            ->options([
                                'sj' => 'SJ (Penjualan)',
                                'to' => 'Realisasi TO',
                                'surat_jalan' => 'Surat Jalan',
                                'manual' => 'Input Manual',
                            ]),

                        Select::make('badan_usaha')
                            ->label('Badan Usaha')
                            ->placeholder('Semua badan usaha')
                            ->options(fn (): array => self::badanUsahaOptions())
                            ->searchable(),

                        Select::make('bulan')
                            ->label('Bulan Pengiriman')
                            ->placeholder('Semua bulan')
                            ->options(fn (): array => $this->monthOptions())
                            ->searchable(),
                    ])
                    ->action(function (array $data): StreamedResponse {
                        $query = $this->applyExportFilters(clone $this->getFilteredSortedTableQuery(), $data);

                        return app(DataPengirimanExporter::class)->download(
                            $query,
                            $this->exportFileName($data),
                        );
                    }),

                Action::make('importSpreadsheet')
                    ->label('Import Spreadsheet')
                    ->icon('heroicon-o-arrow-up-tray')
                    ->color('gray')
                    // Mengisi semua cabang sekaligus, jadi hanya untuk admin pusat
                    ->visible(fn (): bool => blank(auth()->user()?->cabangScope())
                        && (auth()->user()?->can('create', CsaShipment::class) ?? false))
                    ->modalHeading('Import Spreadsheet Update Pengiriman')
                    ->modalDescription('Download Google Sheet lewat File → Download → Microsoft Excel (.xlsx), lalu upload di sini. Setiap tab menjadi cabang dengan kolom yang sama seperti tabel ini.')
                    ->modalWidth(Width::Large)
                    ->modalSubmitActionLabel('Import')
                    ->schema([
                        FileUpload::make('file')
                            ->label('File spreadsheet (.xlsx)')
                            ->disk('local')
                            ->directory('pengiriman-imports')
                            ->acceptedFileTypes([
                                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                                'application/octet-stream',
                                'application/zip',
                            ])
                            ->maxSize(102400)
                            ->storeFileNamesIn('file_name')
                            ->required(),
                        Toggle::make('replace_existing')
                            ->label('Ganti data hasil import spreadsheet sebelumnya')
                            ->helperText('Untuk cabang yang ada di file ini, supaya import ulang tidak dobel. Data dari upload CSA dan input manual tidak ikut terhapus.')
                            ->default(true),
                    ])
                    ->action(function (array $data): void {
                        ImportShipmentSpreadsheetJob::dispatch(
                            $data['file'],
                            $data['file_name'] ?? basename($data['file']),
                            (bool) ($data['replace_existing'] ?? true),
                            auth()->id(),
                        );

                        Notification::make()
                            ->title('Import spreadsheet sedang diproses')
                            ->body('Berjalan di belakang layar ±1–3 menit. Hasilnya muncul di notifikasi lonceng di kanan atas.')
                            ->success()
                            ->send();
                    }),

                Action::make('syncTrackingOrders')
                    ->label('Sinkron Surat Jalan')
                    ->icon('heroicon-o-arrow-path')
                    ->color('gray')
                    ->visible(fn (): bool => (auth()->user()?->can('create', CsaShipment::class) ?? false)
                        || (auth()->user()?->can('update', new CsaShipment) ?? false))
                    ->requiresConfirmation()
                    ->modalHeading('Sinkronisasi Data Surat Jalan')
                    ->modalDescription('Mengambil data dari menu Surat Jalan berdasarkan Nomor SJ. Jika No SJ belum ada di Data Pengiriman (data CSA belum di-upload), baris baru dibuat otomatis; jika sudah ada, kolom kosong seperti alamat, kota, nama ekspedisi (INTERNAL), status pengiriman (Sudah Diterima), dan tanggal diterima dilengkapi tanpa menimpa data CSA atau isian admin.')
                    ->modalSubmitActionLabel('Sinkron Sekarang')
                    ->action(function (): void {
                        $stats = app(TrackingOrderShipmentSyncService::class)->syncAll(auth()->user()?->cabangScope());

                        Notification::make()
                            ->title('Sinkronisasi Surat Jalan selesai')
                            ->body(number_format($stats['created'], 0, ',', '.').' data baru ditambahkan · '.number_format($stats['updated'], 0, ',', '.').' baris dilengkapi · '.number_format($stats['skipped'], 0, ',', '.').' sudah sesuai.')
                            ->success()
                            ->send();
                    }),

                Action::make('resetData')
                    ->label('Reset Data')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->visible(fn (): bool => static::canResetData())
                    ->authorize(fn (): bool => static::canResetData())
                    ->modalHeading(function (): string {
                        if (! AppSetting::hasDataPengirimanResetPassword()) {
                            return 'Atur Password Reset Data Pengiriman';
                        }

                        return $this->isManagingResetPassword()
                            ? 'Ubah Password Reset'
                            : 'Reset Data Pengiriman';
                    })
                    ->modalDescription(function (): string {
                        if (! AppSetting::hasDataPengirimanResetPassword()) {
                            return 'Password reset belum diatur di database server ini. Tetapkan password reset terlebih dahulu (tersimpan terenkripsi di database).';
                        }

                        return $this->isManagingResetPassword()
                            ? 'Masukkan password reset saat ini lalu tetapkan password baru (tersimpan terenkripsi di database).'
                            : 'Tindakan ini menghapus data secara permanen untuk pengujian atau upload ulang. Masukkan password reset untuk melanjutkan.';
                    })
                    ->modalWidth(Width::Medium)
                    ->modalSubmitActionLabel(fn (): string => $this->isManagingResetPassword()
                        ? 'Simpan Password'
                        : 'Ya, Reset Data')
                    ->modalSubmitAction(fn (Action $action): Action => $action
                        ->color($this->isManagingResetPassword() ? 'primary' : 'danger'))
                    ->schema(function (): array {
                        $hasPassword = AppSetting::hasDataPengirimanResetPassword();
                        $isSettingPassword = fn (Get $get): bool => ! $hasPassword || (bool) ($get('manage_password') ?? false);

                        return [
                            Toggle::make('manage_password')
                                ->label('Atur / Ubah Password Reset')
                                ->default(! $hasPassword)
                                ->visible($hasPassword)
                                ->live(),

                            Select::make('scope')
                                ->label('Data yang di-reset')
                                ->options([
                                    'all' => 'Semua Data Pengiriman',
                                    'to' => 'Hanya Realisasi TO',
                                    'spreadsheet' => 'Hanya Hasil Import Spreadsheet',
                                    'csa' => 'Hanya Upload Penjualan CSA',
                                    'surat_jalan' => 'Hanya Hasil Sinkron Surat Jalan',
                                ])
                                ->default('all')
                                ->live()
                                ->visible(fn (Get $get): bool => ! $isSettingPassword($get))
                                ->required(fn (Get $get): bool => ! $isSettingPassword($get)),

                            Toggle::make('reset_imports')
                                ->label('Ikut hapus riwayat batch di menu Upload CSA')
                                ->helperText('Mengosongkan daftar file di menu Import Penjualan CSA. Data alokasi stok tetap aman.')
                                ->default(false)
                                ->visible(fn (Get $get): bool => ! $isSettingPassword($get) && ($get('scope') ?? 'all') === 'all'),

                            TextInput::make('confirmation')
                                ->label('Ketik RESET untuk konfirmasi')
                                ->placeholder('RESET')
                                ->visible(fn (Get $get): bool => ! $isSettingPassword($get))
                                ->required(fn (Get $get): bool => ! $isSettingPassword($get))
                                ->rule('in:RESET')
                                ->validationMessages([
                                    'in' => 'Ketik RESET dengan huruf kapital untuk mengonfirmasi.',
                                ]),

                            TextInput::make('password')
                                ->label('Password Reset')
                                ->password()
                                ->revealable()
                                ->visible(fn (Get $get): bool => ! $isSettingPassword($get))
                                ->required(fn (Get $get): bool => ! $isSettingPassword($get))
                                ->rule(fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                                    if (! AppSetting::verifyDataPengirimanResetPassword((string) $value)) {
                                        $fail('Password reset salah.');
                                    }
                                }),

                            TextInput::make('current_reset_password')
                                ->label('Password Reset Saat Ini')
                                ->password()
                                ->revealable()
                                ->visible(fn (Get $get): bool => $hasPassword && $isSettingPassword($get))
                                ->required(fn (Get $get): bool => $hasPassword && $isSettingPassword($get))
                                ->rule(fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                                    if (! AppSetting::verifyDataPengirimanResetPassword((string) $value)) {
                                        $fail('Password reset saat ini salah.');
                                    }
                                }),

                            TextInput::make('new_password')
                                ->label('Password Reset Baru')
                                ->password()
                                ->revealable()
                                ->minLength(6)
                                ->confirmed()
                                ->visible($isSettingPassword)
                                ->required($isSettingPassword)
                                ->validationMessages([
                                    'min' => 'Password reset minimal 6 karakter.',
                                    'confirmed' => 'Konfirmasi password reset tidak cocok.',
                                ]),

                            TextInput::make('new_password_confirmation')
                                ->label('Ulangi Password Reset Baru')
                                ->password()
                                ->revealable()
                                ->visible($isSettingPassword)
                                ->required($isSettingPassword),
                        ];
                    })
                    ->action(function (array $data): void {
                        $settingMode = ! AppSetting::hasDataPengirimanResetPassword()
                            || (bool) ($data['manage_password'] ?? false);

                        if ($settingMode) {
                            AppSetting::setDataPengirimanResetPassword((string) $data['new_password']);

                            Log::info('Password Reset Data Pengiriman diperbarui oleh Super Admin', [
                                'user_id' => auth()->id(),
                                'user_email' => auth()->user()?->email,
                            ]);

                            Notification::make()
                                ->title('Password Reset tersimpan')
                                ->body('Password reset baru sudah disimpan terenkripsi di database.')
                                ->success()
                                ->send();

                            return;
                        }

                        $scope = (string) ($data['scope'] ?? 'all');
                        $resetImports = $scope === 'all' && (bool) ($data['reset_imports'] ?? false);

                        [$deletedShipments, $deletedImports] = $this->performReset($scope, $resetImports);

                        Log::warning('Data Pengiriman di-reset oleh Super Admin', [
                            'user_id' => auth()->id(),
                            'user_email' => auth()->user()?->email,
                            'scope' => $scope,
                            'reset_imports' => $resetImports,
                            'deleted_shipments' => $deletedShipments,
                            'deleted_imports' => $deletedImports,
                        ]);

                        $body = number_format($deletedShipments, 0, ',', '.').' baris Data Pengiriman dihapus.';
                        if ($deletedImports > 0) {
                            $body .= ' '.$deletedImports.' riwayat Upload CSA ikut dikosongkan.';
                        }

                        Notification::make()
                            ->title('Reset Data Pengiriman berhasil')
                            ->body($body)
                            ->success()
                            ->send();
                    }),
            ])
                ->label('Lainnya')
                ->icon('heroicon-m-chevron-down')
                ->iconPosition(IconPosition::After)
                ->color('gray')
                ->button(),

            CreateAction::make()
                ->label('Tambah Manual')
                ->modalHeading('Tambah Data Pengiriman Manual')
                ->modalWidth('5xl')
                ->createAnother(false)
                ->mutateDataUsing(function (array $data): array {
                    $data['source'] = CsaShipment::SOURCE_MANUAL;
                    $data['kode_gudang'] = $data['kode_gudang'] ?? 'MANUAL';
                    // Admin cabang selalu menambah data untuk cabangnya sendiri
                    $data['target_sheet'] = auth()->user()?->cabangScope() ?? $data['target_sheet'];
                    $data['is_synced'] = false;

                    return $data;
                }),
        ];
    }

    private function isManagingResetPassword(): bool
    {
        return ! AppSetting::hasDataPengirimanResetPassword()
            || (bool) data_get($this->mountedActions, '0.data.manage_password', false);
    }

    public static function canResetData(): bool
    {
        $user = auth()->user();

        return $user !== null
            && blank($user->cabangScope())
            && $user->hasRole(Utils::getSuperAdminName())
            && ($user->can('ResetDataPengiriman') || $user->can('reset_data_pengiriman'));
    }

    /**
     * @return array{0: int, 1: int} [deleted shipments, deleted csa_imports]
     */
    private function performReset(string $scope, bool $resetImports): array
    {
        $result = DB::transaction(function () use ($scope, $resetImports): array {
            $query = DB::table('csa_shipments');

            if ($scope === 'to') {
                $query->where(function ($q): void {
                    $q->where('source', CsaShipment::SOURCE_TO)
                        ->orWhere('no_sj', 'like', CsaShipment::TO_NUMBER_LIKE);
                });
            } elseif (in_array($scope, [CsaShipment::SOURCE_SPREADSHEET, CsaShipment::SOURCE_CSA, CsaShipment::SOURCE_SURAT_JALAN], true)) {
                $query->where('source', $scope);
            }

            if ($scope === 'all') {
                DB::table('freight_invoice_items')
                    ->whereNotNull('csa_shipment_id')
                    ->update(['csa_shipment_id' => null]);
                $deletedShipments = $query->delete();
            } else {
                $idsQuery = (clone $query)->select('id');
                DB::table('freight_invoice_items')
                    ->whereIn('csa_shipment_id', $idsQuery)
                    ->update(['csa_shipment_id' => null]);
                $deletedShipments = $query->delete();
            }

            $deletedImports = 0;
            $filePaths = [];

            if ($resetImports) {
                $filePaths = DB::table('csa_imports')->whereNotNull('file_path')->pluck('file_path')->all();
                DB::table('stock_allocations')->whereNotNull('sales_import_id')->update(['sales_import_id' => null]);
                DB::table('csa_import_items')->delete();
                $deletedImports = DB::table('csa_imports')->delete();
            }

            return [$deletedShipments, $deletedImports, $filePaths];
        });

        foreach ($result[2] as $filePath) {
            $relative = str_replace('\\', '/', (string) $filePath);
            if (is_file($filePath)) {
                @unlink($filePath);
            } elseif (Storage::disk('local')->exists($relative)) {
                Storage::disk('local')->delete($relative);
            }
        }

        CsaShipment::flushLookupCache();

        return [$result[0], $result[1]];
    }

    /**
     * Cabang yang sedang dilihat: cabang akun admin cabang, atau pilihan dropdown Cabang.
     */
    private function selectedCabang(): string
    {
        return auth()->user()?->cabangScope()
            ?? ($this->tableFilters['target_sheet']['value'] ?? null)
            ?: 'Semua Cabang';
    }

    /**
     * Daftar Badan Usaha yang sudah distandarkan dari variasi penulisan di spreadsheet lama.
     *
     * @return array<string, string>
     */
    public static function badanUsahaOptions(): array
    {
        $cabang = auth()->user()?->cabangScope();
        $cacheKey = 'badan_usaha_options:'.md5((string) $cabang);

        return CsaShipment::cached($cacheKey, function (): array {
            $raw = CsaShipment::query()
                ->visibleTo(auth()->user())
                ->whereNotNull('badan_usaha')
                ->where('badan_usaha', '!=', '')
                ->distinct()
                ->pluck('badan_usaha');

            $options = [];
            foreach ($raw as $value) {
                $canonical = self::canonicalBadanUsaha((string) $value);
                if ($canonical !== '') {
                    $options[$canonical] = $canonical;
                }
            }

            ksort($options);

            return $options;
        });
    }

    /**
     * @param  Builder<CsaShipment>  $query
     * @return Builder<CsaShipment>
     */
    public static function filterByBadanUsaha(Builder $query, ?string $selected): Builder
    {
        if (blank($selected)) {
            return $query;
        }

        $target = self::canonicalBadanUsaha($selected);

        /** @var array<string, list<string>> $variantMap */
        $variantMap = CsaShipment::cached('badan_usaha_variant_map', function (): array {
            $map = [];

            CsaShipment::query()
                ->whereNotNull('badan_usaha')
                ->where('badan_usaha', '!=', '')
                ->distinct()
                ->pluck('badan_usaha')
                ->each(function ($raw) use (&$map): void {
                    $canonical = self::canonicalBadanUsaha((string) $raw);
                    if ($canonical !== '') {
                        $map[$canonical][] = (string) $raw;
                    }
                });

            return $map;
        });

        $matches = $variantMap[$target] ?? [$selected];

        return $query->whereIn('badan_usaha', $matches);
    }

    public static function canonicalBadanUsaha(string $raw): string
    {
        $clean = strtoupper(trim((string) preg_replace('/\s+/', ' ', str_replace('"&"', '', $raw))));
        $compact = trim((string) preg_replace('/\s+/', ' ', str_replace(['.', ','], ' ', $clean)));

        if ($compact === '' || in_array($compact, ['BADAN USAHA', 'TIDAK ADA PENGIRIMAN', '-'], true)) {
            return '';
        }

        return match (true) {
            str_contains($compact, 'MEDIA SELUL') || str_contains($compact, 'MDIA SELUL') => 'PT. MEDIA SELULAR INDONESIA',
            str_contains($compact, 'TOP SELUL') || str_contains($compact, 'TOP SELLUL') || in_array($compact, ['CV TOP', 'CVTOP', 'TOP SELULAR'], true) => 'CV. TOP SELULAR',
            str_contains($compact, 'COMPLETE SELUL') || str_contains($compact, 'COMPLETE SELLUL') => 'CV. COMPLETE SELULAR',
            str_contains($compact, 'SATU MEDIA') => 'PT. SATU MEDIA INDONESIA',
            str_contains($compact, 'RETAIL INDONESIA') => 'PT. RETAIL INDONESIA SELALU MAJU',
            str_contains($compact, 'MAJU TEKNO') || str_contains($compact, 'MAJU TECNO') => 'CV. MAJU TEKNOLOGI',
            str_contains($compact, 'COMPLETE SOLUSI NUSANTARA') => 'PT. COMPLETE SOLUSI NUSANTARA',
            str_contains($compact, 'SATU BISNIS INDONESIA') => 'PT. SATU BISNIS INDONESIA',
            str_contains($compact, 'DATA SELULAR TEKNOLOGI') => 'PT. DATA SELULAR TEKNOLOGI',
            default => $clean,
        };
    }

    /**
     * @return array<string, string>
     */
    private function monthOptions(): array
    {
        $cabang = auth()->user()?->cabangScope();

        return CsaShipment::cached('month_options:'.md5((string) $cabang), function (): array {
            $dates = CsaShipment::query()
                ->visibleTo(auth()->user())
                ->whereNotNull('tanggal_kirim')
                ->selectRaw('DISTINCT SUBSTR(CAST(tanggal_kirim AS CHAR), 1, 7) as ym')
                ->orderByDesc('ym')
                ->pluck('ym');

            $options = [];
            foreach ($dates as $ym) {
                $ym = trim((string) $ym);
                if (preg_match('/^(\d{4})-(\d{2})$/', $ym, $match) !== 1) {
                    continue;
                }

                $year = (int) $match[1];
                $month = (int) $match[2];
                if (isset(self::MONTH_NAMES[$month])) {
                    $options[$ym] = self::MONTH_NAMES[$month].' '.$year;
                }
            }

            return $options;
        });
    }

    /**
     * @param  Builder<CsaShipment>  $query
     * @param  array<string, mixed>  $data
     * @return Builder<CsaShipment>
     */
    private function applyExportFilters(Builder $query, array $data): Builder
    {
        $jenis = $data['jenis'] ?? null;
        if ($jenis === 'sj') {
            $query->transferOrders(false);
        } elseif ($jenis === 'to') {
            $query->transferOrders(true);
        } elseif ($jenis === 'surat_jalan') {
            $query->where('source', CsaShipment::SOURCE_SURAT_JALAN);
        } elseif ($jenis === 'manual') {
            $query->where('source', CsaShipment::SOURCE_MANUAL);
        }

        if (filled($data['badan_usaha'] ?? null)) {
            self::filterByBadanUsaha($query, (string) $data['badan_usaha']);
        }

        $bulan = trim((string) ($data['bulan'] ?? ''));
        if (preg_match('/^(\d{4})-(\d{2})$/', $bulan) === 1) {
            $start = Carbon::createFromFormat('!Y-m', $bulan)->startOfMonth()->toDateString();
            $end = Carbon::createFromFormat('!Y-m', $bulan)->endOfMonth()->toDateString();

            $query->where(function (Builder $q) use ($start, $end): void {
                $q->whereBetween('tanggal_kirim', [$start, $end])
                    ->orWhere(function (Builder $sub) use ($start, $end): void {
                        $sub->whereNull('tanggal_kirim')
                            ->whereBetween('tanggal_order', [$start, $end]);
                    });
            });
        }

        return $query;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function exportFileName(array $data): string
    {
        $parts = ['Data Pengiriman', $this->selectedCabang()];

        $jenisLabel = match ($data['jenis'] ?? null) {
            'sj' => 'SJ',
            'to' => 'Realisasi TO',
            'surat_jalan' => 'Surat Jalan',
            'manual' => 'Manual',
            default => null,
        };
        if ($jenisLabel !== null) {
            $parts[] = $jenisLabel;
        }

        if (filled($data['badan_usaha'] ?? null)) {
            $parts[] = trim((string) preg_replace('/[\\\\\/:*?"<>|]+/', ' ', (string) $data['badan_usaha']));
        }

        $parts[] = filled($data['bulan'] ?? null) ? (string) $data['bulan'] : now()->format('Y-m-d');

        return implode(' ', $parts).'.xlsx';
    }
}
