<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('source_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('source_document_id')->constrained()->cascadeOnDelete();
            // 上書きされる前の本文（求人票の文言変化の検知用）
            $table->text('raw_text');
            $table->timestamp('replaced_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('source_revisions');
    }
};
