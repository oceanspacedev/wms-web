<?php

namespace Tests\Feature;

use App\Filament\Resources\Users\Pages\ListUsers;
use App\Jobs\SendUserAccountActivatedWhatsAppJob;
use App\Models\User;
use App\Services\TalentaCourierImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class TalentaCourierImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_upload_creates_accounts_for_warehouse_couriers_only(): void
    {
        Queue::fake();
        Storage::fake('local');
        $this->actingAs($this->userAdmin());
        User::factory()->withVerifiedWhatsApp('6281200000006')->create(['name' => 'Kurir Lama']);

        Livewire::test(ListUsers::class)
            ->callAction('importTalentaCouriers', data: [
                'file' => UploadedFile::fake()->createWithContent('list-employee-gudang.json', $this->talentaJson()),
                'send_whatsapp' => true,
            ])
            ->assertHasNoFormErrors()
            ->assertNotified('3 akun kurir dibuat');

        $courier = User::query()->where('email', 'kurir.satu@example.com')->sole();
        $this->assertSame('Dino Liardi Putra', $courier->name);
        // Username dari email sebelum "@"; titik tidak boleh ada di username
        $this->assertSame('kurir_satu', $courier->username);
        $this->assertSame('6281200000001', $courier->whatsapp_number);
        $this->assertNotNull($courier->whatsapp_verified_at);
        $this->assertSame(['panel_user'], $courier->getRoleNames()->all());

        $this->assertSame('driver', User::query()->where('email', 'driver@example.com')->value('username'));

        // Staff gudang, driver & kurir di luar gudang, akun yang sudah ada, dan data tanpa WhatsApp tidak dibuatkan akun
        $this->assertSame(5, User::count());
        // Satu antrean berisi 3 pesan yang dikirim bergiliran
        $messages = $this->activationMessages();
        $this->assertCount(3, $messages);
        // Password awal yang dikirim ke WhatsApp memang password akun itu, dan mudah diketik ulang
        $courierMessage = collect($messages)->firstWhere('userId', $courier->id);
        $this->assertMatchesRegularExpression('/^[a-z2-9]{10}$/', (string) $courierMessage->password);
        $this->assertTrue(Hash::check((string) $courierMessage->password, $courier->password));
        $this->assertSame([], Storage::disk('local')->allFiles('talenta-imports'));
    }

    public function test_reimporting_the_same_file_only_reports_existing_accounts(): void
    {
        Queue::fake();
        $importer = app(TalentaCourierImporter::class);
        $importer->import($this->talentaJson());

        $result = $importer->import($this->talentaJson());

        $this->assertSame([], $result['created']);
        $this->assertSame(['Dino Liardi Putra', 'Budi', 'Andi Logistik', 'Kurir Lama'], $result['existing']);
        $this->assertSame(['Tanpa Nomor'], $result['incomplete']);
        $this->assertSame(4, User::count());
    }

    public function test_username_gets_a_suffix_when_already_taken(): void
    {
        User::factory()->withUsername('kurir_dua')->create();

        app(TalentaCourierImporter::class)->import($this->talentaJson(), sendWhatsApp: false);

        $this->assertSame('kurir_dua_2', User::query()->where('email', 'kurir.dua@example.com')->value('username'));
    }

    public function test_whatsapp_message_can_be_turned_off(): void
    {
        Queue::fake();
        Storage::fake('local');
        $this->actingAs($this->userAdmin());

        Livewire::test(ListUsers::class)
            ->callAction('importTalentaCouriers', data: [
                'file' => UploadedFile::fake()->createWithContent('list-employee-gudang.json', $this->talentaJson()),
                'send_whatsapp' => false,
            ])
            ->assertNotified('4 akun kurir dibuat');

        Queue::assertNotPushed(SendUserAccountActivatedWhatsAppJob::class);
    }

    public function test_unreadable_file_creates_nothing(): void
    {
        Storage::fake('local');
        $this->actingAs($this->userAdmin());

        Livewire::test(ListUsers::class)
            ->callAction('importTalentaCouriers', data: [
                'file' => UploadedFile::fake()->createWithContent('list-employee.json', '{"data": "bukan daftar"'),
            ])
            ->assertNotified('File tidak bisa dibaca');

        $this->assertSame(1, User::count());
        $this->assertSame([], Storage::disk('local')->allFiles('talenta-imports'));
    }

    public function test_import_is_hidden_without_create_permission(): void
    {
        $role = Role::findOrCreate('admin_viewer', 'web');
        $role->givePermissionTo(Permission::findOrCreate('ViewAny:User', 'web'));
        $viewer = User::factory()->create();
        $viewer->assignRole($role);
        $this->actingAs($viewer);

        Livewire::test(ListUsers::class)->assertActionHidden('importTalentaCouriers');
    }

    /**
     * Bentuk response API direktori karyawan Talenta: daftar karyawan ada di `data.data`.
     */
    private function talentaJson(): string
    {
        $employee = fn (array $attributes): array => array_merge([
            'branch' => 'PT Media Selular Indonesia',
            'email' => null,
            'mobile_phone' => null,
            'id' => (string) fake()->unique()->numberBetween(100000, 999999),
            'job' => 'KURIR',
            'organization' => 'SUPPLY CHAIN',
            'title' => 'STAFF',
            'first_name' => '',
            'last_name' => '',
        ], $attributes);

        return (string) json_encode([
            'message' => 'success',
            'status' => 200,
            'data' => [
                'data' => [
                    $employee(['first_name' => 'DINO LIARDI', 'last_name' => 'PUTRA', 'email' => 'Kurir.Satu@example.com', 'mobile_phone' => '081200000001']),
                    $employee(['job' => 'STAFF KURIR', 'first_name' => 'BUDI ', 'email' => 'kurir.dua@example.com', 'mobile_phone' => '+6281200000002']),
                    $employee(['job' => 'DRIVER LOGISTIK', 'first_name' => 'ANDI', 'last_name' => 'LOGISTIK', 'email' => 'driver@example.com', 'mobile_phone' => '081200000007']),
                    $employee(['job' => 'STAFF WAREHOUSE', 'first_name' => 'STAFF GUDANG', 'email' => 'gudang@example.com', 'mobile_phone' => '081200000003']),
                    $employee(['job' => 'DRIVER', 'organization' => 'HUMAN CAPITAL', 'first_name' => 'SOPIR', 'email' => 'sopir@example.com', 'mobile_phone' => '081200000004']),
                    $employee(['organization' => 'RETAIL STORE', 'first_name' => 'KURIR TOKO', 'email' => 'toko@example.com', 'mobile_phone' => '081200000005']),
                    $employee(['first_name' => 'KURIR', 'last_name' => 'LAMA', 'email' => 'kurir.lama@example.com', 'mobile_phone' => '081200000006']),
                    $employee(['first_name' => 'TANPA', 'last_name' => 'NOMOR', 'email' => 'tanpa.nomor@example.com']),
                ],
                '_page_count' => 1,
                '_total_data' => 8,
            ],
        ]);
    }

    /**
     * Pesan WhatsApp akun aktif yang diantrekan: job pertama, lalu job berikutnya di chain-nya.
     *
     * @return list<SendUserAccountActivatedWhatsAppJob>
     */
    private function activationMessages(): array
    {
        $first = Queue::pushed(SendUserAccountActivatedWhatsAppJob::class)->sole();

        return [$first, ...array_map(unserialize(...), $first->chained)];
    }

    private function userAdmin(): User
    {
        $role = Role::findOrCreate('super_admin', 'web');

        foreach (['ViewAny:User', 'Create:User'] as $permissionName) {
            $role->givePermissionTo(Permission::findOrCreate($permissionName, 'web'));
        }

        $admin = User::factory()->create();
        $admin->assignRole($role);

        return $admin;
    }
}
