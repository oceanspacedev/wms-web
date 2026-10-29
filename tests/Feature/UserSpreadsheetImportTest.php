<?php

namespace Tests\Feature;

use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Jobs\SendUserAccountActivatedWhatsAppJob;
use App\Models\User;
use App\Services\UserSpreadsheetImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Reader\XLSX\Reader;
use OpenSpout\Writer\XLSX\Writer;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class UserSpreadsheetImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_upload_creates_accounts_from_name_and_whatsapp_only(): void
    {
        Queue::fake();
        Storage::fake('local');
        $this->actingAs($this->userAdmin());
        User::factory()->withVerifiedWhatsApp('6281200000003')->create(['name' => 'Kurir Lama']);

        Livewire::test(ListUsers::class)
            ->callAction('importUsers', data: [
                'file' => $this->upload([
                    UserSpreadsheetImporter::HEADERS,
                    ['  Dino   Liardi Putra ', '0812-0000-0001'],
                    // Nomor diketik tanpa format teks: Excel menyimpannya sebagai angka tanpa nol di depan
                    ['Budi', 81200000002],
                    ['Kurir Lama', '6281200000003'],
                    ['', '081200000004'],
                    ['Tanpa Nomor', ''],
                    ['', ''],
                ]),
                'send_whatsapp' => true,
            ])
            ->assertHasNoFormErrors()
            ->assertNotified('2 akun pengguna dibuat');

        $dino = User::query()->where('whatsapp_number', '6281200000001')->sole();
        $this->assertSame('Dino Liardi Putra', $dino->name);
        // Username dari nama, email dikosongkan
        $this->assertSame('dino_liardi_putra', $dino->username);
        $this->assertNull($dino->email);
        $this->assertNotNull($dino->whatsapp_verified_at);
        $this->assertSame(['panel_user'], $dino->getRoleNames()->all());

        $this->assertSame('budi', User::query()->where('whatsapp_number', '6281200000002')->value('username'));

        // Admin, akun lama, dan 2 akun baru; baris tanpa nama atau WhatsApp valid dilewati
        $this->assertSame(4, User::count());
        // Satu antrean berisi 2 pesan yang dikirim bergiliran, berisi password awal akunnya
        $messages = $this->activationMessages();
        $this->assertSame([$dino->id, User::query()->where('username', 'budi')->value('id')], array_column($messages, 'userId'));
        $this->assertTrue(Hash::check((string) $messages[0]->password, $dino->password));
        $this->assertSame([], Storage::disk('local')->allFiles('user-imports'));
    }

    public function test_import_reports_skipped_rows(): void
    {
        Queue::fake();
        User::factory()->withVerifiedWhatsApp('6281200000003')->create(['name' => 'Kurir Lama']);

        $result = app(UserSpreadsheetImporter::class)->import($this->spreadsheet([
            // Header tanpa tanda * dan beda huruf besar/kecil tetap dikenali
            ['nomor whatsapp', 'NAMA LENGKAP'],
            ['081200000003', 'Kurir Lama'],
            ['081200000004', ''],
            ['12345', 'Nomor Pendek'],
        ]), sendWhatsApp: false);

        $this->assertSame([], $result['created']);
        $this->assertSame(['Kurir Lama'], $result['existing']);
        $this->assertSame(['Baris 3', 'Nomor Pendek'], $result['incomplete']);
        Queue::assertNothingPushed();
    }

    public function test_username_gets_a_suffix_when_already_taken(): void
    {
        User::factory()->withUsername('budi')->create();

        app(UserSpreadsheetImporter::class)->import($this->spreadsheet([
            UserSpreadsheetImporter::HEADERS,
            ['Budi', '081200000001'],
            ['Al', '081200000002'],
        ]), sendWhatsApp: false);

        $this->assertSame('budi_2', User::query()->where('whatsapp_number', '6281200000001')->value('username'));
        // Nama terlalu pendek untuk username diberi awalan
        $this->assertSame('user_al', User::query()->where('whatsapp_number', '6281200000002')->value('username'));
    }

    public function test_file_without_template_columns_creates_nothing(): void
    {
        Queue::fake();
        Storage::fake('local');
        $this->actingAs($this->userAdmin());

        Livewire::test(ListUsers::class)
            ->callAction('importUsers', data: [
                'file' => $this->upload([['Nama', 'Email'], ['Budi', 'budi@example.com']]),
            ])
            ->assertNotified('File tidak bisa dibaca');

        $this->assertSame(1, User::count());
        $this->assertSame([], Storage::disk('local')->allFiles('user-imports'));
    }

    public function test_non_excel_file_is_rejected(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'import');
        file_put_contents($path, '{"data": []}');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('File bukan Excel (.xlsx) yang valid.');

        try {
            app(UserSpreadsheetImporter::class)->import($path);
        } finally {
            @unlink($path);
        }
    }

    public function test_template_download_has_the_import_columns(): void
    {
        $this->actingAs($this->userAdmin());

        $component = Livewire::test(ListUsers::class)
            ->callAction(['importUsers', 'downloadTemplate'])
            ->assertFileDownloaded('Template Import Pengguna.xlsx');

        $path = tempnam(sys_get_temp_dir(), 'template').'.xlsx';
        file_put_contents($path, base64_decode($component->effects['download']['content']));
        $reader = new Reader;
        $reader->open($path);
        $rows = [];
        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $rows[] = $row->toArray();
            }
        }
        $reader->close();
        @unlink($path);

        // Tanpa baris contoh, supaya tidak ada akun contoh yang ikut terbuat
        $this->assertSame([UserSpreadsheetImporter::HEADERS], $rows);
    }

    public function test_import_is_hidden_without_create_permission(): void
    {
        $role = Role::findOrCreate('admin_viewer', 'web');
        $role->givePermissionTo(Permission::findOrCreate('ViewAny:User', 'web'));
        $viewer = User::factory()->create();
        $viewer->assignRole($role);
        $this->actingAs($viewer);

        Livewire::test(ListUsers::class)->assertActionHidden('importUsers');
    }

    public function test_user_can_be_created_without_email(): void
    {
        Queue::fake();
        $this->actingAs($this->userAdmin());

        Livewire::test(CreateUser::class)
            ->fillForm([
                'name' => 'Kurir Tanpa Email',
                'username' => 'kurir_tanpa_email',
                'password' => 'secret-pass',
                'whatsapp_number' => '081298765432',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertNull(User::query()->where('username', 'kurir_tanpa_email')->value('email'));
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

    /**
     * @param  list<list<mixed>>  $rows
     */
    private function upload(array $rows): UploadedFile
    {
        $path = $this->spreadsheet($rows);
        $file = UploadedFile::fake()->createWithContent('pengguna.xlsx', (string) file_get_contents($path));
        @unlink($path);

        return $file;
    }

    /**
     * @param  list<list<mixed>>  $rows
     */
    private function spreadsheet(array $rows): string
    {
        $path = tempnam(sys_get_temp_dir(), 'import').'.xlsx';
        $writer = new Writer;
        $writer->openToFile($path);
        foreach ($rows as $row) {
            $writer->addRow(Row::fromValues($row));
        }
        $writer->close();

        return $path;
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
