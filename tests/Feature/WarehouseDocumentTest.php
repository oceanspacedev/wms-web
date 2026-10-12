<?php

namespace Tests\Feature;

use App\Filament\Resources\WarehouseDocuments\Pages\ListWarehouseDocuments;
use App\Filament\Resources\WarehouseDocuments\Schemas\WarehouseDocumentForm;
use App\Jobs\SendWarehouseDocumentToArsipJob;
use App\Models\User;
use App\Models\WarehouseDocument;
use App\Services\ScmArsipClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class WarehouseDocumentTest extends TestCase
{
    use RefreshDatabase;

    private const ARSIP_URL = 'https://arsip.test';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.scm_arsip.url' => self::ARSIP_URL,
            'services.scm_arsip.token' => 'token-test',
        ]);

        Storage::fake('local');
    }

    public function test_warehouse_staff_uploads_memo_and_it_is_queued_for_arsip(): void
    {
        Queue::fake();
        $this->fakeArsipPrograms([$this->program('PRG-1', 'GDG-BDG-01', 'PO-001')]);

        $staff = $this->warehouseStaff('GDG-BDG-01');
        $this->actingAs($staff);

        Livewire::test(ListWarehouseDocuments::class)
            ->callAction('create', [
                'arsip_program_id' => 'PRG-1',
                'file_path' => UploadedFile::fake()->create('memo-po-001.pdf', 100, 'application/pdf'),
            ])
            ->assertHasNoActionErrors()
            ->assertNotified('Dokumen berhasil diunggah');

        $this->assertDatabaseHas('warehouse_documents', [
            'user_id' => $staff->id,
            'kode_gudang' => 'GDG-BDG-01',
            'arsip_program_id' => 'PRG-1',
            'po_sj_number' => 'PO-001',
            'program_title' => 'Program A',
            'original_name' => 'memo-po-001.pdf',
            'status' => WarehouseDocument::STATUS_PENDING,
        ]);

        Queue::assertPushed(SendWarehouseDocumentToArsipJob::class);
        // Petugas gudang hanya mengambil PO gudangnya sendiri
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'kode_gudang=GDG-BDG-01'));
    }

    public function test_warehouse_staff_cannot_send_to_a_po_of_another_warehouse(): void
    {
        Queue::fake();
        $this->fakeArsipPrograms([$this->program('PRG-1', 'GDG-BDG-01', 'PO-001')]);

        $this->actingAs($this->warehouseStaff('GDG-BDG-01'));

        Livewire::test(ListWarehouseDocuments::class)
            ->callAction('create', [
                'arsip_program_id' => 'PRG-9',
                'file_path' => UploadedFile::fake()->create('memo.pdf', 100, 'application/pdf'),
            ]);

        $this->assertSame(0, WarehouseDocument::count());
        Queue::assertNothingPushed();
    }

    public function test_scm_admin_uploads_many_memos_to_different_warehouses_at_once(): void
    {
        Queue::fake();
        $this->fakeArsipPrograms([
            $this->program('3', 'GDG-BDG-01', 'PO/2026/0103 / SJ-0103'),
            $this->program('2', 'GDG-SBY-02', 'PO/2026/0102 / SJ-0102'),
        ]);

        $this->actingAs($this->scmAdmin());

        Livewire::test(ListWarehouseDocuments::class)
            ->callAction('bulkUpload', [
                'files' => [
                    UploadedFile::fake()->create('PO-2026-0103.pdf', 100, 'application/pdf'),
                    UploadedFile::fake()->create('SJ-0102.pdf', 100, 'application/pdf'),
                ],
                'items' => [
                    ['file_key' => 'a', 'file_name' => 'PO-2026-0103.pdf', 'arsip_program_id' => '3'],
                    ['file_key' => 'b', 'file_name' => 'SJ-0102.pdf', 'arsip_program_id' => '2'],
                ],
            ])
            ->assertHasNoActionErrors()
            ->assertNotified('2 dokumen diunggah');

        $this->assertDatabaseHas('warehouse_documents', ['arsip_program_id' => '3', 'kode_gudang' => 'GDG-BDG-01', 'original_name' => 'PO-2026-0103.pdf']);
        $this->assertDatabaseHas('warehouse_documents', ['arsip_program_id' => '2', 'kode_gudang' => 'GDG-SBY-02', 'original_name' => 'SJ-0102.pdf']);
        Queue::assertPushed(SendWarehouseDocumentToArsipJob::class, 2);
        // Admin mengambil PO semua gudang
        Http::assertSent(fn (Request $request): bool => ! str_contains($request->url(), 'kode_gudang='));
    }

    public function test_bulk_upload_rows_are_prefilled_from_file_names(): void
    {
        $this->fakeArsipPrograms([
            $this->program('3', 'GDG-BDG-01', 'PO/2026/0103 / SJ-0103'),
            $this->program('5', 'GDG-TBN-01', 'PO/2026/0105 / SJ-0105'),
        ]);

        $this->actingAs($this->scmAdmin());

        $items = WarehouseDocumentForm::itemsForFiles([
            'a' => 'livewire-tmp/PO-2026-0103.pdf',
            'b' => 'livewire-tmp/DO 0105 scan.jpg',
            'c' => 'livewire-tmp/IMG_20261011_0832.jpg',
        ], [
            // Pilihan yang sudah diubah pengguna tidak ditimpa
            'c' => ['file_key' => 'c', 'file_name' => 'IMG_20261011_0832.jpg', 'arsip_program_id' => '3'],
        ]);

        $this->assertSame('3', $items['a']['arsip_program_id']);
        $this->assertSame('5', $items['b']['arsip_program_id']);
        $this->assertSame('3', $items['c']['arsip_program_id']);
        $this->assertSame('DO 0105 scan.jpg', $items['b']['file_name']);
    }

    public function test_warehouse_staff_only_sees_documents_of_their_own_warehouse(): void
    {
        $own = $this->makeDocument('GDG-BDG-01');
        $other = $this->makeDocument('GDG-SBY-02');

        $this->actingAs($this->warehouseStaff('GDG-BDG-01'));

        Livewire::test(ListWarehouseDocuments::class)
            ->assertCanSeeTableRecords([$own])
            ->assertCanNotSeeTableRecords([$other]);
    }

    public function test_job_marks_document_as_sent(): void
    {
        Http::fake([
            'arsip.test/api/integrations/whms/documents' => Http::response(['success' => true, 'document_id' => 'whms-1', 'program_id' => 'PRG-1'], 201),
        ]);
        $document = $this->makeDocument('GDG-BDG-01');

        (new SendWarehouseDocumentToArsipJob($document))->handle(app(ScmArsipClient::class));

        $document->refresh();
        $this->assertSame(WarehouseDocument::STATUS_SENT, $document->status);
        $this->assertSame('whms-1', $document->arsip_document_id);
        $this->assertNotNull($document->sent_at);

        Http::assertSent(function (Request $request) use ($document): bool {
            // Bagian multipart tersimpan sebagai daftar ['name' => ..., 'contents' => ...]
            $parts = collect($request->data())->pluck('contents', 'name');

            return $request->hasHeader('Authorization', 'Bearer token-test')
                && $request->isMultipart()
                && $parts['program_id'] === 'PRG-1'
                && $parts['kode_gudang'] === 'GDG-BDG-01'
                && $parts['whms_document_id'] === $document->uuid
                && $parts->has('file');
        });
    }

    public function test_job_fails_without_retry_when_arsip_rejects_the_document(): void
    {
        Http::fake([
            'arsip.test/api/integrations/whms/documents' => Http::response(['success' => false, 'message' => 'Program/PO tidak ditemukan untuk kode gudang ini.'], 404),
        ]);
        $document = $this->makeDocument('GDG-BDG-01');

        (new SendWarehouseDocumentToArsipJob($document))->handle(app(ScmArsipClient::class));

        $document->refresh();
        $this->assertSame(WarehouseDocument::STATUS_FAILED, $document->status);
        $this->assertSame('Program/PO tidak ditemukan untuk kode gudang ini.', $document->error_message);
    }

    public function test_job_stays_pending_and_retries_when_arsip_is_down(): void
    {
        Http::fake([
            'arsip.test/api/integrations/whms/documents' => Http::response('Service Unavailable', 503),
        ]);
        $document = $this->makeDocument('GDG-BDG-01');

        try {
            (new SendWarehouseDocumentToArsipJob($document))->handle(app(ScmArsipClient::class));
            $this->fail('Job seharusnya melempar exception agar dicoba ulang oleh antrean.');
        } catch (RequestException) {
            // diharapkan: worker antrean akan mencoba ulang
        }

        $document->refresh();
        $this->assertSame(WarehouseDocument::STATUS_PENDING, $document->status);
        $this->assertStringContainsString('dicoba ulang', $document->error_message);
    }

    public function test_job_fails_when_arsip_integration_is_not_configured(): void
    {
        config(['services.scm_arsip.url' => null]);
        Http::fake();
        $document = $this->makeDocument('GDG-BDG-01');

        (new SendWarehouseDocumentToArsipJob($document))->handle(app(ScmArsipClient::class));

        $this->assertSame(WarehouseDocument::STATUS_FAILED, $document->fresh()->status);
        Http::assertNothingSent();
    }

    /**
     * @param  array<int, array<string, mixed>>  $programs
     */
    private function fakeArsipPrograms(array $programs): void
    {
        Http::fake([
            'arsip.test/api/integrations/whms/programs*' => Http::response(['success' => true, 'programs' => $programs]),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function program(string $id, string $kodeGudang, string $poSj): array
    {
        return ['id' => $id, 'title' => 'Program A', 'po_sj_number' => $poSj, 'kode_gudang' => $kodeGudang, 'has_memo' => false];
    }

    private function scmAdmin(): User
    {
        $role = Role::firstOrCreate(['name' => 'staf_scm', 'guard_name' => 'web']);
        $role->givePermissionTo(['ViewAny:WarehouseDocument', 'View:WarehouseDocument', 'Create:WarehouseDocument']);

        $user = User::factory()->create();
        $user->assignRole(Role::firstOrCreate(['name' => 'panel_user', 'guard_name' => 'web']), $role);

        return $user;
    }

    private function warehouseStaff(string $kodeGudang): User
    {
        $user = User::factory()->create(['kode_gudang_arsip' => $kodeGudang]);
        $user->assignRole(
            Role::firstOrCreate(['name' => 'panel_user', 'guard_name' => 'web']),
            Role::findByName('gudang', 'web'),
        );

        return $user;
    }

    private function makeDocument(string $kodeGudang): WarehouseDocument
    {
        $path = 'warehouse_documents/'.uniqid().'.pdf';
        Storage::disk('local')->put($path, '%PDF-1.4 test');

        return WarehouseDocument::create([
            'kode_gudang' => $kodeGudang,
            'arsip_program_id' => 'PRG-1',
            'po_sj_number' => 'PO-001',
            'program_title' => 'Program A',
            'file_path' => $path,
            'original_name' => 'memo.pdf',
            'file_size' => 13,
            'status' => WarehouseDocument::STATUS_PENDING,
        ]);
    }
}
