<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * メール取り込みの「人が入力する設定」（自分専用アプリなので常に1行）。
 *
 * 設定ファイルではなく画面から入れる。理由は3つ:
 * - OAuth クライアントの値は Google Cloud の画面からコピペするもので、貼り先が画面のほうが素直
 * - 秘密の値を暗号化して持てる（設定ファイルは平文）
 * - 保存した瞬間に効く（設定キャッシュのクリアやプロセス再起動が要らない）
 *
 * 連携の「結果」（アクセストークン等）は google_credentials 側が持つ。設定と状態を混ぜない。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mail_settings', function (Blueprint $table) {
            $table->id();
            // Google Cloud で発行した OAuth クライアント。暗号化して保存する
            $table->text('google_client_id')->nullable();
            $table->text('google_client_secret')->nullable();
            // 取り込む差出人ドメイン（カンマ区切り）。未設定なら1通も取り込まない
            $table->text('allowed_domains')->nullable();
            // codex に渡す前に伏せる語（氏名など）。本人の情報なので暗号化する
            $table->text('mask_words')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mail_settings');
    }
};
