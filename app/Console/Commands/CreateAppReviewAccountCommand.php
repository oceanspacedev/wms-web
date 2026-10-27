<?php

namespace App\Console\Commands;

use App\Models\PurchaseOrder;
use App\Models\TrackingOrder;
use App\Models\User;
use BezhanSalleh\FilamentShield\Support\Utils;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

class CreateAppReviewAccountCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app-review:account
                            {--username=appreview : Username akun demo untuk Apple / Google reviewer}
                            {--email=appreview@oceanspace.co.id : Email akun demo}
                            {--password= : Password akun demo (kosongkan untuk dibuat acak)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Buat / reset akun demo App Review beserta contoh data Tracking Order dan Purchase Order miliknya';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $username = Str::lower((string) $this->option('username'));
        $email = (string) $this->option('email');
        $password = (string) ($this->option('password') ?: Str::password(12, symbols: false));

        if (strlen($password) < 8) {
            $this->error('Password minimal 8 karakter.');

            return self::FAILURE;
        }

        $user = DB::transaction(function () use ($username, $email, $password): User {
            // Reviewers may delete the account in-app (guideline 5.1.1(v)), so this must be safe to re-run.
            $user = User::query()
                ->where('username', $username)
                ->orWhere('email', $email)
                ->first() ?? new User;

            $user->fill([
                'name' => 'App Review Demo',
                'username' => $username,
                'email' => $email,
                'password' => Hash::make($password),
            ])->save();

            $panelUserRole = Role::firstOrCreate(['name' => Utils::getPanelUserRoleName(), 'guard_name' => 'web']);
            $user->syncRoles([$panelUserRole]);
            $user->tokens()->delete();

            $this->seedTrackingOrders($user);
            $this->seedPurchaseOrders($user);

            return $user;
        });

        $this->info('Akun App Review siap.');
        $this->table(['Field', 'Value'], [
            ['Username', $user->username],
            ['Email', $user->email],
            ['Password', $password],
            ['Tracking Order', TrackingOrder::query()->where('user_id', $user->id)->count()],
            ['Purchase Order', PurchaseOrder::query()->where('user_id', $user->id)->count()],
        ]);
        $this->warn('Simpan password ini di App Store Connect > TestFlight > Test Information > Beta App Review Information.');

        return self::SUCCESS;
    }

    private function seedTrackingOrders(User $user): void
    {
        $orders = [
            [
                'no_sj' => 'DEMO-SJ-0001',
                'nama_dealer' => 'Demo Dealer Bandung',
                'alamat_dealer' => 'Jl. Purnawarman No. 13, Bandung',
                'jumlah_value_nota' => 12500000,
                'tanggal_nota' => now()->subDays(3)->toDateString(),
                'tanggal_pengiriman' => now()->subDays(2)->toDateString(),
                'nama_pengirim' => 'Demo Driver',
                'nama_penerima' => 'Demo Penerima',
                'address' => 'Jl. Purnawarman, Tamansari, Bandung City, West Java, Indonesia',
                'latitude' => -6.9089,
                'longitude' => 107.6107,
                'notes' => 'Contoh data untuk App Review.',
            ],
            [
                'no_sj' => 'DEMO-SJ-0002',
                'nama_dealer' => 'Demo Dealer Cirebon',
                'alamat_dealer' => 'Jl. Siliwangi No. 88, Cirebon',
                'jumlah_value_nota' => 4750000,
                'tanggal_nota' => now()->subDay()->toDateString(),
                'tanggal_pengiriman' => now()->toDateString(),
                'nama_pengirim' => 'Demo Driver',
                'nama_penerima' => 'Demo Penerima',
                'address' => 'Jl. Siliwangi, Kejaksan, Cirebon, West Java, Indonesia',
                'latitude' => -6.7063,
                'longitude' => 108.5570,
                'notes' => 'Contoh data untuk App Review.',
            ],
        ];

        foreach ($orders as $order) {
            TrackingOrder::query()->updateOrCreate(
                ['user_id' => $user->id, 'no_sj' => $order['no_sj']],
                $order,
            );
        }
    }

    private function seedPurchaseOrders(User $user): void
    {
        $orders = [
            [
                'no_po' => 'DEMO-PO-0001',
                'no_do' => 'DEMO-DO-0001',
                'tanggal_do' => now()->subDays(4)->toDateString(),
                'tanggal_datang' => now()->subDays(2)->toDateString(),
                'nama_supplier' => 'Demo Supplier',
                'nama_gudang' => 'GUDANG DEMO',
                'alamat_gudang' => 'Jl. Purnawarman, Tamansari, Bandung',
                'nama_kurir_ekspedisi' => 'J&T Cargo',
                'no_resi' => 'DEMO100000001',
                'penerima_gudang' => 'Demo Admin Gudang',
                'qty_koli' => 5,
                'qty_unit' => 50,
                'total_nominal' => 15000000,
                'keterangan_barang' => 'Contoh barang masuk untuk App Review',
                'status_penerimaan' => PurchaseOrder::RECEIPT_COMPLETE,
                'catatan_gudang' => 'Kondisi baik.',
                'status_verifikasi_finance' => 'Disetujui',
                'verified_by' => 'Demo Finance',
                'verified_at' => now()->subDay(),
            ],
            [
                'no_po' => 'DEMO-PO-0002',
                'no_do' => 'DEMO-DO-0002',
                'tanggal_do' => now()->subDays(2)->toDateString(),
                'tanggal_datang' => now()->toDateString(),
                'nama_supplier' => 'Demo Supplier',
                'nama_gudang' => 'GUDANG DEMO',
                'alamat_gudang' => 'Jl. Purnawarman, Tamansari, Bandung',
                'nama_kurir_ekspedisi' => 'SiCepat Cargo',
                'no_resi' => 'DEMO100000002',
                'penerima_gudang' => 'Demo Admin Gudang',
                'qty_koli' => 2,
                'qty_unit' => 20,
                'total_nominal' => 3500000,
                'keterangan_barang' => 'Contoh barang masuk untuk App Review',
                'status_penerimaan' => 'Kurang',
                'catatan_gudang' => 'Kurang 1 koli dari surat jalan.',
                'status_verifikasi_finance' => 'Menunggu Pemeriksaan',
            ],
        ];

        foreach ($orders as $order) {
            PurchaseOrder::query()->updateOrCreate(
                ['user_id' => $user->id, 'no_po' => $order['no_po']],
                $order,
            );
        }
    }
}
