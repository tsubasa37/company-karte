<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('legal_number')->nullable();     // 法人番号
            $table->boolean('listed')->default(false);      // 上場フラグ
            $table->string('position')->nullable();         // 応募ポジション
            $table->string('status')->default('kininaru');  // CompanyStatus
            $table->unsignedTinyInteger('score')->nullable(); // 適合スコア 0-100
            $table->text('selection_notes')->nullable();    // 選考メモ（手編集）
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('companies');
    }
};
