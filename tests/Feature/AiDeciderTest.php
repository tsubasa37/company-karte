<?php

namespace Tests\Feature;

use App\Enums\AiProvider;
use App\Enums\AiPurpose;
use App\Models\AiProviderConfig;
use App\Models\AiSetting;
use App\Models\AiUsage;
use App\Models\Company;
use App\Services\Ai\AiDecider;
use App\Services\Ai\AiPricing;
use App\Services\Ai\AiSettings;
use App\Services\Ai\Decision\ChoiceQuestion;
use App\Services\Ai\Decision\DecisionDriverRegistry;
use App\Services\Ai\Decision\DecisionEngineNotConfigured;
use App\Services\Ai\Decision\DecisionRequest;
use App\Services\Ai\Decision\NoulQuestion;
use App\Services\Ai\Decision\ScoreQuestion;
use App\Services\Ai\Driver\AiRequestFailed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\Support\ForbiddenAiDecider;
use Tests\Support\WithApiKeys;
use Tests\TestCase;

/**
 * 判定の正規パス。「未設定なら通信せず機能オフ」「設定済みの失敗は例外で黙って戻さない」
 * 「成功も失敗も ai_usages に残る」ことが要点。本物には一切飛ばさない（Http::fake + preventStrayRequests）。
 */
class AiDeciderTest extends TestCase
{
    use RefreshDatabase;
    use WithApiKeys;

