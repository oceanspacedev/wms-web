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
            ->assertSet('data.origin', null)
            ->assertSet('data.destination', null)
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
            ->set('data.origin', 'JAKARTA')
            ->set('data.destination', 'BANDUNG')
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
            ->set('data.origin', 'CIREBON')
            ->set('data.destination', 'BANDUNG')
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
            ->set('data.origin', 'JAKARTA')
            ->set('data.destination', 'SURABAYA')
            ->set('data.weight', 20)
            ->call('checkTariff')
            ->assertSet('hasSearched', true)
            ->call('resetSearch')
            ->assertSet('data.origin', null)
            ->assertSet('data.destination', null)
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
            ->assertSet('data.origin', 'CIREBON')
            ->assertSet('data.destination', 'BANDUNG')
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
            ->assertSet('data.origin', 'MANADO')
            ->assertSet('data.destination', 'PALEMBANG')
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
            ->set('data.origin', 'JAKARTA')
            ->set('data.destination', 'BANDUNG')
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
            ->set('data.origin', 'CIREBON')
            ->set('data.destination', 'JAKARTA')
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
