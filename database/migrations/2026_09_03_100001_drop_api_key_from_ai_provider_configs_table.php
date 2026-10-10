<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * API キーの置き場所を DB から .env に移す。
 *
 * 他の外部サービスのキー（EDINET_API_KEY / GBIZINFO_API_TOKEN）と揃える判断。
 * 読み取り元は App\Services\Ai\ApiKeys の1本だけにし、この列は残さない
 * （残すと「どちらが本物か」が生まれる。読み取り元は1本にする）。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_provider_configs', function (Blueprint $table) {
            $table->dropColumn('api_key');
        });
    }

    /**
     * 列は戻すが、値は戻らない（暗号化された値ごと落としているため）。
     * ロールバックした場合は .env のキーをそのまま使い続けることになる。
     */
    public function down(): void
    {
        Schema::table('ai_provider_configs', function (Blueprint $table) {
            $table->text('api_key')->nullable()->after('provider');
        });
    }
};
