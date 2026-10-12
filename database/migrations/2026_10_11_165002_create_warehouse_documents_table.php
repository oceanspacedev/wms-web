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
        // Dokumen (Memo/DO) yang diunggah petugas gudang lalu diteruskan ke SCM Arsip
        Schema::create('warehouse_documents', function (Blueprint $table) {
            $table->id();
            // Kunci unik saat dikirim ke SCM Arsip agar kiriman ulang tidak membuat dokumen ganda
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('kode_gudang')->index();
            $table->string('arsip_program_id');
            $table->string('po_sj_number')->nullable();
            $table->string('program_title')->nullable();
            $table->string('document_type')->default('memo_do');
            $table->string('file_path');
            $table->string('original_name');
            $table->unsignedBigInteger('file_size')->default(0);
            $table->string('status')->default('pending')->index(); // pending, sent, failed
            $table->string('arsip_document_id')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('warehouse_documents');
    }
};
