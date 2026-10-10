<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('profiles', function (Blueprint $table) {
            // 分析プロンプトに渡る項目（キャリア要約・must/want/NG・軸）が最後に変わった時刻。
            // updated_at だと現年収の変更だけで全社が「再分析が必要」と出て誤報になるため分けて持つ
            $table->timestamp('analysis_relevant_updated_at')->nullable();
        });

        // 既存行は「分析に効く項目の最終更新時刻」が記録されていない。
        // updated_at が唯一の手掛かりなので、それを初期値として移行する
        // （null のまま残すと既存プロファイルで再分析候補が一切出なくなる）
        DB::table('profiles')->update(['analysis_relevant_updated_at' => DB::raw('updated_at')]);
    }

    public function down(): void
    {
        Schema::table('profiles', function (Blueprint $table) {
            $table->dropColumn('analysis_relevant_updated_at');
        });
    }
};
