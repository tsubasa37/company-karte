<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 口コミの機械判定（RJ）の結果。1 行 = 1 ラン × 口コミ 1 件 × 主張 1 件。
 * 確率は画面に出さず、主張ごとの件数の集計と材料選抜の並び順にだけ使う。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('review_judgments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('analysis_run_id')->constrained()->cascadeOnDelete();
            $table->foreignId('review_item_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('claim_index');  // P1 の claims の添字
            $table->string('claim_hash', 64);             // P1 の主張本文の sha256（添字と本文の対応を後から確かめる）
            $table->string('relation', 16);               // contradict / support / none / unsure（PHP がしきい値で確定）
            $table->float('p_contradict');
            $table->float('p_support');
            $table->float('p_none');
            $table->float('confidence');
            $table->string('model');
            $table->timestamps();

            $table->unique(['analysis_run_id', 'review_item_id', 'claim_index']);
            $table->index(['analysis_run_id', 'claim_index']);
            // SQLite は外部キーに index を自動で作らない（口コミ削除の cascade で全件走査になる）
            $table->index('review_item_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('review_judgments');
    }
};
