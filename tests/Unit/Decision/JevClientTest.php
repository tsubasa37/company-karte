<?php

namespace Tests\Unit\Decision;

use App\Enums\AiProvider;
use App\Services\Ai\AiTarget;
use App\Services\Ai\Decision\ChoiceQuestion;
use App\Services\Ai\Decision\DecisionNotSent;
use App\Services\Ai\Decision\DecisionRequest;
use App\Services\Ai\Decision\JevClient;
use App\Services\Ai\Decision\NoulQuestion;
use App\Services\Ai\Decision\ScoreQuestion;
use App\Services\Ai\Driver\AiRequestFailed;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\WithApiKeys;
use Tests\TestCase;

/**
 * Jev（TypeSafe AI）の判定クライアント。本物には一切飛ばさない（Http::fake + preventStrayRequests）。
 * 「リクエストが API 契約の形になること」「回答が値オブジェクトに載ること」「失敗が例外になること」が要点。
 */
class JevClientTest extends TestCase
{
    use WithApiKeys;

    private const KEY = 'ts-test-key-123';

    private const USAGE = ['input_tokens' => 1234, 'output_tokens' => 0];

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        // 開発機の .env（TYPESAFE_BASE_URL）で結果が変わらないよう既定値を明示する
        config(['ai.providers.jev.base_url' => 'https://api.typesafe.ai/v1']);
        $this->setApiKey(AiProvider::Jev, self::KEY);
    }

    private function client(): JevClient
    {
        return $this->app->make(JevClient::class);
    }

    private function target(?string $model = 'jev-1.13.0'): AiTarget
    {
        return new AiTarget(AiProvider::Jev, $model, [], 10);
    }

    private function request(): DecisionRequest
    {
        return new DecisionRequest('求人票: 残業月10時間。口コミ: 毎日終電。', [
            new ChoiceQuestion('overtime', '求人票と口コミの残業は整合しているか', ['consistent' => '整合', 'contradicts' => '矛盾']),
            new ScoreQuestion('severity', '食い違いの大きさ', ['小', '中', '大']),
            new NoulQuestion('firsthand', '口コミは本人の体験に基づくか'),
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides  回答の差し替え（id => 回答。null で欠落）
     * @return array<string, mixed>
     */
    private function body(array $overrides = [], ?array $usage = self::USAGE): array
    {
        $answers = array_merge([
            'overtime' => ['type' => 'choice', 'choice' => 'contradicts', 'probabilities' => ['consistent' => 0.1, 'contradicts' => 0.9], 'confidence' => 0.85],
            'severity' => ['type' => 'score', 'score' => 2.3, 'probabilities' => [0.1, 0.5, 0.4], 'confidence' => 0.7],
            'firsthand' => ['type' => 'noul', 'noul' => 0.92],
        ], $overrides);

        return array_filter([
            'model' => 'jev-1.13.0',
            'answers' => array_filter($answers, fn ($answer) => $answer !== null),
            'usage' => $usage,
        ], fn ($value) => $value !== null);
    }

    public function test_リクエストはsystemoneにbearerとmodel_state_questionsを送る(): void
    {
        Http::fake(['api.typesafe.ai/*' => Http::response($this->body())]);

        $this->client()->decide($this->request(), $this->target());

        Http::assertSent(function (Request $request) {
            $body = $request->data();

            return $request->url() === 'https://api.typesafe.ai/v1/systemone'
                && $request->method() === 'POST'
                && $request->hasHeader('Authorization', 'Bearer '.self::KEY)
                && $request->hasHeader('Accept', 'application/json')
                && $body['model'] === 'jev-1.13.0'
                && $body['state'] === '求人票: 残業月10時間。口コミ: 毎日終電。'
                && $body['questions'] === [
                    'overtime' => ['type' => 'choice', 'instructions' => '求人票と口コミの残業は整合しているか', 'criteria' => ['consistent' => '整合', 'contradicts' => '矛盾']],
                    'severity' => ['type' => 'score', 'instructions' => '食い違いの大きさ', 'levels' => ['小', '中', '大']],
                    'firsthand' => ['type' => 'noul', 'instructions' => '口コミは本人の体験に基づくか'],
                ];
        });
    }

    public function test_配列のstateはそのままjsonで送る(): void
    {
        Http::fake(['api.typesafe.ai/*' => Http::response($this->body(['overtime' => null, 'severity' => null]))]);

        $this->client()->decide(new DecisionRequest(['review' => '毎日終電'], [new NoulQuestion('firsthand', '体験か')]), $this->target());

        Http::assertSent(fn (Request $request) => $request->data()['state'] === ['review' => '毎日終電']);
    }

    public function test_回答が値オブジェクトに載りusageが正規化される(): void
    {
        Http::fake(['api.typesafe.ai/*' => Http::response($this->body())]);

        $response = $this->client()->decide($this->request(), $this->target());

        $choice = $response->choice('overtime');
        $this->assertSame('contradicts', $choice->choice);
        $this->assertSame(['consistent' => 0.1, 'contradicts' => 0.9], $choice->probabilities);
        $this->assertSame(0.85, $choice->confidence);

        $score = $response->score('severity');
        $this->assertSame(2.3, $score->score);
        $this->assertSame([0.1, 0.5, 0.4], $score->probabilities);
        $this->assertSame(0.7, $score->confidence);

        $this->assertSame(0.92, $response->noul('firsthand')->noul);

        $this->assertSame(AiProvider::Jev, $response->provider);
        $this->assertSame('jev-1.13.0', $response->model);
        $this->assertSame(1234, $response->tokens->input);
        $this->assertSame(0, $response->tokens->output);
        $this->assertNull($response->tokens->cached);
        $this->assertNull($response->tokens->reasoning);
        $this->assertSame(self::USAGE, $response->tokens->raw);
    }

    public function test_scoreのprobabilitiesが段階名の連想配列でもlevelsの並びにそろえる(): void
    {
        // 返る順番が levels と違っても、levels の並びで読む
        Http::fake(['api.typesafe.ai/*' => Http::response($this->body([
            'severity' => ['type' => 'score', 'score' => 2.0, 'probabilities' => ['大' => 0.2, '小' => 0.1, '中' => 0.7], 'confidence' => 0.6],
        ]))]);

        $score = $this->client()->decide($this->request(), $this->target())->score('severity');

        $this->assertSame([0.1, 0.7, 0.2], $score->probabilities);
    }

    public function test_scoreのprobabilitiesの鍵が段階名と違えば課金済みの失敗(): void
    {
        Http::fake(['api.typesafe.ai/*' => Http::response($this->body([
            'severity' => ['type' => 'score', 'score' => 2.0, 'probabilities' => ['low' => 0.2, 'mid' => 0.7, 'high' => 0.1], 'confidence' => 0.6],
        ]))]);

        try {
            $this->client()->decide($this->request(), $this->target());
            $this->fail('例外になるはず');
        } catch (AiRequestFailed $e) {
            $this->assertStringContainsString('severity', $e->getMessage());
            $this->assertStringContainsString('levels', $e->getMessage());
            $this->assertSame(1234, $e->usage->input);
        }
    }

    public function test_応答にmodelが無ければ実行先のモデル名を使う(): void
    {
        $body = $this->body();
        unset($body['model']);
        Http::fake(['api.typesafe.ai/*' => Http::response($body)]);

        $this->assertSame('jev-1.13.0', $this->client()->decide($this->request(), $this->target())->model);
    }

    public function test_usageが無ければtokensはnull(): void
    {
        Http::fake(['api.typesafe.ai/*' => Http::response($this->body(usage: null))]);

        $this->assertNull($this->client()->decide($this->request(), $this->target())->tokens);
    }

    public function test_こちらの質問に無い回答は無視する(): void
    {
        Http::fake(['api.typesafe.ai/*' => Http::response($this->body(['extra' => ['type' => 'noul', 'noul' => 0.1]]))]);

        $response = $this->client()->decide($this->request(), $this->target());

        $this->assertFalse($response->has('extra'));
        $this->assertSame(['overtime', 'severity', 'firsthand'], array_keys($response->answers));
    }

    public function test_401はキーの確認を促す(): void
    {
        Http::fake(['api.typesafe.ai/*' => Http::response(['error' => ['message' => 'Invalid API key']], 401)]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Jev API エラー（HTTP 401）: Invalid API key（TYPESAFE_API_KEY を確認してください）');

        $this->client()->decide($this->request(), $this->target());
    }

    public function test_429は時間をおいた再実行を促す(): void
    {
        Http::fake(['api.typesafe.ai/*' => Http::response(['error' => ['message' => 'Too many requests']], 429)]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('（レート制限です。時間をおいて再実行してください）');

        $this->client()->decide($this->request(), $this->target());
    }

    public function test_500で本文が無くても文言を組める(): void
    {
        Http::fake(['api.typesafe.ai/*' => Http::response('', 500)]);

        try {
            $this->client()->decide($this->request(), $this->target());
            $this->fail('例外になるはず');
        } catch (\RuntimeException $e) {
            // 課金されない失敗なので AiRequestFailed（usage 付き）にはしない
            $this->assertNotInstanceOf(AiRequestFailed::class, $e);
            $this->assertSame('Jev API エラー（HTTP 500）: （error.message なし）', $e->getMessage());
        }
    }

    public function test_errorが文字列の本文でも文言に載せる(): void
    {
        Http::fake(['api.typesafe.ai/*' => Http::response(['error' => 'upstream timeout'], 502)]);

        $this->expectExceptionMessage('Jev API エラー（HTTP 502）: upstream timeout');

        $this->client()->decide($this->request(), $this->target());
    }

    public function test_errorの無いjson本文は先頭を文言に載せる(): void
    {
        // 契約が未検証のうちは、想定外の形のエラー本文も理由を残す（実際の応答と照合するときに切り分けるため）
        Http::fake(['api.typesafe.ai/*' => Http::response(['detail' => [['loc' => ['body', 'questions'], 'msg' => 'field required']]], 422)]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Jev API エラー（HTTP 422）: （error.message なし）本文: {"detail":[{"loc":["body","questions"],"msg":"field required"}]}');

        $this->client()->decide($this->request(), $this->target());
    }

    public function test_errorの無い本文に含まれるキーも伏せる(): void
    {
        Http::fake(['api.typesafe.ai/*' => Http::response(['detail' => 'Invalid API key '.self::KEY], 401)]);

        try {
            $this->client()->decide($this->request(), $this->target());
            $this->fail('例外になるはず');
        } catch (\RuntimeException $e) {
            $this->assertStringNotContainsString(self::KEY, $e->getMessage());
            $this->assertStringContainsString('Invalid API key ***', $e->getMessage());
            $this->assertStringContainsString('（TYPESAFE_API_KEY を確認してください）', $e->getMessage());
        }
    }

    public function test_エラー本文に含まれるキーは伏せる(): void
    {
        Http::fake(['api.typesafe.ai/*' => Http::response(['error' => ['message' => 'bad key '.self::KEY]], 401)]);

        try {
            $this->client()->decide($this->request(), $this->target());
            $this->fail('例外になるはず');
        } catch (\RuntimeException $e) {
            $this->assertStringNotContainsString(self::KEY, $e->getMessage());
            $this->assertStringContainsString('bad key ***', $e->getMessage());
        }
    }

    /**
     * 本文の抜粋の切り詰め境界をキーがまたいでも、キーの先頭部分が文言に残らない（伏せてから切る）
     *
     * @return array<string, array{int}>
     */
    public static function 切り詰め境界をまたぐ本文(): array
    {
        return [
            '4xx で error.message なし' => [500],
            '2xx で JSON でない' => [200],
        ];
    }

    #[DataProvider('切り詰め境界をまたぐ本文')]
    public function test_切り詰め境界をまたぐキーも伏せる(int $status): void
    {
        // 190 字目からキーが始まると、切ってから伏せる順ではキーの先頭 9 字が残る
        Http::fake(['api.typesafe.ai/*' => Http::response(str_repeat('x', 190).self::KEY, $status)]);

        try {
            $this->client()->decide($this->request(), $this->target());
            $this->fail('例外になるはず');
        } catch (\RuntimeException $e) {
            $this->assertStringNotContainsString('ts-test-', $e->getMessage());
            $this->assertStringContainsString(str_repeat('x', 190).'***', $e->getMessage());
        }
    }

    public function test_接続できなければ日本語の例外(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 28: timed out'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Jev API に接続できませんでした: cURL error 28: timed out');

        $this->client()->decide($this->request(), $this->target());
    }

    public function test_2xxでもjsonでない本文は接続先の確認を促す(): void
    {
        Http::fake(['api.typesafe.ai/*' => Http::response('<html>proxy</html>', 200)]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Jev API の応答が JSON ではありません（HTTP 200）。接続先（ai.providers.jev.base_url）を確認してください: <html>proxy</html>');

        $this->client()->decide($this->request(), $this->target());
    }

    public function test_回答が欠けていれば課金済みの失敗(): void
    {
        Http::fake(['api.typesafe.ai/*' => Http::response($this->body(['firsthand' => null]))]);

        $this->assertFailedWithUsage('質問 firsthand の回答がありません');
    }

    public function test_回答の型が質問と食い違えば課金済みの失敗(): void
    {
        Http::fake(['api.typesafe.ai/*' => Http::response($this->body(['firsthand' => ['type' => 'choice', 'choice' => 'x', 'probabilities' => ['x' => 1.0], 'confidence' => 1.0]]))]);

        $this->assertFailedWithUsage('型が質問と食い違います（質問: noul / 回答: choice）');
    }

    public function test_probabilitiesが欠けていれば課金済みの失敗(): void
    {
        Http::fake(['api.typesafe.ai/*' => Http::response($this->body([
            'overtime' => ['type' => 'choice', 'choice' => 'contradicts', 'confidence' => 0.85],
        ]))]);

        $this->assertFailedWithUsage('回答 overtime が不正です: probabilities がありません');
    }

    public function test_数値でない項目は課金済みの失敗(): void
    {
        Http::fake(['api.typesafe.ai/*' => Http::response($this->body(['firsthand' => ['type' => 'noul', 'noul' => '0.9']]))]);

        $this->assertFailedWithUsage('回答 firsthand が不正です: noul が数値ではありません');
    }

    public function test_answersが無ければ課金済みの失敗(): void
    {
        Http::fake(['api.typesafe.ai/*' => Http::response(['model' => 'jev-1.13.0', 'usage' => self::USAGE])]);

        $this->assertFailedWithUsage('answers がありません');
    }

    public function test_モデル名が空なら送信せずに例外(): void
    {
        Http::fake();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Jev はモデル名の指定が必要です（例: jev-1.13.0）');

        try {
            $this->client()->decide($this->request(), $this->target(''));
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_接続先が空なら送信せずに例外(): void
    {
        Http::fake();
        config(['ai.providers.jev.base_url' => '']);

        $this->expectExceptionMessage('ai.providers.jev.base_url');

        try {
            $this->client()->decide($this->request(), $this->target());
        } finally {
            Http::assertNothingSent();
        }
    }

    /**
     * 一括用のリクエスト。state に番号を入れて、fake 側がどの要素かを見分けられるようにする
     *
     * @return list<DecisionRequest>
     */
    private function manyRequests(int $count): array
    {
        return array_map(
            fn (int $i) => new DecisionRequest(['n' => $i], [new NoulQuestion('firsthand', '口コミは本人の体験に基づくか')]),
            range(0, $count - 1),
        );
    }

    /** state の番号 n に応じて noul = n / 10 を返す fake（$override で番号ごとに応答を差し替える） */
    private function fakeMany(array $override = []): void
    {
        Http::fake(function (Request $request) use ($override) {
            $n = $request->data()['state']['n'];

            return $override[$n] ?? Http::response([
                'model' => 'jev-1.13.0',
                'answers' => ['firsthand' => ['type' => 'noul', 'noul' => $n / 10]],
                'usage' => self::USAGE,
            ]);
        });
    }

    public function test_一括はpoolで全件送り同じ添字で回答を返す(): void
    {
        $this->fakeMany();

        $results = $this->client()->decideMany($this->manyRequests(5), $this->target());

        $this->assertSame([0, 1, 2, 3, 4], array_keys($results));
        foreach ($results as $i => $response) {
            $this->assertSame((float) ($i / 10), $response->noul('firsthand')->noul);
            $this->assertSame(1234, $response->tokens->input);
        }

        Http::assertSentCount(5);
        Http::assertSent(fn (Request $request) => $request->url() === 'https://api.typesafe.ai/v1/systemone'
            && $request->hasHeader('Authorization', 'Bearer '.self::KEY)
            && $request->hasHeader('Accept', 'application/json')
            && $request->data()['model'] === 'jev-1.13.0'
            && $request->data()['questions'] === ['firsthand' => ['type' => 'noul', 'instructions' => '口コミは本人の体験に基づくか']]);
    }

    public function test_一括は同時数ごとに区切って区切りの間は直列に送る(): void
    {
        // 同時数 2 で 5 件 → [0,1] [2,3] [4] の 3 回。fake では pool の回数は見えないので送信順で確かめる
        config(['ai.decision.concurrency' => 2]);
        Sleep::fake();
        $this->fakeMany();

        $this->client()->decideMany($this->manyRequests(5), $this->target());

        $sent = array_map(fn (array $pair) => $pair[0]->data()['state']['n'], Http::recorded()->all());
        $this->assertCount(5, $sent);
        $this->assertEqualsCanonicalizing([0, 1], array_slice($sent, 0, 2));
        $this->assertEqualsCanonicalizing([2, 3], array_slice($sent, 2, 2));
        $this->assertSame(4, $sent[4]);
    }

    public function test_一括は1秒あたりの上限を超えないよう区切りの間に待つ(): void
    {
        // 同時数 2・毎秒 2 件 → 1 区切りに最短 1 秒。区切りは 3 つなので間の 2 回だけ待つ（最後の後は待たない）
        config(['ai.decision.concurrency' => 2, 'ai.decision.requests_per_second' => 2]);
        Sleep::fake();
        $this->fakeMany();

        $this->client()->decideMany($this->manyRequests(5), $this->target());

        Sleep::assertSleptTimes(2);
    }

    public function test_一括で失敗した区切りの後は残りを送らない(): void
    {
        config(['ai.decision.concurrency' => 2]);
        Sleep::fake();
        $this->fakeMany([1 => Http::response(['error' => ['message' => 'rate limited']], 429)]);

        $results = $this->client()->decideMany($this->manyRequests(5), $this->target());

        Http::assertSentCount(2);
        $this->assertInstanceOf(\RuntimeException::class, $results[1]);
        foreach ([2, 3, 4] as $i) {
            $this->assertInstanceOf(DecisionNotSent::class, $results[$i]);
        }
        Sleep::assertNeverSlept();
    }

    public function test_一括で1件だけ500ならその要素だけが例外オブジェクトになる(): void
    {
        $this->fakeMany([2 => Http::response(['error' => ['message' => 'internal']], 500)]);

        $results = $this->client()->decideMany($this->manyRequests(4), $this->target());

        $this->assertInstanceOf(\RuntimeException::class, $results[2]);
        $this->assertNotInstanceOf(AiRequestFailed::class, $results[2]);
        $this->assertSame('Jev API エラー（HTTP 500）: internal', $results[2]->getMessage());
        foreach ([0, 1, 3] as $i) {
            $this->assertSame((float) ($i / 10), $results[$i]->noul('firsthand')->noul);
        }
    }

    public function test_一括で回答が欠けた要素は課金済みの失敗になる(): void
    {
        $this->fakeMany([1 => Http::response(['model' => 'jev-1.13.0', 'answers' => [], 'usage' => self::USAGE])]);

        $results = $this->client()->decideMany($this->manyRequests(3), $this->target());

        $this->assertInstanceOf(AiRequestFailed::class, $results[1]);
        $this->assertStringContainsString('質問 firsthand の回答がありません', $results[1]->getMessage());
        $this->assertSame(1234, $results[1]->usage->input);
    }

    public function test_一括でjsonでない本文の要素は接続先の確認を促す例外になる(): void
    {
        $this->fakeMany([0 => Http::response('<html>proxy</html>', 200)]);

        $results = $this->client()->decideMany($this->manyRequests(2), $this->target());

        $this->assertInstanceOf(\RuntimeException::class, $results[0]);
        $this->assertStringContainsString('Jev API の応答が JSON ではありません（HTTP 200）', $results[0]->getMessage());
        $this->assertSame(0.1, $results[1]->noul('firsthand')->noul);
    }

    public function test_一括で接続できなければ要素が日本語の例外になる(): void
    {
        // pool は接続失敗を投げずに ConnectionException を要素として返す
        Http::fake(['api.typesafe.ai/*' => Http::failedConnection('cURL error 28: timed out')]);

        $results = $this->client()->decideMany($this->manyRequests(2), $this->target());

        foreach ($results as $result) {
            $this->assertInstanceOf(\RuntimeException::class, $result);
            $this->assertStringContainsString('Jev API に接続できませんでした: ', $result->getMessage());
            $this->assertStringContainsString('cURL error 28: timed out', $result->getMessage());
            $this->assertInstanceOf(ConnectionException::class, $result->getPrevious());
        }
    }

    public function test_一括でモデル名が空なら送信せずに例外(): void
    {
        Http::fake();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Jev はモデル名の指定が必要です（例: jev-1.13.0）');

        try {
            $this->client()->decideMany($this->manyRequests(2), $this->target(''));
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_一括で接続先が空なら送信せずに例外(): void
    {
        Http::fake();
        config(['ai.providers.jev.base_url' => '']);

        $this->expectExceptionMessage('ai.providers.jev.base_url');

        try {
            $this->client()->decideMany($this->manyRequests(2), $this->target());
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_一括でキーが無ければ送信せずに例外(): void
    {
        Http::fake();
        $this->setApiKey(AiProvider::Jev, null);

        $this->expectExceptionMessage('TYPESAFE_API_KEY');

        try {
            $this->client()->decideMany($this->manyRequests(2), $this->target());
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_一括で同時数の設定が無ければ送信せずに例外(): void
    {
        Http::fake();
        config(['ai.decision.concurrency' => null]);

        $this->expectExceptionMessage('decision.concurrency が設定されていません');

        try {
            $this->client()->decideMany($this->manyRequests(2), $this->target());
        } finally {
            Http::assertNothingSent();
        }
    }

    private function assertFailedWithUsage(string $message): void
    {
        try {
            $this->client()->decide($this->request(), $this->target());
            $this->fail('例外になるはず');
        } catch (AiRequestFailed $e) {
            $this->assertStringContainsString($message, $e->getMessage());
            $this->assertSame(AiProvider::Jev, $e->provider);
            $this->assertSame('jev-1.13.0', $e->model);
            // 応答は返っている（課金済み）ので usage を残す
            $this->assertSame(1234, $e->usage->input);
            $this->assertSame(0, $e->usage->output);
        }
    }
}
