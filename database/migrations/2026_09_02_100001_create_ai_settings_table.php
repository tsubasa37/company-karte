<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AI 実行先の選択（用途の重さ Tier → プロバイダ）。自分専用アプリなので常に1行。
 *
 * 設定ファイルではなく DB に置く理由は mail_settings と同じ: 画面から変えられ、保存した瞬間に効く。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_settings', function (Blueprint $table) {
            $table->id();
            // 分析・要約（P1〜P5、逆質問、ブリーフィング）の実行先。AiProvider の値
            $table->string('analysis_provider');
            // 抽出・判定（クリップ、口コミ分割、条件抽出、メール）の実行先。AiProvider の値
            $table->string('light_provider');
            $table->timestamps();
        });

        // 初期行。現状の挙動（全て codex 実行）と同じ状態から始める
        DB::table('ai_settings')->insert([
            'analysis_provider' => 'codex',
            'light_provider' => 'codex',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_settings');
    }
};
