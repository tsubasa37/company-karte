<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * codex（ChatGPTサブスク）の実行1回ぶんの記録。
 * 「何にどれだけ使ったか」を後から分解できるようにするための計測専用テーブルで、
 * プロンプト本文・出力本文は保存しない（PIIを二重に持たない）。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('codex_usages', function (Blueprint $table) {
            $table->id();
            // CodexPurpose の値（analysis / clip_classify 等）
            $table->string('purpose');
            // 実行モデル。未指定（codex の既定に任せた）場合は null
            $table->string('model')->nullable();
            // 分析ステップ（p1..p6）など、用途の中の内訳。無ければ null
            $table->string('detail')->nullable();
            // どの企業のための実行か。企業に紐づかない実行（一括抽出など）は null
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $table->integer('duration_ms');
            // トークン数は codex CLI が返さないため、規模の目安として文字数を持つ
            $table->integer('prompt_chars');
            $table->integer('output_chars');
            $table->boolean('succeeded');
            $table->text('error')->nullable();
            $table->timestamps();

            // 集計は「期間 × 用途」で引くことが多い
            $table->index(['created_at', 'purpose']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('codex_usages');
    }
};
