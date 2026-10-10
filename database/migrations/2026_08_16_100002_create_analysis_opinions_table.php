<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('analysis_opinions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('analysis_run_id')->constrained()->cascadeOnDelete();
            $table->string('step');            // AnalysisStep（現状 p2 のみ）
            $table->string('perspective');     // VerdictPerspective（判定者の観点）
            $table->unsignedTinyInteger('panelist_index'); // 1始まり（1人目 = Base）
            $table->json('output_json');       // その判定者の生出力（集計前）
            $table->timestamps();

            // 集計後の結果は analysis_steps に入る。こちらは集計前の生出力を残すためのテーブルで、
            // 「なぜその判定に確定したか」を後から検証し、集計ルールを変えたときに再集計できるようにする。
            $table->unique(['analysis_run_id', 'step', 'panelist_index']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('analysis_opinions');
    }
};
