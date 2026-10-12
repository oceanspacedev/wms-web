<?php

namespace App\Filament\Resources\WarehouseDocuments\Schemas;

use App\Models\User;
use App\Services\ScmArsipClient;
use App\Support\MemoFileMatcher;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Throwable;

class WarehouseDocumentForm
{
    /**
     * Form upload satu Memo/DO.
     */
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Select::make('kode_gudang')
                    ->label('Kode Gudang')
                    ->options(fn (): array => static::warehouseOptions())
                    // Petugas gudang terkunci ke gudangnya sendiri; admin/staf SCM bisa memilih semua gudang
                    ->default(fn (): ?string => static::currentUser()?->isWarehouseStaff() ? static::currentUser()->kode_gudang_arsip : null)
                    ->disabled(fn (): bool => (bool) static::currentUser()?->isWarehouseStaff())
                    ->searchable()
                    ->required()
                    ->live()
                    ->afterStateUpdated(fn (Set $set) => $set('arsip_program_id', null))
                    ->helperText('Pilih gudang dulu; daftar No. PO/SJ menyesuaikan.'),

                Select::make('arsip_program_id')
                    ->label('No. PO/SJ')
                    ->options(fn (Get $get): array => static::programOptions($get('kode_gudang')))
                    ->disabled(fn (Get $get): bool => blank($get('kode_gudang')))
                    ->placeholder(fn (Get $get): string => blank($get('kode_gudang')) ? 'Pilih kode gudang dulu' : 'Pilih PO')
                    ->searchable()
                    ->required()
                    ->helperText('Yang belum punya Memo/DO tampil paling atas.'),

