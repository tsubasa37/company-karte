<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 裏取り（P2）に渡す口コミを機械判定の関連度順に選ぶか。既定はオフ（従来どおり新しい順）。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_settings', function (Blueprint $table) {
            $table->boolean('decision_review_ordering')->default(false)->after('decision_provider');
        });
    }

    public function down(): void
    {
        Schema::table('ai_settings', function (Blueprint $table) {
            $table->dropColumn('decision_review_ordering');
        });
    }
};
