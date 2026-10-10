<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * メールの取り込み方法（自動 / 手動）。
 *
 * 既定は false（手動）。連携しただけで15分おきの取得が始まると、
 * 「押していないのに抽出・判定の AI が走る＝知らないうちに課金される」状態になるため、
 * 自動にするかどうかは必ず人が画面で選ぶ。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mail_settings', function (Blueprint $table) {
            $table->boolean('auto_fetch')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('mail_settings', function (Blueprint $table) {
            $table->dropColumn('auto_fetch');
        });
    }
};
