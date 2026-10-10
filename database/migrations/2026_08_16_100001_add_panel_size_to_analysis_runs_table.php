<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('analysis_runs', function (Blueprint $table) {
            // P2（裏取り）を何人で判定したか。既定1（多重化前と同じ挙動）。
            // 実行ごとに選ぶ値なので設定ではなくランに持たせ、後から「このカルテは何人で判定したか」を辿れるようにする。
            // 既存ランは 1 で埋まり、実際に1人で判定した事実と一致する。
            $table->unsignedTinyInteger('panel_size')->default(1)->after('current_step');
        });
    }

    public function down(): void
    {
        Schema::table('analysis_runs', function (Blueprint $table) {
            $table->dropColumn('panel_size');
        });
    }
};
