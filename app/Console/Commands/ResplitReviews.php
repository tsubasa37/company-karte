<?php

namespace App\Console\Commands;

use App\Enums\SourceType;
use App\Jobs\SplitReviewSourceJob;
use App\Models\SourceDocument;
use Illuminate\Console\Command;

/**
 * 既存の口コミソースを全件再分割する。
 * 分割スキーマに項目を足した（投稿時期と在籍時期の分離・職種）ときなど、
 * 既存 review_items が新しい項目を持たないままになるのを解消するために使う。
 * 1ソースにつき AI 実行が1回走るため、実行はユーザーの判断に委ねる（--dry-run で件数だけ確認できる）。
 * 実行先は設定画面の「抽出・判定」で選んだもの（codex ならサブスク枠、API なら従量課金）。
 */
class ResplitReviews extends Command
{
    protected $signature = 'karte:resplit-reviews
        {--dry-run : 対象件数だけ表示して何も変更しない}
        {--force : 確認プロンプトを省略する}';

    protected $description = '口コミソースを再分割する（投稿時期/在籍時期/職種の抽出をやり直す。AI 実行を消費する）';

    public function handle(): int
    {
        $sources = SourceDocument::where('type', SourceType::Review)->orderBy('id')->get();
        $count = $sources->count();

        if ($count === 0) {
            $this->info('再分割の対象になる口コミソースはありません。');

            return self::SUCCESS;
        }

        $this->warn("対象の口コミソース: {$count}件");
        $this->warn("再分割は1ソースにつき AI を1回実行します（合計 {$count}回分）。");
        $this->warn('実行先は設定画面の「抽出・判定」で選んだものです。API を選んでいる場合は課金が発生します。');

        if ($this->option('dry-run')) {
            $this->info('--dry-run のため何も変更していません。');

            return self::SUCCESS;
        }

        if (! $this->option('force')
            && ! $this->confirm("{$count}件を再分割しますか？（既存の分割結果は作り直されます）")) {
            $this->info('中止しました。');

            return self::FAILURE;
        }

        foreach ($sources as $source) {
            // クリップ投入時と同じ正規パス（ジョブ）に乗せる。
            // split_at を null に戻すので、キューが動かなくても次の分析実行時に分割し直される
            $source->update(['split_at' => null]);
            SplitReviewSourceJob::dispatch($source->id);
        }

        $this->info("{$count}件の分割ジョブを投入しました（php artisan queue:work で処理されます）。");

        return self::SUCCESS;
    }
}
