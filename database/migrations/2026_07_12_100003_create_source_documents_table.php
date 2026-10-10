<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('source_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('type');                 // SourceType（求人票/口コミ/しょくばらぼ/EDINET/ニュース/その他）
            $table->string('origin');               // SourceOrigin（paste/url）
            $table->string('url')->nullable();
            $table->longText('raw_text');           // 本文（貼付 or URL抽出）
            $table->timestamp('fetched_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('source_documents');
    }
};
