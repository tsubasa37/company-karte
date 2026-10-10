<?php

namespace Tests\Feature;

use App\Actions\IngestClipAction;
use App\Models\Company;
use App\Models\SourceRevision;
use App\Services\Ai\ClipClassifier;
use App\Services\SourceDiffService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class SourceRevisionTest extends TestCase
{
    use RefreshDatabase;

    private function mockClassifier(): void
    {
        $mock = Mockery::mock(ClipClassifier::class);
        $mock->shouldReceive('classify')->andReturn([
            'is_company_related' => true,
            'company_name' => '株式会社テスト',
            'source_type' => 'jobposting',
            'summary' => '求人票',
        ]);
        $this->app->instance(ClipClassifier::class, $mock);
    }

    public function test_再クリップで本文が変わると旧本文が履歴に残る(): void
    {
        Queue::fake();
        $this->mockClassifier();
        $action = $this->app->make(IngestClipAction::class);

        $action->execute('求人', 'https://example.com/job', "年収500万円\n急募");
        $action->execute('求人', 'https://example.com/job', "年収450万円\n急募\n未経験歓迎");

        $this->assertSame(1, SourceRevision::count());
        $this->assertStringContainsString('年収500万円', SourceRevision::firstOrFail()->raw_text);

        $company = Company::firstOrFail();
        $this->assertSame(1, $company->sourceDocuments()->count(), 'ソース自体は上書きで1件のまま');
    }

    public function test_同じ本文の再クリップでは履歴を作らない(): void
    {
        Queue::fake();
        $this->mockClassifier();
        $action = $this->app->make(IngestClipAction::class);

        $action->execute('求人', 'https://example.com/job', '年収500万円');
        $action->execute('求人', 'https://example.com/job', '年収500万円');

        $this->assertSame(0, SourceRevision::count());
    }

    public function test_差分は追加行と削除行だけを返す(): void
    {
        $diff = $this->app->make(SourceDiffService::class)->changedLines(
            "年収500万円\n勤務地: 東京\n急募",
            "年収450万円\n勤務地: 東京\n急募\n未経験歓迎",
        );

        $this->assertContains(['type' => 'del', 'line' => '年収500万円'], $diff['lines']);
        $this->assertContains(['type' => 'add', 'line' => '年収450万円'], $diff['lines']);
        $this->assertContains(['type' => 'add', 'line' => '未経験歓迎'], $diff['lines']);
        $this->assertNotContains(['type' => 'add', 'line' => '勤務地: 東京'], $diff['lines'], '変わらない行は含めない');
        $this->assertSame(2, $diff['addTotal']);
        $this->assertSame(1, $diff['delTotal']);
        $this->assertFalse($diff['truncated']);
        $this->assertSame(0, $diff['omitted']);
    }

    public function test_変化行が上限を超えたら打ち切りと総数を返す(): void
    {
        $new = implode("\n", array_map(fn (int $i) => "追加行{$i}", range(1, 300)));

        $diff = $this->app->make(SourceDiffService::class)->changedLines('旧本文', $new);

        // 表示用は 200 行で打ち切るが、総数と省略行数は失わず返す（黙って捨てない）
        $this->assertCount(SourceDiffService::MAX_LINES, $diff['lines']);
        $this->assertSame(300, $diff['addTotal']);
        $this->assertSame(1, $diff['delTotal']);
        $this->assertTrue($diff['truncated']);
        $this->assertSame(101, $diff['omitted']);
    }
}
