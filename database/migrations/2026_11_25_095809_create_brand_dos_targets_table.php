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
        Schema::create('brand_dos_targets', function (Blueprint $table) {
            $table->id();
            $table->string('company', 50)->nullable()->index();
            $table->string('brand', 100)->index();
            $table->string('label', 120);
            $table->string('segment', 20)->default('all');
            $table->string('region_scope', 80)->nullable();
            $table->unsignedSmallInteger('target_dos')->nullable();
            $table->boolean('is_active')->default(true);
            $table->string('notes', 255)->nullable();
            $table->timestamps();

            $table->index(['brand', 'is_active'], 'brand_dos_targets_brand_active_index');
            $table->unique(['company', 'label'], 'brand_dos_targets_company_label_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('brand_dos_targets');
    }
};
