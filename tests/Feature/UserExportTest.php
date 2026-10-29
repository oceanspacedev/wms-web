<?php

namespace Tests\Feature;

use App\Filament\Resources\Users\Pages\ListUsers;
use App\Models\User;
use App\Services\UserExporter;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use OpenSpout\Reader\XLSX\Reader;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class UserExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_export_writes_the_user_columns_without_password(): void
    {
        $user = User::factory()->withUsername('kurir_satu')->withVerifiedWhatsApp('6281200000001')->create([
            'name' => 'Kurir Satu',
            'email' => 'kurir.satu@example.com',
            'kode_gudang_arsip' => 'GDG-BDG-01',
            'cabang' => 'CIREBON',
            'created_at' => Carbon::parse('2026-10-01 08:30:00'),
        ]);
        $user->assignRole(Role::findOrCreate('gudang', 'web'), Role::findOrCreate('panel_user', 'web'));

        $path = tempnam(sys_get_temp_dir(), 'export').'.xlsx';
        app(UserExporter::class)->write(User::query()->orderBy('id'), $path);
        $rows = $this->readRows($path);
        @unlink($path);

        $this->assertSame(UserExporter::HEADERS, $rows[0]);
        $this->assertSame(['Kurir Satu', 'kurir_satu', 'kurir.satu@example.com'], array_slice($rows[1], 0, 3));
        // Nomor WhatsApp tetap teks, bukan angka
        $this->assertSame('6281200000001', $rows[1][3]);
        $this->assertSame('gudang, panel_user', $rows[1][4]);
        $this->assertSame(['GDG-BDG-01', 'CIREBON'], array_slice($rows[1], 5, 2));
        $this->assertEquals(new DateTimeImmutable('2026-10-01 08:30:00'), $rows[1][7]);
        $this->assertNotContains($user->password, $rows[1]);
    }

    public function test_export_action_downloads_the_filtered_users(): void
    {
        $admin = $this->userAdmin();
        $this->actingAs($admin);
        $courierRole = Role::findOrCreate('gudang', 'web');
        User::factory()->create(['name' => 'Kurir Gudang'])->assignRole($courierRole);
        User::factory()->create(['name' => 'Staff Lain']);

        $component = Livewire::test(ListUsers::class)
            ->filterTable('roles', $courierRole->id)
            ->callAction('export')
            ->assertFileDownloaded('Daftar Pengguna '.now()->format('Y-m-d').'.xlsx');

        $path = tempnam(sys_get_temp_dir(), 'export').'.xlsx';
        file_put_contents($path, base64_decode($component->effects['download']['content']));
        $rows = $this->readRows($path);
        @unlink($path);

        $this->assertSame(['Kurir Gudang'], array_column(array_slice($rows, 1), 0));
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

    private function userAdmin(): User
    {
        $role = Role::findOrCreate('super_admin', 'web');
        $role->givePermissionTo(Permission::findOrCreate('ViewAny:User', 'web'));

        $admin = User::factory()->create(['name' => 'Admin']);
        $admin->assignRole($role);

        return $admin;
    }
}
