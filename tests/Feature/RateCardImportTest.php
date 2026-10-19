<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Pages\CheckTariff;
use App\Filament\Resources\ExpeditionRateCards\Pages\ManageExpeditionRateCards;
use App\Jobs\ImportRateCardsJob;
use App\Models\Expedition;
use App\Models\ExpeditionRateCard;
use App\Models\User;
use App\Services\RateCardImportService;
use BezhanSalleh\FilamentShield\Support\Utils;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class RateCardImportTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $role = Role::firstOrCreate([
            'name' => Utils::getSuperAdminName(),
            'guard_name' => 'web',
        ]);

        Permission::firstOrCreate([
            'name' => 'ViewAny:ExpeditionRateCard',
            'guard_name' => 'web',
        ]);
        Permission::firstOrCreate([
            'name' => 'View:CheckTariff',
            'guard_name' => 'web',
        ]);
        $role->givePermissionTo(['ViewAny:ExpeditionRateCard', 'View:CheckTariff']);

        $this->user = User::factory()->create();
        $this->user->assignRole($role);
    }

    public function test_can_import_rate_cards_from_csv(): void
    {
        $csvContent = implode("\n", [
            'Expedisi,Origin,Provinsi,Kota Tujuan/District,Kecamatan Tujuan/District,/Kg, Price,Lead Time, Service, Kode, KET',
            'J&T CARGO,JAKARTA,JAWA BARAT,BANDUNG,BANDUNG,10 KG," Rp 2.500 ",1-2 HARI,DARAT,,PKS 2026',
            '21 EXPRES,JAKARTA,SUMATERA,LAMPUNG,BANDAR LAMPUNG,10 KG," Rp 2.700 ",1-2 HARI,DARAT,,PKS 2026',
            'SENTRAL CARGO,JAKARTA,JAWA TIMUR,SURABAYA,SURABAYA,5 KG," Rp 4.000 ",2-3 HARI,DARAT,,PKS 2026',
        ]);

        $tempFile = tempnam(sys_get_temp_dir(), 'test_rate_').'.csv';
        file_put_contents($tempFile, $csvContent);

        $service = app(RateCardImportService::class);
        $result = $service->importFile($tempFile, 'upsert', 0.2);

        @unlink($tempFile);

        $this->assertTrue($result['success']);
        $this->assertEquals(3, $result['total_rows']);
        $this->assertEquals(3, $result['imported_count']);

        $this->assertDatabaseHas('expeditions', [
            'name' => 'J&T CARGO',
        ]);
        $this->assertDatabaseHas('expeditions', [
            'name' => '21 EXPRES',
        ]);
        $this->assertDatabaseHas('expeditions', [
            'name' => 'SENTRAL CARGO',
        ]);

        $this->assertDatabaseHas('expedition_rate_cards', [
            'origin_depo' => 'JAKARTA',
            'destination_city' => 'BANDUNG',
            'service_type' => 'DARAT',
            'rate_per_kg' => 2500,
            'min_kg' => 10,
        ]);
    }

    public function test_check_tariff_prioritizes_imported_expedition_rate_cards(): void
    {
        $expedition = Expedition::create([
            'name' => 'J&T CARGO TEST',
            'code' => 'JNTTEST',
            'is_active' => true,
        ]);

        ExpeditionRateCard::create([
            'expedition_id' => $expedition->id,
            'origin_depo' => 'JAKARTA',
            'destination_city' => 'SEMARANG',
            'destination_district' => 'SEMARANG',
            'service_type' => 'DARAT',
            'rate_per_kg' => 3200,
            'min_kg' => 10,
            'insurance_rate_percent' => 0.2,
            'sla_days' => '2-3',
            'is_active' => true,
        ]);

        Livewire::actingAs($this->user)
            ->test(CheckTariff::class)
            ->set('data.origin', 'JAKARTA')
            ->set('data.destination', 'SEMARANG')
            ->set('data.weight', 20)
            ->set('data.service', 'DARAT')
            ->call('checkTariff')
            ->assertSee('J&T CARGO TEST')
            ->assertSee('DARAT')
            ->assertSee('Termurah');

        $this->assertDatabaseHas('tariff_search_histories', [
            'origin' => 'JAKARTA',
            'destination' => 'SEMARANG',
            'weight' => 20,
        ]);
    }

    public function test_manage_expedition_rate_cards_page_renders_import_action(): void
    {
        $response = $this->actingAs($this->user)->get('/admin/expedition-rate-cards');

        $response->assertStatus(200);
        $response->assertSee('Import Excel / Spreadsheet');
        $response->assertDontSee('Unduh Template CSV');
    }

    public function test_can_execute_import_with_replace_mode(): void
    {
        // 1. Initial rate card
        $expedition = Expedition::create([
            'name' => 'EXPEDISI LAMA',
            'code' => 'EXPLAMA',
            'is_active' => true,
        ]);

        ExpeditionRateCard::create([
            'expedition_id' => $expedition->id,
            'origin_depo' => 'SURABAYA',
            'destination_city' => 'MALANG',
            'service_type' => 'DARAT',
            'rate_per_kg' => 5000,
            'min_kg' => 1,
            'insurance_rate_percent' => 0.2,
            'is_active' => true,
        ]);

        $this->assertEquals(1, ExpeditionRateCard::count());

        // 2. New CSV
        $csvContent = implode("\n", [
            'Expedisi,Origin,Provinsi,Kota Tujuan/District,Kecamatan Tujuan/District,/Kg, Price,Lead Time, Service, Kode, KET',
            'J&T CARGO,JAKARTA,JAWA BARAT,BANDUNG,BANDUNG,10 KG," Rp 2.500 ",1-2 HARI,DARAT,,PKS 2026',
            '21 EXPRES,JAKARTA,SUMATERA,LAMPUNG,BANDAR LAMPUNG,10 KG," Rp 2.700 ",1-2 HARI,DARAT,,PKS 2026',
        ]);

        $tempFile = tempnam(sys_get_temp_dir(), 'test_rate_replace_').'.csv';
        file_put_contents($tempFile, $csvContent);

        $service = app(RateCardImportService::class);
        $result = $service->importFile($tempFile, 'replace', 0.2);

        @unlink($tempFile);

        $this->assertTrue($result['success']);
        $this->assertEquals(2, $result['total_rows']);
        $this->assertEquals(2, ExpeditionRateCard::count());
        $this->assertDatabaseMissing('expedition_rate_cards', [
            'origin_depo' => 'SURABAYA',
            'destination_city' => 'MALANG',
        ]);
    }

    public function test_import_action_runs_in_the_background(): void
    {
        Queue::fake();
        Storage::fake('local');

        Livewire::actingAs($this->user)
            ->test(ManageExpeditionRateCards::class)
            ->callAction('importRateCards', data: [
                'attachment' => UploadedFile::fake()->createWithContent('PRICELIST ALL EKSPEDISI.csv', $this->priceListCsv()),
                'mode' => 'replace',
                'default_insurance_percent' => 0.2,
            ])
            ->assertHasNoFormErrors()
            ->assertNotified('Import tarif sedang diproses');

        // File ±200 ribu baris tidak lagi diproses di dalam request (dulu kena 504 Gateway Time-out)
        $this->assertSame(0, ExpeditionRateCard::count());
        Queue::assertPushed(ImportRateCardsJob::class, fn (ImportRateCardsJob $job): bool => $job->fileName === 'PRICELIST ALL EKSPEDISI.csv'
            && $job->mode === 'replace'
            && $job->userId === $this->user->id
            && Storage::disk('local')->exists($job->path));
    }

    public function test_import_job_saves_prices_as_written_and_notifies_the_uploader(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('rate-card-imports/pricelist.csv', $this->priceListCsv());

        (new ImportRateCardsJob('rate-card-imports/pricelist.csv', 'PRICELIST ALL EKSPEDISI.csv', 'upsert', 0.2, $this->user->id))
            ->handle(app(RateCardImportService::class));

        // Angka mentah "1500.0" dari export Excel tetap Rp1.500, bukan Rp15.000
        $this->assertDatabaseHas('expedition_rate_cards', ['destination_city' => 'BOGOR', 'rate_per_kg' => 1500, 'min_kg' => 10]);
        Storage::disk('local')->assertMissing('rate-card-imports/pricelist.csv');
        $this->assertSame('Import tarif selesai', $this->user->notifications()->sole()->data['title']);
    }

    private function priceListCsv(): string
    {
        return implode("\n", [
            'Expedisi,Origin,Provinsi,Kota Tujuan/District,Kecamatan Tujuan/District,/Kg, Price,Lead Time, Service, Kode, KET',
            '21 EXPRES,JAKARTA,JABODETABEK,BOGOR,BOGOR,10 KG,1500.0,1 HARI,DARAT,,Diambil dari Data Inv Mei',
        ]);
    }
}
