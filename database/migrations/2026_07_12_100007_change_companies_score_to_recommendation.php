<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // SQLite は 1 バッチ内での drop + add でテーブル再構築が競合するため 2 バッチに分ける。
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('score');
        });

        Schema::table('companies', function (Blueprint $table) {
            $table->string('recommendation')->nullable()->after('status'); // Recommendation（おすすめ度）
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('recommendation');
        });

        Schema::table('companies', function (Blueprint $table) {
            $table->integer('score')->nullable()->after('status');
        });
    }
};
