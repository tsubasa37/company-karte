<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('source_documents', function (Blueprint $table) {
            $table->string('title')->nullable()->after('url');     // クリップ時のページタイトル（document.title）
            $table->string('summary')->nullable()->after('title'); // クリップ時に AI（ClipClassifier）が生成した一行要約
        });
    }

    public function down(): void
    {
        Schema::table('source_documents', function (Blueprint $table) {
            $table->dropColumn(['title', 'summary']);
        });
    }
};
