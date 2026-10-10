<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('profiles', function (Blueprint $table) {
            $table->id();
            $table->text('career_summary')->nullable();   // 職務経歴サマリ
            $table->json('must_json')->nullable();          // 必須条件
            $table->json('want_json')->nullable();          // 歓迎条件
            $table->json('ng_json')->nullable();            // NG条件
            $table->text('axis')->nullable();               // 転職の軸
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('profiles');
    }
};