    private const USAGE = ['input_tokens' => 1000, 'output_tokens' => 0];

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        // 開発機の .env で結果が変わらないよう、接続先と上限の既定値を明示する
        config([
            'ai.providers.jev.base_url' => 'https://api.typesafe.ai/v1',
            'ai.providers.jev.timeout' => 10,
            'ai.decision.limits' => ['max_questions' => 60, 'max_choice_options' => 255, 'max_state_chars' => 20000],
        ]);
    }

    /** TestCase は AiDecider を ForbiddenAiDecider に束縛しているため、本物を直接組み立てる */
    private function decider(): AiDecider
    {
        return new AiDecider(
            $this->app->make(AiSettings::class),
            $this->app->make(DecisionDriverRegistry::class),
            new AiPricing,
        );
    }

    private function configure(?string $model = 'jev-1.13.0', ?string $key = 'ts-test-key'): void
    {
        $this->setApiKey(AiProvider::Jev, $key);
        AiSetting::sole()->update(['decision_provider' => AiProvider::Jev]);
        AiProviderConfig::create(['provider' => AiProvider::Jev, 'model_decision' => $model]);
    }

    private function request(): DecisionRequest
    {
        return new DecisionRequest('求人票: 残業月10時間。口コミ: 毎日終電。', [
            new ChoiceQuestion('overtime', '残業は整合しているか', ['consistent' => '整合', 'contradicts' => '矛盾']),
            new ScoreQuestion('severity', '食い違いの大きさ', ['小', '中', '大']),
            new NoulQuestion('firsthand', '本人の体験か'),
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function body(array $overrides = []): array
    {
        return [
            'model' => 'jev-1.13.0',
            'answers' => array_merge([
                'overtime' => ['type' => 'choice', 'choice' => 'contradicts', 'probabilities' => ['consistent' => 0.1, 'contradicts' => 0.9], 'confidence' => 0.85],
                'severity' => ['type' => 'score', 'score' => 2.3, 'probabilities' => [0.1, 0.5, 0.4], 'confidence' => 0.7],
                'firsthand' => ['type' => 'noul', 'noul' => 0.92],
            ], $overrides),
            'usage' => self::USAGE,
        ];
    }

    public function test_未設定なら通信せずに判定エンジン未設定の例外(): void
    {
        Http::fake();
        $this->setApiKey(AiProvider::Jev, 'ts-test-key');

        $this->assertFalse($this->decider()->isConfigured(), '既定はオフ');

        try {
            $this->decider()->decide($this->request(), AiPurpose::Analysis);
            $this->fail('例外になるはず');
        } catch (DecisionEngineNotConfigured $e) {
            // 判定エンジンを選ぶ画面ができたので、案内先を保存口（PUT）から設定画面に戻した
            $this->assertSame('判定エンジンが未設定です。設定画面の「AIエンジン」→「判定エンジン」で実行先を選んで保存してください', $e->getMessage());
        }

        Http::assertNothingSent();
        $this->assertSame(0, AiUsage::count());
    }

    public function test_キーが無ければ変数名つきの例外で通信しない(): void
    {
        Http::fake();
        $this->configure(key: null);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('TYPESAFE_API_KEY');

        try {
            $this->decider()->decide($this->request(), AiPurpose::Analysis);
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_判定用モデルが無ければ例外で通信しない(): void
    {
        Http::fake();
        $this->configure(model: null);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('判定用モデルが未設定です');

        try {
            $this->decider()->decide($this->request(), AiPurpose::Analysis);
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_質問が上限を超えれば通信前に落とす(): void
    {
        $questions = array_map(fn (int $i) => new NoulQuestion("q{$i}", '判定'), range(1, 61));

        $this->assertRejectedBeforeSending(new DecisionRequest('材料', $questions), '判定の質問が多すぎます（61 件。上限 60 件）');
    }

    public function test_候補が上限を超えれば通信前に落とす(): void
    {
        $criteria = [];
        foreach (range(1, 256) as $i) {
            $criteria["opt{$i}"] = "候補{$i}";
        }

        $this->assertRejectedBeforeSending(
            new DecisionRequest('材料', [new ChoiceQuestion('many', '選ぶ', $criteria)]),
            '判定の質問 many の候補が多すぎます（256 個。上限 255 個）',
        );
    }

    public function test_stateが上限を超えれば通信前に落とす(): void
    {
        $this->assertRejectedBeforeSending(
            new DecisionRequest(str_repeat('あ', 20001), [new NoulQuestion('q1', '判定')]),
            '判定の state が長すぎます（20,001 字。上限 20,000 字）',
        );
    }

    public function test_上限の設定が無ければ例外(): void
    {
        Http::fake();
        $this->configure();
        config(['ai.decision.limits' => null]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('decision.limits.max_questions が設定されていません');

        try {
            $this->decider()->decide($this->request(), AiPurpose::Analysis);
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_成功した判定が応答で返りai_usagesに記録される(): void
    {
        $company = Company::create(['name' => '株式会社テスト']);
        $this->configure();
        Http::fake(['api.typesafe.ai/*' => Http::response($this->body())]);

        $response = $this->decider()->decide($this->request(), AiPurpose::Analysis, 'p1', $company->id);

        $this->assertTrue($this->decider()->isConfigured());
        $this->assertSame('contradicts', $response->choice('overtime')->choice);
        $this->assertSame([0.1, 0.5, 0.4], $response->score('severity')->probabilities);
        $this->assertSame(0.92, $response->noul('firsthand')->noul);

        $usage = AiUsage::sole();
        $this->assertSame(AiProvider::Jev, $usage->provider);
        $this->assertSame(AiPurpose::Analysis, $usage->purpose);
        $this->assertSame('jev-1.13.0', $usage->model);
        $this->assertSame('p1', $usage->detail);
        $this->assertSame($company->id, $usage->company_id);
        $this->assertSame(1000, $usage->input_tokens);
        $this->assertSame(0, $usage->output_tokens);
        $this->assertNull($usage->cached_input_tokens);
        // 1,000 入力トークン × 0.042 USD/MTok = 42 マイクロ USD（出力は無料）
        $this->assertSame(42, $usage->cost_micro_usd);
        $this->assertSame(self::USAGE, $usage->usage_raw);
        $this->assertTrue($usage->succeeded);
        $this->assertNull($usage->error);
        $this->assertSame($this->request()->stateChars() + $this->request()->questionsChars(), $usage->prompt_chars);
        $this->assertGreaterThan(0, $usage->output_chars);
    }

    public function test_choiceが候補外なら課金済みの失敗として記録する(): void
    {
        $this->configure();
        Http::fake(['api.typesafe.ai/*' => Http::response($this->body([
            'overtime' => ['type' => 'choice', 'choice' => 'unknown', 'probabilities' => ['consistent' => 0.1, 'contradicts' => 0.9], 'confidence' => 0.85],
        ]))]);

        $this->assertFailedAndRecorded('choice が候補にありません（unknown）');
    }

    public function test_probabilitiesの合計が1でなければ課金済みの失敗として記録する(): void
    {
        $this->configure();
        Http::fake(['api.typesafe.ai/*' => Http::response($this->body([
            'overtime' => ['type' => 'choice', 'choice' => 'contradicts', 'probabilities' => ['consistent' => 0.1, 'contradicts' => 0.4], 'confidence' => 0.85],
        ]))]);

        $this->assertFailedAndRecorded('probabilities の合計が 1 ではありません（0.5）');
    }

    public function test_probabilitiesの合計が1±0_01の境界ちょうどなら通す(): void
    {
        // 2 桁に丸めた確率は合計 0.99 / 1.01 になりうる。浮動小数点の誤差で境界を弾かない
        $this->configure();
        Http::fake(['api.typesafe.ai/*' => Http::response($this->body([
            'overtime' => ['type' => 'choice', 'choice' => 'consistent', 'probabilities' => ['consistent' => 0.99, 'contradicts' => 0.02], 'confidence' => 0.85],
            'severity' => ['type' => 'score', 'score' => 2.0, 'probabilities' => [0.33, 0.33, 0.33], 'confidence' => 0.7],
        ]))]);

        $response = $this->decider()->decide($this->request(), AiPurpose::Analysis);

        $this->assertSame('consistent', $response->choice('overtime')->choice);
        $this->assertTrue(AiUsage::sole()->succeeded);
    }

    public function test_probabilitiesの合計が境界を超えれば課金済みの失敗として記録する(): void
    {
        $this->configure();
        Http::fake(['api.typesafe.ai/*' => Http::response($this->body([
            'severity' => ['type' => 'score', 'score' => 2.0, 'probabilities' => [0.32, 0.33, 0.33], 'confidence' => 0.7],
        ]))]);

        $this->assertFailedAndRecorded('probabilities の合計が 1 ではありません（0.98）');
    }

    public function test_scoreが段階の範囲外なら課金済みの失敗として記録する(): void
    {
        $this->configure();
        Http::fake(['api.typesafe.ai/*' => Http::response($this->body([
            'severity' => ['type' => 'score', 'score' => 3.5, 'probabilities' => [0.1, 0.5, 0.4], 'confidence' => 0.7],
        ]))]);

        $this->assertFailedAndRecorded('score が 1〜3 の範囲外です（3.5）');
    }

    public function test_scoreのprobabilitiesの個数が段階数と違えば課金済みの失敗として記録する(): void
    {
        $this->configure();
        Http::fake(['api.typesafe.ai/*' => Http::response($this->body([
            'severity' => ['type' => 'score', 'score' => 2.0, 'probabilities' => [0.5, 0.5], 'confidence' => 0.7],
        ]))]);

        $this->assertFailedAndRecorded('probabilities の個数が段階数と一致しません');
    }

    public function test_noulが範囲外なら課金済みの失敗として記録する(): void
    {
        $this->configure();
        Http::fake(['api.typesafe.ai/*' => Http::response($this->body(['firsthand' => ['type' => 'noul', 'noul' => 1.2]]))]);

        $this->assertFailedAndRecorded('noul が 0〜1 の範囲外です（1.2）');
    }

    public function test_http500は例外でトークン無しの失敗として記録する(): void
    {
        $this->configure();
        Http::fake(['api.typesafe.ai/*' => Http::response(['error' => ['message' => 'internal']], 500)]);

        try {
            $this->decider()->decide($this->request(), AiPurpose::Analysis);
            $this->fail('例外になるはず');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Jev API エラー（HTTP 500）: internal', $e->getMessage());
        }

        $usage = AiUsage::sole();
        $this->assertFalse($usage->succeeded);
        $this->assertStringContainsString('HTTP 500', $usage->error);
        $this->assertNull($usage->input_tokens);
        $this->assertNull($usage->output_tokens);
        $this->assertNull($usage->cost_micro_usd);
        $this->assertSame(0, $usage->output_chars);
    }

    public function test_接続テストはconnection_testとして記録し今回の記録を返す(): void
    {
        $this->configure();
        Http::fake(['api.typesafe.ai/*' => Http::response([
            'model' => 'jev-1.13.0',
            'answers' => ['is_test' => ['type' => 'noul', 'noul' => 0.99]],
            'usage' => ['input_tokens' => 30, 'output_tokens' => 0],
        ])]);

        $usage = $this->decider()->probe();

        $this->assertNotNull($usage);
        $this->assertTrue($usage->is(AiUsage::sole()));
        $this->assertSame(AiPurpose::ConnectionTest, $usage->purpose);
        $this->assertSame(AiProvider::Jev, $usage->provider);
        $this->assertTrue($usage->succeeded);
        $this->assertSame(30, $usage->input_tokens);

        Http::assertSent(fn ($request) => $request->data()['state'] === 'これは接続テストです。'
            && $request->data()['questions'] === ['is_test' => ['type' => 'noul', 'instructions' => 'state は接続テストの文章か']]);
    }

    public function test_既定の束縛はforbidden_ai_deciderで判定を呼ぶと例外(): void
    {
        Http::fake();
        $decider = $this->app->make(AiDecider::class);

        $this->assertInstanceOf(ForbiddenAiDecider::class, $decider);
        // オン・オフは本物の設定で答える（既定はオフ）
        $this->assertFalse($decider->isConfigured());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('AiDecider のモック漏れです');

        try {
            $decider->decide($this->request(), AiPurpose::Analysis);
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
            fn (int $i) => new DecisionRequest(['n' => $i], [new ChoiceQuestion('relation', '主張との関係', ['support' => '整合', 'contradict' => '矛盾'])]),
            range(0, $count - 1),
        );
    }

    /** 番号ごとに応答を差し替えられる fake（既定は support を選ぶ正常な応答） */
    private function fakeMany(array $override = []): void
    {
        Http::fake(function (Request $request) use ($override) {
            return $override[$request->data()['state']['n']] ?? Http::response($this->manyBody());
        });
    }

    /**
     * @param  array<string, mixed>|null  $relation  回答の差し替え（null で欠落）
     * @return array<string, mixed>
     */
    private function manyBody(?array $relation = ['type' => 'choice', 'choice' => 'support', 'probabilities' => ['support' => 0.8, 'contradict' => 0.2], 'confidence' => 0.9]): array
    {
        return [
            'model' => 'jev-1.13.0',
            'answers' => $relation === null ? [] : ['relation' => $relation],
            'usage' => self::USAGE,
        ];
    }

    public function test_一括の判定は同じ添字で返り1件1行で記録される(): void
    {
        $company = Company::create(['name' => '株式会社テスト']);
        $this->configure();
        $this->fakeMany();

        $responses = $this->decider()->decideMany($this->manyRequests(3), AiPurpose::Analysis, 'rj', $company->id);

        $this->assertSame([0, 1, 2], array_keys($responses));
        $this->assertSame('support', $responses[2]->choice('relation')->choice);
        Http::assertSentCount(3);

        $usages = AiUsage::orderBy('id')->get();
        $this->assertCount(3, $usages);
        foreach ($usages as $usage) {
            $this->assertTrue($usage->succeeded);
            $this->assertSame(AiProvider::Jev, $usage->provider);
            $this->assertSame('rj', $usage->detail);
            $this->assertSame($company->id, $usage->company_id);
            $this->assertSame(1000, $usage->input_tokens);
            $this->assertSame(42, $usage->cost_micro_usd);
            $this->assertGreaterThan(0, $usage->output_chars);
        }
        // 並列実行のため 1 件ごとの時間は取れず、各行に一括全体の所要時間が入る
        $this->assertCount(1, $usages->pluck('duration_ms')->unique());
    }

    public function test_一括で1件だけ500なら全件を記録してから例外にする(): void
    {
        $this->configure();
        $this->fakeMany([3 => Http::response(['error' => ['message' => 'internal']], 500)]);

        try {
            $this->decider()->decideMany($this->manyRequests(5), AiPurpose::Analysis);
            $this->fail('例外になるはず');
        } catch (\RuntimeException $e) {
            $this->assertSame('判定の一括実行で 1 / 5 件が失敗しました。最初の失敗: Jev API エラー（HTTP 500）: internal', $e->getMessage());
            $this->assertInstanceOf(\RuntimeException::class, $e->getPrevious());
            $this->assertStringContainsString('HTTP 500', $e->getPrevious()->getMessage());
        }

        $this->assertSame(4, AiUsage::where('succeeded', true)->count());
        $failed = AiUsage::where('succeeded', false)->sole();
        $this->assertStringContainsString('HTTP 500', $failed->error);
        // 課金されない失敗なのでトークン無し
        $this->assertNull($failed->input_tokens);
        $this->assertNull($failed->cost_micro_usd);
    }

    public function test_一括で失敗した区切りの後の送っていない分は記録しない(): void
    {
        $this->configure();
        config(['ai.decision.concurrency' => 2]);
        Sleep::fake();
        $this->fakeMany([1 => Http::response(['error' => ['message' => 'internal']], 500)]);

        try {
            $this->decider()->decideMany($this->manyRequests(5), AiPurpose::Analysis);
            $this->fail('例外になるはず');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('1 / 5 件が失敗しました（残り 3 件は送っていません）', $e->getMessage());
        }

        // 送った 2 件（成功 1・失敗 1）だけが記録に残る
        $this->assertSame(2, AiUsage::count());
        $this->assertSame(1, AiUsage::where('succeeded', false)->count());
    }

    public function test_一括で回答が欠けた要素はusage付きの失敗行になる(): void
    {
        $this->configure();
        $this->fakeMany([1 => Http::response($this->manyBody(null))]);

        try {
            $this->decider()->decideMany($this->manyRequests(3), AiPurpose::Analysis);
            $this->fail('例外になるはず');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('1 / 3 件が失敗しました', $e->getMessage());
            $this->assertInstanceOf(AiRequestFailed::class, $e->getPrevious());
        }

        $failed = AiUsage::where('succeeded', false)->sole();
        $this->assertStringContainsString('質問 relation の回答がありません', $failed->error);
        // 応答は返っている（課金済み）ので、失敗でもトークンと費用を残す
        $this->assertSame(1000, $failed->input_tokens);
        $this->assertSame(42, $failed->cost_micro_usd);
        $this->assertSame(2, AiUsage::where('succeeded', true)->count());
    }

    public function test_一括で値が不正な要素はdecideと同じ検査で課金済みの失敗になる(): void
    {
        $this->configure();
        $this->fakeMany([0 => Http::response($this->manyBody(
            ['type' => 'choice', 'choice' => 'unknown', 'probabilities' => ['support' => 0.8, 'contradict' => 0.2], 'confidence' => 0.9],
        ))]);

        try {
            $this->decider()->decideMany($this->manyRequests(2), AiPurpose::Analysis);
            $this->fail('例外になるはず');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('最初の失敗: Jev（TypeSafe AI） の判定応答が不正です（質問 relation: choice が候補にありません（unknown））', $e->getMessage());
            $this->assertInstanceOf(AiRequestFailed::class, $e->getPrevious());
        }

        $failed = AiUsage::where('succeeded', false)->sole();
        $this->assertSame(1000, $failed->input_tokens);
        $this->assertSame(1, AiUsage::where('succeeded', true)->count());
    }

    public function test_一括で接続できなかった要素も失敗行に残る(): void
    {
        $this->configure();
        Http::fake(['api.typesafe.ai/*' => Http::failedConnection('cURL error 28: timed out')]);

        try {
            $this->decider()->decideMany($this->manyRequests(2), AiPurpose::Analysis);
            $this->fail('例外になるはず');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('2 / 2 件が失敗しました。最初の失敗: Jev API に接続できませんでした', $e->getMessage());
        }

        $this->assertSame(2, AiUsage::where('succeeded', false)->count());
        $this->assertSame(0, AiUsage::where('succeeded', true)->count());
    }

    public function test_一括で1件でも上限を超えれば何も送らない(): void
    {
        Http::fake();
        $this->configure();
        $requests = $this->manyRequests(3);
        $requests[2] = new DecisionRequest(str_repeat('あ', 20001), [new NoulQuestion('q1', '判定')]);

        try {
            $this->decider()->decideMany($requests, AiPurpose::Analysis);
            $this->fail('例外になるはず');
        } catch (\InvalidArgumentException $e) {
            $this->assertSame('判定の state が長すぎます（20,001 字。上限 20,000 字）', $e->getMessage());
        }

        Http::assertNothingSent();
        $this->assertSame(0, AiUsage::count());
    }

    public function test_一括も未設定なら通信せずに判定エンジン未設定の例外(): void
    {
        Http::fake();

        try {
            $this->decider()->decideMany($this->manyRequests(2), AiPurpose::Analysis);
            $this->fail('例外になるはず');
        } catch (DecisionEngineNotConfigured) {
            // 期待どおり
        }

        Http::assertNothingSent();
        $this->assertSame(0, AiUsage::count());
    }

    public function test_一括に空の配列は渡せない(): void
    {
        Http::fake();
        $this->configure();

        $this->expectException(\InvalidArgumentException::class);

        try {
            $this->decider()->decideMany([], AiPurpose::Analysis);
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_既定の束縛のforbidden_ai_deciderは一括でも例外(): void
    {
        Http::fake();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('AiDecider のモック漏れです');

        try {
            $this->app->make(AiDecider::class)->decideMany($this->manyRequests(1), AiPurpose::Analysis);
        } finally {
            Http::assertNothingSent();
        }
    }

    private function assertRejectedBeforeSending(DecisionRequest $request, string $message): void
    {
        Http::fake();
        $this->configure();

        try {
            $this->decider()->decide($request, AiPurpose::Analysis);
            $this->fail('例外になるはず');
        } catch (\InvalidArgumentException $e) {
            $this->assertSame($message, $e->getMessage());
        }

        Http::assertNothingSent();
        $this->assertSame(0, AiUsage::count());
    }

    private function assertFailedAndRecorded(string $message): void
    {
        try {
            $this->decider()->decide($this->request(), AiPurpose::Analysis, 'p1');
            $this->fail('例外になるはず');
        } catch (AiRequestFailed $e) {
            $this->assertStringContainsString($message, $e->getMessage());
            $this->assertSame(1000, $e->usage->input);
        }

        // 応答は返っている（課金済み）ので、失敗でもトークンと費用を残す
        $usage = AiUsage::sole();
        $this->assertFalse($usage->succeeded);
        $this->assertStringContainsString($message, $usage->error);
        $this->assertSame(1000, $usage->input_tokens);
        $this->assertSame(0, $usage->output_tokens);
        $this->assertSame(42, $usage->cost_micro_usd);
        $this->assertSame('p1', $usage->detail);
    }
}
