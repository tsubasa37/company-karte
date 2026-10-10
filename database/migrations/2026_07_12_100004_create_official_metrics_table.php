<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('official_metrics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('source');               // MetricSource（edinet/shokuba）
            $table->string('key');                  // 項目（平均年間給与 等）
            $table->string('value');                // 値（文字列で保持）
            $table->string('unit')->nullable();
            $table->string('fiscal_year')->nullable();
            $table->string('source_url')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('official_metrics');
    }
};
