<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('schedule_events', function (Blueprint $table) {
            // ScheduleSource（manual/mail）。メール由来の予定にバッジを出し、誤登録に気づけるようにする
            $table->string('source')->default('manual')->after('company_id');
        });
    }

    public function down(): void
    {
        Schema::table('schedule_events', function (Blueprint $table) {
            $table->dropColumn('source');
        });
    }
};
