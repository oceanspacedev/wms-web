<?php

namespace Tests\Feature;

use App\Filament\Resources\CsaShipments\Pages\ListCsaShipments;
use App\Jobs\ProcessCsaImportJob;
use App\Models\CsaImport;
use App\Models\CsaShipment;
use App\Models\User;
use App\Models\WarehouseMapping;
use App\Services\CsaExcelParserService;
use BezhanSalleh\FilamentShield\Support\Utils;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class TransferOrderImportTest extends TestCase
{
    use RefreshDatabase;

    /** 14/10/2026 dan 20/10/2026 sebagai nomor seri tanggal Excel. */
    private const SENT = 46309;

    private const RECEIVED = 46315;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['GMJKT' => ['GUDANG MSIS JAKARTA', 'JAKARTA PC'], 'GMPDG' => ['GUDANG PADANG', 'PADANG'], 'GMSMG' => ['GUDANG MSIS SEMARANG', 'SEMARANG'], 'GMPWT' => ['GUDANG MSIS PURWOKERTO', 'PURWOKERTO']] as $code => [$name, $sheet]) {
            WarehouseMapping::create(['csa_code' => $code, 'csa_name' => $name, 'target_sheet' => $sheet, 'is_active' => true]);
        }
    }

    public function test_report_adds_central_warehouse_transfers_to_depots_as_jakarta_pik_rows(): void
    {
        // Alamat depo diambil dari baris TO sebelumnya
        $this->shipment('2609000100-01', ['tujuan_dealer' => 'DEPO PADANG', 'alamat_kirim' => 'Komplek Belanti Permai Padang']);

        $import = $this->runImport($this->report([
            ...$this->transfer('2610000219-01', 'GMJKT', '[GMPDG   ]', 'ISSUE', [
                ['TECNO - HP 4G - CAMON 50 8/256 - BLACK', 10, 45990000],
                ['FREEGIFT DATA CABLE', 10, 0],
            ]),
            // Penerimaan di depo, TO antar depo, dan TO ke gudang non-depo tidak masuk
            ...$this->transfer('2610000219-02', '[GMJKT   ]', 'GMPDG', 'RECEIVE', [['TECNO - HP 4G - CAMON 50 8/256 - BLACK', 10, 45990000]]),
            ...$this->transfer('2610000220-01', 'GMSMG', '[GMPWT   ]', 'ISSUE', [['ITEL - HP - A90', 5, 5000000]]),
            ...$this->transfer('2610000221-01', 'GMJKT', '[GBO     ]', 'ISSUE', [['ITEL - HP - A90', 5, 5000000]]),
        ]));

        $this->assertSame('completed', $import->status, (string) $import->error_message);
        $this->assertSame(CsaImport::REPORT_TRANSFER, $import->report_type);
        $this->assertSame(1, $import->total_new);

        $shipment = CsaShipment::where('no_sj', '2610000219-01')->sole();
        $this->assertSame(CsaShipment::SOURCE_TO, $shipment->source);
        $this->assertSame($import->id, $shipment->csa_import_id);
        $this->assertSame('2610000219', $shipment->no_trans);
        $this->assertSame('JAKARTA PIK', $shipment->target_sheet);
        $this->assertSame('DEPO PADANG', $shipment->tujuan_dealer);
        $this->assertSame('PADANG', $shipment->nama_kota);
        $this->assertSame('Komplek Belanti Permai Padang', $shipment->alamat_kirim);
        $this->assertSame('GMJKT', $shipment->kode_gudang);
        $this->assertSame('GUDANG MSIS JAKARTA', $shipment->nama_gudang);
        $this->assertSame('PT. MEDIA SELULAR INDONESIA', $shipment->badan_usaha);
        $this->assertSame('TECNO', $shipment->brand);
        $this->assertSame('T-PADANG', $shipment->reff_note);
        // Baris IMEI di bawah tiap barang tidak ikut dihitung
        $this->assertSame(20, $shipment->qty_unit);
        $this->assertEquals(45990000, (float) $shipment->total_nominal_sj);
        $this->assertSame('2026-10-14', $shipment->tanggal_kirim->format('Y-m-d'));
        $this->assertSame('2026-10-20', $shipment->tanggal_diterima->format('Y-m-d'));

        $this->assertSame(0, CsaShipment::whereIn('no_sj', ['2610000219-02', '2610000220-01', '2610000221-01'])->count());
    }

    public function test_transfer_orders_already_in_data_pengiriman_are_not_added_again(): void
    {
        $withoutReceivedDate = $this->shipment('2610000219-01', ['total_nominal_sj' => 0]);
        $complete = $this->shipment('2610000222-01', ['tanggal_diterima' => '2026-10-18']);

        $import = $this->runImport($this->report([
            ...$this->transfer('2610000219-01', 'GMJKT', '[GMPDG   ]', 'ISSUE', [['TECNO - HP 4G - CAMON 50', 10, 45990000]]),
            ...$this->transfer('2610000222-01', 'GMJKT', '[GMPDG   ]', 'ISSUE', [['TECNO - HP 4G - CAMON 50', 3, 13797000]]),
        ]));

        $this->assertSame([0, 1, 1], [$import->total_new, $import->total_updated, $import->total_skipped]);
        $this->assertSame(2, CsaShipment::count());

        // Hanya tanggal diterima yang kosong diisi; data spreadsheet lainnya tidak ditimpa
        $withoutReceivedDate->refresh();
        $this->assertSame('2026-10-20', $withoutReceivedDate->tanggal_diterima->format('Y-m-d'));
        $this->assertEquals(0, (float) $withoutReceivedDate->total_nominal_sj);
        $this->assertSame(CsaShipment::SOURCE_SPREADSHEET, $withoutReceivedDate->source);
        $this->assertSame('2026-10-18', $complete->refresh()->tanggal_diterima->format('Y-m-d'));
    }

    public function test_reuploading_the_report_refreshes_earlier_transfer_orders_without_duplicates(): void
    {
        $file = $this->report($this->transfer('2610000219-01', 'GMJKT', '[GMPDG   ]', 'ISSUE', [['TECNO - HP 4G - CAMON 50', 10, 45990000]]));

        $this->runImport($file);
        CsaShipment::where('no_sj', '2610000219-01')->update(['nama_ekspedisi' => '21 EXPRESS']);

        $second = $this->runImport($file);

        $this->assertSame([0, 1, 0], [$second->total_new, $second->total_updated, $second->total_skipped]);
        $shipment = CsaShipment::where('no_sj', '2610000219-01')->sole();
        $this->assertSame($second->id, $shipment->csa_import_id);
        $this->assertSame('21 EXPRESS', $shipment->nama_ekspedisi);
    }

    public function test_transfer_order_rows_keep_report_data_locked_and_cannot_be_deleted(): void
    {
        $shipment = $this->shipment('2610000219-01', ['source' => CsaShipment::SOURCE_TO, 'tujuan_dealer' => 'DEPO PADANG']);

        $this->actingAs($this->centralAdmin());

        Livewire::test(ListCsaShipments::class)
            ->assertActionHidden(TestAction::make('delete')->table($shipment))
            ->callAction(TestAction::make('edit')->table($shipment), data: [
                'tujuan_dealer' => 'DIUBAH',
                'no_resi_awb' => 'RESI-123',
            ])
            ->assertHasNoFormErrors();

        $shipment->refresh();
        $this->assertSame('DEPO PADANG', $shipment->tujuan_dealer);
        $this->assertSame('RESI-123', $shipment->no_resi_awb);
    }

    public function test_data_pengiriman_marks_and_filters_transfer_orders_and_sj(): void
    {
        // TO lama dari spreadsheet dikenali dari akhiran nomornya, TO hasil upload dari sumbernya
        $oldTransfer = $this->shipment('2610000224-01');
        $uploadedTransfer = $this->shipment('2610000300-01', ['source' => CsaShipment::SOURCE_TO]);
        $sj = $this->shipment('2609000005', ['tujuan_dealer' => 'RASYAVA CELL']);
        $withoutNumber = $this->shipment('', ['tujuan_dealer' => 'RETURAN DEMO']);

        $this->actingAs($this->centralAdmin());

        Livewire::test(ListCsaShipments::class)
            ->assertTableColumnHasDescription('no_sj', 'Realisasi TO', $oldTransfer)
            ->assertTableColumnHasDescription('no_sj', 'Realisasi TO', $uploadedTransfer)
            ->assertTableColumnHasDescription('no_sj', 'SJ', $sj)
            ->filterTable('jenis', 'to')
            ->assertCanSeeTableRecords([$oldTransfer, $uploadedTransfer])
            ->assertCanNotSeeTableRecords([$sj, $withoutNumber])
            ->filterTable('jenis', 'sj')
            ->assertCanSeeTableRecords([$sj, $withoutNumber])
            ->assertCanNotSeeTableRecords([$oldTransfer, $uploadedTransfer]);
    }

    private function runImport(string $file): CsaImport
    {
        $import = CsaImport::create([
            'file_name' => 'laporan realisasi TO.xlsx',
            'file_path' => $file,
            'status' => 'pending',
            'total_raw_rows' => 0,
            'total_shipments' => 0,
            'total_synced' => 0,
        ]);

        (new ProcessCsaImportJob($import))->handle(app(CsaExcelParserService::class));

        return $import->refresh();
    }

    /**
     * Baris TO lama di cabang JAKARTA PIK hasil import spreadsheet.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function shipment(string $noSj, array $attributes = []): CsaShipment
    {
        return CsaShipment::create(array_merge([
            'source' => CsaShipment::SOURCE_SPREADSHEET,
            'no_sj' => $noSj,
            'badan_usaha' => 'PT. MEDIA SELULAR INDONESIA',
            'kode_gudang' => 'SPREADSHEET',
            'target_sheet' => 'JAKARTA PIK',
            'tujuan_dealer' => 'DEPO PADANG',
            'tanggal_kirim' => '2026-10-14',
            'total_nominal_sj' => 45990000,
            'qty_unit' => 10,
        ], $attributes));
    }

    /**
     * Baris satu TO seperti di laporan ERP: tiap barang diikuti baris IMEI tanpa nomor transaksi.
     *
     * @param  list<array{0: string, 1: int, 2: int}>  $items  [nama item, qty, jumlah]
     * @return list<array<string, mixed>>
     */
    private function transfer(string $number, string $from, string $to, string $type, array $items): array
    {
        $rows = [];

        foreach ($items as $index => [$name, $qty, $amount]) {
            $rows[] = [
                'A' => 1, 'B' => $number, 'C' => self::SENT, 'H' => $from, 'I' => '#HPMPDG', 'J' => $to, 'K' => $type,
                'L' => self::RECEIVED, 'N' => $index + 1, 'O' => 'T-PADANG', 'P' => 'FG2-0264'.$index, 'Q' => $name,
                'R' => '#Mixed#', 'S' => $qty, 'T' => 'UNT', 'U' => $qty > 0 ? intdiv($amount, $qty) : 0, 'V' => $amount,
            ];
            $rows[] = ['R' => '358746510527472', 'S' => 1];
        }

        return $rows;
    }

    /**
     * File .xlsx berformat laporan [XRSTOK23] dengan header di baris 12.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    private function report(array $rows): string
    {
        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.uniqid('realisasi_to_').'.xlsx';
        $header = [
            'A' => 'No', 'B' => 'Transaksi', 'C' => 'Tgl Kirim', 'D' => 'Kode1', 'E' => 'Nama Pengirim1', 'F' => 'Kode2',
            'G' => 'Nama Pengirim2', 'H' => 'Dari', 'I' => 'Transit', 'J' => 'Ke', 'K' => 'Jenis', 'L' => 'Tgl Terima',
            'M' => 'Nama Penerima', 'N' => 'No', 'O' => 'Ref.Note', 'P' => 'Kode Item', 'Q' => 'Nama Item', 'R' => 'IMEI',
            'S' => 'Qty', 'T' => 'Sat', 'U' => 'Harga Jual', 'V' => 'Jumlah',
        ];

        $writer = new Writer;
        $writer->openToFile($path);
        $writer->getCurrentSheet()->setName('Lap, Realisasi Transfer Order');

        $title = [
            '[XRSTOK23] ADITYA', 'LAPORAN REALISASI TRANSFER ANTAR GUDANG', 'PT Media Selular Indonesia', 'Jl. Tuparev Kertawinangun',
            '-', 'Dari Gudang: #GTRBO,#HPMBDG', 'Ke Gudang  : #GTRBO,#HPMBDG', 'Transit    : #GTRBO', 'Kode Item  : (semua)',
            'Ref Note   : (semua)', 'Tgl Trx    : 01/01/2026 sd 22/10/2026',
        ];

        foreach ($title as $line) {
            $writer->addRow(Row::fromValues([$line]));
        }

        foreach ([$header, ...$rows] as $cells) {
            $values = array_fill(0, 22, null);

            foreach ($cells as $column => $value) {
                $values[ord($column) - ord('A')] = $value;
            }

            $writer->addRow(Row::fromValues($values));
        }

        $writer->close();

        return $path;
    }

    private function centralAdmin(): User
    {
        $permissions = ['ViewAny:CsaShipment', 'View:CsaShipment', 'Update:CsaShipment', 'Delete:CsaShipment'];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        $role = Role::firstOrCreate(['name' => Utils::getSuperAdminName(), 'guard_name' => 'web']);
        $role->givePermissionTo($permissions);

        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }
}
