<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 分析ランが「どの材料を見て作られたか」を記録する列。
 *
 * 鮮度（再分析が必要か）を終了時刻と材料の更新時刻の比較だけで見ると、
 * 分析中（開始〜完了の間）に投入・更新した材料は「完了より古い」と扱われて見逃し、
 * 分析後に削除した材料は比較対象の値が動かないため検知できない。
 * 開始時点の材料（ID と更新時刻）を残し、現在の材料との差分で判定する。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('analysis_runs', function (Blueprint $table) {
            $table->json('materials_json')->nullable()->after('panel_size');
        });
    }

    public function down(): void
    {
        Schema::table('analysis_runs', function (Blueprint $table) {
            $table->dropColumn('materials_json');
        });
    }
};
