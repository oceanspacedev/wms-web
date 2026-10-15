<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Owner name column per table, used to link existing rows to their user.
     *
     * @var array<string, string>
     */
    private const OWNER_NAME_COLUMNS = [
        'tracking_orders' => 'nama_pengirim',
        'purchase_orders' => 'penerima_gudang',
    ];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        foreach (self::OWNER_NAME_COLUMNS as $tableName => $nameColumn) {
            Schema::table($tableName, function (Blueprint $table) {
                // Akun yang mencatat data dari aplikasi mobile; API hanya menampilkan data milik akun ini
                $table->foreignId('user_id')->nullable()->after('id')->constrained()->nullOnDelete();
            });

            // Data lama hanya menyimpan nama, jadi dicocokkan ke users.name / users.username
            DB::table($tableName)
                ->whereNull('user_id')
                ->whereNotNull($nameColumn)
                ->update([
                    'user_id' => DB::raw("(select users.id from users where users.name = {$tableName}.{$nameColumn} or users.username = {$tableName}.{$nameColumn} order by users.id limit 1)"),
                ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach (array_keys(self::OWNER_NAME_COLUMNS) as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->dropConstrainedForeignId('user_id');
            });
        }
    }
};
