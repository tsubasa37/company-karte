<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            // 面接前ブリーフィング（生成物・状態）
            $table->json('briefing_json')->nullable();
            $table->timestamp('briefing_requested_at')->nullable();
            $table->timestamp('briefing_generated_at')->nullable();
            $table->text('briefing_error')->nullable();
            // 求人票から構造化抽出した条件（給与レンジ・リモート等）
            $table->json('conditions_json')->nullable();
            // オファー条件（比較用・手入力）
            $table->json('offer_json')->nullable();
            // EDINET コード（有報取得で解決した値のキャッシュ）
            $table->string('edinet_code')->nullable();
            // 労基関係法令違反の公表リスト突合結果（ヒット時のみ note に文脈を保存）
            $table->text('labor_note')->nullable();
            $table->timestamp('labor_checked_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn([
                'briefing_json',
                'briefing_requested_at',
                'briefing_generated_at',
                'briefing_error',
                'conditions_json',
                'offer_json',
                'edinet_code',
                'labor_note',
                'labor_checked_at',
            ]);
        });
    }
};
