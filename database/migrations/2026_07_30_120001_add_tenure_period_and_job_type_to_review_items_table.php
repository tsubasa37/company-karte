<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('review_items', function (Blueprint $table) {
            // posted_period（投稿日・回答日）と在籍時期は別物なので分けて持つ。
            // 「2015年〜2025年在籍」の口コミを「2015年の古い声」と誤判定しないため
            $table->string('tenure_period')->nullable()->after('posted_period');
            // p4（口コミ分析）が職種でグループ化するために使う
            $table->string('job_type')->nullable()->after('category');
        });
    }

    public function down(): void
    {
        Schema::table('review_items', function (Blueprint $table) {
            $table->dropColumn(['tenure_period', 'job_type']);
        });
    }
};
