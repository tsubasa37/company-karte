<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Gmail 取得に使う OAuth トークンの置き場（自分専用アプリなので常に1行）。
 * 画面のログイン機能ではなく、一度きりの認可で得たリフレッシュトークンを持つだけ。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('google_credentials', function (Blueprint $table) {
            $table->id();
            // 連携した Google アカウント（どのアカウントに繋がっているかを画面に出すため）
            $table->string('email')->nullable();
            $table->text('refresh_token');
            $table->text('access_token')->nullable();
            $table->dateTime('access_token_expires_at')->nullable();
            $table->dateTime('last_fetched_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('google_credentials');
    }
};
