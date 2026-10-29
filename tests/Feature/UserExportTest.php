<?php

namespace Tests\Feature;

use App\Filament\Exports\UserExporter;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Models\User;
use Filament\Actions\Exports\Models\Export;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use OpenSpout\Reader\XLSX\Reader;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class UserExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_export_writes_the_filtered_users_without_password(): void
    {
        Storage::fake('local');
        $this->actingAs($this->userAdmin(['ViewAny:User']));
        $courierRole = Role::findOrCreate('gudang', 'web');
        $courier = User::factory()->withUsername('kurir_satu')->withVerifiedWhatsApp('6281200000001')->create([
            'name' => 'Kurir Satu',
            'email' => 'kurir.satu@example.com',
            'kode_gudang_arsip' => 'GDG-BDG-01',
            'cabang' => 'CIREBON',
            'created_at' => Carbon::parse('2026-10-01 08:30:00'),
        ]);
        $courier->assignRole($courierRole, Role::findOrCreate('panel_user', 'web'));
        User::factory()->create(['name' => 'Staff Lain']);

        Livewire::test(ListUsers::class)
            ->filterTable('roles', $courierRole->id)
            ->callAction('export')
            ->assertHasNoFormErrors();

        $export = Export::query()->sole();
        $this->assertSame(UserExporter::class, $export->exporter);
        $this->assertSame('local', $export->file_disk);
        $this->assertSame(1, $export->successful_rows);

        $rows = $this->readRows(Storage::disk('local')->path($export->getFileDirectory().'/'.$export->file_name.'.xlsx'));

        $this->assertSame(['Nama', 'Username', 'Email', 'WhatsApp', 'Role', 'Kode Gudang Arsip', 'Cabang', 'Tanggal Dibuat'], $rows[0]);
        // Hanya user hasil filter role, dan nomor WhatsApp tetap teks
        $this->assertSame([
            ['Kurir Satu', 'kurir_satu', 'kurir.satu@example.com', '6281200000001', 'gudang, panel_user', 'GDG-BDG-01', 'CIREBON', '01/10/2026 08:30'],
        ], array_slice($rows, 1));
    }

    public function test_export_is_available_without_create_permission(): void
    {
        $this->actingAs($this->userAdmin(['ViewAny:User'], role: 'admin_viewer'));

        Livewire::test(ListUsers::class)
            ->assertActionVisible('export')
            ->assertActionHidden('importTalentaCouriers');
    }

    /**
     * @return list<list<mixed>>
     */
    private function readRows(string $path): array
    {
        $rows = [];
        $reader = new Reader;
        $reader->open($path);
        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $rows[] = $row->toArray();
            }
        }
        $reader->close();

        return $rows;
    }

    /**
     * @param  list<string>  $permissions
     */
    private function userAdmin(array $permissions, string $role = 'super_admin'): User
    {
        $adminRole = Role::findOrCreate($role, 'web');

        foreach ($permissions as $permissionName) {
            $adminRole->givePermissionTo(Permission::findOrCreate($permissionName, 'web'));
        }

        $admin = User::factory()->create(['name' => 'Admin']);
        $admin->assignRole($adminRole);

        return $admin;
    }
}
