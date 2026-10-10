<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('open_issues', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            // どのランの P2 判定から拾った論点か。ランを消しても論点は残す（追跡の履歴が消えるため）
            $table->foreignId('opened_run_id')->nullable()->constrained('analysis_runs')->nullOnDelete();
            // 論点の本文（登録時の P2 の主張文をそのまま固定する）。
            // 再分析で AI の文言が揺れても、追跡中の論点は登録時の文で残す
            $table->text('claim');
            // 同一論点の二重登録を防ぐ照合キー（正規化した claim の sha256）。
            // 文言の揺れまでは吸収しないので、揺れた分は別論点として増える（重複はユーザーが取り下げる）
            $table->string('claim_hash', 64);
            // 登録時点の P2 判定（contradict / split / unverifiable）。判定の変化を見るため固定値として持つ
            $table->string('verdict');
            $table->text('rationale')->nullable();
            // IssueStatus。既定は「未確認」
            $table->string('status')->default('open');
            // 状態を決めた根拠（面接での回答の要約、または手入力のメモ）
            $table->text('resolution_note')->nullable();
            // 状態を「解消」等に変えた根拠になった面接メモ。手動で変えたときは null
            $table->foreignId('resolved_source_document_id')->nullable()->constrained('source_documents')->nullOnDelete();
            // 面接メモとの照合（AI）を最後に走らせた時刻。同じメモで二重に走らせないための判定に使う
            $table->dateTime('matched_at')->nullable();
            $table->dateTime('status_updated_at')->nullable();
            $table->timestamps();

            // 同じ企業に同じ論点を二重登録しない
            $table->unique(['company_id', 'claim_hash']);
            // 企業ページの一覧・ダッシュボードの未解消件数
            $table->index(['company_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('open_issues');
    }
};
