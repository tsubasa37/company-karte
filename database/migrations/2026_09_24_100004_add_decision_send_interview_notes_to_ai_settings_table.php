<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 面接メモを判定エンジンに送り、論点の消し込みを機械判定にするか（同意スイッチ）。
 * 面接メモは最も個人的なデータなので、判定エンジンを設定済みでも既定はオフ（従来どおり LLM で判定）。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_settings', function (Blueprint $table) {
            $table->boolean('decision_send_interview_notes')->default(false)->after('decision_review_ordering');
        });
    }

    public function down(): void
    {
        Schema::table('ai_settings', function (Blueprint $table) {
            $table->dropColumn('decision_send_interview_notes');
        });
    }
};
