<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * メール取り込みの失敗を「記録して次回に持ち越さない」ための列。
 *
 * - mail_ingests.error: AI 抽出を通した後に登録で失敗した理由。行が残ることで次回の取得が
 *   同じメールに AI を使い直さない（15分ごとに黙って費用が増えるのを止める）。null なら成功した取り込み
 * - google_credentials.last_fetch_error(_at): 取得処理（一覧取得・トークン更新）自体の直近の失敗。
 *   スケジューラの失敗はログにしか出ず画面から分からなかったため、設定画面と履歴画面に出す
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mail_ingests', function (Blueprint $table) {
            $table->text('error')->nullable()->after('reviewed');
        });

        Schema::table('google_credentials', function (Blueprint $table) {
            $table->text('last_fetch_error')->nullable()->after('last_fetched_at');
            $table->dateTime('last_fetch_error_at')->nullable()->after('last_fetch_error');
        });
    }

    public function down(): void
    {
        Schema::table('mail_ingests', function (Blueprint $table) {
            $table->dropColumn('error');
        });

        Schema::table('google_credentials', function (Blueprint $table) {
            $table->dropColumn(['last_fetch_error', 'last_fetch_error_at']);
        });
    }
};
