<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('schedule_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            // ScheduleKind（カジュアル面談/面接/その他）。予定表の色分けと文言に使う
            $table->string('kind');
            // 「2次」「最終（役員）」等の補足。空なら kind のラベルを表示に使う
            $table->string('title')->nullable();
            $table->dateTime('starts_at');
            $table->unsignedSmallInteger('duration_minutes')->nullable();
            // オンラインのURL、または対面の場所
            $table->string('place')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();

            // 予定表の期間絞り込みと時系列ソート
            $table->index('starts_at');
            // 企業ページで1社分を時系列に引く
            $table->index(['company_id', 'starts_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('schedule_events');
    }
};