                static::fileUpload('file_path')
                    ->storeFileNamesIn('original_name')
                    ->helperText('PDF, JPG, atau PNG, maksimal 15 MB.'),
            ]);
    }

    /**
     * Form upload banyak Memo/DO sekaligus; PO tujuan tiap file ditebak dari nama filenya.
     *
     * @return array<int, mixed>
     */
    public static function bulkComponents(): array
    {
        return [
            static::fileUpload('files')
                ->label('File Memo/DO')
                ->multiple()
                ->maxFiles(30)
                ->storeFileNamesIn('file_names')
                ->live()
                ->afterStateUpdated(fn (?array $state, Get $get, Set $set) => $set('items', static::itemsForFiles($state ?? [], $get('items') ?? [])))
                ->helperText('Maksimal 30 file (PDF, JPG, PNG; masing-masing 15 MB). Beri nama file dengan nomor PO/SJ, misal PO-2026-0103.pdf, agar PO terisi otomatis.'),

            Repeater::make('items')
                ->label('PO tujuan tiap file')
                ->table([
                    TableColumn::make('File'),
                    TableColumn::make('PO tujuan')->markAsRequired(),
                ])
                ->schema([
                    Hidden::make('file_key'),
                    TextInput::make('file_name')
                        ->disabled()
                        ->dehydrated(),
                    static::programSelect('arsip_program_id')
                        ->placeholder('Pilih PO'),
                ])
                ->addable(false)
                ->deletable(false)
                ->reorderable(false)
                ->visible(fn (Get $get): bool => filled($get('files'))),
        ];
    }

    /**
     * Baris tabel cek untuk setiap file; pilihan PO yang sudah diubah pengguna tetap dipertahankan.
     *
     * @param  array<string, TemporaryUploadedFile|string>  $files
     * @param  array<string, array<string, mixed>>  $currentItems
     * @return array<string, array{file_key: string, file_name: string, arsip_program_id: ?string}>
     */
    public static function itemsForFiles(array $files, array $currentItems): array
    {
        $programs = static::availablePrograms();
        $current = collect($currentItems)->keyBy('file_key');
        $items = [];

        foreach ($files as $key => $file) {
            $fileName = $file instanceof TemporaryUploadedFile ? $file->getClientOriginalName() : basename((string) $file);

            $items[(string) $key] = $current->get((string) $key) ?? [
                'file_key' => (string) $key,
                'file_name' => $fileName,
                'arsip_program_id' => MemoFileMatcher::guess($fileName, $programs),
            ];
        }

        return $items;
    }

    /**
     * PO di SCM Arsip yang boleh dikirimi Memo/DO oleh pengguna saat ini:
     * petugas gudang hanya gudangnya sendiri, admin/staf SCM semua gudang.
     *
     * @return array<int, array{id: string, title: string, po_sj_number: ?string, kode_gudang: string, has_memo: bool}>
     */
    public static function availablePrograms(): array
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            return [];
        }

        if ($user->isWarehouseStaff()) {
            return filled($user->kode_gudang_arsip) ? static::programs($user->kode_gudang_arsip) : [];
        }

        return static::programs(null);
    }

    /**
     * Kode gudang yang punya PO di SCM Arsip, beserta jumlah PO yang belum punya Memo/DO.
     *
     * @return array<string, string>
     */
    public static function warehouseOptions(): array
    {
        return collect(static::availablePrograms())
            ->groupBy('kode_gudang')
            ->sortKeys()
            ->map(fn ($programs, string $kodeGudang): string => sprintf(
                '%s — %d PO, %d belum ada Memo/DO',
                $kodeGudang,
                $programs->count(),
                $programs->filter(fn (array $program): bool => empty($program['has_memo']))->count(),
            ))
            ->all();
    }

    /**
     * Pilihan PO; jika kode gudang diisi, hanya PO gudang itu (tanpa awalan kode gudang di label).
     *
     * @return array<string, string>
     */
    public static function programOptions(?string $kodeGudang = null): array
    {
        $kodeGudang = mb_strtolower(trim((string) $kodeGudang));

        return collect(static::availablePrograms())
            ->when($kodeGudang !== '', fn ($programs) => $programs->filter(
                fn (array $program): bool => mb_strtolower(trim((string) $program['kode_gudang'])) === $kodeGudang
            ))
            ->mapWithKeys(fn (array $program): array => [
                (string) $program['id'] => ($kodeGudang === '' ? $program['kode_gudang'].' · ' : '')
                    .($program['po_sj_number'] ?: $program['id']).' — '.$program['title']
                    .(! empty($program['has_memo']) ? ' (sudah ada Memo/DO)' : ''),
            ])
            ->all();
    }

    /**
     * Program di SCM Arsip (satu gudang atau semua), di-cache sebentar agar form tidak memanggil API berulang kali.
     *
     * @return array<int, array{id: string, title: string, po_sj_number: ?string, kode_gudang: string, has_memo: bool}>
     */
    public static function programs(?string $kodeGudang): array
    {
        $kodeGudang = trim((string) $kodeGudang);
        $cacheKey = 'scm-arsip:programs:'.($kodeGudang === '' ? '*' : mb_strtolower($kodeGudang));

        if (is_array($cached = Cache::get($cacheKey))) {
            return $cached;
        }

        try {
            $programs = app(ScmArsipClient::class)->programs($kodeGudang === '' ? null : $kodeGudang);
        } catch (Throwable $e) {
            Log::warning('Gagal mengambil daftar PO dari SCM Arsip', ['kode_gudang' => $kodeGudang ?: 'semua', 'error' => $e->getMessage()]);

            return [];
        }

        Cache::put($cacheKey, $programs, now()->addMinute());

        return $programs;
    }

    private static function programSelect(string $name): Select
    {
        return Select::make($name)
            ->options(fn (): array => static::programOptions())
            ->searchable()
            ->required();
    }

    private static function currentUser(): ?User
    {
        $user = auth()->user();

        return $user instanceof User ? $user : null;
    }

    private static function fileUpload(string $name): FileUpload
    {
        return FileUpload::make($name)
            ->label('File Memo/DO')
            ->disk('local')
            ->directory('warehouse_documents')
            ->acceptedFileTypes(['application/pdf', 'image/jpeg', 'image/png'])
            ->maxSize(15360)
            ->required();
    }
}
