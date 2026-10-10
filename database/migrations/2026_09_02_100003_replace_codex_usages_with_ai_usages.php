<?php

use App\Enums\AiProvider;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * codex_usages を ai_usages に置き換える（AI 実行1回ぶんの計測。プロバイダ横断）。
 *
 * rename ではなく「新表を作る → 行をコピー → 旧表を drop」の3手にする。
 * provider を default なしの NOT NULL にでき、既存行の埋め値（全行 codex）が migration に明示され、
 * index 名も新表に揃うため。
 *
 * Laravel は SQLite の migration を自動でトランザクションに包まないため、3手を DB::transaction() で包む
 * （SQLite は DDL もロールバックできる）。
 */
return new class extends Migration
{
    /** 旧表と共通の列（コピー対象）。provider・トークン・費用列は新表にしか無い */
    private const COMMON_COLUMNS = [
        'id',
        'purpose',
        'model',
        'detail',
        'company_id',
        'duration_ms',
        'prompt_chars',
        'output_chars',
        'succeeded',
        'error',
        'created_at',
        'updated_at',
    ];

    public function up(): void
    {
        DB::transaction(function () {
            Schema::create('ai_usages', function (Blueprint $table) {
                $table->id();
                // 実行先（AiProvider の値）。default を持たせず、記録側に必ず書かせる
                $table->string('provider');
                // 用途（analysis / clip_classify 等）
                $table->string('purpose');
                // 実行モデル。未指定（プロバイダの既定に任せた）場合は null
                $table->string('model')->nullable();
                // 分析ステップ（p1..p6）など、用途の中の内訳。無ければ null
                $table->string('detail')->nullable();
                // どの企業のための実行か。企業に紐づかない実行（一括抽出など）は null
                $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
                $table->integer('duration_ms');
                // 規模の目安としての文字数（トークンが取れないプロバイダでも残る）
                $table->integer('prompt_chars');
                $table->integer('output_chars');
                // トークン数。取れなかった実行は null（0 と区別する）。
                // input = 全入力（cached・cache_write を含む）/ output = 課金対象の全出力（reasoning を含む）
                $table->integer('input_tokens')->nullable();
                $table->integer('cached_input_tokens')->nullable();
                $table->integer('cache_write_input_tokens')->nullable();
                $table->integer('output_tokens')->nullable();
                $table->integer('reasoning_tokens')->nullable();
                // 推定費用（マイクロ USD の整数）。SQLite では decimal が REAL になり SUM に丸め誤差が出るため整数で持つ。
                // 単価未登録・トークン不明は null（0 で埋めない）
                $table->bigInteger('cost_micro_usd')->nullable();
                // プロバイダが返した usage の生値。正規化の検証と再計算のために残す
                $table->json('usage_raw')->nullable();
                $table->boolean('succeeded');
                $table->text('error')->nullable();
                $table->timestamps();

                // 集計は「期間 × 用途」「期間 × プロバイダ」で引く
                $table->index(['created_at', 'purpose']);
                $table->index(['created_at', 'provider']);
            });

            // 既存行は全て codex 実行だったので provider='codex' で埋める。id も保つ
            DB::table('ai_usages')->insertUsing(
                [...self::COMMON_COLUMNS, 'provider'],
                DB::table('codex_usages')->select(self::COMMON_COLUMNS)->selectRaw("'codex' as provider"),
            );

            Schema::drop('codex_usages');
        });
    }

    public function down(): void
    {
        // 旧表には実行先の区別が無い。API の実行行をそのまま戻すと codex の実行として復元され、
        // 以後の使用状況・見積もりが狂う（費用とトークンの列も失われる）。黙って化かさず止める
        $foreign = DB::table('ai_usages')->where('provider', '!=', AiProvider::Codex->value)->count();

        if ($foreign > 0) {
            throw new RuntimeException(
                "ai_usages に codex 以外の実行が {$foreign} 件あります。旧表 codex_usages には実行先の区別が無いため、"
                .'このロールバックは実行先とトークン・費用の記録を失います。必要なら先に ai_usages を退避してください'
            );
        }

        DB::transaction(function () {
            // 旧定義で作り直す（2026_08_02_120001_create_codex_usages_table と同じ）
            Schema::create('codex_usages', function (Blueprint $table) {
                $table->id();
                $table->string('purpose');
                $table->string('model')->nullable();
                $table->string('detail')->nullable();
                $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
                $table->integer('duration_ms');
                $table->integer('prompt_chars');
                $table->integer('output_chars');
                $table->boolean('succeeded');
                $table->text('error')->nullable();
                $table->timestamps();
                $table->index(['created_at', 'purpose']);
            });

            // 旧列だけをコピーする。provider・トークン・費用列は旧表に無いので捨てる
            // （旧表に実行先の区別は無いため、codex 以外の実行行もそのまま入る）
            DB::table('codex_usages')->insertUsing(
                self::COMMON_COLUMNS,
                DB::table('ai_usages')->select(self::COMMON_COLUMNS),
            );

            Schema::drop('ai_usages');
        });
    }
};
