<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('review_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('source_document_id')->constrained()->cascadeOnDelete();
            $table->text('body');
            $table->string('stance')->nullable();
            $table->string('posted_period')->nullable();
            $table->string('category')->nullable();
            $table->string('body_hash', 40);
            $table->timestamps();

            // 同じ口コミが複数ページ（複数ソース）に重複して表示されても企業内で1件に保つ
            $table->unique(['company_id', 'body_hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('review_items');
    }
};
