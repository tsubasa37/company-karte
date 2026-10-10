<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 外部キー列と絞り込み列のインデックス。
 *
 * SQLite は MySQL と違い外部キー列に自動でインデックスを作らない。企業削除の CASCADE や
 * 「企業ごとの最新ラン」「ステータス別の一覧」が子テーブルの全表走査になっていたため足す。
 * 現在のデータ量では体感差は無く、将来の負債を先に返す変更。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('source_documents', function (Blueprint $table) {
            $table->index(['company_id', 'type']);
        });

        Schema::table('analysis_runs', function (Blueprint $table) {
            $table->index(['company_id', 'status']);
        });

        Schema::table('official_metrics', function (Blueprint $table) {
            $table->index('company_id');
        });

        Schema::table('source_revisions', function (Blueprint $table) {
            $table->index('source_document_id');
        });

        Schema::table('review_items', function (Blueprint $table) {
            $table->index('source_document_id');
        });

        Schema::table('ai_usages', function (Blueprint $table) {
            $table->index('company_id');
        });

        Schema::table('companies', function (Blueprint $table) {
            $table->index('status');
            $table->index('favorite');
        });
    }

    public function down(): void
    {
        Schema::table('source_documents', function (Blueprint $table) {
            $table->dropIndex(['company_id', 'type']);
        });

        Schema::table('analysis_runs', function (Blueprint $table) {
            $table->dropIndex(['company_id', 'status']);
        });

        Schema::table('official_metrics', function (Blueprint $table) {
            $table->dropIndex(['company_id']);
        });

        Schema::table('source_revisions', function (Blueprint $table) {
            $table->dropIndex(['source_document_id']);
        });

        Schema::table('review_items', function (Blueprint $table) {
            $table->dropIndex(['source_document_id']);
        });

        Schema::table('ai_usages', function (Blueprint $table) {
            $table->dropIndex(['company_id']);
        });

        Schema::table('companies', function (Blueprint $table) {
            $table->dropIndex(['status']);
            $table->dropIndex(['favorite']);
        });
    }
};
