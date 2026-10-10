<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Resources\CsaImports\Pages\ListCsaImports;
use App\Jobs\ProcessCsaImportJob;
use App\Jobs\SyncToGoogleSheetJob;
use App\Models\User;
use BezhanSalleh\FilamentShield\Support\Utils;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CsaImportModalTest extends TestCase
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
            'name' => 'ViewAny:CsaImport',
            'guard_name' => 'web',
        ]);
        Permission::firstOrCreate([
            'name' => 'Create:CsaImport',
            'guard_name' => 'web',
        ]);
        $role->givePermissionTo(['ViewAny:CsaImport', 'Create:CsaImport']);

        $this->user = User::factory()->create();
        $this->user->assignRole($role);
    }

    public function test_create_action_opens_modal_without_page_url(): void
    {
        $this->actingAs($this->user);

        Livewire::test(ListCsaImports::class)
            ->assertActionVisible('create')
            ->mountAction('create')
            ->assertActionMounted('create');
    }

    public function test_can_create_csa_import_via_modal_action(): void
    {
        Queue::fake();
        Storage::fake('local');

        $this->actingAs($this->user);

        $fakeFile = UploadedFile::fake()->create('sales_september_2026.xlsx', 100, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        Livewire::test(ListCsaImports::class)
            ->callAction('create', [
                'excel_file' => $fakeFile,
                'auto_sync' => true,
            ])
            ->assertHasNoActionErrors()
            ->assertNotified('File berhasil diunggah');

        $this->assertDatabaseHas('csa_imports', [
            'user_id' => $this->user->id,
            'status' => 'pending',
        ]);

        Queue::assertPushed(ProcessCsaImportJob::class, function (ProcessCsaImportJob $job): bool {
            return $job->autoSyncToSheet === true;
        });

        // Upload tidak lagi memproses/menyinkronkan di dalam request
        Queue::assertNotPushed(SyncToGoogleSheetJob::class);
    }
}
