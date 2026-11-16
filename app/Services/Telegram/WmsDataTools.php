<?php

namespace App\Services\Telegram;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Alat baca data WMS untuk AI: cari_data (daftar baris) dan rekap_data (jumlah per kelompok). Hanya data & kolom di
 * WmsDataCatalog, hanya baca, dan selalu lewat izin & batasan akun penanya. Kesalahan dikembalikan sebagai "error"
 * supaya AI bisa memperbaiki permintaannya.
 */
class WmsDataTools
{
    private const MAX_ROWS = 50;

    private const MAX_GROUPS = 50;

    /**
     * @var list<string>
     */
    private const OPERATORS = ['=', '!=', '>', '>=', '<', '<=', 'mengandung', 'kosong', 'tidak_kosong', 'salah_satu'];

    public function __construct(private User $user) {}

    /**
     * Definisi tool format OpenAI (chat completions). Nilai filter selalu teks supaya cocok untuk semua penyedia AI.
     *
     * @return list<array<string, mixed>>
     */
    public static function definitions(): array
    {
        $data = ['type' => 'string', 'description' => 'Nama data dari daftar data di instruksi, mis. "surat_jalan".'];
        $filter = [
            'type' => 'array',
            'description' => 'Syarat (semua harus terpenuhi).',
            'items' => [
                'type' => 'object',
                'properties' => [
                    'kolom' => ['type' => 'string'],
                    'operator' => ['type' => 'string', 'enum' => self::OPERATORS],
                    'nilai' => ['type' => 'string', 'description' => 'Tanggal YYYY-MM-DD, angka, true/false, atau beberapa nilai dipisah koma untuk salah_satu. Kosongkan untuk operator kosong/tidak_kosong.'],
                ],
                'required' => ['kolom', 'operator'],
            ],
        ];

        return [
            [
                'type' => 'function',
                'function' => [
                    'name' => 'cari_data',
                    'description' => 'Ambil baris data dari satu menu WMS beserta total barisnya.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'data' => $data,
                            'filter' => $filter,
                            'urut_kolom' => ['type' => 'string', 'description' => 'Kolom pengurutan (default: kolom tanggal utama).'],
                            'urut_arah' => ['type' => 'string', 'enum' => ['naik', 'turun']],
                            'batas' => ['type' => 'integer', 'description' => 'Jumlah baris dirinci, maks. 50 (default 20).'],
                        ],
                        'required' => ['data'],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'rekap_data',
                    'description' => 'Hitung jumlah baris dan total kolom angka, dikelompokkan per satu kolom (mis. per depo, per gudang, per tanggal). Pakai untuk pertanyaan "berapa", "paling banyak", "total", perbandingan, atau tren.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'data' => $data,
                            'kelompok' => ['type' => 'string', 'description' => 'Kolom pengelompokan. Kosongkan untuk total saja.'],
                            'per' => ['type' => 'string', 'enum' => ['hari', 'bulan'], 'description' => 'Untuk kelompok kolom tanggal.'],
                            'jumlahkan' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Kolom angka yang dijumlahkan.'],
                            'filter' => $filter,
                        ],
                        'required' => ['data'],
                    ],
                ],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    public function run(string $name, array $arguments): array
    {
        try {
            return match ($name) {
                'cari_data' => $this->search($arguments),
                'rekap_data' => $this->summary($arguments),
                default => throw new InvalidArgumentException("Alat {$name} tidak dikenal."),
            };
        } catch (InvalidArgumentException $exception) {
            return ['error' => $exception->getMessage()];
        }
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function search(array $arguments): array
    {
        [$key, $dataset] = $this->dataset($arguments['data'] ?? null);
        $query = $this->query($dataset, $arguments['filter'] ?? []);
        $total = (clone $query)->count();

        $sortColumn = $arguments['urut_kolom'] ?? $dataset['tanggal'] ?? null;
        $direction = ($arguments['urut_arah'] ?? 'turun') === 'naik' ? 'asc' : 'desc';

        if ($sortColumn !== null) {
            $column = $this->column($dataset, (string) $sortColumn);

            if ($column['tipe'] === 'relasi') {
                throw new InvalidArgumentException("Kolom {$sortColumn} tidak bisa dipakai untuk mengurutkan.");
            }

            $query->orderBy($sortColumn, $direction);
        }

        $relations = collect($dataset['kolom'])->where('tipe', 'relasi')->pluck('relasi')->unique()->values()->all();
        $rows = $query->with($relations)->orderByDesc($query->getModel()->getKeyName())
            ->limit(min(self::MAX_ROWS, max(1, (int) ($arguments['batas'] ?? 20))))
            ->get()
            ->map(fn (Model $record): array => $this->row($record, $dataset))
            ->all();

        return ['data' => $key, 'total_baris' => $total, 'ditampilkan' => count($rows), 'baris' => $rows];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function summary(array $arguments): array
    {
        [$key, $dataset] = $this->dataset($arguments['data'] ?? null);
        $sums = collect((array) ($arguments['jumlahkan'] ?? []))->map(function (mixed $name) use ($dataset): string {
            if ($this->column($dataset, (string) $name)['tipe'] !== 'angka') {
                throw new InvalidArgumentException("Kolom {$name} bukan angka, tidak bisa dijumlahkan.");
            }

            return (string) $name;
        })->unique()->values();

        $query = $this->query($dataset, $arguments['filter'] ?? []);
        $selects = ['count(*) as jumlah_baris', ...$sums->map(fn (string $name): string => "sum({$name}) as total_{$name}")];
        $totals = (array) (clone $query)->toBase()->selectRaw(implode(', ', $selects))->first();

        $result = ['data' => $key, 'total' => $this->numbers($totals)];
        $group = $arguments['kelompok'] ?? null;

        if (blank($group)) {
            return $result;
        }

        $column = $this->column($dataset, (string) $group);
        $model = $query->getModel();
        $expression = match ($column['tipe']) {
            'relasi' => $model->{$column['relasi']}()->getForeignKeyName(),
            'tanggal' => 'substr('.$group.', 1, '.(($arguments['per'] ?? 'hari') === 'bulan' ? 7 : 10).')',
            'foto' => "case when {$group} is null or {$group} = '' then 'belum ada' else 'ada' end",
            default => $group,
        };

        $groups = $query->toBase()
            ->selectRaw("{$expression} as kelompok, ".implode(', ', $selects))
            ->groupBy(DB::raw($expression))
            ->orderBy(DB::raw($column['tipe'] === 'tanggal' ? 'kelompok' : 'jumlah_baris'), $column['tipe'] === 'tanggal' ? 'asc' : 'desc')
            ->limit(self::MAX_GROUPS)
            ->get()
            ->map(fn (object $row): array => $this->numbers((array) $row));

        // Kelompok relasi (mis. depo_id) diganti namanya
        if ($column['tipe'] === 'relasi') {
            $related = $model->{$column['relasi']}()->getRelated();
            $names = $related->newQuery()->whereKey($groups->pluck('kelompok')->filter()->all())->pluck($column['target'], $related->getKeyName());
            $groups = $groups->map(fn (array $row): array => [...$row, 'kelompok' => $names[$row['kelompok']] ?? 'Tanpa '.$group]);
        }

        return [...$result, 'kelompok' => $group, 'hasil' => $groups->values()->all()];
    }

    /**
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function dataset(mixed $key): array
    {
        $datasets = WmsDataCatalog::forUser($this->user);

        if (! is_string($key) || ! isset($datasets[$key])) {
            throw new InvalidArgumentException('Data "'.(is_string($key) ? $key : '').'" tidak ada atau akun ini tidak punya akses. Data yang tersedia: '.implode(', ', array_keys($datasets)).'.');
        }

        return [$key, $datasets[$key]];
    }

    /**
     * @param  array<string, mixed>  $dataset
     * @return array<string, string>
     */
    private function column(array $dataset, string $name): array
    {
        return $dataset['kolom'][$name]
            ?? throw new InvalidArgumentException("Kolom {$name} tidak ada. Kolom yang tersedia: ".implode(', ', array_keys($dataset['kolom'])).'.');
    }

    /**
     * @param  array<string, mixed>  $dataset
     * @return Builder<Model>
     */
    private function query(array $dataset, mixed $filters): Builder
    {
        /** @var Builder<Model> $query */
        $query = $dataset['model']::query();

        if (isset($dataset['batasi'])) {
            ($dataset['batasi'])($query, $this->user);
        }

        foreach (is_array($filters) ? $filters : [] as $filter) {
            if (is_array($filter)) {
                $this->filter($query, $dataset, $filter);
            }
        }

        return $query;
    }

    /**
     * @param  Builder<Model>  $query
     * @param  array<string, mixed>  $dataset
     * @param  array<string, mixed>  $filter
     */
    private function filter(Builder $query, array $dataset, array $filter): void
    {
        $name = (string) ($filter['kolom'] ?? '');
        $column = $this->column($dataset, $name);
        $operator = (string) ($filter['operator'] ?? '=');
        $value = trim((string) ($filter['nilai'] ?? ''));

        if (! in_array($operator, self::OPERATORS, true)) {
            throw new InvalidArgumentException("Operator {$operator} tidak dikenal.");
        }

        if ($column['tipe'] === 'relasi') {
            $this->relationFilter($query, $column, $operator, $value);

            return;
        }

        if ($column['tipe'] === 'foto' || in_array($operator, ['kosong', 'tidak_kosong'], true)) {
            // Foto: nilai true/"ada" berarti ada fotonya
            $empty = $operator === 'kosong' || ($column['tipe'] === 'foto' && $operator !== 'tidak_kosong' && in_array(Str::lower($value), ['false', 'tidak', 'belum', 'belum ada', '0'], true));
            $query->where(fn (Builder $q): Builder => $empty
                ? $q->whereNull($name)->orWhere($name, '')
                : $q->whereNotNull($name)->where($name, '!=', ''));

            return;
        }

        if ($operator === 'salah_satu') {
            $query->whereIn($name, array_map('trim', explode(',', $value)));

            return;
        }

        if ($operator === 'mengandung') {
            $query->where($name, 'like', '%'.$value.'%');

            return;
        }

        match ($column['tipe']) {
            'tanggal' => $query->whereDate($name, $operator === '!=' ? '<>' : $operator, $this->date($value)),
            'angka' => $query->where($name, $operator, is_numeric($value) ? (float) $value : throw new InvalidArgumentException("Nilai {$name} harus angka.")),
            'ya_tidak' => $query->where($name, $operator, in_array(Str::lower($value), ['true', '1', 'ya'], true)),
            default => $query->where($name, $operator, $value),
        };
    }

    /**
     * @param  Builder<Model>  $query
     * @param  array<string, string>  $column
     */
    private function relationFilter(Builder $query, array $column, string $operator, string $value): void
    {
        if ($operator === 'kosong') {
            $query->whereDoesntHave($column['relasi']);

            return;
        }

        if ($operator === 'tidak_kosong') {
            $query->whereHas($column['relasi']);

            return;
        }

        $fields = explode(',', $column['cari'] ?? $column['target']);
        $values = $operator === 'salah_satu' ? array_map('trim', explode(',', $value)) : [$value];
        // Nama/kode relasi (depo, ekspedisi) dicocokkan tanpa membedakan huruf besar/kecil
        $match = fn (Builder $related): Builder => $related->where(function (Builder $q) use ($fields, $values, $operator): void {
            foreach ($fields as $field) {
                foreach ($values as $item) {
                    $operator === 'mengandung'
                        ? $q->orWhereRaw("LOWER({$field}) like ?", ['%'.Str::lower($item).'%'])
                        : $q->orWhereRaw("LOWER({$field}) = ?", [Str::lower($item)]);
                }
            }
        });

        $operator === '!=' ? $query->whereDoesntHave($column['relasi'], $match) : $query->whereHas($column['relasi'], $match);
    }

    /**
     * @param  array<string, mixed>  $dataset
     * @return array<string, mixed>
     */
    private function row(Model $record, array $dataset): array
    {
        return collect($dataset['kolom'])->map(function (array $column, string $name) use ($record): mixed {
            $value = $column['tipe'] === 'relasi' ? $record->{$column['relasi']}?->{$column['target']} : $record->getAttribute($name);

            return match (true) {
                $column['tipe'] === 'foto' => filled($value),
                $value instanceof Carbon => $value->format($column['tipe'] === 'tanggal' && $value->format('H:i:s') === '00:00:00' ? 'Y-m-d' : 'Y-m-d H:i'),
                is_string($value) => Str::limit($value, 200),
                default => $value,
            };
        })->all();
    }

    private function date(string $value): string
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            throw new InvalidArgumentException("Tanggal {$value} harus berformat YYYY-MM-DD.");
        }

        return $value;
    }

    /**
     * Angka dari database (string desimal) dijadikan angka biasa; nama kelompok (mis. nomor SJ) tetap teks.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function numbers(array $row): array
    {
        return collect($row)
            ->map(fn (mixed $value, string $key): mixed => $key !== 'kelompok' && is_string($value) && is_numeric($value) ? $value + 0 : $value)
            ->all();
    }
}
