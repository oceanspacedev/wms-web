<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Dokumen mutasi antar gudang dari saran Alokasi Stok yang sudah disetujui: satu dokumen per rute (dari → ke).
     */
    public function up(): void
    {
        Schema::create('stock_mutations', function (Blueprint $table) {
            $table->id();
            $table->string('number')->unique();
            $table->foreignId('stock_allocation_id')->constrained()->cascadeOnDelete();
            $table->string('region');
            $table->string('from_warehouse');
            $table->string('to_warehouse');
            $table->string('status', 20)->default('prepared');
            $table->timestamp('shipped_at')->nullable();
            $table->string('shipped_via')->nullable();
            $table->foreignId('shipped_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('received_at')->nullable();
            $table->text('received_note')->nullable();
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('stock_mutation_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_mutation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('stock_allocation_transfer_id')->nullable()->constrained()->nullOnDelete();
            $table->string('sku');
            $table->string('product_name');
            $table->unsignedInteger('qty');
            $table->unsignedInteger('received_qty')->nullable();
            $table->timestamps();
        });

        Schema::table('stock_allocation_transfers', function (Blueprint $table) {
            $table->foreignId('stock_mutation_id')->nullable()->after('status')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('stock_allocation_transfers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('stock_mutation_id');
        });

        Schema::dropIfExists('stock_mutation_items');
        Schema::dropIfExists('stock_mutations');
    }
};
