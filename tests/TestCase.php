<?php

namespace Tests;

use App\Services\Ai\AiDecider;
use App\Services\Ai\AiRunner;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Tests\Support\ForbiddenAiDecider;
use Tests\Support\ForbiddenAiRunner;

abstract class TestCase extends BaseTestCase
{
    /**
     * テストから本物の AI 実行（codex / API）を絶対に起動させない。
     *
     * テストのキューは sync 実行のため、Queue::fake() を書き忘れると
     * ソース保存が派生ジョブ（口コミ分割・条件抽出）を同期実行し、
     * ChatGPT サブスクを実際に消費してしまう（実際に発生していた）。
     * 黙って消費されると気づけないので、既定を「呼ばれたら例外」にする。
     *
     * AI の戻り値が必要なテストは、これまでどおり AiRunner か
     * それを使うサービス（ClipClassifier 等）を個別にモックして上書きする。
     *
     * Driver は AiRunner を通さずコンテナから直接も取れるため、同じ既定を HTTP 層にも掛ける
     * （Http::fake を書き忘れたテストは本物の API に飛ばず、その場で失敗する）。
     * サブプロセス（現状は CodexDriver）も同様に塞ぐ。Process::fake() を書き忘れたテストが
     * 本物の codex を起動してサブスク枠を消費するのを防ぐ。
     *
     * 判定（AiDecider）も同じ既定にする。判定の戻り値が必要なテストは AiDecider を個別にモックして上書きする。
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(AiRunner::class, new ForbiddenAiRunner);
        $this->app->instance(AiDecider::class, new ForbiddenAiDecider);
        Http::preventStrayRequests();
        Process::preventStrayProcesses();
        $this->forgetApiKeys();
        // 開発サーバー（composer dev）が動いていると Inertia が SSR へ通信しようとし、
        // 上の preventStrayRequests に当たって画面のテストが全滅する。
        // テスト結果が開発サーバーの起動有無で変わらないよう、常にクライアント描画にする
        config()->set('inertia.ssr.enabled', false);
    }

    /**
     * API キーは開発機の .env から読まれるため、そのままだと
     * 「キーを入れている人だけ実行先の候補が増えてテストが落ちる」ことになる。
     * 常に未設定から始め、必要なテストが WithApiKeys で明示的に設定する。
     */
    private function forgetApiKeys(): void
    {
        foreach (array_keys((array) config('ai.providers')) as $provider) {
            config()->set("ai.providers.{$provider}.api_key", null);
        }
    }
}
