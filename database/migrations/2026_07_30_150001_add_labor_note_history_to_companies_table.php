<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 労基公表事案の突合結果（labor_note）が消えたときの履歴を残す。
 *
 * 厚労省の公表リストは掲載期間が約1年でローテーションするため、次回の突合では
 * 一致しなくなる。labor_note を null にするだけでは「過去に載っていた」事実が
 * 黙って消えるので、消えた内容と日時をここに追記する。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            // [{ note: 消えた注記, reason: 消えた理由, cleared_at: 消えた日時 }, …]
            $table->json('labor_note_history')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('labor_note_history');
        });
    }
};
