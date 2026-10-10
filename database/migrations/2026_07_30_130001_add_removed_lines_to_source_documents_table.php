<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('source_documents', function (Blueprint $table) {
            // SourceTextCleaner が落とした定型行（ナビ・フッター・応募ボタン等）の件数。
            // この機能より前に取り込んだソースは null = 未記録（0件と区別して画面に出す）。
            // 面接メモはクリーニング対象外なので常に null。
            $table->unsignedInteger('removed_lines')->nullable()->after('raw_text');
        });
    }

    public function down(): void
    {
        Schema::table('source_documents', function (Blueprint $table) {
            $table->dropColumn('removed_lines');
        });
    }
};
