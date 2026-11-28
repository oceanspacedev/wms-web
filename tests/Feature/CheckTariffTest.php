<?php

namespace Tests\Feature;

use App\Filament\Pages\CheckTariff;
use App\Models\Expedition;
use App\Models\ExpeditionRateCard;
use App\Models\TariffSearchHistory;
use App\Models\User;
use App\Services\FreightRateGoogleSheetService;
use BezhanSalleh\FilamentShield\Support\Utils;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CheckTariffTest extends TestCase
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

        $this->user = User::factory()->create();
        $this->user->assignRole($role);
        $this->grantCheckTariffAccess($this->user);
    }

    public function test_guest_is_redirected_from_check_tariff_page(): void
    {
        $response = $this->get('/admin/check-tariff');

        $response->assertRedirect('/admin/login');
    }

    public function test_authenticated_user_can_access_check_tariff_page(): void
    {
        $response = $this->actingAs($this->user)->get('/admin/check-tariff');

        $response->assertStatus(200);
        $response->assertSee('Cek Tarif Ekspedisi');
    }

    public function test_panel_user_without_check_tariff_permission_is_forbidden(): void
    {
        $user = $this->panelUser();

        $response = $this->actingAs($user)->get('/admin/check-tariff');

        $response->assertForbidden();
    }

    public function test_panel_user_with_check_tariff_permission_can_access_the_page(): void
    {
        $user = $this->panelUser();
        $this->grantCheckTariffAccess($user);

        $response = $this->actingAs($user)->get('/admin/check-tariff');

        $response->assertOk();
        $response->assertSee('Cek Tarif Ekspedisi');
    }

    public function test_freight_service_compares_cirebon_to_bandung(): void
    {
        $service = app(FreightRateGoogleSheetService::class);

        $results = $service->compareRates('CIREBON', 'BANDUNG', 5);

        $this->assertNotEmpty($results);
        $this->assertSame('21 EXPRES', $results[0]['expedition']);
        $this->assertSame('DARAT', $results[0]['service']);
        $this->assertEquals(4000, $results[0]['rate_per_kg']);
        $this->assertEquals(20000, $results[0]['total_cost']);
        $this->assertTrue($results[0]['is_recommended']);
    }

    public function test_destination_options_are_strictly_scoped_to_origin(): void
    {
        $service = app(FreightRateGoogleSheetService::class);

        // BANDUNG only has 7 destination cities in the spreadsheet
        $bandungDestinations = $service->getDestinationOptions('BANDUNG');
        $this->assertCount(7, $bandungDestinations);
        $this->assertArrayHasKey('BOGOR', $bandungDestinations);
        $this->assertArrayHasKey('JAKARTA', $bandungDestinations);
        $this->assertArrayHasKey('MADIUN', $bandungDestinations);
        $this->assertArrayHasKey('PURWOKERTO', $bandungDestinations);
        $this->assertArrayHasKey('SEMARANG', $bandungDestinations);
        $this->assertArrayHasKey('SIDOARJO', $bandungDestinations);
        $this->assertArrayHasKey('SURABAYA', $bandungDestinations);
        $this->assertArrayNotHasKey('MEDAN', $bandungDestinations);

        // CIREBON has 26 destination cities
        $cirebonDestinations = $service->getDestinationOptions('CIREBON');
        $this->assertCount(26, $cirebonDestinations);
        $this->assertArrayHasKey('BANDUNG', $cirebonDestinations);
        $this->assertArrayNotHasKey('MEDAN', $cirebonDestinations);
    }

    public function test_freight_service_provides_up_to_5_recommendations_sorted_by_lowest_price(): void
    {
        $service = app(FreightRateGoogleSheetService::class);

        // Route with 5 distinct expeditions (JAKARTA -> MEDAN)
        $results = $service->compareRates('JAKARTA', 'MEDAN', 10);

        $this->assertCount(5, $results);
        $this->assertTrue($results[0]['is_recommended']);
        $this->assertSame('Rekomendasi 1 (Termurah)', $results[0]['recommendation_label']);
        $this->assertSame('Rekomendasi 2', $results[1]['recommendation_label']);
        $this->assertSame('Rekomendasi 3', $results[2]['recommendation_label']);
        $this->assertSame('Rekomendasi 4', $results[3]['recommendation_label']);
        $this->assertSame('Rekomendasi 5', $results[4]['recommendation_label']);

        // Verify sorted asc
        for ($i = 0; $i < count($results) - 1; $i++) {
            $this->assertLessThanOrEqual($results[$i + 1]['total_cost'], $results[$i]['total_cost']);
        }

        // Route with 3 options (JAKARTA -> BANDUNG)
        $bandungResults = $service->compareRates('JAKARTA', 'BANDUNG', 5);
        $this->assertCount(3, $bandungResults);
        $this->assertSame('Rekomendasi 1 (Termurah)', $bandungResults[0]['recommendation_label']);
    }

    public function test_livewire_check_tariff_component_renders_empty_initially(): void
    {
        Livewire::actingAs($this->user)
            ->test(CheckTariff::class)
            ->assertSet('data.origin', [])
            ->assertSet('data.destination', [])
            ->assertSet('data.weight', null)
            ->assertSet('hasSearched', false)
            ->assertSet('showHistory', false)
            ->assertSee('History')
            ->assertDontSee('Hasil Cek Tarif')
            ->assertDontSee('Riwayat Pencarian Tarif');
    }

    public function test_livewire_toggle_history_displays_history_table(): void
    {
        Livewire::actingAs($this->user)
            ->test(CheckTariff::class)
            ->assertSet('showHistory', false)
            ->assertDontSee('Riwayat Pencarian Tarif')
            ->call('toggleHistory')
            ->assertSet('showHistory', true)
            ->assertSee('Riwayat Pencarian Tarif')
            ->call('toggleHistory')
            ->assertSet('showHistory', false)
            ->assertDontSee('Riwayat Pencarian Tarif');
    }

    public function test_livewire_check_tariff_form_submission_with_different_cities(): void
    {
        Livewire::actingAs($this->user)
            ->test(CheckTariff::class)
            ->set('data.origin', ['JAKARTA'])
            ->set('data.destination', ['BANDUNG'])
            ->set('data.weight', 10)
            ->set('data.service', 'DARAT')
            ->call('checkTariff')
            ->assertSee('J&T CARGO')
            ->assertSee('DARAT')
            ->assertSee('Termurah');

        $this->assertDatabaseHas('tariff_search_histories', [
            'origin' => 'JAKARTA',
            'destination' => 'BANDUNG',
            'weight' => 10,
            'service' => 'DARAT',
            'user_id' => $this->user->id,
        ]);
    }

    public function test_livewire_check_tariff_shows_empty_state_when_no_rates_found(): void
    {
        Livewire::actingAs($this->user)
            ->test(CheckTariff::class)
            ->set('data.origin', ['CIREBON'])
            ->set('data.destination', ['BANDUNG'])
            ->set('data.weight', 5)
            ->set('data.service', 'UDARA')
            ->call('checkTariff')
            ->assertSee('Belum Tersedia');

        $this->assertDatabaseHas('tariff_search_histories', [
            'origin' => 'CIREBON',
            'destination' => 'BANDUNG',
            'weight' => 5,
            'service' => 'UDARA',
            'total_results' => 0,
        ]);
    }

    public function test_livewire_reset_search_clears_form_and_results(): void
    {
        Livewire::actingAs($this->user)
            ->test(CheckTariff::class)
            ->set('data.origin', ['JAKARTA'])
            ->set('data.destination', ['SURABAYA'])
            ->set('data.weight', 20)
            ->call('checkTariff')
            ->assertSet('hasSearched', true)
            ->call('resetSearch')
            ->assertSet('data.origin', [])
            ->assertSet('data.destination', [])
            ->assertSet('data.weight', null)
            ->assertSet('hasSearched', false)
            ->assertDontSee('Hasil Cek Tarif');
    }

    public function test_livewire_apply_history_loads_route_and_searches(): void
    {
        $history = TariffSearchHistory::create([
            'user_id' => $this->user->id,
            'origin' => 'CIREBON',
            'destination' => 'BANDUNG',
            'weight' => 5,
            'service' => 'DARAT',
            'total_results' => 1,
            'cheapest_expedition' => '21 EXPRES',
            'cheapest_cost' => 20000,
        ]);

        Livewire::actingAs($this->user)
            ->test(CheckTariff::class)
            ->call('applyHistory', $history->id)
            ->assertSet('data.origin', ['CIREBON'])
            ->assertSet('data.destination', ['BANDUNG'])
            ->assertSet('data.weight', 5)
            ->assertSet('hasSearched', true)
            ->assertSee('21 EXPRES')
            ->assertSee('20.000');
    }

    public function test_livewire_delete_history_item_and_clear_all(): void
    {
        $item1 = TariffSearchHistory::create([
            'user_id' => $this->user->id,
            'origin' => 'CIREBON',
            'destination' => 'BANDUNG',
            'weight' => 5,
            'service' => '',
            'total_results' => 1,
        ]);

        $item2 = TariffSearchHistory::create([
            'user_id' => $this->user->id,
            'origin' => 'JAKARTA',
            'destination' => 'SURABAYA',
            'weight' => 10,
            'service' => '',
            'total_results' => 5,
        ]);

        Livewire::actingAs($this->user)
            ->test(CheckTariff::class)
            ->call('deleteHistoryItem', $item1->id);

        $this->assertDatabaseMissing('tariff_search_histories', ['id' => $item1->id]);
        $this->assertDatabaseHas('tariff_search_histories', ['id' => $item2->id]);

        Livewire::actingAs($this->user)
            ->test(CheckTariff::class)
            ->call('clearAllHistory');

        $this->assertDatabaseCount('tariff_search_histories', 0);
    }

    public function test_load_screenshot_example_populates_exact_data_and_rates_matching_image(): void
    {
        Livewire::actingAs($this->user)
            ->test(CheckTariff::class)
            ->call('loadScreenshotExample')
            ->assertSet('data.origin', ['MANADO'])
            ->assertSet('data.destination', ['PALEMBANG'])
            ->assertSet('data.weight', 26)
            ->assertSet('data.total_amount', 59040000)
            ->assertSet('hasSearched', true)
            ->assertSee('Hasil Cek Tarif')
            ->assertSee('59.040.000')
            ->assertSee('21 EXPRESS')
            ->assertSee('480.000')
            ->assertSee('0,8%')
            ->assertSee('SENTRAL')
            ->assertSee('612.080')
            ->assertSee('1,0%')
            ->assertSee('JNT CARGO')
            ->assertSee('1.504.500')
            ->assertSee('2,5%');

        $this->assertDatabaseHas('tariff_search_histories', [
            'origin' => 'MANADO',
            'destination' => 'PALEMBANG',
            'weight' => 26,
            'total_amount' => 59040000,
            'cheapest_cost' => 480000,
        ]);
    }

    public function test_jakarta_to_bandung_filters_unrealistic_air_service(): void
    {
        $test = Livewire::actingAs($this->user)
            ->test(CheckTariff::class)
            ->set('data.origin', ['JAKARTA'])
            ->set('data.destination', ['BANDUNG'])
            ->set('data.weight', 26)
            ->set('data.service', '')
            ->call('checkTariff')
            ->assertSet('hasSearched', true)
            ->assertSee('DARAT')
            ->assertDontSee('font-mono');

        $rates = $test->get('rates');
        $this->assertNotEmpty($rates);
        foreach ($rates as $r) {
            $this->assertNotSame('UDARA', $r['service']);
        }
    }

    public function test_per_kg_rate_with_zero_min_kg_is_multiplied_by_weight_and_doc_is_excluded_for_cargo(): void
    {
        $exp21 = Expedition::create(['name' => '21 EXPRES', 'code' => '21EXP', 'is_active' => true]);
        $rax = Expedition::create(['name' => 'RAX', 'code' => 'RAX', 'is_active' => true]);
        $karya = Expedition::create(['name' => 'KARYA MANDIRI', 'code' => 'KARYA', 'is_active' => true]);

        // 21 EXPRES: per_kg dengan min_kg = 0 harus tetap dikali 10 KG (= 110.000), bukan flat 11.000
        ExpeditionRateCard::create([
            'expedition_id' => $exp21->id,
            'origin_depo' => 'CIREBON',
            'destination_city' => 'JAKARTA',
            'service_type' => 'DARAT',
            'rate_per_kg' => 11000,
            'min_kg' => 0,
            'pricing_type' => ExpeditionRateCard::PRICING_PER_KG,
            'insurance_rate_percent' => 0,
            'sla_days' => '1',
            'is_active' => true,
        ]);

        // 21 EXPRES: tarif DOC tidak boleh muncul untuk pengiriman kargo 10 KG
        ExpeditionRateCard::create([
            'expedition_id' => $exp21->id,
            'origin_depo' => 'CIREBON',
            'destination_city' => 'JAKARTA',
            'service_type' => 'DARAT',
            'rate_per_kg' => 9000,
            'min_kg' => 0,
            'pricing_type' => ExpeditionRateCard::PRICING_DOC,
            'insurance_rate_percent' => 0,
            'sla_days' => '1',
            'is_active' => true,
        ]);

        // RAX: 2.000/kg × 10 KG = 20.000 (seharusnya Termurah)
        ExpeditionRateCard::create([
            'expedition_id' => $rax->id,
            'origin_depo' => 'CIREBON',
            'destination_city' => 'JAKARTA',
            'service_type' => 'DARAT',
            'rate_per_kg' => 2000,
            'min_kg' => 3,
            'pricing_type' => ExpeditionRateCard::PRICING_PER_KG,
            'insurance_rate_percent' => 0,
            'sla_days' => '1-2',
            'is_active' => true,
        ]);

        // KARYA MANDIRI: per koli flat 150.000
        ExpeditionRateCard::create([
            'expedition_id' => $karya->id,
            'origin_depo' => 'CIREBON',
            'destination_city' => 'JAKARTA',
            'service_type' => 'DARAT',
            'rate_per_kg' => 150000,
            'min_kg' => 0,
            'pricing_type' => ExpeditionRateCard::PRICING_PER_COLLY,
            'insurance_rate_percent' => 0,
            'sla_days' => '1',
            'is_active' => true,
        ]);

        $component = Livewire::actingAs($this->user)
            ->test(CheckTariff::class)
            ->set('data.origin', ['CIREBON'])
            ->set('data.destination', ['JAKARTA'])
            ->set('data.weight', 10)
            ->set('data.service', '')
            ->call('checkTariff')
            ->assertSet('hasSearched', true)
            ->assertSee('/Koli');

        $rates = $component->get('rates');
        $this->assertCount(3, $rates);
        $this->assertSame('RAX', $rates[0]['expedition']);
        $this->assertEquals(20000.0, $rates[0]['total_cost']);
        $this->assertSame('21 EXPRES', $rates[1]['expedition']);
        $this->assertEquals(110000.0, $rates[1]['total_cost']);
        $this->assertSame('KARYA MANDIRI', $rates[2]['expedition']);
        $this->assertEquals(150000.0, $rates[2]['total_cost']);
        $this->assertTrue($rates[2]['is_flat']);
    }

    public function test_check_tariff_supports_district_lookup_with_city_level_fallback(): void
    {
        $sentral = Expedition::create(['name' => 'SENTRAL CARGO', 'code' => 'SNT', 'is_active' => true]);
        $express21 = Expedition::create(['name' => '21 EXPRES', 'code' => '21EXP', 'is_active' => true]);

        // SENTRAL CARGO punya tarif khusus per kecamatan: ANDIR lebih murah (3.000) daripada SOREANG (5.500)
        ExpeditionRateCard::create([
            'expedition_id' => $sentral->id,
            'origin_depo' => 'JAKARTA',
            'destination_city' => 'BANDUNG',
            'destination_district' => 'ANDIR',
            'service_type' => 'DARAT',
            'rate_per_kg' => 3000,
            'min_kg' => 1,
            'insurance_rate_percent' => 0,
            'sla_days' => '2',
            'is_active' => true,
        ]);

        ExpeditionRateCard::create([
            'expedition_id' => $sentral->id,
            'origin_depo' => 'JAKARTA',
            'destination_city' => 'BANDUNG',
            'destination_district' => 'SOREANG',
            'service_type' => 'DARAT',
            'rate_per_kg' => 5500,
            'min_kg' => 1,
            'insurance_rate_percent' => 0,
            'sla_days' => '2-3',
            'is_active' => true,
        ]);

        // 21 EXPRES hanya punya tarif level kota BANDUNG (tanpa rincian kecamatan)
        ExpeditionRateCard::create([
            'expedition_id' => $express21->id,
            'origin_depo' => 'JAKARTA',
            'destination_city' => 'BANDUNG',
            'destination_district' => null,
            'service_type' => 'DARAT',
            'rate_per_kg' => 4500,
            'min_kg' => 1,
            'insurance_rate_percent' => 0,
            'sla_days' => '1-2',
            'is_active' => true,
        ]);

        $component = Livewire::actingAs($this->user)
            ->test(CheckTariff::class)
            ->set('data.origin', ['JAKARTA'])
            ->set('data.destination', ['BANDUNG'])
            ->set('data.district', 'SOREANG')
            ->set('data.weight', 10)
            ->set('data.service', 'DARAT')
            ->call('checkTariff')
            ->assertSet('hasSearched', true)
            ->assertSet('searchedDistrict', 'SOREANG')
            ->assertSee('Kec. SOREANG')
            ->assertSee('Tarif Kota');

        $rates = $component->get('rates');
        $this->assertCount(2, $rates);
        $this->assertSame('21 EXPRES', $rates[0]['expedition']);
        $this->assertSame('city', $rates[0]['match_scope']);
        $this->assertEquals(45000.0, $rates[0]['total_cost']);

        // SENTRAL harus mengambil tarif SOREANG (55.000), bukan ANDIR (30.000)
        $this->assertSame('SENTRAL CARGO', $rates[1]['expedition']);
        $this->assertSame('district', $rates[1]['match_scope']);
        $this->assertSame('SOREANG', $rates[1]['matched_district']);
        $this->assertEquals(55000.0, $rates[1]['total_cost']);

        $this->assertDatabaseHas('tariff_search_histories', [
            'origin' => 'JAKARTA',
            'destination' => 'BANDUNG',
            'district' => 'SOREANG',
            'weight' => 10,
        ]);

        // Bisa cari kecamatan langsung tanpa memilih kota tujuan lebih dulu
        $directSelection = Livewire::actingAs($this->user)
            ->test(CheckTariff::class)
            ->set('data.origin', ['JAKARTA']);

        $districtSearchResults = $directSelection->instance()->searchDistrictsForOrigin('JAKARTA', 'sore');
        $this->assertSame(['BANDUNG||SOREANG' => 'SOREANG (Kota: BANDUNG)'], $districtSearchResults);

        $directSelection
            ->set('data.district', 'BANDUNG||SOREANG')
            ->assertSet('data.destination', ['BANDUNG'])
            ->assertSet('data.district', 'SOREANG');
    }

    public function test_check_tariff_supports_selecting_multiple_origin_cities_in_one_comparison(): void
    {
        $sentral = Expedition::create(['name' => 'SENTRAL CARGO', 'code' => 'SNT', 'is_active' => true]);
        $express21 = Expedition::create(['name' => '21 EXPRES', 'code' => '21EXP', 'is_active' => true]);

        // SURABAYA -> DENPASAR lebih murah (3.500/kg = 35.000)
        ExpeditionRateCard::create([
            'expedition_id' => $sentral->id,
            'origin_depo' => 'SURABAYA',
            'destination_city' => 'DENPASAR',
            'service_type' => 'DARAT',
            'rate_per_kg' => 3500,
            'min_kg' => 1,
            'insurance_rate_percent' => 0,
            'sla_days' => '2',
            'is_active' => true,
        ]);

        // SURABAYA -> MATARAM hanya ada dari SURABAYA
        ExpeditionRateCard::create([
            'expedition_id' => $sentral->id,
            'origin_depo' => 'SURABAYA',
            'destination_city' => 'MATARAM',
            'service_type' => 'DARAT',
            'rate_per_kg' => 6000,
            'min_kg' => 1,
            'insurance_rate_percent' => 0,
            'sla_days' => '3',
            'is_active' => true,
        ]);

        // JAKARTA -> DENPASAR lebih mahal (7.000/kg = 70.000)
        ExpeditionRateCard::create([
            'expedition_id' => $express21->id,
            'origin_depo' => 'JAKARTA',
            'destination_city' => 'DENPASAR',
            'service_type' => 'DARAT',
            'rate_per_kg' => 7000,
            'min_kg' => 1,
            'insurance_rate_percent' => 0,
            'sla_days' => '3-4',
            'is_active' => true,
        ]);

        // JAKARTA -> MAKASSAR hanya ada dari JAKARTA
        ExpeditionRateCard::create([
            'expedition_id' => $express21->id,
            'origin_depo' => 'JAKARTA',
            'destination_city' => 'MAKASSAR',
            'service_type' => 'DARAT',
            'rate_per_kg' => 9000,
            'min_kg' => 1,
            'insurance_rate_percent' => 0,
            'sla_days' => '4',
            'is_active' => true,
        ]);

        $component = Livewire::actingAs($this->user)
            ->test(CheckTariff::class)
            ->set('data.origin', ['JAKARTA', 'SURABAYA']);

        // Daftar tujuan adalah gabungan dari semua kota asal yang dipilih
        $destinations = $component->instance()->getDestinationOptionsForOrigins(['JAKARTA', 'SURABAYA']);
        $this->assertArrayHasKey('DENPASAR', $destinations);
        $this->assertArrayHasKey('MATARAM', $destinations);
        $this->assertArrayHasKey('MAKASSAR', $destinations);

        $component
            ->set('data.destination', ['DENPASAR'])
            ->set('data.weight', 10)
            ->set('data.service', 'DARAT')
            ->call('checkTariff')
            ->assertSet('hasSearched', true)
            ->assertSet('searchedOrigins', ['JAKARTA', 'SURABAYA'])
            ->assertSet('searchedDestinations', ['DENPASAR'])
            ->assertSee('Kota Asal')
            ->assertSee('SURABAYA')
            ->assertSee('JAKARTA')
            ->assertSee('SENTRAL CARGO')
            ->assertSee('21 EXPRES');

        $rates = $component->get('rates');
        $this->assertCount(2, $rates);
        $this->assertSame('SURABAYA', $rates[0]['origin']);
        $this->assertSame('DENPASAR', $rates[0]['destination']);
        $this->assertSame('SENTRAL CARGO', $rates[0]['expedition']);
        $this->assertEquals(35000.0, $rates[0]['total_cost']);
        $this->assertSame('JAKARTA', $rates[1]['origin']);
        $this->assertSame('DENPASAR', $rates[1]['destination']);
        $this->assertSame('21 EXPRES', $rates[1]['expedition']);
        $this->assertEquals(70000.0, $rates[1]['total_cost']);

        $history = TariffSearchHistory::query()->latest('id')->first();
        $this->assertNotNull($history);
        $this->assertSame('JAKARTA, SURABAYA', $history->origin);
        $this->assertSame('DENPASAR', $history->destination);

        // Buka kembali riwayat multi-origin harus memulihkan semua kota asal
        Livewire::actingAs($this->user)
            ->test(CheckTariff::class)
            ->call('applyHistory', $history->id)
            ->assertSet('data.origin', ['SURABAYA', 'JAKARTA'])
            ->assertSet('data.destination', ['DENPASAR'])
            ->assertSet('searchedOrigins', ['SURABAYA', 'JAKARTA'])
            ->assertSet('searchedDestinations', ['DENPASAR'])
            ->assertSee('Kota Asal')
            ->assertSee('SURABAYA')
            ->assertSee('JAKARTA');
    }

    public function test_check_tariff_supports_selecting_multiple_destination_cities_and_origins(): void
    {
        $sentral = Expedition::create(['name' => 'SENTRAL CARGO', 'code' => 'SNT', 'is_active' => true]);
        $express21 = Expedition::create(['name' => '21 EXPRES', 'code' => '21EXP', 'is_active' => true]);

        // JAKARTA -> MATARAM (2.500/kg = 25.000) dengan kecamatan AMPENAN
        ExpeditionRateCard::create([
            'expedition_id' => $sentral->id,
            'origin_depo' => 'JAKARTA',
            'destination_city' => 'MATARAM',
            'destination_district' => 'AMPENAN',
            'service_type' => 'DARAT',
            'rate_per_kg' => 2500,
            'min_kg' => 1,
            'insurance_rate_percent' => 0,
            'sla_days' => '2',
            'is_active' => true,
        ]);

        // JAKARTA -> KUPANG (4.000/kg = 40.000) dengan kecamatan OEBOBO
        ExpeditionRateCard::create([
            'expedition_id' => $express21->id,
            'origin_depo' => 'JAKARTA',
            'destination_city' => 'KUPANG',
            'destination_district' => 'OEBOBO',
            'service_type' => 'DARAT',
            'rate_per_kg' => 4000,
            'min_kg' => 1,
            'insurance_rate_percent' => 0,
            'sla_days' => '2-3',
            'is_active' => true,
        ]);

        // BANDUNG -> KUPANG (3.000/kg = 30.000)
        ExpeditionRateCard::create([
            'expedition_id' => $sentral->id,
            'origin_depo' => 'BANDUNG',
            'destination_city' => 'KUPANG',
            'service_type' => 'DARAT',
            'rate_per_kg' => 3000,
            'min_kg' => 1,
            'insurance_rate_percent' => 0,
            'sla_days' => '2',
            'is_active' => true,
        ]);

        // 1. Satu kota asal -> beberapa kota tujuan
        $component = Livewire::actingAs($this->user)
            ->test(CheckTariff::class)
            ->set('data.origin', ['JAKARTA'])
            ->set('data.destination', ['MATARAM', 'KUPANG']);

        $districts = $component->instance()->getDistrictOptionsForRoutes(['JAKARTA'], ['MATARAM', 'KUPANG']);
        $this->assertArrayHasKey('AMPENAN', $districts);
        $this->assertArrayHasKey('OEBOBO', $districts);

        $component
            ->set('data.weight', 10)
            ->set('data.service', 'DARAT')
            ->call('checkTariff')
            ->assertSet('hasSearched', true)
            ->assertSet('searchedOrigins', ['JAKARTA'])
            ->assertSet('searchedDestinations', ['MATARAM', 'KUPANG'])
            ->assertSee('Kota Tujuan')
            ->assertSee('MATARAM')
            ->assertSee('KUPANG');

        $rates = $component->get('rates');
        $this->assertCount(2, $rates);
        $this->assertSame('MATARAM', $rates[0]['destination']);
        $this->assertEquals(25000.0, $rates[0]['total_cost']);
        $this->assertSame('KUPANG', $rates[1]['destination']);
        $this->assertEquals(40000.0, $rates[1]['total_cost']);

        $singleOriginHistory = TariffSearchHistory::query()->latest('id')->first();
        $this->assertNotNull($singleOriginHistory);
        $this->assertSame('JAKARTA', $singleOriginHistory->origin);
        $this->assertSame('MATARAM, KUPANG', $singleOriginHistory->destination);

        // Buka kembali riwayat multi-destination memulihkan semua kota tujuan
        Livewire::actingAs($this->user)
            ->test(CheckTariff::class)
            ->call('applyHistory', $singleOriginHistory->id)
            ->assertSet('data.origin', ['JAKARTA'])
            ->assertSet('data.destination', ['MATARAM', 'KUPANG'])
            ->assertSet('searchedDestinations', ['MATARAM', 'KUPANG'])
            ->assertSee('Kota Tujuan')
            ->assertSee('MATARAM')
            ->assertSee('KUPANG');

        // 2. Beberapa kota asal x beberapa kota tujuan sekaligus
        $multiBoth = Livewire::actingAs($this->user)
            ->test(CheckTariff::class)
            ->set('data.origin', ['JAKARTA', 'BANDUNG'])
            ->set('data.destination', ['MATARAM', 'KUPANG'])
            ->set('data.weight', 10)
            ->set('data.service', 'DARAT')
            ->call('checkTariff')
            ->assertSet('hasSearched', true)
            ->assertSet('searchedOrigins', ['JAKARTA', 'BANDUNG'])
            ->assertSet('searchedDestinations', ['MATARAM', 'KUPANG'])
            ->assertSee('Kota Asal')
            ->assertSee('Kota Tujuan');

        $bothRates = $multiBoth->get('rates');
        $this->assertCount(3, $bothRates);
        $this->assertSame('JAKARTA', $bothRates[0]['origin']);
        $this->assertSame('MATARAM', $bothRates[0]['destination']);
        $this->assertEquals(25000.0, $bothRates[0]['total_cost']);

        $this->assertSame('BANDUNG', $bothRates[1]['origin']);
        $this->assertSame('KUPANG', $bothRates[1]['destination']);
        $this->assertEquals(30000.0, $bothRates[1]['total_cost']);

        $this->assertSame('JAKARTA', $bothRates[2]['origin']);
        $this->assertSame('KUPANG', $bothRates[2]['destination']);
        $this->assertEquals(40000.0, $bothRates[2]['total_cost']);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function panelUser(array $attributes = []): User
    {
        $role = Role::findOrCreate('panel_user', 'web');
        $user = User::factory()->create($attributes);
        $user->assignRole($role);

        return $user;
    }

    private function grantCheckTariffAccess(User $user): void
    {
        $permission = Permission::findOrCreate('View:CheckTariff', 'web');
        $user->givePermissionTo($permission);
    }
}
