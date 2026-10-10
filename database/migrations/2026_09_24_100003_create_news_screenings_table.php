<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ニュースの機械判定の結果。1 行 = 1 企業 × 記事 1 件。
 * ニュース一覧は保存せず毎回 RSS から取るため、判定結果だけを正規化 URL で引けるように残す。
 * 確率・score の生値は画面に出さず、PHP がしきい値で関連の有無・深刻度を確定するのに使う。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('news_screenings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('url_hash', 64);           // 正規化 URL の sha256（RSS の追跡 ID が変わっても同じ記事を引く）
            $table->text('url');                      // 正規化 URL
            $table->string('title', 255);
            $table->string('source', 255);            // 媒体（記事 URL のホスト名）
            $table->date('published_at')->nullable();
            $table->float('relevant_p');              // 関連度（noul）
            $table->string('kind', 16);               // NewsKind
            $table->float('severity_score');          // 深刻度（段階 3 つの 1 始まりの位置）
            $table->string('model');
            $table->timestamp('judged_at');
            $table->timestamps();

            $table->unique(['company_id', 'url_hash']);
            // SQLite は外部キーに index を自動で作らない（企業削除の cascade で全件走査になる）
            $table->index('company_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('news_screenings');
    }
};
