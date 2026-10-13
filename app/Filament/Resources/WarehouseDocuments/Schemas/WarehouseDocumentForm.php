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
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Schemas\Schema;
use Filament\Support\Enums\TextSize;
use finfo;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Throwable;

class WarehouseDocumentForm
{
    /**
     * Jenis isi file yang diterima (dicek dari isi file, bukan dari akhiran nama file).
     */
    public const ACCEPTED_TYPES = ['application/pdf', 'image/jpeg', 'image/png'];

    /**
     * Form upload satu Memo/DO.
     */
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                static::warehouseSelect()
                    ->label('Kode Gudang')
                    ->default(fn (): ?string => static::currentUser()?->isWarehouseStaff() ? static::currentUser()->kode_gudang_arsip : null)
                    ->helperText('Pilih gudang dulu; daftar No. PO/SJ menyesuaikan.'),

                static::programSelect('arsip_program_id')
                    ->label('No. PO/SJ')
                    ->helperText('Yang belum punya Memo/DO tampil paling atas.'),

                static::fileUpload('file_path')
                    ->storeFileNamesIn('original_name')
                    ->helperText('PDF, JPG, atau PNG, maksimal 15 MB.'),
            ]);
    }

    /**
     * Upload banyak Memo/DO dalam dua langkah: pilih file, lalu cek gudang & PO tujuan tiap file.
     * PO (dan kode gudangnya) ditebak dari nama file.
     *
     * @return array<int, Step>
     */
    public static function bulkSteps(): array
    {
        return [
            Step::make('Pilih file')
                ->schema([
                    static::fileUpload('files')
                        ->hiddenLabel()
                        ->multiple()
                        ->maxFiles(30)
                        // Daftar satu baris tipis per file (nama, ukuran, hapus); layout grid membuat kotak besar kosong untuk PDF
                        ->previewable(false)
                        ->storeFileNamesIn('file_names')
                        ->live()
                        ->afterStateUpdated(function (?array $state, Get $get, Set $set): void {
                            static::warnAboutInvalidFiles($state ?? [], $get('items') ?? []);
                            $set('items', static::itemsForFiles($state ?? [], $get('items') ?? []));
                        })
                        ->helperText('Maks. 30 file PDF/JPG/PNG (≤ 15 MB). Nama file = No. PO, misal 2610000077.pdf, agar terisi otomatis.'),
                ]),

            Step::make('Cek PO tujuan')
                ->schema([
                    Text::make(fn (Get $get): string => static::bulkSummary($get('items') ?? [])),

                    Repeater::make('items')
                        ->hiddenLabel()
                        ->table([
                            TableColumn::make('File')->width('30%'),
                            TableColumn::make('Kode Gudang')->markAsRequired()->width('22%'),
                            TableColumn::make('PO tujuan')->markAsRequired(),
                        ])
                        ->compact()
                        ->schema([
                            Hidden::make('file_key'),
                            Hidden::make('file_name'),
                            // Nama file ditampilkan utuh dengan huruf kecil (boleh turun baris), bukan input yang terpotong
                            TextEntry::make('file_label')
                                ->hiddenLabel()
                                ->size(TextSize::Small)
                                ->state(fn (Get $get): ?string => $get('file_name')),
                            static::warehouseSelect(compact: true)
                                ->placeholder('Pilih gudang'),
                            static::programSelect('arsip_program_id', compact: true),
                        ])
                        ->addable(false)
                        ->deletable(false)
                        ->reorderable(false),
                ]),
        ];
    }

    /**
     * Sebut langsung file baru yang isinya bukan PDF/JPG/PNG (misal halaman web yang tersimpan sebagai .pdf),
     * karena pesan validasi untuk banyak file tidak menyebut nama file yang bermasalah.
     *
     * @param  array<string, TemporaryUploadedFile|string>  $files
     * @param  array<string, array<string, mixed>>  $currentItems
     */
    public static function warnAboutInvalidFiles(array $files, array $currentItems): void
    {
        $knownKeys = collect($currentItems)->pluck('file_key')->map(fn ($key): string => (string) $key)->all();

        $invalidNames = collect($files)
            ->filter(fn ($file, $key): bool => $file instanceof TemporaryUploadedFile && ! in_array((string) $key, $knownKeys, true))
            ->reject(fn (TemporaryUploadedFile $file): bool => in_array(static::detectMimeType($file), self::ACCEPTED_TYPES, true))
            ->map(fn (TemporaryUploadedFile $file): string => $file->getClientOriginalName())
            ->values();

        if ($invalidNames->isEmpty()) {
            return;
        }

        Notification::make()
            ->title('File bukan PDF/JPG/PNG asli')
            ->body($invalidNames->implode(', ').'. Isinya bukan PDF/gambar (misal halaman web yang tersimpan sebagai .pdf). Hapus dari daftar lalu unduh ulang file aslinya.')
            ->danger()
            ->persistent()
            ->send();
    }

    /**
     * Jenis file menurut isinya (misal "text/html" untuk halaman web berakhiran .pdf).
     */
    private static function detectMimeType(TemporaryUploadedFile $file): ?string
    {
        $path = $file->getRealPath();

        if (is_string($path) && is_file($path)) {
            return (new finfo(FILEINFO_MIME_TYPE))->file($path) ?: null;
        }

        return $file->getMimeType();
    }

    /**
     * Ringkasan di atas tabel cek, misal "6 file · 4 sudah ada PO tujuan · 2 perlu dipilih".
     *
     * @param  array<string, array<string, mixed>>  $items
     */
    public static function bulkSummary(array $items): string
    {
        $total = count($items);
        $ready = collect($items)->filter(fn (array $item): bool => filled($item['kode_gudang'] ?? null) && filled($item['arsip_program_id'] ?? null))->count();

        return $total === $ready
            ? "{$total} file · semua sudah ada PO tujuan"
            : "{$total} file · {$ready} sudah ada PO tujuan · ".($total - $ready).' perlu dipilih';
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

        $ownWarehouse = static::currentUser()?->isWarehouseStaff() ? static::currentUser()->kode_gudang_arsip : null;

        foreach ($files as $key => $file) {
            $fileName = $file instanceof TemporaryUploadedFile ? $file->getClientOriginalName() : basename((string) $file);
            $programId = MemoFileMatcher::guess($fileName, $programs);
            $program = collect($programs)->first(fn (array $program): bool => (string) $program['id'] === $programId);

            $items[(string) $key] = $current->get((string) $key) ?? [
                'file_key' => (string) $key,
                'file_name' => $fileName,
                // Jika PO terbaca dari nama file, kode gudangnya ikut terisi dari PO tersebut
                'kode_gudang' => $program['kode_gudang'] ?? $ownWarehouse,
                'arsip_program_id' => $programId,
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
    public static function warehouseOptions(bool $compact = false): array
    {
        return collect(static::availablePrograms())
            ->groupBy('kode_gudang')
            ->sortKeys()
            ->map(fn ($programs, string $kodeGudang): string => $compact ? $kodeGudang : sprintf(
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
    public static function programOptions(?string $kodeGudang = null, bool $compact = false): array
    {
        $kodeGudang = mb_strtolower(trim((string) $kodeGudang));

        return collect(static::availablePrograms())
            ->when($kodeGudang !== '', fn ($programs) => $programs->filter(
                fn (array $program): bool => mb_strtolower(trim((string) $program['kode_gudang'])) === $kodeGudang
            ))
            ->mapWithKeys(fn (array $program): array => [
                (string) $program['id'] => ($kodeGudang === '' ? $program['kode_gudang'].' · ' : '')
                    .($program['po_sj_number'] ?: $program['id']).' — '.$program['title']
                    // Versi ringkas (tabel upload banyak) memakai penanda yang lebih pendek
                    .(! empty($program['has_memo']) ? ($compact ? ' (ada Memo)' : ' (sudah ada Memo/DO)') : ''),
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

    /**
     * Pilihan kode gudang; petugas gudang terkunci ke gudangnya sendiri, admin/staf SCM bisa memilih semua gudang.
     */
    private static function warehouseSelect(bool $compact = false): Select
    {
        return Select::make('kode_gudang')
            ->options(fn (): array => static::warehouseOptions($compact))
            ->disabled(fn (): bool => (bool) static::currentUser()?->isWarehouseStaff())
            ->searchable()
            // Teks pendek agar muat di kolom tabel yang sempit
            ->searchPrompt('Cari kode…')
            ->noSearchResultsMessage('Tidak ditemukan')
            ->required()
            ->live()
            ->afterStateUpdated(fn (Set $set) => $set('arsip_program_id', null));
    }

    /**
     * Pilihan PO, hanya untuk kode gudang yang dipilih di samping/di atasnya.
     */
    private static function programSelect(string $name, bool $compact = false): Select
    {
        return Select::make($name)
            ->options(fn (Get $get): array => static::programOptions($get('kode_gudang'), $compact))
            ->disabled(fn (Get $get): bool => blank($get('kode_gudang')))
            ->placeholder(fn (Get $get): string => blank($get('kode_gudang')) ? 'Pilih kode gudang dulu' : 'Pilih PO')
            ->searchable()
            ->searchPrompt('Cari No. PO atau judul…')
            ->noSearchResultsMessage('PO tidak ditemukan')
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
            ->acceptedFileTypes(self::ACCEPTED_TYPES)
            ->maxSize(15360)
            ->validationMessages([
                'mimetypes' => 'Ada file yang isinya bukan PDF, JPG, atau PNG asli (misal halaman web yang tersimpan sebagai .pdf). Hapus file tersebut lalu coba lagi.',
                'max' => 'Ukuran file maksimal 15 MB.',
            ])
            ->required();
    }
}
