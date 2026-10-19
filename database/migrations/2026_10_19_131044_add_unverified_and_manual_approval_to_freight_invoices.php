<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('freight_invoices', function (Blueprint $table) {
            // Tagihan resi yang tidak ada di Data Pengiriman: belum bisa dinilai, dipisah dari koreksi
            $table->decimal('total_unverified_amount', 15, 2)->default(0)->after('total_discrepancy_amount');
        });

        Schema::table('freight_invoice_items', function (Blueprint $table) {
            // Persetujuan manual admin untuk kasus kesepakatan khusus (charter, tarif khusus, dll.)
            $table->decimal('manual_approved_amount', 15, 2)->nullable()->after('audit_notes');
            $table->text('manual_note')->nullable()->after('manual_approved_amount');
            $table->foreignId('manual_approved_by')->nullable()->after('manual_note')->constrained('users')->nullOnDelete();
            $table->timestamp('manual_approved_at')->nullable()->after('manual_approved_by');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('freight_invoice_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('manual_approved_by');
            $table->dropColumn(['manual_approved_amount', 'manual_note', 'manual_approved_at']);
        });

        Schema::table('freight_invoices', function (Blueprint $table) {
            $table->dropColumn('total_unverified_amount');
        });
    }
};
