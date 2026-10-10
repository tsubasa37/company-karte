<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // EDINET 書類一覧のローカルキャッシュ（有価証券報告書のみ保持）。
        // EDINET API は企業単位の検索が無く日付単位でしか引けないため、
        // 一度走査した日付の有報をここに貯め、法人番号(JCN)で探せるようにする。
        Schema::create('edinet_documents', function (Blueprint $table) {
            $table->id();
            $table->date('doc_date')->index();
            $table->string('doc_id')->unique();
            $table->string('edinet_code')->nullable();
            $table->string('jcn')->nullable()->index();
            $table->string('period_end')->nullable();
            $table->string('filer_name')->nullable();
            $table->timestamps();
        });

        // 走査済みの日付（結果0件の日を再走査しないための記録）
        Schema::create('edinet_scan_logs', function (Blueprint $table) {
            $table->id();
            $table->date('scan_date')->unique();
            $table->timestamps();
        });

        Schema::table('companies', function (Blueprint $table) {
            // EDINET取得の状態（requested_at != null = 取得中）
            $table->timestamp('edinet_requested_at')->nullable();
            $table->timestamp('edinet_fetched_at')->nullable();
            $table->text('edinet_error')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('edinet_documents');
        Schema::dropIfExists('edinet_scan_logs');
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn(['edinet_requested_at', 'edinet_fetched_at', 'edinet_error']);
        });
    }
};
