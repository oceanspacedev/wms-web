<?php

namespace Tests\Feature;

use App\Filament\Pages\Auth\EditProfile;
use App\Models\CsaShipment;
use App\Models\Depo;
use App\Models\TrackingOrder;
use App\Models\User;
use App\Services\Telegram\TelegramBot;
use App\Services\Telegram\WmsDataCatalog;
use App\Services\Telegram\WmsDataTools;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class TelegramBotTest extends TestCase
{
    use RefreshDatabase;

    private const CHAT_ID = 5551234;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.telegram.bot_token' => 'token-tes',
            'services.telegram.bot_username' => 'wms_tes_bot',
            'services.telegram.webhook_secret' => 'rahasia-tes',
            // AI mati kecuali tes memakai configureAi(); tidak ada permintaan HTTP sungguhan ke luar
            'services.ai.base_url' => null,
            'services.ai.api_key' => null,
            'services.ai.model' => null,
        ]);
        Http::preventStrayRequests();
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
        $this->travelTo('2026-11-10 10:00:00');
    }

    public function test_webhook_without_the_secret_is_rejected(): void
    {
        $this->postJson('/api/telegram/webhook', $this->update('sj hari ini'))->assertForbidden();
        $this->postJson('/api/telegram/webhook', $this->update('sj hari ini'), ['X-Telegram-Bot-Api-Secret-Token' => 'salah'])->assertForbidden();

        Http::assertNothingSent();
    }

    public function test_chat_is_linked_with_the_code_from_the_profile_page_and_unknown_chats_get_no_data(): void
    {
        $user = User::factory()->create(['name' => 'Reza']);
        $code = TelegramBot::issueLinkCode($user);
        $this->assertSame("https://t.me/wms_tes_bot?start={$code}", TelegramBot::linkUrl($code));

        // Chat yang belum terhubung tidak mendapat data
        $this->webhook('sj hari ini');
        $this->assertSentText('belum terhubung');

        $this->webhook("/start {$code}");
        $this->assertSame(self::CHAT_ID, $user->refresh()->telegram_chat_id);
        $this->assertSentText('Terhubung ke akun WMS <b>Reza</b>');

        // Kode hanya sekali pakai
        $this->assertStringContainsString('tidak valid', $this->bot()->reply(999, "/start {$code}"));

        $this->assertStringContainsString('sudah diputus', $this->bot()->reply(self::CHAT_ID, 'putuskan'));
        $this->assertNull($user->refresh()->telegram_chat_id);
    }

    public function test_summary_of_today_answers_a_plain_question(): void
    {
        $admin = $this->linkedUser(superAdmin: true);
        [$cirebon, $bandung] = [Depo::factory()->create(['kode' => 'GMCRB', 'nama' => 'CIREBON']), Depo::factory()->create(['kode' => 'GMBDG', 'nama' => 'BANDUNG'])];
        $courier = User::factory()->create(['depo_id' => $cirebon->id]);
        $this->order('SJ-1', $courier, ['jumlah_value_nota' => 1000000]);
        $this->order('SJ-2', $courier, ['jumlah_value_nota' => 500000, 'foto_penerima' => null]);
        $this->order('SJ-3', User::factory()->create(['depo_id' => $bandung->id]), ['jumlah_value_nota' => 250000]);
        // SJ kemarin tidak ikut ringkasan hari ini
        $this->order('SJ-LAMA', $courier, ['tanggal_pengiriman' => '2026-11-09']);

        $reply = $this->bot()->reply(self::CHAT_ID, 'apakah ada nota surat jalan pada hari ini?');

        $this->assertStringContainsString('Selasa, 10/11/2026', $reply);
        $this->assertStringContainsString('3 SJ · Rp 1.750.000', $reply);
        $this->assertStringContainsString('CIREBON 2 · BANDUNG 1', $reply);
        $this->assertStringContainsString('1 SJ fotonya belum lengkap', $reply);

        $this->assertStringContainsString('1 SJ', $this->bot()->reply(self::CHAT_ID, 'sj kemarin'));
        $this->assertStringContainsString('Belum ada surat jalan pada Minggu, 01/11/2026', $this->bot()->reply(self::CHAT_ID, 'sj 01/11'));
        $this->assertTrue($admin->hasRole('super_admin'));
    }

    public function test_list_per_depo_per_courier_incomplete_photos_and_detail(): void
    {
        $this->linkedUser(superAdmin: true);
        $cirebon = Depo::factory()->create(['kode' => 'GMCRB', 'nama' => 'CIREBON']);
        $agus = User::factory()->create(['depo_id' => $cirebon->id]);
        $this->order('SJ-CRB-1', $agus, ['nama_pengirim' => 'Agus']);
        $this->order('SJ-CRB-2', $agus, ['nama_pengirim' => 'Agus', 'foto_nota_sj' => null]);
        $this->order('SJ-BDG-1', User::factory()->create(), ['nama_pengirim' => 'Tomi']);

        $perDepo = $this->bot()->reply(self::CHAT_ID, 'sj cirebon');
        $this->assertStringContainsString('CIREBON — Selasa, 10/11/2026</b> — 2 SJ', $perDepo);
        $this->assertStringContainsString('SJ-CRB-2', $perDepo);
        $this->assertStringNotContainsString('SJ-BDG-1', $perDepo);

        $this->assertStringContainsString('SJ-BDG-1', $this->bot()->reply(self::CHAT_ID, 'sj tomi'));

        $incomplete = $this->bot()->reply(self::CHAT_ID, 'belum lengkap');
        $this->assertStringContainsString('SJ-CRB-2', $incomplete);
        $this->assertStringNotContainsString('SJ-CRB-1', $incomplete);

        $detail = $this->bot()->reply(self::CHAT_ID, 'cari SJ-CRB-2');
        $this->assertStringContainsString('Kurir: Agus (CIREBON)', $detail);
        $this->assertStringContainsString('Foto nota: ❌ · Foto penerima: ✅', $detail);
        $this->assertStringContainsString('tidak ditemukan', $this->bot()->reply(self::CHAT_ID, 'cari TIDAK-ADA'));
    }

    public function test_courier_only_sees_their_own_surat_jalan(): void
    {
        $courier = $this->linkedUser();
        $this->order('SJ-SAYA', $courier);
        $this->order('SJ-ORANG-LAIN', User::factory()->create());

        $this->assertStringContainsString('1 SJ', $this->bot()->reply(self::CHAT_ID, 'sj'));
        $this->assertStringContainsString('tidak ditemukan', $this->bot()->reply(self::CHAT_ID, 'cari SJ-ORANG-LAIN'));
    }

    public function test_free_question_is_answered_by_ai_using_the_wms_data_tools(): void
    {
        $this->configureAi();
        $this->linkedUser(superAdmin: true);
        $cirebon = Depo::factory()->create(['kode' => 'GMCRB', 'nama' => 'CIREBON']);
        $courier = User::factory()->create(['depo_id' => $cirebon->id]);
        $this->order('SJ-CRB-1', $courier, ['nama_pengirim' => 'Agus', 'foto_penerima' => null, 'jumlah_value_nota' => 750000]);
        $this->order('SJ-CRB-2', $courier, ['nama_pengirim' => 'Agus']);
        $this->order('SJ-BDG-1', User::factory()->create(), ['nama_pengirim' => 'Tomi']);

        // AI minta data dulu lewat alat, baru menjawab
        Http::fake(['ai.test/*' => Http::sequence()
            ->push($this->aiToolCall('cari_data', ['data' => 'surat_jalan', 'filter' => [
                ['kolom' => 'tanggal_pengiriman', 'operator' => '=', 'nilai' => '2026-11-10'],
                ['kolom' => 'depo', 'operator' => '=', 'nilai' => 'cirebon'],
                ['kolom' => 'foto_penerima', 'operator' => 'kosong'],
            ]]))
            ->push($this->aiAnswer('Hari ini ada 1 SJ Cirebon yang foto penerimanya belum ada: SJ-CRB-1 <Agus>.')),
        ]);

        $reply = $this->bot()->reply(self::CHAT_ID, 'cirebon hari ini yg foto penerimanya blm ada apa aja?');

        // Jawaban AI di-escape untuk HTML Telegram
        $this->assertSame('Hari ini ada 1 SJ Cirebon yang foto penerimanya belum ada: SJ-CRB-1 &lt;Agus&gt;.', $reply);

        $requests = Http::recorded(fn (Request $request): bool => str_starts_with($request->url(), 'https://ai.test/'));
        $this->assertCount(2, $requests);
        [$first, $second] = [$requests[0][0], $requests[1][0]];
        $this->assertSame('model-tes', $first['model']);
        $this->assertSame(['cari_data', 'rekap_data'], array_column(array_column($first['tools'], 'function'), 'name'));
        // Instruksi berisi tanggal hari ini dan data menu yang boleh dibaca akun ini
        $this->assertStringContainsString('2026-11-10', $first['messages'][0]['content']);
        $this->assertStringContainsString('surat_jalan (menu Surat Jalan)', $first['messages'][0]['content']);

        // Hasil alat yang dikirim balik ke AI: hanya SJ Cirebon yang fotonya belum lengkap
        $toolResult = json_decode(collect($second['messages'])->firstWhere('role', 'tool')['content'], true);
        $this->assertSame(1, $toolResult['total_baris']);
        $this->assertSame('SJ-CRB-1', $toolResult['baris'][0]['no_sj']);
        $this->assertSame('CIREBON', $toolResult['baris'][0]['depo']);
        $this->assertFalse($toolResult['baris'][0]['foto_penerima']);
    }

    public function test_ai_tools_respect_what_the_account_may_see(): void
    {
        $courier = $this->linkedUser();
        $this->order('SJ-SAYA', $courier, ['jumlah_value_nota' => 100000]);
        $this->order('SJ-ORANG-LAIN', User::factory()->create(), ['jumlah_value_nota' => 900000]);
        $this->shipment(['no_sj' => 'CSA-1', 'target_sheet' => 'CIREBON', 'tanggal_kirim' => '2026-11-10']);
        $tools = new WmsDataTools($courier);

        // Surat jalan: hanya milik sendiri
        $result = $tools->run('rekap_data', ['data' => 'surat_jalan', 'jumlahkan' => ['jumlah_value_nota']]);
        $this->assertSame(['jumlah_baris' => 1, 'total_jumlah_value_nota' => 100000], $result['total']);
        $this->assertSame(0, $tools->run('cari_data', ['data' => 'surat_jalan', 'filter' => [['kolom' => 'no_sj', 'operator' => '=', 'nilai' => 'SJ-ORANG-LAIN']]])['total_baris']);

        // Menu tanpa izin lihat (Data Pengiriman) tidak bisa dibaca dan tidak disebut di instruksi AI
        $this->assertStringContainsString('tidak punya akses', $tools->run('cari_data', ['data' => 'data_pengiriman'])['error']);
        $this->assertStringNotContainsString('data_pengiriman', WmsDataCatalog::describe($courier));

        // Setelah diberi izin lihat menu, admin cabang hanya melihat cabangnya
        $courier->givePermissionTo(Permission::findOrCreate('ViewAny:CsaShipment', 'web'));
        $this->shipment(['no_sj' => 'CSA-2', 'target_sheet' => 'BANDUNG', 'tanggal_kirim' => '2026-11-10']);
        $courier->update(['cabang' => 'BANDUNG']);
        $rows = (new WmsDataTools($courier->refresh()))->run('cari_data', ['data' => 'data_pengiriman']);
        $this->assertSame(['CSA-2'], array_column($rows['baris'], 'no_sj'));
    }

    public function test_ai_tools_validate_columns_and_group_totals(): void
    {
        $admin = $this->linkedUser(superAdmin: true);
        $admin->givePermissionTo(Permission::findOrCreate('ViewAny:CsaShipment', 'web'));
        $this->shipment(['no_sj' => 'A', 'target_sheet' => 'CIREBON', 'tanggal_kirim' => '2026-11-03', 'biaya_kirim' => 10000]);
        $this->shipment(['no_sj' => 'B', 'target_sheet' => 'CIREBON', 'tanggal_kirim' => '2026-11-09', 'biaya_kirim' => 5000]);
        $this->shipment(['no_sj' => 'C', 'target_sheet' => 'BANDUNG', 'tanggal_kirim' => '2026-10-20', 'biaya_kirim' => 7000]);
        $tools = new WmsDataTools($admin);

        $perCabang = $tools->run('rekap_data', ['data' => 'data_pengiriman', 'kelompok' => 'target_sheet', 'jumlahkan' => ['biaya_kirim'], 'filter' => [
            ['kolom' => 'tanggal_kirim', 'operator' => '>=', 'nilai' => '2026-11-01'],
        ]]);
        $this->assertSame([['kelompok' => 'CIREBON', 'jumlah_baris' => 2, 'total_biaya_kirim' => 15000]], $perCabang['hasil']);

        $perBulan = $tools->run('rekap_data', ['data' => 'data_pengiriman', 'kelompok' => 'tanggal_kirim', 'per' => 'bulan']);
        $this->assertSame(['2026-10', '2026-11'], array_column($perBulan['hasil'], 'kelompok'));

        // Kolom di luar daftar (mis. lokasi file, koordinat) ditolak
        $this->assertStringContainsString('Kolom latitude tidak ada', $tools->run('cari_data', ['data' => 'data_pengiriman', 'filter' => [['kolom' => 'latitude', 'operator' => 'tidak_kosong']]])['error']);
        $this->assertStringContainsString('Kolom alamat_kirim tidak ada', $tools->run('rekap_data', ['data' => 'data_pengiriman', 'kelompok' => 'alamat_kirim'])['error']);
        $this->assertStringContainsString('bukan angka', $tools->run('rekap_data', ['data' => 'data_pengiriman', 'jumlahkan' => ['no_sj']])['error']);
        $this->assertStringContainsString('YYYY-MM-DD', $tools->run('cari_data', ['data' => 'data_pengiriman', 'filter' => [['kolom' => 'tanggal_kirim', 'operator' => '=', 'nilai' => '10/11']]])['error']);
    }

    public function test_bot_falls_back_to_keyword_answers_when_the_ai_fails(): void
    {
        $this->configureAi();
        $this->linkedUser(superAdmin: true);
        $this->order('SJ-1', User::factory()->create());
        Http::fake(['ai.test/*' => Http::response(['error' => 'down'], 503)]);

        $reply = $this->bot()->reply(self::CHAT_ID, 'sj hari ini');

        $this->assertStringContainsString('AI sedang tidak bisa dipakai', $reply);
        $this->assertStringContainsString('1 SJ', $reply);
    }

    public function test_profile_page_shows_a_link_code_and_can_disconnect(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        Livewire::test(EditProfile::class)
            ->mountAction('connectTelegram')
            ->assertMountedActionModalSee(['/start', '@wms_tes_bot']);

        $user->forceFill(['telegram_chat_id' => self::CHAT_ID])->save();

        Livewire::test(EditProfile::class)
            ->assertActionHidden('connectTelegram')
            ->callAction('disconnectTelegram');

        $this->assertNull($user->refresh()->telegram_chat_id);
    }

    private function configureAi(): void
    {
        config(['services.ai.base_url' => 'https://ai.test/v1', 'services.ai.api_key' => 'kunci-tes', 'services.ai.model' => 'model-tes']);
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function aiToolCall(string $name, array $arguments): array
    {
        return ['choices' => [['message' => ['role' => 'assistant', 'content' => null, 'tool_calls' => [
            ['id' => 'call_1', 'type' => 'function', 'function' => ['name' => $name, 'arguments' => json_encode($arguments)]],
        ]]]]];
    }

    /**
     * @return array<string, mixed>
     */
    private function aiAnswer(string $text): array
    {
        return ['choices' => [['message' => ['role' => 'assistant', 'content' => $text]]]];
    }

    private function linkedUser(bool $superAdmin = false): User
    {
        $user = User::factory()->create();
        $user->forceFill(['telegram_chat_id' => self::CHAT_ID])->save();

        if ($superAdmin) {
            $user->assignRole(Role::findOrCreate('super_admin', 'web'));
        }

        return $user;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function order(string $noSj, User $courier, array $attributes = []): TrackingOrder
    {
        return TrackingOrder::create([
            'no_sj' => $noSj,
            'nama_dealer' => 'Toko '.$noSj,
            'tanggal_pengiriman' => '2026-11-10',
            'user_id' => $courier->id,
            'foto_nota_sj' => 'tracking-orders/nota/a.jpg',
            'foto_penerima' => 'tracking-orders/penerima/a.jpg',
            ...$attributes,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function shipment(array $attributes): CsaShipment
    {
        return CsaShipment::create(['kode_gudang' => 'GMCRB', 'nama_gudang' => 'GUDANG MSIS CIREBON', ...$attributes]);
    }

    private function webhook(string $text): void
    {
        $this->postJson('/api/telegram/webhook', $this->update($text), ['X-Telegram-Bot-Api-Secret-Token' => 'rahasia-tes'])->assertOk();
    }

    /**
     * @return array<string, mixed>
     */
    private function update(string $text): array
    {
        return ['update_id' => 1, 'message' => ['message_id' => 1, 'chat' => ['id' => self::CHAT_ID, 'type' => 'private'], 'text' => $text]];
    }

    private function assertSentText(string $expected): void
    {
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/sendMessage')
            && $request['chat_id'] === self::CHAT_ID
            && str_contains((string) $request['text'], $expected));
    }

    private function bot(): TelegramBot
    {
        return app(TelegramBot::class);
    }
}
