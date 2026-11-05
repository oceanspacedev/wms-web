<?php

namespace Tests\Feature;

use App\Filament\Resources\Depos\Pages\ManageDepos;
use App\Filament\Resources\TrackingOrders\Pages\ListTrackingOrders;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Models\Depo;
use App\Models\TrackingOrder;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class DepoMasterTest extends TestCase
{
    use RefreshDatabase;

    public function test_depo_is_added_and_edited_from_the_master_and_shown_on_users_and_surat_jalan(): void
    {
        $this->actingAs($this->admin());

        // Kode & nama disimpan huruf besar
        Livewire::test(ManageDepos::class)
            ->callAction('create', data: ['kode' => ' gmjog ', 'nama' => 'Jogja'])
            ->assertHasNoFormErrors();

        $jogja = Depo::query()->sole();
        $this->assertSame(['GMJOG', 'JOGJA'], [$jogja->kode, $jogja->nama]);

        // Kode gudang tidak boleh dobel
        Livewire::test(ManageDepos::class)
            ->callAction('create', data: ['kode' => 'GMJOG', 'nama' => 'Jogja 2'])
            ->assertHasFormErrors(['kode' => 'unique']);

        $courier = User::factory()->create(['name' => 'Lulut', 'depo_id' => $jogja->id]);
        $order = TrackingOrder::create(['no_sj' => 'SJ-JOG-1', 'nama_dealer' => 'Toko Jogja', 'user_id' => $courier->id]);

        // Nama diedit di master: akun & SJ langsung ikut nama baru
        Livewire::test(ManageDepos::class)
            ->callAction(TestAction::make('edit')->table($jogja), data: ['kode' => 'GMJOG', 'nama' => 'Yogyakarta'])
            ->assertHasNoFormErrors();

        Livewire::test(ListUsers::class)
            ->assertTableColumnStateSet('depo.nama', 'YOGYAKARTA', $courier);
        Livewire::test(ListTrackingOrders::class)
            ->assertTableColumnStateSet('depo.nama', 'YOGYAKARTA', $order);
    }

    public function test_depo_still_used_by_an_account_or_surat_jalan_cannot_be_deleted(): void
    {
        $this->actingAs($this->admin());
        $used = Depo::factory()->create();
        User::factory()->create(['depo_id' => $used->id]);
        $unused = Depo::factory()->create();

        Livewire::test(ManageDepos::class)
            ->assertTableColumnStateSet('users_count', 1, $used)
            ->assertActionHidden(TestAction::make('delete')->table($used))
            ->callAction(TestAction::make('delete')->table($unused));

        $this->assertModelExists($used);
        $this->assertModelMissing($unused);
        // Dicegah juga di model, bukan hanya tombolnya disembunyikan
        $this->assertFalse($used->delete());
    }

    private function admin(): User
    {
        $role = Role::findOrCreate('super_admin', 'web');

        foreach (['ViewAny:Depo', 'View:Depo', 'Create:Depo', 'Update:Depo', 'Delete:Depo', 'ViewAny:User', 'ViewAny:TrackingOrder'] as $permission) {
            $role->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }

        $admin = User::factory()->create(['name' => 'Admin']);
        $admin->assignRole($role);

        return $admin;
    }
}
