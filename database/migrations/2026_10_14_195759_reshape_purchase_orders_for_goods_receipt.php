<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A PO is recorded once, by the warehouse, when the goods arrive: the supplier
 * DO, the courier resi (when there is one) and a GPS-stamped receiving photo.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->renameColumn('no_sj_supplier', 'no_do');
            $table->renameColumn('tanggal_po', 'tanggal_do');
            $table->renameColumn('bukti_serah_terima', 'foto_do');
        });

        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->index('no_do');
            $table->string('foto_resi')->nullable()->after('foto_do');
            $table->string('foto_penerimaan')->nullable()->after('foto_resi');
            $table->text('address')->nullable()->after('foto_penerimaan');
            $table->decimal('latitude', 10, 7)->nullable()->after('address');
            $table->decimal('longitude', 11, 7)->nullable()->after('latitude');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->dropIndex(['no_do']);
            $table->dropColumn(['foto_resi', 'foto_penerimaan', 'address', 'latitude', 'longitude']);
        });

        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->renameColumn('no_do', 'no_sj_supplier');
            $table->renameColumn('tanggal_do', 'tanggal_po');
            $table->renameColumn('foto_do', 'bukti_serah_terima');
        });
    }
};
