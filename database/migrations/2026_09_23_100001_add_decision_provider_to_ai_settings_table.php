<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 判定専用の実行先（AiKind::Decision）。null は機能オフ。
 * 既定をオフにするため初期値は入れない（既存の分析の挙動を変えない）。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_settings', function (Blueprint $table) {
            $table->string('decision_provider')->nullable()->after('light_provider');
        });
    }

    public function down(): void
    {
        Schema::table('ai_settings', function (Blueprint $table) {
            $table->dropColumn('decision_provider');
        });
    }
};
