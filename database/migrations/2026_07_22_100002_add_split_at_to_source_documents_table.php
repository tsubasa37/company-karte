<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('source_documents', function (Blueprint $table) {
            // 口コミソースの分割処理（review_items 生成）が完了した日時。null = 未分割。
            // review 以外の種別では常に null。
            $table->timestamp('split_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('source_documents', function (Blueprint $table) {
            $table->dropColumn('split_at');
        });
    }
};
