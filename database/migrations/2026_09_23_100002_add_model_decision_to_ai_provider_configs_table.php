<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 判定専用の実行先（AiKind::Decision）で使うモデル。null は未設定（判定エンジンを選んでも実行時に止まる）。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_provider_configs', function (Blueprint $table) {
            $table->string('model_decision')->nullable()->after('model_light');
        });
    }

    public function down(): void
    {
        Schema::table('ai_provider_configs', function (Blueprint $table) {
            $table->dropColumn('model_decision');
        });
    }
};
