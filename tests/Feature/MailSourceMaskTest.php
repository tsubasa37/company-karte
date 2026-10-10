<?php

namespace Tests\Feature;

use App\Actions\IngestMailAction;
use App\Enums\AnalysisStep;
use App\Enums\SourceType;
use App\Enums\VerdictPerspective;
use App\Models\MailSetting;
use App\Models\SourceDocument;
use App\Services\Ai\AiRunner;
use App\Services\Ai\AnalysisRunner;
use App\Services\Ai\MailExtractor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

/**
 * メール由来の本文が AI へ渡るときのマスク。
 *
 * 取り込み時のマスクは「メール抽出のプロンプト」にしか掛からず、原文は source_documents に残る
 * （画面で読むのは本人なので伏せない）。種別をスカウトから求人票・面接メモへ付け替えると
 * その原文が分析・条件抽出のプロンプトへ入るため、読み出し側でも必ずマスクされることを確かめる。
 */
class MailSourceMaskTest extends TestCase
{
    use RefreshDatabase;

    private const NAME = '山田太郎';

    private const EMAIL = 'yamada.taro@example.com';

    private const PHONE = '090-1234-5678';

    protected function setUp(): void
    {
        parent::setUp();

        MailSetting::create([
            'allowed_domains' => 'bizreach.jp',
            'mask_words' => self::NAME,
        ]);
    }

    private function body(): string
    {
        return self::NAME."　様\n"
            ."この度はスカウトのご連絡です。月給30万円〜45万円。\n"
            .'ご返信は '.self::EMAIL.' まで、お電話は '.self::PHONE."へお願いします。\n";
    }

    /** メール取り込みを1通ぶん通し、作られたソースを返す */
    private function ingest(): SourceDocument
    {
        $extractor = Mockery::mock(MailExtractor::class);
        $extractor->shouldReceive('extract')->andReturn([
            'is_job_related' => true,
            'company_name' => '株式会社サンプル',
            'mail_kind' => 'scout',
            'position' => 'バックエンドエンジニア',
            'event_candidates' => [],
            'summary' => 'スカウトの案内',
        ]);
        $this->app->instance(MailExtractor::class, $extractor);

        app(IngestMailAction::class)->execute([
            'id' => 'gmail-msg-1',
            'from' => '採用担当 <scout@bizreach.jp>',
            'from_domain' => 'bizreach.jp',
            'subject' => self::NAME.'様へのスカウト',
            'received_at' => Carbon::parse('2026-08-20 09:00'),
            'body' => $this->body(),
        ]);

        return SourceDocument::firstOrFail();
    }

    /** AI に渡ったプロンプトを捕まえる（TestCase 既定の ForbiddenAiRunner を上書きする） */
    private function capturingRunner(?string &$captured): void
    {
        $mock = Mockery::mock(AiRunner::class);
        $mock->shouldReceive('runWithSchema')
            ->andReturnUsing(function (array $schema, string $prompt) use (&$captured) {
                $captured = $prompt;

                return ['claims' => [], 'verdicts' => []];
            });

        $this->app->instance(AiRunner::class, $mock);
    }

    /** @param  array<int, string>  $needles */
    private function assertNoneIn(?string $haystack, array $needles, string $what): void
    {
        $this->assertNotNull($haystack, "{$what}: プロンプトが組み立てられていない");

        foreach ($needles as $needle) {
            $this->assertStringNotContainsString($needle, $haystack, "{$what}に {$needle} が入っている");
        }
    }

    public function test_原文は画面用にマスクせず保存する(): void
    {
        $source = $this->ingest();

        // 読むのは本人なので伏せない（伏せるのは AI へ渡すときだけ）
        $this->assertStringContainsString(self::NAME, (string) $source->raw_text);
        $this->assertStringContainsString(self::EMAIL, (string) $source->raw_text);
    }

    public function test_種別を求人票へ付け替えても分析プロンプトに原文が入らない(): void
    {
        $source = $this->ingest();
        Queue::fake(); // 付け替えが積む条件抽出ジョブはこのテストの対象外

        $this->patch(route('sources.update', $source), [
            'type' => SourceType::JobPosting->value,
            'company_id' => $source->company_id,
        ])->assertRedirect();

        $this->assertSame(SourceType::JobPosting, $source->fresh()->type);

        $captured = null;
        $this->capturingRunner($captured);
        app(AnalysisRunner::class)->runStep($source->company->fresh(), AnalysisStep::P1, []);

        $this->assertNoneIn($captured, [self::NAME, self::EMAIL, self::PHONE], '主張抽出（P1）');
    }

    public function test_種別を面接メモへ付け替えても検証プロンプトに原文が入らない(): void
    {
        $source = $this->ingest();
        Queue::fake();

        $this->patch(route('sources.update', $source), [
            'type' => SourceType::Interview->value,
            'company_id' => $source->company_id,
        ])->assertRedirect();

        $captured = null;
        $this->capturingRunner($captured);
        app(AnalysisRunner::class)->runStep(
            $source->company->fresh(),
            AnalysisStep::P2,
            [],
            VerdictPerspective::Base,
        );

        $this->assertNoneIn($captured, [self::NAME, self::EMAIL, self::PHONE], '主張検証（P2）');
    }

    public function test_付け替え直後に走る条件抽出にも原文が入らない(): void
    {
        $source = $this->ingest();

        // Queue::fake() を掛けない = テストのキューは sync なので、本番の queue:work と同じく
        // 付け替えの直後に ExtractConditionsJob が走る
        $captured = null;
        $this->capturingRunner($captured);

        $this->patch(route('sources.update', $source), [
            'type' => SourceType::JobPosting->value,
            'company_id' => $source->company_id,
        ])->assertRedirect();

        $this->assertNoneIn($captured, [self::NAME, self::EMAIL, self::PHONE], '条件抽出');
    }
}
