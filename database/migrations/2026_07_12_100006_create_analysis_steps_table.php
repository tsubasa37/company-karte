<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('analysis_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('analysis_run_id')->constrained()->cascadeOnDelete();
            $table->string('step');                     // AnalysisStep（p1..p6）
            $table->string('status')->default('pending'); // StepStatus
            $table->json('output_json')->nullable();    // このステップのLLM構造化出力
            $table->text('error')->nullable();
            $table->timestamps();

            $table->unique(['analysis_run_id', 'step']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('analysis_steps');
    }
};
