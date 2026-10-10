<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            // オファーの回答期限（日付のみ）。複数社並行時の「いつまでに返事」をアプリ内に持つ
            $table->date('offer_deadline')->nullable();
            // 次回面接の日時。「明日どこの面接か」をダッシュボードで出すため時刻まで持つ
            $table->dateTime('next_interview_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn(['offer_deadline', 'next_interview_at']);
        });
    }
};
