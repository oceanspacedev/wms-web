<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Saran alokasi stok antar gudang: satu upload data stok/penjualan, isi per gudang × SKU, dan saran transfernya.
     */
    public function up(): void
    {
        Schema::create('stock_allocations', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('file_name')->nullable();
            $table->unsignedSmallInteger('target_dos')->default(30);
            $table->unsignedInteger('min_transfer_qty')->default(2);
            $table->unsignedInteger('warehouse_count')->default(0);
            $table->unsignedInteger('sku_count')->default(0);
            $table->unsignedInteger('transfer_count')->default(0);
            $table->unsignedInteger('transfer_qty')->default(0);
            $table->unsignedInteger('po_qty_before')->default(0);
            $table->unsignedInteger('po_qty_after')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('stock_allocation_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_allocation_id')->constrained()->cascadeOnDelete();
            $table->string('region');
            $table->string('warehouse');
            $table->string('sku');
            $table->string('product_name');
            $table->integer('stock')->default(0);
            $table->integer('incoming')->default(0);
            $table->integer('sales_qty')->default(0);
            $table->unsignedSmallInteger('sales_days')->default(30);
            $table->decimal('avg_daily_sales', 12, 2)->default(0);
            $table->decimal('dos_before', 10, 1)->nullable();
            $table->decimal('dos_after', 10, 1)->nullable();
            $table->string('status', 20);
            $table->unsignedInteger('need_qty')->default(0);
            $table->unsignedInteger('transfer_in')->default(0);
            $table->unsignedInteger('transfer_out')->default(0);
            $table->unsignedInteger('po_qty')->default(0);
            $table->timestamps();

            $table->index(['stock_allocation_id', 'region', 'sku']);
            $table->index(['stock_allocation_id', 'warehouse']);
        });

        Schema::create('stock_allocation_transfers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_allocation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('from_line_id')->constrained('stock_allocation_lines')->cascadeOnDelete();
            $table->foreignId('to_line_id')->constrained('stock_allocation_lines')->cascadeOnDelete();
            $table->string('region');
            $table->string('sku');
            $table->string('product_name');
            $table->string('from_warehouse');
            $table->string('to_warehouse');
            $table->unsignedInteger('suggested_qty');
            $table->unsignedInteger('qty');
            $table->string('status', 20)->default('pending');
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            $table->index(['stock_allocation_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_allocation_transfers');
        Schema::dropIfExists('stock_allocation_lines');
        Schema::dropIfExists('stock_allocations');
    }
};
